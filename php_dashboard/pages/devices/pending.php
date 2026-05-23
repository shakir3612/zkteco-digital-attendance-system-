<?php
/**
 * Pending Devices - Approval Queue
 */
$pageTitle = 'Pending Device Approvals';
require_once __DIR__ . '/../../includes/header.php';

$db = getDB();
$stmt = $db->query("
    SELECT d.*, 
           (SELECT COUNT(*) FROM device_connection_log WHERE device_sn = d.serial_number) as connection_count,
           (SELECT created_at FROM device_connection_log WHERE device_sn = d.serial_number ORDER BY created_at DESC LIMIT 1) as last_connection
    FROM devices d
    WHERE d.status = 'pending_approval'
    ORDER BY d.registered_at DESC
");
$pendingDevices = $stmt->fetchAll();
?>

<div class="card">
    <div class="card-header">
        <h3>Pending Approval (<?= count($pendingDevices) ?> devices)</h3>
    </div>
    <div class="card-body">
        <?php if (empty($pendingDevices)): ?>
            <p class="text-muted text-center">No devices waiting for approval.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Serial Number</th>
                        <th>IP Address</th>
                        <th>Firmware</th>
                        <th>Push Ver</th>
                        <th>Registered</th>
                        <th>Connections</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pendingDevices as $dev): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($dev['serial_number']) ?></code></td>
                        <td><code><?= htmlspecialchars($dev['ip_address'] ?: '—') ?></code></td>
                        <td><?= htmlspecialchars($dev['firmware_ver'] ?: '—') ?></td>
                        <td><?= htmlspecialchars($dev['push_ver'] ?: '—') ?></td>
                        <td><?= date('Y-m-d H:i', strtotime($dev['registered_at'])) ?></td>
                        <td><?= $dev['connection_count'] ?></td>
                        <td>
                            <a href="/pages/devices/approve.php?sn=<?= urlencode($dev['serial_number']) ?>" class="btn btn-sm btn-success">Approve</a>
                            <a href="/pages/devices/approve.php?sn=<?= urlencode($dev['serial_number']) ?>&action=reject" class="btn btn-sm btn-danger">Reject</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
