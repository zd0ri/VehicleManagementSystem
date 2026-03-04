<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($full_name) || empty($email) || empty($password) || empty($confirm_password)) {
        $error = 'Please fill in all fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } else {
        // Check if email already exists
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'An account with this email already exists.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->beginTransaction();
            try {
                // Create user account with customer role
                $stmt = $pdo->prepare("INSERT INTO users (full_name, email, password_hash, role, status) VALUES (?, ?, ?, 'customer', 'active')");
                $stmt->execute([$full_name, $email, $hash]);
                $newUserId = $pdo->lastInsertId();

                // Also create a client record linked to this user
                $phone = trim($_POST['phone'] ?? '');
                $address = trim($_POST['address'] ?? '');
                $stmt = $pdo->prepare("INSERT INTO clients (user_id, full_name, phone, email, address) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$newUserId, $full_name, $phone, $email, $address]);

                $pdo->commit();
                $success = 'Account created successfully! You can now sign in.';
                $full_name = $email = '';
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Registration failed. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - VehiCare</title>
    <link rel="stylesheet" href="../includes/style/style.css">
    <link rel="stylesheet" href="../includes/style/auth.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700;900&family=Oswald:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="auth-body">

<div class="auth-wrapper">
    <div class="auth-left">
        <div class="auth-left-content">
            <a href="../index.php" class="auth-logo">
                <span class="logo-vehi">Vehi</span><span class="logo-care">Care</span>
            </a>
            <div class="auth-left-text">
                <i class="fas fa-tools auth-hero-icon"></i>
                <h2>Join VehiCare</h2>
                <p>Create an account and get access to our wide range of vehicle services and auto parts.</p>
            </div>
            <div class="auth-features">
                <div class="auth-feature"><i class="fas fa-check-circle"></i> Track your service history</div>
                <div class="auth-feature"><i class="fas fa-check-circle"></i> Book appointments online</div>
                <div class="auth-feature"><i class="fas fa-check-circle"></i> Exclusive member deals</div>
            </div>
        </div>
    </div>
    <div class="auth-right">
        <div class="auth-form-container">
            <div class="auth-form-header">
                <h2>Create Account</h2>
                <p>Fill in the details below to register</p>
            </div>

            <?php if ($error): ?>
                <div class="auth-alert auth-alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="auth-alert auth-alert-success">
                    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="auth-form">
                <div class="auth-input-group">
                    <label for="full_name"><i class="fas fa-user"></i> Full Name</label>
                    <input type="text" id="full_name" name="full_name" placeholder="Enter your full name" value="<?= htmlspecialchars($full_name ?? '') ?>" required>
                </div>
                <div class="auth-input-group">
                    <label for="email"><i class="fas fa-envelope"></i> Email Address</label>
                    <input type="email" id="email" name="email" placeholder="Enter your email" value="<?= htmlspecialchars($email ?? '') ?>" required>
                </div>
                <div class="auth-input-group">
                    <label for="phone"><i class="fas fa-phone"></i> Phone Number</label>
                    <input type="text" id="phone" name="phone" placeholder="e.g. 09123456789" value="<?= htmlspecialchars($phone ?? '') ?>">
                </div>
                <div class="auth-input-group">
                    <label for="address"><i class="fas fa-map-marker-alt"></i> Address</label>
                    <input type="text" id="address" name="address" placeholder="Your address (optional)" value="<?= htmlspecialchars($address ?? '') ?>">
                </div>
                <div class="auth-input-group">
                    <label for="password"><i class="fas fa-lock"></i> Password</label>
                    <div class="password-wrapper">
                        <input type="password" id="password" name="password" placeholder="At least 6 characters" required>
                        <button type="button" class="password-toggle" onclick="togglePassword('password', 'toggleIcon1')">
                            <i class="fas fa-eye" id="toggleIcon1"></i>
                        </button>
                    </div>
                </div>
                <div class="auth-input-group">
                    <label for="confirm_password"><i class="fas fa-lock"></i> Confirm Password</label>
                    <div class="password-wrapper">
                        <input type="password" id="confirm_password" name="confirm_password" placeholder="Re-enter your password" required>
                        <button type="button" class="password-toggle" onclick="togglePassword('confirm_password', 'toggleIcon2')">
                            <i class="fas fa-eye" id="toggleIcon2"></i>
                        </button>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary auth-btn">
                    <i class="fas fa-user-plus"></i> Create Account
                </button>
            </form>

            <div class="auth-footer">
                <p>Already have an account? <a href="login.php">Sign In</a></p>
                <a href="../index.php" class="auth-back"><i class="fas fa-arrow-left"></i> Back to Home</a>
            </div>
        </div>
    </div>
</div>

<script>
function togglePassword(inputId, iconId) {
    const input = document.getElementById(inputId);
    const icon = document.getElementById(iconId);
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('fa-eye-slash', 'fa-eye');
    }
}
</script>

</body>
</html>
