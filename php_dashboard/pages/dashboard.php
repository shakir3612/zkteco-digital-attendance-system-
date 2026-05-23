<?php
/**
 * Dashboard - Overview with Device Health Widget
 */
$pageTitle = 'Dashboard';
require_once __DIR__ . '/../includes/header.php';

$db = getDB();

// Get device health stats
$settings = getSettings(['offline_threshold_minutes', 'idle_threshold_minutes']);
$offlineMin = (int)($settings['offline_threshold_minutes'] ?? 10);
$idleMin = (int)($settings['idle_threshold_minutes'] ?? 2);

$stmt = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'approved' AND last_seen >= NOW() - INTERVAL {$idleMin} MINUTE THEN 1 ELSE 0 END) as online_count,
        SUM(CASE WHEN status = 'approved' AND last_seen >= NOW() - INTERVAL {$offlineMin} MINUTE AND last_seen < NOW() - INTERVAL {$idleMin} MINUTE THEN 1 ELSE 0 END) as idle_count,
        SUM(CASE WHEN status = 'approved' AND (last_seen < NOW() - INTERVAL {$offlineMin} MINUTE OR last_seen IS NULL) THEN 1 ELSE 0 END) as offline_count,
        SUM(CASE WHEN status = 'pending_approval' THEN 1 ELSE 0 END) as pending_count
    FROM devices
");
$deviceStats = $stmt->fetch();

// Get offline devices list
$stmt = $db->prepare("
    SELECT serial_number, name, location, last_seen,
           TIMESTAMPDIFF(MINUTE, last_seen, NOW()) as mins_ago
    FROM devices 
    WHERE status = 'approved' AND (last_seen < NOW() - INTERVAL ? MINUTE OR last_seen IS NULL)
    ORDER BY last_seen DESC
    LIMIT 5
");
$stmt->execute([$offlineMin]);
$offlineDevices = $stmt->fetchAll();

// Get today's attendance stats
$stmt = $db->query("
    SELECT COUNT(DISTINCT pin) as total_punches,
           COUNT(DISTINCT device_sn) as active_devices
    FROM attendance_raw 
    WHERE DATE(punch_time) = CURDATE()
");
$attStats = $stmt->fetch();

// Get total employees
$stmt = $db->query("SELECT COUNT(*) as cnt FROM employees WHERE status = 'active'");
$empCount = $stmt->fetch()['cnt'];

// Get recent notifications
$role = $_SESSION['user_role'];
$stmt = $db->prepare("
    SELECT * FROM notifications 
    WHERE (target_role = 'all' OR target_role = ?)
    ORDER BY created_at DESC LIMIT 5
");
$stmt->execute([$role]);
$recentNotifications = $stmt->fetchAll();

// Pending approvals
$stmt = $db->query("
    SELECT serial_number, ip_address, registered_at 
    FROM devices WHERE status = 'pending_approval'
    ORDER BY registered_at DESC LIMIT 5
");
$pendingDevices = $stmt->fetchAll();
?>

<!-- STATS CARDS -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?= $empCount ?></div>
        <div class="stat-label">Active Employees</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= $attStats['total_punches'] ?? 0 ?></div>
        <div class="stat-label">Punches Today</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= $attStats['active_devices'] ?? 0 ?></div>
        <div class="stat-label">Devices Sent Data Today</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= $deviceStats['total'] ?? 0 ?></div>
        <div class="stat-label">Total Devices</div>
    </div>
</div>

<!-- DEVICE HEALTH WIDGET -->
<div class="card" id="device-health-widget" data-auto-refresh="30">
    <div class="card-header">
        <h3>Device Health</h3>
        <span class="refresh-indicator" title="Auto-refreshes every 30s">&#8635;</span>
    </div>
    <div class="card-body">
        <div class="device-health-summary">
            <span class="health-badge health-online"><?= $deviceStats['online_count'] ?? 0 ?> Online</span>
            <span class="health-badge health-idle"><?= $deviceStats['idle_count'] ?? 0 ?> Idle</span>
            <span class="health-badge health-offline"><?= $deviceStats['offline_count'] ?? 0 ?> Offline</span>
            <span class="health-badge health-pending"><?= $deviceStats['pending_count'] ?? 0 ?> Pending</span>
        </div>

        <?php if (!empty($offlineDevices)): ?>
        <div class="offline-list">
            <h4>Offline Devices</h4>
            <ul>
                <?php foreach ($offlineDevices as $dev): ?>
                <li>
                    <strong><?= htmlspecialchars($dev['name'] ?: $dev['serial_number']) ?></strong>
                    <?php if ($dev['location']): ?><small>(<?= htmlspecialchars($dev['location']) ?>)</small><?php endif; ?>
                    <span class="text-muted">
                        &mdash; last seen <?= $dev['mins_ago'] ? $dev['mins_ago'] . ' min ago' : 'never' ?>
                    </span>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="grid-2col">
    <!-- PENDING APPROVALS -->
    <div class="card">
        <div class="card-header">
            <h3>Pending Approvals</h3>
            <a href="<?= BASE_PATH ?>/pages/devices/pending.php" class="btn btn-sm">View All</a>
        </div>
        <div class="card-body">
            <?php if (empty($pendingDevices)): ?>
                <p class="text-muted">No devices pending approval.</p>
            <?php else: ?>
                <table class="table table-compact">
                    <thead><tr><th>Serial Number</th><th>IP</th><th>Registered</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($pendingDevices as $dev): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($dev['serial_number']) ?></code></td>
                            <td><?= htmlspecialchars($dev['ip_address']) ?></td>
                            <td><?= date('M j, H:i', strtotime($dev['registered_at'])) ?></td>
                            <td><a href="<?= BASE_PATH ?>/pages/devices/approve.php?sn=<?= urlencode($dev['serial_number']) ?>" class="btn btn-xs btn-success">Review</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- RECENT NOTIFICATIONS -->
    <div class="card">
        <div class="card-header">
            <h3>Recent Notifications</h3>
        </div>
        <div class="card-body">
            <?php if (empty($recentNotifications)): ?>
                <p class="text-muted">No notifications.</p>
            <?php else: ?>
                <ul class="notification-list">
                <?php foreach ($recentNotifications as $notif): ?>
                    <li class="notif-item <?= $notif['is_read'] ? '' : 'notif-unread' ?>">
                        <span class="notif-type notif-<?= $notif['type'] ?>"><?= $notif['type'] ?></span>
                        <span class="notif-title"><?= htmlspecialchars($notif['title']) ?></span>
                        <small class="notif-time"><?= date('M j, H:i', strtotime($notif['created_at'])) ?></small>
                    </li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
