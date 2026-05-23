"""
ZKTeco PUSH/ADMS (iclock) Protocol Endpoints.
All device communication goes through these routes.
"""

import logging
from datetime import datetime
from fastapi import APIRouter, Request, Query
from fastapi.responses import PlainTextResponse

from middleware.device_auth import (
    check_device_approved,
    update_last_seen,
    log_connection,
    get_device_status,
)
from services.device import (
    register_device,
    get_device_by_sn,
    get_device_stamp,
    update_device_stamp,
    get_pending_command,
    mark_command_delivered,
    acknowledge_command,
    get_all_stamps_for_device,
)
from services.attendance import process_attlog_body
from services.notification import notify_new_device

logger = logging.getLogger(__name__)

router = APIRouter(prefix="/iclock")


def get_client_ip(request: Request) -> str:
    """Extract client IP from request, considering proxies."""
    forwarded = request.headers.get("X-Forwarded-For")
    if forwarded:
        return forwarded.split(",")[0].strip()
    return request.client.host if request.client else "unknown"


# =============================================================================
# 4.1 DEVICE HANDSHAKE / REGISTRATION
# =============================================================================
@router.get("/cdata")
async def device_handshake(
    request: Request,
    SN: str = Query(..., description="Device Serial Number"),
    options: str = Query(None),
    pushver: str = Query(None),
    language: str = Query(None),
):
    """
    Device handshake / registration endpoint.
    - New device: auto-register as pending_approval, return minimal options.
    - Existing + not approved: return minimal, track heartbeat.
    - Existing + approved: return full options with stamps and ServerTime.
    """
    ip_address = get_client_ip(request)

    # Always update last_seen for existing devices
    device = await get_device_by_sn(SN)

    if device is None:
        # NEW DEVICE - auto-register
        device = await register_device(SN, ip_address, push_ver=pushver)
        await notify_new_device(SN, ip_address)
        await log_connection(SN, ip_address, "handshake", False, "New device auto-registered")

        # Return minimal options - no data exchange
        response = "GET OPTION FROM: {}\r\n".format(SN)
        response += "Stamp=None\r\n"
        response += "OpStamp=None\r\n"
        response += "ErrorDelay=30\r\n"
        response += "Delay=10\r\n"
        response += "ResLogDay=0\r\n"
        response += "TransTimes=00:00;14:05\r\n"
        response += "TransInterval=1\r\n"
        response += "TransFlag=TransData AttLog\r\n"
        response += "Realtime=1\r\n"
        response += "ServerVer=2.4.1\r\n"
        return PlainTextResponse(content=response)

    # Update heartbeat for ALL devices (even unapproved)
    await update_last_seen(SN, ip_address)

    if device["status"] != "approved":
        # Not approved - return minimal, don't accept data
        await log_connection(SN, ip_address, "handshake", False,
                            f"Device status: {device['status']}")

        response = "GET OPTION FROM: {}\r\n".format(SN)
        response += "Stamp=None\r\n"
        response += "OpStamp=None\r\n"
        response += "ErrorDelay=60\r\n"
        response += "Delay=30\r\n"
        response += "ResLogDay=0\r\n"
        response += "TransFlag=0\r\n"
        response += "Realtime=0\r\n"
        response += "ServerVer=2.4.1\r\n"
        return PlainTextResponse(content=response)

    # APPROVED DEVICE - full options
    await log_connection(SN, ip_address, "handshake", True, "Approved device connected")

    # Get stamps for this device
    stamps = await get_all_stamps_for_device(SN)
    att_stamp = stamps.get("ATTLOG", "0")
    oplog_stamp = stamps.get("OPERLOG", "0")
    bio_stamp = stamps.get("BIODATA", "0")

    # Build full response with ServerTime
    server_time = datetime.now().strftime("%Y-%m-%d %H:%M:%S")

    response = "GET OPTION FROM: {}\r\n".format(SN)
    response += "ATTLOGStamp={}\r\n".format(att_stamp)
    response += "OPERLOGStamp={}\r\n".format(oplog_stamp)
    response += "ATTPHOTOStamp=0\r\n"
    response += "BIODATAStamp={}\r\n".format(bio_stamp)
    response += "ErrorDelay=30\r\n"
    response += "Delay=10\r\n"
    response += "TransTimes=00:00;14:05\r\n"
    response += "TransInterval=1\r\n"
    response += "TransFlag=TransData AttLog OpLog BioData\r\n"
    response += "Realtime=1\r\n"
    response += "ServerVer=2.4.1\r\n"
    response += "PushProtVer=2.4.1\r\n"
    response += "ServerTime={}\r\n".format(server_time)

    return PlainTextResponse(content=response)


