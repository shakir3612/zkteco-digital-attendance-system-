<?php
/**
 * Employee-Device Sync Status - shows which devices have this employee synced.
 */
$pageTitle = 'Employee Sync Status';
require_once __DIR__ . '/../../includes/header.php';

$db = getDB();
$pin = $_GET['pin'] ?? '';
$message = '';

if (empty($pin)) { echo '<div class="alert alert-error">No PIN specified.</div>'; require_once __DIR__ . '/../../includes/footer.php'; exit; }

$stmt = $db->prepare("SELECT * FROM employees WHERE pin = ?");
$stmt->execute([$pin]);
$employee = $stmt->fetch();
if (!$employee) { echo '<div class="alert alert-error">Employee not found.</div>'; require_once __DIR__ . '/../../includes/footer.php'; exit; }

// Handle re-sync
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resync'])) {
    $targetSn = $_POST['device_sn'] ?? '';
    $devices = ($targetSn === 'all')
        ? $db->query("SELECT serial_number FROM devices WHERE status = 'approved'")->fetchAll()
        : [['serial_number' => $targetSn]];

    $count = 0;
    foreach ($devices as $dev) {
        $sn = $dev['serial_number'];
        $content = "DATA UPDATE USERINFO PIN={$pin}\tName={$employee['name']}\tPri={$employee['privilege']}\tPasswd=\tCard={$employee['card_number']}\tGrp=1\tTZ=0000000100000000";
        $db->prepare("INSERT INTO device_commands (device_sn, command_type, command_content, priority, status, created_at) VALUES (?, 'SET_USER', ?, 3, 'pending', NOW())")->execute([$sn, $content]);

        $bios = $db->prepare("SELECT * FROM biometric_templates WHERE pin = ?");
        $bios->execute([$pin]);
        foreach ($bios->fetchAll() as $bio) {
            $bioContent = "DATA UPDATE BIODATA PIN={$pin}\tNo={$bio['bio_no']}\tIndex={$bio['bio_index']}\tValid=1\tDuress=0\tType={$bio['bio_type']}\tTmp={$bio['template']}";
            $db->prepare("INSERT INTO device_commands (device_sn, command_type, command_content, priority, status, created_at) VALUES (?, 'SET_BIODATA', ?, 4, 'pending', NOW())")->execute([$sn, $bioContent]);
        }
        $count++;
    }
    $message = "Re-sync queued for {$count} device(s).";
    auditLog('employee_resync', 'employee', $employee['id'], "Re-synced PIN={$pin} to {$count} devices");
}

// Get sync status
$stmt = $db->prepare("SELECT de.*, d.name as device_name, d.location FROM device_employees de JOIN devices d ON d.serial_number = de.device_sn WHERE de.pin = ? ORDER BY d.name");
$stmt->execute([$pin]);
$syncStatus = $stmt->fetchAll();

$allApproved = $db->query("SELECT serial_number, name, location FROM devices WHERE status = 'approved'")->fetchAll();

$stmt = $db->prepare("SELECT COUNT(*) as cnt FROM biometric_templates WHERE pin = ?");
$stmt->execute([$pin]);
$bioCount = $stmt->fetch()['cnt'];
?>

<?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h3>Sync: <?= htmlspecialchars($employee['name']) ?> (PIN: <?= htmlspecialchars($pin) ?>)</h3>
        <form method="POST" style="display:inline"><input type="hidden" name="device_sn" value="all"><button type="submit" name="resync" value="1" class="btn btn-sm btn-primary">Re-sync All</button></form>
    </div>
    <div class="card-body">
        <div class="stats-grid stats-grid-sm">
            <div class="stat-card"><div class="stat-value"><?= $bioCount ?></div><div class="stat-label">Bio Templates</div></div>
            <div class="stat-card"><div class="stat-value"><?= count($syncStatus) ?></div><div class="stat-label">Devices Synced</div></div>
            <div class="stat-card"><div class="stat-value"><?= count($allApproved) ?></div><div class="stat-label">Approved Devices</div></div>
        </div>
        <table class="table">
            <thead><tr><th>Device</th><th>Location</th><th>User</th><th>Bio</th><th>Last Sync</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($allApproved as $dev):
                $synced = null;
                foreach ($syncStatus as $ss) { if ($ss['device_sn'] === $dev['serial_number']) { $synced = $ss; break; } }
            ?>
                <tr>
                    <td><strong><?= htmlspecialchars($dev['name'] ?: $dev['serial_number']) ?></strong></td>
                    <td><?= htmlspecialchars($dev['location'] ?? '—') ?></td>
                    <td><?= $synced && $synced['user_synced'] ? '<span class="text-success">Yes</span>' : '<span class="text-danger">No</span>' ?></td>
                    <td><?= $synced && $synced['bio_synced'] ? '<span class="text-success">Yes</span>' : '<span class="text-danger">No</span>' ?></td>
                    <td><?= $synced && $synced['synced_at'] ? date('M j H:i', strtotime($synced['synced_at'])) : '—' ?></td>
                    <td><form method="POST" style="display:inline"><input type="hidden" name="device_sn" value="<?= htmlspecialchars($dev['serial_number']) ?>"><button type="submit" name="resync" value="1" class="btn btn-xs btn-outline">Re-sync</button></form></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<div class="form-actions"><a href="/pages/employees/form.php?id=<?= $employee['id'] ?>" class="btn btn-outline">Edit</a> <a href="/pages/employees/list.php" class="btn btn-outline">Back</a></div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
