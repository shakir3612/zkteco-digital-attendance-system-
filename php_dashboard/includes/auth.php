<?php
/**
 * Authentication & Session Management
 * Handles login, logout, session validation, and role checking.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';

/**
 * Check if user is logged in. Redirect to login if not.
 */
function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_PATH . '/index.php');
        exit;
    }
}

/**
 * Check if user is logged in.
 */
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Get current logged-in user data.
 */
function currentUser(): ?array {
    if (!isLoggedIn()) return null;
    return [
        'id' => $_SESSION['user_id'],
        'username' => $_SESSION['username'],
        'name' => $_SESSION['user_name'],
        'role' => $_SESSION['user_role'],
    ];
}

/**
 * Check if current user has a specific role.
 */
function hasRole(string $role): bool {
    return isLoggedIn() && $_SESSION['user_role'] === $role;
}

/**
 * Check if current user is super_admin.
 */
function isSuperAdmin(): bool {
    return hasRole('super_admin');
}

/**
 * Require super_admin role. Redirect if not.
 */
function requireSuperAdmin(): void {
    requireLogin();
    if (!isSuperAdmin()) {
        header('Location: ' . BASE_PATH . '/pages/dashboard.php?error=access_denied');
        exit;
    }
}

/**
 * Attempt to log in a user.
 * Returns true on success, false on failure.
 */
function attemptLogin(string $username, string $password): bool {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ? AND status = 'active'");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user) return false;

    // Auto-provision: if password_hash is '__PENDING__', set it now (first-run setup)
    if ($user['password_hash'] === '__PENDING__') {
        if ($password === 'admin123') {
            $hash = password_hash('admin123', PASSWORD_BCRYPT);
            $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmt->execute([$hash, $user['id']]);
            $user['password_hash'] = $hash;
        } else {
            return false;
        }
    }

    // Verify password (bcrypt)
    if (!password_verify($password, $user['password_hash'])) {
        return false;
    }

    // Set session
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_role'] = $user['role'];

    // Update last_login
    $stmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
    $stmt->execute([$user['id']]);

    return true;
}

/**
 * Log out the current user.
 */
function logout(): void {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}

/**
 * Log an audit action.
 */
function auditLog(string $action, string $targetType = null, int $targetId = null, string $details = null): void {
    if (!isLoggedIn()) return;
    $db = getDB();
    $stmt = $db->prepare(
        "INSERT INTO audit_log (user_id, action, target_type, target_id, details, ip_address, created_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())"
    );
    $stmt->execute([
        $_SESSION['user_id'],
        $action,
        $targetType,
        $targetId,
        $details,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}

/**
 * Get unread notification count for current user's role.
 */
function getUnreadNotificationCount(): int {
    if (!isLoggedIn()) return 0;
    $db = getDB();
    $role = $_SESSION['user_role'];
    $stmt = $db->prepare(
        "SELECT COUNT(*) as cnt FROM notifications 
         WHERE is_read = 0 AND (target_role = 'all' OR target_role = ?)"
    );
    $stmt->execute([$role]);
    $row = $stmt->fetch();
    return (int)$row['cnt'];
}
