<?php
/**
 * Get Template Data (AJAX endpoint)
 */
require_once dirname(dirname(__DIR__)) . '/config/session.php';

header('Content-Type: application/json');

$db = db();
$clinicId = getCurrentClinicId();
$id = intval($_GET['id'] ?? 0);

if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Invalid template ID']);
    exit;
}

$template = $db->fetch(
    "SELECT * FROM prescription_templates WHERE id = ? AND (clinic_id = ? OR scope = 'clinic')", 
    [$id, $clinicId]
);

if (!$template) {
    echo json_encode(['success' => false, 'error' => 'Template not found']);
    exit;
}

$data = json_decode($template['template_data'] ?? '{}', true);

echo json_encode([
    'success' => true,
    'template' => [
        'id' => $template['id'],
        'name' => $template['name'],
        'chief_complaints' => $data['chief_complaints'] ?? '',
        'diagnosis' => $data['diagnosis'] ?? '',
        'examination' => $data['examination'] ?? '',
        'advice' => $data['advice'] ?? '',
        'medicines' => $data['medicines'] ?? [],
        'lab_tests' => $data['lab_tests'] ?? []
    ]
]);
