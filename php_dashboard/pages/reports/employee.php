<?php
/**
 * Single Employee Attendance Report - Print/PDF ready
 */
$pageTitle = 'Employee Report';
require_once __DIR__ . '/../../includes/header.php';
$db = getDB();
$employees = $db->query("SELECT id, pin, name FROM employees WHERE status = 'active' ORDER BY name")->fetchAll();
$report = null; $employee = null; $summary = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['pin'])) {
    $pin = $_POST['pin'] ?? $_GET['pin'] ?? '';
    $fromDate = $_POST['from_date'] ?? $_GET['from'] ?? date('Y-m-01');
    $toDate = $_POST['to_date'] ?? $_GET['to'] ?? date('Y-m-d');
    if ($pin) {
        $stmt = $db->prepare("SELECT e.*, d.name as dept_name FROM employees e LEFT JOIN departments d ON d.id = e.department_id WHERE e.pin = ?"); $stmt->execute([$pin]); $employee = $stmt->fetch();
        if ($employee) {
            $stmt = $db->prepare("SELECT s.name, s.start_time, s.end_time FROM employee_shifts es JOIN shifts s ON s.id = es.shift_id WHERE es.employee_id = ? ORDER BY es.effective_from DESC LIMIT 1"); $stmt->execute([$employee['id']]); $shift = $stmt->fetch();
            $stmt = $db->prepare("SELECT * FROM attendance_daily WHERE pin = ? AND date BETWEEN ? AND ? ORDER BY date"); $stmt->execute([$pin, $fromDate, $toDate]); $records = $stmt->fetchAll();
            $summary = ['work_days'=>0,'present'=>0,'absent'=>0,'late'=>0,'early'=>0,'leave'=>0,'holiday'=>0,'weekend'=>0,'total_hours'=>0,'late_min'=>0,'early_min'=>0];
            foreach ($records as $r) {
                if ($r['status']==='present') { $summary['present']++; $summary['work_days']++; if($r['was_late']){$summary['late']++;$summary['late_min']+=$r['late_minutes'];} if($r['left_early']){$summary['early']++;$summary['early_min']+=$r['early_minutes'];} if($r['total_hours'])$summary['total_hours']+=$r['total_hours']; }
                elseif ($r['status']==='absent') { $summary['absent']++; $summary['work_days']++; }
                elseif ($r['status']==='on_leave') $summary['leave']++;
                elseif ($r['status']==='holiday') $summary['holiday']++;
                elseif ($r['status']==='weekend') $summary['weekend']++;
            }
            $report = ['records'=>$records,'shift'=>$shift,'from'=>$fromDate,'to'=>$toDate];
        }
    }
}
?>
<div class="card"><div class="card-header"><h3>Generate Report</h3></div><div class="card-body">
<form method="POST"><div class="form-row">
<div class="form-group"><label>Employee *</label><select name="pin" required><option value="">-- Select --</option><?php foreach($employees as $e): ?><option value="<?= $e['pin'] ?>" <?= isset($pin)&&$pin===$e['pin']?'selected':'' ?>><?= htmlspecialchars($e['name']) ?> (<?= $e['pin'] ?>)</option><?php endforeach; ?></select></div>
<div class="form-group"><label>From</label><input type="date" name="from_date" value="<?= htmlspecialchars($fromDate ?? date('Y-m-01')) ?>"></div>
<div class="form-group"><label>To</label><input type="date" name="to_date" value="<?= htmlspecialchars($toDate ?? date('Y-m-d')) ?>"></div>
</div><button type="submit" class="btn btn-primary">Generate</button></form></div></div>

