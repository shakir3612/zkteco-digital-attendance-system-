"""
Background Sync Worker - processes biometric sync queue.
Runs as a background task within the FastAPI server.
Periodically checks for templates that need syncing to devices.
"""

import asyncio
import logging
from datetime import datetime
from database import get_db
from services.sync import sync_template_to_all_devices

logger = logging.getLogger(__name__)

# Flag to control the worker loop
_running = False


async def start_sync_worker():
    """Start the background sync worker loop."""
    global _running
    _running = True
    logger.info("Sync worker started")

    while _running:
        try:
            await process_pending_syncs()
        except Exception as e:
            logger.error(f"Sync worker error: {e}")

        # Check every 5 seconds
        await asyncio.sleep(5)


async def stop_sync_worker():
    """Stop the background sync worker."""
    global _running
    _running = False
    logger.info("Sync worker stopped")


async def process_pending_syncs():
    """
    Check for biometric templates that have been recently updated
    and may need syncing. This handles the case where templates
    arrive while the sync service couldn't process them immediately.
    """
    async with get_db() as conn:
        async with conn.cursor() as cur:
            # Find templates updated in last 60 seconds that haven't been
            # synced to all approved devices yet
            await cur.execute(
                """SELECT bt.*, 
                   (SELECT COUNT(*) FROM devices 
                    WHERE status = 'approved' 
                    AND serial_number != bt.source_device_sn) as target_count,
                   (SELECT COUNT(*) FROM device_employees de 
                    WHERE de.pin = bt.pin AND de.bio_synced = TRUE
                    AND de.device_sn != bt.source_device_sn) as synced_count
                FROM biometric_templates bt
                WHERE bt.updated_at >= NOW() - INTERVAL 60 SECOND
                HAVING target_count > synced_count
                LIMIT 10"""
            )
            pending = await cur.fetchall()

    if not pending:
        return

    for tpl in pending:
        try:
            # Check if commands already exist for this template
            async with get_db() as conn:
                async with conn.cursor() as cur:
                    await cur.execute(
                        """SELECT COUNT(*) as cnt FROM device_commands
                           WHERE command_type = 'SET_BIODATA'
                           AND status = 'pending'
                           AND command_content LIKE %s""",
                        (f"%PIN={tpl['pin']}%Type={tpl['bio_type']}%",)
                    )
                    row = await cur.fetchone()
                    if row["cnt"] > 0:
                        continue  # Already queued

            await sync_template_to_all_devices(
                pin=tpl["pin"],
                bio_type=tpl["bio_type"],
                bio_no=tpl["bio_no"],
                bio_index=tpl["bio_index"],
                template=tpl["template"],
                source_device_sn=tpl["source_device_sn"],
            )
        except Exception as e:
            logger.error(f"Error syncing template PIN={tpl['pin']}: {e}")
