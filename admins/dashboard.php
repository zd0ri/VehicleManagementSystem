<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../users/login.php');
    exit;
}
$page_title = 'Dashboard';
$current_page = 'dashboard';
require_once __DIR__ . '/../includes/db.php';

// Fetch dashboard stats
$total_clients = $pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
$total_vehicles = $pdo->query("SELECT COUNT(*) FROM vehicles")->fetchColumn();
$total_appointments = $pdo->query("SELECT COUNT(*) FROM appointments WHERE status='Pending'")->fetchColumn();
$total_services = $pdo->query("SELECT COUNT(*) FROM services")->fetchColumn();
$total_queue = $pdo->query("SELECT COUNT(*) FROM queue WHERE status='Waiting'")->fetchColumn();
$total_technicians = $pdo->query("SELECT COUNT(*) FROM users WHERE role='technician'")->fetchColumn();

// Expiring / Expired inventory items
$expiry_items = $pdo->query("
    SELECT item_id, item_name, category, quantity, expiry_date,
           DATEDIFF(expiry_date, CURDATE()) AS days_left
    FROM inventory 
    WHERE expiry_date IS NOT NULL
    ORDER BY expiry_date ASC
")->fetchAll();

$expired_count = 0;
$expiring_soon_count = 0;
foreach ($expiry_items as $ei) {
    if ((int)$ei['days_left'] < 0) $expired_count++;
    elseif ((int)$ei['days_left'] <= 30) $expiring_soon_count++;
}

// Recent appointments
$recent_appointments = $pdo->query("
    SELECT a.*, c.full_name as client_name, v.plate_number, v.make, v.model
    FROM appointments a 
    LEFT JOIN clients c ON a.client_id = c.client_id 
    LEFT JOIN vehicles v ON a.vehicle_id = v.vehicle_id 
    ORDER BY a.created_at DESC LIMIT 5
")->fetchAll();

// Recent queue
$recent_queue = $pdo->query("
    SELECT q.*, c.full_name as client_name, v.plate_number, v.make, v.model
    FROM queue q 
    LEFT JOIN clients c ON q.client_id = c.client_id 
    LEFT JOIN vehicles v ON q.vehicle_id = v.vehicle_id 
    WHERE q.status IN ('Waiting','Serving')
    ORDER BY q.position ASC LIMIT 5
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - VehiCare Admin</title>
    <link rel="stylesheet" href="../includes/style/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700;900&family=Oswald:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body.admin-body { background: #f4f6fb; color: #2c3e50; }
        .admin-main { background: #f4f6fb; }
        .admin-content { background: transparent; }
        .admin-card, .stat-card, .admin-table { background: #ffffff; color: #2c3e50; }
        .admin-card { border: 1px solid #e6ebf2; box-shadow: 0 6px 18px rgba(15, 23, 42, 0.06); }
        .admin-table thead th { color: #607080; }
        .admin-table tbody td { color: #2c3e50; }
    </style>
</head>
<body class="admin-body">

<div class="admin-layout">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <main class="admin-main">
        <?php include __DIR__ . '/includes/topbar.php'; ?>

        <div class="admin-content">
            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(231,76,60,0.15); color: #e74c3c;">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-info">
                        <span class="stat-label">Total Clients</span>
                        <span class="stat-value"><?= number_format($total_clients) ?></span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(52,152,219,0.15); color: #3498db;">
                        <i class="fas fa-car"></i>
                    </div>
                    <div class="stat-info">
                        <span class="stat-label">Total Vehicles</span>
                        <span class="stat-value"><?= number_format($total_vehicles) ?></span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(46,204,113,0.15); color: #2ecc71;">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div class="stat-info">
                        <span class="stat-label">Pending Appointments</span>
                        <span class="stat-value"><?= number_format($total_appointments) ?></span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(155,89,182,0.15); color: #9b59b6;">
                        <i class="fas fa-concierge-bell"></i>
                    </div>
                    <div class="stat-info">
                        <span class="stat-label">Services Offered</span>
                        <span class="stat-value"><?= number_format($total_services) ?></span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(243,156,18,0.15); color: #f39c12;">
                        <i class="fas fa-list-ol"></i>
                    </div>
                    <div class="stat-info">
                        <span class="stat-label">In Queue</span>
                        <span class="stat-value"><?= number_format($total_queue) ?></span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(26,188,156,0.15); color: #1abc9c;">
                        <i class="fas fa-user-cog"></i>
                    </div>
                    <div class="stat-info">
                        <span class="stat-label">Technicians</span>
                        <span class="stat-value"><?= number_format($total_technicians) ?></span>
                    </div>
                </div>
            </div>

            <!-- Tables Row -->
            <div class="dashboard-grid">
                <!-- Recent Appointments -->
                <div class="admin-card">
                    <div class="card-header">
                        <h3><i class="fas fa-calendar-alt"></i> Recent Appointments</h3>
                        <a href="appointments.php" class="card-link">View All <i class="fas fa-arrow-right"></i></a>
                    </div>
                    <div class="card-body">
                        <?php if (empty($recent_appointments)): ?>
                            <div class="empty-state">
                                <i class="fas fa-calendar-times"></i>
                                <p>No appointments yet</p>
                            </div>
                        <?php else: ?>
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th>Client</th>
                                        <th>Vehicle</th>
                                        <th>Date</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_appointments as $apt): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($apt['client_name'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars(($apt['make'] ?? '') . ' ' . ($apt['model'] ?? '') . ' (' . ($apt['plate_number'] ?? '') . ')') ?></td>
                                        <td><?= date('M d, Y h:i A', strtotime($apt['appointment_date'])) ?></td>
                                        <td><span class="badge badge-<?= strtolower($apt['status']) ?>"><?= $apt['status'] ?></span></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Current Queue -->
                <div class="admin-card">
                    <div class="card-header">
                        <h3><i class="fas fa-list-ol"></i> Current Queue</h3>
                        <a href="queue.php" class="card-link">View All <i class="fas fa-arrow-right"></i></a>
                    </div>
                    <div class="card-body">
                        <?php if (empty($recent_queue)): ?>
                            <div class="empty-state">
                                <i class="fas fa-inbox"></i>
                                <p>Queue is empty</p>
                            </div>
                        <?php else: ?>
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Client</th>
                                        <th>Vehicle</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_queue as $q): ?>
                                    <tr>
                                        <td><?= $q['position'] ?></td>
                                        <td><?= htmlspecialchars($q['client_name'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars(($q['make'] ?? '') . ' ' . ($q['model'] ?? '')) ?></td>
                                        <td><span class="badge badge-<?= strtolower($q['status']) ?>"><?= $q['status'] ?></span></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Expiry Tracking -->
            <?php if (!empty($expiry_items)): ?>
            <div class="admin-card" style="margin-top:24px;">
                <div class="card-header">
                    <h3><i class="fas fa-clock" style="color:#e74c3c;"></i> Item Expiry Tracking
                        <?php if ($expired_count > 0): ?>
                            <span class="badge badge-ongoing" style="margin-left:8px;font-size:11px;"><?= $expired_count ?> Expired</span>
                        <?php endif; ?>
                        <?php if ($expiring_soon_count > 0): ?>
                            <span class="badge badge-assigned" style="margin-left:4px;font-size:11px;background:rgba(243,156,18,0.15);color:#f39c12;"><?= $expiring_soon_count ?> Expiring Soon</span>
                        <?php endif; ?>
                    </h3>
                    <a href="inventory.php" class="card-link">View Inventory <i class="fas fa-arrow-right"></i></a>
                </div>
                <div class="card-body">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Category</th>
                                <th>Stock</th>
                                <th>Expiry Date</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($expiry_items as $ei):
                                $daysLeft = (int)$ei['days_left'];
                            ?>
                            <tr style="<?= $daysLeft < 0 ? 'background:rgba(231,76,60,0.05);' : ($daysLeft <= 30 ? 'background:rgba(243,156,18,0.05);' : '') ?>">
                                <td><strong><?= htmlspecialchars($ei['item_name']) ?></strong></td>
                                <td><?= htmlspecialchars($ei['category'] ?? '—') ?></td>
                                <td><?= (int)$ei['quantity'] ?></td>
                                <td><?= date('M d, Y', strtotime($ei['expiry_date'])) ?></td>
                                <td>
                                    <?php if ($daysLeft < 0): ?>
                                        <span style="color:#e74c3c;font-weight:700;"><i class="fas fa-exclamation-circle"></i> Expired <?= abs($daysLeft) ?> day<?= abs($daysLeft) !== 1 ? 's' : '' ?> ago</span>
                                    <?php elseif ($daysLeft === 0): ?>
                                        <span style="color:#e74c3c;font-weight:700;"><i class="fas fa-exclamation-triangle"></i> Expires Today!</span>
                                    <?php elseif ($daysLeft <= 7): ?>
                                        <span style="color:#e67e22;font-weight:600;"><i class="fas fa-clock"></i> <?= $daysLeft ?> day<?= $daysLeft !== 1 ? 's' : '' ?> left</span>
                                    <?php elseif ($daysLeft <= 30): ?>
                                        <span style="color:#f39c12;font-weight:500;"><i class="fas fa-clock"></i> <?= $daysLeft ?> days left</span>
                                    <?php else: ?>
                                        <span style="color:#27ae60;"><i class="fas fa-check-circle"></i> <?= $daysLeft ?> days left</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($daysLeft < 0): ?>
                                        <a href="purchase_orders.php" class="btn btn-primary" style="font-size:12px;padding:5px 12px;"><i class="fas fa-shopping-cart"></i> Restock</a>
                                    <?php elseif ($daysLeft <= 30): ?>
                                        <a href="purchase_orders.php" class="btn btn-secondary" style="font-size:12px;padding:5px 12px;"><i class="fas fa-shopping-cart"></i> Plan Restock</a>
                                    <?php else: ?>
                                        <span style="color:#27ae60;font-size:12px;"><i class="fas fa-thumbs-up"></i> OK</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </main>
</div>

<script src="includes/admin.js"></script>
</body>
</html>
