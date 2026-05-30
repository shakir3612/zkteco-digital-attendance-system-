<?php
/**
 * Shift Overrides - Temporary office hours (govt orders, Ramadan, etc.)
 */
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$db = getDB();
$message = '';
$messageType = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $startTime = $_POST['start_time'] ?? '';
        $endTime = $_POST['end_time'] ?? '';
        $graceLate = (int)($_POST['grace_minutes_late'] ?? 30);
        $graceEarly = (int)($_POST['grace_minutes_early'] ?? 30);
        $fromDate = $_POST['from_date'] ?? '';
        $toDate = $_POST['to_date'] ?: null;
        $reason = trim($_POST['reason'] ?? '');

        if ($name && $startTime && $endTime && $fromDate) {
            $db->prepare("INSERT INTO shift_overrides (name, start_time, end_time, grace_minutes_late, grace_minutes_early, from_date, to_date, reason, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW())")
               ->execute([$name, $startTime, $endTime, $graceLate, $graceEarly, $fromDate, $toDate, $reason, $_SESSION['user_id']]);
            auditLog('shift_override_created', 'shift_override', $db->lastInsertId(), "Created: {$name} ({$startTime}-{$endTime}) from {$fromDate}");
            $message = "Shift override '{$name}' created.";
            $messageType = 'success';
        } else {
            $message = 'Name, start time, end time, and from date are required.';
            $messageType = 'error';
        }
    } elseif ($action === 'deactivate') {
        $id = (int)($_POST['override_id'] ?? 0);
        if ($id) {
            $db->prepare("UPDATE shift_overrides SET status = 'inactive' WHERE id = ?")->execute([$id]);
            auditLog('shift_override_deactivated', 'shift_override', $id, "Deactivated");
            $message = "Override deactivated. Normal shifts will apply.";
            $messageType = 'info';
        }
    } elseif ($action === 'activate') {
        $id = (int)($_POST['override_id'] ?? 0);
        if ($id) {
            $db->prepare("UPDATE shift_overrides SET status = 'active' WHERE id = ?")->execute([$id]);
            auditLog('shift_override_activated', 'shift_override', $id, "Reactivated");
            $message = "Override reactivated.";
            $messageType = 'success';
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['override_id'] ?? 0);
        if ($id) {
            $db->prepare("DELETE FROM shift_overrides WHERE id = ?")->execute([$id]);
            auditLog('shift_override_deleted', 'shift_override', $id, "Deleted");
            $message = "Override deleted.";
            $messageType = 'info';
        }
    }
}

// Check current active override
$activeOverride = $db->query("SELECT * FROM shift_overrides WHERE status = 'active' AND from_date <= CURDATE() AND (to_date IS NULL OR to_date >= CURDATE()) ORDER BY created_at DESC LIMIT 1")->fetch();

// Get all overrides
$overrides = $db->query("SELECT * FROM shift_overrides ORDER BY created_at DESC")->fetchAll();

$pageTitle = 'Shift Overrides';
require_once __DIR__ . '/../../includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<!-- CURRENT STATUS -->
<?php if ($activeOverride): ?>
<div class="alert alert-info" style="font-size:14px">
    <strong>⚡ Active Override:</strong> <?= htmlspecialchars($activeOverride['name']) ?>
    (<?= substr($activeOverride['start_time'],0,5) ?> - <?= substr($activeOverride['end_time'],0,5) ?>)
    — Since <?= date('j M Y', strtotime($activeOverride['from_date'])) ?>
    <?= $activeOverride['to_date'] ? ' until ' . date('j M Y', strtotime($activeOverride['to_date'])) : ' (until further notice)' ?>
    <?php if ($activeOverride['reason']): ?><br><small><?= htmlspecialchars($activeOverride['reason']) ?></small><?php endif; ?>
</div>
<?php else: ?>
<div class="alert alert-success" style="font-size:14px">
    <strong>✓ Normal Schedule:</strong> No active override. Employee shifts apply as configured.
</div>
<?php endif; ?>

<!-- ADD NEW OVERRIDE -->
<div class="card">
    <div class="card-header"><h3>Add Temporary Office Hours</h3></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="form-row">
                <div class="form-group">
                    <label>Name *</label>
                    <input type="text" name="name" required placeholder="e.g. Summer Hours, Ramadan Schedule">
                </div>
                <div class="form-group">
                    <label>Reason</label>
                    <input type="text" name="reason" placeholder="e.g. Government order dated...">
                </div>
            </div>
            <div class="form-row" style="grid-template-columns: 1fr 1fr 1fr 1fr;">
                <div class="form-group">
                    <label>Start Time *</label>
                    <input type="time" name="start_time" required value="09:00">
                </div>
                <div class="form-group">
                    <label>End Time *</label>
                    <input type="time" name="end_time" required value="16:00">
                </div>
                <div class="form-group">
                    <label>Grace Late (min)</label>
                    <input type="number" name="grace_minutes_late" value="30" min="0" max="120">
                </div>
                <div class="form-group">
                    <label>Grace Early (min)</label>
                    <input type="number" name="grace_minutes_early" value="30" min="0" max="120">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>From Date *</label>
                    <input type="date" name="from_date" required value="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label>To Date <small>(leave empty = until further notice)</small></label>
                    <input type="date" name="to_date" value="">
                </div>
            </div>
            <button type="submit" class="btn btn-primary">Add Override</button>
        </form>
    </div>
</div>

<!-- OVERRIDES LIST -->
<div class="card">
    <div class="card-header"><h3>All Overrides</h3></div>
    <div class="card-body">
        <table class="table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Hours</th>
                    <th>Grace</th>
                    <th>From</th>
                    <th>To</th>
                    <th>Reason</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($overrides)): ?>
                <tr><td colspan="8" class="text-center text-muted">No overrides created yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($overrides as $ov): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($ov['name']) ?></strong></td>
                    <td><?= substr($ov['start_time'],0,5) ?> - <?= substr($ov['end_time'],0,5) ?></td>
                    <td><?= $ov['grace_minutes_late'] ?>m / <?= $ov['grace_minutes_early'] ?>m</td>
                    <td><?= date('j M Y', strtotime($ov['from_date'])) ?></td>
                    <td><?= $ov['to_date'] ? date('j M Y', strtotime($ov['to_date'])) : '<em>Until further notice</em>' ?></td>
                    <td class="text-small"><?= htmlspecialchars($ov['reason'] ?? '—') ?></td>
                    <td><span class="badge badge-<?= $ov['status']==='active'?'approved':'inactive' ?>"><?= $ov['status'] ?></span></td>
                    <td>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="override_id" value="<?= $ov['id'] ?>">
                            <?php if ($ov['status'] === 'active'): ?>
                                <button name="action" value="deactivate" class="btn btn-xs btn-outline" onclick="return confirm('Deactivate this override?')">Deactivate</button>
                            <?php else: ?>
                                <button name="action" value="activate" class="btn btn-xs btn-success">Activate</button>
                            <?php endif; ?>
                            <button name="action" value="delete" class="btn btn-xs btn-danger" onclick="return confirm('Delete this override permanently?')">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
