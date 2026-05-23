<?php
/**
 * Holiday Calendar Management
 */
$pageTitle = 'Holidays';
require_once __DIR__ . '/../../includes/header.php';
$db = getDB();
$message = ''; $messageType = '';
$year = $_GET['year'] ?? date('Y');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $name = trim($_POST['name'] ?? ''); $date = $_POST['date'] ?? ''; $type = $_POST['type'] ?? 'public';
        if ($name && $date) {
            try { $db->prepare("INSERT INTO holidays (name, date, type) VALUES (?, ?, ?)")->execute([$name, $date, $type]); auditLog('holiday_created', 'holiday', $db->lastInsertId(), "{$name} on {$date}"); $message = "Holiday added."; $messageType = 'success'; }
            catch (\PDOException $e) { $message = strpos($e->getMessage(), 'Duplicate') !== false ? "Holiday already exists on {$date}." : $e->getMessage(); $messageType = 'error'; }
        }
    } elseif ($action === 'delete') {
        $hid = $_POST['holiday_id'] ?? 0;
        $db->prepare("DELETE FROM holidays WHERE id = ?")->execute([$hid]);
        $message = "Holiday deleted."; $messageType = 'info';
    }
}
$stmt = $db->prepare("SELECT * FROM holidays WHERE YEAR(date) = ? ORDER BY date ASC"); $stmt->execute([$year]); $holidays = $stmt->fetchAll();
?>
<?php if ($message): ?><div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<div class="grid-2col">
<div class="card"><div class="card-header"><h3>Add Holiday</h3></div><div class="card-body">
<form method="POST"><input type="hidden" name="action" value="add">
<div class="form-group"><label>Name *</label><input type="text" name="name" required placeholder="e.g., Eid ul-Fitr"></div>
<div class="form-row"><div class="form-group"><label>Date *</label><input type="date" name="date" required value="<?= date('Y-m-d') ?>"></div>
<div class="form-group"><label>Type</label><select name="type"><option value="public">Public</option><option value="optional">Optional</option><option value="restricted">Restricted</option></select></div></div>
<button type="submit" class="btn btn-primary">Add</button></form></div></div>
<div class="card"><div class="card-header"><h3><?= $year ?></h3><div><a href="?year=<?= $year-1 ?>" class="btn btn-xs btn-outline">&larr;</a> <a href="?year=<?= date('Y') ?>" class="btn btn-xs btn-outline">Now</a> <a href="?year=<?= $year+1 ?>" class="btn btn-xs btn-outline">&rarr;</a></div></div><div class="card-body"><p><?= count($holidays) ?> holidays</p></div></div></div>

<div class="card"><div class="card-header"><h3>Holidays (<?= count($holidays) ?>)</h3></div><div class="card-body">
<table class="table"><thead><tr><th>Date</th><th>Day</th><th>Name</th><th>Type</th><th>Action</th></tr></thead><tbody>
<?php foreach ($holidays as $h): ?><tr><td><?= date('M j', strtotime($h['date'])) ?></td><td><?= date('D', strtotime($h['date'])) ?></td><td><strong><?= htmlspecialchars($h['name']) ?></strong></td><td><span class="badge badge-<?= $h['type']==='public'?'approved':'suspended' ?>"><?= $h['type'] ?></span></td>
<td><form method="POST" style="display:inline" onsubmit="return confirm('Delete?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="holiday_id" value="<?= $h['id'] ?>"><button class="btn btn-xs btn-danger">Delete</button></form></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
