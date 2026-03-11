<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') { header('Location: ../users/login.php'); exit; }
$page_title = 'Ratings'; $current_page = 'ratings';
require_once __DIR__ . '/../includes/db.php';
$success = $error = '';

// Ensure technician_id and rating_type columns exist
try {
    $cols = $pdo->query("SHOW COLUMNS FROM ratings")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('technician_id', $cols)) {
        $pdo->exec("ALTER TABLE ratings ADD COLUMN technician_id INT(11) DEFAULT NULL AFTER vehicle_id");
    }
    if (!in_array('rating_type', $cols)) {
        $pdo->exec("ALTER TABLE ratings ADD COLUMN rating_type ENUM('service','product') DEFAULT 'service' AFTER technician_id");
    }
} catch (Exception $e) { /* columns may already exist */ }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'delete') {
            $pdo->prepare("DELETE FROM ratings WHERE rating_id = ?")->execute([$_POST['rating_id']]);
            logAudit($pdo, 'Deleted rating', 'ratings', $_POST['rating_id']);
            $success = 'Rating deleted successfully.';
        }
    } catch (Exception $e) { $error = 'Error: ' . $e->getMessage(); }
}

$filter_type = $_GET['type'] ?? '';
$sql = "SELECT r.*, c.full_name AS client_name, v.plate_number, v.make, v.model,
        t.full_name AS technician_name
        FROM ratings r
        LEFT JOIN clients c ON r.client_id = c.client_id
        LEFT JOIN vehicles v ON r.vehicle_id = v.vehicle_id
        LEFT JOIN users t ON r.technician_id = t.user_id";
$params = [];
if ($filter_type) { $sql .= " WHERE r.rating_type = ?"; $params[] = $filter_type; }
$sql .= " ORDER BY r.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$ratings = $stmt->fetchAll();

// Stats
$avg_rating = $pdo->query("SELECT ROUND(AVG(rating_value),1) FROM ratings")->fetchColumn() ?: 0;
$total_ratings = $pdo->query("SELECT COUNT(*) FROM ratings")->fetchColumn();
$five_stars = $pdo->query("SELECT COUNT(*) FROM ratings WHERE rating_value = 5")->fetchColumn();
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

            <!-- Stats Cards -->
            <div class="stats-row" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-bottom:1.5rem;">
                <div class="admin-card" style="text-align:center;padding:1.5rem;">
                    <div style="font-size:2rem;font-weight:700;color:var(--primary-red);"><?= $avg_rating ?></div>
                    <div style="color:var(--text-muted);font-size:0.85rem;">Average Rating</div>
                    <div style="color:var(--star-color, #f5a623);font-size:1.2rem;margin-top:0.25rem;">
                        <?php for ($i = 1; $i <= 5; $i++): ?><i class="fa<?= $i <= round($avg_rating) ? 's' : 'r' ?> fa-star"></i><?php endfor; ?>
                    </div>
                </div>
                <div class="admin-card" style="text-align:center;padding:1.5rem;">
                    <div style="font-size:2rem;font-weight:700;color:var(--primary-red);"><?= $total_ratings ?></div>
                    <div style="color:var(--text-muted);font-size:0.85rem;">Total Ratings</div>
                </div>
                <div class="admin-card" style="text-align:center;padding:1.5rem;">
                    <div style="font-size:2rem;font-weight:700;color:var(--primary-red);"><?= $five_stars ?></div>
                    <div style="color:var(--text-muted);font-size:0.85rem;">5-Star Ratings</div>
                </div>
            </div>

            <div class="page-header">
                <h2><i class="fas fa-star"></i> Customer Ratings</h2>
                <div style="display:flex;gap:0.5rem;">
                    <a href="ratings.php" class="btn <?= !$filter_type ? 'btn-primary' : 'btn-secondary' ?>">All</a>
                    <a href="ratings.php?type=service" class="btn <?= $filter_type === 'service' ? 'btn-primary' : 'btn-secondary' ?>"><i class="fas fa-wrench"></i> Service</a>
                    <a href="ratings.php?type=product" class="btn <?= $filter_type === 'product' ? 'btn-primary' : 'btn-secondary' ?>"><i class="fas fa-box"></i> Product</a>
                </div>
            </div>

            <div class="admin-card">
                <div class="table-toolbar"><div class="search-box"><i class="fas fa-search"></i><input type="text" placeholder="Search ratings..." onkeyup="searchTable(this.value)"></div></div>
                <?php if (empty($ratings)): ?>
                    <div class="empty-state"><i class="fas fa-star"></i><h3>No ratings yet</h3><p>Ratings from customers will appear here.</p></div>
                <?php else: ?>
                <div class="table-responsive">
                <table class="admin-table" id="dataTable"><thead><tr><th>ID</th><th>Customer</th><th>Type</th><th>Vehicle</th><th>Technician</th><th>Rating</th><th>Comment</th><th>Date</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($ratings as $r): ?>
                <tr>
                    <td><?= $r['rating_id'] ?></td>
                    <td><?= htmlspecialchars($r['client_name'] ?? 'N/A') ?></td>
                    <td><span class="badge <?= ($r['rating_type'] ?? 'service') === 'service' ? 'badge-info' : 'badge-warning' ?>"><?= ucfirst($r['rating_type'] ?? 'service') ?></span></td>
                    <td><?php if ($r['plate_number']): ?><?= htmlspecialchars($r['plate_number'] . ' - ' . $r['make'] . ' ' . $r['model']) ?><?php else: ?>N/A<?php endif; ?></td>
                    <td><?= htmlspecialchars($r['technician_name'] ?? 'N/A') ?></td>
                    <td>
                        <div style="color:#f5a623;font-size:0.9rem;">
                            <?php for ($i = 1; $i <= 5; $i++): ?><i class="fa<?= $i <= $r['rating_value'] ? 's' : 'r' ?> fa-star"></i><?php endfor; ?>
                        </div>
                        <small>(<?= $r['rating_value'] ?>/5)</small>
                    </td>
                    <td style="max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($r['comment'] ?? '') ?>"><?= htmlspecialchars($r['comment'] ?? '-') ?></td>
                    <td><?= date('M d, Y', strtotime($r['created_at'])) ?></td>
                    <td>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete this rating?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="rating_id" value="<?= $r['rating_id'] ?>">
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
<script src="includes/admin.js"></script>
<script>
function searchTable(q) {
    q = q.toLowerCase();
    document.querySelectorAll('#dataTable tbody tr').forEach(r => { r.style.display = r.textContent.toLowerCase().includes(q) ? '' : 'none'; });
}
</script>
</body>
</html>
