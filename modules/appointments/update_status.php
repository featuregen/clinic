<?php
/**
 * Update Appointment Status (AJAX)
 */
require_once dirname(dirname(__DIR__)) . '/config/session.php';
require_once dirname(dirname(__DIR__)) . '/config/database.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Invalid request method', 405);
}

$data = json_decode(file_get_contents('php://input'), true);
$id = intval($data['id'] ?? 0);
$status = sanitize($data['status'] ?? '');

if (!$id || !$status) {
    errorResponse('Missing required fields');
}

$validStatuses = array_keys(APPOINTMENT_STATUS);
if (!in_array($status, $validStatuses)) {
    errorResponse('Invalid status');
}

try {
    $db = db();
    $db->query("UPDATE appointments SET status = ? WHERE id = ? AND clinic_id = ?", [$status, $id, getCurrentClinicId()]);
    
    // If completed, mark consultation fee as paid
    if ($status === 'completed') {
        $db->query("UPDATE appointments SET fee_status = 'paid' WHERE id = ?", [$id]);
    }
    
    logAudit('update', 'appointments', 'appointment', $id, null, ['status' => $status]);
    successResponse('Appointment status updated to ' . APPOINTMENT_STATUS[$status]);
} catch (Exception $e) {
    errorResponse('Failed to update status: ' . $e->getMessage());
}
