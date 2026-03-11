<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../users/login.php');
    exit;
}

$page_title = 'Suppliers';
$current_page = 'suppliers';

require_once __DIR__ . '/../includes/db.php';

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'add') {
        try {
            $stmt = $pdo->prepare("INSERT INTO suppliers (supplier_name, contact_person, email, phone, address, status) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                trim($_POST['supplier_name']),
                trim($_POST['contact_person']) ?: null,
                trim($_POST['email']) ?: null,
                trim($_POST['phone']) ?: null,
                trim($_POST['address']) ?: null,
                $_POST['status'] ?? 'active'
            ]);
            $new_id = $pdo->lastInsertId();
            logAudit($pdo, 'Created supplier', 'suppliers', $new_id);
            $success = 'Supplier added successfully.';
        } catch (Exception $e) {
            $error = 'Failed to add supplier: ' . $e->getMessage();
        }
    }

    if ($action === 'edit') {
        try {
            $stmt = $pdo->prepare("UPDATE suppliers SET supplier_name = ?, contact_person = ?, email = ?, phone = ?, address = ?, status = ? WHERE supplier_id = ?");
            $stmt->execute([
                trim($_POST['supplier_name']),
                trim($_POST['contact_person']) ?: null,
                trim($_POST['email']) ?: null,
                trim($_POST['phone']) ?: null,
                trim($_POST['address']) ?: null,
                $_POST['status'],
                (int)$_POST['supplier_id']
            ]);
            logAudit($pdo, 'Updated supplier', 'suppliers', (int)$_POST['supplier_id']);
            $success = 'Supplier updated successfully.';
        } catch (Exception $e) {
            $error = 'Failed to update supplier: ' . $e->getMessage();
        }
    }

    if ($action === 'delete') {
        try {
            // Check if supplier is used in inventory or purchase orders
            $inUse = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE supplier_id = ?");
            $inUse->execute([(int)$_POST['supplier_id']]);
            $poUse = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE supplier_id = ?");
            $poUse->execute([(int)$_POST['supplier_id']]);

            if ((int)$inUse->fetchColumn() > 0 || (int)$poUse->fetchColumn() > 0) {
                // Deactivate instead of delete
                $pdo->prepare("UPDATE suppliers SET status = 'inactive' WHERE supplier_id = ?")->execute([(int)$_POST['supplier_id']]);
                logAudit($pdo, 'Deactivated supplier (has linked items)', 'suppliers', (int)$_POST['supplier_id']);
                $success = 'Supplier has linked items/orders and was deactivated instead of deleted.';
            } else {
                $pdo->prepare("DELETE FROM suppliers WHERE supplier_id = ?")->execute([(int)$_POST['supplier_id']]);
                logAudit($pdo, 'Deleted supplier', 'suppliers', (int)$_POST['supplier_id']);
                $success = 'Supplier deleted successfully.';
            }
        } catch (Exception $e) {
            $error = 'Failed to delete supplier: ' . $e->getMessage();
        }
    }
}

