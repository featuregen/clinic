<?php
/**
 * Save Dental Treatment
 */
require_once dirname(dirname(__DIR__)) . '/config/session.php';

header('Content-Type: application/json');

// AJAX-safe permission check (returns JSON instead of redirect)
if (!hasPermission('dental.manage') && !hasPermission('dental.edit')) {
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db = db();
        $clinicId = getCurrentClinicId();
        $action = sanitize($_POST['action'] ?? '');
        
        if ($action === 'delete') {
            $id = intval($_POST['id'] ?? 0);
            if ($id) {
                $db->query("DELETE FROM dental_treatments WHERE id = ?", [$id]);
                echo json_encode(['success' => true]);
                exit;
            }
        }

        $patientId = intval($_POST['patient_id']);
        $toothNumber = intval($_POST['tooth_number'] ?? 0);
        $procedure = sanitize($_POST['procedure_name'] ?? '');
        $status = sanitize($_POST['status'] ?? 'planned');
        $cost = floatval($_POST['cost'] ?? 0);
        
        // Resolve doctor_id properly: must be a valid doctors.id, not users.id
        $doctorId = getCurrentDoctorId();
        if (!$doctorId) {
            // Admin/staff — find the first doctor in this clinic as fallback
            $firstDoc = $db->fetch(
                "SELECT d.id FROM doctors d WHERE d.clinic_id = ? ORDER BY d.id ASC LIMIT 1",
                [$clinicId]
            );
            $doctorId = $firstDoc ? intval($firstDoc['id']) : null;
        }
        
        if (!$doctorId) {
            echo json_encode(['success' => false, 'error' => 'No doctor found. Please add a doctor first.']);
            exit;
        }
        
        if (empty($procedure)) {
            echo json_encode(['success' => false, 'error' => 'Procedure name is required.']);
            exit;
        }
        
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
        
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        error_log("save_treatment error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
