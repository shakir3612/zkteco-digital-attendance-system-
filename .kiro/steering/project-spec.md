# ZKTeco Attendance Management System — Complete Revised Spec

## 1. OVERVIEW

Build a complete attendance management system that communicates with multiple
ZKTeco SpeedFace V5L (and compatible) devices using the PUSH/ADMS protocol.
The system receives data from devices over HTTP, stores it in MySQL, and
provides a web-based admin dashboard for managing employees, attendance,
biometric sync, device monitoring, and report generation.

---

## 2. INFRASTRUCTURE & CONSTRAINTS

- **Server:** Windows PC running XAMPP (Apache + MySQL already in use for other apps)
- **Server IP:** Real/public IP with port forwarding configured
- **Devices:** Multiple ZKTeco SpeedFace V5L terminals
  - Some on local network (same as server)
  - Some on remote locations (different networks, behind NAT)
- All devices configured to push data to server's public IP
- XAMPP Apache and MySQL are shared — do NOT conflict with existing services

### Stack

| Layer | Technology | Port |
|---|---|---|
| Device Communication | Python (FastAPI) | 8015 (port-forwarded) |
| Web Admin Dashboard | PHP (XAMPP Apache) | 80/443 |
| Database | MySQL (XAMPP instance) | 3306 |
| Background Workers | Python (threaded queue or Celery+Redis) | — |

Both Python backend and PHP frontend share the same MySQL database (`attendance_system`).


---

## 3. TIME & TIMEZONE

### Decision: All devices sync clock from server PC

| Setting | Value |
|---|---|
| Server timezone | Asia/Dhaka (UTC+6) |
| All devices timezone | Asia/Dhaka (single timezone) |
| Primary time source for devices | Server PC (via ADMS handshake + daily cron) |
| Backup time source | NTP on each device (e.g., pool.ntp.org) — optional |
| Daily forced re-sync | 3:00 AM server time (cron pushes SET_TIME to all approved devices) |
| Manual sync button | Yes — on device detail page ("Sync Time Now") |
| Server PC time source | Windows default NTP (time.windows.com) — verified during setup |

### How it works:
1. Windows server PC syncs from `time.windows.com` (default, already enabled).
2. On every device handshake (`GET /iclock/cdata?SN=...&options=all`), server response includes `ServerTime=YYYY-MM-DD HH:MM:SS`.
3. Daily at 3:00 AM, server queues `SET_TIME` command for every approved device.
4. Admin can click "Sync Time Now" on any device's detail page for immediate sync.
5. No internet needed on device side beyond reaching the server.


---

## 4. COMMUNICATION PROTOCOL: ZKTeco PUSH/ADMS (iclock)

The device initiates all connections to the server. The server listens and responds.

### 4.1 DEVICE HANDSHAKE / REGISTRATION
```
GET /iclock/cdata?SN={serial}&options=all&pushver=2.4.1&language=en
```
- Check if device serial_number exists in devices table
- If NEW (unknown device):
  - Auto-register with status='pending_approval'
  - Respond with MINIMAL options (no data exchange allowed)
  - Log the connection attempt (IP, time, serial, firmware)
  - Create notification for admin ("New device detected")
- If EXISTING but status != 'approved':
  - Respond with minimal options, do NOT accept or send any data
  - Track last_seen (heartbeat)
- If EXISTING and status='approved':
  - Respond with full server options, stamps, and `ServerTime`
  - Normal operation proceeds

### 4.2 RECEIVE ATTENDANCE LOGS
```
POST /iclock/cdata?SN={serial}&table=ATTLOG&Stamp={stamp}
```
- FIRST: Check device approval status. REJECT if not approved.
- Body: tab-delimited lines: `PIN\tDatetime\tStatus\tVerify\tWorkcode\tReserved1\tReserved2`
- Parse and store each punch record in `attendance_raw`
- Respond with OK and new stamp

### 4.3 RECEIVE BIOMETRIC DATA
```
POST /iclock/cdata?SN={serial}&table=BIODATA&Stamp={stamp}
```
- FIRST: Check device approval status. REJECT if not approved.
- Body: `PIN\tNo\tIndex\tValid\tDuress\tType\tMajorVer\tMinorVer\tFormat\tTmp={base64}`
- Type=9: Face template; Type=1-10: Fingerprint templates
- Store in biometric_templates table
- TRIGGER SYNC: Queue this template to be pushed to ALL OTHER approved devices


