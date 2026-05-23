<?php
/**
 * Custom Report Builder - selectable columns, filters, export
 */
$pageTitle = 'Custom Report';
require_once __DIR__ . '/../../includes/header.php';
$db = getDB();
$departments = $db->query("SELECT id, name FROM departments WHERE status='active' ORDER BY name")->fetchAll();
$employees = $db->query("SELECT id, pin, name FROM employees WHERE status='active' ORDER BY name")->fetchAll();
$report = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fromDate = $_POST['from_date'] ?? date('Y-m-01');
    $toDate = $_POST['to_date'] ?? date('Y-m-d');
    $deptFilter = $_POST['department_id'] ?? '';
    $columns = $_POST['columns'] ?? [];
    $grouping = $_POST['grouping'] ?? 'summary';

    $where = "WHERE ad.date BETWEEN ? AND ?";
    $params = [$fromDate, $toDate];
    if ($deptFilter) { $where .= " AND e.department_id = ?"; $params[] = $deptFilter; }

    if ($grouping === 'detailed') {
        $stmt = $db->prepare("SELECT ad.*, e.name as emp_name, e.pin as emp_pin, d.name as dept_name, e.designation, s.name as shift_name FROM attendance_daily ad JOIN employees e ON e.pin=ad.pin LEFT JOIN departments d ON d.id=e.department_id LEFT JOIN shifts s ON s.id=ad.shift_id {$where} ORDER BY e.name, ad.date");
        $stmt->execute($params);
        $report = ['rows' => $stmt->fetchAll(), 'type' => 'detailed', 'columns' => $columns];
    } else {
        $stmt = $db->prepare("SELECT e.pin, e.name as emp_name, d.name as dept_name, e.designation, s.name as shift_name,
            SUM(CASE WHEN ad.status IN('present','absent') THEN 1 ELSE 0 END) as work_days,
            SUM(CASE WHEN ad.status='present' THEN 1 ELSE 0 END) as days_present,
            SUM(CASE WHEN ad.was_late=1 THEN 1 ELSE 0 END) as days_late,
            SUM(CASE WHEN ad.left_early=1 THEN 1 ELSE 0 END) as days_early,
            SUM(CASE WHEN ad.status='absent' THEN 1 ELSE 0 END) as days_absent,
            SUM(CASE WHEN ad.status='on_leave' THEN 1 ELSE 0 END) as days_leave,
            SUM(CASE WHEN ad.status='holiday' THEN 1 ELSE 0 END) as days_holiday,
            SUM(CASE WHEN ad.status='weekend' THEN 1 ELSE 0 END) as days_weekend,
            COALESCE(SUM(ad.total_hours),0) as total_hours,
            COALESCE(SUM(ad.late_minutes),0) as total_late_min,
            COALESCE(SUM(ad.early_minutes),0) as total_early_min
            FROM attendance_daily ad JOIN employees e ON e.pin=ad.pin LEFT JOIN departments d ON d.id=e.department_id
            LEFT JOIN employee_shifts es ON es.employee_id=e.id AND es.effective_from<=? AND (es.effective_to IS NULL OR es.effective_to>=?)
            LEFT JOIN shifts s ON s.id=es.shift_id
            {$where} GROUP BY e.pin ORDER BY e.name");
        $stmt->execute(array_merge([$toDate, $fromDate], $params));
        $report = ['rows' => $stmt->fetchAll(), 'type' => 'summary', 'columns' => $columns];
    }
}
?>
<div class="card"><div class="card-header"><h3>Custom Report Builder</h3></div><div class="card-body">
<form method="POST">
<div class="form-row">
<div class="form-group"><label>From</label><input type="date" name="from_date" value="<?= htmlspecialchars($_POST['from_date'] ?? date('Y-m-01')) ?>"></div>
<div class="form-group"><label>To</label><input type="date" name="to_date" value="<?= htmlspecialchars($_POST['to_date'] ?? date('Y-m-d')) ?>"></div>
<div class="form-group"><label>Department</label><select name="department_id"><option value="">All</option><?php foreach($departments as $d):?><option value="<?=$d['id']?>"><?=htmlspecialchars($d['name'])?></option><?php endforeach;?></select></div>
</div>
<div class="form-group"><label>Columns</label><div style="display:flex;flex-wrap:wrap;gap:12px">
<?php $cols = ['pin'=>'PIN','name'=>'Name','department'=>'Department','designation'=>'Designation','shift'=>'Shift','work_days'=>'Work Days','present'=>'Present','late'=>'Late','early'=>'Early Leave','absent'=>'Absent','leave'=>'On Leave','holiday'=>'Holiday','weekend'=>'Weekend','hours'=>'Total Hours','late_min'=>'Late Minutes','early_min'=>'Early Minutes'];
foreach($cols as $k=>$v): ?><label class="checkbox-label"><input type="checkbox" name="columns[]" value="<?=$k?>" checked><?=$v?></label><?php endforeach; ?>
</div></div>
<div class="form-group"><label>Grouping</label><select name="grouping"><option value="summary">One row per employee (summary)</option><option value="detailed">One row per day (detailed)</option></select></div>
<button type="submit" class="btn btn-primary">Generate</button>
</form></div></div>

