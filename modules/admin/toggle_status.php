<?php
/**
 * Toggle User Status AJAX - Advanced Clinic Suite
 */
require_once dirname(dirname(__DIR__)) . '/config/database.php';
require_once dirname(dirname(__DIR__)) . '/config/constants.php';
require_once dirname(dirname(__DIR__)) . '/config/session.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn() || getCurrentUserRole() !== ROLE_SUPER_ADMIN) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized'], 403);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$userId = intval($input['user_id'] ?? 0);

if (!$userId) {
    jsonResponse(['success' => false, 'message' => 'Invalid user ID']);
    exit;
}

$db = db();
$clinicId = getCurrentClinicId();

// Prevent self-deactivation
if ($userId == getCurrentUserId()) {
    jsonResponse(['success' => false, 'message' => 'Cannot change your own status']);
    exit;
}

$user = $db->fetch(
    "SELECT u.id, u.is_active, u.full_name, r.name as role_name 
     FROM users u 
     JOIN roles r ON u.role_id = r.id 
     WHERE u.id = ? AND u.clinic_id = ?", 
    [$userId, $clinicId]
);

if (!$user) {
    jsonResponse(['success' => false, 'message' => 'User not found']);
    exit;
}

if ($user['role_name'] === 'super_admin') {
    jsonResponse(['success' => false, 'message' => 'Super Admin status cannot be modified']);
    exit;
}

$newStatus = $user['is_active'] ? 0 : 1;
$db->query("UPDATE users SET is_active = ? WHERE id = ?", [$newStatus, $userId]);

$action = $newStatus ? 'activated' : 'deactivated';
logAudit('update', 'admin', 'user', $userId, null, null, "User {$user['full_name']} $action");

jsonResponse([
    'success' => true,
    'message' => "User {$action} successfully",
    'new_status' => $newStatus
]);
?>
