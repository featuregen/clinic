<?php
/**
 * REST API Reports Endpoints - Feature Gen Care
 * GET /api/v1/reports.php?from=YYYY-MM-DD&to=YYYY-MM-DD (daily summary)
 */
require_once __DIR__ . '/middleware/auth.php';
$auth = authenticateApiRequest();
$db = db();
$clinicId = intval($auth['user']['clinic_id'] ?? 1);

$fromDate = $_GET['from'] ?? date('Y-m-d');
$toDate = $_GET['to'] ?? date('Y-m-d');

try {
    $appts = $db->fetch("SELECT COUNT(*) as total, SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as completed, SUM(CASE WHEN status='cancelled' THEN 1 ELSE 0 END) as cancelled FROM appointments WHERE clinic_id=? AND appointment_date BETWEEN ? AND ?", [$clinicId, $fromDate, $toDate]);
    $revenue = $db->fetch("SELECT COALESCE(SUM(amount),0) as total FROM payments WHERE clinic_id=? AND payment_date BETWEEN ? AND ?", [$clinicId, $fromDate, $toDate])['total'];
    $newPatients = $db->fetch("SELECT COUNT(*) as total FROM patients WHERE clinic_id=? AND DATE(created_at) BETWEEN ? AND ?", [$clinicId, $fromDate, $toDate])['total'];
    $invoices = $db->fetch("SELECT COALESCE(SUM(total_amount),0) as billed, COALESCE(SUM(paid_amount),0) as collected, COALESCE(SUM(due_amount),0) as due FROM invoices WHERE clinic_id=? AND invoice_date BETWEEN ? AND ?", [$clinicId, $fromDate, $toDate]);

    $doctorRevenue = $db->fetchAll(
        "SELECT u.full_name, COUNT(a.id) as appointments, COALESCE(SUM(a.consultation_fee),0) as fees
         FROM appointments a JOIN doctors d ON a.doctor_id=d.id JOIN users u ON d.user_id=u.id
         WHERE a.clinic_id=? AND a.appointment_date BETWEEN ? AND ? GROUP BY d.id ORDER BY fees DESC",
        [$clinicId, $fromDate, $toDate]
    );

    $paymentModes = $db->fetchAll(
        "SELECT payment_mode, COUNT(*) as count, SUM(amount) as total FROM payments WHERE clinic_id=? AND payment_date BETWEEN ? AND ? GROUP BY payment_mode",
        [$clinicId, $fromDate, $toDate]
    );

    jsonResponse(true, [
        'period' => ['from' => $fromDate, 'to' => $toDate],
        'appointments' => ['total' => intval($appts['total'] ?? 0), 'completed' => intval($appts['completed'] ?? 0), 'cancelled' => intval($appts['cancelled'] ?? 0)],
        'revenue' => floatval($revenue ?? 0),
        'new_patients' => intval($newPatients ?? 0),
        'invoices' => ['billed' => floatval($invoices['billed'] ?? 0), 'collected' => floatval($invoices['collected'] ?? 0), 'due' => floatval($invoices['due'] ?? 0)],
        'doctor_revenue' => $doctorRevenue,
        'payment_modes' => $paymentModes
    ]);
} catch (Exception $e) {
    jsonResponse(false, null, $e->getMessage(), 500);
}
