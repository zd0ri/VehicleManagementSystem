<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'customer') {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$client_id = $_SESSION['client_id'];
$full_name = $_SESSION['full_name'];

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'mark_read') {
        $nid = (int) ($_POST['notification_id'] ?? 0);
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?")->execute([$nid, $user_id]);
    } elseif ($action === 'mark_all_read') {
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")->execute([$user_id]);
    } elseif ($action === 'delete') {
        $nid = (int) ($_POST['notification_id'] ?? 0);
        $pdo->prepare("DELETE FROM notifications WHERE notification_id = ? AND user_id = ?")->execute([$nid, $user_id]);
    }
    header('Location: notifications.php');
    exit;
}

// Fetch notifications
$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY is_read ASC, created_at DESC");
$stmt->execute([$user_id]);
$notifications = $stmt->fetchAll();

$unread = 0;
foreach ($notifications as $n) { if (!$n['is_read']) $unread++; }

// Cart count for badge
$cartStmt = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM cart WHERE client_id = ?");
$cartStmt->execute([$client_id]);
$cartCount = (int)$cartStmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - VehiCare</title>
    <link rel="stylesheet" href="../includes/style/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700;900&family=Oswald:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .notif-section { padding: 60px 0; min-height: 60vh; background: #f8f9fa; }
        .notif-page-title { font-family: 'Oswald', sans-serif; font-size: 28px; font-weight: 700; margin-bottom: 24px; color: #1a1a2e; }
        .notif-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px; }
        .notif-card { background: #fff; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); overflow: hidden; }
        .notif-item { display: flex; align-items: flex-start; gap: 14px; padding: 16px 20px; border-bottom: 1px solid #f0f0f0; transition: background 0.2s; }
        .notif-item:last-child { border-bottom: none; }
        .notif-item.unread { background: rgba(52,152,219,0.05); border-left: 3px solid #3498db; }
        .notif-item.read { opacity: 0.6; }
        .notif-icon { width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 16px; }
        .notif-icon.blue { background: rgba(52,152,219,0.12); color: #3498db; }
        .notif-icon.green { background: rgba(39,174,96,0.12); color: #27ae60; }
        .notif-icon.orange { background: rgba(243,156,18,0.12); color: #f39c12; }
        .notif-icon.purple { background: rgba(155,89,182,0.12); color: #9b59b6; }
        .notif-body { flex: 1; min-width: 0; }
        .notif-title { font-weight: 600; font-size: 14px; color: #1a1a2e; margin-bottom: 4px; }
        .notif-message { font-size: 13px; color: #666; line-height: 1.5; }
        .notif-time { font-size: 11px; color: #aaa; margin-top: 6px; }
        .notif-actions { display: flex; gap: 6px; flex-shrink: 0; align-self: center; }
        .notif-btn { width: 30px; height: 30px; border-radius: 6px; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 12px; transition: all 0.2s; }
        .notif-btn.read-btn { background: rgba(39,174,96,0.1); color: #27ae60; }
        .notif-btn.read-btn:hover { background: #27ae60; color: #fff; }
        .notif-btn.del-btn { background: rgba(231,76,60,0.1); color: #e74c3c; }
        .notif-btn.del-btn:hover { background: #e74c3c; color: #fff; }
        .btn-mark-all { background: #3498db; color: #fff; border: none; padding: 8px 16px; border-radius: 8px; font-size: 13px; cursor: pointer; font-weight: 500; transition: background 0.2s; }
        .btn-mark-all:hover { background: #2980b9; }
        .notif-empty { padding: 60px 20px; text-align: center; color: #aaa; }
        .notif-empty i { font-size: 48px; margin-bottom: 16px; display: block; }
        .notif-empty h3 { font-size: 18px; color: #666; margin-bottom: 8px; }
        .notif-badge-count { background: #e74c3c; color: #fff; font-size: 12px; padding: 2px 8px; border-radius: 12px; margin-left: 8px; }
    </style>
</head>
<body>

<!-- ========== TOP BAR ========== -->
<div class="top-bar">
    <div class="container">
        <div class="top-bar-left">
            <span><i class="fas fa-phone-alt"></i> +63 912 345 6789</span>
            <span><i class="fas fa-envelope"></i> info@vehicare.ph</span>
            <span><i class="fas fa-map-marker-alt"></i> Taguig City, Metro Manila</span>
        </div>
        <div class="top-bar-right">
            <span><i class="fas fa-user"></i> <?= htmlspecialchars($full_name) ?></span>
            <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>
</div>

<!-- ========== HEADER / NAVBAR ========== -->
<header class="main-header">
    <div class="container">
        <div class="header-inner">
            <a href="../index.php" class="logo">
                <span class="logo-vehi">Vehi</span><span class="logo-care">Care</span>
            </a>
            <nav class="main-nav">
                <ul>
                    <li><a href="../index.php"><i class="fas fa-home"></i> Home</a></li>
                    <li><a href="../index.php#shop"><i class="fas fa-store"></i> Shop</a></li>
                    <li><a href="../index.php#services"><i class="fas fa-wrench"></i> Services</a></li>
                    <li><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="../index.php#about"><i class="fas fa-info-circle"></i> About</a></li>
                    <li><a href="../index.php#contact"><i class="fas fa-envelope"></i> Contact</a></li>
                </ul>
            </nav>
            <div class="header-actions">
                <a href="notifications.php" class="header-icon" title="Notifications" style="color:#3498db;">
                    <i class="fas fa-bell"></i>
                    <?php if ($unread > 0): ?><span class="badge"><?= $unread ?></span><?php endif; ?>
                </a>
                <a href="cart.php" class="header-icon" title="Cart">
                    <i class="fas fa-shopping-cart"></i>
                    <span class="badge"><?= $cartCount ?></span>
                </a>
            </div>
            <button class="mobile-toggle" id="mobileToggle">
                <i class="fas fa-bars"></i>
            </button>
        </div>
    </div>
</header>

<!-- ========== NOTIFICATIONS SECTION ========== -->
<section class="notif-section">
    <div class="container">
        <div class="notif-header">
            <h1 class="notif-page-title">
                <i class="fas fa-bell"></i> Notifications
                <?php if ($unread > 0): ?><span class="notif-badge-count"><?= $unread ?> unread</span><?php endif; ?>
            </h1>
            <?php if ($unread > 0): ?>
            <form method="POST">
                <input type="hidden" name="action" value="mark_all_read">
                <button type="submit" class="btn-mark-all"><i class="fas fa-check-double"></i> Mark All as Read</button>
            </form>
            <?php endif; ?>
        </div>

        <div class="notif-card">
            <?php if (empty($notifications)): ?>
                <div class="notif-empty">
                    <i class="fas fa-bell-slash"></i>
                    <h3>No notifications yet</h3>
                    <p>You'll see updates about your appointments, orders, and services here.</p>
                </div>
            <?php else: ?>
                <?php foreach ($notifications as $n):
                    $type = $n['type'] ?? '';
                    if (in_array($type, ['job_done', 'queue_turn'])) { $iconClass = 'green'; $icon = 'check-circle'; }
                    elseif (in_array($type, ['new_assignment'])) { $iconClass = 'blue'; $icon = 'tasks'; }
                    elseif (in_array($type, ['queue', 'ewallet_payment'])) { $iconClass = 'orange'; $icon = 'clock'; }
                    else { $iconClass = 'purple'; $icon = 'bell'; }
                ?>
                <div class="notif-item <?= $n['is_read'] ? 'read' : 'unread' ?>">
                    <div class="notif-icon <?= $iconClass ?>"><i class="fas fa-<?= $icon ?>"></i></div>
                    <div class="notif-body">
                        <div class="notif-title"><?= htmlspecialchars($n['title']) ?></div>
                        <div class="notif-message"><?= htmlspecialchars($n['message']) ?></div>
                        <div class="notif-time"><i class="far fa-clock"></i> <?= date('M d, Y h:i A', strtotime($n['created_at'])) ?></div>
                    </div>
                    <div class="notif-actions">
                        <?php if (!$n['is_read']): ?>
                        <form method="POST" style="display:inline;"><input type="hidden" name="action" value="mark_read"><input type="hidden" name="notification_id" value="<?= $n['notification_id'] ?>"><button type="submit" class="notif-btn read-btn" title="Mark as read"><i class="fas fa-check"></i></button></form>
                        <?php endif; ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this notification?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="notification_id" value="<?= $n['notification_id'] ?>"><button type="submit" class="notif-btn del-btn" title="Delete"><i class="fas fa-trash"></i></button></form>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- ========== FOOTER ========== -->
<footer style="background:#1a1a2e;color:#fff;padding:30px 0;text-align:center;">
    <div class="container">
        <p style="font-size:14px;opacity:0.7;">&copy; <?= date('Y') ?> VehiCare. All rights reserved.</p>
    </div>
</footer>

<script>
document.getElementById('mobileToggle')?.addEventListener('click', function() {
    document.querySelector('.main-nav').classList.toggle('active');
});
</script>
</body>
</html>
