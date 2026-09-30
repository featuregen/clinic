<?php
/**
 * Save Dental Chart Status
 */
require_once dirname(dirname(__DIR__)) . '/config/session.php';
requirePermission('dental.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db = db();
        $patientId = intval($_POST['patient_id']);
        $toothNumber = intval($_POST['tooth_number']);
        $status = sanitize($_POST['status']);
        $notes = sanitize($_POST['notes'] ?? '');
        
        $toothType = sanitize($_POST['tooth_type'] ?? '');
        if (!in_array($toothType, ['adult', 'child'])) {
            $toothType = ($toothNumber >= 51 && $toothNumber <= 85) ? 'child' : 'adult';
        }
        
        // Check if exists
        $exists = $db->fetch("SELECT id FROM dental_charts WHERE patient_id = ? AND tooth_number = ? AND tooth_type = ?", [$patientId, $toothNumber, $toothType]);
        if (!$exists) {
            $exists = $db->fetch("SELECT id FROM dental_charts WHERE patient_id = ? AND tooth_number = ?", [$patientId, $toothNumber]);
        }
        
        if ($exists) {
            $db->query(
                "UPDATE dental_charts SET status = ?, tooth_type = ?, notes = ?, last_treatment_date = CURDATE() WHERE id = ?",
                [$status, $toothType, $notes, $exists['id']]
            );
        } else {
            $db->query(
                "INSERT INTO dental_charts (patient_id, tooth_number, tooth_type, status, notes, last_treatment_date) VALUES (?, ?, ?, ?, ?, CURDATE())",
                [$patientId, $toothNumber, $toothType, $status, $notes]
            );
        }
        
        jsonResponse(['success' => true]);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'error' => $e->getMessage()]);
    }
}
