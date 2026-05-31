"""
Attendance Processor - pairs raw punches into daily records and applies shift rules.

What it does:
  * working day + punches      -> present (with late / early-leave flags)
  * working day + no punches    -> 'absent' ONLY if the day is "settled" (data is
                                   known-complete); otherwise 'pending' (device was
                                   offline / no data received yet - never falsely absent)
  * holiday/weekend + punches   -> present + worked_on_off_day=TRUE (office duty, counted)
  * holiday/weekend + no punches-> 'holiday' / 'weekend' (an expected day off)
  * approved leave              -> 'on_leave'

Driven two ways inside the running server:
  1. A dirty-date queue (attendance_reprocess_queue) - rebuilds any date that received
     new/backfilled raw punches, no matter how old. This is what self-heals data that
     arrived late after a connectivity outage.
  2. A trailing sweep of today + yesterday every cycle - covers the live edge, the
     midnight rollover, and late same-day punches.

NOTE (per requirements): total work-hours and night-shift handling are intentionally
NOT computed here. total_hours is always stored as NULL. Lateness/early-leave and the
single-punch flag are still computed for ordinary working-day attendance.

Run manually: python -m workers.attendance_processor
"""

import asyncio
import logging
from datetime import datetime, date, timedelta, time as dtime
from database import get_db, init_db_pool, close_db_pool

logger = logging.getLogger(__name__)


# =============================================================================
# LOOKUPS
# =============================================================================
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
            await cur.execute("SELECT id FROM holidays WHERE date = %s", (target_date,))
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
    """
    Get the effective shift for an employee on a given date.
    Checks active shift overrides first (govt orders, Ramadan, etc.), then the
    employee's assigned shift, then falls back to the default shift (id=1).
    """
    override = await get_active_shift_override(target_date)
    if override:
        return {
            "id": None,
            "name": override["name"],
            "start_time": override["start_time"],
            "end_time": override["end_time"],
            "grace_minutes_late": override["grace_minutes_late"],
            "grace_minutes_early": override["grace_minutes_early"],
            "is_night_shift": False,
        }

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
            # Fallback to default shift
            await cur.execute("SELECT * FROM shifts WHERE id = 1")
            return await cur.fetchone()


async def get_active_shift_override(target_date: date) -> dict:
    """Check if there's an active shift override for a given date."""
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                """SELECT * FROM shift_overrides
                   WHERE status = 'active'
                   AND from_date <= %s
                   AND (to_date IS NULL OR to_date >= %s)
                   ORDER BY created_at DESC LIMIT 1""",
                (target_date, target_date)
            )
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


async def day_has_coverage(target_date: date) -> bool:
    """
    Did the server receive ANY data on this date?
    True if there is at least one raw punch that day OR at least one allowed device
    connection logged that day. When this is True we trust that "no punch" means a
    genuine absence; when it is False the day had no data at all (total outage /
    server down), so absence cannot be asserted.
    """
    start = datetime.combine(target_date, dtime.min)
    end = start + timedelta(days=1)
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                "SELECT 1 FROM attendance_raw WHERE punch_time >= %s AND punch_time < %s LIMIT 1",
                (start, end)
            )
            if await cur.fetchone():
                return True
            await cur.execute(
                """SELECT 1 FROM device_connection_log
                   WHERE created_at >= %s AND created_at < %s AND was_allowed = 1
                   LIMIT 1""",
                (start, end)
            )
            return await cur.fetchone() is not None


async def get_locked_pins(target_date: date) -> set:
    """PINs whose daily row for this date was manually adjusted (must not be overwritten)."""
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                "SELECT pin FROM attendance_daily WHERE date = %s AND locked = 1",
                (target_date,)
            )
            rows = await cur.fetchall()
            return {r["pin"] for r in rows}


def is_weekend(target_date: date, weekly_off_days: str) -> bool:
    """Check if a date falls on a weekend day."""
    day_names = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']
    day_of_week = day_names[target_date.weekday()]
    off_days = [d.strip().lower() for d in weekly_off_days.split(',')]
    return day_of_week in off_days


async def determine_day_type(target_date: date, settings: dict) -> str:
    """Return the calendar nature of the day: 'holiday', 'weekend', or 'working'."""
    if await get_holidays(target_date):
        return 'holiday'
    weekly_off = settings.get("weekly_off_days", "fri,sat")
    if is_weekend(target_date, weekly_off):
        return 'weekend'
    return 'working'


