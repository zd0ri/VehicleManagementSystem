<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../users/login.php');
    exit;
}

$page_title = 'Walk-In Bookings';
$current_page = 'walkin';

require_once __DIR__ . '/../includes/db.php';

$success = '';
$error = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // --- Create new customer account + vehicle + walk-in appointment ---
    if ($action === 'add_walkin') {
        try {
            $pdo->beginTransaction();

            $customer_type = $_POST['customer_type'] ?? 'new';

            if ($customer_type === 'new') {
                // Create new user account
                $full_name = trim($_POST['full_name']);
                $email = trim($_POST['email']);
                $phone = trim($_POST['phone']);
                $address = trim($_POST['address'] ?? '');

                if (!$full_name || !$email || !$phone) {
                    throw new Exception('Full name, email, and phone are required.');
                }

                // Check for duplicate email
                $dup = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
                $dup->execute([$email]);
                if ($dup->fetch()) {
                    throw new Exception('A user with that email already exists. Use "Existing Customer" instead.');
                }

                // Generate default password (phone number as default)
                $default_password = password_hash($phone, PASSWORD_DEFAULT);

                $stmt = $pdo->prepare("INSERT INTO users (full_name, email, password_hash, role, status) VALUES (?, ?, ?, 'customer', 'active')");
                $stmt->execute([$full_name, $email, $default_password]);
                $user_id = $pdo->lastInsertId();

                // Create client record
                $stmt = $pdo->prepare("INSERT INTO clients (user_id, full_name, phone, email, address) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$user_id, $full_name, $phone, $email, $address]);
                $client_id = $pdo->lastInsertId();
            } else {
                // Existing customer
                $client_id = (int) $_POST['client_id'];
                if (!$client_id) {
                    throw new Exception('Please select an existing customer.');
                }
            }

            // Handle vehicle
            $vehicle_type = $_POST['vehicle_type'] ?? 'new';

            if ($vehicle_type === 'new') {
                $plate = strtoupper(trim($_POST['plate_number']));
                $make = trim($_POST['make']);
                $model = trim($_POST['model']);
                $year = trim($_POST['year'] ?? '');
                $color = trim($_POST['color'] ?? '');

                if (!$plate || !$make || !$model) {
                    throw new Exception('Plate number, make, and model are required.');
                }

                // Check duplicate plate
                $dup = $pdo->prepare("SELECT vehicle_id FROM vehicles WHERE plate_number = ?");
                $dup->execute([$plate]);
                if ($dup->fetch()) {
                    throw new Exception('A vehicle with that plate number already exists.');
                }

                $stmt = $pdo->prepare("INSERT INTO vehicles (client_id, plate_number, make, model, year, color, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
                $stmt->execute([$client_id, $plate, $make, $model, $year ?: null, $color]);
                $vehicle_id = $pdo->lastInsertId();
            } else {
                $vehicle_id = (int) $_POST['vehicle_id'];
                if (!$vehicle_id) {
                    throw new Exception('Please select a vehicle.');
                }
            }

            $service_id = (int) ($_POST['service_id'] ?? 0);
            $notes = trim($_POST['notes'] ?? '');

            // Find least busy available technician
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

            $free_tech = null;
            $least_busy_tech = null;
            foreach ($all_techs as $t) {
                if (!$least_busy_tech) $least_busy_tech = $t;
                if ((int) $t['ongoing_count'] === 0) {
                    $free_tech = $t;
                    break;
                }
            }

            $svc_name = 'Walk-In Service';
            if ($service_id) {
                $svc = $pdo->prepare("SELECT service_name FROM services WHERE service_id = ?");
                $svc->execute([$service_id]);
                $svc_name = $svc->fetchColumn() ?: $svc_name;
            }

            if ($free_tech) {
                // Auto-assign: create appointment + assignment
                $stmt = $pdo->prepare("INSERT INTO appointments (client_id, vehicle_id, service_id, appointment_date, status, appointment_type, notes, created_by) VALUES (?, ?, ?, NOW(), 'Approved', 'Walk-In', ?, ?)");
                $stmt->execute([$client_id, $vehicle_id, $service_id ?: null, $notes, $_SESSION['user_id']]);
                $appointment_id = $pdo->lastInsertId();

                $stmt = $pdo->prepare("INSERT INTO assignments (appointment_id, vehicle_id, technician_id, service_id, status) VALUES (?, ?, ?, ?, 'Assigned')");
                $stmt->execute([$appointment_id, $vehicle_id, $free_tech['user_id'], $service_id ?: null]);

                $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'New Walk-In Assignment', ?, 'new_assignment')")
                    ->execute([$free_tech['user_id'], 'Walk-in assigned to you: ' . $svc_name . '.']);

                // Add to queue for tracking
                $next_pos = (int) $pdo->query("SELECT COALESCE(MAX(position), 0) + 1 FROM queue WHERE status IN ('Waiting','Serving')")->fetchColumn();
                $stmt = $pdo->prepare("INSERT INTO queue (vehicle_id, client_id, position, status) VALUES (?, ?, ?, 'Serving')");
                $stmt->execute([$vehicle_id, $client_id, $next_pos]);

                logAudit($pdo, 'Created walk-in appointment (assigned)', 'appointments', $appointment_id);
                $pdo->commit();
                $success = 'Walk-in assigned to technician ' . htmlspecialchars($free_tech['full_name']) . '. Queue position: #' . $next_pos;
            } else {
                // All technicians busy — add to queue
                $stmt = $pdo->prepare("INSERT INTO appointments (client_id, vehicle_id, service_id, appointment_date, status, appointment_type, notes, created_by) VALUES (?, ?, ?, NOW(), 'Pending', 'Walk-In', ?, ?)");
                $stmt->execute([$client_id, $vehicle_id, $service_id ?: null, $notes, $_SESSION['user_id']]);

                $next_pos = (int) $pdo->query("SELECT COALESCE(MAX(position), 0) + 1 FROM queue WHERE status IN ('Waiting','Serving')")->fetchColumn();
                $stmt = $pdo->prepare("INSERT INTO queue (vehicle_id, client_id, position, status) VALUES (?, ?, ?, 'Waiting')");
                $stmt->execute([$vehicle_id, $client_id, $next_pos]);

                logAudit($pdo, 'Created walk-in appointment (queued)', 'appointments', $pdo->lastInsertId());
                $pdo->commit();
                $tech_name = $least_busy_tech ? htmlspecialchars($least_busy_tech['full_name']) : 'a technician';
                $success = 'All technicians are busy. Walk-in queued at position #' . $next_pos . '. Will be assigned to ' . $tech_name . ' when available.';
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Failed to add walk-in: ' . $e->getMessage();
        }
    }

    if ($action === 'update_status') {
        try {
            $new_status = $_POST['status'];
            $queue_id = (int) $_POST['queue_id'];

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("UPDATE queue SET status = ? WHERE queue_id = ?");
            $stmt->execute([$new_status, $queue_id]);

            // Cascading logic when cancelling a walk-in
            if ($new_status === 'Cancelled') {
                // Get queue info
                $qInfo = $pdo->prepare("SELECT q.client_id, q.vehicle_id, q.position FROM queue WHERE queue_id = ?");
                $qInfo->execute([$queue_id]);
                $qData = $qInfo->fetch();

                if ($qData) {
                    // Reorder queue positions
                    $pdo->prepare("UPDATE queue SET position = position - 1 WHERE position > ? AND status IN ('Waiting','Serving') ORDER BY position ASC")->execute([$qData['position']]);

                    // Cancel related appointment
                    $apptStmt = $pdo->prepare("SELECT ap.appointment_id, ap.service_id, s.service_name
                        FROM appointments ap
                        LEFT JOIN services s ON ap.service_id = s.service_id
                        WHERE ap.client_id = ? AND ap.vehicle_id = ? AND ap.appointment_type = 'Walk-In'
                        AND ap.status IN ('Pending','Approved') AND DATE(ap.created_at) = CURDATE()
                        LIMIT 1");
                    $apptStmt->execute([$qData['client_id'], $qData['vehicle_id']]);
                    $appt = $apptStmt->fetch();

                    if ($appt) {
                        $pdo->prepare("UPDATE appointments SET status = 'Cancelled' WHERE appointment_id = ?")->execute([$appt['appointment_id']]);

                        // Cancel related assignments and notify technician
                        $asgnStmt = $pdo->prepare("SELECT assignment_id, technician_id FROM assignments WHERE appointment_id = ? AND status IN ('Assigned','Ongoing')");
                        $asgnStmt->execute([$appt['appointment_id']]);
                        $assignments = $asgnStmt->fetchAll();
                        if (!empty($assignments)) {
                            $pdo->prepare("DELETE FROM assignments WHERE appointment_id = ? AND status IN ('Assigned','Ongoing')")->execute([$appt['appointment_id']]);
                            foreach ($assignments as $a) {
                                $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'Walk-In Cancelled', ?, 'cancellation')")
                                    ->execute([$a['technician_id'], 'Walk-in appointment #' . $appt['appointment_id'] . ' for ' . ($appt['service_name'] ?? 'a service') . ' has been cancelled.']);
                            }
                        }
                    }

                    // Notify customer
                    $clientUser = $pdo->prepare("SELECT user_id FROM clients WHERE client_id = ?");
                    $clientUser->execute([$qData['client_id']]);
                    $cu = $clientUser->fetch();
                    if ($cu) {
                        $svcName = $appt['service_name'] ?? 'your walk-in service';
                        $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'Walk-In Cancelled', ?, 'cancellation')")
                            ->execute([$cu['user_id'], 'Your walk-in booking for ' . $svcName . ' has been cancelled by admin.']);
                    }
                }
            }

            logAudit($pdo, 'Updated walk-in queue status to ' . $new_status, 'queue', $queue_id);
            $pdo->commit();
            $success = 'Queue status updated successfully.';
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Failed to update status: ' . $e->getMessage();
        }
    }

    if ($action === 'delete') {
        try {
            $del = $pdo->prepare("SELECT position FROM queue WHERE queue_id = ?");
            $del->execute([$_POST['queue_id']]);
            $del_pos = $del->fetchColumn();
            $pdo->prepare("DELETE FROM queue WHERE queue_id = ?")->execute([$_POST['queue_id']]);
            if ($del_pos) {
                $pdo->prepare("UPDATE queue SET position = position - 1 WHERE position > ? ORDER BY position ASC")->execute([$del_pos]);
            }
            logAudit($pdo, 'Deleted walk-in entry', 'queue', $_POST['queue_id']);
            $success = 'Walk-in entry deleted successfully.';
        } catch (Exception $e) {
            $error = 'Failed to delete walk-in: ' . $e->getMessage();
        }
    }

    // --- Place order for walk-in customer ---
    if ($action === 'place_order') {
        try {
            $client_id = (int) $_POST['order_client_id'];
            $pay_method = $_POST['payment_method'] ?? 'Cash';
            $order_notes = trim($_POST['order_notes'] ?? '');
            $items_json = $_POST['order_items'] ?? '[]';
            $items = json_decode($items_json, true);

            if (!$client_id) throw new Exception('Client ID is required.');
            if (empty($items)) throw new Exception('No items selected.');
            if (!in_array($pay_method, ['Cash', 'GCash', 'Maya'])) $pay_method = 'Cash';

            $pdo->beginTransaction();

            $subtotal = 0;
            $validated_items = [];

            // Validate stock and calculate subtotal
            foreach ($items as $item) {
                $item_id = (int) $item['item_id'];
                $qty = max(1, (int) $item['qty']);

                $stock = $pdo->prepare("SELECT item_id, item_name, unit_price, quantity FROM inventory WHERE item_id = ? FOR UPDATE");
                $stock->execute([$item_id]);
                $inv = $stock->fetch();

                if (!$inv) throw new Exception('Item not found in inventory.');
                if ($inv['quantity'] < $qty) throw new Exception('Not enough stock for "' . $inv['item_name'] . '". Available: ' . $inv['quantity']);

                $line_total = $inv['unit_price'] * $qty;
                $subtotal += $line_total;
                $validated_items[] = ['item_id' => $item_id, 'qty' => $qty, 'unit_price' => $inv['unit_price'], 'name' => $inv['item_name']];
            }

            $tax = round($subtotal * 0.12, 2);
            $total = $subtotal + $tax;

            // Handle receipt for e-wallet
            $receipt_image = null;
            if (in_array($pay_method, ['GCash', 'Maya']) && isset($_FILES['receipt_image']) && $_FILES['receipt_image']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['receipt_image']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                    $filename = 'receipt_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    move_uploaded_file($_FILES['receipt_image']['tmp_name'], __DIR__ . '/../uploads/' . $filename);
                    $receipt_image = $filename;
                }
            }

            $order_status = (in_array($pay_method, ['GCash', 'Maya']) && $receipt_image) ? 'Completed' : 'Pending';

            // Create order
            $stmt = $pdo->prepare("INSERT INTO orders (client_id, order_type, subtotal, tax_amount, total_amount, status, payment_method, receipt_image, notes) VALUES (?, 'product', ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$client_id, $subtotal, $tax, $total, $order_status, $pay_method, $receipt_image, $order_notes ?: 'Walk-in purchase']);
            $order_id = $pdo->lastInsertId();

            // Create order items and deduct inventory
            foreach ($validated_items as $vi) {
                $pdo->prepare("INSERT INTO order_items (order_id, item_id, quantity, unit_price) VALUES (?, ?, ?, ?)")
                    ->execute([$order_id, $vi['item_id'], $vi['qty'], $vi['unit_price']]);
                $pdo->prepare("UPDATE inventory SET quantity = quantity - ? WHERE item_id = ?")
                    ->execute([$vi['qty'], $vi['item_id']]);
            }

            // Notify admins for e-wallet
            if (in_array($pay_method, ['GCash', 'Maya'])) {
                $admins = $pdo->query("SELECT user_id FROM users WHERE role = 'admin' AND status = 'active'")->fetchAll();
                foreach ($admins as $adm) {
                    $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'Walk-In E-Wallet Payment', ?, 'ewallet_payment')")
                        ->execute([$adm['user_id'], 'Walk-in Order #' . $order_id . ' paid via ' . $pay_method . '. Amount: ₱' . number_format($total, 2)]);
                }
            }

            logAudit($pdo, 'Placed walk-in order', 'orders', $order_id);
            $pdo->commit();
            $success = 'Order #' . $order_id . ' placed successfully for walk-in customer! Total: ₱' . number_format($total, 2) . ' (' . $pay_method . ')';
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Failed to place order: ' . $e->getMessage();
        }
    }
}

