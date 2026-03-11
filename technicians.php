<?php
session_start();
require_once __DIR__ . '/includes/db.php';

// Get cart count for logged-in customers
$cartCount = 0;
if (isset($_SESSION['client_id'])) {
    $cStmt = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM cart WHERE client_id = ?");
    $cStmt->execute([$_SESSION['client_id']]);
    $cartCount = (int)$cStmt->fetchColumn();
}

// Dashboard URL based on role
$dashboardUrl = '#';
if (isset($_SESSION['role'])) {
    if ($_SESSION['role'] === 'customer') $dashboardUrl = 'users/dashboard.php';
    elseif ($_SESSION['role'] === 'technician') $dashboardUrl = 'technicians/dashboard.php';
    elseif ($_SESSION['role'] === 'admin') $dashboardUrl = 'admins/dashboard.php';
}

// ── Fetch all technicians with stats ──
$techStmt = $pdo->query("
    SELECT u.user_id, u.full_name, u.email, u.created_at,
           COALESCE((SELECT ROUND(AVG(r.rating_value),1) FROM ratings r WHERE r.technician_id = u.user_id), 0) AS avg_rating,
           (SELECT COUNT(*) FROM ratings r2 WHERE r2.technician_id = u.user_id) AS total_reviews,
           (SELECT COUNT(*) FROM assignments a WHERE a.technician_id = u.user_id AND a.status = 'Finished') AS completed_jobs,
           (SELECT COUNT(*) FROM assignments a2 WHERE a2.technician_id = u.user_id AND a2.status IN ('Assigned','Ongoing')) AS active_jobs
    FROM users u
    WHERE u.role = 'technician' AND u.status = 'active'
    ORDER BY avg_rating DESC, completed_jobs DESC
");
$technicians = $techStmt->fetchAll();

// ── Fetch reviews for selected technician ──
$selected_tech = isset($_GET['tech_id']) ? (int)$_GET['tech_id'] : null;
$techReviews = [];
$techDetail = null;
$ratingBreakdown = [];

if ($selected_tech) {
    foreach ($technicians as $t) {
        if ($t['user_id'] === $selected_tech) {
            $techDetail = $t;
            break;
        }
    }

    if ($techDetail) {
        $revStmt = $pdo->prepare("
            SELECT r.rating_value, r.comment, r.created_at,
                   c.full_name AS client_name,
                   s.service_name
            FROM ratings r
            LEFT JOIN clients c ON r.client_id = c.client_id
            LEFT JOIN assignments a ON r.assignment_id = a.assignment_id
            LEFT JOIN services s ON a.service_id = s.service_id
            WHERE r.technician_id = ?
            ORDER BY r.created_at DESC
        ");
        $revStmt->execute([$selected_tech]);
        $techReviews = $revStmt->fetchAll();

        $breakdownStmt = $pdo->prepare("
            SELECT rating_value, COUNT(*) as cnt
            FROM ratings WHERE technician_id = ?
            GROUP BY rating_value ORDER BY rating_value DESC
        ");
        $breakdownStmt->execute([$selected_tech]);
        foreach ($breakdownStmt->fetchAll() as $bd) {
            $ratingBreakdown[$bd['rating_value']] = $bd['cnt'];
        }
    }
}

// ── Nav data ──
$dbServices = $pdo->query("SELECT * FROM services ORDER BY service_name ASC")->fetchAll();
$categories = $pdo->query("SELECT category, COUNT(*) as cnt FROM inventory WHERE quantity > 0 AND category IS NOT NULL GROUP BY category ORDER BY cnt DESC")->fetchAll();
$catIcons = [
    'Brake Parts' => 'fa-compact-disc', 'Engine Parts' => 'fa-cogs', 'Wheels & Tires' => 'fa-circle-notch',
    'Lighting' => 'fa-lightbulb', 'Fluids & Oils' => 'fa-oil-can', 'Accessories' => 'fa-car-battery',
    'Body Parts' => 'fa-car-side', 'Electronics' => 'fa-microchip', 'Suspension' => 'fa-car-alt',
    'Cooling System' => 'fa-thermometer-half', 'Ignition' => 'fa-plug',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $techDetail ? htmlspecialchars($techDetail['full_name']) . ' - ' : '' ?>Our Technicians - VehiCare</title>
    <link rel="stylesheet" href="includes/style/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700;900&family=Oswald:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .tech-section { padding: 60px 0; min-height: 60vh; background: #f8f9fa; }
        .tech-section .container { max-width: 1100px; margin: 0 auto; padding: 0 20px; }
        .page-title { font-family: 'Oswald', sans-serif; font-size: 32px; font-weight: 700; color: #2c3e50; margin-bottom: 8px; display: flex; align-items: center; gap: 12px; }
        .page-title i { color: #e74c3c; }
        .page-subtitle { color: #888; margin-bottom: 30px; font-size: 0.95rem; }
        .back-link { display: inline-flex; align-items: center; gap: 8px; color: #e74c3c; text-decoration: none; font-weight: 600; margin-bottom: 20px; font-size: 0.92rem; transition: gap 0.2s; }
        .back-link:hover { gap: 12px; text-decoration: underline; }

        /* ── Technician Grid ── */
        .tech-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 24px; }

        .tech-card { background: #fff; border-radius: 16px; overflow: hidden; box-shadow: 0 3px 16px rgba(0,0,0,0.06); transition: transform 0.2s, box-shadow 0.2s; border: 1px solid #eee; text-decoration: none; color: inherit; display: block; }
        .tech-card:hover { transform: translateY(-4px); box-shadow: 0 8px 30px rgba(0,0,0,0.1); }

        .tc-header { padding: 28px 24px 20px; display: flex; align-items: center; gap: 18px; background: linear-gradient(135deg, #f8f9fa, #eef2f7); border-bottom: 1px solid #f0f0f0; }
        .tc-avatar { width: 72px; height: 72px; border-radius: 50%; background: linear-gradient(135deg, #e74c3c, #c0392b); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 1.8rem; font-weight: 700; font-family: 'Oswald', sans-serif; flex-shrink: 0; box-shadow: 0 4px 12px rgba(231,76,60,0.25); }
        .tc-name { font-family: 'Oswald', sans-serif; font-size: 1.2rem; color: #2c3e50; margin: 0 0 4px; }
        .tc-role { font-size: 0.82rem; color: #888; display: flex; align-items: center; gap: 6px; }
        .tc-role i { color: #aaa; }

        .tc-body { padding: 20px 24px; }
        .tc-rating-row { display: flex; align-items: center; gap: 10px; margin-bottom: 16px; }
        .tc-stars { color: #f1c40f; font-size: 18px; display: flex; gap: 2px; }
        .tc-rating-val { font-size: 1.4rem; font-weight: 700; color: #333; font-family: 'Oswald', sans-serif; }
        .tc-rating-count { font-size: 0.82rem; color: #888; }

        .tc-stats { display: flex; gap: 0; border-top: 1px solid #f0f0f0; margin: 0 -24px; padding: 0; }
        .tc-stat { flex: 1; text-align: center; padding: 16px 8px; border-right: 1px solid #f0f0f0; }
        .tc-stat:last-child { border-right: none; }
        .tc-stat .ts-num { display: block; font-size: 1.3rem; font-weight: 700; color: #333; font-family: 'Oswald', sans-serif; }
        .tc-stat .ts-lbl { display: block; font-size: 0.75rem; color: #888; margin-top: 2px; }

        .tc-footer { padding: 14px 24px; background: #f9fafb; border-top: 1px solid #f0f0f0; text-align: center; }
        .tc-view-btn { color: #e74c3c; font-weight: 600; font-size: 0.88rem; display: inline-flex; align-items: center; gap: 6px; }

        /* ── Technician Profile Detail ── */
        .tp-card { background: #fff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.07); margin-bottom: 30px; border: 1px solid #eee; }

        .tp-header { padding: 36px 32px; display: flex; align-items: center; gap: 24px; background: linear-gradient(135deg, #2c3e50, #34495e); color: #fff; }
        .tp-avatar { width: 90px; height: 90px; border-radius: 50%; background: linear-gradient(135deg, #e74c3c, #c0392b); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 2.4rem; font-weight: 700; font-family: 'Oswald', sans-serif; flex-shrink: 0; box-shadow: 0 4px 16px rgba(0,0,0,0.3); border: 3px solid rgba(255,255,255,0.2); }
        .tp-info h2 { font-family: 'Oswald', sans-serif; font-size: 1.6rem; margin: 0 0 4px; }
        .tp-info .tp-role { font-size: 0.88rem; color: rgba(255,255,255,0.7); display: flex; align-items: center; gap: 8px; margin-bottom: 10px; }
        .tp-info .tp-joined { font-size: 0.8rem; color: rgba(255,255,255,0.5); }
        .tp-big-rating { display: flex; align-items: center; gap: 12px; margin-left: auto; text-align: center; }
        .tp-big-rating .tbr-num { font-size: 2.8rem; font-weight: 700; font-family: 'Oswald', sans-serif; line-height: 1; }
        .tp-big-rating .tbr-stars { color: #f1c40f; font-size: 16px; display: flex; gap: 2px; justify-content: center; margin-top: 4px; }
        .tp-big-rating .tbr-count { font-size: 0.78rem; color: rgba(255,255,255,0.6); margin-top: 2px; }

        .tp-stats { display: flex; border-bottom: 1px solid #f0f0f0; }
        .tp-stat { flex: 1; text-align: center; padding: 22px 12px; border-right: 1px solid #f0f0f0; }
        .tp-stat:last-child { border-right: none; }
        .tp-stat .tps-num { display: block; font-size: 1.6rem; font-weight: 700; color: #333; font-family: 'Oswald', sans-serif; }
        .tp-stat .tps-lbl { display: block; font-size: 0.82rem; color: #888; margin-top: 4px; }
        .tp-stat .tps-icon { display: block; font-size: 1.1rem; margin-bottom: 6px; }

        .tp-breakdown { padding: 28px 32px; border-bottom: 1px solid #f0f0f0; }
        .tp-breakdown h3 { font-family: 'Oswald', sans-serif; font-size: 1.1rem; color: #2c3e50; margin: 0 0 16px; }
        .bd-row { display: flex; align-items: center; gap: 12px; margin-bottom: 10px; }
        .bd-label { font-size: 0.85rem; font-weight: 600; color: #555; min-width: 55px; }
        .bd-bar { flex: 1; height: 10px; background: #f0f0f0; border-radius: 5px; overflow: hidden; }
        .bd-bar .bd-fill { height: 100%; background: #f1c40f; border-radius: 5px; transition: width 0.5s ease; }
        .bd-count { font-size: 0.82rem; color: #888; min-width: 30px; text-align: right; }

        .tp-reviews { padding: 28px 32px; }
        .tp-reviews h3 { font-family: 'Oswald', sans-serif; font-size: 1.1rem; color: #2c3e50; margin: 0 0 20px; display: flex; align-items: center; gap: 10px; }
        .tp-reviews h3 .rv-count { background: #e0e0e0; color: #555; font-size: 0.75rem; padding: 2px 10px; border-radius: 10px; font-family: 'Roboto', sans-serif; }

        .review-card { padding: 18px 0; border-bottom: 1px solid #f5f5f5; }
        .review-card:last-child { border-bottom: none; }
        .rv-top { display: flex; align-items: center; gap: 12px; margin-bottom: 8px; }
        .rv-client-avatar { width: 36px; height: 36px; border-radius: 50%; background: #e3f2fd; color: #1976d2; display: flex; align-items: center; justify-content: center; font-size: 0.85rem; font-weight: 700; flex-shrink: 0; }
        .rv-client-name { font-weight: 600; color: #333; font-size: 0.9rem; }
        .rv-date { font-size: 0.78rem; color: #aaa; margin-left: auto; }
        .rv-stars { color: #f1c40f; font-size: 14px; display: flex; gap: 1px; }
        .rv-service { font-size: 0.78rem; color: #888; display: flex; align-items: center; gap: 4px; margin-left: 48px; margin-bottom: 6px; }
        .rv-comment { font-size: 0.9rem; color: #555; margin-left: 48px; line-height: 1.5; }
        .rv-no-comment { font-size: 0.85rem; color: #bbb; font-style: italic; margin-left: 48px; }

        .no-reviews { text-align: center; padding: 40px 20px; color: #999; }
        .no-reviews i { font-size: 2.5rem; color: #ddd; margin-bottom: 10px; display: block; }

        @media (max-width: 600px) {
            .tech-grid { grid-template-columns: 1fr; }
            .tp-header { flex-direction: column; text-align: center; }
            .tp-big-rating { margin-left: 0; margin-top: 12px; }
            .tp-stats { flex-wrap: wrap; }
            .tp-stat { min-width: 50%; }
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
            <?php if (isset($_SESSION['user_id'])): ?>
                <?php if ($_SESSION['role'] === 'customer'): ?>
                    <a href="users/profile.php"><i class="fas fa-user-circle"></i> My Profile</a>
                    <a href="users/ratings.php"><i class="fas fa-star"></i> My Ratings</a>
                    <a href="users/orders.php"><i class="fas fa-box"></i> My Orders</a>
                    <a href="users/book_service.php"><i class="fas fa-calendar-check"></i> Book Service</a>
                <?php endif; ?>
                <a href="users/logout.php"><i class="fas fa-sign-out-alt"></i> Logout (<?= htmlspecialchars($_SESSION['full_name'] ?? '') ?>)</a>
            <?php else: ?>
                <a href="users/login.php"><i class="fas fa-sign-in-alt"></i> Login</a>
                <a href="users/register.php"><i class="fas fa-user-plus"></i> Register</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ========== HEADER / NAVBAR ========== -->
<header class="main-header">
    <div class="container">
        <div class="header-inner">
            <a href="index.php" class="logo">
                <span class="logo-vehi">Vehi</span><span class="logo-care">Care</span>
            </a>
            <nav class="main-nav">
                <ul>
                    <li><a href="index.php"><i class="fas fa-home"></i> Home</a></li>
                    <li class="has-dropdown">
                        <a href="index.php#services"><i class="fas fa-wrench"></i> Services <i class="fas fa-chevron-down"></i></a>
                        <ul class="dropdown">
                            <?php
                            $navSvcIcons = ['fa-oil-can','fa-cogs','fa-compact-disc','fa-circle-notch','fa-car-battery','fa-spray-can','fa-wrench','fa-tools'];
                            foreach ($dbServices as $ni => $ns):
                                $navIcon = $navSvcIcons[$ni % count($navSvcIcons)];
                            ?>
                            <li><a href="index.php#services"><i class="fas <?= $navIcon ?>"></i> <?= htmlspecialchars($ns['service_name']) ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                    <li class="has-dropdown">
                        <a href="index.php#shop"><i class="fas fa-store"></i> Shop <i class="fas fa-chevron-down"></i></a>
                        <ul class="dropdown">
                            <?php foreach ($categories as $navCat):
                                $navCatIcon = $catIcons[$navCat['category']] ?? 'fa-box';
                            ?>
                            <li><a href="index.php#shop"><i class="fas <?= $navCatIcon ?>"></i> <?= htmlspecialchars($navCat['category']) ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                    <li><a href="index.php#about"><i class="fas fa-info-circle"></i> About</a></li>
                    <li><a href="technicians.php" class="active"><i class="fas fa-users-cog"></i> Technicians</a></li>
                    <?php if (isset($_SESSION['user_id'])): ?>
                    <li><a href="<?= $dashboardUrl ?>"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <?php endif; ?>
                </ul>
            </nav>
            <div class="header-actions">
                <div class="search-box">
                    <input type="text" placeholder="Search products..." id="productSearch">
                    <button onclick="window.location.href='index.php#all-products'"><i class="fas fa-search"></i></button>
                </div>
                <?php if (isset($_SESSION['user_id'])): ?>
                <a href="users/notifications.php" class="header-icon" title="Notifications">
                    <i class="fas fa-bell"></i>
                    <?php
                        $nStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
                        $nStmt->execute([$_SESSION['user_id']]);
                        $nCount = (int)$nStmt->fetchColumn();
                        if ($nCount > 0): ?><span class="badge"><?= $nCount ?></span><?php endif; ?>
                </a>
                <?php endif; ?>
                <a href="users/cart.php" class="header-icon" title="Cart">
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

<!-- ========== TECHNICIANS SECTION ========== -->
<section class="tech-section">
    <div class="container">

    <?php if ($techDetail): ?>
        <!-- ══ TECHNICIAN DETAIL VIEW ══ -->
        <a href="technicians.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to All Technicians</a>

        <div class="tp-card">
            <div class="tp-header">
                <div class="tp-avatar"><?= strtoupper(substr($techDetail['full_name'], 0, 1)) ?></div>
                <div class="tp-info">
                    <h2><?= htmlspecialchars($techDetail['full_name']) ?></h2>
                    <div class="tp-role"><i class="fas fa-id-badge"></i> Certified Service Technician</div>
                    <div class="tp-joined"><i class="fas fa-calendar-alt"></i> Member since <?= date('F Y', strtotime($techDetail['created_at'])) ?></div>
                </div>
                <div class="tp-big-rating">
                    <div>
                        <div class="tbr-num"><?= $techDetail['avg_rating'] ?: '0.0' ?></div>
                        <div class="tbr-stars">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <i class="fa<?= $i <= round($techDetail['avg_rating']) ? 's' : 'r' ?> fa-star"></i>
                            <?php endfor; ?>
                        </div>
                        <div class="tbr-count"><?= (int)$techDetail['total_reviews'] ?> review<?= (int)$techDetail['total_reviews'] !== 1 ? 's' : '' ?></div>
                    </div>
                </div>
            </div>

            <div class="tp-stats">
                <div class="tp-stat">
                    <span class="tps-icon" style="color:#388e3c;"><i class="fas fa-check-circle"></i></span>
                    <span class="tps-num"><?= (int)$techDetail['completed_jobs'] ?></span>
                    <span class="tps-lbl">Jobs Completed</span>
                </div>
                <div class="tp-stat">
                    <span class="tps-icon" style="color:#f1c40f;"><i class="fas fa-star"></i></span>
                    <span class="tps-num"><?= $techDetail['avg_rating'] ?: 'N/A' ?></span>
                    <span class="tps-lbl">Avg Rating</span>
                </div>
                <div class="tp-stat">
                    <span class="tps-icon" style="color:#1976d2;"><i class="fas fa-comments"></i></span>
                    <span class="tps-num"><?= (int)$techDetail['total_reviews'] ?></span>
                    <span class="tps-lbl">Total Reviews</span>
                </div>
                <div class="tp-stat">
                    <span class="tps-icon" style="color:#f57c00;"><i class="fas fa-tools"></i></span>
                    <span class="tps-num"><?= (int)$techDetail['active_jobs'] ?></span>
                    <span class="tps-lbl">Active Jobs</span>
                </div>
            </div>

            <?php if ((int)$techDetail['total_reviews'] > 0): ?>
            <div class="tp-breakdown">
                <h3><i class="fas fa-chart-bar"></i> Rating Breakdown</h3>
                <?php for ($star = 5; $star >= 1; $star--):
                    $cnt = $ratingBreakdown[$star] ?? 0;
                    $pct = $techDetail['total_reviews'] > 0 ? round(($cnt / $techDetail['total_reviews']) * 100) : 0;
                ?>
                <div class="bd-row">
                    <div class="bd-label"><?= $star ?> <i class="fas fa-star" style="color:#f1c40f; font-size:0.8rem;"></i></div>
                    <div class="bd-bar"><div class="bd-fill" style="width: <?= $pct ?>%"></div></div>
                    <div class="bd-count"><?= $cnt ?></div>
                </div>
                <?php endfor; ?>
            </div>
            <?php endif; ?>

            <div class="tp-reviews">
                <h3><i class="fas fa-comments"></i> Client Reviews <span class="rv-count"><?= count($techReviews) ?></span></h3>

                <?php if (empty($techReviews)): ?>
                    <div class="no-reviews">
                        <i class="fas fa-comment-slash"></i>
                        <p>No reviews yet for this technician.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($techReviews as $rev): ?>
                    <div class="review-card">
                        <div class="rv-top">
                            <div class="rv-client-avatar"><?= strtoupper(substr($rev['client_name'] ?? '?', 0, 1)) ?></div>
                            <div>
                                <div class="rv-client-name"><?= htmlspecialchars($rev['client_name'] ?? 'Anonymous') ?></div>
                                <div class="rv-stars">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="fa<?= $i <= $rev['rating_value'] ? 's' : 'r' ?> fa-star"></i>
                                    <?php endfor; ?>
                                </div>
                            </div>
                            <div class="rv-date"><?= date('M d, Y', strtotime($rev['created_at'])) ?></div>
                        </div>
                        <?php if ($rev['service_name']): ?>
                            <div class="rv-service"><i class="fas fa-wrench"></i> <?= htmlspecialchars($rev['service_name']) ?></div>
                        <?php endif; ?>
                        <?php if ($rev['comment']): ?>
                            <div class="rv-comment">"<?= htmlspecialchars($rev['comment']) ?>"</div>
                        <?php else: ?>
                            <div class="rv-no-comment">No comment provided</div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

    <?php else: ?>
        <!-- ══ ALL TECHNICIANS LIST ══ -->
        <a href="index.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Home</a>
        <h1 class="page-title"><i class="fas fa-users-cog"></i> Our Technicians</h1>
        <p class="page-subtitle">Meet our certified technicians and see their ratings from clients like you.</p>

        <?php if (empty($technicians)): ?>
            <div class="no-reviews">
                <i class="fas fa-user-slash"></i>
                <p>No technicians available at the moment.</p>
            </div>
        <?php else: ?>
            <div class="tech-grid">
                <?php foreach ($technicians as $tech): ?>
                <a href="technicians.php?tech_id=<?= $tech['user_id'] ?>" class="tech-card">
                    <div class="tc-header">
                        <div class="tc-avatar"><?= strtoupper(substr($tech['full_name'], 0, 1)) ?></div>
                        <div>
                            <h3 class="tc-name"><?= htmlspecialchars($tech['full_name']) ?></h3>
                            <div class="tc-role"><i class="fas fa-id-badge"></i> Service Technician</div>
                        </div>
                    </div>
                    <div class="tc-body">
                        <div class="tc-rating-row">
                            <span class="tc-rating-val"><?= $tech['avg_rating'] ?: '0.0' ?></span>
                            <span class="tc-stars">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <i class="fa<?= $i <= round($tech['avg_rating']) ? 's' : 'r' ?> fa-star"></i>
                                <?php endfor; ?>
                            </span>
                            <span class="tc-rating-count">(<?= (int)$tech['total_reviews'] ?> review<?= (int)$tech['total_reviews'] !== 1 ? 's' : '' ?>)</span>
                        </div>
                        <div class="tc-stats">
                            <div class="tc-stat">
                                <span class="ts-num"><?= (int)$tech['completed_jobs'] ?></span>
                                <span class="ts-lbl">Jobs Done</span>
                            </div>
                            <div class="tc-stat">
                                <span class="ts-num"><?= (int)$tech['active_jobs'] ?></span>
                                <span class="ts-lbl">Active</span>
                            </div>
                            <div class="tc-stat">
                                <span class="ts-num"><?= date('M Y', strtotime($tech['created_at'])) ?></span>
                                <span class="ts-lbl">Joined</span>
                            </div>
                        </div>
                    </div>
                    <div class="tc-footer">
                        <span class="tc-view-btn"><i class="fas fa-eye"></i> View Profile & Reviews</span>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    </div>
</section>

<!-- ========== FOOTER ========== -->
<footer class="main-footer">
    <div class="footer-top">
        <div class="container">
            <div class="footer-grid">
                <div class="footer-col">
                    <a href="index.php" class="footer-logo">
                        <span class="logo-vehi">Vehi</span><span class="logo-care">Care</span>
                    </a>
                    <p>Your trusted partner for quality auto parts and professional vehicle services in Taguig City and Metro Manila.</p>
                    <div class="footer-social">
                        <a href="#"><i class="fab fa-facebook-f"></i></a>
                        <a href="#"><i class="fab fa-twitter"></i></a>
                        <a href="#"><i class="fab fa-instagram"></i></a>
                        <a href="#"><i class="fab fa-youtube"></i></a>
                    </div>
                </div>
                <div class="footer-col">
                    <h4>Quick Links</h4>
                    <ul>
                        <li><a href="index.php">Home</a></li>
                        <li><a href="index.php#services">Services</a></li>
                        <li><a href="index.php#shop">Shop</a></li>
                        <li><a href="index.php#about">About Us</a></li>
                        <li><a href="technicians.php">Technicians</a></li>
                    </ul>
                </div>
                <div class="footer-col">
                    <h4>Our Services</h4>
                    <ul>
                        <?php foreach (array_slice($dbServices, 0, 5) as $fs): ?>
                        <li><a href="index.php#services"><?= htmlspecialchars($fs['service_name']) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="footer-col">
                    <h4>Customer Support</h4>
                    <ul>
                        <li><a href="#">FAQ</a></li>
                        <li><a href="#">Shipping Policy</a></li>
                        <li><a href="#">Return Policy</a></li>
                        <li><a href="#">Privacy Policy</a></li>
                        <li><a href="#">Terms & Conditions</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    <div class="footer-bottom">
        <div class="container">
            <p>&copy; 2026 VehiCare. All rights reserved. | Designed for Vehicle Service DB</p>
            <div class="payment-methods">
                <i class="fab fa-cc-visa"></i>
                <i class="fab fa-cc-mastercard"></i>
                <i class="fab fa-cc-paypal"></i>
                <i class="fas fa-money-bill-wave"></i>
            </div>
        </div>
    </div>
</footer>

<!-- Scroll to top -->
<a href="#" class="scroll-top" id="scrollTop"><i class="fas fa-chevron-up"></i></a>

<script>
// Mobile nav toggle
document.getElementById('mobileToggle')?.addEventListener('click', () => {
    document.querySelector('.main-nav').classList.toggle('active');
});

// Sticky header
window.addEventListener('scroll', () => {
    document.querySelector('.main-header').classList.toggle('sticky', window.scrollY > 100);
    const scrollBtn = document.getElementById('scrollTop');
    if (scrollBtn) scrollBtn.classList.toggle('visible', window.scrollY > 300);
});
</script>
</body>
</html>
