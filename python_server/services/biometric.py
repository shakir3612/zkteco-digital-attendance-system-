"""
Biometric Service - handles biometric template storage and triggers sync.
"""

import logging
from datetime import datetime
from typing import List, Optional
from database import get_db

logger = logging.getLogger(__name__)


async def store_biometric_template(pin: str, bio_type: int, bio_no: int,
                                   bio_index: int, valid: int, duress: int,
                                   major_ver: int, minor_ver: int, fmt: int,
                                   template: str, source_device_sn: str) -> bool:
    """
    Store or update a biometric template in the master table.
    Returns True if new/updated, False if unchanged.
    """
    async with get_db() as conn:
        async with conn.cursor() as cur:
            now = datetime.now()
            await cur.execute(
                """INSERT INTO biometric_templates
                   (pin, bio_type, bio_no, bio_index, valid, duress,
                    major_ver, minor_ver, format, template, source_device_sn,
                    created_at, updated_at)
                   VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                   ON DUPLICATE KEY UPDATE
                    template = VALUES(template),
                    valid = VALUES(valid),
                    duress = VALUES(duress),
                    major_ver = VALUES(major_ver),
                    minor_ver = VALUES(minor_ver),
                    format = VALUES(format),
                    source_device_sn = VALUES(source_device_sn),
                    updated_at = VALUES(updated_at)""",
                (pin, bio_type, bio_no, bio_index, valid, duress,
                 major_ver, minor_ver, fmt, template, source_device_sn,
                 now, now)
            )
            return cur.rowcount > 0


async def get_all_templates_for_pin(pin: str) -> List[dict]:
    """Get all biometric templates for a given PIN."""
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                "SELECT * FROM biometric_templates WHERE pin = %s",
                (pin,)
            )
            return await cur.fetchall()


async def get_all_templates() -> List[dict]:
    """Get all biometric templates (for full device sync)."""
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute("SELECT * FROM biometric_templates")
            return await cur.fetchall()


async def get_template_count() -> int:
    """Get total number of biometric templates."""
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute("SELECT COUNT(*) as cnt FROM biometric_templates")
            row = await cur.fetchone()
            return row["cnt"]


async def delete_templates_for_pin(pin: str) -> int:
    """Delete all biometric templates for a PIN. Returns count deleted."""
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                "DELETE FROM biometric_templates WHERE pin = %s", (pin,)
            )
            return cur.rowcount
