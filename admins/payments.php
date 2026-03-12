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
            $new_id = $pdo->lastInsertId();
            logAudit($pdo, 'Created payment', 'payments', $new_id);
            $success = 'Payment recorded successfully.';
        } elseif ($action === 'edit') {
            $old = $pdo->prepare("SELECT invoice_id FROM payments WHERE payment_id = ?"); $old->execute([$_POST['payment_id']]); $old_inv = $old->fetchColumn();
            $pdo->prepare("UPDATE payments SET invoice_id=?, amount_paid=?, payment_method=?, reference_number=? WHERE payment_id=?")->execute([$_POST['invoice_id'], $_POST['amount_paid'], $_POST['payment_method'], $_POST['reference_number'] ?: null, $_POST['payment_id']]);
            recalcInvoiceStatus($pdo, $_POST['invoice_id']);
            if ($old_inv != $_POST['invoice_id']) recalcInvoiceStatus($pdo, $old_inv);
            logAudit($pdo, 'Updated payment', 'payments', $_POST['payment_id']);
            $success = 'Payment updated.';
        } elseif ($action === 'delete') {
            $old = $pdo->prepare("SELECT invoice_id FROM payments WHERE payment_id = ?"); $old->execute([$_POST['payment_id']]); $old_inv = $old->fetchColumn();
            $pdo->prepare("DELETE FROM payments WHERE payment_id = ?")->execute([$_POST['payment_id']]);
            if ($old_inv) recalcInvoiceStatus($pdo, $old_inv);
            logAudit($pdo, 'Deleted payment', 'payments', $_POST['payment_id']);
            $success = 'Payment deleted.';
        } elseif ($action === 'update_order_status') {
            $new_status = $_POST['status'];
            $order_id = (int) $_POST['order_id'];

            $pdo->beginTransaction();

            // If cancelling, restore inventory and notify customer
            if ($new_status === 'Cancelled') {
                // Check current status to avoid double-restoring
                $curStmt = $pdo->prepare("SELECT status, client_id FROM orders WHERE order_id = ?");
                $curStmt->execute([$order_id]);
                $curOrder = $curStmt->fetch();

                if ($curOrder && $curOrder['status'] !== 'Cancelled') {
                    // Restore inventory quantities
                    $itemsStmt = $pdo->prepare("SELECT item_id, quantity FROM order_items WHERE order_id = ? AND item_id IS NOT NULL");
                    $itemsStmt->execute([$order_id]);
                    $orderItems = $itemsStmt->fetchAll();
                    foreach ($orderItems as $oi) {
                        $pdo->prepare("UPDATE inventory SET quantity = quantity + ? WHERE item_id = ?")->execute([$oi['quantity'], $oi['item_id']]);
                    }

                    // Notify customer
                    $clientUser = $pdo->prepare("SELECT user_id FROM clients WHERE client_id = ?");
                    $clientUser->execute([$curOrder['client_id']]);
                    $cu = $clientUser->fetch();
                    if ($cu) {
                        $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'Order Cancelled', ?, 'cancellation')")
                            ->execute([$cu['user_id'], 'Your order #' . $order_id . ' has been cancelled by admin. If payment was made, a refund will be processed.']);
                    }
                }
            }

            $pdo->prepare("UPDATE orders SET status = ? WHERE order_id = ?")->execute([$new_status, $order_id]);
            logAudit($pdo, 'Updated order status to ' . $new_status, 'orders', $order_id);
            $pdo->commit();
            $success = 'Order status updated.';
        }
    } catch (Exception $e) { $error = 'Error: ' . $e->getMessage(); }
}

$payments = $pdo->query("SELECT p.*, i.total_amount as invoice_total, i.status as invoice_status, c.full_name as client_name FROM payments p LEFT JOIN invoices i ON p.invoice_id = i.invoice_id LEFT JOIN clients c ON i.client_id = c.client_id ORDER BY p.payment_date DESC")->fetchAll();
$invoices = $pdo->query("SELECT i.invoice_id, i.total_amount, i.status, c.full_name as client_name FROM invoices i LEFT JOIN clients c ON i.client_id = c.client_id ORDER BY i.invoice_id DESC")->fetchAll();

// Fetch e-wallet order payments
$ewallet_orders = $pdo->query("SELECT o.*, c.full_name as client_name FROM orders o LEFT JOIN clients c ON o.client_id = c.client_id WHERE o.payment_method IN ('GCash', 'Maya') ORDER BY o.created_at DESC")->fetchAll();

