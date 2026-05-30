<?php
/**
 * Shifts Management - Add/Edit shifts with grace periods
 */
$pageTitle = 'Shifts';
require_once __DIR__ . '/../../includes/header.php';

$db = getDB();
$message = '';
$messageType = '';

// Handle form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $name = trim($_POST['name'] ?? '');
    $startTime = $_POST['start_time'] ?? '';
    $endTime = $_POST['end_time'] ?? '';
    $graceLate = (int)($_POST['grace_minutes_late'] ?? 30);
    $graceEarly = (int)($_POST['grace_minutes_early'] ?? 30);
    $fullDay = (float)($_POST['full_day_hours'] ?? 8.0);
    $isNight = isset($_POST['is_night_shift']) ? 1 : 0;

    if ($action === 'add' && $name && $startTime && $endTime) {
        $db->prepare("INSERT INTO shifts (name, start_time, end_time, grace_minutes_late, grace_minutes_early, full_day_hours, is_night_shift, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'active')")
            ->execute([$name, $startTime, $endTime, $graceLate, $graceEarly, $fullDay, $isNight]);
        auditLog('shift_created', 'shift', $db->lastInsertId(), "Created: {$name}");
        $message = "Shift '{$name}' created.";
        $messageType = 'success';
    } elseif ($action === 'edit') {
        $sid = $_POST['shift_id'] ?? 0;
        if ($sid && $name) {
            $db->prepare("UPDATE shifts SET name=?, start_time=?, end_time=?, grace_minutes_late=?, grace_minutes_early=?, full_day_hours=?, is_night_shift=? WHERE id=?")
                ->execute([$name, $startTime, $endTime, $graceLate, $graceEarly, $fullDay, $isNight, $sid]);
            $message = "Shift updated.";
            $messageType = 'success';
        }
    } elseif ($action === 'toggle') {
        $sid = $_POST['shift_id'] ?? 0;
        $db->prepare("UPDATE shifts SET status = IF(status='active','inactive','active') WHERE id = ?")->execute([$sid]);
        $message = "Shift status toggled.";
        $messageType = 'info';
    }
}

// Get shifts with employee count
$shifts = $db->query("
    SELECT s.*, 
           (SELECT COUNT(DISTINCT es.employee_id) FROM employee_shifts es 
            WHERE es.shift_id = s.id AND (es.effective_to IS NULL OR es.effective_to >= CURDATE())) as employee_count
    FROM shifts s
    ORDER BY s.status DESC, s.name
")->fetchAll();
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<!-- ADD SHIFT FORM -->
<div class="card">
    <div class="card-header"><h3>Add New Shift</h3></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="form-row" style="grid-template-columns: 1fr 1fr 1fr;">
                <div class="form-group">
                    <label>Shift Name *</label>
                    <input type="text" name="name" required placeholder="e.g., Morning Shift">
                </div>
                <div class="form-group">
                    <label>Start Time *</label>
                    <input type="time" name="start_time" required value="09:00">
                </div>
                <div class="form-group">
                    <label>Grace Late (min)</label>
                    <input type="number" name="grace_minutes_late" value="30" min="0" max="120">
                </div>
            </div>
            <div class="form-row" style="grid-template-columns: 1fr 1fr 1fr;">
                <div class="form-group">
                    <label>End Time *</label>
                    <input type="time" name="end_time" required value="17:00">
                </div>
                <div class="form-group">
                    <label>Grace Early (min)</label>
                    <input type="number" name="grace_minutes_early" value="30" min="0" max="120">
                </div>
                <div class="form-group">
                    <label>Full Day Hours</label>
                    <input type="number" name="full_day_hours" value="8.00" step="0.5" min="1" max="24">
                </div>
            </div>
            <div class="form-group">
                <label class="checkbox-label"><input type="checkbox" name="is_night_shift"> Night shift (crosses midnight)</label>
            </div>
            <button type="submit" class="btn btn-primary">Add Shift</button>
        </form>
    </div>
</div>

<!-- SHIFTS LIST -->
<div class="card">
    <div class="card-header"><h3>All Shifts (<?= count($shifts) ?>)</h3></div>
    <div class="card-body">
        <table class="table">
            <thead><tr><th>Name</th><th>Time</th><th>Grace In</th><th>Grace Out</th><th>Hours</th><th>Night</th><th>Employees</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($shifts as $s): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($s['name']) ?></strong></td>
                    <td><?= substr($s['start_time'],0,5) ?> - <?= substr($s['end_time'],0,5) ?></td>
                    <td><?= $s['grace_minutes_late'] ?> min</td>
                    <td><?= $s['grace_minutes_early'] ?> min</td>
                    <td><?= $s['full_day_hours'] ?>h</td>
                    <td><?= $s['is_night_shift'] ? 'Yes' : 'No' ?></td>
                    <td><?= $s['employee_count'] ?></td>
                    <td><span class="badge badge-<?= $s['status'] === 'active' ? 'approved' : 'inactive' ?>"><?= $s['status'] ?></span></td>
                    <td>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="shift_id" value="<?= $s['id'] ?>">
                            <button type="submit" class="btn btn-xs btn-outline"><?= $s['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