<?php if ($report): ?>
<div class="card" id="report-output"><div class="card-header"><h3>Attendance Report</h3><button onclick="window.print()" class="btn btn-sm btn-outline">Print / PDF</button></div>
<div class="card-body">
<div style="text-align:center;margin-bottom:20px"><h2><?= htmlspecialchars(getSetting('company_name','Company')) ?></h2><h4 style="color:var(--gray-500)">Employee Attendance Report</h4></div>
<div class="detail-grid">
<div class="detail-item"><label>Employee:</label><span><?= htmlspecialchars($employee['name']) ?> (PIN: <?= $employee['pin'] ?>)</span></div>
<div class="detail-item"><label>Department:</label><span><?= htmlspecialchars($employee['dept_name'] ?? '—') ?></span></div>
<div class="detail-item"><label>Designation:</label><span><?= htmlspecialchars($employee['designation'] ?? '—') ?></span></div>
<div class="detail-item"><label>Shift:</label><span><?= $report['shift'] ? $report['shift']['name'].' ('.substr($report['shift']['start_time'],0,5).'-'.substr($report['shift']['end_time'],0,5).')' : 'Default 9-5' ?></span></div>
<div class="detail-item"><label>Period:</label><span><?= date('M j, Y',strtotime($report['from'])) ?> — <?= date('M j, Y',strtotime($report['to'])) ?></span></div>
</div><hr>
<h4>Summary</h4>
<div class="stats-grid stats-grid-sm">
<div class="stat-card"><div class="stat-value"><?= $summary['work_days'] ?></div><div class="stat-label">Work Days</div></div>
<div class="stat-card"><div class="stat-value"><?= $summary['present'] ?></div><div class="stat-label">Present</div></div>
<div class="stat-card"><div class="stat-value"><?= $summary['absent'] ?></div><div class="stat-label">Absent</div></div>
<div class="stat-card"><div class="stat-value"><?= $summary['late'] ?></div><div class="stat-label">Late</div></div>
<div class="stat-card"><div class="stat-value"><?= $summary['early'] ?></div><div class="stat-label">Early Leave</div></div>
<div class="stat-card"><div class="stat-value"><?= $summary['leave'] ?></div><div class="stat-label">On Leave</div></div>
<div class="stat-card"><div class="stat-value"><?= $summary['holiday'] ?></div><div class="stat-label">Holidays</div></div>
<div class="stat-card"><div class="stat-value"><?= $summary['weekend'] ?></div><div class="stat-label">Weekends</div></div>
<div class="stat-card"><div class="stat-value"><?= number_format($summary['total_hours'],1) ?></div><div class="stat-label">Hours</div></div>
<div class="stat-card"><div class="stat-value"><?= $summary['late_min'] ?></div><div class="stat-label">Late (min)</div></div>
<div class="stat-card"><div class="stat-value"><?= $summary['early_min'] ?></div><div class="stat-label">Early (min)</div></div>
</div><hr>
<h4>Daily Detail</h4>
<table class="table table-compact"><thead><tr><th>Date</th><th>Day</th><th>In</th><th>Out</th><th>Hours</th><th>Status</th><th>Flags</th></tr></thead><tbody>
<?php foreach ($report['records'] as $r): ?><tr>
<td><?= date('M j',strtotime($r['date'])) ?></td><td><?= date('D',strtotime($r['date'])) ?></td>
<td><?= $r['first_in']?date('H:i',strtotime($r['first_in'])):'—' ?></td>
<td><?= $r['last_out']?date('H:i',strtotime($r['last_out'])):'—' ?></td>
<td><?= $r['total_hours']!==null?number_format($r['total_hours'],1):'—' ?></td>
<td><span class="badge badge-<?= $r['status']==='present'?'approved':($r['status']==='absent'?'rejected':'suspended') ?>"><?= ucfirst($r['status']) ?></span></td>
<td><?php if($r['was_late']):?>Late(<?=$r['late_minutes']?>m) <?php endif;?><?php if($r['left_early']):?>Early(<?=$r['early_minutes']?>m) <?php endif;?><?php if($r['single_punch']):?>1-punch<?php endif;?></td>
</tr><?php endforeach; ?></tbody></table></div></div>
<style>@media print{.sidebar,.top-bar,.card:first-child,.btn{display:none!important}.main-content{margin-left:0!important}#report-output{box-shadow:none}}</style>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
