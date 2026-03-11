<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../users/login.php');
    exit;
}

$page_title = 'Inventory';
$current_page = 'inventory';

require_once __DIR__ . '/../includes/db.php';

$success = '';
$error = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // ADD
    if ($action === 'add') {
        try {
            $image = null;
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                if (in_array($ext, $allowed)) {
                    $image = uniqid('item_') . '.' . $ext;
                    move_uploaded_file($_FILES['image']['tmp_name'], __DIR__ . '/../uploads/' . $image);
                } else {
                    $error = 'Invalid image format. Allowed: jpg, jpeg, png, gif, webp.';
                }
            }

            if (!$error) {
                $supplierId = (int)($_POST['supplier_id'] ?? 0) ?: null;
                $supplierName = null;
                if ($supplierId) {
                    $sn = $pdo->prepare("SELECT supplier_name FROM suppliers WHERE supplier_id = ?");
                    $sn->execute([$supplierId]);
                    $supplierName = $sn->fetchColumn() ?: null;
                }
                $expiryDate = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
                $stmt = $pdo->prepare("INSERT INTO inventory (item_name, description, category, image, sku, quantity, unit_price, expiry_date, supplier, supplier_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $_POST['item_name'],
                    $_POST['description'] ?: null,
                    $_POST['category'] ?: null,
                    $image,
                    $_POST['sku'] ?: null,
                    (int)$_POST['quantity'],
                    (float)$_POST['unit_price'],
                    $expiryDate,
                    $supplierName,
                    $supplierId
                ]);
                $new_id = $pdo->lastInsertId();
                logAudit($pdo, 'Created inventory item', 'inventory', $new_id);
                $success = 'Item added successfully.';
            }
        } catch (Exception $e) {
            $error = 'Failed to add item: ' . $e->getMessage();
        }
    }

    // EDIT
    if ($action === 'edit') {
        try {
            $image = $_POST['existing_image'] ?? null;
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                if (in_array($ext, $allowed)) {
                    $newImage = uniqid('item_') . '.' . $ext;
                    move_uploaded_file($_FILES['image']['tmp_name'], __DIR__ . '/../uploads/' . $newImage);
                    // Remove old image if exists
                    if ($image && file_exists(__DIR__ . '/../uploads/' . $image)) {
                        unlink(__DIR__ . '/../uploads/' . $image);
                    }
                    $image = $newImage;
                } else {
                    $error = 'Invalid image format. Allowed: jpg, jpeg, png, gif, webp.';
                }
            }

            if (!$error) {
                $supplierId = (int)($_POST['supplier_id'] ?? 0) ?: null;
                $supplierName = null;
                if ($supplierId) {
                    $sn = $pdo->prepare("SELECT supplier_name FROM suppliers WHERE supplier_id = ?");
                    $sn->execute([$supplierId]);
                    $supplierName = $sn->fetchColumn() ?: null;
                }
                $expiryDate = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
                $stmt = $pdo->prepare("UPDATE inventory SET item_name = ?, description = ?, category = ?, image = ?, sku = ?, quantity = ?, unit_price = ?, expiry_date = ?, supplier = ?, supplier_id = ? WHERE item_id = ?");
                $stmt->execute([
                    $_POST['item_name'],
                    $_POST['description'] ?: null,
                    $_POST['category'] ?: null,
                    $image,
                    $_POST['sku'] ?: null,
                    (int)$_POST['quantity'],
                    (float)$_POST['unit_price'],
                    $expiryDate,
                    $supplierName,
                    $supplierId,
                    (int)$_POST['item_id']
                ]);
                logAudit($pdo, 'Updated inventory item', 'inventory', (int)$_POST['item_id']);
                $success = 'Item updated successfully.';
            }
        } catch (Exception $e) {
            $error = 'Failed to update item: ' . $e->getMessage();
        }
    }

    // DELETE
    if ($action === 'delete') {
        try {
            // Remove image file if exists
            $stmt = $pdo->prepare("SELECT image FROM inventory WHERE item_id = ?");
            $stmt->execute([(int)$_POST['item_id']]);
            $item = $stmt->fetch();
            if ($item && $item['image'] && file_exists(__DIR__ . '/../uploads/' . $item['image'])) {
                unlink(__DIR__ . '/../uploads/' . $item['image']);
            }

            $stmt = $pdo->prepare("DELETE FROM inventory WHERE item_id = ?");
            $stmt->execute([(int)$_POST['item_id']]);
            logAudit($pdo, 'Deleted inventory item', 'inventory', (int)$_POST['item_id']);
            $success = 'Item deleted successfully.';
        } catch (Exception $e) {
            $error = 'Failed to delete item: ' . $e->getMessage();
        }
    }

    // RESTOCK
    if ($action === 'restock') {
        try {
            $qty = (int)$_POST['restock_quantity'];
            if ($qty <= 0) {
                $error = 'Restock quantity must be greater than zero.';
            } else {
                $stmt = $pdo->prepare("UPDATE inventory SET quantity = quantity + ? WHERE item_id = ?");
                $stmt->execute([$qty, (int)$_POST['item_id']]);
                logAudit($pdo, 'Restocked inventory item', 'inventory', (int)$_POST['item_id']);
                $success = 'Item restocked successfully.';
            }
        } catch (Exception $e) {
            $error = 'Failed to restock item: ' . $e->getMessage();
        }
    }
}

