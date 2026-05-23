<?php
/**
 * Device Detail - Data flow stats, connection log, actions
 */
$pageTitle = 'Device Detail';
require_once __DIR__ . '/../../includes/header.php';

$db = getDB();
$sn = $_GET['sn'] ?? '';

if (empty($sn)) {
    echo '<div class="alert alert-error">No device specified.</div>';
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

// Connection state
$settings = getSettings(['offline_threshold_minutes', 'idle_threshold_minutes']);
$offlineMin = (int)($settings['offline_threshold_minutes'] ?? 10);
$idleMin = (int)($settings['idle_threshold_minutes'] ?? 2);

$connState = 'never';
if ($device['last_seen']) {
    $diff = (time() - strtotime($device['last_seen'])) / 60;
    if ($diff <= $idleMin) $connState = 'online';
    elseif ($diff <= $offlineMin) $connState = 'idle';
    else $connState = 'offline';
}
?>

<div class="detail-header-row">
    <div>
        <h2><?= htmlspecialchars($device['name'] ?: $sn) ?></h2>
        <code><?= htmlspecialchars($sn) ?></code>
    </div>
    <div>
        <span class="status-dot status-<?= $connState ?>"></span>
        <span class="badge badge-<?= $device['status'] ?>"><?= $device['status'] ?></span>
    </div>
</div>

<?php
// Data flow stats (last 24 hours)
$stmt = $db->prepare("
    SELECT COUNT(*) as cnt FROM attendance_raw 
    WHERE device_sn = ? AND punch_time >= NOW() - INTERVAL 24 HOUR
");
$stmt->execute([$sn]);
$attCount24h = $stmt->fetch()['cnt'];

$stmt = $db->prepare("
    SELECT MAX(punch_time) as last_att FROM attendance_raw WHERE device_sn = ?
");
$stmt->execute([$sn]);
$lastAtt = $stmt->fetch()['last_att'];

$stmt = $db->prepare("
    SELECT COUNT(*) as cnt FROM biometric_templates WHERE source_device_sn = ?
");
$stmt->execute([$sn]);
$bioCount = $stmt->fetch()['cnt'];

$stmt = $db->prepare("
    SELECT COUNT(*) as cnt FROM device_connection_log 
    WHERE device_sn = ? AND created_at >= NOW() - INTERVAL 24 HOUR
");
$stmt->execute([$sn]);
$pollCount24h = $stmt->fetch()['cnt'];

$stmt = $db->prepare("
    SELECT COUNT(*) as cnt FROM device_commands 
    WHERE device_sn = ? AND status = 'pending'
");
$stmt->execute([$sn]);
$pendingCmds = $stmt->fetch()['cnt'];

$stmt = $db->prepare("
    SELECT COUNT(*) as cnt FROM device_commands 
    WHERE device_sn = ? AND status = 'acknowledged' 
    AND acknowledged_at >= NOW() - INTERVAL 24 HOUR
");
$stmt->execute([$sn]);
$ackCmds24h = $stmt->fetch()['cnt'];
?>

<!-- DEVICE INFO -->
<div class="card">
    <div class="card-header"><h3>Device Information</h3></div>
    <div class="card-body">
        <div class="detail-grid">
            <div class="detail-item"><label>Serial Number:</label><span><code><?= htmlspecialchars($sn) ?></code></span></div>
            <div class="detail-item"><label>Name:</label><span><?= htmlspecialchars($device['name'] ?: '—') ?></span></div>
            <div class="detail-item"><label>Location:</label><span><?= htmlspecialchars($device['location'] ?: '—') ?></span></div>
            <div class="detail-item"><label>IP Address:</label><span><?= htmlspecialchars($device['ip_address'] ?: '—') ?></span></div>
            <div class="detail-item"><label>Firmware:</label><span><?= htmlspecialchars($device['firmware_ver'] ?: '—') ?></span></div>
            <div class="detail-item"><label>Push Version:</label><span><?= htmlspecialchars($device['push_ver'] ?: '—') ?></span></div>
            <div class="detail-item"><label>Model:</label><span><?= htmlspecialchars($device['model'] ?: '—') ?></span></div>
            <div class="detail-item"><label>Registered:</label><span><?= $device['registered_at'] ? date('Y-m-d H:i:s', strtotime($device['registered_at'])) : '—' ?></span></div>
            <div class="detail-item"><label>Last Seen:</label><span><?= $device['last_seen'] ? date('Y-m-d H:i:s', strtotime($device['last_seen'])) : 'Never' ?></span></div>
            <div class="detail-item"><label>Approved By:</label><span><?= $device['approved_by'] ? "User #{$device['approved_by']}" : '—' ?></span></div>
            <div class="detail-item"><label>Approved At:</label><span><?= $device['approved_at'] ? date('Y-m-d H:i:s', strtotime($device['approved_at'])) : '—' ?></span></div>
        </div>
    </div>
</div>

<!-- DATA FLOW STATS -->
<div class="card" data-auto-refresh="30">
    <div class="card-header"><h3>Data Flow (Last 24 Hours)</h3></div>
    <div class="card-body">
        <div class="stats-grid stats-grid-sm">
            <div class="stat-card"><div class="stat-value"><?= $attCount24h ?></div><div class="stat-label">Attendance Logs</div></div>
            <div class="stat-card"><div class="stat-value"><?= $lastAtt ? date('H:i', strtotime($lastAtt)) : '—' ?></div><div class="stat-label">Last Attendance</div></div>
            <div class="stat-card"><div class="stat-value"><?= $bioCount ?></div><div class="stat-label">Bio Templates (Total)</div></div>
            <div class="stat-card"><div class="stat-value"><?= $pollCount24h ?></div><div class="stat-label">Connections</div></div>
            <div class="stat-card"><div class="stat-value"><?= $ackCmds24h ?></div><div class="stat-label">Commands Completed</div></div>
            <div class="stat-card"><div class="stat-value"><?= $pendingCmds ?></div><div class="stat-label">Commands Pending</div></div>
        </div>
    </div>
</div>

<!-- ACTION BUTTONS -->
<?php if ($device['status'] === 'approved'): ?>
<div class="card">
    <div class="card-header"><h3>Actions</h3></div>
    <div class="card-body">
        <div class="action-buttons">
            <form method="POST" action="/pages/devices/manage.php" style="display:inline">
                <input type="hidden" name="sn" value="<?= htmlspecialchars($sn) ?>">
                <button type="submit" name="action" value="sync_time" class="btn btn-primary">Sync Time Now</button>
                <button type="submit" name="action" value="resync_bio" class="btn btn-outline">Re-sync All Bio</button>
                <button type="submit" name="action" value="reboot" class="btn btn-outline" onclick="return confirm('Reboot this device?')">Reboot Device</button>
                <button type="submit" name="action" value="suspend" class="btn btn-danger" onclick="return confirm('Suspend this device?')">Suspend</button>
            </form>
            <a href="/pages/devices/manage.php?sn=<?= urlencode($sn) ?>" class="btn btn-outline">Edit Details</a>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- CONNECTION LOG -->
<?php
$stmt = $db->prepare("
    SELECT * FROM device_connection_log 
    WHERE device_sn = ? 
    ORDER BY created_at DESC LIMIT 50
");
$stmt->execute([$sn]);
$logs = $stmt->fetchAll();
?>
<div class="card">
    <div class="card-header"><h3>Connection Log (Last 50)</h3></div>
    <div class="card-body">
        <table class="table table-compact">
            <thead><tr><th>Time</th><th>Action</th><th>Allowed</th><th>Details</th></tr></thead>
            <tbody>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td><?= date('M j H:i:s', strtotime($log['created_at'])) ?></td>
                    <td><code><?= htmlspecialchars($log['action']) ?></code></td>
                    <td><?= $log['was_allowed'] ? '<span class="text-success">Yes</span>' : '<span class="text-danger">No</span>' ?></td>
                    <td class="text-small"><?= htmlspecialchars($log['details'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