### 4.4 RECEIVE USER INFO
```
POST /iclock/cdata?SN={serial}&table=USERTAB&Stamp={stamp}
```
- FIRST: Check device approval status. REJECT if not approved.
- Body: `PIN\tName\tPri\tPasswd\tCard\tGrp\tTZ\tVerify`
- Store/update employee info from device
- If triggered by an import job: apply conflict_mode rules, update job counters

### 4.5 DEVICE POLLS FOR COMMANDS
```
GET /iclock/getrequest?SN={serial}
```
- FIRST: Check device approval status. Return empty if not approved.
- Check command_queue for pending commands for this device
- Return commands one at a time (ordered by priority, then created_at)
- Command types: SET_USER, SET_BIODATA, DELETE_USER, REBOOT, CLEAR_LOG, SET_TIME, QUERY_USERINFO, QUERY_BIODATA, QUERY_ATTLOG, etc.
- If no commands: respond with OK

### 4.6 DEVICE ACKNOWLEDGES COMMAND
```
POST /iclock/devicecmd?SN={serial}
```
- FIRST: Check device approval status. REJECT if not approved.
- Body: `ID={cmd_id}&Return={result_code}`
- Mark command as acknowledged in DB
- Handle success/failure

### 4.7 HEARTBEAT
- Track `last_seen` timestamp for ALL devices (including unapproved ones)
- Updated on every request from device
- Used by device monitoring system to determine online/offline status


---

## 5. DEVICE APPROVAL / AUTHORIZATION SYSTEM

### Device Lifecycle
```
Unknown device connects → auto-registered as 'pending_approval'
Admin reviews → approves or rejects
Approved device → full data exchange begins
Admin can revoke approval at any time → device becomes 'suspended'
```

### Device Statuses
- **pending_approval**: Device connected but not yet authorized. No data exchange.
- **approved**: Fully authorized. Sends/receives attendance, biometrics, commands.
- **rejected**: Admin explicitly denied this device. No data exchange.
- **suspended**: Was approved, now temporarily disabled. No data exchange.
- **inactive**: Decommissioned/removed from service.

### Behavior by Status

| Action | pending | approved | rejected | suspended | inactive |
|---|---|---|---|---|---|
| Heartbeat/ping | track | track | track | track | track |
| Accept ATTLOG | no | YES | no | no | no |
| Accept BIODATA | no | YES | no | no | no |
| Accept USERTAB | no | YES | no | no | no |
| Deliver commands | no | YES | no | no | no |
| Bio sync target | no | YES | no | no | no |
| Show in dashboard | YES | YES | YES | YES | YES |

### On Approval (first time or re-approval)
- Queue ALL active employee user info to the device
- Queue ALL biometric templates to the device
- Mark device as bio sync target going forward
- Optional checkboxes during approval:
  - "Also import existing employees from this device"
  - "Also import existing biometric data from this device"

### On Suspension/Rejection
- Cancel all pending commands for that device
- Remove from bio sync targets
- Optionally: queue a CLEAR_DATA command before suspending (admin choice)


---

## 6. DEVICE MONITORING (Online/Offline Tracking)

### Online/Offline Status Logic (computed from last_seen)

| State | Rule | Color |
|---|---|---|
| Online | last_seen <= 2 min ago | Green |
| Idle | last_seen 2-10 min ago | Yellow |
| Offline | last_seen > 10 min ago | Red |
| Never connected | last_seen IS NULL | Gray |

Threshold configurable via `system_settings` table.

### Background Worker: `device_monitor.py`
Runs every 1 minute:
- Scans all `status='approved'` devices
- If device crosses from online → offline (last_seen > threshold) and no offline notification in last hour:
  - Insert `device_offline` notification
  - Log in audit trail
- When device comes back online:
  - Insert `system` notification: "Device X is back online"

### Dashboard Widget (top of dashboard.php)
```
DEVICE HEALTH                    [auto-refresh 30s]
Online: 7   Idle: 1   Offline: 2   Pending: 1

Offline devices:
  - Warehouse-Gate1 — last seen 2h ago
  - Branch-B-Main — last seen 5h ago
```

### Devices List Page (pages/devices/list.php)
Columns: Status indicator | Name | Serial | Location | IP | Last seen | Punches today | Last punch | Actions
- Filters: status, location, "not sending data today"
- Auto-refresh: every 30 seconds

