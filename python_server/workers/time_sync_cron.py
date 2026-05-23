"""
Time Sync Cron - Pushes SET_TIME command to all approved devices.
Runs daily at 3:00 AM (configurable via system_settings.time_sync_hour).

Schedule via Windows Task Scheduler:
  Program: C:\\path\\to\\venv\\Scripts\\python.exe
  Arguments: -m workers.time_sync_cron
  Start in: C:\\path\\to\\python_server
  Trigger: Daily at 03:00

Or run standalone: python -m workers.time_sync_cron
"""

import asyncio
import logging
from datetime import datetime
from database import get_db, init_db_pool, close_db_pool
from services.command import queue_set_time_command

logger = logging.getLogger(__name__)


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
