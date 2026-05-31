"""
Device Service - handles device registration, status management, and stamp tracking.
"""

import logging
from datetime import datetime
from typing import Optional
from database import get_db

logger = logging.getLogger(__name__)


async def register_device(serial_number: str, ip_address: str,
                          push_ver: str = None, firmware_ver: str = None) -> dict:
    """
    Auto-register a new device with status='pending_approval'.
    Returns the created device record.
    """
    async with get_db() as conn:
        async with conn.cursor() as cur:
            now = datetime.now()
            await cur.execute(
                """INSERT INTO devices 
                   (serial_number, ip_address, push_ver, firmware_ver, 
                    status, last_seen, registered_at)
                   VALUES (%s, %s, %s, %s, 'pending_approval', %s, %s)
                   ON DUPLICATE KEY UPDATE 
                    last_seen = VALUES(last_seen),
                    ip_address = VALUES(ip_address)""",
                (serial_number, ip_address, push_ver, firmware_ver, now, now)
            )
            
            # Fetch the device record
            await cur.execute(
                "SELECT * FROM devices WHERE serial_number = %s",
                (serial_number,)
            )
            device = await cur.fetchone()
            logger.info(f"Device registered/updated: SN={serial_number}, IP={ip_address}")
            return device


async def get_device_by_sn(serial_number: str) -> Optional[dict]:
    """Get a device by its serial number."""
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                "SELECT * FROM devices WHERE serial_number = %s",
                (serial_number,)
            )
            return await cur.fetchone()


async def get_device_stamp(device_sn: str, table_name: str) -> str:
    """
    Get the current stamp for a device/table combination.
    Returns '0' if no stamp exists yet.
    """
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                "SELECT stamp FROM device_stamps WHERE device_sn = %s AND table_name = %s",
                (device_sn, table_name)
            )
            row = await cur.fetchone()
            return row["stamp"] if row else "0"


async def update_device_stamp(device_sn: str, table_name: str, new_stamp: str):
    """Update the stamp for a device/table. Creates if not exists."""
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                """INSERT INTO device_stamps (device_sn, table_name, stamp)
                   VALUES (%s, %s, %s)
                   ON DUPLICATE KEY UPDATE stamp = VALUES(stamp)""",
                (device_sn, table_name, new_stamp)
            )


async def get_pending_command(device_sn: str) -> Optional[dict]:
    """
    Get the next pending command for a device (ordered by priority, then created_at).
    Returns the command dict or None.
    """
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                """SELECT * FROM device_commands 
                   WHERE device_sn = %s AND status = 'pending'
                   ORDER BY priority ASC, created_at ASC
                   LIMIT 1""",
                (device_sn,)
            )
            return await cur.fetchone()


async def mark_command_delivered(command_id: int):
    """Mark a command as delivered."""
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                """UPDATE device_commands 
                   SET status = 'delivered', delivered_at = %s, attempts = attempts + 1
                   WHERE id = %s""",
                (datetime.now(), command_id)
            )


async def acknowledge_command(command_id: int, result_code: int):
    """
    Mark a command as acknowledged with its result code.
    On SUCCESS (result_code == 0) for a SET_USER / SET_BIODATA command, also update
    the device_employees tracking row so the dashboard sync status reflects reality.
    """
    import re

    async with get_db() as conn:
        async with conn.cursor() as cur:
            # Fetch the command first so we know its type / target device / content.
            await cur.execute(
                "SELECT device_sn, command_type, command_content FROM device_commands WHERE id = %s",
                (command_id,)
            )
            cmd = await cur.fetchone()

            status = "acknowledged" if result_code == 0 else "failed"
            await cur.execute(
                """UPDATE device_commands 
                   SET status = %s, acknowledged_at = %s, result_code = %s
                   WHERE id = %s""",
                (status, datetime.now(), result_code, command_id)
            )

            # On success, reflect the sync into device_employees.
            if cmd and result_code == 0 and cmd["command_type"] in ("SET_USER", "SET_BIODATA"):
                device_sn = cmd["device_sn"]
                content = cmd["command_content"] or ""
                m = re.search(r"PIN=(\S+)", content)
                if m:
                    pin = m.group(1)
                    now = datetime.now()
                    if cmd["command_type"] == "SET_USER":
                        await cur.execute(
                            """INSERT INTO device_employees (device_sn, pin, user_synced, synced_at)
                               VALUES (%s, %s, TRUE, %s)
                               ON DUPLICATE KEY UPDATE user_synced = TRUE, synced_at = VALUES(synced_at)""",
                            (device_sn, pin, now)
                        )
                    else:  # SET_BIODATA
                        await cur.execute(
                            """INSERT INTO device_employees (device_sn, pin, bio_synced, synced_at)
                               VALUES (%s, %s, TRUE, %s)
                               ON DUPLICATE KEY UPDATE bio_synced = TRUE, synced_at = VALUES(synced_at)""",
                            (device_sn, pin, now)
                        )
                    logger.info(f"Sync tracked: {cmd['command_type']} PIN={pin} -> {device_sn}")

            # On a successful DELETE_USER, remove the tracking row entirely.
            if cmd and result_code == 0 and cmd["command_type"] == "DELETE_USER":
                content = cmd["command_content"] or ""
                m = re.search(r"PIN=(\S+)", content)
                if m:
                    await cur.execute(
                        "DELETE FROM device_employees WHERE device_sn = %s AND pin = %s",
                        (cmd["device_sn"], m.group(1))
                    )
                    logger.info(f"Sync tracked: DELETE_USER PIN={m.group(1)} -> {cmd['device_sn']}")


async def get_all_stamps_for_device(device_sn: str) -> dict:
    """Get all stamps for a device as a dict {table_name: stamp}."""
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                "SELECT table_name, stamp FROM device_stamps WHERE device_sn = %s",
                (device_sn,)
            )
            rows = await cur.fetchall()
            return {row["table_name"]: row["stamp"] for row in rows}
