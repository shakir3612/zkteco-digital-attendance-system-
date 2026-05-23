"""
Internal API - endpoints called by the PHP dashboard.
These allow the PHP frontend to trigger actions on the Python server.
"""

import logging
from datetime import datetime
from fastapi import APIRouter, HTTPException
from pydantic import BaseModel
from typing import Optional

from services.command import (
    queue_set_time_command,
    queue_reboot_command,
    queue_query_userinfo_command,
    queue_query_biodata_command,
    queue_query_attlog_command,
    get_pending_commands_count,
    get_command_stats,
)
from services.sync import (
    sync_all_to_device,
    cancel_pending_commands,
)
from services.importer import (
    create_import_job,
    get_import_job,
    get_recent_imports,
)
from services.device import get_device_by_sn

logger = logging.getLogger(__name__)

router = APIRouter(prefix="/api", tags=["Internal API"])


# =============================================================================
# REQUEST MODELS
# =============================================================================
class ImportRequest(BaseModel):
    device_sn: str
    job_type: str  # 'users', 'biometrics', 'attendance'
    conflict_mode: str = 'skip'  # 'skip', 'update', 'update_blank'
    requested_by: int = 1


class DeviceActionRequest(BaseModel):
    device_sn: str


class ApprovalSyncRequest(BaseModel):
    device_sn: str


# =============================================================================
# DEVICE COMMANDS
# =============================================================================
@router.post("/device/sync-time")
async def api_sync_time(req: DeviceActionRequest):
    """Queue a SET_TIME command for a device."""
    device = await get_device_by_sn(req.device_sn)
    if not device or device["status"] != "approved":
        raise HTTPException(400, "Device not found or not approved")

    cmd_id = await queue_set_time_command(req.device_sn)
    return {"success": True, "command_id": cmd_id, "message": "Time sync command queued"}


@router.post("/device/reboot")
async def api_reboot(req: DeviceActionRequest):
    """Queue a REBOOT command for a device."""
    device = await get_device_by_sn(req.device_sn)
    if not device or device["status"] != "approved":
        raise HTTPException(400, "Device not found or not approved")

    cmd_id = await queue_reboot_command(req.device_sn)
    return {"success": True, "command_id": cmd_id, "message": "Reboot command queued"}


@router.post("/device/resync-all")
async def api_resync_all(req: ApprovalSyncRequest):
    """
    Queue full employee + biometric sync to a device.
    Used after device approval or manual re-sync.
    """
    device = await get_device_by_sn(req.device_sn)
    if not device or device["status"] != "approved":
        raise HTTPException(400, "Device not found or not approved")

    user_count, bio_count = await sync_all_to_device(req.device_sn)
    return {
        "success": True,
        "message": f"Queued {user_count} users + {bio_count} bio templates",
        "users_queued": user_count,
        "bio_queued": bio_count,
    }


@router.post("/device/cancel-commands")
async def api_cancel_commands(req: DeviceActionRequest):
    """Cancel all pending commands for a device (used on suspension)."""
    count = await cancel_pending_commands(req.device_sn)
    return {"success": True, "cancelled": count, "message": f"{count} commands cancelled"}


# =============================================================================
# IMPORT FROM DEVICE
# =============================================================================
@router.post("/import/start")
async def api_start_import(req: ImportRequest):
    """
    Start an import job - queues the QUERY command to the device.
    Device will respond by pushing its stored data.
    """
    device = await get_device_by_sn(req.device_sn)
    if not device or device["status"] != "approved":
        raise HTTPException(400, "Device not found or not approved")

    if req.job_type not in ('users', 'biometrics', 'attendance'):
        raise HTTPException(400, "Invalid job_type. Must be: users, biometrics, attendance")

    if req.conflict_mode not in ('skip', 'update', 'update_blank'):
        raise HTTPException(400, "Invalid conflict_mode. Must be: skip, update, update_blank")

    job_id = await create_import_job(
        device_sn=req.device_sn,
        job_type=req.job_type,
        conflict_mode=req.conflict_mode,
        requested_by=req.requested_by,
    )
    return {
        "success": True,
        "job_id": job_id,
        "message": f"Import job created. Device will push {req.job_type} data on next poll."
    }


@router.get("/import/status/{job_id}")
async def api_import_status(job_id: int):
    """Get the status of an import job."""
    job = await get_import_job(job_id)
    if not job:
        raise HTTPException(404, "Import job not found")
    return {"success": True, "job": job}


@router.get("/import/history/{device_sn}")
async def api_import_history(device_sn: str, limit: int = 10):
    """Get recent import jobs for a device."""
    jobs = await get_recent_imports(device_sn, limit)
    return {"success": True, "jobs": jobs}


# =============================================================================
# DEVICE STATUS
# =============================================================================
@router.get("/device/status/{device_sn}")
async def api_device_status(device_sn: str):
    """Get device info and command stats."""
    device = await get_device_by_sn(device_sn)
    if not device:
        raise HTTPException(404, "Device not found")

    cmd_stats = await get_command_stats(device_sn)
    pending = await get_pending_commands_count(device_sn)

    return {
        "success": True,
        "device": device,
        "commands": {
            "pending": pending,
            "stats": cmd_stats,
        }
    }


@router.get("/devices/approved")
async def api_approved_devices():
    """Get all approved devices (for sync targets list)."""
    from database import get_db
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                "SELECT serial_number, name, location, last_seen FROM devices WHERE status = 'approved'"
            )
            devices = await cur.fetchall()
    return {"success": True, "devices": devices}
