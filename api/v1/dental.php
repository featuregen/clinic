<?php
/**
 * REST API Dental Module Endpoints - Feature Gen Care
 * GET  /api/v1/dental.php?patient_id={id} (fetch patient tooth chart & treatments)
 * POST /api/v1/dental.php?action=save_chart (save/update tooth condition)
 * POST /api/v1/dental.php?action=save_treatment (record procedure/treatment)
 */
require_once __DIR__ . '/middleware/auth.php';
$auth = authenticateApiRequest();
$db = db();
$clinicId = intval($auth['user']['clinic_id'] ?? 1);
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

try {
    // 1. Save Tooth Condition on Dental Chart
    if ($action === 'save_chart' && $method === 'POST') {
        $input = getJsonInput();
        $patientId = intval($input['patient_id'] ?? 0);
        $toothNo = intval($input['tooth_number'] ?? 0);
        $condition = sanitize($input['condition'] ?? 'healthy');
        $surface = sanitize($input['surface'] ?? '');
        $notes = sanitize($input['notes'] ?? '');

        if (!$patientId || $toothNo < 1 || $toothNo > 85) {
            jsonResponse(false, null, 'Valid patient ID and tooth number (FDI notation 11-85) are required', 400);
        }

        $existing = $db->fetch(
            "SELECT id FROM dental_charts WHERE patient_id = ? AND tooth_number = ?",
            [$patientId, $toothNo]
        );

        if ($existing) {
            $db->query(
                "UPDATE dental_charts SET `condition` = ?, surface = ?, notes = ?, updated_at = NOW() WHERE id = ?",
                [$condition, $surface ?: null, $notes ?: null, $existing['id']]
            );
        } else {
            $db->query(
                "INSERT INTO dental_charts (patient_id, tooth_number, `condition`, surface, notes) VALUES (?, ?, ?, ?, ?)",
                [$patientId, $toothNo, $condition, $surface ?: null, $notes ?: null]
            );
        }

        jsonResponse(true, ['message' => "Tooth #{$toothNo} updated to {$condition}"]);
    }

    // 2. Save Dental Treatment
    if ($action === 'save_treatment' && $method === 'POST') {
        $input = getJsonInput();
        $patientId = intval($input['patient_id'] ?? 0);
        $toothNo = !empty($input['tooth_number']) ? intval($input['tooth_number']) : null;
        $procName = sanitize(trim($input['procedure_name'] ?? ''));
        $cost = floatval($input['cost'] ?? 0);
        $status = sanitize($input['status'] ?? 'planned');
        $notes = sanitize($input['notes'] ?? '');

        if (!$patientId || empty($procName)) {
            jsonResponse(false, null, 'Patient ID and procedure name are required', 400);
        }

        // Auto resolve doctor
        $doctorId = null;
        $doc = $db->fetch("SELECT id FROM doctors WHERE user_id = ? AND clinic_id = ?", [$auth['user']['id'], $clinicId]);
        if ($doc) $doctorId = $doc['id'];

        $db->query(
            "INSERT INTO dental_treatments (patient_id, doctor_id, tooth_number, procedure_name, description, cost, status, treatment_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE())",
            [$patientId, $doctorId, $toothNo, $procName, $notes ?: null, $cost, $status]
        );

        jsonResponse(true, ['message' => 'Dental treatment recorded successfully']);
    }

    // 3. Fetch Patient Tooth Chart & Treatments (Default GET)
    $patientId = intval($_GET['patient_id'] ?? 0);
    if (!$patientId) {
        jsonResponse(false, null, 'Patient ID is required', 400);
    }

    $charts = $db->fetchAll(
        "SELECT tooth_number, `condition`, surface, notes, updated_at FROM dental_charts WHERE patient_id = ?",
        [$patientId]
    );

    // Map by tooth_number for O(1) mobile UI lookup
    $chartMap = [];
    foreach ($charts as $c) {
        $chartMap[$c['tooth_number']] = $c;
    }

    $treatments = $db->fetchAll(
        "SELECT dt.*, u.full_name as doctor_name 
         FROM dental_treatments dt 
         LEFT JOIN doctors d ON dt.doctor_id = d.id 
         LEFT JOIN users u ON d.user_id = u.id 
         WHERE dt.patient_id = ? 
         ORDER BY dt.treatment_date DESC, dt.id DESC",
        [$patientId]
    );

    jsonResponse(true, [
        'patient_id' => $patientId,
        'tooth_chart' => $chartMap,
        'treatments' => $treatments
    ]);

} catch (Exception $e) {
    jsonResponse(false, null, 'Dental API Error: ' . $e->getMessage(), 500);
}
