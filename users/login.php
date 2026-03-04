<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Please fill in all fields.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND status = 'active'");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $user['role'];

            // If customer, also fetch client_id for cart/order operations
            if ($user['role'] === 'customer') {
                $cstmt = $pdo->prepare("SELECT client_id FROM clients WHERE user_id = ?");
                $cstmt->execute([$user['user_id']]);
                $client = $cstmt->fetch();
                if ($client) {
                    $_SESSION['client_id'] = $client['client_id'];
                }
            }

            if ($user['role'] === 'admin') {
                header('Location: ../admins/dashboard.php');
            } else {
                header('Location: ../index.php');
            }
            exit;
        } else {
            $error = 'Invalid email or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - VehiCare</title>
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
                <i class="fas fa-car-side auth-hero-icon"></i>
                <h2>Welcome Back!</h2>
                <p>Your trusted partner for quality auto parts and professional vehicle services in Taguig City.</p>
            </div>
            <div class="auth-features">
                <div class="auth-feature"><i class="fas fa-check-circle"></i> Professional Service</div>
                <div class="auth-feature"><i class="fas fa-check-circle"></i> Quality Parts</div>
                <div class="auth-feature"><i class="fas fa-check-circle"></i> Expert Technicians</div>
            </div>
        </div>
    </div>
    <div class="auth-right">
        <div class="auth-form-container">
            <div class="auth-form-header">
                <h2>Sign In</h2>
                <p>Enter your credentials to access your account</p>
            </div>

            <?php if ($error): ?>
                <div class="auth-alert auth-alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="auth-form">
                <div class="auth-input-group">
                    <label for="email"><i class="fas fa-envelope"></i> Email Address</label>
                    <input type="email" id="email" name="email" placeholder="Enter your email" value="<?= htmlspecialchars($email ?? '') ?>" required>
                </div>
                <div class="auth-input-group">
                    <label for="password"><i class="fas fa-lock"></i> Password</label>
                    <div class="password-wrapper">
                        <input type="password" id="password" name="password" placeholder="Enter your password" required>
                        <button type="button" class="password-toggle" onclick="togglePassword()">
                            <i class="fas fa-eye" id="toggleIcon"></i>
                        </button>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary auth-btn">
                    <i class="fas fa-sign-in-alt"></i> Sign In
                </button>
            </form>

            <div class="auth-footer">
                <p>Don't have an account? <a href="register.php">Create Account</a></p>
                <a href="../index.php" class="auth-back"><i class="fas fa-arrow-left"></i> Back to Home</a>
            </div>
        </div>
    </div>
</div>

<script>
function togglePassword() {
    const input = document.getElementById('password');
    const icon = document.getElementById('toggleIcon');
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
