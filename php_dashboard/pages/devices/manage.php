<?php
/**
 * Device Management - Suspend, reactivate, rename, location, queue commands
 */
$pageTitle = 'Manage Device';
require_once __DIR__ . '/../../includes/header.php';

$db = getDB();
$sn = $_GET['sn'] ?? ($_POST['sn'] ?? '');
$message = '';
$messageType = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $sn) {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'sync_time':
            $now = date('Y-m-d H:i:s');
            $stmt = $db->prepare("
                INSERT INTO device_commands (device_sn, command_type, command_content, priority, status, created_at)
                VALUES (?, 'SET_TIME', ?, 1, 'pending', NOW())
            ");
            $stmt->execute([$sn, "SET TIME {$now}"]);
            auditLog('device_sync_time', 'device', null, "Queued time sync for SN={$sn}");
            $message = "Time sync command queued.";
            $messageType = 'success';
            break;

        case 'reboot':
            $stmt = $db->prepare("
                INSERT INTO device_commands (device_sn, command_type, command_content, priority, status, created_at)
                VALUES (?, 'REBOOT', 'REBOOT', 2, 'pending', NOW())
            ");
            $stmt->execute([$sn]);
            auditLog('device_reboot', 'device', null, "Queued reboot for SN={$sn}");
            $message = "Reboot command queued.";
            $messageType = 'success';
            break;

        case 'suspend':
            $stmt = $db->prepare("UPDATE devices SET status = 'suspended' WHERE serial_number = ?");
            $stmt->execute([$sn]);
            // Cancel pending commands
            $stmt = $db->prepare("UPDATE device_commands SET status = 'cancelled' WHERE device_sn = ? AND status = 'pending'");
            $stmt->execute([$sn]);
            auditLog('device_suspended', 'device', null, "Suspended device SN={$sn}");
            $message = "Device suspended. All pending commands cancelled.";
            $messageType = 'info';
            break;

        case 'reactivate':
            $stmt = $db->prepare("UPDATE devices SET status = 'approved' WHERE serial_number = ?");
            $stmt->execute([$sn]);
            auditLog('device_reactivated', 'device', null, "Reactivated device SN={$sn}");
            $message = "Device reactivated.";
            $messageType = 'success';
            break;

        case 'resync_bio':
            // Queue all biometric templates to this device
            $stmt = $db->prepare("SELECT pin, bio_type, bio_no, bio_index, template FROM biometric_templates");
            $stmt->execute();
            $templates = $stmt->fetchAll();
            $count = 0;
            foreach ($templates as $tpl) {
                $content = "DATA UPDATE BIODATA PIN={$tpl['pin']}\tNo={$tpl['bio_no']}\tIndex={$tpl['bio_index']}\tType={$tpl['bio_type']}\tTmp={$tpl['template']}";
                $ins = $db->prepare("
                    INSERT INTO device_commands (device_sn, command_type, command_content, priority, status, created_at)
                    VALUES (?, 'SET_BIODATA', ?, 5, 'pending', NOW())
                ");
                $ins->execute([$sn, $content]);
                $count++;
            }
            auditLog('device_resync_bio', 'device', null, "Queued {$count} bio sync commands for SN={$sn}");
            $message = "{$count} biometric sync commands queued.";
            $messageType = 'success';
            break;

        case 'update_info':
            $name = trim($_POST['device_name'] ?? '');
            $location = trim($_POST['device_location'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            $stmt = $db->prepare("UPDATE devices SET name = ?, location = ?, notes = ? WHERE serial_number = ?");
            $stmt->execute([$name, $location, $notes, $sn]);
            auditLog('device_updated', 'device', null, "Updated info for SN={$sn}");
            $message = "Device information updated.";
            $messageType = 'success';
            break;
    }

    // Redirect back to detail if action came from there
    if (in_array($action, ['sync_time', 'reboot', 'suspend', 'resync_bio'])) {
        header("Location: " . BASE_PATH . "/pages/devices/detail.php?sn=" . urlencode($sn) . "&msg=" . urlencode($message));
        exit;
    }
}

// Fetch device for edit form
if (empty($sn)) {
    echo '<div class="alert alert-error">No device specified.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$stmt = $db->prepare("SELECT * FROM devices WHERE serial_number = ?");
$stmt->execute([$sn]);
$device = $stmt->fetch();

if (!$device) {
    echo '<div class="alert alert-error">Device not found.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h3>Edit Device: <?= htmlspecialchars($sn) ?></h3>
        <span class="badge badge-<?= $device['status'] ?>"><?= $device['status'] ?></span>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="sn" value="<?= htmlspecialchars($sn) ?>">
            <input type="hidden" name="action" value="update_info">
            <div class="form-row">
                <div class="form-group">
                    <label for="device_name">Device Name</label>
                    <input type="text" id="device_name" name="device_name" value="<?= htmlspecialchars($device['name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="device_location">Location</label>
                    <input type="text" id="device_location" name="device_location" value="<?= htmlspecialchars($device['location'] ?? '') ?>">
                </div>
            </div>
            <div class="form-group">
                <label for="notes">Notes</label>
                <textarea id="notes" name="notes" rows="3"><?= htmlspecialchars($device['notes'] ?? '') ?></textarea>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <?php if ($device['status'] === 'suspended'): ?>
                    <button type="submit" name="action" value="reactivate" class="btn btn-success">Reactivate</button>
                <?php endif; ?>
                <a href="<?= BASE_PATH ?>/pages/devices/detail.php?sn=<?= urlencode($sn) ?>" class="btn btn-outline">Back to Detail</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
