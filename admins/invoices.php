<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') { header('Location: ../users/login.php'); exit; }
$page_title = 'Invoices'; $current_page = 'invoices';
require_once __DIR__ . '/../includes/db.php';
$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add') {
            $subtotal = floatval($_POST['subtotal']);
            $tax_amount = round($subtotal * 0.12, 2);
            $total_amount = round($subtotal + $tax_amount, 2);
            $stmt = $pdo->prepare("INSERT INTO invoices (client_id, vehicle_id, subtotal, tax_amount, total_amount, status) VALUES (?,?,?,?,?,?)");
            $stmt->execute([$_POST['client_id'], $_POST['vehicle_id'], $subtotal, $tax_amount, $total_amount, $_POST['status']]);
            $new_id = $pdo->lastInsertId();
            logAudit($pdo, 'Created invoice', 'invoices', $new_id);
            $success = 'Invoice created successfully.';
        } elseif ($action === 'edit') {
            $subtotal = floatval($_POST['subtotal']);
            $tax_amount = round($subtotal * 0.12, 2);
            $total_amount = round($subtotal + $tax_amount, 2);
            $pdo->prepare("UPDATE invoices SET client_id=?, vehicle_id=?, subtotal=?, tax_amount=?, total_amount=?, status=? WHERE invoice_id=?")->execute([$_POST['client_id'], $_POST['vehicle_id'], $subtotal, $tax_amount, $total_amount, $_POST['status'], $_POST['invoice_id']]);
            logAudit($pdo, 'Updated invoice', 'invoices', $_POST['invoice_id']);
            $success = 'Invoice updated successfully.';
        } elseif ($action === 'delete') {
            $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id = ?")->execute([$_POST['invoice_id']]);
            $pdo->prepare("DELETE FROM invoices WHERE invoice_id = ?")->execute([$_POST['invoice_id']]);
            logAudit($pdo, 'Deleted invoice', 'invoices', $_POST['invoice_id']);
            $success = 'Invoice deleted successfully.';
        } elseif ($action === 'update_status') {
            $pdo->prepare("UPDATE invoices SET status=? WHERE invoice_id=?")->execute([$_POST['status'], $_POST['invoice_id']]);
            logAudit($pdo, 'Updated invoice status to ' . $_POST['status'], 'invoices', $_POST['invoice_id']);
            $success = 'Invoice status updated.';
        }
    } catch (Exception $e) { $error = 'Error: ' . $e->getMessage(); }
}

