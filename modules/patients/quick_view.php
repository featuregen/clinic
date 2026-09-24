<?php
/**
 * Patient Quick View API
 * Returns JSON data for the slide-in quick view panel
 */
require_once dirname(dirname(__DIR__)) . '/config/session.php';
requireAuth();
requirePermission('patients.view');

header('Content-Type: application/json');

$db = db();
$clinicId = getCurrentClinicId();
$patientId = intval($_GET['id'] ?? 0);

if (!$patientId) {
    echo json_encode(['error' => 'Missing patient ID']);
    exit;
}

$patient = $db->fetch("SELECT * FROM patients WHERE id = ? AND clinic_id = ?", [$patientId, $clinicId]);
if (!$patient) {
    echo json_encode(['error' => 'Patient not found']);
    exit;
}

$age = $patient['date_of_birth'] ? calculateAge($patient['date_of_birth']) : ($patient['age'] ?? null);

// Last visit
$lastVisit = $db->fetch(
    "SELECT appointment_date, appointment_time FROM appointments WHERE patient_id = ? AND status IN ('completed','checked_in','in_progress') ORDER BY appointment_date DESC, appointment_time DESC LIMIT 1",
    [$patientId]
);

// Next upcoming appointment
$nextAppt = $db->fetch(
    "SELECT a.appointment_date, a.appointment_time, u.full_name as doctor_name 
     FROM appointments a JOIN doctors d ON a.doctor_id = d.id JOIN users u ON d.user_id = u.id
     WHERE a.patient_id = ? AND a.appointment_date >= CURDATE() AND a.status NOT IN ('cancelled','completed') 
     ORDER BY a.appointment_date ASC, a.appointment_time ASC LIMIT 1",
    [$patientId]
);

// Visit count
$visitCount = (int)($db->fetch("SELECT COUNT(*) as c FROM appointments WHERE patient_id = ?", [$patientId])['c'] ?? 0);

// Recent prescriptions (last 3)
$recentPrescriptions = [];
try {
    $recentPrescriptions = $db->fetchAll(
        "SELECT pr.id, pr.prescription_date, u.full_name as doctor_name,
                (SELECT GROUP_CONCAT(pm.medicine_name SEPARATOR ', ') FROM prescription_medicines pm WHERE pm.prescription_id = pr.id LIMIT 3) as medicines
         FROM prescriptions pr
         JOIN doctors d ON pr.doctor_id = d.id JOIN users u ON d.user_id = u.id
         WHERE pr.patient_id = ? ORDER BY pr.prescription_date DESC LIMIT 3",
        [$patientId]
    );
} catch (Exception $e) {}

// Allergies
$allergies = [];
try {
    $allergies = $db->fetchAll("SELECT allergen, severity FROM patient_allergies WHERE patient_id = ? ORDER BY severity DESC LIMIT 5", [$patientId]);
} catch (Exception $e) {}

// Medical conditions
$conditions = [];
try {
    $conditions = $db->fetchAll("SELECT condition_name FROM patient_medical_history WHERE patient_id = ? AND status = 'active' LIMIT 5", [$patientId]);
} catch (Exception $e) {}

// Outstanding dues
$outstandingDue = 0;
try {
    $outstandingDue = (float)($db->fetch(
        "SELECT COALESCE(SUM(due_amount), 0) as due FROM invoices WHERE patient_id = ? AND status IN ('due','partial','overdue')",
        [$patientId]
    )['due'] ?? 0);
} catch (Exception $e) {}

$address = array_filter([
    $patient['address'] ?? null,
    $patient['city'] ?? null,
    $patient['state'] ?? null,
    $patient['pincode'] ?? null
]);

echo json_encode([
    'id' => $patient['id'],
    'name' => trim($patient['first_name'] . ' ' . ($patient['last_name'] ?? '')),
    'initials' => strtoupper(substr($patient['first_name'], 0, 1) . substr($patient['last_name'] ?? '', 0, 1)),
    'patient_uid' => $patient['patient_uid'],
    'is_active' => (bool)$patient['is_active'],
    'gender' => $patient['gender'] ?? null,
    'age' => $age,
    'phone' => $patient['phone'],
    'alt_phone' => $patient['alt_phone'] ?? null,
    'email' => $patient['email'] ?? null,
    'blood_group' => $patient['blood_group'] ?? null,
    'address' => implode(', ', $address) ?: null,
    'occupation' => $patient['occupation'] ?? null,
    'marital_status' => $patient['marital_status'] ?? null,
    'risk_level' => $patient['risk_level'] ?? 'Low',
    'visit_count' => $visitCount,
    'last_visit' => $lastVisit ? $lastVisit['appointment_date'] : null,
    'next_appointment' => $nextAppt ? [
        'date' => $nextAppt['appointment_date'],
        'time' => $nextAppt['appointment_time'],
        'doctor' => $nextAppt['doctor_name']
    ] : null,
    'recent_prescriptions' => $recentPrescriptions,
    'allergies' => $allergies,
    'conditions' => array_column($conditions, 'condition_name'),
    'outstanding_due' => $outstandingDue,
    'registered' => $patient['created_at']
]);
