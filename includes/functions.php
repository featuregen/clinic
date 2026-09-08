<?php
/**
 * Utility Functions
 * Advanced Clinic Suite
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';

// ============================================
// SANITIZATION & VALIDATION
// ============================================

function sanitize($input) {
    if (is_array($input)) {
        return array_map('sanitize', $input);
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function sanitizeOutput($input) {
    return htmlspecialchars($input ?? '', ENT_QUOTES, 'UTF-8');
}

function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

function validatePhone($phone) {
    return preg_match('/^[\+]?[0-9\s\-]{10,15}$/', $phone);
}

function validateDate($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

// ============================================
// DATE & TIME HELPERS
// ============================================

function formatDate($date, $format = null) {
    if (empty($date)) return '-';
    $format = $format ?? DATE_FORMAT;
    return date($format, strtotime($date));
}

function formatTime($time, $format = null) {
    if (empty($time)) return '-';
    $format = $format ?? TIME_FORMAT;
    return date($format, strtotime($time));
}

function formatDateTime($datetime, $format = null) {
    if (empty($datetime)) return '-';
    $format = $format ?? DATETIME_FORMAT;
    return date($format, strtotime($datetime));
}

function timeAgo($datetime) {
    $now = new DateTime();
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);
    
    if ($diff->y > 0) return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
    if ($diff->m > 0) return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
    if ($diff->d > 0) return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
    if ($diff->h > 0) return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
    if ($diff->i > 0) return $diff->i . ' min' . ($diff->i > 1 ? 's' : '') . ' ago';
    return 'Just now';
}

function calculateAge($dob) {
    if (empty($dob)) return null;
    $birthDate = new DateTime($dob);
    $today = new DateTime();
    return $today->diff($birthDate)->y;
}

// ============================================
// CURRENCY & NUMBER FORMATTING
// ============================================

function formatCurrency($amount) {
    $symbol = CURRENCY_SYMBOL;
    // Fallback if symbol is broken (e.g. integer 262145)
    if (is_numeric($symbol)) {
        $symbol = 'Rs.';
    }
    return $symbol . ' ' . number_format((float)$amount, 2);
}

function formatNumber($number, $decimals = 0) {
    return number_format((float)$number, $decimals);
}

// ============================================
// PATIENT ID GENERATION
// ============================================

function generatePatientId() {
    $db = db();
    $clinic = $db->fetch("SELECT patient_id_prefix, patient_id_start_no FROM clinics LIMIT 1");
    $prefix = $clinic['patient_id_prefix'] ?? 'PT';
    $startNo = $clinic['patient_id_start_no'] ?? 1000;
    
    $lastPatient = $db->fetch("SELECT patient_uid FROM patients ORDER BY id DESC LIMIT 1");
    if ($lastPatient) {
        $lastNum = (int)preg_replace('/[^0-9]/', '', $lastPatient['patient_uid']);
        $nextNum = $lastNum + 1;
    } else {
        $nextNum = $startNo;
    }
    
    return $prefix . str_pad($nextNum, 6, '0', STR_PAD_LEFT);
}

// ============================================
// INVOICE NUMBER GENERATION
// ============================================

function generateInvoiceNumber() {
    $db = db();
    $clinic = $db->fetch("SELECT invoice_prefix, invoice_start_no FROM clinics LIMIT 1");
    $prefix = $clinic['invoice_prefix'] ?? 'INV';
    
    $lastInvoice = $db->fetch("SELECT invoice_number FROM invoices ORDER BY id DESC LIMIT 1");
    if ($lastInvoice) {
        $lastNum = (int)preg_replace('/[^0-9]/', '', $lastInvoice['invoice_number']);
        $nextNum = $lastNum + 1;
    } else {
        $nextNum = $clinic['invoice_start_no'] ?? 1;
    }
    
    return $prefix . '-' . date('Ym') . '-' . str_pad($nextNum, 5, '0', STR_PAD_LEFT);
}

// ============================================
// TOKEN NUMBER GENERATION
// ============================================

function generateTokenNumber($doctorId, $date) {
    $db = db();
    $result = $db->fetch(
        "SELECT MAX(token_number) as max_token FROM appointments WHERE doctor_id = ? AND appointment_date = ?",
        [$doctorId, $date]
    );
    return ($result['max_token'] ?? 0) + 1;
}

// ============================================
// PERMISSION CHECKING
// ============================================

function hasPermission($permissionName) {
    if (getCurrentUserRole() === ROLE_SUPER_ADMIN) return true;

    $db  = db();
    $roleId = $_SESSION['role_id'] ?? null;
    $userId = $_SESSION['user_id']  ?? null;
    if (!$roleId || !$userId) return false;

    // 1. Check user-level override first (deny beats role grant; grant beats role deny)
    try {
        $userOverride = $db->fetch(
            "SELECT up.type FROM user_permissions up
             JOIN permissions p ON up.permission_id = p.id
             WHERE up.user_id = ? AND p.name = ?",
            [$userId, $permissionName]
        );
        if ($userOverride) {
            return $userOverride['type'] === 'grant';
        }
    } catch (Exception $e) {
        // user_permissions table may not exist yet — skip and fall through to role check
    }

    // 2. Fall back to role-level permissions
    $result = $db->fetch(
        "SELECT COUNT(*) as cnt FROM role_permissions rp
         JOIN permissions p ON rp.permission_id = p.id
         WHERE rp.role_id = ? AND p.name = ?",
        [$roleId, $permissionName]
    );

    return ($result['cnt'] ?? 0) > 0;
}

function requirePermission($permissionName) {
    if (!hasPermission($permissionName)) {
        setFlashMessage('error', 'You do not have permission to access this feature.');
        header('Location: ' . BASE_URL . '/modules/dashboard/index.php');
        exit;
    }
}

// ============================================
// FILE UPLOAD
// ============================================

function uploadFile($file, $directory, $allowedTypes = null) {
    $allowedTypes = $allowedTypes ?? array_merge(ALLOWED_IMAGE_TYPES, ALLOWED_DOC_TYPES);
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Upload failed with error code: ' . $file['error']];
    }
    
    if ($file['size'] > MAX_UPLOAD_SIZE) {
        return ['success' => false, 'error' => 'File size exceeds maximum allowed size.'];
    }
    
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedTypes)) {
        return ['success' => false, 'error' => 'File type not allowed.'];
    }
    
    $uploadDir = UPLOADS_PATH . '/' . $directory;
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $filename = uniqid() . '_' . time() . '.' . $ext;
    $filepath = $uploadDir . '/' . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return [
            'success' => true,
            'filename' => $filename,
            'filepath' => $directory . '/' . $filename,
            'filesize' => $file['size'],
            'filetype' => $ext
        ];
    }
    
    return ['success' => false, 'error' => 'Failed to move uploaded file.'];
}

// ============================================
// PAGINATION
// ============================================

function paginate($totalRecords, $currentPage = 1, $perPage = null) {
    $perPage = $perPage ?? RECORDS_PER_PAGE;
    $totalPages = ceil($totalRecords / $perPage);
    $currentPage = max(1, min($currentPage, $totalPages));
    $offset = ($currentPage - 1) * $perPage;
    
    return [
        'total_records' => $totalRecords,
        'per_page' => $perPage,
        'current_page' => $currentPage,
        'total_pages' => $totalPages,
        'offset' => $offset,
        'has_prev' => $currentPage > 1,
        'has_next' => $currentPage < $totalPages
    ];
}

function renderPagination($pagination, $baseUrl) {
    if ($pagination['total_pages'] <= 1) return '';
    
    $html = '<nav class="pagination-wrapper"><ul class="pagination">';
    
    // Previous
    if ($pagination['has_prev']) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . '?page=' . ($pagination['current_page'] - 1) . '"><i class="fas fa-chevron-left"></i></a></li>';
    }
    
    // Page numbers
    $start = max(1, $pagination['current_page'] - 2);
    $end = min($pagination['total_pages'], $pagination['current_page'] + 2);
    
    if ($start > 1) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . '?page=1">1</a></li>';
        if ($start > 2) $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
    }
    
    for ($i = $start; $i <= $end; $i++) {
        $active = $i === $pagination['current_page'] ? ' active' : '';
        $html .= '<li class="page-item' . $active . '"><a class="page-link" href="' . $baseUrl . '?page=' . $i . '">' . $i . '</a></li>';
    }
    
    if ($end < $pagination['total_pages']) {
        if ($end < $pagination['total_pages'] - 1) $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . '?page=' . $pagination['total_pages'] . '">' . $pagination['total_pages'] . '</a></li>';
    }
    
    // Next
    if ($pagination['has_next']) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . '?page=' . ($pagination['current_page'] + 1) . '"><i class="fas fa-chevron-right"></i></a></li>';
    }
    
    $html .= '</ul></nav>';
    return $html;
}

// ============================================
// AUDIT LOGGING
// ============================================

function logAudit($action, $module = null, $entityType = null, $entityId = null, $oldValues = null, $newValues = null, $description = null) {
    try {
        $db = db();
        $db->query(
            "INSERT INTO audit_logs (user_id, clinic_id, action, module, entity_type, entity_id, old_values, new_values, ip_address, user_agent, description) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                getCurrentUserId(),
                getCurrentClinicId(),
                $action,
                $module,
                $entityType,
                $entityId,
                $oldValues ? json_encode($oldValues) : null,
                $newValues ? json_encode($newValues) : null,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                $description
            ]
        );
    } catch (Exception $e) {
        error_log("Audit log error: " . $e->getMessage());
    }
}

// ============================================
// STATUS BADGES
// ============================================

function getStatusBadge($status, $type = 'appointment') {
    $badges = [
        'appointment' => [
            'scheduled'   => ['class' => 'badge-info',    'icon' => 'clock'],
            'confirmed'   => ['class' => 'badge-primary', 'icon' => 'check-circle'],
            'checked_in'  => ['class' => 'badge-warning', 'icon' => 'sign-in-alt'],
            'in_progress' => ['class' => 'badge-accent',  'icon' => 'stethoscope'],
            'completed'   => ['class' => 'badge-success', 'icon' => 'check-double'],
            'cancelled'   => ['class' => 'badge-danger',  'icon' => 'times-circle'],
            'no_show'     => ['class' => 'badge-dark',    'icon' => 'user-slash'],
            'rescheduled' => ['class' => 'badge-secondary','icon' => 'calendar-alt'],
        ],
        'payment' => [
            'paid'     => ['class' => 'badge-success', 'icon' => 'check'],
            'partial'  => ['class' => 'badge-warning', 'icon' => 'minus-circle'],
            'due'      => ['class' => 'badge-danger',  'icon' => 'exclamation-circle'],
            'overdue'  => ['class' => 'badge-danger',  'icon' => 'exclamation-triangle'],
            'refunded' => ['class' => 'badge-info',    'icon' => 'undo'],
            'cancelled'=> ['class' => 'badge-dark',    'icon' => 'ban'],
        ]
    ];
    
    $badge = $badges[$type][$status] ?? ['class' => 'badge-secondary', 'icon' => 'circle'];
    $label = ucfirst(str_replace('_', ' ', $status));
    
    return '<span class="badge ' . $badge['class'] . '"><i class="fas fa-' . $badge['icon'] . '"></i> ' . $label . '</span>';
}

// ============================================
// RESPONSE HELPERS (for AJAX)
// ============================================

function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function successResponse($message, $data = null) {
    $response = ['success' => true, 'message' => $message];
    if ($data !== null) $response['data'] = $data;
    jsonResponse($response);
}

function errorResponse($message, $statusCode = 400) {
    jsonResponse(['success' => false, 'error' => $message], $statusCode);
}

// ============================================
// MISCELLANEOUS
// ============================================

function getGravatarUrl($email, $size = 80) {
    $hash = md5(strtolower(trim($email ?? 'default@example.com')));
    return "https://www.gravatar.com/avatar/{$hash}?s={$size}&d=mp";
}

function getInitials($name) {
    $parts = explode(' ', $name);
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $initials .= strtoupper(substr($part, 0, 1));
    }
    return $initials;
}

function truncateText($text, $length = 50) {
    if (strlen($text) <= $length) return $text;
    return substr($text, 0, $length) . '...';
}

function randomColor() {
    $colors = ['#0097A7', '#00838F', '#00ACC1', '#059669', '#D97706', '#DC2626', '#DB2777', '#0891B2'];
    return $colors[array_rand($colors)];
}

function isAjaxRequest() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function redirect($url, $permanent = false) {
    header('Location: ' . $url, true, $permanent ? 301 : 302);
    exit;
}

function getCurrentUrl() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $protocol . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
}
?>