### Device Detail Page (pages/devices/detail.php)
```
DATA FLOW (last 24 hours):
  Attendance logs received: 87
  Last attendance log: 2:45 PM (3 min ago)
  Biometric pushes: 2
  Last biometric: 11:30 AM
  Polls received: 1,287
  Commands delivered: 4 (2 ack, 2 pending)

  [Sync Time Now] [Re-sync Bio] [Reboot] [Suspend]

CONNECTION LOG (last 50 events)
```

### Inactive Devices Page (pages/devices/inactive.php)
Quick filtered view: approved devices that haven't sent attendance in last 4 hours during work time, or 24 hours otherwise.


---

## 7. IMPORT EMPLOYEES / DATA FROM DEVICES

### Overview
Admin can manually trigger a data pull from any approved device. The server queues a QUERY command; the device responds by pushing its stored data.

### Import Types

| Import | Command queued | Device responds with |
|---|---|---|
| Import Employees | QUERY_USERINFO | POST /iclock/cdata?table=USERTAB |
| Import Biometrics | QUERY_BIODATA | POST /iclock/cdata?table=BIODATA |
| Import Attendance | QUERY_ATTLOG | POST /iclock/cdata?table=ATTLOG |

### Conflict Modes (admin chooses)
- **Skip existing** — only insert new PINs (default, safest)
- **Update existing** — device data overwrites DB
- **Update only blank fields** — fill in missing data only, never overwrite

### UI: Device Detail Page Buttons
```
PULL DATA FROM DEVICE
  [Import Employees]    Pulls all user info (PIN, name, card, privilege)
  [Import Biometrics]   Pulls fingerprints + face templates
  [Import Attendance]   Pulls historical punches

  Last import: 18 May 2026 14:30 by Admin (47 users)
```

### UI: Bulk Import Page (pages/devices/import.php)
1. Pick a device
2. Choose what to import (employees / biometrics / attendance)
3. Choose conflict mode
4. Click "Start Import"
5. See progress + summary when done:
   - Imported: X new
   - Updated: Y existing
   - Skipped: Z
   - Errors: N

### Auto-Import on First Approval
During device approval, admin sees optional checkboxes:
- [ ] Also import existing employees from this device
- [ ] Also import existing biometric data from this device

### Import for Attendance
Device stores recent attendance logs (100K+ records).
Admin can choose date range when importing attendance to avoid overload.

### Imported Employees — Missing Fields
Device only knows: PIN, name, password, card, privilege, group.
Extra fields (department, designation, phone, email, join_date) stay blank.
Admin fills in later via employee management page.
Device "group" field → mapped to "Default" department.


---

## 8. BIOMETRIC SYNC LOGIC

When biometric data is received from ANY approved device:
1. Store/update the template in `biometric_templates` (master copy)
2. For EVERY OTHER device with status='approved':
   a. Check if user info exists on target device (via `device_employees`)
   b. If not, queue SET_USER command first
   c. Then queue SET_BIODATA command with the template data
3. When target device polls (GET /iclock/getrequest), deliver commands in order:
   - User info first, then biometric data

When a device is NEWLY APPROVED:
1. Queue ALL existing employee user info to that device
2. Queue ALL biometric templates to that device
3. Commands delivered gradually as device polls

When an employee is DELETED:
1. Queue DELETE_USER command to ALL approved devices

Sync status tracking:
- `device_employees` table tracks which employees are synced to which device
- `device_commands` tracks delivery status (pending → delivered → acknowledged)


---

## 9. SHIFTS & ATTENDANCE PROCESSING

### Default Shift
- **Regular 9-5**: start=09:00, end=17:00, grace_late=30min, grace_early=30min, full_day=8hr
- Auto-assigned to every new employee
- Admin can override per employee

### Multiple Shifts
- Admin can add/edit/delete shifts via dashboard (pages/shifts/)
- Each shift has: name, start_time, end_time, grace_minutes_late, grace_minutes_early, full_day_hours, is_night_shift
- **No half-day concept** — removed entirely

### Grace Period
- Default: **30 minutes** for both check-in and check-out
- Configurable per shift via admin UI
- Example: shift 9:00, grace 30 min → late only if first punch after 9:30 AM

### Punch Pairing Rule
- **First punch of the day = check-in (first_in)**
- **Last punch of the day = check-out (last_out)**
- Middle punches stored in `attendance_raw` but ignored for daily calculation

