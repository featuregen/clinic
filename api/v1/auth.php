<?php
/**
 * REST API Auth Endpoints - Feature Gen Care
 * POST /api/v1/auth.php?action=login
 * GET  /api/v1/auth.php?action=me
 * POST /api/v1/auth.php?action=logout
 * POST /api/v1/auth.php?action=change_password
 */
require_once __DIR__ . '/helpers.php';

$action = $_GET['action'] ?? ($_POST['action'] ?? 'login');
$db = db();

switch ($action) {
    case 'login':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, null, 'Method not allowed. Use POST for login.', 405);
        }
        
        $input = getJsonInput();
        $username = trim($input['username'] ?? '');
        $password = $input['password'] ?? '';
        $deviceInfo = trim($input['device_info'] ?? 'Mobile App');
        
        if (empty($username) || empty($password)) {
            jsonResponse(false, null, 'Username and password are required.', 400);
        }
        
        $user = $db->fetch(
            "SELECT u.*, r.name as role_name, r.display_name as role_display_name
             FROM users u
             LEFT JOIN roles r ON u.role_id = r.id
             WHERE (u.username = ? OR u.email = ? OR u.phone = ?) AND u.is_active = 1 LIMIT 1",
            [$username, $username, $username]
        );
        
        if (!$user || !password_verify($password, $user['password'])) {
            jsonResponse(false, null, 'Invalid username or password.', 401);
        }
        
        // Generate secure API Token (64-byte random hex)
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
        
        $db->query(
            "INSERT INTO api_tokens (user_id, token, device_info, expires_at, last_used_at, is_active)
             VALUES (?, ?, ?, ?, NOW(), 1)",
            [$user['id'], $token, $deviceInfo, $expiresAt]
        );
        
        // Update user last_login
        $db->query("UPDATE users SET last_login = NOW() WHERE id = ?", [$user['id']]);
        
        // Fetch clinic details
        $clinicId = intval($user['clinic_id'] ?? 1);
        $clinic = $db->fetch("SELECT * FROM clinics WHERE id = ?", [$clinicId]);
        if (!$clinic) {
            $clinic = $db->fetch("SELECT * FROM clinics ORDER BY id ASC LIMIT 1") ?: [];
        }
        
        // Remove password hash from response
        unset($user['password']);
        
        jsonResponse(true, [
            'token' => $token,
            'expires_at' => $expiresAt,
            'user' => $user,
            'clinic' => $clinic,
            'tenant' => [
                'subdomain' => $db->tenantInfo['subdomain'] ?? '',
                'clinic_name' => $db->tenantInfo['clinic_name'] ?? ($clinic['name'] ?? 'Feature Gen Care'),
                'plan_type' => $db->tenantInfo['plan_type'] ?? 'trial',
                'is_lifetime' => !empty($db->tenantInfo['is_lifetime']),
                'is_expired' => !empty($db->tenantInfo['is_expired']),
                'max_doctors' => intval($db->tenantInfo['max_doctors'] ?? 2)
            ]
        ]);
        break;

    case 'me':
        require_once __DIR__ . '/middleware/auth.php';
        $auth = authenticateApiRequest();
        $user = $auth['user'];
        $clinic = $auth['clinic'];
        $tenant = $auth['tenant'];
        
        unset($user['password']);
        
        jsonResponse(true, [
            'user' => $user,
            'clinic' => $clinic,
            'tenant' => [
                'subdomain' => $tenant['subdomain'] ?? '',
                'clinic_name' => $tenant['clinic_name'] ?? ($clinic['name'] ?? 'Feature Gen Care'),
                'plan_type' => $tenant['plan_type'] ?? 'trial',
                'is_lifetime' => !empty($tenant['is_lifetime']),
                'is_expired' => !empty($tenant['is_expired']),
                'max_doctors' => intval($tenant['max_doctors'] ?? 2)
            ]
        ]);
        break;

    case 'logout':
        require_once __DIR__ . '/middleware/auth.php';
        $auth = authenticateApiRequest();
        $db->query("UPDATE api_tokens SET is_active = 0 WHERE id = ?", [$auth['token_id']]);
        jsonResponse(true, ['message' => 'Logged out successfully']);
        break;

    case 'change_password':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, null, 'Method not allowed', 405);
        }
        require_once __DIR__ . '/middleware/auth.php';
        $auth = authenticateApiRequest();
        $input = getJsonInput();
        $current = $input['current_password'] ?? '';
        $new = $input['new_password'] ?? '';
        
        $dbUser = $db->fetch("SELECT password FROM users WHERE id = ?", [$auth['user']['id']]);
        if (!password_verify($current, $dbUser['password'])) {
            jsonResponse(false, null, 'Current password is incorrect.', 400);
        }
        if (strlen($new) < 6) {
            jsonResponse(false, null, 'New password must be at least 6 characters.', 400);
        }
        
        $db->query("UPDATE users SET password = ? WHERE id = ?", [password_hash($new, PASSWORD_DEFAULT), $auth['user']['id']]);
        jsonResponse(true, ['message' => 'Password updated successfully.']);
        break;

    case 'update_profile':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, null, 'Method not allowed', 405);
        }
        require_once __DIR__ . '/middleware/auth.php';
        $auth = authenticateApiRequest();
        $input = getJsonInput();
        $phone = sanitize(trim($input['phone'] ?? ''));
        $fullName = sanitize(trim($input['full_name'] ?? ''));

        if (empty($fullName)) {
            jsonResponse(false, null, 'Full name is required.', 400);
        }

        $db->query(
            "UPDATE users SET full_name = ?, phone = ?, updated_at = NOW() WHERE id = ?",
            [$fullName, $phone ?: null, $auth['user']['id']]
        );

        $updatedUser = $db->fetch(
            "SELECT u.*, r.name as role_name, r.display_name as role_display_name
             FROM users u
             LEFT JOIN roles r ON u.role_id = r.id
             WHERE u.id = ?",
            [$auth['user']['id']]
        );
        unset($updatedUser['password']);

        jsonResponse(true, [
            'message' => 'Profile updated successfully',
            'user' => $updatedUser
        ]);
        break;

    default:
        jsonResponse(false, null, 'Invalid action specified.', 400);
}
