<?php
/**
 * REST API Authentication Middleware - Feature Gen Care
 */
require_once dirname(__DIR__) . '/helpers.php';

/**
 * Authenticate incoming Bearer token
 * Returns authenticated user array with clinic details
 */
function authenticateApiRequest() {
    $db = db();
    
    // Extract Authorization Header
    // Apache/MAMP may place the header in different $_SERVER keys
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] 
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] 
        ?? '';
    if (!$authHeader && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    // Final fallback: check getallheaders()
    if (!$authHeader && function_exists('getallheaders')) {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    
    if (!$authHeader || !preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
        jsonResponse(false, null, 'Authorization token required. Please pass "Bearer <token>" in header.', 401);
    }
    
    $token = trim($matches[1]);
    
    try {
        // Query api_tokens table in tenant DB
        $tokenRecord = $db->fetch(
            "SELECT * FROM api_tokens WHERE token = ? AND is_active = 1 AND expires_at > NOW() LIMIT 1",
            [$token]
        );
        
        if (!$tokenRecord) {
            jsonResponse(false, null, 'Invalid or expired authentication token. Please log in again.', 401);
        }
        
        // Update last_used_at timestamp
        $db->query("UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?", [$tokenRecord['id']]);
        
        // Fetch user with role details
        $user = $db->fetch(
            "SELECT u.id, u.clinic_id, u.branch_id, u.role_id, u.role, u.username, u.full_name, u.email, u.phone, u.profile_image, u.is_active,
                    r.name as role_name, r.display_name as role_display_name
             FROM users u
             LEFT JOIN roles r ON u.role_id = r.id
             WHERE u.id = ? AND u.is_active = 1 LIMIT 1",
            [$tokenRecord['user_id']]
        );
        
        if (!$user) {
            jsonResponse(false, null, 'User account not found or suspended.', 401);
        }
        
        // Fetch clinic record
        $clinicId = intval($user['clinic_id'] ?? 1);
        $clinic = $db->fetch("SELECT * FROM clinics WHERE id = ?", [$clinicId]);
        if (!$clinic) {
            $clinic = $db->fetch("SELECT * FROM clinics ORDER BY id ASC LIMIT 1") ?: [];
        }
        
        return [
            'user' => $user,
            'clinic' => $clinic,
            'tenant' => $db->tenantInfo,
            'token_id' => $tokenRecord['id']
        ];
        
    } catch (Exception $e) {
        error_log("API Auth Exception: " . $e->getMessage());
        jsonResponse(false, null, 'Authentication verification error: ' . $e->getMessage(), 500);
    }
}