### Weekend Days
- **Friday + Saturday** (configurable via `system_settings.weekly_off_days = 'fri,sat'`)

### Daily Processing Rules (cron job)

For each employee, each date:
```
IF date is in holidays table           → status = 'holiday'
ELSE IF day-of-week is Fri or Sat      → status = 'weekend'
ELSE IF employee has approved leave    → status = 'on_leave'
ELSE IF no punches                     → status = 'absent'
ELSE:
    status = 'present'
    first_in = MIN(punches.punch_time)
    last_out = MAX(punches.punch_time)

    IF first_in == last_out (single punch):
        single_punch = TRUE
        last_out = NULL
        total_hours = NULL
    ELSE:
        total_hours = last_out - first_in (hours)

    IF first_in > shift.start_time + grace_minutes_late:
        was_late = TRUE
        late_minutes = (first_in - shift.start_time) in minutes

    IF last_out IS NOT NULL AND last_out < shift.end_time - grace_minutes_early:
        left_early = TRUE
        early_minutes = (shift.end_time - last_out) in minutes
```

### No Overtime
- No overtime tracking or policy
- Only present or absent as core status
- `total_hours` stored for informational purposes only


---

## 10. REPORTS

### Primary Report: Single Employee Attendance Report (PDF)

```
[Company Logo]    EMPLOYEE ATTENDANCE REPORT

Employee: Md. Rahim Uddin (PIN: 1042)
Department: Operations    Designation: Officer
Shift: Regular 9-5 (09:00 - 17:00)
Period: 1 May 2026 - 31 May 2026
Generated: 20 May 2026 14:32

SUMMARY
  Total work days        : 22
  Days present           : 19
  Days absent            : 1
  Days on leave          : 2
  Days late              : 4
  Days left early        : 1
  Holidays               : 1
  Weekends               : 8
  Total late minutes     : 87
  Total early minutes    : 22
  Total hours worked     : 152.5

DAILY DETAIL
Date       Day  In      Out     Hours  Status    Flags
01 May    Fri  —       —       —      Weekend
02 May    Sat  —       —       —      Weekend
03 May    Sun  09:25   17:10   7.75   Present   Late
04 May    Mon  09:00   17:00   8.00   Present
05 May    Tue  09:45   17:00   7.25   Present   Late
06 May    Wed  —       —       —      Absent
07 May    Thu  09:00   16:30   7.50   Present   Early
...
```

PDF generation: TCPDF or DomPDF (PHP library).

### Custom Report Builder (pages/reports/custom.php)

**Filters (sidebar):**
- Date range (from / to)
- Employee(s) — multi-select or all
- Department(s) — multi-select
- Shift — optional filter
- Status — multi-select (present, late, early_leave, absent, on_leave, holiday, weekend)

**Selectable Columns (checkboxes):**
- Employee PIN
- Employee name
- Department
- Designation
- Shift
- Total work days in range
- Days present
- Days late
- Days early leave
- Days absent
- Days on leave
- Days holiday
- Days weekend
- Total hours worked
- Average daily hours
- Total late minutes
- Total early leave minutes

**Grouping options:**
- One row per employee (summary, default)
- One row per employee per day (detailed)

**Export formats:**
- View on screen (HTML table)
- Download as Excel (.xlsx), PDF, or CSV

### Saved Report Templates
Admin can save a column/filter combination as a named template for one-click re-running.
Stored in `report_templates` table.


---

## 11. DATABASE SCHEMA (MySQL)

Database name: `attendance_system`

### system_settings
```sql
CREATE TABLE system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    description VARCHAR(255),
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP
);
-- Seeds: weekly_off_days='fri,sat', offline_threshold_minutes=10, company_name, company_logo_path, timezone='Asia/Dhaka'
```

### users
```sql
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('super_admin', 'admin') NOT NULL,
    name VARCHAR(100),
    email VARCHAR(100),
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_login DATETIME
);
```

### devices
```sql
CREATE TABLE devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    serial_number VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(100),
    location VARCHAR(200),
    ip_address VARCHAR(45),
    firmware_ver VARCHAR(50),
    push_ver VARCHAR(20),
    model VARCHAR(50),
    last_seen DATETIME,
    status ENUM('pending_approval','approved','rejected','suspended','inactive') DEFAULT 'pending_approval',
    approved_by INT,
    approved_at DATETIME,
    notes TEXT,
    registered_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_serial (serial_number)
);
```

