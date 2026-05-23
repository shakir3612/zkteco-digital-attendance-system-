"""
Command Service - builds and queues device commands.
Commands are delivered one at a time when device polls GET /iclock/getrequest.
"""

import logging
from datetime import datetime
from database import get_db

logger = logging.getLogger(__name__)


async def queue_command(device_sn: str, command_type: str,
                       command_content: str, priority: int = 5) -> int:
    """
    Queue a command for a device. Returns the command ID.
    Lower priority number = delivered first.
    """
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                """INSERT INTO device_commands
                   (device_sn, command_type, command_content, priority, status, created_at)
                   VALUES (%s, %s, %s, %s, 'pending', %s)""",
                (device_sn, command_type, command_content, priority, datetime.now())
            )
            cmd_id = cur.lastrowid
    logger.debug(f"Command queued: ID={cmd_id}, device={device_sn}, type={command_type}")
    return cmd_id


async def queue_set_user_command(device_sn: str, pin: str, name: str = "",
                                privilege: int = 0, card: str = "",
                                priority: int = 3) -> int:
    """
    Queue a SET_USER command to push user info to a device.
    Format: DATA UPDATE USERINFO PIN={pin}\tName={name}\tPri={pri}\tPasswd=\tCard={card}\tGrp=1\tTZ=0000000100000000
    """
    content = (
        f"DATA UPDATE USERINFO PIN={pin}\tName={name}\t"
        f"Pri={privilege}\tPasswd=\tCard={card}\tGrp=1\t"
        f"TZ=0000000100000000"
    )
    return await queue_command(device_sn, "SET_USER", content, priority)


async def queue_set_biodata_command(device_sn: str, pin: str, bio_type: int,
                                   bio_no: int, bio_index: int,
                                   template: str, priority: int = 4) -> int:
    """
    Queue a SET_BIODATA command to push biometric data to a device.
    Format: DATA UPDATE BIODATA PIN={pin}\tNo={no}\tIndex={index}\tValid=1\tDuress=0\tType={type}\tTmp={base64}
    """
    content = (
        f"DATA UPDATE BIODATA PIN={pin}\tNo={bio_no}\t"
        f"Index={bio_index}\tValid=1\tDuress=0\t"
        f"Type={bio_type}\tTmp={template}"
    )
    return await queue_command(device_sn, "SET_BIODATA", content, priority)


async def queue_delete_user_command(device_sn: str, pin: str,
                                   priority: int = 2) -> int:
    """Queue a DELETE_USER command."""
    content = f"DATA DELETE USERINFO PIN={pin}"
    return await queue_command(device_sn, "DELETE_USER", content, priority)


async def queue_set_time_command(device_sn: str, priority: int = 1) -> int:
    """Queue a SET_TIME command with current server time."""
    now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    content = f"SET TIME {now}"
    return await queue_command(device_sn, "SET_TIME", content, priority)


async def queue_reboot_command(device_sn: str, priority: int = 2) -> int:
    """Queue a REBOOT command."""
    return await queue_command(device_sn, "REBOOT", "REBOOT", priority)


async def queue_clear_log_command(device_sn: str, priority: int = 3) -> int:
    """Queue a CLEAR_LOG command."""
    return await queue_command(device_sn, "CLEAR_LOG", "CLEAR LOG", priority)


async def queue_query_userinfo_command(device_sn: str, priority: int = 2) -> int:
    """Queue a QUERY_USERINFO command to pull users from device."""
    return await queue_command(device_sn, "QUERY_USERINFO",
                              "DATA QUERY USERINFO", priority)


async def queue_query_biodata_command(device_sn: str, priority: int = 3) -> int:
    """Queue a QUERY_BIODATA command to pull biometrics from device."""
    return await queue_command(device_sn, "QUERY_BIODATA",
                              "DATA QUERY BIODATA", priority)


async def queue_query_attlog_command(device_sn: str, priority: int = 2) -> int:
    """Queue a QUERY_ATTLOG command to pull attendance from device."""
    return await queue_command(device_sn, "QUERY_ATTLOG",
                              "DATA QUERY ATTLOG", priority)


async def get_pending_commands_count(device_sn: str) -> int:
    """Get count of pending commands for a device."""
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                "SELECT COUNT(*) as cnt FROM device_commands WHERE device_sn = %s AND status = 'pending'",
                (device_sn,)
            )
            row = await cur.fetchone()
            return row["cnt"]


async def get_command_stats(device_sn: str) -> dict:
    """Get command statistics for a device."""
    async with get_db() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                """SELECT status, COUNT(*) as cnt FROM device_commands
                   WHERE device_sn = %s GROUP BY status""",
                (device_sn,)
            )
            rows = await cur.fetchall()
            return {row["status"]: row["cnt"] for row in rows}
