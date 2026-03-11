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

// ── Handle rating submission ──
$rating_success = '';
$rating_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_rating') {
    $assignment_id = (int)($_POST['assignment_id'] ?? 0);
    $technician_id = (int)($_POST['technician_id'] ?? 0);
    $rating_value = (int)($_POST['rating_value'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');

    if (!$assignment_id || !$technician_id || $rating_value < 1 || $rating_value > 5) {
        $rating_error = 'Please provide a valid rating (1-5 stars).';
    } else {
        $chk = $pdo->prepare("SELECT rating_id FROM ratings WHERE assignment_id = ? AND client_id = ?");
        $chk->execute([$assignment_id, $client_id]);
        if ($chk->fetch()) {
            $rating_error = 'You have already rated this service.';
        } else {
            $verify = $pdo->prepare("
                SELECT a.assignment_id, ap.vehicle_id FROM assignments a
                JOIN appointments ap ON a.appointment_id = ap.appointment_id
                WHERE a.assignment_id = ? AND ap.client_id = ? AND a.status IN ('Done', 'Finished')
            ");
            $verify->execute([$assignment_id, $client_id]);
            $verified = $verify->fetch();
            if (!$verified) {
                $rating_error = 'Invalid or incomplete service.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO ratings (client_id, vehicle_id, technician_id, assignment_id, rating_value, comment) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$client_id, $verified['vehicle_id'], $technician_id, $assignment_id, $rating_value, $comment]);

                $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'New Rating Received', ?, 'rating')")
                    ->execute([$technician_id, 'A client rated your service ' . $rating_value . '/5 stars.' . ($comment ? ' Comment: "' . mb_substr($comment, 0, 100) . '"' : '')]);

                $rating_success = 'Thank you for your rating!';
            }
        }
    }
}

// ── Fetch completed services ──
$completedStmt = $pdo->prepare("
    SELECT ap.appointment_id, ap.appointment_date,
           a.assignment_id, a.status AS assign_status, a.notes AS tech_notes, a.start_time, a.end_time,
           s.service_name, s.base_price,
           v.plate_number, v.make, v.model,
           u.full_name AS tech_name, u.user_id AS tech_user_id, u.email AS tech_email,
           (SELECT ROUND(AVG(r2.rating_value),1) FROM ratings r2 WHERE r2.technician_id = u.user_id) AS tech_avg_rating,
           (SELECT COUNT(*) FROM ratings r3 WHERE r3.technician_id = u.user_id) AS tech_total_ratings,
           (SELECT COUNT(*) FROM assignments a2 WHERE a2.technician_id = u.user_id AND a2.status = 'Finished') AS tech_completed_jobs
    FROM appointments ap
    JOIN assignments a ON ap.appointment_id = a.appointment_id
    LEFT JOIN services s ON COALESCE(a.service_id, ap.service_id) = s.service_id
    LEFT JOIN vehicles v ON ap.vehicle_id = v.vehicle_id
    LEFT JOIN users u ON a.technician_id = u.user_id
    WHERE ap.client_id = ? AND a.status IN ('Done', 'Finished')
    ORDER BY a.end_time DESC, ap.appointment_date DESC
");
$completedStmt->execute([$client_id]);
$completedServices = $completedStmt->fetchAll();

// Get existing ratings
$ratedAssignments = [];
if (!empty($completedServices)) {
    $assignIds = array_filter(array_column($completedServices, 'assignment_id'));
    if (!empty($assignIds)) {
        $placeholders = implode(',', array_fill(0, count($assignIds), '?'));
        $rStmt = $pdo->prepare("SELECT assignment_id, rating_value, comment, created_at FROM ratings WHERE assignment_id IN ($placeholders) AND client_id = ?");
        $rStmt->execute([...$assignIds, $client_id]);
        foreach ($rStmt->fetchAll() as $r) {
            $ratedAssignments[$r['assignment_id']] = $r;
        }
    }
}

// Separate unrated and rated
$unratedServices = [];
$ratedServices = [];
foreach ($completedServices as $svc) {
    if (isset($ratedAssignments[$svc['assignment_id']])) {
        $ratedServices[] = $svc;
    } else {
        $unratedServices[] = $svc;
    }
}

// ── Nav data ──
$dbServices = $pdo->query("SELECT * FROM services ORDER BY service_name ASC")->fetchAll();
$categories = $pdo->query("SELECT category, COUNT(*) as cnt FROM inventory WHERE quantity > 0 AND category IS NOT NULL GROUP BY category ORDER BY cnt DESC")->fetchAll();
$catIcons = ['Engine Parts'=>'fa-cogs','Brake System'=>'fa-compact-disc','Oils & Fluids'=>'fa-oil-can','Tires & Wheels'=>'fa-circle-notch','Battery & Electrical'=>'fa-car-battery','Body & Exterior'=>'fa-car','Filters'=>'fa-filter','Accessories'=>'fa-star'];

$cartStmt = $pdo->prepare("SELECT COUNT(*) FROM cart WHERE client_id = ?");
$cartStmt->execute([$client_id]);
$cartCount = $cartStmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rate Services - VehiCare</title>
    <link rel="stylesheet" href="../includes/style/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700;900&family=Oswald:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .rating-section { padding: 60px 0; min-height: 60vh; background: #f8f9fa; }
        .rating-section .container { max-width: 900px; margin: 0 auto; padding: 0 20px; }
        .page-title { font-family: 'Oswald', sans-serif; font-size: 32px; font-weight: 700; color: #2c3e50; margin-bottom: 8px; display: flex; align-items: center; gap: 12px; }
        .page-title i { color: #f1c40f; }
        .page-subtitle { color: #888; margin-bottom: 30px; font-size: 0.95rem; }
        .back-link { display: inline-flex; align-items: center; gap: 8px; color: #e74c3c; text-decoration: none; font-weight: 600; margin-bottom: 20px; font-size: 0.92rem; }
        .back-link:hover { text-decoration: underline; }

        .section-label { font-family: 'Oswald', sans-serif; font-size: 1.1rem; color: #2c3e50; margin: 30px 0 16px; padding-bottom: 8px; border-bottom: 2px solid #e0e0e0; display: flex; align-items: center; gap: 10px; }
        .section-label .sl-badge { background: #e74c3c; color: #fff; font-size: 0.75rem; padding: 2px 10px; border-radius: 12px; font-family: 'Roboto', sans-serif; }

        .rate-card { background: #fff; border-radius: 14px; padding: 0; margin-bottom: 24px; box-shadow: 0 3px 16px rgba(0,0,0,0.06); overflow: hidden; border: 1px solid #eee; }
        .rate-card.unrated { border-color: #ffe082; }
        .rate-card-top { padding: 24px 28px; border-bottom: 1px solid #f0f0f0; }
        .rate-card-top .rc-head { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 12px; }
        .rate-card-top .rc-head h3 { font-family: 'Oswald', sans-serif; font-size: 1.15rem; color: #2c3e50; margin: 0; }
        .rc-tag { padding: 4px 12px; border-radius: 20px; font-size: 0.78rem; font-weight: 600; }
        .rc-tag.completed { background: #e8f5e9; color: #2e7d32; }
        .rc-tag.rated { background: #e3f2fd; color: #1565c0; }
        .rc-meta { display: flex; flex-wrap: wrap; gap: 8px 20px; font-size: 0.88rem; color: #666; }
        .rc-meta span { display: flex; align-items: center; gap: 6px; }
        .rc-meta i { color: #aaa; width: 14px; text-align: center; }

        /* Technician profile */
        .tech-profile { display: flex; gap: 20px; padding: 24px 28px; background: linear-gradient(135deg, #f9fafb, #eef2f7); align-items: center; }
        .tech-avatar { width: 68px; height: 68px; border-radius: 50%; background: linear-gradient(135deg, #e74c3c, #c0392b); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 1.7rem; font-weight: 700; font-family: 'Oswald', sans-serif; flex-shrink: 0; box-shadow: 0 3px 10px rgba(231,76,60,0.25); }
        .tech-info { flex: 1; }
        .tech-info h4 { margin: 0 0 3px; font-family: 'Oswald', sans-serif; font-size: 1.15rem; color: #2c3e50; }
        .tech-info .tech-role { font-size: 0.82rem; color: #888; margin-bottom: 8px; display: flex; align-items: center; gap: 6px; }
        .tech-info .tech-role i { color: #aaa; }
        .tech-stats { display: flex; gap: 20px; flex-wrap: wrap; }
        .tech-stats .ts { display: flex; align-items: center; gap: 5px; font-size: 0.85rem; color: #555; }
        .tech-stats .ts .ts-val { font-weight: 700; color: #333; }
        .tech-stats .ts i.star { color: #f1c40f; }
        .tech-stats .ts i.check { color: #388e3c; }

        /* Rating form area */
        .rate-form-area { padding: 24px 28px; }
        .rate-form-area .rf-title { font-weight: 600; color: #333; margin-bottom: 12px; font-size: 0.95rem; display: flex; align-items: center; gap: 8px; }
        .rate-form-area .rf-title i { color: #f1c40f; }

        .star-pick { display: flex; gap: 4px; flex-direction: row-reverse; justify-content: flex-end; }
        .star-pick input { display: none; }
        .star-pick label { font-size: 32px; color: #ddd; cursor: pointer; transition: color 0.2s, transform 0.15s; }
        .star-pick label:hover { transform: scale(1.15); }
        .star-pick input:checked ~ label, .star-pick label:hover, .star-pick label:hover ~ label { color: #f1c40f; }

        .rate-comment { width: 100%; border: 1px solid #ddd; border-radius: 10px; padding: 12px 14px; margin-top: 14px; resize: vertical; font-family: inherit; font-size: 0.92rem; transition: border-color 0.2s; box-sizing: border-box; }
        .rate-comment:focus { outline: none; border-color: #f1c40f; box-shadow: 0 0 0 3px rgba(241,196,15,0.12); }

        .btn-rate { background: linear-gradient(135deg, #e74c3c, #c0392b); color: #fff; border: none; padding: 12px 28px; border-radius: 10px; font-weight: 600; cursor: pointer; margin-top: 14px; display: inline-flex; align-items: center; gap: 8px; font-size: 0.95rem; transition: all 0.2s; box-shadow: 0 3px 10px rgba(231,76,60,0.2); }
        .btn-rate:hover { transform: translateY(-1px); box-shadow: 0 5px 16px rgba(231,76,60,0.3); }

        .btn-skip { background: none; border: 1px solid #ddd; color: #888; padding: 10px 20px; border-radius: 10px; font-weight: 500; cursor: pointer; margin-top: 14px; margin-left: 10px; font-size: 0.9rem; transition: all 0.2s; }
        .btn-skip:hover { border-color: #bbb; color: #555; }

        /* Rated display */
        .rated-section { padding: 24px 28px; }
        .rated-header { display: flex; align-items: center; gap: 10px; margin-bottom: 6px; }
        .rated-header span.lbl { font-weight: 600; color: #333; font-size: 0.92rem; }
        .rated-stars { color: #f1c40f; font-size: 20px; display: flex; gap: 2px; }
        .rated-value { color: #888; font-size: 0.88rem; }
        .rated-comment { font-size: 0.88rem; color: #555; font-style: italic; margin-top: 6px; padding: 10px 14px; background: #f8f9fa; border-radius: 8px; }
        .rated-date { font-size: 0.78rem; color: #aaa; margin-top: 6px; }

        /* Alerts */
        .r-alert { padding: 14px 20px; border-radius: 10px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; font-weight: 500; animation: rSlide 0.4s ease; }
        .r-alert.r-success { background: #d4edda; color: #155724; border: 1px solid #b1dfbb; }
        .r-alert.r-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        @keyframes rSlide { from { opacity: 0; transform: translateY(-15px); } to { opacity: 1; transform: translateY(0); } }

        .empty-state { text-align: center; padding: 60px 20px; color: #999; }
        .empty-state i { font-size: 3rem; margin-bottom: 15px; display: block; color: #ddd; }
        .empty-state h3 { color: #666; margin-bottom: 8px; }
        .empty-state a { color: #e74c3c; text-decoration: none; font-weight: 600; }
        .empty-state a:hover { text-decoration: underline; }

        @media (max-width: 600px) {
            .tech-profile { flex-direction: column; text-align: center; }
            .tech-stats { justify-content: center; }
            .rc-meta { flex-direction: column; }
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
            <a href="profile.php"><i class="fas fa-user-circle"></i> My Profile</a>
            <a href="orders.php"><i class="fas fa-box"></i> My Orders</a>
            <a href="book_service.php"><i class="fas fa-calendar-check"></i> Book Service</a>
            <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout (<?= htmlspecialchars($full_name) ?>)</a>
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
                    <li class="has-dropdown">
                        <a href="../index.php#services"><i class="fas fa-wrench"></i> Services <i class="fas fa-chevron-down"></i></a>
                        <ul class="dropdown">
                            <?php
                            $navSvcIcons = ['fa-oil-can','fa-cogs','fa-compact-disc','fa-circle-notch','fa-car-battery','fa-spray-can','fa-wrench','fa-tools'];
                            foreach ($dbServices as $ni => $ns):
                                $navIcon = $navSvcIcons[$ni % count($navSvcIcons)];
                            ?>
                            <li><a href="../index.php"><i class="fas <?= $navIcon ?>"></i> <?= htmlspecialchars($ns['service_name']) ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                    <li class="has-dropdown">
                        <a href="../index.php#shop"><i class="fas fa-store"></i> Shop <i class="fas fa-chevron-down"></i></a>
                        <ul class="dropdown">
                            <?php foreach ($categories as $navCat):
                                $navCatIcon = $catIcons[$navCat['category']] ?? 'fa-box';
                            ?>
                            <li><a href="../index.php#shop"><i class="fas <?= $navCatIcon ?>"></i> <?= htmlspecialchars($navCat['category']) ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                    <li><a href="../index.php#about"><i class="fas fa-info-circle"></i> About</a></li>
                    <li><a href="../index.php#contact"><i class="fas fa-envelope"></i> Contact</a></li>
                    <li><a href="technicians.php"><i class="fas fa-users-cog"></i> Technicians</a></li>
                    <li><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                </ul>
            </nav>
            <div class="header-actions">
                <a href="notifications.php" class="header-icon" title="Notifications">
                    <i class="fas fa-bell"></i>
                    <?php
                        $nStmt2 = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
                        $nStmt2->execute([$_SESSION['user_id']]);
                        $nCount2 = (int)$nStmt2->fetchColumn();
                        if ($nCount2 > 0): ?><span class="badge"><?= $nCount2 ?></span><?php endif; ?>
                </a>
                <a href="cart.php" class="header-icon" title="Cart">
                    <i class="fas fa-shopping-cart"></i>
                    <span class="badge"><?= $cartCount ?></span>
                </a>
                <a href="orders.php" class="header-icon" title="My Orders" style="margin-left: 8px;">
                    <i class="fas fa-receipt"></i>
                </a>
                <a href="invoices.php" class="header-icon" title="My Invoices">
                    <i class="fas fa-file-invoice-dollar"></i>
                </a>
            </div>
            <button class="mobile-toggle" id="mobileToggle">
                <i class="fas fa-bars"></i>
            </button>
        </div>
    </div>
</header>

<!-- ========== RATINGS SECTION ========== -->
<section class="rating-section">
    <div class="container">
        <a href="dashboard.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        <h1 class="page-title"><i class="fas fa-star"></i> Rate Your Services</h1>
        <p class="page-subtitle">Help us improve by rating the technicians who serviced your vehicle.</p>

        <?php if ($rating_success): ?>
            <div class="r-alert r-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($rating_success) ?></div>
        <?php endif; ?>
        <?php if ($rating_error): ?>
            <div class="r-alert r-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($rating_error) ?></div>
        <?php endif; ?>

        <?php if (empty($completedServices)): ?>
            <div class="empty-state">
                <i class="fas fa-clipboard-check"></i>
                <h3>No completed services yet</h3>
                <p>Once a service is finished, you'll be able to rate your technician here.</p>
                <a href="dashboard.php"><i class="fas fa-arrow-left"></i> Go to Dashboard</a>
            </div>
        <?php else: ?>

            <!-- Unrated Services -->
            <?php if (!empty($unratedServices)): ?>
            <div class="section-label">
                <i class="fas fa-star" style="color:#f1c40f;"></i> Awaiting Your Rating
                <span class="sl-badge"><?= count($unratedServices) ?></span>
            </div>

            <?php foreach ($unratedServices as $svc): ?>
            <div class="rate-card unrated">
                <div class="rate-card-top">
                    <div class="rc-head">
                        <h3><i class="fas fa-wrench"></i> <?= htmlspecialchars($svc['service_name'] ?? 'Vehicle Service') ?></h3>
                        <span class="rc-tag completed"><i class="fas fa-check-circle"></i> Completed</span>
                    </div>
                    <div class="rc-meta">
                        <span><i class="fas fa-car"></i> <?= htmlspecialchars(($svc['make'] ?? '') . ' ' . ($svc['model'] ?? '') . ' - ' . ($svc['plate_number'] ?? '')) ?></span>
                        <span><i class="fas fa-calendar"></i> <?= date('M d, Y h:i A', strtotime($svc['appointment_date'])) ?></span>
                        <?php if ($svc['base_price']): ?>
                        <span><i class="fas fa-peso-sign"></i> ₱<?= number_format($svc['base_price'], 2) ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($svc['tech_name']): ?>
                <div class="tech-profile">
                    <div class="tech-avatar"><?= strtoupper(substr($svc['tech_name'], 0, 1)) ?></div>
                    <div class="tech-info">
                        <h4><?= htmlspecialchars($svc['tech_name']) ?></h4>
                        <div class="tech-role"><i class="fas fa-id-badge"></i> Service Technician</div>
                        <div class="tech-stats">
                            <div class="ts">
                                <i class="fas fa-star star"></i>
                                <span class="ts-val"><?= $svc['tech_avg_rating'] ? $svc['tech_avg_rating'] : 'New' ?></span>
                                <span>(<?= (int)$svc['tech_total_ratings'] ?> review<?= (int)$svc['tech_total_ratings'] !== 1 ? 's' : '' ?>)</span>
                            </div>
                            <div class="ts">
                                <i class="fas fa-check-circle check"></i>
                                <span class="ts-val"><?= (int)$svc['tech_completed_jobs'] ?></span>
                                <span>jobs completed</span>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="rate-form-area">
                    <div class="rf-title"><i class="fas fa-star"></i> How was your experience with <?= htmlspecialchars($svc['tech_name'] ?? 'the technician') ?>?</div>
                    <form method="POST">
                        <input type="hidden" name="action" value="submit_rating">
                        <input type="hidden" name="assignment_id" value="<?= $svc['assignment_id'] ?>">
                        <input type="hidden" name="technician_id" value="<?= $svc['tech_user_id'] ?>">
                        <div class="star-pick">
                            <?php for ($i = 5; $i >= 1; $i--): ?>
                            <input type="radio" name="rating_value" id="star<?= $svc['assignment_id'] ?>_<?= $i ?>" value="<?= $i ?>" required>
                            <label for="star<?= $svc['assignment_id'] ?>_<?= $i ?>"><i class="fas fa-star"></i></label>
                            <?php endfor; ?>
                        </div>
                        <textarea name="comment" rows="3" placeholder="Tell us about your experience — what went well, what could be improved? (optional)" class="rate-comment"></textarea>
                        <div>
                            <button type="submit" class="btn-rate"><i class="fas fa-paper-plane"></i> Submit Rating</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>

            <!-- Already Rated Services -->
            <?php if (!empty($ratedServices)): ?>
            <div class="section-label">
                <i class="fas fa-check-circle" style="color:#388e3c;"></i> Your Past Ratings
            </div>

            <?php foreach ($ratedServices as $svc): ?>
            <?php $rated = $ratedAssignments[$svc['assignment_id']]; ?>
            <div class="rate-card">
                <div class="rate-card-top">
                    <div class="rc-head">
                        <h3><i class="fas fa-wrench"></i> <?= htmlspecialchars($svc['service_name'] ?? 'Vehicle Service') ?></h3>
                        <span class="rc-tag rated"><i class="fas fa-star"></i> Rated</span>
                    </div>
                    <div class="rc-meta">
                        <span><i class="fas fa-car"></i> <?= htmlspecialchars(($svc['make'] ?? '') . ' ' . ($svc['model'] ?? '') . ' - ' . ($svc['plate_number'] ?? '')) ?></span>
                        <span><i class="fas fa-calendar"></i> <?= date('M d, Y h:i A', strtotime($svc['appointment_date'])) ?></span>
                        <?php if ($svc['base_price']): ?>
                        <span><i class="fas fa-peso-sign"></i> ₱<?= number_format($svc['base_price'], 2) ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($svc['tech_name']): ?>
                <div class="tech-profile">
                    <div class="tech-avatar"><?= strtoupper(substr($svc['tech_name'], 0, 1)) ?></div>
                    <div class="tech-info">
                        <h4><?= htmlspecialchars($svc['tech_name']) ?></h4>
                        <div class="tech-role"><i class="fas fa-id-badge"></i> Service Technician</div>
                        <div class="tech-stats">
                            <div class="ts">
                                <i class="fas fa-star star"></i>
                                <span class="ts-val"><?= $svc['tech_avg_rating'] ? $svc['tech_avg_rating'] : 'New' ?></span>
                                <span>(<?= (int)$svc['tech_total_ratings'] ?> review<?= (int)$svc['tech_total_ratings'] !== 1 ? 's' : '' ?>)</span>
                            </div>
                            <div class="ts">
                                <i class="fas fa-check-circle check"></i>
                                <span class="ts-val"><?= (int)$svc['tech_completed_jobs'] ?></span>
                                <span>jobs completed</span>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="rated-section">
                    <div class="rated-header">
                        <span class="lbl">Your Rating:</span>
                        <span class="rated-stars">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <i class="fa<?= $i <= $rated['rating_value'] ? 's' : 'r' ?> fa-star"></i>
                            <?php endfor; ?>
                        </span>
                        <span class="rated-value">(<?= $rated['rating_value'] ?>/5)</span>
                    </div>
                    <?php if ($rated['comment']): ?>
                        <div class="rated-comment">"<?= htmlspecialchars($rated['comment']) ?>"</div>
                    <?php endif; ?>
                    <?php if (!empty($rated['created_at'])): ?>
                        <div class="rated-date"><i class="fas fa-clock"></i> Rated on <?= date('M d, Y h:i A', strtotime($rated['created_at'])) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
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
                    <a href="../index.php" class="footer-logo">
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
                        <li><a href="../index.php">Home</a></li>
                        <li><a href="../index.php#services">Services</a></li>
                        <li><a href="../index.php#shop">Shop</a></li>
                        <li><a href="../index.php#about">About Us</a></li>
                        <li><a href="../index.php#contact">Contact</a></li>
                    </ul>
                </div>
                <div class="footer-col">
                    <h4>Our Services</h4>
                    <ul>
                        <?php foreach (array_slice($dbServices, 0, 5) as $fs): ?>
                        <li><a href="../index.php#services"><?= htmlspecialchars($fs['service_name']) ?></a></li>
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
            <p>&copy; <?= date('Y') ?> VehiCare. All rights reserved. | Designed for Vehicle Service DB</p>
            <div class="payment-methods">
                <i class="fab fa-cc-visa"></i>
                <i class="fab fa-cc-mastercard"></i>
                <i class="fab fa-cc-paypal"></i>
                <i class="fas fa-money-bill-wave"></i>
            </div>
        </div>
    </div>
</footer>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var toggle = document.getElementById('mobileToggle');
    var nav = document.querySelector('.main-nav');
    if (toggle && nav) {
        toggle.addEventListener('click', function() { nav.classList.toggle('active'); });
    }
});
</script>
</body>
</html>
