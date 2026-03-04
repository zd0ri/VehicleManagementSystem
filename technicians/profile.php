<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'technician') { header('Location: ../users/login.php'); exit; }
$page_title = 'My Profile'; $current_page = 'profile';
require_once __DIR__ . '/../includes/db.php';

$tid = $_SESSION['user_id'];
$success = $error = '';

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'update_profile') {
            $pdo->prepare("UPDATE users SET full_name = ? WHERE user_id = ?")
                ->execute([trim($_POST['full_name']), $tid]);
            $_SESSION['full_name'] = trim($_POST['full_name']);
            $success = 'Profile updated successfully.';
        } elseif ($action === 'change_password') {
            $current = $_POST['current_password'];
            $new = $_POST['new_password'];
            $confirm = $_POST['confirm_password'];

            // Verify current password
            $user = $pdo->prepare("SELECT password_hash FROM users WHERE user_id = ?");
            $user->execute([$tid]);
            $hash = $user->fetchColumn();

            if (!password_verify($current, $hash)) {
                $error = 'Current password is incorrect.';
            } elseif (strlen($new) < 6) {
                $error = 'New password must be at least 6 characters.';
            } elseif ($new !== $confirm) {
                $error = 'New passwords do not match.';
            } else {
                $new_hash = password_hash($new, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?")->execute([$new_hash, $tid]);
                $success = 'Password changed successfully.';
            }
        }
    } catch (Exception $e) { $error = 'Error: ' . $e->getMessage(); }
}

// Fetch user data
$user = $pdo->prepare("SELECT * FROM users WHERE user_id = ?"); $user->execute([$tid]); $user = $user->fetch();

// Quick stats
$total = $pdo->prepare("SELECT COUNT(*) FROM assignments WHERE technician_id = ?"); $total->execute([$tid]); $total = $total->fetchColumn();
$finished = $pdo->prepare("SELECT COUNT(*) FROM assignments WHERE technician_id = ? AND status = 'Finished'"); $finished->execute([$tid]); $finished = $finished->fetchColumn();
$avg_rating = $pdo->prepare("SELECT ROUND(AVG(rating_value),1) FROM ratings WHERE technician_id = ?"); $avg_rating->execute([$tid]); $avg_rating = $avg_rating->fetchColumn() ?: 0;
$total_ratings = $pdo->prepare("SELECT COUNT(*) FROM ratings WHERE technician_id = ?"); $total_ratings->execute([$tid]); $total_ratings = $total_ratings->fetchColumn();

// Unique clients served
$clients_served = $pdo->prepare("SELECT COUNT(DISTINCT v.client_id) FROM assignments a LEFT JOIN vehicles v ON a.vehicle_id = v.vehicle_id WHERE a.technician_id = ?");
$clients_served->execute([$tid]);
$clients_served = $clients_served->fetchColumn();