// Fetch all inventory items with supplier info
$items = [];
try {
    $result = $pdo->query("SELECT i.*, s.supplier_name AS linked_supplier_name FROM inventory i LEFT JOIN suppliers s ON i.supplier_id = s.supplier_id ORDER BY i.item_name");
    $items = $result->fetchAll();
} catch (Exception $e) {
    $error = 'Failed to fetch inventory: ' . $e->getMessage();
}

// Fetch active suppliers for dropdown
$suppliersList = $pdo->query("SELECT supplier_id, supplier_name FROM suppliers WHERE status = 'active' ORDER BY supplier_name")->fetchAll();

// Count low stock items (quantity <= 5)
$lowStockCount = 0;
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM inventory WHERE quantity <= 5");
    $lowStockCount = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    // silently ignore
}
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
                <h2><i class="fas fa-boxes-stacked"></i> Inventory</h2>
                <button class="btn btn-primary" onclick="openModal('addModal')">
                    <i class="fas fa-plus"></i> Add Item
                </button>
            </div>

            <!-- Low stock warning -->
            <?php if ($lowStockCount > 0): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Low Stock Alert:</strong> <?= $lowStockCount ?> item<?= $lowStockCount > 1 ? 's are' : ' is' ?> running low (quantity &le; 5).
                </div>
            <?php endif; ?>

            <!-- Admin card with table -->
            <div class="admin-card">
                <div class="table-toolbar">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="searchInput" placeholder="Search inventory..." onkeyup="searchTable()">
                    </div>
                </div>

                <?php if (count($items) > 0): ?>
                <div class="table-responsive">
                    <table class="admin-table" id="inventoryTable">
                        <thead>
                            <tr>
                                <th>Image</th>
                                <th>Item Name</th>
                                <th>SKU</th>
                                <th>Category</th>
                                <th>Qty</th>
                                <th>Unit Price</th>
                                <th>Expiry Date</th>
                                <th>Supplier</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $row): ?>
                            <tr>
                                <td>
                                    <?php if ($row['image'] && file_exists(__DIR__ . '/../uploads/' . $row['image'])): ?>
                                        <img src="../uploads/<?= htmlspecialchars($row['image']) ?>" alt="Item" style="width:40px;height:40px;object-fit:cover;border-radius:4px;">
                                    <?php else: ?>
                                        <div style="width:40px;height:40px;background:#e9ecef;border-radius:4px;display:flex;align-items:center;justify-content:center;">
                                            <i class="fas fa-image" style="color:#adb5bd;"></i>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($row['item_name']) ?></td>
                                <td><?= htmlspecialchars($row['sku'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($row['category'] ?? '—') ?></td>
                                <td>
                                    <span style="<?= (int)$row['quantity'] <= 5 ? 'color:#e74c3c;font-weight:700;' : '' ?>">
                                        <?= (int)$row['quantity'] ?>
                                    </span>
                                </td>
                                <td>₱<?= number_format((float)$row['unit_price'], 2) ?></td>
                                <td>
                                    <?php if ($row['expiry_date']): ?>
                                        <?php
                                        $expDate = new DateTime($row['expiry_date']);
                                        $today = new DateTime();
                                        $diff = $today->diff($expDate);
                                        $daysLeft = (int)$expDate->format('U') - (int)$today->format('U');
                                        $daysLeft = (int)floor($daysLeft / 86400);
                                        if ($daysLeft < 0): ?>
                                            <span style="color:#e74c3c;font-weight:700;"><i class="fas fa-exclamation-circle"></i> Expired</span>
                                            <br><small style="color:#e74c3c;"><?= $expDate->format('M d, Y') ?></small>
                                        <?php elseif ($daysLeft <= 30): ?>
                                            <span style="color:#f39c12;font-weight:600;"><i class="fas fa-clock"></i> <?= $daysLeft ?> day<?= $daysLeft !== 1 ? 's' : '' ?></span>
                                            <br><small style="color:#f39c12;"><?= $expDate->format('M d, Y') ?></small>
                                        <?php else: ?>
                                            <span style="color:#27ae60;"><?= $expDate->format('M d, Y') ?></span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color:#bdc3c7;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($row['linked_supplier_name']): ?>
                                        <a href="suppliers.php" style="color:#3498db;text-decoration:none;font-weight:500;"><?= htmlspecialchars($row['linked_supplier_name']) ?></a>
                                    <?php elseif ($row['supplier']): ?>
                                        <?= htmlspecialchars($row['supplier']) ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td class="action-btns">
                                    <button class="btn-icon btn-edit" title="Edit" onclick="editItem(
                                        <?= $row['item_id'] ?>,
                                        '<?= htmlspecialchars(addslashes($row['item_name']), ENT_QUOTES) ?>',
                                        '<?= htmlspecialchars(addslashes($row['description'] ?? ''), ENT_QUOTES) ?>',
                                        '<?= htmlspecialchars(addslashes($row['category'] ?? ''), ENT_QUOTES) ?>',
                                        '<?= htmlspecialchars(addslashes($row['image'] ?? ''), ENT_QUOTES) ?>',
                                        '<?= htmlspecialchars(addslashes($row['sku'] ?? ''), ENT_QUOTES) ?>',
                                        <?= (int)$row['quantity'] ?>,
                                        '<?= htmlspecialchars($row['unit_price'], ENT_QUOTES) ?>',
                                        '<?= htmlspecialchars(addslashes($row['supplier'] ?? ''), ENT_QUOTES) ?>',
                                        <?= (int)($row['supplier_id'] ?? 0) ?>,
                                        '<?= htmlspecialchars($row['expiry_date'] ?? '', ENT_QUOTES) ?>'
                                    )"><i class="fas fa-edit"></i></button>
                                    <button class="btn-icon btn-view" title="Restock" onclick="restockItem(<?= $row['item_id'] ?>, '<?= htmlspecialchars(addslashes($row['item_name']), ENT_QUOTES) ?>')">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                    <form method="POST" style="display:inline" onsubmit="return confirm('Delete this item?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="item_id" value="<?= $row['item_id'] ?>">
                                        <button type="submit" class="btn-icon btn-delete" title="Delete"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-boxes-stacked"></i>
                    <h3>No inventory items found</h3>
                    <p>Click "Add Item" to create your first inventory entry.</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Add Modal -->
            <div class="modal-overlay" id="addModal">
                <div class="modal">
                    <div class="modal-header">
                        <h3>Add Item</h3>
                        <button class="modal-close" onclick="closeModal('addModal')">&times;</button>
                    </div>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="add">
                        <div class="modal-body">
                            <div class="form-group">
                                <label>Item Name</label>
                                <input type="text" name="item_name" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label>Description</label>
                                <textarea name="description" class="form-control" rows="3"></textarea>
                            </div>
                            <div class="form-group">
                                <label>Category</label>
                                <input type="text" name="category" class="form-control">
                            </div>
                            <div class="form-group">
                                <label>Image</label>
                                <input type="file" name="image" class="form-control" accept="image/*">
                            </div>
                            <div class="form-group">
                                <label>SKU</label>
                                <input type="text" name="sku" class="form-control">
                            </div>
                            <div class="form-group">
                                <label>Quantity</label>
                                <input type="number" name="quantity" class="form-control" min="0" value="0" required>
                            </div>
                            <div class="form-group">
                                <label>Unit Price (₱)</label>
                                <input type="number" name="unit_price" class="form-control" step="0.01" min="0" required>
                            </div>
                            <div class="form-group">
                                <label>Expiry Date <small style="color:#888;">(for oils, fluids, consumables)</small></label>
                                <input type="date" name="expiry_date" class="form-control">
                            </div>
                            <div class="form-group">
                                <label>Supplier</label>
                                <select name="supplier_id" class="form-control">
                                    <option value="">-- No Supplier --</option>
                                    <?php foreach ($suppliersList as $sup): ?>
                                    <option value="<?= $sup['supplier_id'] ?>"><?= htmlspecialchars($sup['supplier_name']) ?></option>
                                    <?php endforeach; ?>
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
                        <h3>Edit Item</h3>
                        <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
                    </div>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="item_id" id="edit_item_id">
                        <input type="hidden" name="existing_image" id="edit_existing_image">
                        <div class="modal-body">
                            <div class="form-group">
                                <label>Item Name</label>
                                <input type="text" name="item_name" id="edit_item_name" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label>Description</label>
                                <textarea name="description" id="edit_description" class="form-control" rows="3"></textarea>
                            </div>
                            <div class="form-group">
                                <label>Category</label>
                                <input type="text" name="category" id="edit_category" class="form-control">
                            </div>
                            <div class="form-group">
                                <label>Current Image</label>
                                <div id="edit_image_preview" style="margin-bottom:8px;"></div>
                                <label>Replace Image</label>
                                <input type="file" name="image" class="form-control" accept="image/*">
                            </div>
                            <div class="form-group">
                                <label>SKU</label>
                                <input type="text" name="sku" id="edit_sku" class="form-control">
                            </div>
                            <div class="form-group">
                                <label>Quantity</label>
                                <input type="number" name="quantity" id="edit_quantity" class="form-control" min="0" required>
                            </div>
                            <div class="form-group">
                                <label>Unit Price (₱)</label>
                                <input type="number" name="unit_price" id="edit_unit_price" class="form-control" step="0.01" min="0" required>
                            </div>
                            <div class="form-group">
                                <label>Expiry Date <small style="color:#888;">(for oils, fluids, consumables)</small></label>
                                <input type="date" name="expiry_date" id="edit_expiry_date" class="form-control">
                            </div>
                            <div class="form-group">
                                <label>Supplier</label>
                                <select name="supplier_id" id="edit_supplier_id" class="form-control">
                                    <option value="">-- No Supplier --</option>
                                    <?php foreach ($suppliersList as $sup): ?>
                                    <option value="<?= $sup['supplier_id'] ?>"><?= htmlspecialchars($sup['supplier_name']) ?></option>
                                    <?php endforeach; ?>
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

            <!-- Restock Modal -->
            <div class="modal-overlay" id="restockModal">
                <div class="modal">
                    <div class="modal-header">
                        <h3>Restock Item</h3>
                        <button class="modal-close" onclick="closeModal('restockModal')">&times;</button>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="restock">
                        <input type="hidden" name="item_id" id="restock_item_id">
                        <div class="modal-body">
                            <p>Restocking: <strong id="restock_item_name"></strong></p>
                            <div class="form-group">
                                <label>Quantity to Add</label>
                                <input type="number" name="restock_quantity" class="form-control" min="1" value="1" required>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="closeModal('restockModal')">Cancel</button>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Restock</button>
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

function editItem(itemId, itemName, description, category, image, sku, quantity, unitPrice, supplier, supplierId, expiryDate) {
    document.getElementById('edit_item_id').value = itemId;
    document.getElementById('edit_item_name').value = itemName;
    document.getElementById('edit_description').value = description;
    document.getElementById('edit_category').value = category;
    document.getElementById('edit_existing_image').value = image;
    document.getElementById('edit_sku').value = sku;
    document.getElementById('edit_quantity').value = quantity;
    document.getElementById('edit_unit_price').value = unitPrice;
    document.getElementById('edit_supplier_id').value = supplierId || '';
    document.getElementById('edit_expiry_date').value = expiryDate || '';

    // Image preview
    var previewDiv = document.getElementById('edit_image_preview');
    if (image) {
        previewDiv.innerHTML = '<img src="../uploads/' + image + '" alt="Current" style="width:60px;height:60px;object-fit:cover;border-radius:4px;">';
    } else {
        previewDiv.innerHTML = '<span style="color:#999;">No image uploaded</span>';
    }

    openModal('editModal');
}

function restockItem(itemId, itemName) {
    document.getElementById('restock_item_id').value = itemId;
    document.getElementById('restock_item_name').textContent = itemName;
    openModal('restockModal');
}

function searchTable() {
    var input = document.getElementById('searchInput').value.toLowerCase();
    var table = document.getElementById('inventoryTable');
    if (!table) return;
    var rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
    for (var i = 0; i < rows.length; i++) {
        var text = rows[i].textContent.toLowerCase();
        rows[i].style.display = text.indexOf(input) > -1 ? '' : 'none';
    }
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
