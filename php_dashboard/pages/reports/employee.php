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

// Present and Absent are ALWAYS included. The checkboxes control the optional extras.
$filters = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $filters = $_POST['filters'] ?? [];
} else {
    $filters = ['late','on_leave','holiday','weekend','pending'];
}
// Force present + absent to always be included.
if (!in_array('present', $filters)) $filters[] = 'present';
if (!in_array('absent', $filters)) $filters[] = 'absent';

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

            // Calendar context so we can label days that have NO processed row yet.
            $hstmt = $db->prepare("SELECT date, name FROM holidays WHERE date BETWEEN ? AND ?");
            $hstmt->execute([$fromDate, $toDate]);
            $holidayMap = [];
            foreach ($hstmt->fetchAll() as $h) { $holidayMap[$h['date']] = $h['name']; }
            $weeklyOff = array_map('trim', explode(',', strtolower(getSetting('weekly_off_days', 'fri,sat'))));
            $dowMap = [1=>'mon',2=>'tue',3=>'wed',4=>'thu',5=>'fri',6=>'sat',7=>'sun']; // ISO-8601 (N)

            // Index processed rows by date.
            $byDate = [];
            foreach ($records as $r) { $byDate[$r['date']] = $r; }

            // Build a UNIFIED list with one entry per calendar day in the range.
            // A day with no processed row is NOT silently dropped: working days become
            // 'unprocessed' (counted as pending / no-data), off days are labelled from
            // the calendar. This is what prevents a connectivity gap from hiding days.
            $days = [];
            $cursor = new DateTime($fromDate);
            $endDt = new DateTime($toDate);
            while ($cursor <= $endDt) {
                $ds = $cursor->format('Y-m-d');
                if (isset($byDate[$ds])) {
                    $row = $byDate[$ds];
                    $row['is_synthetic'] = false;
                    $days[] = $row;
                } else {
                    $dow = $dowMap[(int)$cursor->format('N')];
                    if (isset($holidayMap[$ds]))      { $st = 'holiday';      $dt = 'holiday'; }
                    elseif (in_array($dow, $weeklyOff)) { $st = 'weekend';      $dt = 'weekend'; }
                    else                                { $st = 'unprocessed';  $dt = 'working'; }
                    $days[] = [
                        'date'=>$ds, 'status'=>$st, 'day_type'=>$dt,
                        'first_in'=>null, 'last_out'=>null, 'total_hours'=>null,
                        'was_late'=>0, 'left_early'=>0, 'late_minutes'=>0, 'early_minutes'=>0,
                        'single_punch'=>0, 'worked_on_off_day'=>0,
                        'is_pending'=>($st === 'unprocessed' ? 1 : 0), 'is_synthetic'=>true,
                    ];
                }
                $cursor->modify('+1 day');
            }

            // Summary. NOTE: work_days = present + absent only; 'pending'/'unprocessed'
            // days are NOT counted as absent (we don't yet trust the data is complete).
            // Holiday/weekend duty (worked_on_off_day) counts as present.
            $summary = ['work_days'=>0,'present'=>0,'absent'=>0,'late'=>0,'early'=>0,'leave'=>0,
                        'holiday'=>0,'weekend'=>0,'single_punch'=>0,'pending'=>0,'holiday_duty'=>0,
                        'late_min'=>0,'early_min'=>0];
            foreach ($days as $r) {
                switch ($r['status']) {
                    case 'present':
                        $summary['present']++; $summary['work_days']++;
                        if (!empty($r['worked_on_off_day'])) $summary['holiday_duty']++;
                        if (!empty($r['was_late']))  { $summary['late']++;  $summary['late_min']  += $r['late_minutes']; }
                        if (!empty($r['left_early'])){ $summary['early']++; $summary['early_min'] += $r['early_minutes']; }
                        if (!empty($r['single_punch'])) $summary['single_punch']++;
                        break;
                    case 'absent':       $summary['absent']++; $summary['work_days']++; break;
                    case 'on_leave':     $summary['leave']++;   break;
                    case 'holiday':      $summary['holiday']++; break;
                    case 'weekend':      $summary['weekend']++; break;
                    case 'pending':
                    case 'unprocessed':  $summary['pending']++; break;
                }
            }
            $report = ['days'=>$days,'shift'=>$shift,'from'=>$fromDate,'to'=>$toDate];
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
                <label>Also Include in Report:</label>
                <div style="display:flex;flex-wrap:wrap;gap:16px;margin-top:6px">
                    <label class="checkbox-label"><input type="checkbox" name="filters[]" value="late" <?= in_array('late',$filters)?'checked':'' ?>> Late</label>
                    <label class="checkbox-label"><input type="checkbox" name="filters[]" value="early" <?= in_array('early',$filters)?'checked':'' ?>> Early Leave</label>
                    <label class="checkbox-label"><input type="checkbox" name="filters[]" value="on_leave" <?= in_array('on_leave',$filters)?'checked':'' ?>> On Leave</label>
                    <label class="checkbox-label"><input type="checkbox" name="filters[]" value="holiday" <?= in_array('holiday',$filters)?'checked':'' ?>> Holiday</label>
                    <label class="checkbox-label"><input type="checkbox" name="filters[]" value="weekend" <?= in_array('weekend',$filters)?'checked':'' ?>> Weekend</label>
                    <label class="checkbox-label"><input type="checkbox" name="filters[]" value="single_punch" <?= in_array('single_punch',$filters)?'checked':'' ?>> Single Punch</label>
                    <label class="checkbox-label"><input type="checkbox" name="filters[]" value="pending" <?= in_array('pending',$filters)?'checked':'' ?>> Pending / No Data</label>
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
                <tr><td>Days Present</td><td><?= $summary['present'] ?></td></tr>
                <?php if ($summary['holiday_duty']>0): ?><tr><td>&nbsp;&nbsp;&#8627; of which Holiday/Weekend Duty</td><td><?= $summary['holiday_duty'] ?></td></tr><?php endif; ?>
                <tr><td>Days Absent</td><td><?= $summary['absent'] ?></td></tr>
                <?php if (in_array('pending',$filters)): ?><tr><td>Days Pending / No Data</td><td><?= $summary['pending'] ?></td></tr><?php endif; ?>
                <?php if (in_array('late',$filters)): ?><tr><td>Days Late</td><td><?= $summary['late'] ?></td></tr><?php endif; ?>
                <?php if (in_array('early',$filters)): ?><tr><td>Days Early Leave</td><td><?= $summary['early'] ?></td></tr><?php endif; ?>
                <?php if (in_array('on_leave',$filters)): ?><tr><td>Days On Leave</td><td><?= $summary['leave'] ?></td></tr><?php endif; ?>
                <?php if (in_array('holiday',$filters)): ?><tr><td>Holidays</td><td><?= $summary['holiday'] ?></td></tr><?php endif; ?>
                <?php if (in_array('weekend',$filters)): ?><tr><td>Weekends</td><td><?= $summary['weekend'] ?></td></tr><?php endif; ?>
                <?php if (in_array('single_punch',$filters)): ?><tr><td>Single Punch Days</td><td><?= $summary['single_punch'] ?></td></tr><?php endif; ?>
                <?php if (in_array('total_late',$filters)): ?><tr><td>Total Late Minutes</td><td><?= $summary['late_min'] ?></td></tr><?php endif; ?>
                <?php if (in_array('total_early',$filters)): ?><tr><td>Total Early Minutes</td><td><?= $summary['early_min'] ?></td></tr><?php endif; ?>
            </table>
            <?php if ($summary['pending']>0): ?>
            <p class="report-pending-note">&#9888; <?= $summary['pending'] ?> day(s) have no confirmed data yet (device offline or not yet processed). These are shown as <strong>Pending</strong> and are <strong>not</strong> counted as absent.</p>
            <?php endif; ?>
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
                    <th>Status</th>
                    <th>Flags</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($report['days'] as $r):
                $st = $r['status'];
                $isPendingRow = ($st === 'pending' || $st === 'unprocessed');
                // Filter logic (which rows to display)
                $show = false;
                if ($st==='present' && in_array('present',$filters)) $show = true;
                if ($st==='present' && !empty($r['was_late']) && in_array('late',$filters)) $show = true;
                if ($st==='present' && !empty($r['left_early']) && in_array('early',$filters)) $show = true;
                if ($st==='absent' && in_array('absent',$filters)) $show = true;
                if ($st==='on_leave' && in_array('on_leave',$filters)) $show = true;
                if ($st==='holiday' && in_array('holiday',$filters)) $show = true;
                if ($st==='weekend' && in_array('weekend',$filters)) $show = true;
                if ($isPendingRow && in_array('pending',$filters)) $show = true;
                if (!$show) continue;

                // Display label
                if ($isPendingRow) { $label = 'Pending / No data'; }
                elseif ($st==='present' && !empty($r['worked_on_off_day'])) { $label = 'Present ('.ucfirst($r['day_type']).' Duty)'; }
                else { $label = ucfirst(str_replace('_',' ',$st)); }
                $rowClass = 'status-row-'.($isPendingRow ? 'pending' : $st);
            ?>
                <tr class="<?= $rowClass ?><?= !empty($r['was_late'])?' row-late':'' ?><?= !empty($r['left_early'])?' row-early':'' ?>">
                    <td><?= date('d M',strtotime($r['date'])) ?></td>
                    <td><?= date('D',strtotime($r['date'])) ?></td>
                    <td><?= $r['first_in']?date('H:i',strtotime($r['first_in'])):'—' ?></td>
                    <td><?= $r['last_out']?date('H:i',strtotime($r['last_out'])):'—' ?></td>
                    <td><?= htmlspecialchars($label) ?></td>
                    <td>
                        <?php if(!empty($r['worked_on_off_day'])):?><?= ucfirst($r['day_type']) ?> duty <?php endif;?>
                        <?php if(!empty($r['was_late'])):?>Late(<?=$r['late_minutes']?>m) <?php endif;?>
                        <?php if(!empty($r['left_early'])):?>Early(<?=$r['early_minutes']?>m) <?php endif;?>
                        <?php if(!empty($r['single_punch'])):?>1-punch <?php endif;?>
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
.status-row-pending td { background: #fff7ed; color: var(--gray-600); font-style: italic; }
.row-late td { border-left: 3px solid var(--warning); }
.row-early td { border-left: 3px solid var(--info); }
.report-pending-note { margin-top: 14px; font-size: 12px; color: #b45309; background: #fff7ed; border: 1px solid #fed7aa; border-radius: 4px; padding: 8px 12px; }

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
