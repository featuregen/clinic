<?php
/**
 * REST API Doctors Endpoints - Feature Gen Care
 * GET    /api/v1/doctors.php         (list doctors)
 * GET    /api/v1/doctors.php?id=N    (get single doctor)
 * POST   /api/v1/doctors.php         (create doctor)
 * PUT    /api/v1/doctors.php?id=N    (update doctor)
 * DELETE /api/v1/doctors.php?id=N    (deactivate doctor)
 */
require_once __DIR__ . '/middleware/auth.php';
$auth = authenticateApiRequest();
$db = db();
$clinicId = intval($auth['user']['clinic_id'] ?? 1);
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        if (isset($_GET['id'])) {
            $doc = $db->fetch(
                "SELECT d.*, u.full_name, u.email, u.phone, u.gender, u.date_of_birth, u.username,
                        u.qualification, u.specialization, u.license_number, u.address, u.profile_image, u.is_active, u.branch_id
                 FROM doctors d JOIN users u ON d.user_id = u.id
                 WHERE d.id = ? AND d.clinic_id = ?", [intval($_GET['id']), $clinicId]
            );
            if (!$doc) jsonResponse(false, null, 'Doctor not found', 404);
            jsonResponse(true, $doc);
        }

        // List all doctors
        $doctors = $db->fetchAll(
            "SELECT d.id, d.user_id, u.full_name, u.email, u.phone, u.gender, u.profile_image,
                    u.qualification, u.specialization, u.is_active, d.consultation_fee, d.is_available,
                    d.experience_years, d.registration_number
             FROM doctors d JOIN users u ON d.user_id = u.id
             WHERE d.clinic_id = ? ORDER BY u.full_name", [$clinicId]
        );

        // Quota info
        $tenantData = $db->tenantInfo ?? [];
        $maxDoctors = intval($tenantData['max_doctors'] ?? 0);
        $activeCount = $db->fetch("SELECT COUNT(*) as c FROM doctors d JOIN users u ON d.user_id=u.id WHERE d.clinic_id=? AND u.is_active=1", [$clinicId])['c'] ?? 0;

        jsonResponse(true, [
            'doctors' => $doctors,
            'quota' => ['max' => $maxDoctors, 'used' => intval($activeCount)]
        ]);
        break;

    case 'POST':
        $input = getJsonInput();
        $fullName = trim($input['full_name'] ?? '');
        $username = trim($input['username'] ?? '');
        $password = $input['password'] ?? '';
        $email = trim($input['email'] ?? '');
        $phone = trim($input['phone'] ?? '');
        $gender = $input['gender'] ?? '';
        $dob = $input['date_of_birth'] ?? null;
        $qualification = trim($input['qualification'] ?? '');
        $specialization = trim($input['specialization'] ?? '');
        $licenseNumber = trim($input['license_number'] ?? '');
        $consultFee = floatval($input['consultation_fee'] ?? 0);
        $followupFee = floatval($input['followup_fee'] ?? 0);
        $expYears = intval($input['experience_years'] ?? 0);
        $slotDuration = intval($input['default_slot_duration'] ?? 15);
        $bio = trim($input['bio'] ?? '');
        $regNumber = trim($input['registration_number'] ?? '');

        if (empty($fullName) || empty($username) || empty($password)) {
            jsonResponse(false, null, 'Full name, username, and password are required.', 400);
        }

        // Check quota
        $tenantData = $db->tenantInfo ?? [];
        $maxDoctors = intval($tenantData['max_doctors'] ?? 0);
        $activeCount = $db->fetch("SELECT COUNT(*) as c FROM doctors d JOIN users u ON d.user_id=u.id WHERE d.clinic_id=? AND u.is_active=1", [$clinicId])['c'] ?? 0;
        if ($maxDoctors > 0 && $activeCount >= $maxDoctors) {
            jsonResponse(false, null, "Doctor limit reached ({$activeCount}/{$maxDoctors}). Upgrade your plan.", 403);
        }

        // Check username unique
        $existing = $db->fetch("SELECT id FROM users WHERE username = ?", [$username]);
        if ($existing) jsonResponse(false, null, 'Username already exists.', 409);

        try {
            $db->beginTransaction();
            $roleId = $db->fetch("SELECT id FROM roles WHERE name = 'doctor'")['id'] ?? 3;
            $db->query(
                "INSERT INTO users (clinic_id, role_id, role, username, password, full_name, email, phone, gender, date_of_birth, qualification, specialization, license_number) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
                [$clinicId, $roleId, 'doctor', $username, password_hash($password, PASSWORD_DEFAULT), $fullName, $email ?: null, $phone ?: null, $gender ?: null, $dob ?: null, $qualification ?: null, $specialization ?: null, $licenseNumber ?: null]
            );
            $userId = $db->lastInsertId();

            $db->query(
                "INSERT INTO doctors (user_id, clinic_id, registration_number, consultation_fee, followup_fee, experience_years, default_slot_duration, is_available, bio) VALUES (?,?,?,?,?,?,?,?,?)",
                [$userId, $clinicId, $regNumber ?: null, $consultFee, $followupFee, $expYears, $slotDuration, 1, $bio ?: null]
            );
            $docId = $db->lastInsertId();
            $db->commit();
            jsonResponse(true, ['id' => intval($docId), 'user_id' => intval($userId)], null, 201);
        } catch (Exception $e) {
            $db->rollback();
            jsonResponse(false, null, $e->getMessage(), 500);
        }
        break;

    case 'PUT':
        $id = intval($_GET['id'] ?? 0);
        if (!$id) jsonResponse(false, null, 'Doctor ID required', 400);
        $doc = $db->fetch("SELECT d.*, d.user_id FROM doctors d WHERE d.id = ? AND d.clinic_id = ?", [$id, $clinicId]);
        if (!$doc) jsonResponse(false, null, 'Doctor not found', 404);

        $input = getJsonInput();
        try {
            $db->beginTransaction();
            $db->query(
                "UPDATE users SET full_name=?, email=?, phone=?, gender=?, date_of_birth=?, qualification=?, specialization=?, license_number=?, is_active=? WHERE id=?",
                [trim($input['full_name'] ?? ''), trim($input['email'] ?? '') ?: null, trim($input['phone'] ?? '') ?: null, $input['gender'] ?? null, $input['date_of_birth'] ?? null, trim($input['qualification'] ?? '') ?: null, trim($input['specialization'] ?? '') ?: null, trim($input['license_number'] ?? '') ?: null, intval($input['is_active'] ?? 1), $doc['user_id']]
            );
            if (!empty($input['password'])) {
                $db->query("UPDATE users SET password=? WHERE id=?", [password_hash($input['password'], PASSWORD_DEFAULT), $doc['user_id']]);
            }
            $db->query(
                "UPDATE doctors SET registration_number=?, consultation_fee=?, followup_fee=?, experience_years=?, default_slot_duration=?, is_available=?, bio=? WHERE id=?",
                [trim($input['registration_number'] ?? '') ?: null, floatval($input['consultation_fee'] ?? 0), floatval($input['followup_fee'] ?? 0), intval($input['experience_years'] ?? 0), intval($input['default_slot_duration'] ?? 15), intval($input['is_available'] ?? 1), trim($input['bio'] ?? '') ?: null, $id]
            );
            $db->commit();
            jsonResponse(true, ['message' => 'Doctor updated successfully']);
        } catch (Exception $e) {
            $db->rollback();
            jsonResponse(false, null, $e->getMessage(), 500);
        }
        break;

    case 'DELETE':
        $id = intval($_GET['id'] ?? 0);
        if (!$id) jsonResponse(false, null, 'Doctor ID required', 400);
        $doc = $db->fetch("SELECT user_id FROM doctors WHERE id = ? AND clinic_id = ?", [$id, $clinicId]);
        if (!$doc) jsonResponse(false, null, 'Doctor not found', 404);
        $db->query("UPDATE users SET is_active = 0 WHERE id = ?", [$doc['user_id']]);
        jsonResponse(true, ['message' => 'Doctor deactivated']);
        break;

    default:
        jsonResponse(false, null, 'Method not allowed', 405);
}
