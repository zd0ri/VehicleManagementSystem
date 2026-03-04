<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') { header('Location: ../users/login.php'); exit; }
$page_title = 'Payments'; $current_page = 'payments';
require_once __DIR__ . '/../includes/db.php';
$success = $error = '';

function recalcInvoiceStatus($pdo, $invoice_id) {
    $inv = $pdo->prepare("SELECT total_amount FROM invoices WHERE invoice_id = ?"); $inv->execute([$invoice_id]); $inv = $inv->fetch();
    if (!$inv) return;
    $paid = $pdo->prepare("SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE invoice_id = ?"); $paid->execute([$invoice_id]);
    $total_paid = $paid->fetchColumn();
    $status = $total_paid >= $inv['total_amount'] ? 'Paid' : ($total_paid > 0 ? 'Partially Paid' : 'Unpaid');
    $pdo->prepare("UPDATE invoices SET status = ? WHERE invoice_id = ?")->execute([$status, $invoice_id]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add') {
            $stmt = $pdo->prepare("INSERT INTO payments (invoice_id, amount_paid, payment_method, reference_number) VALUES (?,?,?,?)");
            $stmt->execute([$_POST['invoice_id'], $_POST['amount_paid'], $_POST['payment_method'], $_POST['reference_number'] ?: null]);
            recalcInvoiceStatus($pdo, $_POST['invoice_id']);
            $success = 'Payment recorded successfully.';
        } elseif ($action === 'edit') {
            $old = $pdo->prepare("SELECT invoice_id FROM payments WHERE payment_id = ?"); $old->execute([$_POST['payment_id']]); $old_inv = $old->fetchColumn();
            $pdo->prepare("UPDATE payments SET invoice_id=?, amount_paid=?, payment_method=?, reference_number=? WHERE payment_id=?")->execute([$_POST['invoice_id'], $_POST['amount_paid'], $_POST['payment_method'], $_POST['reference_number'] ?: null, $_POST['payment_id']]);
            recalcInvoiceStatus($pdo, $_POST['invoice_id']);
            if ($old_inv != $_POST['invoice_id']) recalcInvoiceStatus($pdo, $old_inv);
            $success = 'Payment updated.';
        } elseif ($action === 'delete') {
            $old = $pdo->prepare("SELECT invoice_id FROM payments WHERE payment_id = ?"); $old->execute([$_POST['payment_id']]); $old_inv = $old->fetchColumn();
            $pdo->prepare("DELETE FROM payments WHERE payment_id = ?")->execute([$_POST['payment_id']]);
            if ($old_inv) recalcInvoiceStatus($pdo, $old_inv);
            $success = 'Payment deleted.';
        }
    } catch (Exception $e) { $error = 'Error: ' . $e->getMessage(); }
}

