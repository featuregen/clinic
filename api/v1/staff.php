<?php
/**
 * REST API Staff Endpoints - Feature Gen Care
 * GET    /api/v1/staff.php          (list non-doctor staff)
 * POST   /api/v1/staff.php          (create staff)
 * PUT    /api/v1/staff.php?id=N     (update staff)
 */
require_once __DIR__ . '/middleware/auth.php';
$auth = authenticateApiRequest();
$db = db();
$clinicId = intval($auth['user']['clinic_id'] ?? 1);
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        if (isset($_GET['id'])) {
            $staff = $db->fetch("SELECT u.*, r.display_name as role_display_name FROM users u LEFT JOIN roles r ON u.role_id=r.id WHERE u.id=? AND u.clinic_id=? AND u.role NOT IN ('doctor','super_admin')", [intval($_GET['id']), $clinicId]);
            if (!$staff) jsonResponse(false, null, 'Staff not found', 404);
            unset($staff['password']);
            jsonResponse(true, $staff);
        }
        $staff = $db->fetchAll(
            "SELECT u.id, u.full_name, u.email, u.phone, u.gender, u.role, u.is_active, u.qualification, u.profile_image, r.display_name as role_display_name
             FROM users u LEFT JOIN roles r ON u.role_id=r.id
             WHERE u.clinic_id=? AND u.role NOT IN ('doctor','super_admin')
             ORDER BY u.full_name", [$clinicId]
        );
        $roles = $db->fetchAll("SELECT id, name, display_name FROM roles WHERE name NOT IN ('super_admin','doctor') ORDER BY display_name");
        jsonResponse(true, ['staff' => $staff, 'roles' => $roles]);
        break;

    case 'POST':
        $input = getJsonInput();
        $fullName = trim($input['full_name'] ?? '');
        $username = trim($input['username'] ?? '');
        $password = $input['password'] ?? '';
        $roleId = intval($input['role_id'] ?? 0);

        if (empty($fullName) || empty($username) || empty($password) || !$roleId) {
            jsonResponse(false, null, 'Full name, username, password, and role are required.', 400);
        }
        $existing = $db->fetch("SELECT id FROM users WHERE username = ?", [$username]);
        if ($existing) jsonResponse(false, null, 'Username already exists.', 409);

        $roleRow = $db->fetch("SELECT name FROM roles WHERE id = ?", [$roleId]);
        $roleName = $roleRow['name'] ?? 'receptionist';

        try {
            $db->query(
                "INSERT INTO users (clinic_id, role_id, role, username, password, full_name, email, phone, gender, date_of_birth, qualification, is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
                [$clinicId, $roleId, $roleName, $username, password_hash($password, PASSWORD_DEFAULT), $fullName, trim($input['email'] ?? '') ?: null, trim($input['phone'] ?? '') ?: null, $input['gender'] ?? null, $input['date_of_birth'] ?? null, trim($input['qualification'] ?? '') ?: null, 1]
            );
            jsonResponse(true, ['id' => intval($db->lastInsertId())], null, 201);
        } catch (Exception $e) {
            jsonResponse(false, null, $e->getMessage(), 500);
        }
        break;

    case 'PUT':
        $id = intval($_GET['id'] ?? 0);
        if (!$id) jsonResponse(false, null, 'Staff ID required', 400);
        $staff = $db->fetch("SELECT * FROM users WHERE id=? AND clinic_id=? AND role NOT IN ('doctor','super_admin')", [$id, $clinicId]);
        if (!$staff) jsonResponse(false, null, 'Staff not found', 404);

        $input = getJsonInput();
        $roleId = intval($input['role_id'] ?? $staff['role_id']);
        $roleRow = $db->fetch("SELECT name FROM roles WHERE id = ?", [$roleId]);
        $roleName = $roleRow['name'] ?? $staff['role'];

        try {
            $db->query(
                "UPDATE users SET full_name=?, email=?, phone=?, gender=?, date_of_birth=?, role_id=?, role=?, qualification=?, is_active=? WHERE id=?",
                [trim($input['full_name'] ?? $staff['full_name']), trim($input['email'] ?? '') ?: null, trim($input['phone'] ?? '') ?: null, $input['gender'] ?? null, $input['date_of_birth'] ?? null, $roleId, $roleName, trim($input['qualification'] ?? '') ?: null, intval($input['is_active'] ?? 1), $id]
            );
            if (!empty($input['password'])) {
                $db->query("UPDATE users SET password=? WHERE id=?", [password_hash($input['password'], PASSWORD_DEFAULT), $id]);
            }
            jsonResponse(true, ['message' => 'Staff updated successfully']);
        } catch (Exception $e) {
            jsonResponse(false, null, $e->getMessage(), 500);
        }
        break;

    default:
        jsonResponse(false, null, 'Method not allowed', 405);
}
