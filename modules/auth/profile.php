<?php
/**
 * User Profile - Advanced Clinic Suite
 */
require_once dirname(dirname(__DIR__)) . '/config/session.php';
requireAuth();

$db = db();
$userId = getCurrentUserId();
$user = $db->fetch("SELECT u.*, r.name as role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ?", [$userId]);

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    
    if (!password_verify($current, $user['password'])) {
        setFlashMessage('error', 'Current password is incorrect.');
    } elseif (strlen($new) < 6) {
        setFlashMessage('error', 'New password must be at least 6 characters.');
    } elseif ($new !== $confirm) {
        setFlashMessage('error', 'Passwords do not match.');
    } else {
        $db->query("UPDATE users SET password = ? WHERE id = ?", [password_hash($new, PASSWORD_DEFAULT), $userId]);
        logAudit('update', 'auth', 'user', $userId, null, null, 'Password changed');
        setFlashMessage('success', 'Password updated successfully.');
    }
    header('Location: ' . BASE_URL . '/modules/auth/profile.php');
    exit;
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $fullName = sanitize($_POST['full_name'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    
    $db->query("UPDATE users SET full_name = ?, email = ?, phone = ? WHERE id = ?", [$fullName, $email, $phone, $userId]);
    $_SESSION['full_name'] = $fullName;
    logAudit('update', 'auth', 'user', $userId, null, null, 'Profile updated');
    setFlashMessage('success', 'Profile updated successfully.');
    header('Location: ' . BASE_URL . '/modules/auth/profile.php');
    exit;
}

// NOW include header — after any potential redirect
$pageTitle = 'My Profile';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="content-header">
    <div>
        <ul class="breadcrumb"><li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li><li>Profile</li></ul>
        <h1>My Profile</h1>
    </div>
</div>

<div class="grid-3 gap-24">
    <!-- Profile Card -->
    <div class="card">
        <div class="card-body text-center" style="padding: 32px;">
            <div class="user-avatar" style="width: 96px; height: 96px; font-size: 32px; margin: 0 auto 16px;">
                <?= getInitials($user['full_name']) ?>
            </div>
            <h2><?= sanitizeOutput($user['full_name']) ?></h2>
            <p class="text-muted"><?= ucfirst(str_replace('_', ' ', $user['role_name'])) ?></p>
            <div style="margin-top: 16px; font-size: 13px; color: var(--text-secondary);">
                <p><i class="fas fa-envelope"></i> <?= sanitizeOutput($user['email'] ?? 'No email') ?></p>
                <p><i class="fas fa-phone"></i> <?= sanitizeOutput($user['phone'] ?? 'No phone') ?></p>
                <p><i class="fas fa-clock"></i> Last login: <?= $user['last_login'] ? formatDateTime($user['last_login']) : 'N/A' ?></p>
            </div>
        </div>
    </div>
    
    <!-- Edit Profile -->
    <div class="card" style="grid-column: span 2;">
        <div class="card-body">
            <div class="tabs mb-24">
                <button class="tab-btn active" data-tab="tab-profile">Edit Profile</button>
                <button class="tab-btn" data-tab="tab-password">Change Password</button>
            </div>
            
            <div class="tab-content active" id="tab-profile">
                <form method="POST">
                    <input type="hidden" name="action" value="update_profile">
                    <div class="form-group">
                        <label class="form-label">Full Name</label>
                        <input type="text" name="full_name" class="form-control" value="<?= sanitizeOutput($user['full_name']) ?>" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" value="<?= sanitizeOutput($user['email'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Phone</label>
                            <input type="tel" name="phone" class="form-control" value="<?= sanitizeOutput($user['phone'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Username</label>
                        <input type="text" class="form-control" value="<?= sanitizeOutput($user['username']) ?>" disabled>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
                </form>
            </div>
            
            <div class="tab-content" id="tab-password">
                <form method="POST">
                    <input type="hidden" name="action" value="change_password">
                    <div class="form-group">
                        <label class="form-label">Current Password</label>
                        <div style="position: relative;">
                            <input type="password" name="current_password" id="currentPwd" class="form-control" required style="padding-right: 40px;">
                            <button type="button" onclick="togglePassword('currentPwd', this)" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: var(--text-muted);">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">New Password</label>
                            <div style="position: relative;">
                                <input type="password" name="new_password" id="newPwd" class="form-control" required minlength="6" style="padding-right: 40px;">
                                <button type="button" onclick="togglePassword('newPwd', this)" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: var(--text-muted);">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Confirm Password</label>
                            <div style="position: relative;">
                                <input type="password" name="confirm_password" id="confirmPwd" class="form-control" required style="padding-right: 40px;">
                                <button type="button" onclick="togglePassword('confirmPwd', this)" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: var(--text-muted);">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-key"></i> Update Password</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
