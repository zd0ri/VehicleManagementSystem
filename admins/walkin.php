<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../users/login.php');
    exit;
}

$page_title = 'Walk-In Bookings';
$current_page = 'walkin';

require_once __DIR__ . '/../includes/db.php';

$success = '';
$error = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'add_walkin') {
        try {
            $client_id = $_POST['client_id'];
            $vehicle_id = $_POST['vehicle_id'];
            $service_id = (int)($_POST['service_id'] ?? 0);
            $notes = $_POST['notes'] ?? '';

            $pdo->beginTransaction();

            // Find least busy available technician (with NO ongoing assignments)
            $tech_stmt = $pdo->query("
                SELECT u.user_id, u.full_name,
                       COUNT(a.assignment_id) AS active_count,
                       SUM(CASE WHEN a.status = 'Ongoing' THEN 1 ELSE 0 END) AS ongoing_count
                FROM users u
                LEFT JOIN assignments a ON u.user_id = a.technician_id AND a.status IN ('Assigned', 'Ongoing')
                WHERE u.role = 'technician' AND u.status = 'active'
                GROUP BY u.user_id
                ORDER BY ongoing_count ASC, active_count ASC
            ");
            $all_techs = $tech_stmt->fetchAll();

            $free_tech = null;
            $least_busy_tech = null;
            foreach ($all_techs as $t) {
                if (!$least_busy_tech) $least_busy_tech = $t;
                if ((int)$t['ongoing_count'] === 0) {
                    $free_tech = $t;
                    break;
                }
            }

            $svc_name = 'Walk-In Service';
            if ($service_id) {
                $svc = $pdo->prepare("SELECT service_name FROM services WHERE service_id = ?");
                $svc->execute([$service_id]);
                $svc_name = $svc->fetchColumn() ?: $svc_name;
            }

            if ($free_tech) {
                // Auto-assign: create appointment + assignment
                $stmt = $pdo->prepare("INSERT INTO appointments (client_id, vehicle_id, service_id, appointment_date, status, notes, created_by) VALUES (?, ?, ?, NOW(), 'Approved', ?, ?)");
                $stmt->execute([$client_id, $vehicle_id, $service_id ?: null, $notes, $_SESSION['user_id']]);
                $appointment_id = $pdo->lastInsertId();

                $stmt = $pdo->prepare("INSERT INTO assignments (appointment_id, vehicle_id, technician_id, service_id, status) VALUES (?, ?, ?, ?, 'Assigned')");
                $stmt->execute([$appointment_id, $vehicle_id, $free_tech['user_id'], $service_id ?: null]);

                $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'New Walk-In Assignment', ?, 'new_assignment')")
                    ->execute([$free_tech['user_id'], 'Walk-in assigned to you: ' . $svc_name . '.']);

                // Also add to queue for tracking
                $next_pos = (int) $pdo->query("SELECT COALESCE(MAX(position), 0) + 1 FROM queue WHERE status IN ('Waiting','Serving')")->fetchColumn();
                $stmt = $pdo->prepare("INSERT INTO queue (vehicle_id, client_id, position, status) VALUES (?, ?, ?, 'Serving')");
                $stmt->execute([$vehicle_id, $client_id, $next_pos]);

                $pdo->commit();
                $success = 'Walk-in assigned to technician ' . htmlspecialchars($free_tech['full_name']) . '. Queue position: #' . $next_pos;
            } else {
                // All technicians have ongoing work — queue with tech info
                $assigned_tech = $least_busy_tech;
                $stmt = $pdo->prepare("INSERT INTO appointments (client_id, vehicle_id, service_id, appointment_date, status, notes, created_by) VALUES (?, ?, ?, NOW(), 'Pending', ?, ?)");
                $stmt->execute([$client_id, $vehicle_id, $service_id ?: null, $notes, $_SESSION['user_id']]);

                $next_pos = (int) $pdo->query("SELECT COALESCE(MAX(position), 0) + 1 FROM queue WHERE status IN ('Waiting','Serving')")->fetchColumn();
                $stmt = $pdo->prepare("INSERT INTO queue (vehicle_id, client_id, position, status) VALUES (?, ?, ?, 'Waiting')");
                $stmt->execute([$vehicle_id, $client_id, $next_pos]);

                $pdo->commit();
                $tech_name = $assigned_tech ? htmlspecialchars($assigned_tech['full_name']) : 'a technician';
                $success = 'All technicians are currently busy. Walk-in queued at position #' . $next_pos . '. Assigned technician will be ' . $tech_name . '.';
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Failed to add walk-in: ' . $e->getMessage();
        }
    }

    if ($action === 'edit') {
        try {
            $stmt = $pdo->prepare("UPDATE queue SET status = ? WHERE queue_id = ?");
            $stmt->execute([
                $_POST['status'],
                $_POST['queue_id']
            ]);
            $success = 'Walk-in status updated successfully.';
        } catch (Exception $e) {
            $error = 'Failed to update walk-in: ' . $e->getMessage();
        }
    }

    if ($action === 'update_status') {
        try {
            $stmt = $pdo->prepare("UPDATE queue SET status = ? WHERE queue_id = ?");
            $stmt->execute([
                $_POST['status'],
                $_POST['queue_id']
            ]);
            $success = 'Queue status updated successfully.';
        } catch (Exception $e) {
            $error = 'Failed to update status: ' . $e->getMessage();
        }
    }

    if ($action === 'delete') {
        try {
            // Delete the queue entry
            $stmt = $pdo->prepare("DELETE FROM queue WHERE queue_id = ?");
            $stmt->execute([$_POST['queue_id']]);

            $success = 'Walk-in entry deleted successfully.';
        } catch (Exception $e) {
            $error = 'Failed to delete walk-in: ' . $e->getMessage();
        }
    }
}