# =============================================================================
# 4.2 RECEIVE DATA (ATTLOG, BIODATA, USERTAB)
# =============================================================================
@router.post("/cdata")
async def receive_device_data(
    request: Request,
    SN: str = Query(..., description="Device Serial Number"),
    table: str = Query(None, description="Data table type"),
    Stamp: str = Query(None, description="Current stamp"),
):
    """
    Receive data pushed by device.
    Handles: ATTLOG, BIODATA, USERTAB (OPERLOG also accepted but logged only).
    """
    ip_address = get_client_ip(request)
    body_bytes = await request.body()
    
    # Reject oversized payloads (max 10MB)
    if len(body_bytes) > 10 * 1024 * 1024:
        return PlainTextResponse(content="ERROR: PAYLOAD_TOO_LARGE", status_code=413)
    
    body = body_bytes.decode("utf-8", errors="ignore")

    # Always update heartbeat
    device = await get_device_by_sn(SN)
    if device:
        await update_last_seen(SN, ip_address)

    # Check approval
    if not device or device["status"] != "approved":
        await log_connection(SN, ip_address, f"push_{table}", False,
                            "Rejected: device not approved")
        return PlainTextResponse(content="ERROR: DEVICE_NOT_APPROVED", status_code=403)

    # Route by table type
    if table == "ATTLOG":
        return await _handle_attlog(SN, ip_address, body, Stamp)
    elif table == "BIODATA":
        return await _handle_biodata(SN, ip_address, body, Stamp)
    elif table == "USERTAB":
        return await _handle_usertab(SN, ip_address, body, Stamp)
    elif table == "OPERLOG":
        return await _handle_operlog(SN, ip_address, body, Stamp)
    else:
        await log_connection(SN, ip_address, f"push_{table}", True,
                            f"Unknown table type: {table}")
        return PlainTextResponse(content="OK")


async def _handle_attlog(device_sn: str, ip_address: str, body: str, stamp: str):
    """Process incoming attendance logs."""
    inserted, skipped = await process_attlog_body(device_sn, body)

    # Update stamp
    if stamp:
        new_stamp = str(int(stamp) + inserted) if stamp.isdigit() else stamp
        await update_device_stamp(device_sn, "ATTLOG", new_stamp)

    await log_connection(device_sn, ip_address, "push_att", True,
                        f"Records: {inserted} inserted, {skipped} skipped")

    return PlainTextResponse(content="OK")


async def _handle_biodata(device_sn: str, ip_address: str, body: str, stamp: str):
    """
    Process incoming biometric data.
    Format: PIN\tNo\tIndex\tValid\tDuress\tType\tMajorVer\tMinorVer\tFormat\tTmp={base64}
    Stores template and TRIGGERS SYNC to all other approved devices.
    """
    from services.biometric import store_biometric_template
    from services.sync import sync_template_to_all_devices

    lines = body.strip().split("\n")
    stored_count = 0
    synced_templates = []

    for line in lines:
        if not line.strip():
            continue
        try:
            parts = line.strip().split("\t")
            if len(parts) < 10:
                continue

            pin = parts[0].strip()
            bio_no = int(parts[1].strip()) if parts[1].strip() else 0
            bio_index = int(parts[2].strip()) if parts[2].strip() else 0
            valid = int(parts[3].strip()) if parts[3].strip() else 1
            duress = int(parts[4].strip()) if parts[4].strip() else 0
            bio_type = int(parts[5].strip()) if parts[5].strip() else 0
            major_ver = int(parts[6].strip()) if parts[6].strip() else None
            minor_ver = int(parts[7].strip()) if parts[7].strip() else None
            fmt = int(parts[8].strip()) if parts[8].strip() else 0

            # Template data - may start with "Tmp=" prefix
            template_data = parts[9].strip()
            if template_data.startswith("Tmp="):
                template_data = template_data[4:]

            # Store in master table
            await store_biometric_template(
                pin=pin, bio_type=bio_type, bio_no=bio_no,
                bio_index=bio_index, valid=valid, duress=duress,
                major_ver=major_ver, minor_ver=minor_ver, fmt=fmt,
                template=template_data, source_device_sn=device_sn
            )
            stored_count += 1

            # Collect for sync
            synced_templates.append({
                "pin": pin, "bio_type": bio_type, "bio_no": bio_no,
                "bio_index": bio_index, "template": template_data,
            })
        except Exception as e:
            logger.error(f"Error parsing BIODATA line: {e}")

    # TRIGGER SYNC: distribute each template to all other approved devices
    for tpl in synced_templates:
        try:
            await sync_template_to_all_devices(
                pin=tpl["pin"],
                bio_type=tpl["bio_type"],
                bio_no=tpl["bio_no"],
                bio_index=tpl["bio_index"],
                template=tpl["template"],
                source_device_sn=device_sn,
            )
        except Exception as e:
            logger.error(f"Error triggering sync for PIN={tpl['pin']}: {e}")

    # Update stamp
    if stamp:
        new_stamp = str(int(stamp) + stored_count) if stamp.isdigit() else stamp
        await update_device_stamp(device_sn, "BIODATA", new_stamp)

    await log_connection(device_sn, ip_address, "push_bio", True,
                        f"Stored {stored_count} templates, sync triggered")

    logger.info(f"BIODATA from {device_sn}: {stored_count} templates stored + sync triggered")
    return PlainTextResponse(content="OK")


