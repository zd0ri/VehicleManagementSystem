<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../users/login.php');
    exit;
}
$page_title = 'Clients';
$current_page = 'clients';
require_once __DIR__ . '/../includes/db.php';

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    try {
        if ($action === 'add') {
            $stmt = $pdo->prepare("INSERT INTO clients (full_name, phone, email, address) VALUES (?, ?, ?, ?)");
            $stmt->execute([$_POST['full_name'], $_POST['phone'], $_POST['email'], $_POST['address']]);
            $success = 'Client added successfully.';
        }
        if ($action === 'edit') {
            $stmt = $pdo->prepare("UPDATE clients SET full_name = ?, phone = ?, email = ?, address = ? WHERE client_id = ?");
            $stmt->execute([$_POST['full_name'], $_POST['phone'], $_POST['email'], $_POST['address'], $_POST['client_id']]);
            $success = 'Client updated successfully.';
        }
        if ($action === 'delete') {
            $pdo->prepare("DELETE FROM clients WHERE client_id = ?")->execute([$_POST['client_id']]);
            $success = 'Client deleted successfully.';
        }
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

$clients = $pdo->query("SELECT c.*, u.email as user_email FROM clients c LEFT JOIN users u ON c.user_id = u.user_id ORDER BY c.created_at DESC")->fetchAll();
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
                <h2><i class="fas fa-users"></i> Clients</h2>
                <button class="btn btn-primary" onclick="openModal('addModal')"><i class="fas fa-plus"></i> Add New</button>
            </div>

            <div class="admin-card">
                <div class="table-toolbar">
                    <div class="search-box"><i class="fas fa-search"></i>
                        <input type="text" id="searchInput" placeholder="Search clients..." onkeyup="searchTable()">
                    </div>
                </div>
                <?php if (count($clients) > 0): ?>
                <div class="table-responsive">
                    <table class="admin-table" id="clientsTable">
                        <thead><tr><th>ID</th><th>Full Name</th><th>Phone</th><th>Email</th><th>Address</th><th>Registered</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($clients as $row): ?>
                        <tr>
                            <td><?= $row['client_id'] ?></td>
                            <td><?= htmlspecialchars($row['full_name']) ?></td>
                            <td><?= htmlspecialchars($row['phone']) ?></td>
                            <td><?= htmlspecialchars($row['email'] ?? $row['user_email'] ?? '') ?></td>
                            <td><?= htmlspecialchars(mb_strimwidth($row['address'] ?? '', 0, 40, '...')) ?></td>
                            <td><?= date('M d, Y', strtotime($row['created_at'])) ?></td>
                            <td class="action-btns">
                                <button class="btn-icon btn-edit" onclick="editClient(<?= $row['client_id'] ?>, '<?= htmlspecialchars(addslashes($row['full_name']), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($row['phone']), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($row['email'] ?? ''), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($row['address'] ?? ''), ENT_QUOTES) ?>')"><i class="fas fa-edit"></i></button>
                                <form method="POST" style="display:inline" onsubmit="return confirm('Delete this client?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="client_id" value="<?= $row['client_id'] ?>">
                                    <button type="submit" class="btn-icon btn-delete"><i class="fas fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state"><i class="fas fa-users"></i><h3>No clients found</h3><p>Clients will appear here when users register.</p></div>
                <?php endif; ?>
            </div>

            <!-- Add Modal -->
            <div class="modal-overlay" id="addModal"><div class="modal"><div class="modal-header"><h3>Add Client</h3><button class="modal-close" onclick="closeModal('addModal')">&times;</button></div>
            <form method="POST"><input type="hidden" name="action" value="add"><div class="modal-body">
                <div class="form-group"><label>Full Name</label><input type="text" name="full_name" class="form-control" required></div>
                <div class="form-group"><label>Phone</label><input type="text" name="phone" class="form-control" required></div>
                <div class="form-group"><label>Email</label><input type="email" name="email" class="form-control"></div>
                <div class="form-group"><label>Address</label><textarea name="address" class="form-control" rows="3"></textarea></div>
            </div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button></div></form></div></div>

            <!-- Edit Modal -->
            <div class="modal-overlay" id="editModal"><div class="modal"><div class="modal-header"><h3>Edit Client</h3><button class="modal-close" onclick="closeModal('editModal')">&times;</button></div>
            <form method="POST"><input type="hidden" name="action" value="edit"><input type="hidden" name="client_id" id="edit_client_id"><div class="modal-body">
                <div class="form-group"><label>Full Name</label><input type="text" name="full_name" id="edit_full_name" class="form-control" required></div>
                <div class="form-group"><label>Phone</label><input type="text" name="phone" id="edit_phone" class="form-control" required></div>
                <div class="form-group"><label>Email</label><input type="email" name="email" id="edit_email" class="form-control"></div>
                <div class="form-group"><label>Address</label><textarea name="address" id="edit_address" class="form-control" rows="3"></textarea></div>
            </div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update</button></div></form></div></div>

        </div>
    </main>
</div>
<script src="includes/admin.js"></script>
<script>
function openModal(id){document.getElementById(id).classList.add('active');}
function closeModal(id){document.getElementById(id).classList.remove('active');}
function editClient(id,name,phone,email,address){
    document.getElementById('edit_client_id').value=id;
    document.getElementById('edit_full_name').value=name;
    document.getElementById('edit_phone').value=phone;
    document.getElementById('edit_email').value=email;
    document.getElementById('edit_address').value=address;
    openModal('editModal');
}
function searchTable(){
    var q=document.getElementById('searchInput').value.toLowerCase();
    var t=document.getElementById('clientsTable');if(!t)return;
    var rows=t.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
    for(var i=0;i<rows.length;i++){rows[i].style.display=rows[i].textContent.toLowerCase().indexOf(q)>-1?'':'none';}
}
document.querySelectorAll('.modal-overlay').forEach(function(o){o.addEventListener('click',function(e){if(e.target===o)o.classList.remove('active');});});
</script>
</body>
</html>
