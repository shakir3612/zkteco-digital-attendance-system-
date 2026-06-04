# Installation & Setup Guide

## Prerequisites

- **Windows PC** with XAMPP installed (Apache + MySQL running)
- **Python 3.9+** installed on the same PC
- **Port 8015** available (not used by other applications)
- **Router access** for port forwarding (if devices are on remote networks)

---

## Step 1: Database Setup

1. Open phpMyAdmin (http://localhost/phpmyadmin) or MySQL CLI.

2. Run the schema file to create all tables:
   ```sql
   SOURCE C:/path/to/attendance_system/database/schema.sql;
   ```

3. Run the seed file to create default admin + settings:
   ```sql
   SOURCE C:/path/to/attendance_system/database/seed.sql;
   ```

4. Verify the database exists:
   ```sql
   USE attendance_system;
   SHOW TABLES;
   -- Should show 20 tables
   ```

---

## Step 2: Python Server Setup

1. Open Command Prompt, navigate to the python_server folder:
   ```cmd
   cd C:\path\to\attendance_system\python_server
   ```

2. Create a virtual environment (recommended):
   ```cmd
   python -m venv venv
   venv\Scripts\activate
   ```

3. Install dependencies:
   ```cmd
   pip install -r requirements.txt
   ```

4. Configure via `.env` file (already present in `python_server/`):
   - Open `python_server/.env` in any text editor
   - Defaults work for a fresh XAMPP install (blank password, port 3306)
   - Change `DB_PASSWORD` if your MySQL has a root password
   - Change `SERVER_PORT` only if 8015 is taken

5. Start the server:
   ```cmd
   python run.py
   ```

6. Verify it's running: open http://localhost:8015 in browser.
   You should see: `{"status": "running", "service": "ZKTeco Attendance Server"}`

---

## Step 3: PHP Dashboard Setup

1. Copy the `php_dashboard` folder to your XAMPP htdocs:
   ```
   C:\xampp\htdocs\attendance\
   ```
   Or create an Apache alias/virtual host pointing to it.

2. Edit `php_dashboard/config/database.php` if your MySQL credentials differ:
   ```php
   define('DB_HOST', '127.0.0.1');
   define('DB_PORT', 3306);
   define('DB_USER', 'root');
   define('DB_PASS', '');
   ```

3. Ensure Apache has `mod_rewrite` enabled (usually enabled by default in XAMPP).

4. Access the dashboard: http://localhost/attendance/
   - Default login: `admin` / `admin123`
   - **Change this password immediately after first login!**

---

## Step 4: Port Forwarding

For remote devices to reach your server:

1. Log into your router admin panel.
2. Forward **external port 8015** → **internal IP:8015** (your server PC's local IP).
3. Note your **public IP address** (check at https://whatismyip.com).
4. Devices will connect to: `http://YOUR_PUBLIC_IP:8015/iclock`

---

## Step 5: Background Workers (Automatic)

The Python server runs these background workers automatically on startup:

| Worker | Interval | Purpose |
|---|---|---|
| Sync Worker | Every 5 seconds | Distributes biometric templates to devices |
| Device Monitor | Every 60 seconds | Detects offline devices, creates notifications, auto-backfill on reconnect |
| Attendance Processor | Every 2 minutes | Pairs raw punches into daily records (late/early/absent), drains dirty-date queue |
| Time Sync Cron | Every 60 seconds (fires at set hour) | Daily SET_TIME command to all approved devices (default 3:00 AM) |

**No Task Scheduler needed for attendance processing or time sync** — both run automatically as background workers while the server is running.

### Time Sync Details

The `time_sync_cron` worker runs every minute, checks the configured `time_sync_hour` setting (default: 3 AM), and queues `SET_TIME` to all approved devices at that hour automatically. No Windows Task Scheduler setup needed.

---

## Step 6: Run Python Server as Windows Service (Optional)

To keep the Python server running even after logout, use NSSM:

1. Download NSSM: https://nssm.cc/download
2. Install as service:
   ```cmd
   nssm install ZKTecoServer "C:\path\to\venv\Scripts\python.exe" "run.py"
   nssm set ZKTecoServer AppDirectory "C:\path\to\python_server"
   nssm start ZKTecoServer
   ```

---

## Verification Checklist

- [ ] MySQL running with `attendance_system` database (20 tables)
- [ ] Python server running on port 8015
- [ ] Background workers active (sync + device monitor + attendance processor)
- [ ] PHP dashboard accessible via Apache
- [ ] Can log in with admin/admin123
- [ ] Port 8015 reachable from outside (test with phone on mobile data)
- [ ] At least one device configured and connecting (see device_config.md)

---

## Troubleshooting

| Problem | Solution |
|---|---|
| "Database connection failed" | Check MySQL is running, credentials in config files |
| Python server won't start | Check port 8015 not in use: `netstat -an | find "8015"` |
| Devices not connecting | Check port forwarding, firewall, device ADMS URL |
| Dashboard blank page | Check Apache error log, enable `display_errors` in PHP |
| "ModuleNotFoundError" | Activate venv: `venv\Scripts\activate`, then `pip install -r requirements.txt` |
