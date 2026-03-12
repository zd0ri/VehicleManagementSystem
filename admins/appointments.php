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

    if ($action === 'update_status') {
        try {
            $new_status = $_POST['status'];
            $appt_id = (int) $_POST['appointment_id'];

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("UPDATE appointments SET status = ? WHERE appointment_id = ?");
            $stmt->execute([$new_status, $appt_id]);

            // Cascading logic when cancelling
            if ($new_status === 'Cancelled') {
                // Get appointment details for notifications
                $apptInfo = $pdo->prepare("SELECT a.client_id, a.service_id, c.full_name AS client_name, s.service_name,
                    cu.user_id AS client_user_id
                    FROM appointments a
                    LEFT JOIN clients c ON a.client_id = c.client_id
                    LEFT JOIN services s ON a.service_id = s.service_id
                    LEFT JOIN clients cu ON a.client_id = cu.client_id
                    WHERE a.appointment_id = ?");
                $apptInfo->execute([$appt_id]);
                $apptData = $apptInfo->fetch();

                // Cancel related assignments
                $asgn = $pdo->prepare("SELECT assignment_id, technician_id FROM assignments WHERE appointment_id = ? AND status IN ('Assigned','Ongoing')");
                $asgn->execute([$appt_id]);
                $assignments = $asgn->fetchAll();
                if (!empty($assignments)) {
                    $pdo->prepare("DELETE FROM assignments WHERE appointment_id = ? AND status IN ('Assigned','Ongoing')")->execute([$appt_id]);
                    // Notify each technician
                    foreach ($assignments as $a) {
                        $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'Appointment Cancelled', ?, 'cancellation')")
                            ->execute([$a['technician_id'], 'Appointment #' . $appt_id . ' for ' . ($apptData['service_name'] ?? 'a service') . ' has been cancelled by admin.']);
                    }
                }

                // Cancel related queue entries
                if ($apptData) {
                    $queueDel = $pdo->prepare("SELECT queue_id, position FROM queue WHERE client_id = ? AND vehicle_id = (SELECT vehicle_id FROM appointments WHERE appointment_id = ?) AND status IN ('Waiting','Serving')");
                    $queueDel->execute([$apptData['client_id'], $appt_id]);
                    $queueEntry = $queueDel->fetch();
                    if ($queueEntry) {
                        $pdo->prepare("UPDATE queue SET status = 'Cancelled' WHERE queue_id = ?")->execute([$queueEntry['queue_id']]);
                        $pdo->prepare("UPDATE queue SET position = position - 1 WHERE position > ? AND status IN ('Waiting','Serving') ORDER BY position ASC")->execute([$queueEntry['position']]);
                    }

                    // Notify customer
                    $clientUser = $pdo->prepare("SELECT user_id FROM clients WHERE client_id = ?");
                    $clientUser->execute([$apptData['client_id']]);
                    $cu = $clientUser->fetch();
                    if ($cu) {
                        $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'Appointment Cancelled', ?, 'cancellation')")
                            ->execute([$cu['user_id'], 'Your appointment #' . $appt_id . ' for ' . ($apptData['service_name'] ?? 'a service') . ' has been cancelled by admin.']);
                    }
                }
            }

            logAudit($pdo, 'Updated appointment status to ' . $new_status, 'appointments', $appt_id);
            $pdo->commit();
            $success = 'Appointment status updated successfully.';
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Failed to update status: ' . $e->getMessage();
        }
    }

    if ($action === 'delete') {
        try {
            $stmt = $pdo->prepare("DELETE FROM appointments WHERE appointment_id = ?");
            $stmt->execute([$_POST['appointment_id']]);
            logAudit($pdo, 'Deleted appointment', 'appointments', $_POST['appointment_id']);
            $success = 'Appointment deleted successfully.';
        } catch (Exception $e) {
            $error = 'Failed to delete appointment: ' . $e->getMessage();
        }
    }
}