<?php if ($report): ?>
<div class="card"><div class="card-header"><h3>Results (<?= count($report['rows']) ?> rows)</h3><button onclick="window.print()" class="btn btn-sm btn-outline">Print</button></div>
<div class="card-body" style="overflow-x:auto">
<table class="table table-compact"><thead><tr>
<?php if ($report['type']==='summary'): ?>
<?php if(in_array('pin',$report['columns'])):?><th>PIN</th><?php endif;?>
<?php if(in_array('name',$report['columns'])):?><th>Name</th><?php endif;?>
<?php if(in_array('department',$report['columns'])):?><th>Dept</th><?php endif;?>
<?php if(in_array('work_days',$report['columns'])):?><th>Work Days</th><?php endif;?>
<?php if(in_array('present',$report['columns'])):?><th>Present</th><?php endif;?>
<?php if(in_array('late',$report['columns'])):?><th>Late</th><?php endif;?>
<?php if(in_array('early',$report['columns'])):?><th>Early</th><?php endif;?>
<?php if(in_array('absent',$report['columns'])):?><th>Absent</th><?php endif;?>
<?php if(in_array('leave',$report['columns'])):?><th>Leave</th><?php endif;?>
<?php if(in_array('hours',$report['columns'])):?><th>Hours</th><?php endif;?>
<?php if(in_array('late_min',$report['columns'])):?><th>Late Min</th><?php endif;?>
<?php if(in_array('early_min',$report['columns'])):?><th>Early Min</th><?php endif;?>
<?php else: ?>
<th>Date</th><th>PIN</th><th>Name</th><th>In</th><th>Out</th><th>Hours</th><th>Status</th><th>Flags</th>
<?php endif; ?>
</tr></thead><tbody>
<?php foreach($report['rows'] as $r): ?><tr>
<?php if ($report['type']==='summary'): ?>
<?php if(in_array('pin',$report['columns'])):?><td><?=$r['pin']?></td><?php endif;?>
<?php if(in_array('name',$report['columns'])):?><td><?=htmlspecialchars($r['emp_name'])?></td><?php endif;?>
<?php if(in_array('department',$report['columns'])):?><td><?=htmlspecialchars($r['dept_name']??'—')?></td><?php endif;?>
<?php if(in_array('work_days',$report['columns'])):?><td><?=$r['work_days']?></td><?php endif;?>
<?php if(in_array('present',$report['columns'])):?><td><?=$r['days_present']?></td><?php endif;?>
<?php if(in_array('late',$report['columns'])):?><td><?=$r['days_late']?></td><?php endif;?>
<?php if(in_array('early',$report['columns'])):?><td><?=$r['days_early']?></td><?php endif;?>
<?php if(in_array('absent',$report['columns'])):?><td><?=$r['days_absent']?></td><?php endif;?>
<?php if(in_array('leave',$report['columns'])):?><td><?=$r['days_leave']?></td><?php endif;?>
<?php if(in_array('hours',$report['columns'])):?><td><?=number_format($r['total_hours'],1)?></td><?php endif;?>
<?php if(in_array('late_min',$report['columns'])):?><td><?=$r['total_late_min']?></td><?php endif;?>
<?php if(in_array('early_min',$report['columns'])):?><td><?=$r['total_early_min']?></td><?php endif;?>
<?php else: ?>
<td><?=date('M j',strtotime($r['date']))?></td><td><?=$r['emp_pin']?></td><td><?=htmlspecialchars($r['emp_name'])?></td>
<td><?=$r['first_in']?date('H:i',strtotime($r['first_in'])):'—'?></td><td><?=$r['last_out']?date('H:i',strtotime($r['last_out'])):'—'?></td>
<td><?=$r['total_hours']!==null?number_format($r['total_hours'],1):'—'?></td><td><?=ucfirst($r['status'])?></td>
<td><?php if($r['was_late']):?>L<?php endif;?><?php if($r['left_early']):?>E<?php endif;?></td>
<?php endif; ?>
</tr><?php endforeach; ?>
</tbody></table></div></div>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