// Fetch all orders for the orders tab
$all_orders = $pdo->query("SELECT o.*, c.full_name as client_name FROM orders o LEFT JOIN clients c ON o.client_id = c.client_id ORDER BY o.created_at DESC")->fetchAll();
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
            <div class="page-header"><div><h1><i class="fas fa-credit-card"></i> Payments</h1><p>Track and process all payments</p></div>
                <button class="btn btn-primary" onclick="openModal('addModal')"><i class="fas fa-plus"></i> Record Payment</button></div>

            <!-- Tabs -->
            <div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;">
                <button class="btn btn-primary" id="tabInvoice" onclick="switchTab('invoice')"><i class="fas fa-file-invoice"></i> Invoice Payments</button>
                <button class="btn btn-secondary" id="tabOrders" onclick="switchTab('orders')"><i class="fas fa-shopping-cart"></i> Order Payments (<?= count($all_orders) ?>)</button>
                <button class="btn btn-secondary" id="tabEwallet" onclick="switchTab('ewallet')"><i class="fas fa-wallet"></i> E-Wallet Receipts (<?= count($ewallet_orders) ?>)</button>
            </div>

            <!-- Invoice Payments Tab -->
            <div class="admin-card" id="invoiceTab">
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

            <!-- Order Payments Tab -->
            <div class="admin-card" id="ordersTab" style="display:none;">
                <div class="card-body" style="padding:0">
                    <?php if (empty($all_orders)): ?><div class="empty-state"><i class="fas fa-shopping-cart"></i><p>No orders yet.</p></div>
                    <?php else: ?>
                    <table class="admin-table"><thead><tr><th>Order #</th><th>Client</th><th>Type</th><th>Total</th><th>Method</th><th>Receipt</th><th>Status</th><th>Date</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($all_orders as $o): ?>
                    <tr>
                        <td>#<?= $o['order_id'] ?></td>
                        <td><?= htmlspecialchars($o['client_name'] ?? 'N/A') ?></td>
                        <td><span class="badge badge-info"><?= ucfirst($o['order_type']) ?></span></td>
                        <td>₱<?= number_format($o['total_amount'],2) ?></td>
                        <td>
                            <?php if (in_array($o['payment_method'], ['GCash','Maya'])): ?>
                                <span style="color:#007bff;font-weight:600;"><i class="fas fa-wallet"></i> E-Wallet (<?= htmlspecialchars($o['payment_method']) ?>)</span>
                            <?php else: ?>
                                <?= htmlspecialchars($o['payment_method'] ?? '-') ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($o['receipt_image']): ?>
                                <a href="../uploads/<?= htmlspecialchars($o['receipt_image']) ?>" target="_blank" style="color:#27ae60;"><i class="fas fa-image"></i> View</a>
                            <?php else: ?>
                                <span style="color:#aaa;">None</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="update_order_status">
                                <input type="hidden" name="order_id" value="<?= $o['order_id'] ?>">
                                <select name="status" class="form-control" onchange="this.form.submit()" style="width:auto;display:inline-block;padding:4px 8px;font-size:12px;">
                                    <option value="Pending" <?= $o['status'] === 'Pending' ? 'selected' : '' ?>>Pending</option>
                                    <option value="Processing" <?= $o['status'] === 'Processing' ? 'selected' : '' ?>>Processing</option>
                                    <option value="Completed" <?= $o['status'] === 'Completed' ? 'selected' : '' ?>>Completed</option>
                                    <option value="Cancelled" <?= $o['status'] === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                </select>
                            </form>
                        </td>
                        <td><?= date('M d, Y h:i A', strtotime($o['created_at'])) ?></td>
                        <td>
                            <?php if ($o['receipt_image']): ?>
                                <a href="../uploads/<?= htmlspecialchars($o['receipt_image']) ?>" target="_blank" class="btn-icon btn-edit" title="View Receipt"><i class="fas fa-eye"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody></table><?php endif; ?>
                </div>
            </div>

            <!-- E-Wallet Receipts Tab -->
            <div class="admin-card" id="ewalletTab" style="display:none;">
                <div class="card-body" style="padding:0">
                    <?php if (empty($ewallet_orders)): ?><div class="empty-state"><i class="fas fa-wallet"></i><p>No e-wallet payments yet.</p></div>
                    <?php else: ?>
                    <table class="admin-table"><thead><tr><th>Order #</th><th>Client</th><th>E-Wallet</th><th>Amount</th><th>Receipt</th><th>Status</th><th>Date</th></tr></thead>
                    <tbody>
                    <?php foreach ($ewallet_orders as $eo): ?>
                    <tr>
                        <td>#<?= $eo['order_id'] ?></td>
                        <td><?= htmlspecialchars($eo['client_name'] ?? 'N/A') ?></td>
                        <td><span style="color:#007bff;font-weight:600;"><i class="fas fa-wallet"></i> E-Wallet (<?= htmlspecialchars($eo['payment_method']) ?>)</span></td>
                        <td style="font-weight:700;">₱<?= number_format($eo['total_amount'],2) ?></td>
                        <td>
                            <?php if ($eo['receipt_image']): ?>
                                <a href="../uploads/<?= htmlspecialchars($eo['receipt_image']) ?>" target="_blank" style="display:inline-flex;align-items:center;gap:4px;color:#27ae60;font-weight:600;">
                                    <i class="fas fa-image"></i> View Receipt
                                </a>
                            <?php else: ?>
                                <span style="color:#e74c3c;"><i class="fas fa-exclamation-triangle"></i> Missing</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="update_order_status">
                                <input type="hidden" name="order_id" value="<?= $eo['order_id'] ?>">
                                <select name="status" class="form-control" onchange="this.form.submit()" style="width:auto;display:inline-block;padding:4px 8px;font-size:12px;">
                                    <option value="Pending" <?= $eo['status'] === 'Pending' ? 'selected' : '' ?>>Pending</option>
                                    <option value="Processing" <?= $eo['status'] === 'Processing' ? 'selected' : '' ?>>Processing</option>
                                    <option value="Completed" <?= $eo['status'] === 'Completed' ? 'selected' : '' ?>>Completed</option>
                                    <option value="Cancelled" <?= $eo['status'] === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                </select>
                            </form>
                        </td>
                        <td><?= date('M d, Y h:i A', strtotime($eo['created_at'])) ?></td>
                    </tr>
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
    <div class="form-group"><label>Method</label><select name="payment_method" class="form-control" required><option value="Cash">Cash</option><optgroup label="E-Wallet"><option value="GCash">E-Wallet (GCash)</option><option value="Maya">E-Wallet (Maya)</option></optgroup></select></div></div>
    <div class="form-group"><label>Reference #</label><input type="text" name="reference_number" class="form-control" placeholder="Optional"></div>
