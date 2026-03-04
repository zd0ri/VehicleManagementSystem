<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../users/login.php');
    exit;
}

$page_title = 'Assignments';
$current_page = 'assignments';

require_once __DIR__ . '/../includes/db.php';

$success = '';
$error = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'add') {
        try {
            $stmt = $conn->prepare("INSERT INTO assignments (vehicle_id, technician_id, service_id, status, start_time, notes) VALUES (?, ?, ?, ?, ?, ?)");
            $status = $_POST['status'] ?? 'Assigned';
            $start_time = ($status === 'Ongoing') ? date('Y-m-d H:i:s') : null;
            $notes = $_POST['notes'] ?: null;
            $stmt->bind_param("iiisss",
                $_POST['vehicle_id'],
                $_POST['technician_id'],
                $_POST['service_id'],
                $status,
                $start_time,
                $notes
            );
            $stmt->execute();
            $success = 'Assignment added successfully.';
        } catch (Exception $e) {
            $error = 'Failed to add assignment: ' . $e->getMessage();
        }
    }

    if ($action === 'edit') {
        try {
            $stmt = $conn->prepare("UPDATE assignments SET vehicle_id = ?, technician_id = ?, service_id = ?, status = ?, notes = ? WHERE assignment_id = ?");
            $notes = $_POST['notes'] ?: null;
            $stmt->bind_param("iiissi",
                $_POST['vehicle_id'],
                $_POST['technician_id'],
                $_POST['service_id'],
                $_POST['status'],
                $notes,
                $_POST['assignment_id']
            );
            $stmt->execute();
            $success = 'Assignment updated successfully.';
        } catch (Exception $e) {
            $error = 'Failed to update assignment: ' . $e->getMessage();
        }
    }

    if ($action === 'delete') {
        try {
            $stmt = $conn->prepare("DELETE FROM assignments WHERE assignment_id = ?");
            $stmt->bind_param("i", $_POST['assignment_id']);
            $stmt->execute();
            $success = 'Assignment deleted successfully.';
        } catch (Exception $e) {
            $error = 'Failed to delete assignment: ' . $e->getMessage();
        }
    }

    if ($action === 'update_status') {
        try {
            $new_status = $_POST['status'];
            $assignment_id = $_POST['assignment_id'];

            if ($new_status === 'Ongoing') {
                $stmt = $conn->prepare("UPDATE assignments SET status = ?, start_time = NOW() WHERE assignment_id = ?");
                $stmt->bind_param("si", $new_status, $assignment_id);
            } elseif ($new_status === 'Finished') {
                $stmt = $conn->prepare("UPDATE assignments SET status = ?, end_time = NOW() WHERE assignment_id = ?");
                $stmt->bind_param("si", $new_status, $assignment_id);
            } else {
                $stmt = $conn->prepare("UPDATE assignments SET status = ? WHERE assignment_id = ?");
                $stmt->bind_param("si", $new_status, $assignment_id);
            }
            $stmt->execute();
            $success = 'Assignment status updated successfully.';
        } catch (Exception $e) {
            $error = 'Failed to update status: ' . $e->getMessage();
        }
    }
}

