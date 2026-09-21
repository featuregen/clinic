<?php
/**
 * Tenant Lookup API - Feature Gen Care
 * GET  /api/v1/tenant_lookup.php?q=<search>     - Search clinics by name/subdomain
 * GET  /api/v1/tenant_lookup.php?subdomain=<sub> - Get specific tenant info
 *
 * This endpoint does NOT require authentication.
 * It queries the MASTER database to resolve tenant/clinic info.
 */

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Tenant-Subdomain');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Quick JSON response helper (standalone, doesn't load full framework)
function tenantJsonResponse($success, $data = null, $error = null, $statusCode = 200) {
    http_response_code($statusCode);
    $payload = ['success' => (bool)$success, 'timestamp' => date('c')];
    if ($success) {
        $payload['data'] = $data;
    } else {
        $payload['error'] = ['message' => $error ?: 'An error occurred'];
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    tenantJsonResponse(false, null, 'Method not allowed. Use GET.', 405);
}

// Determine master DB credentials based on environment
$serverName = $_SERVER['SERVER_NAME'] ?? 'localhost';
if (in_array($serverName, ['localhost', '127.0.0.1'])) {
    $masterHost = 'localhost';
    $masterDbName = 'clinic_suite_master';
    $masterUser = 'root';
    $masterPass = 'root';
} else {
    $masterHost = 'localhost';
    $masterDbName = 'u882688268_clinic_master';
    $masterUser = 'u882688268_clinic_master';
    $masterPass = 'ClinicMaster@123';
}

try {
    $masterDsn = "mysql:host={$masterHost};dbname={$masterDbName};charset=utf8mb4";
    $masterConn = new PDO($masterDsn, $masterUser, $masterPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    error_log("Tenant lookup master DB error: " . $e->getMessage());
    tenantJsonResponse(false, null, 'Could not connect to master database.', 500);
}

// Mode 1: Exact subdomain lookup
$subdomain = trim($_GET['subdomain'] ?? '');
if (!empty($subdomain)) {
    $stmt = $masterConn->prepare(
        "SELECT id, subdomain, clinic_name, status, plan_type, is_lifetime, max_doctors,
                subscription_ends_at, trial_ends_at
         FROM tenants WHERE subdomain = ? LIMIT 1"
    );
    $stmt->execute([$subdomain]);
    $tenant = $stmt->fetch();

    if (!$tenant) {
        tenantJsonResponse(false, null, 'No clinic found with subdomain: ' . $subdomain, 404);
    }
    if ($tenant['status'] !== 'active') {
        tenantJsonResponse(false, null, 'This clinic account is suspended or inactive.', 403);
    }

    tenantJsonResponse(true, [
        'subdomain' => $tenant['subdomain'],
        'clinic_name' => $tenant['clinic_name'],
        'plan_type' => $tenant['plan_type'] ?? 'trial',
        'is_active' => $tenant['status'] === 'active',
    ]);
}

// Mode 2: Search by clinic name (fuzzy)
$query = trim($_GET['q'] ?? '');
if (empty($query)) {
    tenantJsonResponse(false, null, 'Provide ?q=<search> or ?subdomain=<sub> parameter.', 400);
}

if (strlen($query) < 2) {
    tenantJsonResponse(false, null, 'Search query must be at least 2 characters.', 400);
}

$stmt = $masterConn->prepare(
    "SELECT subdomain, clinic_name, plan_type, status
     FROM tenants
     WHERE status = 'active' AND (clinic_name LIKE ? OR subdomain LIKE ?)
     ORDER BY clinic_name ASC
     LIMIT 10"
);
$searchTerm = '%' . $query . '%';
$stmt->execute([$searchTerm, $searchTerm]);
$results = $stmt->fetchAll();

$clinics = array_map(function($t) {
    return [
        'subdomain' => $t['subdomain'],
        'clinic_name' => $t['clinic_name'],
        'plan_type' => $t['plan_type'] ?? 'trial',
    ];
}, $results);

tenantJsonResponse(true, ['clinics' => $clinics, 'count' => count($clinics)]);
