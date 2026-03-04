<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../users/login.php');
    exit;
}
$page_title = 'Services';
$current_page = 'services';
require_once __DIR__ . '/../includes/db.php';

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    try {
        if ($action === 'add') {
            $stmt = $pdo->prepare("INSERT INTO services (service_name, description, base_price, estimated_duration) VALUES (?, ?, ?, ?)");
            $stmt->execute([$_POST['service_name'], $_POST['description'], $_POST['base_price'], $_POST['estimated_duration'] ?: null]);
            $success = 'Service added successfully.';
        }
        if ($action === 'edit') {
            $stmt = $pdo->prepare("UPDATE services SET service_name = ?, description = ?, base_price = ?, estimated_duration = ? WHERE service_id = ?");
            $stmt->execute([$_POST['service_name'], $_POST['description'], $_POST['base_price'], $_POST['estimated_duration'] ?: null, $_POST['service_id']]);
            $success = 'Service updated successfully.';
        }
        if ($action === 'delete') {
            $pdo->prepare("DELETE FROM services WHERE service_id = ?")->execute([$_POST['service_id']]);
            $success = 'Service deleted successfully.';
        }
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

$services = $pdo->query("SELECT * FROM services ORDER BY service_name")->fetchAll();
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
                <h2><i class="fas fa-wrench"></i> Services</h2>
                <button class="btn btn-primary" onclick="openModal('addModal')"><i class="fas fa-plus"></i> Add New</button>
            </div>

            <div class="admin-card">
                <div class="table-toolbar">
                    <div class="search-box"><i class="fas fa-search"></i>
                        <input type="text" id="searchInput" placeholder="Search services..." onkeyup="searchTable()">
                    </div>
                </div>
                <?php if (count($services) > 0): ?>
                <div class="table-responsive">
                    <table class="admin-table" id="servicesTable">
                        <thead><tr><th>ID</th><th>Service Name</th><th>Description</th><th>Base Price</th><th>Duration</th><th>Created At</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($services as $row): ?>
                        <tr>
                            <td><?= $row['service_id'] ?></td>
                            <td><?= htmlspecialchars($row['service_name']) ?></td>
                            <td><?= htmlspecialchars(mb_strimwidth($row['description'] ?? '', 0, 50, '...')) ?></td>
                            <td>₱<?= number_format((float)$row['base_price'], 2) ?></td>
                            <td><?= $row['estimated_duration'] ? $row['estimated_duration'] . ' mins' : '—' ?></td>
                            <td><?= date('M d, Y', strtotime($row['created_at'])) ?></td>
                            <td class="action-btns">
                                <button class="btn-icon btn-edit" onclick="editService(<?= $row['service_id'] ?>, '<?= htmlspecialchars(addslashes($row['service_name']), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($row['description'] ?? ''), ENT_QUOTES) ?>', '<?= $row['base_price'] ?>', '<?= $row['estimated_duration'] ?? '' ?>')"><i class="fas fa-edit"></i></button>
                                <form method="POST" style="display:inline" onsubmit="return confirm('Delete this service?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="service_id" value="<?= $row['service_id'] ?>">
                                    <button type="submit" class="btn-icon btn-delete"><i class="fas fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state"><i class="fas fa-wrench"></i><h3>No services found</h3><p>Click "Add New" to create a service.</p></div>
                <?php endif; ?>
            </div>

            <!-- Add Modal -->
            <div class="modal-overlay" id="addModal"><div class="modal"><div class="modal-header"><h3>Add Service</h3><button class="modal-close" onclick="closeModal('addModal')">&times;</button></div>
            <form method="POST"><input type="hidden" name="action" value="add"><div class="modal-body">
                <div class="form-group"><label>Service Name</label><input type="text" name="service_name" class="form-control" required></div>
                <div class="form-group"><label>Description</label><textarea name="description" class="form-control" rows="3"></textarea></div>
                <div class="form-group"><label>Base Price (₱)</label><input type="number" name="base_price" class="form-control" step="0.01" min="0" required></div>
                <div class="form-group"><label>Estimated Duration (minutes)</label><input type="number" name="estimated_duration" class="form-control" min="1"></div>
            </div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button></div></form></div></div>

            <!-- Edit Modal -->
            <div class="modal-overlay" id="editModal"><div class="modal"><div class="modal-header"><h3>Edit Service</h3><button class="modal-close" onclick="closeModal('editModal')">&times;</button></div>
            <form method="POST"><input type="hidden" name="action" value="edit"><input type="hidden" name="service_id" id="edit_service_id"><div class="modal-body">
                <div class="form-group"><label>Service Name</label><input type="text" name="service_name" id="edit_service_name" class="form-control" required></div>
                <div class="form-group"><label>Description</label><textarea name="description" id="edit_description" class="form-control" rows="3"></textarea></div>
                <div class="form-group"><label>Base Price (₱)</label><input type="number" name="base_price" id="edit_base_price" class="form-control" step="0.01" min="0" required></div>
                <div class="form-group"><label>Estimated Duration (minutes)</label><input type="number" name="estimated_duration" id="edit_estimated_duration" class="form-control" min="1"></div>
            </div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update</button></div></form></div></div>

        </div>
    </main>
</div>
<script src="includes/admin.js"></script>
<script>
function openModal(id){document.getElementById(id).classList.add('active');}
function closeModal(id){document.getElementById(id).classList.remove('active');}
function editService(id,name,desc,price,dur){
    document.getElementById('edit_service_id').value=id;
    document.getElementById('edit_service_name').value=name;
    document.getElementById('edit_description').value=desc;
    document.getElementById('edit_base_price').value=price;
    document.getElementById('edit_estimated_duration').value=dur;
    openModal('editModal');
}
function searchTable(){
    var q=document.getElementById('searchInput').value.toLowerCase();
    var t=document.getElementById('servicesTable');if(!t)return;
    var rows=t.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
    for(var i=0;i<rows.length;i++){rows[i].style.display=rows[i].textContent.toLowerCase().indexOf(q)>-1?'':'none';}
}
document.querySelectorAll('.modal-overlay').forEach(function(o){o.addEventListener('click',function(e){if(e.target===o)o.classList.remove('active');});});
</script>
</body>
</html>
