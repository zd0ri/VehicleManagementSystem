<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'technician') { header('Location: ../users/login.php'); exit; }
$page_title = 'Dashboard'; $current_page = 'dashboard';
require_once __DIR__ . '/../includes/db.php';

$tid = $_SESSION['user_id'];

// Stats
$total_assignments = $pdo->prepare("SELECT COUNT(*) FROM assignments WHERE technician_id = ?"); $total_assignments->execute([$tid]); $total_assignments = $total_assignments->fetchColumn();
$finished = $pdo->prepare("SELECT COUNT(*) FROM assignments WHERE technician_id = ? AND status = 'Finished'"); $finished->execute([$tid]); $finished = $finished->fetchColumn();
$ongoing = $pdo->prepare("SELECT COUNT(*) FROM assignments WHERE technician_id = ? AND status = 'Ongoing'"); $ongoing->execute([$tid]); $ongoing = $ongoing->fetchColumn();
$assigned = $pdo->prepare("SELECT COUNT(*) FROM assignments WHERE technician_id = ? AND status = 'Assigned'"); $assigned->execute([$tid]); $assigned = $assigned->fetchColumn();

// Average rating
$avg_stmt = $pdo->prepare("SELECT ROUND(AVG(r.rating_value),1) FROM ratings r WHERE r.technician_id = ?"); $avg_stmt->execute([$tid]); $avg_rating = $avg_stmt->fetchColumn() ?: 0;

