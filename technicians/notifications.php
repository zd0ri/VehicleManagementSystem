<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'technician') { header('Location: ../users/login.php'); exit; }
$page_title = 'Notifications'; $current_page = 'notifications';
require_once __DIR__ . '/../includes/db.php';

$tid = $_SESSION['user_id'];
$success = $error = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'mark_read') {
        $notif_id = (int) ($_POST['notification_id'] ?? 0);
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?")->execute([$notif_id, $tid]);
        $success = 'Notification marked as read.';
    }

    if ($action === 'mark_all_read') {
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")->execute([$tid]);
        $success = 'All notifications marked as read.';
    }

    if ($action === 'delete') {
        $notif_id = (int) ($_POST['notification_id'] ?? 0);
        $pdo->prepare("DELETE FROM notifications WHERE notification_id = ? AND user_id = ?")->execute([$notif_id, $tid]);
        $success = 'Notification deleted.';
    }
}

// Fetch notifications
$notifs = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY is_read ASC, created_at DESC");
$notifs->execute([$tid]);
$notifications = $notifs->fetchAll();

$unread = 0;
foreach ($notifications as $n) {
    if (!$n['is_read']) $unread++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - VehiCare Technician</title>
    <link rel="stylesheet" href="../includes/style/technician.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700;900&family=Oswald:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="tech-body">
<div class="tech-layout">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <main class="tech-main">
        <?php include __DIR__ . '/includes/topbar.php'; ?>
        <div class="tech-content">
            <?php if ($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div><?php endif; ?>

            <div class="page-header">
                <div class="page-header-left">
                    <h1><i class="fas fa-bell"></i> Notifications</h1>
                    <p><?= $unread ?> unread notification<?= $unread !== 1 ? 's' : '' ?></p>
                </div>
                <div class="page-header-right">
                    <?php if ($unread > 0): ?>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action" value="mark_all_read">
                        <button type="submit" class="btn btn-secondary"><i class="fas fa-check-double"></i> Mark All Read</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tech-card">
                <div class="card-body">
                    <?php if (empty($notifications)): ?>
                        <div class="empty-state"><i class="fas fa-bell-slash"></i><h3>No notifications</h3><p>You're all caught up!</p></div>
                    <?php else: ?>
                        <?php foreach ($notifications as $n): ?>
                        <div class="assignment-item" style="<?= !$n['is_read'] ? 'background:rgba(52,152,219,0.08);border-left:3px solid var(--primary);' : 'opacity:0.7;' ?>">
                            <div class="assignment-icon <?= !$n['is_read'] ? 'assigned' : 'finished' ?>">
                                <i class="fas fa-<?= ($n['type'] ?? '') === 'new_assignment' ? 'tasks' : (($n['type'] ?? '') === 'job_done' ? 'check-circle' : 'bell') ?>"></i>
                            </div>
                            <div class="assignment-details">
                                <div class="assignment-title"><?= htmlspecialchars($n['title']) ?></div>
                                <div class="assignment-meta">
                                    <span><?= htmlspecialchars($n['message']) ?></span>
                                </div>
                                <div style="color:#888;font-size:0.75rem;margin-top:4px;">
                                    <i class="fas fa-clock"></i> <?= date('M d, Y h:i A', strtotime($n['created_at'])) ?>
                                </div>
                            </div>
                            <div style="display:flex;gap:4px;">
                                <?php if (!$n['is_read']): ?>
                                <form method="POST" style="display:inline;"><input type="hidden" name="action" value="mark_read"><input type="hidden" name="notification_id" value="<?= $n['notification_id'] ?>"><button type="submit" class="btn-icon btn-edit" title="Mark as Read"><i class="fas fa-check"></i></button></form>
                                <?php endif; ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this notification?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="notification_id" value="<?= $n['notification_id'] ?>"><button type="submit" class="btn-icon btn-delete" title="Delete"><i class="fas fa-trash"></i></button></form>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>
<script src="includes/tech.js"></script>
</body>
</html>
