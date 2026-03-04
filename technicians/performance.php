<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'technician') { header('Location: ../users/login.php'); exit; }
$page_title = 'Performance'; $current_page = 'performance';
require_once __DIR__ . '/../includes/db.php';

$tid = $_SESSION['user_id'];

// Overall stats
$total = $pdo->prepare("SELECT COUNT(*) FROM assignments WHERE technician_id = ?"); $total->execute([$tid]); $total = $total->fetchColumn();
$finished = $pdo->prepare("SELECT COUNT(*) FROM assignments WHERE technician_id = ? AND status = 'Finished'"); $finished->execute([$tid]); $finished = $finished->fetchColumn();
$ongoing = $pdo->prepare("SELECT COUNT(*) FROM assignments WHERE technician_id = ? AND status = 'Ongoing'"); $ongoing->execute([$tid]); $ongoing = $ongoing->fetchColumn();
$assigned = $pdo->prepare("SELECT COUNT(*) FROM assignments WHERE technician_id = ? AND status = 'Assigned'"); $assigned->execute([$tid]); $assigned = $assigned->fetchColumn();

$completion_rate = $total > 0 ? round(($finished / $total) * 100) : 0;

// Average time per service (only finished ones with both start and end times)
$avg_time_stmt = $pdo->prepare("SELECT AVG(TIMESTAMPDIFF(MINUTE, start_time, end_time)) FROM assignments WHERE technician_id = ? AND status = 'Finished' AND start_time IS NOT NULL AND end_time IS NOT NULL");
$avg_time_stmt->execute([$tid]);
$avg_time = round($avg_time_stmt->fetchColumn() ?? 0);
$avg_hours = floor($avg_time / 60);
$avg_mins = $avg_time % 60;

