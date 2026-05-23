"""
Attendance Processor - Daily cron job that pairs raw punches into daily records.
Applies shift rules: late (was_late flag), early leave (left_early flag), single punch.
Checks holidays, weekends, leaves.

Run manually: python -m workers.attendance_processor
Or schedule via cron/task scheduler to run at end of each day (e.g., 11:55 PM).
"""

import asyncio
import logging
from datetime import datetime, date, timedelta, time as dtime
from database import get_db, init_db_pool, close_db_pool

logger = logging.getLogger(__name__)


async def get_system_settings() -> dict:
    """Get relevant system settings."""
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute("SELECT setting_key, setting_value FROM system_settings")
            rows = await cur.fetchall()
            return {r["setting_key"]: r["setting_value"] for r in rows}


async def get_holidays(target_date: date) -> bool:
    """Check if a date is a holiday."""
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                "SELECT id FROM holidays WHERE date = %s", (target_date,)
            )
            return await cur.fetchone() is not None


async def get_approved_leave(employee_id: int, target_date: date) -> bool:
    """Check if employee has approved leave on this date."""
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                """SELECT id FROM leaves 
                   WHERE employee_id = %s AND status = 'approved'
                   AND from_date <= %s AND to_date >= %s""",
                (employee_id, target_date, target_date)
            )
            return await cur.fetchone() is not None


async def get_employee_shift(employee_id: int, target_date: date) -> dict:
    """Get the shift assigned to an employee for a given date."""
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                """SELECT s.* FROM employee_shifts es
                   JOIN shifts s ON s.id = es.shift_id
                   WHERE es.employee_id = %s 
                   AND es.effective_from <= %s
                   AND (es.effective_to IS NULL OR es.effective_to >= %s)
                   ORDER BY es.effective_from DESC LIMIT 1""",
                (employee_id, target_date, target_date)
            )
            shift = await cur.fetchone()
            if shift:
                return shift
            # Fallback to default shift (id=1)
            await cur.execute("SELECT * FROM shifts WHERE id = 1")
            return await cur.fetchone()


async def get_punches_for_day(pin: str, target_date: date) -> list:
    """Get all raw punches for an employee on a specific date."""
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                """SELECT punch_time FROM attendance_raw
                   WHERE pin = %s AND DATE(punch_time) = %s
                   ORDER BY punch_time ASC""",
                (pin, target_date)
            )
            return await cur.fetchall()


def is_weekend(target_date: date, weekly_off_days: str) -> bool:
    """Check if a date falls on a weekend day."""
    day_names = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']
    day_of_week = day_names[target_date.weekday()]
    off_days = [d.strip().lower() for d in weekly_off_days.split(',')]
    return day_of_week in off_days


