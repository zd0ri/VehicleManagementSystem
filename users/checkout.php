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
$error = '';

// ── Fetch client info ──
$stmtClient = $pdo->prepare("SELECT full_name, email, phone, address FROM clients WHERE client_id = ?");
$stmtClient->execute([$client_id]);
$clientInfo = $stmtClient->fetch();

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

// ── If cart is empty, redirect ──
if (empty($cartItems)) {
    $_SESSION['cart_message'] = 'Your cart is empty. Add items before checking out.';
    header('Location: cart.php');
    exit;
}

// ── Cart count for badge ──
$cartCount = count($cartItems);

// ── Totals ──
$subtotal = 0;
$hasProducts = false;
$hasServices = false;
foreach ($cartItems as $item) {
    $price = $item['item_id'] ? $item['unit_price'] : $item['base_price'];
    $subtotal += $price * $item['quantity'];
    if ($item['item_id']) $hasProducts = true;
    if ($item['service_id']) $hasServices = true;
}
$tax   = $subtotal * 0.12;
$total = $subtotal + $tax;

// ── POST handler: Place Order ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
    $pay_method = $_POST['payment_method'] ?? 'Cash';
    $notes      = trim($_POST['notes'] ?? '');

    // Validate payment method
    $validMethods = ['Cash', 'Card', 'GCash', 'Bank Transfer'];
    if (!in_array($pay_method, $validMethods)) {
        $pay_method = 'Cash';
    }

    // Determine order type
    if ($hasProducts && !$hasServices) {
        $order_type = 'product';
    } elseif (!$hasProducts && $hasServices) {
        $order_type = 'service';
    } else {
        $order_type = 'product'; // mixed defaults to product
    }

    try {
        $pdo->beginTransaction();

        // Check stock availability for product items
        foreach ($cartItems as $item) {
            if ($item['item_id']) {
                $checkStock = $pdo->prepare("SELECT quantity FROM inventory WHERE item_id = ? FOR UPDATE");
                $checkStock->execute([$item['item_id']]);
                $currentStock = $checkStock->fetchColumn();
                if ($currentStock < $item['quantity']) {
                    throw new Exception('Insufficient stock for "' . $item['item_name'] . '". Available: ' . $currentStock . ', Requested: ' . $item['quantity']);
                }
            }
        }

        // Create order record
        $stmtOrder = $pdo->prepare("
            INSERT INTO orders (client_id, order_type, vehicle_id, subtotal, tax_amount, total_amount, status, payment_method, notes, created_at)
            VALUES (?, ?, NULL, ?, ?, ?, 'Pending', ?, ?, NOW())
        ");
        $stmtOrder->execute([$client_id, $order_type, $subtotal, $tax, $total, $pay_method, $notes]);
        $order_id = $pdo->lastInsertId();

        // Create order items and decrease inventory
        foreach ($cartItems as $item) {
            $item_id    = $item['item_id'] ?: null;
            $service_id = $item['service_id'] ?: null;
            $qty        = (int) $item['quantity'];
            $unit_price = $item['item_id'] ? $item['unit_price'] : $item['base_price'];

            $stmtOI = $pdo->prepare("
                INSERT INTO order_items (order_id, item_id, service_id, quantity, unit_price)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmtOI->execute([$order_id, $item_id, $service_id, $qty, $unit_price]);

            // Decrease inventory for product items
            if ($item['item_id']) {
                $stmtDec = $pdo->prepare("UPDATE inventory SET quantity = quantity - ? WHERE item_id = ?");
                $stmtDec->execute([$qty, $item['item_id']]);
            }
        }

        // Clear cart
        $stmtClear = $pdo->prepare("DELETE FROM cart WHERE client_id = ?");
        $stmtClear->execute([$client_id]);

        $pdo->commit();

        $_SESSION['order_success'] = 'Your order #' . $order_id . ' has been placed successfully!';
        header('Location: orders.php');
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - VehiCare</title>
    <link rel="stylesheet" href="../includes/style/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700;900&family=Oswald:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ── Checkout-specific styles ── */
        .checkout-section {
            padding: 60px 0;
            min-height: 60vh;
            background: #f8f9fa;
        }
        .checkout-section .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }
        .checkout-page-title {
            font-family: 'Oswald', sans-serif;
            font-size: 32px;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .checkout-page-title i {
            color: #e74c3c;
        }

        /* ── Two-column layout ── */
        .checkout-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            align-items: start;
        }

        /* ── Order Summary (left) ── */
        .order-summary-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 20px rgba(0,0,0,0.06);
            overflow: hidden;
        }
        .order-summary-card h3 {
            font-family: 'Oswald', sans-serif;
            font-size: 20px;
            font-weight: 600;
            color: #fff;
            background: #2c3e50;
            padding: 18px 24px;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .order-summary-card h3 i {
            color: #e74c3c;
        }
        .order-items-list {
            padding: 0;
            margin: 0;
            list-style: none;
        }
        .order-items-list li {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 24px;
            border-bottom: 1px solid #f0f0f0;
            gap: 15px;
        }
        .order-items-list li:last-child {
            border-bottom: none;
        }
        .order-item-left {
            display: flex;
            align-items: center;
            gap: 14px;
            flex: 1;
            min-width: 0;
        }
        .order-item-img {
            width: 56px;
            height: 56px;
            border-radius: 8px;
            object-fit: cover;
            border: 1px solid #eee;
            background: #f5f5f5;
            flex-shrink: 0;
        }
        .order-item-icon {
            width: 56px;
            height: 56px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 22px;
            flex-shrink: 0;
        }
        .order-item-icon.product {
            background: linear-gradient(135deg, #2c3e50, #34495e);
        }
        .order-item-icon.service {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
        }
        .order-item-details {
            min-width: 0;
        }
        .order-item-details h4 {
            font-size: 14px;
            font-weight: 600;
            color: #2c3e50;
            margin: 0 0 3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .order-item-details span {
            font-size: 12px;
            color: #888;
        }
        .order-item-right {
            text-align: right;
            flex-shrink: 0;
        }
        .order-item-right .item-qty {
            font-size: 12px;
            color: #888;
            display: block;
        }
        .order-item-right .item-total {
            font-size: 15px;
            font-weight: 700;
            color: #2c3e50;
        }

        /* ── Totals ── */
        .order-totals {
            padding: 20px 24px;
            background: #fafbfc;
            border-top: 2px solid #f0f0f0;
        }
        .order-totals .totals-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            font-size: 14px;
            color: #555;
        }
        .order-totals .totals-row.tax {
            color: #888;
            font-size: 13px;
        }
        .order-totals .totals-row.grand-total {
            border-top: 2px solid #2c3e50;
            margin-top: 10px;
            padding-top: 14px;
            font-size: 20px;
            font-weight: 700;
            color: #2c3e50;
        }
        .order-totals .totals-row.grand-total span:last-child {
            color: #e74c3c;
        }

        /* ── Checkout Form (right) ── */
        .checkout-form-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 20px rgba(0,0,0,0.06);
            overflow: hidden;
        }
        .checkout-form-card h3 {
            font-family: 'Oswald', sans-serif;
            font-size: 20px;
            font-weight: 600;
            color: #fff;
            background: #2c3e50;
            padding: 18px 24px;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .checkout-form-card h3 i {
            color: #e74c3c;
        }
        .checkout-form {
            padding: 24px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .form-group label i {
            color: #e74c3c;
            margin-right: 5px;
            width: 16px;
            text-align: center;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 14px;
            border: 2px solid #e8e8e8;
            border-radius: 8px;
            font-size: 14px;
            font-family: 'Roboto', sans-serif;
            color: #2c3e50;
            background: #fff;
            transition: border-color 0.3s, box-shadow 0.3s;
            box-sizing: border-box;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #e74c3c;
            box-shadow: 0 0 0 3px rgba(231, 76, 60, 0.1);
        }
        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }
        .form-group select {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23888' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
            padding-right: 36px;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .form-divider {
            border: none;
            border-top: 1px solid #eee;
            margin: 24px 0;
        }
        .form-section-title {
            font-family: 'Oswald', sans-serif;
            font-size: 15px;
            font-weight: 600;
            color: #2c3e50;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .form-section-title i {
            color: #e74c3c;
        }

        /* ── Place Order Button ── */
        .btn-place-order {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-family: 'Oswald', sans-serif;
            font-size: 18px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn-place-order:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(231, 76, 60, 0.4);
        }
        .btn-place-order:active {
            transform: translateY(0);
        }

        .btn-back-cart {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 14px;
            padding: 10px 20px;
            font-size: 14px;
            font-weight: 500;
            color: #555;
            background: #f0f0f0;
            border-radius: 8px;
            text-decoration: none;
            transition: background 0.2s, color 0.2s;
            width: 100%;
            justify-content: center;
        }
        .btn-back-cart:hover {
            background: #e0e0e0;
            color: #2c3e50;
        }

        /* ── Security note ── */
        .security-note {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 18px;
            padding: 12px 16px;
            background: #f0fdf4;
            border-radius: 8px;
            font-size: 12px;
            color: #16a34a;
        }
        .security-note i {
            font-size: 16px;
        }

        /* ── Error alert ── */
        .checkout-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
            padding: 14px 20px;
            border-radius: 10px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
        }
        .checkout-error i {
            font-size: 18px;
            flex-shrink: 0;
        }

        /* ── Responsive ── */
        @media (max-width: 992px) {
            .checkout-layout {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 768px) {
            .checkout-page-title {
                font-size: 24px;
            }
            .form-row {
                grid-template-columns: 1fr;
                gap: 0;
            }
            .order-items-list li {
                padding: 14px 18px;
            }
        }
        @media (max-width: 480px) {
            .order-item-details h4 {
                max-width: 120px;
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
            </div>
            <button class="mobile-toggle" id="mobileToggle">
                <i class="fas fa-bars"></i>
            </button>
        </div>
    </div>
</header>

<!-- ========== CHECKOUT SECTION ========== -->
<section class="checkout-section">
    <div class="container">
        <h1 class="checkout-page-title"><i class="fas fa-credit-card"></i> Checkout</h1>

        <?php if ($error): ?>
            <div class="checkout-error">
                <i class="fas fa-exclamation-circle"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <div class="checkout-layout">
            <!-- ===== LEFT: Order Summary ===== -->
            <div class="order-summary-card">
                <h3><i class="fas fa-receipt"></i> Order Summary</h3>
                <ul class="order-items-list">
                    <?php foreach ($cartItems as $item):
                        $isProduct = !empty($item['item_id']);
                        $name      = $isProduct ? $item['item_name'] : $item['service_name'];
                        $price     = $isProduct ? $item['unit_price'] : $item['base_price'];
                        $qty       = (int) $item['quantity'];
                        $rowTotal  = $price * $qty;
                    ?>
                    <li>
                        <div class="order-item-left">
                            <?php if ($isProduct && !empty($item['item_image'])): ?>
                                <img src="../uploads/<?= htmlspecialchars($item['item_image']) ?>" alt="<?= htmlspecialchars($name) ?>" class="order-item-img">
                            <?php elseif ($isProduct): ?>
                                <div class="order-item-icon product"><i class="fas fa-box"></i></div>
                            <?php else: ?>
                                <div class="order-item-icon service"><i class="fas fa-wrench"></i></div>
                            <?php endif; ?>
                            <div class="order-item-details">
                                <h4><?= htmlspecialchars($name) ?></h4>
                                <span>₱<?= number_format($price, 2) ?> <?= $isProduct ? '× ' . $qty : '' ?></span>
                            </div>
                        </div>
                        <div class="order-item-right">
                            <?php if ($isProduct && $qty > 1): ?>
                                <span class="item-qty">Qty: <?= $qty ?></span>
                            <?php endif; ?>
                            <span class="item-total">₱<?= number_format($rowTotal, 2) ?></span>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <div class="order-totals">
                    <div class="totals-row">
                        <span>Subtotal (<?= $cartCount ?> item<?= $cartCount > 1 ? 's' : '' ?>)</span>
                        <span>₱<?= number_format($subtotal, 2) ?></span>
                    </div>
                    <div class="totals-row tax">
                        <span>VAT (12%)</span>
                        <span>₱<?= number_format($tax, 2) ?></span>
                    </div>
                    <div class="totals-row grand-total">
                        <span>Total</span>
                        <span>₱<?= number_format($total, 2) ?></span>
                    </div>
                </div>
            </div>

            <!-- ===== RIGHT: Checkout Form ===== -->
            <div class="checkout-form-card">
                <h3><i class="fas fa-file-invoice"></i> Billing Details</h3>
                <form method="POST" action="checkout.php" class="checkout-form">

                    <div class="form-section-title"><i class="fas fa-user-circle"></i> Customer Information</div>

                    <div class="form-row">
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Full Name</label>
                            <input type="text" name="full_name" value="<?= htmlspecialchars($clientInfo['full_name'] ?? '') ?>" readonly>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-envelope"></i> Email</label>
                            <input type="email" name="email" value="<?= htmlspecialchars($clientInfo['email'] ?? '') ?>" readonly>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label><i class="fas fa-phone"></i> Phone</label>
                            <input type="text" name="phone" value="<?= htmlspecialchars($clientInfo['phone'] ?? '') ?>" readonly>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-map-marker-alt"></i> Address</label>
                            <input type="text" name="address" value="<?= htmlspecialchars($clientInfo['address'] ?? '') ?>" readonly>
                        </div>
                    </div>

                    <hr class="form-divider">

                    <div class="form-section-title"><i class="fas fa-wallet"></i> Payment</div>

                    <div class="form-group">
                        <label><i class="fas fa-credit-card"></i> Payment Method</label>
                        <select name="payment_method" required>
                            <option value="Cash">Cash</option>
                            <option value="Card">Card</option>
                            <option value="GCash">GCash</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-sticky-note"></i> Order Notes <span style="font-weight:400;color:#aaa;">(optional)</span></label>
                        <textarea name="notes" placeholder="Any special instructions or requests..." rows="3"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                    </div>

                    <button type="submit" name="place_order" class="btn-place-order">
                        <i class="fas fa-lock"></i> Place Order &mdash; ₱<?= number_format($total, 2) ?>
                    </button>

                    <a href="cart.php" class="btn-back-cart">
                        <i class="fas fa-arrow-left"></i> Back to Cart
                    </a>

                    <div class="security-note">
                        <i class="fas fa-shield-alt"></i>
                        <span>Your order is secure. We do not store payment card details.</span>
                    </div>
                </form>
            </div>
        </div>
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