// Recent assignments
$recent = $pdo->prepare("SELECT a.*, s.service_name, v.plate_number, v.make, v.model, c.full_name AS client_name
    FROM assignments a
    LEFT JOIN services s ON a.service_id = s.service_id
    LEFT JOIN vehicles v ON a.vehicle_id = v.vehicle_id
    LEFT JOIN clients c ON v.client_id = c.client_id
    WHERE a.technician_id = ?
    ORDER BY FIELD(a.status, 'Ongoing', 'Assigned', 'Finished'), a.assignment_id DESC
    LIMIT 5");
$recent->execute([$tid]);
$recent_assignments = $recent->fetchAll();

// Upcoming (Assigned, not started)
$upcoming = $pdo->prepare("SELECT a.*, s.service_name, v.plate_number, v.make, v.model
    FROM assignments a
    LEFT JOIN services s ON a.service_id = s.service_id
    LEFT JOIN vehicles v ON a.vehicle_id = v.vehicle_id
    WHERE a.technician_id = ? AND a.status = 'Assigned'
    ORDER BY a.assignment_id ASC LIMIT 3");
$upcoming->execute([$tid]);
$upcoming_jobs = $upcoming->fetchAll();

// Recent ratings
$recent_ratings = $pdo->prepare("SELECT r.*, c.full_name AS client_name FROM ratings r LEFT JOIN clients c ON r.client_id = c.client_id WHERE r.technician_id = ? ORDER BY r.created_at DESC LIMIT 3");
$recent_ratings->execute([$tid]);
$recent_ratings = $recent_ratings->fetchAll();

// Unread notifications
$unread_notifs = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 5");
$unread_notifs->execute([$tid]);
$unread_notifs = $unread_notifs->fetchAll();
$unread_count = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$unread_count->execute([$tid]);
$unread_count = $unread_count->fetchColumn();

// Completion rate
$completion_rate = $total_assignments > 0 ? round(($finished / $total_assignments) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - VehiCare Technician</title>
    <link rel="stylesheet" href="../includes/style/technician.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700;900&family=Oswald:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="tech-body">
<div class="tech-layout">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <main class="tech-main">
        <?php include __DIR__ . '/includes/topbar.php'; ?>
        <div class="tech-content">
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-header-left">
                    <h1>Dashboard</h1>
                    <p>Plan, prioritize, and accomplish your tasks with ease.</p>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card highlight">
                    <div class="stat-icon" style="background:rgba(255,255,255,0.15);color:#fff;">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <div class="stat-info">
                        <span class="stat-label">Total Assignments</span>
                        <span class="stat-value"><?= $total_assignments ?></span>
                        <span class="stat-trend up"><i class="fas fa-circle-check"></i> All time</span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
                    <div class="stat-info">
                        <span class="stat-label">Completed</span>
                        <span class="stat-value"><?= $finished ?></span>
                        <span class="stat-trend up"><i class="fas fa-check"></i> Finished</span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon orange"><i class="fas fa-spinner"></i></div>
                    <div class="stat-info">
                        <span class="stat-label">In Progress</span>
                        <span class="stat-value"><?= $ongoing ?></span>
                        <span class="stat-trend"><i class="fas fa-clock"></i> Active</span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon purple"><i class="fas fa-hourglass-half"></i></div>
                    <div class="stat-info">
                        <span class="stat-label">Pending</span>
                        <span class="stat-value"><?= $assigned ?></span>
                        <span class="stat-trend"><i class="fas fa-pause"></i> Waiting</span>
                    </div>
                </div>
            </div>

            <!-- Dashboard Grid -->
            <div class="dashboard-grid">
                <!-- Notifications -->
                <?php if ($unread_count > 0): ?>
                <div class="tech-card" style="grid-column: 1 / -1;">
                    <div class="card-header">
                        <h3><i class="fas fa-bell"></i> Unread Notifications <span class="badge badge-assigned" style="margin-left:8px;"><?= $unread_count ?></span></h3>
                        <a href="notifications.php" class="card-action">View All <i class="fas fa-arrow-right"></i></a>
                    </div>
                    <div class="card-body">
                        <?php foreach ($unread_notifs as $n): ?>
                        <div class="assignment-item">
                            <div class="assignment-icon assigned"><i class="fas fa-bell"></i></div>
                            <div class="assignment-details">
                                <div class="assignment-title"><?= htmlspecialchars($n['title']) ?></div>
                                <div class="assignment-meta"><span><?= htmlspecialchars($n['message']) ?></span></div>
                            </div>
                            <span style="color:#888;font-size:0.8rem;white-space:nowrap;"><?= date('M d, h:iA', strtotime($n['created_at'])) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Recent Assignments -->
                <div class="tech-card">
                    <div class="card-header">
                        <h3><i class="fas fa-tasks"></i> Recent Assignments</h3>
                        <a href="assignments.php" class="card-action">View All <i class="fas fa-arrow-right"></i></a>
                    </div>
                    <div class="card-body">
                        <?php if (empty($recent_assignments)): ?>
                            <div class="empty-state"><i class="fas fa-tasks"></i><h3>No assignments yet</h3><p>Your assignments will appear here.</p></div>
                        <?php else: ?>
                            <?php foreach ($recent_assignments as $a): ?>
                            <div class="assignment-item">
                                <div class="assignment-icon <?= strtolower($a['status']) ?>">
                                    <i class="fas fa-<?= $a['status'] === 'Finished' ? 'check' : ($a['status'] === 'Ongoing' ? 'play' : 'clock') ?>"></i>
                                </div>
                                <div class="assignment-details">
                                    <div class="assignment-title"><?= htmlspecialchars($a['service_name'] ?? 'Service') ?></div>
                                    <div class="assignment-meta">
                                        <span><i class="fas fa-car"></i> <?= htmlspecialchars(($a['make'] ?? '') . ' ' . ($a['model'] ?? '')) ?></span>
                                        <span><i class="fas fa-id-badge"></i> <?= htmlspecialchars($a['plate_number'] ?? 'N/A') ?></span>
                                        <span><i class="fas fa-user"></i> <?= htmlspecialchars($a['client_name'] ?? 'N/A') ?></span>
                                    </div>
                                </div>
                                <span class="badge badge-<?= strtolower($a['status']) ?>"><?= $a['status'] ?></span>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Progress & Performance -->
                <div class="tech-card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-pie"></i> Task Progress</h3>
                        <a href="performance.php" class="card-action">Details <i class="fas fa-arrow-right"></i></a>
                    </div>
                    <div class="card-body" style="padding:24px;">
                        <!-- Donut chart -->
                        <div class="donut-chart" style="background: conic-gradient(
                            var(--green) 0deg <?= $total_assignments > 0 ? ($finished/$total_assignments)*360 : 0 ?>deg,
                            var(--orange) <?= $total_assignments > 0 ? ($finished/$total_assignments)*360 : 0 ?>deg <?= $total_assignments > 0 ? (($finished+$ongoing)/$total_assignments)*360 : 0 ?>deg,
                            var(--purple) <?= $total_assignments > 0 ? (($finished+$ongoing)/$total_assignments)*360 : 0 ?>deg 360deg
                        );">
                            <div class="donut-center">
                                <span class="donut-value"><?= $completion_rate ?>%</span>
                                <span class="donut-label">Completed</span>
                            </div>
                        </div>
                        <div class="donut-legend">
                            <div class="donut-legend-item"><div class="donut-legend-dot" style="background:var(--green)"></div> Completed (<?= $finished ?>)</div>
                            <div class="donut-legend-item"><div class="donut-legend-dot" style="background:var(--orange)"></div> In Progress (<?= $ongoing ?>)</div>
                            <div class="donut-legend-item"><div class="donut-legend-dot" style="background:var(--purple)"></div> Pending (<?= $assigned ?>)</div>
                        </div>

                        <!-- Performance Bars -->
                        <div style="margin-top:24px;">
                            <div class="progress-bar-wrapper">
                                <div class="progress-label"><span>Completion Rate</span><span><?= $completion_rate ?>%</span></div>
                                <div class="progress-track"><div class="progress-fill green" style="width:<?= $completion_rate ?>%"></div></div>
                            </div>
                            <div class="progress-bar-wrapper">
                                <div class="progress-label"><span>Average Rating</span><span><?= $avg_rating ?>/5</span></div>
                                <div class="progress-track"><div class="progress-fill red" style="width:<?= ($avg_rating/5)*100 ?>%"></div></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Upcoming Jobs -->
                <div class="tech-card">
                    <div class="card-header">
                        <h3><i class="fas fa-clock"></i> Upcoming Jobs</h3>
                    </div>
                    <div class="card-body">
                        <?php if (empty($upcoming_jobs)): ?>
                            <div class="empty-state" style="padding:20px;"><i class="fas fa-calendar-check"></i><p>No pending jobs. You're all caught up!</p></div>
                        <?php else: ?>
                            <?php foreach ($upcoming_jobs as $uj): ?>
                            <div class="vehicle-info-card">
                                <div class="vehicle-icon"><i class="fas fa-wrench"></i></div>
                                <div class="vehicle-details">
                                    <div class="vehicle-name"><?= htmlspecialchars($uj['service_name'] ?? 'Service') ?></div>
                                    <div class="vehicle-plate"><?= htmlspecialchars(($uj['make'] ?? '') . ' ' . ($uj['model'] ?? '') . ' • ' . ($uj['plate_number'] ?? '')) ?></div>
                                </div>
                                <span class="badge badge-assigned">Pending</span>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Ratings -->
                <div class="tech-card">
                    <div class="card-header">
                        <h3><i class="fas fa-star"></i> Recent Ratings</h3>
                    </div>
                    <div class="card-body">
                        <?php if (empty($recent_ratings)): ?>
                            <div class="empty-state" style="padding:20px;"><i class="fas fa-star"></i><p>No ratings yet. Keep up the great work!</p></div>
                        <?php else: ?>
                            <?php foreach ($recent_ratings as $rr): ?>
                            <div class="timeline-item">
                                <div class="timeline-dot completed"></div>
                                <div class="timeline-content">
                                    <div class="timeline-title">
                                        <?= htmlspecialchars($rr['client_name'] ?? 'Customer') ?>
                                        <span class="stars" style="margin-left:8px;">
                                            <?php for ($i = 1; $i <= 5; $i++): ?><i class="fa<?= $i <= $rr['rating_value'] ? 's' : 'r' ?> fa-star<?= $i > $rr['rating_value'] ? ' empty' : '' ?>"></i><?php endfor; ?>
                                        </span>
                                    </div>
                                    <div class="timeline-time"><?= htmlspecialchars($rr['comment'] ?? 'No comment') ?> &bull; <?= date('M d, Y', strtotime($rr['created_at'])) ?></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
<script src="includes/tech.js"></script>
</body>
</html>
