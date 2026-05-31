"""
Reprocess Queue Service - the "dirty date" queue that makes attendance self-healing.

Whenever raw punches are inserted or backfilled for a given date (e.g. a device
that was offline for days finally pushes/redelivers its logs), that calendar date
is enqueued here. The attendance processor drains this queue every cycle and
rebuilds those exact dates - no matter how old - so late-arriving data is never
silently lost.

Because attendance_daily uses ON DUPLICATE KEY UPDATE on (pin, date), reprocessing
the same date any number of times is idempotent.
"""

import logging
from datetime import date, timedelta
from typing import Iterable, List
from database import get_db

logger = logging.getLogger(__name__)


async def enqueue_date(target_date, reason: str = "manual") -> None:
    """Enqueue a single date for reprocessing (deduplicated by UNIQUE key)."""
    await enqueue_dates([target_date], reason)


async def enqueue_dates(dates: Iterable, reason: str = "raw_insert") -> int:
    """
    Enqueue one or more dates for reprocessing.
    Duplicate dates already in the queue are ignored (INSERT IGNORE on UNIQUE key).
    Returns the number of rows newly enqueued.
    """
    # Normalise + de-duplicate, skip falsy values
    unique_dates = sorted({d for d in dates if d})
    if not unique_dates:
        return 0

    enqueued = 0
    async with get_db() as conn:
        async with conn.cursor() as cur:
            for d in unique_dates:
                await cur.execute(
                    """INSERT IGNORE INTO attendance_reprocess_queue (target_date, reason)
                       VALUES (%s, %s)""",
                    (d, reason),
                )
                enqueued += cur.rowcount
    if enqueued:
        logger.info(f"Reprocess queue: +{enqueued} date(s) enqueued (reason={reason})")
    return enqueued


async def enqueue_range(from_date: date, to_date: date, reason: str = "backfill") -> int:
    """Enqueue every date in an inclusive range [from_date, to_date]."""
    if from_date > to_date:
        from_date, to_date = to_date, from_date
    days = []
    cur = from_date
    while cur <= to_date:
        days.append(cur)
        cur += timedelta(days=1)
    return await enqueue_dates(days, reason)


async def get_queued_dates(limit: int = 200) -> List[dict]:
    """Return queued dates (oldest enqueue first), capped at `limit`."""
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                """SELECT id, target_date, reason, enqueued_at
                   FROM attendance_reprocess_queue
                   ORDER BY enqueued_at ASC, target_date ASC
                   LIMIT %s""",
                (limit,),
            )
            return await cur.fetchall()


async def remove_date(target_date) -> None:
    """Remove a date from the queue once it has been reprocessed."""
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                "DELETE FROM attendance_reprocess_queue WHERE target_date = %s",
                (target_date,),
            )


async def queue_size() -> int:
    """Number of dates currently waiting to be reprocessed."""
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute("SELECT COUNT(*) AS cnt FROM attendance_reprocess_queue")
            row = await cur.fetchone()
            return row["cnt"] if row else 0
