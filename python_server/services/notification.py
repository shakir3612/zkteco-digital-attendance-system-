"""
Notification Service - creates dashboard alerts for admins.
"""

import logging
from datetime import datetime
from database import get_db

logger = logging.getLogger(__name__)


async def create_notification(type: str, title: str, message: str = None,
                              target_role: str = "all"):
    """
    Create a new notification for the dashboard.
    Types: new_device, device_offline, device_online, sync_failed, system
    """
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                """INSERT INTO notifications (type, title, message, target_role, created_at)
                   VALUES (%s, %s, %s, %s, %s)""",
                (type, title, message, target_role, datetime.now())
            )
    logger.info(f"Notification created: [{type}] {title}")


async def notify_new_device(serial_number: str, ip_address: str):
    """Create a notification for a newly detected device."""
    await create_notification(
        type="new_device",
        title=f"New device detected: SN={serial_number}",
        message=f"A new device (SN: {serial_number}) connected from IP: {ip_address}. "
                f"Review and approve/reject from the Devices page.",
        target_role="all"
    )


async def notify_device_offline(device_name: str, serial_number: str):
    """Create a notification when a device goes offline."""
    name = device_name or serial_number
    await create_notification(
        type="device_offline",
        title=f"Device offline: {name}",
        message=f"Device {name} (SN: {serial_number}) is no longer responding.",
        target_role="all"
    )


async def notify_device_online(device_name: str, serial_number: str):
    """Create a notification when a device comes back online."""
    name = device_name or serial_number
    await create_notification(
        type="device_online",
        title=f"Device back online: {name}",
        message=f"Device {name} (SN: {serial_number}) is now connected again.",
        target_role="all"
    )


async def notify_oversized_push(serial_number: str, size_bytes: int,
                                limit_bytes: int, table: str = None):
    """
    Alert admins that a device push was rejected for exceeding the size limit.

    Rate-limited to once per device per hour: a device retries the SAME rejected
    chunk repeatedly, so without this guard the retry loop would flood the
    notifications table.
    """
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                """SELECT id FROM notifications
                   WHERE type = 'sync_failed'
                   AND message LIKE %s
                   AND created_at > NOW() - INTERVAL 1 HOUR
                   LIMIT 1""",
                (f"%{serial_number}%",)
            )
            if await cur.fetchone():
                return  # already alerted recently

            size_mb = size_bytes / (1024 * 1024)
            limit_mb = limit_bytes / (1024 * 1024)
            await cur.execute(
                """INSERT INTO notifications (type, title, message, target_role, created_at)
                   VALUES ('sync_failed', %s, %s, 'all', %s)""",
                (
                    f"Device push rejected (too large): SN={serial_number}",
                    f"Device {serial_number} sent a {size_mb:.1f} MB "
                    f"{(table + ' ') if table else ''}payload, exceeding the "
                    f"{limit_mb:.0f} MB limit. The device will keep retrying this batch. "
                    f"Increase MAX_PUSH_MB on the server if this is a legitimate bulk push.",
                    datetime.now(),
                )
            )
    logger.warning(
        f"Oversized push from {serial_number}: {size_bytes} bytes > {limit_bytes} limit "
        f"(table={table})"
    )
