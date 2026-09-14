<?php
/**
 * REST API Vaccination Module Endpoints - Feature Gen Care
 * GET  /api/v1/vaccination.php?action=master (all vaccines & schedules)
 * GET  /api/v1/vaccination.php?patient_id={id} (patient vaccination status)
 * POST /api/v1/vaccination.php?action=record (administer vaccine)
 */
require_once __DIR__ . '/middleware/auth.php';
$auth = authenticateApiRequest();
$db = db();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

try {
    // 1. Master List of Vaccines & Standard Schedules
    if ($action === 'master') {
        $vaccines = $db->fetchAll(
            "SELECT v.*, vs.id as schedule_id, vs.dose_number, vs.recommended_age_months, vs.recommended_age_label, vs.is_mandatory
             FROM vaccines v
             LEFT JOIN vaccine_schedules vs ON v.id = vs.vaccine_id
             WHERE v.is_active = 1
             ORDER BY vs.recommended_age_months ASC, v.name ASC"
        );
        jsonResponse(true, $vaccines);
    }

    // 2. Record Administered Vaccine
    if ($action === 'record' && $method === 'POST') {
        $input = getJsonInput();
        $patientId = intval($input['patient_id'] ?? 0);
        $vaccineId = intval($input['vaccine_id'] ?? 0);
        $doseNo = intval($input['dose_number'] ?? 1);
        $batchNo = sanitize($input['batch_number'] ?? '');
        $site = sanitize($input['site'] ?? 'Left Upper Arm');
        $notes = sanitize($input['notes'] ?? '');

        if (!$patientId || !$vaccineId) {
            jsonResponse(false, null, 'Patient ID and Vaccine ID are required', 400);
        }

        $db->query(
            "INSERT INTO patient_vaccinations (patient_id, vaccine_id, dose_number, vaccination_date, status, batch_number, administered_by, site, notes)
             VALUES (?, ?, ?, CURDATE(), 'administered', ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE vaccination_date = CURDATE(), status = 'administered', batch_number = VALUES(batch_number), administered_by = VALUES(administered_by)",
            [$patientId, $vaccineId, $doseNo, $batchNo ?: null, $auth['user']['id'], $site ?: null, $notes ?: null]
        );

        jsonResponse(true, ['message' => 'Vaccination recorded successfully']);
    }

    // 3. Patient Vaccination Schedule Status
    $patientId = intval($_GET['patient_id'] ?? 0);
    if (!$patientId) {
        jsonResponse(false, null, 'Patient ID is required', 400);
    }

    $records = $db->fetchAll(
        "SELECT v.name as vaccine_name, v.disease, vs.dose_number, vs.recommended_age_label,
                pv.id as record_id, pv.vaccination_date, pv.status, pv.batch_number, u.full_name as administered_by_name
         FROM vaccines v
         JOIN vaccine_schedules vs ON v.id = vs.vaccine_id
         LEFT JOIN patient_vaccinations pv ON v.id = pv.vaccine_id AND vs.dose_number = pv.dose_number AND pv.patient_id = ?
         WHERE v.is_active = 1
         ORDER BY vs.recommended_age_months ASC, v.name ASC",
        [$patientId]
    );

    jsonResponse(true, $records);

} catch (Exception $e) {
    jsonResponse(false, null, 'Vaccination API Error: ' . $e->getMessage(), 500);
}
