<?php
/**
 * REST API Patients Endpoints - Feature Gen Care
 * GET  /api/v1/patients.php (list/search)
 * GET  /api/v1/patients.php?id={id} (details)
 * POST /api/v1/patients.php (create)
 * PUT  /api/v1/patients.php?id={id} (update)
 * POST /api/v1/patients.php?action=add_vitals
 */
require_once __DIR__ . '/middleware/auth.php';
$auth = authenticateApiRequest();
$db = db();
$clinicId = intval($auth['user']['clinic_id'] ?? 1);
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

try {
    // 1. Record Vitals Action
    if ($action === 'add_vitals' && $method === 'POST') {
        $input = getJsonInput();
        $patientId = intval($input['patient_id'] ?? 0);
        if (!$patientId) {
            jsonResponse(false, null, 'Patient ID is required', 400);
        }

        $weight = !empty($input['weight_kg']) ? floatval($input['weight_kg']) : null;
        $height = !empty($input['height_cm']) ? floatval($input['height_cm']) : null;
        $bmi = null;
        if ($weight && $height && $height > 0) {
            $hMeter = $height / 100;
            $bmi = round($weight / ($hMeter * $hMeter), 1);
        }

        $db->query(
            "INSERT INTO patient_vitals (patient_id, recorded_by, blood_pressure_systolic, blood_pressure_diastolic, 
                                        pulse_rate, temperature, respiratory_rate, spo2, weight_kg, height_cm, bmi, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $patientId,
                $auth['user']['id'],
                $input['systolic'] ?? null,
                $input['diastolic'] ?? null,
                $input['pulse'] ?? null,
                $input['temperature'] ?? null,
                $input['respiratory_rate'] ?? null,
                $input['spo2'] ?? null,
                $weight,
                $height,
                $bmi,
                $input['notes'] ?? null
            ]
        );

        jsonResponse(true, ['message' => 'Vitals recorded successfully', 'bmi' => $bmi]);
    }

    // 2. Fetch Single Patient Details
    if (isset($_GET['id']) && $method === 'GET') {
        $id = intval($_GET['id']);
        $patient = $db->fetch("SELECT * FROM patients WHERE id = ? AND clinic_id = ?", [$id, $clinicId]);
        if (!$patient) {
            jsonResponse(false, null, 'Patient not found', 404);
        }

        // Fetch vitals history
        $vitals = $db->fetchAll(
            "SELECT pv.*, u.full_name as recorded_by_name 
             FROM patient_vitals pv 
             LEFT JOIN users u ON pv.recorded_by = u.id 
             WHERE pv.patient_id = ? 
             ORDER BY pv.recorded_at DESC LIMIT 10",
            [$id]
        );

        // Fetch medical history
        $history = $db->fetchAll("SELECT * FROM patient_medical_history WHERE patient_id = ? ORDER BY diagnosed_date DESC", [$id]);

        // Fetch allergies
        $allergies = $db->fetchAll("SELECT * FROM patient_allergies WHERE patient_id = ?", [$id]);

        // Fetch appointments history
        $appointments = $db->fetchAll(
            "SELECT a.*, u.full_name as doctor_name 
             FROM appointments a 
             JOIN doctors d ON a.doctor_id = d.id 
             JOIN users u ON d.user_id = u.id 
             WHERE a.patient_id = ? 
             ORDER BY a.appointment_date DESC, a.appointment_time DESC LIMIT 10",
            [$id]
        );

        // Fetch prescriptions
        $prescriptions = $db->fetchAll(
            "SELECT pr.*, u.full_name as doctor_name 
             FROM prescriptions pr 
             JOIN doctors d ON pr.doctor_id = d.id 
             JOIN users u ON d.user_id = u.id 
             WHERE pr.patient_id = ? 
             ORDER BY pr.created_at DESC LIMIT 10",
            [$id]
        );

        // Fetch invoices
        $invoices = $db->fetchAll("SELECT * FROM invoices WHERE patient_id = ? ORDER BY invoice_date DESC LIMIT 10", [$id]);

        jsonResponse(true, [
            'patient' => $patient,
            'vitals' => $vitals,
            'medical_history' => $history,
            'allergies' => $allergies,
            'appointments' => $appointments,
            'prescriptions' => $prescriptions,
            'invoices' => $invoices
        ]);
    }

    // 3. Create Patient
    if ($method === 'POST') {
        $input = getJsonInput();
        $firstName = sanitize(trim($input['first_name'] ?? ''));
        $lastName = sanitize(trim($input['last_name'] ?? ''));
        $phone = sanitize(trim($input['phone'] ?? ''));
        $gender = sanitize(trim($input['gender'] ?? 'other'));
        $dob = !empty($input['dob']) ? sanitize($input['dob']) : null;
        $bloodGroup = sanitize($input['blood_group'] ?? '');
        $email = sanitize($input['email'] ?? '');
        $address = sanitize($input['address'] ?? '');

        if (empty($firstName) || empty($phone)) {
            jsonResponse(false, null, 'First name and phone number are required', 400);
        }

        // Generate patient UID
        $clinic = $db->fetch("SELECT patient_id_prefix, patient_id_start_no FROM clinics WHERE id = ?", [$clinicId]);
        $prefix = $clinic['patient_id_prefix'] ?? 'PT';
        $startNo = intval($clinic['patient_id_start_no'] ?? 1000);
        
        $lastPt = $db->fetch("SELECT id FROM patients WHERE clinic_id = ? ORDER BY id DESC LIMIT 1", [$clinicId]);
        $nextNum = $startNo + ($lastPt ? intval($lastPt['id']) + 1 : 1);
        $patientUid = $prefix . '-' . $nextNum;

        $db->query(
            "INSERT INTO patients (clinic_id, patient_uid, first_name, last_name, phone, email, date_of_birth, gender, blood_group, address, registered_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $clinicId, $patientUid, $firstName, $lastName, $phone,
                $email ?: null, $dob, $gender, $bloodGroup ?: null, $address ?: null, $auth['user']['id']
            ]
        );
        $newId = intval($db->lastInsertId());

        jsonResponse(true, [
            'message' => 'Patient registered successfully',
            'patient_id' => $newId,
            'patient_uid' => $patientUid
        ], null, 201);
    }

    // 4. List / Search Patients
    $query = trim($_GET['q'] ?? '');
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(50, max(10, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $where = "clinic_id = ? AND is_active = 1";
    $params = [$clinicId];

    if ($query !== '') {
        $where .= " AND (first_name LIKE ? OR last_name LIKE ? OR phone LIKE ? OR patient_uid LIKE ?)";
        $searchTerm = "%{$query}%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }

    $total = $db->fetch("SELECT COUNT(*) as cnt FROM patients WHERE {$where}", $params)['cnt'] ?? 0;

    $patients = $db->fetchAll(
        "SELECT id, patient_uid, first_name, last_name, phone, email, gender, date_of_birth, blood_group, created_at
         FROM patients 
         WHERE {$where} 
         ORDER BY id DESC 
         LIMIT {$limit} OFFSET {$offset}",
        $params
    );

    jsonResponse(true, $patients, null, 200, [
        'page' => $page,
        'limit' => $limit,
        'total' => intval($total),
        'has_more' => ($offset + count($patients)) < $total
    ]);

} catch (Exception $e) {
    jsonResponse(false, null, 'Patient API Error: ' . $e->getMessage(), 500);
}
