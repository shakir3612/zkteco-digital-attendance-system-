<?php
/**
 * Holiday Calendar Management
 * Supports adding single day or a range of days as holidays.
 */
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$db = getDB();
$message = '';
$messageType = '';
$year = $_GET['year'] ?? date('Y');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $fromDate = $_POST['from_date'] ?? '';
        $toDate = $_POST['to_date'] ?? '';
        $type = $_POST['type'] ?? 'public';

        if (empty($name) || empty($fromDate)) {
            $message = 'Name and From Date are required.';
            $messageType = 'error';
        } else {
            // If no To Date, treat as single day
            if (empty($toDate) || $toDate < $fromDate) {
                $toDate = $fromDate;
            }

            $added = 0;
            $skipped = 0;
            $current = new DateTime($fromDate);
            $end = new DateTime($toDate);

            while ($current <= $end) {
                $dateStr = $current->format('Y-m-d');
                try {
                    $db->prepare("INSERT INTO holidays (name, date, type) VALUES (?, ?, ?)")
                       ->execute([$name, $dateStr, $type]);
                    $added++;
                } catch (\PDOException $e) {
                    // Duplicate date — skip
                    $skipped++;
                }
                $current->modify('+1 day');
            }

            if ($added > 0) {
                $totalDays = $added + $skipped;
                auditLog('holiday_created', 'holiday', null, "{$name}: {$added} day(s) added");
                if ($added === 1 && $skipped === 0) {
                    $message = "Holiday '{$name}' added on {$fromDate}.";
                } else {
                    $message = "Holiday '{$name}' added for {$added} day(s)." . ($skipped > 0 ? " ({$skipped} already existed)" : "");
                }
                $messageType = 'success';
            } else {
                $message = "All dates in the range already have holidays.";
                $messageType = 'error';
            }
        }
    } elseif ($action === 'delete') {
        $hid = (int)($_POST['holiday_id'] ?? 0);
        if ($hid) {
            $db->prepare("DELETE FROM holidays WHERE id = ?")->execute([$hid]);
            $message = "Holiday deleted.";
            $messageType = 'success';
        }
    }
}

$stmt = $db->prepare("SELECT * FROM holidays WHERE YEAR(date) = ? ORDER BY date ASC");
$stmt->execute([$year]);
$holidays = $stmt->fetchAll();

$pageTitle = 'Holidays';
require_once __DIR__ . '/../../includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div class="grid-2col">
    <!-- ADD HOLIDAY FORM -->
    <div class="card">
        <div class="card-header"><h3>Add Holiday</h3></div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="form-group">
                    <label>Holiday Name *</label>
                    <input type="text" name="name" required placeholder="e.g., Eid ul-Fitr, National Day">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>From Date *</label>
                        <input type="date" name="from_date" required value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-group">
                        <label>To Date <small class="text-muted">(leave empty for single day)</small></label>
                        <input type="date" name="to_date" value="">
                    </div>
                </div>
                <div class="form-group">
                    <label>Type</label>
                    <select name="type" class="no-search">
                        <option value="public">Public Holiday</option>
                        <option value="optional">Optional Holiday</option>
                        <option value="restricted">Restricted Holiday</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">Add Holiday</button>
            </form>
        </div>
    </div>

    <!-- YEAR NAVIGATION -->
    <div class="card">
        <div class="card-header">
            <h3>Year: <?= $year ?></h3>
            <div>
                <a href="?year=<?= $year - 1 ?>" class="btn btn-xs btn-outline">&larr; <?= $year - 1 ?></a>
                <a href="?year=<?= date('Y') ?>" class="btn btn-xs btn-outline">Current</a>
                <a href="?year=<?= $year + 1 ?>" class="btn btn-xs btn-outline"><?= $year + 1 ?> &rarr;</a>
            </div>
        </div>
        <div class="card-body">
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?= count($holidays) ?></div>
                    <div class="stat-label">Total Holidays</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= count(array_filter($holidays, fn($h) => $h['type'] === 'public')) ?></div>
                    <div class="stat-label">Public</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= count(array_filter($holidays, fn($h) => $h['type'] === 'optional')) ?></div>
                    <div class="stat-label">Optional</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- HOLIDAY LIST -->
<div class="card">
    <div class="card-header"><h3>Holidays in <?= $year ?> (<?= count($holidays) ?>)</h3></div>
    <div class="card-body">
        <?php if (empty($holidays)): ?>
            <p class="text-muted">No holidays added for <?= $year ?>.</p>
        <?php else: ?>
        <table class="table">
            <thead>
                <tr><th>Date</th><th>Day</th><th>Name</th><th>Type</th><th>Action</th></tr>
            </thead>
            <tbody>
            <?php foreach ($holidays as $h): ?>
                <tr>
                    <td><?= date('M j, Y', strtotime($h['date'])) ?></td>
                    <td><?= date('l', strtotime($h['date'])) ?></td>
                    <td><strong><?= htmlspecialchars($h['name']) ?></strong></td>
                    <td><span class="badge badge-<?= $h['type'] === 'public' ? 'approved' : ($h['type'] === 'optional' ? 'pending_approval' : 'suspended') ?>"><?= $h['type'] ?></span></td>
                    <td>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete this holiday?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="holiday_id" value="<?= $h['id'] ?>">
                            <button class="btn btn-xs btn-danger">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
