<?php
/**
 * Create Razorpay Order - Server-side API endpoint
 * Generates an order_id via Razorpay Orders API
 */
require_once dirname(dirname(__DIR__)) . '/config/session.php';
requireAuth();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$planType = sanitize($input['plan_type'] ?? 'yearly');
$addonDoctors = intval($input['addon_doctors'] ?? 0);

$master = master_db();
$tenant = db()->tenantInfo;
$tenantId = intval($tenant['id'] ?? 0);

if (!$tenantId) {
    echo json_encode(['success' => false, 'error' => 'Tenant identification failed.']);
    exit;
}

try {
    // 1. Fetch current tenant details
    $stmtT = $master->prepare("SELECT * FROM tenants WHERE id = ?");
    $stmtT->execute([$tenantId]);
    $currentTenant = $stmtT->fetch();

    if (!$currentTenant) {
        echo json_encode(['success' => false, 'error' => 'Tenant record not found.']);
        exit;
    }

    // 2. Fetch global settings
    $settingsStmt = $master->query("SELECT setting_key, setting_value FROM saas_global_settings");
    $settings = $settingsStmt ? $settingsStmt->fetchAll(PDO::FETCH_KEY_PAIR) : [];

    // Determine active Razorpay Keys (Tenant custom key takes priority, otherwise global key)
    $keyId = !empty($currentTenant['custom_razorpay_key_id']) 
        ? trim($currentTenant['custom_razorpay_key_id']) 
        : trim($settings['razorpay_key_id'] ?? '');

    $keySecret = !empty($currentTenant['custom_razorpay_key_secret']) 
        ? trim($currentTenant['custom_razorpay_key_secret']) 
        : trim($settings['razorpay_key_secret'] ?? '');

    if (empty($keyId) || empty($keySecret)) {
        echo json_encode([
            'success' => false, 
            'error' => 'Razorpay API credentials are not configured. Please enter Key ID and Key Secret in Super Admin -> Subscriptions -> Global Settings.'
        ]);
        exit;
    }

    // 3. Compute base price, 18% GST, and total amount on server
    $basePrice = 0.00;
    $orderTitle = 'Subscription Renewal';

    if ($planType === 'doctor_addon') {
        if ($addonDoctors < 1) $addonDoctors = 1;

        // Determine rate per doctor based on current plan cycle
        $currPlan = $currentTenant['plan_type'] ?? 'yearly';
        $isYearly = ($currPlan === 'yearly' || $currPlan === 'one_time');

        $customRate = $isYearly
            ? ($currentTenant['custom_addon_doctor_yearly_price'] ?? null)
            : ($currentTenant['custom_addon_doctor_monthly_price'] ?? null);

        if ($customRate !== null && floatval($customRate) > 0) {
            $ratePerDoc = floatval($customRate);
        } else {
            $ratePerDoc = $isYearly
                ? floatval($settings['addon_doctor_yearly_price'] ?? 250)
                : floatval($settings['addon_doctor_monthly_price'] ?? 25);
        }

        $basePrice = round($addonDoctors * $ratePerDoc, 2);
        $orderTitle = "+{$addonDoctors} Doctor Slots Add-On Pack";
    } elseif ($planType === 'monthly') {
        $basePrice = isset($currentTenant['custom_monthly_price']) && $currentTenant['custom_monthly_price'] !== null
            ? floatval($currentTenant['custom_monthly_price'])
            : floatval($settings['monthly_base_price'] ?? 600);
        $orderTitle = "Monthly Plan Subscription";
    } elseif ($planType === 'yearly') {
        $basePrice = isset($currentTenant['custom_yearly_price']) && $currentTenant['custom_yearly_price'] !== null
            ? floatval($currentTenant['custom_yearly_price'])
            : floatval($settings['yearly_base_price'] ?? 6000);
        $orderTitle = "Yearly Plan Subscription";
    } elseif ($planType === 'one_time') {
        echo json_encode([
            'success' => false,
            'error' => 'Online payment is not available for the One-Time Lifetime License (* server and domain will be maintained by client). Please contact sales directly.'
        ]);
        exit;
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid plan type selected.']);
        exit;
    }

    $gstRate = 18.00;
    $gstAmount = round($basePrice * 0.18, 2);
    $totalPrice = round($basePrice + $gstAmount, 2);
    $amountInPaise = intval(round($totalPrice * 100));

    if ($amountInPaise < 100) { // Minimum 1 INR
        echo json_encode(['success' => false, 'error' => 'Payment amount too low.']);
        exit;
    }

    $receipt = 'rcpt_' . $tenantId . '_' . time();

    // 4. Create Order via Razorpay API
    $orderPayload = [
        'amount' => $amountInPaise,
        'currency' => 'INR',
        'receipt' => $receipt,
        'notes' => [
            'tenant_id' => (string)$tenantId,
            'clinic_name' => (string)($currentTenant['clinic_name'] ?? 'Clinic'),
            'plan_type' => (string)$planType,
            'addon_doctors' => (string)$addonDoctors,
            'base_amount' => (string)$basePrice,
            'gst_amount' => (string)$gstAmount,
            'total_amount' => (string)$totalPrice
        ]
    ];

    $ch = curl_init('https://api.razorpay.com/v1/orders');
    curl_setopt($ch, CURLOPT_USERPWD, $keyId . ':' . $keySecret);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($orderPayload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        echo json_encode([
            'success' => false,
            'error' => 'Could not connect to Razorpay: ' . $curlError
        ]);
        exit;
    }

    $result = json_decode($response, true);

    if ($httpCode !== 200 || empty($result['id'])) {
        $errorMsg = $result['error']['description'] ?? 'Failed to initialize payment gateway order.';
        $errorCode = $result['error']['code'] ?? 'UNKNOWN_ERROR';
        
        // Provide friendly troubleshooting guidance
        if (stripos($errorMsg, 'Authentication failed') !== false) {
            $errorMsg = "Razorpay Authentication Failed: The Key ID or Key Secret in settings is invalid or has been regenerated/expired in your Razorpay Dashboard.";
        }

        echo json_encode([
            'success' => false,
            'error' => $errorMsg,
            'code' => $errorCode,
            'http_code' => $httpCode
        ]);
        exit;
    }

    // Success: Return order ID and details
    echo json_encode([
        'success' => true,
        'order_id' => $result['id'],
        'key_id' => $keyId,
        'amount' => $amountInPaise,
        'currency' => 'INR',
        'name' => 'Feature Gen Care',
        'description' => $orderTitle . ' (Incl. 18% GST)',
        'base_price' => $basePrice,
        'gst_amount' => $gstAmount,
        'total_price' => $totalPrice,
        'plan_type' => $planType,
        'addon_doctors' => $addonDoctors
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error: ' . $e->getMessage()
    ]);
}
