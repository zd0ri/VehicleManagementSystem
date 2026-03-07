<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'technician') { header('Location: ../users/login.php'); exit; }
$page_title = 'My Assignments'; $current_page = 'assignments';
require_once __DIR__ . '/../includes/db.php';

$tid = $_SESSION['user_id'];
$success = $error = '';

// Handle status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'update_status') {
            $new_status = $_POST['status'];
            $assignment_id = $_POST['assignment_id'];

            if ($new_status === 'Ongoing') {
                $pdo->prepare("UPDATE assignments SET status = 'Ongoing', start_time = NOW() WHERE assignment_id = ? AND technician_id = ?")
                    ->execute([$assignment_id, $tid]);
                $success = 'Service started! Timer is running.';
            } elseif ($new_status === 'Finished') {
                $pdo->prepare("UPDATE assignments SET status = 'Finished', end_time = NOW() WHERE assignment_id = ? AND technician_id = ?")
                    ->execute([$assignment_id, $tid]);

                // Also update the linked appointment status
                $pdo->prepare("UPDATE appointments SET status = 'Completed' WHERE appointment_id = (SELECT appointment_id FROM assignments WHERE assignment_id = ?)")
                    ->execute([$assignment_id]);

                $success = 'Service completed! Great work.';
            }
        } elseif ($action === 'add_notes') {
            $pdo->prepare("UPDATE assignments SET notes = ? WHERE assignment_id = ? AND technician_id = ?")
                ->execute([$_POST['notes'], $_POST['assignment_id'], $tid]);
            $success = 'Notes updated successfully.';
        } elseif ($action === 'notify_done') {
            $assignment_id = (int) $_POST['assignment_id'];
            // Get assignment details with client info
            $asgn = $pdo->prepare("
                SELECT a.*, v.client_id, s.service_name, c.full_name AS client_name, c.user_id AS client_user_id
                FROM assignments a
                LEFT JOIN vehicles v ON a.vehicle_id = v.vehicle_id
                LEFT JOIN services s ON a.service_id = s.service_id
                LEFT JOIN clients c ON v.client_id = c.client_id
                WHERE a.assignment_id = ? AND a.technician_id = ?
            ");
            $asgn->execute([$assignment_id, $tid]);
            $asgn_data = $asgn->fetch();

            if ($asgn_data && $asgn_data['client_user_id']) {
                $svc_name = $asgn_data['service_name'] ?? 'vehicle service';
                $tech_name = $_SESSION['full_name'] ?? 'Your technician';
                $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'Service Completed!', ?, 'job_done')")
                    ->execute([$asgn_data['client_user_id'], 'Your ' . $svc_name . ' has been completed by ' . $tech_name . '! You can now pick up your vehicle.']);

                // Also notify all admins
                $admins = $pdo->query("SELECT user_id FROM users WHERE role = 'admin' AND status = 'active'")->fetchAll();
                foreach ($admins as $admin) {
                    $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'Service Completed', ?, 'job_done')")
                        ->execute([$admin['user_id'], $tech_name . ' has completed ' . $svc_name . ' for client ' . ($asgn_data['client_name'] ?? 'N/A') . '.']);
                }

                // Check queue - if there are waiting clients, auto-assign
                $waiting = $pdo->prepare("SELECT q.*, c2.user_id AS waiting_user_id FROM queue q LEFT JOIN clients c2 ON q.client_id = c2.client_id WHERE q.status = 'Waiting' ORDER BY q.position ASC LIMIT 1");
                $waiting->execute();
                $next_in_queue = $waiting->fetch();

                if ($next_in_queue) {
                    // Find pending appointment for this queued client
                    $pending_appt = $pdo->prepare("SELECT * FROM appointments WHERE client_id = ? AND status = 'Pending' ORDER BY appointment_id ASC LIMIT 1");
                    $pending_appt->execute([$next_in_queue['client_id']]);
                    $queued_appt = $pending_appt->fetch();

                    if ($queued_appt) {
                        // Auto-assign the queued appointment to this technician
                        $pdo->prepare("UPDATE appointments SET status = 'Approved' WHERE appointment_id = ?")->execute([$queued_appt['appointment_id']]);
                        $pdo->prepare("INSERT INTO assignments (appointment_id, vehicle_id, technician_id, service_id, status) VALUES (?, ?, ?, ?, 'Assigned')")
                            ->execute([$queued_appt['appointment_id'], $queued_appt['vehicle_id'], $tid, $queued_appt['service_id']]);
                        $pdo->prepare("UPDATE queue SET status = 'Serving' WHERE queue_id = ?")->execute([$next_in_queue['queue_id']]);

                        // Notify the queued client
                        if ($next_in_queue['waiting_user_id']) {
                            $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'It\\'s Your Turn!', 'A technician is now available for your vehicle service. Your appointment has been confirmed!', 'queue_turn')")
                                ->execute([$next_in_queue['waiting_user_id']]);
                        }
                    }
                }

                $success = 'Client has been notified that their service is complete!';
            } else {
                $error = 'Could not find client information for this assignment.';
            }
        } elseif ($action === 'use_parts') {
            $assignment_id = (int) $_POST['assignment_id'];
            $item_id = (int) $_POST['item_id'];
            $qty = (int) $_POST['qty'];

            // Verify assignment belongs to this technician and is active
            $verify = $pdo->prepare("SELECT assignment_id FROM assignments WHERE assignment_id = ? AND technician_id = ? AND status IN ('Assigned','Ongoing')");
            $verify->execute([$assignment_id, $tid]);
            if (!$verify->fetch()) { throw new Exception('Assignment not found or already finished.'); }

            if ($qty < 1) { throw new Exception('Quantity must be at least 1.'); }

            // Check stock availability with row lock
            $pdo->beginTransaction();
            $stock = $pdo->prepare("SELECT quantity, item_name FROM inventory WHERE item_id = ? FOR UPDATE");
            $stock->execute([$item_id]);
            $item = $stock->fetch();
            if (!$item) { $pdo->rollBack(); throw new Exception('Item not found in inventory.'); }
            if ($item['quantity'] < $qty) { $pdo->rollBack(); throw new Exception('Not enough stock for "' . $item['item_name'] . '". Available: ' . $item['quantity']); }

            // Deduct from inventory
            $pdo->prepare("UPDATE inventory SET quantity = quantity - ? WHERE item_id = ?")->execute([$qty, $item_id]);

            // Record parts usage
            $pdo->prepare("INSERT INTO parts_used (assignment_id, item_id, quantity) VALUES (?, ?, ?)")->execute([$assignment_id, $item_id, $qty]);

            $pdo->commit();
            $success = 'Used ' . $qty . 'x "' . htmlspecialchars($item['item_name']) . '" — stock updated.';

        } elseif ($action === 'remove_part') {
            $parts_used_id = (int) $_POST['parts_used_id'];

            // Verify the part usage belongs to this technician's assignment
            $verify = $pdo->prepare("SELECT pu.*, a.technician_id FROM parts_used pu JOIN assignments a ON pu.assignment_id = a.assignment_id WHERE pu.parts_used_id = ? AND a.technician_id = ?");
            $verify->execute([$parts_used_id, $tid]);
            $part = $verify->fetch();
            if (!$part) { throw new Exception('Part usage record not found.'); }

            // Restore stock
            $pdo->prepare("UPDATE inventory SET quantity = quantity + ? WHERE item_id = ?")->execute([$part['quantity'], $part['item_id']]);
            $pdo->prepare("DELETE FROM parts_used WHERE parts_used_id = ?")->execute([$parts_used_id]);

            $success = 'Part removed and stock restored.';
        }
    } catch (Exception $e) { $error = 'Error: ' . $e->getMessage(); }
}

