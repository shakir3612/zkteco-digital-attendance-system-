# Device Configuration Guide

## Supported Devices

- ZKTeco SpeedFace V5L (primary)
- Any ZKTeco device supporting PUSH/ADMS protocol (iclock)
- Compatible models: SpeedFace-V5L[TD], ProFace X, SpeedFace-H5, etc.

---

## Configure Device ADMS Server

### On the Device Screen:

1. Go to **Menu → Communication → Cloud Server Setting** (or ADMS Setting)
2. Set the following:

| Setting | Value |
|---|---|
| **Enable** | Yes / On |
| **Server URL** | `http://YOUR_PUBLIC_IP:8015/iclock` |
| **Server Port** | 8015 |
| **Enable Proxy** | No |

3. Save and reboot the device.

### Via Web Interface (if device has web UI):

1. Access device web UI at its local IP (e.g., http://192.168.1.100)
2. Navigate to: **Network → Cloud Server** or **Communication → ADMS**
3. Enter:
   - Domain: `YOUR_PUBLIC_IP`
   - Port: `8015`
   - Enable Push: Yes
4. Save.

---

## What Happens After Configuration

1. **Device connects** → Server auto-registers it as `pending_approval`
2. **Dashboard notification** → Admin sees "New device detected" alert
3. **Admin reviews** → Goes to Devices → Pending → clicks Approve
4. **Data flows** → Device starts sending attendance, biometrics
5. **Sync begins** → Server pushes existing employees/bio to the device

---

## Device Connection Flow

```
Device powers on
    ↓
Sends GET /iclock/cdata?SN=SERIAL&options=all
    ↓
Server responds with options + ServerTime
    ↓
Device syncs clock to ServerTime
    ↓
Every 10-30 seconds: polls GET /iclock/getrequest (picks up commands)
    ↓
When employee punches: sends POST /iclock/cdata?table=ATTLOG
    ↓
When bio enrolled: sends POST /iclock/cdata?table=BIODATA
```

---

## Recommended Device Settings

| Setting | Recommended Value | Why |
|---|---|---|
| Push Interval | 10-30 seconds | Balance between real-time and network load |
| Realtime Push | Enabled | Send attendance immediately on punch |
| Trans Interval | 1 minute | How often to check for new data to send |
| HTTPS | Not required | Internal network or port-forwarded |

---

## Multiple Devices Setup

Each device needs:
1. A **unique serial number** (factory-set, cannot be changed)
2. The **same server URL** (`http://YOUR_IP:8015/iclock`)
3. To be **approved individually** in the dashboard

After approval, biometric data is automatically synced across all approved devices.

---

## Local vs Remote Devices

### Local (same network as server):
- Server URL can use local IP: `http://192.168.1.100:8015/iclock`
- No port forwarding needed
- Lower latency

### Remote (different network/location):
- Server URL must use public IP: `http://YOUR_PUBLIC_IP:8015/iclock`
- Port 8015 must be forwarded on server's router
- Device needs internet access (to reach your public IP)
- Works through any NAT — device initiates all connections

---

## Troubleshooting Device Connection

| Symptom | Check |
|---|---|
| Device not appearing in dashboard | Is ADMS URL correct? Is port 8015 open? |
| Device shows "pending" forever | Admin hasn't approved it yet |
| Device approved but no data | Check device time, restart device |
| Attendance not coming through | Is "Realtime Push" enabled on device? |
| Biometrics not syncing | Check command queue in device detail page |
| "Connection failed" on device | Ping server IP from device network |

### Quick Test:
From the device's network, open a browser and go to:
```
http://YOUR_SERVER_IP:8015/
```
If you see `{"status": "running"}`, the device can reach the server.

---

## Factory Reset Warning

If you factory-reset a device:
- Its serial number stays the same
- It will reconnect with the same SN
- Server will recognize it (already registered)
- But all local data on the device is wiped
- Admin should trigger "Re-sync All Bio" from the device detail page
