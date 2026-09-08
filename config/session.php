<?php
/**
 * Session Management
 * Advanced Clinic Suite
 */

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';

// Configure session
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Strict');

if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', 1);
}

// Isolate session per tenant
$tenantInfo = db()->tenantInfo;
$sessionSuffix = $tenantInfo ? '_' . $tenantInfo['id'] : '';
session_name(SESSION_NAME . $sessionSuffix);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Check session timeout
 */
function checkSessionTimeout() {
    if (isset($_SESSION['last_activity'])) {
        if (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT) {
            destroySession();
            return false;
        }
    }
    $_SESSION['last_activity'] = time();
    return true;
}

/**
 * Create user session
 */
function createSession($user) {
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['role_id'] = $user['role_id'] ?? null;
    $_SESSION['clinic_id'] = $user['clinic_id'] ?? 1;
    $_SESSION['branch_id'] = $user['branch_id'] ?? null;
    $_SESSION['profile_image'] = $user['profile_image'] ?? null;
    $_SESSION['last_activity'] = time();
    $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? '';
    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $_SESSION['csrf_token'] = generateCSRFToken();
}

/**
 * Destroy session
 */
function destroySession() {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}

/**
 * Generate CSRF Token
 */
function generateCSRFToken() {
    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $token;
    return $token;
}

/**
 * Validate CSRF Token
 */
function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Get current user ID
 */
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Get current user role
 */
function getCurrentUserRole() {
    return $_SESSION['role'] ?? null;
}

/**
 * Get current clinic ID
 */
function getCurrentClinicId() {
    return $_SESSION['clinic_id'] ?? 1;
}

/**
 * Get current Doctor ID (if user is a doctor)
 */
function getCurrentDoctorId() {
    $role = getCurrentUserRole();
    if ($role === 'doctor' || $role === ROLE_DOCTOR) {
        return $_SESSION['role_id'] ?? null;
    }
    return null;
}

/**
 * Get session value
 */
function getSession($key, $default = null) {
    return $_SESSION[$key] ?? $default;
}

/**
 * Require authentication - redirect to login if not authenticated
 */
function requireAuth() {
    if (!isLoggedIn() || !checkSessionTimeout()) {
        header('Location: ' . BASE_URL . '/index.php?session=expired');
        exit;
    }
}

/**
 * Require specific role
 */
function requireRole($roles) {
    requireAuth();
    if (!is_array($roles)) {
        $roles = [$roles];
    }
    if (!in_array(getCurrentUserRole(), $roles)) {
        header('HTTP/1.1 403 Forbidden');
        include MODULES_PATH . '/auth/403.php';
        exit;
    }
}

/**
 * Flash message system
 */
function setFlashMessage($type, $message) {
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

function getFlashMessage() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}
?>
