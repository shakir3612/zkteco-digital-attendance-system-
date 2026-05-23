"""
Device Monitor Worker - detects offline devices and creates notifications.
Runs every 1 minute, checks last_seen against threshold.
Creates device_offline notifications when device goes silent.
Creates device_online notifications when device comes back.

Run standalone: python -m workers.device_monitor
Or started as background task in main.py
"""

import asyncio
import logging
from datetime import datetime, timedelta
from database import get_db, init_db_pool, close_db_pool

logger = logging.getLogger(__name__)

_running = False


async def start_device_monitor():
    """Start the device monitor loop. Runs every 60 seconds."""
    global _running
    _running = True
    logger.info("Device monitor started")

    while _running:
        try:
            await check_device_status()
        except Exception as e:
            logger.error(f"Device monitor error: {e}")

        await asyncio.sleep(60)


async def stop_device_monitor():
    """Stop the device monitor."""
    global _running
    _running = False
    logger.info("Device monitor stopped")


async def check_device_status():
    """
    Check all approved devices for offline status.
    Also cleans up stale commands that were delivered but never acknowledged.
    """
    async with get_db() as conn:
        async with conn.cursor() as cur:
            # --- STALE COMMAND CLEANUP ---
            # Commands delivered more than 5 minutes ago but never acknowledged: retry them
            await cur.execute(
                """UPDATE device_commands 
                   SET status = 'pending', attempts = attempts
                   WHERE status = 'delivered' 
                   AND delivered_at < NOW() - INTERVAL 5 MINUTE
                   AND attempts < 3"""
            )
            retried = cur.rowcount
            if retried > 0:
                logger.info(f"Retried {retried} stale delivered commands")

            # Commands that failed 3+ delivery attempts: mark as failed
            await cur.execute(
                """UPDATE device_commands 
                   SET status = 'failed'
                   WHERE status = 'delivered' 
                   AND delivered_at < NOW() - INTERVAL 15 MINUTE
                   AND attempts >= 3"""
            )
            failed = cur.rowcount
            if failed > 0:
                logger.warning(f"Marked {failed} commands as failed (max retries exceeded)")

            # --- DEVICE OFFLINE DETECTION ---
            # Get offline threshold from settings
            await cur.execute(
                "SELECT setting_value FROM system_settings WHERE setting_key = 'offline_threshold_minutes'"
            )
            row = await cur.fetchone()
            threshold_minutes = int(row["setting_value"]) if row else 10

            threshold_time = datetime.now() - timedelta(minutes=threshold_minutes)

            # Find approved devices that are now offline
            await cur.execute(
                """SELECT serial_number, name, last_seen FROM devices
                   WHERE status = 'approved'
                   AND last_seen IS NOT NULL
                   AND last_seen < %s""",
                (threshold_time,)
            )
            offline_devices = await cur.fetchall()

            for device in offline_devices:
                sn = device["serial_number"]
                device_name = device["name"] or sn

                # Check if we already sent an offline notification in last hour
                await cur.execute(
                    """SELECT id FROM notifications
                       WHERE type = 'device_offline'
                       AND title LIKE %s
                       AND created_at > NOW() - INTERVAL 1 HOUR
                       LIMIT 1""",
                    (f"%{sn}%",)
                )
                existing = await cur.fetchone()

                if not existing:
                    # Create offline notification
                    mins_ago = int((datetime.now() - device["last_seen"]).total_seconds() / 60)
                    await cur.execute(
                        """INSERT INTO notifications (type, title, message, target_role, created_at)
                           VALUES ('device_offline', %s, %s, 'all', NOW())""",
                        (
                            f"Device offline: {device_name}",
                            f"Device {device_name} (SN: {sn}) has been offline for {mins_ago} minutes. Last seen: {device['last_seen'].strftime('%Y-%m-%d %H:%M:%S')}",
                        )
                    )
                    logger.warning(f"Device OFFLINE: {device_name} (SN={sn}), last seen {mins_ago}m ago")

            # Find devices that were offline but are now back online
            await cur.execute(
                """SELECT serial_number, name, last_seen FROM devices
                   WHERE status = 'approved'
                   AND last_seen IS NOT NULL
                   AND last_seen >= %s""",
                (threshold_time,)
            )
            online_devices = await cur.fetchall()

            for device in online_devices:
                sn = device["serial_number"]
                device_name = device["name"] or sn

                # Check if there was a recent offline notification (last 2 hours)
                # that hasn't been followed by an online notification
                await cur.execute(
                    """SELECT id FROM notifications
                       WHERE type = 'device_offline'
                       AND title LIKE %s
                       AND created_at > NOW() - INTERVAL 2 HOUR
                       AND id > COALESCE(
                           (SELECT MAX(id) FROM notifications
                            WHERE type = 'device_online' AND title LIKE %s),
                           0
                       )
                       LIMIT 1""",
                    (f"%{sn}%", f"%{sn}%")
                )
                was_offline = await cur.fetchone()

                if was_offline:
                    # Device came back - create online notification
                    await cur.execute(
                        """INSERT INTO notifications (type, title, message, target_role, created_at)
                           VALUES ('device_online', %s, %s, 'all', NOW())""",
                        (
                            f"Device back online: {device_name}",
                            f"Device {device_name} (SN: {sn}) is now connected again.",
                        )
                    )
                    logger.info(f"Device BACK ONLINE: {device_name} (SN={sn})")


async def main():
    """Standalone execution for testing."""
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s [%(levelname)s] %(name)s: %(message)s"
    )
    logger.info("Device Monitor - standalone run")
    await init_db_pool()
    try:
        await check_device_status()
        logger.info("Check complete.")
    finally:
        await close_db_pool()


if __name__ == "__main__":
    asyncio.run(main())
