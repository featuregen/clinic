<?php
/**
 * Patient Search AJAX - Advanced Clinic Suite
 */
require_once dirname(dirname(__DIR__)) . '/config/session.php';
require_once dirname(dirname(__DIR__)) . '/config/database.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

header('Content-Type: application/json');

$db = db();
$clinicId = getCurrentClinicId();
$search = sanitize($_GET['q'] ?? '');

$where = "WHERE clinic_id = ? AND is_active = 1";
$params = [$clinicId];

if ($search) {
    $where .= " AND (first_name LIKE ? OR last_name LIKE ? OR patient_uid LIKE ? OR phone LIKE ?)";
    $s = "%$search%";
    $params = array_merge($params, [$s, $s, $s, $s]);
}

$patients = $db->fetchAll(
    "SELECT id, patient_uid, first_name, last_name, phone, gender, date_of_birth 
     FROM patients $where ORDER BY first_name LIMIT 50",
    $params
);

echo json_encode($patients);
