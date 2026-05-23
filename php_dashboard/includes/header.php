<?php
require_once __DIR__ . '/auth.php';
requireLogin();
$user = currentUser();
$notifCount = getUnreadNotificationCount();
$companyName = getSetting('company_name', 'Attendance System');
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?> - <?= htmlspecialchars($companyName) ?></title>
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/style.css">
</head>
<body>
    <div class="app-wrapper">
        <!-- SIDEBAR OVERLAY (mobile) -->
        <div class="sidebar-overlay" id="sidebarOverlay"></div>
        <!-- SIDEBAR -->
        <nav class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-brand">
                    <div class="sidebar-brand-icon">
                        <img src="<?= BASE_PATH ?>/assets/logo.png" alt="Logo" class="sidebar-logo">
                    </div>
                    <div class="sidebar-brand-text">
                        <h2><?= htmlspecialchars($companyName) ?></h2>
                        <small>Attendance System</small>
                    </div>
                </div>
            </div>
            <ul class="nav-menu">
                <li class="<?= $currentPage === 'dashboard' ? 'active' : '' ?>">
                    <a href="<?= BASE_PATH ?>/pages/dashboard.php">Dashboard</a>
                </li>
                <li class="nav-section">Devices</li>
                <li class="<?= $currentPage === 'list' && strpos($_SERVER['PHP_SELF'], 'devices') !== false ? 'active' : '' ?>">
                    <a href="<?= BASE_PATH ?>/pages/devices/list.php">All Devices</a>
                </li>
                <li class="<?= $currentPage === 'pending' ? 'active' : '' ?>">
                    <a href="<?= BASE_PATH ?>/pages/devices/pending.php">Pending Approval</a>
                </li>
                <li class="nav-section">Employees</li>
                <li class="<?= strpos($_SERVER['PHP_SELF'], 'employees') !== false && $currentPage !== 'grades' ? 'active' : '' ?>">
                    <a href="<?= BASE_PATH ?>/pages/employees/list.php">Employees</a>
                </li>
                <li class="<?= $currentPage === 'grades' ? 'active' : '' ?>">
                    <a href="<?= BASE_PATH ?>/pages/employees/grades.php">Grades</a>
                </li>
                <li class="nav-section">Attendance</li>
                <li class="<?= strpos($_SERVER['PHP_SELF'], 'attendance') !== false ? 'active' : '' ?>">
                    <a href="<?= BASE_PATH ?>/pages/attendance/daily.php">Daily View</a>
                </li>
                <li class="<?= strpos($_SERVER['PHP_SELF'], 'shifts') !== false ? 'active' : '' ?>">
                    <a href="<?= BASE_PATH ?>/pages/shifts/list.php">Shifts</a>
                </li>
                <li class="<?= strpos($_SERVER['PHP_SELF'], 'leaves') !== false ? 'active' : '' ?>">
                    <a href="<?= BASE_PATH ?>/pages/leaves/list.php">Leaves</a>
                </li>
                <li class="<?= strpos($_SERVER['PHP_SELF'], 'holidays') !== false ? 'active' : '' ?>">
                    <a href="<?= BASE_PATH ?>/pages/holidays/list.php">Holidays</a>
                </li>
                <li class="nav-section">Reports</li>
                <li class="<?= strpos($_SERVER['PHP_SELF'], 'reports') !== false ? 'active' : '' ?>">
                    <a href="<?= BASE_PATH ?>/pages/reports/employee.php">Reports</a>
                </li>
                <?php if (isSuperAdmin()): ?>
                <li class="nav-section">Admin</li>
                <li class="<?= strpos($_SERVER['PHP_SELF'], 'settings') !== false && $currentPage === 'general' ? 'active' : '' ?>">
                    <a href="<?= BASE_PATH ?>/pages/settings/general.php">Settings</a>
                </li>
                <li class="<?= $currentPage === 'admins' ? 'active' : '' ?>">
                    <a href="<?= BASE_PATH ?>/pages/settings/admins.php">Manage Admins</a>
                </li>
                <?php endif; ?>
                <li class="nav-section">Account</li>
                <li class="<?= $currentPage === 'profile' ? 'active' : '' ?>">
                    <a href="<?= BASE_PATH ?>/pages/settings/profile.php">My Profile</a>
                </li>
            </ul>
        </nav>

        <!-- MAIN CONTENT -->
        <div class="main-content">
            <!-- TOP BAR -->
            <header class="top-bar">
                <div class="top-bar-left">
                    <button class="sidebar-toggle" id="sidebarToggle" type="button" aria-label="Toggle sidebar">&#9776;</button>
                    <h1 class="page-title"><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?></h1>
                </div>
                <div class="top-bar-right">
                    <span class="notification-badge" title="<?= $notifCount ?> unread notifications">
                        <?= $notifCount > 0 ? $notifCount : '' ?>
                    </span>
                    <span class="user-info">
                        <?= htmlspecialchars($user['name']) ?>
                        <small>(<?= $user['role'] ?>)</small>
                    </span>
                    <a href="<?= BASE_PATH ?>/index.php?action=logout" class="btn btn-sm btn-outline">Logout</a>
                </div>
            </header>

            <!-- PAGE CONTENT -->
            <div class="content-area">
