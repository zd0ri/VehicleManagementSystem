<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

// ── Auth guard ──
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'customer') {
    header('Location: login.php');
    exit;
}

$user_id   = $_SESSION['user_id'];
$client_id = $_SESSION['client_id'];
$full_name = $_SESSION['full_name'];

$success_msg = '';
$error_msg   = '';

// ── POST handlers ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // ── Update Profile ──
    if ($action === 'update_profile') {
        $new_name    = trim($_POST['full_name'] ?? '');
        $new_email   = trim($_POST['email'] ?? '');
        $new_phone   = trim($_POST['phone'] ?? '');
        $new_address = trim($_POST['address'] ?? '');

        if ($new_name === '' || $new_email === '') {
            $error_msg = 'Full name and email are required.';
        } else {
            // Check email uniqueness (exclude self)
            $chk = $pdo->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
            $chk->execute([$new_email, $user_id]);
            if ($chk->fetch()) {
                $error_msg = 'That email address is already in use by another account.';
            } else {
                $pdo->prepare("UPDATE users SET full_name = ?, email = ? WHERE user_id = ?")
                    ->execute([$new_name, $new_email, $user_id]);
                $pdo->prepare("UPDATE clients SET full_name = ?, email = ?, phone = ?, address = ? WHERE client_id = ?")
                    ->execute([$new_name, $new_email, $new_phone, $new_address, $client_id]);

                $_SESSION['full_name'] = $new_name;
                $_SESSION['email']     = $new_email;
                $full_name = $new_name;

                $success_msg = 'Profile updated successfully.';
            }
        }
    }

    // ── Change Password ──
    if ($action === 'change_password') {
        $current  = $_POST['current_password'] ?? '';
        $newpass   = $_POST['new_password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        if ($current === '' || $newpass === '' || $confirm === '') {
            $error_msg = 'All password fields are required.';
        } elseif (strlen($newpass) < 6) {
            $error_msg = 'New password must be at least 6 characters.';
        } elseif ($newpass !== $confirm) {
            $error_msg = 'New password and confirmation do not match.';
        } else {
            $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $hash = $stmt->fetchColumn();

            if (!password_verify($current, $hash)) {
                $error_msg = 'Current password is incorrect.';
            } else {
                $new_hash = password_hash($newpass, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?")
                    ->execute([$new_hash, $user_id]);
                $success_msg = 'Password changed successfully.';
            }
        }
    }

    // ── Add Vehicle ──
    if ($action === 'add_vehicle') {
        $plate = strtoupper(trim($_POST['plate_number'] ?? ''));
        $vin   = strtoupper(trim($_POST['vin'] ?? ''));
        $make  = trim($_POST['make'] ?? '');
        $model = trim($_POST['model'] ?? '');
        $year  = (int) ($_POST['year'] ?? 0);
        $color = trim($_POST['color'] ?? '');

        if ($plate === '' || $make === '' || $model === '') {
            $error_msg = 'Plate number, make, and model are required.';
        } else {
            $pdo->prepare("INSERT INTO vehicles (client_id, plate_number, vin, make, model, year, color) VALUES (?, ?, ?, ?, ?, ?, ?)")
                ->execute([$client_id, $plate, $vin ?: null, $make, $model, $year ?: null, $color ?: null]);
            $success_msg = 'Vehicle added successfully.';
        }
    }

    // ── Edit Vehicle ──
    if ($action === 'edit_vehicle') {
        $vid   = (int) ($_POST['vehicle_id'] ?? 0);
        $plate = strtoupper(trim($_POST['plate_number'] ?? ''));
        $vin   = strtoupper(trim($_POST['vin'] ?? ''));
        $make  = trim($_POST['make'] ?? '');
        $model = trim($_POST['model'] ?? '');
        $year  = (int) ($_POST['year'] ?? 0);
        $color = trim($_POST['color'] ?? '');

        if ($plate === '' || $make === '' || $model === '') {
            $error_msg = 'Plate number, make, and model are required.';
        } else {
            $pdo->prepare("UPDATE vehicles SET plate_number = ?, vin = ?, make = ?, model = ?, year = ?, color = ? WHERE vehicle_id = ? AND client_id = ?")
                ->execute([$plate, $vin ?: null, $make, $model, $year ?: null, $color ?: null, $vid, $client_id]);
            $success_msg = 'Vehicle updated successfully.';
        }
    }

    // ── Delete Vehicle ──
    if ($action === 'delete_vehicle') {
        $vid = (int) ($_POST['vehicle_id'] ?? 0);
        $pdo->prepare("DELETE FROM vehicles WHERE vehicle_id = ? AND client_id = ?")->execute([$vid, $client_id]);
        $success_msg = 'Vehicle deleted successfully.';
    }
}

