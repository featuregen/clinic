<?php
/**
 * REST API Appointments Endpoints - Feature Gen Care
 * GET  /api/v1/appointments.php (list/calendar by date, status, doctor)
 * POST /api/v1/appointments.php (book appointment)
 * PUT  /api/v1/appointments.php?id={id}&action=status (update status)
 * GET  /api/v1/appointments.php?action=doctors (list doctors for appointment booking)
 */
require_once __DIR__ . '/middleware/auth.php';
$auth = authenticateApiRequest();
$db = db();
$clinicId = intval($auth['user']['clinic_id'] ?? 1);
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

try {
    // 1. List Available Doctors
    if ($action === 'doctors') {
        $doctors = $db->fetchAll(
            "SELECT d.id, d.specialty, d.qualification, d.consultation_fee,
                    u.full_name, u.email, u.phone, u.profile_image
             FROM doctors d
             JOIN users u ON d.user_id = u.id
             WHERE d.clinic_id = ? AND d.is_active = 1",
            [$clinicId]
        );
        jsonResponse(true, $doctors);
    }

    // 2. Update Appointment Status
    if ($action === 'status' || $method === 'PUT') {
        $input = getJsonInput();
        $apptId = intval($_GET['id'] ?? ($input['id'] ?? 0));
        $newStatus = sanitize($input['status'] ?? ($_GET['status'] ?? ''));

        $validStatuses = ['scheduled', 'confirmed', 'checked_in', 'in_consultation', 'completed', 'cancelled', 'no_show'];
        if (!$apptId || !in_array($newStatus, $validStatuses)) {
            jsonResponse(false, null, 'Valid appointment ID and status are required', 400);
        }

        $db->query("UPDATE appointments SET status = ? WHERE id = ? AND clinic_id = ?", [$newStatus, $apptId, $clinicId]);
        logAudit('update', 'appointments', 'appointment', $apptId, null, ['status' => $newStatus], "Updated status to {$newStatus}");

        jsonResponse(true, ['message' => 'Status updated successfully', 'status' => $newStatus]);
    }

    // 3. Book Appointment
    if ($method === 'POST') {
        $input = getJsonInput();
        $patientId = intval($input['patient_id'] ?? 0);
        $doctorId = intval($input['doctor_id'] ?? 0);
        $date = sanitize($input['appointment_date'] ?? date('Y-m-d'));
        $time = sanitize($input['appointment_time'] ?? date('H:i:s'));
        $reason = sanitize($input['reason'] ?? '');
        $type = sanitize($input['appointment_type'] ?? 'consultation');
        $branchId = intval($input['branch_id'] ?? ($auth['user']['branch_id'] ?? 1));

        if (!$patientId || !$doctorId) {
            jsonResponse(false, null, 'Patient and Doctor are required', 400);
        }

        // Generate token number for this doctor on this day
        $lastToken = $db->fetch(
            "SELECT MAX(token_number) as max_t FROM appointments WHERE clinic_id = ? AND doctor_id = ? AND appointment_date = ?",
            [$clinicId, $doctorId, $date]
        )['max_t'] ?? 0;
        $tokenNumber = intval($lastToken) + 1;

        $db->query(
            "INSERT INTO appointments (clinic_id, branch_id, patient_id, doctor_id, appointment_date, appointment_time, token_number, appointment_type, status, visit_reason, booked_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'scheduled', ?, ?)",
            [
                $clinicId, $branchId, $patientId, $doctorId, $date, $time,
                $tokenNumber, $type, $reason, $auth['user']['id']
            ]
        );
        $newApptId = intval($db->lastInsertId());

        jsonResponse(true, [
            'message' => 'Appointment booked successfully',
            'appointment_id' => $newApptId,
            'token_number' => $tokenNumber,
            'date' => $date,
            'time' => $time
        ], null, 201);
    }

    // 4. List Appointments (Default GET)
    $date = sanitize($_GET['date'] ?? date('Y-m-d'));
    $doctorId = !empty($_GET['doctor_id']) ? intval($_GET['doctor_id']) : null;
    $status = !empty($_GET['status']) ? sanitize($_GET['status']) : null;

    $where = "a.clinic_id = ? AND a.appointment_date = ?";
    $params = [$clinicId, $date];

    if ($doctorId) {
        $where .= " AND a.doctor_id = ?";
        $params[] = $doctorId;
    }
    if ($status) {
        $where .= " AND a.status = ?";
        $params[] = $status;
    }

    $appointments = $db->fetchAll(
        "SELECT a.id, a.token_number, a.appointment_date, a.appointment_time, a.status, a.visit_reason, a.appointment_type,
                p.id as patient_id, p.patient_uid, p.first_name, p.last_name, p.phone as patient_phone, p.gender, p.date_of_birth,
                d.id as doctor_id, d.specialty, u.full_name as doctor_name
         FROM appointments a
         JOIN patients p ON a.patient_id = p.id
         JOIN doctors d ON a.doctor_id = d.id
         JOIN users u ON d.user_id = u.id
         WHERE {$where}
         ORDER BY a.appointment_time ASC, a.token_number ASC",
        $params
    );

    jsonResponse(true, $appointments, null, 200, ['date' => $date, 'count' => count($appointments)]);

} catch (Exception $e) {
    jsonResponse(false, null, 'Appointments API Error: ' . $e->getMessage(), 500);
}