// Member since
$member_since = date('F d, Y', strtotime($user['created_at']));
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
                    <h1><i class="fas fa-user-circle" style="color:var(--primary);margin-right:10px;"></i> My Profile</h1>
                    <p>Manage your account settings</p>
                </div>
            </div>

            <div class="profile-grid">
                <!-- Profile Card -->
                <div>
                    <div class="tech-card" style="margin-bottom:20px;">
                        <div class="profile-card-main">
                            <div class="profile-avatar-lg"><?= strtoupper(substr($user['full_name'], 0, 1)) ?></div>
                            <div class="profile-name"><?= htmlspecialchars($user['full_name']) ?></div>
                            <div class="profile-email"><?= htmlspecialchars($user['email']) ?></div>
                            <div class="profile-badge"><i class="fas fa-user-gear"></i> Technician</div>
                            <div style="margin-top:16px;font-size:13px;color:var(--tech-text-muted);">
                                <i class="fas fa-calendar"></i> Member since <?= $member_since ?>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Stats -->
                    <div class="tech-card">
                        <div class="card-header"><h3><i class="fas fa-chart-simple"></i> Quick Stats</h3></div>
                        <div class="card-body" style="padding:20px;">
                            <div style="display:flex;flex-direction:column;gap:14px;">
                                <div style="display:flex;justify-content:space-between;align-items:center;">
                                    <span style="font-size:14px;color:var(--tech-text-dim);"><i class="fas fa-tasks" style="color:var(--primary);width:20px;"></i> Total Assignments</span>
                                    <span style="font-family:var(--font-heading);font-size:18px;font-weight:700;color:var(--tech-text);"><?= $total ?></span>
                                </div>
                                <div style="display:flex;justify-content:space-between;align-items:center;">
                                    <span style="font-size:14px;color:var(--tech-text-dim);"><i class="fas fa-check-circle" style="color:var(--green);width:20px;"></i> Completed</span>
                                    <span style="font-family:var(--font-heading);font-size:18px;font-weight:700;color:var(--green);"><?= $finished ?></span>
                                </div>
                                <div style="display:flex;justify-content:space-between;align-items:center;">
                                    <span style="font-size:14px;color:var(--tech-text-dim);"><i class="fas fa-users" style="color:var(--blue);width:20px;"></i> Clients Served</span>
                                    <span style="font-family:var(--font-heading);font-size:18px;font-weight:700;color:var(--tech-text);"><?= $clients_served ?></span>
                                </div>
                                <div style="display:flex;justify-content:space-between;align-items:center;">
                                    <span style="font-size:14px;color:var(--tech-text-dim);"><i class="fas fa-star" style="color:#f39c12;width:20px;"></i> Avg. Rating</span>
                                    <span style="font-family:var(--font-heading);font-size:18px;font-weight:700;color:var(--tech-text);"><?= $avg_rating ?>/5 <span style="font-size:12px;color:var(--tech-text-muted);font-family:var(--font-main);">(<?= $total_ratings ?>)</span></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Settings Forms -->
                <div>
                    <!-- Update Profile -->
                    <div class="tech-card" style="margin-bottom:20px;">
                        <div class="card-header"><h3><i class="fas fa-user-edit"></i> Update Profile</h3></div>
                        <div class="card-body" style="padding:24px;">
                            <form method="POST">
                                <input type="hidden" name="action" value="update_profile">
                                <div class="form-group">
                                    <label>Full Name</label>
                                    <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($user['full_name']) ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Email Address</label>
                                    <input type="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" disabled style="opacity:0.6;">
                                    <small style="color:var(--tech-text-muted);font-size:12px;">Contact admin to change email.</small>
                                </div>
                                <div class="form-group">
                                    <label>Role</label>
                                    <input type="text" class="form-control" value="Technician" disabled style="opacity:0.6;">
                                </div>
                                <div class="form-group">
                                    <label>Status</label>
                                    <span class="badge badge-active" style="font-size:14px;padding:6px 16px;"><?= ucfirst($user['status']) ?></span>
                                </div>
                                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
                            </form>
                        </div>
                    </div>

                    <!-- Change Password -->
                    <div class="tech-card">
                        <div class="card-header"><h3><i class="fas fa-lock"></i> Change Password</h3></div>
                        <div class="card-body" style="padding:24px;">
                            <form method="POST">
                                <input type="hidden" name="action" value="change_password">
                                <div class="form-group">
                                    <label>Current Password</label>
                                    <input type="password" name="current_password" class="form-control" required>
                                </div>
                                <div class="form-group">
                                    <label>New Password</label>
                                    <input type="password" name="new_password" class="form-control" required minlength="6">
                                </div>
                                <div class="form-group">
                                    <label>Confirm New Password</label>
                                    <input type="password" name="confirm_password" class="form-control" required minlength="6">
                                </div>
                                <button type="submit" class="btn btn-primary"><i class="fas fa-key"></i> Update Password</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
<script src="includes/tech.js"></script>
</body>
</html>
