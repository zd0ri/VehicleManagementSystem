<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') { header('Location: ../users/login.php'); exit; }
$page_title = 'Transactions'; $current_page = 'transactions';
require_once __DIR__ . '/../includes/db.php';

// ── Period & date filter ──
$period = $_GET['period'] ?? 'month';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$filter_type = $_GET['type'] ?? '';
$tab = $_GET['tab'] ?? 'revenue';

$today = date('Y-m-d');
if ($period === 'today') {
    $date_from = $today;
    $date_to = $today;
} elseif ($period === 'month') {
    $date_from = date('Y-m-01');
    $date_to = $today;
} elseif ($period === 'year') {
    $date_from = date('Y-01-01');
    $date_to = $today;
} elseif ($period === 'all') {
    $date_from = '2000-01-01';
    $date_to = '2099-12-31';
} elseif ($period === 'custom') {
    if (!$date_from) $date_from = date('Y-m-01');
    if (!$date_to) $date_to = $today;
}

// ============================================================
// REVENUE - Orders + Invoice Payments for selected period
// ============================================================
$rev_order = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE status != 'Cancelled' AND DATE(created_at) BETWEEN ? AND ?");
$rev_order->execute([$date_from, $date_to]);
$period_order_rev = (float)$rev_order->fetchColumn();

$rev_pay = $pdo->prepare("SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE DATE(payment_date) BETWEEN ? AND ?");
$rev_pay->execute([$date_from, $date_to]);
$period_pay_rev = (float)$rev_pay->fetchColumn();

$period_revenue = $period_order_rev + $period_pay_rev;

// ============================================================
// EXPENSES - Purchase Orders for selected period
// ============================================================
$exp_stmt = $pdo->prepare("SELECT COALESCE(SUM(total_cost),0) FROM purchase_orders WHERE status = 'Received' AND DATE(created_at) BETWEEN ? AND ?");
$exp_stmt->execute([$date_from, $date_to]);
$period_expenses = (float)$exp_stmt->fetchColumn();

$period_profit = $period_revenue - $period_expenses;

// ============================================================
// QUICK COMPARISON STATS - Today / Month / Year
// ============================================================
function getRevenue($pdo, $from, $to) {
    $o = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE status != 'Cancelled' AND DATE(created_at) BETWEEN ? AND ?");
    $o->execute([$from, $to]);
    $p = $pdo->prepare("SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE DATE(payment_date) BETWEEN ? AND ?");
    $p->execute([$from, $to]);
    return (float)$o->fetchColumn() + (float)$p->fetchColumn();
}
function getExpenses($pdo, $from, $to) {
    $e = $pdo->prepare("SELECT COALESCE(SUM(total_cost),0) FROM purchase_orders WHERE status = 'Received' AND DATE(created_at) BETWEEN ? AND ?");
    $e->execute([$from, $to]);
    return (float)$e->fetchColumn();
}

$today_rev = getRevenue($pdo, $today, $today);
$today_exp = getExpenses($pdo, $today, $today);
$month_rev = getRevenue($pdo, date('Y-m-01'), $today);
$month_exp = getExpenses($pdo, date('Y-m-01'), $today);
$year_rev = getRevenue($pdo, date('Y-01-01'), $today);
$year_exp = getExpenses($pdo, date('Y-01-01'), $today);

// ============================================================
// REVENUE TRANSACTIONS LIST
// ============================================================
$order_sql = "SELECT o.order_id, o.order_type, o.total_amount AS amount, o.payment_method, o.status, o.created_at, o.receipt_image,
              c.full_name AS client_name, 'order' AS source
              FROM orders o LEFT JOIN clients c ON o.client_id = c.client_id
              WHERE DATE(o.created_at) BETWEEN ? AND ?";
$payment_sql = "SELECT p.payment_id, 'invoice' AS order_type, p.amount_paid AS amount, p.payment_method, 
                CASE WHEN i.status = 'Paid' THEN 'Completed' WHEN i.status = 'Partially Paid' THEN 'Processing' ELSE 'Pending' END AS status,
                p.payment_date AS created_at, NULL AS receipt_image,
                c.full_name AS client_name, 'payment' AS source
                FROM payments p 
                LEFT JOIN invoices i ON p.invoice_id = i.invoice_id 
                LEFT JOIN clients c ON i.client_id = c.client_id
                WHERE DATE(p.payment_date) BETWEEN ? AND ?";

