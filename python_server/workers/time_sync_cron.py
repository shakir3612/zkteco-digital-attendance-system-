"""
Time Sync Cron - Pushes SET_TIME command to all approved devices.
Runs daily at the configured hour (system_settings.time_sync_hour).

Also runs as a background worker inside the main server — checks every minute
if it's time to sync, and fires once per day at the configured hour.

Or run standalone: python -m workers.time_sync_cron
"""

import asyncio
import logging
from datetime import datetime, date
from database import get_db, init_db_pool, close_db_pool
from services.command import queue_set_time_command

logger = logging.getLogger(__name__)

_running = False
_last_sync_date = None  # Track which date we last synced on


async def get_sync_hour() -> int:
    """Get the configured time sync hour from system_settings."""
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                "SELECT setting_value FROM system_settings WHERE setting_key = 'time_sync_hour'"
            )
            row = await cur.fetchone()
            return int(row["setting_value"]) if row else 3


async def sync_time_all_devices():
    """Queue SET_TIME command for every approved device."""
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                "SELECT serial_number, name FROM devices WHERE status = 'approved'"
            )
            devices = await cur.fetchall()

    if not devices:
        logger.info("No approved devices found.")
        return 0

    count = 0
    for device in devices:
        sn = device["serial_number"]
        try:
            await queue_set_time_command(sn)
            count += 1
        except Exception as e:
            logger.error(f"Failed to queue time sync for {sn}: {e}")

    logger.info(f"Time sync queued for {count}/{len(devices)} devices")
    return count


async def start_time_sync_worker():
    """Background worker that checks every 60 seconds if it's time to sync."""
    global _running, _last_sync_date
    _running = True
    logger.info("Time sync worker started")

    while _running:
        try:
            now = datetime.now()
            sync_hour = await get_sync_hour()
            today = now.date()

            # Fire once per day at the configured hour
            if now.hour == sync_hour and _last_sync_date != today:
                _last_sync_date = today
                logger.info(f"Daily time sync triggered (hour={sync_hour})")
                count = await sync_time_all_devices()
                logger.info(f"Time sync complete: {count} devices queued")
        except Exception as e:
            logger.error(f"Time sync worker error: {e}")

        await asyncio.sleep(60)


async def stop_time_sync_worker():
    """Stop the time sync worker."""
    global _running
    _running = False
    logger.info("Time sync worker stopped")


async def main():
    """Standalone execution."""
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s [%(levelname)s] %(name)s: %(message)s"
    )
    logger.info("=" * 50)
    logger.info("Daily Time Sync - Starting")
    logger.info(f"Server time: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    logger.info("=" * 50)

    await init_db_pool()
    try:
        count = await sync_time_all_devices()
        logger.info(f"Done. {count} devices will sync on next poll.")
    finally:
        await close_db_pool()


if __name__ == "__main__":
    asyncio.run(main())
