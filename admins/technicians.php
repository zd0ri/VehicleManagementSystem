<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../users/login.php');
    exit;
}
$page_title = 'Technicians';
$current_page = 'technicians';
require_once __DIR__ . '/../includes/db.php';

// Ensure expertise column exists for skill-based assignment.
try {
    $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('expertise', $cols, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN expertise TEXT NULL AFTER status");
    }
} catch (Exception $e) {
    // Keep page usable even if schema migration fails in restricted environments.
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    try {
        if ($action === 'add') {
            $password_hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $expertise = trim($_POST['expertise'] ?? '');
            $stmt = $pdo->prepare("INSERT INTO users (full_name, email, password_hash, role, status, expertise) VALUES (?, ?, ?, 'technician', ?, ?)");
            $stmt->execute([$_POST['full_name'], $_POST['email'], $password_hash, $_POST['status'], $expertise ?: null]);
            $new_id = $pdo->lastInsertId();
            logAudit($pdo, 'Created technician', 'users', $new_id);
            $success = 'Technician added successfully.';
        }
        if ($action === 'edit') {
            $expertise = trim($_POST['expertise'] ?? '');
            if (!empty($_POST['password'])) {
                $password_hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, password_hash = ?, status = ?, expertise = ? WHERE user_id = ? AND role = 'technician'");
                $stmt->execute([$_POST['full_name'], $_POST['email'], $password_hash, $_POST['status'], $expertise ?: null, $_POST['user_id']]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, status = ?, expertise = ? WHERE user_id = ? AND role = 'technician'");
                $stmt->execute([$_POST['full_name'], $_POST['email'], $_POST['status'], $expertise ?: null, $_POST['user_id']]);
            }
            logAudit($pdo, 'Updated technician', 'users', $_POST['user_id']);
            $success = 'Technician updated successfully.';
        }
        if ($action === 'delete') {
            $pdo->prepare("DELETE FROM users WHERE user_id = ? AND role = 'technician'")->execute([$_POST['user_id']]);
            logAudit($pdo, 'Deleted technician', 'users', $_POST['user_id']);
            $success = 'Technician deleted successfully.';
        }
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

$technicians = $pdo->query("SELECT u.*, 
    COALESCE((SELECT ROUND(AVG(r.rating_value), 1) FROM ratings r WHERE r.technician_id = u.user_id), 0) AS avg_rating,
    COALESCE((SELECT COUNT(*) FROM assignments a WHERE a.technician_id = u.user_id AND a.status = 'Finished'), 0) AS finished_jobs
    FROM users u
    WHERE u.role = 'technician'
    ORDER BY u.created_at DESC")->fetchAll();

function incentiveRateByRating(float $avgRating): float {
    if ($avgRating >= 4.8) return 0.20;
    if ($avgRating >= 4.5) return 0.15;
    if ($avgRating >= 4.0) return 0.10;
    if ($avgRating >= 3.5) return 0.05;
    return 0.00;
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
                <h2><i class="fas fa-tools"></i> Technicians</h2>
                <button class="btn btn-primary" onclick="openModal('addModal')"><i class="fas fa-plus"></i> Add New</button>
            </div>

            <div class="admin-card">
                <div class="table-toolbar">
                    <div class="search-box"><i class="fas fa-search"></i>
                        <input type="text" id="searchInput" placeholder="Search technicians..." onkeyup="searchTable()">
                    </div>
                </div>
                <?php if (count($technicians) > 0): ?>
                <div class="table-responsive">
                    <table class="admin-table" id="techniciansTable">
                        <thead><tr><th>ID</th><th>Full Name</th><th>Email</th><th>Expertise</th><th>Rating</th><th>Finished Jobs</th><th>Incentive</th><th>Status</th><th>Created At</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($technicians as $row): ?>
                        <?php
                            $avg = (float)($row['avg_rating'] ?? 0);
                            $finishedJobs = (int)($row['finished_jobs'] ?? 0);
                            $rate = incentiveRateByRating($avg);
                            $incentive = $finishedJobs * 150 * $rate;
                        ?>
                        <tr>
                            <td><?= $row['user_id'] ?></td>
                            <td><?= htmlspecialchars($row['full_name']) ?></td>
                            <td><?= htmlspecialchars($row['email']) ?></td>
                            <td><?= htmlspecialchars($row['expertise'] ?: 'General') ?></td>
                            <td><?= number_format($avg, 1) ?>/5</td>
                            <td><?= $finishedJobs ?></td>
                            <td>₱<?= number_format($incentive, 2) ?></td>
                            <td><span class="badge badge-<?= $row['status'] === 'active' ? 'active' : 'inactive' ?>"><?= ucfirst($row['status']) ?></span></td>
                            <td><?= date('M d, Y', strtotime($row['created_at'])) ?></td>
                            <td class="action-btns">
                                <button class="btn-icon btn-edit" onclick="editTech(<?= $row['user_id'] ?>, '<?= htmlspecialchars(addslashes($row['full_name']), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($row['email']), ENT_QUOTES) ?>', '<?= $row['status'] ?>', '<?= htmlspecialchars(addslashes($row['expertise'] ?? ''), ENT_QUOTES) ?>')"><i class="fas fa-edit"></i></button>
                                <form method="POST" style="display:inline" onsubmit="return confirm('Delete this technician?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="user_id" value="<?= $row['user_id'] ?>">
                                    <button type="submit" class="btn-icon btn-delete"><i class="fas fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state"><i class="fas fa-tools"></i><h3>No technicians found</h3><p>Click "Add New" to create a technician.</p></div>
                <?php endif; ?>
            </div>

            <!-- Add Modal -->
            <div class="modal-overlay" id="addModal"><div class="modal"><div class="modal-header"><h3>Add Technician</h3><button class="modal-close" onclick="closeModal('addModal')">&times;</button></div>
            <form method="POST"><input type="hidden" name="action" value="add"><div class="modal-body">
                <div class="form-group"><label>Full Name</label><input type="text" name="full_name" class="form-control" required></div>
                <div class="form-group"><label>Email</label><input type="email" name="email" class="form-control" required></div>
                <div class="form-group"><label>Expertise (comma-separated)</label><input type="text" name="expertise" class="form-control" placeholder="Engine Repair, Brake Service, Tire Service"></div>
                <div class="form-group"><label>Password</label><input type="password" name="password" class="form-control" required></div>
                <div class="form-group"><label>Status</label><select name="status" class="form-control"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
            </div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button></div></form></div></div>

            <!-- Edit Modal -->
            <div class="modal-overlay" id="editModal"><div class="modal"><div class="modal-header"><h3>Edit Technician</h3><button class="modal-close" onclick="closeModal('editModal')">&times;</button></div>
            <form method="POST"><input type="hidden" name="action" value="edit"><input type="hidden" name="user_id" id="edit_user_id"><div class="modal-body">
                <div class="form-group"><label>Full Name</label><input type="text" name="full_name" id="edit_full_name" class="form-control" required></div>
                <div class="form-group"><label>Email</label><input type="email" name="email" id="edit_email" class="form-control" required></div>
                <div class="form-group"><label>Expertise (comma-separated)</label><input type="text" name="expertise" id="edit_expertise" class="form-control" placeholder="Engine Repair, Brake Service, Tire Service"></div>
                <div class="form-group"><label>Password</label><input type="password" name="password" id="edit_password" class="form-control" placeholder="Leave blank to keep current"></div>
                <div class="form-group"><label>Status</label><select name="status" id="edit_status" class="form-control"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
            </div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update</button></div></form></div></div>

        </div>
    </main>
</div>
<script src="includes/admin.js"></script>
<script>
function openModal(id){document.getElementById(id).classList.add('active');}
function closeModal(id){document.getElementById(id).classList.remove('active');}
function editTech(id,name,email,status,expertise){
    document.getElementById('edit_user_id').value=id;
    document.getElementById('edit_full_name').value=name;
    document.getElementById('edit_email').value=email;
    document.getElementById('edit_expertise').value=expertise;
    document.getElementById('edit_password').value='';
    document.getElementById('edit_status').value=status;
    openModal('editModal');
}
function searchTable(){
    var q=document.getElementById('searchInput').value.toLowerCase();
    var t=document.getElementById('techniciansTable');if(!t)return;
    var rows=t.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
    for(var i=0;i<rows.length;i++){rows[i].style.display=rows[i].textContent.toLowerCase().indexOf(q)>-1?'':'none';}
}
document.querySelectorAll('.modal-overlay').forEach(function(o){o.addEventListener('click',function(e){if(e.target===o)o.classList.remove('active');});});
</script>
</body>
</html>