// Fetch today's walk-in appointments with queue info
$walkins = $pdo->query("
    SELECT q.*, c.full_name AS client_name, c.phone AS client_phone,
           v.plate_number, v.make, v.model, v.year, v.color,
           ap.appointment_id, ap.service_id, ap.status AS appt_status, ap.appointment_type,
           s.service_name,
           u.full_name AS technician_name,
           asgn.status AS assignment_status
    FROM queue q
    LEFT JOIN clients c ON q.client_id = c.client_id
    LEFT JOIN vehicles v ON q.vehicle_id = v.vehicle_id
    LEFT JOIN appointments ap ON ap.vehicle_id = q.vehicle_id AND ap.client_id = q.client_id AND ap.appointment_type = 'Walk-In' AND DATE(ap.created_at) = CURDATE()
    LEFT JOIN services s ON ap.service_id = s.service_id
    LEFT JOIN assignments asgn ON asgn.appointment_id = ap.appointment_id
    LEFT JOIN users u ON asgn.technician_id = u.user_id
    WHERE DATE(q.added_at) = CURDATE()
    ORDER BY q.position ASC
")->fetchAll();

// Fetch clients and vehicles for dropdowns
$clients = $pdo->query("SELECT c.client_id, c.full_name, c.phone, c.email FROM clients c ORDER BY c.full_name")->fetchAll();
$vehicles = $pdo->query("SELECT vehicle_id, plate_number, make, model, year, client_id FROM vehicles ORDER BY plate_number")->fetchAll();
$services = $pdo->query("SELECT service_id, service_name, base_price FROM services ORDER BY service_name")->fetchAll();

// Fetch inventory for shop modal
$inventory = $pdo->query("SELECT item_id, item_name, category, image, unit_price, quantity FROM inventory WHERE quantity > 0 ORDER BY category, item_name")->fetchAll();

// Stats
$todayCount = count($walkins);
$waitingCount = 0;
$servingCount = 0;
$doneCount = 0;
foreach ($walkins as $w) {
    if ($w['status'] === 'Waiting') $waitingCount++;
    elseif ($w['status'] === 'Serving') $servingCount++;
    elseif ($w['status'] === 'Done') $doneCount++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> - VehiCare Admin</title>
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

            <?php if ($success): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <!-- Page header -->
            <div class="page-header">
                <h2><i class="fas fa-walking"></i> Walk-In Bookings</h2>
                <button class="btn btn-primary" onclick="openModal('addWalkinModal')">
                    <i class="fas fa-plus"></i> New Walk-In
                </button>
            </div>

            <!-- Summary cards -->
            <div style="display:flex;gap:1rem;margin-bottom:1.5rem;flex-wrap:wrap;">
                <div class="admin-card" style="flex:1;min-width:150px;padding:1.25rem;text-align:center;">
                    <div style="font-size:2rem;font-weight:700;color:#3498db;"><?= $todayCount ?></div>
                    <div style="color:#888;font-size:0.85rem;margin-top:0.25rem;"><i class="fas fa-walking"></i> Today's Walk-Ins</div>
                </div>
                <div class="admin-card" style="flex:1;min-width:150px;padding:1.25rem;text-align:center;">
                    <div style="font-size:2rem;font-weight:700;color:#f0ad4e;"><?= $waitingCount ?></div>
                    <div style="color:#888;font-size:0.85rem;margin-top:0.25rem;"><i class="fas fa-clock"></i> Waiting</div>
                </div>
                <div class="admin-card" style="flex:1;min-width:150px;padding:1.25rem;text-align:center;">
                    <div style="font-size:2rem;font-weight:700;color:#5bc0de;"><?= $servingCount ?></div>
                    <div style="color:#888;font-size:0.85rem;margin-top:0.25rem;"><i class="fas fa-cogs"></i> Being Served</div>
                </div>
                <div class="admin-card" style="flex:1;min-width:150px;padding:1.25rem;text-align:center;">
                    <div style="font-size:2rem;font-weight:700;color:#5cb85c;"><?= $doneCount ?></div>
                    <div style="color:#888;font-size:0.85rem;margin-top:0.25rem;"><i class="fas fa-check-circle"></i> Done</div>
                </div>
            </div>

            <!-- Walk-ins table -->
            <div class="admin-card">
                <div class="table-toolbar" style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap;">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="searchInput" placeholder="Search walk-ins..." onkeyup="searchTable()">
                    </div>
                    <select id="statusFilter" onchange="searchTable()" class="form-control" style="width:auto;min-width:150px;">
                        <option value="">All Statuses</option>
                        <option value="Waiting">Waiting</option>
                        <option value="Serving">Serving</option>
                        <option value="Done">Done</option>
                        <option value="Cancelled">Cancelled</option>
                    </select>
                </div>

                <?php if ($todayCount > 0): ?>
                <div class="table-responsive">
                    <table class="admin-table" id="walkinTable">
                        <thead>
                            <tr>
                                <th>Queue #</th>
                                <th>Client</th>
                                <th>Vehicle</th>
                                <th>Service</th>
                                <th>Technician</th>
                                <th>Status</th>
                                <th>Time</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($walkins as $row): ?>
                            <tr data-status="<?= htmlspecialchars($row['status']) ?>">
                                <td><strong>#<?= htmlspecialchars($row['position']) ?></strong></td>
                                <td>
                                    <strong><?= htmlspecialchars($row['client_name'] ?? 'N/A') ?></strong>
                                    <?php if (!empty($row['client_phone'])): ?>
                                        <br><small style="color:#888;"><i class="fas fa-phone"></i> <?= htmlspecialchars($row['client_phone']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars(($row['make'] ?? '') . ' ' . ($row['model'] ?? '')) ?>
                                    <?php if (!empty($row['plate_number'])): ?>
                                        <br><small style="color:#888;"><?= htmlspecialchars($row['plate_number']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($row['service_name'] ?? '—') ?></td>
                                <td>
                                    <?php if ($row['technician_name']): ?>
                                        <span style="color:#27ae60;font-weight:500;"><?= htmlspecialchars($row['technician_name']) ?></span>
                                    <?php else: ?>
                                        <span style="color:#e67e22;">Unassigned</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                        $badgeClass = 'badge-pending';
                                        if ($row['status'] === 'Waiting') $badgeClass = 'badge-pending';
                                        elseif ($row['status'] === 'Serving') $badgeClass = 'badge-approved';
                                        elseif ($row['status'] === 'Done') $badgeClass = 'badge-completed';
                                        elseif ($row['status'] === 'Cancelled') $badgeClass = 'badge-cancelled';
                                    ?>
                                    <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($row['status']) ?></span>
                                </td>
                                <td><?= date('h:i A', strtotime($row['added_at'])) ?></td>
                                <td class="action-btns">
                                    <form method="POST" style="display:inline">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="queue_id" value="<?= $row['queue_id'] ?>">
                                        <select name="status" class="form-control" onchange="this.form.submit()" style="width:auto;min-width:110px;padding:0.25rem 0.5rem;font-size:0.85rem;">
                                            <option value="Waiting" <?= $row['status'] === 'Waiting' ? 'selected' : '' ?>>Waiting</option>
                                            <option value="Serving" <?= $row['status'] === 'Serving' ? 'selected' : '' ?>>Serving</option>
                                            <option value="Done" <?= $row['status'] === 'Done' ? 'selected' : '' ?>>Done</option>
                                            <option value="Cancelled" <?= $row['status'] === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                        </select>
                                    </form>
                                    <!-- Shop button -->
                                    <button type="button" class="btn-icon" style="background:#8e44ad;color:#fff;" title="Shop for this customer" onclick="openShopModal(<?= (int)$row['client_id'] ?>, '<?= htmlspecialchars(addslashes($row['client_name'] ?? 'N/A'), ENT_QUOTES) ?>')">
                                        <i class="fas fa-shopping-cart"></i>
                                    </button>
                                    <form method="POST" style="display:inline" onsubmit="return confirm('Delete this walk-in entry?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="queue_id" value="<?= $row['queue_id'] ?>">
                                        <button type="submit" class="btn-icon btn-delete"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-walking"></i>
                    <h3>No walk-ins today</h3>
                    <p>Click "New Walk-In" to register a walk-in customer.</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- ============= ADD WALK-IN MODAL ============= -->
            <div class="modal-overlay" id="addWalkinModal">
                <div class="modal" style="max-width:650px;">
                    <div class="modal-header" style="background:linear-gradient(135deg,#2c3e50,#34495e);">
                        <h3><i class="fas fa-walking"></i> New Walk-In Booking</h3>
                        <button class="modal-close" onclick="closeModal('addWalkinModal')">&times;</button>
                    </div>
                    <form method="POST" id="walkinForm">
                        <input type="hidden" name="action" value="add_walkin">
                        <div class="modal-body" style="max-height:65vh;overflow-y:auto;">

                            <!-- Customer Section -->
                            <div style="background:#f8f9fa;border-radius:10px;padding:18px;margin-bottom:18px;">
                                <h4 style="margin:0 0 14px 0;font-size:1rem;color:#2c3e50;">
                                    <i class="fas fa-user" style="color:#3498db;"></i> Customer Information
                                </h4>
                                <div style="display:flex;gap:8px;margin-bottom:14px;">
                                    <button type="button" class="btn btn-primary btn-sm toggle-btn active" data-target="new" onclick="toggleCustomerType('new')">
                                        <i class="fas fa-user-plus"></i> New Customer
                                    </button>
                                    <button type="button" class="btn btn-secondary btn-sm toggle-btn" data-target="existing" onclick="toggleCustomerType('existing')">
                                        <i class="fas fa-users"></i> Existing Customer
                                    </button>
                                </div>
                                <input type="hidden" name="customer_type" id="customer_type" value="new">

                                <!-- New customer fields -->
                                <div id="newCustomerFields">
                                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                                        <div class="form-group" style="margin-bottom:0;">
                                            <label>Full Name <span style="color:red;">*</span></label>
                                            <input type="text" name="full_name" class="form-control" placeholder="e.g. Juan Dela Cruz">
                                        </div>
                                        <div class="form-group" style="margin-bottom:0;">
                                            <label>Email <span style="color:red;">*</span></label>
                                            <input type="email" name="email" class="form-control" placeholder="e.g. juan@email.com">
                                        </div>
                                        <div class="form-group" style="margin-bottom:0;">
                                            <label>Phone <span style="color:red;">*</span></label>
                                            <input type="text" name="phone" class="form-control" placeholder="e.g. 09171234567">
                                        </div>
                                        <div class="form-group" style="margin-bottom:0;">
                                            <label>Address</label>
                                            <input type="text" name="address" class="form-control" placeholder="e.g. Taguig City">
                                        </div>
                                    </div>
                                    <p style="margin:10px 0 0;font-size:0.8rem;color:#888;">
                                        <i class="fas fa-info-circle"></i> Default password will be the phone number. Customer can change it later.
                                    </p>
                                </div>

                                <!-- Existing customer dropdown -->
                                <div id="existingCustomerFields" style="display:none;">
                                    <div class="form-group" style="margin-bottom:0;">
                                        <label>Select Customer</label>
                                        <select name="client_id" id="sel_client_id" class="form-control" onchange="onClientChange()">
                                            <option value="">-- Select Customer --</option>
                                            <?php foreach ($clients as $c): ?>
                                                <option value="<?= $c['client_id'] ?>" data-phone="<?= htmlspecialchars($c['phone']) ?>" data-email="<?= htmlspecialchars($c['email'] ?? '') ?>">
                                                    <?= htmlspecialchars($c['full_name']) ?> (<?= htmlspecialchars($c['phone']) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- Vehicle Section -->
                            <div style="background:#f8f9fa;border-radius:10px;padding:18px;margin-bottom:18px;">
                                <h4 style="margin:0 0 14px 0;font-size:1rem;color:#2c3e50;">
                                    <i class="fas fa-car" style="color:#e67e22;"></i> Vehicle Information
                                </h4>
                                <div style="display:flex;gap:8px;margin-bottom:14px;">
                                    <button type="button" class="btn btn-primary btn-sm vehicle-toggle-btn active" data-target="new" onclick="toggleVehicleType('new')">
                                        <i class="fas fa-plus-circle"></i> New Vehicle
                                    </button>
                                    <button type="button" class="btn btn-secondary btn-sm vehicle-toggle-btn" data-target="existing" onclick="toggleVehicleType('existing')">
                                        <i class="fas fa-car"></i> Existing Vehicle
                                    </button>
                                </div>
                                <input type="hidden" name="vehicle_type" id="vehicle_type" value="new">

                                <!-- New vehicle fields -->
                                <div id="newVehicleFields">
                                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                                        <div class="form-group" style="margin-bottom:0;">
                                            <label>Plate Number <span style="color:red;">*</span></label>
                                            <input type="text" name="plate_number" class="form-control" placeholder="e.g. ABC 1234" style="text-transform:uppercase;">
                                        </div>
                                        <div class="form-group" style="margin-bottom:0;">
                                            <label>Make <span style="color:red;">*</span></label>
                                            <input type="text" name="make" class="form-control" placeholder="e.g. Toyota">
                                        </div>
                                        <div class="form-group" style="margin-bottom:0;">
                                            <label>Model <span style="color:red;">*</span></label>
                                            <input type="text" name="model" class="form-control" placeholder="e.g. Vios">
                                        </div>
                                        <div class="form-group" style="margin-bottom:0;">
                                            <label>Year</label>
                                            <input type="number" name="year" class="form-control" placeholder="e.g. 2024" min="1990" max="2030">
                                        </div>
                                        <div class="form-group" style="margin-bottom:0;grid-column:1/-1;">
                                            <label>Color</label>
                                            <input type="text" name="color" class="form-control" placeholder="e.g. Black">
                                        </div>
                                    </div>
                                </div>

                                <!-- Existing vehicle dropdown -->
                                <div id="existingVehicleFields" style="display:none;">
                                    <div class="form-group" style="margin-bottom:0;">
                                        <label>Select Vehicle</label>
                                        <select name="vehicle_id" id="sel_vehicle_id" class="form-control">
                                            <option value="">-- Select Vehicle --</option>
                                            <?php foreach ($vehicles as $v): ?>
                                                <option value="<?= $v['vehicle_id'] ?>" data-client="<?= $v['client_id'] ?>">
                                                    <?= htmlspecialchars($v['plate_number'] . ' — ' . $v['make'] . ' ' . $v['model'] . ($v['year'] ? ' (' . $v['year'] . ')' : '')) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <p id="noVehiclesMsg" style="display:none;margin:10px 0 0;font-size:0.85rem;color:#e74c3c;">
                                        <i class="fas fa-exclamation-triangle"></i> No vehicles found for this customer. Switch to "New Vehicle".
                                    </p>
                                </div>
                            </div>

                            <!-- Service & Notes -->
                            <div style="background:#f8f9fa;border-radius:10px;padding:18px;">
                                <h4 style="margin:0 0 14px 0;font-size:1rem;color:#2c3e50;">
                                    <i class="fas fa-wrench" style="color:#27ae60;"></i> Service & Notes
                                </h4>
                                <div class="form-group">
                                    <label>Service</label>
                                    <select name="service_id" class="form-control">
                                        <option value="">-- Select Service (optional) --</option>
                                        <?php foreach ($services as $svc): ?>
                                            <option value="<?= $svc['service_id'] ?>"><?= htmlspecialchars($svc['service_name']) ?> — ₱<?= number_format($svc['base_price'], 2) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group" style="margin-bottom:0;">
                                    <label>Notes</label>
                                    <textarea name="notes" class="form-control" rows="2" placeholder="Any additional notes..."></textarea>
                                </div>
                            </div>

                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="closeModal('addWalkinModal')">Cancel</button>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Submit Walk-In</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ============= SHOP MODAL ============= -->
            <div class="modal-overlay" id="shopModal">
                <div class="modal" style="max-width:900px;width:95%;">
                    <div class="modal-header" style="background:linear-gradient(135deg,#8e44ad,#9b59b6);">
                        <h3><i class="fas fa-shopping-cart"></i> Shop — <span id="shopClientName"></span></h3>
                        <button class="modal-close" onclick="closeModal('shopModal')">&times;</button>
                    </div>
                    <form method="POST" enctype="multipart/form-data" onsubmit="return submitShopOrder()">
                        <input type="hidden" name="action" value="place_order">
                        <input type="hidden" name="order_client_id" id="order_client_id" value="">
                        <input type="hidden" name="order_items" id="order_items_json" value="[]">
                        <div class="modal-body" style="max-height:65vh;overflow-y:auto;padding:0;">
                            <div style="display:flex;gap:0;min-height:400px;">

                                <!-- Product browsing panel -->
                                <div style="flex:1.5;padding:18px;border-right:1px solid #eee;overflow-y:auto;max-height:65vh;">
                                    <div style="display:flex;gap:8px;margin-bottom:14px;">
                                        <div class="search-box" style="flex:1;">
                                            <i class="fas fa-search"></i>
                                            <input type="text" id="shopSearch" placeholder="Search products..." oninput="filterShopProducts()">
                                        </div>
                                        <select id="shopCatFilter" class="form-control" style="width:auto;min-width:130px;" onchange="filterShopProducts()">
                                            <option value="">All Categories</option>
                                            <?php
                                            $cats = [];
                                            foreach ($inventory as $inv) {
                                                if ($inv['category'] && !in_array($inv['category'], $cats)) {
                                                    $cats[] = $inv['category'];
                                                    echo '<option value="' . htmlspecialchars($inv['category']) . '">' . htmlspecialchars($inv['category']) . '</option>';
                                                }
                                            }
                                            ?>
                                        </select>
                                    </div>
                                    <div id="shopProductGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;">
                                        <?php foreach ($inventory as $inv): ?>
                                        <div class="shop-product-card" data-id="<?= $inv['item_id'] ?>" data-name="<?= htmlspecialchars($inv['item_name']) ?>" data-cat="<?= htmlspecialchars($inv['category'] ?? '') ?>" data-price="<?= $inv['unit_price'] ?>" data-stock="<?= $inv['quantity'] ?>" style="border:1px solid #eee;border-radius:10px;overflow:hidden;cursor:pointer;transition:all .2s;" onclick="addToShopCart(<?= $inv['item_id'] ?>, '<?= htmlspecialchars(addslashes($inv['item_name']), ENT_QUOTES) ?>', <?= $inv['unit_price'] ?>, <?= $inv['quantity'] ?>)">
                                            <div style="height:100px;background:#f5f5f5;display:flex;align-items:center;justify-content:center;overflow:hidden;">
                                                <?php if ($inv['image']): ?>
                                                    <img src="../uploads/<?= htmlspecialchars($inv['image']) ?>" alt="" style="max-height:100%;max-width:100%;object-fit:cover;">
                                                <?php else: ?>
                                                    <i class="fas fa-box" style="font-size:2rem;color:#ccc;"></i>
                                                <?php endif; ?>
                                            </div>
                                            <div style="padding:8px 10px;">
                                                <div style="font-weight:600;font-size:0.85rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= htmlspecialchars($inv['item_name']) ?>"><?= htmlspecialchars($inv['item_name']) ?></div>
                                                <div style="color:#8e44ad;font-weight:700;font-size:0.9rem;">₱<?= number_format($inv['unit_price'], 2) ?></div>
                                                <div style="font-size:0.75rem;color:#888;">Stock: <?= $inv['quantity'] ?></div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if (empty($inventory)): ?>
                                    <div style="text-align:center;padding:40px;color:#888;">
                                        <i class="fas fa-box-open" style="font-size:2.5rem;margin-bottom:10px;"></i>
                                        <p>No products in stock.</p>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Cart sidebar -->
                                <div style="flex:1;padding:18px;display:flex;flex-direction:column;background:#fafafa;">
                                    <h4 style="margin:0 0 12px;font-size:1rem;color:#2c3e50;"><i class="fas fa-shopping-basket" style="color:#8e44ad;"></i> Cart</h4>
                                    <div id="shopCartItems" style="flex:1;overflow-y:auto;min-height:120px;">
                                        <div id="shopCartEmpty" style="text-align:center;padding:30px;color:#aaa;">
                                            <i class="fas fa-cart-plus" style="font-size:2rem;margin-bottom:8px;"></i>
                                            <p style="margin:0;">Click products to add</p>
                                        </div>
                                    </div>

                                    <!-- Totals -->
                                    <div style="border-top:2px solid #eee;padding-top:12px;margin-top:12px;">
                                        <div style="display:flex;justify-content:space-between;margin-bottom:4px;font-size:0.9rem;">
                                            <span>Subtotal:</span><span id="shopSubtotal">₱0.00</span>
                                        </div>
                                        <div style="display:flex;justify-content:space-between;margin-bottom:4px;font-size:0.9rem;color:#888;">
                                            <span>Tax (12%):</span><span id="shopTax">₱0.00</span>
                                        </div>
                                        <div style="display:flex;justify-content:space-between;font-weight:700;font-size:1.1rem;color:#8e44ad;">
                                            <span>Total:</span><span id="shopTotal">₱0.00</span>
                                        </div>
                                    </div>

                                    <!-- Payment method -->
                                    <div style="margin-top:14px;">
                                        <label style="font-weight:600;font-size:0.85rem;margin-bottom:4px;display:block;">Payment Method</label>
                                        <select name="payment_method" id="shopPayMethod" class="form-control" onchange="toggleShopReceipt()">
                                            <option value="Cash">Cash</option>
                                            <option value="GCash">GCash</option>
                                            <option value="Maya">Maya</option>
                                        </select>
                                    </div>
                                    <div id="shopReceiptField" style="display:none;margin-top:10px;">
                                        <label style="font-weight:600;font-size:0.85rem;margin-bottom:4px;display:block;">Receipt Image</label>
                                        <input type="file" name="receipt_image" accept="image/*" class="form-control" style="font-size:0.85rem;">
                                    </div>
                                    <div style="margin-top:10px;">
                                        <label style="font-weight:600;font-size:0.85rem;margin-bottom:4px;display:block;">Notes</label>
                                        <textarea name="order_notes" class="form-control" rows="2" placeholder="Order notes..." style="font-size:0.85rem;"></textarea>
                                    </div>
                                </div>

                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="closeModal('shopModal')">Cancel</button>
                            <button type="submit" class="btn btn-primary" id="shopSubmitBtn" disabled style="background:#8e44ad;border-color:#8e44ad;">
                                <i class="fas fa-receipt"></i> Place Order
                            </button>
                        </div>
                    </form>
                </div>
            </div>

        </div>
    </main>
</div>

<script src="includes/admin.js"></script>
<script>
function openModal(id) { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }

// Customer type toggle
function toggleCustomerType(type) {
    document.getElementById('customer_type').value = type;
    document.getElementById('newCustomerFields').style.display = type === 'new' ? '' : 'none';
    document.getElementById('existingCustomerFields').style.display = type === 'existing' ? '' : 'none';

    document.querySelectorAll('.toggle-btn').forEach(function(b) {
        b.className = b.getAttribute('data-target') === type
            ? 'btn btn-primary btn-sm toggle-btn active'
            : 'btn btn-secondary btn-sm toggle-btn';
    });

    if (type === 'existing') {
        toggleVehicleType('existing');
        onClientChange();
    } else {
        toggleVehicleType('new');
    }
}

// Vehicle type toggle
function toggleVehicleType(type) {
    document.getElementById('vehicle_type').value = type;
    document.getElementById('newVehicleFields').style.display = type === 'new' ? '' : 'none';
    document.getElementById('existingVehicleFields').style.display = type === 'existing' ? '' : 'none';

    document.querySelectorAll('.vehicle-toggle-btn').forEach(function(b) {
        b.className = b.getAttribute('data-target') === type
            ? 'btn btn-primary btn-sm vehicle-toggle-btn active'
            : 'btn btn-secondary btn-sm vehicle-toggle-btn';
    });
}

// Filter vehicles by selected client
function onClientChange() {
    var clientId = document.getElementById('sel_client_id').value;
    var vehicleSelect = document.getElementById('sel_vehicle_id');
    var options = vehicleSelect.querySelectorAll('option[data-client]');
    var hasOptions = false;

    vehicleSelect.value = '';
    options.forEach(function(opt) {
        if (!clientId || opt.getAttribute('data-client') === clientId) {
            opt.style.display = '';
            hasOptions = true;
        } else {
            opt.style.display = 'none';
        }
    });

    document.getElementById('noVehiclesMsg').style.display = (clientId && !hasOptions) ? '' : 'none';
}

// Search and filter table
function searchTable() {
    var q = document.getElementById('searchInput').value.toLowerCase();
    var sf = document.getElementById('statusFilter').value;
    var table = document.getElementById('walkinTable');
    if (!table) return;
    var rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
    for (var i = 0; i < rows.length; i++) {
        var text = rows[i].textContent.toLowerCase();
        var rowStatus = rows[i].getAttribute('data-status');
        rows[i].style.display = (text.indexOf(q) > -1 && (!sf || rowStatus === sf)) ? '' : 'none';
    }
}

// Close modal on overlay click
document.querySelectorAll('.modal-overlay').forEach(function(overlay) {
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) overlay.classList.remove('active');
    });
});

// ============= SHOP FUNCTIONS =============
var shopCart = {};

function openShopModal(clientId, clientName) {
    shopCart = {};
    document.getElementById('order_client_id').value = clientId;
    document.getElementById('shopClientName').textContent = clientName;
    document.getElementById('shopPayMethod').value = 'Cash';
    toggleShopReceipt();
    renderShopCart();
    openModal('shopModal');
}

function filterShopProducts() {
    var q = document.getElementById('shopSearch').value.toLowerCase();
    var cat = document.getElementById('shopCatFilter').value;
    var cards = document.querySelectorAll('.shop-product-card');
    cards.forEach(function(card) {
        var name = card.getAttribute('data-name').toLowerCase();
        var cardCat = card.getAttribute('data-cat');
        var matchQ = !q || name.indexOf(q) > -1;
        var matchCat = !cat || cardCat === cat;
        card.style.display = (matchQ && matchCat) ? '' : 'none';
    });
}

function addToShopCart(itemId, name, price, stock) {
    if (shopCart[itemId]) {
        if (shopCart[itemId].qty < stock) {
            shopCart[itemId].qty++;
        }
    } else {
        shopCart[itemId] = { item_id: itemId, name: name, price: price, stock: stock, qty: 1 };
    }
    renderShopCart();
}

function removeFromShopCart(itemId) {
    delete shopCart[itemId];
    renderShopCart();
}

function updateShopQty(itemId, delta) {
    if (!shopCart[itemId]) return;
    var newQty = shopCart[itemId].qty + delta;
    if (newQty < 1) {
        removeFromShopCart(itemId);
        return;
    }
    if (newQty > shopCart[itemId].stock) return;
    shopCart[itemId].qty = newQty;
    renderShopCart();
}

function renderShopCart() {
    var container = document.getElementById('shopCartItems');
    var keys = Object.keys(shopCart);

    if (keys.length === 0) {
        container.innerHTML = '<div id="shopCartEmpty" style="text-align:center;padding:30px;color:#aaa;"><i class="fas fa-cart-plus" style="font-size:2rem;margin-bottom:8px;"></i><p style="margin:0;">Click products to add</p></div>';
        document.getElementById('shopSubmitBtn').disabled = true;
    } else {
        var html = '';
        keys.forEach(function(id) {
            var item = shopCart[id];
            var lineTotal = item.price * item.qty;
            html += '<div style="display:flex;align-items:center;gap:8px;padding:8px;border-bottom:1px solid #eee;">'
                + '<div style="flex:1;min-width:0;">'
                + '<div style="font-weight:600;font-size:0.85rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + item.name + '</div>'
                + '<div style="font-size:0.8rem;color:#888;">₱' + item.price.toFixed(2) + ' each</div>'
                + '</div>'
                + '<div style="display:flex;align-items:center;gap:4px;">'
                + '<button type="button" onclick="updateShopQty(' + id + ',-1)" style="width:24px;height:24px;border:1px solid #ddd;border-radius:4px;cursor:pointer;background:#fff;font-weight:700;">−</button>'
                + '<span style="min-width:24px;text-align:center;font-weight:600;">' + item.qty + '</span>'
                + '<button type="button" onclick="updateShopQty(' + id + ',1)" style="width:24px;height:24px;border:1px solid #ddd;border-radius:4px;cursor:pointer;background:#fff;font-weight:700;">+</button>'
                + '</div>'
                + '<div style="min-width:70px;text-align:right;font-weight:600;color:#8e44ad;">₱' + lineTotal.toFixed(2) + '</div>'
                + '<button type="button" onclick="removeFromShopCart(' + id + ')" style="background:none;border:none;color:#e74c3c;cursor:pointer;font-size:1rem;" title="Remove"><i class="fas fa-times"></i></button>'
                + '</div>';
        });
        container.innerHTML = html;
        document.getElementById('shopSubmitBtn').disabled = false;
    }

    updateShopTotals();
}

function updateShopTotals() {
    var subtotal = 0;
    var itemsArr = [];
    Object.keys(shopCart).forEach(function(id) {
        var item = shopCart[id];
        subtotal += item.price * item.qty;
        itemsArr.push({ item_id: item.item_id, qty: item.qty });
    });
    var tax = Math.round(subtotal * 0.12 * 100) / 100;
    var total = subtotal + tax;

    document.getElementById('shopSubtotal').textContent = '₱' + subtotal.toFixed(2);
    document.getElementById('shopTax').textContent = '₱' + tax.toFixed(2);
    document.getElementById('shopTotal').textContent = '₱' + total.toFixed(2);
    document.getElementById('order_items_json').value = JSON.stringify(itemsArr);
}

function toggleShopReceipt() {
    var method = document.getElementById('shopPayMethod').value;
    document.getElementById('shopReceiptField').style.display = (method === 'GCash' || method === 'Maya') ? '' : 'none';
}

function submitShopOrder() {
    var keys = Object.keys(shopCart);
    if (keys.length === 0) {
        alert('Please add at least one product to the cart.');
        return false;
    }
    updateShopTotals();
    return true;
}
</script>
</body>
</html>