// Fetch suppliers with item count and total purchase value
$suppliers = $pdo->query("
    SELECT s.*, 
           COUNT(DISTINCT i.item_id) AS item_count,
           COALESCE(SUM(CASE WHEN po.status = 'Received' THEN po.total_cost ELSE 0 END), 0) AS total_purchased
    FROM suppliers s
    LEFT JOIN inventory i ON s.supplier_id = i.supplier_id
    LEFT JOIN purchase_orders po ON s.supplier_id = po.supplier_id
    GROUP BY s.supplier_id
    ORDER BY s.supplier_name
")->fetchAll();

$totalSuppliers = count($suppliers);
$activeSuppliers = 0;
foreach ($suppliers as $s) { if ($s['status'] === 'active') $activeSuppliers++; }
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
                <h2><i class="fas fa-truck-field"></i> Suppliers</h2>
                <button class="btn btn-primary" onclick="openModal('addModal')"><i class="fas fa-plus"></i> Add Supplier</button>
            </div>

            <!-- Stats -->
            <div class="stats-row" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px;">
                <div class="admin-card" style="padding:20px;text-align:center;">
                    <div style="font-size:28px;font-weight:700;color:#2c3e50;"><?= $totalSuppliers ?></div>
                    <div style="font-size:13px;color:#7f8c8d;">Total Suppliers</div>
                </div>
                <div class="admin-card" style="padding:20px;text-align:center;">
                    <div style="font-size:28px;font-weight:700;color:#27ae60;"><?= $activeSuppliers ?></div>
                    <div style="font-size:13px;color:#7f8c8d;">Active Suppliers</div>
                </div>
                <div class="admin-card" style="padding:20px;text-align:center;">
                    <div style="font-size:28px;font-weight:700;color:#e74c3c;"><?= $totalSuppliers - $activeSuppliers ?></div>
                    <div style="font-size:13px;color:#7f8c8d;">Inactive Suppliers</div>
                </div>
            </div>

            <div class="admin-card">
                <div class="table-toolbar" style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap;">
                    <div class="search-box"><i class="fas fa-search"></i>
                        <input type="text" id="searchInput" placeholder="Search suppliers..." onkeyup="searchTable()">
                    </div>
                    <select id="statusFilter" onchange="searchTable()" class="form-control" style="width:auto;">
                        <option value="">All Status</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>

                <?php if (count($suppliers) > 0): ?>
                <div class="table-responsive">
                    <table class="admin-table" id="suppliersTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Supplier Name</th>
                                <th>Contact Person</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Items</th>
                                <th>Total Purchased</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($suppliers as $row): ?>
                            <tr data-status="<?= htmlspecialchars($row['status']) ?>">
                                <td><?= $row['supplier_id'] ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($row['supplier_name']) ?></strong>
                                    <?php if ($row['address']): ?>
                                    <br><small style="color:#888;"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars(mb_strimwidth($row['address'], 0, 40, '...')) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($row['contact_person'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($row['email'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($row['phone'] ?? '—') ?></td>
                                <td><span class="badge badge-assigned"><?= (int)$row['item_count'] ?></span></td>
                                <td>₱<?= number_format((float)$row['total_purchased'], 2) ?></td>
                                <td>
                                    <span class="badge <?= $row['status'] === 'active' ? 'badge-finished' : 'badge-ongoing' ?>">
                                        <?= ucfirst($row['status']) ?>
                                    </span>
                                </td>
                                <td class="action-btns">
                                    <button class="btn-icon btn-view" title="View Details" onclick="viewSupplier(<?= $row['supplier_id'] ?>)"><i class="fas fa-eye"></i></button>
                                    <button class="btn-icon btn-edit" title="Edit" onclick="editSupplier(
                                        <?= $row['supplier_id'] ?>,
                                        <?= json_encode($row['supplier_name']) ?>,
                                        <?= json_encode($row['contact_person'] ?? '') ?>,
                                        <?= json_encode($row['email'] ?? '') ?>,
                                        <?= json_encode($row['phone'] ?? '') ?>,
                                        <?= json_encode($row['address'] ?? '') ?>,
                                        <?= json_encode($row['status']) ?>
                                    )"><i class="fas fa-edit"></i></button>
                                    <form method="POST" style="display:inline" onsubmit="return confirm('Delete this supplier? If linked to items, it will be deactivated instead.')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="supplier_id" value="<?= $row['supplier_id'] ?>">
                                        <button type="submit" class="btn-icon btn-delete" title="Delete"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state"><i class="fas fa-truck-field"></i><h3>No suppliers found</h3><p>Click "Add Supplier" to add your first supplier.</p></div>
                <?php endif; ?>
            </div>

            <!-- Add Modal -->
            <div class="modal-overlay" id="addModal"><div class="modal"><div class="modal-header"><h3><i class="fas fa-plus"></i> Add Supplier</h3><button class="modal-close" onclick="closeModal('addModal')">&times;</button></div>
            <form method="POST"><input type="hidden" name="action" value="add"><div class="modal-body">
                <div class="form-group"><label>Supplier Name <span style="color:#e74c3c;">*</span></label><input type="text" name="supplier_name" class="form-control" required></div>
                <div class="form-group"><label>Contact Person</label><input type="text" name="contact_person" class="form-control"></div>
                <div class="form-group"><label>Email</label><input type="email" name="email" class="form-control"></div>
                <div class="form-group"><label>Phone</label><input type="text" name="phone" class="form-control"></div>
                <div class="form-group"><label>Address</label><textarea name="address" class="form-control" rows="2"></textarea></div>
                <div class="form-group"><label>Status</label><select name="status" class="form-control"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
            </div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button></div></form></div></div>

            <!-- Edit Modal -->
            <div class="modal-overlay" id="editModal"><div class="modal"><div class="modal-header"><h3><i class="fas fa-edit"></i> Edit Supplier</h3><button class="modal-close" onclick="closeModal('editModal')">&times;</button></div>
            <form method="POST"><input type="hidden" name="action" value="edit"><input type="hidden" name="supplier_id" id="edit_supplier_id"><div class="modal-body">
                <div class="form-group"><label>Supplier Name <span style="color:#e74c3c;">*</span></label><input type="text" name="supplier_name" id="edit_supplier_name" class="form-control" required></div>
                <div class="form-group"><label>Contact Person</label><input type="text" name="contact_person" id="edit_contact_person" class="form-control"></div>
                <div class="form-group"><label>Email</label><input type="email" name="email" id="edit_email" class="form-control"></div>
                <div class="form-group"><label>Phone</label><input type="text" name="phone" id="edit_phone" class="form-control"></div>
                <div class="form-group"><label>Address</label><textarea name="address" id="edit_address" class="form-control" rows="2"></textarea></div>
                <div class="form-group"><label>Status</label><select name="status" id="edit_status" class="form-control"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
            </div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update</button></div></form></div></div>

            <!-- View Modal -->
            <div class="modal-overlay" id="viewModal"><div class="modal" style="max-width:550px;"><div class="modal-header" style="background:linear-gradient(135deg,#2c3e50,#34495e);"><h3><i class="fas fa-truck-field"></i> Supplier Details</h3><button class="modal-close" onclick="closeModal('viewModal')">&times;</button></div>
            <div class="modal-body" id="viewModalBody" style="padding:20px;"><p style="color:#888;">Loading...</p></div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('viewModal')">Close</button></div></div></div>

        </div>
    </main>
</div>

<script src="includes/admin.js"></script>
<script>
function openModal(id){document.getElementById(id).classList.add('active');}
function closeModal(id){document.getElementById(id).classList.remove('active');}

function editSupplier(id, name, contact, email, phone, address, status) {
    document.getElementById('edit_supplier_id').value = id;
    document.getElementById('edit_supplier_name').value = name;
    document.getElementById('edit_contact_person').value = contact;
    document.getElementById('edit_email').value = email;
    document.getElementById('edit_phone').value = phone;
    document.getElementById('edit_address').value = address;
    document.getElementById('edit_status').value = status;
    openModal('editModal');
}

const supplierData = {
<?php foreach ($suppliers as $row): ?>
    <?= (int)$row['supplier_id'] ?>: {
        name: <?= json_encode($row['supplier_name']) ?>,
        contact: <?= json_encode($row['contact_person'] ?? 'N/A') ?>,
        email: <?= json_encode($row['email'] ?? 'N/A') ?>,
        phone: <?= json_encode($row['phone'] ?? 'N/A') ?>,
        address: <?= json_encode($row['address'] ?? 'N/A') ?>,
        status: <?= json_encode($row['status']) ?>,
        items: <?= (int)$row['item_count'] ?>,
        totalPurchased: <?= json_encode('₱' . number_format((float)$row['total_purchased'], 2)) ?>,
        since: <?= json_encode(date('M d, Y', strtotime($row['created_at']))) ?>
    },
<?php endforeach; ?>
};

function viewSupplier(id) {
    const s = supplierData[id];
    if (!s) return;
    const sc = s.status === 'active' ? '#27ae60' : '#e74c3c';
    document.getElementById('viewModalBody').innerHTML = `
        <div style="text-align:center;margin-bottom:20px;">
            <div style="width:60px;height:60px;border-radius:50%;background:linear-gradient(135deg,#3498db,#2c3e50);display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:24px;font-weight:700;margin-bottom:10px;">
                ${s.name.charAt(0).toUpperCase()}
            </div>
            <h3 style="margin:0;color:#2c3e50;">${s.name}</h3>
            <span style="display:inline-block;padding:3px 12px;border-radius:20px;font-size:11px;font-weight:600;background:${sc}20;color:${sc};margin-top:5px;">${s.status.charAt(0).toUpperCase() + s.status.slice(1)}</span>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div style="background:#f8f9fa;border-radius:8px;padding:12px;">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#95a5a6;margin-bottom:4px;"><i class="fas fa-user"></i> Contact Person</div>
                <div style="font-weight:600;color:#2c3e50;">${s.contact}</div>
            </div>
            <div style="background:#f8f9fa;border-radius:8px;padding:12px;">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#95a5a6;margin-bottom:4px;"><i class="fas fa-envelope"></i> Email</div>
                <div style="font-weight:600;color:#2c3e50;">${s.email}</div>
            </div>
            <div style="background:#f8f9fa;border-radius:8px;padding:12px;">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#95a5a6;margin-bottom:4px;"><i class="fas fa-phone"></i> Phone</div>
                <div style="font-weight:600;color:#2c3e50;">${s.phone}</div>
            </div>
            <div style="background:#f8f9fa;border-radius:8px;padding:12px;">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#95a5a6;margin-bottom:4px;"><i class="fas fa-calendar"></i> Supplier Since</div>
                <div style="font-weight:600;color:#2c3e50;">${s.since}</div>
            </div>
            <div style="background:#f8f9fa;border-radius:8px;padding:12px;grid-column:1/-1;">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#95a5a6;margin-bottom:4px;"><i class="fas fa-map-marker-alt"></i> Address</div>
                <div style="font-weight:600;color:#2c3e50;">${s.address}</div>
            </div>
            <div style="background:#f8f9fa;border-radius:8px;padding:12px;">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#95a5a6;margin-bottom:4px;"><i class="fas fa-boxes-stacked"></i> Items Supplied</div>
                <div style="font-size:22px;font-weight:700;color:#3498db;">${s.items}</div>
            </div>
            <div style="background:#f8f9fa;border-radius:8px;padding:12px;">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#95a5a6;margin-bottom:4px;"><i class="fas fa-peso-sign"></i> Total Purchased</div>
                <div style="font-size:22px;font-weight:700;color:#27ae60;">${s.totalPurchased}</div>
            </div>
        </div>
    `;
    openModal('viewModal');
}

function searchTable() {
    const q = document.getElementById('searchInput').value.toLowerCase();
    const sf = document.getElementById('statusFilter').value;
    const t = document.getElementById('suppliersTable');
    if (!t) return;
    const rows = t.querySelector('tbody').querySelectorAll('tr');
    rows.forEach(r => {
        const text = r.textContent.toLowerCase();
        const rs = r.getAttribute('data-status');
        r.style.display = (text.includes(q) && (!sf || rs === sf)) ? '' : 'none';
    });
}

document.querySelectorAll('.modal-overlay').forEach(o => { o.addEventListener('click', e => { if (e.target === o) o.classList.remove('active'); }); });
</script>
</body>
</html>
