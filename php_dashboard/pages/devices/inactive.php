<?php
/**
 * Inactive Devices - Approved devices NOT sending data.
 * Quick filtered view: approved devices that haven't sent attendance
 * in last 4 hours during work time, or 24 hours otherwise.
 */
$pageTitle = 'Inactive Devices';
require_once __DIR__ . '/../../includes/header.php';

$db = getDB();
$settings = getSettings(['offline_threshold_minutes']);
$offlineMin = (int)($settings['offline_threshold_minutes'] ?? 10);

// Determine if we're in work hours (9 AM - 5 PM)
$currentHour = (int)date('G');
$isWorkHours = ($currentHour >= 9 && $currentHour < 17);
$thresholdHours = $isWorkHours ? 4 : 24;

// Get approved devices that haven't sent attendance data recently
$stmt = $db->prepare("
    SELECT d.*,
           COALESCE(v.punches_today, 0) as punches_today,
           v.last_punch_at,
           (SELECT MAX(ar.punch_time) FROM attendance_raw ar WHERE ar.device_sn = d.serial_number) as last_attendance_ever,
           TIMESTAMPDIFF(MINUTE, d.last_seen, NOW()) as mins_since_seen,
           CASE 
             WHEN d.last_seen >= NOW() - INTERVAL ? MINUTE THEN 'online'
             WHEN d.last_seen IS NULL THEN 'never'
             ELSE 'offline'
           END as connection_state
    FROM devices d
    LEFT JOIN v_device_today_stats v ON v.device_sn = d.serial_number
    WHERE d.status = 'approved'
    AND (
        NOT EXISTS (
            SELECT 1 FROM attendance_raw ar 
            WHERE ar.device_sn = d.serial_number 
            AND ar.punch_time >= NOW() - INTERVAL ? HOUR
        )
        OR d.last_seen < NOW() - INTERVAL ? MINUTE
        OR d.last_seen IS NULL
    )
    ORDER BY d.last_seen ASC
");
$stmt->execute([$offlineMin, $thresholdHours, $offlineMin]);
$inactiveDevices = $stmt->fetchAll();
?>

<div class="card">
    <div class="card-header">
        <h3>Inactive Devices (<?= count($inactiveDevices) ?>)</h3>
        <span class="text-muted">
            Approved devices with no attendance in last <?= $thresholdHours ?>h
            <?= $isWorkHours ? '(work hours)' : '(off hours)' ?>
        </span>
    </div>
    <div class="card-body">
        <?php if (empty($inactiveDevices)): ?>
            <p class="text-muted text-center">All approved devices are actively sending data.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Name / Serial</th>
                        <th>Location</th>
                        <th>Last Seen</th>
                        <th>Last Attendance</th>
                        <th>Punches Today</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($inactiveDevices as $dev): ?>
                    <tr>
                        <td><span class="status-dot status-<?= $dev['connection_state'] ?>"></span></td>
                        <td>
                            <strong><?= htmlspecialchars($dev['name'] ?: '—') ?></strong><br>
                            <code class="text-small"><?= htmlspecialchars($dev['serial_number']) ?></code>
                        </td>
                        <td><?= htmlspecialchars($dev['location'] ?: '—') ?></td>
                        <td>
                            <?php if ($dev['last_seen']): ?>
                                <?= date('M j, H:i', strtotime($dev['last_seen'])) ?>
                                <br><small class="text-muted"><?= $dev['mins_since_seen'] ?>m ago</small>
                            <?php else: ?>
                                <span class="text-danger">Never</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $dev['last_attendance_ever'] ? date('M j, H:i', strtotime($dev['last_attendance_ever'])) : '<span class="text-muted">None</span>' ?>
                        </td>
                        <td><?= $dev['punches_today'] ?></td>
                        <td>
                            <a href="/pages/devices/detail.php?sn=<?= urlencode($dev['serial_number']) ?>" class="btn btn-xs">Detail</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
