<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'customer') {
    header('Location: login.php');
    exit;
}

$client_id = $_SESSION['client_id'];
$user_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'];

// Fetch all invoices for this client
$stmt = $pdo->prepare("
    SELECT i.invoice_id, i.vehicle_id, i.subtotal, i.tax_amount, i.total_amount, i.status, i.created_at,
           v.plate_number, v.make, v.model
    FROM invoices i
    LEFT JOIN vehicles v ON i.vehicle_id = v.vehicle_id
    WHERE i.client_id = ?
    ORDER BY i.created_at DESC
");
$stmt->execute([$client_id]);
$invoices = $stmt->fetchAll();

// Fetch invoice items
$invoiceItems = [];
if (!empty($invoices)) {
    $invIds = array_column($invoices, 'invoice_id');
    $placeholders = implode(',', array_fill(0, count($invIds), '?'));
    $itemsStmt = $pdo->prepare("
        SELECT ii.invoice_id, ii.invoice_item_id, ii.item_id, ii.service_id, ii.quantity, ii.unit_price,
               (ii.quantity * ii.unit_price) AS total_price,
               inv.item_name, s.service_name
        FROM invoice_items ii
        LEFT JOIN inventory inv ON ii.item_id = inv.item_id
        LEFT JOIN services s ON ii.service_id = s.service_id
        WHERE ii.invoice_id IN ($placeholders)
        ORDER BY ii.invoice_item_id ASC
    ");
    $itemsStmt->execute($invIds);
    foreach ($itemsStmt->fetchAll() as $item) {
        $invoiceItems[$item['invoice_id']][] = $item;
    }
}

// Fetch payments per invoice
$invoicePayments = [];
if (!empty($invoices)) {
    $invIds = array_column($invoices, 'invoice_id');
    $placeholders = implode(',', array_fill(0, count($invIds), '?'));
    $payStmt = $pdo->prepare("
        SELECT p.invoice_id, p.payment_id, p.amount_paid, p.payment_method, p.reference_number, p.payment_date
        FROM payments p
        WHERE p.invoice_id IN ($placeholders)
        ORDER BY p.payment_date ASC
    ");
    $payStmt->execute($invIds);
    foreach ($payStmt->fetchAll() as $pay) {
        $invoicePayments[$pay['invoice_id']][] = $pay;
    }
}

// Cart count
$cartStmt = $pdo->prepare("SELECT COUNT(*) FROM cart WHERE client_id = ?");
$cartStmt->execute([$client_id]);
$cartCount = $cartStmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Invoices - VehiCare</title>
    <link rel="stylesheet" href="../includes/style/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700;900&family=Oswald:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .invoices-section { padding: 60px 0; min-height: 60vh; background: #f8f9fa; }
        .invoices-section .container { max-width: 1000px; margin: 0 auto; padding: 0 20px; }
        .invoices-page-title { font-family: 'Oswald', sans-serif; font-size: 32px; font-weight: 700; color: #2c3e50; margin-bottom: 30px; display: flex; align-items: center; gap: 12px; }
        .invoices-page-title i { color: #e74c3c; }

        .inv-card { background: #fff; border-radius: 12px; box-shadow: 0 2px 15px rgba(0,0,0,0.06); margin-bottom: 20px; overflow: hidden; transition: box-shadow 0.3s ease; }
        .inv-card:hover { box-shadow: 0 4px 25px rgba(0,0,0,0.1); }
        .inv-card-header { display: flex; justify-content: space-between; align-items: center; padding: 20px 24px; cursor: pointer; user-select: none; transition: background 0.2s ease; flex-wrap: wrap; gap: 12px; }
        .inv-card-header:hover { background: #fafbfc; }
        .inv-header-left { display: flex; align-items: center; gap: 20px; flex-wrap: wrap; }
        .inv-id { font-family: 'Oswald', sans-serif; font-size: 18px; font-weight: 600; color: #2c3e50; }
        .inv-date { color: #7f8c8d; font-size: 14px; }
        .inv-date i { margin-right: 4px; }
        .inv-vehicle { color: #7f8c8d; font-size: 13px; background: #f0f0f0; padding: 4px 12px; border-radius: 20px; }
        .inv-header-right { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
        .inv-total { font-family: 'Oswald', sans-serif; font-size: 20px; font-weight: 700; color: #e74c3c; }
        .inv-toggle-icon { color: #bbb; font-size: 14px; transition: transform 0.3s ease; }
        .inv-card.active .inv-toggle-icon { transform: rotate(180deg); }

        .status-badge { display: inline-block; padding: 5px 14px; border-radius: 20px; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .status-unpaid { background: #fce4ec; color: #e74c3c; border: 1px solid #ef9a9a; }
        .status-partially-paid { background: #fff3e0; color: #e67e22; border: 1px solid #f0c27a; }
        .status-paid { background: #e8f5e9; color: #27ae60; border: 1px solid #a5d6a7; }

        .inv-details { max-height: 0; overflow: hidden; transition: max-height 0.4s ease; border-top: 0 solid #eee; }
        .inv-card.active .inv-details { max-height: 2000px; border-top: 1px solid #eee; }
        .inv-details-inner { padding: 20px 24px; }

        .inv-items-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        .inv-items-table thead { background: #f8f9fa; }
        .inv-items-table thead th { padding: 10px 14px; font-family: 'Oswald', sans-serif; font-weight: 500; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; color: #7f8c8d; text-align: left; border-bottom: 2px solid #eee; }
        .inv-items-table tbody td { padding: 12px 14px; font-size: 14px; color: #2c3e50; border-bottom: 1px solid #f0f0f0; }
        .inv-items-table tbody tr:last-child td { border-bottom: 0; }

        .item-type-badge { display: inline-block; font-size: 10px; padding: 2px 8px; border-radius: 10px; font-weight: 600; text-transform: uppercase; }
        .item-type-product { background: #e8f5e9; color: #2e7d32; }
        .item-type-service { background: #e3f2fd; color: #1565c0; }

        .inv-totals { display: flex; flex-direction: column; align-items: flex-end; gap: 6px; margin-bottom: 20px; font-size: 14px; }
        .inv-totals .inv-line { display: flex; gap: 20px; }
        .inv-totals .inv-line span:first-child { color: #7f8c8d; min-width: 100px; text-align: right; }
        .inv-totals .inv-line span:last-child { font-weight: 600; min-width: 100px; text-align: right; }
        .inv-totals .inv-grand { font-size: 18px; color: #e74c3c; border-top: 2px solid #eee; padding-top: 8px; }

        .pay-section { margin-top: 20px; padding-top: 16px; border-top: 1px solid #eee; }
        .pay-section h4 { font-family: 'Oswald', sans-serif; font-size: 15px; color: #2c3e50; margin: 0 0 12px; display: flex; align-items: center; gap: 8px; }
        .pay-section h4 i { color: #27ae60; }
        .pay-table { width: 100%; border-collapse: collapse; }
        .pay-table thead th { padding: 8px 12px; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; color: #7f8c8d; text-align: left; border-bottom: 2px solid #eee; background: #f8f9fa; }
        .pay-table tbody td { padding: 10px 12px; font-size: 13px; color: #2c3e50; border-bottom: 1px solid #f0f0f0; }
        .pay-table tbody tr:last-child td { border-bottom: 0; }
        .pay-none { color: #999; font-style: italic; font-size: 13px; }

        .invoices-empty { text-align: center; padding: 80px 20px; background: #fff; border-radius: 12px; box-shadow: 0 2px 15px rgba(0,0,0,0.06); }
        .invoices-empty i { font-size: 64px; color: #ddd; margin-bottom: 20px; }
        .invoices-empty h3 { font-family: 'Oswald', sans-serif; font-size: 24px; color: #2c3e50; margin-bottom: 10px; }
        .invoices-empty p { color: #7f8c8d; font-size: 15px; }

        .invoices-footer { background: #2c3e50; color: rgba(255,255,255,0.7); text-align: center; padding: 25px 0; font-size: 14px; }
        .invoices-footer a { color: #e74c3c; text-decoration: none; }

        @media (max-width: 768px) {
            .invoices-page-title { font-size: 24px; }
            .inv-card-header { padding: 16px 18px; }
            .inv-header-left, .inv-header-right { gap: 10px; }
            .inv-id { font-size: 16px; }
            .inv-total { font-size: 18px; }
            .inv-details-inner { padding: 16px 18px; overflow-x: auto; }
            .inv-items-table, .pay-table { min-width: 500px; }
            .inv-totals { align-items: stretch; }
        }
        @media (max-width: 480px) {
            .inv-card-header { flex-direction: column; align-items: flex-start; }
            .inv-header-right { width: 100%; justify-content: space-between; }
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
                        $nStmt->execute([$user_id]);
                        $nCount = (int)$nStmt->fetchColumn();
                        if ($nCount > 0): ?><span class="badge"><?= $nCount ?></span><?php endif; ?>
                </a>
                <a href="cart.php" class="header-icon" title="Cart">
                    <i class="fas fa-shopping-cart"></i>
                    <?php if ($cartCount > 0): ?><span class="badge"><?= $cartCount ?></span><?php endif; ?>
                </a>
                <a href="orders.php" class="header-icon" title="My Orders">
                    <i class="fas fa-receipt"></i>
                </a>
                <a href="invoices.php" class="header-icon" title="My Invoices" style="color:#e74c3c;">
                    <i class="fas fa-file-invoice-dollar"></i>
                </a>
            </div>
            <button class="mobile-toggle" id="mobileToggle">
                <i class="fas fa-bars"></i>
            </button>
        </div>
    </div>
</header>

<!-- ========== INVOICES SECTION ========== -->
<section class="invoices-section">
    <div class="container">
        <h1 class="invoices-page-title"><i class="fas fa-file-invoice-dollar"></i> My Invoices</h1>

        <?php if (empty($invoices)): ?>
            <div class="invoices-empty">
                <i class="fas fa-file-invoice"></i>
                <h3>No invoices yet</h3>
                <p>You don't have any invoices. Invoices will appear here once your services are billed.</p>
            </div>
        <?php else: ?>
            <?php foreach ($invoices as $inv): ?>
                <?php
                    $statusSlug = strtolower(str_replace(' ', '-', $inv['status']));
                    $items = $invoiceItems[$inv['invoice_id']] ?? [];
                    $payments = $invoicePayments[$inv['invoice_id']] ?? [];
                    $totalPaid = array_sum(array_column($payments, 'amount_paid'));
                    $balance = $inv['total_amount'] - $totalPaid;
                ?>
                <div class="inv-card" id="inv-<?= $inv['invoice_id'] ?>">
                    <div class="inv-card-header" onclick="toggleInvoice(<?= $inv['invoice_id'] ?>)">
                        <div class="inv-header-left">
                            <span class="inv-id">INV-<?= $inv['invoice_id'] ?></span>
                            <span class="status-badge status-<?= $statusSlug ?>"><?= htmlspecialchars($inv['status']) ?></span>
                            <span class="inv-date">
                                <i class="far fa-calendar-alt"></i>
                                <?= date('M d, Y', strtotime($inv['created_at'])) ?>
                            </span>
                        </div>
                        <div class="inv-header-right">
                            <?php if ($inv['plate_number']): ?>
                                <span class="inv-vehicle">
                                    <i class="fas fa-car"></i>
                                    <?= htmlspecialchars($inv['make'] . ' ' . $inv['model'] . ' — ' . $inv['plate_number']) ?>
                                </span>
                            <?php endif; ?>
                            <span class="inv-total">₱<?= number_format($inv['total_amount'], 2) ?></span>
                            <i class="fas fa-chevron-down inv-toggle-icon"></i>
                        </div>
                    </div>
                    <div class="inv-details">
                        <div class="inv-details-inner">
                            <?php if (!empty($items)): ?>
                                <table class="inv-items-table">
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
                                                $name = $item['item_id'] ? ($item['item_name'] ?? 'Product') : ($item['service_name'] ?? 'Service');
                                                $type = $item['item_id'] ? 'Product' : 'Service';
                                                $typeClass = $item['item_id'] ? 'item-type-product' : 'item-type-service';
                                                $lineTotal = $item['quantity'] * $item['unit_price'];
                                            ?>
                                            <tr>
                                                <td><?= htmlspecialchars($name) ?></td>
                                                <td><span class="item-type-badge <?= $typeClass ?>"><?= $type ?></span></td>
                                                <td><?= (int) $item['quantity'] ?></td>
                                                <td>₱<?= number_format($item['unit_price'], 2) ?></td>
                                                <td><strong>₱<?= number_format($lineTotal, 2) ?></strong></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <p style="color: #999; font-style: italic;">No line items for this invoice.</p>
                            <?php endif; ?>

                            <!-- Totals -->
                            <div class="inv-totals">
                                <div class="inv-line">
                                    <span>Subtotal:</span>
                                    <span>₱<?= number_format($inv['subtotal'], 2) ?></span>
                                </div>
                                <div class="inv-line">
                                    <span>Tax (12%):</span>
                                    <span>₱<?= number_format($inv['tax_amount'], 2) ?></span>
                                </div>
                                <div class="inv-line inv-grand">
                                    <span>Total:</span>
                                    <span>₱<?= number_format($inv['total_amount'], 2) ?></span>
                                </div>
                                <?php if ($inv['status'] !== 'Paid'): ?>
                                <div class="inv-line" style="color:#e67e22;">
                                    <span>Paid:</span>
                                    <span>₱<?= number_format($totalPaid, 2) ?></span>
                                </div>
                                <div class="inv-line" style="color:#e74c3c;font-weight:700;">
                                    <span>Balance:</span>
                                    <span>₱<?= number_format(max(0, $balance), 2) ?></span>
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Payment History -->
                            <div class="pay-section">
                                <h4><i class="fas fa-money-check-alt"></i> Payment History</h4>
                                <?php if (!empty($payments)): ?>
                                    <table class="pay-table">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Amount</th>
                                                <th>Method</th>
                                                <th>Reference</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($payments as $pay): ?>
                                            <tr>
                                                <td><?= date('M d, Y h:i A', strtotime($pay['payment_date'])) ?></td>
                                                <td><strong>₱<?= number_format($pay['amount_paid'], 2) ?></strong></td>
                                                <td><?= htmlspecialchars($pay['payment_method'] ?? '—') ?></td>
                                                <td><?= htmlspecialchars($pay['reference_number'] ?? '—') ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php else: ?>
                                    <p class="pay-none"><i class="fas fa-info-circle"></i> No payments recorded yet.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>

<!-- ========== FOOTER ========== -->
<footer class="invoices-footer">
    <div class="container">
        <p>&copy; 2026 <a href="../index.php">VehiCare</a>. All rights reserved. | Designed for Vehicle Service DB</p>
    </div>
</footer>

<!-- ========== JAVASCRIPT ========== -->
<script>
function toggleInvoice(id) {
    var card = document.getElementById('inv-' + id);
    if (card) card.classList.toggle('active');
}

var mobileToggle = document.getElementById('mobileToggle');
if (mobileToggle) {
    mobileToggle.addEventListener('click', function () {
        document.querySelector('.main-nav').classList.toggle('active');
        this.querySelector('i').classList.toggle('fa-bars');
        this.querySelector('i').classList.toggle('fa-times');
    });
}

window.addEventListener('scroll', function () {
    var header = document.querySelector('.main-header');
    if (header) header.classList.toggle('sticky', window.scrollY > 100);
});
</script>

</body>
</html>
