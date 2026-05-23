# Internal API Reference

Base URL: `http://127.0.0.1:8015`

All internal API endpoints are under `/api/`. These are called by the PHP dashboard.

---

## Health Check

### GET /
Server status check.

**Response:**
```json
{"status": "running", "service": "ZKTeco Attendance Server", "version": "1.0.0", "timezone": "Asia/Dhaka"}
```

### GET /health
Detailed health with database status.

**Response:**
```json
{"status": "healthy", "database": "connected"}
```

---

## Device Commands

### POST /api/device/sync-time
Queue a SET_TIME command (syncs device clock to server time).

**Body:** `{"device_sn": "ABC123"}`

**Response:** `{"success": true, "command_id": 42, "message": "Time sync command queued"}`

---

### POST /api/device/reboot
Queue a REBOOT command.

**Body:** `{"device_sn": "ABC123"}`

**Response:** `{"success": true, "command_id": 43, "message": "Reboot command queued"}`

---

### POST /api/device/resync-all
Queue full employee + biometric sync to a device.

**Body:** `{"device_sn": "ABC123"}`

**Response:**
```json
{"success": true, "message": "Queued 45 users + 120 bio templates", "users_queued": 45, "bio_queued": 120}
```

---

### POST /api/device/cancel-commands
Cancel all pending commands for a device.

**Body:** `{"device_sn": "ABC123"}`

**Response:** `{"success": true, "cancelled": 15, "message": "15 commands cancelled"}`

---

### GET /api/device/status/{device_sn}
Get device info and command statistics.

**Response:**
```json
{
  "success": true,
  "device": {"serial_number": "ABC123", "status": "approved", "last_seen": "2026-05-23 14:30:00", ...},
  "commands": {"pending": 3, "stats": {"pending": 3, "acknowledged": 120, "failed": 2}}
}
```

---

### GET /api/devices/approved
List all approved devices.

**Response:**
```json
{"success": true, "devices": [{"serial_number": "ABC123", "name": "Main Gate", "location": "HQ", "last_seen": "..."}]}
```

---

## Import from Device

### POST /api/import/start
Start an import job (pulls data from device).

**Body:**
```json
{
  "device_sn": "ABC123",
  "job_type": "users",
  "conflict_mode": "skip",
  "requested_by": 1
}
```

- `job_type`: `users`, `biometrics`, or `attendance`
- `conflict_mode`: `skip`, `update`, or `update_blank`

**Response:** `{"success": true, "job_id": 7, "message": "Import job created..."}`

---

### GET /api/import/status/{job_id}
Check import job progress.

**Response:**
```json
{
  "success": true,
  "job": {
    "id": 7, "device_sn": "ABC123", "job_type": "users",
    "status": "completed", "records_received": 47,
    "records_inserted": 42, "records_updated": 3,
    "records_skipped": 2, "records_failed": 0
  }
}
```

---

### GET /api/import/history/{device_sn}
Get recent import jobs for a device.

**Query params:** `?limit=10` (optional)

**Response:** `{"success": true, "jobs": [...]}`

---

## Attendance Processing

### POST /api/attendance/process
Trigger daily attendance processing.

**Body (single date):**
```json
{"date": "2026-05-23"}
```

**Body (date range):**
```json
{"date": "2026-05-01", "end_date": "2026-05-23"}
```

**Response:**
```json
{
  "success": true,
  "result": {
    "date": "2026-05-23", "total_employees": 50,
    "present": 42, "late": 5, "early_leave": 2,
    "absent": 3, "on_leave": 2, "holiday": 0, "weekend": 0
  }
}
```

---

## Device Communication (iclock Protocol)

These endpoints are used by ZKTeco devices directly, NOT by the PHP dashboard.

| Method | Endpoint | Purpose |
|---|---|---|
| GET | /iclock/cdata | Device handshake / registration |
| POST | /iclock/cdata | Receive ATTLOG / BIODATA / USERTAB / OPERLOG |
| GET | /iclock/getrequest | Device polls for commands |
| POST | /iclock/devicecmd | Device acknowledges command |

---

## Error Responses

All errors return:
```json
{"detail": "Error description"}
```

Common HTTP codes:
- 400: Bad request (invalid parameters)
- 403: Device not approved
- 404: Resource not found
- 500: Server error
