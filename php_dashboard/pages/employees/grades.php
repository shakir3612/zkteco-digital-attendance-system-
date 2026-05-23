<?php
/**
 * Grades Management - Add/Edit/Deactivate employee grades
 */
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$db = getDB();
$message = '';
$messageType = '';
$editGrade = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        if ($name) {
            $stmt = $db->prepare("SELECT id FROM grades WHERE name = ?");
            $stmt->execute([$name]);
            if ($stmt->fetch()) {
                $message = "Grade '{$name}' already exists.";
                $messageType = 'error';
            } else {
                $db->prepare("INSERT INTO grades (name, status) VALUES (?, 'active')")->execute([$name]);
                auditLog('grade_created', 'grade', $db->lastInsertId(), "Created: {$name}");
                $message = "Grade '{$name}' created successfully.";
                $messageType = 'success';
            }
        }
    } elseif ($action === 'edit') {
        $gid = (int)($_POST['grade_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($gid && $name) {
            $db->prepare("UPDATE grades SET name = ? WHERE id = ?")->execute([$name, $gid]);
            auditLog('grade_updated', 'grade', $gid, "Renamed to: {$name}");
            $message = "Grade updated successfully.";
            $messageType = 'success';
        }
    } elseif ($action === 'toggle') {
        $gid = (int)($_POST['grade_id'] ?? 0);
        $db->prepare("UPDATE grades SET status = IF(status='active','inactive','active') WHERE id = ?")->execute([$gid]);
        $message = "Grade status updated.";
        $messageType = 'success';
    }
}

// Check if editing
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $stmt = $db->prepare("SELECT * FROM grades WHERE id = ?");
    $stmt->execute([$editId]);
    $editGrade = $stmt->fetch();
}

// Get all grades with employee count
$grades = $db->query("
    SELECT g.*, COUNT(e.id) as employee_count
    FROM grades g
    LEFT JOIN employees e ON e.grade_id = g.id AND e.status = 'active'
    GROUP BY g.id
    ORDER BY g.status DESC, g.name
")->fetchAll();

$pageTitle = 'Employee Grades';
require_once __DIR__ . '/../../includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div class="grid-2col">
    <!-- ADD / EDIT FORM -->
    <div class="card">
        <div class="card-header">
            <h3><?= $editGrade ? 'Edit Grade' : 'Add Grade' ?></h3>
            <?php if ($editGrade): ?>
                <a href="<?= BASE_PATH ?>/pages/employees/grades.php" class="btn btn-xs btn-outline">Cancel</a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="action" value="<?= $editGrade ? 'edit' : 'add' ?>">
                <?php if ($editGrade): ?>
                    <input type="hidden" name="grade_id" value="<?= $editGrade['id'] ?>">
                <?php endif; ?>
                <div class="form-group">
                    <label for="name">Grade Name</label>
                    <input type="text" id="name" name="name" required
                           placeholder="e.g., Grade-1, Grade-2, Officer, Staff"
                           value="<?= htmlspecialchars($editGrade['name'] ?? '') ?>">
                </div>
                <button type="submit" class="btn btn-primary">
                    <?= $editGrade ? 'Update Grade' : 'Add Grade' ?>
                </button>
            </form>
        </div>
    </div>

    <!-- LIST -->
    <div class="card">
        <div class="card-header"><h3>All Grades (<?= count($grades) ?>)</h3></div>
        <div class="card-body">
            <?php if (empty($grades)): ?>
                <p class="text-muted">No grades created yet.</p>
            <?php else: ?>
            <table class="table table-compact">
                <thead><tr><th>Name</th><th>Employees</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($grades as $grade): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($grade['name']) ?></strong></td>
                        <td><?= $grade['employee_count'] ?></td>
                        <td><span class="badge badge-<?= $grade['status'] === 'active' ? 'approved' : 'inactive' ?>"><?= $grade['status'] ?></span></td>
                        <td>
                            <a href="<?= BASE_PATH ?>/pages/employees/grades.php?edit=<?= $grade['id'] ?>" class="btn btn-xs">Edit</a>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="grade_id" value="<?= $grade['id'] ?>">
                                <button type="submit" class="btn btn-xs btn-outline"
                                        onclick="return confirm('<?= $grade['status'] === 'active' ? 'Deactivate' : 'Activate' ?> this grade?')">
                                    <?= $grade['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
