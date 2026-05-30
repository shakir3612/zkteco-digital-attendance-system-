<?php
/**
 * Single Employee Attendance Report - A4 Print/PDF ready
 * Page 1: Summary | Page 2+: Filtered daily detail
 */
$pageTitle = 'Employee Report';
require_once __DIR__ . '/../../includes/header.php';
$db = getDB();
$employees = $db->query("SELECT id, pin, name FROM employees WHERE status = 'active' ORDER BY name")->fetchAll();
$report = null; $employee = null; $summary = null;

// Default filters (all checked on first load, empty array if form submitted with none checked)
$filters = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $filters = $_POST['filters'] ?? [];
} else {
    $filters = ['present','absent','late','on_leave','holiday','weekend'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['pin'])) {
    $pin = $_POST['pin'] ?? $_GET['pin'] ?? '';
    $fromDate = $_POST['from_date'] ?? $_GET['from'] ?? date('Y-m-01');
    $toDate = $_POST['to_date'] ?? $_GET['to'] ?? date('Y-m-d');
    if ($pin) {
        $stmt = $db->prepare("SELECT e.*, g.name as grade_name FROM employees e LEFT JOIN grades g ON g.id = e.grade_id WHERE e.pin = ?");
        $stmt->execute([$pin]);
        $employee = $stmt->fetch();
        if ($employee) {
            $stmt = $db->prepare("SELECT s.name, s.start_time, s.end_time FROM employee_shifts es JOIN shifts s ON s.id = es.shift_id WHERE es.employee_id = ? ORDER BY es.effective_from DESC LIMIT 1");
            $stmt->execute([$employee['id']]);
            $shift = $stmt->fetch();

            $stmt = $db->prepare("SELECT * FROM attendance_daily WHERE pin = ? AND date BETWEEN ? AND ? ORDER BY date");
            $stmt->execute([$pin, $fromDate, $toDate]);
            $records = $stmt->fetchAll();

            $summary = ['work_days'=>0,'present'=>0,'absent'=>0,'late'=>0,'early'=>0,'leave'=>0,'holiday'=>0,'weekend'=>0,'single_punch'=>0,'total_hours'=>0,'late_min'=>0,'early_min'=>0];
            foreach ($records as $r) {
                if ($r['status']==='present') {
                    $summary['present']++; $summary['work_days']++;
                    if($r['was_late']){$summary['late']++;$summary['late_min']+=$r['late_minutes'];}
                    if($r['left_early']){$summary['early']++;$summary['early_min']+=$r['early_minutes'];}
                    if($r['single_punch'])$summary['single_punch']++;
                    if($r['total_hours'])$summary['total_hours']+=$r['total_hours'];
                }
                elseif ($r['status']==='absent') { $summary['absent']++; $summary['work_days']++; }
                elseif ($r['status']==='on_leave') $summary['leave']++;
                elseif ($r['status']==='holiday') $summary['holiday']++;
                elseif ($r['status']==='weekend') $summary['weekend']++;
            }
            $report = ['records'=>$records,'shift'=>$shift,'from'=>$fromDate,'to'=>$toDate];
        }
    }
}

$companyName = getSetting('company_name', 'Company');
?>