// Fetch appointments with JOINs for client, vehicle, service, and assigned technician
$appointments = $pdo->query("
    SELECT a.*, c.full_name AS client_name, v.plate_number, v.make, v.model,
           s.service_name, s.estimated_duration,
           tech.full_name AS technician_name,
           asgn.status AS assignment_status,
           COALESCE(a.appointment_type, 'Online') AS appointment_type
    FROM appointments a
    LEFT JOIN clients c ON a.client_id = c.client_id
    LEFT JOIN vehicles v ON a.vehicle_id = v.vehicle_id
    LEFT JOIN services s ON a.service_id = s.service_id
    LEFT JOIN assignments asgn ON asgn.appointment_id = a.appointment_id
    LEFT JOIN users tech ON asgn.technician_id = tech.user_id
    ORDER BY a.appointment_date DESC
")->fetchAll();
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
                <h2><i class="fas fa-calendar-alt"></i> Appointments Monitor</h2>
                <span class="badge badge-info" style="font-size:14px;padding:8px 16px;"><?= count($appointments) ?> Total</span>
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
                    <div class="filter-box">
                        <select id="typeFilter" onchange="filterByStatus()">
                            <option value="">All Types</option>
                            <option value="Online">Online Booking</option>
                            <option value="Walk-In">Walk-In</option>
                        </select>
                    </div>
                </div>

                <?php if (count($appointments) > 0): ?>
                <div class="table-responsive">
                    <table class="admin-table" id="appointmentsTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Type</th>
                                <th>Client</th>
                                <th>Vehicle</th>
                                <th>Service</th>
                                <th>Technician</th>
                                <th>Date/Time</th>
                                <th>Status</th>
                                <th>Progress</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($appointments as $row): ?>
                            <tr data-status="<?= htmlspecialchars($row['status']) ?>" data-type="<?= htmlspecialchars($row['appointment_type']) ?>">
                                <td><?= htmlspecialchars($row['appointment_id']) ?></td>
                                <td>
                                    <?php if ($row['appointment_type'] === 'Walk-In'): ?>
                                        <span class="badge" style="background:#e67e22;color:#fff;font-size:11px;"><i class="fas fa-walking"></i> Walk-In</span>
                                    <?php else: ?>
                                        <span class="badge" style="background:#3498db;color:#fff;font-size:11px;"><i class="fas fa-globe"></i> Online</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($row['client_name'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars(($row['make'] ?? '') . ' ' . ($row['model'] ?? '') . ' - ' . ($row['plate_number'] ?? 'N/A')) ?></td>
                                <td><?= htmlspecialchars($row['service_name'] ?? 'N/A') ?></td>
                                <td>
                                    <?php if ($row['technician_name']): ?>
                                        <span style="color:#27ae60;"><i class="fas fa-user-check"></i> <?= htmlspecialchars($row['technician_name']) ?></span>
                                    <?php else: ?>
                                        <span style="color:#e67e22;"><i class="fas fa-clock"></i> Queued</span>
                                    <?php endif; ?>
                                </td>
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
                                <td>
                                    <?php if ($row['assignment_status']): ?>
                                        <?php
                                            $pBadge = 'badge-pending';
                                            if ($row['assignment_status'] === 'Ongoing') $pBadge = 'badge-approved';
                                            elseif ($row['assignment_status'] === 'Finished') $pBadge = 'badge-completed';
                                        ?>
                                        <span class="badge <?= $pBadge ?>"><?= htmlspecialchars($row['assignment_status']) ?></span>
                                    <?php else: ?>
                                        <span style="color:#aaa;">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                                <td class="action-btns">
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

            <!-- Add Modal removed: appointments are auto-assigned from client bookings -->

        </div>
    </main>
</div>

<script src="includes/admin.js"></script>
<script>
function searchTable() {
    var input = document.getElementById('searchInput').value.toLowerCase();
    var table = document.getElementById('appointmentsTable');
    if (!table) return;
    var rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
    var statusFilter = document.getElementById('statusFilter').value;
    var typeFilter = document.getElementById('typeFilter').value;
    for (var i = 0; i < rows.length; i++) {
        var text = rows[i].textContent.toLowerCase();
        var rowStatus = rows[i].getAttribute('data-status');
        var rowType = rows[i].getAttribute('data-type');
        var matchesSearch = text.indexOf(input) > -1;
        var matchesStatus = !statusFilter || rowStatus === statusFilter;
        var matchesType = !typeFilter || rowType === typeFilter;
        rows[i].style.display = (matchesSearch && matchesStatus && matchesType) ? '' : 'none';
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