// ── Fetch profile data ──
$stmt = $pdo->prepare("SELECT u.full_name, u.email, u.created_at, c.phone, c.address
                        FROM users u JOIN clients c ON u.user_id = c.user_id
                        WHERE u.user_id = ?");
$stmt->execute([$user_id]);
$profile = $stmt->fetch();

// ── Quick stats ──
$totalOrders = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE client_id = ?");
$totalOrders->execute([$client_id]);
$totalOrders = $totalOrders->fetchColumn();

$totalVehicles = $pdo->prepare("SELECT COUNT(*) FROM vehicles WHERE client_id = ?");
$totalVehicles->execute([$client_id]);
$totalVehicles = $totalVehicles->fetchColumn();

$totalAppointments = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE client_id = ?");
$totalAppointments->execute([$client_id]);
$totalAppointments = $totalAppointments->fetchColumn();

// ── Vehicles list ──
$vStmt = $pdo->prepare("SELECT * FROM vehicles WHERE client_id = ? ORDER BY created_at DESC");
$vStmt->execute([$client_id]);
$vehicles = $vStmt->fetchAll();

// ── Cart count for badge ──
$cartStmt = $pdo->prepare("SELECT COUNT(*) FROM cart WHERE client_id = ?");
$cartStmt->execute([$client_id]);
$cartCount = $cartStmt->fetchColumn();

// ── Active tab ──
$activeTab = 'profile';
if (isset($_POST['action'])) {
    if ($_POST['action'] === 'change_password') $activeTab = 'password';
    if (in_array($_POST['action'], ['add_vehicle', 'edit_vehicle', 'delete_vehicle'])) $activeTab = 'vehicles';
}
if (isset($_GET['tab']) && in_array($_GET['tab'], ['profile', 'password', 'vehicles'])) {
    $activeTab = $_GET['tab'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - VehiCare</title>
    <link rel="stylesheet" href="../includes/style/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700;900&family=Oswald:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ── Profile Page Styles ── */
        .profile-section {
            padding: 60px 0;
            min-height: 60vh;
            background: #f8f9fa;
        }
        .profile-section .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }
        .profile-page-title {
            font-family: 'Oswald', sans-serif;
            font-size: 32px;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .profile-page-title i { color: #e74c3c; }

        .profile-layout {
            display: grid;
            grid-template-columns: 340px 1fr;
            gap: 30px;
            align-items: start;
        }

        /* ── Profile Card (Left) ── */
        .profile-card {
            background: #fff;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        .profile-card-header {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
            padding: 40px 30px 50px;
            text-align: center;
            position: relative;
        }
        .profile-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            border: 4px solid rgba(255,255,255,0.4);
        }
        .profile-avatar i {
            font-size: 48px;
            color: #fff;
        }
        .profile-card-header h3 {
            color: #fff;
            font-family: 'Oswald', sans-serif;
            font-size: 22px;
            font-weight: 600;
            margin: 0 0 4px;
        }
        .profile-card-header p {
            color: rgba(255,255,255,0.85);
            font-size: 14px;
            margin: 0;
        }
        .profile-card-body {
            padding: 25px 30px;
        }
        .profile-info-row {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .profile-info-row:last-child { border-bottom: none; }
        .profile-info-row i {
            color: #e74c3c;
            width: 20px;
            text-align: center;
            margin-top: 2px;
            flex-shrink: 0;
        }
        .profile-info-row .info-content {
            flex: 1;
        }
        .profile-info-row .info-label {
            font-size: 11px;
            color: #999;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
        .profile-info-row .info-value {
            font-size: 14px;
            color: #2c3e50;
            font-weight: 500;
        }

        .profile-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0;
            border-top: 1px solid #f0f0f0;
        }
        .stat-item {
            text-align: center;
            padding: 20px 10px;
            border-right: 1px solid #f0f0f0;
        }
        .stat-item:last-child { border-right: none; }
        .stat-item .stat-number {
            font-family: 'Oswald', sans-serif;
            font-size: 26px;
            font-weight: 700;
            color: #e74c3c;
            display: block;
        }
        .stat-item .stat-label {
            font-size: 11px;
            color: #999;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }

        /* ── Tabs (Right) ── */
        .profile-tabs-wrapper {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        .profile-tabs {
            display: flex;
            border-bottom: 2px solid #f0f0f0;
            background: #fafafa;
        }
        .profile-tabs .tab-btn {
            flex: 1;
            padding: 16px 20px;
            text-align: center;
            font-size: 14px;
            font-weight: 600;
            color: #999;
            background: transparent;
            border: none;
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-family: 'Roboto', sans-serif;
        }
        .profile-tabs .tab-btn:hover { color: #2c3e50; background: #f5f5f5; }
        .profile-tabs .tab-btn.active {
            color: #e74c3c;
            background: #fff;
        }
        .profile-tabs .tab-btn.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 3px;
            background: #e74c3c;
            border-radius: 3px 3px 0 0;
        }
        .tab-content {
            display: none;
            padding: 30px;
        }
        .tab-content.active { display: block; }
        .tab-content h3 {
            font-family: 'Oswald', sans-serif;
            font-size: 20px;
            color: #2c3e50;
            margin: 0 0 20px;
        }

        /* ── Forms ── */
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e8e8e8;
            border-radius: 10px;
            font-size: 14px;
            font-family: 'Roboto', sans-serif;
            color: #2c3e50;
            transition: border-color 0.3s;
            background: #fafafa;
            box-sizing: border-box;
        }
        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: #e74c3c;
            background: #fff;
        }
        .form-group textarea { resize: vertical; min-height: 80px; }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            color: #fff;
            border: none;
            padding: 13px 30px;
            font-size: 14px;
            font-weight: 600;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-family: 'Roboto', sans-serif;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(231,76,60,0.35);
        }
        .btn-secondary {
            background: #2c3e50;
            color: #fff;
            border: none;
            padding: 10px 20px;
            font-size: 13px;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-family: 'Roboto', sans-serif;
        }
        .btn-secondary:hover {
            background: #34495e;
            transform: translateY(-1px);
        }
        .btn-danger {
            background: transparent;
            color: #e74c3c;
            border: 2px solid #e74c3c;
            padding: 8px 16px;
            font-size: 13px;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-family: 'Roboto', sans-serif;
        }
        .btn-danger:hover {
            background: #e74c3c;
            color: #fff;
        }
        .btn-sm {
            padding: 8px 14px;
            font-size: 12px;
            border-radius: 6px;
        }

        /* ── Alert Messages ── */
        .alert {
            padding: 14px 20px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* ── Vehicles Table ── */
        .vehicles-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .vehicle-table {
            width: 100%;
            border-collapse: collapse;
        }
        .vehicle-table thead th {
            background: #2c3e50;
            color: #fff;
            padding: 12px 14px;
            text-align: left;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .vehicle-table thead th:first-child { border-radius: 8px 0 0 0; }
        .vehicle-table thead th:last-child { border-radius: 0 8px 0 0; }
        .vehicle-table tbody td {
            padding: 12px 14px;
            font-size: 14px;
            color: #2c3e50;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
        }
        .vehicle-table tbody tr:hover { background: #fafafa; }
        .vehicle-actions {
            display: flex;
            gap: 6px;
        }
        .vehicle-status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .vehicle-status.active { background: #d4edda; color: #155724; }
        .vehicle-status.in_service { background: #fff3cd; color: #856404; }
        .vehicle-status.completed { background: #cce5ff; color: #004085; }
        .vehicle-status.released { background: #e2e3e5; color: #383d41; }

        /* ── Inline Vehicle Form ── */
        .vehicle-form-wrapper {
            background: #f8f9fa;
            border: 2px dashed #ddd;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
            display: none;
        }
        .vehicle-form-wrapper.show { display: block; }
        .vehicle-form-wrapper h4 {
            font-family: 'Oswald', sans-serif;
            font-size: 18px;
            color: #2c3e50;
            margin: 0 0 18px;
        }
        .vehicle-form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 16px;
        }

        /* ── Edit Row ── */
        .edit-row { display: none; }
        .edit-row.show { display: table-row; }
        .edit-row td {
            background: #f8f9fa;
            padding: 20px 14px !important;
        }
        .edit-form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 12px;
        }
        .edit-form-grid .form-group { margin-bottom: 0; }
        .edit-form-actions {
            display: flex;
            gap: 8px;
            margin-top: 12px;
        }

        /* ── No vehicles ── */
        .no-vehicles {
            text-align: center;
            padding: 50px 20px;
            color: #999;
        }
        .no-vehicles i {
            font-size: 50px;
            color: #ddd;
            margin-bottom: 15px;
            display: block;
        }
        .no-vehicles h4 {
            font-family: 'Oswald', sans-serif;
            font-size: 20px;
            color: #bbb;
            margin: 0 0 8px;
        }
        .no-vehicles p {
            font-size: 14px;
            margin: 0;
        }

        /* ── Footer ── */
        .profile-footer {
            background: #2c3e50;
            color: rgba(255,255,255,0.6);
            text-align: center;
            padding: 25px 20px;
            font-size: 14px;
        }
        .profile-footer a { color: #e74c3c; text-decoration: none; }

        /* ── Responsive ── */
        @media (max-width: 900px) {
            .profile-layout {
                grid-template-columns: 1fr;
            }
            .profile-tabs .tab-btn {
                padding: 14px 10px;
                font-size: 13px;
            }
            .vehicle-form-grid,
            .edit-form-grid {
                grid-template-columns: 1fr 1fr;
            }
            .form-row {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 600px) {
            .profile-page-title { font-size: 24px; }
            .vehicle-table thead { display: none; }
            .vehicle-table tbody tr {
                display: block;
                margin-bottom: 12px;
                background: #fff;
                border-radius: 10px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.06);
                padding: 10px;
            }
            .vehicle-table tbody td {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 8px 10px;
                border-bottom: 1px solid #f5f5f5;
            }
            .vehicle-table tbody td::before {
                content: attr(data-label);
                font-weight: 600;
                font-size: 12px;
                color: #888;
                text-transform: uppercase;
            }
            .vehicle-table tbody td:last-child { border-bottom: none; }
            .vehicle-form-grid,
            .edit-form-grid {
                grid-template-columns: 1fr;
            }
            .profile-tabs .tab-btn span.tab-text { display: none; }
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
            <span><i class="fas fa-user"></i> <?= htmlspecialchars($full_name) ?></span>
            <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
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
                    <li><a href="../index.php#shop"><i class="fas fa-store"></i> Shop</a></li>
                    <li><a href="../index.php#services"><i class="fas fa-wrench"></i> Services</a></li>
                    <li><a href="../index.php#about"><i class="fas fa-info-circle"></i> About</a></li>
                    <li><a href="../index.php#contact"><i class="fas fa-envelope"></i> Contact</a></li>
                </ul>
            </nav>
            <div class="header-actions">
                <a href="cart.php" class="header-icon" title="Cart">
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

<!-- ========== PROFILE SECTION ========== -->
<section class="profile-section">
    <div class="container">
        <h1 class="profile-page-title"><i class="fas fa-user-circle"></i> My Profile</h1>

        <?php if ($success_msg): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_msg) ?></div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_msg) ?></div>
        <?php endif; ?>

        <div class="profile-layout">
            <!-- ── LEFT: Profile Card ── -->
            <div class="profile-card">
                <div class="profile-card-header">
                    <div class="profile-avatar">
                        <i class="fas fa-user"></i>
                    </div>
                    <h3><?= htmlspecialchars($profile['full_name']) ?></h3>
                    <p>Customer Account</p>
                </div>
                <div class="profile-card-body">
                    <div class="profile-info-row">
                        <i class="fas fa-envelope"></i>
                        <div class="info-content">
                            <div class="info-label">Email</div>
                            <div class="info-value"><?= htmlspecialchars($profile['email']) ?></div>
                        </div>
                    </div>
                    <div class="profile-info-row">
                        <i class="fas fa-phone"></i>
                        <div class="info-content">
                            <div class="info-label">Phone</div>
                            <div class="info-value"><?= htmlspecialchars($profile['phone'] ?: 'Not set') ?></div>
                        </div>
                    </div>
                    <div class="profile-info-row">
                        <i class="fas fa-map-marker-alt"></i>
                        <div class="info-content">
                            <div class="info-label">Address</div>
                            <div class="info-value"><?= htmlspecialchars($profile['address'] ?: 'Not set') ?></div>
                        </div>
                    </div>
                    <div class="profile-info-row">
                        <i class="fas fa-calendar-alt"></i>
                        <div class="info-content">
                            <div class="info-label">Member Since</div>
                            <div class="info-value"><?= date('F j, Y', strtotime($profile['created_at'])) ?></div>
                        </div>
                    </div>
                </div>
                <div class="profile-stats">
                    <div class="stat-item">
                        <span class="stat-number"><?= $totalOrders ?></span>
                        <span class="stat-label">Orders</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-number"><?= $totalVehicles ?></span>
                        <span class="stat-label">Vehicles</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-number"><?= $totalAppointments ?></span>
                        <span class="stat-label">Appts</span>
                    </div>
                </div>
            </div>

            <!-- ── RIGHT: Tabbed Sections ── -->
            <div class="profile-tabs-wrapper">
                <div class="profile-tabs">
                    <button class="tab-btn <?= $activeTab === 'profile' ? 'active' : '' ?>" onclick="switchTab('profile')">
                        <i class="fas fa-user-edit"></i> <span class="tab-text">Profile Info</span>
                    </button>
                    <button class="tab-btn <?= $activeTab === 'password' ? 'active' : '' ?>" onclick="switchTab('password')">
                        <i class="fas fa-lock"></i> <span class="tab-text">Change Password</span>
                    </button>
                    <button class="tab-btn <?= $activeTab === 'vehicles' ? 'active' : '' ?>" onclick="switchTab('vehicles')">
                        <i class="fas fa-car"></i> <span class="tab-text">My Vehicles</span>
                    </button>
                </div>

                <!-- Tab 1: Profile Info -->
                <div class="tab-content <?= $activeTab === 'profile' ? 'active' : '' ?>" id="tab-profile">
                    <h3><i class="fas fa-user-edit"></i> Edit Profile Information</h3>
                    <form method="POST" action="profile.php">
                        <input type="hidden" name="action" value="update_profile">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="full_name">Full Name</label>
                                <input type="text" id="full_name" name="full_name" value="<?= htmlspecialchars($profile['full_name']) ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="email">Email Address</label>
                                <input type="email" id="email" name="email" value="<?= htmlspecialchars($profile['email']) ?>" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="phone">Phone Number</label>
                                <input type="text" id="phone" name="phone" value="<?= htmlspecialchars($profile['phone'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label for="address">Address</label>
                                <input type="text" id="address" name="address" value="<?= htmlspecialchars($profile['address'] ?? '') ?>">
                            </div>
                        </div>
                        <button type="submit" class="btn-primary">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                    </form>
                </div>

                <!-- Tab 2: Change Password -->
                <div class="tab-content <?= $activeTab === 'password' ? 'active' : '' ?>" id="tab-password">
                    <h3><i class="fas fa-lock"></i> Change Password</h3>
                    <form method="POST" action="profile.php">
                        <input type="hidden" name="action" value="change_password">
                        <div class="form-group">
                            <label for="current_password">Current Password</label>
                            <input type="password" id="current_password" name="current_password" required>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="new_password">New Password</label>
                                <input type="password" id="new_password" name="new_password" required minlength="6">
                            </div>
                            <div class="form-group">
                                <label for="confirm_password">Confirm New Password</label>
                                <input type="password" id="confirm_password" name="confirm_password" required minlength="6">
                            </div>
                        </div>
                        <button type="submit" class="btn-primary">
                            <i class="fas fa-key"></i> Update Password
                        </button>
                    </form>
                </div>

                <!-- Tab 3: My Vehicles -->
                <div class="tab-content <?= $activeTab === 'vehicles' ? 'active' : '' ?>" id="tab-vehicles">
                    <div class="vehicles-header">
                        <h3><i class="fas fa-car"></i> My Vehicles</h3>
                        <button class="btn-secondary" onclick="toggleAddVehicle()">
                            <i class="fas fa-plus"></i> Add Vehicle
                        </button>
                    </div>

                    <!-- Add Vehicle Inline Form -->
                    <div class="vehicle-form-wrapper" id="addVehicleForm">
                        <h4><i class="fas fa-plus-circle"></i> Add New Vehicle</h4>
                        <form method="POST" action="profile.php">
                            <input type="hidden" name="action" value="add_vehicle">
                            <div class="vehicle-form-grid">
                                <div class="form-group">
                                    <label>Plate Number *</label>
                                    <input type="text" name="plate_number" placeholder="e.g. ABC 1234" required>
                                </div>
                                <div class="form-group">
                                    <label>VIN</label>
                                    <input type="text" name="vin" placeholder="Vehicle Identification Number" maxlength="17">
                                </div>
                                <div class="form-group">
                                    <label>Make *</label>
                                    <input type="text" name="make" placeholder="e.g. Toyota" required>
                                </div>
                                <div class="form-group">
                                    <label>Model *</label>
                                    <input type="text" name="model" placeholder="e.g. Vios" required>
                                </div>
                                <div class="form-group">
                                    <label>Year</label>
                                    <input type="number" name="year" placeholder="e.g. 2024" min="1900" max="2099">
                                </div>
                                <div class="form-group">
                                    <label>Color</label>
                                    <input type="text" name="color" placeholder="e.g. Silver">
                                </div>
                            </div>
                            <div style="margin-top: 15px; display: flex; gap: 10px;">
                                <button type="submit" class="btn-primary btn-sm">
                                    <i class="fas fa-save"></i> Save Vehicle
                                </button>
                                <button type="button" class="btn-danger btn-sm" onclick="toggleAddVehicle()">
                                    <i class="fas fa-times"></i> Cancel
                                </button>
                            </div>
                        </form>
                    </div>

                    <?php if (empty($vehicles)): ?>
                        <div class="no-vehicles">
                            <i class="fas fa-car-side"></i>
                            <h4>No Vehicles Yet</h4>
                            <p>Click "Add Vehicle" to register your first vehicle.</p>
                        </div>
                    <?php else: ?>
                        <table class="vehicle-table">
                            <thead>
                                <tr>
                                    <th>Plate</th>
                                    <th>Make</th>
                                    <th>Model</th>
                                    <th>Year</th>
                                    <th>Color</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($vehicles as $v): ?>
                                <tr id="row-<?= $v['vehicle_id'] ?>">
                                    <td data-label="Plate"><?= htmlspecialchars($v['plate_number']) ?></td>
                                    <td data-label="Make"><?= htmlspecialchars($v['make'] ?? '') ?></td>
                                    <td data-label="Model"><?= htmlspecialchars($v['model'] ?? '') ?></td>
                                    <td data-label="Year"><?= htmlspecialchars($v['year'] ?? '—') ?></td>
                                    <td data-label="Color"><?= htmlspecialchars($v['color'] ?? '—') ?></td>
                                    <td data-label="Status">
                                        <span class="vehicle-status <?= htmlspecialchars($v['status']) ?>">
                                            <?= htmlspecialchars(str_replace('_', ' ', $v['status'])) ?>
                                        </span>
                                    </td>
                                    <td data-label="Actions">
                                        <div class="vehicle-actions">
                                            <button class="btn-secondary btn-sm" onclick="toggleEditVehicle(<?= $v['vehicle_id'] ?>)" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <form method="POST" action="profile.php" style="display:inline;" onsubmit="return confirm('Delete this vehicle?');">
                                                <input type="hidden" name="action" value="delete_vehicle">
                                                <input type="hidden" name="vehicle_id" value="<?= $v['vehicle_id'] ?>">
                                                <button type="submit" class="btn-danger btn-sm" title="Delete">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <!-- Edit Row -->
                                <tr class="edit-row" id="edit-<?= $v['vehicle_id'] ?>">
                                    <td colspan="7">
                                        <form method="POST" action="profile.php">
                                            <input type="hidden" name="action" value="edit_vehicle">
                                            <input type="hidden" name="vehicle_id" value="<?= $v['vehicle_id'] ?>">
                                            <div class="edit-form-grid">
                                                <div class="form-group">
                                                    <label>Plate Number *</label>
                                                    <input type="text" name="plate_number" value="<?= htmlspecialchars($v['plate_number']) ?>" required>
                                                </div>
                                                <div class="form-group">
                                                    <label>VIN</label>
                                                    <input type="text" name="vin" value="<?= htmlspecialchars($v['vin'] ?? '') ?>" maxlength="17">
                                                </div>
                                                <div class="form-group">
                                                    <label>Make *</label>
                                                    <input type="text" name="make" value="<?= htmlspecialchars($v['make'] ?? '') ?>" required>
                                                </div>
                                                <div class="form-group">
                                                    <label>Model *</label>
                                                    <input type="text" name="model" value="<?= htmlspecialchars($v['model'] ?? '') ?>" required>
                                                </div>
                                                <div class="form-group">
                                                    <label>Year</label>
                                                    <input type="number" name="year" value="<?= htmlspecialchars($v['year'] ?? '') ?>" min="1900" max="2099">
                                                </div>
                                                <div class="form-group">
                                                    <label>Color</label>
                                                    <input type="text" name="color" value="<?= htmlspecialchars($v['color'] ?? '') ?>">
                                                </div>
                                            </div>
                                            <div class="edit-form-actions">
                                                <button type="submit" class="btn-primary btn-sm">
                                                    <i class="fas fa-save"></i> Update
                                                </button>
                                                <button type="button" class="btn-danger btn-sm" onclick="toggleEditVehicle(<?= $v['vehicle_id'] ?>)">
                                                    <i class="fas fa-times"></i> Cancel
                                                </button>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ========== FOOTER ========== -->
<footer class="profile-footer">
    <div class="container">
        <p>&copy; 2026 <a href="../index.php">VehiCare</a>. All rights reserved. | Designed for Vehicle Service DB</p>
    </div>
</footer>

<!-- ========== JAVASCRIPT ========== -->
<script>
// Tab Switching
function switchTab(tab) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
    document.getElementById('tab-' + tab).classList.add('active');
    event.currentTarget.classList.add('active');
}

// Toggle Add Vehicle Form
function toggleAddVehicle() {
    document.getElementById('addVehicleForm').classList.toggle('show');
}

// Toggle Edit Vehicle Row
function toggleEditVehicle(id) {
    const row = document.getElementById('edit-' + id);
    row.classList.toggle('show');
}

// Mobile Nav Toggle
const mobileToggle = document.getElementById('mobileToggle');
if (mobileToggle) {
    mobileToggle.addEventListener('click', function () {
        document.querySelector('.main-nav').classList.toggle('active');
        this.querySelector('i').classList.toggle('fa-bars');
        this.querySelector('i').classList.toggle('fa-times');
    });
}

// Sticky Header
window.addEventListener('scroll', function () {
    const header = document.querySelector('.main-header');
    if (header) header.classList.toggle('sticky', window.scrollY > 100);
});
</script>

</body>
</html>
