<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../users/login.php');
    exit;
}

$page_title = 'Appointments';
$current_page = 'appointments';

require_once __DIR__ . '/../includes/db.php';

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'add') {
        try {
            $stmt = $pdo->prepare("INSERT INTO appointments (client_id, vehicle_id, appointment_date, status, created_by) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $_POST['client_id'],
                $_POST['vehicle_id'],
                $_POST['appointment_date'],
                $_POST['status'],
                $_SESSION['user_id']
            ]);
            $success = 'Appointment added successfully.';
        } catch (Exception $e) {
            $error = 'Failed to add appointment: ' . $e->getMessage();
        }
    }

    if ($action === 'edit') {
        try {
            $stmt = $pdo->prepare("UPDATE appointments SET client_id = ?, vehicle_id = ?, appointment_date = ?, status = ? WHERE appointment_id = ?");
            $stmt->execute([
                $_POST['client_id'],
                $_POST['vehicle_id'],
                $_POST['appointment_date'],
                $_POST['status'],
                $_POST['appointment_id']
            ]);
            $success = 'Appointment updated successfully.';
        } catch (Exception $e) {
            $error = 'Failed to update appointment: ' . $e->getMessage();
        }
    }

    if ($action === 'delete') {
        try {
            $stmt = $pdo->prepare("DELETE FROM appointments WHERE appointment_id = ?");
            $stmt->execute([$_POST['appointment_id']]);
            $success = 'Appointment deleted successfully.';
        } catch (Exception $e) {
            $error = 'Failed to delete appointment: ' . $e->getMessage();
        }
    }

    if ($action === 'update_status') {
        try {
            $stmt = $pdo->prepare("UPDATE appointments SET status = ? WHERE appointment_id = ?");
            $stmt->execute([
                $_POST['status'],
                $_POST['appointment_id']
            ]);
            $success = 'Appointment status updated successfully.';
        } catch (Exception $e) {
            $error = 'Failed to update status: ' . $e->getMessage();
        }
    }
}

// Fetch appointments with JOINs for client and vehicle names
$appointments = $pdo->query("SELECT a.*, c.full_name AS client_name, v.plate_number, v.make, v.model FROM appointments a LEFT JOIN clients c ON a.client_id = c.client_id LEFT JOIN vehicles v ON a.vehicle_id = v.vehicle_id ORDER BY a.appointment_date DESC")->fetchAll();

