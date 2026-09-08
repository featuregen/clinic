<?php
/**
 * Save Dental Treatment
 */
require_once dirname(dirname(__DIR__)) . '/config/session.php';
requirePermission('dental.edit');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db = db();
        $patientId = intval($_POST['patient_id']);
        $toothNumber = intval($_POST['tooth_number'] ?? 0);
        $procedure = sanitize($_POST['procedure_name']);
        $status = sanitize($_POST['status']);
        $cost = floatval($_POST['cost'] ?? 0);
        $doctorId = getCurrentDoctorId() ?: $_SESSION['user_id']; // Fallback if not doctor role
        
        $id = intval($_POST['id'] ?? 0);

        if ($id) {
            $db->query(
                "UPDATE dental_treatments SET tooth_number=?, procedure_name=?, status=?, cost=? WHERE id=?",
                [$toothNumber, $procedure, $status, $cost, $id]
            );
        } else {
            $db->query(
                "INSERT INTO dental_treatments (patient_id, doctor_id, tooth_number, procedure_name, status, cost, treatment_date) 
                 VALUES (?, ?, ?, ?, ?, ?, CURDATE())",
                [$patientId, $doctorId, $toothNumber, $procedure, $status, $cost]
            );
        }
        
        // If completed and linked to a tooth, update tooth status? 
        // Logic: specific procedures might imply status change (e.g. Extraction -> Missing).
        // For now, keep it manual status update.
        
        jsonResponse(['success' => true]);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'error' => $e->getMessage()]);
    }
}