// Fetch assignments with JOINs
$assignments = [];
try {
    $result = $conn->query("SELECT a.*, v.plate_number, v.make, v.model, u.full_name AS technician_name, s.service_name 
        FROM assignments a 
        LEFT JOIN vehicles v ON a.vehicle_id = v.vehicle_id 
        LEFT JOIN users u ON a.technician_id = u.user_id 
        LEFT JOIN services s ON a.service_id = s.service_id 
        ORDER BY a.assignment_id DESC");
    while ($row = $result->fetch_assoc()) {
        $assignments[] = $row;
    }
} catch (Exception $e) {
    $error = 'Failed to fetch assignments: ' . $e->getMessage();
}

// Dropdown data
$vehicles = [];
try {
    $result = $conn->query("SELECT vehicle_id, plate_number, make, model FROM vehicles ORDER BY plate_number");
    while ($row = $result->fetch_assoc()) {
        $vehicles[] = $row;
    }
} catch (Exception $e) {}

$technicians = [];
try {
    $result = $conn->query("SELECT user_id, full_name FROM users WHERE role='technician' AND status='active' ORDER BY full_name");
    while ($row = $result->fetch_assoc()) {
        $technicians[] = $row;
    }
} catch (Exception $e) {}

$services = [];
try {
    $result = $conn->query("SELECT service_id, service_name FROM services ORDER BY service_name");
    while ($row = $result->fetch_assoc()) {
        $services[] = $row;
    }
} catch (Exception $e) {}
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
                <h2><i class="fas fa-tasks"></i> Assignments</h2>
                <button class="btn btn-primary" onclick="openModal('addModal')">
                    <i class="fas fa-plus"></i> Add Assignment
                </button>
            </div>

            <!-- Admin card with table -->
            <div class="admin-card">
                <div class="table-toolbar">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="searchInput" placeholder="Search assignments..." onkeyup="searchTable()">
                    </div>
                    <div class="filter-box">
                        <select id="statusFilter" onchange="filterByStatus()" class="form-control" style="width:auto;display:inline-block;">
                            <option value="">All Status</option>
                            <option value="Assigned">Assigned</option>
                            <option value="Ongoing">Ongoing</option>
                            <option value="Finished">Finished</option>
                        </select>
                    </div>
                </div>

                <?php if (count($assignments) > 0): ?>
                <div class="table-responsive">
                    <table class="admin-table" id="assignmentsTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Vehicle</th>
                                <th>Technician</th>
                                <th>Service</th>
                                <th>Status</th>
                                <th>Start Time</th>
                                <th>End Time</th>
                                <th>Notes</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assignments as $row): ?>
                            <tr data-status="<?= htmlspecialchars($row['status']) ?>">
                                <td><?= htmlspecialchars($row['assignment_id']) ?></td>
                                <td><?= htmlspecialchars(($row['make'] ?? '') . ' ' . ($row['model'] ?? '') . ' - ' . ($row['plate_number'] ?? 'N/A')) ?></td>
                                <td><?= htmlspecialchars($row['technician_name'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($row['service_name'] ?? 'N/A') ?></td>
                                <td>
                                    <?php
                                        $status = $row['status'] ?? 'Assigned';
                                        $badgeClass = 'badge-assigned';
                                        if ($status === 'Ongoing') $badgeClass = 'badge-ongoing';
                                        elseif ($status === 'Finished') $badgeClass = 'badge-finished';
                                    ?>
                                    <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($status) ?></span>
                                </td>
                                <td><?= $row['start_time'] ? htmlspecialchars($row['start_time']) : '—' ?></td>
                                <td><?= $row['end_time'] ? htmlspecialchars($row['end_time']) : '—' ?></td>
                                <td><?= htmlspecialchars(mb_strimwidth($row['notes'] ?? '', 0, 50, '...')) ?></td>
                                <td class="action-btns">
                                    <button class="btn-icon btn-edit" onclick="editAssignment(
                                        <?= $row['assignment_id'] ?>,
                                        <?= $row['vehicle_id'] ?>,
                                        <?= $row['technician_id'] ?>,
                                        <?= $row['service_id'] ?>,
                                        '<?= htmlspecialchars($row['status'], ENT_QUOTES) ?>',
                                        '<?= htmlspecialchars(addslashes($row['notes'] ?? ''), ENT_QUOTES) ?>'
                                    )"><i class="fas fa-edit"></i></button>
                                    <form method="POST" style="display:inline" class="status-form">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="assignment_id" value="<?= $row['assignment_id'] ?>">
                                        <select name="status" class="form-control form-control-sm" onchange="this.form.submit()" style="width:auto;display:inline-block;padding:2px 6px;font-size:12px;">
                                            <option value="" disabled selected>Change</option>
                                            <option value="Assigned" <?= $status === 'Assigned' ? 'disabled' : '' ?>>Assigned</option>
                                            <option value="Ongoing" <?= $status === 'Ongoing' ? 'disabled' : '' ?>>Ongoing</option>
                                            <option value="Finished" <?= $status === 'Finished' ? 'disabled' : '' ?>>Finished</option>
                                        </select>
                                    </form>
                                    <form method="POST" style="display:inline" onsubmit="return confirm('Delete this assignment?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="assignment_id" value="<?= $row['assignment_id'] ?>">
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
                    <i class="fas fa-tasks"></i>
                    <h3>No assignments found</h3>
                    <p>Click "Add Assignment" to create one.</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Add Modal -->
            <div class="modal-overlay" id="addModal">
                <div class="modal">
                    <div class="modal-header">
                        <h3>Add Assignment</h3>
                        <button class="modal-close" onclick="closeModal('addModal')">&times;</button>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="add">
                        <div class="modal-body">
                            <div class="form-group">
                                <label>Vehicle</label>
                                <select name="vehicle_id" class="form-control" required>
                                    <option value="">Select Vehicle</option>
                                    <?php foreach ($vehicles as $v): ?>
                                        <option value="<?= $v['vehicle_id'] ?>"><?= htmlspecialchars($v['make'] . ' ' . $v['model'] . ' - ' . $v['plate_number']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Technician</label>
                                <select name="technician_id" class="form-control" required>
                                    <option value="">Select Technician</option>
                                    <?php foreach ($technicians as $t): ?>
                                        <option value="<?= $t['user_id'] ?>"><?= htmlspecialchars($t['full_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Service</label>
                                <select name="service_id" class="form-control" required>
                                    <option value="">Select Service</option>
                                    <?php foreach ($services as $s): ?>
                                        <option value="<?= $s['service_id'] ?>"><?= htmlspecialchars($s['service_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Status</label>
                                <select name="status" class="form-control">
                                    <option value="Assigned">Assigned</option>
                                    <option value="Ongoing">Ongoing</option>
                                    <option value="Finished">Finished</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Notes</label>
                                <textarea name="notes" class="form-control" rows="3"></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Edit Modal -->
            <div class="modal-overlay" id="editModal">
                <div class="modal">
                    <div class="modal-header">
                        <h3>Edit Assignment</h3>
                        <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="assignment_id" id="edit_assignment_id">
                        <div class="modal-body">
                            <div class="form-group">
                                <label>Vehicle</label>
                                <select name="vehicle_id" id="edit_vehicle_id" class="form-control" required>
                                    <option value="">Select Vehicle</option>
                                    <?php foreach ($vehicles as $v): ?>
                                        <option value="<?= $v['vehicle_id'] ?>"><?= htmlspecialchars($v['make'] . ' ' . $v['model'] . ' - ' . $v['plate_number']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Technician</label>
                                <select name="technician_id" id="edit_technician_id" class="form-control" required>
                                    <option value="">Select Technician</option>
                                    <?php foreach ($technicians as $t): ?>
                                        <option value="<?= $t['user_id'] ?>"><?= htmlspecialchars($t['full_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Service</label>
                                <select name="service_id" id="edit_service_id" class="form-control" required>
                                    <option value="">Select Service</option>
                                    <?php foreach ($services as $s): ?>
                                        <option value="<?= $s['service_id'] ?>"><?= htmlspecialchars($s['service_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Status</label>
                                <select name="status" id="edit_status" class="form-control">
                                    <option value="Assigned">Assigned</option>
                                    <option value="Ongoing">Ongoing</option>
                                    <option value="Finished">Finished</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Notes</label>
                                <textarea name="notes" id="edit_notes" class="form-control" rows="3"></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancel</button>
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

function editAssignment(assignmentId, vehicleId, technicianId, serviceId, status, notes) {
    document.getElementById('edit_assignment_id').value = assignmentId;
    document.getElementById('edit_vehicle_id').value = vehicleId;
    document.getElementById('edit_technician_id').value = technicianId;
    document.getElementById('edit_service_id').value = serviceId;
    document.getElementById('edit_status').value = status;
    document.getElementById('edit_notes').value = notes;
    openModal('editModal');
}

function searchTable() {
    var input = document.getElementById('searchInput').value.toLowerCase();
    var table = document.getElementById('assignmentsTable');
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
