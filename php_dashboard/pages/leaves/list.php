<?php
/**
 * Leave Management - Apply, approve, reject leaves
 */
$pageTitle = 'Leave Management';
require_once __DIR__ . '/../../includes/header.php';

$db = getDB();
$message = '';
$messageType = '';
$statusFilter = $_GET['status'] ?? 'pending';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'apply') {
        $empId = $_POST['employee_id'] ?? '';
        $leaveType = $_POST['leave_type'] ?? '';
        $fromDate = $_POST['from_date'] ?? '';
        $toDate = $_POST['to_date'] ?? '';
        $reason = trim($_POST['reason'] ?? '');
        if ($empId && $leaveType && $fromDate && $toDate) {
            $days = (strtotime($toDate) - strtotime($fromDate)) / 86400 + 1;
            $db->prepare("INSERT INTO leaves (employee_id, leave_type, from_date, to_date, days, reason, status, applied_at) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())")->execute([$empId, $leaveType, $fromDate, $toDate, $days, $reason]);
            auditLog('leave_applied', 'leave', $db->lastInsertId(), "Leave for emp #{$empId}");
            $message = "Leave application submitted ({$days} days)."; $messageType = 'success';
        }
    } elseif ($action === 'approve') {
        $lid = $_POST['leave_id'] ?? 0;
        $db->prepare("UPDATE leaves SET status = 'approved', approved_by = ?, actioned_at = NOW() WHERE id = ?")->execute([$_SESSION['user_id'], $lid]);
        auditLog('leave_approved', 'leave', $lid, "Approved"); $message = "Leave approved."; $messageType = 'success';
    } elseif ($action === 'reject') {
        $lid = $_POST['leave_id'] ?? 0;
        $db->prepare("UPDATE leaves SET status = 'rejected', approved_by = ?, actioned_at = NOW() WHERE id = ?")->execute([$_SESSION['user_id'], $lid]);
        $message = "Leave rejected."; $messageType = 'info';
    } elseif ($action === 'cancel') {
        $lid = $_POST['leave_id'] ?? 0;
        $db->prepare("UPDATE leaves SET status = 'cancelled', actioned_at = NOW() WHERE id = ?")->execute([$lid]);
        $message = "Leave cancelled."; $messageType = 'info';
    }
}

$where = ""; $params = [];
if ($statusFilter && $statusFilter !== 'all') { $where = "WHERE l.status = ?"; $params[] = $statusFilter; }
$stmt = $db->prepare("SELECT l.*, e.name as emp_name, e.pin as emp_pin, g.name as grade_name FROM leaves l JOIN employees e ON e.id = l.employee_id LEFT JOIN grades g ON g.id = e.grade_id {$where} ORDER BY l.applied_at DESC LIMIT 50");
$stmt->execute($params);
$leaves = $stmt->fetchAll();
$employees = $db->query("SELECT id, pin, name FROM employees WHERE status = 'active' ORDER BY name")->fetchAll();
?>
<?php if ($message): ?><div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<div class="card"><div class="card-header"><h3>Apply Leave</h3></div><div class="card-body">
<form method="POST"><input type="hidden" name="action" value="apply">
<div class="form-row"><div class="form-group"><label>Employee *</label><select name="employee_id" required><option value="">-- Select --</option><?php foreach ($employees as $emp): ?><option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['name']) ?> (<?= $emp['pin'] ?>)</option><?php endforeach; ?></select></div>
<div class="form-group"><label>Leave Type *</label><select name="leave_type" required><option value="casual">Casual</option><option value="sick">Sick</option><option value="annual">Annual</option><option value="unpaid">Unpaid</option><option value="maternity">Maternity</option><option value="other">Other</option></select></div></div>
<div class="form-row"><div class="form-group"><label>From *</label><input type="date" name="from_date" required value="<?= date('Y-m-d') ?>"></div><div class="form-group"><label>To *</label><input type="date" name="to_date" required value="<?= date('Y-m-d') ?>"></div></div>
<div class="form-group"><label>Reason</label><textarea name="reason" rows="2"></textarea></div>
<button type="submit" class="btn btn-primary">Apply Leave</button></form></div></div>

<div class="card"><div class="card-header"><h3>Leave Records</h3>
<form method="GET"><select name="status" class="filter-select" onchange="this.form.submit()"><option value="all" <?= $statusFilter==='all'?'selected':'' ?>>All</option><option value="pending" <?= $statusFilter==='pending'?'selected':'' ?>>Pending</option><option value="approved" <?= $statusFilter==='approved'?'selected':'' ?>>Approved</option><option value="rejected" <?= $statusFilter==='rejected'?'selected':'' ?>>Rejected</option></select></form></div>
<div class="card-body"><table class="table"><thead><tr><th>Employee</th><th>Type</th><th>From</th><th>To</th><th>Days</th><th>Reason</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php foreach ($leaves as $lv): ?><tr><td><strong><?= htmlspecialchars($lv['emp_name']) ?></strong></td><td><?= $lv['leave_type'] ?></td><td><?= date('M j', strtotime($lv['from_date'])) ?></td><td><?= date('M j', strtotime($lv['to_date'])) ?></td><td><?= $lv['days'] ?></td><td class="text-small"><?= htmlspecialchars($lv['reason'] ?? '') ?></td>
<td><span class="badge badge-<?= match($lv['status']){'approved'=>'approved','rejected'=>'rejected','pending'=>'pending_approval',default=>'inactive'} ?>"><?= $lv['status'] ?></span></td>
<td><?php if ($lv['status']==='pending'): ?><form method="POST" style="display:inline"><input type="hidden" name="leave_id" value="<?= $lv['id'] ?>"><button name="action" value="approve" class="btn btn-xs btn-success">Approve</button><button name="action" value="reject" class="btn btn-xs btn-danger">Reject</button></form><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
