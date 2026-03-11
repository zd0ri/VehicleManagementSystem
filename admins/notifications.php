<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') { header('Location: ../users/login.php'); exit; }
$page_title = 'Notifications'; $current_page = 'notifications';
require_once __DIR__ . '/../includes/db.php';
$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add') {
            $user_id = !empty($_POST['user_id']) ? $_POST['user_id'] : null;
            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, is_read) VALUES (?,?,?,0)");
            $stmt->execute([$user_id, $_POST['title'], $_POST['message']]);
            $new_id = $pdo->lastInsertId();
            logAudit($pdo, 'Created notification', 'notifications', $new_id);
            $success = 'Notification sent successfully.';
        } elseif ($action === 'mark_read') {
            $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE notification_id = ?")->execute([$_POST['notification_id']]);
            logAudit($pdo, 'Marked notification as read', 'notifications', $_POST['notification_id']);
            $success = 'Notification marked as read.';
        } elseif ($action === 'mark_all_read') {
            $pdo->exec("UPDATE notifications SET is_read = 1 WHERE is_read = 0");
            logAudit($pdo, 'Marked all notifications as read', 'notifications', null);
            $success = 'All notifications marked as read.';
        } elseif ($action === 'delete') {
            $pdo->prepare("DELETE FROM notifications WHERE notification_id = ?")->execute([$_POST['notification_id']]);
            logAudit($pdo, 'Deleted notification', 'notifications', $_POST['notification_id']);
            $success = 'Notification deleted.';
        } elseif ($action === 'delete_all_read') {
            $pdo->exec("DELETE FROM notifications WHERE is_read = 1");
            logAudit($pdo, 'Deleted all read notifications', 'notifications', null);
            $success = 'All read notifications deleted.';
        }
    } catch (Exception $e) { $error = 'Error: ' . $e->getMessage(); }
}

$notifications = $pdo->query("SELECT n.*, u.full_name AS user_name FROM notifications n LEFT JOIN users u ON n.user_id = u.user_id ORDER BY n.created_at DESC")->fetchAll();
$users = $pdo->query("SELECT user_id, full_name, role FROM users WHERE status = 'active' ORDER BY full_name ASC")->fetchAll();
$unread_count = $pdo->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0")->fetchColumn();
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
            <div class="page-header">
                <h2><i class="fas fa-bell"></i> Notifications <span class="badge badge-info"><?= $unread_count ?> unread</span></h2>
                <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
                    <button class="btn btn-primary" onclick="openModal('addModal')"><i class="fas fa-plus"></i> Send Notification</button>
                    <form method="POST" style="display:inline"><input type="hidden" name="action" value="mark_all_read"><button type="submit" class="btn btn-secondary"><i class="fas fa-check-double"></i> Mark All Read</button></form>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Delete all read notifications?')"><input type="hidden" name="action" value="delete_all_read"><button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Clear Read</button></form>
                </div>
            </div>

            <div class="admin-card">
                <div class="table-toolbar"><div class="search-box"><i class="fas fa-search"></i><input type="text" placeholder="Search..." onkeyup="searchTable(this.value)"></div></div>
                <?php if (empty($notifications)): ?><div class="empty-state"><i class="fas fa-bell-slash"></i><h3>No notifications</h3><p>Click "Send Notification" to create one.</p></div>
                <?php else: ?>
                <div class="notification-list" id="dataTable">
                    <?php foreach ($notifications as $n): ?>
                    <div class="notification-item <?= $n['is_read'] ? 'read' : 'unread' ?>" style="display:flex;justify-content:space-between;align-items:flex-start;padding:1rem;border-bottom:1px solid var(--border-color);<?= !$n['is_read'] ? 'background:rgba(227,30,36,0.04);border-left:3px solid var(--primary-red);' : '' ?>">
                        <div style="flex:1;">
                            <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.25rem;">
                                <?php if (!$n['is_read']): ?><span style="width:8px;height:8px;border-radius:50%;background:var(--primary-red);display:inline-block;"></span><?php endif; ?>
                                <strong style="font-size:0.95rem;"><?= htmlspecialchars($n['title']) ?></strong>
                            </div>
                            <p style="margin:0.25rem 0;color:var(--text-muted);font-size:0.9rem;"><?= htmlspecialchars($n['message']) ?></p>
                            <small style="color:var(--text-muted);">
                                <?php if ($n['user_name']): ?>To: <?= htmlspecialchars($n['user_name']) ?> &bull; <?php else: ?>System &bull; <?php endif; ?>
                                <?= date('M d, Y h:i A', strtotime($n['created_at'])) ?>
                            </small>
                        </div>
                        <div style="display:flex;gap:0.25rem;margin-left:1rem;">
                            <?php if (!$n['is_read']): ?>
                            <form method="POST"><input type="hidden" name="action" value="mark_read"><input type="hidden" name="notification_id" value="<?= $n['notification_id'] ?>"><button type="submit" class="btn-icon btn-edit" title="Mark Read"><i class="fas fa-check"></i></button></form>
                            <?php endif; ?>
                            <form method="POST" onsubmit="return confirm('Delete this notification?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="notification_id" value="<?= $n['notification_id'] ?>"><button type="submit" class="btn-icon btn-delete" title="Delete"><i class="fas fa-trash"></i></button></form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<!-- Add Modal -->
<div class="modal-overlay" id="addModal"><div class="modal"><div class="modal-header"><h3>Send Notification</h3><button class="modal-close" onclick="closeModal('addModal')">&times;</button></div>
<form method="POST"><input type="hidden" name="action" value="add"><div class="modal-body">
    <div class="form-group"><label>Recipient (optional - leave blank for system-wide)</label><select name="user_id" class="form-control"><option value="">All Users (System)</option><?php foreach ($users as $u): ?><option value="<?= $u['user_id'] ?>"><?= htmlspecialchars($u['full_name']) ?> (<?= $u['role'] ?>)</option><?php endforeach; ?></select></div>
    <div class="form-group"><label>Title</label><input type="text" name="title" class="form-control" required placeholder="Notification title"></div>
    <div class="form-group"><label>Message</label><textarea name="message" class="form-control" rows="4" required placeholder="Notification message..."></textarea></div>
</div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Send</button></div></form></div></div>

<script src="includes/admin.js"></script>
<script>
function openModal(id){ document.getElementById(id).classList.add('active'); }
function closeModal(id){ document.getElementById(id).classList.remove('active'); }
document.querySelectorAll('.modal-overlay').forEach(m => { m.addEventListener('click', e => { if (e.target === m) m.classList.remove('active'); }); });

function searchTable(q) {
    q = q.toLowerCase();
    document.querySelectorAll('.notification-item').forEach(r => { r.style.display = r.textContent.toLowerCase().includes(q) ? '' : 'none'; });
}
</script>
</body>
</html>
