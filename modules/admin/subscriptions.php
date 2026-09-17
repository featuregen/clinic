<?php
/**
 * Subscription & Custom Plan Decision Screen - Feature Gen Care
 * Super Admin Only - Scoped to Current Clinic Tenant
 */
ob_start();
require_once dirname(dirname(__DIR__)) . '/config/session.php';
requireAuth();
requireRole([ROLE_SUPER_ADMIN]);

$master = master_db();
$tenantInfo = db()->tenantInfo;
$currentTenantId = intval($tenantInfo['id'] ?? 0);
$currentTab = sanitize($_GET['tab'] ?? 'plan');
$successMsg = '';
$errorMsg = '';

// Fetch ONLY this current clinic tenant from Master DB
$stmtTenant = $master->prepare("SELECT * FROM tenants WHERE id = ?");
$stmtTenant->execute([$currentTenantId]);
$thisTenant = $stmtTenant->fetch();

if (!$thisTenant && !empty($tenantInfo['subdomain'])) {
    $stmtTenant = $master->prepare("SELECT * FROM tenants WHERE subdomain = ?");
    $stmtTenant->execute([$tenantInfo['subdomain']]);
    $thisTenant = $stmtTenant->fetch();
}

$t = $thisTenant ?: $tenantInfo;

// Fetch SaaS Global Fallback Settings
$settingsRows = $master->query("SELECT setting_key, setting_value FROM saas_global_settings")->fetchAll();
$settings = [];
foreach ($settingsRows as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Active plan effective values for this clinic
$effMonthlyPrice = !empty($t['custom_monthly_price']) ? floatval($t['custom_monthly_price']) : floatval($settings['monthly_price'] ?? 1499);
$effMonthlyDoctors = !empty($t['custom_monthly_doctors']) ? intval($t['custom_monthly_doctors']) : intval($settings['monthly_max_doctors'] ?? 3);

$effYearlyPrice = !empty($t['custom_yearly_price']) ? floatval($t['custom_yearly_price']) : floatval($settings['yearly_price'] ?? 14999);
$effYearlyDoctors = !empty($t['custom_yearly_doctors']) ? intval($t['custom_yearly_doctors']) : intval($settings['yearly_max_doctors'] ?? 10);
$effYearlyBonus = isset($t['custom_yearly_bonus_months']) && $t['custom_yearly_bonus_months'] !== null ? intval($t['custom_yearly_bonus_months']) : intval($settings['yearly_default_bonus_months'] ?? 2);

$effLifetimePrice = !empty($t['custom_lifetime_price']) ? floatval($t['custom_lifetime_price']) : floatval($settings['one_time_price'] ?? 49999);
$effLifetimeDoctors = isset($t['custom_lifetime_doctors']) && $t['custom_lifetime_doctors'] !== null ? intval($t['custom_lifetime_doctors']) : intval($settings['one_time_max_doctors'] ?? 0);

$effTrialMonths = !empty($t['custom_trial_months']) ? intval($t['custom_trial_months']) : intval($settings['trial_duration_months'] ?? 1);
$effTrialDoctors = !empty($t['custom_trial_doctors']) ? intval($t['custom_trial_doctors']) : intval($settings['trial_max_doctors'] ?? 2);

$effAddonMonthly = !empty($t['custom_addon_doctor_monthly_price']) ? floatval($t['custom_addon_doctor_monthly_price']) : floatval($settings['addon_doctor_monthly_price'] ?? 25);
$effAddonYearly = !empty($t['custom_addon_doctor_yearly_price']) ? floatval($t['custom_addon_doctor_yearly_price']) : floatval($settings['addon_doctor_yearly_price'] ?? 250);

// ============================================
// POST ACTION HANDLERS
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = sanitize($_POST['action'] ?? '');
    
    // 1. SAVE CUSTOM CLINIC PLANS & PRICING
    if ($action === 'save_clinic_custom_plans') {
        $monthlyPrice = floatval($_POST['custom_monthly_price'] ?? $effMonthlyPrice);
        $monthlyDoctors = intval($_POST['custom_monthly_doctors'] ?? $effMonthlyDoctors);
        $yearlyPrice = floatval($_POST['custom_yearly_price'] ?? $effYearlyPrice);
        $yearlyDoctors = intval($_POST['custom_yearly_doctors'] ?? $effYearlyDoctors);
        $yearlyBonus = intval($_POST['custom_yearly_bonus_months'] ?? $effYearlyBonus);
        $lifetimePrice = floatval($_POST['custom_lifetime_price'] ?? $effLifetimePrice);
        $lifetimeDoctors = intval($_POST['custom_lifetime_doctors'] ?? $effLifetimeDoctors);
        $trialMonths = intval($_POST['custom_trial_months'] ?? $effTrialMonths);
        $trialDoctors = intval($_POST['custom_trial_doctors'] ?? $effTrialDoctors);

        $customAddonMonthly = isset($_POST['custom_addon_doctor_monthly_price']) && $_POST['custom_addon_doctor_monthly_price'] !== '' ? floatval($_POST['custom_addon_doctor_monthly_price']) : null;
        $customAddonYearly = isset($_POST['custom_addon_doctor_yearly_price']) && $_POST['custom_addon_doctor_yearly_price'] !== '' ? floatval($_POST['custom_addon_doctor_yearly_price']) : null;
        $customRzpKey = sanitize(trim($_POST['custom_razorpay_key_id'] ?? ''));
        $customRzpSecret = sanitize(trim($_POST['custom_razorpay_key_secret'] ?? ''));

        $clinicGst = strtoupper(sanitize(trim($_POST['clinic_gst_number'] ?? '')));
        $clinicPan = strtoupper(sanitize(trim($_POST['clinic_pan_number'] ?? '')));
        $clinicAddress = sanitize($_POST['clinic_billing_address'] ?? '');

        // Update active max_doctors immediately for the current plan
        $currentPlanType = $t['plan_type'] ?? 'trial';
        $newActiveMaxDoctors = intval($t['max_doctors']);
        if ($currentPlanType === 'trial') {
            $newActiveMaxDoctors = $trialDoctors;
        } elseif ($currentPlanType === 'monthly') {
            $newActiveMaxDoctors = $monthlyDoctors;
        } elseif ($currentPlanType === 'yearly') {
            $newActiveMaxDoctors = $yearlyDoctors;
        } elseif ($currentPlanType === 'one_time') {
            $newActiveMaxDoctors = $lifetimeDoctors;
        }

        try {
            $stmt = $master->prepare("
                UPDATE tenants 
                SET custom_monthly_price = ?,
                    custom_monthly_doctors = ?,
                    custom_yearly_price = ?,
                    custom_yearly_doctors = ?,
                    custom_yearly_bonus_months = ?,
                    custom_lifetime_price = ?,
                    custom_lifetime_doctors = ?,
                    custom_trial_months = ?,
                    custom_trial_doctors = ?,
                    custom_addon_doctor_monthly_price = ?,
                    custom_addon_doctor_yearly_price = ?,
                    custom_razorpay_key_id = ?,
                    custom_razorpay_key_secret = ?,
                    gst_number = ?,
                    pan_number = ?,
                    billing_address = ?,
                    max_doctors = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $monthlyPrice, $monthlyDoctors, $yearlyPrice, $yearlyDoctors,
                $yearlyBonus, $lifetimePrice, $lifetimeDoctors, $trialMonths,
                $trialDoctors, $customAddonMonthly, $customAddonYearly,
                $customRzpKey, $customRzpSecret,
                $clinicGst, $clinicPan, $clinicAddress,
                $newActiveMaxDoctors, $t['id']
            ]);
            
            setFlashMessage('success', "Custom configuration for {$t['clinic_name']} saved successfully! Active doctor quota updated to {$newActiveMaxDoctors} doctors.");
            header("Location: " . BASE_URL . "/modules/admin/subscriptions.php?tab=plan");
            exit;
        } catch (Exception $e) {
            $errorMsg = 'Error saving custom pricing: ' . $e->getMessage();
        }
    }
    
    // 2. ACTIVATE SPECIFIC PLAN FOR CLINIC DIRECTLY
    elseif ($action === 'activate_plan_tier') {
        $tier = sanitize($_POST['tier'] ?? 'monthly');
        $extendMode = sanitize($_POST['extend_mode'] ?? 'now');
        $recordPayment = isset($_POST['record_cash_payment']) ? 1 : 0;
        $passedDoctorLimit = isset($_POST['doctor_limit']) ? intval($_POST['doctor_limit']) : null;
        
        $now = time();
        $currentEnd = !empty($t['subscription_ends_at']) ? strtotime($t['subscription_ends_at']) : 0;
        $startTs = ($extendMode === 'extend' && $currentEnd > $now) ? $currentEnd : $now;
        $periodStart = date('Y-m-d H:i:s', $startTs);
        
        $newEnd = null;
        $isLifetime = 0;
        $planType = $tier;
        $billingCycle = $tier;
        $doctorLimit = 2;
        $bonusGranted = 0;
        $planPrice = 0.00;
        
        if ($tier === 'trial') {
            $trialMonths = !empty($t['custom_trial_months']) ? intval($t['custom_trial_months']) : intval($settings['trial_duration_months'] ?? 1);
            $newEnd = date('Y-m-d 23:59:59', strtotime("+{$trialMonths} month", $startTs));
            $doctorLimit = ($passedDoctorLimit !== null && $passedDoctorLimit > 0) ? $passedDoctorLimit : (!empty($t['custom_trial_doctors']) ? intval($t['custom_trial_doctors']) : intval($settings['trial_max_doctors'] ?? 2));
            $planPrice = 0.00;
        } elseif ($tier === 'monthly') {
            $newEnd = date('Y-m-d 23:59:59', strtotime("+1 month", $startTs));
            $doctorLimit = ($passedDoctorLimit !== null && $passedDoctorLimit > 0) ? $passedDoctorLimit : (!empty($t['custom_monthly_doctors']) ? intval($t['custom_monthly_doctors']) : intval($settings['monthly_max_doctors'] ?? 3));
            $planPrice = !empty($t['custom_monthly_price']) ? floatval($t['custom_monthly_price']) : floatval($settings['monthly_price'] ?? 1499);
        } elseif ($tier === 'yearly') {
            $bonusGranted = isset($t['custom_yearly_bonus_months']) && $t['custom_yearly_bonus_months'] !== null ? intval($t['custom_yearly_bonus_months']) : intval($settings['yearly_default_bonus_months'] ?? 2);
            $totalMonths = 12 + $bonusGranted;
            $newEnd = date('Y-m-d 23:59:59', strtotime("+{$totalMonths} month", $startTs));
            $doctorLimit = ($passedDoctorLimit !== null && $passedDoctorLimit > 0) ? $passedDoctorLimit : (!empty($t['custom_yearly_doctors']) ? intval($t['custom_yearly_doctors']) : intval($settings['yearly_max_doctors'] ?? 10));
            $planPrice = !empty($t['custom_yearly_price']) ? floatval($t['custom_yearly_price']) : floatval($settings['yearly_price'] ?? 14999);
        } elseif ($tier === 'one_time') {
            $isLifetime = 1;
            $newEnd = null;
            $doctorLimit = ($passedDoctorLimit !== null) ? $passedDoctorLimit : (isset($t['custom_lifetime_doctors']) && $t['custom_lifetime_doctors'] !== null ? intval($t['custom_lifetime_doctors']) : intval($settings['one_time_max_doctors'] ?? 0));
            $planPrice = !empty($t['custom_lifetime_price']) ? floatval($t['custom_lifetime_price']) : floatval($settings['one_time_price'] ?? 49999);
            $billingCycle = 'one_time';
        }
        
        try {
            $master->beginTransaction();
            
            // 1. Update Tenant
            $stmtU = $master->prepare("
                UPDATE tenants 
                SET plan_type = ?,
                    billing_cycle = ?,
                    max_doctors = ?,
                    bonus_months = ?,
                    plan_amount = ?,
                    is_lifetime = ?,
                    subscription_starts_at = NOW(),
                    subscription_ends_at = ?,
                    subscription_status = 'active',
                    status = 'active',
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmtU->execute([
                $planType, $billingCycle, $doctorLimit, $bonusGranted, $planPrice,
                $isLifetime, $newEnd, $t['id']
            ]);
            
            // 2. Optionally record payment if requested
            $issuedPaymentId = null;
            if ($recordPayment && $planPrice > 0) {
                $baseAmount = $planPrice;
                $gstRate = 18.00;
                $gstAmount = round($baseAmount * ($gstRate / 100), 2);
                $totalAmount = $baseAmount + $gstAmount;

                $ref = 'REC-' . date('Y') . '-' . rand(1000, 9999);
                $stmtP = $master->prepare("
                    INSERT INTO tenant_subscription_payments (
                        tenant_id, plan_type, amount, base_amount, gst_rate, gst_amount,
                        payment_mode, payment_reference, collected_by, period_start, period_end,
                        bonus_months_granted, doctor_limit_granted, status, notes, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, 'cash_on_hand', ?, ?, ?, ?, ?, ?, 'completed', ?, NOW())
                ");
                $stmtP->execute([
                    $t['id'], $planType, $totalAmount, $baseAmount, $gstRate, $gstAmount, $ref,
                    getSession('full_name', 'Super Admin'),
                    $periodStart, $newEnd, $bonusGranted, $doctorLimit,
                    "Plan activated via Super Admin control panel with direct cash logging (Base: ₹" . number_format($baseAmount, 2) . " + 18% GST: ₹" . number_format($gstAmount, 2) . ")."
                ]);
                $issuedPaymentId = $master->lastInsertId();
            }
            
            $master->commit();
            
            if ($issuedPaymentId) {
                $totalPaidDisplay = isset($totalAmount) ? $totalAmount : $planPrice;
                setFlashMessage('success', "Plan activated and Cash on Hand payment of ₹" . number_format($totalPaidDisplay, 2) . " (Base ₹" . number_format($planPrice, 2) . " + 18% GST) recorded!");
                header("Location: " . BASE_URL . "/modules/admin/print_subscription_receipt.php?id=" . $issuedPaymentId);
                exit;
            } else {
                setFlashMessage('success', "Plan successfully updated and activated for {$t['clinic_name']}!");
                header("Location: " . BASE_URL . "/modules/admin/subscriptions.php?tab=plan");
                exit;
            }
        } catch (Exception $e) {
            $master->rollBack();
            $errorMsg = 'Error activating plan: ' . $e->getMessage();
        }
    }
    
    // 3. RECORD PAYMENT (Cash on Hand, Bank Transfer, Cheque, UPI, Razorpay)
    elseif ($action === 'record_payment') {
        $amount = floatval($_POST['amount'] ?? 0);
        $baseAmount = floatval($_POST['base_amount'] ?? 0);
        $gstRate = floatval($_POST['gst_rate'] ?? 18.00);
        $gstAmount = floatval($_POST['gst_amount'] ?? 0);

        if ($baseAmount <= 0 && $amount > 0) {
            $baseAmount = round($amount / (1 + ($gstRate / 100)), 2);
            $gstAmount = round($amount - $baseAmount, 2);
        } elseif ($gstAmount <= 0 && $baseAmount > 0) {
            $gstAmount = round($baseAmount * ($gstRate / 100), 2);
            if ($amount <= 0) {
                $amount = $baseAmount + $gstAmount;
            }
        }

        $paymentMode = sanitize($_POST['payment_mode'] ?? 'cash_on_hand');
        $reference = sanitize($_POST['payment_reference'] ?? '');
        $collectedBy = sanitize($_POST['collected_by'] ?? '');
        $periodType = sanitize($_POST['period_type'] ?? '12_months');
        $bonusMonths = intval($_POST['bonus_months_granted'] ?? 0);
        $doctorLimit = intval($_POST['doctor_limit_granted'] ?? 2);
        $notes = sanitize($_POST['notes'] ?? '');
        $paymentDate = !empty($_POST['payment_date']) ? $_POST['payment_date'] : date('Y-m-d');
        
        if (empty($reference)) {
            $reference = 'REC-' . date('Y') . '-' . rand(1000, 9999);
        }
        
        try {
            $master->beginTransaction();
            
            $now = time();
            $currentEnd = !empty($t['subscription_ends_at']) ? strtotime($t['subscription_ends_at']) : 0;
            $periodStart = ($currentEnd > $now) ? date('Y-m-d H:i:s', $currentEnd) : date('Y-m-d H:i:s');
            $startTs = strtotime($periodStart);
            $newEnd = null;
            $isLifetime = 0;
            $planType = $t['plan_type'];
            
            if ($periodType === 'lifetime') {
                $isLifetime = 1;
                $newEnd = null;
                $planType = 'one_time';
            } elseif ($periodType === '1_month') {
                $newEnd = date('Y-m-d 23:59:59', strtotime("+1 month", $startTs));
                $planType = 'monthly';
            } elseif ($periodType === '12_months') {
                $totalMonths = 12 + $bonusMonths;
                $newEnd = date('Y-m-d 23:59:59', strtotime("+{$totalMonths} month", $startTs));
                $planType = 'yearly';
            } elseif ($periodType === 'doctor_addon') {
                $planType = 'doctor_addon';
                $newEnd = !empty($t['subscription_ends_at']) ? $t['subscription_ends_at'] : date('Y-m-d 23:59:59', strtotime('+1 month'));
            } elseif ($periodType === 'custom') {
                $customDays = intval($_POST['custom_days'] ?? 30);
                $newEnd = date('Y-m-d 23:59:59', strtotime("+{$customDays} days", $startTs));
            }
            
            // Insert Payment record with Option A GST breakdown
            $stmtP = $master->prepare("
                INSERT INTO tenant_subscription_payments (
                    tenant_id, plan_type, amount, base_amount, gst_rate, gst_amount,
                    payment_mode, payment_reference, collected_by, period_start, period_end,
                    bonus_months_granted, doctor_limit_granted, status, notes, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', ?, ?)
            ");
            $stmtP->execute([
                $t['id'], $planType, $amount, $baseAmount, $gstRate, $gstAmount,
                $paymentMode, $reference, $collectedBy, $periodStart, $newEnd,
                $bonusMonths, $doctorLimit, $notes, $paymentDate . ' ' . date('H:i:s')
            ]);
            $newPaymentId = $master->lastInsertId();
            
            // Update Tenant validity
            if ($periodType === 'doctor_addon') {
                $extraDocs = max(1, $doctorLimit);
                $stmtU = $master->prepare("
                    UPDATE tenants 
                    SET max_doctors = max_doctors + ?,
                        addon_doctors = COALESCE(addon_doctors, 0) + ?,
                        status = 'active',
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmtU->execute([$extraDocs, $extraDocs, $t['id']]);
            } else {
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
                $billingCycle = ($periodType === 'lifetime') ? 'one_time' : (($periodType === '1_month') ? 'monthly' : 'yearly');
                $stmtU->execute([
                    $planType, $billingCycle, $doctorLimit, $bonusMonths, $amount,
                    $isLifetime, $newEnd, $t['id']
                ]);
            }
            
            $master->commit();
            setFlashMessage('success', "Payment of ₹" . number_format($amount, 2) . " recorded successfully! Receipt #$reference issued.");
            header("Location: " . BASE_URL . "/modules/admin/print_subscription_receipt.php?id=" . $newPaymentId);
            exit;
        } catch (Exception $e) {
            $master->rollBack();
            $errorMsg = 'Error recording payment: ' . $e->getMessage();
        }
    }
    
    // 3B. CANCEL / VOID A RECEIPT
    elseif ($action === 'cancel_receipt') {
        $paymentId = intval($_POST['payment_id'] ?? 0);
        if ($paymentId > 0) {
            try {
                $master->beginTransaction();
                
                // Fetch the payment to reverse
                $paymentRow = $master->prepare("SELECT * FROM tenant_subscription_payments WHERE id = ? AND tenant_id = ?");
                $paymentRow->execute([$paymentId, $t['id']]);
                $cancelPayment = $paymentRow->fetch();
                
                if ($cancelPayment && $cancelPayment['status'] !== 'cancelled') {
                    // Mark payment as cancelled
                    $master->prepare("UPDATE tenant_subscription_payments SET status = 'cancelled', notes = CONCAT(COALESCE(notes, ''), ' [CANCELLED on " . date('d M Y h:i A') . " by " . sanitize(getSession('full_name', 'Admin')) . "]') WHERE id = ?")->execute([$paymentId]);
                    
                    // If it was a doctor addon, reverse the addon_doctors count
                    if ($cancelPayment['plan_type'] === 'doctor_addon') {
                        $docsToRemove = max(1, intval($cancelPayment['doctor_limit_granted']));
                        $master->prepare("UPDATE tenants SET addon_doctors = GREATEST(0, COALESCE(addon_doctors, 0) - ?), max_doctors = GREATEST(1, max_doctors - ?) WHERE id = ?")->execute([$docsToRemove, $docsToRemove, $t['id']]);
                    }
                    
                    $master->commit();
                    setFlashMessage('success', "Receipt #{$cancelPayment['payment_reference']} has been cancelled/voided successfully.");
                } else {
                    $master->commit();
                    setFlashMessage('error', 'Payment not found or already cancelled.');
                }
            } catch (Exception $e) {
                $master->rollBack();
                $errorMsg = 'Error cancelling receipt: ' . $e->getMessage();
            }
        }
        header("Location: " . BASE_URL . "/modules/admin/subscriptions.php?tab=payments");
        exit;
    }
    
    // 4. UPDATE SAAS GLOBAL PLATFORM DEFAULTS
    elseif ($action === 'update_global_settings') {
        $settingsToSave = [
            'trial_duration_months' => sanitize($_POST['trial_duration_months'] ?? '1'),
            'trial_max_doctors' => sanitize($_POST['trial_max_doctors'] ?? '2'),
            'monthly_price' => sanitize($_POST['monthly_price'] ?? '1499'),
            'monthly_max_doctors' => sanitize($_POST['monthly_max_doctors'] ?? '3'),
            'yearly_price' => sanitize($_POST['yearly_price'] ?? '14999'),
            'yearly_max_doctors' => sanitize($_POST['yearly_max_doctors'] ?? '10'),
            'yearly_default_bonus_months' => sanitize($_POST['yearly_default_bonus_months'] ?? '2'),
            'one_time_price' => sanitize($_POST['one_time_price'] ?? '49999'),
            'one_time_max_doctors' => sanitize($_POST['one_time_max_doctors'] ?? '0'),
            'addon_doctor_monthly_price' => sanitize($_POST['addon_doctor_monthly_price'] ?? '25'),
            'addon_doctor_yearly_price' => sanitize($_POST['addon_doctor_yearly_price'] ?? '250'),
            'platform_company_name' => sanitize($_POST['platform_company_name'] ?? 'Feature Gen Technologies'),
            'platform_gstin' => strtoupper(sanitize(trim($_POST['platform_gstin'] ?? ''))),
            'platform_pan' => strtoupper(sanitize(trim($_POST['platform_pan'] ?? ''))),
            'platform_address' => sanitize($_POST['platform_address'] ?? ''),
            'platform_state' => sanitize($_POST['platform_state'] ?? ''),
            'razorpay_key_id' => sanitize($_POST['razorpay_key_id'] ?? ''),
            'razorpay_key_secret' => sanitize($_POST['razorpay_key_secret'] ?? ''),
            'offline_payment_contact' => sanitize($_POST['offline_payment_contact'] ?? ''),
            'offline_bank_details' => sanitize($_POST['offline_bank_details'] ?? '')
        ];
        
        try {
            $stmtSet = $master->prepare("
                INSERT INTO saas_global_settings (setting_key, setting_value) 
                VALUES (?, ?) 
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");
            foreach ($settingsToSave as $k => $v) {
                $stmtSet->execute([$k, $v]);
            }
            setFlashMessage('success', 'SaaS Global Platform Defaults updated successfully.');
            header("Location: " . BASE_URL . "/modules/admin/subscriptions.php?tab=settings");
            exit;
        } catch (Exception $e) {
            $errorMsg = 'Error saving defaults: ' . $e->getMessage();
        }
    }
}

// ============================================
// FETCH DATA FOR DISPLAY (CURRENT CLINIC ONLY)
// ============================================
// Payment History Ledger for THIS clinic only
$stmtPay = $master->prepare("
    SELECT p.*, t.clinic_name, t.subdomain 
    FROM tenant_subscription_payments p 
    JOIN tenants t ON p.tenant_id = t.id 
    WHERE p.tenant_id = ?
    ORDER BY p.id DESC 
    LIMIT 100
");
$stmtPay->execute([$t['id']]);
$payments = $stmtPay->fetchAll();
$activePayments = array_filter($payments, fn($p) => ($p['status'] ?? '') !== 'cancelled');
$activePaymentCount = count($activePayments);

// Stats for THIS clinic
$now = time();
$isLife = !empty($t['is_lifetime']) && $t['is_lifetime'] == 1;
$endsAt = !empty($t['subscription_ends_at']) ? strtotime($t['subscription_ends_at']) : null;
$isExp = (!$isLife && $endsAt && $endsAt < $now);
$daysLeft = $endsAt ? ceil(($endsAt - $now) / 86400) : null;

// Doctor counts in current clinic database
$db = db();
$currentClinicId = getCurrentClinicId();
$activeDocCount = $db->fetch("SELECT COUNT(*) as c FROM doctors WHERE clinic_id = ?", [$currentClinicId])['c'] ?? 0;

$stmtRev = $master->prepare("SELECT COALESCE(SUM(amount), 0) as tot FROM tenant_subscription_payments WHERE tenant_id = ? AND status = 'completed'");
$stmtRev->execute([$t['id']]);
$thisClinicRevenue = $stmtRev->fetch()['tot'] ?? 0;
?>
<?php
$pageTitle = 'Decide Clinic Plan & Pricing';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="content-header">
    <div>
        <ul class="breadcrumb">
            <li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li>
            <li><a href="<?= BASE_URL ?>/modules/admin/index.php">System Admin</a></li>
            <li>Subscription Decision</li>
        </ul>
        <h1><i class="fas fa-handshake" style="color: var(--primary);"></i> <?= sanitizeOutput($t['clinic_name']) ?> &bull; Subscription & Pricing Control</h1>
    </div>
    <div class="d-flex gap-8">
        <button class="btn btn-success" onclick="openPaymentModal()"><i class="fas fa-hand-holding-usd"></i> Record Payment (COD / Cash)</button>
        <a href="<?= BASE_URL ?>/modules/subscription/paywall.php" target="_blank" class="btn btn-outline" title="Preview what this clinic sees when renewing">
            <i class="fas fa-eye"></i> View Clinic Paywall
        </a>
    </div>
</div>

<?php if ($errorMsg): ?>
<div class="alert alert-error mb-20"><i class="fas fa-times-circle"></i> <?= sanitizeOutput($errorMsg) ?></div>
<?php endif; ?>

<!-- Stat Metrics For THIS Clinic -->
<div class="grid-4 mb-24" style="gap: 18px;">
    <div class="stat-card">
        <div class="stat-icon primary"><i class="fas fa-crown"></i></div>
        <div class="stat-details">
            <div class="stat-label">Currently Active Plan</div>
            <div class="stat-value" style="font-size: 20px; text-transform: capitalize;">
                <?= sanitizeOutput($t['plan_type'] ?? 'trial') ?>
                <?= ($t['bonus_months'] ?? 0) > 0 ? '(+' . $t['bonus_months'] . 'm Bonus)' : '' ?>
            </div>
            <div class="stat-change">
                <?php if ($isExp): ?>
                    <span style="color: #dc2626; font-weight: 700;">Status: Expired</span>
                <?php else: ?>
                    <span style="color: #15803d; font-weight: 700;">Status: Active</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon info"><i class="fas fa-user-md"></i></div>
        <div class="stat-details">
            <div class="stat-label">Doctor Slots Used</div>
            <div class="stat-value"><?= $activeDocCount ?> / <?= ($t['max_doctors'] > 0 ? $t['max_doctors'] : '∞') ?></div>
            <div class="stat-change">
                <?= $t['max_doctors'] > 0 ? max(0, $t['max_doctors'] - $activeDocCount) . ' slots remaining' : 'Unlimited slots' ?>
            </div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon <?= $isExp ? 'danger' : 'warning' ?>"><i class="fas fa-clock"></i></div>
        <div class="stat-details">
            <div class="stat-label">Current Validity</div>
            <div class="stat-value" style="font-size: 19px; color: <?= $isExp ? '#dc2626' : '#d97706' ?>;">
                <?= $isLife ? 'Lifetime' : ($endsAt ? date('d M Y', $endsAt) : 'Trial Period') ?>
            </div>
            <div class="stat-change">
                <?php if ($isLife): ?>
                    Perpetual License
                <?php elseif ($isExp): ?>
                    <span style="color: #dc2626; font-weight: 700;">Expired <?= abs($daysLeft) ?> day(s) ago</span>
                <?php else: ?>
                    <?= $daysLeft ?> days remaining
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon success"><i class="fas fa-receipt"></i></div>
        <div class="stat-details">
            <div class="stat-label">Total Paid By Clinic</div>
            <div class="stat-value" style="color: #059669;">₹<?= number_format($thisClinicRevenue, 2) ?></div>
            <div class="stat-change"><?= $activePaymentCount ?> receipts logged</div>
        </div>
    </div>
</div>

<!-- Tabs Navigation -->
<div class="card mb-24">
    <div style="display: flex; border-bottom: 1px solid var(--border-color); background: var(--bg-secondary); border-radius: 12px 12px 0 0;">
        <a href="?tab=plan" class="tab-link <?= $currentTab === 'plan' ? 'active' : '' ?>">
            <i class="fas fa-sliders-h"></i> Decide Clinic Plan & Pricing
        </a>
        <a href="?tab=payments" class="tab-link <?= $currentTab === 'payments' ? 'active' : '' ?>">
            <i class="fas fa-receipt"></i> Payment Receipts (<?= $activePaymentCount ?>)
        </a>
        <a href="?tab=settings" class="tab-link <?= $currentTab === 'settings' ? 'active' : '' ?>">
            <i class="fas fa-globe"></i> SaaS Global Platform Defaults
        </a>
    </div>

    <!-- TAB 1: DECIDE PLAN & PRICING FOR THIS INDIVIDUAL CLINIC -->
    <?php if ($currentTab === 'plan'): ?>
    <div class="card-body" style="padding: 32px 28px;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;">
            <div>
                <h3 style="margin: 0 0 6px; font-size: 20px; color: var(--text);">
                    <i class="fas fa-handshake" style="color: var(--primary);"></i> Configure Custom Pricing & Bonus Months for <?= sanitizeOutput($t['clinic_name']) ?>
                </h3>
                <p style="margin: 0; font-size: 14px; color: var(--text-muted);">
                    Set negotiated rates and bonus months specifically for this clinic. Billing, self-serve paywall, and cash collection for this clinic will happen strictly based on these customized terms.
                </p>
            </div>
            <span class="badge badge-info" style="font-size: 13px; padding: 6px 14px;">
                Portal: <strong><?= sanitizeOutput($t['subdomain']) ?></strong>.featuregen.com
            </span>
        </div>

        <form method="POST" action="">
            <input type="hidden" name="action" value="save_clinic_custom_plans">

            <div class="grid-2 gap-24 mb-32">
                <!-- 1. FREE TRIAL CONFIG -->
                <div class="card" style="background: var(--bg-secondary); border: 2px solid var(--border-color); border-radius: 12px; display: flex; flex-direction: column;">
                    <div class="card-body" style="padding: 24px; flex: 1;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                            <h4 style="margin: 0; font-size: 17px; display: flex; align-items: center; gap: 8px;">
                                <i class="fas fa-vial" style="color: #64748b;"></i> 1. Free Trial Tier
                            </h4>
                            <?php if (($t['plan_type'] ?? '') === 'trial'): ?>
                                <span class="badge badge-primary" style="font-size: 11px;">Currently Active</span>
                            <?php endif; ?>
                        </div>
                        <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 20px;">
                            Introductory trial access before billing starts.
                        </p>

                        <div class="grid-2 gap-16 mb-20">
                            <div class="form-group mb-0">
                                <label class="form-label font-semibold">Trial Duration (Months)</label>
                                <input type="number" name="custom_trial_months" class="form-control" value="<?= $effTrialMonths ?>" min="1" max="12" required>
                            </div>
                            <div class="form-group mb-0">
                                <label class="form-label font-semibold">Doctor Limit Quota</label>
                                <input type="number" name="custom_trial_doctors" class="form-control" value="<?= $effTrialDoctors ?>" min="1" required>
                            </div>
                        </div>

                        <div style="border-top: 1px solid var(--border-color); padding-top: 16px; margin-top: auto;">
                            <button type="button" class="btn btn-outline btn-sm" style="width: 100%; justify-content: center;" onclick="triggerActivatePlan('trial', 'Free Trial', 0, <?= $effTrialDoctors ?>, 0)">
                                <i class="fas fa-play"></i> Activate Free Trial For Clinic
                            </button>
                        </div>
                    </div>
                </div>

                <!-- 2. MONTHLY PLAN CONFIG -->
                <div class="card" style="background: var(--bg-secondary); border: 2px solid var(--border-color); border-radius: 12px; display: flex; flex-direction: column;">
                    <div class="card-body" style="padding: 24px; flex: 1;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                            <h4 style="margin: 0; font-size: 17px; display: flex; align-items: center; gap: 8px;">
                                <i class="fas fa-calendar-alt" style="color: #059669;"></i> 2. Monthly Plan Tier
                            </h4>
                            <?php if (($t['plan_type'] ?? '') === 'monthly'): ?>
                                <span class="badge badge-success" style="font-size: 11px;">Currently Active</span>
                            <?php endif; ?>
                        </div>
                        <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 20px;">
                            Custom negotiated monthly recurring fee for this clinic.
                        </p>

                        <div class="grid-2 gap-16 mb-20">
                            <div class="form-group mb-0">
                                <label class="form-label font-semibold">Agreed Monthly Price (₹)</label>
                                <input type="number" name="custom_monthly_price" id="custMonthlyPrice" class="form-control" value="<?= $effMonthlyPrice ?>" step="0.01" required>
                                <small class="text-muted">Global default: ₹<?= number_format($settings['monthly_price'] ?? 1499, 2) ?></small>
                            </div>
                            <div class="form-group mb-0">
                                <label class="form-label font-semibold">Doctor Limit Quota</label>
                                <input type="number" name="custom_monthly_doctors" id="custMonthlyDoctors" class="form-control" value="<?= $effMonthlyDoctors ?>" min="1" required>
                            </div>
                        </div>

                        <div style="border-top: 1px solid var(--border-color); padding-top: 16px; margin-top: auto;">
                            <button type="button" class="btn btn-outline btn-sm" style="width: 100%; justify-content: center;" onclick="triggerActivatePlan('monthly', 'Monthly Plan', document.getElementById('custMonthlyPrice').value, document.getElementById('custMonthlyDoctors').value, 0)">
                                <i class="fas fa-play"></i> Activate Monthly Plan For Clinic
                            </button>
                        </div>
                    </div>
                </div>

                <!-- 3. YEARLY PLAN CONFIG (WITH ADDITIONAL FREE MONTHS) -->
                <div class="card" style="background: var(--bg-secondary); border: 2px solid #d97706; border-radius: 12px; display: flex; flex-direction: column;">
                    <div class="card-body" style="padding: 24px; flex: 1;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                            <h4 style="margin: 0; font-size: 17px; display: flex; align-items: center; gap: 8px;">
                                <i class="fas fa-crown" style="color: #d97706;"></i> 3. Yearly Plan & Bonus Months
                            </h4>
                            <?php if (($t['plan_type'] ?? '') === 'yearly'): ?>
                                <span class="badge" style="background: #fef3c7; color: #b45309; font-weight: 700; font-size: 11px;">Currently Active</span>
                            <?php endif; ?>
                        </div>
                        <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 20px;">
                            Custom yearly rate + <strong>negotiated free additional months</strong> for this clinic.
                        </p>

                        <div class="grid-3 gap-12 mb-20">
                            <div class="form-group mb-0">
                                <label class="form-label font-semibold">Yearly Price (₹)</label>
                                <input type="number" name="custom_yearly_price" id="custYearlyPrice" class="form-control" value="<?= $effYearlyPrice ?>" step="0.01" required>
                                <small class="text-muted">Default: ₹<?= number_format($settings['yearly_price'] ?? 14999, 2) ?></small>
                            </div>
                            <div class="form-group mb-0">
                                <label class="form-label font-semibold" style="color: #b45309;">+ Extra Free Months</label>
                                <input type="number" name="custom_yearly_bonus_months" id="custYearlyBonus" class="form-control" value="<?= $effYearlyBonus ?>" min="0" required style="border-color: #d97706; font-weight: 700;">
                                <small class="text-muted">e.g. +2 = 14m access</small>
                            </div>
                            <div class="form-group mb-0">
                                <label class="form-label font-semibold">Doctor Limit</label>
                                <input type="number" name="custom_yearly_doctors" id="custYearlyDoctors" class="form-control" value="<?= $effYearlyDoctors ?>" min="1" required>
                            </div>
                        </div>

                        <div style="background: #fffbeb; border: 1px solid #fde68a; padding: 10px 14px; border-radius: 8px; margin-bottom: 18px; font-size: 13px; color: #92400e;">
                            <i class="fas fa-gift"></i> Client gets <strong>12 + <span id="previewBonusMonths"><?= $effYearlyBonus ?></span> = <span id="previewTotalMonths"><?= 12 + $effYearlyBonus ?></span> months</strong> validity for ₹<span id="previewYearlyPrice"><?= number_format($effYearlyPrice, 0) ?></span>.
                        </div>

                        <div style="border-top: 1px solid var(--border-color); padding-top: 16px; margin-top: auto;">
                            <button type="button" class="btn btn-warning btn-sm" style="width: 100%; justify-content: center; background: #d97706; color: white; border: none;" onclick="triggerActivatePlan('yearly', 'Yearly Plan (+Bonus)', document.getElementById('custYearlyPrice').value, document.getElementById('custYearlyDoctors').value, document.getElementById('custYearlyBonus').value)">
                                <i class="fas fa-play"></i> Activate Yearly (+Bonus) For Clinic
                            </button>
                        </div>
                    </div>
                </div>

                <!-- 4. ONE-TIME COST LIFETIME CONFIG -->
                <div class="card" style="background: var(--bg-secondary); border: 2px solid var(--border-color); border-radius: 12px; display: flex; flex-direction: column;">
                    <div class="card-body" style="padding: 24px; flex: 1;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                            <h4 style="margin: 0; font-size: 17px; display: flex; align-items: center; gap: 8px;">
                                <i class="fas fa-infinity" style="color: #0284c7;"></i> 4. One-Time Lifetime License <span style="color: #0284c7;">*</span>
                            </h4>
                            <?php if ($isLife || ($t['plan_type'] ?? '') === 'one_time'): ?>
                                <span class="badge" style="background: #e0f2fe; color: #0369a1; font-weight: 700; font-size: 11px;">Currently Active</span>
                            <?php endif; ?>
                        </div>
                        <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 20px;">
                            One-time perpetual purchase for this clinic. Never expires.<br>
                            <span style="color: #0284c7; font-weight: 600; font-size: 12px;">* Server and domain will be maintained by client (Online checkout is disabled for clients; "Contact Sales" is shown).</span>
                        </p>

                        <div class="grid-2 gap-16 mb-20">
                            <div class="form-group mb-0">
                                <label class="form-label font-semibold">Agreed Lifetime Price (₹)</label>
                                <input type="number" name="custom_lifetime_price" id="custLifetimePrice" class="form-control" value="<?= $effLifetimePrice ?>" step="0.01" required>
                                <small class="text-muted">Global default: ₹<?= number_format($settings['one_time_price'] ?? 49999, 2) ?></small>
                            </div>
                            <div class="form-group mb-0">
                                <label class="form-label font-semibold">Doctor Limit (0 = Unlimited)</label>
                                <input type="number" name="custom_lifetime_doctors" id="custLifetimeDoctors" class="form-control" value="<?= $effLifetimeDoctors ?>" min="0" required>
                            </div>
                        </div>

                        <div style="border-top: 1px solid var(--border-color); padding-top: 16px; margin-top: auto;">
                            <button type="button" class="btn btn-outline btn-sm" style="width: 100%; justify-content: center; color: #0284c7; border-color: #0284c7;" onclick="triggerActivatePlan('one_time', 'Lifetime License', document.getElementById('custLifetimePrice').value, document.getElementById('custLifetimeDoctors').value, 0)">
                                <i class="fas fa-play"></i> Activate Lifetime License For Clinic
                            </button>
                        </div>
                    </div>
                </div>

                <!-- 5. DOCTOR ADD-ON RATES FOR THIS CLINIC -->
                <div class="card" style="background: var(--bg-secondary); border: 2px solid var(--border-color); border-radius: 12px; display: flex; flex-direction: column;">
                    <div class="card-body" style="padding: 24px; flex: 1;">
                        <h4 style="margin: 0 0 16px; font-size: 17px; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-plus-circle" style="color: #0891b2;"></i> 5. Doctor Add-On Pack Pricing
                        </h4>
                        <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 20px;">
                            Custom doctor slot pricing when this clinic needs extra doctor capacity beyond their plan quota.
                        </p>
                        <div class="grid-2 gap-16">
                            <div class="form-group mb-0">
                                <label class="form-label font-semibold">Monthly Rate (₹ / extra doc)</label>
                                <input type="number" name="custom_addon_doctor_monthly_price" class="form-control" value="<?= isset($t['custom_addon_doctor_monthly_price']) && $t['custom_addon_doctor_monthly_price'] !== null ? $t['custom_addon_doctor_monthly_price'] : '' ?>" placeholder="Default: <?= $settings['addon_doctor_monthly_price'] ?? 25 ?>" step="0.01">
                                <small class="text-muted">Empty = use default (₹<?= $settings['addon_doctor_monthly_price'] ?? 25 ?>/mo)</small>
                            </div>
                            <div class="form-group mb-0">
                                <label class="form-label font-semibold">Yearly Rate (₹ / extra doc)</label>
                                <input type="number" name="custom_addon_doctor_yearly_price" class="form-control" value="<?= isset($t['custom_addon_doctor_yearly_price']) && $t['custom_addon_doctor_yearly_price'] !== null ? $t['custom_addon_doctor_yearly_price'] : '' ?>" placeholder="Default: <?= $settings['addon_doctor_yearly_price'] ?? 250 ?>" step="0.01">
                                <small class="text-muted">Empty = use default (₹<?= $settings['addon_doctor_yearly_price'] ?? 250 ?>/yr)</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 6. RAZORPAY GATEWAY CONFIGURATION FOR THIS CLINIC -->
                <div class="card" style="background: var(--bg-secondary); border: 2px solid <?= !empty($t['custom_razorpay_key_id']) ? '#00838f' : 'var(--border-color)' ?>; border-radius: 12px; display: flex; flex-direction: column;">
                    <div class="card-body" style="padding: 24px; flex: 1;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                            <h4 style="margin: 0; font-size: 17px; display: flex; align-items: center; gap: 8px;">
                                <i class="fas fa-bolt" style="color: #00838f;"></i> 6. Razorpay Gateway (This Clinic)
                            </h4>
                            <?php if (!empty($t['custom_razorpay_key_id'])): ?>
                                <span class="badge badge-success" style="font-size: 11px;"><i class="fas fa-check-circle"></i> Custom Key Active</span>
                            <?php else: ?>
                                <span class="badge" style="background: #f1f5f9; color: #475569; font-size: 11px;">Using Global Fallback</span>
                            <?php endif; ?>
                        </div>
                        <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 20px;">
                            Configure a dedicated Razorpay Key for <strong><?= sanitizeOutput($t['clinic_name']) ?></strong>, or leave blank to use the SaaS platform's default account.
                        </p>
                        <div class="grid-2 gap-16">
                            <div class="form-group mb-0">
                                <label class="form-label font-semibold">Clinic Razorpay Key ID</label>
                                <input type="text" name="custom_razorpay_key_id" class="form-control" value="<?= sanitizeOutput($t['custom_razorpay_key_id'] ?? '') ?>" placeholder="rzp_test_... or rzp_live_...">
                                <small class="text-muted">Leave blank to use platform default.</small>
                            </div>
                            <div class="form-group mb-0">
                                <label class="form-label font-semibold">Clinic Razorpay Key Secret</label>
                                <input type="password" name="custom_razorpay_key_secret" class="form-control" value="<?= sanitizeOutput($t['custom_razorpay_key_secret'] ?? '') ?>" placeholder="••••••••••••••••">
                                <small class="text-muted">Secret key for verification.</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 7. CLIENT CLINIC TAX & GST DETAILS (CUSTOMER / BILLED TO) -->
                <div class="card" style="background: var(--bg-secondary); border: 2px solid var(--border-color); border-radius: 12px; grid-column: span 2;">
                    <div class="card-body" style="padding: 24px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                            <h4 style="margin: 0; font-size: 17px; display: flex; align-items: center; gap: 8px;">
                                <i class="fas fa-file-invoice" style="color: #059669;"></i> 7. Clinic GSTIN & Tax Details (Customer / Billed To)
                            </h4>
                            <?php if (!empty($t['gst_number'])): ?>
                                <span class="badge badge-success" style="font-size: 11px;"><i class="fas fa-check"></i> Registered (GSTIN: <?= sanitizeOutput($t['gst_number']) ?>)</span>
                            <?php else: ?>
                                <span class="badge" style="background: #f1f5f9; color: #64748b; font-size: 11px;">Unregistered (B2C)</span>
                            <?php endif; ?>
                        </div>
                        <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 20px;">
                            These details appear in the <strong>"Billed To (Recipient)"</strong> section on all official tax invoices & receipts for <strong><?= sanitizeOutput($t['clinic_name']) ?></strong> so they can claim Input Tax Credit (ITC).
                        </p>
                        <div class="grid-3 gap-16 mb-16">
                            <div class="form-group mb-0">
                                <label class="form-label font-semibold">Clinic GSTIN (15 Digits)</label>
                                <input type="text" name="clinic_gst_number" class="form-control" value="<?= sanitizeOutput($t['gst_number'] ?? '') ?>" placeholder="e.g. 33XYZAB9876C1Z2" maxlength="15" style="text-transform: uppercase;">
                                <small class="text-muted">Leave empty if clinic is unregistered under GST.</small>
                            </div>
                            <div class="form-group mb-0">
                                <label class="form-label font-semibold">Clinic PAN Number (10 Digits)</label>
                                <input type="text" name="clinic_pan_number" class="form-control" value="<?= sanitizeOutput($t['pan_number'] ?? '') ?>" placeholder="e.g. ABCDE1234F" maxlength="10" style="text-transform: uppercase;">
                            </div>
                            <div class="form-group mb-0">
                                <label class="form-label font-semibold">Billing Address for Invoices</label>
                                <input type="text" name="clinic_billing_address" class="form-control" value="<?= sanitizeOutput($t['billing_address'] ?? '') ?>" placeholder="Street, Area, City, State - Pincode">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; padding-top: 16px; border-top: 1px solid var(--border-color);">
                <div style="font-size: 13px; color: var(--text-muted);">
                    <i class="fas fa-info-circle"></i> Saving updates the customized pricing rates that this clinic sees on their renewal portal and paywall.
                </div>
                <button type="submit" class="btn btn-primary" style="padding: 12px 24px; font-weight: 700;">
                    <i class="fas fa-save"></i> Save Customized Pricing for This Clinic
                </button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- TAB 2: PAYMENT HISTORY LEDGER (THIS CLINIC ONLY) -->
    <?php if ($currentTab === 'payments'): ?>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table" style="margin: 0;">
                <thead>
                    <tr>
                        <th>Receipt #</th>
                        <th>Amount</th>
                        <th>Payment Mode</th>
                        <th>Validity Granted</th>
                        <th>Collected By</th>
                        <th>Date</th>
                        <th style="text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($payments)): ?>
                    <tr><td colspan="7" class="text-center text-muted" style="padding: 30px;">No payment records logged for this clinic yet.</td></tr>
                    <?php else: foreach ($payments as $p): 
                        $modePills = [
                            'cash_on_hand' => '<span class="badge" style="background:#dcfce7; color:#15803d; border:1px solid #bbf7d0; font-weight:700;"><i class="fas fa-hand-holding-usd"></i> COD (Cash)</span>',
                            'razorpay' => '<span class="badge" style="background:#cffafe; color:#0e7490; border:1px solid #a5f3fc; font-weight:700;"><i class="fas fa-bolt"></i> Razorpay</span>',
                            'bank_transfer' => '<span class="badge" style="background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; font-weight:700;"><i class="fas fa-university"></i> Bank Transfer</span>',
                            'upi' => '<span class="badge" style="background:#f3e8ff; color:#7e22ce; border:1px solid #e9d5ff; font-weight:700;"><i class="fas fa-mobile-alt"></i> UPI</span>',
                            'cheque' => '<span class="badge" style="background:#fef3c7; color:#b45309; border:1px solid #fde68a; font-weight:700;"><i class="fas fa-money-check-alt"></i> Cheque</span>'
                        ];
                    ?>
                    <tr>
                        <td>
                            <strong><?= sanitizeOutput($p['payment_reference'] ?: ('REC-' . $p['id'])) ?></strong>
                        </td>
                        <td>
                            <span style="font-size: 15px; font-weight: 800; color: #059669;">₹<?= number_format($p['amount'], 2) ?></span>
                            <?php if (!empty($p['gst_amount']) && $p['gst_amount'] > 0): ?>
                            <div style="font-size: 11px; color: var(--text-muted); font-weight: 500;">
                                Base: ₹<?= number_format($p['base_amount'], 2) ?> + 18% GST: ₹<?= number_format($p['gst_amount'], 2) ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $modePills[$p['payment_mode']] ?? ucfirst(str_replace('_', ' ', $p['payment_mode'])) ?>
                        </td>
                        <td>
                            <?php if ($p['plan_type'] === 'one_time' || empty($p['period_end'])): ?>
                                <span style="color: #0284c7; font-weight: 700;"><i class="fas fa-infinity"></i> Lifetime</span>
                            <?php else: ?>
                                <span style="font-size: 12px; font-weight: 600;"><?= date('d M Y', strtotime($p['period_end'])) ?></span>
                            <?php endif; ?>
                            <div style="font-size: 11px; color: var(--text-muted);">
                                <?= $p['doctor_limit_granted'] > 0 ? $p['doctor_limit_granted'] . ' Doc Slots' : 'Unlimited' ?>
                            </div>
                        </td>
                        <td>
                            <?= sanitizeOutput($p['collected_by'] ?: 'Super Admin') ?>
                        </td>
                        <td>
                            <span style="font-size: 12px;"><?= date('d M Y, h:i A', strtotime($p['created_at'])) ?></span>
                        </td>
                        <td style="text-align: right;">
                            <?php if (($p['status'] ?? '') === 'cancelled'): ?>
                                <span class="badge" style="background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; font-weight: 700; padding: 4px 10px;">
                                    <i class="fas fa-ban"></i> Cancelled
                                </span>
                            <?php else: ?>
                            <div style="display: flex; gap: 4px; justify-content: flex-end;">
                                <a href="<?= BASE_URL ?>/modules/admin/print_subscription_receipt.php?id=<?= $p['id'] ?>" target="_blank" class="btn btn-sm btn-outline">
                                    <i class="fas fa-print"></i> Receipt
                                </a>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to cancel/void this receipt? This action cannot be undone.');">
                                    <input type="hidden" name="action" value="cancel_receipt">
                                    <input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
                                    <button type="submit" class="btn btn-sm" style="background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; font-weight: 600;">
                                        <i class="fas fa-times-circle"></i> Cancel
                                    </button>
                                </form>
                            </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- TAB 3: SAAS GLOBAL PLATFORM DEFAULTS -->
    <?php if ($currentTab === 'settings'): ?>
    <div class="card-body">
        <form method="POST" action="">
            <input type="hidden" name="action" value="update_global_settings">
            
            <h4 class="mb-16" style="color: var(--primary);"><i class="fas fa-globe"></i> Global Default Plan Pricing & Quotas (Fallback for New Clinics)</h4>
            <div class="grid-2 gap-24 mb-24">
                <!-- Free Trial -->
                <div class="card" style="background: var(--bg-secondary); border: 1px solid var(--border-color);">
                    <div class="card-body">
                        <h4 style="margin: 0 0 12px; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-vial" style="color: #64748b;"></i> Default Trial Tier
                        </h4>
                        <div class="grid-2 gap-12">
                            <div class="form-group">
                                <label class="form-label">Trial Duration (Months)</label>
                                <input type="number" name="trial_duration_months" class="form-control" value="<?= sanitizeOutput($settings['trial_duration_months'] ?? '1') ?>" min="1" max="12" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Max Doctors</label>
                                <input type="number" name="trial_max_doctors" class="form-control" value="<?= sanitizeOutput($settings['trial_max_doctors'] ?? '2') ?>" min="1" required>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Monthly Plan -->
                <div class="card" style="background: var(--bg-secondary); border: 1px solid var(--border-color);">
                    <div class="card-body">
                        <h4 style="margin: 0 0 12px; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-calendar-alt" style="color: #059669;"></i> Default Monthly Tier
                        </h4>
                        <div class="grid-2 gap-12">
                            <div class="form-group">
                                <label class="form-label">Monthly Price (₹)</label>
                                <input type="number" name="monthly_price" class="form-control" value="<?= sanitizeOutput($settings['monthly_price'] ?? '1499') ?>" step="0.01" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Max Doctors</label>
                                <input type="number" name="monthly_max_doctors" class="form-control" value="<?= sanitizeOutput($settings['monthly_max_doctors'] ?? '3') ?>" min="1" required>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Yearly Plan -->
                <div class="card" style="background: var(--bg-secondary); border: 1px solid var(--border-color);">
                    <div class="card-body">
                        <h4 style="margin: 0 0 12px; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-crown" style="color: #d97706;"></i> Default Yearly Tier (+Bonus)
                        </h4>
                        <div class="grid-3 gap-12">
                            <div class="form-group">
                                <label class="form-label">Yearly Price (₹)</label>
                                <input type="number" name="yearly_price" class="form-control" value="<?= sanitizeOutput($settings['yearly_price'] ?? '14999') ?>" step="0.01" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Default Bonus Months</label>
                                <input type="number" name="yearly_default_bonus_months" class="form-control" value="<?= sanitizeOutput($settings['yearly_default_bonus_months'] ?? '2') ?>" min="0">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Max Doctors</label>
                                <input type="number" name="yearly_max_doctors" class="form-control" value="<?= sanitizeOutput($settings['yearly_max_doctors'] ?? '10') ?>" min="1" required>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- One Time Lifetime -->
                <div class="card" style="background: var(--bg-secondary); border: 1px solid var(--border-color);">
                    <div class="card-body">
                        <h4 style="margin: 0 0 12px; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-infinity" style="color: #0284c7;"></i> Default Lifetime Tier
                        </h4>
                        <div class="grid-2 gap-12">
                            <div class="form-group">
                                <label class="form-label">Lifetime Price (₹)</label>
                                <input type="number" name="one_time_price" class="form-control" value="<?= sanitizeOutput($settings['one_time_price'] ?? '49999') ?>" step="0.01" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Max Doctors (0=Unlimited)</label>
                                <input type="number" name="one_time_max_doctors" class="form-control" value="<?= sanitizeOutput($settings['one_time_max_doctors'] ?? '0') ?>" min="0" required>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Doctor Add-on Capacity Rates -->
                <div class="card" style="background: var(--bg-secondary); border: 1px solid var(--border-color);">
                    <div class="card-body">
                        <h4 style="margin: 0 0 12px; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-plus-circle" style="color: #0891b2;"></i> Doctor Add-On Pack Defaults
                        </h4>
                        <div class="grid-2 gap-12">
                            <div class="form-group">
                                <label class="form-label">Monthly Add-on Rate per Doctor (₹)</label>
                                <input type="number" name="addon_doctor_monthly_price" class="form-control" value="<?= sanitizeOutput($settings['addon_doctor_monthly_price'] ?? '25') ?>" step="0.01" required>
                                <small class="text-muted">Default: ₹25 / month per extra doctor (+ 18% GST)</small>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Yearly Add-on Rate per Doctor (₹)</label>
                                <input type="number" name="addon_doctor_yearly_price" class="form-control" value="<?= sanitizeOutput($settings['addon_doctor_yearly_price'] ?? '250') ?>" step="0.01" required>
                                <small class="text-muted">Default: ₹250 / year per extra doctor (+ 18% GST)</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SaaS Platform Company & GST Details (Supplier / Billed By) -->
                <div class="card" style="background: var(--bg-secondary); border: 2px solid #00838f; border-radius: 12px; grid-column: span 2;">
                    <div class="card-body">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                            <h4 style="margin: 0; display: flex; align-items: center; gap: 8px; color: #00838f;">
                                <i class="fas fa-landmark"></i> SaaS Platform Legal & GST Details (Supplier / Billed By)
                            </h4>
                            <?php if (!empty($settings['platform_gstin'])): ?>
                                <span class="badge badge-success" style="font-size: 11px;"><i class="fas fa-check-circle"></i> GSTIN: <?= sanitizeOutput($settings['platform_gstin']) ?></span>
                            <?php else: ?>
                                <span class="badge" style="background: #fef3c7; color: #b45309; font-size: 11px;">GSTIN Not Set (Unregistered)</span>
                            <?php endif; ?>
                        </div>
                        <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 16px;">
                            These details appear in the <strong>"Billed By (Supplier)"</strong> section on all official Tax Invoices (SAC: 998314) issued to clinics when they subscribe, renew, or purchase add-on doctor slots.
                        </p>
                        <div class="grid-3 gap-16 mb-16">
                            <div class="form-group mb-0">
                                <label class="form-label font-semibold">Legal Company / Business Name <span class="required">*</span></label>
                                <input type="text" name="platform_company_name" class="form-control" value="<?= sanitizeOutput($settings['platform_company_name'] ?? 'Feature Gen Technologies') ?>" required>
                            </div>
                            <div class="form-group mb-0">
                                <label class="form-label font-semibold">Platform GSTIN (15 Digits)</label>
                                <input type="text" name="platform_gstin" class="form-control" value="<?= sanitizeOutput($settings['platform_gstin'] ?? '') ?>" placeholder="e.g. 33ABCDE1234F1Z5" maxlength="15" style="text-transform: uppercase;">
                                <small class="text-muted">Required to legally charge 18% GST.</small>
                            </div>
                            <div class="form-group mb-0">
                                <label class="form-label font-semibold">Platform PAN Number (10 Digits)</label>
                                <input type="text" name="platform_pan" class="form-control" value="<?= sanitizeOutput($settings['platform_pan'] ?? '') ?>" placeholder="e.g. ABCDE1234F" maxlength="10" style="text-transform: uppercase;">
                            </div>
                        </div>
                        <div class="grid-2 gap-16">
                            <div class="form-group mb-0">
                                <label class="form-label font-semibold">Registered Business Address</label>
                                <input type="text" name="platform_address" class="form-control" value="<?= sanitizeOutput($settings['platform_address'] ?? 'Chennai, Tamil Nadu, India') ?>">
                            </div>
                            <div class="form-group mb-0">
                                <label class="form-label font-semibold">Registered State & State Code</label>
                                <input type="text" name="platform_state" class="form-control" value="<?= sanitizeOutput($settings['platform_state'] ?? 'Tamil Nadu (State Code: 33)') ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Global Razorpay Payment Gateway Credentials -->
                <div class="card" style="background: var(--bg-secondary); border: 2px solid #00838f; border-radius: 12px; grid-column: span 2;">
                    <div class="card-body">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                            <h4 style="margin: 0; display: flex; align-items: center; gap: 8px; color: #00838f;">
                                <i class="fas fa-bolt"></i> SaaS Platform Razorpay Gateway (Global Fallback)
                            </h4>
                            <?php if (!empty($settings['razorpay_key_id'])): ?>
                                <span class="badge badge-success" style="font-size: 11px;"><i class="fas fa-check-circle"></i> Online Gateway Active</span>
                            <?php else: ?>
                                <span class="badge badge-warning" style="font-size: 11px; background: #fef3c7; color: #b45309;"><i class="fas fa-exclamation-triangle"></i> Not Configured (Offline Mode)</span>
                            <?php endif; ?>
                        </div>
                        <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 16px;">
                            These credentials enable online subscription and add-on card/UPI payments for all clinics on the platform. 
                            Find your keys in the <a href="https://dashboard.razorpay.com/#/app/keys" target="_blank" style="color: #00838f; font-weight: 600; text-decoration: underline;">Razorpay Dashboard &rarr; Settings &rarr; API Keys</a>.
                        </p>
                        <div class="grid-2 gap-16 mb-16">
                            <div class="form-group mb-0">
                                <label class="form-label font-semibold">Razorpay Key ID</label>
                                <input type="text" name="razorpay_key_id" class="form-control" value="<?= sanitizeOutput($settings['razorpay_key_id'] ?? '') ?>" placeholder="rzp_test_... or rzp_live_...">
                                <small class="text-muted">Starts with <code>rzp_test_</code> (for testing) or <code>rzp_live_</code> (for real payments).</small>
                            </div>
                            <div class="form-group mb-0">
                                <label class="form-label font-semibold">Razorpay Key Secret</label>
                                <input type="password" name="razorpay_key_secret" class="form-control" value="<?= sanitizeOutput($settings['razorpay_key_secret'] ?? '') ?>" placeholder="••••••••••••••••">
                                <small class="text-muted">Secret key used for secure server-side verification.</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Offline Payment & Bank Details -->
                <div class="card" style="background: var(--bg-secondary); border: 1px solid var(--border-color); grid-column: span 2;">
                    <div class="card-body">
                        <h4 style="margin: 0 0 12px; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-university" style="color: #059669;"></i> Offline Payment Contact & Bank Details
                        </h4>
                        <div class="grid-2 gap-16">
                            <div class="form-group mb-0">
                                <label class="form-label font-semibold">Offline Payment Support Line / Contact</label>
                                <input type="text" name="offline_payment_contact" class="form-control" value="<?= sanitizeOutput($settings['offline_payment_contact'] ?? '') ?>" placeholder="Phone: +91 98765 43210 | WhatsApp: +91 98765 43210">
                            </div>
                            <div class="form-group mb-0">
                                <label class="form-label font-semibold">Bank Account & UPI Instructions</label>
                                <textarea name="offline_bank_details" class="form-control" rows="3" placeholder="Account Name, Number, IFSC, UPI ID..."><?= sanitizeOutput($settings['offline_bank_details'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-end">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save Platform Defaults
                </button>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>

<!-- ============================================ -->
<!-- MODAL: ACTIVATE PLAN CONFIRMATION -->
<!-- ============================================ -->
<div id="activatePlanModal" class="custom-modal-backdrop" style="display:none;">
    <div class="custom-modal-dialog" style="width: 520px;">
        <div class="card modal-card">
            <div class="card-header modal-header-bar" style="background: linear-gradient(135deg, #00838f, #00695c); color: white;">
                <h3 style="color: white;"><i class="fas fa-check-circle"></i> Activate Plan for Clinic</h3>
                <button type="button" class="modal-close-btn" onclick="closeActivateModal()" style="color: white;" aria-label="Close">&times;</button>
            </div>
            <form method="POST" action="" class="modal-form-wrap">
                <input type="hidden" name="action" value="activate_plan_tier">
                <input type="hidden" name="tier" id="actTier">
                
                <div class="card-body modal-scroll-body">
                    <div style="background: var(--bg-secondary); padding: 14px 18px; border-radius: 8px; margin-bottom: 20px; border: 1px solid var(--border-color);">
                        <div style="font-size: 11px; text-transform: uppercase; color: var(--text-muted); font-weight: 700;">Activating For Clinic</div>
                        <div style="font-size: 16px; font-weight: 700; color: var(--primary); margin: 2px 0;"><?= sanitizeOutput($t['clinic_name']) ?></div>
                        <div style="font-size: 14px; font-weight: 600; color: #00838f;" id="actPlanTitle">Yearly Plan</div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; margin-bottom: 16px;">
                        <div style="background: #f8fafc; padding: 10px; border-radius: 6px; border: 1px solid #e2e8f0;">
                            <div style="font-size: 11px; color: #64748b;">Base Fee</div>
                            <div style="font-size: 15px; font-weight: 800; color: var(--text);" id="actPlanBaseDisplay">₹14,999.00</div>
                        </div>
                        <div style="background: #f8fafc; padding: 10px; border-radius: 6px; border: 1px solid #e2e8f0;">
                            <div style="font-size: 11px; color: #64748b;">+ 18% GST</div>
                            <div style="font-size: 15px; font-weight: 800; color: #d97706;" id="actPlanGstDisplay">₹2,699.82</div>
                        </div>
                        <div style="background: #f8fafc; padding: 10px; border-radius: 6px; border: 1px solid #e2e8f0;">
                            <div style="font-size: 11px; color: #64748b;">Total Due</div>
                            <div style="font-size: 15px; font-weight: 800; color: #00838f;" id="actPlanPriceDisplay">₹17,698.82</div>
                        </div>
                    </div>
                    <div style="margin-bottom: 20px; background: #f1f5f9; padding: 8px 12px; border-radius: 6px; font-size: 12px; display: flex; justify-content: space-between; align-items: center;">
                        <span style="color: #475569;">Doctor Quota: <strong id="actPlanDoctorDisplay">10 Doctors</strong></span>
                        <span class="badge" style="background: #e0f2fe; color: #0369a1; font-weight: 700; font-size: 11px;">SAC: 998314</span>
                    </div>

                    <div class="form-group mb-16">
                        <label class="form-label font-semibold">Start Validity Period From</label>
                        <select name="extend_mode" class="form-control">
                            <option value="now" selected>Today (Reset & Start from Now)</option>
                            <?php if ($endsAt && $endsAt > $now): ?>
                            <option value="extend">Extend from Current Expiry (<?= date('d M Y', $endsAt) ?>)</option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="form-group mb-0" id="actCashOptionGroup">
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; background: #f0fdf4; border: 1px solid #bbf7d0; padding: 12px; border-radius: 8px;">
                            <input type="checkbox" name="record_cash_payment" value="1" checked>
                            <div>
                                <strong style="color: #15803d; font-size: 13px;">Also log as COD (Cash on Hand) Payment</strong>
                                <div style="font-size: 11px; color: #166534;">Generates official receipt # and records payment in ledger immediately.</div>
                            </div>
                        </label>
                    </div>
                </div>

                <div class="card-footer modal-footer-bar">
                    <button type="button" class="btn btn-outline" onclick="closeActivateModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="background: #00838f; border: none;">
                        <i class="fas fa-check"></i> Confirm & Activate Plan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODAL: RECORD PAYMENT (CASH ON HAND) -->
<!-- Pre-populated with this clinic's custom rates -->
<!-- ============================================ -->
<div id="paymentModal" class="custom-modal-backdrop" style="display:none;">
    <div class="custom-modal-dialog" style="width: 620px;">
        <div class="card modal-card">
            <div class="card-header modal-header-bar" style="background: linear-gradient(135deg, #059669, #047857); color: white;">
                <h3 style="color: white;"><i class="fas fa-hand-holding-usd"></i> Record Subscription Payment</h3>
                <button type="button" class="modal-close-btn" onclick="closePaymentModal()" style="color: white;" aria-label="Close">&times;</button>
            </div>
            <form method="POST" action="" class="modal-form-wrap">
                <input type="hidden" name="action" value="record_payment">
                <input type="hidden" name="tenant_id" value="<?= $t['id'] ?>">
                
                <div class="card-body modal-scroll-body">
                    <!-- Fixed Clinic Target -->
                    <div style="background: var(--bg-secondary); padding: 12px 16px; border-radius: 8px; margin-bottom: 18px; border: 1px solid var(--border-color);">
                        <div style="font-size: 11px; text-transform: uppercase; color: var(--text-muted); font-weight: 700;">Recording Payment For</div>
                        <div style="font-size: 16px; font-weight: 700; color: var(--primary);"><?= sanitizeOutput($t['clinic_name']) ?></div>
                        <div style="font-size: 12px; color: var(--text-muted);"><i class="fas fa-globe"></i> <?= sanitizeOutput($t['subdomain']) ?>.featuregen.com</div>
                    </div>

                    <div class="form-group mb-16">
                        <label class="form-label font-semibold">Payment Mode <span class="required">*</span></label>
                        <select name="payment_mode" class="form-control" required>
                            <option value="cash_on_hand" selected>💵 COD (Cash on Hand / Direct Handover)</option>
                            <option value="upi">📱 UPI (Instant Transfer / QR / GPay / PhonePe)</option>
                            <option value="bank_transfer">🏦 Net Banking / Direct Bank Transfer (IMPS / NEFT)</option>
                            <option value="cheque">📝 Cheque / Demand Draft</option>
                        </select>
                    </div>

                    <!-- Option A: Base + 18% GST = Total Payable -->
                    <div class="grid-3 gap-12 mb-16" style="background: #f8fafc; padding: 12px; border-radius: 8px; border: 1px solid #e2e8f0;">
                        <div class="form-group mb-0">
                            <label class="form-label font-semibold" style="font-size: 12px;">Base Fee (₹) <span class="required">*</span></label>
                            <input type="number" name="base_amount" id="payBaseAmount" class="form-control" step="0.01" value="<?= $effYearlyPrice ?>" required oninput="calcPaymentGst('base')">
                        </div>
                        <div class="form-group mb-0">
                            <label class="form-label font-semibold" style="font-size: 12px;">18% GST (SAC 998314)</label>
                            <input type="number" name="gst_amount" id="payGstAmount" class="form-control" step="0.01" value="<?= round($effYearlyPrice * 0.18, 2) ?>" readonly style="background: #f1f5f9; color: #b45309; font-weight: 600;">
                            <input type="hidden" name="gst_rate" value="18.00">
                        </div>
                        <div class="form-group mb-0">
                            <label class="form-label font-semibold" style="font-size: 12px; color: #00838f;">Total Collected (₹) <span class="required">*</span></label>
                            <input type="number" name="amount" id="payAmount" class="form-control" step="0.01" value="<?= round($effYearlyPrice * 1.18, 2) ?>" required oninput="calcPaymentGst('total')" style="font-weight: 800; color: #00838f;">
                        </div>
                    </div>

                    <div class="grid-2 gap-16 mb-16">
                        <div class="form-group">
                            <label class="form-label font-semibold">Receipt / Reference #</label>
                            <input type="text" name="payment_reference" id="payReference" class="form-control" value="REC-<?= date('Y') ?>-<?= rand(1000, 9999) ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label font-semibold">Collected By <span class="required">*</span></label>
                            <input type="text" name="collected_by" class="form-control" value="<?= sanitizeOutput(getSession('full_name', 'Super Admin')) ?>" required>
                        </div>
                    </div>

                    <div class="grid-2 gap-16 mb-16">
                        <div class="form-group">
                            <label class="form-label font-semibold">Plan to Grant <span class="required">*</span></label>
                            <select name="period_type" id="payPeriodType" class="form-control" onchange="handlePaymentPeriodChange()">
                                <option value="12_months" selected>Yearly (12 Months + <?= $effYearlyBonus ?> Free Bonus)</option>
                                <option value="1_month">Monthly (1 Month)</option>
                                <option value="lifetime">One-Time Lifetime License</option>
                                <option value="doctor_addon">⚡ Doctor Capacity Add-On Slots</option>
                                <option value="custom">Custom Days</option>
                            </select>
                        </div>
                        <div class="form-group" id="payBonusGroup">
                            <label class="form-label font-semibold">Free Bonus Months Granted</label>
                            <input type="number" name="bonus_months_granted" id="payBonusMonths" class="form-control" value="<?= $effYearlyBonus ?>" min="0">
                        </div>
                    </div>

                    <div class="grid-2 gap-16 mb-16">
                        <div class="form-group">
                            <label class="form-label font-semibold">Doctor Limit Granted <span class="required">*</span></label>
                            <input type="number" name="doctor_limit_granted" id="payDoctorLimit" class="form-control" value="<?= $effYearlyDoctors ?>" min="1" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label font-semibold">Payment Date <span class="required">*</span></label>
                            <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>

                    <div class="form-group mb-0">
                        <label class="form-label font-semibold">Payment & Handover Notes</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="e.g. Cash on hand received directly at clinic office."></textarea>
                    </div>
                </div>
                
                <div class="card-footer modal-footer-bar">
                    <button type="button" class="btn btn-outline" onclick="closePaymentModal()">Cancel</button>
                    <button type="submit" class="btn btn-success" style="background: #059669; color: white;">
                        <i class="fas fa-check-circle"></i> Confirm Payment & Issue Receipt
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.tab-link {
    padding: 14px 20px;
    color: var(--text-muted);
    text-decoration: none;
    font-weight: 600;
    font-size: 14px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    border-bottom: 2px solid transparent;
}
.tab-link:hover { color: var(--primary); }
.tab-link.active {
    color: var(--primary);
    border-bottom-color: var(--primary);
    background: var(--bg-card);
}

.custom-modal-backdrop {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 99999;
    background: rgba(15, 23, 42, 0.7);
    backdrop-filter: blur(4px);
    overflow-y: auto;
    padding: 24px 16px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.custom-modal-dialog {
    max-width: 100%;
    margin: auto;
    position: relative;
}

.modal-card {
    border-radius: 14px;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.35);
    border: 1px solid var(--border-color);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    max-height: 88vh;
    animation: modalSlideUp 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}

.modal-header-bar {
    padding: 16px 22px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.modal-header-bar h3 {
    margin: 0;
    font-size: 17px;
    font-weight: 700;
}

.modal-close-btn {
    background: none;
    border: none;
    font-size: 26px;
    line-height: 1;
    cursor: pointer;
    opacity: 0.8;
    transition: opacity 0.2s;
    padding: 0 4px;
}
.modal-close-btn:hover { opacity: 1; }

.modal-form-wrap {
    display: flex;
    flex-direction: column;
    overflow: hidden;
    flex: 1;
}

.modal-scroll-body {
    overflow-y: auto;
    padding: 22px;
    flex: 1;
}

.modal-footer-bar {
    padding: 14px 22px;
    background: var(--bg-secondary);
    border-top: 1px solid var(--border-color);
    flex-shrink: 0;
    display: flex;
    justify-content: flex-end;
    gap: 12px;
}

@keyframes modalSlideUp {
    from { opacity: 0; transform: translateY(24px) scale(0.97); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}
</style>

<script>
// Live update preview of bonus months & yearly price
const custYearlyPriceInput = document.getElementById('custYearlyPrice');
const custYearlyBonusInput = document.getElementById('custYearlyBonus');
if (custYearlyPriceInput && custYearlyBonusInput) {
    function updateYearlyPreview() {
        const bonus = parseInt(custYearlyBonusInput.value) || 0;
        const price = parseFloat(custYearlyPriceInput.value) || 0;
        const prevBonus = document.getElementById('previewBonusMonths');
        const prevTotal = document.getElementById('previewTotalMonths');
        const prevPrice = document.getElementById('previewYearlyPrice');
        if (prevBonus) prevBonus.textContent = bonus;
        if (prevTotal) prevTotal.textContent = 12 + bonus;
        if (prevPrice) prevPrice.textContent = price.toLocaleString('en-IN');
    }
    custYearlyPriceInput.addEventListener('input', updateYearlyPreview);
    custYearlyBonusInput.addEventListener('input', updateYearlyPreview);
}

// Open Activate Plan Modal
function triggerActivatePlan(tier, title, price, doctors, bonus) {
    // Check if already on the same plan
    const currentPlan = clinicCustomRates.currentPlan;
    const tierToPlanMap = { 'monthly': 'monthly', 'yearly': 'yearly', 'lifetime': 'one_time' };
    const mappedTier = tierToPlanMap[tier] || tier;
    
    if (currentPlan === mappedTier && currentPlan !== 'trial') {
        if (!confirm('⚠️ This clinic is already on the ' + title + ' plan.\n\nAre you sure you want to activate the same plan again? This may create a duplicate billing.')) {
            return;
        }
    }

    const base = parseFloat(price) || 0;
    const gst = Math.round(base * 18) / 100;
    const total = Math.round((base + gst) * 100) / 100;

    document.getElementById('actTier').value = tier;
    document.getElementById('actPlanTitle').textContent = title;
    document.getElementById('actPlanBaseDisplay').textContent = '₹' + base.toLocaleString('en-IN', { minimumFractionDigits: 2 });
    document.getElementById('actPlanGstDisplay').textContent = '₹' + gst.toLocaleString('en-IN', { minimumFractionDigits: 2 });
    document.getElementById('actPlanPriceDisplay').textContent = '₹' + total.toLocaleString('en-IN', { minimumFractionDigits: 2 });
    document.getElementById('actPlanDoctorDisplay').textContent = (doctors > 0 ? doctors + ' Doctors' : 'Unlimited Doctors') + (bonus > 0 ? ' (+' + bonus + 'm Bonus)' : '');
    
    const cashGroup = document.getElementById('actCashOptionGroup');
    if (price <= 0) {
        cashGroup.style.display = 'none';
    } else {
        cashGroup.style.display = 'block';
    }
    
    document.getElementById('activatePlanModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeActivateModal() {
    document.getElementById('activatePlanModal').style.display = 'none';
    document.body.style.overflow = '';
}

// Payment Modal Controls
function openPaymentModal() {
    document.getElementById('paymentModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closePaymentModal() {
    document.getElementById('paymentModal').style.display = 'none';
    document.body.style.overflow = '';
}

// Click backdrop to close
document.getElementById('paymentModal').addEventListener('click', function(e) {
    if (e.target === this || e.target.classList.contains('custom-modal-dialog')) {
        closePaymentModal();
    }
});
document.getElementById('activatePlanModal').addEventListener('click', function(e) {
    if (e.target === this || e.target.classList.contains('custom-modal-dialog')) {
        closeActivateModal();
    }
});

// ESC key closes any open modal
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closePaymentModal();
        closeActivateModal();
    }
});

// Switch pricing in Record Payment according to this clinic's custom rates
const clinicCustomRates = {
    monthlyPrice: <?= json_encode($effMonthlyPrice) ?>,
    monthlyDoctors: <?= json_encode($effMonthlyDoctors) ?>,
    yearlyPrice: <?= json_encode($effYearlyPrice) ?>,
    yearlyDoctors: <?= json_encode($effYearlyDoctors) ?>,
    yearlyBonus: <?= json_encode($effYearlyBonus) ?>,
    lifetimePrice: <?= json_encode($effLifetimePrice) ?>,
    lifetimeDoctors: <?= json_encode($effLifetimeDoctors) ?>,
    addonMonthly: <?= json_encode($effAddonMonthly) ?>,
    addonYearly: <?= json_encode($effAddonYearly) ?>,
    currentPlan: <?= json_encode($t['plan_type'] ?? 'trial') ?>
};

function calcPaymentGst(source) {
    const baseInput = document.getElementById('payBaseAmount');
    const gstInput = document.getElementById('payGstAmount');
    const totalInput = document.getElementById('payAmount');
    
    if (!baseInput || !gstInput || !totalInput) return;
    
    if (source === 'base') {
        const base = parseFloat(baseInput.value) || 0;
        const gst = Math.round(base * 18) / 100;
        const total = Math.round((base + gst) * 100) / 100;
        gstInput.value = gst.toFixed(2);
        totalInput.value = total.toFixed(2);
    } else {
        const total = parseFloat(totalInput.value) || 0;
        const base = Math.round((total / 1.18) * 100) / 100;
        const gst = Math.round((total - base) * 100) / 100;
        baseInput.value = base.toFixed(2);
        gstInput.value = gst.toFixed(2);
    }
}

function handlePaymentPeriodChange() {
    const period = document.getElementById('payPeriodType').value;
    const bonusGroup = document.getElementById('payBonusGroup');
    let base = 0;
    if (period === '12_months') {
        bonusGroup.style.display = 'block';
        base = clinicCustomRates.yearlyPrice;
        document.getElementById('payBonusMonths').value = clinicCustomRates.yearlyBonus;
        document.getElementById('payDoctorLimit').value = clinicCustomRates.yearlyDoctors;
    } else if (period === '1_month') {
        bonusGroup.style.display = 'none';
        base = clinicCustomRates.monthlyPrice;
        document.getElementById('payDoctorLimit').value = clinicCustomRates.monthlyDoctors;
    } else if (period === 'lifetime') {
        bonusGroup.style.display = 'none';
        base = clinicCustomRates.lifetimePrice;
        document.getElementById('payDoctorLimit').value = clinicCustomRates.lifetimeDoctors;
    } else if (period === 'doctor_addon') {
        bonusGroup.style.display = 'none';
        base = (clinicCustomRates.currentPlan === 'yearly') ? clinicCustomRates.addonYearly : clinicCustomRates.addonMonthly;
        document.getElementById('payDoctorLimit').value = 1; // 1 extra doctor
    } else {
        bonusGroup.style.display = 'none';
        base = parseFloat(document.getElementById('payBaseAmount').value) || 0;
    }
    document.getElementById('payBaseAmount').value = base.toFixed(2);
    calcPaymentGst('base');
}
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
