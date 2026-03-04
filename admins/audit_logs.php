<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') { header('Location: ../users/login.php'); exit; }
$page_title = 'Audit Logs'; $current_page = 'audit_logs';
require_once __DIR__ . '/../includes/db.php';

$filter_table = $_GET['table'] ?? '';
$filter_action = $_GET['action_type'] ?? '';
$search = $_GET['search'] ?? '';

$sql = "SELECT al.*, u.full_name AS user_name FROM audit_logs al LEFT JOIN users u ON al.user_id = u.user_id WHERE 1=1";
$params = [];
if ($filter_table) { $sql .= " AND al.table_name = ?"; $params[] = $filter_table; }
if ($filter_action) { $sql .= " AND al.action = ?"; $params[] = $filter_action; }
if ($search) { $sql .= " AND (al.action LIKE ? OR al.table_name LIKE ? OR u.full_name LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }
$sql .= " ORDER BY al.timestamp DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$tables = $pdo->query("SELECT DISTINCT table_name FROM audit_logs ORDER BY table_name ASC")->fetchAll(PDO::FETCH_COLUMN);
$actions = $pdo->query("SELECT DISTINCT action FROM audit_logs ORDER BY action ASC")->fetchAll(PDO::FETCH_COLUMN);
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
            <div class="page-header"><h2><i class="fas fa-clipboard-list"></i> Audit Logs</h2>
                <span class="badge badge-info"><?= count($logs) ?> records</span></div>

            <div class="admin-card">
                <form method="GET" class="table-toolbar" style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap;margin-bottom:1rem;">
                    <div class="search-box"><i class="fas fa-search"></i><input type="text" name="search" placeholder="Search logs..." value="<?= htmlspecialchars($search) ?>"></div>
                    <select name="table" class="form-control" style="width:auto;">
                        <option value="">All Tables</option>
                        <?php foreach ($tables as $t): ?><option value="<?= htmlspecialchars($t) ?>" <?= $filter_table === $t ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option><?php endforeach; ?>
                    </select>
                    <select name="action_type" class="form-control" style="width:auto;">
                        <option value="">All Actions</option>
                        <?php foreach ($actions as $a): ?><option value="<?= htmlspecialchars($a) ?>" <?= $filter_action === $a ? 'selected' : '' ?>><?= htmlspecialchars($a) ?></option><?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
                    <?php if ($filter_table || $filter_action || $search): ?><a href="audit_logs.php" class="btn btn-secondary"><i class="fas fa-times"></i> Clear</a><?php endif; ?>
                </form>

                <?php if (empty($logs)): ?><div class="empty-state"><i class="fas fa-clipboard-list"></i><h3>No audit logs found</h3><p>Actions performed in the system will appear here.</p></div>
                <?php else: ?>
                <div class="table-responsive">
                <table class="admin-table"><thead><tr><th>ID</th><th>User</th><th>Action</th><th>Table</th><th>Record ID</th><th>Timestamp</th></tr></thead>
                <tbody>
                <?php foreach ($logs as $l): ?>
                <tr>
                    <td><?= $l['log_id'] ?></td>
                    <td><?= htmlspecialchars($l['user_name'] ?? 'System') ?></td>
                    <td><span class="badge <?php
                        $act = strtolower($l['action']);
                        if (strpos($act,'insert') !== false || strpos($act,'create') !== false || strpos($act,'add') !== false) echo 'badge-success';
                        elseif (strpos($act,'update') !== false || strpos($act,'edit') !== false) echo 'badge-warning';
                        elseif (strpos($act,'delete') !== false || strpos($act,'remove') !== false) echo 'badge-danger';
                        else echo 'badge-info';
                    ?>"><?= htmlspecialchars($l['action']) ?></span></td>
                    <td><code style="font-size:0.85rem;background:var(--hover-bg);padding:2px 6px;border-radius:4px;"><?= htmlspecialchars($l['table_name']) ?></code></td>
                    <td><?= $l['record_id'] ?></td>
                    <td><?= date('M d, Y h:i:s A', strtotime($l['timestamp'])) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody></table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>
<script src="includes/admin.js"></script>
</body>
</html>
