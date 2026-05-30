<?php
/**
 * Employees List - Search, filter by grade/status
 */
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$db = getDB();

// Handle delete
if (isset($_GET['delete']) && isset($_GET['pin'])) {
    $pin = $_GET['pin'];
    $emp = $db->prepare("SELECT id, name FROM employees WHERE pin = ?");
    $emp->execute([$pin]);
    $empData = $emp->fetch();
    if ($empData) {
        // Delete from employees
        $db->prepare("DELETE FROM employees WHERE pin = ?")->execute([$pin]);
        // Remove shift assignments
        $db->prepare("DELETE FROM employee_shifts WHERE employee_id = ?")->execute([$empData['id']]);
        // Queue DELETE_USER to all approved devices
        $devices = $db->query("SELECT serial_number FROM devices WHERE status = 'approved'")->fetchAll();
        foreach ($devices as $dev) {
            $db->prepare("INSERT INTO device_commands (device_sn, command_type, command_content, priority, status, created_at) VALUES (?, 'DELETE_USER', ?, 2, 'pending', NOW())")
               ->execute([$dev['serial_number'], "DATA DELETE USERINFO PIN={$pin}"]);
        }
        auditLog('employee_deleted', 'employee', $empData['id'], "Deleted PIN={$pin} ({$empData['name']})");
        header('Location: ' . BASE_PATH . '/pages/employees/list.php?msg=' . urlencode("Employee {$empData['name']} (PIN: {$pin}) deleted."));
        exit;
    }
}

$pageTitle = 'Employees';
require_once __DIR__ . '/../../includes/header.php';

if (isset($_GET['msg'])) {
    echo '<div class="alert alert-success">' . htmlspecialchars($_GET['msg']) . '</div>';
}

// Filters
$search = trim($_GET['search'] ?? '');
$gradeFilter = $_GET['grade'] ?? '';
$statusFilter = $_GET['status'] ?? 'active';

// Build query
$where = [];
$params = [];

if ($search) {
    $where[] = "(e.pin LIKE ? OR e.name LIKE ? OR e.phone LIKE ? OR e.email LIKE ? OR e.designation LIKE ? OR e.card_number LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
if ($gradeFilter) {
    $where[] = "e.grade_id = ?";
    $params[] = $gradeFilter;
}
if ($statusFilter && $statusFilter !== 'all') {
    $where[] = "e.status = ?";
    $params[] = $statusFilter;
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $db->prepare("
    SELECT e.*, g.name as grade_name,
           s.name as shift_name
    FROM employees e
    LEFT JOIN grades g ON g.id = e.grade_id
    LEFT JOIN employee_shifts es ON es.employee_id = e.id 
        AND es.effective_from <= CURDATE() 
        AND (es.effective_to IS NULL OR es.effective_to >= CURDATE())
    LEFT JOIN shifts s ON s.id = es.shift_id
    {$whereClause}
    ORDER BY e.name ASC
");
$stmt->execute($params);
$employees = $stmt->fetchAll();

// Get grades for filter
$grades = $db->query("SELECT id, name FROM grades WHERE status = 'active' ORDER BY name")->fetchAll();
?>

<div class="card">
    <div class="card-header">
        <h3>Employees (<?= count($employees) ?>)</h3>
        <a href="<?= BASE_PATH ?>/pages/employees/form.php" class="btn btn-primary">+ Add Employee</a>
    </div>
    <div class="card-body">
        <!-- FILTERS -->
        <form method="GET" class="filter-bar">
            <input type="text" name="search" placeholder="Search PIN, name, phone, email, designation, card..." 
                   value="<?= htmlspecialchars($search) ?>" class="filter-input" style="min-width:300px">
            <select name="grade" class="filter-select">
                <option value="">All Grades</option>
                <?php foreach ($grades as $g): ?>
                    <option value="<?= $g['id'] ?>" <?= $gradeFilter == $g['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($g['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="status" class="filter-select">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Status</option>
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                <option value="terminated" <?= $statusFilter === 'terminated' ? 'selected' : '' ?>>Terminated</option>
            </select>
            <button type="submit" class="btn btn-outline btn-sm">Filter</button>
            <?php if ($search || $gradeFilter || $statusFilter !== 'active'): ?>
            <a href="<?= BASE_PATH ?>/pages/employees/list.php" class="btn btn-xs btn-outline">Clear</a>
            <?php endif; ?>
            <input type="text" id="instantSearch" placeholder="Quick filter..." class="filter-input" style="margin-left:auto;min-width:180px">
        </form>

        <!-- TABLE -->
        <table class="table" id="employeesTable">
            <thead>
                <tr>
                    <th>PIN</th>
                    <th>Name</th>
                    <th>Grade</th>
                    <th>Designation</th>
                    <th>Shift</th>
                    <th>Phone</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($employees)): ?>
                <tr><td colspan="8" class="text-center text-muted">No employees found.</td></tr>
            <?php endif; ?>
            <?php foreach ($employees as $emp): ?>
                <tr>
                    <td><code><?= htmlspecialchars($emp['pin']) ?></code></td>
                    <td><strong><?= htmlspecialchars($emp['name']) ?></strong></td>
                    <td><?= htmlspecialchars($emp['grade_name'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($emp['designation'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($emp['shift_name'] ?? 'Default 9-5') ?></td>
                    <td><?= htmlspecialchars($emp['phone'] ?? '—') ?></td>
                    <td><span class="badge badge-<?= $emp['status'] === 'active' ? 'approved' : 'inactive' ?>"><?= $emp['status'] ?></span></td>
                    <td>
                        <a href="<?= BASE_PATH ?>/pages/employees/form.php?id=<?= $emp['id'] ?>" class="btn btn-xs">Edit</a>
                        <a href="<?= BASE_PATH ?>/pages/employees/sync.php?pin=<?= urlencode($emp['pin']) ?>" class="btn btn-xs btn-outline">Sync</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.getElementById('instantSearch').addEventListener('input', function() {
    var query = this.value.toLowerCase();
    var rows = document.querySelectorAll('#employeesTable tbody tr');
    rows.forEach(function(row) {
        var text = row.textContent.toLowerCase();
        row.style.display = text.includes(query) ? '' : 'none';
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
