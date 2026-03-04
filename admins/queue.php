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

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // ADD to queue
    if ($action === 'add') {
        try {
            // Get next position (max of active queue + 1)
            $res = $conn->query("SELECT COALESCE(MAX(position), 0) + 1 AS next_pos FROM queue WHERE status IN ('Waiting','Serving')");
            $next_pos = $res->fetch_assoc()['next_pos'];

            $stmt = $conn->prepare("INSERT INTO queue (vehicle_id, client_id, position, status) VALUES (?, ?, ?, 'Waiting')");
            $stmt->bind_param("iii", $_POST['vehicle_id'], $_POST['client_id'], $next_pos);
            $stmt->execute();
            $success = 'Vehicle added to queue successfully.';
        } catch (Exception $e) {
            $error = 'Failed to add to queue: ' . $e->getMessage();
        }
    }

    // EDIT queue entry
    if ($action === 'edit') {
        try {
            $stmt = $conn->prepare("UPDATE queue SET status = ? WHERE queue_id = ?");
            $stmt->bind_param("si", $_POST['status'], $_POST['queue_id']);
            $stmt->execute();
            $success = 'Queue entry updated successfully.';
        } catch (Exception $e) {
            $error = 'Failed to update queue entry: ' . $e->getMessage();
        }
    }

    // DELETE queue entry and reorder positions
    if ($action === 'delete') {
        try {
            // Get position of the entry being deleted
            $stmt = $conn->prepare("SELECT position FROM queue WHERE queue_id = ?");
            $stmt->bind_param("i", $_POST['queue_id']);
            $stmt->execute();
            $result = $stmt->get_result();
            $deleted = $result->fetch_assoc();

            if ($deleted) {
                $del_pos = $deleted['position'];

                // Delete the entry
                $stmt = $conn->prepare("DELETE FROM queue WHERE queue_id = ?");
                $stmt->bind_param("i", $_POST['queue_id']);
                $stmt->execute();

                // Reorder remaining positions
                $conn->query("UPDATE queue SET position = position - 1 WHERE position > $del_pos ORDER BY position ASC");
            }
            $success = 'Queue entry deleted successfully.';
        } catch (Exception $e) {
            $error = 'Failed to delete queue entry: ' . $e->getMessage();
        }
    }

    // UPDATE STATUS (quick change)
    if ($action === 'update_status') {
        try {
            $stmt = $conn->prepare("UPDATE queue SET status = ? WHERE queue_id = ?");
            $stmt->bind_param("si", $_POST['status'], $_POST['queue_id']);
            $stmt->execute();
            $success = 'Status updated successfully.';
        } catch (Exception $e) {
            $error = 'Failed to update status: ' . $e->getMessage();
        }
    }

    // MOVE UP
    if ($action === 'move_up') {
        try {
            $stmt = $conn->prepare("SELECT queue_id, position FROM queue WHERE queue_id = ?");
            $stmt->bind_param("i", $_POST['queue_id']);
            $stmt->execute();
            $current = $stmt->get_result()->fetch_assoc();

            if ($current && $current['position'] > 1) {
                $cur_pos = $current['position'];
                $new_pos = $cur_pos - 1;

                // Find the entry above
                $stmt2 = $conn->prepare("SELECT queue_id FROM queue WHERE position = ?");
                $stmt2->bind_param("i", $new_pos);
                $stmt2->execute();
                $above = $stmt2->get_result()->fetch_assoc();

                if ($above) {
                    // Swap positions
                    $stmt3 = $conn->prepare("UPDATE queue SET position = ? WHERE queue_id = ?");
                    $stmt3->bind_param("ii", $new_pos, $current['queue_id']);
                    $stmt3->execute();

                    $stmt4 = $conn->prepare("UPDATE queue SET position = ? WHERE queue_id = ?");
                    $stmt4->bind_param("ii", $cur_pos, $above['queue_id']);
                    $stmt4->execute();
                }
            }
            $success = 'Queue position moved up.';
        } catch (Exception $e) {
            $error = 'Failed to move up: ' . $e->getMessage();
        }
    }

    // MOVE DOWN
    if ($action === 'move_down') {
        try {
            $stmt = $conn->prepare("SELECT queue_id, position FROM queue WHERE queue_id = ?");
            $stmt->bind_param("i", $_POST['queue_id']);
            $stmt->execute();
            $current = $stmt->get_result()->fetch_assoc();

            // Get max position
            $max_res = $conn->query("SELECT MAX(position) as max_pos FROM queue");
            $max_pos = $max_res->fetch_assoc()['max_pos'];

            if ($current && $current['position'] < $max_pos) {
                $cur_pos = $current['position'];
                $new_pos = $cur_pos + 1;

                // Find the entry below
                $stmt2 = $conn->prepare("SELECT queue_id FROM queue WHERE position = ?");
                $stmt2->bind_param("i", $new_pos);
                $stmt2->execute();
                $below = $stmt2->get_result()->fetch_assoc();

                if ($below) {
                    // Swap positions
                    $stmt3 = $conn->prepare("UPDATE queue SET position = ? WHERE queue_id = ?");
                    $stmt3->bind_param("ii", $new_pos, $current['queue_id']);
                    $stmt3->execute();

                    $stmt4 = $conn->prepare("UPDATE queue SET position = ? WHERE queue_id = ?");
                    $stmt4->bind_param("ii", $cur_pos, $below['queue_id']);
                    $stmt4->execute();
                }
            }
            $success = 'Queue position moved down.';
        } catch (Exception $e) {
            $error = 'Failed to move down: ' . $e->getMessage();
        }
    }
}

