<?php
/**
 * Employee Add/Edit Form
 * On add: pushes SET_USER to all approved devices + auto-assigns default shift.
 */
$pageTitle = 'Employee';
require_once __DIR__ . '/../../includes/header.php';

$db = getDB();
$id = $_GET['id'] ?? null;
$employee = null;
$message = '';
$messageType = '';

if ($id) {
    $stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
    $stmt->execute([$id]);
    $employee = $stmt->fetch();
    if (!$employee) {
        echo '<div class="alert alert-error">Employee not found.</div>';
        require_once __DIR__ . '/../../includes/footer.php';
        exit;
    }
    $pageTitle = 'Edit Employee: ' . $employee['name'];
}

$departments = $db->query("SELECT id, name FROM departments WHERE status = 'active' ORDER BY name")->fetchAll();
$shifts = $db->query("SELECT id, name, start_time, end_time FROM shifts WHERE status = 'active'")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pin = trim($_POST['pin'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $deptId = $_POST['department_id'] ?: null;
    $designation = trim($_POST['designation'] ?? '');
    $card = trim($_POST['card_number'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $joinDate = $_POST['join_date'] ?: null;
    $status = $_POST['status'] ?? 'active';
    $shiftId = $_POST['shift_id'] ?? 1;

    if (empty($pin) || empty($name)) {
        $message = 'PIN and Name are required.';
        $messageType = 'error';
    } else {
        try {
            if ($id) {
                $stmt = $db->prepare("UPDATE employees SET name=?, department_id=?, designation=?, card_number=?, phone=?, email=?, join_date=?, status=?, updated_at=NOW() WHERE id=?");
                $stmt->execute([$name, $deptId, $designation, $card, $phone, $email, $joinDate, $status, $id]);

                $stmt = $db->prepare("INSERT INTO employee_shifts (employee_id, shift_id, effective_from) VALUES (?, ?, CURDATE()) ON DUPLICATE KEY UPDATE shift_id = VALUES(shift_id)");
                $stmt->execute([$id, $shiftId]);

                // Push to devices
                $devices = $db->query("SELECT serial_number FROM devices WHERE status = 'approved'")->fetchAll();
                if ($status === 'active') {
                    foreach ($devices as $dev) {
                        $content = "DATA UPDATE USERINFO PIN={$pin}\tName={$name}\tPri=0\tPasswd=\tCard={$card}\tGrp=1\tTZ=0000000100000000";
                        $db->prepare("INSERT INTO device_commands (device_sn, command_type, command_content, priority, status, created_at) VALUES (?, 'SET_USER', ?, 3, 'pending', NOW())")->execute([$dev['serial_number'], $content]);
                    }
                } else {
                    foreach ($devices as $dev) {
                        $db->prepare("INSERT INTO device_commands (device_sn, command_type, command_content, priority, status, created_at) VALUES (?, 'DELETE_USER', ?, 2, 'pending', NOW())")->execute([$dev['serial_number'], "DATA DELETE USERINFO PIN={$pin}"]);
                    }
                }

                auditLog('employee_updated', 'employee', $id, "Updated PIN={$pin}");
                $message = 'Employee updated. Sync commands queued.';
                $messageType = 'success';
                $stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
                $stmt->execute([$id]);
                $employee = $stmt->fetch();
            } else {
                $stmt = $db->prepare("INSERT INTO employees (pin, name, department_id, designation, card_number, phone, email, join_date, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())");
                $stmt->execute([$pin, $name, $deptId, $designation, $card, $phone, $email, $joinDate]);
                $newId = $db->lastInsertId();

                $db->prepare("INSERT INTO employee_shifts (employee_id, shift_id, effective_from) VALUES (?, ?, CURDATE())")->execute([$newId, $shiftId]);

                $devices = $db->query("SELECT serial_number FROM devices WHERE status = 'approved'")->fetchAll();
                foreach ($devices as $dev) {
                    $content = "DATA UPDATE USERINFO PIN={$pin}\tName={$name}\tPri=0\tPasswd=\tCard={$card}\tGrp=1\tTZ=0000000100000000";
                    $db->prepare("INSERT INTO device_commands (device_sn, command_type, command_content, priority, status, created_at) VALUES (?, 'SET_USER', ?, 3, 'pending', NOW())")->execute([$dev['serial_number'], $content]);
                }

                auditLog('employee_created', 'employee', $newId, "Created PIN={$pin}");
                header("Location: /pages/employees/form.php?id={$newId}&msg=" . urlencode("Employee added! Synced to " . count($devices) . " device(s)."));
                exit;
            }
        } catch (\PDOException $e) {
            $message = strpos($e->getMessage(), 'Duplicate') !== false ? "PIN '{$pin}' already exists." : 'Error: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

if (isset($_GET['msg'])) { $message = $_GET['msg']; $messageType = 'success'; }
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h3><?= $id ? 'Edit' : 'Add New' ?> Employee</h3></div>
    <div class="card-body">
        <form method="POST">
            <div class="form-row">
                <div class="form-group">
                    <label for="pin">PIN (Device ID) *</label>
                    <input type="text" id="pin" name="pin" required value="<?= htmlspecialchars($employee['pin'] ?? '') ?>" <?= $id ? 'readonly' : '' ?>>
                </div>
                <div class="form-group">
                    <label for="name">Full Name *</label>
                    <input type="text" id="name" name="name" required value="<?= htmlspecialchars($employee['name'] ?? '') ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="department_id">Department</label>
                    <select id="department_id" name="department_id">
                        <option value="">— None —</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= $dept['id'] ?>" <?= ($employee['department_id'] ?? '') == $dept['id'] ? 'selected' : '' ?>><?= htmlspecialchars($dept['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="designation">Designation</label>
                    <input type="text" id="designation" name="designation" value="<?= htmlspecialchars($employee['designation'] ?? '') ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="phone">Phone</label>
                    <input type="text" id="phone" name="phone" value="<?= htmlspecialchars($employee['phone'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?= htmlspecialchars($employee['email'] ?? '') ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="card_number">Card Number</label>
                    <input type="text" id="card_number" name="card_number" value="<?= htmlspecialchars($employee['card_number'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="join_date">Join Date</label>
                    <input type="date" id="join_date" name="join_date" value="<?= htmlspecialchars($employee['join_date'] ?? '') ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="shift_id">Shift</label>
                    <select id="shift_id" name="shift_id">
                        <?php
                        $currentShift = 1;
                        if ($id) { $s = $db->prepare("SELECT shift_id FROM employee_shifts WHERE employee_id = ? ORDER BY effective_from DESC LIMIT 1"); $s->execute([$id]); $r = $s->fetch(); if ($r) $currentShift = $r['shift_id']; }
                        foreach ($shifts as $shift): ?>
                            <option value="<?= $shift['id'] ?>" <?= $currentShift == $shift['id'] ? 'selected' : '' ?>><?= htmlspecialchars($shift['name']) ?> (<?= substr($shift['start_time'],0,5) ?>-<?= substr($shift['end_time'],0,5) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($id): ?>
                <div class="form-group">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="active" <?= ($employee['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= ($employee['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        <option value="terminated" <?= ($employee['status'] ?? '') === 'terminated' ? 'selected' : '' ?>>Terminated</option>
                    </select>
                </div>
                <?php endif; ?>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= $id ? 'Update' : 'Add Employee' ?></button>
                <a href="/pages/employees/list.php" class="btn btn-outline">Back to List</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
