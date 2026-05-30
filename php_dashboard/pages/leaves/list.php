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
        if (empty($toDate)) { $toDate = $fromDate; }
        if ($empId && $leaveType && $fromDate) {
            if (empty($toDate)) { $toDate = $fromDate; }
            // Auto-swap: earlier date = from, later date = to
            if (strtotime($toDate) < strtotime($fromDate)) {
                $tmp = $fromDate; $fromDate = $toDate; $toDate = $tmp;
            }
            $days = (strtotime($toDate) - strtotime($fromDate)) / 86400 + 1;
            $db->prepare("INSERT INTO leaves (employee_id, leave_type, from_date, to_date, days, reason, status, applied_at) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())")->execute([$empId, $leaveType, $fromDate, $toDate, $days, $reason]);
            auditLog('leave_applied', 'leave', $db->lastInsertId(), "Leave for emp #{$empId}");
            $message = "Leave application submitted ({$days} day" . ($days > 1 ? 's' : '') . ")."; $messageType = 'success';
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
        auditLog('leave_cancelled', 'leave', $lid, "Cancelled");
        $message = "Leave cancelled."; $messageType = 'info';
    } elseif ($action === 'edit') {
        $lid = $_POST['leave_id'] ?? 0;
        $newFrom = $_POST['edit_from_date'] ?? '';
        $newTo = $_POST['edit_to_date'] ?? '';
        $newType = $_POST['edit_leave_type'] ?? '';
        $newReason = trim($_POST['edit_reason'] ?? '');
        if ($lid && $newFrom && $newType) {
            if (empty($newTo)) { $newTo = $newFrom; }
            // Auto-swap: earlier date = from, later date = to
            if (strtotime($newTo) < strtotime($newFrom)) {
                $tmp = $newFrom; $newFrom = $newTo; $newTo = $tmp;
            }
            $newDays = (strtotime($newTo) - strtotime($newFrom)) / 86400 + 1;
            $db->prepare("UPDATE leaves SET leave_type = ?, from_date = ?, to_date = ?, days = ?, reason = ?, actioned_at = NOW() WHERE id = ?")
               ->execute([$newType, $newFrom, $newTo, $newDays, $newReason, $lid]);
            auditLog('leave_modified', 'leave', $lid, "Modified: {$newType}, {$newFrom} to {$newTo} ({$newDays} days)");
            $message = "Leave modified ({$newDays} day" . ($newDays > 1 ? 's' : '') . ")."; $messageType = 'success';
        }
    }
}

$where = ""; $params = [];
$leaveSearch = trim($_GET['leave_search'] ?? '');
if ($statusFilter && $statusFilter !== 'all') { $where = "WHERE l.status = ?"; $params[] = $statusFilter; }
if ($leaveSearch) {
    $where .= ($where ? " AND" : "WHERE") . " (e.name LIKE ? OR e.pin LIKE ? OR l.leave_type LIKE ? OR l.reason LIKE ?)";
    $params[] = "%{$leaveSearch}%";
    $params[] = "%{$leaveSearch}%";
    $params[] = "%{$leaveSearch}%";
    $params[] = "%{$leaveSearch}%";
}
$limit = $leaveSearch ? 50 : 10;
$stmt = $db->prepare("SELECT l.*, e.name as emp_name, e.pin as emp_pin, g.name as grade_name FROM leaves l JOIN employees e ON e.id = l.employee_id LEFT JOIN grades g ON g.id = e.grade_id {$where} ORDER BY l.applied_at DESC LIMIT {$limit}");
$stmt->execute($params);
$leaves = $stmt->fetchAll();
$employees = $db->query("SELECT id, pin, name FROM employees WHERE status = 'active' ORDER BY name")->fetchAll();
?>
<?php if ($message): ?><div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<div class="card"><div class="card-header"><h3>Apply Leave</h3></div><div class="card-body">
<form method="POST"><input type="hidden" name="action" value="apply">
<div class="form-row"><div class="form-group"><label>Employee *</label><select name="employee_id" required><option value="">-- Select --</option><?php foreach ($employees as $emp): ?><option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['name']) ?> (<?= $emp['pin'] ?>)</option><?php endforeach; ?></select></div>
<div class="form-group"><label>Leave Type *</label><select name="leave_type" required><option value="casual">Casual</option><option value="sick">Sick</option><option value="annual">Annual</option><option value="unpaid">Unpaid</option><option value="maternity">Maternity</option><option value="other">Other</option></select></div></div>
<div class="form-row"><div class="form-group"><label>From *</label><input type="date" name="from_date" required value="<?= date('Y-m-d') ?>"></div><div class="form-group"><label>To <small>(optional, defaults to From)</small></label><input type="date" name="to_date" value=""></div></div>
<div class="form-group"><label>Reason</label><textarea name="reason" rows="2"></textarea></div>
<button type="submit" class="btn btn-primary">Apply Leave</button></form></div></div>

