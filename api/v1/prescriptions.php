<?php
/**
 * REST API Prescriptions Endpoints - Feature Gen Care
 * GET  /api/v1/prescriptions.php (list)
 * GET  /api/v1/prescriptions.php?id={id} (full prescription details)
 * GET  /api/v1/prescriptions.php?action=search_medicines&q={q} (medicine auto-complete)
 * POST /api/v1/prescriptions.php (create prescription)
 */
require_once __DIR__ . '/middleware/auth.php';
$auth = authenticateApiRequest();
$db = db();
$clinicId = intval($auth['user']['clinic_id'] ?? 1);
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

try {
    // 1. Search Medicines Auto-complete
    if ($action === 'search_medicines') {
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 2) {
            jsonResponse(true, []);
        }
        $meds = $db->fetchAll(
            "SELECT id, name, generic_name, dosage_form, strength, category 
             FROM medicines 
             WHERE is_active = 1 AND (name LIKE ? OR generic_name LIKE ?) 
             ORDER BY name ASC LIMIT 20",
            ["%{$q}%", "%{$q}%"]
        );
        jsonResponse(true, $meds);
    }

    // 2. View Single Prescription
    if (isset($_GET['id']) && $method === 'GET') {
        $id = intval($_GET['id']);
        $rx = $db->fetch(
            "SELECT pr.*, 
                    p.patient_uid, p.first_name, p.last_name, p.gender, p.date_of_birth, p.phone as patient_phone,
                    d.specialty, d.qualification, u.full_name as doctor_name
             FROM prescriptions pr
             JOIN patients p ON pr.patient_id = p.id
             JOIN doctors d ON pr.doctor_id = d.id
             JOIN users u ON d.user_id = u.id
             WHERE pr.id = ? AND pr.clinic_id = ?",
            [$id, $clinicId]
        );
        if (!$rx) {
            jsonResponse(false, null, 'Prescription not found', 404);
        }

        $medicines = $db->fetchAll("SELECT * FROM prescription_medicines WHERE prescription_id = ?", [$id]);
        $tests = $db->fetchAll("SELECT * FROM prescription_tests WHERE prescription_id = ?", [$id]);

        jsonResponse(true, [
            'prescription' => $rx,
            'medicines' => $medicines,
            'tests' => $tests
        ]);
    }

    // 3. Create Prescription
    if ($method === 'POST') {
        $input = getJsonInput();
        $patientId = intval($input['patient_id'] ?? 0);
        $doctorId = intval($input['doctor_id'] ?? 0);
        $appointmentId = !empty($input['appointment_id']) ? intval($input['appointment_id']) : null;
        $complaints = sanitize($input['chief_complaints'] ?? '');
        $diagnosis = sanitize($input['diagnosis'] ?? '');
        $clinicalNotes = sanitize($input['clinical_notes'] ?? '');
        $advice = sanitize($input['advice'] ?? '');
        $followUp = !empty($input['follow_up_date']) ? sanitize($input['follow_up_date']) : null;
        $medicines = $input['medicines'] ?? [];
        $tests = $input['tests'] ?? [];

        // Auto-resolve doctor ID if current user is a doctor
        if (!$doctorId) {
            $docRecord = $db->fetch("SELECT id FROM doctors WHERE user_id = ? AND clinic_id = ?", [$auth['user']['id'], $clinicId]);
            if ($docRecord) {
                $doctorId = intval($docRecord['id']);
            } else {
                $firstDoc = $db->fetch("SELECT id FROM doctors WHERE clinic_id = ? AND is_active = 1 LIMIT 1", [$clinicId]);
                $doctorId = intval($firstDoc['id'] ?? 1);
            }
        }

        if (!$patientId) {
            jsonResponse(false, null, 'Patient ID is required', 400);
        }

        $db->beginTransaction();

        $db->query(
            "INSERT INTO prescriptions (clinic_id, appointment_id, patient_id, doctor_id, chief_complaints, diagnosis, clinical_notes, advice, follow_up_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$clinicId, $appointmentId, $patientId, $doctorId, $complaints, $diagnosis, $clinicalNotes, $advice, $followUp]
        );
        $rxId = intval($db->lastInsertId());

        // Insert Medicines
        if (is_array($medicines)) {
            foreach ($medicines as $m) {
                $medName = sanitize(trim($m['medicine_name'] ?? ''));
                if (empty($medName)) continue;
                $db->query(
                    "INSERT INTO prescription_medicines (prescription_id, medicine_id, medicine_name, dosage, frequency, duration, instructions)
                     VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [
                        $rxId,
                        !empty($m['medicine_id']) ? intval($m['medicine_id']) : null,
                        $medName,
                        sanitize($m['dosage'] ?? '1 Tablet'),
                        sanitize($m['frequency'] ?? '1-0-1 (After Food)'),
                        sanitize($m['duration'] ?? '5 Days'),
                        sanitize($m['instructions'] ?? '')
                    ]
                );
            }
        }

        // Insert Lab Tests
        if (is_array($tests)) {
            foreach ($tests as $t) {
                $testName = is_string($t) ? sanitize(trim($t)) : sanitize(trim($t['test_name'] ?? ''));
                if (empty($testName)) continue;
                $db->query(
                    "INSERT INTO prescription_tests (prescription_id, test_name, instructions) VALUES (?, ?, ?)",
                    [$rxId, $testName, is_array($t) ? sanitize($t['instructions'] ?? '') : null]
                );
            }
        }

        // If appointment was tied, mark completed
        if ($appointmentId) {
            $db->query("UPDATE appointments SET status = 'completed' WHERE id = ? AND clinic_id = ?", [$appointmentId, $clinicId]);
        }

        $db->commit();

        jsonResponse(true, [
            'message' => 'Prescription created successfully',
            'prescription_id' => $rxId
        ], null, 201);
    }

    // 4. List Prescriptions (Default GET)
    $patientId = !empty($_GET['patient_id']) ? intval($_GET['patient_id']) : null;
    $where = "pr.clinic_id = ?";
    $params = [$clinicId];

    if ($patientId) {
        $where .= " AND pr.patient_id = ?";
        $params[] = $patientId;
    }

    $prescriptions = $db->fetchAll(
        "SELECT pr.id, pr.created_at, pr.diagnosis, pr.follow_up_date,
                p.id as patient_id, p.patient_uid, p.first_name, p.last_name,
                u.full_name as doctor_name
         FROM prescriptions pr
         JOIN patients p ON pr.patient_id = p.id
         JOIN doctors d ON pr.doctor_id = d.id
         JOIN users u ON d.user_id = u.id
         WHERE {$where}
         ORDER BY pr.id DESC LIMIT 50",
        $params
    );

    jsonResponse(true, $prescriptions);

} catch (Exception $e) {
    if ($db && $db->getConnection()->inTransaction()) {
        $db->rollback();
    }
    jsonResponse(false, null, 'Prescription API Error: ' . $e->getMessage(), 500);
}
