"""
Device Authorization Middleware.
Checks device approval status on every /iclock request.
Updates last_seen timestamp for heartbeat tracking.
"""

import logging
from datetime import datetime
from database import get_db

logger = logging.getLogger(__name__)


async def check_device_approved(serial_number: str) -> bool:
    """
    Check if a device with given serial number is approved.
    Returns True if device exists and status='approved', False otherwise.
    """
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                "SELECT status FROM devices WHERE serial_number = %s",
                (serial_number,)
            )
            row = await cur.fetchone()
            if row is None:
                return False
            return row["status"] == "approved"


async def get_device_status(serial_number: str) -> dict:
    """
    Get device info including status.
    Returns dict with device info or None if not found.
    """
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                "SELECT id, serial_number, status, name, location FROM devices WHERE serial_number = %s",
                (serial_number,)
            )
            return await cur.fetchone()


async def update_last_seen(serial_number: str, ip_address: str):
    """
    Update the last_seen timestamp and IP for a device.
    Called on every request from any device (including unapproved ones).
    """
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                """UPDATE devices 
                   SET last_seen = %s, ip_address = %s 
                   WHERE serial_number = %s""",
                (datetime.now(), ip_address, serial_number)
            )


async def log_connection(device_sn: str, ip_address: str, action: str,
                         was_allowed: bool, details: str = None):
    """
    Log a device connection attempt to device_connection_log table.
    """
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                """INSERT INTO device_connection_log 
                   (device_sn, ip_address, action, was_allowed, details, created_at)
                   VALUES (%s, %s, %s, %s, %s, %s)""",
                (device_sn, ip_address, action, was_allowed, details, datetime.now())
            )
