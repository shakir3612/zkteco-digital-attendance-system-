<?php
/**
 * Departments Management - Add/Edit/Deactivate departments
 */
$pageTitle = 'Departments';
require_once __DIR__ . '/../../includes/header.php';

$db = getDB();
$message = '';
$messageType = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        if ($name) {
            $db->prepare("INSERT INTO departments (name, status) VALUES (?, 'active')")->execute([$name]);
            auditLog('department_created', 'department', $db->lastInsertId(), "Created: {$name}");
            $message = "Department '{$name}' created.";
            $messageType = 'success';
        }
    } elseif ($action === 'edit') {
        $did = $_POST['dept_id'] ?? 0;
        $name = trim($_POST['name'] ?? '');
        if ($did && $name) {
            $db->prepare("UPDATE departments SET name = ? WHERE id = ?")->execute([$name, $did]);
            $message = "Department updated.";
            $messageType = 'success';
        }
    } elseif ($action === 'toggle') {
        $did = $_POST['dept_id'] ?? 0;
        $db->prepare("UPDATE departments SET status = IF(status='active','inactive','active') WHERE id = ?")->execute([$did]);
        $message = "Department status toggled.";
        $messageType = 'info';
    }
}

// Get all departments with employee count
$departments = $db->query("
    SELECT d.*, COUNT(e.id) as employee_count
    FROM departments d
    LEFT JOIN employees e ON e.department_id = d.id AND e.status = 'active'
    GROUP BY d.id
    ORDER BY d.status DESC, d.name
")->fetchAll();
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div class="grid-2col">
    <!-- ADD FORM -->
    <div class="card">
        <div class="card-header"><h3>Add Department</h3></div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="form-group">
                    <label for="name">Department Name</label>
                    <input type="text" id="name" name="name" required placeholder="e.g., Operations, HR, Finance">
                </div>
                <button type="submit" class="btn btn-primary">Add Department</button>
            </form>
        </div>
    </div>

    <!-- LIST -->
    <div class="card">
        <div class="card-header"><h3>All Departments (<?= count($departments) ?>)</h3></div>
        <div class="card-body">
            <table class="table table-compact">
                <thead><tr><th>Name</th><th>Employees</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($departments as $dept): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($dept['name']) ?></strong></td>
                        <td><?= $dept['employee_count'] ?></td>
                        <td><span class="badge badge-<?= $dept['status'] === 'active' ? 'approved' : 'inactive' ?>"><?= $dept['status'] ?></span></td>
                        <td>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="dept_id" value="<?= $dept['id'] ?>">
                                <button type="submit" class="btn btn-xs btn-outline"><?= $dept['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