### device_connection_log
```sql
CREATE TABLE device_connection_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_sn VARCHAR(50) NOT NULL,
    ip_address VARCHAR(45),
    action VARCHAR(50),
    was_allowed BOOLEAN,
    details TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_device_time (device_sn, created_at)
);
```


### departments
```sql
CREATE TABLE departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    parent_id INT,
    status ENUM('active', 'inactive') DEFAULT 'active'
);
```

### employees
```sql
CREATE TABLE employees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pin VARCHAR(20) UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL,
    department_id INT,
    designation VARCHAR(100),
    card_number VARCHAR(50),
    privilege INT DEFAULT 0,
    phone VARCHAR(20),
    email VARCHAR(100),
    join_date DATE,
    status ENUM('active', 'inactive', 'terminated') DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP
);
```

### biometric_templates
```sql
CREATE TABLE biometric_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pin VARCHAR(20) NOT NULL,
    bio_type INT NOT NULL,
    bio_no INT DEFAULT 0,
    bio_index INT DEFAULT 0,
    valid INT DEFAULT 1,
    duress INT DEFAULT 0,
    major_ver INT,
    minor_ver INT,
    format INT DEFAULT 0,
    template LONGTEXT NOT NULL,
    source_device_sn VARCHAR(50),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_bio (pin, bio_type, bio_no, bio_index)
);
```

### device_employees
```sql
CREATE TABLE device_employees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_sn VARCHAR(50) NOT NULL,
    pin VARCHAR(20) NOT NULL,
    user_synced BOOLEAN DEFAULT FALSE,
    bio_synced BOOLEAN DEFAULT FALSE,
    synced_at DATETIME,
    UNIQUE KEY unique_device_emp (device_sn, pin)
);
```


### device_commands
```sql
CREATE TABLE device_commands (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_sn VARCHAR(50) NOT NULL,
    command_type VARCHAR(50) NOT NULL,
    command_content LONGTEXT NOT NULL,
    priority INT DEFAULT 5,
    status ENUM('pending','delivered','acknowledged','failed','cancelled') DEFAULT 'pending',
    attempts INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    delivered_at DATETIME,
    acknowledged_at DATETIME,
    result_code INT,
    INDEX idx_device_status (device_sn, status),
    INDEX idx_priority (priority, created_at)
);
```

### attendance_raw
```sql
CREATE TABLE attendance_raw (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_sn VARCHAR(50) NOT NULL,
    pin VARCHAR(20) NOT NULL,
    punch_time DATETIME NOT NULL,
    status INT DEFAULT 0,
    verify_type INT DEFAULT 0,
    work_code VARCHAR(20),
    reserved1 VARCHAR(20),
    reserved2 VARCHAR(20),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_punch (pin, punch_time, device_sn),
    INDEX idx_pin_time (pin, punch_time),
    INDEX idx_device_time (device_sn, punch_time)
);
```

### attendance_daily
```sql
CREATE TABLE attendance_daily (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    pin VARCHAR(20) NOT NULL,
    date DATE NOT NULL,
    first_in DATETIME,
    last_out DATETIME,
    total_hours DECIMAL(5,2),
    status ENUM('present','absent','on_leave','holiday','weekend') DEFAULT 'absent',
    was_late BOOLEAN DEFAULT FALSE,
    late_minutes INT DEFAULT 0,
    left_early BOOLEAN DEFAULT FALSE,
    early_minutes INT DEFAULT 0,
    single_punch BOOLEAN DEFAULT FALSE,
    shift_id INT,
    remarks VARCHAR(255),
    processed_at DATETIME,
    UNIQUE KEY unique_daily (pin, date),
    INDEX idx_date (date)
);
```


### shifts
```sql
CREATE TABLE shifts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    grace_minutes_late INT DEFAULT 30,
    grace_minutes_early INT DEFAULT 30,
    full_day_hours DECIMAL(4,2) DEFAULT 8.00,
    is_night_shift BOOLEAN DEFAULT FALSE,
    status ENUM('active', 'inactive') DEFAULT 'active'
);
-- Seed: INSERT INTO shifts VALUES (1,'Regular 9-5','09:00:00','17:00:00',30,30,8.00,FALSE,'active');
```

### employee_shifts
```sql
CREATE TABLE employee_shifts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    shift_id INT NOT NULL,
    effective_from DATE NOT NULL,
    effective_to DATE,
    UNIQUE KEY unique_assignment (employee_id, effective_from)
);
```