$payments = $pdo->query("SELECT p.*, i.total_amount as invoice_total, i.status as invoice_status, c.full_name as client_name FROM payments p LEFT JOIN invoices i ON p.invoice_id = i.invoice_id LEFT JOIN clients c ON i.client_id = c.client_id ORDER BY p.payment_date DESC")->fetchAll();
$invoices = $pdo->query("SELECT i.invoice_id, i.total_amount, i.status, c.full_name as client_name FROM invoices i LEFT JOIN clients c ON i.client_id = c.client_id ORDER BY i.invoice_id DESC")->fetchAll();
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
            <?php if ($error): ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div><?php endif; ?>
            <div class="page-header"><div><h1><i class="fas fa-credit-card"></i> Payments</h1><p>Track and process payments</p></div>
                <button class="btn btn-primary" onclick="openModal('addModal')"><i class="fas fa-plus"></i> Record Payment</button></div>
            <div class="admin-card">
                <div class="table-toolbar"><div class="table-search"><i class="fas fa-search"></i><input type="text" placeholder="Search..." onkeyup="searchTable(this.value)"></div></div>
                <div class="card-body" style="padding:0">
                    <?php if (empty($payments)): ?><div class="empty-state"><i class="fas fa-credit-card"></i><p>No payments recorded yet.</p></div>
                    <?php else: ?>
                    <table class="admin-table" id="dataTable"><thead><tr><th>ID</th><th>Invoice</th><th>Client</th><th>Amount</th><th>Method</th><th>Reference</th><th>Date</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($payments as $r): ?>
                    <tr><td><?= $r['payment_id'] ?></td><td>INV-<?= $r['invoice_id'] ?></td><td><?= htmlspecialchars($r['client_name'] ?? 'N/A') ?></td>
                        <td>₱<?= number_format($r['amount_paid'],2) ?></td><td><?= htmlspecialchars($r['payment_method'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($r['reference_number'] ?? '-') ?></td><td><?= date('M d, Y h:i A', strtotime($r['payment_date'])) ?></td>
                        <td class="action-btns">
                            <button class="btn-icon btn-edit" onclick="editPayment(<?= htmlspecialchars(json_encode($r)) ?>)"><i class="fas fa-edit"></i></button>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Delete?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="payment_id" value="<?= $r['payment_id'] ?>"><button type="submit" class="btn-icon btn-delete"><i class="fas fa-trash"></i></button></form>
                        </td></tr>
                    <?php endforeach; ?>
                    </tbody></table><?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>
<div class="modal-overlay" id="addModal"><div class="modal"><div class="modal-header"><h3>Record Payment</h3><button class="modal-close" onclick="closeModal('addModal')">&times;</button></div>
<form method="POST"><input type="hidden" name="action" value="add"><div class="modal-body">
    <div class="form-group"><label>Invoice</label><select name="invoice_id" class="form-control" required><option value="">Select Invoice</option><?php foreach ($invoices as $inv): ?><option value="<?= $inv['invoice_id'] ?>">INV-<?= $inv['invoice_id'] ?> - <?= htmlspecialchars($inv['client_name'] ?? 'N/A') ?> - ₱<?= number_format($inv['total_amount'],2) ?> (<?= $inv['status'] ?>)</option><?php endforeach; ?></select></div>
    <div class="form-row"><div class="form-group"><label>Amount Paid</label><input type="number" name="amount_paid" class="form-control" step="0.01" min="0" required></div>
    <div class="form-group"><label>Method</label><select name="payment_method" class="form-control" required><option value="Cash">Cash</option><option value="Card">Card</option><option value="GCash">GCash</option><option value="Bank Transfer">Bank Transfer</option></select></div></div>
    <div class="form-group"><label>Reference #</label><input type="text" name="reference_number" class="form-control" placeholder="Optional"></div>
</div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button></div></form></div></div>
<div class="modal-overlay" id="editModal"><div class="modal"><div class="modal-header"><h3>Edit Payment</h3><button class="modal-close" onclick="closeModal('editModal')">&times;</button></div>
<form method="POST"><input type="hidden" name="action" value="edit"><input type="hidden" name="payment_id" id="edit_payment_id"><div class="modal-body">
    <div class="form-group"><label>Invoice</label><select name="invoice_id" id="edit_invoice_id" class="form-control" required><option value="">Select</option><?php foreach ($invoices as $inv): ?><option value="<?= $inv['invoice_id'] ?>">INV-<?= $inv['invoice_id'] ?> - <?= htmlspecialchars($inv['client_name'] ?? 'N/A') ?> - ₱<?= number_format($inv['total_amount'],2) ?></option><?php endforeach; ?></select></div>
    <div class="form-row"><div class="form-group"><label>Amount</label><input type="number" name="amount_paid" id="edit_amount_paid" class="form-control" step="0.01" min="0" required></div>
    <div class="form-group"><label>Method</label><select name="payment_method" id="edit_payment_method" class="form-control" required><option value="Cash">Cash</option><option value="Card">Card</option><option value="GCash">GCash</option><option value="Bank Transfer">Bank Transfer</option></select></div></div>
    <div class="form-group"><label>Reference #</label><input type="text" name="reference_number" id="edit_reference_number" class="form-control"></div>
</div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update</button></div></form></div></div>
<script src="includes/admin.js"></script>
<script>
function openModal(id){document.getElementById(id).classList.add('active');}
function closeModal(id){document.getElementById(id).classList.remove('active');}
document.querySelectorAll('.modal-overlay').forEach(m=>{m.addEventListener('click',e=>{if(e.target===m)m.classList.remove('active');});});
function editPayment(r){document.getElementById('edit_payment_id').value=r.payment_id;document.getElementById('edit_invoice_id').value=r.invoice_id;document.getElementById('edit_amount_paid').value=r.amount_paid;document.getElementById('edit_payment_method').value=r.payment_method;document.getElementById('edit_reference_number').value=r.reference_number||'';openModal('editModal');}
function searchTable(q){q=q.toLowerCase();document.querySelectorAll('#dataTable tbody tr').forEach(r=>{r.style.display=r.textContent.toLowerCase().includes(q)?'':'none';});}
</script>
</body></html>
