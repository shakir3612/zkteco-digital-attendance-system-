<?php
/**
 * Device Approval / Rejection Page
 */
$pageTitle = 'Approve Device';
require_once __DIR__ . '/../../includes/header.php';

$db = getDB();
$sn = $_GET['sn'] ?? '';

if (empty($sn)) {
    echo '<div class="alert alert-error">No device serial number provided.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

// Fetch device
$stmt = $db->prepare("SELECT * FROM devices WHERE serial_number = ?");
$stmt->execute([$sn]);
$device = $stmt->fetch();

if (!$device) {
    echo '<div class="alert alert-error">Device not found.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

// Handle form submission
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $deviceName = trim($_POST['device_name'] ?? '');
    $deviceLocation = trim($_POST['device_location'] ?? '');
    $importUsers = isset($_POST['import_users']);
    $importBio = isset($_POST['import_bio']);

    if ($action === 'approve') {
        $stmt = $db->prepare("
            UPDATE devices SET 
                status = 'approved', 
                name = ?, 
                location = ?, 
                approved_by = ?, 
                approved_at = NOW()
            WHERE serial_number = ?
        ");
        $stmt->execute([$deviceName, $deviceLocation, $_SESSION['user_id'], $sn]);

        auditLog('device_approved', 'device', $device['id'], "Approved device SN={$sn}");

        // Queue import commands if requested
        if ($importUsers) {
            $stmt = $db->prepare("
                INSERT INTO device_commands (device_sn, command_type, command_content, priority, status, created_at)
                VALUES (?, 'QUERY_USERINFO', 'DATA QUERY USERINFO', 2, 'pending', NOW())
            ");
            $stmt->execute([$sn]);

            $stmt = $db->prepare("
                INSERT INTO import_jobs (device_sn, job_type, conflict_mode, status, requested_by, created_at)
                VALUES (?, 'users', 'skip', 'queued', ?, NOW())
            ");
            $stmt->execute([$sn, $_SESSION['user_id']]);
        }

        if ($importBio) {
            $stmt = $db->prepare("
                INSERT INTO device_commands (device_sn, command_type, command_content, priority, status, created_at)
                VALUES (?, 'QUERY_BIODATA', 'DATA QUERY BIODATA', 3, 'pending', NOW())
            ");
            $stmt->execute([$sn]);

            $stmt = $db->prepare("
                INSERT INTO import_jobs (device_sn, job_type, conflict_mode, status, requested_by, created_at)
                VALUES (?, 'biometrics', 'skip', 'queued', ?, NOW())
            ");
            $stmt->execute([$sn, $_SESSION['user_id']]);
        }

        $message = "Device approved successfully!" . ($importUsers ? " User import queued." : "") . ($importBio ? " Biometric import queued." : "");
        $messageType = 'success';

        // Refresh device data
        $stmt = $db->prepare("SELECT * FROM devices WHERE serial_number = ?");
        $stmt->execute([$sn]);
        $device = $stmt->fetch();

    } elseif ($action === 'reject') {
        $stmt = $db->prepare("UPDATE devices SET status = 'rejected' WHERE serial_number = ?");
        $stmt->execute([$sn]);
        auditLog('device_rejected', 'device', $device['id'], "Rejected device SN={$sn}");
        $message = "Device rejected.";
        $messageType = 'info';

        $stmt = $db->prepare("SELECT * FROM devices WHERE serial_number = ?");
        $stmt->execute([$sn]);
        $device = $stmt->fetch();
    }
}
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h3>Device: <?= htmlspecialchars($sn) ?></h3>
        <span class="badge badge-<?= $device['status'] ?>"><?= $device['status'] ?></span>
    </div>
    <div class="card-body">
        <div class="detail-grid">
            <div class="detail-item"><label>Serial Number:</label><span><code><?= htmlspecialchars($device['serial_number']) ?></code></span></div>
            <div class="detail-item"><label>IP Address:</label><span><?= htmlspecialchars($device['ip_address'] ?: '—') ?></span></div>
            <div class="detail-item"><label>Firmware:</label><span><?= htmlspecialchars($device['firmware_ver'] ?: '—') ?></span></div>
            <div class="detail-item"><label>Push Version:</label><span><?= htmlspecialchars($device['push_ver'] ?: '—') ?></span></div>
            <div class="detail-item"><label>Registered:</label><span><?= $device['registered_at'] ? date('Y-m-d H:i:s', strtotime($device['registered_at'])) : '—' ?></span></div>
            <div class="detail-item"><label>Last Seen:</label><span><?= $device['last_seen'] ? date('Y-m-d H:i:s', strtotime($device['last_seen'])) : 'Never' ?></span></div>
        </div>

        <?php if ($device['status'] === 'pending_approval'): ?>
        <hr>
        <form method="POST" class="approve-form">
            <h4>Approve This Device</h4>
            <div class="form-row">
                <div class="form-group">
                    <label for="device_name">Device Name</label>
                    <input type="text" id="device_name" name="device_name" placeholder="e.g., Main Gate, Floor 2 Entry">
                </div>
                <div class="form-group">
                    <label for="device_location">Location</label>
                    <input type="text" id="device_location" name="device_location" placeholder="e.g., HQ Lobby, Branch Office">
                </div>
            </div>
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="import_users" value="1">
                    Import existing employees from this device
                </label>
            </div>
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="import_bio" value="1">
                    Import existing biometric data from this device
                </label>
            </div>
            <div class="form-actions">
                <button type="submit" name="action" value="approve" class="btn btn-success">Approve Device</button>
                <button type="submit" name="action" value="reject" class="btn btn-danger" onclick="return confirm('Are you sure you want to reject this device?')">Reject Device</button>
                <a href="/pages/devices/pending.php" class="btn btn-outline">Cancel</a>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