// Filters
$filter_status = $_GET['status'] ?? '';
$sql = "SELECT a.*, s.service_name, s.estimated_duration, s.base_price,
        v.plate_number, v.make, v.model, v.year, v.color,
        c.full_name AS client_name, c.phone AS client_phone, c.email AS client_email
        FROM assignments a
        LEFT JOIN services s ON a.service_id = s.service_id
        LEFT JOIN vehicles v ON a.vehicle_id = v.vehicle_id
        LEFT JOIN clients c ON v.client_id = c.client_id
        WHERE a.technician_id = ?";
$params = [$tid];
if ($filter_status) { $sql .= " AND a.status = ?"; $params[] = $filter_status; }
$sql .= " ORDER BY FIELD(a.status, 'Ongoing', 'Assigned', 'Finished'), a.assignment_id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$assignments = $stmt->fetchAll();

// Tab counts
$cnt_all = $pdo->prepare("SELECT COUNT(*) FROM assignments WHERE technician_id = ?"); $cnt_all->execute([$tid]); $cnt_all = $cnt_all->fetchColumn();
$cnt_assigned = $pdo->prepare("SELECT COUNT(*) FROM assignments WHERE technician_id = ? AND status = 'Assigned'"); $cnt_assigned->execute([$tid]); $cnt_assigned = $cnt_assigned->fetchColumn();
$cnt_ongoing = $pdo->prepare("SELECT COUNT(*) FROM assignments WHERE technician_id = ? AND status = 'Ongoing'"); $cnt_ongoing->execute([$tid]); $cnt_ongoing = $cnt_ongoing->fetchColumn();
$cnt_finished = $pdo->prepare("SELECT COUNT(*) FROM assignments WHERE technician_id = ? AND status = 'Finished'"); $cnt_finished->execute([$tid]); $cnt_finished = $cnt_finished->fetchColumn();

