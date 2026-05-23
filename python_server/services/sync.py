"""
Biometric Sync Service - distributes templates to all approved devices.
When biometric data is received from one device, this service queues
commands to push it to all OTHER approved devices.
"""

import logging
from datetime import datetime
from typing import List
from database import get_db
from services.command import queue_set_user_command, queue_set_biodata_command

logger = logging.getLogger(__name__)


async def get_approved_devices(exclude_sn: str = None) -> List[dict]:
    """Get all approved devices, optionally excluding one."""
    async with get_db() as conn:
        async with conn.cursor() as cur:
            if exclude_sn:
                await cur.execute(
                    "SELECT * FROM devices WHERE status = 'approved' AND serial_number != %s",
                    (exclude_sn,)
                )
            else:
                await cur.execute(
                    "SELECT * FROM devices WHERE status = 'approved'"
                )
            return await cur.fetchall()


async def is_user_synced_to_device(device_sn: str, pin: str) -> bool:
    """Check if user info has been synced to a specific device."""
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                """SELECT user_synced FROM device_employees
                   WHERE device_sn = %s AND pin = %s""",
                (device_sn, pin)
            )
            row = await cur.fetchone()
            return row is not None and row["user_synced"]


async def mark_user_synced(device_sn: str, pin: str):
    """Mark that user info has been synced to a device."""
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                """INSERT INTO device_employees (device_sn, pin, user_synced, synced_at)
                   VALUES (%s, %s, TRUE, %s)
                   ON DUPLICATE KEY UPDATE user_synced = TRUE, synced_at = VALUES(synced_at)""",
                (device_sn, pin, datetime.now())
            )


async def mark_bio_synced(device_sn: str, pin: str):
    """Mark that biometric data has been synced to a device."""
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                """INSERT INTO device_employees (device_sn, pin, bio_synced, synced_at)
                   VALUES (%s, %s, TRUE, %s)
                   ON DUPLICATE KEY UPDATE bio_synced = TRUE, synced_at = VALUES(synced_at)""",
                (device_sn, pin, datetime.now())
            )


async def sync_template_to_all_devices(pin: str, bio_type: int, bio_no: int,
                                       bio_index: int, template: str,
                                       source_device_sn: str):
    """
    Queue commands to sync a biometric template to ALL other approved devices.
    If user info hasn't been synced to target device yet, queue SET_USER first.
    """
    target_devices = await get_approved_devices(exclude_sn=source_device_sn)

    if not target_devices:
        logger.info(f"No other approved devices to sync template for PIN={pin}")
        return

    # Get employee info for SET_USER command
    employee = await _get_employee(pin)

    for device in target_devices:
        device_sn = device["serial_number"]

        # Check if user info needs to be sent first
        if not await is_user_synced_to_device(device_sn, pin):
            if employee:
                await queue_set_user_command(
                    device_sn=device_sn,
                    pin=pin,
                    name=employee.get("name", ""),
                    privilege=employee.get("privilege", 0),
                    card=employee.get("card_number", ""),
                )
                logger.info(f"Queued SET_USER for PIN={pin} to device {device_sn}")

        # Queue the biometric data
        await queue_set_biodata_command(
            device_sn=device_sn,
            pin=pin,
            bio_type=bio_type,
            bio_no=bio_no,
            bio_index=bio_index,
            template=template,
        )
        logger.info(f"Queued SET_BIODATA for PIN={pin} type={bio_type} to device {device_sn}")

    logger.info(
        f"Bio sync queued: PIN={pin} type={bio_type} → {len(target_devices)} devices"
    )


async def sync_all_to_device(device_sn: str):
    """
    Queue ALL employee user info + ALL biometric templates to a single device.
    Used when a device is newly approved or re-approved.
    """
    # Get all active employees
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                "SELECT * FROM employees WHERE status = 'active'"
            )
            employees = await cur.fetchall()

            await cur.execute("SELECT * FROM biometric_templates")
            templates = await cur.fetchall()

    # Queue user info first (priority 3)
    user_count = 0
    for emp in employees:
        await queue_set_user_command(
            device_sn=device_sn,
            pin=emp["pin"],
            name=emp.get("name", ""),
            privilege=emp.get("privilege", 0),
            card=emp.get("card_number", ""),
            priority=3,
        )
        user_count += 1

    # Queue biometric data (priority 4, after users)
    bio_count = 0
    for tpl in templates:
        await queue_set_biodata_command(
            device_sn=device_sn,
            pin=tpl["pin"],
            bio_type=tpl["bio_type"],
            bio_no=tpl["bio_no"],
            bio_index=tpl["bio_index"],
            template=tpl["template"],
            priority=4,
        )
        bio_count += 1

    logger.info(
        f"Full sync queued for device {device_sn}: "
        f"{user_count} users + {bio_count} bio templates"
    )
    return user_count, bio_count


async def cancel_pending_commands(device_sn: str) -> int:
    """Cancel all pending commands for a device (used on suspension)."""
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                """UPDATE device_commands SET status = 'cancelled'
                   WHERE device_sn = %s AND status = 'pending'""",
                (device_sn,)
            )
            count = cur.rowcount
    logger.info(f"Cancelled {count} pending commands for device {device_sn}")
    return count


async def queue_delete_user_all_devices(pin: str):
    """Queue DELETE_USER command to all approved devices."""
    from services.command import queue_delete_user_command
    devices = await get_approved_devices()
    for device in devices:
        await queue_delete_user_command(device["serial_number"], pin)
    logger.info(f"DELETE_USER queued for PIN={pin} to {len(devices)} devices")


async def _get_employee(pin: str) -> dict:
    """Get employee record by PIN."""
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                "SELECT * FROM employees WHERE pin = %s", (pin,)
            )
            return await cur.fetchone()
