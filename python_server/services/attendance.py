"""
Attendance Service - parses and stores raw attendance logs from devices.
"""

import logging
from datetime import datetime
from typing import List, Tuple
from database import get_db

logger = logging.getLogger(__name__)


def parse_attlog_line(line: str) -> dict:
    """
    Parse a single ATTLOG line from device.
    Format: PIN\tDatetime\tStatus\tVerify\tWorkcode\tReserved1\tReserved2
    Returns dict with parsed fields or None if invalid.
    """
    try:
        parts = line.strip().split("\t")
        if len(parts) < 4:
            return None

        pin = parts[0].strip()
        punch_time_str = parts[1].strip()
        status = int(parts[2].strip()) if len(parts) > 2 and parts[2].strip() else 0
        verify_type = int(parts[3].strip()) if len(parts) > 3 and parts[3].strip() else 0
        work_code = parts[4].strip() if len(parts) > 4 else None
        reserved1 = parts[5].strip() if len(parts) > 5 else None
        reserved2 = parts[6].strip() if len(parts) > 6 else None

        # Parse datetime
        punch_time = datetime.strptime(punch_time_str, "%Y-%m-%d %H:%M:%S")

        return {
            "pin": pin,
            "punch_time": punch_time,
            "status": status,
            "verify_type": verify_type,
            "work_code": work_code,
            "reserved1": reserved1,
            "reserved2": reserved2,
        }
    except (ValueError, IndexError) as e:
        logger.warning(f"Failed to parse ATTLOG line: '{line}' - {e}")
        return None


async def store_attendance_records(device_sn: str, records: List[dict]) -> Tuple[int, int]:
    """
    Store parsed attendance records into attendance_raw table.
    Uses INSERT IGNORE to handle duplicates gracefully.
    Returns (inserted_count, skipped_count).
    """
    if not records:
        return 0, 0

    inserted = 0
    skipped = 0

    async with get_db() as conn:
        async with conn.cursor() as cur:
            for record in records:
                try:
                    await cur.execute(
                        """INSERT IGNORE INTO attendance_raw 
                           (device_sn, pin, punch_time, status, verify_type, 
                            work_code, reserved1, reserved2, created_at)
                           VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)""",
                        (
                            device_sn,
                            record["pin"],
                            record["punch_time"],
                            record["status"],
                            record["verify_type"],
                            record.get("work_code"),
                            record.get("reserved1"),
                            record.get("reserved2"),
                            datetime.now(),
                        )
                    )
                    if cur.rowcount > 0:
                        inserted += 1
                    else:
                        skipped += 1
                except Exception as e:
                    logger.error(f"Error storing attendance record: {e}")
                    skipped += 1

    logger.info(
        f"Attendance stored for device {device_sn}: "
        f"{inserted} inserted, {skipped} skipped/duplicates"
    )
    return inserted, skipped


async def process_attlog_body(device_sn: str, body: str) -> Tuple[int, int]:
    """
    Process the full ATTLOG POST body from a device.
    Parses all lines and stores valid records.
    Returns (inserted_count, skipped_count).
    """
    lines = body.strip().split("\n")
    records = []

    for line in lines:
        if not line.strip():
            continue
        parsed = parse_attlog_line(line)
        if parsed:
            records.append(parsed)

    return await store_attendance_records(device_sn, records)
