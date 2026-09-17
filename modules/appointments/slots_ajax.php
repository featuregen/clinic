<?php
/**
 * Booked Slots AJAX Endpoint - Feature Gen Care
 * Returns booked appointment times for a given doctor + date
 */
require_once dirname(dirname(__DIR__)) . '/config/session.php';
require_once dirname(dirname(__DIR__)) . '/config/database.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

header('Content-Type: application/json');

$db = db();
$clinicId = getCurrentClinicId();
$doctorId = intval($_GET['doctor_id'] ?? 0);
$date = sanitize($_GET['date'] ?? '');

if (!$doctorId || !$date) {
    echo json_encode(['slots' => [], 'schedule' => null]);
    exit;
}

// Fetch booked appointments for this doctor on this date (exclude cancelled/no_show)
$appointments = $db->fetchAll(
    "SELECT a.id, a.appointment_time, a.end_time, a.status, a.token_number,
            p.first_name, p.last_name, p.patient_uid
     FROM appointments a
     JOIN patients p ON a.patient_id = p.id
     WHERE a.doctor_id = ? AND a.appointment_date = ? AND a.clinic_id = ?
       AND a.status NOT IN ('cancelled', 'no_show')
     ORDER BY a.appointment_time ASC",
    [$doctorId, $date, $clinicId]
);

// Get doctor's slot duration
$doctor = $db->fetch(
    "SELECT d.default_slot_duration FROM doctors d WHERE d.id = ? AND d.clinic_id = ?",
    [$doctorId, $clinicId]
);
$slotDuration = intval($doctor['default_slot_duration'] ?? 15);

// Format the booked slots
$slots = [];
foreach ($appointments as $a) {
    $startTime = $a['appointment_time'];
    $endTime = $a['end_time'];
    
    // If no end_time, compute from slot duration
    if (empty($endTime)) {
        $startTs = strtotime("2000-01-01 $startTime");
        $endTime = date('H:i:s', $startTs + ($slotDuration * 60));
    }
    
    $slots[] = [
        'id'            => $a['id'],
        'time'          => substr($startTime, 0, 5), // HH:MM
        'end_time'      => substr($endTime, 0, 5),
        'status'        => $a['status'],
        'token'         => $a['token_number'],
        'patient_name'  => trim($a['first_name'] . ' ' . ($a['last_name'] ?? '')),
        'patient_uid'   => $a['patient_uid']
    ];
}

// Fetch doctor's schedule for this day of week
$dayOfWeek = date('w', strtotime($date)); // 0=Sun, 6=Sat
$schedule = $db->fetch(
    "SELECT start_time, end_time, slot_duration, is_active FROM doctor_schedules 
     WHERE doctor_id = ? AND day_of_week = ? AND is_active = 1 LIMIT 1",
    [$doctorId, $dayOfWeek]
);

$scheduleData = null;
if ($schedule) {
    $scheduleData = [
        'start_time' => substr($schedule['start_time'], 0, 5),
        'end_time'   => substr($schedule['end_time'], 0, 5),
        'slot_duration' => intval($schedule['slot_duration'] ?? $slotDuration)
    ];
}

echo json_encode([
    'slots'         => $slots,
    'slot_duration'  => $slotDuration,
    'schedule'       => $scheduleData,
    'total_booked'   => count($slots)
]);