### leaves
```sql
CREATE TABLE leaves (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    leave_type ENUM('casual','sick','annual','unpaid','maternity','other') NOT NULL,
    from_date DATE NOT NULL,
    to_date DATE NOT NULL,
    days DECIMAL(4,1) NOT NULL,
    reason VARCHAR(500),
    status ENUM('pending','approved','rejected','cancelled') DEFAULT 'pending',
    approved_by INT,
    applied_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    actioned_at DATETIME
);
```

### holidays
```sql
CREATE TABLE holidays (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    date DATE NOT NULL UNIQUE,
    type ENUM('public','optional','restricted') DEFAULT 'public'
);
```

### device_stamps
```sql
CREATE TABLE device_stamps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_sn VARCHAR(50) NOT NULL,
    table_name VARCHAR(50) NOT NULL,
    stamp VARCHAR(50) DEFAULT '0',
    UNIQUE KEY unique_stamp (device_sn, table_name)
);
```


### import_jobs
```sql
CREATE TABLE import_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_sn VARCHAR(50) NOT NULL,
    job_type ENUM('users','biometrics','attendance') NOT NULL,
    conflict_mode ENUM('skip','update','update_blank') DEFAULT 'skip',
    status ENUM('queued','running','completed','failed','partial') DEFAULT 'queued',
    requested_by INT NOT NULL,
    records_received INT DEFAULT 0,
    records_inserted INT DEFAULT 0,
    records_updated INT DEFAULT 0,
    records_skipped INT DEFAULT 0,
    records_failed INT DEFAULT 0,
    started_at DATETIME,
    completed_at DATETIME,
    error_message TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_device (device_sn, created_at)
);
```

### report_templates
```sql
CREATE TABLE report_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    created_by INT NOT NULL,
    columns_json JSON,
    filters_json JSON,
    grouping VARCHAR(20) DEFAULT 'summary',
    is_shared BOOLEAN DEFAULT FALSE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

### audit_log
```sql
CREATE TABLE audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(100) NOT NULL,
    target_type VARCHAR(50),
    target_id INT,
    details TEXT,
    ip_address VARCHAR(45),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_action (action, created_at)
);
```

### notifications
```sql
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('new_device','device_offline','device_online','sync_failed','system') NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT,
    target_role ENUM('super_admin','admin','all') DEFAULT 'all',
    is_read BOOLEAN DEFAULT FALSE,
    read_by INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_unread (is_read, created_at)
);
```

### View: v_device_today_stats
```sql
CREATE OR REPLACE VIEW v_device_today_stats AS
SELECT
    device_sn,
    COUNT(*) AS punches_today,
    MAX(punch_time) AS last_punch_at
