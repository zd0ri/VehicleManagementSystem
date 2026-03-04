<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../users/login.php');
    exit;
}
$page_title = 'Queue';
$current_page = 'queue';
require_once __DIR__ . '/../includes/db.php';

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    try {
        if ($action === 'add') {
            $next_pos = (int)$pdo->query("SELECT COALESCE(MAX(position), 0) + 1 FROM queue WHERE status IN ('Waiting','Serving')")->fetchColumn();
            $stmt = $pdo->prepare("INSERT INTO queue (vehicle_id, client_id, position, status) VALUES (?, ?, ?, 'Waiting')");
            $stmt->execute([$_POST['vehicle_id'], $_POST['client_id'], $next_pos]);
            $success = 'Vehicle added to queue successfully.';
        }
        if ($action === 'update_status') {
            $pdo->prepare("UPDATE queue SET status = ? WHERE queue_id = ?")->execute([$_POST['status'], $_POST['queue_id']]);
            $success = 'Status updated successfully.';
        }
        if ($action === 'delete') {
            $del = $pdo->prepare("SELECT position FROM queue WHERE queue_id = ?");
            $del->execute([$_POST['queue_id']]);
            $del_pos = $del->fetchColumn();
            $pdo->prepare("DELETE FROM queue WHERE queue_id = ?")->execute([$_POST['queue_id']]);
            if ($del_pos) {
                $pdo->exec("UPDATE queue SET position = position - 1 WHERE position > $del_pos ORDER BY position ASC");
            }
            $success = 'Queue entry deleted successfully.';
        }
        if ($action === 'move_up') {
            $cur = $pdo->prepare("SELECT queue_id, position FROM queue WHERE queue_id = ?");
            $cur->execute([$_POST['queue_id']]);
            $current = $cur->fetch();
            if ($current && $current['position'] > 1) {
                $new_pos = $current['position'] - 1;
                $above = $pdo->prepare("SELECT queue_id FROM queue WHERE position = ?");
                $above->execute([$new_pos]);
                $above_row = $above->fetch();
                if ($above_row) {
                    $pdo->prepare("UPDATE queue SET position = ? WHERE queue_id = ?")->execute([$new_pos, $current['queue_id']]);
                    $pdo->prepare("UPDATE queue SET position = ? WHERE queue_id = ?")->execute([$current['position'], $above_row['queue_id']]);
                }
            }
            $success = 'Queue position moved up.';
        }
        if ($action === 'move_down') {
            $cur = $pdo->prepare("SELECT queue_id, position FROM queue WHERE queue_id = ?");
            $cur->execute([$_POST['queue_id']]);
            $current = $cur->fetch();
            $max_pos = (int)$pdo->query("SELECT MAX(position) FROM queue")->fetchColumn();
            if ($current && $current['position'] < $max_pos) {
                $new_pos = $current['position'] + 1;
                $below = $pdo->prepare("SELECT queue_id FROM queue WHERE position = ?");
                $below->execute([$new_pos]);
                $below_row = $below->fetch();
                if ($below_row) {
                    $pdo->prepare("UPDATE queue SET position = ? WHERE queue_id = ?")->execute([$new_pos, $current['queue_id']]);
                    $pdo->prepare("UPDATE queue SET position = ? WHERE queue_id = ?")->execute([$current['position'], $below_row['queue_id']]);
                }
            }
            $success = 'Queue position moved down.';
        }
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

$queue = $pdo->query("SELECT q.*, c.full_name AS client_name, v.plate_number, v.make, v.model 
    FROM queue q 
    LEFT JOIN clients c ON q.client_id = c.client_id 
    LEFT JOIN vehicles v ON q.vehicle_id = v.vehicle_id 
    ORDER BY q.position ASC")->fetchAll();

$clients = $pdo->query("SELECT client_id, full_name FROM clients ORDER BY full_name")->fetchAll();
$vehicles_list = $pdo->query("SELECT vehicle_id, plate_number, make, model, client_id FROM vehicles ORDER BY plate_number")->fetchAll();

$waiting_count = $serving_count = $done_today_count = 0;
foreach ($queue as $q) {
    if ($q['status'] === 'Waiting') $waiting_count++;
    if ($q['status'] === 'Serving') $serving_count++;
    if ($q['status'] === 'Done' && date('Y-m-d', strtotime($q['added_at'])) === date('Y-m-d')) $done_today_count++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> - VehiCare Admin</title>
    <link rel="stylesheet" href="../includes/style/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&family=Oswald:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="admin-body">
<div class="admin-layout">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <main class="admin-main">
        <?php include __DIR__ . '/includes/topbar.php'; ?>
        <div class="admin-content">
            <?php if ($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div><?php endif; ?>

            <div class="page-header">
                <h2><i class="fas fa-list-ol"></i> Queue</h2>
                <button class="btn btn-primary" onclick="openModal('addModal')"><i class="fas fa-plus"></i> Add to Queue</button>
            </div>

            <!-- Summary Cards -->
            <div class="summary-cards" style="display:flex;gap:1rem;margin-bottom:1.5rem;flex-wrap:wrap;">
                <div class="admin-card" style="flex:1;min-width:180px;padding:1.25rem;text-align:center;"><div style="font-size:2rem;font-weight:700;color:#f0ad4e;"><?= $waiting_count ?></div><div style="color:#888;font-size:0.9rem;margin-top:0.25rem;"><i class="fas fa-clock"></i> Waiting</div></div>
                <div class="admin-card" style="flex:1;min-width:180px;padding:1.25rem;text-align:center;"><div style="font-size:2rem;font-weight:700;color:#5bc0de;"><?= $serving_count ?></div><div style="color:#888;font-size:0.9rem;margin-top:0.25rem;"><i class="fas fa-cogs"></i> Serving</div></div>
                <div class="admin-card" style="flex:1;min-width:180px;padding:1.25rem;text-align:center;"><div style="font-size:2rem;font-weight:700;color:#5cb85c;"><?= $done_today_count ?></div><div style="color:#888;font-size:0.9rem;margin-top:0.25rem;"><i class="fas fa-check-circle"></i> Done Today</div></div>
            </div>

            <div class="admin-card">
                <div class="table-toolbar" style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap;">
                    <div class="search-box"><i class="fas fa-search"></i>
                        <input type="text" id="searchInput" placeholder="Search queue..." onkeyup="searchTable()">
                    </div>
                    <select id="statusFilter" onchange="searchTable()" class="form-control" style="width:auto;min-width:150px;">
                        <option value="">All Statuses</option>
                        <option value="Waiting">Waiting</option>
                        <option value="Serving">Serving</option>
                        <option value="Done">Done</option>
                        <option value="Cancelled">Cancelled</option>
                    </select>
                </div>
                <?php if (count($queue) > 0): ?>
                <div class="table-responsive">
                    <table class="admin-table" id="queueTable">
                        <thead><tr><th>Position</th><th>Client</th><th>Vehicle</th><th>Status</th><th>Added At</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($queue as $index => $row): ?>
                        <tr data-status="<?= htmlspecialchars($row['status']) ?>">
                            <td><strong>#<?= $row['position'] ?></strong></td>
                            <td><?= htmlspecialchars($row['client_name'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars(($row['make'] ?? '') . ' ' . ($row['model'] ?? '')) ?><?php if (!empty($row['plate_number'])): ?> - <strong><?= htmlspecialchars($row['plate_number']) ?></strong><?php endif; ?></td>
                            <td>
                                <?php $bc = 'badge-waiting';
                                if ($row['status'] === 'Serving') $bc = 'badge-serving';
                                elseif ($row['status'] === 'Done') $bc = 'badge-done';
                                elseif ($row['status'] === 'Cancelled') $bc = 'badge-cancelled'; ?>
                                <span class="badge <?= $bc ?>"><?= htmlspecialchars($row['status']) ?></span>
                            </td>
                            <td><?= date('M d, h:iA', strtotime($row['added_at'])) ?></td>
                            <td class="action-btns">
                                <form method="POST" style="display:inline"><input type="hidden" name="action" value="move_up"><input type="hidden" name="queue_id" value="<?= $row['queue_id'] ?>"><button type="submit" class="btn-icon btn-edit" title="Move Up" <?= $row['position'] <= 1 ? 'disabled' : '' ?>><i class="fas fa-arrow-up"></i></button></form>
                                <form method="POST" style="display:inline"><input type="hidden" name="action" value="move_down"><input type="hidden" name="queue_id" value="<?= $row['queue_id'] ?>"><button type="submit" class="btn-icon btn-edit" title="Move Down" <?= $index >= count($queue) - 1 ? 'disabled' : '' ?>><i class="fas fa-arrow-down"></i></button></form>
                                <form method="POST" style="display:inline"><input type="hidden" name="action" value="update_status"><input type="hidden" name="queue_id" value="<?= $row['queue_id'] ?>">
                                    <select name="status" onchange="this.form.submit()" class="form-control" style="display:inline-block;width:auto;min-width:110px;padding:0.25rem 0.5rem;font-size:0.85rem;">
                                        <option value="Waiting" <?= $row['status'] === 'Waiting' ? 'selected' : '' ?>>Waiting</option>
                                        <option value="Serving" <?= $row['status'] === 'Serving' ? 'selected' : '' ?>>Serving</option>
                                        <option value="Done" <?= $row['status'] === 'Done' ? 'selected' : '' ?>>Done</option>
                                        <option value="Cancelled" <?= $row['status'] === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                    </select>
                                </form>
                                <form method="POST" style="display:inline" onsubmit="return confirm('Delete this queue entry?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="queue_id" value="<?= $row['queue_id'] ?>"><button type="submit" class="btn-icon btn-delete"><i class="fas fa-trash"></i></button></form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state"><i class="fas fa-list-ol"></i><h3>No entries in the queue</h3><p>Click "Add to Queue" to add a vehicle.</p></div>
                <?php endif; ?>
            </div>

            <!-- Add Modal -->
            <div class="modal-overlay" id="addModal"><div class="modal"><div class="modal-header"><h3>Add to Queue</h3><button class="modal-close" onclick="closeModal('addModal')">&times;</button></div>
            <form method="POST"><input type="hidden" name="action" value="add"><div class="modal-body">
                <div class="form-group"><label>Client</label><select name="client_id" id="add_client_id" class="form-control" required onchange="filterVehicles()"><option value="">-- Select Client --</option><?php foreach ($clients as $c): ?><option value="<?= $c['client_id'] ?>"><?= htmlspecialchars($c['full_name']) ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label>Vehicle</label><select name="vehicle_id" id="add_vehicle_id" class="form-control" required><option value="">-- Select Vehicle --</option><?php foreach ($vehicles_list as $v): ?><option value="<?= $v['vehicle_id'] ?>" data-client="<?= $v['client_id'] ?>"><?= htmlspecialchars($v['make'] . ' ' . $v['model'] . ' - ' . $v['plate_number']) ?></option><?php endforeach; ?></select></div>
            </div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add to Queue</button></div></form></div></div>

        </div>
    </main>
</div>
<script src="includes/admin.js"></script>
<script>
function openModal(id){document.getElementById(id).classList.add('active');}
function closeModal(id){document.getElementById(id).classList.remove('active');}
function searchTable(){
    var q=document.getElementById('searchInput').value.toLowerCase();
    var sf=document.getElementById('statusFilter').value;
    var t=document.getElementById('queueTable');if(!t)return;
    var rows=t.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
    for(var i=0;i<rows.length;i++){
        var text=rows[i].textContent.toLowerCase();
        var rs=rows[i].getAttribute('data-status');
        rows[i].style.display=(text.indexOf(q)>-1&&(!sf||rs===sf))?'':'none';
    }
}
function filterVehicles(){
    var cid=document.getElementById('add_client_id').value;
    var opts=document.getElementById('add_vehicle_id').querySelectorAll('option[data-client]');
    document.getElementById('add_vehicle_id').value='';
    opts.forEach(function(o){o.style.display=(!cid||o.getAttribute('data-client')===cid)?'':'none';});
}
document.querySelectorAll('.modal-overlay').forEach(function(o){o.addEventListener('click',function(e){if(e.target===o)o.classList.remove('active');});});
</script>
</body>
</html>
