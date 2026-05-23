<?php
/**
 * Login Page / Entry Point
 */
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

// Handle logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    logout();
    header('Location: ' . BASE_PATH . '/index.php?msg=logged_out');
    exit;
}

// If already logged in, redirect to dashboard
if (isLoggedIn()) {
    header('Location: ' . BASE_PATH . '/pages/dashboard.php');
    exit;
}

// Handle login form submission
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        if (attemptLogin($username, $password)) {
            header('Location: ' . BASE_PATH . '/pages/dashboard.php');
            exit;
        } else {
            $error = 'Invalid username or password.';
        }
    }
}

$companyName = getSetting('company_name', 'Attendance System');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?= htmlspecialchars($companyName) ?></title>
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/style.css">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-card">
            <div class="login-brand">
                <div class="brand-icon">
                    <svg width="48" height="48" viewBox="0 0 48 48" fill="none">
                        <rect width="48" height="48" rx="12" fill="#2563eb"/>
                        <path d="M14 24l6 6 14-14" stroke="#fff" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <h1 class="brand-name"><?= htmlspecialchars($companyName) ?></h1>
                <p class="login-subtitle">Attendance Management System</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'logged_out'): ?>
                <div class="alert alert-info">You have been logged out.</div>
            <?php endif; ?>

            <form method="POST" action="<?= BASE_PATH ?>/index.php">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required autofocus
                           value="<?= htmlspecialchars($username ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <button type="submit" class="btn btn-primary btn-block">Login</button>
            </form>
            <p class="login-footer">
                <span class="footer-divider"></span>
                Developed by <strong>ICT Cell</strong><br>
                Legislative &amp; Parliamentary Affairs Division
            </p>
        </div>
    </div>
</body>
</html>
