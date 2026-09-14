<?php
/**
 * REST API Clinic & Settings Endpoints - Feature Gen Care
 * GET /api/v1/clinic.php
 * PUT /api/v1/clinic.php (update clinic info)
 * GET /api/v1/clinic.php?action=branches
 * GET /api/v1/clinic.php?action=doctors
 * GET /api/v1/clinic.php?action=staff
 */
require_once __DIR__ . '/middleware/auth.php';
$auth = authenticateApiRequest();
$db = db();
$clinicId = intval($auth['user']['clinic_id'] ?? 1);
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

try {
    // 1. Branches
    if ($action === 'branches') {
        $branches = $db->fetchAll("SELECT * FROM branches WHERE clinic_id = ? AND is_active = 1", [$clinicId]);
        jsonResponse(true, $branches);
    }

    // 2. Doctors
    if ($action === 'doctors') {
        $doctors = $db->fetchAll(
            "SELECT d.id, d.specialty, d.qualification, d.consultation_fee, d.experience_years, d.is_active,
                    u.id as user_id, u.full_name, u.email, u.phone, u.profile_image
             FROM doctors d
             JOIN users u ON d.user_id = u.id
             WHERE d.clinic_id = ?",
            [$clinicId]
        );
        jsonResponse(true, $doctors);
    }

    // 3. Staff List
    if ($action === 'staff') {
        $staff = $db->fetchAll(
            "SELECT u.id, u.username, u.full_name, u.email, u.phone, u.role, u.is_active,
                    r.display_name as role_display_name
             FROM users u
             LEFT JOIN roles r ON u.role_id = r.id
             WHERE u.clinic_id = ? AND u.role != 'super_admin'",
            [$clinicId]
        );
        jsonResponse(true, $staff);
    }

    // 4. Update Clinic Details (PUT or POST)
    if ($method === 'PUT' || ($method === 'POST' && $action === 'update')) {
        $input = getJsonInput();
        $name = sanitize($input['name'] ?? '');
        $email = sanitize($input['email'] ?? '');
        $phone = sanitize($input['phone'] ?? '');
        $address = sanitize($input['address'] ?? '');
        $city = sanitize($input['city'] ?? '');
        $state = sanitize($input['state'] ?? '');
        $pincode = sanitize($input['pincode'] ?? '');
        $website = sanitize($input['website'] ?? '');
        $pan = strtoupper(sanitize(trim($input['pan_number'] ?? '')));
        $gst = strtoupper(sanitize(trim($input['gst_number'] ?? '')));

        if (empty($name)) {
            jsonResponse(false, null, 'Clinic name is required', 400);
        }

        $db->query(
            "UPDATE clinics SET name = ?, email = ?, phone = ?, address = ?, city = ?, state = ?, pincode = ?, website = ?, pan_number = ?, gst_number = ? WHERE id = ?",
            [$name, $email, $phone, $address, $city, $state, $pincode, $website, $pan, $gst, $clinicId]
        );

        // Also sync to master tenants
        $tenantId = intval($db->tenantInfo['id'] ?? 0);
        if ($tenantId > 0) {
            try {
                $master = master_db();
                $master->prepare("UPDATE tenants SET clinic_name = ?, gst_number = ?, pan_number = ?, billing_address = ? WHERE id = ?")
                       ->execute([$name, $gst, $pan, $address, $tenantId]);
            } catch (Exception $e) {}
        }

        jsonResponse(true, ['message' => 'Clinic details updated successfully']);
    }

    // 5. Fetch Clinic Details (Default GET)
    $clinic = $db->fetch("SELECT * FROM clinics WHERE id = ?", [$clinicId]);
    if (!$clinic) {
        $clinic = $db->fetch("SELECT * FROM clinics ORDER BY id ASC LIMIT 1") ?: [];
    }

    jsonResponse(true, [
        'clinic' => $clinic,
        'tenant' => $db->tenantInfo
    ]);

} catch (Exception $e) {
    jsonResponse(false, null, 'Clinic API Error: ' . $e->getMessage(), 500);
}
