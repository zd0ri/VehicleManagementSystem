<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

// ── Auth guard ──
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'customer') {
    header('Location: login.php');
    exit;
}

$client_id = $_SESSION['client_id'];
$full_name = $_SESSION['full_name'];

// ── Check for order success message ──
$order_success = false;
if (isset($_SESSION['order_success'])) {
    $order_success = $_SESSION['order_success'];
    unset($_SESSION['order_success']);
}

// ── POST handler: Cancel Order ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_order') {
    $order_id = (int) ($_POST['order_id'] ?? 0);

    // Verify the order belongs to this client and is Pending
    $stmt = $pdo->prepare("SELECT order_id, status FROM orders WHERE order_id = ? AND client_id = ? AND status = 'Pending'");
    $stmt->execute([$order_id, $client_id]);
    $order = $stmt->fetch();

    if ($order) {
        // Restore inventory quantities
        $itemsStmt = $pdo->prepare("SELECT item_id, quantity FROM order_items WHERE order_id = ? AND item_id IS NOT NULL");
        $itemsStmt->execute([$order_id]);
        $orderItems = $itemsStmt->fetchAll();

        foreach ($orderItems as $oi) {
            $restoreStmt = $pdo->prepare("UPDATE inventory SET quantity = quantity + ? WHERE item_id = ?");
            $restoreStmt->execute([$oi['quantity'], $oi['item_id']]);
        }

        // Update order status to Cancelled
        $updateStmt = $pdo->prepare("UPDATE orders SET status = 'Cancelled' WHERE order_id = ?");
        $updateStmt->execute([$order_id]);
    }

    header('Location: orders.php');
    exit;
}

