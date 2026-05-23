<?php
/**
 * My Profile - Allows any admin to update their own name, email, and password.
 */
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$db = getDB();
$user = currentUser();
$message = '';
$messageType = '';

// Fetch full user record
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user['id']]);
$profile = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_info') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if (empty($name)) {
            $message = 'Name is required.';
            $messageType = 'error';
        } else {
            $db->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?")
               ->execute([$name, $email, $user['id']]);
            $_SESSION['user_name'] = $name;
            $message = 'Profile updated successfully.';
            $messageType = 'success';
            $profile['name'] = $name;
            $profile['email'] = $email;
        }
    } elseif ($action === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (empty($currentPassword) || empty($newPassword)) {
            $message = 'All password fields are required.';
            $messageType = 'error';
        } elseif ($newPassword !== $confirmPassword) {
            $message = 'New password and confirmation do not match.';
            $messageType = 'error';
        } elseif (strlen($newPassword) < 6) {
            $message = 'New password must be at least 6 characters.';
            $messageType = 'error';
        } elseif (!password_verify($currentPassword, $profile['password_hash'])) {
            $message = 'Current password is incorrect.';
            $messageType = 'error';
        } else {
            $hash = password_hash($newPassword, PASSWORD_BCRYPT);
            $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
               ->execute([$hash, $user['id']]);
            auditLog('password_changed', 'user', $user['id'], 'Changed own password');
            $message = 'Password changed successfully.';
            $messageType = 'success';
            // Refresh profile
            $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$user['id']]);
            $profile = $stmt->fetch();
        }
    }
}

$pageTitle = 'My Profile';
require_once __DIR__ . '/../../includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div class="grid-2col">
    <!-- UPDATE INFO -->
    <div class="card">
        <div class="card-header"><h3>Personal Information</h3></div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="action" value="update_info">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" value="<?= htmlspecialchars($profile['username']) ?>" disabled>
                </div>
                <div class="form-group">
                    <label for="name">Full Name *</label>
                    <input type="text" id="name" name="name" required value="<?= htmlspecialchars($profile['name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?= htmlspecialchars($profile['email'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Role</label>
                    <input type="text" value="<?= htmlspecialchars($profile['role']) ?>" disabled>
                </div>
                <div class="form-group">
                    <label>Last Login</label>
                    <input type="text" value="<?= $profile['last_login'] ? date('M j, Y g:i A', strtotime($profile['last_login'])) : 'Never' ?>" disabled>
                </div>
                <button type="submit" class="btn btn-primary">Update Profile</button>
            </form>
        </div>
    </div>

    <!-- CHANGE PASSWORD -->
    <div class="card">
        <div class="card-header"><h3>Change Password</h3></div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="action" value="change_password">
                <div class="form-group">
                    <label for="current_password">Current Password *</label>
                    <input type="password" id="current_password" name="current_password" required>
                </div>
                <div class="form-group">
                    <label for="new_password">New Password * <small class="text-muted">(min 6 characters)</small></label>
                    <input type="password" id="new_password" name="new_password" required minlength="6">
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirm New Password *</label>
                    <input type="password" id="confirm_password" name="confirm_password" required minlength="6">
                </div>
                <button type="submit" class="btn btn-primary">Change Password</button>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
