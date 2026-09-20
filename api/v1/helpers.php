<?php
/**
 * REST API Helper Functions - Feature Gen Care
 */

// Enable CORS for Mobile App & Web API consumers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Tenant-Subdomain, X-Tenant-ID, X-Clinic-ID');
header('Content-Type: application/json; charset=utf-8');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/**
 * Standardized JSON response emitter (API version)
 * Define BEFORE requiring functions.php to take precedence over the web version
 */
function jsonResponse($success, $data = null, $error = null, $statusCode = 200, $meta = null) {
    http_response_code($statusCode);
    $payload = [
        'success' => (bool)$success,
        'timestamp' => date('c')
    ];
    
    if ($success) {
        $payload['data'] = $data;
        if ($meta !== null) {
            $payload['meta'] = $meta;
        }
    } else {
        $payload['error'] = is_array($error) ? $error : ['message' => $error ?: 'An error occurred'];
    }
    
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require_once dirname(dirname(__DIR__)) . '/config/database.php';
require_once dirname(dirname(__DIR__)) . '/config/constants.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

/**
 * Parse JSON or Form input
 */
function getJsonInput() {
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        return $decoded;
    }
    return $_POST;
}