async def _handle_usertab(device_sn: str, ip_address: str, body: str, stamp: str):
    """
    Process incoming user info from device.
    Format: PIN\tName\tPri\tPasswd\tCard\tGrp\tTZ\tVerify
    If triggered by an import job, applies conflict_mode rules.
    """
    from services.importer import (
        get_active_import_job, start_import_job,
        update_import_progress, complete_import_job, process_imported_user
    )

    lines = body.strip().split("\n")
    valid_lines = [l for l in lines if l.strip()]

    # Check if there's an active import job for this device
    import_job = await get_active_import_job(device_sn, 'users')
    conflict_mode = 'skip'  # default
    if import_job:
        conflict_mode = import_job.get('conflict_mode', 'skip')
        if import_job['status'] == 'queued':
            await start_import_job(import_job['id'])

    inserted = 0
    updated = 0
    skipped = 0
    failed = 0

    for line in valid_lines:
        try:
            parts = line.strip().split("\t")
            if len(parts) < 2:
                continue

            pin = parts[0].strip()
            name = parts[1].strip() if len(parts) > 1 else ""
            privilege = int(parts[2].strip()) if len(parts) > 2 and parts[2].strip() else 0
            card = parts[4].strip() if len(parts) > 4 else ""

            result = await process_imported_user(
                device_sn=device_sn, pin=pin, name=name,
                privilege=privilege, card=card,
                conflict_mode=conflict_mode
            )

            if result == 'inserted':
                inserted += 1
            elif result == 'updated':
                updated += 1
            else:
                skipped += 1
        except Exception as e:
            logger.error(f"Error parsing USERTAB line: {e}")
            failed += 1

    # Update import job if one exists
    if import_job:
        await update_import_progress(
            import_job['id'],
            received=len(valid_lines),
            inserted=inserted, updated=updated,
            skipped=skipped, failed=failed
        )
        await complete_import_job(import_job['id'], 'completed')

    await log_connection(device_sn, ip_address, "push_user", True,
                        f"Users: {inserted} new, {updated} updated, {skipped} skipped")

    logger.info(f"USERTAB from {device_sn}: {inserted} inserted, {updated} updated, {skipped} skipped")
    return PlainTextResponse(content="OK")


async def _handle_operlog(device_sn: str, ip_address: str, body: str, stamp: str):
    """Handle operation log data (just acknowledge, log only)."""
    lines_count = len([l for l in body.strip().split("\n") if l.strip()])

    if stamp:
        await update_device_stamp(device_sn, "OPERLOG", stamp)

    await log_connection(device_sn, ip_address, "push_operlog", True,
                        f"Received {lines_count} operation log entries")
    return PlainTextResponse(content="OK")


# =============================================================================
# 4.5 DEVICE POLLS FOR COMMANDS
# =============================================================================
@router.get("/getrequest")
async def get_request(
    request: Request,
    SN: str = Query(..., description="Device Serial Number"),
):
    """
    Device polls for pending commands.
    Returns one command at a time, or OK if no commands.
    """
    ip_address = get_client_ip(request)

    # Update heartbeat
    device = await get_device_by_sn(SN)
    if device:
        await update_last_seen(SN, ip_address)

    # Only deliver commands to approved devices
    if not device or device["status"] != "approved":
        return PlainTextResponse(content="OK")

    # Get next pending command
    command = await get_pending_command(SN)
    if not command:
        return PlainTextResponse(content="OK")

    # Mark as delivered
    await mark_command_delivered(command["id"])

    # Format command for device
    cmd_content = command["command_content"]
    cmd_id = command["id"]

    # Commands are formatted as: C:{id}:{content}
    response = f"C:{cmd_id}:{cmd_content}\r\n"

    logger.info(f"Command delivered to {SN}: ID={cmd_id}, Type={command['command_type']}")
    return PlainTextResponse(content=response)


# =============================================================================
# 4.6 DEVICE ACKNOWLEDGES COMMAND
# =============================================================================
@router.post("/devicecmd")
async def device_cmd_ack(
    request: Request,
    SN: str = Query(..., description="Device Serial Number"),
):
    """
    Device acknowledges a previously delivered command.
    Body: ID={cmd_id}&Return={result_code}
    """
    ip_address = get_client_ip(request)
    body = (await request.body()).decode("utf-8", errors="ignore")

    # Update heartbeat
    device = await get_device_by_sn(SN)
    if device:
        await update_last_seen(SN, ip_address)

    if not device or device["status"] != "approved":
        return PlainTextResponse(content="OK")

    # Parse acknowledgment
    try:
        params = {}
        for part in body.strip().split("&"):
            if "=" in part:
                key, value = part.split("=", 1)
                params[key.strip()] = value.strip()

        cmd_id = int(params.get("ID", 0))
        result_code = int(params.get("Return", -1))

        if cmd_id > 0:
            await acknowledge_command(cmd_id, result_code)
            await log_connection(SN, ip_address, "cmd_ack", True,
                                f"Command {cmd_id} acknowledged with code {result_code}")
            logger.info(f"Command {cmd_id} acknowledged by {SN}: result={result_code}")
    except Exception as e:
        logger.error(f"Error parsing command acknowledgment from {SN}: {e}")

    return PlainTextResponse(content="OK")
