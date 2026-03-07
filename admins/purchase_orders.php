<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../users/login.php');
    exit;
}

$page_title = 'Purchase Orders';
$current_page = 'purchase_orders';

require_once __DIR__ . '/../includes/db.php';

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'create_po') {
        try {
            $supplier_id = (int)$_POST['supplier_id'];
            $item_id = (int)$_POST['item_id'];
            $quantity = (int)$_POST['quantity'];
            $unit_cost = (float)$_POST['unit_cost'];
            $notes = trim($_POST['notes'] ?? '');

            if (!$supplier_id || !$item_id || $quantity <= 0 || $unit_cost <= 0) {
                $error = 'Please fill in all required fields with valid values.';
            } else {
                $total_cost = $quantity * $unit_cost;
                $pdo->beginTransaction();

                // Create PO as Received immediately
                $stmt = $pdo->prepare("INSERT INTO purchase_orders (supplier_id, item_id, quantity, unit_cost, total_cost, status, notes, ordered_by, received_at) VALUES (?, ?, ?, ?, ?, 'Received', ?, ?, NOW())");
                $stmt->execute([$supplier_id, $item_id, $quantity, $unit_cost, $total_cost, $notes ?: null, $_SESSION['user_id']]);
                $po_id = $pdo->lastInsertId();

                // Add stock to inventory immediately
                $pdo->prepare("UPDATE inventory SET quantity = quantity + ?, supplier_id = ? WHERE item_id = ?")->execute([$quantity, $supplier_id, $item_id]);

                // Update supplier text field
                $sn = $pdo->prepare("SELECT supplier_name FROM suppliers WHERE supplier_id = ?");
                $sn->execute([$supplier_id]);
                $supplierName = $sn->fetchColumn();
                if ($supplierName) {
                    $pdo->prepare("UPDATE inventory SET supplier = ? WHERE item_id = ?")->execute([$supplierName, $item_id]);
                }

                $pdo->commit();
                $success = 'Purchase order #' . $po_id . ' completed! ' . $quantity . ' units added to inventory.';
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Failed to create order: ' . $e->getMessage();
        }
    }


}

