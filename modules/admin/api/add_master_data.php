<?php
/**
 * API: Add Master Data (Specialty, Department, etc.)
 * Used for Quick Add from other forms
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config/session.php';
requireAuth();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$db = db();
$clinicId = getCurrentClinicId();
$type = $_POST['type'] ?? '';
$name = sanitize($_POST['name'] ?? '');

$code = sanitize($_POST['code'] ?? '');
$description = sanitize($_POST['description'] ?? '');

if (empty($name)) {
    http_response_code(400);
    echo json_encode(['error' => 'Name is required']);
    exit;
}

try {
    $id = 0;
    
    switch ($type) {
        case 'department':
            $db->query("INSERT INTO departments (clinic_id, name, description, is_active) VALUES (?, ?, ?, 1)", [$clinicId, $name, $description]);
            $id = $db->lastInsertId();
            logAudit('create', 'master_data', 'departments', $id, null, ['name' => $name, 'description' => $description], "Quick created department '{$name}' (ID: {$id})");
            break;
            
        case 'specialty':
            $db->query("INSERT INTO specialties (name, code, description, is_active) VALUES (?, ?, ?, 1)", [$name, $code, $description]);
            $id = $db->lastInsertId();
            logAudit('create', 'master_data', 'specialties', $id, null, ['name' => $name, 'code' => $code, 'description' => $description], "Quick created specialty '{$name}' (ID: {$id})");
            break;
            
        default:
            throw new Exception('Invalid Type');
    }

    echo json_encode([
        'success' => true,
        'id' => $id,
        'name' => $name,
        'type' => $type
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