// ── Fetch all orders for this client ──
$stmt = $pdo->prepare("
    SELECT o.order_id, o.order_type, o.vehicle_id, o.subtotal, o.tax_amount, o.total_amount,
           o.status, o.payment_method, o.notes, o.created_at
    FROM orders o
    WHERE o.client_id = ?
    ORDER BY o.created_at DESC
");
$stmt->execute([$client_id]);
$orders = $stmt->fetchAll();

// ── Fetch order items for each order ──
$orderItems = [];
if (!empty($orders)) {
    $orderIds = array_column($orders, 'order_id');
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $itemsStmt = $pdo->prepare("
        SELECT oi.order_id, oi.order_item_id, oi.item_id, oi.service_id, oi.quantity, oi.unit_price,
               (oi.quantity * oi.unit_price) AS total_price,
               i.item_name, s.service_name
        FROM order_items oi
        LEFT JOIN inventory i ON oi.item_id = i.item_id
        LEFT JOIN services s ON oi.service_id = s.service_id
        WHERE oi.order_id IN ($placeholders)
        ORDER BY oi.order_item_id ASC
    ");
    $itemsStmt->execute($orderIds);
    $allItems = $itemsStmt->fetchAll();

    foreach ($allItems as $item) {
        $orderItems[$item['order_id']][] = $item;
    }
}

// ── Cart count for badge ──
$cartStmt = $pdo->prepare("SELECT COUNT(*) FROM cart WHERE client_id = ?");
$cartStmt->execute([$client_id]);
$cartCount = $cartStmt->fetchColumn();

// ── Unread notifications ──
$user_id = $_SESSION['user_id'];
$notifStmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 5");
$notifStmt->execute([$user_id]);
$user_notifs = $notifStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Orders - VehiCare</title>
    <link rel="stylesheet" href="../includes/style/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700;900&family=Oswald:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ── Orders Page Styles ── */
        .orders-section {
            padding: 60px 0;
            min-height: 60vh;
            background: #f8f9fa;
        }
        .orders-section .container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 0 20px;
        }
        .orders-page-title {
            font-family: 'Oswald', sans-serif;
            font-size: 32px;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .orders-page-title i {
            color: #e74c3c;
        }

        /* ── Success Alert ── */
        .alert-success {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            color: #155724;
            border: 1px solid #b1dfbb;
            padding: 16px 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
            animation: slideDown 0.4s ease;
        }
        .alert-success i {
            font-size: 22px;
        }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-15px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ── Order Card ── */
        .order-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 15px rgba(0,0,0,0.06);
            margin-bottom: 20px;
            overflow: hidden;
            transition: box-shadow 0.3s ease;
        }
        .order-card:hover {
            box-shadow: 0 4px 25px rgba(0,0,0,0.1);
        }
        .order-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 24px;
            cursor: pointer;
            user-select: none;
            transition: background 0.2s ease;
            flex-wrap: wrap;
            gap: 12px;
        }
        .order-card-header:hover {
            background: #fafbfc;
        }
        .order-header-left {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }
        .order-id {
            font-family: 'Oswald', sans-serif;
            font-size: 18px;
            font-weight: 600;
            color: #2c3e50;
        }
        .order-date {
            color: #7f8c8d;
            font-size: 14px;
        }
        .order-date i {
            margin-right: 4px;
        }
        .order-header-right {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        .order-payment {
            color: #7f8c8d;
            font-size: 13px;
            background: #f0f0f0;
            padding: 4px 12px;
            border-radius: 20px;
        }
        .order-total {
            font-family: 'Oswald', sans-serif;
            font-size: 20px;
            font-weight: 700;
            color: #e74c3c;
        }
        .order-toggle-icon {
            color: #bbb;
            font-size: 14px;
            transition: transform 0.3s ease;
        }
        .order-card.active .order-toggle-icon {
            transform: rotate(180deg);
        }

        /* ── Status Badges ── */
        .status-badge {
            display: inline-block;
            padding: 5px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-pending {
            background: #fff3e0;
            color: #e67e22;
            border: 1px solid #f0c27a;
        }
        .status-processing {
            background: #e3f2fd;
            color: #2196f3;
            border: 1px solid #90caf9;
        }
        .status-completed {
            background: #e8f5e9;
            color: #27ae60;
            border: 1px solid #a5d6a7;
        }
        .status-cancelled {
            background: #fce4ec;
            color: #e74c3c;
            border: 1px solid #ef9a9a;
        }

        /* ── Order Details (expandable) ── */
        .order-details {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.4s ease;
            border-top: 0 solid #eee;
        }
        .order-card.active .order-details {
            max-height: 1000px;
            border-top: 1px solid #eee;
        }
        .order-details-inner {
            padding: 20px 24px;
        }
        .order-items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
        }
        .order-items-table thead {
            background: #f8f9fa;
        }
        .order-items-table thead th {
            padding: 10px 14px;
            font-family: 'Oswald', sans-serif;
            font-weight: 500;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #7f8c8d;
            text-align: left;
            border-bottom: 2px solid #eee;
        }
        .order-items-table tbody td {
            padding: 12px 14px;
            font-size: 14px;
            color: #2c3e50;
            border-bottom: 1px solid #f0f0f0;
        }
        .order-items-table tbody tr:last-child td {
            border-bottom: 0;
        }
        .item-type-badge {
            display: inline-block;
            font-size: 10px;
            padding: 2px 8px;
            border-radius: 10px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .item-type-product {
            background: #e8f5e9;
            color: #2e7d32;
        }
        .item-type-service {
            background: #e3f2fd;
            color: #1565c0;
        }

        /* ── Order Footer ── */
        .order-detail-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 12px;
            border-top: 1px solid #eee;
            flex-wrap: wrap;
            gap: 12px;
        }
        .order-notes {
            font-size: 13px;
            color: #7f8c8d;
            font-style: italic;
            max-width: 60%;
        }
        .order-notes i {
            margin-right: 4px;
        }

        /* ── Cancel Button ── */
        .btn-cancel-order {
            background: #fff;
            color: #e74c3c;
            border: 2px solid #e74c3c;
            padding: 8px 20px;
            border-radius: 8px;
            font-family: 'Oswald', sans-serif;
            font-weight: 500;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-cancel-order:hover {
            background: #e74c3c;
            color: #fff;
        }

        /* ── Empty State ── */
        .orders-empty {
            text-align: center;
            padding: 80px 20px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 15px rgba(0,0,0,0.06);
        }
        .orders-empty i {
            font-size: 64px;
            color: #ddd;
            margin-bottom: 20px;
        }
        .orders-empty h3 {
            font-family: 'Oswald', sans-serif;
            font-size: 24px;
            color: #2c3e50;
            margin-bottom: 10px;
        }
        .orders-empty p {
            color: #7f8c8d;
            margin-bottom: 25px;
            font-size: 15px;
        }
        .btn-start-shopping {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #e74c3c;
            color: #fff;
            padding: 12px 30px;
            border-radius: 8px;
            text-decoration: none;
            font-family: 'Oswald', sans-serif;
            font-weight: 500;
            font-size: 16px;
            transition: background 0.3s ease;
        }
        .btn-start-shopping:hover {
            background: #c0392b;
        }

        /* ── Simple Footer ── */
        .orders-footer {
            background: #2c3e50;
            color: rgba(255,255,255,0.7);
            text-align: center;
            padding: 25px 0;
            font-size: 14px;
        }
        .orders-footer a {
            color: #e74c3c;
            text-decoration: none;
        }

        /* ── Responsive ── */
        @media (max-width: 768px) {
            .orders-page-title {
                font-size: 24px;
            }
            .order-card-header {
                padding: 16px 18px;
            }
            .order-header-left, .order-header-right {
                gap: 10px;
            }
            .order-id {
                font-size: 16px;
            }
            .order-total {
                font-size: 18px;
            }
            .order-details-inner {
                padding: 16px 18px;
                overflow-x: auto;
            }
            .order-items-table {
                min-width: 500px;
            }
            .order-notes {
                max-width: 100%;
            }
            .order-detail-footer {
                flex-direction: column;
                align-items: flex-start;
            }
        }
        @media (max-width: 480px) {
            .order-card-header {
                flex-direction: column;
                align-items: flex-start;
            }
            .order-header-right {
                width: 100%;
                justify-content: space-between;
            }
        }
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
                    <li><a href="../index.php#about"><i class="fas fa-info-circle"></i> About</a></li>
                    <li><a href="../index.php#contact"><i class="fas fa-envelope"></i> Contact</a></li>
                </ul>
            </nav>
            <div class="header-actions">
                <a href="cart.php" class="header-icon" title="Cart">
                    <i class="fas fa-shopping-cart"></i>
                    <span class="badge"><?= $cartCount ?></span>
                </a>
                <a href="orders.php" class="header-icon" title="My Orders" style="margin-left: 8px;">
                    <i class="fas fa-receipt"></i>
                </a>
            </div>
            <button class="mobile-toggle" id="mobileToggle">
                <i class="fas fa-bars"></i>
            </button>
        </div>
    </div>
</header>

<!-- ========== ORDERS SECTION ========== -->
<section class="orders-section">
    <div class="container">
        <h1 class="orders-page-title"><i class="fas fa-receipt"></i> My Orders</h1>

        <?php if ($order_success): ?>
            <div class="alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?= htmlspecialchars($order_success) ?></span>
            </div>
        <?php endif; ?>

        <?php if (!empty($user_notifs)): ?>
            <?php foreach ($user_notifs as $notif): ?>
            <div class="alert-success" style="background:rgba(52,152,219,0.1);border-left:4px solid #3498db;margin-bottom:10px;">
                <i class="fas fa-bell" style="color:#3498db;"></i>
                <span><strong><?= htmlspecialchars($notif['title']) ?></strong> — <?= htmlspecialchars($notif['message']) ?></span>
            </div>
            <?php endforeach; ?>
            <?php
                // Mark displayed notifications as read
                $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")->execute([$user_id]);
            ?>
        <?php endif; ?>

        <?php if (empty($orders)): ?>
            <!-- ── Empty State ── -->
            <div class="orders-empty">
                <i class="fas fa-box-open"></i>
                <h3>No orders yet</h3>
                <p>You haven't placed any orders. Browse our shop and get started!</p>
                <a href="../index.php#shop" class="btn-start-shopping">
                    <i class="fas fa-store"></i> Start Shopping
                </a>
            </div>
        <?php else: ?>
            <?php foreach ($orders as $order): ?>
                <?php
                    $statusClass = 'status-' . strtolower($order['status']);
                    $items = $orderItems[$order['order_id']] ?? [];
                ?>
                <div class="order-card" id="order-<?= $order['order_id'] ?>">
                    <div class="order-card-header" onclick="toggleOrder(<?= $order['order_id'] ?>)">
                        <div class="order-header-left">
                            <span class="order-id">Order #<?= htmlspecialchars($order['order_id']) ?></span>
                            <span class="status-badge <?= $statusClass ?>"><?= htmlspecialchars($order['status']) ?></span>
                            <span class="order-date">
                                <i class="far fa-calendar-alt"></i>
                                <?= date('M d, Y h:i A', strtotime($order['created_at'])) ?>
                            </span>
                        </div>
                        <div class="order-header-right">
                            <span class="order-payment">
                                <i class="fas fa-credit-card"></i>
                                <?= htmlspecialchars($order['payment_method']) ?>
                            </span>
                            <span class="order-total">₱<?= number_format($order['total_amount'], 2) ?></span>
                            <i class="fas fa-chevron-down order-toggle-icon"></i>
                        </div>
                    </div>
                    <div class="order-details">
                        <div class="order-details-inner">
                            <?php if (!empty($items)): ?>
                                <table class="order-items-table">
                                    <thead>
                                        <tr>
                                            <th>Item</th>
                                            <th>Type</th>
                                            <th>Qty</th>
                                            <th>Unit Price</th>
                                            <th>Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($items as $item): ?>
                                            <?php
                                                $name = $item['item_id'] ? $item['item_name'] : $item['service_name'];
                                                $type = $item['item_id'] ? 'Product' : 'Service';
                                                $typeClass = $item['item_id'] ? 'item-type-product' : 'item-type-service';
                                                $total = $item['quantity'] * $item['unit_price'];
                                            ?>
                                            <tr>
                                                <td><?= htmlspecialchars($name) ?></td>
                                                <td><span class="item-type-badge <?= $typeClass ?>"><?= $type ?></span></td>
                                                <td><?= (int) $item['quantity'] ?></td>
                                                <td>₱<?= number_format($item['unit_price'], 2) ?></td>
                                                <td><strong>₱<?= number_format($total, 2) ?></strong></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <p style="color: #999; font-style: italic;">No items found for this order.</p>
                            <?php endif; ?>

                            <div class="order-detail-footer">
                                <div class="order-notes">
                                    <?php if (!empty($order['notes'])): ?>
                                        <i class="fas fa-sticky-note"></i> <?= htmlspecialchars($order['notes']) ?>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <?php if ($order['status'] === 'Pending'): ?>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to cancel this order?');">
                                            <input type="hidden" name="action" value="cancel_order">
                                            <input type="hidden" name="order_id" value="<?= $order['order_id'] ?>">
                                            <button type="submit" class="btn-cancel-order">
                                                <i class="fas fa-times-circle"></i> Cancel Order
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>

<!-- ========== FOOTER ========== -->
<footer class="orders-footer">
    <div class="container">
        <p>&copy; 2026 <a href="../index.php">VehiCare</a>. All rights reserved. | Designed for Vehicle Service DB</p>
    </div>
</footer>

<!-- ========== JAVASCRIPT ========== -->
<script>
// Toggle order card expand/collapse
function toggleOrder(orderId) {
    const card = document.getElementById('order-' + orderId);
    if (card) {
        card.classList.toggle('active');
    }
}

// Mobile Nav Toggle
const mobileToggle = document.getElementById('mobileToggle');
if (mobileToggle) {
    mobileToggle.addEventListener('click', function () {
        document.querySelector('.main-nav').classList.toggle('active');
        this.querySelector('i').classList.toggle('fa-bars');
        this.querySelector('i').classList.toggle('fa-times');
    });
}

// Sticky Header
window.addEventListener('scroll', function () {
    const header = document.querySelector('.main-header');
    if (header) header.classList.toggle('sticky', window.scrollY > 100);
});
</script>

</body>
</html>
