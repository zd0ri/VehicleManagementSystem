<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') { header('Location: ../users/login.php'); exit; }
$page_title = 'Transactions'; $current_page = 'transactions';
require_once __DIR__ . '/../includes/db.php';

// ── Date range filter ──
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$filter_type = $_GET['type'] ?? '';

// ── Summary Stats ──
$total_order_revenue = $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE status != 'Cancelled'")->fetchColumn();
$total_payment_revenue = $pdo->query("SELECT COALESCE(SUM(amount_paid),0) FROM payments")->fetchColumn();
$total_revenue = $total_order_revenue + $total_payment_revenue;

$total_orders = $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
$total_payments = $pdo->query("SELECT COUNT(*) FROM payments")->fetchColumn();

$pending_orders = $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'Pending'")->fetchColumn();
$completed_orders = $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'Completed'")->fetchColumn();

// ── Build unified transaction list ──
// Orders
$order_sql = "SELECT o.order_id, o.order_type, o.total_amount AS amount, o.payment_method, o.status, o.created_at, o.receipt_image,
              c.full_name AS client_name, 'order' AS source
              FROM orders o LEFT JOIN clients c ON o.client_id = c.client_id
              WHERE DATE(o.created_at) BETWEEN ? AND ?";
$order_params = [$date_from, $date_to];

// Payments (invoice payments)
$payment_sql = "SELECT p.payment_id, 'invoice' AS order_type, p.amount_paid AS amount, p.payment_method, 
                CASE WHEN i.status = 'Paid' THEN 'Completed' WHEN i.status = 'Partially Paid' THEN 'Processing' ELSE 'Pending' END AS status,
                p.payment_date AS created_at, NULL AS receipt_image,
                c.full_name AS client_name, 'payment' AS source
                FROM payments p 
                LEFT JOIN invoices i ON p.invoice_id = i.invoice_id 
                LEFT JOIN clients c ON i.client_id = c.client_id
                WHERE DATE(p.payment_date) BETWEEN ? AND ?";
$payment_params = [$date_from, $date_to];

// Combine based on filter
if ($filter_type === 'orders') {
    $combined_sql = "$order_sql ORDER BY created_at DESC";
    $combined_params = $order_params;
} elseif ($filter_type === 'payments') {
    $combined_sql = "$payment_sql ORDER BY created_at DESC";
    $combined_params = $payment_params;
} else {
    $combined_sql = "($order_sql) UNION ALL ($payment_sql) ORDER BY created_at DESC";
    $combined_params = array_merge($order_params, $payment_params);
}

$stmt = $pdo->prepare($combined_sql);
$stmt->execute($combined_params);
$transactions = $stmt->fetchAll();

// ── Period stats ──
$period_order = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE status != 'Cancelled' AND DATE(created_at) BETWEEN ? AND ?");
$period_order->execute([$date_from, $date_to]);
$period_order_total = $period_order->fetchColumn();

$period_payment = $pdo->prepare("SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE DATE(payment_date) BETWEEN ? AND ?");
$period_payment->execute([$date_from, $date_to]);
$period_payment_total = $period_payment->fetchColumn();

$period_total = $period_order_total + $period_payment_total;

