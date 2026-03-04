<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../users/login.php');
    exit;
}

$page_title = 'Ratings';
$current_page = 'ratings';

require_once __DIR__ . '/../includes/db.php';

$success = '';
$error = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'delete') {
        try {
            $stmt = $conn->prepare("DELETE FROM ratings WHERE rating_id = ?");
            $stmt->bind_param("i", $_POST['rating_id']);
            $stmt->execute();
            $success = 'Rating deleted successfully.';
        } catch (Exception $e) {
            $error = 'Failed to delete rating: ' . $e->getMessage();
        }
    }
}

// Fetch all ratings
$ratings = [];
try {
    $result = $conn->query("SELECT r.*, c.full_name as client_name, v.plate_number, v.make, v.model FROM ratings r LEFT JOIN clients c ON r.client_id = c.client_id LEFT JOIN vehicles v ON r.vehicle_id = v.vehicle_id ORDER BY r.created_at DESC");
    while ($row = $result->fetch_assoc()) {
        $ratings[] = $row;
    }
} catch (Exception $e) {
    $error = 'Failed to fetch ratings: ' . $e->getMessage();
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
                <h2><i class="fas fa-star"></i> Ratings</h2>
            </div>

            <!-- Admin card with table -->
            <div class="admin-card">
                <div class="table-toolbar">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="searchInput" placeholder="Search ratings..." onkeyup="searchTable()">
                    </div>
                </div>

                <?php if (count($ratings) > 0): ?>
                <div class="table-responsive">
                    <table class="admin-table" id="ratingsTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Client</th>
                                <th>Vehicle</th>
                                <th>Rating</th>
                                <th>Comment</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ratings as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['rating_id']) ?></td>
                                <td><?= htmlspecialchars($row['client_name'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars(($row['plate_number'] ?? '') . ' ' . ($row['make'] ?? '') . ' ' . ($row['model'] ?? '')) ?></td>
                                <td>
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <?php if ($i <= $row['rating_value']): ?>
                                            <i class="fas fa-star" style="color:#f39c12"></i>
                                        <?php else: ?>
                                            <i class="fas fa-star" style="color:#3a3a50"></i>
                                        <?php endif; ?>
                                    <?php endfor; ?>
                                </td>
                                <td><?= htmlspecialchars($row['comment'] ?? '') ?></td>
                                <td><?= htmlspecialchars($row['created_at']) ?></td>
                                <td class="action-btns">
                                    <form method="POST" style="display:inline" onsubmit="return confirm('Delete this rating?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="rating_id" value="<?= $row['rating_id'] ?>">
                                        <button type="submit" class="btn-icon btn-delete"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-star"></i>
                    <h3>No ratings found</h3>
                    <p>Ratings will appear here once clients submit them.</p>
                </div>
                <?php endif; ?>
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

function searchTable() {
    var input = document.getElementById('searchInput').value.toLowerCase();
    var table = document.getElementById('ratingsTable');
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