<!-- GENERATE REPORT FORM -->
<div class="card no-print">
    <div class="card-header"><h3>Generate Employee Report</h3></div>
    <div class="card-body">
        <form method="POST">
            <div class="form-row" style="grid-template-columns: 2fr 1fr 1fr;">
                <div class="form-group">
                    <label>Employee *</label>
                    <select name="pin" required>
                        <option value="">-- Select Employee --</option>
                        <?php foreach($employees as $e): ?>
                            <option value="<?= $e['pin'] ?>" <?= isset($pin)&&$pin===$e['pin']?'selected':'' ?>><?= htmlspecialchars($e['name']) ?> (<?= $e['pin'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>From</label>
                    <input type="date" name="from_date" value="<?= htmlspecialchars($fromDate ?? date('Y-m-01')) ?>">
                </div>
                <div class="form-group">
                    <label>To</label>
                    <input type="date" name="to_date" value="<?= htmlspecialchars($toDate ?? date('Y-m-d')) ?>">
                </div>
            </div>
            <div class="form-group">
                <label>Include in Report:</label>
                <div style="display:flex;flex-wrap:wrap;gap:16px;margin-top:6px">
                    <label class="checkbox-label"><input type="checkbox" name="filters[]" value="present" <?= in_array('present',$filters)?'checked':'' ?>> Present</label>
                    <label class="checkbox-label"><input type="checkbox" name="filters[]" value="absent" <?= in_array('absent',$filters)?'checked':'' ?>> Absent</label>
                    <label class="checkbox-label"><input type="checkbox" name="filters[]" value="late" <?= in_array('late',$filters)?'checked':'' ?>> Late</label>
                    <label class="checkbox-label"><input type="checkbox" name="filters[]" value="early" <?= in_array('early',$filters)?'checked':'' ?>> Early Leave</label>
                    <label class="checkbox-label"><input type="checkbox" name="filters[]" value="on_leave" <?= in_array('on_leave',$filters)?'checked':'' ?>> On Leave</label>
                    <label class="checkbox-label"><input type="checkbox" name="filters[]" value="holiday" <?= in_array('holiday',$filters)?'checked':'' ?>> Holiday</label>
                    <label class="checkbox-label"><input type="checkbox" name="filters[]" value="weekend" <?= in_array('weekend',$filters)?'checked':'' ?>> Weekend</label>
                    <label class="checkbox-label"><input type="checkbox" name="filters[]" value="single_punch" <?= in_array('single_punch',$filters)?'checked':'' ?>> Single Punch</label>
                    <label class="checkbox-label"><input type="checkbox" name="filters[]" value="total_hours" <?= in_array('total_hours',$filters)?'checked':'' ?>> Total Hours</label>
                    <label class="checkbox-label"><input type="checkbox" name="filters[]" value="total_late" <?= in_array('total_late',$filters)?'checked':'' ?>> Total Late Min</label>
                    <label class="checkbox-label"><input type="checkbox" name="filters[]" value="total_early" <?= in_array('total_early',$filters)?'checked':'' ?>> Total Early Min</label>
                </div>
            </div>
            <button type="submit" class="btn btn-primary">Generate Report</button>
        </form>
    </div>
</div>

<?php if ($report && $employee): ?>

<!-- PRINT BUTTON -->
<div class="no-print" style="margin-bottom:16px;text-align:right">
    <button onclick="window.print()" class="btn btn-success">🖨 Print / Save as PDF</button>
</div>

<!-- ==================== REPORT OUTPUT (A4 PRINTABLE) ==================== -->
<div id="report-output">

    <!-- PAGE 1: SUMMARY -->
    <div class="report-page report-summary-page">
        <div class="report-header">
            <img src="<?= BASE_PATH ?>/assets/logo.png" alt="Logo" class="report-logo">
            <h2><?= htmlspecialchars($companyName) ?></h2>
            <h3>Employee Attendance Report</h3>
        </div>

        <div class="report-employee-info">
            <table class="report-info-table">
                <tr><td class="info-label">Employee</td><td><?= htmlspecialchars($employee['name']) ?> (PIN: <?= $employee['pin'] ?>)</td></tr>
                <tr><td class="info-label">Grade</td><td><?= htmlspecialchars($employee['grade_name'] ?? '—') ?></td></tr>
                <tr><td class="info-label">Designation</td><td><?= htmlspecialchars($employee['designation'] ?? '—') ?></td></tr>
                <tr><td class="info-label">Shift</td><td><?= $report['shift'] ? $report['shift']['name'].' ('.substr($report['shift']['start_time'],0,5).' - '.substr($report['shift']['end_time'],0,5).')' : 'Regular 9-5 (09:00 - 17:00)' ?></td></tr>
                <tr><td class="info-label">Period</td><td><?= date('j F Y',strtotime($report['from'])) ?> — <?= date('j F Y',strtotime($report['to'])) ?></td></tr>
                <tr><td class="info-label">Generated</td><td><?= date('j F Y, h:i A') ?></td></tr>
            </table>
        </div>

        <div class="report-summary-section">
            <h4>SUMMARY</h4>
            <table class="report-summary-table">
                <tr><td>Total Work Days</td><td><?= $summary['work_days'] ?></td></tr>
                <?php if (in_array('present',$filters)): ?><tr><td>Days Present</td><td><?= $summary['present'] ?></td></tr><?php endif; ?>
                <?php if (in_array('absent',$filters)): ?><tr><td>Days Absent</td><td><?= $summary['absent'] ?></td></tr><?php endif; ?>
                <?php if (in_array('late',$filters)): ?><tr><td>Days Late</td><td><?= $summary['late'] ?></td></tr><?php endif; ?>
                <?php if (in_array('early',$filters)): ?><tr><td>Days Early Leave</td><td><?= $summary['early'] ?></td></tr><?php endif; ?>
                <?php if (in_array('on_leave',$filters)): ?><tr><td>Days On Leave</td><td><?= $summary['leave'] ?></td></tr><?php endif; ?>
                <?php if (in_array('holiday',$filters)): ?><tr><td>Holidays</td><td><?= $summary['holiday'] ?></td></tr><?php endif; ?>
                <?php if (in_array('weekend',$filters)): ?><tr><td>Weekends</td><td><?= $summary['weekend'] ?></td></tr><?php endif; ?>
                <?php if (in_array('single_punch',$filters)): ?><tr><td>Single Punch Days</td><td><?= $summary['single_punch'] ?></td></tr><?php endif; ?>
                <?php if (in_array('total_late',$filters)): ?><tr><td>Total Late Minutes</td><td><?= $summary['late_min'] ?></td></tr><?php endif; ?>
                <?php if (in_array('total_early',$filters)): ?><tr><td>Total Early Minutes</td><td><?= $summary['early_min'] ?></td></tr><?php endif; ?>
                <?php if (in_array('total_hours',$filters)): ?><tr><td>Total Hours Worked</td><td><?= number_format($summary['total_hours'],1) ?></td></tr><?php endif; ?>
            </table>
        </div>
    </div>

    <!-- PAGE 2+: DAILY DETAIL -->
    <div class="report-page report-detail-page">
        <div class="report-detail-header">
            <strong><?= htmlspecialchars($employee['name']) ?></strong> (PIN: <?= $employee['pin'] ?>) — <?= date('j M Y',strtotime($report['from'])) ?> to <?= date('j M Y',strtotime($report['to'])) ?>
        </div>
        <h4 style="margin-bottom:10px">DAILY DETAIL</h4>
        <table class="report-detail-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Day</th>
                    <th>In</th>
                    <th>Out</th>
                    <th>Hours</th>
                    <th>Status</th>
                    <th>Flags</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($report['records'] as $r):
                // Filter logic
                $show = false;
                if ($r['status']==='present' && in_array('present',$filters)) $show = true;
                if ($r['status']==='present' && $r['was_late'] && in_array('late',$filters)) $show = true;
                if ($r['status']==='present' && $r['left_early'] && in_array('early',$filters)) $show = true;
                if ($r['status']==='absent' && in_array('absent',$filters)) $show = true;
                if ($r['status']==='on_leave' && in_array('on_leave',$filters)) $show = true;
                if ($r['status']==='holiday' && in_array('holiday',$filters)) $show = true;
                if ($r['status']==='weekend' && in_array('weekend',$filters)) $show = true;
                if (!$show) continue;
            ?>
                <tr class="status-row-<?= $r['status'] ?><?= $r['was_late']?' row-late':'' ?><?= $r['left_early']?' row-early':'' ?>">
                    <td><?= date('d M',strtotime($r['date'])) ?></td>
                    <td><?= date('D',strtotime($r['date'])) ?></td>
                    <td><?= $r['first_in']?date('H:i',strtotime($r['first_in'])):'—' ?></td>
                    <td><?= $r['last_out']?date('H:i',strtotime($r['last_out'])):'—' ?></td>
                    <td><?= $r['total_hours']!==null?number_format($r['total_hours'],1):'—' ?></td>
                    <td><?= ucfirst(str_replace('_',' ',$r['status'])) ?></td>
                    <td>
                        <?php if($r['was_late']):?>Late(<?=$r['late_minutes']?>m) <?php endif;?>
                        <?php if($r['left_early']):?>Early(<?=$r['early_minutes']?>m) <?php endif;?>
                        <?php if($r['single_punch']):?>1-punch <?php endif;?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</div>
<?php endif; ?>

<!-- A4 PRINT STYLES -->
<style>
/* Report styles (screen) */
#report-output { background: #fff; }
.report-page { padding: 32px; }
.report-summary-page { border-bottom: 2px dashed var(--gray-300); }

.report-header { text-align: center; margin-bottom: 28px; }
.report-header h2 { font-size: 20px; margin-bottom: 2px; }
.report-header h3 { font-size: 14px; color: var(--gray-500); font-weight: 400; }
.report-logo { height: 50px; margin-bottom: 10px; }

.report-employee-info { margin-bottom: 28px; }
.report-info-table { width: 100%; font-size: 13px; border-collapse: collapse; }
.report-info-table td { padding: 5px 10px; }
.report-info-table .info-label { font-weight: 600; color: var(--gray-600); width: 130px; }

.report-summary-section { margin-top: 20px; }
.report-summary-section h4 { font-size: 14px; text-transform: uppercase; letter-spacing: 1px; color: var(--gray-500); border-bottom: 1px solid var(--gray-200); padding-bottom: 8px; margin-bottom: 12px; }
.report-summary-table { width: 60%; font-size: 13px; border-collapse: collapse; }
.report-summary-table td { padding: 6px 12px; }
.report-summary-table td:first-child { font-weight: 500; color: var(--gray-700); }
.report-summary-table td:last-child { font-weight: 700; }

.report-detail-header { font-size: 11px; color: var(--gray-500); margin-bottom: 8px; }
.report-detail-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.report-detail-table th { background: var(--gray-100); padding: 6px 8px; text-align: left; font-size: 11px; text-transform: uppercase; border-bottom: 2px solid var(--gray-300); }
.report-detail-table td { padding: 5px 8px; border-bottom: 1px solid var(--gray-100); }
.status-row-absent td { background: #fef2f2; }
.status-row-on_leave td { background: #fefce8; }
.status-row-holiday td { background: #f0fdf4; }
.status-row-weekend td { background: #f8fafc; color: var(--gray-400); }
.row-late td { border-left: 3px solid var(--warning); }
.row-early td { border-left: 3px solid var(--info); }

/* Print styles */
@media print {
    @page { size: A4; margin: 15mm; }

    body * { visibility: hidden; }
    #report-output, #report-output * { visibility: visible; }
    #report-output { position: absolute; left: 0; top: 0; width: 100%; }

    .no-print, .sidebar, .top-bar, .site-footer, .card:first-of-type { display: none !important; }
    .main-content { margin-left: 0 !important; padding: 0 !important; }
    .content-area { padding: 0 !important; }

    .report-summary-page { page-break-after: always; }
    .report-detail-page { page-break-before: always; }
    .report-detail-table { page-break-inside: auto; }
    .report-detail-table tr { page-break-inside: avoid; }

    .report-page { padding: 0; border: none; }
    .report-summary-section { margin-top: 30px; }
    .report-summary-table { width: 70%; }
    .report-logo { height: 40px; }
}
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