// ── Payment method breakdown (period) ──
$method_breakdown = $pdo->prepare("
    SELECT payment_method, SUM(amount) AS total FROM (
        SELECT payment_method, total_amount AS amount FROM orders WHERE status != 'Cancelled' AND DATE(created_at) BETWEEN ? AND ?
        UNION ALL
        SELECT payment_method, amount_paid AS amount FROM payments WHERE DATE(payment_date) BETWEEN ? AND ?
    ) combined GROUP BY payment_method ORDER BY total DESC
");
$method_breakdown->execute([$date_from, $date_to, $date_from, $date_to]);
$methods = $method_breakdown->fetchAll();
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

            <div class="page-header">
                <div>
                    <h1><i class="fas fa-exchange-alt"></i> Transactions</h1>
                    <p>Unified view of all orders and payments</p>
                </div>
            </div>

            <!-- Summary Cards -->
            <div class="stats-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:24px;">
                <div class="stat-card">
                    <div class="stat-icon" style="background:rgba(39,174,96,0.15);color:#27ae60;"><i class="fas fa-peso-sign"></i></div>
                    <div class="stat-info">
                        <span class="stat-label">Total Revenue</span>
                        <span class="stat-value">₱<?= number_format($total_revenue, 2) ?></span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background:rgba(52,152,219,0.15);color:#3498db;"><i class="fas fa-shopping-cart"></i></div>
                    <div class="stat-info">
                        <span class="stat-label">Total Orders</span>
                        <span class="stat-value"><?= $total_orders ?></span>
                        <span class="stat-trend"><?= $pending_orders ?> pending &bull; <?= $completed_orders ?> completed</span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background:rgba(155,89,182,0.15);color:#9b59b6;"><i class="fas fa-credit-card"></i></div>
                    <div class="stat-info">
                        <span class="stat-label">Invoice Payments</span>
                        <span class="stat-value"><?= $total_payments ?></span>
                        <span class="stat-trend">₱<?= number_format($total_payment_revenue, 2) ?> total</span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background:rgba(243,156,18,0.15);color:#f39c12;"><i class="fas fa-calendar-day"></i></div>
                    <div class="stat-info">
                        <span class="stat-label">Period Revenue</span>
                        <span class="stat-value">₱<?= number_format($period_total, 2) ?></span>
                        <span class="stat-trend"><?= date('M d', strtotime($date_from)) ?> – <?= date('M d, Y', strtotime($date_to)) ?></span>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="admin-card" style="margin-bottom:20px;">
                <div class="card-body" style="padding:16px;">
                    <form method="GET" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
                        <div class="form-group" style="margin:0;">
                            <label style="font-size:12px;color:#888;margin-bottom:4px;display:block;">Date From</label>
                            <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" class="form-control" style="width:auto;">
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label style="font-size:12px;color:#888;margin-bottom:4px;display:block;">Date To</label>
                            <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" class="form-control" style="width:auto;">
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label style="font-size:12px;color:#888;margin-bottom:4px;display:block;">Type</label>
                            <select name="type" class="form-control" style="width:auto;">
                                <option value="" <?= !$filter_type ? 'selected' : '' ?>>All Transactions</option>
                                <option value="orders" <?= $filter_type === 'orders' ? 'selected' : '' ?>>Orders Only</option>
                                <option value="payments" <?= $filter_type === 'payments' ? 'selected' : '' ?>>Invoice Payments Only</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
                        <a href="transactions.php" class="btn btn-secondary"><i class="fas fa-undo"></i> Reset</a>
                    </form>
                </div>
            </div>

            <!-- Payment Method Breakdown -->
            <?php if (!empty($methods)): ?>
            <div class="admin-card" style="margin-bottom:20px;">
                <div class="card-body" style="padding:16px;">
                    <h3 style="font-size:14px;color:#888;margin-bottom:12px;"><i class="fas fa-chart-bar"></i> Payment Method Breakdown (Selected Period)</h3>
                    <div style="display:flex;gap:16px;flex-wrap:wrap;">
                        <?php foreach ($methods as $m):
                            $pct = $period_total > 0 ? round(($m['total'] / $period_total) * 100) : 0;
                            $color = match($m['payment_method']) {
                                'Cash' => '#27ae60', 'GCash' => '#007bff', 'Maya' => '#6f42c1',
                                default => '#888'
                            };
                        ?>
                        <div style="flex:1;min-width:150px;background:rgba(255,255,255,0.05);border-radius:8px;padding:12px;border:1px solid rgba(255,255,255,0.1);">
                            <div style="font-size:12px;color:#888;"><?= htmlspecialchars($m['payment_method'] ?? 'Unknown') ?></div>
                            <div style="font-size:20px;font-weight:700;color:<?= $color ?>;">₱<?= number_format($m['total'], 2) ?></div>
                            <div style="margin-top:6px;height:4px;background:rgba(255,255,255,0.1);border-radius:2px;">
                                <div style="height:100%;width:<?= $pct ?>%;background:<?= $color ?>;border-radius:2px;"></div>
                            </div>
                            <div style="font-size:11px;color:#888;margin-top:4px;"><?= $pct ?>% of total</div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Transactions Table -->
            <div class="admin-card">
                <div class="table-toolbar" style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap;padding:16px;">
                    <div class="table-search" style="flex:1;max-width:300px;"><i class="fas fa-search"></i>
                        <input type="text" id="searchInput" placeholder="Search transactions..." onkeyup="searchTable()">
                    </div>
                    <span style="color:#888;font-size:13px;"><?= count($transactions) ?> transaction<?= count($transactions) !== 1 ? 's' : '' ?> found</span>
                </div>
                <div class="card-body" style="padding:0;">
                    <?php if (empty($transactions)): ?>
                        <div class="empty-state"><i class="fas fa-exchange-alt"></i><h3>No transactions found</h3><p>Try adjusting the date range or filter.</p></div>
                    <?php else: ?>
                    <table class="admin-table" id="transTable">
                        <thead>
                            <tr>
                                <th>Ref</th>
                                <th>Source</th>
                                <th>Client</th>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Status</th>
                                <th>Receipt</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($transactions as $t): ?>
                        <tr>
                            <td style="font-weight:600;">
                                <?php if ($t['source'] === 'order'): ?>
                                    #<?= $t['order_id'] ?>
                                <?php else: ?>
                                    PAY-<?= $t['payment_id'] ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($t['source'] === 'order'): ?>
                                    <span class="badge" style="background:rgba(52,152,219,0.15);color:#3498db;"><i class="fas fa-shopping-cart"></i> Order</span>
                                <?php else: ?>
                                    <span class="badge" style="background:rgba(155,89,182,0.15);color:#9b59b6;"><i class="fas fa-file-invoice"></i> Invoice</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($t['client_name'] ?? 'N/A') ?></td>
                            <td><span class="badge badge-info"><?= ucfirst($t['order_type'] ?? '-') ?></span></td>
                            <td style="font-weight:700;color:#27ae60;">₱<?= number_format($t['amount'], 2) ?></td>
                            <td>
                                <?php if (in_array($t['payment_method'], ['GCash', 'Maya'])): ?>
                                    <span style="color:#007bff;font-weight:600;"><i class="fas fa-wallet"></i> E-Wallet (<?= htmlspecialchars($t['payment_method']) ?>)</span>
                                <?php else: ?>
                                    <i class="fas fa-money-bill-wave" style="color:#27ae60;"></i> <?= htmlspecialchars($t['payment_method'] ?? '-') ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                    $sc = match($t['status']) {
                                        'Completed' => 'badge-finished',
                                        'Processing' => 'badge-ongoing',
                                        'Cancelled' => 'badge-cancelled',
                                        default => 'badge-assigned'
                                    };
                                ?>
                                <span class="badge <?= $sc ?>"><?= htmlspecialchars($t['status']) ?></span>
                            </td>
                            <td>
                                <?php if ($t['receipt_image']): ?>
                                    <a href="../uploads/<?= htmlspecialchars($t['receipt_image']) ?>" target="_blank" style="color:#27ae60;"><i class="fas fa-image"></i> View</a>
                                <?php else: ?>
                                    <span style="color:#666;">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="white-space:nowrap;"><?= date('M d, Y h:i A', strtotime($t['created_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </main>
</div>
<script src="includes/admin.js"></script>
<script>
function searchTable() {
    var q = document.getElementById('searchInput').value.toLowerCase();
    document.querySelectorAll('#transTable tbody tr').forEach(function(r) {
        r.style.display = r.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}
</script>
</body>
</html>
