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

// Handle AJAX add-to-cart
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    if (!isset($_SESSION['client_id'])) {
        echo json_encode(['success' => false, 'message' => 'Please login to add items to cart.', 'redirect' => 'users/login.php']);
        exit;
    }
    $clientId = $_SESSION['client_id'];

    if ($_POST['ajax_action'] === 'add_to_cart') {
        $itemId = isset($_POST['item_id']) ? (int)$_POST['item_id'] : null;
        $serviceId = isset($_POST['service_id']) ? (int)$_POST['service_id'] : null;
        $qty = max(1, (int)($_POST['quantity'] ?? 1));

        if ($itemId) {
            // Check stock
            $st = $pdo->prepare("SELECT quantity FROM inventory WHERE item_id = ?");
            $st->execute([$itemId]);
            $stock = $st->fetchColumn();
            if ($stock === false || $stock < $qty) {
                echo json_encode(['success' => false, 'message' => 'Not enough stock available.']);
                exit;
            }
            // Check if already in cart
            $ch = $pdo->prepare("SELECT cart_id, quantity FROM cart WHERE client_id = ? AND item_id = ?");
            $ch->execute([$clientId, $itemId]);
            $existing = $ch->fetch();
            if ($existing) {
                $newQty = $existing['quantity'] + $qty;
                if ($newQty > $stock) {
                    echo json_encode(['success' => false, 'message' => 'Cannot add more. Stock limit reached.']);
                    exit;
                }
                $pdo->prepare("UPDATE cart SET quantity = ? WHERE cart_id = ?")->execute([$newQty, $existing['cart_id']]);
            } else {
                $pdo->prepare("INSERT INTO cart (client_id, item_id, quantity) VALUES (?, ?, ?)")->execute([$clientId, $itemId, $qty]);
            }
        } elseif ($serviceId) {
            $ch = $pdo->prepare("SELECT cart_id FROM cart WHERE client_id = ? AND service_id = ?");
            $ch->execute([$clientId, $serviceId]);
            if (!$ch->fetch()) {
                $pdo->prepare("INSERT INTO cart (client_id, service_id, quantity) VALUES (?, ?, 1)")->execute([$clientId, $serviceId]);
            }
        }
        // Get updated count
        $cStmt = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM cart WHERE client_id = ?");
        $cStmt->execute([$clientId]);
        $newCount = (int)$cStmt->fetchColumn();
        echo json_encode(['success' => true, 'message' => 'Added to cart!', 'cartCount' => $newCount]);
        exit;
    }

    // Handle AJAX get_vehicles
    if ($_POST['ajax_action'] === 'get_vehicles') {
        $vStmt = $pdo->prepare("SELECT vehicle_id, plate_number, make, model, year FROM vehicles WHERE client_id = ? AND status = 'active' ORDER BY make, model");
        $vStmt->execute([$clientId]);
        echo json_encode(['success' => true, 'vehicles' => $vStmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // Handle AJAX check_timeslot — returns booked slots for a given date
    if ($_POST['ajax_action'] === 'check_timeslot') {
        $date = trim($_POST['date'] ?? '');
        if (!$date) { echo json_encode(['success' => false]); exit; }
        $dayStart = date('Y-m-d 00:00:00', strtotime($date));
        $dayEnd   = date('Y-m-d 23:59:59', strtotime($date));
        $slots = $pdo->prepare("
            SELECT ap.appointment_date, COALESCE(s.estimated_duration, 60) AS duration
            FROM appointments ap
            LEFT JOIN services s ON ap.service_id = s.service_id
            WHERE ap.appointment_date BETWEEN ? AND ?
              AND ap.status IN ('Pending','Approved')
            ORDER BY ap.appointment_date
        ");
        $slots->execute([$dayStart, $dayEnd]);
        $booked = [];
        foreach ($slots->fetchAll() as $sl) {
            $start = strtotime($sl['appointment_date']);
            $end   = $start + ($sl['duration'] * 60);
            $booked[] = ['start' => date('H:i', $start), 'end' => date('H:i', $end)];
        }
        echo json_encode(['success' => true, 'booked' => $booked]);
        exit;
    }

    // Handle AJAX book_appointment
    if ($_POST['ajax_action'] === 'book_appointment') {
        $vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
        $service_id = (int)($_POST['service_id'] ?? 0);
        $appointment_date = trim($_POST['appointment_date'] ?? '');
        if (!$vehicle_id || !$service_id || !$appointment_date) {
            echo json_encode(['success' => false, 'message' => 'Please select a service, vehicle, and appointment date/time.']);
            exit;
        }
        $chk = $pdo->prepare("SELECT vehicle_id FROM vehicles WHERE vehicle_id = ? AND client_id = ?");
        $chk->execute([$vehicle_id, $clientId]);
        if (!$chk->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Invalid vehicle selected.']);
            exit;
        }

        // Get service duration for time-slot conflict check
        $durStmt = $pdo->prepare("SELECT COALESCE(estimated_duration, 60) FROM services WHERE service_id = ?");
        $durStmt->execute([$service_id]);
        $svcDuration = (int)$durStmt->fetchColumn() ?: 60;

        $reqStart = strtotime($appointment_date);
        $reqEnd   = $reqStart + ($svcDuration * 60);

        // Check for overlapping appointments on the same date
        $overlapStmt = $pdo->prepare("
            SELECT ap.appointment_id, ap.appointment_date, COALESCE(s.estimated_duration, 60) AS duration
            FROM appointments ap
            LEFT JOIN services s ON ap.service_id = s.service_id
            WHERE DATE(ap.appointment_date) = DATE(?)
              AND ap.status IN ('Pending','Approved')
        ");
        $overlapStmt->execute([$appointment_date]);
        foreach ($overlapStmt->fetchAll() as $existing) {
            $exStart = strtotime($existing['appointment_date']);
            $exEnd   = $exStart + ($existing['duration'] * 60);
            if ($reqStart < $exEnd && $reqEnd > $exStart) {
                $suggestTime = date('h:i A', $exEnd);
                echo json_encode(['success' => false, 'message' => 'This time slot is already booked. The earliest available time after this slot is ' . $suggestTime . '. Please choose a different time.']);
                exit;
            }
        }

        try {
            $pdo->beginTransaction();

            // Find least busy available technician (with NO ongoing assignments)
            $tech_stmt = $pdo->query("
                SELECT u.user_id, u.full_name,
                       COUNT(a.assignment_id) AS active_count,
                       SUM(CASE WHEN a.status = 'Ongoing' THEN 1 ELSE 0 END) AS ongoing_count
                FROM users u
                LEFT JOIN assignments a ON u.user_id = a.technician_id AND a.status IN ('Assigned', 'Ongoing')
                WHERE u.role = 'technician' AND u.status = 'active'
                GROUP BY u.user_id
                ORDER BY ongoing_count ASC, active_count ASC
            ");
            $all_techs = $tech_stmt->fetchAll();

            // Find a tech with zero ongoing assignments
            $free_tech = null;
            $least_busy_tech = null;
            foreach ($all_techs as $t) {
                if (!$least_busy_tech) $least_busy_tech = $t;
                if ((int)$t['ongoing_count'] === 0) {
                    $free_tech = $t;
                    break;
                }
            }

            $svc = $pdo->prepare("SELECT service_name FROM services WHERE service_id = ?");
            $svc->execute([$service_id]);
            $svc_name = $svc->fetchColumn() ?: 'Vehicle Service';

            if ($free_tech) {
                // Technician available — auto-assign
                $stmt = $pdo->prepare("INSERT INTO appointments (client_id, vehicle_id, service_id, appointment_date, status, created_by) VALUES (?, ?, ?, ?, 'Approved', ?)");
                $stmt->execute([$clientId, $vehicle_id, $service_id, $appointment_date, $_SESSION['user_id']]);
                $appointment_id = $pdo->lastInsertId();

                $stmt = $pdo->prepare("INSERT INTO assignments (appointment_id, vehicle_id, technician_id, service_id, status) VALUES (?, ?, ?, ?, 'Assigned')");
                $stmt->execute([$appointment_id, $vehicle_id, $free_tech['user_id'], $service_id]);

                $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'New Service Assignment', ?, 'new_assignment')")
                    ->execute([$free_tech['user_id'], 'You have been assigned: ' . $svc_name . '. Scheduled for ' . date('M d, Y h:i A', strtotime($appointment_date)) . '.']);

                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Appointment booked and assigned to technician ' . $free_tech['full_name'] . '!']);
            } else {
                // All technicians have ongoing work — queue with assigned tech info
                $assigned_tech = $least_busy_tech;
                $stmt = $pdo->prepare("INSERT INTO appointments (client_id, vehicle_id, service_id, appointment_date, status, created_by) VALUES (?, ?, ?, ?, 'Pending', ?)");
                $stmt->execute([$clientId, $vehicle_id, $service_id, $appointment_date, $_SESSION['user_id']]);
                $appointment_id = $pdo->lastInsertId();

                $next_pos = (int) $pdo->query("SELECT COALESCE(MAX(position), 0) + 1 FROM queue WHERE status IN ('Waiting','Serving')")->fetchColumn();
                $pdo->prepare("INSERT INTO queue (vehicle_id, client_id, position, status) VALUES (?, ?, ?, 'Waiting')")
                    ->execute([$vehicle_id, $clientId, $next_pos]);

                $tech_name = $assigned_tech ? $assigned_tech['full_name'] : 'a technician';
                $queueMsg = 'All technicians are currently busy with ongoing services. You have been placed in queue position #' . $next_pos . '. Your assigned technician will be ' . $tech_name . '. We will notify you once they are available.';

                $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'Appointment Queued', ?, 'queue')")
                    ->execute([$_SESSION['user_id'], $queueMsg]);

                $pdo->commit();
                echo json_encode(['success' => false, 'message' => $queueMsg]);
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Booking failed: ' . $e->getMessage()]);
        }
        exit;
    }

    // Handle AJAX add_vehicle
    if ($_POST['ajax_action'] === 'add_vehicle') {
        $plate = strtoupper(trim($_POST['plate_number'] ?? ''));
        $make = trim($_POST['make'] ?? '');
        $model = trim($_POST['model'] ?? '');
        $year = (int)($_POST['year'] ?? date('Y'));
        $color = trim($_POST['color'] ?? '');
        if (!$plate || !$make || !$model) {
            echo json_encode(['success' => false, 'message' => 'Plate number, make, and model are required.']);
            exit;
        }
        $stmt = $pdo->prepare("INSERT INTO vehicles (client_id, plate_number, make, model, year, color, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
        $stmt->execute([$clientId, $plate, $make, $model, $year, $color]);
        $newId = $pdo->lastInsertId();
        echo json_encode(['success' => true, 'message' => 'Vehicle added successfully!', 'vehicle' => ['vehicle_id' => $newId, 'plate_number' => $plate, 'make' => $make, 'model' => $model, 'year' => $year]]);
        exit;
    }
}

// Fetch inventory items for dynamic product display
$allProducts = $pdo->query("SELECT * FROM inventory WHERE quantity > 0 ORDER BY item_id ASC")->fetchAll();

// Split into sections: Featured (first 8), New Arrivals (items 10-13), Replacement (items 14-17), Deal (item 9)
$featuredProducts = array_slice($allProducts, 0, 8);
$dealProduct = null;
$newArrivals = [];
$replacementParts = [];
foreach ($allProducts as $p) {
    if ($p['item_id'] == 9) $dealProduct = $p;
    if (in_array($p['item_id'], [10, 11, 12, 13])) $newArrivals[] = $p;
    if (in_array($p['item_id'], [14, 15, 16, 17])) $replacementParts[] = $p;
}

// Get distinct categories for category cards
$categories = $pdo->query("SELECT category, COUNT(*) as cnt FROM inventory WHERE quantity > 0 AND category IS NOT NULL GROUP BY category ORDER BY cnt DESC")->fetchAll();

// Fetch services from DB
$svcStmt = $pdo->query("SELECT * FROM services ORDER BY service_name ASC");
$dbServices = $svcStmt->fetchAll();

// Category icon mapping
$catIcons = [
    'Brake Parts' => 'fa-compact-disc', 'Engine Parts' => 'fa-cogs', 'Wheels & Tires' => 'fa-circle-notch',
    'Lighting' => 'fa-lightbulb', 'Fluids & Oils' => 'fa-oil-can', 'Accessories' => 'fa-car-battery',
    'Body Parts' => 'fa-car-side', 'Electronics' => 'fa-microchip', 'Suspension' => 'fa-car-alt',
    'Cooling System' => 'fa-thermometer-half', 'Ignition' => 'fa-plug',
];

// Logged-in state for JS
$isLoggedIn = isset($_SESSION['client_id']) ? 'true' : 'false';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VehiCare - Car Services & Parts Shop</title>
    <link rel="stylesheet" href="includes/style/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700;900&family=Oswald:wght@400;500;600;700&display=swap" rel="stylesheet">
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
                <?php if ($_SESSION['role'] === 'admin'): ?>
                    <a href="admins/dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <?php endif; ?>
                <?php if ($_SESSION['role'] === 'customer'): ?>
                    <a href="users/profile.php"><i class="fas fa-user-circle"></i> My Profile</a>
                    <a href="users/orders.php"><i class="fas fa-box"></i> My Orders</a>
                <?php endif; ?>
                <a href="users/logout.php"><i class="fas fa-sign-out-alt"></i> Logout (<?= htmlspecialchars($_SESSION['full_name']) ?>)</a>
            <?php else: ?>
                <a href="users/login.php"><i class="fas fa-user"></i> Login</a>
                <a href="users/register.php"><i class="fas fa-user-plus"></i> Register</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ========== HEADER / NAVBAR ========== -->
<header class="main-header">
    <div class="container">
        <div class="header-inner">
            <!-- Logo -->
            <a href="index.php" class="logo">
                <span class="logo-vehi">Vehi</span><span class="logo-care">Care</span>
            </a>

            <!-- Navigation -->
            <nav class="main-nav">
                <ul>
                    <li><a href="index.php" class="active"><i class="fas fa-home"></i> Home</a></li>
                    <li class="has-dropdown">
                        <a href="#services"><i class="fas fa-wrench"></i> Services <i class="fas fa-chevron-down"></i></a>
                        <ul class="dropdown">
                            <?php
                            $navSvcIcons = ['fa-oil-can','fa-cogs','fa-compact-disc','fa-circle-notch','fa-car-battery','fa-spray-can','fa-wrench','fa-tools'];
                            foreach ($dbServices as $ni => $ns):
                                $navIcon = $navSvcIcons[$ni % count($navSvcIcons)];
                            ?>
                            <li><a href="javascript:void(0)" onclick="bookService(<?= (int)$ns['service_id'] ?>, '<?= htmlspecialchars($ns['service_name'], ENT_QUOTES) ?>')"><i class="fas <?= $navIcon ?>"></i> <?= htmlspecialchars($ns['service_name']) ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                    <li class="has-dropdown">
                        <a href="#shop"><i class="fas fa-store"></i> Shop <i class="fas fa-chevron-down"></i></a>
                        <ul class="dropdown">
                            <?php foreach ($categories as $navCat):
                                $navCatIcon = $catIcons[$navCat['category']] ?? 'fa-box';
                            ?>
                            <li><a href="javascript:void(0)" onclick="browseCategory('<?= htmlspecialchars($navCat['category'], ENT_QUOTES) ?>')"><i class="fas <?= $navCatIcon ?>"></i> <?= htmlspecialchars($navCat['category']) ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                    <li><a href="#about"><i class="fas fa-info-circle"></i> About</a></li>
                    <li><a href="#contact"><i class="fas fa-envelope"></i> Contact</a></li>
                    <?php if (isset($_SESSION['user_id']) && $_SESSION['role'] === 'customer'): ?>
                    <li><a href="users/dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <?php endif; ?>
                </ul>
            </nav>

            <!-- Header Right -->
            <div class="header-actions">
                <div class="search-box">
                    <input type="text" placeholder="Search products..." id="productSearch">
                    <button><i class="fas fa-search"></i></button>
                </div>
                <?php if (isset($_SESSION['user_id']) && $_SESSION['role'] === 'customer'): ?>
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
                    <span class="badge" id="cartBadge"><?= $cartCount ?></span>
                </a>
            </div>

            <!-- Mobile Toggle -->
            <button class="mobile-toggle" id="mobileToggle">
                <i class="fas fa-bars"></i>
            </button>
        </div>
    </div>
</header>

<!-- ========== HERO SLIDER ========== -->
<section class="hero-section">
    <div class="hero-slider" id="heroSlider">
        <!-- Slide 1 -->
        <div class="hero-slide active" style="background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);">
            <div class="container">
                <div class="hero-content">
                    <div class="hero-text">
                        <span class="hero-subtitle">Wide Selection of Auto Parts</span>
                        <h1>AT THE LOWEST<br><span>PRICES!</span></h1>
                        <p>Premium quality car parts and professional vehicle services at unbeatable prices. Your one-stop auto shop.</p>
                        <a href="#shop" class="btn btn-primary">SHOP NOW <i class="fas fa-arrow-right"></i></a>
                    </div>
                    <div class="hero-image">
                        <div class="hero-car-placeholder">
                            <i class="fas fa-car" style="font-size: 180px; color: rgba(255,255,255,0.1);"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- Slide 2 -->
        <div class="hero-slide" style="background: linear-gradient(135deg, #c0392b 0%, #e74c3c 50%, #c0392b 100%);">
            <div class="container">
                <div class="hero-content">
                    <div class="hero-text">
                        <span class="hero-subtitle">Professional Car Services</span>
                        <h1>EXPERT AUTO<br><span>REPAIR!</span></h1>
                        <p>Certified mechanics, state-of-the-art equipment, and guaranteed workmanship for all makes and models.</p>
                        <a href="#services" class="btn btn-white">VIEW SERVICES <i class="fas fa-arrow-right"></i></a>
                    </div>
                    <div class="hero-image">
                        <div class="hero-car-placeholder">
                            <i class="fas fa-tools" style="font-size: 180px; color: rgba(255,255,255,0.15);"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- Slide 3 -->
        <div class="hero-slide" style="background: linear-gradient(135deg, #2c3e50 0%, #34495e 50%, #2c3e50 100%);">
            <div class="container">
                <div class="hero-content">
                    <div class="hero-text">
                        <span class="hero-subtitle">Limited Time Offer</span>
                        <h1>SAVE UP TO<br><span>70% OFF!</span></h1>
                        <p>Massive discounts on selected brake parts, engine components, wheels, tires, and more!</p>
                        <a href="#deals" class="btn btn-primary">SEE DEALS <i class="fas fa-arrow-right"></i></a>
                    </div>
                    <div class="hero-image">
                        <div class="hero-car-placeholder">
                            <i class="fas fa-tags" style="font-size: 180px; color: rgba(255,255,255,0.12);"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Slider Controls -->
    <button class="slider-btn slider-prev" id="sliderPrev"><i class="fas fa-chevron-left"></i></button>
    <button class="slider-btn slider-next" id="sliderNext"><i class="fas fa-chevron-right"></i></button>
    <div class="slider-dots" id="sliderDots">
        <span class="dot active"></span>
        <span class="dot"></span>
        <span class="dot"></span>
    </div>
</section>

<!-- ========== FEATURE STRIP ========== -->
<section class="feature-strip">
    <div class="container">
        <div class="feature-grid">
            <div class="feature-item">
                <i class="fas fa-truck"></i>
                <div>
                    <h4>Free Shipping</h4>
                    <p>On orders over ₱2,000</p>
                </div>
            </div>
            <div class="feature-item">
                <i class="fas fa-undo-alt"></i>
                <div>
                    <h4>Easy Returns</h4>
                    <p>30-day return policy</p>
                </div>
            </div>
            <div class="feature-item">
                <i class="fas fa-headset"></i>
                <div>
                    <h4>24/7 Support</h4>
                    <p>Dedicated customer care</p>
                </div>
            </div>
            <div class="feature-item">
                <i class="fas fa-shield-alt"></i>
                <div>
                    <h4>Secure Payment</h4>
                    <p>100% secure checkout</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ========== TOP CATEGORIES ========== -->
<section class="section categories-section" id="shop">
    <div class="container">
        <div class="section-header">
            <div class="section-title-wrapper">
                <span class="section-flag">SHOP BY CATEGORY</span>
                <h2 class="section-title">TOP CATEGORIES</h2>
            </div>
        </div>
        <div class="categories-grid">
            <?php
            foreach ($categories as $cat):
                $icon = $catIcons[$cat['category']] ?? 'fa-box';
            ?>
            <div class="category-card">
                <div class="category-icon"><i class="fas <?= $icon ?>"></i></div>
                <h3><?= htmlspecialchars($cat['category']) ?></h3>
                <p><?= $cat['cnt'] ?> product<?= $cat['cnt'] > 1 ? 's' : '' ?> available</p>
                <a href="javascript:void(0)" class="category-link" onclick="browseCategory('<?= htmlspecialchars($cat['category'], ENT_QUOTES) ?>')">Browse <i class="fas fa-arrow-right"></i></a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ========== ALL PRODUCTS ========== -->
<section class="section products-section" id="all-products">
    <div class="container">
        <div class="section-header">
            <div class="section-title-wrapper">
                <span class="section-flag">BROWSE OUR INVENTORY</span>
                <h2 class="section-title">ALL PRODUCTS</h2>
            </div>
            <div class="product-tabs">
                <button class="tab-btn active" data-tab="all">All</button>
                <?php
                $allCatTabs = [];
                foreach ($allProducts as $fp) {
                    $c = $fp['category'] ?? 'General';
                    if (!in_array($c, $allCatTabs)) $allCatTabs[] = $c;
                }
                sort($allCatTabs);
                foreach ($allCatTabs as $tc): ?>
                <button class="tab-btn" data-tab="<?= htmlspecialchars($tc) ?>"><?= htmlspecialchars($tc) ?></button>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="products-grid" id="allProductsGrid">
            <?php foreach ($allProducts as $prod):
                $icon = $catIcons[$prod['category']] ?? 'fa-box';
                $imgSrc = ($prod['image'] && file_exists(__DIR__ . '/uploads/' . $prod['image'])) ? 'uploads/' . htmlspecialchars($prod['image']) : '';
            ?>
            <div class="product-card" data-category="<?= htmlspecialchars($prod['category'] ?? 'General') ?>" data-item-id="<?= $prod['item_id'] ?>">
                <?php if ($prod['quantity'] <= 5): ?>
                <div class="product-badges"><span class="badge-hot">LOW STOCK</span></div>
                <?php endif; ?>
                <div class="product-image">
                    <?php if ($imgSrc): ?>
                        <img src="<?= $imgSrc ?>" alt="<?= htmlspecialchars($prod['item_name']) ?>" style="max-width:100%;max-height:160px;object-fit:contain;">
                    <?php else: ?>
                        <i class="fas <?= $icon ?> product-placeholder-icon"></i>
                    <?php endif; ?>
                </div>
                <div class="product-info">
                    <span class="product-category"><?= htmlspecialchars($prod['category'] ?? 'Auto Parts') ?></span>
                    <h3 class="product-name"><?= htmlspecialchars($prod['item_name']) ?></h3>
                    <div class="product-price">
                        <span class="price-current">₱<?= number_format($prod['unit_price'], 2) ?></span>
                    </div>
                    <small style="color:var(--text-muted);"><?= $prod['quantity'] ?> in stock</small>
                </div>
                <div class="product-actions">
                    <button class="btn-add-cart" onclick="addToCart(<?= $prod['item_id'] ?>, null, this)"><i class="fas fa-shopping-cart"></i> Add to Cart</button>
                    <button class="btn-quick-view" title="Quick View"><i class="fas fa-eye"></i></button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ========== DEAL OF THE DAY + BANNER ========== -->
<section class="section deal-section" id="deals">
    <div class="container">
        <div class="deal-layout">
            <!-- Deal of the Day -->
            <div class="deal-main">
                <div class="section-header">
                    <div class="section-title-wrapper">
                        <span class="section-flag">LIMITED TIME</span>
                        <h2 class="section-title">DEAL OF THE DAY</h2>
                    </div>
                </div>
                <?php if ($dealProduct): ?>
                <div class="deal-card">
                    <div class="deal-image">
                        <?php if ($dealProduct['image'] && file_exists(__DIR__ . '/uploads/' . $dealProduct['image'])): ?>
                            <img src="uploads/<?= htmlspecialchars($dealProduct['image']) ?>" alt="<?= htmlspecialchars($dealProduct['item_name']) ?>" style="max-width:200px;max-height:200px;object-fit:contain;">
                        <?php else: ?>
                            <i class="fas <?= $catIcons[$dealProduct['category']] ?? 'fa-oil-can' ?>" style="font-size: 100px; color: #e74c3c;"></i>
                        <?php endif; ?>
                    </div>
                    <div class="deal-info">
                        <span class="deal-category"><?= htmlspecialchars($dealProduct['category']) ?></span>
                        <h3><?= htmlspecialchars($dealProduct['item_name']) ?></h3>
                        <div class="deal-price">
                            <span class="price-current">₱<?= number_format($dealProduct['unit_price'], 2) ?></span>
                        </div>
                        <p class="deal-description"><?= htmlspecialchars($dealProduct['description']) ?></p>
                        <!-- Countdown -->
                        <div class="countdown" id="dealCountdown">
                            <div class="countdown-item">
                                <span class="count-num" id="days">01</span>
                                <span class="count-label">Days</span>
                            </div>
                            <div class="countdown-item">
                                <span class="count-num" id="hours">23</span>
                                <span class="count-label">Hours</span>
                            </div>
                            <div class="countdown-item">
                                <span class="count-num" id="minutes">47</span>
                                <span class="count-label">Min</span>
                            </div>
                            <div class="countdown-item">
                                <span class="count-num" id="seconds">52</span>
                                <span class="count-label">Sec</span>
                            </div>
                        </div>
                        <button class="btn btn-primary btn-lg" onclick="addToCart(<?= $dealProduct['item_id'] ?>, null, this)"><i class="fas fa-shopping-cart"></i> Add to Cart</button>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <!-- Side Banner -->
            <div class="deal-banners">
                <div class="promo-banner banner-red">
                    <div class="banner-content">
                        <span class="banner-label">AUTO PARTS</span>
                        <h3>SAVE UP TO<br><strong>70% OFF</strong></h3>
                        <p>On selected brake components</p>
                        <a href="javascript:void(0)" onclick="browseCategory('Brake Parts')" class="btn btn-white btn-sm">SHOP NOW</a>
                    </div>
                </div>
                <div class="promo-banner banner-dark">
                    <div class="banner-content">
                        <span class="banner-label">PREMIUM BRAND</span>
                        <h3>Monster Concept</h3>
                        <p>Performance parts collection</p>
                        <a href="javascript:void(0)" onclick="document.getElementById('all-products').scrollIntoView({behavior:'smooth'})" class="btn btn-primary btn-sm">EXPLORE</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ========== OUR SERVICES ========== -->
<section class="section services-section" id="services">
    <div class="container">
        <div class="section-header center">
            <div class="section-title-wrapper">
                <span class="section-flag">WHAT WE OFFER</span>
                <h2 class="section-title">OUR SERVICES</h2>
                <p class="section-subtitle">Professional automotive services with certified mechanics and state-of-the-art equipment</p>
            </div>
        </div>
        <div class="services-grid">
            <?php if (count($dbServices) > 0): ?>
                <?php 
                $serviceIcons = ['fa-oil-can','fa-cogs','fa-compact-disc','fa-circle-notch','fa-car-battery','fa-spray-can','fa-wrench','fa-tools','fa-car-crash','fa-fan'];
                foreach ($dbServices as $si => $svc): 
                    $icon = $serviceIcons[$si % count($serviceIcons)];
                    $duration = $svc['estimated_duration'] ? $svc['estimated_duration'] . ' min' : '';
                ?>
                <div class="service-card">
                    <div class="service-icon">
                        <i class="fas <?= $icon ?>"></i>
                    </div>
                    <h3><?= htmlspecialchars($svc['service_name']) ?></h3>
                    <p><?= htmlspecialchars($svc['description'] ?? 'Professional service for your vehicle.') ?></p>
                    <div class="service-price">Starting at <strong>₱<?= number_format($svc['base_price'], 2) ?></strong><?= $duration ? " &middot; ~{$duration}" : '' ?></div>
                    <div style="display:flex;gap:8px;justify-content:center;margin-top:10px;">
                        <button class="btn btn-outline" onclick="bookService(<?= $svc['service_id'] ?>, '<?= htmlspecialchars($svc['service_name'], ENT_QUOTES) ?>')"><i class="fas fa-calendar-check"></i> Book Now</button>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
            <!-- Fallback static services if no DB data -->
            <div class="service-card">
                <div class="service-icon"><i class="fas fa-oil-can"></i></div>
                <h3>Oil Change</h3>
                <p>Regular oil changes using premium synthetic or conventional oils to keep your engine running smoothly.</p>
                <div class="service-price">Starting at <strong>₱800</strong></div>
                <a href="users/book_service.php" class="btn btn-outline">Book Now</a>
            </div>
            <div class="service-card">
                <div class="service-icon"><i class="fas fa-cogs"></i></div>
                <h3>Engine Repair</h3>
                <p>Complete engine diagnostics, tune-ups, and major repair services for all vehicle makes and models.</p>
                <div class="service-price">Starting at <strong>₱2,500</strong></div>
                <a href="users/book_service.php" class="btn btn-outline">Book Now</a>
            </div>
            <div class="service-card">
                <div class="service-icon"><i class="fas fa-compact-disc"></i></div>
                <h3>Brake Service</h3>
                <p>Brake inspection, pad replacement, rotor resurfacing, and complete brake system overhaul.</p>
                <div class="service-price">Starting at <strong>₱1,500</strong></div>
                <a href="users/book_service.php" class="btn btn-outline">Book Now</a>
            </div>
            <div class="service-card">
                <div class="service-icon"><i class="fas fa-circle-notch"></i></div>
                <h3>Tire Service</h3>
                <p>Tire mounting, balancing, rotation, alignment, and flat tire repair services available.</p>
                <div class="service-price">Starting at <strong>₱500</strong></div>
                <a href="users/book_service.php" class="btn btn-outline">Book Now</a>
            </div>
            <div class="service-card">
                <div class="service-icon"><i class="fas fa-car-battery"></i></div>
                <h3>Battery Service</h3>
                <p>Battery testing, charging, replacement, and electrical system diagnostics for reliable starts.</p>
                <div class="service-price">Starting at <strong>₱300</strong></div>
                <a href="users/book_service.php" class="btn btn-outline">Book Now</a>
            </div>
            <div class="service-card">
                <div class="service-icon"><i class="fas fa-spray-can"></i></div>
                <h3>Car Detailing</h3>
                <p>Interior and exterior detailing, paint correction, ceramic coating, and protective treatments.</p>
                <div class="service-price">Starting at <strong>₱1,800</strong></div>
                <a href="users/book_service.php" class="btn btn-outline">Book Now</a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- ========== NEW ARRIVALS ========== -->
<section class="section new-arrivals-section">
    <div class="container">
        <div class="section-header">
            <div class="section-title-wrapper">
                <span class="section-flag">JUST IN</span>
                <h2 class="section-title">NEW ARRIVALS</h2>
            </div>
            <a href="#shop" class="btn btn-outline btn-sm">View All <i class="fas fa-arrow-right"></i></a>
        </div>
        <div class="products-grid">
            <?php foreach ($newArrivals as $prod):
                $icon = $catIcons[$prod['category']] ?? 'fa-box';
            ?>
            <div class="product-card" data-item-id="<?= $prod['item_id'] ?>">
                <div class="product-badges"><span class="badge-new">NEW</span></div>
                <div class="product-image">
                    <?php if ($prod['image'] && file_exists(__DIR__ . '/uploads/' . $prod['image'])): ?>
                        <img src="uploads/<?= htmlspecialchars($prod['image']) ?>" alt="<?= htmlspecialchars($prod['item_name']) ?>" style="max-width:100%;max-height:160px;object-fit:contain;">
                    <?php else: ?>
                        <i class="fas <?= $icon ?> product-placeholder-icon"></i>
                    <?php endif; ?>
                </div>
                <div class="product-info">
                    <span class="product-category"><?= htmlspecialchars($prod['category'] ?? 'Auto Parts') ?></span>
                    <h3 class="product-name"><?= htmlspecialchars($prod['item_name']) ?></h3>
                    <div class="product-price"><span class="price-current">₱<?= number_format($prod['unit_price'], 2) ?></span></div>
                </div>
                <div class="product-actions">
                    <button class="btn-add-cart" onclick="addToCart(<?= $prod['item_id'] ?>, null, this)"><i class="fas fa-shopping-cart"></i> Add to Cart</button>
                    <button class="btn-quick-view" title="Quick View"><i class="fas fa-eye"></i></button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ========== PROMO BANNERS ROW ========== -->
<section class="section promo-section">
    <div class="container">
        <div class="promo-row">
            <div class="promo-card promo-card-wide" style="background: linear-gradient(135deg, #c0392b, #e74c3c);">
                <div class="promo-card-content">
                    <span class="promo-tag">FERRARI BRAND</span>
                    <h3>AUTO PARTS</h3>
                    <p>Premium quality replacement parts</p>
                    <a href="javascript:void(0)" onclick="browseCategory('Engine Parts')" class="btn btn-white btn-sm">Shop Now</a>
                </div>
            </div>
            <div class="promo-card promo-card-wide" style="background: linear-gradient(135deg, #2c3e50, #34495e);">
                <div class="promo-card-content">
                    <span class="promo-tag">LEXUS BRAND</span>
                    <h3>AUTO PARTS</h3>
                    <p>Genuine OEM replacement parts</p>
                    <a href="javascript:void(0)" onclick="browseCategory('Accessories')" class="btn btn-primary btn-sm">Shop Now</a>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ========== REPLACEMENT PARTS ========== -->
<section class="section replacement-section">
    <div class="container">
        <div class="section-header">
            <div class="section-title-wrapper">
                <span class="section-flag">QUALITY PARTS</span>
                <h2 class="section-title">REPLACEMENT PARTS</h2>
            </div>
            <a href="#shop" class="btn btn-outline btn-sm">View All <i class="fas fa-arrow-right"></i></a>
        </div>
        <div class="products-grid">
            <?php foreach ($replacementParts as $prod):
                $icon = $catIcons[$prod['category']] ?? 'fa-box';
            ?>
            <div class="product-card" data-item-id="<?= $prod['item_id'] ?>">
                <div class="product-image">
                    <?php if ($prod['image'] && file_exists(__DIR__ . '/uploads/' . $prod['image'])): ?>
                        <img src="uploads/<?= htmlspecialchars($prod['image']) ?>" alt="<?= htmlspecialchars($prod['item_name']) ?>" style="max-width:100%;max-height:160px;object-fit:contain;">
                    <?php else: ?>
                        <i class="fas <?= $icon ?> product-placeholder-icon"></i>
                    <?php endif; ?>
                </div>
                <div class="product-info">
                    <span class="product-category"><?= htmlspecialchars($prod['category'] ?? 'Auto Parts') ?></span>
                    <h3 class="product-name"><?= htmlspecialchars($prod['item_name']) ?></h3>
                    <div class="product-price"><span class="price-current">₱<?= number_format($prod['unit_price'], 2) ?></span></div>
                </div>
                <div class="product-actions">
                    <button class="btn-add-cart" onclick="addToCart(<?= $prod['item_id'] ?>, null, this)"><i class="fas fa-shopping-cart"></i> Add to Cart</button>
                    <button class="btn-quick-view" title="Quick View"><i class="fas fa-eye"></i></button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ========== ABOUT SECTION ========== -->
<section class="section about-section" id="about">
    <div class="container">
        <div class="about-layout">
            <div class="about-image">
                <div class="about-img-placeholder">
                    <i class="fas fa-warehouse" style="font-size: 100px; color: #e74c3c;"></i>
                    <div class="about-stats">
                        <div class="stat-item">
                            <span class="stat-num">15+</span>
                            <span class="stat-label">Years Experience</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-num">50K+</span>
                            <span class="stat-label">Happy Customers</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="about-content">
                <span class="section-flag">WHO WE ARE</span>
                <h2>Your Trusted Auto Parts & Service Partner</h2>
                <p>VehiCare has been serving car enthusiasts and everyday drivers in Taguig City and Metro Manila since 2010. We pride ourselves on offering the widest selection of quality auto parts at competitive prices, backed by professional installation services.</p>
                <div class="about-features">
                    <div class="about-feature">
                        <i class="fas fa-check-circle"></i>
                        <span>Genuine OEM & Aftermarket Parts</span>
                    </div>
                    <div class="about-feature">
                        <i class="fas fa-check-circle"></i>
                        <span>Certified Professional Mechanics</span>
                    </div>
                    <div class="about-feature">
                        <i class="fas fa-check-circle"></i>
                        <span>Warranty on All Services</span>
                    </div>
                    <div class="about-feature">
                        <i class="fas fa-check-circle"></i>
                        <span>Competitive Pricing Guaranteed</span>
                    </div>
                </div>
                <a href="#contact" class="btn btn-primary">Learn More <i class="fas fa-arrow-right"></i></a>
            </div>
        </div>
    </div>
</section>

<!-- ========== TESTIMONIALS ========== -->
<section class="section testimonials-section">
    <div class="container">
        <div class="section-header center">
            <div class="section-title-wrapper">
                <span class="section-flag">CUSTOMER REVIEWS</span>
                <h2 class="section-title">WHAT OUR CLIENTS SAY</h2>
            </div>
        </div>
        <div class="testimonials-grid">
            <div class="testimonial-card">
                <div class="testimonial-stars">
                    <i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i>
                </div>
                <p>"Best auto parts shop in Taguig! Great prices on brake pads and the installation was quick and professional. Highly recommend!"</p>
                <div class="testimonial-author">
                    <div class="author-avatar"><i class="fas fa-user"></i></div>
                    <div>
                        <strong>Johnny Walker</strong>
                        <span>Toyota Fortuner Owner</span>
                    </div>
                </div>
            </div>
            <div class="testimonial-card">
                <div class="testimonial-stars">
                    <i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star-half-alt"></i>
                </div>
                <p>"Had my engine serviced here and I'm very impressed with the quality of work. The mechanics really know what they're doing. Fair pricing too!"</p>
                <div class="testimonial-author">
                    <div class="author-avatar"><i class="fas fa-user"></i></div>
                    <div>
                        <strong>Maria Santos</strong>
                        <span>Honda Civic Owner</span>
                    </div>
                </div>
            </div>
            <div class="testimonial-card">
                <div class="testimonial-stars">
                    <i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i>
                </div>
                <p>"I always get my tires and wheels from VehiCare. The selection is great and shipping was fast. Plus their online support is excellent!"</p>
                <div class="testimonial-author">
                    <div class="author-avatar"><i class="fas fa-user"></i></div>
                    <div>
                        <strong>Carlos Reyes</strong>
                        <span>Mitsubishi Montero Owner</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ========== NEWSLETTER ========== -->
<section class="newsletter-section">
    <div class="container">
        <div class="newsletter-content">
            <div class="newsletter-text">
                <h2>Subscribe to Our Newsletter</h2>
                <p>Get the latest deals, new arrivals, and exclusive offers delivered to your inbox.</p>
            </div>
            <form class="newsletter-form">
                <input type="email" placeholder="Enter your email address" required>
                <button type="submit" class="btn btn-primary">SUBSCRIBE <i class="fas fa-paper-plane"></i></button>
            </form>
        </div>
    </div>
</section>

<!-- ========== CONTACT SECTION ========== -->
<section class="section contact-section" id="contact">
    <div class="container">
        <div class="section-header center">
            <div class="section-title-wrapper">
                <span class="section-flag">GET IN TOUCH</span>
                <h2 class="section-title">CONTACT US</h2>
            </div>
        </div>
        <div class="contact-layout">
            <div class="contact-info-cards">
                <div class="contact-card">
                    <i class="fas fa-map-marker-alt"></i>
                    <h4>Our Location</h4>
                    <p>123 Auto Parts Ave, Taguig City<br>Metro Manila, Philippines</p>
                </div>
                <div class="contact-card">
                    <i class="fas fa-phone-alt"></i>
                    <h4>Phone Number</h4>
                    <p>+63 912 345 6789<br>+63 2 8123 4567</p>
                </div>
                <div class="contact-card">
                    <i class="fas fa-envelope"></i>
                    <h4>Email Address</h4>
                    <p>info@vehicare.ph<br>support@vehicare.ph</p>
                </div>
                <div class="contact-card">
                    <i class="fas fa-clock"></i>
                    <h4>Working Hours</h4>
                    <p>Mon - Sat: 8:00 AM - 8:00 PM<br>Sun: 9:00 AM - 5:00 PM</p>
                </div>
            </div>
            <div class="contact-form-wrapper">
                <form class="contact-form">
                    <div class="form-row">
                        <div class="form-group">
                            <input type="text" placeholder="Your Name" required>
                        </div>
                        <div class="form-group">
                            <input type="email" placeholder="Your Email" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <input type="text" placeholder="Subject">
                    </div>
                    <div class="form-group">
                        <textarea rows="5" placeholder="Your Message" required></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary btn-lg">Send Message <i class="fas fa-paper-plane"></i></button>
                </form>
            </div>
        </div>
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
                        <li><a href="#services">Services</a></li>
                        <li><a href="#shop">Shop</a></li>
                        <li><a href="#about">About Us</a></li>
                        <li><a href="#contact">Contact</a></li>
                    </ul>
                </div>
                <div class="footer-col">
                    <h4>Our Services</h4>
                    <ul>
                        <li><a href="#">Oil Change</a></li>
                        <li><a href="#">Engine Repair</a></li>
                        <li><a href="#">Brake Service</a></li>
                        <li><a href="#">Tire Service</a></li>
                        <li><a href="#">Car Detailing</a></li>
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

<!-- ========== BOOKING MODAL ========== -->
<style>
.modal-overlay{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.65);z-index:9999;align-items:center;justify-content:center;animation:fadeIn .3s}
.modal-overlay.active{display:flex}
.modal-content{background:#fff;border-radius:14px;width:560px;max-width:95%;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,0.3);animation:slideUp .3s}
.modal-header{background:linear-gradient(135deg,#e74c3c,#c0392b);color:#fff;padding:22px 25px;border-radius:14px 14px 0 0;display:flex;justify-content:space-between;align-items:center}
.modal-header h2{font-family:'Oswald',sans-serif;font-size:22px;margin:0}
.modal-close{background:rgba(255,255,255,0.2);border:none;color:#fff;width:36px;height:36px;border-radius:50%;font-size:18px;cursor:pointer;transition:background .3s}
.modal-close:hover{background:rgba(255,255,255,0.3)}
.modal-body{padding:25px}
.modal-body .form-group{margin-bottom:18px}
.modal-body .form-group label{display:block;font-size:13px;font-weight:600;color:#2c3e50;margin-bottom:6px}
.modal-body .form-group label .req{color:#e74c3c}
.modal-body .form-control{width:100%;padding:11px 14px;border:1px solid #ddd;border-radius:8px;font-size:14px;font-family:'Roboto',sans-serif;transition:border-color .3s,box-shadow .3s;box-sizing:border-box}
.modal-body .form-control:focus{outline:none;border-color:#e74c3c;box-shadow:0 0 0 3px rgba(231,76,60,0.1)}
.modal-body .btn-submit{width:100%;padding:13px;background:#e74c3c;color:#fff;border:none;border-radius:8px;font-size:15px;font-weight:700;font-family:'Oswald',sans-serif;letter-spacing:.5px;cursor:pointer;transition:background .3s}
.modal-body .btn-submit:hover{background:#c0392b}
.modal-body .btn-submit:disabled{opacity:.5;cursor:not-allowed}
.vehicle-form-panel{display:none;background:#f8f9fa;border:1px solid #dee2e6;border-radius:10px;padding:20px;margin-bottom:20px}
.vehicle-form-panel.active{display:block}
.vehicle-form-panel h4{font-family:'Oswald',sans-serif;font-size:17px;color:#2c3e50;margin:0 0 15px}
.vehicle-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.vehicle-form-grid .full-width{grid-column:1/-1}
.btn-add-vehicle{width:100%;padding:10px;background:#27ae60;color:#fff;border:none;border-radius:6px;font-size:14px;font-weight:600;cursor:pointer;transition:background .3s}
.btn-add-vehicle:hover{background:#219a52}
.btn-show-vehicle-form{background:#2c3e50;color:#fff;border:none;padding:8px 16px;border-radius:6px;font-size:13px;cursor:pointer;transition:background .3s}
.btn-show-vehicle-form:hover{background:#1a252f}
.no-vehicles-msg{background:#fff3cd;color:#856404;border:1px solid #ffeeba;padding:12px 16px;border-radius:8px;font-size:13px;margin-bottom:15px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.alert-modal{padding:12px 18px;border-radius:8px;margin-bottom:15px;font-size:14px;display:flex;align-items:center;gap:10px}
.alert-modal.success{background:#d4edda;color:#155724;border:1px solid #c3e6cb}
.alert-modal.error{background:#f8d7da;color:#721c24;border:1px solid #f5c6cb}
/* Quick View Modal */
.qv-modal .modal-content{width:650px}
.qv-modal .modal-header{background:linear-gradient(135deg,#2c3e50,#34495e)}
.qv-layout{display:flex;gap:25px;align-items:flex-start}
.qv-image{flex:0 0 200px;height:200px;display:flex;align-items:center;justify-content:center;background:#f8f9fa;border-radius:10px;overflow:hidden}
.qv-image img{max-width:100%;max-height:100%;object-fit:contain}
.qv-image i{font-size:80px;color:#e74c3c}
.qv-details{flex:1}
.qv-details .qv-category{color:#888;font-size:13px;text-transform:uppercase;letter-spacing:1px}
.qv-details .qv-name{font-family:'Oswald',sans-serif;font-size:24px;font-weight:700;color:#2c3e50;margin:8px 0}
.qv-details .qv-price{font-family:'Oswald',sans-serif;font-size:28px;color:#e74c3c;font-weight:700;margin:10px 0}
.qv-details .qv-stock{font-size:14px;color:#7f8c8d;margin-bottom:15px}
.qv-details .qv-stock span{font-weight:600;color:#27ae60}
.qv-actions{display:flex;gap:10px;margin-top:15px}
.qv-actions .btn-qv-cart{flex:1;padding:12px;background:#e74c3c;color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;transition:background .3s}
.qv-actions .btn-qv-cart:hover{background:#c0392b}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
@keyframes slideUp{from{opacity:0;transform:translateY(30px)}to{opacity:1;transform:translateY(0)}}
@media(max-width:600px){.qv-layout{flex-direction:column}.qv-image{flex:none;width:100%;height:160px}.vehicle-form-grid{grid-template-columns:1fr}}
</style>

<div class="modal-overlay" id="bookingModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-calendar-check"></i> Book Appointment</h2>
            <button class="modal-close" onclick="closeBookingModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div id="bookingAlert"></div>

            <!-- Add Vehicle Panel -->
            <div class="vehicle-form-panel" id="vehicleFormPanel">
                <h4><i class="fas fa-car"></i> Add a New Vehicle</h4>
                <div class="vehicle-form-grid">
                    <div>
                        <label>Plate Number <span class="req">*</span></label>
                        <input type="text" id="vPlate" class="form-control" placeholder="e.g. ABC 1234">
                    </div>
                    <div>
                        <label>Make <span class="req">*</span></label>
                        <input type="text" id="vMake" class="form-control" placeholder="e.g. Toyota">
                    </div>
                    <div>
                        <label>Model <span class="req">*</span></label>
                        <input type="text" id="vModel" class="form-control" placeholder="e.g. Vios">
                    </div>
                    <div>
                        <label>Year</label>
                        <input type="number" id="vYear" class="form-control" placeholder="e.g. 2023" min="1990" max="2027" value="2025">
                    </div>
                    <div class="full-width">
                        <label>Color</label>
                        <input type="text" id="vColor" class="form-control" placeholder="e.g. White">
                    </div>
                    <div class="full-width">
                        <button type="button" class="btn-add-vehicle" onclick="submitAddVehicle()"><i class="fas fa-plus-circle"></i> Save Vehicle</button>
                    </div>
                </div>
            </div>

            <!-- Booking Form -->
            <div class="form-group">
                <label><i class="fas fa-wrench"></i> Service <span class="req">*</span></label>
                <select id="bookServiceSelect" class="form-control">
                    <option value="">-- Select a Service --</option>
                    <?php foreach ($dbServices as $srv): ?>
                    <option value="<?= (int)$srv['service_id'] ?>"><?= htmlspecialchars($srv['service_name']) ?> — ₱<?= number_format($srv['base_price'], 2) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label><i class="fas fa-car"></i> Vehicle <span class="req">*</span></label>
                <select id="bookVehicleSelect" class="form-control">
                    <option value="">Loading vehicles...</option>
                </select>
                <div style="margin-top:8px;">
                    <button type="button" class="btn-show-vehicle-form" onclick="toggleVehicleForm()">
                        <i class="fas fa-plus"></i> Add New Vehicle
                    </button>
                </div>
            </div>

            <div class="form-group">
                <label><i class="fas fa-calendar-alt"></i> Preferred Date &amp; Time <span class="req">*</span></label>
                <input type="datetime-local" id="bookDate" class="form-control">
                <div id="bookedSlotsInfo" style="margin-top:8px;display:none;">
                    <div style="font-size:12px;font-weight:600;color:#e74c3c;margin-bottom:4px;"><i class="fas fa-exclamation-triangle"></i> Already booked time slots on this date:</div>
                    <div id="bookedSlotsList" style="display:flex;flex-wrap:wrap;gap:6px;"></div>
                </div>
            </div>

            <button type="button" class="btn-submit" id="btnSubmitBooking" onclick="submitBooking()">
                <i class="fas fa-check-circle"></i> Book Appointment
            </button>
        </div>
    </div>
</div>

<!-- ========== QUICK VIEW MODAL ========== -->
<div class="modal-overlay qv-modal" id="quickViewModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-eye"></i> Quick View</h2>
            <button class="modal-close" onclick="closeQuickView()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="qv-layout">
                <div class="qv-image" id="qvImage"></div>
                <div class="qv-details">
                    <div class="qv-category" id="qvCategory"></div>
                    <h2 class="qv-name" id="qvName"></h2>
                    <div class="qv-price" id="qvPrice"></div>
                    <div class="qv-stock" id="qvStock"></div>
                    <div class="qv-actions">
                        <button class="btn-qv-cart" id="qvCartBtn"><i class="fas fa-shopping-cart"></i> Add to Cart</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ========== BACK TO TOP ========== -->
<button class="back-to-top" id="backToTop"><i class="fas fa-chevron-up"></i></button>

<!-- ========== JAVASCRIPT ========== -->
<script>
const IS_LOGGED_IN = <?= $isLoggedIn ?>;

// ===== Mobile Nav Toggle =====
document.getElementById('mobileToggle').addEventListener('click', function() {
    document.querySelector('.main-nav').classList.toggle('active');
    this.querySelector('i').classList.toggle('fa-bars');
    this.querySelector('i').classList.toggle('fa-times');
});

// ===== Hero Slider =====
let currentSlide = 0;
const slides = document.querySelectorAll('.hero-slide');
const dots = document.querySelectorAll('.dot');

function showSlide(index) {
    slides.forEach(s => s.classList.remove('active'));
    dots.forEach(d => d.classList.remove('active'));
    currentSlide = (index + slides.length) % slides.length;
    slides[currentSlide].classList.add('active');
    dots[currentSlide].classList.add('active');
}

document.getElementById('sliderNext').addEventListener('click', () => showSlide(currentSlide + 1));
document.getElementById('sliderPrev').addEventListener('click', () => showSlide(currentSlide - 1));
dots.forEach((dot, i) => dot.addEventListener('click', () => showSlide(i)));
setInterval(() => showSlide(currentSlide + 1), 6000);

// ===== Product Tab Filtering =====
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const section = this.closest('.products-section');
        section.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        const tab = this.dataset.tab;
        section.querySelectorAll('.product-card').forEach(card => {
            if (tab === 'all' || card.dataset.category === tab) {
                card.style.display = '';
                card.style.animation = 'fadeInUp 0.5s ease forwards';
            } else {
                card.style.display = 'none';
            }
        });
    });
});

// ===== Browse Category Function =====
function browseCategory(category) {
    const section = document.getElementById('all-products');
    if (!section) return;
    // Scroll to All Products section
    section.scrollIntoView({ behavior: 'smooth', block: 'start' });
    // Close mobile nav
    document.querySelector('.main-nav').classList.remove('active');
    // Find and click the matching tab
    setTimeout(() => {
        const tabs = section.querySelectorAll('.tab-btn');
        let found = false;
        tabs.forEach(tab => {
            if (tab.dataset.tab === category) {
                tab.click();
                found = true;
            }
        });
        if (!found) {
            // If exact match not found, click All
            tabs.forEach(tab => { if (tab.dataset.tab === 'all') tab.click(); });
        }
    }, 400);
}

// ===== Countdown Timer =====
function updateCountdown() {
    const now = new Date();
    const end = new Date();
    end.setDate(end.getDate() + 1);
    end.setHours(23, 59, 59, 0);
    const diff = end - now;
    const d = Math.floor(diff / (1000 * 60 * 60 * 24));
    const h = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
    const m = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
    const s = Math.floor((diff % (1000 * 60)) / 1000);
    document.getElementById('days').textContent = String(d).padStart(2, '0');
    document.getElementById('hours').textContent = String(h).padStart(2, '0');
    document.getElementById('minutes').textContent = String(m).padStart(2, '0');
    document.getElementById('seconds').textContent = String(s).padStart(2, '0');
}
setInterval(updateCountdown, 1000);
updateCountdown();

// ===== Back to Top =====
const backToTop = document.getElementById('backToTop');
window.addEventListener('scroll', () => {
    backToTop.classList.toggle('visible', window.scrollY > 400);
});
backToTop.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));

// ===== Sticky Header =====
window.addEventListener('scroll', () => {
    document.querySelector('.main-header').classList.toggle('sticky', window.scrollY > 100);
});

// ===== Scroll Animations =====
const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            entry.target.classList.add('animate-in');
        }
    });
}, { threshold: 0.1 });

document.querySelectorAll('.product-card, .service-card, .category-card, .testimonial-card, .contact-card, .about-feature').forEach(el => {
    observer.observe(el);
});

// ===== Smooth Scroll for anchor links =====
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function(e) {
        const href = this.getAttribute('href');
        if (href === '#') return;
        e.preventDefault();
        const target = document.querySelector(href);
        if (target) {
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            document.querySelector('.main-nav').classList.remove('active');
        }
    });
});

// ===== Add to Cart (AJAX) =====
function addToCart(itemId, serviceId, btn) {
    const originalHTML = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding...';
    btn.disabled = true;

    const formData = new FormData();
    formData.append('ajax_action', 'add_to_cart');
    if (itemId) formData.append('item_id', itemId);
    if (serviceId) formData.append('service_id', serviceId);
    formData.append('quantity', 1);

    fetch('index.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            btn.innerHTML = '<i class="fas fa-check"></i> Added!';
            btn.style.background = '#27ae60';
            btn.style.borderColor = '#27ae60';
            const badge = document.getElementById('cartBadge');
            if (badge) badge.textContent = data.cartCount;
            setTimeout(() => {
                btn.innerHTML = originalHTML;
                btn.style.background = '';
                btn.style.borderColor = '';
                btn.disabled = false;
            }, 1500);
        } else {
            if (data.redirect) {
                if (confirm(data.message + '\nGo to login page?')) {
                    window.location.href = data.redirect;
                }
            } else {
                alert(data.message);
            }
            btn.innerHTML = originalHTML;
            btn.disabled = false;
        }
    })
    .catch(() => {
        btn.innerHTML = originalHTML;
        btn.disabled = false;
        alert('Error adding to cart. Please try again.');
    });
}

// ===== Add Service to Cart =====
document.querySelectorAll('.btn-add-service-cart').forEach(btn => {
    btn.addEventListener('click', function() {
        addToCart(null, this.dataset.serviceId, this);
    });
});

// ===== Product Search Filter =====
const searchInput = document.getElementById('productSearch');
if (searchInput) {
    searchInput.addEventListener('input', function() {
        const query = this.value.toLowerCase();
        document.querySelectorAll('.products-grid .product-card').forEach(card => {
            const name = (card.querySelector('.product-name')?.textContent || '').toLowerCase();
            const cat = (card.querySelector('.product-category')?.textContent || '').toLowerCase();
            card.style.display = (name.includes(query) || cat.includes(query) || query === '') ? '' : 'none';
        });
    });
}

// ===========================
// BOOKING MODAL FUNCTIONS
// ===========================
function bookService(serviceId, serviceName) {
    if (!IS_LOGGED_IN) {
        if (confirm('Please login to book a service.\nGo to login page?')) {
            window.location.href = 'users/login.php';
        }
        return;
    }
    // Close mobile nav
    document.querySelector('.main-nav').classList.remove('active');
    // Open modal
    openBookingModal(serviceId);
}

function openBookingModal(serviceId) {
    const modal = document.getElementById('bookingModal');
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
    document.getElementById('bookingAlert').innerHTML = '';
    document.getElementById('vehicleFormPanel').classList.remove('active');

    // Pre-select service
    if (serviceId) {
        document.getElementById('bookServiceSelect').value = serviceId;
    }

    // Set min date to tomorrow 8 AM
    const dt = document.getElementById('bookDate');
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    tomorrow.setHours(8, 0, 0, 0);
    const y = tomorrow.getFullYear();
    const m = String(tomorrow.getMonth() + 1).padStart(2, '0');
    const d = String(tomorrow.getDate()).padStart(2, '0');
    dt.min = `${y}-${m}-${d}T08:00`;
    if (!dt.value) dt.value = `${y}-${m}-${d}T09:00`;

    // Check booked slots for default date
    checkBookedSlots(dt.value);

    // Listen for date changes
    dt.addEventListener('change', function() { checkBookedSlots(this.value); });

    // Fetch vehicles
    fetchVehicles();
}

function closeBookingModal() {
    document.getElementById('bookingModal').classList.remove('active');
    document.body.style.overflow = '';
}

function fetchVehicles() {
    const sel = document.getElementById('bookVehicleSelect');
    sel.innerHTML = '<option value="">Loading...</option>';

    const fd = new FormData();
    fd.append('ajax_action', 'get_vehicles');
    fetch('index.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        if (data.success && data.vehicles.length > 0) {
            sel.innerHTML = '<option value="">-- Select your vehicle --</option>';
            data.vehicles.forEach(v => {
                const opt = document.createElement('option');
                opt.value = v.vehicle_id;
                opt.textContent = `${v.plate_number} — ${v.make} ${v.model}` + (v.year ? ` (${v.year})` : '');
                sel.appendChild(opt);
            });
            document.getElementById('btnSubmitBooking').disabled = false;
        } else {
            sel.innerHTML = '<option value="">No vehicles found — add one below</option>';
            document.getElementById('vehicleFormPanel').classList.add('active');
        }
    })
    .catch(() => {
        sel.innerHTML = '<option value="">Error loading vehicles</option>';
    });
}

function toggleVehicleForm() {
    document.getElementById('vehicleFormPanel').classList.toggle('active');
}

function submitAddVehicle() {
    const plate = document.getElementById('vPlate').value.trim();
    const make = document.getElementById('vMake').value.trim();
    const model = document.getElementById('vModel').value.trim();
    const year = document.getElementById('vYear').value;
    const color = document.getElementById('vColor').value.trim();

    if (!plate || !make || !model) {
        showBookingAlert('Plate number, make, and model are required.', 'error');
        return;
    }

    const fd = new FormData();
    fd.append('ajax_action', 'add_vehicle');
    fd.append('plate_number', plate);
    fd.append('make', make);
    fd.append('model', model);
    fd.append('year', year);
    fd.append('color', color);

    fetch('index.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showBookingAlert(data.message, 'success');
            // Clear form
            document.getElementById('vPlate').value = '';
            document.getElementById('vMake').value = '';
            document.getElementById('vModel').value = '';
            document.getElementById('vColor').value = '';
            document.getElementById('vehicleFormPanel').classList.remove('active');
            // Refresh vehicles dropdown
            fetchVehicles();
            // Auto-select new vehicle after a moment
            setTimeout(() => {
                const sel = document.getElementById('bookVehicleSelect');
                if (data.vehicle) sel.value = data.vehicle.vehicle_id;
            }, 500);
        } else {
            showBookingAlert(data.message, 'error');
        }
    })
    .catch(() => showBookingAlert('Error adding vehicle.', 'error'));
}

function checkBookedSlots(dateVal) {
    const infoDiv = document.getElementById('bookedSlotsInfo');
    const listDiv = document.getElementById('bookedSlotsList');
    if (!dateVal) { infoDiv.style.display = 'none'; return; }

    const fd = new FormData();
    fd.append('ajax_action', 'check_timeslot');
    fd.append('date', dateVal);
    fetch('index.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        if (data.success && data.booked.length > 0) {
            listDiv.innerHTML = data.booked.map(s =>
                `<span style="display:inline-block;padding:4px 10px;background:#ffeaea;color:#c0392b;border-radius:20px;font-size:12px;font-weight:600;border:1px solid #f5c6cb;">
                    <i class="fas fa-clock"></i> ${s.start} – ${s.end}
                </span>`
            ).join('');
            infoDiv.style.display = 'block';
        } else {
            infoDiv.style.display = 'none';
        }
    })
    .catch(() => { infoDiv.style.display = 'none'; });
}

function submitBooking() {
    const serviceId = document.getElementById('bookServiceSelect').value;
    const vehicleId = document.getElementById('bookVehicleSelect').value;
    const date = document.getElementById('bookDate').value;

    if (!serviceId) { showBookingAlert('Please select a service.', 'error'); return; }
    if (!vehicleId) { showBookingAlert('Please select a vehicle.', 'error'); return; }
    if (!date) { showBookingAlert('Please select a date and time.', 'error'); return; }

    const btn = document.getElementById('btnSubmitBooking');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Booking...';

    const fd = new FormData();
    fd.append('ajax_action', 'book_appointment');
    fd.append('vehicle_id', vehicleId);
    fd.append('service_id', serviceId);
    fd.append('appointment_date', date);

    fetch('index.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showBookingAlert(data.message, 'success');
            btn.innerHTML = '<i class="fas fa-check-circle"></i> Booked!';
            btn.style.background = '#27ae60';
            setTimeout(() => {
                closeBookingModal();
                btn.innerHTML = '<i class="fas fa-check-circle"></i> Book Appointment';
                btn.style.background = '';
                btn.disabled = false;
            }, 2000);
        } else {
            showBookingAlert(data.message, 'error');
            btn.innerHTML = '<i class="fas fa-check-circle"></i> Book Appointment';
            btn.disabled = false;
        }
    })
    .catch(() => {
        showBookingAlert('Error booking appointment.', 'error');
        btn.innerHTML = '<i class="fas fa-check-circle"></i> Book Appointment';
        btn.disabled = false;
    });
}

function showBookingAlert(msg, type) {
    document.getElementById('bookingAlert').innerHTML =
        `<div class="alert-modal ${type}"><i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i> ${msg}</div>`;
}

// Modal close on overlay click / Escape
document.getElementById('bookingModal').addEventListener('click', function(e) { if (e.target === this) closeBookingModal(); });
document.getElementById('quickViewModal').addEventListener('click', function(e) { if (e.target === this) closeQuickView(); });
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') { closeBookingModal(); closeQuickView(); }
});

// ===========================
// QUICK VIEW MODAL FUNCTIONS
// ===========================
document.addEventListener('click', function(e) {
    const btn = e.target.closest('.btn-quick-view');
    if (!btn) return;
    const card = btn.closest('.product-card');
    if (!card) return;

    const name = card.querySelector('.product-name')?.textContent || 'Product';
    const category = card.querySelector('.product-category')?.textContent || '';
    const price = card.querySelector('.price-current')?.textContent || '';
    const stockEl = card.querySelector('.product-info small');
    const stock = stockEl ? stockEl.textContent : '';
    const img = card.querySelector('.product-image img');
    const icon = card.querySelector('.product-image .product-placeholder-icon');
    const itemId = card.dataset.itemId || null;

    // Populate modal
    document.getElementById('qvName').textContent = name;
    document.getElementById('qvCategory').textContent = category;
    document.getElementById('qvPrice').textContent = price;
    document.getElementById('qvStock').innerHTML = stock ? `<i class="fas fa-box"></i> ${stock}` : '';

    const imgContainer = document.getElementById('qvImage');
    if (img) {
        imgContainer.innerHTML = `<img src="${img.src}" alt="${name}">`;
    } else if (icon) {
        imgContainer.innerHTML = `<i class="${icon.className}" style="font-size:80px;color:#e74c3c;"></i>`;
    } else {
        imgContainer.innerHTML = '<i class="fas fa-box" style="font-size:80px;color:#ccc;"></i>';
    }

    // Cart button
    const cartBtn = document.getElementById('qvCartBtn');
    if (itemId) {
        cartBtn.onclick = function() { addToCart(parseInt(itemId), null, this); };
        cartBtn.style.display = '';
    } else {
        cartBtn.style.display = 'none';
    }

    // Show modal
    document.getElementById('quickViewModal').classList.add('active');
    document.body.style.overflow = 'hidden';
});

function closeQuickView() {
    document.getElementById('quickViewModal').classList.remove('active');
    document.body.style.overflow = '';
}
</script>

</body>
</html>
