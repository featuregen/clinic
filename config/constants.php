<?php
/**
 * Application Constants
 * Advanced Clinic Suite
 */

// Application Info
define('APP_NAME', 'Advanced Clinic Suite');
define('APP_VERSION', '1.0.0');
define('APP_TAGLINE', 'Multi-Specialty Clinic Management Platform');

// Base paths
define('BASE_PATH', dirname(__DIR__));
define('CONFIG_PATH', BASE_PATH . '/config');
define('MODULES_PATH', BASE_PATH . '/modules');
define('INCLUDES_PATH', BASE_PATH . '/includes');
define('ASSETS_PATH', BASE_PATH . '/assets');
define('UPLOADS_PATH', BASE_PATH . '/uploads');
define('API_PATH', BASE_PATH . '/api');

// Base URL (auto-detect)
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
$baseDir = '';
// Walk up to find clinic-web root
$parts = explode('/', trim($scriptDir, '/'));
$idx = array_search('clinic-web', $parts);
if ($idx !== false) {
    $baseDir = '/' . implode('/', array_slice($parts, 0, $idx + 1));
}
define('BASE_URL', $protocol . '://' . $host . $baseDir);
define('ASSETS_URL', BASE_URL . '/assets');
define('UPLOADS_URL', BASE_URL . '/uploads');
define('API_URL', BASE_URL . '/api');

// Pagination
define('RECORDS_PER_PAGE', 20);
define('MAX_RECORDS_PER_PAGE', 100);

// File Upload
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024); // 10MB
define('ALLOWED_IMAGE_TYPES', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
define('ALLOWED_DOC_TYPES', ['pdf', 'doc', 'docx', 'xls', 'xlsx']);

// Session
define('SESSION_TIMEOUT', 3600); // 1 hour
define('SESSION_NAME', 'clinic_suite_session');

// Date/Time Formats
define('DATE_FORMAT', 'd M Y');
define('TIME_FORMAT', 'h:i A');
define('DATETIME_FORMAT', 'd M Y h:i A');
define('DB_DATE_FORMAT', 'Y-m-d');
define('DB_DATETIME_FORMAT', 'Y-m-d H:i:s');

// Currency
define('CURRENCY_SYMBOL', 'Rs');
define('CURRENCY_CODE', 'INR');

// GST Rates
define('GST_RATES', [0, 5, 12, 18, 28]);
define('DEFAULT_GST_RATE', 0);

// Appointment Status
define('APPOINTMENT_STATUS', [
    'scheduled'  => 'Scheduled',
    'confirmed'  => 'Confirmed',
    'checked_in' => 'Checked In',
    'in_progress' => 'In Progress',
    'completed'  => 'Completed',
    'cancelled'  => 'Cancelled',
    'no_show'    => 'No Show',
    'rescheduled' => 'Rescheduled'
]);

// Payment Modes
define('PAYMENT_MODES', [
    'cash'       => 'Cash',
    'card'       => 'Card',
    'upi'        => 'UPI',
    'bank'       => 'Bank Transfer',
    'insurance'  => 'Insurance',
    'online'     => 'Online Payment'
]);

// Payment Status
define('PAYMENT_STATUS', [
    'paid'    => 'Paid',
    'partial' => 'Partial',
    'due'     => 'Due',
    'refunded' => 'Refunded'
]);

// Prescription Status
define('PRESCRIPTION_STATUS', [
    'draft'     => 'Draft',
    'finalized' => 'Finalized',
    'sent'      => 'Sent'
]);

// User Roles
define('ROLE_SUPER_ADMIN', 'super_admin');
define('ROLE_ADMIN', 'admin');
define('ROLE_DOCTOR', 'doctor');
define('ROLE_RECEPTIONIST', 'receptionist');
define('ROLE_ACCOUNTANT', 'accountant');
define('ROLE_NURSE', 'nurse');
define('ROLE_LAB_TECH', 'lab_technician');

// Blood Groups
define('BLOOD_GROUPS', ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-']);

// Gender Options
define('GENDER_OPTIONS', ['Male', 'Female', 'Other']);

// Tooth Numbers (Dental)
define('ADULT_TEETH', range(1, 32));
define('CHILD_TEETH', range(1, 20));
?>
