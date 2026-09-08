<?php
/**
 * AJAX: Get Patient Billing History + Pending Appointment Fees
 */
require_once dirname(dirname(__DIR__)) . '/config/session.php';
requireAuth();

header('Content-Type: application/json');

$clinicId = getCurrentClinicId();
$patientId = intval($_GET['patient_id'] ?? 0);

if (!$patientId) {
    echo json_encode(['total_due' => 0, 'history' => [], 'pending_appointments' => []]);
    exit;
}

$db = db();

// Fetch total outstanding due for this patient
$totalDue = $db->fetch(
    "SELECT SUM(due_amount) as total_due FROM invoices WHERE clinic_id = ? AND patient_id = ? AND status IN ('due', 'partial', 'overdue')",
    [$clinicId, $patientId]
)['total_due'] ?? 0;

// Fetch last 5 invoices
$invoices = $db->fetchAll(
    "SELECT id, invoice_number, invoice_date, total_amount, paid_amount, due_amount, status, payment_mode, notes 
     FROM invoices 
     WHERE clinic_id = ? AND patient_id = ? 
     ORDER BY invoice_date DESC, id DESC LIMIT 5",
    [$clinicId, $patientId]
);

// Fetch today's completed appointments with unpaid consultation fees
// (fee_status = 'due' means the consultation fee hasn't been collected yet)
$pendingAppointments = $db->fetchAll(
    "SELECT a.id, a.appointment_date, a.consultation_fee, a.fee_status,
            u.full_name as doctor_name, s.name as specialty
     FROM appointments a
     JOIN doctors d ON a.doctor_id = d.id
     JOIN users u ON d.user_id = u.id
     LEFT JOIN specialties s ON d.specialty_id = s.id
     WHERE a.clinic_id = ?
       AND a.patient_id = ?
       AND a.status = 'completed'
       AND a.fee_status = 'due'
       AND a.consultation_fee > 0",
    [$clinicId, $patientId]
);

echo json_encode([
    'total_due'            => (float)$totalDue,
    'history'              => $invoices,
    'pending_appointments' => $pendingAppointments
]);
