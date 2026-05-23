<?php
/**
 * Daily Attendance View - shows processed attendance for a selected date.
 */
$pageTitle = 'Daily Attendance';
require_once __DIR__ . '/../../includes/header.php';

$db = getDB();
$selectedDate = $_GET['date'] ?? date('Y-m-d');
$deptFilter = $_GET['department'] ?? '';
$statusFilter = $_GET['att_status'] ?? '';

// Build query
$where = ["ad.date = ?"];
$params = [$selectedDate];

if ($deptFilter) {
    $where[] = "e.department_id = ?";
    $params[] = $deptFilter;
}
if ($statusFilter) {
    if ($statusFilter === 'late') {
        $where[] = "ad.was_late = 1";
    } elseif ($statusFilter === 'early_leave') {
        $where[] = "ad.left_early = 1";
    } elseif ($statusFilter === 'single_punch') {
        $where[] = "ad.single_punch = 1";
    } else {
        $where[] = "ad.status = ?";
        $params[] = $statusFilter;
    }
}

$whereClause = implode(' AND ', $where);

$stmt = $db->prepare("
    SELECT ad.*, e.name as emp_name, d.name as dept_name, s.name as shift_name
    FROM attendance_daily ad
    JOIN employees e ON e.pin = ad.pin
    LEFT JOIN departments d ON d.id = e.department_id
    LEFT JOIN shifts s ON s.id = ad.shift_id
    WHERE {$whereClause}
    ORDER BY e.name ASC
");
$stmt->execute($params);
$records = $stmt->fetchAll();

// Summary stats
$summary = ['present' => 0, 'absent' => 0, 'late' => 0, 'early' => 0, 'leave' => 0, 'holiday' => 0, 'weekend' => 0, 'single' => 0];
foreach ($records as $r) {
    if ($r['status'] === 'present') { $summary['present']++; if ($r['was_late']) $summary['late']++; if ($r['left_early']) $summary['early']++; if ($r['single_punch']) $summary['single']++; }
    elseif ($r['status'] === 'absent') $summary['absent']++;
    elseif ($r['status'] === 'on_leave') $summary['leave']++;
    elseif ($r['status'] === 'holiday') $summary['holiday']++;
    elseif ($r['status'] === 'weekend') $summary['weekend']++;
}

$departments = $db->query("SELECT id, name FROM departments WHERE status = 'active' ORDER BY name")->fetchAll();

// Check if processing has been done
$stmt2 = $db->prepare("SELECT COUNT(*) as cnt FROM attendance_daily WHERE date = ?");
$stmt2->execute([$selectedDate]);
$processedCount = $stmt2->fetch()['cnt'];
?>

<!-- DATE SELECTOR + FILTERS -->
<div class="card">
    <div class="card-body">
        <form method="GET" class="filter-bar">
            <input type="date" name="date" value="<?= htmlspecialchars($selectedDate) ?>" class="filter-input">
            <select name="department" class="filter-select">
                <option value="">All Departments</option>
                <?php foreach ($departments as $dept): ?>
                    <option value="<?= $dept['id'] ?>" <?= $deptFilter == $dept['id'] ? 'selected' : '' ?>><?= htmlspecialchars($dept['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="att_status" class="filter-select">
                <option value="">All Status</option>
                <option value="present" <?= $statusFilter === 'present' ? 'selected' : '' ?>>Present</option>
                <option value="absent" <?= $statusFilter === 'absent' ? 'selected' : '' ?>>Absent</option>
                <option value="late" <?= $statusFilter === 'late' ? 'selected' : '' ?>>Late</option>
                <option value="early_leave" <?= $statusFilter === 'early_leave' ? 'selected' : '' ?>>Early Leave</option>
                <option value="single_punch" <?= $statusFilter === 'single_punch' ? 'selected' : '' ?>>Single Punch</option>
                <option value="on_leave" <?= $statusFilter === 'on_leave' ? 'selected' : '' ?>>On Leave</option>
            </select>
            <button type="submit" class="btn btn-primary btn-sm">View</button>
            <a href="?date=<?= date('Y-m-d', strtotime($selectedDate . ' -1 day')) ?>" class="btn btn-outline btn-sm">&larr; Prev</a>
            <a href="?date=<?= date('Y-m-d', strtotime($selectedDate . ' +1 day')) ?>" class="btn btn-outline btn-sm">Next &rarr;</a>
            <a href="?date=<?= date('Y-m-d') ?>" class="btn btn-outline btn-sm">Today</a>
        </form>
    </div>
</div>

<?php if ($processedCount == 0): ?>
    <div class="alert alert-info">No processed attendance data for this date. Run the attendance processor to generate daily records from raw punches.</div>
<?php endif; ?>

<!-- SUMMARY STATS -->
<div class="stats-grid">
    <div class="stat-card"><div class="stat-value"><?= $summary['present'] ?></div><div class="stat-label">Present</div></div>
    <div class="stat-card"><div class="stat-value"><?= $summary['absent'] ?></div><div class="stat-label">Absent</div></div>
    <div class="stat-card"><div class="stat-value"><?= $summary['late'] ?></div><div class="stat-label">Late</div></div>
    <div class="stat-card"><div class="stat-value"><?= $summary['early'] ?></div><div class="stat-label">Early Leave</div></div>
    <div class="stat-card"><div class="stat-value"><?= $summary['single'] ?></div><div class="stat-label">Single Punch</div></div>
    <div class="stat-card"><div class="stat-value"><?= $summary['leave'] ?></div><div class="stat-label">On Leave</div></div>
</div>

<!-- ATTENDANCE TABLE -->
<div class="card">
    <div class="card-header">
        <h3><?= date('l, F j, Y', strtotime($selectedDate)) ?></h3>
        <span class="text-muted"><?= count($records) ?> records</span>
    </div>
    <div class="card-body">
        <table class="table">
            <thead>
                <tr>
                    <th>PIN</th>
                    <th>Name</th>
                    <th>Dept</th>
                    <th>Shift</th>
                    <th>In</th>
                    <th>Out</th>
                    <th>Hours</th>
                    <th>Status</th>
                    <th>Flags</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($records)): ?>
                <tr><td colspan="9" class="text-center text-muted">No records for this date.</td></tr>
            <?php endif; ?>
            <?php foreach ($records as $r): ?>
                <tr>
                    <td><code><?= htmlspecialchars($r['pin']) ?></code></td>
                    <td><?= htmlspecialchars($r['emp_name']) ?></td>
                    <td><?= htmlspecialchars($r['dept_name'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($r['shift_name'] ?? '—') ?></td>
                    <td><?= $r['first_in'] ? date('H:i', strtotime($r['first_in'])) : '—' ?></td>
                    <td><?= $r['last_out'] ? date('H:i', strtotime($r['last_out'])) : ($r['single_punch'] ? '<span class="text-muted">single</span>' : '—') ?></td>
                    <td><?= $r['total_hours'] !== null ? number_format($r['total_hours'], 1) . 'h' : '—' ?></td>
                    <td><span class="badge badge-<?= $r['status'] === 'present' ? 'approved' : ($r['status'] === 'absent' ? 'rejected' : 'suspended') ?>"><?= $r['status'] ?></span></td>
                    <td>
                        <?php if ($r['was_late']): ?><span class="badge badge-pending_approval">Late <?= $r['late_minutes'] ?>m</span> <?php endif; ?>
                        <?php if ($r['left_early']): ?><span class="badge badge-suspended">Early <?= $r['early_minutes'] ?>m</span> <?php endif; ?>
                        <?php if ($r['single_punch']): ?><span class="badge badge-inactive">1 punch</span> <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
