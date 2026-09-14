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
$baseAmount = floatval($_POST['base_amount'] ?? 0);
$gstRate = floatval($_POST['gst_rate'] ?? 18.00);
$gstAmount = floatval($_POST['gst_amount'] ?? 0);

if ($baseAmount <= 0 && $amount > 0) {
    $baseAmount = round($amount / (1 + ($gstRate / 100)), 2);
    $gstAmount = round($amount - $baseAmount, 2);
}

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
    
    if ($planType === 'doctor_addon') {
        $addonDoctors = intval($_POST['addon_doctors'] ?? 1);
        if ($addonDoctors < 1) $addonDoctors = 1;

        $currentLimit = intval($currentTenant['max_doctors'] ?? 2);
        $newLimit = $currentLimit + $addonDoctors;
        $periodEnd = $currentTenant['subscription_ends_at'];

        $paymentNotes = "Doctor Capacity Add-On Pack: +{$addonDoctors} doctor slot(s) appended until " . ($periodEnd ? date('d M Y', strtotime($periodEnd)) : 'Lifetime') . " (Base: ₹" . number_format($baseAmount, 2) . " + 18% GST: ₹" . number_format($gstAmount, 2) . ")";

        // 1. Insert payment record
        $stmtP = $master->prepare("
            INSERT INTO tenant_subscription_payments (
                tenant_id, plan_type, amount, base_amount, gst_rate, gst_amount,
                payment_mode, payment_reference, collected_by, period_start, period_end,
                bonus_months_granted, doctor_limit_granted, status, notes, created_at
            ) VALUES (?, 'doctor_addon', ?, ?, ?, ?, 'razorpay', ?, 'Razorpay Online', NOW(), ?, 0, ?, 'completed', ?, NOW())
        ");
        $stmtP->execute([
            $tenantId, $amount, $baseAmount, $gstRate, $gstAmount,
            $paymentId, $periodEnd, $newLimit, $paymentNotes
        ]);

        // 2. Update tenant max_doctors & addon_doctors
        $stmtU = $master->prepare("
            UPDATE tenants 
            SET max_doctors = ?,
                addon_doctors = COALESCE(addon_doctors, 0) + ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmtU->execute([$newLimit, $addonDoctors, $tenantId]);

        $master->commit();
        setFlashMessage('success', "Doctor Add-on activated! +{$addonDoctors} doctor slot(s) added successfully. Your new capacity is {$newLimit} doctors.");
        header('Location: ' . BASE_URL . '/modules/doctors/list.php');
        exit;
    } elseif ($planType === 'one_time') {
        $isLifetime = 1;
        $newEnd = null;
        $doctorLimit = isset($currentTenant['custom_lifetime_doctors']) && $currentTenant['custom_lifetime_doctors'] !== null ? intval($currentTenant['custom_lifetime_doctors']) : intval($settings['one_time_max_doctors'] ?? 0);
        $billingCycle = 'one_time';
    } elseif ($planType === 'monthly') {
        $newEnd = date('Y-m-d 23:59:59', strtotime("+1 month", $startTs));
        $doctorLimit = !empty($currentTenant['custom_monthly_doctors']) ? intval($currentTenant['custom_monthly_doctors']) : intval($settings['monthly_max_doctors'] ?? 3);
        $billingCycle = 'monthly';
    } elseif ($planType === 'yearly') {
        $bonusMonths = isset($currentTenant['custom_yearly_bonus_months']) && $currentTenant['custom_yearly_bonus_months'] !== null ? intval($currentTenant['custom_yearly_bonus_months']) : intval($settings['yearly_default_bonus_months'] ?? 2);
        $totalMonths = 12 + $bonusMonths;
        $newEnd = date('Y-m-d 23:59:59', strtotime("+{$totalMonths} month", $startTs));
        $doctorLimit = !empty($currentTenant['custom_yearly_doctors']) ? intval($currentTenant['custom_yearly_doctors']) : intval($settings['yearly_max_doctors'] ?? 10);
        $billingCycle = 'yearly';
    }
    
    // 1. Insert payment record (Includes Option A 18% GST breakdown)
    $paymentNotes = "Online renewal via Razorpay (Base: ₹" . number_format($baseAmount, 2) . " + 18% GST: ₹" . number_format($gstAmount, 2) . ")";
    $stmtP = $master->prepare("
        INSERT INTO tenant_subscription_payments (
            tenant_id, plan_type, amount, base_amount, gst_rate, gst_amount,
            payment_mode, payment_reference, collected_by, period_start, period_end,
            bonus_months_granted, doctor_limit_granted, status, notes, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, 'razorpay', ?, 'Razorpay Online', ?, ?, ?, ?, 'completed', ?, NOW())
    ");
    $stmtP->execute([
        $tenantId, $planType, $amount, $baseAmount, $gstRate, $gstAmount,
        $paymentId, $periodStart, $newEnd, $bonusMonths, $doctorLimit, $paymentNotes
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
