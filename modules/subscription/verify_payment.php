<?php
/**
 * Razorpay Payment Verification & Activation - Feature Gen Care
 */
require_once dirname(dirname(__DIR__)) . '/config/session.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/modules/dashboard/index.php');
    exit;
}

$paymentId = sanitize($_POST['razorpay_payment_id'] ?? '');
$planType = sanitize($_POST['plan_type'] ?? 'yearly');
$amount = floatval($_POST['amount'] ?? 0);

if (empty($paymentId)) {
    setFlashMessage('error', 'Payment verification failed: Missing transaction ID.');
    header('Location: ' . BASE_URL . '/modules/subscription/paywall.php');
    exit;
}

$master = master_db();
$tenant = db()->tenantInfo;
$tenantId = $tenant['id'];

try {
    $master->beginTransaction();
    
    // Fetch settings for quotas and bonus
    $settingsRows = $master->query("SELECT setting_key, setting_value FROM saas_global_settings")->fetchAll();
    $settings = [];
    foreach ($settingsRows as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    
    // Fetch current tenant
    $stmtT = $master->prepare("SELECT * FROM tenants WHERE id = ?");
    $stmtT->execute([$tenantId]);
    $currentTenant = $stmtT->fetch();
    
    $now = time();
    $currentEnd = !empty($currentTenant['subscription_ends_at']) ? strtotime($currentTenant['subscription_ends_at']) : 0;
    $periodStart = ($currentEnd > $now) ? date('Y-m-d H:i:s', $currentEnd) : date('Y-m-d H:i:s');
    $startTs = strtotime($periodStart);
    $newEnd = null;
    $isLifetime = 0;
    $bonusMonths = 0;
    $doctorLimit = 2;
    $billingCycle = 'monthly';
    
    if ($planType === 'one_time') {
        $isLifetime = 1;
        $newEnd = null;
        $doctorLimit = intval($settings['one_time_max_doctors'] ?? 0);
        $billingCycle = 'one_time';
    } elseif ($planType === 'monthly') {
        $newEnd = date('Y-m-d 23:59:59', strtotime("+1 month", $startTs));
        $doctorLimit = intval($settings['monthly_max_doctors'] ?? 3);
        $billingCycle = 'monthly';
    } elseif ($planType === 'yearly') {
        $bonusMonths = intval($settings['yearly_default_bonus_months'] ?? 2);
        $totalMonths = 12 + $bonusMonths;
        $newEnd = date('Y-m-d 23:59:59', strtotime("+{$totalMonths} month", $startTs));
        $doctorLimit = intval($settings['yearly_max_doctors'] ?? 10);
        $billingCycle = 'yearly';
    }
    
    // 1. Insert payment record
    $stmtP = $master->prepare("
        INSERT INTO tenant_subscription_payments (
            tenant_id, plan_type, amount, payment_mode, payment_reference,
            collected_by, period_start, period_end, bonus_months_granted,
            doctor_limit_granted, status, notes, created_at
        ) VALUES (?, ?, ?, 'razorpay', ?, 'Razorpay Online', ?, ?, ?, ?, 'completed', 'Online renewal via Razorpay', NOW())
    ");
    $stmtP->execute([
        $tenantId, $planType, $amount, $paymentId,
        $periodStart, $newEnd, $bonusMonths, $doctorLimit
    ]);
    
    // 2. Update tenant in Master DB
    $stmtU = $master->prepare("
        UPDATE tenants 
        SET plan_type = ?,
            billing_cycle = ?,
            max_doctors = ?,
            bonus_months = ?,
            plan_amount = ?,
            is_lifetime = ?,
            subscription_starts_at = COALESCE(subscription_starts_at, NOW()),
            subscription_ends_at = ?,
            subscription_status = 'active',
            status = 'active'
        WHERE id = ?
    ");
    $stmtU->execute([
        $planType, $billingCycle, $doctorLimit, $bonusMonths, $amount,
        $isLifetime, $newEnd, $tenantId
    ]);
    
    $master->commit();
    setFlashMessage('success', "Payment successful! Your subscription has been renewed until " . ($newEnd ? date('d M Y', strtotime($newEnd)) : 'Lifetime') . ".");
    header('Location: ' . BASE_URL . '/modules/dashboard/index.php');
    exit;
} catch (Exception $e) {
    $master->rollBack();
    error_log("Razorpay verification error: " . $e->getMessage());
    setFlashMessage('error', 'Error activating subscription: ' . $e->getMessage());
    header('Location: ' . BASE_URL . '/modules/subscription/paywall.php');
    exit;
}
