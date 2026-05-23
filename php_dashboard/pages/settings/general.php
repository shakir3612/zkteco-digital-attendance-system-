<?php
/**
 * System Settings - company info, policies, thresholds
 * Super admin only.
 */
$pageTitle = 'System Settings';
require_once __DIR__ . '/../../includes/header.php';
requireSuperAdmin();

$db = getDB();
$message = '';
$messageType = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $settings = [
        'company_name' => trim($_POST['company_name'] ?? ''),
        'company_logo_path' => trim($_POST['company_logo_path'] ?? ''),
        'timezone' => trim($_POST['timezone'] ?? 'Asia/Dhaka'),
        'weekly_off_days' => trim($_POST['weekly_off_days'] ?? 'fri,sat'),
        'offline_threshold_minutes' => (int)($_POST['offline_threshold_minutes'] ?? 10),
        'idle_threshold_minutes' => (int)($_POST['idle_threshold_minutes'] ?? 2),
        'auto_refresh_seconds' => (int)($_POST['auto_refresh_seconds'] ?? 30),
        'device_approval_by_admin' => isset($_POST['device_approval_by_admin']) ? '1' : '0',
        'time_sync_hour' => (int)($_POST['time_sync_hour'] ?? 3),
    ];

    foreach ($settings as $key => $value) {
        $stmt = $db->prepare(
            "UPDATE system_settings SET setting_value = ? WHERE setting_key = ?"
        );
        $stmt->execute([(string)$value, $key]);
    }

    auditLog('settings_updated', 'system', null, 'System settings updated');
    $message = 'Settings saved successfully.';
    $messageType = 'success';
}

// Load current settings
$stmt = $db->query("SELECT setting_key, setting_value, description FROM system_settings ORDER BY id");
$allSettings = [];
while ($row = $stmt->fetch()) {
    $allSettings[$row['setting_key']] = $row;
}

function sv($key, $default = '') {
    global $allSettings;
    return $allSettings[$key]['setting_value'] ?? $default;
}
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<form method="POST">
    <!-- COMPANY INFO -->
    <div class="card">
        <div class="card-header"><h3>Company Information</h3></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group">
                    <label for="company_name">Company Name</label>
                    <input type="text" id="company_name" name="company_name"
                           value="<?= htmlspecialchars(sv('company_name', 'My Company')) ?>">
                </div>
                <div class="form-group">
                    <label for="company_logo_path">Logo Path</label>
                    <input type="text" id="company_logo_path" name="company_logo_path"
                           value="<?= htmlspecialchars(sv('company_logo_path', 'assets/img/logo.png')) ?>">
                </div>
            </div>
            <div class="form-group">
                <label for="timezone">Timezone</label>
                <input type="text" id="timezone" name="timezone"
                       value="<?= htmlspecialchars(sv('timezone', 'Asia/Dhaka')) ?>" readonly>
                <small class="text-muted">All devices sync to this timezone.</small>
            </div>
        </div>
    </div>

    <!-- ATTENDANCE POLICIES -->
    <div class="card">
        <div class="card-header"><h3>Attendance Policies</h3></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group">
                    <label for="weekly_off_days">Weekly Off Days</label>
                    <input type="text" id="weekly_off_days" name="weekly_off_days"
                           value="<?= htmlspecialchars(sv('weekly_off_days', 'fri,sat')) ?>">
                    <small class="text-muted">Comma-separated: mon,tue,wed,thu,fri,sat,sun</small>
                </div>
                <div class="form-group">
                    <label for="time_sync_hour">Daily Time Sync Hour (0-23)</label>
                    <input type="number" id="time_sync_hour" name="time_sync_hour"
                           min="0" max="23"
                           value="<?= htmlspecialchars(sv('time_sync_hour', '3')) ?>">
                    <small class="text-muted">Hour when server pushes SET_TIME to all devices.</small>
                </div>
            </div>
        </div>
    </div>

    <!-- DEVICE MONITORING -->
    <div class="card">
        <div class="card-header"><h3>Device Monitoring</h3></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group">
                    <label for="idle_threshold_minutes">Idle Threshold (minutes)</label>
                    <input type="number" id="idle_threshold_minutes" name="idle_threshold_minutes"
                           min="1" max="60"
                           value="<?= htmlspecialchars(sv('idle_threshold_minutes', '2')) ?>">
                    <small class="text-muted">After this many minutes without contact, device shows as "idle".</small>
                </div>
                <div class="form-group">
                    <label for="offline_threshold_minutes">Offline Threshold (minutes)</label>
                    <input type="number" id="offline_threshold_minutes" name="offline_threshold_minutes"
                           min="1" max="120"
                           value="<?= htmlspecialchars(sv('offline_threshold_minutes', '10')) ?>">
                    <small class="text-muted">After this many minutes, device is "offline" and notification is sent.</small>
                </div>
            </div>
            <div class="form-group">
                <label for="auto_refresh_seconds">Dashboard Auto-Refresh (seconds)</label>
                <input type="number" id="auto_refresh_seconds" name="auto_refresh_seconds"
                       min="10" max="300"
                       value="<?= htmlspecialchars(sv('auto_refresh_seconds', '30')) ?>">
            </div>
        </div>
    </div>

    <!-- PERMISSIONS -->
    <div class="card">
        <div class="card-header"><h3>Permissions</h3></div>
        <div class="card-body">
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="device_approval_by_admin" value="1"
                           <?= sv('device_approval_by_admin', '0') === '1' ? 'checked' : '' ?>>
                    Allow admins to approve/reject devices (not only super_admin)
                </label>
            </div>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save Settings</button>
    </div>
</form>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
