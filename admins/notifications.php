<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../users/login.php');
    exit;
}

$page_title = 'Notifications';
$current_page = 'notifications';

require_once __DIR__ . '/../includes/db.php';

$success = '';
$error = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'add') {
        try {
            $stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)");
            $user_id = null;
            $stmt->bind_param("iss",
                $user_id,
                $_POST['title'],
                $_POST['message']
            );
            $stmt->execute();
            $success = 'Notification sent successfully.';
        } catch (Exception $e) {
            $error = 'Failed to send notification: ' . $e->getMessage();
        }
    }

    if ($action === 'mark_read') {
        try {
            $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE notification_id = ?");
            $stmt->bind_param("i", $_POST['notification_id']);
            $stmt->execute();
            $success = 'Notification marked as read.';
        } catch (Exception $e) {
            $error = 'Failed to mark notification: ' . $e->getMessage();
        }
    }

    if ($action === 'mark_all_read') {
        try {
            $conn->query("UPDATE notifications SET is_read = 1");
            $success = 'All notifications marked as read.';
        } catch (Exception $e) {
            $error = 'Failed to mark all notifications: ' . $e->getMessage();
        }
    }

    if ($action === 'delete') {
        try {
            $stmt = $conn->prepare("DELETE FROM notifications WHERE notification_id = ?");
            $stmt->bind_param("i", $_POST['notification_id']);
            $stmt->execute();
            $success = 'Notification deleted successfully.';
        } catch (Exception $e) {
            $error = 'Failed to delete notification: ' . $e->getMessage();
        }
    }
}

// Fetch all notifications
$notifications = [];
try {
    $result = $conn->query("SELECT * FROM notifications ORDER BY created_at DESC");
    while ($row = $result->fetch_assoc()) {
        $notifications[] = $row;
    }
} catch (Exception $e) {
    $error = 'Failed to fetch notifications: ' . $e->getMessage();
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
                <h2><i class="fas fa-bell"></i> Notifications</h2>
                <div>
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="action" value="mark_all_read">
                        <button type="submit" class="btn btn-secondary">
                            <i class="fas fa-check-double"></i> Mark All Read
                        </button>
                    </form>
                    <button class="btn btn-primary" onclick="openModal('addModal')">
                        <i class="fas fa-plus"></i> Send Notification
                    </button>
                </div>
            </div>

            <!-- Admin card with table -->
            <div class="admin-card">
                <div class="table-toolbar">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="searchInput" placeholder="Search notifications..." onkeyup="searchTable()">
                    </div>
                </div>

                <?php if (count($notifications) > 0): ?>
                <div class="table-responsive">
                    <table class="admin-table" id="notificationsTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Title</th>
                                <th>Message</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($notifications as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['notification_id']) ?></td>
                                <td><?= htmlspecialchars($row['title']) ?></td>
                                <td><?= htmlspecialchars(mb_strimwidth($row['message'], 0, 60, '...')) ?></td>
                                <td>
                                    <?php if ($row['is_read']): ?>
                                        <span class="badge badge-success">Read</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning">Unread</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($row['created_at']) ?></td>
                                <td class="action-btns">
                                    <?php if (!$row['is_read']): ?>
                                    <form method="POST" style="display:inline">
                                        <input type="hidden" name="action" value="mark_read">
                                        <input type="hidden" name="notification_id" value="<?= $row['notification_id'] ?>">
                                        <button type="submit" class="btn-icon btn-edit" title="Mark as Read"><i class="fas fa-check"></i></button>
                                    </form>
                                    <?php endif; ?>
                                    <form method="POST" style="display:inline" onsubmit="return confirm('Delete this notification?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="notification_id" value="<?= $row['notification_id'] ?>">
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
                    <i class="fas fa-bell"></i>
                    <h3>No notifications found</h3>
                    <p>Click "Send Notification" to create one.</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Add Modal -->
            <div class="modal-overlay" id="addModal">
                <div class="modal">
                    <div class="modal-header">
                        <h3>Send Notification</h3>
                        <button class="modal-close" onclick="closeModal('addModal')">&times;</button>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="add">
                        <div class="modal-body">
                            <div class="form-group">
                                <label>Title</label>
                                <input type="text" name="title" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label>Message</label>
                                <textarea name="message" class="form-control" rows="4" required></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Send</button>
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

function searchTable() {
    var input = document.getElementById('searchInput').value.toLowerCase();
    var table = document.getElementById('notificationsTable');
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