// Services breakdown
$services_breakdown = $pdo->prepare("
    SELECT s.service_name, COUNT(*) as cnt,
        SUM(CASE WHEN a.status = 'Finished' THEN 1 ELSE 0 END) as completed
    FROM assignments a
    LEFT JOIN services s ON a.service_id = s.service_id
    WHERE a.technician_id = ?
    GROUP BY a.service_id
    ORDER BY cnt DESC
");
$services_breakdown->execute([$tid]);
$services = $services_breakdown->fetchAll();

// Monthly trend (last 6 months)
$monthly = $pdo->prepare("
    SELECT DATE_FORMAT(end_time, '%Y-%m') as month,
        DATE_FORMAT(end_time, '%b %Y') as month_label,
        COUNT(*) as completed
    FROM assignments
    WHERE technician_id = ? AND status = 'Finished' AND end_time IS NOT NULL
    GROUP BY DATE_FORMAT(end_time, '%Y-%m')
    ORDER BY month DESC
    LIMIT 6
");
$monthly->execute([$tid]);
$monthly_data = array_reverse($monthly->fetchAll());
$max_monthly = !empty($monthly_data) ? max(array_column($monthly_data, 'completed')) : 1;

// Ratings
$avg_rating = $pdo->prepare("SELECT ROUND(AVG(rating_value),1) FROM ratings WHERE technician_id = ?"); $avg_rating->execute([$tid]); $avg_rating = $avg_rating->fetchColumn() ?: 0;
$total_ratings = $pdo->prepare("SELECT COUNT(*) FROM ratings WHERE technician_id = ?"); $total_ratings->execute([$tid]); $total_ratings = $total_ratings->fetchColumn();

// Rating distribution
$rating_dist = [];
for ($i = 5; $i >= 1; $i--) {
    $rd = $pdo->prepare("SELECT COUNT(*) FROM ratings WHERE technician_id = ? AND rating_value = ?");
    $rd->execute([$tid, $i]);
    $rating_dist[$i] = $rd->fetchColumn();
}

// All ratings
$all_ratings = $pdo->prepare("SELECT r.*, c.full_name AS client_name FROM ratings r LEFT JOIN clients c ON r.client_id = c.client_id WHERE r.technician_id = ? ORDER BY r.created_at DESC");
$all_ratings->execute([$tid]);
$ratings_list = $all_ratings->fetchAll();

// Fastest service
$fastest = $pdo->prepare("SELECT TIMESTAMPDIFF(MINUTE, start_time, end_time) as duration, s.service_name FROM assignments a LEFT JOIN services s ON a.service_id = s.service_id WHERE a.technician_id = ? AND a.status = 'Finished' AND a.start_time IS NOT NULL AND a.end_time IS NOT NULL ORDER BY duration ASC LIMIT 1");
$fastest->execute([$tid]);
$fastest_service = $fastest->fetch();
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
            <div class="page-header">
                <div class="page-header-left">
                    <h1><i class="fas fa-chart-line" style="color:var(--primary);margin-right:10px;"></i> Performance</h1>
                    <p>Track your work metrics, ratings, and service history</p>
                </div>
            </div>

            <!-- Key Stats -->
            <div class="stats-grid">
                <div class="stat-card highlight">
                    <div class="stat-icon" style="background:rgba(255,255,255,0.15);color:#fff;"><i class="fas fa-percentage"></i></div>
                    <div class="stat-info">
                        <span class="stat-label">Completion Rate</span>
                        <span class="stat-value"><?= $completion_rate ?>%</span>
                        <span class="stat-trend up"><i class="fas fa-arrow-up"></i> Performance</span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon orange"><i class="fas fa-clock"></i></div>
                    <div class="stat-info">
                        <span class="stat-label">Avg. Service Time</span>
                        <span class="stat-value"><?= $avg_hours > 0 ? $avg_hours . 'h ' : '' ?><?= $avg_mins ?>m</span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon blue"><i class="fas fa-star"></i></div>
                    <div class="stat-info">
                        <span class="stat-label">Avg. Rating</span>
                        <span class="stat-value"><?= $avg_rating ?>/5</span>
                        <span class="stat-trend"><i class="fas fa-star" style="color:#f39c12;"></i> <?= $total_ratings ?> reviews</span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon green"><i class="fas fa-bolt"></i></div>
                    <div class="stat-info">
                        <span class="stat-label">Fastest Service</span>
                        <span class="stat-value"><?php if ($fastest_service) { $d = $fastest_service['duration']; echo ($d >= 60 ? floor($d/60).'h ' : '') . ($d%60) . 'm'; } else echo 'N/A'; ?></span>
                        <?php if ($fastest_service): ?><span class="stat-trend" style="color:var(--tech-text-muted);"><?= htmlspecialchars($fastest_service['service_name']) ?></span><?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="dashboard-grid">
                <!-- Task Progress (Donut) -->
                <div class="tech-card">
                    <div class="card-header"><h3><i class="fas fa-chart-pie"></i> Task Progress</h3></div>
                    <div class="card-body" style="padding:24px;text-align:center;">
                        <div class="donut-chart" style="background: conic-gradient(
                            var(--green) 0deg <?= $total > 0 ? ($finished/$total)*360 : 0 ?>deg,
                            var(--orange) <?= $total > 0 ? ($finished/$total)*360 : 0 ?>deg <?= $total > 0 ? (($finished+$ongoing)/$total)*360 : 0 ?>deg,
                            var(--purple) <?= $total > 0 ? (($finished+$ongoing)/$total)*360 : 0 ?>deg 360deg
                        );">
                            <div class="donut-center">
                                <span class="donut-value"><?= $completion_rate ?>%</span>
                                <span class="donut-label">Done</span>
                            </div>
                        </div>
                        <div class="donut-legend" style="margin-top:20px;">
                            <div class="donut-legend-item"><div class="donut-legend-dot" style="background:var(--green)"></div> Completed (<?= $finished ?>)</div>
                            <div class="donut-legend-item"><div class="donut-legend-dot" style="background:var(--orange)"></div> In Progress (<?= $ongoing ?>)</div>
                            <div class="donut-legend-item"><div class="donut-legend-dot" style="background:var(--purple)"></div> Pending (<?= $assigned ?>)</div>
                        </div>
                    </div>
                </div>

                <!-- Monthly Trend (Bar chart via CSS) -->
                <div class="tech-card">
                    <div class="card-header"><h3><i class="fas fa-chart-bar"></i> Monthly Completed</h3></div>
                    <div class="card-body" style="padding:24px;">
                        <?php if (empty($monthly_data)): ?>
                            <div class="empty-state" style="padding:20px;"><i class="fas fa-chart-bar"></i><p>No completed services yet.</p></div>
                        <?php else: ?>
                        <div style="display:flex;align-items:flex-end;gap:12px;height:200px;padding-bottom:30px;position:relative;">
                            <!-- Y axis line -->
                            <div style="position:absolute;left:0;bottom:30px;top:0;width:1px;background:var(--tech-border);"></div>
                            <?php foreach ($monthly_data as $m): ?>
                            <?php $height = $max_monthly > 0 ? ($m['completed'] / $max_monthly) * 160 : 0; ?>
                            <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:6px;">
                                <span style="font-size:12px;font-weight:700;color:var(--tech-text);"><?= $m['completed'] ?></span>
                                <div style="width:100%;max-width:40px;height:<?= max($height, 4) ?>px;background:linear-gradient(180deg, var(--primary) 0%, var(--primary-dark) 100%);border-radius:6px 6px 2px 2px;transition:height 0.5s ease;"></div>
                                <span style="font-size:11px;color:var(--tech-text-muted);white-space:nowrap;"><?= $m['month_label'] ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Services Breakdown -->
                <div class="tech-card">
                    <div class="card-header"><h3><i class="fas fa-wrench"></i> Services Breakdown</h3></div>
                    <div class="card-body" style="padding:20px;">
                        <?php if (empty($services)): ?>
                            <div class="empty-state" style="padding:20px;"><i class="fas fa-wrench"></i><p>No services recorded yet.</p></div>
                        <?php else: ?>
                            <?php foreach ($services as $s): ?>
                            <?php $pct = $s['cnt'] > 0 ? round(($s['completed'] / $s['cnt']) * 100) : 0; ?>
                            <div class="progress-bar-wrapper">
                                <div class="progress-label">
                                    <span><?= htmlspecialchars($s['service_name'] ?? 'Unknown') ?></span>
                                    <span><?= $s['completed'] ?>/<?= $s['cnt'] ?> (<?= $pct ?>%)</span>
                                </div>
                                <div class="progress-track"><div class="progress-fill red" style="width:<?= $pct ?>%"></div></div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Rating Distribution -->
                <div class="tech-card">
                    <div class="card-header"><h3><i class="fas fa-star"></i> Rating Distribution</h3></div>
                    <div class="card-body" style="padding:20px;">
                        <div style="text-align:center;margin-bottom:20px;">
                            <div style="font-family:var(--font-heading);font-size:48px;font-weight:700;color:var(--tech-text);"><?= $avg_rating ?></div>
                            <div class="stars" style="font-size:20px;margin:6px 0;">
                                <?php for ($i = 1; $i <= 5; $i++): ?><i class="fa<?= $i <= round($avg_rating) ? 's' : 'r' ?> fa-star<?= $i > round($avg_rating) ? ' empty' : '' ?>"></i><?php endfor; ?>
                            </div>
                            <div style="font-size:13px;color:var(--tech-text-muted);"><?= $total_ratings ?> total ratings</div>
                        </div>
                        <?php for ($i = 5; $i >= 1; $i--): ?>
                        <?php $bar_pct = $total_ratings > 0 ? round(($rating_dist[$i] / $total_ratings) * 100) : 0; ?>
                        <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
                            <span style="font-size:13px;color:var(--tech-text-dim);width:16px;text-align:right;"><?= $i ?></span>
                            <i class="fas fa-star" style="color:#f39c12;font-size:12px;"></i>
                            <div class="progress-track" style="flex:1;height:6px;">
                                <div class="progress-fill orange" style="width:<?= $bar_pct ?>%;"></div>
                            </div>
                            <span style="font-size:12px;color:var(--tech-text-muted);width:30px;"><?= $rating_dist[$i] ?></span>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <!-- All Reviews -->
                <div class="tech-card full-width">
                    <div class="card-header"><h3><i class="fas fa-comment-dots"></i> Customer Reviews</h3></div>
                    <div class="card-body">
                        <?php if (empty($ratings_list)): ?>
                            <div class="empty-state" style="padding:20px;"><i class="fas fa-comment-dots"></i><p>No reviews yet. Complete assignments to receive reviews!</p></div>
                        <?php else: ?>
                            <?php foreach ($ratings_list as $rr): ?>
                            <div class="timeline-item">
                                <div class="client-avatar" style="width:36px;height:36px;font-size:14px;flex-shrink:0;"><?= strtoupper(substr($rr['client_name'] ?? 'C', 0, 1)) ?></div>
                                <div class="timeline-content" style="flex:1;">
                                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                                        <div class="timeline-title"><?= htmlspecialchars($rr['client_name'] ?? 'Customer') ?></div>
                                        <div class="stars">
                                            <?php for ($i = 1; $i <= 5; $i++): ?><i class="fa<?= $i <= $rr['rating_value'] ? 's' : 'r' ?> fa-star<?= $i > $rr['rating_value'] ? ' empty' : '' ?>"></i><?php endfor; ?>
                                        </div>
                                    </div>
                                    <p style="font-size:14px;color:var(--tech-text-dim);margin:6px 0 0;"><?= htmlspecialchars($rr['comment'] ?? 'No comment provided.') ?></p>
                                    <div class="timeline-time"><?= date('F d, Y', strtotime($rr['created_at'])) ?></div>
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
