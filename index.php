<?php
/**
 * Login Page - Advanced Clinic Suite
 */

require_once __DIR__ . '/config/session.php';

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: ' . BASE_URL . '/modules/dashboard/index.php');
    exit;
}

$error = '';
$sessionExpired = isset($_GET['session']) && $_GET['session'] === 'expired';

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/config/database.php';
    
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        try {
            $db = db();
            $user = $db->fetch(
                "SELECT u.*, r.name as role_name, r.id as role_id 
                 FROM users u 
                 JOIN roles r ON u.role_id = r.id 
                 WHERE u.username = ? AND u.is_active = 1",
                [$username]
            );
            
            if ($user) {
                // Check if account is locked
                if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
                    $error = 'Account is temporarily locked. Please try again later.';
                } elseif (password_verify($password, $user['password'])) {
                    // Successful login
                    $user['role'] = $user['role_name'];
                    createSession($user);
                    
                    // Update last login & reset failed attempts
                    $db->query(
                        "UPDATE users SET last_login = NOW(), failed_attempts = 0, locked_until = NULL WHERE id = ?",
                        [$user['id']]
                    );
                    
                    // Audit log
                    logAudit('login', 'auth', 'user', $user['id'], null, null, 'User logged in');
                    
                    header('Location: ' . BASE_URL . '/modules/dashboard/index.php');
                    exit;
                } else {
                    // Failed login
                    $attempts = $user['failed_attempts'] + 1;
                    $lockUntil = $attempts >= 5 ? date('Y-m-d H:i:s', strtotime('+15 minutes')) : null;
                    
                    $db->query(
                        "UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?",
                        [$attempts, $lockUntil, $user['id']]
                    );
                    
                    $error = 'Invalid username or password.';
                    if ($attempts >= 5) {
                        $error = 'Account locked due to too many failed attempts. Try again in 15 minutes.';
                    }
                }
            } else {
                $error = 'Invalid username or password.';
            }
        } catch (Exception $e) {
            error_log("Login error: " . $e->getMessage());
            $error = 'An error occurred. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?= APP_NAME ?></title>
    <meta name="description" content="Advanced Clinic Suite - Multi-Specialty Clinic Management Platform">
    <link rel="icon" type="image/svg+xml" href="<?= ASSETS_URL ?>/images/favicon.svg">
    <link rel="stylesheet" href="<?= ASSETS_URL ?>/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        /* ── Split Layout Login ── */
        .login-wrapper {
            background: #fff !important;
            display: flex !important;
            padding: 0 !important;
            min-height: 100vh;
        }
        .login-wrapper::before,
        .login-wrapper::after { display: none !important; }

        /* Left: form panel */
        .login-left {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px;
            position: relative;
            z-index: 1;
        }

        /* Right: illustration panel */
        .login-right {
            flex: 1;
            background: linear-gradient(160deg, #E0F7FA 0%, #B2EBF2 30%, #0097A7 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        /* Decorative circles on right panel */
        .login-right::before {
            content: '';
            position: absolute;
            width: 400px; height: 400px;
            border-radius: 50%;
            background: rgba(255,255,255,0.1);
            top: -80px; right: -80px;
        }
        .login-right::after {
            content: '';
            position: absolute;
            width: 300px; height: 300px;
            border-radius: 50%;
            background: rgba(255,255,255,0.08);
            bottom: -60px; left: -60px;
        }

        /* Floating medical icons on the right panel */
        .med-icons {
            position: absolute;
            inset: 0;
            pointer-events: none;
        }
        .med-icons i {
            position: absolute;
            color: rgba(255,255,255,0.25);
            animation: floatMed 15s ease-in-out infinite;
        }
        .med-icons i:nth-child(1)  { top: 8%;  left: 12%; font-size: 36px; animation-delay: 0s; }
        .med-icons i:nth-child(2)  { top: 15%; right: 18%; font-size: 44px; animation-delay: -2s; }
        .med-icons i:nth-child(3)  { top: 35%; left: 25%; font-size: 28px; animation-delay: -4s; }
        .med-icons i:nth-child(4)  { top: 50%; right: 10%; font-size: 52px; animation-delay: -1s; }
        .med-icons i:nth-child(5)  { top: 65%; left: 8%;  font-size: 40px; animation-delay: -3s; }
        .med-icons i:nth-child(6)  { top: 80%; right: 25%; font-size: 32px; animation-delay: -5s; }
        .med-icons i:nth-child(7)  { top: 25%; left: 55%; font-size: 48px; animation-delay: -6s; }
        .med-icons i:nth-child(8)  { top: 70%; left: 45%; font-size: 34px; animation-delay: -7s; }
        .med-icons i:nth-child(9)  { top: 45%; left: 65%; font-size: 26px; animation-delay: -2.5s; }
        .med-icons i:nth-child(10) { top: 90%; left: 60%; font-size: 38px; animation-delay: -4.5s; }

        @keyframes floatMed {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            25%  { transform: translateY(-12px) rotate(5deg); }
            50%  { transform: translateY(6px) rotate(-3deg); }
            75%  { transform: translateY(-8px) rotate(2deg); }
        }

        /* Center content on right panel */
        .right-content {
            position: relative;
            z-index: 1;
            text-align: center;
            color: #fff;
            padding: 40px;
        }
        .right-content .hero-icon {
            width: 120px; height: 120px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
            backdrop-filter: blur(10px);
            border: 2px solid rgba(255,255,255,0.3);
        }
        .right-content .hero-icon i {
            font-size: 52px;
            color: #fff;
        }
        .right-content h2 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 12px;
            text-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        .right-content p {
            font-size: 15px;
            opacity: 0.85;
            max-width: 320px;
            margin: 0 auto;
            line-height: 1.6;
        }

        /* Card overrides for white background */
        .login-card {
            background: transparent !important;
            box-shadow: none !important;
            border: none !important;
            backdrop-filter: none !important;
            -webkit-backdrop-filter: none !important;
            max-width: 420px;
            width: 100%;
            padding: 0 !important;
        }

        .login-brand h1 { color: var(--text-primary) !important; }
        .login-brand p { color: var(--text-muted) !important; }
        .login-brand .brand-icon {
            background: linear-gradient(135deg, #0097A7, #00BCD4) !important;
            box-shadow: 0 6px 20px rgba(0,151,167,0.3) !important;
            animation: none !important;
        }

        .login-card .form-label { color: var(--text-primary) !important; }
        .login-card .form-control {
            background: #F9FAFB !important;
            border: 1px solid #E5E7EB !important;
            color: var(--text-primary) !important;
        }
        .login-card .form-control::placeholder { color: var(--gray-400) !important; }
        .login-card .form-control:focus {
            border-color: #0097A7 !important;
            box-shadow: 0 0 0 3px rgba(0,151,167,0.12) !important;
            background: #fff !important;
        }

        .login-field-wrap { position: relative; }
        .login-field-wrap .field-icon {
            position: absolute; left: 14px; top: 50%;
            transform: translateY(-50%);
            color: var(--gray-400); font-size: 15px;
            transition: color 0.3s;
        }
        .login-field-wrap:focus-within .field-icon { color: #0097A7; }
        .login-field-wrap .form-control { padding-left: 42px; }

        .password-toggle {
            position: absolute; right: 14px; top: 50%;
            transform: translateY(-50%);
            background: none; border: none;
            color: var(--gray-400); cursor: pointer; font-size: 14px;
        }
        .password-toggle:hover { color: var(--text-primary); }

        .login-card .btn-primary {
            background: linear-gradient(135deg, #0097A7, #00ACC1) !important;
            border: none !important;
            box-shadow: 0 4px 14px rgba(0,151,167,0.3);
            transition: all 0.3s;
        }
        .login-card .btn-primary:hover {
            box-shadow: 0 6px 24px rgba(0,151,167,0.4);
            transform: translateY(-1px);
        }

        .login-footer { color: var(--text-muted) !important; }

        .login-card .alert-warning {
            background: #FFFBEB !important; color: #D97706 !important;
            border: 1px solid #FDE68A !important;
        }
        .login-card .alert-danger {
            background: #FEF2F2 !important; color: #DC2626 !important;
            border: 1px solid #FECACA !important;
        }

        .form-check { color: var(--text-secondary) !important; }

        /* Responsive: stack on mobile */
        @media (max-width: 768px) {
            .login-wrapper { flex-direction: column !important; }
            .login-right { min-height: 200px; flex: 0 0 200px; }
            .right-content h2 { font-size: 1.3rem; }
            .right-content .hero-icon { width: 70px; height: 70px; }
            .right-content .hero-icon i { font-size: 32px; }
            .login-left { padding: 24px; }
        }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <!-- Left: Login Form -->
        <div class="login-left">
            <div class="login-card">
                <div class="login-brand">
                    <div class="brand-icon">
                        <i class="fas fa-hospital"></i>
                    </div>
                    <h1><?= APP_NAME ?></h1>
                    <p><?= APP_TAGLINE ?></p>
                </div>

                <?php if ($sessionExpired): ?>
                <div class="alert alert-warning" data-auto-dismiss="5000">
                    <i class="fas fa-clock"></i>
                    Your session has expired. Please log in again.
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="alert alert-danger" data-auto-dismiss="5000">
                    <i class="fas fa-exclamation-circle"></i>
                    <?= sanitizeOutput($error) ?>
                </div>
                <?php endif; ?>

                <form class="login-form" method="POST" action="">
                    <div class="form-group">
                        <label class="form-label">Username</label>
                        <div class="login-field-wrap">
                            <i class="field-icon fas fa-user"></i>
                            <input type="text" name="username" class="form-control" placeholder="Enter your username"
                                   value="<?= sanitizeOutput($_POST['username'] ?? '') ?>" required autofocus>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Password</label>
                        <div class="login-field-wrap">
                            <i class="field-icon fas fa-lock"></i>
                            <input type="password" name="password" id="loginPassword" class="form-control"
                                   placeholder="Enter your password" required>
                            <button type="button" class="password-toggle" onclick="togglePassword()">
                                <i class="fas fa-eye" id="toggleIcon"></i>
                            </button>
                        </div>
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
                        <label class="form-check" style="font-size: 13px;">
                            <input type="checkbox" name="remember"> Remember me
                        </label>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block btn-lg">
                        <i class="fas fa-sign-in-alt"></i> Sign In
                    </button>
                </form>

                <div class="login-footer">
                    <p>&copy; <?= date('Y') ?> <?= APP_NAME ?>. All rights reserved.</p>
                    <p style="margin-top: 4px;">v<?= APP_VERSION ?></p>
                </div>
            </div>
        </div>

        <!-- Right: Medical Illustration Panel -->
        <div class="login-right">
            <div class="med-icons">
                <i class="fas fa-stethoscope"></i>
                <i class="fas fa-heartbeat"></i>
                <i class="fas fa-pills"></i>
                <i class="fas fa-user-md"></i>
                <i class="fas fa-dna"></i>
                <i class="fas fa-lungs"></i>
                <i class="fas fa-hospital"></i>
                <i class="fas fa-syringe"></i>
                <i class="fas fa-microscope"></i>
                <i class="fas fa-x-ray"></i>
            </div>
            <div class="right-content">
                <div class="hero-icon">
                    <i class="fas fa-stethoscope"></i>
                </div>
                <h2>Welcome to Clinic Suite</h2>
                <p>Manage your clinic efficiently with our comprehensive healthcare management platform.</p>
            </div>
        </div>
    </div>

    <script>
        function togglePassword() {
            const input = document.getElementById('loginPassword');
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
    <script src="<?= ASSETS_URL ?>/js/app.js"></script>
</body>
</html>
