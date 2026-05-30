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
                    <img src="<?= BASE_PATH ?>/assets/logo.png" alt="Logo" class="login-logo">
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
                    <label for="username">Username or Email</label>
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
                Legislative &amp; Parliamentary Affairs Division<br>
                <a href="#" onclick="document.getElementById('aboutModal').style.display='flex';return false;" style="color:var(--gray-400);text-decoration:none;font-size:10px;margin-top:8px;display:inline-block">About System</a>
            </p>
        </div>
    </div>

    <!-- ABOUT MODAL -->
    <div id="aboutModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:999;align-items:center;justify-content:center">
        <div style="background:#fff;border-radius:12px;padding:32px;max-width:400px;width:90%;margin:auto;text-align:center">
            <h3 style="margin-bottom:16px;font-size:18px">System Information</h3>
            <table style="width:100%;text-align:left;font-size:13px;border-collapse:collapse">
                <tr><td style="padding:8px 0;font-weight:600;color:#6b7280">Version</td><td style="padding:8px 0">1.2.0</td></tr>
                <tr><td style="padding:8px 0;font-weight:600;color:#6b7280">Developer</td><td style="padding:8px 0">Shakir Hossain</td></tr>
                <tr><td style="padding:8px 0;font-weight:600;color:#6b7280">Contact</td><td style="padding:8px 0">01946887117</td></tr>
                <tr><td style="padding:8px 0;font-weight:600;color:#6b7280">Built</td><td style="padding:8px 0">2026</td></tr>
                <tr><td style="padding:8px 0;font-weight:600;color:#6b7280">Stack</td><td style="padding:8px 0">Python (FastAPI) + PHP + MySQL</td></tr>
            </table>
            <button onclick="document.getElementById('aboutModal').style.display='none'" style="margin-top:20px;padding:8px 24px;border:1px solid #d1d5db;border-radius:6px;background:#fff;cursor:pointer;font-size:13px">Close</button>
        </div>
    </div>
    <script>document.getElementById('aboutModal').addEventListener('click',function(e){if(e.target===this)this.style.display='none';});</script>
</body>
</html>