// Fetch clients and vehicles for dropdowns
$clients = $pdo->query("SELECT client_id, full_name FROM clients ORDER BY full_name")->fetchAll();
$vehicles = $pdo->query("SELECT vehicle_id, plate_number, make, model, client_id FROM vehicles ORDER BY plate_number")->fetchAll();
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
                <h2><i class="fas fa-calendar-alt"></i> Appointments</h2>
                <button class="btn btn-primary" onclick="openModal('addModal')">
                    <i class="fas fa-plus"></i> Add Appointment
                </button>
            </div>

            <!-- Admin card with table -->
            <div class="admin-card">
                <div class="table-toolbar">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="searchInput" placeholder="Search appointments..." onkeyup="searchTable()">
                    </div>
                    <div class="filter-box">
                        <select id="statusFilter" onchange="filterByStatus()">
                            <option value="">All Statuses</option>
                            <option value="Pending">Pending</option>
                            <option value="Approved">Approved</option>
                            <option value="Completed">Completed</option>
                            <option value="Cancelled">Cancelled</option>
                        </select>
                    </div>
                </div>

                <?php if (count($appointments) > 0): ?>
                <div class="table-responsive">
                    <table class="admin-table" id="appointmentsTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Client</th>
                                <th>Vehicle</th>
                                <th>Date/Time</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($appointments as $row): ?>
                            <tr data-status="<?= htmlspecialchars($row['status']) ?>">
                                <td><?= htmlspecialchars($row['appointment_id']) ?></td>
                                <td><?= htmlspecialchars($row['client_name'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars(($row['make'] ?? '') . ' ' . ($row['model'] ?? '') . ' - ' . ($row['plate_number'] ?? 'N/A')) ?></td>
                                <td><?= date('M d, Y h:i A', strtotime($row['appointment_date'])) ?></td>
                                <td>
                                    <?php
                                        $badgeClass = 'badge-pending';
                                        if ($row['status'] === 'Approved') $badgeClass = 'badge-approved';
                                        elseif ($row['status'] === 'Completed') $badgeClass = 'badge-completed';
                                        elseif ($row['status'] === 'Cancelled') $badgeClass = 'badge-cancelled';
                                    ?>
                                    <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($row['status']) ?></span>
                                </td>
                                <td class="action-btns">
                                    <button class="btn-icon btn-edit" onclick="editAppointment(
                                        <?= $row['appointment_id'] ?>,
                                        <?= $row['client_id'] ?>,
                                        <?= $row['vehicle_id'] ?>,
                                        '<?= date('Y-m-d\TH:i', strtotime($row['appointment_date'])) ?>',
                                        '<?= htmlspecialchars($row['status'], ENT_QUOTES) ?>'
                                    )"><i class="fas fa-edit"></i></button>

                                    <!-- Quick status change -->
                                    <form method="POST" style="display:inline" class="status-form">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="appointment_id" value="<?= $row['appointment_id'] ?>">
                                        <select name="status" class="form-control-sm" onchange="this.form.submit()">
                                            <option value="Pending" <?= $row['status'] === 'Pending' ? 'selected' : '' ?>>Pending</option>
                                            <option value="Approved" <?= $row['status'] === 'Approved' ? 'selected' : '' ?>>Approved</option>
                                            <option value="Completed" <?= $row['status'] === 'Completed' ? 'selected' : '' ?>>Completed</option>
                                            <option value="Cancelled" <?= $row['status'] === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                        </select>
                                    </form>

                                    <form method="POST" style="display:inline" onsubmit="return confirm('Delete this appointment?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="appointment_id" value="<?= $row['appointment_id'] ?>">
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
                    <i class="fas fa-calendar-alt"></i>
                    <h3>No appointments found</h3>
                    <p>Click "Add Appointment" to create one.</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Add Modal -->
            <div class="modal-overlay" id="addModal">
                <div class="modal">
                    <div class="modal-header">
                        <h3>Add Appointment</h3>
                        <button class="modal-close" onclick="closeModal('addModal')">&times;</button>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="add">
                        <div class="modal-body">
                            <div class="form-group">
                                <label>Client</label>
                                <select name="client_id" class="form-control" required>
                                    <option value="">Select Client</option>
                                    <?php foreach ($clients as $client): ?>
                                        <option value="<?= $client['client_id'] ?>"><?= htmlspecialchars($client['full_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Vehicle</label>
                                <select name="vehicle_id" class="form-control" required>
                                    <option value="">Select Vehicle</option>
                                    <?php foreach ($vehicles as $vehicle): ?>
                                        <option value="<?= $vehicle['vehicle_id'] ?>"><?= htmlspecialchars($vehicle['plate_number'] . ' - ' . $vehicle['make'] . ' ' . $vehicle['model']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Date/Time</label>
                                <input type="datetime-local" name="appointment_date" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label>Status</label>
                                <select name="status" class="form-control" required>
                                    <option value="Pending">Pending</option>
                                    <option value="Approved">Approved</option>
                                    <option value="Completed">Completed</option>
                                    <option value="Cancelled">Cancelled</option>
                                </select>
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
                        <h3>Edit Appointment</h3>
                        <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="appointment_id" id="edit_appointment_id">
                        <div class="modal-body">
                            <div class="form-group">
                                <label>Client</label>
                                <select name="client_id" id="edit_client_id" class="form-control" required>
                                    <option value="">Select Client</option>
                                    <?php foreach ($clients as $client): ?>
                                        <option value="<?= $client['client_id'] ?>"><?= htmlspecialchars($client['full_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Vehicle</label>
                                <select name="vehicle_id" id="edit_vehicle_id" class="form-control" required>
                                    <option value="">Select Vehicle</option>
                                    <?php foreach ($vehicles as $vehicle): ?>
                                        <option value="<?= $vehicle['vehicle_id'] ?>"><?= htmlspecialchars($vehicle['plate_number'] . ' - ' . $vehicle['make'] . ' ' . $vehicle['model']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Date/Time</label>
                                <input type="datetime-local" name="appointment_date" id="edit_appointment_date" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label>Status</label>
                                <select name="status" id="edit_status" class="form-control" required>
                                    <option value="Pending">Pending</option>
                                    <option value="Approved">Approved</option>
                                    <option value="Completed">Completed</option>
                                    <option value="Cancelled">Cancelled</option>
                                </select>
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

function editAppointment(appointmentId, clientId, vehicleId, appointmentDate, status) {
    document.getElementById('edit_appointment_id').value = appointmentId;
    document.getElementById('edit_client_id').value = clientId;
    document.getElementById('edit_vehicle_id').value = vehicleId;
    document.getElementById('edit_appointment_date').value = appointmentDate;
    document.getElementById('edit_status').value = status;
    openModal('editModal');
}

function searchTable() {
    var input = document.getElementById('searchInput').value.toLowerCase();
    var table = document.getElementById('appointmentsTable');
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
