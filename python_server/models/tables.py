"""
Data models / dataclasses representing database entities.
Used for type safety and passing data between services.
"""

from dataclasses import dataclass, field
from datetime import datetime, date, time
from typing import Optional


@dataclass
class Device:
    id: int = 0
    serial_number: str = ""
    name: Optional[str] = None
    location: Optional[str] = None
    ip_address: Optional[str] = None
    firmware_ver: Optional[str] = None
    push_ver: Optional[str] = None
    model: Optional[str] = None
    last_seen: Optional[datetime] = None
    status: str = "pending_approval"
    approved_by: Optional[int] = None
    approved_at: Optional[datetime] = None
    notes: Optional[str] = None
    registered_at: Optional[datetime] = None


@dataclass
class DeviceConnectionLog:
    id: int = 0
    device_sn: str = ""
    ip_address: Optional[str] = None
    action: str = ""
    was_allowed: bool = False
    details: Optional[str] = None
    created_at: Optional[datetime] = None


@dataclass
class Employee:
    id: int = 0
    pin: str = ""
    name: str = ""
    department_id: Optional[int] = None
    designation: Optional[str] = None
    card_number: Optional[str] = None
    privilege: int = 0
    phone: Optional[str] = None
    email: Optional[str] = None
    join_date: Optional[date] = None
    status: str = "active"
    created_at: Optional[datetime] = None
    updated_at: Optional[datetime] = None


@dataclass
class AttendanceRaw:
    id: int = 0
    device_sn: str = ""
    pin: str = ""
    punch_time: Optional[datetime] = None
    status: int = 0
    verify_type: int = 0
    work_code: Optional[str] = None
    reserved1: Optional[str] = None
    reserved2: Optional[str] = None
    created_at: Optional[datetime] = None


@dataclass
class BiometricTemplate:
    id: int = 0
    pin: str = ""
    bio_type: int = 0
    bio_no: int = 0
    bio_index: int = 0
    valid: int = 1
    duress: int = 0
    major_ver: Optional[int] = None
    minor_ver: Optional[int] = None
    format: int = 0
    template: str = ""
    source_device_sn: Optional[str] = None
    created_at: Optional[datetime] = None
    updated_at: Optional[datetime] = None


@dataclass
class DeviceCommand:
    id: int = 0
    device_sn: str = ""
    command_type: str = ""
    command_content: str = ""
    priority: int = 5
    status: str = "pending"
    attempts: int = 0
    created_at: Optional[datetime] = None
    delivered_at: Optional[datetime] = None
    acknowledged_at: Optional[datetime] = None
    result_code: Optional[int] = None


@dataclass
class DeviceStamp:
    id: int = 0
    device_sn: str = ""
    table_name: str = ""
    stamp: str = "0"


@dataclass
class Notification:
    id: int = 0
    type: str = ""
    title: str = ""
    message: Optional[str] = None
    target_role: str = "all"
    is_read: bool = False
    read_by: Optional[int] = None
    created_at: Optional[datetime] = None
