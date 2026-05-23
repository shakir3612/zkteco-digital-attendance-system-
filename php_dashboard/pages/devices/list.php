<?php
/**
 * Devices List - All devices with status + online/offline indicator
 */
$pageTitle = 'All Devices';
require_once __DIR__ . '/../../includes/header.php';

$db = getDB();
$settings = getSettings(['offline_threshold_minutes', 'idle_threshold_minutes']);
$offlineMin = (int)($settings['offline_threshold_minutes'] ?? 10);
$idleMin = (int)($settings['idle_threshold_minutes'] ?? 2);

// Filter
$statusFilter = $_GET['status'] ?? '';
$where = '';
$params = [];
if ($statusFilter && in_array($statusFilter, ['pending_approval','approved','rejected','suspended','inactive'])) {
    $where = " WHERE d.status = ?";
    $params[] = $statusFilter;
}

$stmt = $db->prepare("
    SELECT d.*, 
           COALESCE(v.punches_today, 0) as punches_today,
           v.last_punch_at,
           CASE 
             WHEN d.last_seen >= NOW() - INTERVAL {$idleMin} MINUTE THEN 'online'
             WHEN d.last_seen >= NOW() - INTERVAL {$offlineMin} MINUTE THEN 'idle'
             WHEN d.last_seen IS NULL THEN 'never'
             ELSE 'offline'
           END as connection_state
    FROM devices d
    LEFT JOIN v_device_today_stats v ON v.device_sn = d.serial_number
    {$where}
    ORDER BY d.status = 'pending_approval' DESC, d.last_seen DESC
");
$stmt->execute($params);
$devices = $stmt->fetchAll();
?>

<div class="card" id="devices-list-card" data-auto-refresh="30">
    <div class="card-header">
        <h3>Devices (<?= count($devices) ?>)</h3>
        <div class="card-actions">
            <select onchange="window.location='?status='+this.value" class="filter-select">
                <option value="">All Statuses</option>
                <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>Approved</option>
                <option value="pending_approval" <?= $statusFilter === 'pending_approval' ? 'selected' : '' ?>>Pending</option>
                <option value="suspended" <?= $statusFilter === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </div>
    </div>
    <div class="card-body">
        <table class="table">
            <thead>
                <tr>
                    <th>Status</th>
                    <th>Name / Serial</th>
                    <th>Location</th>
                    <th>IP</th>
                    <th>Last Seen</th>
                    <th>Punches Today</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($devices as $dev): ?>
                <tr>
                    <td>
                        <span class="status-dot status-<?= $dev['connection_state'] ?>"></span>
                        <span class="badge badge-<?= $dev['status'] ?>"><?= $dev['status'] ?></span>
                    </td>
                    <td>
                        <strong><?= htmlspecialchars($dev['name'] ?: '—') ?></strong><br>
                        <code class="text-small"><?= htmlspecialchars($dev['serial_number']) ?></code>
                    </td>
                    <td><?= htmlspecialchars($dev['location'] ?: '—') ?></td>
                    <td><code><?= htmlspecialchars($dev['ip_address'] ?: '—') ?></code></td>
                    <td>
                        <?php if ($dev['last_seen']): ?>
                            <?= date('M j, H:i:s', strtotime($dev['last_seen'])) ?>
                            <br><small class="text-muted"><?= timeAgo($dev['last_seen']) ?></small>
                        <?php else: ?>
                            <span class="text-muted">Never</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $dev['punches_today'] ?></td>
                    <td>
                        <a href="<?= BASE_PATH ?>/pages/devices/detail.php?sn=<?= urlencode($dev['serial_number']) ?>" class="btn btn-xs">Detail</a>
                        <?php if ($dev['status'] === 'pending_approval'): ?>
                            <a href="<?= BASE_PATH ?>/pages/devices/approve.php?sn=<?= urlencode($dev['serial_number']) ?>" class="btn btn-xs btn-success">Approve</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
function timeAgo($datetime) {
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return $diff . 's ago';
    if ($diff < 3600) return floor($diff/60) . 'm ago';
    if ($diff < 86400) return floor($diff/3600) . 'h ago';
    return floor($diff/86400) . 'd ago';
}
require_once __DIR__ . '/../../includes/footer.php';
?>
