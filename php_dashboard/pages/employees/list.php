<?php
/**
 * Employees List - Search, filter by department/status
 */
$pageTitle = 'Employees';
require_once __DIR__ . '/../../includes/header.php';

$db = getDB();

// Filters
$search = trim($_GET['search'] ?? '');
$deptFilter = $_GET['department'] ?? '';
$statusFilter = $_GET['status'] ?? 'active';

// Build query
$where = [];
$params = [];

if ($search) {
    $where[] = "(e.pin LIKE ? OR e.name LIKE ? OR e.phone LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
if ($deptFilter) {
    $where[] = "e.department_id = ?";
    $params[] = $deptFilter;
}
if ($statusFilter && $statusFilter !== 'all') {
    $where[] = "e.status = ?";
    $params[] = $statusFilter;
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $db->prepare("
    SELECT e.*, d.name as department_name,
           s.name as shift_name
    FROM employees e
    LEFT JOIN departments d ON d.id = e.department_id
    LEFT JOIN employee_shifts es ON es.employee_id = e.id 
        AND es.effective_from <= CURDATE() 
        AND (es.effective_to IS NULL OR es.effective_to >= CURDATE())
    LEFT JOIN shifts s ON s.id = es.shift_id
    {$whereClause}
    ORDER BY e.name ASC
");
$stmt->execute($params);
$employees = $stmt->fetchAll();

// Get departments for filter
$departments = $db->query("SELECT id, name FROM departments WHERE status = 'active' ORDER BY name")->fetchAll();
?>

<div class="card">
    <div class="card-header">
        <h3>Employees (<?= count($employees) ?>)</h3>
        <a href="<?= BASE_PATH ?>/pages/employees/form.php" class="btn btn-primary">+ Add Employee</a>
    </div>
    <div class="card-body">
        <!-- FILTERS -->
        <form method="GET" class="filter-bar">
            <input type="text" name="search" placeholder="Search PIN, name, phone..." 
                   value="<?= htmlspecialchars($search) ?>" class="filter-input">
            <select name="department" class="filter-select">
                <option value="">All Departments</option>
                <?php foreach ($departments as $dept): ?>
                    <option value="<?= $dept['id'] ?>" <?= $deptFilter == $dept['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($dept['name']) ?>
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
        </form>

        <!-- TABLE -->
        <table class="table">
            <thead>
                <tr>
                    <th>PIN</th>
                    <th>Name</th>
                    <th>Department</th>
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
                    <td><?= htmlspecialchars($emp['department_name'] ?? '—') ?></td>
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