def time_to_minutes(t) -> int:
    """Convert a TIME (timedelta from MySQL) or time object to minutes from midnight."""
    if isinstance(t, timedelta):
        return int(t.total_seconds() // 60)
    if isinstance(t, dtime):
        return t.hour * 60 + t.minute
    return 0


# =============================================================================
# PER-EMPLOYEE / PER-DAY PROCESSING
# =============================================================================
async def process_employee_day(employee: dict, target_date: date, settings: dict,
                               day_type: str, day_settled: bool) -> dict:
    """
    Compute the attendance_daily record for one employee on one day.

    `day_type`    - precomputed calendar nature ('working'/'weekend'/'holiday').
    `day_settled` - True when the day's data is known-complete (so a missing punch
                    is a real absence). False => use 'pending' instead of 'absent'.
    """
    pin = employee["pin"]
    employee_id = employee["id"]

    result = {
        "employee_id": employee_id,
        "pin": pin,
        "date": target_date,
        "first_in": None,
        "last_out": None,
        "total_hours": None,          # intentionally not computed (per requirements)
        "status": "absent",
        "day_type": day_type,
        "worked_on_off_day": False,
        "is_pending": False,
        "was_late": False,
        "late_minutes": 0,
        "left_early": False,
        "early_minutes": 0,
        "single_punch": False,
        "shift_id": None,
        "processed_at": datetime.now(),
    }

    punches = await get_punches_for_day(pin, target_date)
    has_punches = bool(punches)
    if has_punches:
        first_in = punches[0]["punch_time"]
        last_out = punches[-1]["punch_time"]
        single = (len(punches) == 1 or first_in == last_out)
    else:
        first_in = last_out = None
        single = False

    # --- Holiday / weekend ---------------------------------------------------
    if day_type in ('holiday', 'weekend'):
        if has_punches:
            # Office ran on an off day and the employee attended -> counted as duty.
            # No late/early is applied (special-duty hours differ from the normal shift).
            result["status"] = "present"
            result["worked_on_off_day"] = True
            result["first_in"] = first_in
            result["single_punch"] = single
            result["last_out"] = None if single else last_out
        else:
            result["status"] = day_type  # 'holiday' or 'weekend' (expected day off)
        return result

    # --- Working day ---------------------------------------------------------
    # Approved leave takes precedence over a plain "absent".
    if await get_approved_leave(employee_id, target_date):
        result["status"] = "on_leave"
        if has_punches:  # record times for audit if they actually came in
            result["first_in"] = first_in
            result["single_punch"] = single
            result["last_out"] = None if single else last_out
        return result

    if has_punches:
        result["status"] = "present"
        result["first_in"] = first_in
        result["single_punch"] = single
        result["last_out"] = None if single else last_out

        shift = await get_employee_shift(employee_id, target_date)
        if shift:
            result["shift_id"] = shift["id"]
            shift_start = time_to_minutes(shift["start_time"])
            grace_late = shift.get("grace_minutes_late", 30) or 0
            punch_in_minutes = first_in.hour * 60 + first_in.minute
            if punch_in_minutes > shift_start + grace_late:
                result["was_late"] = True
                result["late_minutes"] = punch_in_minutes - shift_start

            if not result["single_punch"] and result["last_out"]:
                shift_end = time_to_minutes(shift["end_time"])
                grace_early = shift.get("grace_minutes_early", 30) or 0
                punch_out_minutes = last_out.hour * 60 + last_out.minute
                if punch_out_minutes < shift_end - grace_early:
                    result["left_early"] = True
                    result["early_minutes"] = shift_end - punch_out_minutes
        return result

    # Working day, no punches: absent only if we trust the data is complete.
    if day_settled:
        result["status"] = "absent"
    else:
        result["status"] = "pending"
        result["is_pending"] = True
    return result


async def save_daily_record(record: dict):
    """Insert or update an attendance_daily record (idempotent on pin+date)."""
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                """INSERT INTO attendance_daily
                   (employee_id, pin, date, first_in, last_out, total_hours,
                    status, day_type, worked_on_off_day, is_pending,
                    was_late, late_minutes, left_early, early_minutes,
                    single_punch, shift_id, processed_at)
                   VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s,
                           %s, %s, %s, %s, %s, %s, %s)
                   ON DUPLICATE KEY UPDATE
                    first_in = VALUES(first_in),
                    last_out = VALUES(last_out),
                    total_hours = VALUES(total_hours),
                    status = VALUES(status),
                    day_type = VALUES(day_type),
                    worked_on_off_day = VALUES(worked_on_off_day),
                    is_pending = VALUES(is_pending),
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
                    record["status"], record["day_type"], record["worked_on_off_day"],
                    record["is_pending"], record["was_late"], record["late_minutes"],
                    record["left_early"], record["early_minutes"],
                    record["single_punch"], record["shift_id"], record["processed_at"],
                )
            )


# =============================================================================
# WHOLE-DAY PROCESSING
# =============================================================================
async def process_date(target_date: date):
    """Process attendance for ALL active employees for a single date."""
    settings = await get_system_settings()
    day_type = await determine_day_type(target_date, settings)
    coverage = await day_has_coverage(target_date)
    # A past day with confirmed coverage is "settled" -> missing punch = real absence.
    # Today (and any day with no data at all) is never settled -> 'pending'.
    day_settled = (target_date < date.today()) and coverage
    locked_pins = await get_locked_pins(target_date)

    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                "SELECT id, pin, name FROM employees WHERE status = 'active'"
            )
            employees = await cur.fetchall()

    logger.info(
        f"Processing {len(employees)} employees for {target_date} "
        f"(day_type={day_type}, settled={day_settled}, locked={len(locked_pins)})"
    )

    present = late = early = absent = leave = holiday = weekend = single = 0
    pending = off_day_duty = locked = 0

    for emp in employees:
        if emp["pin"] in locked_pins:
            locked += 1
            continue
        try:
            record = await process_employee_day(emp, target_date, settings,
                                                 day_type, day_settled)
            await save_daily_record(record)

            status = record["status"]
            if status == "present":
                present += 1
                if record["worked_on_off_day"]:
                    off_day_duty += 1
                if record["was_late"]:
                    late += 1
                if record["left_early"]:
                    early += 1
                if record["single_punch"]:
                    single += 1
            elif status == "absent":
                absent += 1
            elif status == "pending":
                pending += 1
            elif status == "on_leave":
                leave += 1
            elif status == "holiday":
                holiday += 1
            elif status == "weekend":
                weekend += 1
        except Exception as e:
            logger.error(f"Error processing {emp['pin']} for {target_date}: {e}")

    logger.info(
        f"Date {target_date} done: present={present} (off-day duty={off_day_duty}), "
        f"late={late}, early_leave={early}, single_punch={single}, absent={absent}, "
        f"pending={pending}, leave={leave}, holiday={holiday}, weekend={weekend}, "
        f"locked(skipped)={locked}"
    )
    return {
        "date": str(target_date),
        "day_type": day_type,
        "settled": day_settled,
        "total_employees": len(employees),
        "present": present,
        "off_day_duty": off_day_duty,
        "late": late,
        "early_leave": early,
        "single_punch": single,
        "absent": absent,
        "pending": pending,
        "on_leave": leave,
        "holiday": holiday,
        "weekend": weekend,
        "locked_skipped": locked,
    }


async def process_today():
    """Process today's attendance."""
    return await process_date(date.today())


async def process_date_range(start_date: date, end_date: date):
    """Process attendance for a range of dates (backfill)."""
    results = []
    current = start_date
    while current <= end_date:
        results.append(await process_date(current))
        current += timedelta(days=1)
    return results


# =============================================================================
# BACKGROUND WORKER
# =============================================================================
_running = False
PROCESS_INTERVAL_SECONDS = 120  # 2 minutes


async def drain_reprocess_queue():
    """Reprocess every date sitting in the dirty-date queue, then clear it."""
    from services.reprocess import get_queued_dates, remove_date

    dates = await get_queued_dates(limit=200)
    if not dates:
        return 0

    logger.info(f"Draining reprocess queue: {len(dates)} date(s)")
    for row in dates:
        d = row["target_date"]
        try:
            await process_date(d)
        except Exception as e:
            logger.error(f"Error reprocessing queued date {d}: {e}")
        finally:
            # Remove even on error so a permanently-bad date can't wedge the queue;
            # genuinely new punches for it would simply re-enqueue it later.
            await remove_date(d)
    return len(dates)


async def start_attendance_worker():
    """
    Background loop: drain the dirty-date queue (handles arbitrarily old backfill)
    plus a trailing sweep of today + yesterday (handles the live edge).
    """
    global _running
    _running = True
    logger.info("Attendance processor worker started (queue drain + trailing sweep, every 2 min)")

    while _running:
        try:
            await drain_reprocess_queue()
            today = date.today()
            await process_date(today)
            await process_date(today - timedelta(days=1))
        except Exception as e:
            logger.error(f"Attendance processor error: {e}")

        await asyncio.sleep(PROCESS_INTERVAL_SECONDS)


async def stop_attendance_worker():
    """Stop the attendance processor worker."""
    global _running
    _running = False
    logger.info("Attendance processor worker stopped")


# =============================================================================
# STANDALONE
# =============================================================================
async def main():
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s [%(levelname)s] %(name)s: %(message)s"
    )
    logger.info("=" * 50)
    logger.info("Attendance Processor - Starting (standalone)")
    logger.info("=" * 50)

    await init_db_pool()
    try:
        await drain_reprocess_queue()
        result = await process_today()
        logger.info(f"Result: {result}")
    finally:
        await close_db_pool()
    logger.info("Done.")


if __name__ == "__main__":
    asyncio.run(main())
