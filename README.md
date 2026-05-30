# ZKTeco Digital Attendance Management System

A complete attendance management system that communicates with multiple ZKTeco SpeedFace V5L (and compatible) devices using the PUSH/ADMS protocol. The system receives data from devices over HTTP, stores it in MySQL, and provides a web-based admin dashboard.

## Features

- **Multi-Device Support** — manage multiple ZKTeco devices across local and remote locations
- **Device Authorization** — auto-register unknown devices, approve/reject/suspend via dashboard
- **Real-Time Monitoring** — live online/idle/offline status for all devices (30s auto-refresh)
- **Biometric Sync** — face/fingerprint templates automatically distributed to all approved devices
- **Import from Devices** — pull existing employees, biometrics, and attendance from running devices
- **Attendance Processing** — automatic punch pairing every 5 minutes with configurable shift rules
- **Manual Punch Entry** — add attendance for employees who missed punching
- **Shift Overrides** — temporary office hours for govt orders, Ramadan, etc. (applies to all employees automatically)
- **Employee Management** — CRUD with automatic push to all devices on add/edit/delete
- **Shift Management** — multiple shifts with per-shift grace periods (default: 9 AM–5 PM, with grace)
- **Leave Management** — apply/approve/reject/edit/cancel with per-employee summary
- **Holiday Calendar** — manage public holidays
- **Reports** — single employee A4 report (summary + filtered detail)
- **Notifications** — device offline/online alerts, mark all as read
- **Audit Trail** — all admin actions logged
- **Daily Time Sync** — automatic SET_TIME push to all devices at configurable hour

## Architecture

```
┌─────────────────────┐     HTTP (port 8015)     ┌─────────────────────┐
│  ZKTeco Devices     │ ──────────────────────── │  Python FastAPI     │
│  (SpeedFace V5L)    │   PUSH/ADMS protocol     │  Server             │
└─────────────────────┘                           └────────┬────────────┘
                                                           │
                                                    MySQL Database
                                                    (attendance_system)
                                                           │
                                                  ┌────────┴────────────┐
                                                  │  PHP Dashboard      │
                                                  │  (XAMPP Apache:80)  │
                                                  └─────────────────────┘
```

## Tech Stack

| Layer | Technology |
|---|---|
| Device Communication | Python 3.9+ / FastAPI / Uvicorn (port 8015) |
| Web Dashboard | PHP 7.4+ / XAMPP Apache (port 80) |
| Database | MySQL 5.7+ (XAMPP) |
| Background Workers | Python asyncio tasks (4 workers) |

## Background Workers (Automatic)

| Worker | Interval | Purpose |
|---|---|---|
| Sync Worker | Every 5 seconds | Distributes biometric templates to devices |
| Device Monitor | Every 60 seconds | Detects offline devices, creates notifications |
| Attendance Processor | Every 5 minutes | Pairs raw punches into daily records |
| Time Sync | Once per day | Pushes SET_TIME to all devices at configured hour |

## Quick Start

1. **Database:** Run `database/schema.sql` then `database/seed.sql` in MySQL
2. **Python Server:** `cd python_server && pip install -r requirements.txt && python run.py`
3. **PHP Dashboard:** Copy/link `php_dashboard/` to XAMPP htdocs as `attendance`
4. **Login:** username `admin`, password `admin123` (change immediately!)
5. **Configure Devices:** Set ADMS URL to `http://YOUR_IP:8015/iclock`

See [docs/setup.md](docs/setup.md) for detailed installation instructions.

## Project Structure

```
├── python_server/          # FastAPI — Device communication + Internal API
│   ├── routes/             # /iclock endpoints + /api internal endpoints
│   ├── services/           # Business logic (sync, commands, attendance, import)
│   ├── workers/            # Background tasks (sync, monitor, attendance, time sync)
│   └── middleware/         # Device authorization checks
├── php_dashboard/          # PHP — Admin web interface
│   ├── pages/              # All dashboard pages (devices, employees, reports, etc.)
│   ├── includes/           # Auth, header, footer
│   ├── api/                # PHP-to-Python bridge
│   └── assets/             # CSS, JS
├── database/               # SQL schema + seed data
└── docs/                   # Setup guide, device config, API reference
```

## Documentation

- [Installation Guide](docs/setup.md)
- [Device Configuration](docs/device_config.md)
- [API Reference](docs/api_reference.md)

## Key Decisions

- **Timezone:** Asia/Dhaka — all devices sync clock from server
- **Weekend:** Friday + Saturday (configurable from Settings)
- **Grace Period:** 30 minutes for check-in and check-out (configurable per shift)
- **Punch Logic:** First punch = in, last punch = out (middle punches stored but ignored)
- **No half-day / no overtime** — status is present or absent, with late/early flags
- **Offline threshold:** 10 minutes (configurable) — triggers dashboard notification
- **Attendance processing:** Automatic every 5 minutes (no cron/Task Scheduler needed)
- **Time sync:** Automatic daily at configured hour + on every device handshake via ServerTime

## Default Credentials

| Username | Password | Role |
|---|---|---|
| admin | admin123 | super_admin |

**Change the password immediately after first login.**

## Version

**v1.2.0** — See [changelog.txt](changelog.txt) for full change history.

## Developer

Shakir Hossain — +8801946887117

## License

Private project. All rights reserved.