// Fetch today's walk-ins (queue entries created today) joined with clients and vehicles
$walkins = $pdo->query("SELECT q.*, c.full_name AS client_name, v.plate_number, v.make, v.model 
    FROM queue q 
    LEFT JOIN clients c ON q.client_id = c.client_id 
    LEFT JOIN vehicles v ON q.vehicle_id = v.vehicle_id 
    WHERE DATE(q.added_at) = CURDATE() 
    ORDER BY q.position ASC")->fetchAll();

// Fetch clients and vehicles for dropdowns
$clients = $pdo->query("SELECT client_id, full_name FROM clients ORDER BY full_name")->fetchAll();
$vehicles = $pdo->query("SELECT vehicle_id, plate_number, make, model, client_id FROM vehicles ORDER BY plate_number")->fetchAll();
$services = $pdo->query("SELECT service_id, service_name, base_price FROM services ORDER BY service_name")->fetchAll();

$todayCount = count($walkins);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> - VehiCare Admin</title>
    <link rel="stylesheet" href="../includes/style/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700;900&family=Oswald:wght@400;500;600;700&display=swap" rel="stylesheet">
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
                <h2><i class="fas fa-walking"></i> Walk-In Bookings</h2>
                <button class="btn btn-primary" onclick="openModal('addWalkinModal')">
                    <i class="fas fa-plus"></i> New Walk-In
                </button>
            </div>

            <!-- Info card -->
            <div class="admin-card" style="margin-bottom: 20px; padding: 20px; display: inline-block;">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <div style="background: #e8f5e9; border-radius: 50%; width: 50px; height: 50px; display: flex; align-items: center; justify-content: center;">
                        <i class="fas fa-users" style="font-size: 1.4rem; color: #2e7d32;"></i>
                    </div>
                    <div>
                        <p style="margin: 0; font-size: 0.85rem; color: #888;">Today's Walk-Ins</p>
                        <h3 style="margin: 0; font-size: 1.8rem; color: #333;"><?= $todayCount ?></h3>
                    </div>
                </div>
            </div>

            <!-- Walk-ins table -->
            <div class="admin-card">
                <div class="table-toolbar">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="searchInput" placeholder="Search walk-ins..." onkeyup="searchTable()">
                    </div>
                    <div class="filter-box">
                        <select id="statusFilter" onchange="filterByStatus()">
                            <option value="">All Statuses</option>
                            <option value="Waiting">Waiting</option>
                            <option value="Serving">Serving</option>
                            <option value="Done">Done</option>
                            <option value="Cancelled">Cancelled</option>
                        </select>
                    </div>
                </div>

                <?php if ($todayCount > 0): ?>
                <div class="table-responsive">
                    <table class="admin-table" id="walkinTable">
                        <thead>
                            <tr>
                                <th>Position #</th>
                                <th>Client Name</th>
                                <th>Vehicle</th>
                                <th>Status</th>
                                <th>Time Added</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($walkins as $row): ?>
                            <tr data-status="<?= htmlspecialchars($row['status']) ?>">
                                <td><strong>#<?= htmlspecialchars($row['position']) ?></strong></td>
                                <td><?= htmlspecialchars($row['client_name'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars(($row['make'] ?? '') . ' ' . ($row['model'] ?? '') . ' - ' . ($row['plate_number'] ?? 'N/A')) ?></td>
                                <td>
                                    <?php
                                        $badgeClass = 'badge-pending';
                                        if ($row['status'] === 'Waiting') $badgeClass = 'badge-pending';
                                        elseif ($row['status'] === 'Serving') $badgeClass = 'badge-approved';
                                        elseif ($row['status'] === 'Done') $badgeClass = 'badge-completed';
                                        elseif ($row['status'] === 'Cancelled') $badgeClass = 'badge-cancelled';
                                    ?>
                                    <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($row['status']) ?></span>
                                </td>
                                <td><?= date('h:i A', strtotime($row['added_at'])) ?></td>
                                <td class="action-btns">
                                    <!-- Edit button -->
                                    <button class="btn-icon btn-edit" onclick="editWalkin(
                                        <?= $row['queue_id'] ?>,
                                        '<?= htmlspecialchars($row['status'], ENT_QUOTES) ?>'
                                    )"><i class="fas fa-edit"></i></button>

                                    <!-- Quick status change -->
                                    <form method="POST" style="display:inline" class="status-form">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="queue_id" value="<?= $row['queue_id'] ?>">
                                        <select name="status" class="form-control-sm" onchange="this.form.submit()">
                                            <option value="Waiting" <?= $row['status'] === 'Waiting' ? 'selected' : '' ?>>Waiting</option>
                                            <option value="Serving" <?= $row['status'] === 'Serving' ? 'selected' : '' ?>>Serving</option>
                                            <option value="Done" <?= $row['status'] === 'Done' ? 'selected' : '' ?>>Done</option>
                                            <option value="Cancelled" <?= $row['status'] === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                        </select>
                                    </form>

                                    <!-- Delete -->
                                    <form method="POST" style="display:inline" onsubmit="return confirm('Are you sure you want to delete this walk-in entry?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="queue_id" value="<?= $row['queue_id'] ?>">
                                        <button type="submit" class="btn-icon btn-delete"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-walking"></i>
                    <h3>No walk-ins today</h3>
                    <p>Click "New Walk-In" to add a walk-in booking.</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Add Walk-In Modal -->
            <div class="modal-overlay" id="addWalkinModal">
                <div class="modal">
                    <div class="modal-header">
                        <h3><i class="fas fa-walking"></i> Add Walk-In Booking</h3>
                        <button class="modal-close" onclick="closeModal('addWalkinModal')">&times;</button>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="add_walkin">
                        <div class="modal-body">
                            <div class="form-group">
                                <label>Client</label>
                                <select name="client_id" id="add_client_id" class="form-control" required onchange="filterVehicles(this.value, 'add_vehicle_id')">
                                    <option value="">Select Client</option>
                                    <?php foreach ($clients as $client): ?>
                                        <option value="<?= $client['client_id'] ?>"><?= htmlspecialchars($client['full_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Vehicle</label>
                                <select name="vehicle_id" id="add_vehicle_id" class="form-control" required>
                                    <option value="">Select Vehicle</option>
                                    <?php foreach ($vehicles as $vehicle): ?>
                                        <option value="<?= $vehicle['vehicle_id'] ?>" data-client="<?= $vehicle['client_id'] ?>">
                                            <?= htmlspecialchars($vehicle['plate_number'] . ' - ' . $vehicle['make'] . ' ' . $vehicle['model']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Service</label>
                                <select name="service_id" class="form-control">
                                    <option value="">-- Select Service (optional) --</option>
                                    <?php foreach ($services as $svc): ?>
                                        <option value="<?= $svc['service_id'] ?>"><?= htmlspecialchars($svc['service_name']) ?> — ₱<?= number_format($svc['base_price'], 2) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Notes (optional)</label>
                                <textarea name="notes" class="form-control" rows="3" placeholder="Any additional notes..."></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="closeModal('addWalkinModal')">Cancel</button>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Add to Queue</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Edit Walk-In Modal -->
            <div class="modal-overlay" id="editWalkinModal">
                <div class="modal">
                    <div class="modal-header">
                        <h3><i class="fas fa-edit"></i> Edit Walk-In Status</h3>
                        <button class="modal-close" onclick="closeModal('editWalkinModal')">&times;</button>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="queue_id" id="edit_queue_id">
                        <div class="modal-body">
                            <div class="form-group">
                                <label>Status</label>
                                <select name="status" id="edit_status" class="form-control" required>
                                    <option value="Waiting">Waiting</option>
                                    <option value="Serving">Serving</option>
                                    <option value="Done">Done</option>
                                    <option value="Cancelled">Cancelled</option>
                                </select>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="closeModal('editWalkinModal')">Cancel</button>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update</button>
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

function editWalkin(queueId, status) {
    document.getElementById('edit_queue_id').value = queueId;
    document.getElementById('edit_status').value = status;
    openModal('editWalkinModal');
}

function filterVehicles(clientId, selectId) {
    var vehicleSelect = document.getElementById(selectId);
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

function searchTable() {
    var input = document.getElementById('searchInput').value.toLowerCase();
    var table = document.getElementById('walkinTable');
    if (!table) return;
    var rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
    var statusFilter = document.getElementById('statusFilter').value;
    for (var i = 0; i < rows.length; i++) {
        var text = rows[i].textContent.toLowerCase();
        var rowStatus = rows[i].getAttribute('data-status');
        var matchesSearch = text.indexOf(input) > -1;
        var matchesStatus = !statusFilter || rowStatus === statusFilter;
        rows[i].style.display = (matchesSearch && matchesStatus) ? '' : 'none';
    }
}

function filterByStatus() {
    searchTable();
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
