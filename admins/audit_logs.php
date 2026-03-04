<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../users/login.php');
    exit;
}

$page_title = 'Audit Logs';
$current_page = 'audit_logs';

require_once __DIR__ . '/../includes/db.php';

$error = '';

// Fetch all audit logs
$audit_logs = [];
try {
    $result = $conn->query("SELECT a.*, u.full_name as user_name FROM audit_logs a LEFT JOIN users u ON a.user_id = u.user_id ORDER BY a.timestamp DESC");
    while ($row = $result->fetch_assoc()) {
        $audit_logs[] = $row;
    }
} catch (Exception $e) {
    $error = 'Failed to fetch audit logs: ' . $e->getMessage();
}

// Get distinct table names for filter
$table_names = [];
try {
    $result = $conn->query("SELECT DISTINCT table_name FROM audit_logs ORDER BY table_name");
    while ($row = $result->fetch_assoc()) {
        $table_names[] = $row['table_name'];
    }
} catch (Exception $e) {
    // silently ignore
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> - Admin</title>
    <link rel="stylesheet" href="../includes/style/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&family=Oswald:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="admin-body">
<div class="admin-layout">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <main class="admin-main">
        <?php include __DIR__ . '/includes/topbar.php'; ?>
        <div class="admin-content">

            <!-- Alert messages -->
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <!-- Page header -->
            <div class="page-header">
                <h2><i class="fas fa-history"></i> Audit Logs</h2>
            </div>

            <!-- Admin card with table -->
            <div class="admin-card">
                <div class="table-toolbar">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="searchInput" placeholder="Search audit logs..." onkeyup="searchTable()">
                    </div>
                    <div class="filter-box" style="margin-left:15px;">
                        <select id="tableFilter" class="form-control" onchange="filterByTable()" style="min-width:180px;">
                            <option value="">All Tables</option>
                            <?php foreach ($table_names as $tname): ?>
                                <option value="<?= htmlspecialchars($tname) ?>"><?= htmlspecialchars($tname) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <?php if (count($audit_logs) > 0): ?>
                <div class="table-responsive">
                    <table class="admin-table" id="auditLogsTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>User</th>
                                <th>Action</th>
                                <th>Table</th>
                                <th>Record ID</th>
                                <th>Timestamp</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($audit_logs as $row): ?>
                            <tr data-table="<?= htmlspecialchars($row['table_name'] ?? '') ?>">
                                <td><?= htmlspecialchars($row['log_id']) ?></td>
                                <td><?= htmlspecialchars($row['user_name'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($row['action']) ?></td>
                                <td><?= htmlspecialchars($row['table_name']) ?></td>
                                <td><?= htmlspecialchars($row['record_id'] ?? '') ?></td>
                                <td><?= htmlspecialchars($row['timestamp']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-history"></i>
                    <h3>No audit logs found</h3>
                    <p>Audit logs will appear here as actions are performed.</p>
                </div>
                <?php endif; ?>
            </div>

        </div>
    </main>
</div>

<script src="includes/admin.js"></script>
<script>
function searchTable() {
    var input = document.getElementById('searchInput').value.toLowerCase();
    var table = document.getElementById('auditLogsTable');
    if (!table) return;
    var rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
    var filterValue = document.getElementById('tableFilter').value;
    for (var i = 0; i < rows.length; i++) {
        var text = rows[i].textContent.toLowerCase();
        var tableMatch = !filterValue || rows[i].getAttribute('data-table') === filterValue;
        var searchMatch = text.indexOf(input) > -1;
        rows[i].style.display = (searchMatch && tableMatch) ? '' : 'none';
    }
}

function filterByTable() {
    searchTable();
}
</script>
</body>
</html>