// Fetch queue with JOINs
$queue = [];
try {
    $result = $conn->query("SELECT q.*, c.full_name AS client_name, v.plate_number, v.make, v.model 
        FROM queue q 
        LEFT JOIN clients c ON q.client_id = c.client_id 
        LEFT JOIN vehicles v ON q.vehicle_id = v.vehicle_id 
        ORDER BY q.position ASC");
    while ($row = $result->fetch_assoc()) {
        $queue[] = $row;
    }
} catch (Exception $e) {
    $error = 'Failed to fetch queue: ' . $e->getMessage();
}

// Fetch clients
$clients = [];
try {
    $result = $conn->query("SELECT client_id, full_name FROM clients ORDER BY full_name");
    while ($row = $result->fetch_assoc()) {
        $clients[] = $row;
    }
} catch (Exception $e) {
    $error = 'Failed to fetch clients: ' . $e->getMessage();
}

// Fetch vehicles
$vehicles = [];
try {
    $result = $conn->query("SELECT vehicle_id, plate_number, make, model, client_id FROM vehicles ORDER BY plate_number");
    while ($row = $result->fetch_assoc()) {
        $vehicles[] = $row;
    }
} catch (Exception $e) {
    $error = 'Failed to fetch vehicles: ' . $e->getMessage();
}

// Summary counts
$waiting_count = 0;
$serving_count = 0;
$done_today_count = 0;
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
    <title><?= htmlspecialchars($page_title) ?> - Admin</title>
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

            <!-- Alert messages -->
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <!-- Page header -->
            <div class="page-header">
                <h2><i class="fas fa-list-ol"></i> Queue</h2>
                <button class="btn btn-primary" onclick="openModal('addModal')">
                    <i class="fas fa-plus"></i> Add to Queue
                </button>
            </div>

            <!-- Summary Cards -->
            <div class="summary-cards" style="display:flex; gap:1rem; margin-bottom:1.5rem; flex-wrap:wrap;">
                <div class="admin-card" style="flex:1; min-width:180px; padding:1.25rem; text-align:center;">
                    <div style="font-size:2rem; font-weight:700; color:#f0ad4e;"><?= $waiting_count ?></div>
                    <div style="color:#888; font-size:0.9rem; margin-top:0.25rem;"><i class="fas fa-clock"></i> Waiting</div>
                </div>
                <div class="admin-card" style="flex:1; min-width:180px; padding:1.25rem; text-align:center;">
                    <div style="font-size:2rem; font-weight:700; color:#5bc0de;"><?= $serving_count ?></div>
                    <div style="color:#888; font-size:0.9rem; margin-top:0.25rem;"><i class="fas fa-cogs"></i> Serving</div>
                </div>
                <div class="admin-card" style="flex:1; min-width:180px; padding:1.25rem; text-align:center;">
                    <div style="font-size:2rem; font-weight:700; color:#5cb85c;"><?= $done_today_count ?></div>
                    <div style="color:#888; font-size:0.9rem; margin-top:0.25rem;"><i class="fas fa-check-circle"></i> Done Today</div>
                </div>
            </div>

            <!-- Admin card with table -->
            <div class="admin-card">
                <div class="table-toolbar" style="display:flex; gap:1rem; align-items:center; flex-wrap:wrap;">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="searchInput" placeholder="Search queue..." onkeyup="searchTable()">
                    </div>
                    <div class="filter-box">
                        <select id="statusFilter" onchange="filterByStatus()" class="form-control" style="min-width:150px;">
                            <option value="">All Statuses</option>
                            <option value="Waiting">Waiting</option>
                            <option value="Serving">Serving</option>
                            <option value="Done">Done</option>
                            <option value="Cancelled">Cancelled</option>
                        </select>
                    </div>
                </div>

                <?php if (count($queue) > 0): ?>
                <div class="table-responsive">
                    <table class="admin-table" id="queueTable">
                        <thead>
                            <tr>
                                <th>Position</th>
                                <th>Client</th>
                                <th>Vehicle</th>
                                <th>Status</th>
                                <th>Added At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($queue as $index => $row): ?>
                            <tr data-status="<?= htmlspecialchars($row['status']) ?>">
                                <td><strong>#<?= htmlspecialchars($row['position']) ?></strong></td>
                                <td><?= htmlspecialchars($row['client_name'] ?? 'N/A') ?></td>
                                <td>
                                    <?= htmlspecialchars(($row['make'] ?? '') . ' ' . ($row['model'] ?? '')) ?>
                                    <?php if (!empty($row['plate_number'])): ?>
                                        - <strong><?= htmlspecialchars($row['plate_number']) ?></strong>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $badge_class = 'badge-waiting';
                                    if ($row['status'] === 'Serving') $badge_class = 'badge-serving';
                                    elseif ($row['status'] === 'Done') $badge_class = 'badge-done';
                                    elseif ($row['status'] === 'Cancelled') $badge_class = 'badge-cancelled';
                                    ?>
                                    <span class="badge <?= $badge_class ?>"><?= htmlspecialchars($row['status']) ?></span>
                                </td>
                                <td><?= htmlspecialchars($row['added_at']) ?></td>
                                <td class="action-btns">
                                    <!-- Move Up -->
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="move_up">
                                        <input type="hidden" name="queue_id" value="<?= $row['queue_id'] ?>">
                                        <button type="submit" class="btn-icon btn-edit" title="Move Up" <?= $row['position'] <= 1 ? 'disabled' : '' ?>>
                                            <i class="fas fa-arrow-up"></i>
                                        </button>
                                    </form>
                                    <!-- Move Down -->
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="move_down">
                                        <input type="hidden" name="queue_id" value="<?= $row['queue_id'] ?>">
                                        <button type="submit" class="btn-icon btn-edit" title="Move Down" <?= $index >= count($queue) - 1 ? 'disabled' : '' ?>>
                                            <i class="fas fa-arrow-down"></i>
                                        </button>
                                    </form>
                                    <!-- Status Quick Change -->
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="queue_id" value="<?= $row['queue_id'] ?>">
                                        <select name="status" onchange="this.form.submit()" class="form-control" style="display:inline-block; width:auto; min-width:110px; padding:0.25rem 0.5rem; font-size:0.85rem;">
                                            <option value="Waiting" <?= $row['status'] === 'Waiting' ? 'selected' : '' ?>>Waiting</option>
                                            <option value="Serving" <?= $row['status'] === 'Serving' ? 'selected' : '' ?>>Serving</option>
                                            <option value="Done" <?= $row['status'] === 'Done' ? 'selected' : '' ?>>Done</option>
                                            <option value="Cancelled" <?= $row['status'] === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                        </select>
                                    </form>
                                    <!-- Delete -->
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this queue entry?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="queue_id" value="<?= $row['queue_id'] ?>">
                                        <button type="submit" class="btn-icon btn-delete" title="Delete"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-list-ol"></i>
                    <h3>No entries in the queue</h3>
                    <p>Click "Add to Queue" to add a vehicle.</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Add Modal -->
            <div class="modal-overlay" id="addModal">
                <div class="modal">
                    <div class="modal-header">
                        <h3>Add to Queue</h3>
                        <button class="modal-close" onclick="closeModal('addModal')">&times;</button>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="add">
                        <div class="modal-body">
                            <div class="form-group">
                                <label>Client</label>
                                <select name="client_id" id="add_client_id" class="form-control" required onchange="filterVehicles()">
                                    <option value="">-- Select Client --</option>
                                    <?php foreach ($clients as $c): ?>
                                        <option value="<?= $c['client_id'] ?>"><?= htmlspecialchars($c['full_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Vehicle</label>
                                <select name="vehicle_id" id="add_vehicle_id" class="form-control" required>
                                    <option value="">-- Select Vehicle --</option>
                                    <?php foreach ($vehicles as $v): ?>
                                        <option value="<?= $v['vehicle_id'] ?>" data-client="<?= $v['client_id'] ?>">
                                            <?= htmlspecialchars($v['make'] . ' ' . $v['model'] . ' - ' . $v['plate_number']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add to Queue</button>
                        </div>
                    </form>
                </div>
            </div>

        </div>
    </main>
</div>

<script src="includes/admin.js"></script>
<script>
function openModal(id) {
    document.getElementById(id).classList.add('active');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}

function searchTable() {
    var input = document.getElementById('searchInput').value.toLowerCase();
    var table = document.getElementById('queueTable');
    if (!table) return;
    var rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
    var statusFilter = document.getElementById('statusFilter').value;
    for (var i = 0; i < rows.length; i++) {
        var text = rows[i].textContent.toLowerCase();
        var rowStatus = rows[i].getAttribute('data-status');
        var matchSearch = text.indexOf(input) > -1;
        var matchStatus = !statusFilter || rowStatus === statusFilter;
        rows[i].style.display = (matchSearch && matchStatus) ? '' : 'none';
    }
}

function filterByStatus() {
    searchTable();
}

function filterVehicles() {
    var clientId = document.getElementById('add_client_id').value;
    var vehicleSelect = document.getElementById('add_vehicle_id');
    var options = vehicleSelect.querySelectorAll('option[data-client]');
    vehicleSelect.value = '';
    options.forEach(function(opt) {
        if (!clientId || opt.getAttribute('data-client') === clientId) {
            opt.style.display = '';
        } else {
            opt.style.display = 'none';
        }
    });
}

// Close modal when clicking outside
document.querySelectorAll('.modal-overlay').forEach(function(overlay) {
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) {
            overlay.classList.remove('active');
        }
    });
});
</script>
</body>
</html>