$invoices = $pdo->query("SELECT i.*, c.full_name AS client_name, v.plate_number FROM invoices i LEFT JOIN clients c ON i.client_id = c.client_id LEFT JOIN vehicles v ON i.vehicle_id = v.vehicle_id ORDER BY i.created_at DESC")->fetchAll();
$clients = $pdo->query("SELECT client_id, full_name FROM clients ORDER BY full_name ASC")->fetchAll();
$vehicles = $pdo->query("SELECT vehicle_id, plate_number, make, model FROM vehicles ORDER BY plate_number ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - VehiCare Admin</title>
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
            <?php if ($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div><?php endif; ?>
            <div class="page-header"><h2><i class="fas fa-file-invoice-dollar"></i> Invoices</h2>
                <button class="btn btn-primary" onclick="openModal('addModal')"><i class="fas fa-plus"></i> Create Invoice</button></div>
            <div class="admin-card">
                <div class="table-toolbar" style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap;">
                    <div class="search-box"><i class="fas fa-search"></i><input type="text" placeholder="Search..." onkeyup="searchTable(this.value)"></div>
                    <select onchange="filterByStatus(this.value)" class="form-control" style="width:auto;">
                        <option value="">All Statuses</option>
                        <option value="Unpaid">Unpaid</option>
                        <option value="Partially Paid">Partially Paid</option>
                        <option value="Paid">Paid</option>
                    </select>
                </div>
                <?php if (empty($invoices)): ?><div class="empty-state"><i class="fas fa-file-invoice-dollar"></i><h3>No invoices found</h3><p>Click "Create Invoice" to add one.</p></div>
                <?php else: ?>
                <div class="table-responsive">
                <table class="admin-table" id="dataTable"><thead><tr><th>Invoice #</th><th>Client</th><th>Vehicle</th><th>Subtotal</th><th>Tax</th><th>Total</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($invoices as $r): ?>
                <tr data-status="<?= htmlspecialchars($r['status']) ?>">
                    <td>INV-<?= $r['invoice_id'] ?></td>
                    <td><?= htmlspecialchars($r['client_name'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars($r['plate_number'] ?? 'N/A') ?></td>
                    <td>₱<?= number_format($r['subtotal'], 2) ?></td>
                    <td>₱<?= number_format($r['tax_amount'], 2) ?></td>
                    <td>₱<?= number_format($r['total_amount'], 2) ?></td>
                    <td><span class="badge <?php if ($r['status'] === 'Paid') echo 'badge-paid'; elseif ($r['status'] === 'Partially Paid') echo 'badge-pending'; else echo 'badge-unpaid'; ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                    <td><?= date('M d, Y', strtotime($r['created_at'])) ?></td>
                    <td class="action-btns">
                        <button class="btn-icon btn-edit" onclick='editInvoice(<?= json_encode($r) ?>)'><i class="fas fa-edit"></i></button>
                        <form method="POST" style="display:inline" class="status-form">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="invoice_id" value="<?= $r['invoice_id'] ?>">
                            <select name="status" class="form-control form-control-sm" onchange="this.form.submit()" style="width:auto;display:inline-block;font-size:0.8rem;padding:2px 6px;">
                                <option value="Unpaid" <?= $r['status'] === 'Unpaid' ? 'selected' : '' ?>>Unpaid</option>
                                <option value="Partially Paid" <?= $r['status'] === 'Partially Paid' ? 'selected' : '' ?>>Partially Paid</option>
                                <option value="Paid" <?= $r['status'] === 'Paid' ? 'selected' : '' ?>>Paid</option>
                            </select>
                        </form>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete this invoice?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="invoice_id" value="<?= $r['invoice_id'] ?>">
                            <button type="submit" class="btn-icon btn-delete"><i class="fas fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody></table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<!-- Add Modal -->
<div class="modal-overlay" id="addModal"><div class="modal"><div class="modal-header"><h3>Create Invoice</h3><button class="modal-close" onclick="closeModal('addModal')">&times;</button></div>
<form method="POST"><input type="hidden" name="action" value="add"><div class="modal-body">
    <div class="form-group"><label>Client</label><select name="client_id" class="form-control" required><option value="">Select Client</option><?php foreach ($clients as $c): ?><option value="<?= $c['client_id'] ?>"><?= htmlspecialchars($c['full_name']) ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label>Vehicle</label><select name="vehicle_id" class="form-control" required><option value="">Select Vehicle</option><?php foreach ($vehicles as $v): ?><option value="<?= $v['vehicle_id'] ?>"><?= htmlspecialchars($v['plate_number'] . ' - ' . $v['make'] . ' ' . $v['model']) ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label>Subtotal (₱)</label><input type="number" name="subtotal" class="form-control" step="0.01" min="0" required oninput="calcTotals('add')"></div>
    <div class="form-row">
        <div class="form-group"><label>Tax 12% (₱)</label><input type="text" id="add_tax" class="form-control" readonly></div>
        <div class="form-group"><label>Total (₱)</label><input type="text" id="add_total" class="form-control" readonly></div>
    </div>
    <div class="form-group"><label>Status</label><select name="status" class="form-control" required><option value="Unpaid">Unpaid</option><option value="Partially Paid">Partially Paid</option><option value="Paid">Paid</option></select></div>
</div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button></div></form></div></div>

<!-- Edit Modal -->
<div class="modal-overlay" id="editModal"><div class="modal"><div class="modal-header"><h3>Edit Invoice</h3><button class="modal-close" onclick="closeModal('editModal')">&times;</button></div>
<form method="POST"><input type="hidden" name="action" value="edit"><input type="hidden" name="invoice_id" id="edit_invoice_id"><div class="modal-body">
    <div class="form-group"><label>Client</label><select name="client_id" id="edit_client_id" class="form-control" required><option value="">Select Client</option><?php foreach ($clients as $c): ?><option value="<?= $c['client_id'] ?>"><?= htmlspecialchars($c['full_name']) ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label>Vehicle</label><select name="vehicle_id" id="edit_vehicle_id" class="form-control" required><option value="">Select Vehicle</option><?php foreach ($vehicles as $v): ?><option value="<?= $v['vehicle_id'] ?>"><?= htmlspecialchars($v['plate_number'] . ' - ' . $v['make'] . ' ' . $v['model']) ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label>Subtotal (₱)</label><input type="number" name="subtotal" id="edit_subtotal" class="form-control" step="0.01" min="0" required oninput="calcTotals('edit')"></div>
    <div class="form-row">
        <div class="form-group"><label>Tax 12% (₱)</label><input type="text" id="edit_tax" class="form-control" readonly></div>
        <div class="form-group"><label>Total (₱)</label><input type="text" id="edit_total" class="form-control" readonly></div>
    </div>
    <div class="form-group"><label>Status</label><select name="status" id="edit_status" class="form-control" required><option value="Unpaid">Unpaid</option><option value="Partially Paid">Partially Paid</option><option value="Paid">Paid</option></select></div>
</div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update</button></div></form></div></div>

<script src="includes/admin.js"></script>
<script>
function openModal(id){ document.getElementById(id).classList.add('active'); }
function closeModal(id){ document.getElementById(id).classList.remove('active'); }
document.querySelectorAll('.modal-overlay').forEach(m => { m.addEventListener('click', e => { if (e.target === m) m.classList.remove('active'); }); });

function editInvoice(r) {
    document.getElementById('edit_invoice_id').value = r.invoice_id;
    document.getElementById('edit_client_id').value = r.client_id;
    document.getElementById('edit_vehicle_id').value = r.vehicle_id;
    document.getElementById('edit_subtotal').value = r.subtotal;
    document.getElementById('edit_status').value = r.status;
    calcTotals('edit');
    openModal('editModal');
}

function calcTotals(prefix) {
    var si = prefix === 'add' ? document.querySelector('#addModal input[name="subtotal"]') : document.getElementById('edit_subtotal');
    var subtotal = parseFloat(si.value) || 0;
    var tax = (subtotal * 0.12).toFixed(2);
    var total = (subtotal + parseFloat(tax)).toFixed(2);
    document.getElementById(prefix + '_tax').value = '₱' + parseFloat(tax).toLocaleString('en-PH', {minimumFractionDigits:2});
    document.getElementById(prefix + '_total').value = '₱' + parseFloat(total).toLocaleString('en-PH', {minimumFractionDigits:2});
}

function searchTable(q) {
    q = q.toLowerCase();
    document.querySelectorAll('#dataTable tbody tr').forEach(r => { r.style.display = r.textContent.toLowerCase().includes(q) ? '' : 'none'; });
}

function filterByStatus(status) {
    document.querySelectorAll('#dataTable tbody tr').forEach(r => {
        if (!status) { r.style.display = ''; return; }
        r.style.display = r.getAttribute('data-status') === status ? '' : 'none';
    });
}
</script>
</body>
</html>
