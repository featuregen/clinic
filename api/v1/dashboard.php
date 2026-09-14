<?php
/**
 * REST API Dashboard Endpoint - Feature Gen Care
 * GET /api/v1/dashboard.php
 */
require_once __DIR__ . '/middleware/auth.php';
$auth = authenticateApiRequest();
$db = db();
$clinicId = intval($auth['user']['clinic_id'] ?? 1);
$today = date('Y-m-d');

try {
    // 1. KPI Metrics
    $todayApptsCount = $db->fetch(
        "SELECT COUNT(*) as cnt FROM appointments WHERE clinic_id = ? AND appointment_date = ?",
        [$clinicId, $today]
    )['cnt'] ?? 0;

    $checkedInCount = $db->fetch(
        "SELECT COUNT(*) as cnt FROM appointments WHERE clinic_id = ? AND appointment_date = ? AND status IN ('checked_in', 'in_consultation')",
        [$clinicId, $today]
    )['cnt'] ?? 0;

    $completedCount = $db->fetch(
        "SELECT COUNT(*) as cnt FROM appointments WHERE clinic_id = ? AND appointment_date = ? AND status = 'completed'",
        [$clinicId, $today]
    )['cnt'] ?? 0;

    $totalPatients = $db->fetch(
        "SELECT COUNT(*) as cnt FROM patients WHERE clinic_id = ? AND is_active = 1",
        [$clinicId]
    )['cnt'] ?? 0;

    // Financials
    $todayRevenue = $db->fetch(
        "SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE clinic_id = ? AND payment_date = ?",
        [$clinicId, $today]
    )['total'] ?? 0.00;

    $pendingBills = $db->fetch(
        "SELECT COALESCE(SUM(due_amount), 0) as total FROM invoices WHERE clinic_id = ? AND status IN ('unpaid', 'partially_paid')",
        [$clinicId]
    )['total'] ?? 0.00;

    // 2. Today's Appointment Queue
    $queue = $db->fetchAll(
        "SELECT a.id, a.token_number, a.appointment_time, a.status, a.reason,
                p.id as patient_id, p.patient_uid, p.first_name, p.last_name, p.phone as patient_phone, p.gender,
                d.id as doctor_id, u.full_name as doctor_name
         FROM appointments a
         JOIN patients p ON a.patient_id = p.id
         JOIN doctors d ON a.doctor_id = d.id
         JOIN users u ON d.user_id = u.id
         WHERE a.clinic_id = ? AND a.appointment_date = ?
         ORDER BY a.appointment_time ASC, a.token_number ASC
         LIMIT 25",
        [$clinicId, $today]
    );

    // 3. Recent Prescriptions
    $recentRx = $db->fetchAll(
        "SELECT pr.id, pr.created_at, pr.diagnosis,
                p.patient_uid, p.first_name, p.last_name,
                u.full_name as doctor_name
         FROM prescriptions pr
         JOIN patients p ON pr.patient_id = p.id
         JOIN doctors d ON pr.doctor_id = d.id
         JOIN users u ON d.user_id = u.id
         WHERE pr.clinic_id = ?
         ORDER BY pr.id DESC LIMIT 5",
        [$clinicId]
    );

    jsonResponse(true, [
        'kpis' => [
            'today_appointments' => intval($todayApptsCount),
            'checked_in' => intval($checkedInCount),
            'completed' => intval($completedCount),
            'total_patients' => intval($totalPatients),
            'today_revenue' => floatval($todayRevenue),
            'pending_bills' => floatval($pendingBills),
            'currency' => '₹'
        ],
        'today_queue' => $queue,
        'recent_prescriptions' => $recentRx,
        'user' => [
            'name' => $auth['user']['full_name'],
            'role' => $auth['user']['role_display_name'] ?? $auth['user']['role']
        ]
    ]);

} catch (Exception $e) {
    jsonResponse(false, null, 'Error loading dashboard metrics: ' . $e->getMessage(), 500);
}
