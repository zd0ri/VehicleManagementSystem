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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    try {
        if ($action === 'add') {
            $status = $_POST['status'] ?? 'Assigned';
            $start_time = ($status === 'Ongoing') ? date('Y-m-d H:i:s') : null;
            $notes = $_POST['notes'] ?: null;
            $stmt = $pdo->prepare("INSERT INTO assignments (vehicle_id, technician_id, service_id, status, start_time, notes) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$_POST['vehicle_id'], $_POST['technician_id'], $_POST['service_id'], $status, $start_time, $notes]);
            $success = 'Assignment added successfully.';
        }
        if ($action === 'edit') {
            $notes = $_POST['notes'] ?: null;
            $stmt = $pdo->prepare("UPDATE assignments SET vehicle_id = ?, technician_id = ?, service_id = ?, status = ?, notes = ? WHERE assignment_id = ?");
            $stmt->execute([$_POST['vehicle_id'], $_POST['technician_id'], $_POST['service_id'], $_POST['status'], $notes, $_POST['assignment_id']]);
            $success = 'Assignment updated successfully.';
        }
        if ($action === 'delete') {
            $pdo->prepare("DELETE FROM assignments WHERE assignment_id = ?")->execute([$_POST['assignment_id']]);
            $success = 'Assignment deleted successfully.';
        }
        if ($action === 'update_status') {
            $new_status = $_POST['status'];
            $id = $_POST['assignment_id'];
            if ($new_status === 'Ongoing') {
                $pdo->prepare("UPDATE assignments SET status = ?, start_time = NOW() WHERE assignment_id = ?")->execute([$new_status, $id]);
            } elseif ($new_status === 'Finished') {
                $pdo->prepare("UPDATE assignments SET status = ?, end_time = NOW() WHERE assignment_id = ?")->execute([$new_status, $id]);
            } else {
                $pdo->prepare("UPDATE assignments SET status = ? WHERE assignment_id = ?")->execute([$new_status, $id]);
            }
            $success = 'Status updated successfully.';
        }
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

$assignments = $pdo->query("SELECT a.*, v.plate_number, v.make, v.model, v.year, v.color, u.full_name AS technician_name, s.service_name, s.base_price,
    c.full_name AS customer_name, c.email AS customer_email, c.user_id AS customer_id,
    ap.appointment_date, ap.status AS appointment_status
    FROM assignments a 
    LEFT JOIN vehicles v ON a.vehicle_id = v.vehicle_id 
    LEFT JOIN users u ON a.technician_id = u.user_id 
    LEFT JOIN services s ON a.service_id = s.service_id 
    LEFT JOIN users c ON v.client_id = c.user_id
    LEFT JOIN appointments ap ON a.appointment_id = ap.appointment_id
    ORDER BY a.assignment_id DESC")->fetchAll();

$vehicles = $pdo->query("SELECT vehicle_id, plate_number, make, model FROM vehicles ORDER BY plate_number")->fetchAll();
$technicians_list = $pdo->query("SELECT user_id, full_name FROM users WHERE role='technician' AND status='active' ORDER BY full_name")->fetchAll();
$services_list = $pdo->query("SELECT service_id, service_name FROM services ORDER BY service_name")->fetchAll();

// Parts used per assignment (for admin view)
$admin_parts = [];
$parts_q = $pdo->query("SELECT pu.assignment_id, pu.quantity, i.item_name, i.unit_price FROM parts_used pu JOIN inventory i ON pu.item_id = i.item_id ORDER BY pu.created_at ASC");
foreach ($parts_q->fetchAll() as $p) {
    $admin_parts[$p['assignment_id']][] = $p;
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
                <h2><i class="fas fa-tasks"></i> Assignments</h2>
                <button class="btn btn-primary" onclick="openModal('addModal')"><i class="fas fa-plus"></i> Add Assignment</button>
            </div>

            <div class="admin-card">
                <div class="table-toolbar" style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap;">
                    <div class="search-box"><i class="fas fa-search"></i>
                        <input type="text" id="searchInput" placeholder="Search assignments..." onkeyup="searchTable()">
                    </div>
                    <select id="statusFilter" onchange="searchTable()" class="form-control" style="width:auto;">
                        <option value="">All Status</option>
                        <option value="Assigned">Assigned</option>
                        <option value="Ongoing">Ongoing</option>
                        <option value="Finished">Finished</option>
                    </select>
                </div>
                <?php if (count($assignments) > 0): ?>
                <div class="table-responsive">
                    <table class="admin-table" id="assignmentsTable">
                        <thead><tr><th>ID</th><th>Customer</th><th>Vehicle</th><th>Technician</th><th>Service</th><th>Status</th><th>Start</th><th>End</th><th>Notes</th><th>Parts Used</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($assignments as $row): ?>
                        <tr data-status="<?= htmlspecialchars($row['status']) ?>">
                            <td><?= $row['assignment_id'] ?></td>
                            <td>
                                <span style="font-weight:500;"><?= htmlspecialchars($row['customer_name'] ?? 'N/A') ?></span><br>
                                <small style="color:#888;"><?= htmlspecialchars($row['customer_email'] ?? '') ?></small>
                                <br><button type="button" class="btn-icon" style="color:#3498db;font-size:11px;padding:2px 6px;" onclick="viewCustomer(<?= (int)$row['assignment_id'] ?>)"><i class="fas fa-eye"></i> View</button>
                            </td>
                            <td><?= htmlspecialchars(($row['make'] ?? '') . ' ' . ($row['model'] ?? '') . ' - ' . ($row['plate_number'] ?? 'N/A')) ?></td>
                            <td><?= htmlspecialchars($row['technician_name'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($row['service_name'] ?? 'N/A') ?></td>
                            <td>
                                <?php $st = $row['status'] ?? 'Assigned';
                                $bc = 'badge-assigned';
                                if ($st === 'Ongoing') $bc = 'badge-ongoing';
                                elseif ($st === 'Finished') $bc = 'badge-finished'; ?>
                                <span class="badge <?= $bc ?>"><?= htmlspecialchars($st) ?></span>
                            </td>
                            <td><?= $row['start_time'] ? date('M d, h:iA', strtotime($row['start_time'])) : '—' ?></td>
                            <td><?= $row['end_time'] ? date('M d, h:iA', strtotime($row['end_time'])) : '—' ?></td>
                            <td><?= htmlspecialchars(mb_strimwidth($row['notes'] ?? '', 0, 40, '...')) ?></td>
                            <td>
                                <?php $ap = $admin_parts[$row['assignment_id']] ?? []; ?>
                                <?php if (!empty($ap)): ?>
                                    <?php $pt = 0; foreach ($ap as $pp): $pt += $pp['quantity'] * $pp['unit_price']; ?>
                                    <div style="font-size:12px;white-space:nowrap;"><?= htmlspecialchars($pp['item_name']) ?> x<?= $pp['quantity'] ?></div>
                                    <?php endforeach; ?>
                                    <div style="font-size:11px;font-weight:600;color:#8e44ad;margin-top:2px;">Total: ₱<?= number_format($pt, 2) ?></div>
                                <?php else: ?>
                                    <span style="color:#888;font-size:12px;">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="action-btns">
                                <button class="btn-icon btn-edit" onclick="editAssignment(<?= $row['assignment_id'] ?>, <?= $row['vehicle_id'] ?>, <?= $row['technician_id'] ?>, <?= $row['service_id'] ?>, '<?= htmlspecialchars($row['status'], ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($row['notes'] ?? ''), ENT_QUOTES) ?>')"><i class="fas fa-edit"></i></button>
                                <form method="POST" style="display:inline" class="status-form">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="assignment_id" value="<?= $row['assignment_id'] ?>">
                                    <select name="status" class="form-control form-control-sm" onchange="this.form.submit()" style="width:auto;display:inline-block;padding:2px 6px;font-size:12px;">
                                        <option value="" disabled selected>Change</option>
                                        <option value="Assigned" <?= $st === 'Assigned' ? 'disabled' : '' ?>>Assigned</option>
                                        <option value="Ongoing" <?= $st === 'Ongoing' ? 'disabled' : '' ?>>Ongoing</option>
                                        <option value="Finished" <?= $st === 'Finished' ? 'disabled' : '' ?>>Finished</option>
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
                <div class="empty-state"><i class="fas fa-tasks"></i><h3>No assignments found</h3><p>Click "Add Assignment" to create one.</p></div>
                <?php endif; ?>
            </div>

            <!-- Customer Info Modal -->
            <div class="modal-overlay" id="customerModal"><div class="modal"><div class="modal-header" style="background:linear-gradient(135deg,#2c3e50,#34495e);">
                <h3><i class="fas fa-user-circle"></i> Customer Information</h3><button class="modal-close" onclick="closeModal('customerModal')">&times;</button></div>
                <div class="modal-body" id="customerModalBody" style="padding:20px;">
                    <p style="color:#888;">Loading...</p>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('customerModal')">Close</button></div>
            </div></div>

            <!-- Add Modal -->
            <div class="modal-overlay" id="addModal"><div class="modal"><div class="modal-header"><h3>Add Assignment</h3><button class="modal-close" onclick="closeModal('addModal')">&times;</button></div>
            <form method="POST"><input type="hidden" name="action" value="add"><div class="modal-body">
                <div class="form-group"><label>Vehicle</label><select name="vehicle_id" class="form-control" required><option value="">Select Vehicle</option><?php foreach ($vehicles as $v): ?><option value="<?= $v['vehicle_id'] ?>"><?= htmlspecialchars($v['make'] . ' ' . $v['model'] . ' - ' . $v['plate_number']) ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label>Technician</label><select name="technician_id" class="form-control" required><option value="">Select Technician</option><?php foreach ($technicians_list as $t): ?><option value="<?= $t['user_id'] ?>"><?= htmlspecialchars($t['full_name']) ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label>Service</label><select name="service_id" class="form-control" required><option value="">Select Service</option><?php foreach ($services_list as $s): ?><option value="<?= $s['service_id'] ?>"><?= htmlspecialchars($s['service_name']) ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label>Status</label><select name="status" class="form-control"><option value="Assigned">Assigned</option><option value="Ongoing">Ongoing</option><option value="Finished">Finished</option></select></div>
                <div class="form-group"><label>Notes</label><textarea name="notes" class="form-control" rows="3"></textarea></div>
            </div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button></div></form></div></div>

            <!-- Edit Modal -->
            <div class="modal-overlay" id="editModal"><div class="modal"><div class="modal-header"><h3>Edit Assignment</h3><button class="modal-close" onclick="closeModal('editModal')">&times;</button></div>
            <form method="POST"><input type="hidden" name="action" value="edit"><input type="hidden" name="assignment_id" id="edit_assignment_id"><div class="modal-body">
                <div class="form-group"><label>Vehicle</label><select name="vehicle_id" id="edit_vehicle_id" class="form-control" required><option value="">Select Vehicle</option><?php foreach ($vehicles as $v): ?><option value="<?= $v['vehicle_id'] ?>"><?= htmlspecialchars($v['make'] . ' ' . $v['model'] . ' - ' . $v['plate_number']) ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label>Technician</label><select name="technician_id" id="edit_technician_id" class="form-control" required><option value="">Select Technician</option><?php foreach ($technicians_list as $t): ?><option value="<?= $t['user_id'] ?>"><?= htmlspecialchars($t['full_name']) ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label>Service</label><select name="service_id" id="edit_service_id" class="form-control" required><option value="">Select Service</option><?php foreach ($services_list as $s): ?><option value="<?= $s['service_id'] ?>"><?= htmlspecialchars($s['service_name']) ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label>Status</label><select name="status" id="edit_status" class="form-control"><option value="Assigned">Assigned</option><option value="Ongoing">Ongoing</option><option value="Finished">Finished</option></select></div>
                <div class="form-group"><label>Notes</label><textarea name="notes" id="edit_notes" class="form-control" rows="3"></textarea></div>
            </div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update</button></div></form></div></div>

        </div>
    </main>
</div>
<script src="includes/admin.js"></script>
<script>
function openModal(id){document.getElementById(id).classList.add('active');}
function closeModal(id){document.getElementById(id).classList.remove('active');}
// Customer data embedded from PHP
const customerData = {
<?php foreach ($assignments as $row): ?>
    <?= (int)$row['assignment_id'] ?>: {
        name: <?= json_encode($row['customer_name'] ?? 'N/A') ?>,
        email: <?= json_encode($row['customer_email'] ?? 'N/A') ?>,
        vehicle: <?= json_encode(($row['make'] ?? '') . ' ' . ($row['model'] ?? '') . ' (' . ($row['year'] ?? '') . ')') ?>,
        plate: <?= json_encode($row['plate_number'] ?? 'N/A') ?>,
        color: <?= json_encode($row['color'] ?? 'N/A') ?>,
        service: <?= json_encode($row['service_name'] ?? 'N/A') ?>,
        price: <?= json_encode($row['base_price'] ? '₱' . number_format($row['base_price'], 2) : 'N/A') ?>,
        appointmentDate: <?= json_encode($row['appointment_date'] ? date('M d, Y h:i A', strtotime($row['appointment_date'])) : 'N/A') ?>,
        appointmentStatus: <?= json_encode($row['appointment_status'] ?? 'N/A') ?>,
        techName: <?= json_encode($row['technician_name'] ?? 'N/A') ?>,
        status: <?= json_encode($row['status'] ?? 'N/A') ?>
    },
<?php endforeach; ?>
};

function viewCustomer(assignmentId) {
    const c = customerData[assignmentId];
    if (!c) { alert('No customer info found.'); return; }
    const statusColor = c.appointmentStatus === 'Approved' ? '#27ae60' : c.appointmentStatus === 'Completed' ? '#3498db' : c.appointmentStatus === 'Pending' ? '#f39c12' : '#e74c3c';
    const aStatusColor = c.status === 'Ongoing' ? '#f39c12' : c.status === 'Finished' ? '#27ae60' : '#3498db';
    document.getElementById('customerModalBody').innerHTML = `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div style="grid-column:1/-1;background:#f8f9fa;border-radius:10px;padding:16px;display:flex;align-items:center;gap:15px;">
                <div style="width:50px;height:50px;border-radius:50%;background:linear-gradient(135deg,#3498db,#2c3e50);display:flex;align-items:center;justify-content:center;color:#fff;font-size:22px;font-weight:700;">
                    ${c.name.charAt(0).toUpperCase()}
                </div>
                <div>
                    <div style="font-size:18px;font-weight:700;color:#2c3e50;">${c.name}</div>
                    <div style="font-size:13px;color:#7f8c8d;"><i class="fas fa-envelope" style="margin-right:5px;"></i>${c.email}</div>
                </div>
            </div>
            <div style="background:#f8f9fa;border-radius:8px;padding:14px;">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#95a5a6;margin-bottom:6px;"><i class="fas fa-car"></i> Vehicle</div>
                <div style="font-weight:600;color:#2c3e50;">${c.vehicle}</div>
                <div style="font-size:13px;color:#7f8c8d;">Plate: ${c.plate} &middot; Color: ${c.color}</div>
            </div>
            <div style="background:#f8f9fa;border-radius:8px;padding:14px;">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#95a5a6;margin-bottom:6px;"><i class="fas fa-wrench"></i> Service</div>
                <div style="font-weight:600;color:#2c3e50;">${c.service}</div>
                <div style="font-size:13px;color:#7f8c8d;">Price: ${c.price}</div>
            </div>
            <div style="background:#f8f9fa;border-radius:8px;padding:14px;">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#95a5a6;margin-bottom:6px;"><i class="fas fa-calendar"></i> Appointment</div>
                <div style="font-weight:600;color:#2c3e50;">${c.appointmentDate}</div>
                <div><span style="display:inline-block;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:600;background:${statusColor}20;color:${statusColor};">${c.appointmentStatus}</span></div>
            </div>
            <div style="background:#f8f9fa;border-radius:8px;padding:14px;">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#95a5a6;margin-bottom:6px;"><i class="fas fa-user-cog"></i> Assignment</div>
                <div style="font-weight:600;color:#2c3e50;">Tech: ${c.techName}</div>
                <div><span style="display:inline-block;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:600;background:${aStatusColor}20;color:${aStatusColor};">${c.status}</span></div>
            </div>
        </div>
    `;
    openModal('customerModal');
}

function editAssignment(id,vid,tid,sid,status,notes){
    document.getElementById('edit_assignment_id').value=id;
    document.getElementById('edit_vehicle_id').value=vid;
    document.getElementById('edit_technician_id').value=tid;
    document.getElementById('edit_service_id').value=sid;
    document.getElementById('edit_status').value=status;
    document.getElementById('edit_notes').value=notes;
    openModal('editModal');
}
function searchTable(){
    var q=document.getElementById('searchInput').value.toLowerCase();
    var sf=document.getElementById('statusFilter').value;
    var t=document.getElementById('assignmentsTable');if(!t)return;
    var rows=t.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
    for(var i=0;i<rows.length;i++){
        var text=rows[i].textContent.toLowerCase();
        var rs=rows[i].getAttribute('data-status');
        rows[i].style.display=(text.indexOf(q)>-1&&(!sf||rs===sf))?'':'none';
    }
}
document.querySelectorAll('.modal-overlay').forEach(function(o){o.addEventListener('click',function(e){if(e.target===o)o.classList.remove('active');});});
</script>
</body>
</html>