FROM attendance_raw
WHERE DATE(punch_time) = CURDATE()
GROUP BY device_sn;
```


---

## 12. USER ROLES & PERMISSIONS

### Super Admin (1 user)
- All admin permissions
- Create/edit/delete admin accounts
- Approve/reject/suspend devices
- System settings (company info, policies, server config)
- Database backup/restore
- View audit logs
- View device connection logs

### Admin (3 users)
- Manage employees (add, edit, deactivate)
- View device status (online/offline, sync progress)
- Approve/reject/suspend devices (if super_admin grants this — configurable in system settings)
- Trigger manual biometric sync
- Import data from devices
- Manage shifts and assign to employees
- Manage leaves (approve/reject)
- View and generate reports
- Manage departments and holidays

---

## 13. PROJECT STRUCTURE

```
attendance_system/
├── python_server/                  # FastAPI - Device Communication
│   ├── main.py                     # FastAPI app entry point
│   ├── config.py                   # DB connection, server settings
│   ├── database.py                 # MySQL connection (SQLAlchemy or aiomysql)
│   ├── middleware/
│   │   └── device_auth.py          # Check device approval status
│   ├── routes/
│   │   ├── iclock.py               # All /iclock/* endpoints
│   │   └── api.py                  # Internal API (for PHP dashboard)
│   ├── services/
│   │   ├── attendance.py           # Parse and store attendance logs
│   │   ├── biometric.py            # Handle bio data, trigger sync
│   │   ├── command.py              # Build and queue device commands
│   │   ├── device.py               # Device registration, approval, status
│   │   ├── sync.py                 # Biometric distribution logic
│   │   ├── importer.py             # Import job handling
│   │   └── notification.py         # Create alerts
│   ├── workers/
│   │   ├── sync_worker.py          # Background bio sync processor
│   │   ├── attendance_processor.py # Daily attendance pairing (cron)
│   │   └── device_monitor.py       # Online/offline detection (every 1 min)
│   ├── models/
│   │   └── tables.py               # SQLAlchemy models
│   ├── requirements.txt
│   └── run.py                      # Uvicorn launcher
│
├── php_dashboard/                  # PHP - Web Admin Dashboard
│   ├── index.php                   # Login page
│   ├── config/
│   │   └── database.php            # MySQL connection config
│   ├── includes/
│   │   ├── auth.php                # Session, role checking
│   │   ├── header.php
│   │   └── footer.php
│   ├── pages/
│   │   ├── dashboard.php           # Overview + device health widget
│   │   ├── employees/              # CRUD
│   │   ├── devices/
│   │   │   ├── list.php            # All devices with status + online indicator
│   │   │   ├── pending.php         # Pending approval queue
│   │   │   ├── approve.php         # Approve/reject action (with import checkboxes)
│   │   │   ├── detail.php          # Device detail, data flow, connection log
│   │   │   ├── manage.php          # Suspend, reactivate, rename, location
│   │   │   ├── import.php          # Pull data from device
│   │   │   └── inactive.php        # Devices not sending data
│   │   ├── attendance/             # Daily view
│   │   ├── shifts/                 # Shift management
│   │   ├── leaves/                 # Leave management
│   │   ├── reports/
│   │   │   ├── employee.php        # Single employee PDF report
│   │   │   └── custom.php          # Custom report builder
│   │   ├── holidays/               # Holiday calendar
│   │   └── settings/               # Admin management, system config
│   ├── api/
│   │   └── internal.php            # PHP calls to Python server
│   ├── assets/
│   │   ├── css/
│   │   ├── js/
│   │   └── img/
│   └── .htaccess
│
├── database/
│   ├── schema.sql                  # Full DB creation script
│   ├── seed.sql                    # Default super_admin, default shift, settings
│   └── migrations/
│
├── docs/
│   ├── setup.md                    # Installation guide
│   ├── device_config.md            # How to configure devices
│   └── api_reference.md            # Internal API docs
│
└── README.md
```


---

## 14. IMPLEMENTATION PHASES

### Phase 1: Foundation + Device Authorization
- Set up MySQL database (schema, indexes, seed super_admin, default shift, system_settings)
- Python FastAPI server with /iclock endpoints
- Device auto-registration (status='pending_approval')
- Device approval middleware (block unapproved devices)
- Receive and store raw attendance logs (approved devices only)
- Device connection logging
- Heartbeat / last_seen tracking
- ServerTime delivery on handshake
- Test with one device

### Phase 2: Dashboard — Device Management + Monitoring
- PHP login system with role-based access
- Dashboard with device health widget (online/idle/offline counts)
- "Pending devices" alert/notification
- Device list page (status badges + live online/offline indicator)
- Approve/reject/suspend actions (with import checkboxes on approval)
- Device detail page (data flow stats, connection history, last seen, IP)
- Inactive devices page (not sending data)
- Auto-refresh (30 sec)
- Device monitor background worker (offline detection + notifications)
- "Sync Time Now" button

### Phase 3: Biometric Sync Engine + Import
- Receive biometric templates from approved devices
- Store in master table
- Command queue system
- Distribute templates to all other approved devices
- On device approval: queue full employee + bio sync
- On device suspension: cancel pending commands
- Track sync status per device
- Import employees from device (QUERY_USERINFO)
- Import biometrics from device (QUERY_BIODATA)
- Import attendance from device (QUERY_ATTLOG)
- Import jobs tracking (progress, summary)
- Conflict mode handling

### Phase 4: Employee Management + Shifts
- Employee CRUD (add from dashboard → push to all approved devices)
- Department management
- Shift CRUD (add/edit/delete shifts)
- Default 9-5 shift auto-assignment
- Assign shifts to employees
- Employee-device sync status view
- Manual re-sync trigger

### Phase 5: Attendance Processing
- Cron job to pair raw punches into daily records
- Apply shift rules: late (was_late flag), early leave (left_early flag), single punch detection
- Absent = no punches + not holiday + not weekend + not on leave
- Weekend logic (Friday + Saturday from system_settings)
- Holiday check
- Leave check
- Daily attendance view in dashboard

### Phase 6: Reports + Leave + Holiday Management
- Single employee attendance report (PDF) — PRIMARY
- Custom report builder (selectable columns, filters, grouping)
- Export: PDF, Excel (.xlsx), CSV
- Saved report templates
- Leave application and approval workflow
- Holiday calendar management (add/edit/delete holidays)

### Phase 7: Polish & Security
- Audit logging for all admin actions
- Rate limiting on device registration
- Input validation and error handling
- Dashboard UI refinement
- Daily SET_TIME cron (3:00 AM)
- Backup strategy
- Documentation (setup.md, device_config.md, api_reference.md)


---

## 15. KEY TECHNICAL NOTES

1. **PORT SEPARATION**: Python FastAPI on port 8015. PHP dashboard on XAMPP Apache (80/443). Port-forward 8015 on router for device access.

2. **DEVICE CONFIG**: Each ZKTeco device set ADMS server to: `http://YOUR_PUBLIC_IP:8015/iclock`