// Fetch purchase orders
$orders = $pdo->query("
    SELECT po.*, s.supplier_name, i.item_name, i.sku, i.category, u.full_name AS ordered_by_name
    FROM purchase_orders po
    LEFT JOIN suppliers s ON po.supplier_id = s.supplier_id
    LEFT JOIN inventory i ON po.item_id = i.item_id
    LEFT JOIN users u ON po.ordered_by = u.user_id
    ORDER BY po.created_at DESC
")->fetchAll();

// Fetch active suppliers and inventory for form
$activeSuppliers = $pdo->query("SELECT supplier_id, supplier_name FROM suppliers WHERE status = 'active' ORDER BY supplier_name")->fetchAll();
$inventoryItems = $pdo->query("SELECT item_id, item_name, sku, unit_price, quantity, category FROM inventory ORDER BY item_name")->fetchAll();

// Stats
$totalOrders = count($orders);
$pendingOrders = 0;
$totalSpent = 0;
$receivedOrders = 0;
foreach ($orders as $o) {
    if ($o['status'] === 'Pending') $pendingOrders++;
    if ($o['status'] === 'Received') { $receivedOrders++; $totalSpent += (float)$o['total_cost']; }
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
                <h2><i class="fas fa-cart-shopping"></i> Purchase Orders</h2>
                <button class="btn btn-primary" onclick="openModal('createModal')"><i class="fas fa-plus"></i> New Purchase Order</button>
            </div>

            <!-- Stats -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:24px;">
                <div class="admin-card" style="padding:20px;text-align:center;">
                    <div style="font-size:28px;font-weight:700;color:#2c3e50;"><?= $totalOrders ?></div>
                    <div style="font-size:13px;color:#7f8c8d;">Total Orders</div>
                </div>
                <div class="admin-card" style="padding:20px;text-align:center;">
                    <div style="font-size:28px;font-weight:700;color:#27ae60;"><?= $receivedOrders ?></div>
                    <div style="font-size:13px;color:#7f8c8d;">Completed</div>
                </div>
                <div class="admin-card" style="padding:20px;text-align:center;">
                    <div style="font-size:28px;font-weight:700;color:#27ae60;"><?= $receivedOrders ?></div>
                    <div style="font-size:13px;color:#7f8c8d;">Received</div>
                </div>
                <div class="admin-card" style="padding:20px;text-align:center;">
                    <div style="font-size:28px;font-weight:700;color:#e74c3c;">₱<?= number_format($totalSpent, 2) ?></div>
                    <div style="font-size:13px;color:#7f8c8d;">Total Spent</div>
                </div>
            </div>

            <div class="admin-card">
                <div class="table-toolbar" style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap;">
                    <div class="search-box"><i class="fas fa-search"></i>
                        <input type="text" id="searchInput" placeholder="Search orders..." onkeyup="searchTable()">
                    </div>
                    <select id="statusFilter" onchange="searchTable()" class="form-control" style="width:auto;">
                        <option value="">All Status</option>

                        <option value="Received">Received</option>
                        <option value="Cancelled">Cancelled</option>
                    </select>
                </div>

                <?php if (count($orders) > 0): ?>
                <div class="table-responsive">
                    <table class="admin-table" id="ordersTable">
                        <thead>
                            <tr>
                                <th>PO #</th>
                                <th>Supplier</th>
                                <th>Item</th>
                                <th>Qty</th>
                                <th>Unit Cost</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Ordered By</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($orders as $row): ?>
                            <tr data-status="<?= htmlspecialchars($row['status']) ?>">
                                <td><strong>#<?= $row['po_id'] ?></strong></td>
                                <td><?= htmlspecialchars($row['supplier_name'] ?? 'N/A') ?></td>
                                <td>
                                    <?= htmlspecialchars($row['item_name'] ?? 'N/A') ?>
                                    <?php if ($row['sku']): ?><br><small style="color:#888;">SKU: <?= htmlspecialchars($row['sku']) ?></small><?php endif; ?>
                                </td>
                                <td><?= (int)$row['quantity'] ?></td>
                                <td>₱<?= number_format((float)$row['unit_cost'], 2) ?></td>
                                <td><strong>₱<?= number_format((float)$row['total_cost'], 2) ?></strong></td>
                                <td>
                                    <?php
                                    $bc = 'badge-assigned';
                                    if ($row['status'] === 'Received') $bc = 'badge-finished';
                                    elseif ($row['status'] === 'Cancelled') $bc = 'badge-ongoing';
                                    elseif ($row['status'] === 'Pending') $bc = 'badge-assigned';
                                    ?>
                                    <span class="badge <?= $bc ?>"><?= htmlspecialchars($row['status']) ?></span>
                                </td>
                                <td><?= htmlspecialchars($row['ordered_by_name'] ?? 'N/A') ?></td>
                                <td>
                                    <?= date('M d, Y', strtotime($row['created_at'])) ?>
                                    <br><small style="color:#888;"><?= date('h:i A', strtotime($row['created_at'])) ?></small>
                                </td>
                                <td class="action-btns">
                                    <?php if ($row['status'] === 'Received'): ?>
                                    <span style="font-size:11px;color:#27ae60;"><i class="fas fa-check"></i> <?= $row['received_at'] ? date('M d, h:iA', strtotime($row['received_at'])) : '' ?></span>
                                    <?php elseif ($row['status'] === 'Cancelled'): ?>
                                    <span style="font-size:11px;color:#e74c3c;"><i class="fas fa-ban"></i> Cancelled</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state"><i class="fas fa-cart-shopping"></i><h3>No purchase orders yet</h3><p>Click "New Purchase Order" to buy stock from suppliers.</p></div>
                <?php endif; ?>
            </div>

            <!-- Create PO Modal -->
            <div class="modal-overlay" id="createModal"><div class="modal" style="max-width:550px;"><div class="modal-header"><h3><i class="fas fa-cart-plus"></i> New Purchase Order</h3><button class="modal-close" onclick="closeModal('createModal')">&times;</button></div>
            <form method="POST"><input type="hidden" name="action" value="create_po"><div class="modal-body">
                <div class="form-group">
                    <label>Supplier <span style="color:#e74c3c;">*</span></label>
                    <select name="supplier_id" id="po_supplier" class="form-control" required>
                        <option value="">-- Select Supplier --</option>
                        <?php foreach ($activeSuppliers as $sup): ?>
                        <option value="<?= $sup['supplier_id'] ?>"><?= htmlspecialchars($sup['supplier_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Item to Order <span style="color:#e74c3c;">*</span></label>
                    <select name="item_id" id="po_item" class="form-control" required onchange="updateItemInfo()">
                        <option value="">-- Select Item --</option>
                        <?php foreach ($inventoryItems as $itm): ?>
                        <option value="<?= $itm['item_id'] ?>" data-price="<?= $itm['unit_price'] ?>" data-stock="<?= $itm['quantity'] ?>" data-cat="<?= htmlspecialchars($itm['category'] ?? '') ?>"><?= htmlspecialchars($itm['item_name']) ?><?= $itm['sku'] ? ' (SKU: ' . htmlspecialchars($itm['sku']) . ')' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div id="itemInfo" style="margin-top:8px;display:none;padding:10px;background:#f8f9fa;border-radius:8px;font-size:13px;">
                        <span id="itemCat" style="color:#8e44ad;"></span> &middot;
                        Current Stock: <strong id="itemStock">0</strong> &middot;
                        Retail Price: <strong id="itemPrice">₱0</strong>
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div class="form-group">
                        <label>Quantity <span style="color:#e74c3c;">*</span></label>
                        <input type="number" name="quantity" id="po_qty" class="form-control" min="1" value="1" required oninput="calcTotal()">
                    </div>
                    <div class="form-group">
                        <label>Unit Cost (₱) <span style="color:#e74c3c;">*</span></label>
                        <input type="number" name="unit_cost" id="po_cost" class="form-control" step="0.01" min="0.01" required oninput="calcTotal()">
                    </div>
                </div>
                <div style="background:#eafaf1;border:1px solid #27ae60;border-radius:8px;padding:12px;text-align:center;margin-bottom:15px;">
                    <div style="font-size:12px;color:#27ae60;text-transform:uppercase;letter-spacing:1px;">Total Cost</div>
                    <div style="font-size:24px;font-weight:700;color:#27ae60;" id="po_total">₱0.00</div>
                </div>
                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="notes" class="form-control" rows="2" placeholder="Optional notes..."></textarea>
                </div>
            </div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('createModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-shopping-cart"></i> Buy Now</button></div></form></div></div>

        </div>
    </main>
</div>

<script src="includes/admin.js"></script>
<script>
function openModal(id){document.getElementById(id).classList.add('active');}
function closeModal(id){document.getElementById(id).classList.remove('active');}

function updateItemInfo() {
    const sel = document.getElementById('po_item');
    const opt = sel.options[sel.selectedIndex];
    const info = document.getElementById('itemInfo');
    if (!sel.value) { info.style.display = 'none'; return; }
    document.getElementById('itemStock').textContent = opt.dataset.stock;
    document.getElementById('itemPrice').textContent = '₱' + parseFloat(opt.dataset.price).toFixed(2);
    document.getElementById('itemCat').textContent = opt.dataset.cat || 'General';
    info.style.display = 'block';
    // Pre-fill unit cost slightly lower than retail
    const retailPrice = parseFloat(opt.dataset.price) || 0;
    document.getElementById('po_cost').value = (retailPrice * 0.6).toFixed(2);
    calcTotal();
}

function calcTotal() {
    const qty = parseInt(document.getElementById('po_qty').value) || 0;
    const cost = parseFloat(document.getElementById('po_cost').value) || 0;
    document.getElementById('po_total').textContent = '₱' + (qty * cost).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

function searchTable() {
    const q = document.getElementById('searchInput').value.toLowerCase();
    const sf = document.getElementById('statusFilter').value;
    const t = document.getElementById('ordersTable');
    if (!t) return;
    t.querySelector('tbody').querySelectorAll('tr').forEach(r => {
        const text = r.textContent.toLowerCase();
        const rs = r.getAttribute('data-status');
        r.style.display = (text.includes(q) && (!sf || rs === sf)) ? '' : 'none';
    });
}

document.querySelectorAll('.modal-overlay').forEach(o => { o.addEventListener('click', e => { if (e.target === o) o.classList.remove('active'); }); });
</script>
</body>
</html>