if ($filter_type === 'orders') {
    $combined_sql = "$order_sql ORDER BY created_at DESC";
    $combined_params = [$date_from, $date_to];
} elseif ($filter_type === 'payments') {
    $combined_sql = "$payment_sql ORDER BY created_at DESC";
    $combined_params = [$date_from, $date_to];
} else {
    $combined_sql = "($order_sql) UNION ALL ($payment_sql) ORDER BY created_at DESC";
    $combined_params = [$date_from, $date_to, $date_from, $date_to];
}

$stmt = $pdo->prepare($combined_sql);
$stmt->execute($combined_params);
$transactions = $stmt->fetchAll();

// ============================================================
// EXPENSE TRANSACTIONS LIST
// ============================================================
$exp_list_stmt = $pdo->prepare("
    SELECT po.po_id, po.quantity, po.unit_cost, po.total_cost, po.status, po.notes, po.created_at, po.received_at,
           s.supplier_name, inv.item_name, inv.category, u.full_name AS ordered_by
    FROM purchase_orders po
    LEFT JOIN suppliers s ON po.supplier_id = s.supplier_id
    LEFT JOIN inventory inv ON po.item_id = inv.item_id
    LEFT JOIN users u ON po.ordered_by = u.user_id
    WHERE DATE(po.created_at) BETWEEN ? AND ?
    ORDER BY po.created_at DESC
");
$exp_list_stmt->execute([$date_from, $date_to]);
$expense_list = $exp_list_stmt->fetchAll();

// ============================================================
// PAYMENT METHOD BREAKDOWN
// ============================================================
$method_breakdown = $pdo->prepare("
    SELECT payment_method, SUM(amount) AS total FROM (
        SELECT payment_method, total_amount AS amount FROM orders WHERE status != 'Cancelled' AND DATE(created_at) BETWEEN ? AND ?
        UNION ALL
        SELECT payment_method, amount_paid AS amount FROM payments WHERE DATE(payment_date) BETWEEN ? AND ?
    ) combined GROUP BY payment_method ORDER BY total DESC
");
$method_breakdown->execute([$date_from, $date_to, $date_from, $date_to]);
$methods = $method_breakdown->fetchAll();

// ============================================================
// EXPENSE CATEGORY BREAKDOWN
// ============================================================
$cat_breakdown = $pdo->prepare("
    SELECT COALESCE(inv.category, 'Uncategorized') AS category, SUM(po.total_cost) AS total
    FROM purchase_orders po
    LEFT JOIN inventory inv ON po.item_id = inv.item_id
    WHERE po.status = 'Received' AND DATE(po.created_at) BETWEEN ? AND ?
    GROUP BY category ORDER BY total DESC
");
$cat_breakdown->execute([$date_from, $date_to]);
$expense_cats = $cat_breakdown->fetchAll();

// Period label
$period_labels = ['today' => 'Today', 'month' => 'This Month', 'year' => 'This Year', 'all' => 'All Time', 'custom' => 'Custom Range'];
$period_label = $period_labels[$period] ?? 'This Month';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - VehiCare Admin</title>
    <link rel="stylesheet" href="../includes/style/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700;900&family=Oswald:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .quick-period { display:flex; gap:6px; flex-wrap:wrap; }
        .quick-period a { padding:6px 16px; border-radius:6px; font-size:13px; font-weight:600; text-decoration:none; border:1px solid #ddd; color:#888; transition:all .2s; }
        .quick-period a:hover { background:#f0f0f0; color:#333; }
        .quick-period a.active { background:#3498db; color:#fff; border-color:#3498db; }

        .overview-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:16px; margin-bottom:24px; }
        .overview-card { border-radius:12px; padding:20px; color:#fff; position:relative; overflow:hidden; }
        .overview-card .ov-icon { position:absolute; top:12px; right:16px; font-size:2.5rem; opacity:0.2; }
        .overview-card .ov-label { font-size:12px; text-transform:uppercase; letter-spacing:1px; opacity:0.85; margin-bottom:4px; }
        .overview-card .ov-amount { font-size:28px; font-weight:700; }
        .overview-card .ov-sub { font-size:12px; opacity:0.8; margin-top:6px; }

        .comparison-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:16px; margin-bottom:24px; }
        .comparison-card { background:#fff; border-radius:12px; padding:18px; box-shadow:0 2px 10px rgba(0,0,0,0.05); }
        .comparison-card h4 { margin:0 0 12px; font-size:14px; color:#888; font-weight:500; }
        .comparison-row { display:flex; justify-content:space-between; align-items:center; padding:6px 0; }
        .comparison-row .cr-label { font-size:13px; color:#555; display:flex; align-items:center; gap:6px; }
        .comparison-row .cr-value { font-weight:700; font-size:14px; }
        .comparison-row .cr-value.green { color:#27ae60; }
        .comparison-row .cr-value.red { color:#e74c3c; }
        .comparison-row .cr-value.blue { color:#3498db; }

        .tab-btns { display:flex; gap:0; border-bottom:2px solid #eee; margin-bottom:20px; }
        .tab-btns a { padding:12px 24px; font-size:14px; font-weight:600; color:#888; text-decoration:none; border-bottom:3px solid transparent; margin-bottom:-2px; transition:all .2s; }
        .tab-btns a:hover { color:#333; }
        .tab-btns a.active { color:#3498db; border-bottom-color:#3498db; }
        .tab-panel { display:none; }
        .tab-panel.active { display:block; }

        .breakdown-grid { display:flex; gap:12px; flex-wrap:wrap; }
        .breakdown-item { flex:1; min-width:140px; background:#f8f9fa; border-radius:8px; padding:12px; }
        .breakdown-item .bi-label { font-size:12px; color:#888; margin-bottom:2px; }
        .breakdown-item .bi-value { font-size:18px; font-weight:700; }
        .breakdown-item .bi-bar { height:4px; background:#eee; border-radius:2px; margin-top:6px; }
        .breakdown-item .bi-bar-fill { height:100%; border-radius:2px; }

        @media (max-width:768px) {
            .overview-grid, .comparison-grid { grid-template-columns:1fr; }
            .overview-card .ov-amount { font-size:22px; }
        }
    </style>
</head>
<body class="admin-body">
<div class="admin-layout">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <main class="admin-main">
        <?php include __DIR__ . '/includes/topbar.php'; ?>
        <div class="admin-content">

            <div class="page-header">
                <div>
                    <h1><i class="fas fa-chart-line"></i> Revenue & Expenses</h1>
                    <p>Track sales, payments, expenses, and net profit</p>
                </div>
            </div>

            <!-- ============ QUICK PERIOD SELECTOR ============ -->
            <div class="admin-card" style="margin-bottom:20px;">
                <div class="card-body" style="padding:16px;">
                    <div style="display:flex;gap:16px;align-items:flex-end;flex-wrap:wrap;">
                        <div>
                            <label style="font-size:12px;color:#888;margin-bottom:6px;display:block;font-weight:600;">Quick Filter</label>
                            <div class="quick-period">
                                <a href="?period=today&tab=<?= $tab ?>" class="<?= $period === 'today' ? 'active' : '' ?>"><i class="fas fa-sun"></i> Today</a>
                                <a href="?period=month&tab=<?= $tab ?>" class="<?= $period === 'month' ? 'active' : '' ?>"><i class="fas fa-calendar-week"></i> This Month</a>
                                <a href="?period=year&tab=<?= $tab ?>" class="<?= $period === 'year' ? 'active' : '' ?>"><i class="fas fa-calendar"></i> This Year</a>
                                <a href="?period=all&tab=<?= $tab ?>" class="<?= $period === 'all' ? 'active' : '' ?>"><i class="fas fa-infinity"></i> All Time</a>
                            </div>
                        </div>
                        <form method="GET" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-left:auto;">
                            <input type="hidden" name="period" value="custom">
                            <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
                            <div>
                                <label style="font-size:11px;color:#888;display:block;">From</label>
                                <input type="date" name="date_from" value="<?= htmlspecialchars($period === 'custom' ? $date_from : date('Y-m-01')) ?>" class="form-control" style="width:auto;font-size:13px;">
                            </div>
                            <div>
                                <label style="font-size:11px;color:#888;display:block;">To</label>
                                <input type="date" name="date_to" value="<?= htmlspecialchars($period === 'custom' ? $date_to : $today) ?>" class="form-control" style="width:auto;font-size:13px;">
                            </div>
                            <button type="submit" class="btn btn-primary" style="padding:7px 14px;font-size:13px;"><i class="fas fa-filter"></i> Filter</button>
                        </form>
                    </div>
                    <div style="margin-top:10px;font-size:12px;color:#888;">
                        <i class="fas fa-calendar-alt"></i> Showing: <strong><?= $period_label ?></strong>
                        <?php if ($period !== 'all'): ?>
                            (<?= date('M d, Y', strtotime($date_from)) ?> – <?= date('M d, Y', strtotime($date_to)) ?>)
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ============ OVERVIEW CARDS ============ -->
            <div class="overview-grid">
                <div class="overview-card" style="background:linear-gradient(135deg,#27ae60,#2ecc71);">
                    <i class="fas fa-peso-sign ov-icon"></i>
                    <div class="ov-label">Revenue (<?= $period_label ?>)</div>
                    <div class="ov-amount">₱<?= number_format($period_revenue, 2) ?></div>
                    <div class="ov-sub"><i class="fas fa-shopping-cart"></i> Orders: ₱<?= number_format($period_order_rev, 2) ?> &bull; <i class="fas fa-file-invoice"></i> Invoices: ₱<?= number_format($period_pay_rev, 2) ?></div>
                </div>
                <div class="overview-card" style="background:linear-gradient(135deg,#e74c3c,#c0392b);">
                    <i class="fas fa-arrow-down ov-icon"></i>
                    <div class="ov-label">Expenses (<?= $period_label ?>)</div>
                    <div class="ov-amount">₱<?= number_format($period_expenses, 2) ?></div>
                    <div class="ov-sub"><i class="fas fa-truck"></i> Purchase Orders (<?= count($expense_list) ?> transactions)</div>
                </div>
                <div class="overview-card" style="background:linear-gradient(135deg,<?= $period_profit >= 0 ? '#3498db,#2980b9' : '#e67e22,#d35400' ?>);">
                    <i class="fas fa-<?= $period_profit >= 0 ? 'trending-up' : 'trending-down' ?> ov-icon"></i>
                    <div class="ov-label">Net Profit (<?= $period_label ?>)</div>
                    <div class="ov-amount"><?= $period_profit < 0 ? '-' : '' ?>₱<?= number_format(abs($period_profit), 2) ?></div>
                    <div class="ov-sub"><?= $period_revenue > 0 ? round(($period_profit / $period_revenue) * 100, 1) . '% margin' : 'No revenue' ?></div>
                </div>
            </div>

            <!-- ============ COMPARISON CARDS ============ -->
            <div class="comparison-grid">
                <div class="comparison-card">
                    <h4><i class="fas fa-sun" style="color:#f39c12;"></i> Today</h4>
                    <div class="comparison-row">
                        <span class="cr-label"><i class="fas fa-arrow-up" style="color:#27ae60;"></i> Revenue</span>
                        <span class="cr-value green">₱<?= number_format($today_rev, 2) ?></span>
                    </div>
                    <div class="comparison-row">
                        <span class="cr-label"><i class="fas fa-arrow-down" style="color:#e74c3c;"></i> Expenses</span>
                        <span class="cr-value red">₱<?= number_format($today_exp, 2) ?></span>
                    </div>
                    <div class="comparison-row" style="border-top:1px solid #eee;padding-top:8px;margin-top:4px;">
                        <span class="cr-label"><i class="fas fa-equals" style="color:#3498db;"></i> Net</span>
                        <span class="cr-value blue"><?= ($today_rev - $today_exp) < 0 ? '-' : '' ?>₱<?= number_format(abs($today_rev - $today_exp), 2) ?></span>
                    </div>
                </div>
                <div class="comparison-card">
                    <h4><i class="fas fa-calendar-week" style="color:#3498db;"></i> This Month</h4>
                    <div class="comparison-row">
                        <span class="cr-label"><i class="fas fa-arrow-up" style="color:#27ae60;"></i> Revenue</span>
                        <span class="cr-value green">₱<?= number_format($month_rev, 2) ?></span>
                    </div>
                    <div class="comparison-row">
                        <span class="cr-label"><i class="fas fa-arrow-down" style="color:#e74c3c;"></i> Expenses</span>
                        <span class="cr-value red">₱<?= number_format($month_exp, 2) ?></span>
                    </div>
                    <div class="comparison-row" style="border-top:1px solid #eee;padding-top:8px;margin-top:4px;">
                        <span class="cr-label"><i class="fas fa-equals" style="color:#3498db;"></i> Net</span>
                        <span class="cr-value blue"><?= ($month_rev - $month_exp) < 0 ? '-' : '' ?>₱<?= number_format(abs($month_rev - $month_exp), 2) ?></span>
                    </div>
                </div>
                <div class="comparison-card">
                    <h4><i class="fas fa-calendar" style="color:#9b59b6;"></i> This Year</h4>
                    <div class="comparison-row">
                        <span class="cr-label"><i class="fas fa-arrow-up" style="color:#27ae60;"></i> Revenue</span>
                        <span class="cr-value green">₱<?= number_format($year_rev, 2) ?></span>
                    </div>
                    <div class="comparison-row">
                        <span class="cr-label"><i class="fas fa-arrow-down" style="color:#e74c3c;"></i> Expenses</span>
                        <span class="cr-value red">₱<?= number_format($year_exp, 2) ?></span>
                    </div>
                    <div class="comparison-row" style="border-top:1px solid #eee;padding-top:8px;margin-top:4px;">
                        <span class="cr-label"><i class="fas fa-equals" style="color:#3498db;"></i> Net</span>
                        <span class="cr-value blue"><?= ($year_rev - $year_exp) < 0 ? '-' : '' ?>₱<?= number_format(abs($year_rev - $year_exp), 2) ?></span>
                    </div>
                </div>
            </div>

            <!-- ============ TABS ============ -->
            <div class="tab-btns">
                <a href="?period=<?= $period ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&tab=revenue" class="<?= $tab === 'revenue' ? 'active' : '' ?>">
                    <i class="fas fa-arrow-up"></i> Revenue (<?= count($transactions) ?>)
                </a>
                <a href="?period=<?= $period ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&tab=expenses" class="<?= $tab === 'expenses' ? 'active' : '' ?>">
                    <i class="fas fa-arrow-down"></i> Expenses (<?= count($expense_list) ?>)
                </a>
                <a href="?period=<?= $period ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&tab=breakdown" class="<?= $tab === 'breakdown' ? 'active' : '' ?>">
                    <i class="fas fa-chart-pie"></i> Breakdown
                </a>
            </div>

            <!-- ============ REVENUE TAB ============ -->
            <div class="tab-panel <?= $tab === 'revenue' ? 'active' : '' ?>">
                <div class="admin-card">
                    <div class="table-toolbar" style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap;padding:16px;">
                        <div class="table-search" style="flex:1;max-width:300px;"><i class="fas fa-search"></i>
                            <input type="text" id="revSearch" placeholder="Search revenue..." onkeyup="searchRevTable()">
                        </div>
                        <select id="revTypeFilter" onchange="searchRevTable()" class="form-control" style="width:auto;min-width:150px;">
                            <option value="">All Sources</option>
                            <option value="order">Orders</option>
                            <option value="payment">Invoice Payments</option>
                        </select>
                        <span style="color:#888;font-size:13px;"><?= count($transactions) ?> record<?= count($transactions) !== 1 ? 's' : '' ?></span>
                    </div>
                    <div class="card-body" style="padding:0;">
                        <?php if (empty($transactions)): ?>
                            <div class="empty-state"><i class="fas fa-exchange-alt"></i><h3>No revenue transactions</h3><p>No orders or payments found for this period.</p></div>
                        <?php else: ?>
                        <table class="admin-table" id="revTable">
                            <thead><tr>
                                <th>Ref</th><th>Source</th><th>Client</th><th>Type</th><th>Amount</th><th>Method</th><th>Status</th><th>Receipt</th><th>Date</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($transactions as $t): ?>
                            <tr data-source="<?= $t['source'] ?>">
                                <td style="font-weight:600;">
                                    <?= $t['source'] === 'order' ? '#' . $t['order_id'] : 'PAY-' . $t['payment_id'] ?>
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
                                        <span style="color:#007bff;font-weight:600;"><i class="fas fa-wallet"></i> <?= htmlspecialchars($t['payment_method']) ?></span>
                                    <?php else: ?>
                                        <i class="fas fa-money-bill-wave" style="color:#27ae60;"></i> <?= htmlspecialchars($t['payment_method'] ?? '-') ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                        $sc = match($t['status']) {
                                            'Completed' => 'badge-finished', 'Processing' => 'badge-ongoing',
                                            'Cancelled' => 'badge-cancelled', default => 'badge-assigned'
                                        };
                                    ?>
                                    <span class="badge <?= $sc ?>"><?= htmlspecialchars($t['status']) ?></span>
                                </td>
                                <td>
                                    <?php if ($t['receipt_image']): ?>
                                        <a href="../uploads/<?= htmlspecialchars($t['receipt_image']) ?>" target="_blank" style="color:#27ae60;"><i class="fas fa-image"></i> View</a>
                                    <?php else: ?><span style="color:#666;">—</span><?php endif; ?>
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

            <!-- ============ EXPENSES TAB ============ -->
            <div class="tab-panel <?= $tab === 'expenses' ? 'active' : '' ?>">
                <div class="admin-card">
                    <div class="table-toolbar" style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap;padding:16px;">
                        <div class="table-search" style="flex:1;max-width:300px;"><i class="fas fa-search"></i>
                            <input type="text" id="expSearch" placeholder="Search expenses..." onkeyup="searchExpTable()">
                        </div>
                        <span style="color:#888;font-size:13px;"><?= count($expense_list) ?> record<?= count($expense_list) !== 1 ? 's' : '' ?> &bull; Total: ₱<?= number_format($period_expenses, 2) ?></span>
                    </div>
                    <div class="card-body" style="padding:0;">
                        <?php if (empty($expense_list)): ?>
                            <div class="empty-state"><i class="fas fa-truck"></i><h3>No expenses</h3><p>No purchase orders found for this period.</p></div>
                        <?php else: ?>
                        <table class="admin-table" id="expTable">
                            <thead><tr>
                                <th>PO #</th><th>Supplier</th><th>Item</th><th>Category</th><th>Qty</th><th>Unit Cost</th><th>Total Cost</th><th>Status</th><th>Ordered By</th><th>Date</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($expense_list as $e): ?>
                            <tr>
                                <td style="font-weight:600;">PO-<?= $e['po_id'] ?></td>
                                <td><?= htmlspecialchars($e['supplier_name'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($e['item_name'] ?? 'N/A') ?></td>
                                <td><span class="badge badge-info"><?= htmlspecialchars($e['category'] ?? '—') ?></span></td>
                                <td><?= (int)$e['quantity'] ?></td>
                                <td>₱<?= number_format($e['unit_cost'], 2) ?></td>
                                <td style="font-weight:700;color:#e74c3c;">₱<?= number_format($e['total_cost'], 2) ?></td>
                                <td>
                                    <?php
                                        $esc = match($e['status']) {
                                            'Received' => 'badge-finished', 'Cancelled' => 'badge-cancelled', default => 'badge-assigned'
                                        };
                                    ?>
                                    <span class="badge <?= $esc ?>"><?= htmlspecialchars($e['status']) ?></span>
                                </td>
                                <td><?= htmlspecialchars($e['ordered_by'] ?? '—') ?></td>
                                <td style="white-space:nowrap;"><?= date('M d, Y h:i A', strtotime($e['created_at'])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ============ BREAKDOWN TAB ============ -->
            <div class="tab-panel <?= $tab === 'breakdown' ? 'active' : '' ?>">
                <!-- Revenue by Payment Method -->
                <div class="admin-card" style="margin-bottom:20px;">
                    <div class="card-body" style="padding:18px;">
                        <h3 style="font-size:15px;color:#2c3e50;margin:0 0 14px;"><i class="fas fa-credit-card" style="color:#3498db;"></i> Revenue by Payment Method</h3>
                        <?php if (!empty($methods)): ?>
                        <div class="breakdown-grid">
                            <?php foreach ($methods as $m):
                                $pct = $period_revenue > 0 ? round(($m['total'] / $period_revenue) * 100) : 0;
                                $color = match($m['payment_method']) { 'Cash' => '#27ae60', 'GCash' => '#007bff', 'Maya' => '#6f42c1', default => '#888' };
                            ?>
                            <div class="breakdown-item">
                                <div class="bi-label"><?= htmlspecialchars($m['payment_method'] ?? 'Unknown') ?></div>
                                <div class="bi-value" style="color:<?= $color ?>;">₱<?= number_format($m['total'], 2) ?></div>
                                <div class="bi-bar"><div class="bi-bar-fill" style="width:<?= $pct ?>%;background:<?= $color ?>;"></div></div>
                                <div style="font-size:11px;color:#888;margin-top:2px;"><?= $pct ?>% of revenue</div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                            <p style="color:#999;font-style:italic;">No revenue data for this period.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Expenses by Category -->
                <div class="admin-card" style="margin-bottom:20px;">
                    <div class="card-body" style="padding:18px;">
                        <h3 style="font-size:15px;color:#2c3e50;margin:0 0 14px;"><i class="fas fa-boxes" style="color:#e74c3c;"></i> Expenses by Category</h3>
                        <?php if (!empty($expense_cats)): ?>
                        <div class="breakdown-grid">
                            <?php
                                $cat_colors = ['#e74c3c','#e67e22','#f39c12','#9b59b6','#1abc9c','#3498db','#34495e','#2ecc71'];
                                $ci = 0;
                            ?>
                            <?php foreach ($expense_cats as $cat):
                                $pct = $period_expenses > 0 ? round(($cat['total'] / $period_expenses) * 100) : 0;
                                $color = $cat_colors[$ci % count($cat_colors)]; $ci++;
                            ?>
                            <div class="breakdown-item">
                                <div class="bi-label"><?= htmlspecialchars($cat['category']) ?></div>
                                <div class="bi-value" style="color:<?= $color ?>;">₱<?= number_format($cat['total'], 2) ?></div>
                                <div class="bi-bar"><div class="bi-bar-fill" style="width:<?= $pct ?>%;background:<?= $color ?>;"></div></div>
                                <div style="font-size:11px;color:#888;margin-top:2px;"><?= $pct ?>% of expenses</div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                            <p style="color:#999;font-style:italic;">No expense data for this period.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Revenue vs Expenses Summary -->
                <div class="admin-card">
                    <div class="card-body" style="padding:18px;">
                        <h3 style="font-size:15px;color:#2c3e50;margin:0 0 14px;"><i class="fas fa-balance-scale" style="color:#f39c12;"></i> Summary (<?= $period_label ?>)</h3>
                        <table class="admin-table">
                            <thead><tr><th>Category</th><th style="text-align:right;">Amount</th></tr></thead>
                            <tbody>
                                <tr><td><i class="fas fa-shopping-cart" style="color:#3498db;"></i> Order Revenue</td><td style="text-align:right;font-weight:700;color:#27ae60;">₱<?= number_format($period_order_rev, 2) ?></td></tr>
                                <tr><td><i class="fas fa-file-invoice" style="color:#9b59b6;"></i> Invoice Payments</td><td style="text-align:right;font-weight:700;color:#27ae60;">₱<?= number_format($period_pay_rev, 2) ?></td></tr>
                                <tr style="border-top:2px solid #eee;"><td><strong><i class="fas fa-arrow-up" style="color:#27ae60;"></i> Total Revenue</strong></td><td style="text-align:right;font-weight:700;color:#27ae60;font-size:16px;">₱<?= number_format($period_revenue, 2) ?></td></tr>
                                <tr><td><i class="fas fa-truck" style="color:#e74c3c;"></i> Purchase Order Expenses</td><td style="text-align:right;font-weight:700;color:#e74c3c;">₱<?= number_format($period_expenses, 2) ?></td></tr>
                                <tr style="border-top:2px solid #eee;background:#f8f9fa;">
                                    <td><strong><i class="fas fa-<?= $period_profit >= 0 ? 'trending-up' : 'trending-down' ?>" style="color:<?= $period_profit >= 0 ? '#3498db' : '#e74c3c' ?>;"></i> Net Profit</strong></td>
                                    <td style="text-align:right;font-weight:700;color:<?= $period_profit >= 0 ? '#3498db' : '#e74c3c' ?>;font-size:18px;"><?= $period_profit < 0 ? '-' : '' ?>₱<?= number_format(abs($period_profit), 2) ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </main>
</div>
<script src="includes/admin.js"></script>
<script>
function searchRevTable() {
    var q = document.getElementById('revSearch').value.toLowerCase();
    var sf = document.getElementById('revTypeFilter').value;
    document.querySelectorAll('#revTable tbody tr').forEach(function(r) {
        var textMatch = !q || r.textContent.toLowerCase().indexOf(q) > -1;
        var sourceMatch = !sf || r.getAttribute('data-source') === sf;
        r.style.display = (textMatch && sourceMatch) ? '' : 'none';
    });
}
function searchExpTable() {
    var q = document.getElementById('expSearch').value.toLowerCase();
    document.querySelectorAll('#expTable tbody tr').forEach(function(r) {
        r.style.display = r.textContent.toLowerCase().indexOf(q) > -1 ? '' : 'none';
    });
}
</script>
</body>
</html>
