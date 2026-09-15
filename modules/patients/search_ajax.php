<?php
/**
 * Patient Search AJAX - Feature Gen Care
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
    $cleanPhone = preg_replace('/[^0-9]/', '', $search);
    if (!empty($cleanPhone) && strlen($cleanPhone) >= 3) {
        $where .= " AND (first_name LIKE ? OR last_name LIKE ? OR CONCAT(first_name, ' ', IFNULL(last_name, '')) LIKE ? OR patient_uid LIKE ? OR phone LIKE ? OR phone LIKE ?)";
        $s = "%$search%";
        $sp = "%$cleanPhone%";
        $params = array_merge($params, [$s, $s, $s, $s, $s, $sp]);
    } else {
        $where .= " AND (first_name LIKE ? OR last_name LIKE ? OR CONCAT(first_name, ' ', IFNULL(last_name, '')) LIKE ? OR patient_uid LIKE ? OR phone LIKE ?)";
        $s = "%$search%";
        $params = array_merge($params, [$s, $s, $s, $s, $s]);
    }
}

$orderBy = $search ? "ORDER BY first_name ASC" : "ORDER BY id DESC";

$patients = $db->fetchAll(
    "SELECT id, patient_uid, first_name, last_name, phone, gender, date_of_birth, age, blood_group 
     FROM patients $where $orderBy LIMIT 30",
    $params
);

foreach ($patients as &$p) {
    if (empty($p['age']) && !empty($p['date_of_birth'])) {
        $p['age'] = calculateAge($p['date_of_birth']);
    }
}

echo json_encode($patients);