// Available inventory items for "Use Parts" dropdown
$inventory_items = $pdo->query("SELECT item_id, item_name, quantity, unit_price FROM inventory WHERE quantity > 0 ORDER BY item_name ASC")->fetchAll();

// Parts used per assignment
$parts_by_assignment = [];
$all_parts = $pdo->prepare("SELECT pu.*, i.item_name, i.unit_price FROM parts_used pu JOIN inventory i ON pu.item_id = i.item_id JOIN assignments a ON pu.assignment_id = a.assignment_id WHERE a.technician_id = ? ORDER BY pu.created_at DESC");
$all_parts->execute([$tid]);
foreach ($all_parts->fetchAll() as $p) {
    $parts_by_assignment[$p['assignment_id']][] = $p;
}
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
            <?php if ($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div><?php endif; ?>

            <div class="page-header">
                <div class="page-header-left">
                    <h1><i class="fas fa-tasks" style="color:var(--primary);margin-right:10px;"></i> My Assignments</h1>
                    <p>View and update your service assignments</p>
                </div>
            </div>

            <!-- Filter Tabs -->
            <div style="display:flex;gap:8px;margin-bottom:24px;flex-wrap:wrap;">
                <a href="assignments.php" class="btn <?= !$filter_status ? 'btn-primary' : 'btn-secondary' ?> btn-sm">All (<?= $cnt_all ?>)</a>
                <a href="assignments.php?status=Assigned" class="btn <?= $filter_status === 'Assigned' ? 'btn-primary' : 'btn-secondary' ?> btn-sm"><i class="fas fa-clock"></i> Assigned (<?= $cnt_assigned ?>)</a>
                <a href="assignments.php?status=Ongoing" class="btn <?= $filter_status === 'Ongoing' ? 'btn-primary' : 'btn-secondary' ?> btn-sm"><i class="fas fa-play"></i> Ongoing (<?= $cnt_ongoing ?>)</a>
                <a href="assignments.php?status=Finished" class="btn <?= $filter_status === 'Finished' ? 'btn-primary' : 'btn-secondary' ?> btn-sm"><i class="fas fa-check"></i> Finished (<?= $cnt_finished ?>)</a>
            </div>

            <?php if (empty($assignments)): ?>
                <div class="tech-card"><div class="card-body"><div class="empty-state"><i class="fas fa-tasks"></i><h3>No assignments found</h3><p>You have no assignments in this category.</p></div></div></div>
            <?php else: ?>
                <?php foreach ($assignments as $a): ?>
                <div class="tech-card" style="margin-bottom:16px;">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-wrench"></i>
                            <?= htmlspecialchars($a['service_name'] ?? 'Service #' . $a['assignment_id']) ?>
                        </h3>
                        <span class="badge badge-<?= strtolower($a['status']) ?>"><?= $a['status'] ?></span>
                    </div>
                    <div class="card-body">
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px;">
                            <!-- Vehicle Info -->
                            <div>
                                <h4 style="font-size:13px;color:var(--tech-text-muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:10px;">
                                    <i class="fas fa-car" style="color:var(--primary)"></i> Vehicle Information
                                </h4>
                                <div class="vehicle-info-card">
                                    <div class="vehicle-icon"><i class="fas fa-car"></i></div>
                                    <div class="vehicle-details">
                                        <div class="vehicle-name"><?= htmlspecialchars(($a['year'] ?? '') . ' ' . ($a['make'] ?? '') . ' ' . ($a['model'] ?? '')) ?></div>
                                        <div class="vehicle-plate"><i class="fas fa-id-badge"></i> <?= htmlspecialchars($a['plate_number'] ?? 'N/A') ?> <?= $a['color'] ? '&bull; ' . htmlspecialchars($a['color']) : '' ?></div>
                                    </div>
                                </div>
                            </div>

                            <!-- Client Info -->
                            <div>
                                <h4 style="font-size:13px;color:var(--tech-text-muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:10px;">
                                    <i class="fas fa-user" style="color:var(--primary)"></i> Client Information
                                </h4>
                                <div style="display:flex;flex-direction:column;gap:6px;">
                                    <div style="display:flex;align-items:center;gap:8px;font-size:14px;">
                                        <i class="fas fa-user" style="color:var(--tech-text-muted);width:16px;"></i>
                                        <span style="color:var(--tech-text);"><?= htmlspecialchars($a['client_name'] ?? 'N/A') ?></span>
                                    </div>
                                    <?php if ($a['client_phone']): ?>
                                    <div style="display:flex;align-items:center;gap:8px;font-size:14px;">
                                        <i class="fas fa-phone" style="color:var(--tech-text-muted);width:16px;"></i>
                                        <span style="color:var(--tech-text-dim);"><?= htmlspecialchars($a['client_phone']) ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($a['client_email']): ?>
                                    <div style="display:flex;align-items:center;gap:8px;font-size:14px;">
                                        <i class="fas fa-envelope" style="color:var(--tech-text-muted);width:16px;"></i>
                                        <span style="color:var(--tech-text-dim);"><?= htmlspecialchars($a['client_email']) ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Service Details -->
                            <div>
                                <h4 style="font-size:13px;color:var(--tech-text-muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:10px;">
                                    <i class="fas fa-info-circle" style="color:var(--primary)"></i> Service Details
                                </h4>
                                <div class="client-info-grid" style="grid-template-columns:1fr 1fr;">
                                    <?php if ($a['estimated_duration']): ?>
                                    <div class="client-info-item">
                                        <div class="client-info-label">Est. Duration</div>
                                        <div class="client-info-value"><?= $a['estimated_duration'] ?> min</div>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($a['start_time']): ?>
                                    <div class="client-info-item">
                                        <div class="client-info-label">Started</div>
                                        <div class="client-info-value"><?= date('M d, h:i A', strtotime($a['start_time'])) ?></div>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($a['end_time']): ?>
                                    <div class="client-info-item">
                                        <div class="client-info-label">Completed</div>
                                        <div class="client-info-value"><?= date('M d, h:i A', strtotime($a['end_time'])) ?></div>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($a['start_time'] && $a['end_time']): ?>
                                    <div class="client-info-item">
                                        <div class="client-info-label">Duration</div>
                                        <div class="client-info-value"><?php
                                            $diff = strtotime($a['end_time']) - strtotime($a['start_time']);
                                            $hours = floor($diff / 3600);
                                            $mins = floor(($diff % 3600) / 60);
                                            echo ($hours > 0 ? $hours . 'h ' : '') . $mins . 'm';
                                        ?></div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Notes -->
                        <?php if ($a['notes']): ?>
                        <div style="margin-top:16px;padding:12px;background:var(--tech-surface-2);border-radius:var(--radius-sm);border-left:3px solid var(--primary);">
                            <div style="font-size:12px;color:var(--tech-text-muted);text-transform:uppercase;margin-bottom:4px;">Notes</div>
                            <div style="font-size:14px;color:var(--tech-text-dim);"><?= nl2br(htmlspecialchars($a['notes'])) ?></div>
                        </div>
                        <?php endif; ?>

                        <!-- Parts Used -->
                        <?php $used_parts = $parts_by_assignment[$a['assignment_id']] ?? []; ?>
                        <?php if (!empty($used_parts)): ?>
                        <div style="margin-top:16px;">
                            <h4 style="font-size:13px;color:var(--tech-text-muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;">
                                <i class="fas fa-boxes-stacked" style="color:var(--primary)"></i> Parts Used
                            </h4>
                            <table style="width:100%;border-collapse:collapse;font-size:13px;">
                                <thead><tr style="background:var(--tech-surface-2);color:var(--tech-text-muted);">
                                    <th style="padding:8px;text-align:left;">Item</th>
                                    <th style="padding:8px;text-align:center;">Qty</th>
                                    <th style="padding:8px;text-align:right;">Unit Price</th>
                                    <th style="padding:8px;text-align:right;">Total</th>
                                    <?php if ($a['status'] !== 'Finished'): ?><th style="padding:8px;text-align:center;">Action</th><?php endif; ?>
                                </tr></thead>
                                <tbody>
                                <?php $parts_total = 0; foreach ($used_parts as $up): $line_total = $up['quantity'] * $up['unit_price']; $parts_total += $line_total; ?>
                                <tr style="border-bottom:1px solid var(--tech-border);">
                                    <td style="padding:8px;color:var(--tech-text);"><?= htmlspecialchars($up['item_name']) ?></td>
                                    <td style="padding:8px;text-align:center;color:var(--tech-text);"><?= $up['quantity'] ?></td>
                                    <td style="padding:8px;text-align:right;color:var(--tech-text-dim);">₱<?= number_format($up['unit_price'], 2) ?></td>
                                    <td style="padding:8px;text-align:right;color:var(--tech-text);">₱<?= number_format($line_total, 2) ?></td>
                                    <?php if ($a['status'] !== 'Finished'): ?>
                                    <td style="padding:8px;text-align:center;">
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Remove this part and restore stock?')">
                                            <input type="hidden" name="action" value="remove_part">
                                            <input type="hidden" name="parts_used_id" value="<?= $up['parts_used_id'] ?>">
                                            <button type="submit" class="btn-icon btn-delete" title="Remove"><i class="fas fa-times"></i></button>
                                        </form>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                                <tr style="font-weight:600;">
                                    <td style="padding:8px;" colspan="3">Parts Total</td>
                                    <td style="padding:8px;text-align:right;color:var(--primary);">₱<?= number_format($parts_total, 2) ?></td>
                                    <?php if ($a['status'] !== 'Finished'): ?><td></td><?php endif; ?>
                                </tr>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>

                        <!-- Actions -->
                        <div style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                            <?php if ($a['status'] === 'Assigned'): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="assignment_id" value="<?= $a['assignment_id'] ?>">
                                    <input type="hidden" name="status" value="Ongoing">
                                    <button type="submit" class="btn btn-warning btn-sm" onclick="return confirm('Start working on this service?')">
                                        <i class="fas fa-play"></i> Start Service
                                    </button>
                                </form>
                            <?php elseif ($a['status'] === 'Ongoing'): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="assignment_id" value="<?= $a['assignment_id'] ?>">
                                    <input type="hidden" name="status" value="Finished">
                                    <button type="submit" class="btn btn-success btn-sm" onclick="return confirm('Mark this service as complete?')">
                                        <i class="fas fa-check-circle"></i> Complete Service
                                    </button>
                                </form>
                                <?php if ($a['start_time']): ?>
                                <div style="display:flex;align-items:center;gap:6px;padding:6px 14px;background:rgba(243,156,18,0.1);border-radius:6px;font-size:13px;color:var(--orange);">
                                    <i class="fas fa-clock"></i>
                                    <span>In progress since <?= date('h:i A', strtotime($a['start_time'])) ?></span>
                                </div>
                                <?php endif; ?>
                            <?php elseif ($a['status'] === 'Finished'): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="notify_done">
                                    <input type="hidden" name="assignment_id" value="<?= $a['assignment_id'] ?>">
                                    <button type="submit" class="btn btn-primary btn-sm" onclick="return confirm('Send notification to the client that their service is complete?')" style="background:#27ae60;border-color:#27ae60;">
                                        <i class="fas fa-bell"></i> Notify Client - Job Done
                                    </button>
                                </form>
                            <?php endif; ?>

                            <button class="btn btn-secondary btn-sm" onclick="openNotesModal(<?= $a['assignment_id'] ?>, '<?= addslashes($a['notes'] ?? '') ?>')">
                                <i class="fas fa-sticky-note"></i> <?= $a['notes'] ? 'Edit Notes' : 'Add Notes' ?>
                            </button>

                            <?php if ($a['status'] !== 'Finished'): ?>
                            <button class="btn btn-secondary btn-sm" onclick="openPartsModal(<?= $a['assignment_id'] ?>)" style="background:#8e44ad;border-color:#8e44ad;color:#fff;">
                                <i class="fas fa-boxes-stacked"></i> Use Parts
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>
</div>

<!-- Notes Modal -->
<div class="modal-overlay" id="notesModal">
    <div class="modal">
        <div class="modal-header">
            <h3>Assignment Notes</h3>
            <button class="modal-close" onclick="closeModal('notesModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add_notes">
            <input type="hidden" name="assignment_id" id="notes_assignment_id">
            <div class="modal-body">
                <div class="form-group">
                    <label>Notes & Observations</label>
                    <textarea name="notes" id="notes_text" class="form-control" rows="5" placeholder="Enter your notes about this service..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('notesModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Notes</button>
            </div>
        </form>
    </div>
</div>

<!-- Use Parts Modal -->
<div class="modal-overlay" id="partsModal">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fas fa-boxes-stacked"></i> Use Parts from Inventory</h3>
            <button class="modal-close" onclick="closeModal('partsModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="use_parts">
            <input type="hidden" name="assignment_id" id="parts_assignment_id">
            <div class="modal-body">
                <div class="form-group">
                    <label>Select Item</label>
                    <select name="item_id" class="form-control" required>
                        <option value="">-- Select an item --</option>
                        <?php foreach ($inventory_items as $inv): ?>
                        <option value="<?= $inv['item_id'] ?>"><?= htmlspecialchars($inv['item_name']) ?> — Stock: <?= $inv['quantity'] ?> (₱<?= number_format($inv['unit_price'], 2) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Quantity</label>
                    <input type="number" name="qty" class="form-control" min="1" value="1" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('partsModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background:#8e44ad;border-color:#8e44ad;"><i class="fas fa-minus-circle"></i> Deduct from Stock</button>
            </div>
        </form>
    </div>
</div>

<script src="includes/tech.js"></script>
<script>
function openModal(id) { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }
document.querySelectorAll('.modal-overlay').forEach(m => { m.addEventListener('click', e => { if (e.target === m) m.classList.remove('active'); }); });

function openNotesModal(assignmentId, notes) {
    document.getElementById('notes_assignment_id').value = assignmentId;
    document.getElementById('notes_text').value = notes;
    openModal('notesModal');
}

function openPartsModal(assignmentId) {
    document.getElementById('parts_assignment_id').value = assignmentId;
    openModal('partsModal');
}
</script>
</body>
</html>
