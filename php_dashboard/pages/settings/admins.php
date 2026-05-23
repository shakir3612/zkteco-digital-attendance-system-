<?php
/**
 * Admin Account Management - Create/edit/delete admin users.
 * Super admin only.
 */
$pageTitle = 'Admin Accounts';
require_once __DIR__ . '/../../includes/header.php';
requireSuperAdmin();

$db = getDB();
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'] ?? 'admin';

        if (empty($username) || empty($password) || empty($name)) {
            $message = 'Username, password, and name are required.';
            $messageType = 'error';
        } elseif (strlen($password) < 6) {
            $message = 'Password must be at least 6 characters.';
            $messageType = 'error';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            try {
                $db->prepare("INSERT INTO users (username, password_hash, role, name, email, status, created_at) VALUES (?, ?, ?, ?, ?, 'active', NOW())")->execute([$username, $hash, $role, $name, $email]);
                auditLog('admin_created', 'user', $db->lastInsertId(), "Created user: {$username}");
                $message = "Admin '{$username}' created."; $messageType = 'success';
            } catch (\PDOException $e) {
                $message = strpos($e->getMessage(), 'Duplicate') !== false ? "Username '{$username}' already exists." : $e->getMessage();
                $messageType = 'error';
            }
        }
    } elseif ($action === 'toggle_status') {
        $uid = $_POST['user_id'] ?? 0;
        if ($uid != $_SESSION['user_id']) {
            $db->prepare("UPDATE users SET status = IF(status='active','inactive','active') WHERE id = ?")->execute([$uid]);
            auditLog('admin_status_toggled', 'user', $uid, "Toggled");
            $message = "Status updated."; $messageType = 'success';
        } else { $message = "Cannot deactivate yourself."; $messageType = 'error'; }
    } elseif ($action === 'reset_password') {
        $uid = $_POST['user_id'] ?? 0;
        $newPass = $_POST['new_password'] ?? '';
        if (strlen($newPass) >= 6) {
            $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([password_hash($newPass, PASSWORD_BCRYPT), $uid]);
            auditLog('admin_password_reset', 'user', $uid, "Reset by super_admin");
            $message = "Password reset."; $messageType = 'success';
        } else { $message = "Min 6 characters."; $messageType = 'error'; }
    } elseif ($action === 'delete') {
        $uid = $_POST['user_id'] ?? 0;
        if ($uid != $_SESSION['user_id']) {
            $db->prepare("DELETE FROM users WHERE id = ? AND role != 'super_admin'")->execute([$uid]);
            auditLog('admin_deleted', 'user', $uid, "Deleted");
            $message = "Deleted."; $messageType = 'info';
        } else { $message = "Cannot delete yourself."; $messageType = 'error'; }
    } elseif ($action === 'change_own_password') {
        $cur = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $conf = $_POST['confirm_password'] ?? '';
        $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]); $u = $stmt->fetch();
        if (!password_verify($cur, $u['password_hash'])) { $message = "Current password incorrect."; $messageType = 'error'; }
        elseif (strlen($new) < 6) { $message = "Min 6 chars."; $messageType = 'error'; }
        elseif ($new !== $conf) { $message = "Passwords don't match."; $messageType = 'error'; }
        else { $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([password_hash($new, PASSWORD_BCRYPT), $_SESSION['user_id']]); auditLog('password_changed', 'user', $_SESSION['user_id'], "Changed own password"); $message = "Password changed."; $messageType = 'success'; }
    }
}

$admins = $db->query("SELECT * FROM users ORDER BY role DESC, name")->fetchAll();
?>
<?php if ($message): ?><div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?>

<div class="card"><div class="card-header"><h3>Change My Password</h3></div><div class="card-body">
<form method="POST"><input type="hidden" name="action" value="change_own_password">
<div class="form-row">
<div class="form-group"><label>Current Password *</label><input type="password" name="current_password" required></div>
<div class="form-group"><label>New Password *</label><input type="password" name="new_password" required minlength="6"></div>
<div class="form-group"><label>Confirm *</label><input type="password" name="confirm_password" required minlength="6"></div>
</div><button type="submit" class="btn btn-primary">Change Password</button></form></div></div>

<div class="card"><div class="card-header"><h3>Create Admin</h3></div><div class="card-body">
<form method="POST"><input type="hidden" name="action" value="create">
<div class="form-row"><div class="form-group"><label>Username *</label><input type="text" name="username" required></div><div class="form-group"><label>Password *</label><input type="password" name="password" required minlength="6"></div></div>
<div class="form-row"><div class="form-group"><label>Full Name *</label><input type="text" name="name" required></div><div class="form-group"><label>Email</label><input type="email" name="email"></div></div>
<div class="form-group"><label>Role</label><select name="role"><option value="admin">Admin</option><option value="super_admin">Super Admin</option></select></div>
<button type="submit" class="btn btn-success">Create Account</button></form></div></div>

<div class="card"><div class="card-header"><h3>All Accounts (<?= count($admins) ?>)</h3></div><div class="card-body">
<table class="table"><thead><tr><th>Username</th><th>Name</th><th>Role</th><th>Status</th><th>Last Login</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($admins as $a): ?><tr>
<td><code><?= htmlspecialchars($a['username']) ?></code></td>
<td><?= htmlspecialchars($a['name']) ?></td>
<td><span class="badge badge-<?= $a['role']==='super_admin'?'approved':'suspended' ?>"><?= $a['role'] ?></span></td>
<td><span class="badge badge-<?= $a['status']==='active'?'approved':'inactive' ?>"><?= $a['status'] ?></span></td>
<td><?= $a['last_login'] ? date('M j, H:i', strtotime($a['last_login'])) : 'Never' ?></td>
<td><?php if ($a['id'] != $_SESSION['user_id']): ?>
<form method="POST" style="display:inline"><input type="hidden" name="user_id" value="<?= $a['id'] ?>"><button name="action" value="toggle_status" class="btn btn-xs btn-outline"><?= $a['status']==='active'?'Deactivate':'Activate' ?></button></form>
<form method="POST" style="display:inline" onsubmit="return confirm('Delete?')"><input type="hidden" name="user_id" value="<?= $a['id'] ?>"><button name="action" value="delete" class="btn btn-xs btn-danger">Delete</button></form>
<form method="POST" style="display:inline" onsubmit="var p=prompt('New password (min 6):');if(!p||p.length<6){alert('Min 6');return false;}this.querySelector('[name=new_password]').value=p;return true;"><input type="hidden" name="user_id" value="<?= $a['id'] ?>"><input type="hidden" name="new_password"><button name="action" value="reset_password" class="btn btn-xs btn-outline">Reset Pass</button></form>
<?php else: ?><span class="text-muted">(you)</span><?php endif; ?></td>
</tr><?php endforeach; ?></tbody></table></div></div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
