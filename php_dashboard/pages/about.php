<?php
/**
 * About / System Information Page
 */
$pageTitle = 'About';
require_once __DIR__ . '/../includes/header.php';

$db = getDB();
$companyName = getSetting('company_name', 'Attendance System');

// Get some system stats
$empCount = $db->query("SELECT COUNT(*) as cnt FROM employees WHERE status='active'")->fetch()['cnt'];
$deviceCount = $db->query("SELECT COUNT(*) as cnt FROM devices WHERE status='approved'")->fetch()['cnt'];
$punchCount = $db->query("SELECT COUNT(*) as cnt FROM attendance_raw")->fetch()['cnt'];
?>

<div class="card" style="max-width:600px;margin:0 auto">
    <div class="card-header"><h3>System Information</h3></div>
    <div class="card-body">
        <table class="table table-compact" style="font-size:14px">
            <tbody>
                <tr><td style="font-weight:600;width:160px">System</td><td><?= htmlspecialchars($companyName) ?> — Attendance Management System</td></tr>
                <tr><td style="font-weight:600">Version</td><td>1.2.0</td></tr>
                <tr><td style="font-weight:600">Developer</td><td>Shakir Hossain</td></tr>
                <tr><td style="font-weight:600">Contact</td><td>01946887117</td></tr>
                <tr><td style="font-weight:600">Built</td><td>2026</td></tr>
                <tr><td style="font-weight:600">Stack</td><td>Python (FastAPI) + PHP + MySQL</td></tr>
            </tbody>
        </table>

        <hr>
        <h4 style="margin-bottom:12px">System Stats</h4>
        <table class="table table-compact" style="font-size:14px">
            <tbody>
                <tr><td style="font-weight:600;width:160px">Active Employees</td><td><?= $empCount ?></td></tr>
                <tr><td style="font-weight:600">Approved Devices</td><td><?= $deviceCount ?></td></tr>
                <tr><td style="font-weight:600">Total Punch Records</td><td><?= number_format($punchCount) ?></td></tr>
                <tr><td style="font-weight:600">Server Time</td><td><?php date_default_timezone_set('Asia/Dhaka'); echo date('Y-m-d h:i:s A'); ?></td></tr>
                <tr><td style="font-weight:600">PHP Version</td><td><?= phpversion() ?></td></tr>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