</div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button></div></form></div></div>
<div class="modal-overlay" id="editModal"><div class="modal"><div class="modal-header"><h3>Edit Payment</h3><button class="modal-close" onclick="closeModal('editModal')">&times;</button></div>
<form method="POST"><input type="hidden" name="action" value="edit"><input type="hidden" name="payment_id" id="edit_payment_id"><div class="modal-body">
    <div class="form-group"><label>Invoice</label><select name="invoice_id" id="edit_invoice_id" class="form-control" required><option value="">Select</option><?php foreach ($invoices as $inv): ?><option value="<?= $inv['invoice_id'] ?>">INV-<?= $inv['invoice_id'] ?> - <?= htmlspecialchars($inv['client_name'] ?? 'N/A') ?> - ₱<?= number_format($inv['total_amount'],2) ?></option><?php endforeach; ?></select></div>
    <div class="form-row"><div class="form-group"><label>Amount</label><input type="number" name="amount_paid" id="edit_amount_paid" class="form-control" step="0.01" min="0" required></div>
    <div class="form-group"><label>Method</label><select name="payment_method" id="edit_payment_method" class="form-control" required><option value="Cash">Cash</option><optgroup label="E-Wallet"><option value="GCash">E-Wallet (GCash)</option><option value="Maya">E-Wallet (Maya)</option></optgroup></select></div></div>
    <div class="form-group"><label>Reference #</label><input type="text" name="reference_number" id="edit_reference_number" class="form-control"></div>
</div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update</button></div></form></div></div>
<script src="includes/admin.js"></script>
<script>
function openModal(id){document.getElementById(id).classList.add('active');}
function closeModal(id){document.getElementById(id).classList.remove('active');}
document.querySelectorAll('.modal-overlay').forEach(m=>{m.addEventListener('click',e=>{if(e.target===m)m.classList.remove('active');});});
function editPayment(r){document.getElementById('edit_payment_id').value=r.payment_id;document.getElementById('edit_invoice_id').value=r.invoice_id;document.getElementById('edit_amount_paid').value=r.amount_paid;document.getElementById('edit_payment_method').value=r.payment_method;document.getElementById('edit_reference_number').value=r.reference_number||'';openModal('editModal');}
function searchTable(q){q=q.toLowerCase();document.querySelectorAll('#dataTable tbody tr').forEach(r=>{r.style.display=r.textContent.toLowerCase().includes(q)?'':'none';});}
function switchTab(tab){
    document.getElementById('invoiceTab').style.display=tab==='invoice'?'':'none';
    document.getElementById('ordersTab').style.display=tab==='orders'?'':'none';
    document.getElementById('ewalletTab').style.display=tab==='ewallet'?'':'none';
    document.getElementById('tabInvoice').className='btn '+(tab==='invoice'?'btn-primary':'btn-secondary');
    document.getElementById('tabOrders').className='btn '+(tab==='orders'?'btn-primary':'btn-secondary');
    document.getElementById('tabEwallet').className='btn '+(tab==='ewallet'?'btn-primary':'btn-secondary');
}
</script>
</body></html>