3. **DEVICE AUTHORIZATION MIDDLEWARE**: Every /iclock request passes through a check that verifies device_sn exists AND status='approved'. Exception: initial handshake auto-registers unknown devices.

4. **STAMP MECHANISM**: Server tracks last received record stamp per device per table. On reconnect, device sends all records after that stamp. Ensures no data loss during downtime.

5. **COMMAND DELIVERY**: Commands delivered one at a time when device polls. Order: user info before biometric data. Only delivered to approved devices.

6. **TEMPLATE FORMAT**: Bio templates are base64-encoded binary. Store as LONGTEXT. Do NOT decode — pass through as-is.

7. **DUPLICATE HANDLING**: Use UNIQUE keys and INSERT IGNORE / ON DUPLICATE KEY UPDATE.

8. **TIMEZONE**: Asia/Dhaka (UTC+6). All devices same timezone as server. Server syncs from Windows NTP. Devices sync from server.

9. **SECURITY**:
   - Reject data from unapproved devices (log the attempt)
   - Rate-limit new device registrations from same IP
   - Dashboard alerts for new device connections
   - All approval/suspension actions logged in audit_log
   - Consider IP whitelist for known device locations (optional)

10. **ON APPROVAL TRIGGER**: Queue all employee data + biometric templates to device. Process in background with priority ordering.

11. **ATTENDANCE PAIRING**: First punch = in, last punch = out. Single punch flagged. No half-day. No overtime.

12. **GRACE PERIOD**: Default 30 min for late and early. Configurable per shift. Late/early are flags on 'present' status.

13. **WEEKENDS**: Friday + Saturday (configurable via system_settings).

14. **OFFLINE DETECTION**: Background worker every 1 min. Threshold: 10 min (configurable). Creates notification on transition to offline and back to online.

15. **IMPORT FROM DEVICE**: Server queues QUERY commands. Device responds by pushing data. Conflict modes: skip/update/update_blank. Admin controls from dashboard.

---

## 16. SEED DATA (database/seed.sql)

```sql
-- Super Admin
INSERT INTO users (username, password_hash, role, name, status)
VALUES ('admin', '$2y$10$...hashed...', 'super_admin', 'System Administrator', 'active');

-- Default Shift
INSERT INTO shifts (name, start_time, end_time, grace_minutes_late, grace_minutes_early, full_day_hours, is_night_shift, status)
VALUES ('Regular 9-5', '09:00:00', '17:00:00', 30, 30, 8.00, FALSE, 'active');

-- System Settings
INSERT INTO system_settings (setting_key, setting_value, description) VALUES
('weekly_off_days', 'fri,sat', 'Days of week that are off (comma-separated: mon,tue,wed,thu,fri,sat,sun)'),
('offline_threshold_minutes', '10', 'Minutes after which device is considered offline'),
('company_name', 'My Company', 'Company name shown in reports and dashboard'),
('company_logo_path', 'assets/img/logo.png', 'Path to company logo for PDF reports'),
('timezone', 'Asia/Dhaka', 'Server timezone'),
('device_approval_by_admin', '0', '1=admins can approve devices, 0=only super_admin'),
('time_sync_hour', '3', 'Hour (0-23) for daily device time sync cron');

-- Default Department
INSERT INTO departments (name, status) VALUES ('Default', 'active');
```

---

*End of spec. This document is the single source of truth for all implementation decisions.*
