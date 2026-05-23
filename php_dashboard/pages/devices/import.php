<?php
/**
 * Device Import Page - Pull data (employees/biometrics/attendance) from device.
 */
$pageTitle = 'Import from Device';
require_once __DIR__ . '/../../includes/header.php';

$db = getDB();
$sn = $_GET['sn'] ?? '';
$message = '';
$messageType = '';

// Get approved devices for dropdown
$stmt = $db->query("SELECT serial_number, name, location FROM devices WHERE status = 'approved' ORDER BY name");
$approvedDevices = $stmt->fetchAll();

// Handle import request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sn = $_POST['device_sn'] ?? '';
    $jobType = $_POST['job_type'] ?? '';
    $conflictMode = $_POST['conflict_mode'] ?? 'skip';

    if (empty($sn) || empty($jobType)) {
        $message = 'Please select a device and import type.';
        $messageType = 'error';
    } else {
        // Validate device is approved
        $stmt = $db->prepare("SELECT status FROM devices WHERE serial_number = ?");
        $stmt->execute([$sn]);
        $device = $stmt->fetch();

        if (!$device || $device['status'] !== 'approved') {
            $message = 'Device is not approved. Only approved devices can be imported from.';
            $messageType = 'error';
        } else {
            // Create import job and queue command
            $stmt = $db->prepare("
                INSERT INTO import_jobs (device_sn, job_type, conflict_mode, status, requested_by, created_at)
                VALUES (?, ?, ?, 'queued', ?, NOW())
            ");
            $stmt->execute([$sn, $jobType, $conflictMode, $_SESSION['user_id']]);
            $jobId = $db->lastInsertId();

            // Queue the appropriate QUERY command
            $commandMap = [
                'users' => ['QUERY_USERINFO', 'DATA QUERY USERINFO'],
                'biometrics' => ['QUERY_BIODATA', 'DATA QUERY BIODATA'],
                'attendance' => ['QUERY_ATTLOG', 'DATA QUERY ATTLOG'],
            ];

            if (isset($commandMap[$jobType])) {
                $cmd = $commandMap[$jobType];
                $stmt = $db->prepare("
                    INSERT INTO device_commands (device_sn, command_type, command_content, priority, status, created_at)
                    VALUES (?, ?, ?, 2, 'pending', NOW())
                ");
                $stmt->execute([$sn, $cmd[0], $cmd[1]]);
            }

            auditLog('import_started', 'import_job', $jobId,
                     "Import {$jobType} from device SN={$sn}, mode={$conflictMode}");

            $message = "Import job #{$jobId} created! The device will push its {$jobType} data on the next poll (within ~30 seconds).";
            $messageType = 'success';
        }
    }
}

// Get recent import jobs
$recentWhere = $sn ? "WHERE device_sn = ?" : "";
$recentParams = $sn ? [$sn] : [];
$stmt = $db->prepare("
    SELECT ij.*, d.name as device_name
    FROM import_jobs ij
    LEFT JOIN devices d ON d.serial_number = ij.device_sn
    {$recentWhere}
    ORDER BY ij.created_at DESC LIMIT 20
");
$stmt->execute($recentParams);
$recentJobs = $stmt->fetchAll();
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<!-- IMPORT FORM -->
<div class="card">
    <div class="card-header"><h3>Start New Import</h3></div>
    <div class="card-body">
        <form method="POST">
            <div class="form-row">
                <div class="form-group">
                    <label for="device_sn">Select Device</label>
                    <select id="device_sn" name="device_sn" required>
                        <option value="">-- Choose a device --</option>
                        <?php foreach ($approvedDevices as $dev): ?>
                            <option value="<?= htmlspecialchars($dev['serial_number']) ?>"
                                <?= $sn === $dev['serial_number'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($dev['name'] ?: $dev['serial_number']) ?>
                                <?= $dev['location'] ? '(' . htmlspecialchars($dev['location']) . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="job_type">What to Import</label>
                    <select id="job_type" name="job_type" required>
                        <option value="">-- Select type --</option>
                        <option value="users">Employees (PIN, name, card, privilege)</option>
                        <option value="biometrics">Biometric Templates (face, fingerprint)</option>
                        <option value="attendance">Attendance Logs (historical punches)</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label for="conflict_mode">Conflict Mode (for existing records)</label>
                <select id="conflict_mode" name="conflict_mode">
                    <option value="skip">Skip existing — only import new records (safest)</option>
                    <option value="update">Update existing — device data overwrites DB</option>
                    <option value="update_blank">Update blank fields only — fill missing data, never overwrite</option>
                </select>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Start Import</button>
                <?php if ($sn): ?>
                    <a href="<?= BASE_PATH ?>/pages/devices/detail.php?sn=<?= urlencode($sn) ?>" class="btn btn-outline">Back to Device</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- RECENT IMPORT JOBS -->
<div class="card">
    <div class="card-header"><h3>Recent Import Jobs</h3></div>
    <div class="card-body">
        <?php if (empty($recentJobs)): ?>
            <p class="text-muted text-center">No import jobs yet.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Device</th>
                        <th>Type</th>
                        <th>Mode</th>
                        <th>Status</th>
                        <th>Inserted</th>
                        <th>Updated</th>
                        <th>Skipped</th>
                        <th>Failed</th>
                        <th>Started</th>
                        <th>Completed</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($recentJobs as $job): ?>
                    <tr>
                        <td>#<?= $job['id'] ?></td>
                        <td><?= htmlspecialchars($job['device_name'] ?: $job['device_sn']) ?></td>
                        <td><span class="badge badge-approved"><?= $job['job_type'] ?></span></td>
                        <td><?= $job['conflict_mode'] ?></td>
                        <td>
                            <?php
                            $statusClass = match($job['status']) {
                                'completed' => 'badge-approved',
                                'running' => 'badge-pending_approval',
                                'queued' => 'badge-suspended',
                                'failed' => 'badge-rejected',
                                default => 'badge-inactive',
                            };
                            ?>
                            <span class="badge <?= $statusClass ?>"><?= $job['status'] ?></span>
                        </td>
                        <td class="text-success"><?= $job['records_inserted'] ?></td>
                        <td><?= $job['records_updated'] ?></td>
                        <td><?= $job['records_skipped'] ?></td>
                        <td class="text-danger"><?= $job['records_failed'] ?></td>
                        <td><?= $job['started_at'] ? date('M j H:i', strtotime($job['started_at'])) : '—' ?></td>
                        <td><?= $job['completed_at'] ? date('M j H:i', strtotime($job['completed_at'])) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
