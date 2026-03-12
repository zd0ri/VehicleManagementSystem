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

$success = '';
$error   = '';

// ── POST handlers ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // ── Book Appointment ──
    if ($action === 'book') {
        $vehicle_id       = (int) ($_POST['vehicle_id'] ?? 0);
        $service_id       = (int) ($_POST['service_id'] ?? 0);
        $appointment_date = trim($_POST['appointment_date'] ?? '');
        $notes            = trim($_POST['notes'] ?? '');

        if (!$vehicle_id || !$appointment_date || !$service_id) {
            $error = 'Please fill in all required fields.';
        } else {
            // Verify the vehicle belongs to this client
            $chk = $pdo->prepare("SELECT vehicle_id FROM vehicles WHERE vehicle_id = ? AND client_id = ?");
            $chk->execute([$vehicle_id, $client_id]);
            if (!$chk->fetch()) {
                $error = 'Invalid vehicle selected.';
            } else {
                // Get service duration for time-slot conflict check
                $durStmt = $pdo->prepare("SELECT COALESCE(estimated_duration, 60) FROM services WHERE service_id = ?");
                $durStmt->execute([$service_id]);
                $svcDuration = (int)$durStmt->fetchColumn() ?: 60;

                $reqStart = strtotime($appointment_date);
                $reqEnd   = $reqStart + ($svcDuration * 60);

                // Check for overlapping appointments
                $overlapStmt = $pdo->prepare("
                    SELECT ap.appointment_id, ap.appointment_date, COALESCE(s.estimated_duration, 60) AS duration
                    FROM appointments ap
                    LEFT JOIN services s ON ap.service_id = s.service_id
                    WHERE DATE(ap.appointment_date) = DATE(?)
                      AND ap.status IN ('Pending','Approved')
                ");
                $overlapStmt->execute([$appointment_date]);
                $timeConflict = false;
                foreach ($overlapStmt->fetchAll() as $existing) {
                    $exStart = strtotime($existing['appointment_date']);
                    $exEnd   = $exStart + ($existing['duration'] * 60);
                    if ($reqStart < $exEnd && $reqEnd > $exStart) {
                        $suggestTime = date('h:i A', $exEnd);
                        $error = 'This time slot is already booked. The earliest available time after this slot is ' . $suggestTime . '. Please choose a different time.';
                        $timeConflict = true;
                        break;
                    }
                }

                if (!$timeConflict) {
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
                        // Auto-assign: create appointment as Approved
                        $stmt = $pdo->prepare("INSERT INTO appointments (client_id, vehicle_id, service_id, appointment_date, status, notes, created_by) VALUES (?, ?, ?, ?, 'Approved', ?, ?)");
                        $stmt->execute([$client_id, $vehicle_id, $service_id, $appointment_date, $notes, $user_id]);
                        $appointment_id = $pdo->lastInsertId();

                        // Create assignment for technician
                        $stmt = $pdo->prepare("INSERT INTO assignments (appointment_id, vehicle_id, technician_id, service_id, status) VALUES (?, ?, ?, ?, 'Assigned')");
                        $stmt->execute([$appointment_id, $vehicle_id, $free_tech['user_id'], $service_id]);

                        $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'New Service Assignment', ?, 'new_assignment')")
                            ->execute([$free_tech['user_id'], 'You have been assigned: ' . $svc_name . '. Scheduled for ' . date('M d, Y h:i A', strtotime($appointment_date)) . '.']);

                        $pdo->commit();
                        $success = 'Appointment booked and assigned to technician ' . htmlspecialchars($free_tech['full_name']) . '!';
                    } else {
                        // All technicians have ongoing work — queue with assigned tech info
                        $assigned_tech = $least_busy_tech;
                        $stmt = $pdo->prepare("INSERT INTO appointments (client_id, vehicle_id, service_id, appointment_date, status, notes, created_by) VALUES (?, ?, ?, ?, 'Pending', ?, ?)");
                        $stmt->execute([$client_id, $vehicle_id, $service_id, $appointment_date, $notes, $user_id]);

                        $next_pos = (int) $pdo->query("SELECT COALESCE(MAX(position), 0) + 1 FROM queue WHERE status IN ('Waiting','Serving')")->fetchColumn();
                        $pdo->prepare("INSERT INTO queue (vehicle_id, client_id, position, status) VALUES (?, ?, ?, 'Waiting')")
                            ->execute([$vehicle_id, $client_id, $next_pos]);

                        $tech_name = $assigned_tech ? htmlspecialchars($assigned_tech['full_name']) : 'a technician';
                        $queueMsg = 'All technicians are currently busy with ongoing services. You have been placed in queue position #' . $next_pos . '. Your assigned technician will be ' . $tech_name . '. We will notify you once they are available.';

                        $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'Appointment Queued', ?, 'queue')")
                            ->execute([$user_id, $queueMsg]);

                        $pdo->commit();
                        $error = $queueMsg;
                    }
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = 'Failed to book appointment: ' . $e->getMessage();
                }
                }
            }
        }
    }

    // ── Add Vehicle ──
    if ($action === 'add_vehicle') {
        $plate  = strtoupper(trim($_POST['plate_number'] ?? ''));
        $make   = trim($_POST['make'] ?? '');
        $model  = trim($_POST['model'] ?? '');
        $year   = (int) ($_POST['year'] ?? date('Y'));
        $color  = trim($_POST['color'] ?? '');

        if (!$plate || !$make || !$model) {
            $error = 'Plate number, make, and model are required.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO vehicles (client_id, plate_number, make, model, year, color, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
            $stmt->execute([$client_id, $plate, $make, $model, $year, $color]);
            $success = 'Vehicle added successfully!';
        }
    }

    // ── Add to Cart ──
    if ($action === 'add_to_cart') {
        $service_id = (int) ($_POST['service_id'] ?? 0);
        if ($service_id) {
            // Check if already in cart
            $chk = $pdo->prepare("SELECT cart_id FROM cart WHERE client_id = ? AND service_id = ?");
            $chk->execute([$client_id, $service_id]);
            if ($chk->fetch()) {
                $success = 'Service is already in your cart.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO cart (client_id, service_id, quantity) VALUES (?, ?, 1)");
                $stmt->execute([$client_id, $service_id]);
                $success = 'Service added to cart!';
            }
        }
    }

    // ── Cancel Appointment ──
    if ($action === 'cancel') {
        $appointment_id = (int) ($_POST['appointment_id'] ?? 0);

        try {
            $pdo->beginTransaction();

            // Get appointment info before cancelling
            $apptInfo = $pdo->prepare("SELECT a.vehicle_id, a.service_id, s.service_name
                FROM appointments a
                LEFT JOIN services s ON a.service_id = s.service_id
                WHERE a.appointment_id = ? AND a.client_id = ? AND a.status = 'Pending'");
            $apptInfo->execute([$appointment_id, $client_id]);
            $apptData = $apptInfo->fetch();

            if ($apptData) {
                // Cancel the appointment
                $pdo->prepare("UPDATE appointments SET status = 'Cancelled' WHERE appointment_id = ?")->execute([$appointment_id]);

                // Cancel related assignments
                $asgnStmt = $pdo->prepare("SELECT assignment_id, technician_id FROM assignments WHERE appointment_id = ? AND status IN ('Assigned','Ongoing')");
                $asgnStmt->execute([$appointment_id]);
                $assignments = $asgnStmt->fetchAll();
                if (!empty($assignments)) {
                    $pdo->prepare("DELETE FROM assignments WHERE appointment_id = ? AND status IN ('Assigned','Ongoing')")->execute([$appointment_id]);
                    foreach ($assignments as $a) {
                        $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'Appointment Cancelled', ?, 'cancellation')")
                            ->execute([$a['technician_id'], 'Appointment #' . $appointment_id . ' for ' . ($apptData['service_name'] ?? 'a service') . ' was cancelled by the customer.']);
                    }
                }

                // Cancel related queue entry
                $queueStmt = $pdo->prepare("SELECT queue_id, position FROM queue WHERE client_id = ? AND vehicle_id = ? AND status IN ('Waiting','Serving')");
                $queueStmt->execute([$client_id, $apptData['vehicle_id']]);
                $queueEntry = $queueStmt->fetch();
                if ($queueEntry) {
                    $pdo->prepare("UPDATE queue SET status = 'Cancelled' WHERE queue_id = ?")->execute([$queueEntry['queue_id']]);
                    $pdo->prepare("UPDATE queue SET position = position - 1 WHERE position > ? AND status IN ('Waiting','Serving') ORDER BY position ASC")->execute([$queueEntry['position']]);
                }

                // Notify admins
                $admins = $pdo->query("SELECT user_id FROM users WHERE role = 'admin' AND status = 'active'")->fetchAll();
                foreach ($admins as $adm) {
                    $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'Appointment Cancelled by Customer', ?, 'cancellation')")
                        ->execute([$adm['user_id'], $full_name . ' cancelled appointment #' . $appointment_id . ' for ' . ($apptData['service_name'] ?? 'a service') . '.']);
                }

                $pdo->commit();
                $success = 'Appointment cancelled successfully.';
            } else {
                $pdo->rollBack();
                $error = 'Appointment not found or cannot be cancelled.';
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Failed to cancel appointment: ' . $e->getMessage();
        }
    }
}

