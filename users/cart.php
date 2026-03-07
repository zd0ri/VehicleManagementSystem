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

// ── POST handlers ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'update_qty') {
        $cart_id  = (int) ($_POST['cart_id'] ?? 0);
        $new_qty  = max(1, (int) ($_POST['quantity'] ?? 1));

        // Validate stock for product items
        $stmt = $pdo->prepare("SELECT c.item_id, i.quantity AS stock FROM cart c LEFT JOIN inventory i ON c.item_id = i.item_id WHERE c.cart_id = ? AND c.client_id = ?");
        $stmt->execute([$cart_id, $client_id]);
        $row = $stmt->fetch();

        if ($row) {
            if ($row['item_id'] && $new_qty > $row['stock']) {
                $new_qty = $row['stock'];
            }
            $upd = $pdo->prepare("UPDATE cart SET quantity = ? WHERE cart_id = ? AND client_id = ?");
            $upd->execute([$new_qty, $cart_id, $client_id]);
        }
    }

    if ($action === 'remove') {
        $cart_id = (int) ($_POST['cart_id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM cart WHERE cart_id = ? AND client_id = ?");
        $stmt->execute([$cart_id, $client_id]);
    }

    if ($action === 'clear') {
        $stmt = $pdo->prepare("DELETE FROM cart WHERE client_id = ?");
        $stmt->execute([$client_id]);
    }

    header('Location: cart.php');
    exit;
}

// ── Fetch cart items ──
$stmt = $pdo->prepare("
    SELECT c.cart_id, c.item_id, c.service_id, c.quantity,
           i.item_name, i.image AS item_image, i.unit_price, i.quantity AS stock, i.category AS item_category,
           s.service_name, s.base_price, s.estimated_duration
    FROM cart c
    LEFT JOIN inventory i ON c.item_id = i.item_id
    LEFT JOIN services  s ON c.service_id = s.service_id
    WHERE c.client_id = ?
    ORDER BY c.added_at DESC
");
$stmt->execute([$client_id]);
$cartItems = $stmt->fetchAll();

// ── Cart count for badge ──
$cartCount = count($cartItems);

// ── Totals ──
$subtotal = 0;
foreach ($cartItems as $item) {
    $price = $item['item_id'] ? $item['unit_price'] : $item['base_price'];
    $subtotal += $price * $item['quantity'];
}
$tax   = $subtotal * 0.12;
$total = $subtotal + $tax;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shopping Cart - VehiCare</title>
    <link rel="stylesheet" href="../includes/style/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700;900&family=Oswald:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ── Cart-specific styles ── */
        .cart-section {
            padding: 60px 0;
            min-height: 60vh;
            background: #f8f9fa;
        }
        .cart-section .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }
        .cart-page-title {
            font-family: 'Oswald', sans-serif;
            font-size: 32px;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .cart-page-title i {
            color: #e74c3c;
        }
        .cart-layout {
            display: grid;
            grid-template-columns: 1fr 380px;
            gap: 30px;
            align-items: start;
        }

        /* ── Cart Table ── */
        .cart-table-wrapper {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 20px rgba(0,0,0,0.06);
            overflow: hidden;
        }
        .cart-table {
            width: 100%;
            border-collapse: collapse;
        }
        .cart-table thead {
            background: #2c3e50;
            color: #fff;
        }
        .cart-table thead th {
            padding: 16px 20px;
            font-family: 'Oswald', sans-serif;
            font-weight: 500;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            text-align: left;
        }
        .cart-table tbody tr {
            border-bottom: 1px solid #eee;
            transition: background 0.2s;
        }
        .cart-table tbody tr:hover {
            background: #fafafa;
        }
        .cart-table tbody td {
            padding: 16px 20px;
            vertical-align: middle;
        }

        /* ── Product cell ── */
        .cart-product {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .cart-product-img {
            width: 70px;
            height: 70px;
            border-radius: 8px;
            object-fit: cover;
            border: 1px solid #eee;
            background: #f5f5f5;
        }
        .cart-product-icon {
            width: 70px;
            height: 70px;
            border-radius: 8px;
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 28px;
            flex-shrink: 0;
        }
        .cart-product-info h4 {
            font-size: 15px;
            font-weight: 600;
            color: #2c3e50;
            margin: 0 0 4px;
        }
        .cart-product-info span {
            font-size: 12px;
            color: #888;
        }
        .cart-product-info .badge-service {
            display: inline-block;
            background: #e74c3c;
            color: #fff;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 500;
        }
        .cart-product-info .badge-product {
            display: inline-block;
            background: #2c3e50;
            color: #fff;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 500;
        }

        /* ── Price ── */
        .cart-price {
            font-weight: 600;
            color: #2c3e50;
            font-size: 15px;
        }

        /* ── Quantity Controls ── */
        .qty-control {
            display: flex;
            align-items: center;
            gap: 0;
        }
        .qty-control button {
            width: 34px;
            height: 34px;
            border: 1px solid #ddd;
            background: #f5f5f5;
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
            color: #2c3e50;
        }
        .qty-control button:hover {
            background: #e74c3c;
            color: #fff;
            border-color: #e74c3c;
        }
        .qty-control button:first-child {
            border-radius: 6px 0 0 6px;
        }
        .qty-control button:last-child {
            border-radius: 0 6px 6px 0;
        }
        .qty-control input {
            width: 48px;
            height: 34px;
            text-align: center;
            border: 1px solid #ddd;
            border-left: none;
            border-right: none;
            font-size: 14px;
            font-weight: 600;
            color: #2c3e50;
            outline: none;
        }
        .qty-fixed {
            display: inline-block;
            background: #f0f0f0;
            padding: 6px 18px;
            border-radius: 6px;
            font-weight: 600;
            color: #888;
            font-size: 14px;
        }

        /* ── Subtotal ── */
        .cart-subtotal {
            font-weight: 700;
            color: #e74c3c;
            font-size: 16px;
        }

        /* ── Remove button ── */
        .btn-remove {
            background: none;
            border: none;
            color: #ccc;
            font-size: 18px;
            cursor: pointer;
            padding: 8px;
            border-radius: 50%;
            transition: all 0.2s;
        }
        .btn-remove:hover {
            color: #e74c3c;
            background: #ffeaea;
        }

        /* ── Cart Actions Bar ── */
        .cart-actions-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 20px;
            background: #f8f9fa;
            border-top: 1px solid #eee;
        }
        .btn-clear-cart {
            background: none;
            border: 2px solid #e74c3c;
            color: #e74c3c;
            padding: 10px 24px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .btn-clear-cart:hover {
            background: #e74c3c;
            color: #fff;
        }
        .btn-continue {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            color: #2c3e50;
            font-weight: 600;
            font-size: 14px;
            padding: 10px 24px;
            border: 2px solid #2c3e50;
            border-radius: 8px;
            transition: all 0.2s;
        }
        .btn-continue:hover {
            background: #2c3e50;
            color: #fff;
        }

        /* ── Summary Sidebar ── */
        .cart-summary {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 20px rgba(0,0,0,0.06);
            padding: 30px;
            position: sticky;
            top: 100px;
        }
        .cart-summary h3 {
            font-family: 'Oswald', sans-serif;
            font-size: 22px;
            font-weight: 600;
            color: #2c3e50;
            margin: 0 0 24px;
            padding-bottom: 16px;
            border-bottom: 2px solid #eee;
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            font-size: 15px;
            color: #555;
        }
        .summary-row span:last-child {
            font-weight: 600;
            color: #2c3e50;
        }
        .summary-row.tax {
            border-bottom: 1px dashed #ddd;
            padding-bottom: 16px;
            margin-bottom: 6px;
        }
        .summary-row.total {
            font-size: 20px;
            font-weight: 700;
            color: #2c3e50;
            padding-top: 16px;
        }
        .summary-row.total span:last-child {
            color: #e74c3c;
            font-size: 24px;
        }
        .btn-checkout {
            display: block;
            width: 100%;
            padding: 16px;
            background: #e74c3c;
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 700;
            font-family: 'Oswald', sans-serif;
            text-transform: uppercase;
            letter-spacing: 1px;
            cursor: pointer;
            margin-top: 24px;
            transition: all 0.3s;
            text-align: center;
            text-decoration: none;
        }
        .btn-checkout:hover {
            background: #c0392b;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(231,76,60,0.35);
        }
        .btn-checkout i {
            margin-right: 8px;
        }
        .btn-shop-more {
            display: block;
            width: 100%;
            padding: 14px;
            background: #fff;
            color: #2c3e50;
            border: 2px solid #2c3e50;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 12px;
            text-align: center;
            text-decoration: none;
            transition: all 0.3s;
        }
        .btn-shop-more:hover {
            background: #2c3e50;
            color: #fff;
        }
        .summary-info {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 20px;
            padding: 12px;
            background: #f0faf0;
            border-radius: 8px;
            font-size: 13px;
            color: #27ae60;
        }
        .summary-info i {
            font-size: 16px;
        }

        /* ── Empty Cart ── */
        .cart-empty {
            text-align: center;
            padding: 80px 20px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 20px rgba(0,0,0,0.06);
        }
        .cart-empty i {
            font-size: 80px;
            color: #ddd;
            margin-bottom: 24px;
        }
        .cart-empty h2 {
            font-family: 'Oswald', sans-serif;
            font-size: 28px;
            color: #2c3e50;
            margin-bottom: 10px;
        }
        .cart-empty p {
            color: #888;
            font-size: 16px;
            margin-bottom: 30px;
        }
        .btn-start-shopping {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 14px 36px;
            background: #e74c3c;
            color: #fff;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 700;
            font-size: 16px;
            font-family: 'Oswald', sans-serif;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: all 0.3s;
        }
        .btn-start-shopping:hover {
            background: #c0392b;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(231,76,60,0.35);
        }

        /* ── Simple Footer ── */
        .cart-footer {
            background: #2c3e50;
            color: rgba(255,255,255,0.7);
            text-align: center;
            padding: 24px 0;
            font-size: 14px;
        }
        .cart-footer a {
            color: #e74c3c;
            text-decoration: none;
        }

        /* ── Responsive ── */
        @media (max-width: 992px) {
            .cart-layout {
                grid-template-columns: 1fr;
            }
            .cart-summary {
                position: static;
            }
        }
        @media (max-width: 768px) {
            .cart-table thead {
                display: none;
            }
            .cart-table tbody tr {
                display: block;
                padding: 16px;
                margin-bottom: 12px;
                border: 1px solid #eee;
                border-radius: 10px;
            }
            .cart-table tbody td {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 8px 0;
                border: none;
            }
            .cart-table tbody td::before {
                content: attr(data-label);
                font-weight: 600;
                font-size: 13px;
                color: #888;
                text-transform: uppercase;
            }
            .cart-table tbody td:first-child {
                justify-content: flex-start;
            }
            .cart-table tbody td:first-child::before {
                display: none;
            }
            .cart-actions-bar {
                flex-direction: column;
                gap: 12px;
            }
            .cart-page-title {
                font-size: 24px;
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
                    <li><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="../index.php#about"><i class="fas fa-info-circle"></i> About</a></li>
                    <li><a href="../index.php#contact"><i class="fas fa-envelope"></i> Contact</a></li>
                </ul>
            </nav>
            <div class="header-actions">
                <a href="notifications.php" class="header-icon" title="Notifications">
                    <i class="fas fa-bell"></i>
                    <?php
                        $nStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
                        $nStmt->execute([$_SESSION['user_id']]);
                        $nCount = (int)$nStmt->fetchColumn();
                        if ($nCount > 0): ?><span class="badge"><?= $nCount ?></span><?php endif; ?>
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

<!-- ========== CART SECTION ========== -->
<section class="cart-section">
    <div class="container">
        <h1 class="cart-page-title"><i class="fas fa-shopping-cart"></i> Shopping Cart</h1>

        <?php if (empty($cartItems)): ?>
            <!-- Empty Cart -->
            <div class="cart-empty">
                <i class="fas fa-shopping-basket"></i>
                <h2>Your Cart is Empty</h2>
                <p>Looks like you haven't added any products or services to your cart yet.</p>
                <a href="../index.php" class="btn-start-shopping">
                    <i class="fas fa-store"></i> Start Shopping
                </a>
            </div>
        <?php else: ?>
            <div class="cart-layout">
                <!-- Cart Table -->
                <div class="cart-table-wrapper">
                    <table class="cart-table">
                        <thead>
                            <tr>
                                <th>Product / Service</th>
                                <th>Price</th>
                                <th>Quantity</th>
                                <th>Subtotal</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cartItems as $item):
                                $isProduct  = !empty($item['item_id']);
                                $name       = $isProduct ? $item['item_name'] : $item['service_name'];
                                $price      = $isProduct ? $item['unit_price'] : $item['base_price'];
                                $qty        = (int) $item['quantity'];
                                $rowTotal   = $price * $qty;
                            ?>
                            <tr>
                                <!-- Product / Service -->
                                <td data-label="Item">
                                    <div class="cart-product">
                                        <?php if ($isProduct && !empty($item['item_image'])): ?>
                                            <img src="../uploads/<?= htmlspecialchars($item['item_image']) ?>" alt="<?= htmlspecialchars($name) ?>" class="cart-product-img">
                                        <?php elseif ($isProduct): ?>
                                            <div class="cart-product-icon" style="background:linear-gradient(135deg,#2c3e50,#34495e);">
                                                <i class="fas fa-box"></i>
                                            </div>
                                        <?php else: ?>
                                            <div class="cart-product-icon">
                                                <i class="fas fa-wrench"></i>
                                            </div>
                                        <?php endif; ?>
                                        <div class="cart-product-info">
                                            <h4><?= htmlspecialchars($name) ?></h4>
                                            <?php if ($isProduct): ?>
                                                <span class="badge-product"><i class="fas fa-box"></i> Product</span>
                                                <?php if (!empty($item['item_category'])): ?>
                                                    <span> &middot; <?= htmlspecialchars($item['item_category']) ?></span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="badge-service"><i class="fas fa-wrench"></i> Service</span>
                                                <?php if (!empty($item['estimated_duration'])): ?>
                                                    <span> &middot; <?= htmlspecialchars($item['estimated_duration']) ?></span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>

                                <!-- Price -->
                                <td data-label="Price">
                                    <span class="cart-price">₱<?= number_format($price, 2) ?></span>
                                </td>

                                <!-- Quantity -->
                                <td data-label="Quantity">
                                    <?php if ($isProduct): ?>
                                        <form method="POST" action="cart.php" class="qty-control">
                                            <input type="hidden" name="action" value="update_qty">
                                            <input type="hidden" name="cart_id" value="<?= $item['cart_id'] ?>">
                                            <button type="submit" name="quantity" value="<?= max(1, $qty - 1) ?>" title="Decrease">
                                                <i class="fas fa-minus"></i>
                                            </button>
                                            <input type="text" value="<?= $qty ?>" readonly>
                                            <button type="submit" name="quantity" value="<?= min($item['stock'], $qty + 1) ?>" title="Increase">
                                                <i class="fas fa-plus"></i>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="qty-fixed">1</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Subtotal -->
                                <td data-label="Subtotal">
                                    <span class="cart-subtotal">₱<?= number_format($rowTotal, 2) ?></span>
                                </td>

                                <!-- Remove -->
                                <td data-label="">
                                    <form method="POST" action="cart.php">
                                        <input type="hidden" name="action" value="remove">
                                        <input type="hidden" name="cart_id" value="<?= $item['cart_id'] ?>">
                                        <button type="submit" class="btn-remove" title="Remove item" onclick="return confirm('Remove this item from your cart?');">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <!-- Cart Actions Bar -->
                    <div class="cart-actions-bar">
                        <a href="../index.php" class="btn-continue">
                            <i class="fas fa-arrow-left"></i> Continue Shopping
                        </a>
                        <form method="POST" action="cart.php">
                            <input type="hidden" name="action" value="clear">
                            <button type="submit" class="btn-clear-cart" onclick="return confirm('Clear all items from your cart?');">
                                <i class="fas fa-trash"></i> Clear Cart
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Cart Summary Sidebar -->
                <div class="cart-summary">
                    <h3><i class="fas fa-receipt"></i> Order Summary</h3>

                    <div class="summary-row">
                        <span>Subtotal (<?= $cartCount ?> item<?= $cartCount > 1 ? 's' : '' ?>)</span>
                        <span>₱<?= number_format($subtotal, 2) ?></span>
                    </div>
                    <div class="summary-row tax">
                        <span>VAT (12%)</span>
                        <span>₱<?= number_format($tax, 2) ?></span>
                    </div>
                    <div class="summary-row total">
                        <span>Total</span>
                        <span>₱<?= number_format($total, 2) ?></span>
                    </div>

                    <a href="checkout.php" class="btn-checkout">
                        <i class="fas fa-lock"></i> Proceed to Checkout
                    </a>
                    <a href="../index.php" class="btn-shop-more">
                        <i class="fas fa-store"></i> Continue Shopping
                    </a>

                    <div class="summary-info">
                        <i class="fas fa-shield-alt"></i>
                        <span>Secure checkout &middot; 100% money-back guarantee</span>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- ========== FOOTER ========== -->
<footer class="cart-footer">
    <div class="container">
        <p>&copy; 2026 <a href="../index.php">VehiCare</a>. All rights reserved. | Designed for Vehicle Service DB</p>
    </div>
</footer>

<!-- ========== JAVASCRIPT ========== -->
<script>
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
