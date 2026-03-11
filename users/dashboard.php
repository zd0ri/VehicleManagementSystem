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

// ── Handle rating submission (redirect to ratings page) ──
$rating_success = $_GET['rated'] ?? '';
$rating_error = '';

// ── Fetch active services (appointments with assignments) ──
$activeStmt = $pdo->prepare("
    SELECT ap.appointment_id, ap.appointment_date, ap.status AS appt_status, ap.service_id AS appt_service_id,
           a.assignment_id, a.status AS assign_status, a.notes AS tech_notes, a.start_time, a.end_time,
           s.service_name, s.base_price,
           v.plate_number, v.make, v.model,
           u.full_name AS tech_name, u.user_id AS tech_user_id,
           (SELECT ROUND(AVG(r2.rating_value),1) FROM ratings r2 WHERE r2.technician_id = u.user_id) AS tech_avg_rating,
           (SELECT COUNT(*) FROM ratings r3 WHERE r3.technician_id = u.user_id) AS tech_total_ratings,
           (SELECT COUNT(*) FROM assignments a2 WHERE a2.technician_id = u.user_id AND a2.status = 'Finished') AS tech_completed_jobs
    FROM appointments ap
    LEFT JOIN assignments a ON ap.appointment_id = a.appointment_id
    LEFT JOIN services s ON COALESCE(a.service_id, ap.service_id) = s.service_id
    LEFT JOIN vehicles v ON ap.vehicle_id = v.vehicle_id
    LEFT JOIN users u ON a.technician_id = u.user_id
    WHERE ap.client_id = ?
    ORDER BY ap.appointment_date DESC
");
$activeStmt->execute([$client_id]);
$allServices = $activeStmt->fetchAll();

// Separate into active (in-progress) and completed
$activeServices = [];
$completedServices = [];
$pendingServices = [];
foreach ($allServices as $svc) {
    if (in_array($svc['assign_status'], ['Assigned', 'Ongoing'])) {
        $activeServices[] = $svc;
    } elseif (in_array($svc['assign_status'], ['Done', 'Finished'])) {
        $completedServices[] = $svc;
    } else {
        // No assignment or pending appointment
        $pendingServices[] = $svc;
    }
}

// Check which assignments already have ratings
$ratedAssignments = [];
if (!empty($completedServices)) {
    $assignIds = array_filter(array_column($completedServices, 'assignment_id'));
    if (!empty($assignIds)) {
        $placeholders = implode(',', array_fill(0, count($assignIds), '?'));
        $rStmt = $pdo->prepare("SELECT assignment_id, rating_value, comment FROM ratings WHERE assignment_id IN ($placeholders) AND client_id = ?");
        $rStmt->execute([...$assignIds, $client_id]);
        foreach ($rStmt->fetchAll() as $r) {
            $ratedAssignments[$r['assignment_id']] = $r;
        }
    }
}

// Count unrated completed services
$unratedServices = array_filter($completedServices, function($svc) use ($ratedAssignments) {
    return !isset($ratedAssignments[$svc['assignment_id']]);
});

// ── Data for nav dropdowns (match index.php) ──
$dbServices = $pdo->query("SELECT * FROM services ORDER BY service_name ASC")->fetchAll();
$categories = $pdo->query("SELECT category, COUNT(*) as cnt FROM inventory WHERE quantity > 0 AND category IS NOT NULL GROUP BY category ORDER BY cnt DESC")->fetchAll();
$catIcons = ['Engine Parts'=>'fa-cogs','Brake System'=>'fa-compact-disc','Oils & Fluids'=>'fa-oil-can','Tires & Wheels'=>'fa-circle-notch','Battery & Electrical'=>'fa-car-battery','Body & Exterior'=>'fa-car','Filters'=>'fa-filter','Accessories'=>'fa-star'];

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
    <title>My Dashboard - VehiCare</title>
    <link rel="stylesheet" href="../includes/style/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700;900&family=Oswald:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ── Dashboard-specific styles ── */
        .dash-section { padding: 60px 0; min-height: 60vh; background: #f8f9fa; }
        .dash-section .container { max-width: 1100px; margin: 0 auto; padding: 0 20px; }
        .dash-title { font-family: 'Oswald', sans-serif; font-size: 32px; font-weight: 700; color: #2c3e50; margin-bottom: 30px; display: flex; align-items: center; gap: 12px; }
        .dash-title i { color: #e74c3c; }

        .dash-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 35px; }
        .dash-stat { background: #fff; border-radius: 12px; padding: 24px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); display: flex; align-items: center; gap: 16px; }
        .dash-stat .ds-icon { width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; }
        .dash-stat .ds-icon.ds-active { background: #e3f2fd; color: #1976d2; }
        .dash-stat .ds-icon.ds-pending { background: #fff3e0; color: #f57c00; }
        .dash-stat .ds-icon.ds-done { background: #e8f5e9; color: #388e3c; }
        .dash-stat h3 { font-size: 1.8rem; margin: 0; color: #333; }
        .dash-stat p { font-size: 0.85rem; margin: 0; color: #888; }

        .dash-tabs { display: flex; gap: 0; margin-bottom: 25px; border-bottom: 2px solid #e0e0e0; }
        .dash-tabs button { padding: 12px 24px; border: none; background: none; font-size: 15px; font-weight: 600; color: #888; cursor: pointer; border-bottom: 3px solid transparent; margin-bottom: -2px; transition: all 0.2s; }
        .dash-tabs button.ds-selected { color: #e74c3c; border-bottom-color: #e74c3c; }
        .dash-tabs button:hover { color: #e74c3c; }

        .dash-panel { display: none; }
        .dash-panel.ds-visible { display: block; }

        .svc-card { background: #fff; border-radius: 12px; padding: 24px; margin-bottom: 16px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); border-left: 4px solid #e0e0e0; }
        .svc-card.st-assigned { border-left-color: #1976d2; }
        .svc-card.st-ongoing { border-left-color: #f57c00; }
        .svc-card.st-done { border-left-color: #388e3c; }
        .svc-card.st-pending { border-left-color: #9e9e9e; }

        .svc-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; flex-wrap: wrap; gap: 10px; }
        .svc-head h3 { font-family: 'Oswald', sans-serif; font-size: 1.2rem; color: #2c3e50; margin: 0; }

        .svc-tag { padding: 5px 14px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; }
        .svc-tag.t-assigned { background: #e3f2fd; color: #1565c0; }
        .svc-tag.t-ongoing { background: #fff3e0; color: #e65100; }
        .svc-tag.t-done { background: #e8f5e9; color: #2e7d32; }
        .svc-tag.t-pending { background: #f5f5f5; color: #757575; }

        .svc-info { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 20px; font-size: 0.92rem; color: #555; }
        .svc-info span { display: flex; align-items: center; gap: 8px; }
        .svc-info i { color: #999; width: 16px; text-align: center; }

        .svc-progress { margin-top: 16px; padding-top: 16px; border-top: 1px solid #f0f0f0; display: flex; align-items: center; gap: 0; }
        .prog-step { flex: 1; text-align: center; position: relative; }
        .prog-step .prog-dot { width: 28px; height: 28px; border-radius: 50%; background: #e0e0e0; margin: 0 auto 6px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 12px; }
        .prog-step.p-reached .prog-dot { background: #388e3c; }
        .prog-step.p-current .prog-dot { background: #f57c00; animation: dashPulse 1.5s infinite; }
        .prog-step .prog-lbl { font-size: 0.75rem; color: #999; }
        .prog-step.p-reached .prog-lbl, .prog-step.p-current .prog-lbl { color: #333; font-weight: 600; }
        .prog-line { flex: 0 0 40px; height: 3px; background: #e0e0e0; }
        .prog-line.p-reached { background: #388e3c; }
        @keyframes dashPulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.15); } }

        .svc-note { margin-top: 12px; padding: 12px 16px; background: #f8f9fa; border-radius: 8px; font-size: 0.88rem; color: #555; }
        .svc-note i { color: #999; margin-right: 6px; }

        .rate-area { margin-top: 16px; padding-top: 16px; border-top: 1px solid #f0f0f0; }
        .star-pick { display: flex; gap: 4px; flex-direction: row-reverse; justify-content: flex-end; }
        .star-pick input { display: none; }
        .star-pick label { font-size: 28px; color: #ddd; cursor: pointer; transition: color 0.2s; }
        .star-pick input:checked ~ label, .star-pick label:hover, .star-pick label:hover ~ label { color: #f1c40f; }
        .rate-comment { width: 100%; border: 1px solid #ddd; border-radius: 8px; padding: 10px; margin-top: 10px; resize: vertical; font-family: inherit; }
        .btn-rate { background: #e74c3c; color: #fff; border: none; padding: 10px 24px; border-radius: 8px; font-weight: 600; cursor: pointer; margin-top: 10px; display: inline-flex; align-items: center; gap: 8px; transition: background 0.2s; }
        .btn-rate:hover { background: #c0392b; }

        .rated-display { display: flex; align-items: center; gap: 8px; margin-top: 8px; }
        .rated-display .rated-stars { color: #f1c40f; font-size: 18px; }
        .rated-comment { font-size: 0.88rem; color: #555; font-style: italic; margin-top: 4px; }

        .dash-empty { text-align: center; padding: 60px 20px; color: #999; }
        .dash-empty i { font-size: 3rem; margin-bottom: 15px; display: block; color: #ddd; }
        .dash-empty h3 { color: #666; margin-bottom: 8px; }

        .dash-alert { padding: 14px 20px; border-radius: 10px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; font-weight: 500; animation: dashSlide 0.4s ease; }
        .dash-alert.da-success { background: #d4edda; color: #155724; border: 1px solid #b1dfbb; }
        .dash-alert.da-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        @keyframes dashSlide { from { opacity: 0; transform: translateY(-15px); } to { opacity: 1; transform: translateY(0); } }

        /* Unrated reminder banner */
        .unrated-banner { background: linear-gradient(135deg, #fff8e1, #fff3cd); border: 1px solid #ffe082; border-radius: 12px; padding: 18px 24px; margin-bottom: 20px; display: flex; align-items: center; gap: 14px; cursor: pointer; transition: all 0.2s; }
        .unrated-banner:hover { box-shadow: 0 4px 16px rgba(241,196,15,0.2); transform: translateY(-1px); }
        .unrated-banner .ub-icon { width: 45px; height: 45px; border-radius: 50%; background: #f1c40f; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0; }
        .unrated-banner .ub-text h4 { margin: 0 0 2px; color: #856404; font-size: 0.95rem; }
        .unrated-banner .ub-text p { margin: 0; color: #997a00; font-size: 0.85rem; }
        .unrated-banner .ub-arrow { margin-left: auto; color: #997a00; font-size: 1.1rem; }

        /* Technician profile card in completed services */
        .tech-profile-box { display: flex; align-items: center; gap: 18px; margin-top: 16px; padding: 18px; background: linear-gradient(135deg, #f8f9fa, #eef2f7); border-radius: 12px; border: 1px solid #e3e8ef; }
        .tech-avatar { width: 56px; height: 56px; border-radius: 50%; background: linear-gradient(135deg, #e74c3c, #c0392b); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; font-weight: 700; font-family: 'Oswald', sans-serif; flex-shrink: 0; }
        .tech-details { flex: 1; }
        .tech-details h4 { margin: 0 0 4px; color: #2c3e50; font-size: 1.05rem; font-family: 'Oswald', sans-serif; }
        .tech-details .tech-role { font-size: 0.8rem; color: #888; margin-bottom: 6px; }
        .tech-stats-row { display: flex; gap: 16px; flex-wrap: wrap; }
        .tech-stat-item { font-size: 0.82rem; color: #555; display: flex; align-items: center; gap: 4px; }
        .tech-stat-item i { font-size: 0.75rem; }
        .tech-stat-item .ts-val { font-weight: 700; color: #333; }
        .tech-stat-item .star-color { color: #f1c40f; }

        /* Highlight unrated card */
        .svc-card.unrated-highlight { border-left-color: #f1c40f; box-shadow: 0 2px 12px rgba(241,196,15,0.15); }
        .rate-prompt { background: linear-gradient(135deg, #fff8e1, #fffdf5); border: 1px solid #ffe082; border-radius: 10px; padding: 16px; margin-top: 12px; }
        .rate-prompt > p { color: #856404; font-weight: 600; margin-bottom: 10px; }

        @media (max-width: 600px) {
            .svc-info { grid-template-columns: 1fr; }
            .dash-summary { grid-template-columns: 1fr; }
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
            <a href="ratings.php"><i class="fas fa-star"></i> My Ratings</a>
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
                    <li><a href="dashboard.php" class="active"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
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

<!-- ========== DASHBOARD SECTION ========== -->
<section class="dash-section">
    <div class="container">
        <h1 class="dash-title"><i class="fas fa-tachometer-alt"></i> My Service Dashboard</h1>

        <?php if ($rating_success): ?>
            <div class="dash-alert da-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($rating_success) ?></div>
        <?php endif; ?>

        <?php if (!empty($unratedServices)): ?>
        <a href="ratings.php" class="unrated-banner" style="text-decoration:none;">
            <div class="ub-icon"><i class="fas fa-star"></i></div>
            <div class="ub-text">
                <h4><i class="fas fa-bell"></i> You have <?= count($unratedServices) ?> completed service<?= count($unratedServices) > 1 ? 's' : '' ?> to rate!</h4>
                <p>Your feedback helps our technicians improve. Click here to rate now.</p>
            </div>
            <div class="ub-arrow"><i class="fas fa-chevron-right"></i></div>
        </a>
        <?php endif; ?>

        <!-- Summary Cards -->
        <div class="dash-summary">
            <div class="dash-stat">
                <div class="ds-icon ds-active"><i class="fas fa-tools"></i></div>
                <div>
                    <h3><?= count($activeServices) ?></h3>
                    <p>In Progress</p>
                </div>
            </div>
            <div class="dash-stat">
                <div class="ds-icon ds-pending"><i class="fas fa-clock"></i></div>
                <div>
                    <h3><?= count($pendingServices) ?></h3>
                    <p>Pending / Queued</p>
                </div>
            </div>
            <div class="dash-stat">
                <div class="ds-icon ds-done"><i class="fas fa-check-circle"></i></div>
                <div>
                    <h3><?= count($completedServices) ?></h3>
                    <p>Completed</p>
                </div>
            </div>
            <a href="ratings.php" class="dash-stat" style="text-decoration:none; cursor:pointer; transition: transform 0.2s, box-shadow 0.2s;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 16px rgba(241,196,15,0.2)';" onmouseout="this.style.transform=''; this.style.boxShadow='';">
                <div class="ds-icon" style="background:#fff8e1; color:#f1c40f;"><i class="fas fa-star"></i></div>
                <div>
                    <h3><?= count($unratedServices) ?></h3>
                    <p>To Rate</p>
                </div>
            </a>
        </div>

        <!-- Tabs -->
        <div class="dash-tabs">
            <button class="ds-selected" onclick="dashTab('active', this)"><i class="fas fa-tools"></i> Active (<?= count($activeServices) ?>)</button>
            <button onclick="dashTab('pending', this)"><i class="fas fa-clock"></i> Pending (<?= count($pendingServices) ?>)</button>
            <button onclick="dashTab('completed', this)"><i class="fas fa-check-circle"></i> Completed (<?= count($completedServices) ?>)</button>
        </div>

        <!-- Active Services -->
        <div class="dash-panel ds-visible" id="dp-active">
            <?php if (empty($activeServices)): ?>
                <div class="dash-empty">
                    <i class="fas fa-tools"></i>
                    <h3>No active services</h3>
                    <p>You don't have any services in progress right now.</p>
                </div>
            <?php else: ?>
                <?php foreach ($activeServices as $svc): ?>
                <?php
                    $statusClass = strtolower($svc['assign_status']);
                    $steps = ['Booked' => false, 'Assigned' => false, 'Ongoing' => false, 'Done' => false];
                    $currentStep = $svc['assign_status'] === 'Finished' ? 'Done' : $svc['assign_status'];
                    $reached = true;
                    foreach ($steps as $step => &$val) {
                        if ($step === $currentStep) { $val = 'current'; $reached = false; }
                        elseif ($reached) { $val = 'reached'; }
                    }
                    unset($val);
                ?>
                <div class="svc-card st-<?= $statusClass ?>">
                    <div class="svc-head">
                        <h3><i class="fas fa-wrench"></i> <?= htmlspecialchars($svc['service_name'] ?? 'Vehicle Service') ?></h3>
                        <span class="svc-tag t-<?= $statusClass ?>">
                            <i class="fas fa-<?= $statusClass === 'ongoing' ? 'spinner fa-spin' : 'user-check' ?>"></i>
                            <?= htmlspecialchars($svc['assign_status']) ?>
                        </span>
                    </div>
                    <div class="svc-info">
                        <span><i class="fas fa-car"></i> <?= htmlspecialchars(($svc['make'] ?? '') . ' ' . ($svc['model'] ?? '') . ' - ' . ($svc['plate_number'] ?? '')) ?></span>
                        <span><i class="fas fa-calendar"></i> <?= date('M d, Y h:i A', strtotime($svc['appointment_date'])) ?></span>
                        <span><i class="fas fa-user-cog"></i> Technician: <strong><?= htmlspecialchars($svc['tech_name'] ?? 'Unassigned') ?></strong></span>
                        <?php if ($svc['base_price']): ?>
                        <span><i class="fas fa-peso-sign"></i> ₱<?= number_format($svc['base_price'], 2) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="svc-progress">
                        <?php $i = 0; foreach ($steps as $step => $state): ?>
                            <?php if ($i > 0): ?><div class="prog-line <?= ($state === 'reached' || $state === 'current') ? 'p-reached' : '' ?>"></div><?php endif; ?>
                            <div class="prog-step <?= $state === 'reached' ? 'p-reached' : ($state === 'current' ? 'p-current' : '') ?>">
                                <div class="prog-dot">
                                    <?php if ($state === 'reached'): ?><i class="fas fa-check"></i>
                                    <?php elseif ($state === 'current'): ?><i class="fas fa-circle"></i>
                                    <?php else: ?><?= $i + 1 ?><?php endif; ?>
                                </div>
                                <div class="prog-lbl"><?= $step ?></div>
                            </div>
                        <?php $i++; endforeach; ?>
                    </div>
                    <?php if ($svc['tech_notes']): ?>
                    <div class="svc-note"><i class="fas fa-sticky-note"></i> Technician Note: <?= htmlspecialchars($svc['tech_notes']) ?></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Pending Services -->
        <div class="dash-panel" id="dp-pending">
            <?php if (empty($pendingServices)): ?>
                <div class="dash-empty">
                    <i class="fas fa-clock"></i>
                    <h3>No pending services</h3>
                    <p>All your appointments have been assigned.</p>
                </div>
            <?php else: ?>
                <?php foreach ($pendingServices as $svc): ?>
                <div class="svc-card st-pending">
                    <div class="svc-head">
                        <h3><i class="fas fa-wrench"></i> <?= htmlspecialchars($svc['service_name'] ?? 'Vehicle Service') ?></h3>
                        <span class="svc-tag t-pending">
                            <i class="fas fa-hourglass-half"></i>
                            <?= htmlspecialchars($svc['appt_status']) ?>
                        </span>
                    </div>
                    <div class="svc-info">
                        <span><i class="fas fa-car"></i> <?= htmlspecialchars(($svc['make'] ?? '') . ' ' . ($svc['model'] ?? '') . ' - ' . ($svc['plate_number'] ?? '')) ?></span>
                        <span><i class="fas fa-calendar"></i> <?= date('M d, Y h:i A', strtotime($svc['appointment_date'])) ?></span>
                        <span><i class="fas fa-info-circle"></i> Waiting for technician assignment</span>
                    </div>
                    <div class="svc-progress">
                        <div class="prog-step p-current">
                            <div class="prog-dot"><i class="fas fa-circle"></i></div>
                            <div class="prog-lbl">Booked</div>
                        </div>
                        <div class="prog-line"></div>
                        <div class="prog-step">
                            <div class="prog-dot">2</div>
                            <div class="prog-lbl">Assigned</div>
                        </div>
                        <div class="prog-line"></div>
                        <div class="prog-step">
                            <div class="prog-dot">3</div>
                            <div class="prog-lbl">Ongoing</div>
                        </div>
                        <div class="prog-line"></div>
                        <div class="prog-step">
                            <div class="prog-dot">4</div>
                            <div class="prog-lbl">Done</div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Completed Services -->
        <div class="dash-panel" id="dp-completed">
            <?php if (empty($completedServices)): ?>
                <div class="dash-empty">
                    <i class="fas fa-check-circle"></i>
                    <h3>No completed services yet</h3>
                    <p>Once a service is finished, you can leave a rating here.</p>
                </div>
            <?php else: ?>
                <?php foreach ($completedServices as $svc): ?>
                <?php $rated = $ratedAssignments[$svc['assignment_id']] ?? null; ?>
                <div class="svc-card st-done <?= !$rated ? 'unrated-highlight' : '' ?>">
                    <div class="svc-head">
                        <h3><i class="fas fa-wrench"></i> <?= htmlspecialchars($svc['service_name'] ?? 'Vehicle Service') ?></h3>
                        <span class="svc-tag t-done">
                            <i class="fas fa-check-circle"></i> Completed
                        </span>
                    </div>
                    <div class="svc-info">
                        <span><i class="fas fa-car"></i> <?= htmlspecialchars(($svc['make'] ?? '') . ' ' . ($svc['model'] ?? '') . ' - ' . ($svc['plate_number'] ?? '')) ?></span>
                        <span><i class="fas fa-calendar"></i> <?= date('M d, Y h:i A', strtotime($svc['appointment_date'])) ?></span>
                        <?php if ($svc['base_price']): ?>
                        <span><i class="fas fa-peso-sign"></i> ₱<?= number_format($svc['base_price'], 2) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="svc-progress">
                        <div class="prog-step p-reached"><div class="prog-dot"><i class="fas fa-check"></i></div><div class="prog-lbl">Booked</div></div>
                        <div class="prog-line p-reached"></div>
                        <div class="prog-step p-reached"><div class="prog-dot"><i class="fas fa-check"></i></div><div class="prog-lbl">Assigned</div></div>
                        <div class="prog-line p-reached"></div>
                        <div class="prog-step p-reached"><div class="prog-dot"><i class="fas fa-check"></i></div><div class="prog-lbl">Ongoing</div></div>
                        <div class="prog-line p-reached"></div>
                        <div class="prog-step p-reached"><div class="prog-dot"><i class="fas fa-check"></i></div><div class="prog-lbl">Done</div></div>
                    </div>
                    <?php if ($svc['tech_notes']): ?>
                    <div class="svc-note"><i class="fas fa-sticky-note"></i> Technician Note: <?= htmlspecialchars($svc['tech_notes']) ?></div>
                    <?php endif; ?>

                    <!-- Technician Profile -->
                    <?php if ($svc['tech_name']): ?>
                    <a href="technicians.php?tech_id=<?= $svc['tech_user_id'] ?>" class="tech-profile-box" style="text-decoration:none; color:inherit; transition: box-shadow 0.2s, transform 0.2s;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 16px rgba(0,0,0,0.1)';" onmouseout="this.style.transform=''; this.style.boxShadow='';">
                        <div class="tech-avatar"><?= strtoupper(substr($svc['tech_name'], 0, 1)) ?></div>
                        <div class="tech-details">
                            <h4><?= htmlspecialchars($svc['tech_name']) ?></h4>
                            <div class="tech-role"><i class="fas fa-id-badge"></i> Service Technician</div>
                            <div class="tech-stats-row">
                                <div class="tech-stat-item">
                                    <i class="fas fa-star star-color"></i>
                                    <span class="ts-val"><?= $svc['tech_avg_rating'] ? $svc['tech_avg_rating'] : 'N/A' ?></span>
                                    <span>(<?= (int)$svc['tech_total_ratings'] ?> review<?= (int)$svc['tech_total_ratings'] !== 1 ? 's' : '' ?>)</span>
                                </div>
                                <div class="tech-stat-item">
                                    <i class="fas fa-check-circle" style="color:#388e3c;"></i>
                                    <span class="ts-val"><?= (int)$svc['tech_completed_jobs'] ?></span>
                                    <span>jobs done</span>
                                </div>
                            </div>
                        </div>
                    </a>
                    <?php endif; ?>

                    <!-- Rating Section -->
                    <div class="rate-area">
                        <?php if ($rated): ?>
                            <div class="rated-display">
                                <span style="font-weight:600; color:#333;">Your Rating:</span>
                                <span class="rated-stars">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="fa<?= $i <= $rated['rating_value'] ? 's' : 'r' ?> fa-star"></i>
                                    <?php endfor; ?>
                                </span>
                                <span style="color:#888;">(<?= $rated['rating_value'] ?>/5)</span>
                            </div>
                            <?php if ($rated['comment']): ?>
                                <div class="rated-comment">"<?= htmlspecialchars($rated['comment']) ?>"</div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="rate-prompt">
                                <p><i class="fas fa-star" style="color:#f1c40f;"></i> How was your experience with <?= htmlspecialchars($svc['tech_name'] ?? 'the technician') ?>?</p>
                                <a href="ratings.php" class="btn-rate" style="text-decoration:none;"><i class="fas fa-star"></i> Rate Now</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

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
function dashTab(tabId, btn) {
    document.querySelectorAll('.dash-panel').forEach(function(p) { p.classList.remove('ds-visible'); });
    document.querySelectorAll('.dash-tabs button').forEach(function(b) { b.classList.remove('ds-selected'); });
    document.getElementById('dp-' + tabId).classList.add('ds-visible');
    btn.classList.add('ds-selected');
}

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