def time_to_minutes(t) -> int:
    """Convert a time object or timedelta to minutes from midnight."""
    if isinstance(t, timedelta):
        return int(t.total_seconds() // 60)
    if isinstance(t, dtime):
        return t.hour * 60 + t.minute
    return 0


async def process_employee_day(employee: dict, target_date: date,
                               settings: dict) -> dict:
    """
    Process attendance for one employee for one day.
    Returns the attendance_daily record dict.
    """
    pin = employee["pin"]
    employee_id = employee["id"]
    weekly_off = settings.get("weekly_off_days", "fri,sat")

    result = {
        "employee_id": employee_id,
        "pin": pin,
        "date": target_date,
        "first_in": None,
        "last_out": None,
        "total_hours": None,
        "status": "absent",
        "was_late": False,
        "late_minutes": 0,
        "left_early": False,
        "early_minutes": 0,
        "single_punch": False,
        "shift_id": None,
        "processed_at": datetime.now(),
    }

    # Check holiday
    if await get_holidays(target_date):
        result["status"] = "holiday"
        return result

    # Check weekend
    if is_weekend(target_date, weekly_off):
        result["status"] = "weekend"
        return result

    # Check leave
    if await get_approved_leave(employee_id, target_date):
        result["status"] = "on_leave"
        return result

    # Get punches
    punches = await get_punches_for_day(pin, target_date)

    if not punches:
        result["status"] = "absent"
        return result

    # Has at least one punch - present
    result["status"] = "present"

    # Get shift
    shift = await get_employee_shift(employee_id, target_date)
    if shift:
        result["shift_id"] = shift["id"]

    # First punch = check-in, last punch = check-out
    first_in = punches[0]["punch_time"]
    last_out = punches[-1]["punch_time"]
    result["first_in"] = first_in

    # Single punch detection
    if first_in == last_out or len(punches) == 1:
        result["single_punch"] = True
        result["last_out"] = None
        result["total_hours"] = None
    else:
        result["last_out"] = last_out
        diff = (last_out - first_in).total_seconds() / 3600.0
        result["total_hours"] = round(diff, 2)

    # Check late (first_in > shift.start_time + grace)
    if shift:
        shift_start_minutes = time_to_minutes(shift["start_time"])
        grace_late = shift.get("grace_minutes_late", 30)
        allowed_late_minutes = shift_start_minutes + grace_late

        punch_in_minutes = first_in.hour * 60 + first_in.minute
        if punch_in_minutes > allowed_late_minutes:
            result["was_late"] = True
            result["late_minutes"] = punch_in_minutes - shift_start_minutes

    # Check early leave (last_out < shift.end_time - grace)
    if shift and not result["single_punch"] and result["last_out"]:
        shift_end_minutes = time_to_minutes(shift["end_time"])
        grace_early = shift.get("grace_minutes_early", 30)
        allowed_early_minutes = shift_end_minutes - grace_early

        punch_out_minutes = last_out.hour * 60 + last_out.minute
        if punch_out_minutes < allowed_early_minutes:
            result["left_early"] = True
            result["early_minutes"] = shift_end_minutes - punch_out_minutes

    return result


async def save_daily_record(record: dict):
    """Insert or update attendance_daily record."""
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                """INSERT INTO attendance_daily
                   (employee_id, pin, date, first_in, last_out, total_hours,
                    status, was_late, late_minutes, left_early, early_minutes,
                    single_punch, shift_id, processed_at)
                   VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                   ON DUPLICATE KEY UPDATE
                    first_in = VALUES(first_in),
                    last_out = VALUES(last_out),
                    total_hours = VALUES(total_hours),
                    status = VALUES(status),
                    was_late = VALUES(was_late),
                    late_minutes = VALUES(late_minutes),
                    left_early = VALUES(left_early),
                    early_minutes = VALUES(early_minutes),
                    single_punch = VALUES(single_punch),
                    shift_id = VALUES(shift_id),
                    processed_at = VALUES(processed_at)""",
                (
                    record["employee_id"], record["pin"], record["date"],
                    record["first_in"], record["last_out"], record["total_hours"],
                    record["status"], record["was_late"], record["late_minutes"],
                    record["left_early"], record["early_minutes"],
                    record["single_punch"], record["shift_id"], record["processed_at"],
                )
            )


async def process_date(target_date: date):
    """Process attendance for ALL active employees for a single date."""
    settings = await get_system_settings()

    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                "SELECT id, pin, name FROM employees WHERE status = 'active'"
            )
            employees = await cur.fetchall()

    logger.info(f"Processing {len(employees)} employees for {target_date}")

    present = late = early = absent = leave = holiday = weekend = single = 0

    for emp in employees:
        try:
            record = await process_employee_day(emp, target_date, settings)
            await save_daily_record(record)

            if record["status"] == "present":
                present += 1
                if record["was_late"]:
                    late += 1
                if record["left_early"]:
                    early += 1
                if record["single_punch"]:
                    single += 1
            elif record["status"] == "absent":
                absent += 1
            elif record["status"] == "on_leave":
                leave += 1
            elif record["status"] == "holiday":
                holiday += 1
            elif record["status"] == "weekend":
                weekend += 1
        except Exception as e:
            logger.error(f"Error processing {emp['pin']} for {target_date}: {e}")

    logger.info(
        f"Date {target_date} done: present={present}, late={late}, "
        f"early_leave={early}, single_punch={single}, absent={absent}, "
        f"leave={leave}, holiday={holiday}, weekend={weekend}"
    )
    return {
        "date": str(target_date),
        "total_employees": len(employees),
        "present": present,
        "late": late,
        "early_leave": early,
        "single_punch": single,
        "absent": absent,
        "on_leave": leave,
        "holiday": holiday,
        "weekend": weekend,
    }


async def process_today():
    """Process today's attendance."""
    today = date.today()
    return await process_date(today)


async def process_date_range(start_date: date, end_date: date):
    """Process attendance for a range of dates (backfill)."""
    results = []
    current = start_date
    while current <= end_date:
        result = await process_date(current)
        results.append(result)
        current += timedelta(days=1)
    return results


async def main():
    """Standalone execution - process today's attendance."""
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s [%(levelname)s] %(name)s: %(message)s"
    )
    logger.info("=" * 50)
    logger.info("Attendance Processor - Starting")
    logger.info("=" * 50)

    await init_db_pool()
    try:
        result = await process_today()
        logger.info(f"Result: {result}")
    finally:
        await close_db_pool()
    logger.info("Done.")


if __name__ == "__main__":
    asyncio.run(main())