// ── Fetch services ──
$services = $pdo->query("SELECT * FROM services ORDER BY service_name ASC")->fetchAll();

// ── Fetch customer vehicles ──
$vehicles = $pdo->prepare("SELECT * FROM vehicles WHERE client_id = ? AND status = 'active' ORDER BY make, model");
$vehicles->execute([$client_id]);
$vehicles = $vehicles->fetchAll();

// ── Fetch existing appointments ──
$appts = $pdo->prepare("
    SELECT a.appointment_id, a.appointment_date, a.status, a.created_at,
           v.plate_number, v.make, v.model, v.year,
           s.service_name,
           tech.full_name AS technician_name
    FROM appointments a
    JOIN vehicles v ON a.vehicle_id = v.vehicle_id
    LEFT JOIN services s ON a.service_id = s.service_id
    LEFT JOIN assignments asgn ON asgn.appointment_id = a.appointment_id
    LEFT JOIN users tech ON asgn.technician_id = tech.user_id
    WHERE a.client_id = ?
    ORDER BY a.appointment_date DESC
");
$appts->execute([$client_id]);
$appointments = $appts->fetchAll();

// ── Unread notifications ──
$notifStmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 5");
$notifStmt->execute([$user_id]);
$unread_notifs = $notifStmt->fetchAll();
$notifCount = count($unread_notifs);

// ── Cart count for badge ──
$cartStmt = $pdo->prepare("SELECT COUNT(*) FROM cart WHERE client_id = ?");
$cartStmt->execute([$client_id]);
$cartCount = $cartStmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book a Service - VehiCare</title>
    <link rel="stylesheet" href="../includes/style/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700;900&family=Oswald:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ── Page Layout ── */
        .book-section {
            padding: 60px 0;
            min-height: 60vh;
            background: #f8f9fa;
        }
        .book-section .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }
        .page-title {
            font-family: 'Oswald', sans-serif;
            font-size: 32px;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .page-title i { color: #e74c3c; }
        .page-subtitle {
            color: #7f8c8d;
            font-size: 15px;
            margin-bottom: 35px;
        }

        /* ── Alert Messages ── */
        .alert {
            padding: 14px 20px;
            border-radius: 8px;
            margin-bottom: 25px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* ── Services Grid ── */
        .section-heading {
            font-family: 'Oswald', sans-serif;
            font-size: 22px;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e74c3c;
            display: inline-block;
        }
        .services-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 25px;
            margin-bottom: 50px;
        }
        .service-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 20px rgba(0,0,0,0.06);
            overflow: hidden;
            transition: transform 0.3s, box-shadow 0.3s;
            display: flex;
            flex-direction: column;
        }
        .service-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
        }
        .service-card-header {
            background: linear-gradient(135deg, #2c3e50, #34495e);
            color: #fff;
            padding: 25px 20px;
            text-align: center;
        }
        .service-card-header i {
            font-size: 36px;
            margin-bottom: 10px;
            color: #e74c3c;
        }
        .service-card-header h3 {
            font-family: 'Oswald', sans-serif;
            font-size: 18px;
            font-weight: 600;
            margin: 0;
        }
        .service-card-body {
            padding: 20px;
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        .service-card-body .description {
            font-size: 13px;
            color: #666;
            line-height: 1.6;
            flex: 1;
            margin-bottom: 15px;
        }
        .service-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-top: 12px;
            border-top: 1px solid #eee;
        }
        .service-price {
            font-family: 'Oswald', sans-serif;
            font-size: 22px;
            font-weight: 700;
            color: #e74c3c;
        }
        .service-duration {
            font-size: 13px;
            color: #888;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .service-card-actions {
            display: flex;
            gap: 8px;
        }
        .btn-book-now {
            flex: 1;
            padding: 10px 16px;
            background: #e74c3c;
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s;
            text-align: center;
        }
        .btn-book-now:hover { background: #c0392b; }
        .btn-add-cart {
            padding: 10px 14px;
            background: #2c3e50;
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 13px;
            cursor: pointer;
            transition: background 0.3s;
        }
        .btn-add-cart:hover { background: #1a252f; }

        /* ── Booking Modal Overlay ── */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.6);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s;
        }
        .modal-overlay.active { display: flex; }
        .modal-content {
            background: #fff;
            border-radius: 14px;
            width: 560px;
            max-width: 95%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: slideUp 0.3s;
        }
        .modal-header {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            color: #fff;
            padding: 22px 25px;
            border-radius: 14px 14px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-header h2 {
            font-family: 'Oswald', sans-serif;
            font-size: 22px;
            margin: 0;
        }
        .modal-close {
            background: rgba(255,255,255,0.2);
            border: none;
            color: #fff;
            width: 36px; height: 36px;
            border-radius: 50%;
            font-size: 18px;
            cursor: pointer;
            transition: background 0.3s;
        }
        .modal-close:hover { background: rgba(255,255,255,0.3); }
        .modal-body { padding: 25px; }
        .form-group {
            margin-bottom: 18px;
        }
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 6px;
        }
        .form-group label .required { color: #e74c3c; }
        .form-control {
            width: 100%;
            padding: 11px 14px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            font-family: 'Roboto', sans-serif;
            transition: border-color 0.3s, box-shadow 0.3s;
            box-sizing: border-box;
        }
        .form-control:focus {
            outline: none;
            border-color: #e74c3c;
            box-shadow: 0 0 0 3px rgba(231,76,60,0.1);
        }
        textarea.form-control { resize: vertical; min-height: 80px; }
        .btn-submit {
            width: 100%;
            padding: 13px;
            background: #e74c3c;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 700;
            font-family: 'Oswald', sans-serif;
            letter-spacing: 0.5px;
            cursor: pointer;
            transition: background 0.3s;
        }
        .btn-submit:hover { background: #c0392b; }
        .no-vehicles-msg {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeeba;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .btn-show-vehicle-form {
            background: #2c3e50;
            color: #fff;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 13px;
            cursor: pointer;
            margin-left: auto;
            transition: background 0.3s;
        }
        .btn-show-vehicle-form:hover { background: #1a252f; }

        /* ── Add Vehicle Panel ── */
        .vehicle-form-panel {
            display: none;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .vehicle-form-panel.active { display: block; }
        .vehicle-form-panel h4 {
            font-family: 'Oswald', sans-serif;
            font-size: 17px;
            color: #2c3e50;
            margin: 0 0 15px;
        }
        .vehicle-form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        .vehicle-form-grid .full-width { grid-column: 1 / -1; }
        .btn-add-vehicle {
            width: 100%;
            padding: 10px;
            background: #27ae60;
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s;
        }
        .btn-add-vehicle:hover { background: #219a52; }

        /* ── Appointments Table ── */
        .appointments-section { margin-top: 50px; }
        .appt-table-wrapper {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 20px rgba(0,0,0,0.06);
            overflow: hidden;
        }
        .appt-table {
            width: 100%;
            border-collapse: collapse;
        }
        .appt-table thead {
            background: #2c3e50;
            color: #fff;
        }
        .appt-table thead th {
            padding: 14px 18px;
            font-family: 'Oswald', sans-serif;
            font-weight: 500;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            text-align: left;
        }
        .appt-table tbody tr {
            border-bottom: 1px solid #eee;
            transition: background 0.2s;
        }
        .appt-table tbody tr:hover { background: #fafafa; }
        .appt-table tbody td {
            padding: 14px 18px;
            font-size: 14px;
            vertical-align: middle;
        }
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .badge-pending   { background: #fff3cd; color: #856404; }
        .badge-approved  { background: #d4edda; color: #155724; }
        .badge-completed { background: #cce5ff; color: #004085; }
        .badge-cancelled { background: #f8d7da; color: #721c24; }
        .btn-cancel {
            padding: 6px 14px;
            background: transparent;
            color: #e74c3c;
            border: 1px solid #e74c3c;
            border-radius: 5px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        .btn-cancel:hover {
            background: #e74c3c;
            color: #fff;
        }
        .no-appointments {
            text-align: center;
            padding: 50px 20px;
            color: #aaa;
        }
        .no-appointments i { font-size: 48px; margin-bottom: 15px; display: block; }

        /* ── Footer ── */
        .book-footer {
            background: #2c3e50;
            color: #fff;
            text-align: center;
            padding: 22px 0;
            font-size: 14px;
            margin-top: 0;
        }
        .book-footer a { color: #e74c3c; text-decoration: none; }
        .book-footer a:hover { text-decoration: underline; }

        /* ── Animations ── */
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes slideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }

        /* ── Responsive ── */
        @media (max-width: 992px) {
            .services-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 600px) {
            .services-grid { grid-template-columns: 1fr; }
            .page-title { font-size: 24px; }
            .vehicle-form-grid { grid-template-columns: 1fr; }
            .modal-content { margin: 10px; }
            .appt-table thead { display: none; }
            .appt-table tbody tr {
                display: block;
                margin-bottom: 12px;
                border: 1px solid #eee;
                border-radius: 8px;
                padding: 10px;
            }
            .appt-table tbody td {
                display: flex;
                justify-content: space-between;
                padding: 8px 12px;
                border-bottom: 1px solid #f5f5f5;
            }
            .appt-table tbody td::before {
                content: attr(data-label);
                font-weight: 600;
                color: #2c3e50;
                font-size: 12px;
                text-transform: uppercase;
            }
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
                    <li><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="../index.php#about"><i class="fas fa-info-circle"></i> About</a></li>
                    <li><a href="../index.php#contact"><i class="fas fa-envelope"></i> Contact</a></li>
                </ul>
            </nav>
            <div class="header-actions">
                <a href="notifications.php" class="header-icon" title="Notifications">
                    <i class="fas fa-bell"></i>
                    <?php if ($notifCount > 0): ?><span class="badge"><?= $notifCount ?></span><?php endif; ?>
                </a>
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

<!-- ========== BOOK SERVICE SECTION ========== -->
<section class="book-section">
    <div class="container">
        <h1 class="page-title"><i class="fas fa-calendar-check"></i> Book a Service</h1>
        <p class="page-subtitle">Browse our services below and book an appointment for your vehicle.</p>

        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Notification Alerts -->
        <?php if (!empty($unread_notifs)): ?>
            <?php foreach ($unread_notifs as $notif): ?>
                <div class="alert alert-success" style="background:#e3f2fd;color:#1565c0;border:1px solid #90caf9;">
                    <i class="fas fa-bell"></i>
                    <strong><?= htmlspecialchars($notif['title']) ?></strong> &mdash; <?= htmlspecialchars($notif['message']) ?>
                    <small style="margin-left:10px;opacity:0.7;"><?= date('M d, h:i A', strtotime($notif['created_at'])) ?></small>
                </div>
            <?php endforeach; ?>
            <?php
                // Mark displayed notifications as read
                $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")->execute([$user_id]);
            ?>
        <?php endif; ?>

        <!-- ── Available Services ── -->
        <h2 class="section-heading"><i class="fas fa-wrench"></i> Available Services</h2>

        <?php if (empty($services)): ?>
            <p style="color:#888;">No services available at the moment.</p>
        <?php else: ?>
            <div class="services-grid">
                <?php
                $icons = ['fa-oil-can','fa-cogs','fa-car-battery','fa-tools','fa-spray-can','fa-tire','fa-fan','fa-car-crash','fa-gas-pump','fa-screwdriver-wrench'];
                foreach ($services as $i => $srv):
                    $icon = $icons[$i % count($icons)];
                    $duration = (int) $srv['estimated_duration'];
                    $hours = floor($duration / 60);
                    $mins  = $duration % 60;
                    $durationStr = $hours > 0 ? "{$hours}h " : '';
                    $durationStr .= $mins > 0 ? "{$mins}m" : '';
                    if (!$durationStr) $durationStr = 'N/A';
                ?>
                <div class="service-card">
                    <div class="service-card-header">
                        <i class="fas <?= $icon ?>"></i>
                        <h3><?= htmlspecialchars($srv['service_name']) ?></h3>
                    </div>
                    <div class="service-card-body">
                        <p class="description"><?= htmlspecialchars($srv['description'] ?? 'Professional vehicle service.') ?></p>
                        <div class="service-meta">
                            <span class="service-price">₱<?= number_format($srv['base_price'], 2) ?></span>
                            <span class="service-duration"><i class="fas fa-clock"></i> <?= htmlspecialchars($durationStr) ?></span>
                        </div>
                        <div class="service-card-actions">
                            <button class="btn-book-now" onclick="openBookingModal(<?= (int) $srv['service_id'] ?>)">
                                <i class="fas fa-calendar-plus"></i> Book Now
                            </button>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="add_to_cart">
                                <input type="hidden" name="service_id" value="<?= (int) $srv['service_id'] ?>">
                                <button type="submit" class="btn-add-cart" title="Add to Cart">
                                    <i class="fas fa-cart-plus"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- ── My Appointments ── -->
        <div class="appointments-section">
            <h2 class="section-heading"><i class="fas fa-list-alt"></i> My Appointments</h2>

            <?php if (empty($appointments)): ?>
                <div class="appt-table-wrapper">
                    <div class="no-appointments">
                        <i class="fas fa-calendar-times"></i>
                        <p>You have no appointments yet. Book a service above to get started!</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="appt-table-wrapper">
                    <table class="appt-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Vehicle</th>
                                <th>Service</th>
                                <th>Technician</th>
                                <th>Date &amp; Time</th>
                                <th>Status</th>
                                <th>Booked On</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($appointments as $idx => $appt):
                                $statusClass = 'badge-' . strtolower($appt['status']);
                            ?>
                            <tr>
                                <td data-label="#"><?= $idx + 1 ?></td>
                                <td data-label="Vehicle">
                                    <?= htmlspecialchars($appt['plate_number']) ?> &mdash;
                                    <?= htmlspecialchars($appt['make'] . ' ' . $appt['model']) ?>
                                    <?php if ($appt['year']): ?>(<?= htmlspecialchars($appt['year']) ?>)<?php endif; ?>
                                </td>
                                <td data-label="Service"><?= htmlspecialchars($appt['service_name'] ?? 'N/A') ?></td>
                                <td data-label="Technician"><?= htmlspecialchars($appt['technician_name'] ?? 'Queued') ?></td>
                                <td data-label="Date &amp; Time"><?= date('M d, Y - h:i A', strtotime($appt['appointment_date'])) ?></td>
                                <td data-label="Status"><span class="badge <?= $statusClass ?>"><?= htmlspecialchars($appt['status']) ?></span></td>
                                <td data-label="Booked On"><?= date('M d, Y', strtotime($appt['created_at'])) ?></td>
                                <td data-label="Action">
                                    <?php if ($appt['status'] === 'Pending'): ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Cancel this appointment?')">
                                            <input type="hidden" name="action" value="cancel">
                                            <input type="hidden" name="appointment_id" value="<?= (int) $appt['appointment_id'] ?>">
                                            <button type="submit" class="btn-cancel"><i class="fas fa-times"></i> Cancel</button>
                                        </form>
                                    <?php else: ?>
                                        &mdash;
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- ========== BOOKING MODAL ========== -->
<div class="modal-overlay" id="bookingModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-calendar-check"></i> Book Appointment</h2>
            <button class="modal-close" onclick="closeBookingModal()">&times;</button>
        </div>
        <div class="modal-body">
            <!-- Add Vehicle Panel -->
            <div class="vehicle-form-panel" id="vehicleFormPanel">
                <h4><i class="fas fa-car"></i> Add a New Vehicle</h4>
                <form method="POST">
                    <input type="hidden" name="action" value="add_vehicle">
                    <div class="vehicle-form-grid">
                        <div>
                            <label>Plate Number <span class="required">*</span></label>
                            <input type="text" name="plate_number" class="form-control" placeholder="e.g. ABC 1234" required>
                        </div>
                        <div>
                            <label>Make <span class="required">*</span></label>
                            <input type="text" name="make" class="form-control" placeholder="e.g. Toyota" required>
                        </div>
                        <div>
                            <label>Model <span class="required">*</span></label>
                            <input type="text" name="model" class="form-control" placeholder="e.g. Vios" required>
                        </div>
                        <div>
                            <label>Year</label>
                            <input type="number" name="year" class="form-control" placeholder="e.g. 2023" min="1990" max="<?= date('Y') + 1 ?>" value="<?= date('Y') ?>">
                        </div>
                        <div class="full-width">
                            <label>Color</label>
                            <input type="text" name="color" class="form-control" placeholder="e.g. White">
                        </div>
                        <div class="full-width">
                            <button type="submit" class="btn-add-vehicle"><i class="fas fa-plus-circle"></i> Save Vehicle</button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Booking Form -->
            <form method="POST" id="bookingForm">
                <input type="hidden" name="action" value="book">

                <div class="form-group">
                    <label><i class="fas fa-wrench"></i> Service <span class="required">*</span></label>
                    <select name="service_id" id="modalServiceSelect" class="form-control">
                        <option value="">-- Select a Service --</option>
                        <?php foreach ($services as $srv): ?>
                            <option value="<?= (int) $srv['service_id'] ?>">
                                <?= htmlspecialchars($srv['service_name']) ?> — ₱<?= number_format($srv['base_price'], 2) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-car"></i> Vehicle <span class="required">*</span></label>
                    <?php if (empty($vehicles)): ?>
                        <div class="no-vehicles-msg">
                            <i class="fas fa-exclamation-triangle"></i>
                            <span>You have no vehicles registered.</span>
                            <button type="button" class="btn-show-vehicle-form" onclick="toggleVehicleForm()">
                                <i class="fas fa-plus"></i> Add Vehicle
                            </button>
                        </div>
                    <?php else: ?>
                        <select name="vehicle_id" class="form-control" required>
                            <option value="">-- Select your vehicle --</option>
                            <?php foreach ($vehicles as $v): ?>
                                <option value="<?= (int) $v['vehicle_id'] ?>">
                                    <?= htmlspecialchars($v['plate_number']) ?> — <?= htmlspecialchars($v['make'] . ' ' . $v['model']) ?>
                                    <?php if ($v['year']): ?>(<?= htmlspecialchars($v['year']) ?>)<?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div style="margin-top:8px;">
                            <button type="button" class="btn-show-vehicle-form" onclick="toggleVehicleForm()" style="margin-left:0;">
                                <i class="fas fa-plus"></i> Add New Vehicle
                            </button>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-calendar-alt"></i> Preferred Date &amp; Time <span class="required">*</span></label>
                    <input type="datetime-local" name="appointment_date" class="form-control" id="appointmentDate" required>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-sticky-note"></i> Notes / Special Requests</label>
                    <textarea name="notes" class="form-control" placeholder="Any special requests or notes about your vehicle ..."></textarea>
                </div>

                <?php if (!empty($vehicles)): ?>
                    <button type="submit" class="btn-submit"><i class="fas fa-check-circle"></i> Book Appointment</button>
                <?php else: ?>
                    <button type="button" class="btn-submit" style="opacity:0.5;cursor:not-allowed;" disabled title="Add a vehicle first">
                        <i class="fas fa-check-circle"></i> Book Appointment
                    </button>
                <?php endif; ?>
            </form>
        </div>
    </div>
</div>

<!-- ========== FOOTER ========== -->
<footer class="book-footer">
    <div class="container">
        <p>&copy; 2026 <a href="../index.php">VehiCare</a>. All rights reserved. | Designed for Vehicle Service DB</p>
    </div>
</footer>

<!-- ========== JAVASCRIPT ========== -->
<script>
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

// Set min date to tomorrow
(function () {
    const dt = document.getElementById('appointmentDate');
    if (dt) {
        const tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        tomorrow.setHours(8, 0, 0, 0);
        const y   = tomorrow.getFullYear();
        const m   = String(tomorrow.getMonth() + 1).padStart(2, '0');
        const d   = String(tomorrow.getDate()).padStart(2, '0');
        dt.min = `${y}-${m}-${d}T08:00`;
        dt.value = `${y}-${m}-${d}T09:00`;
    }
})();

// Booking Modal
function openBookingModal(serviceId) {
    document.getElementById('bookingModal').classList.add('active');
    document.body.style.overflow = 'hidden';
    if (serviceId) {
        const sel = document.getElementById('modalServiceSelect');
        if (sel) sel.value = serviceId;
    }
}

function closeBookingModal() {
    document.getElementById('bookingModal').classList.remove('active');
    document.body.style.overflow = '';
}

// Close modal on overlay click
document.getElementById('bookingModal').addEventListener('click', function (e) {
    if (e.target === this) closeBookingModal();
});

// Close modal on Escape key
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeBookingModal();
});

// Toggle vehicle form
function toggleVehicleForm() {
    document.getElementById('vehicleFormPanel').classList.toggle('active');
}
</script>

</body>
</html>