<!-- LEAVE SUMMARY (Current Year) -->
<?php
$currentYear = date('Y');
$summaryEmpId = $_GET['summary_emp'] ?? '';

if ($summaryEmpId) {
    $summaryStmt = $db->prepare("
        SELECT e.pin, e.name,
            SUM(CASE WHEN l.leave_type='casual' THEN l.days ELSE 0 END) as casual,
            SUM(CASE WHEN l.leave_type='sick' THEN l.days ELSE 0 END) as sick,
            SUM(CASE WHEN l.leave_type='annual' THEN l.days ELSE 0 END) as annual,
            SUM(CASE WHEN l.leave_type='unpaid' THEN l.days ELSE 0 END) as unpaid,
            SUM(CASE WHEN l.leave_type='maternity' THEN l.days ELSE 0 END) as maternity,
            SUM(CASE WHEN l.leave_type='other' THEN l.days ELSE 0 END) as other_leave,
            SUM(l.days) as total_days
        FROM leaves l
        JOIN employees e ON e.id = l.employee_id
        WHERE l.status = 'approved' AND YEAR(l.from_date) = ? AND e.id = ?
        GROUP BY e.id
    ");
    $summaryStmt->execute([$currentYear, $summaryEmpId]);
    $leaveSummary = $summaryStmt->fetchAll();
} else {
    $leaveSummary = [];
}
?>
<div class="card">
    <div class="card-header">
        <h3>Leave Summary (<?= $currentYear ?>)</h3>
    </div>
    <div class="card-body">
        <form method="GET" class="filter-bar" style="margin-bottom:12px;overflow:visible;align-items:center">
            <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
            <div style="max-width:300px;position:relative;z-index:10">
            <select name="summary_emp" style="min-width:250px">
                <option value="">-- Select Employee --</option>
                <?php foreach ($employees as $emp): ?>
                    <option value="<?= $emp['id'] ?>" <?= $summaryEmpId == $emp['id'] ? 'selected' : '' ?>><?= htmlspecialchars($emp['name']) ?> (<?= $emp['pin'] ?>)</option>
                <?php endforeach; ?>
            </select>
            </div>
            <button type="submit" class="btn btn-outline btn-sm" style="height:36px">View Summary</button>
        </form>
        <?php if (!$summaryEmpId): ?>
            <div class="alert alert-info" style="margin:0">Select an employee to view their leave summary.</div>
        <?php elseif (empty($leaveSummary)): ?>
            <div class="alert alert-info" style="margin:0">No approved leaves this year for this employee.</div>
        <?php else: ?>
        <table class="table table-compact">
            <thead>
                <tr>
                    <th>PIN</th>
                    <th>Employee</th>
                    <th>Casual</th>
                    <th>Sick</th>
                    <th>Annual</th>
                    <th>Unpaid</th>
                    <th>Maternity</th>
                    <th>Other</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($leaveSummary as $ls): ?>
                <tr>
                    <td><code><?= htmlspecialchars($ls['pin']) ?></code></td>
                    <td><?= htmlspecialchars($ls['name']) ?></td>
                    <td><?= $ls['casual'] > 0 ? $ls['casual'] : '—' ?></td>
                    <td><?= $ls['sick'] > 0 ? $ls['sick'] : '—' ?></td>
                    <td><?= $ls['annual'] > 0 ? $ls['annual'] : '—' ?></td>
                    <td><?= $ls['unpaid'] > 0 ? $ls['unpaid'] : '—' ?></td>
                    <td><?= $ls['maternity'] > 0 ? $ls['maternity'] : '—' ?></td>
                    <td><?= $ls['other_leave'] > 0 ? $ls['other_leave'] : '—' ?></td>
                    <td><strong><?= $ls['total_days'] ?></strong></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<div class="card"><div class="card-header"><h3>Leave Records<?= $leaveSearch ? ' (search results)' : ' (recent 10)' ?></h3>
<form method="GET" style="display:flex;gap:8px;align-items:center"><input type="text" name="leave_search" placeholder="Search name, PIN, type..." value="<?= htmlspecialchars($leaveSearch) ?>" class="filter-input" style="min-width:180px"><select name="status" class="filter-select" onchange="this.form.submit()"><option value="all" <?= $statusFilter==='all'?'selected':'' ?>>All</option><option value="pending" <?= $statusFilter==='pending'?'selected':'' ?>>Pending</option><option value="approved" <?= $statusFilter==='approved'?'selected':'' ?>>Approved</option><option value="rejected" <?= $statusFilter==='rejected'?'selected':'' ?>>Rejected</option></select><button type="submit" class="btn btn-xs btn-outline">Search</button><?php if($leaveSearch):?><a href="?status=<?= $statusFilter ?>" class="btn btn-xs btn-outline">Clear</a><?php endif;?></form></div>
<div class="card-body"><table class="table"><thead><tr><th>Employee</th><th>Type</th><th>From</th><th>To</th><th>Days</th><th>Reason</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php foreach ($leaves as $lv): ?><tr><td><strong><?= htmlspecialchars($lv['emp_name']) ?></strong></td><td><?= $lv['leave_type'] ?></td><td><?= date('M j', strtotime($lv['from_date'])) ?></td><td><?= date('M j', strtotime($lv['to_date'])) ?></td><td><?= $lv['days'] ?></td><td class="text-small"><?= htmlspecialchars($lv['reason'] ?? '') ?></td>
<td><span class="badge badge-<?= match($lv['status']){'approved'=>'approved','rejected'=>'rejected','pending'=>'pending_approval',default=>'inactive'} ?>"><?= $lv['status'] ?></span></td>
<td><?php if ($lv['status']==='pending'): ?><form method="POST" style="display:inline"><input type="hidden" name="leave_id" value="<?= $lv['id'] ?>"><button name="action" value="approve" class="btn btn-xs btn-success">Approve</button><button name="action" value="reject" class="btn btn-xs btn-danger">Reject</button></form><?php elseif ($lv['status']==='approved'): ?><form method="POST" style="display:inline"><input type="hidden" name="leave_id" value="<?= $lv['id'] ?>"><button name="action" value="cancel" class="btn btn-xs btn-danger" onclick="return confirm('Cancel this approved leave?')">Cancel</button></form> <button class="btn btn-xs btn-outline" onclick="openEditModal(<?= $lv['id'] ?>,'<?= $lv['leave_type'] ?>','<?= $lv['from_date'] ?>','<?= $lv['to_date'] ?>','<?= htmlspecialchars(addslashes($lv['reason'] ?? ''), ENT_QUOTES) ?>')">Edit</button><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

<!-- EDIT LEAVE MODAL -->
<div id="editLeaveModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center">
<div style="background:#fff;border-radius:8px;padding:24px;max-width:450px;width:90%;margin:auto;margin-top:10vh">
<h3 style="margin-bottom:16px">Edit Leave</h3>
<form method="POST">
<input type="hidden" name="action" value="edit">
<input type="hidden" name="leave_id" id="editLeaveId">
<div class="form-group"><label>Leave Type</label><select name="edit_leave_type" id="editLeaveType" class="no-search"><option value="casual">Casual</option><option value="sick">Sick</option><option value="annual">Annual</option><option value="unpaid">Unpaid</option><option value="maternity">Maternity</option><option value="other">Other</option></select></div>
<div class="form-row"><div class="form-group"><label>From</label><input type="date" name="edit_from_date" id="editFromDate" required></div><div class="form-group"><label>To</label><input type="date" name="edit_to_date" id="editToDate" required></div></div>
<div class="form-group"><label>Reason</label><textarea name="edit_reason" id="editReason" rows="2"></textarea></div>
<div class="form-actions"><button type="submit" class="btn btn-primary">Save Changes</button><button type="button" class="btn btn-outline" onclick="closeEditModal()">Cancel</button></div>
</form></div></div>
<script>
function openEditModal(id, type, from, to, reason) {
    document.getElementById('editLeaveId').value = id;
    document.getElementById('editLeaveType').value = type;
    document.getElementById('editFromDate').value = from;
    document.getElementById('editToDate').value = to;
    document.getElementById('editReason').value = reason;
    document.getElementById('editLeaveModal').style.display = 'flex';
}
function closeEditModal() { document.getElementById('editLeaveModal').style.display = 'none'; }
document.getElementById('editLeaveModal').addEventListener('click', function(e) { if (e.target === this) closeEditModal(); });
</script>
