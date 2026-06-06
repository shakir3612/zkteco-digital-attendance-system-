<?php
/**
 * Daily Attendance PDF Export
 * Generates a print-friendly HTML page that can be saved as PDF via browser print
 */
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

// Check authentication
requireLogin();

// Get date from GET or POST
$date = $_GET['date'] ?? $_POST['date'] ?? date('Y-m-d');

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
    die('Invalid date format. Please use YYYY-MM-DD format.');
}

try {
    $db = getDB();
} catch (PDOException $e) {
    die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
}

// Fetch attendance data for the date
$stmt = $db->prepare("
    SELECT ad.*, e.name as emp_name, g.name as grade_name, s.name as shift_name
    FROM attendance_daily ad
    JOIN employees e ON e.pin = ad.pin
    LEFT JOIN grades g ON g.id = e.grade_id
    LEFT JOIN shifts s ON s.id = ad.shift_id
    WHERE ad.date = ?
    ORDER BY e.name ASC
");
$stmt->execute([$date]);
$records = $stmt->fetchAll();

// Calculate summary stats
$summary = ['present' => 0, 'absent' => 0, 'late' => 0, 'early' => 0, 'leave' => 0, 'holiday' => 0, 'weekend' => 0, 'duty' => 0];
foreach ($records as $r) {
    if ($r['status'] === 'present') {
        $summary['present']++;
        if ($r['was_late']) $summary['late']++;
        if ($r['left_early']) $summary['early']++;
        if (!empty($r['worked_on_off_day'])) $summary['duty']++;
    } elseif ($r['status'] === 'absent') {
        $summary['absent']++;
    } elseif ($r['status'] === 'on_leave') {
        $summary['leave']++;
    } elseif ($r['status'] === 'holiday') {
        $summary['holiday']++;
    } elseif ($r['status'] === 'weekend') {
        $summary['weekend']++;
    }
}

// Get company settings
$companyName = 'Company';
$logoPath = 'assets/logo.png';
try {
    $stmtSettings = $db->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('company_name', 'company_logo_path')");
    while ($row = $stmtSettings->fetch()) {
        if ($row['setting_key'] === 'company_name' && $row['setting_value']) {
            $companyName = $row['setting_value'];
        }
        if ($row['setting_key'] === 'company_logo_path' && $row['setting_value']) {
            $logoPath = $row['setting_value'];
        }
    }
} catch (PDOException $e) {
    // Use defaults if settings table doesn't exist or fails
}

// Format functions
function formatTime($datetime) {
    if (empty($datetime) || $datetime === '0000-00-00 00:00:00') {
        return '—';
    }
    return date('H:i', strtotime($datetime));
}

function formatHours($hours) {
    if ($hours === null || $hours === '') {
        return '—';
    }
    return number_format($hours, 1) . 'h';
}

function getStatusLabel($status) {
    $labels = [
        'present' => 'Present',
        'absent' => 'Absent',
        'on_leave' => 'On Leave',
        'holiday' => 'Holiday',
        'weekend' => 'Weekend',
        'pending' => 'Pending'
    ];
    return $labels[$status] ?? ucfirst($status);
}

function getStatusClass($status) {
    $classes = [
        'present' => 'present',
        'absent' => 'absent',
        'on_leave' => 'on-leave',
        'holiday' => 'holiday',
        'weekend' => 'weekend',
        'pending' => 'pending'
    ];
    return $classes[$status] ?? '';
}

// Output print-friendly HTML
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Attendance Report - <?= htmlspecialchars(date('d M Y', strtotime($date))) ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 11pt;
            line-height: 1.4;
            color: #333;
            background: #fff;
        }
        
        .print-container {
            max-width: 100%;
            margin: 0 auto;
            padding: 20px;
        }
        
        /* Header */
        .report-header {
            text-align: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #333;
        }
        
        .company-logo {
            max-height: 60px;
            margin-bottom: 10px;
        }
        
        .company-name {
            font-size: 18pt;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .report-title {
            font-size: 14pt;
            font-weight: bold;
            margin: 10px 0 5px;
        }
        
        .report-date {
            font-size: 12pt;
            color: #666;
        }
        
        .generated-at {
            font-size: 9pt;
            color: #999;
            margin-top: 5px;
        }
        
        /* Summary Box */
        .summary-section {
            margin-bottom: 20px;
        }
        
        .summary-title {
            font-size: 12pt;
            font-weight: bold;
            margin-bottom: 10px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 5px;
        }
        
        .summary-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .summary-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .summary-label {
            color: #666;
        }
        
        .summary-value {
            font-weight: bold;
            font-size: 12pt;
        }
        
        .summary-value.present { color: #28a745; }
        .summary-value.absent { color: #dc3545; }
        .summary-value.late { color: #ffc107; }
        .summary-value.early { color: #fd7e14; }
        
        /* Table */
        .table-section {
            margin-top: 20px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10pt;
        }
        
        th, td {
            border: 1px solid #ddd;
            padding: 6px 8px;
            text-align: left;
        }
        
        th {
            background-color: #f5f5f5;
            font-weight: bold;
            text-align: center;
        }
        
        td {
            vertical-align: middle;
        }
        
        td:nth-child(1) { text-align: center; } /* PIN */
        td:nth-child(5) { text-align: center; } /* In */
        td:nth-child(6) { text-align: center; } /* Out */
        td:nth-child(7) { text-align: center; } /* Hours */
        td:nth-child(8) { text-align: center; } /* Status */
        td:nth-child(9) { text-align: center; } /* Flags */
        
        /* Status badges */
        .status-present { color: #28a745; font-weight: bold; }
        .status-absent { color: #dc3545; font-weight: bold; }
        .status-on-leave { color: #6f42c1; font-weight: bold; }
        .status-holiday { color: #17a2b8; font-weight: bold; }
        .status-weekend { color: #6c757d; font-weight: bold; }
        .status-pending { color: #ffc107; font-weight: bold; }
        
        /* Flags */
        .flag {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 8pt;
            margin: 1px;
        }
        
        .flag-late { background: #fff3cd; color: #856404; }
        .flag-early { background: #f8d7da; color: #721c24; }
        .flag-single { background: #e2e3e5; color: #383d41; }
        .flag-duty { background: #d4edda; color: #155724; }
        
        /* No records */
        .no-records {
            text-align: center;
            padding: 40px;
            color: #666;
            font-style: italic;
        }
        
        /* Footer */
        .report-footer {
            margin-top: 30px;
            padding-top: 10px;
            border-top: 1px solid #ddd;
            text-align: center;
            font-size: 9pt;
            color: #999;
        }
        
        /* Print styles */
        @media print {
            body {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .print-container {
                padding: 0;
            }
            
            .no-print {
                display: none !important;
            }
            
            th {
                background-color: #f5f5f5 !important;
            }
        }
        
        /* Screen-only styles */
        .print-button {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
        }
        
        .print-button button {
            background: #007bff;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }
        
        .print-button button:hover {
            background: #0056b3;
        }
    </style>
</head>
<body>
    <!-- Print button (hidden when printing) -->
    <div class="print-button no-print">
        <button onclick="window.print()">🖨️ Print / Save as PDF</button>
    </div>
    
    <div class="print-container">
        <!-- Header -->
        <div class="report-header">
            <?php if (file_exists(__DIR__ . '/../../' . $logoPath)): ?>
                <img src="<?= htmlspecialchars($logoPath) ?>" alt="Company Logo" class="company-logo">
            <?php endif; ?>
            <div class="company-name"><?= htmlspecialchars($companyName) ?></div>
            <div class="report-title">DAILY ATTENDANCE REPORT</div>
            <div class="report-date">Date: <?= htmlspecialchars(date('l, F j, Y', strtotime($date))) ?></div>
            <div class="generated-at">Generated: <?= htmlspecialchars(date('d M Y, h:i A')) ?></div>
        </div>
        
        <!-- Summary -->
        <div class="summary-section">
            <div class="summary-title">SUMMARY</div>
            <div class="summary-grid">
                <div class="summary-item">
                    <span class="summary-label">Present:</span>
                    <span class="summary-value present"><?= $summary['present'] ?></span>
                </div>
                <div class="summary-item">
                    <span class="summary-label">Absent:</span>
                    <span class="summary-value absent"><?= $summary['absent'] ?></span>
                </div>
                <div class="summary-item">
                    <span class="summary-label">On Leave:</span>
                    <span class="summary-value"><?= $summary['leave'] ?></span>
                </div>
                <div class="summary-item">
                    <span class="summary-label">Holiday:</span>
                    <span class="summary-value"><?= $summary['holiday'] ?></span>
                </div>
                <div class="summary-item">
                    <span class="summary-label">Weekend:</span>
                    <span class="summary-value"><?= $summary['weekend'] ?></span>
                </div>
                <div class="summary-item">
                    <span class="summary-label">Late:</span>
                    <span class="summary-value late"><?= $summary['late'] ?></span>
                </div>
                <div class="summary-item">
                    <span class="summary-label">Early Leave:</span>
                    <span class="summary-value early"><?= $summary['early'] ?></span>
                </div>
                <div class="summary-item">
                    <span class="summary-label">Off-Day Duty:</span>
                    <span class="summary-value"><?= $summary['duty'] ?></span>
                </div>
            </div>
        </div>
        
        <!-- Table -->
        <div class="table-section">
            <table>
                <thead>
                    <tr>
                        <th>PIN</th>
                        <th>Name</th>
                        <th>Grade</th>
                        <th>Shift</th>
                        <th>In</th>
                        <th>Out</th>
                        <th>Hours</th>
                        <th>Status</th>
                        <th>Flags</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($records)): ?>
                        <tr>
                            <td colspan="9" class="no-records">No attendance records found for this date.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($records as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars($r['pin']) ?></td>
                                <td><?= htmlspecialchars($r['emp_name']) ?></td>
                                <td><?= htmlspecialchars($r['grade_name'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($r['shift_name'] ?? '—') ?></td>
                                <td><?= formatTime($r['first_in']) ?></td>
                                <td><?= formatTime($r['last_out']) ?></td>
                                <td><?= formatHours($r['total_hours']) ?></td>
                                <td class="status-<?= getStatusClass($r['status']) ?>"><?= getStatusLabel($r['status']) ?></td>
                                <td>
                                    <?php if (!empty($r['worked_on_off_day'])): ?>
                                        <span class="flag flag-duty"><?= ucfirst($r['day_type']) ?> duty</span>
                                    <?php endif; ?>
                                    <?php if ($r['was_late']): ?>
                                        <span class="flag flag-late">Late <?= (int)$r['late_minutes'] ?>m</span>
                                    <?php endif; ?>
                                    <?php if ($r['left_early']): ?>
                                        <span class="flag flag-early">Early <?= (int)$r['early_minutes'] ?>m</span>
                                    <?php endif; ?>
                                    <?php if ($r['single_punch']): ?>
                                        <span class="flag flag-single">1 punch</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Footer -->
        <div class="report-footer">
            Page 1 of 1 | Attendance System - <?= htmlspecialchars($companyName) ?>
        </div>
    </div>
</body>
</html>