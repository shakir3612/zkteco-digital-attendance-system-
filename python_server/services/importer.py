"""
Import Service - handles data import jobs from devices.
Admin triggers import → command queued → device pushes data → this service tracks progress.
"""

import logging
from datetime import datetime
from typing import Optional
from database import get_db
from services.command import (
    queue_query_userinfo_command,
    queue_query_biodata_command,
    queue_query_attlog_command,
)

logger = logging.getLogger(__name__)


async def create_import_job(device_sn: str, job_type: str,
                           conflict_mode: str, requested_by: int) -> int:
    """
    Create a new import job and queue the corresponding QUERY command.
    job_type: 'users', 'biometrics', 'attendance'
    conflict_mode: 'skip', 'update', 'update_blank'
    Returns the job ID.
    """
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                """INSERT INTO import_jobs
                   (device_sn, job_type, conflict_mode, status, requested_by, created_at)
                   VALUES (%s, %s, %s, 'queued', %s, %s)""",
                (device_sn, job_type, conflict_mode, requested_by, datetime.now())
            )
            job_id = cur.lastrowid

    # Queue the appropriate QUERY command to the device
    if job_type == 'users':
        await queue_query_userinfo_command(device_sn)
    elif job_type == 'biometrics':
        await queue_query_biodata_command(device_sn)
    elif job_type == 'attendance':
        await queue_query_attlog_command(device_sn)

    logger.info(f"Import job created: ID={job_id}, device={device_sn}, "
                f"type={job_type}, mode={conflict_mode}")
    return job_id


async def get_import_job(job_id: int) -> Optional[dict]:
    """Get an import job by ID."""
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute("SELECT * FROM import_jobs WHERE id = %s", (job_id,))
            return await cur.fetchone()


async def get_active_import_job(device_sn: str, job_type: str) -> Optional[dict]:
    """Get the most recent active (queued/running) import job for a device+type."""
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                """SELECT * FROM import_jobs
                   WHERE device_sn = %s AND job_type = %s
                   AND status IN ('queued', 'running')
                   ORDER BY created_at DESC LIMIT 1""",
                (device_sn, job_type)
            )
            return await cur.fetchone()


async def start_import_job(job_id: int):
    """Mark an import job as running."""
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                """UPDATE import_jobs SET status = 'running', started_at = %s
                   WHERE id = %s""",
                (datetime.now(), job_id)
            )


async def update_import_progress(job_id: int, received: int = 0,
                                 inserted: int = 0, updated: int = 0,
                                 skipped: int = 0, failed: int = 0):
    """Increment import job counters."""
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                """UPDATE import_jobs SET
                    records_received = records_received + %s,
                    records_inserted = records_inserted + %s,
                    records_updated = records_updated + %s,
                    records_skipped = records_skipped + %s,
                    records_failed = records_failed + %s
                   WHERE id = %s""",
                (received, inserted, updated, skipped, failed, job_id)
            )


async def complete_import_job(job_id: int, status: str = 'completed',
                             error_message: str = None):
    """Mark an import job as completed/failed/partial."""
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                """UPDATE import_jobs SET status = %s, completed_at = %s,
                   error_message = %s WHERE id = %s""",
                (status, datetime.now(), error_message, job_id)
            )


async def get_recent_imports(device_sn: str, limit: int = 10) -> list:
    """Get recent import jobs for a device."""
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                """SELECT * FROM import_jobs WHERE device_sn = %s
                   ORDER BY created_at DESC LIMIT %s""",
                (device_sn, limit)
            )
            return await cur.fetchall()


async def process_imported_user(device_sn: str, pin: str, name: str,
                               privilege: int, card: str,
                               conflict_mode: str) -> str:
    """
    Process a single imported user record based on conflict mode.
    Returns: 'inserted', 'updated', 'skipped'
    """
    async with get_db() as conn:
        async with conn.cursor() as cur:
            # Check if employee exists
            await cur.execute("SELECT * FROM employees WHERE pin = %s", (pin,))
            existing = await cur.fetchone()

            if existing is None:
                # Always insert new
                await cur.execute(
                    """INSERT INTO employees (pin, name, privilege, card_number,
                       status, created_at)
                       VALUES (%s, %s, %s, %s, 'active', %s)""",
                    (pin, name, privilege, card, datetime.now())
                )
                return 'inserted'

            if conflict_mode == 'skip':
                return 'skipped'

            if conflict_mode == 'update':
                # Overwrite with device data
                await cur.execute(
                    """UPDATE employees SET name = %s, privilege = %s,
                       card_number = %s, updated_at = %s WHERE pin = %s""",
                    (name, privilege, card, datetime.now(), pin)
                )
                return 'updated'

            if conflict_mode == 'update_blank':
                # Only fill in blank fields
                updates = []
                params = []
                if not existing.get('name') and name:
                    updates.append("name = %s")
                    params.append(name)
                if not existing.get('card_number') and card:
                    updates.append("card_number = %s")
                    params.append(card)
                if updates:
                    updates.append("updated_at = %s")
                    params.append(datetime.now())
                    params.append(pin)
                    await cur.execute(
                        f"UPDATE employees SET {', '.join(updates)} WHERE pin = %s",
                        params
                    )
                    return 'updated'
                return 'skipped'

    return 'skipped'
