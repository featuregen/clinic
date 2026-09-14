<?php
/**
 * Clinic Subscription Paywall & Upgrade Portal
 * Feature Gen Care
 */
$pageTitle = 'Subscription & Renewal';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';

$master = master_db();
$tenant = db()->tenantInfo;
$clinicId = getCurrentClinicId();
$currentUserRole = getCurrentUserRole();

// Fetch latest tenant info from Master DB
$stmtT = $master->prepare("SELECT * FROM tenants WHERE id = ?");
$stmtT->execute([$tenant['id']]);
$currentTenant = $stmtT->fetch() ?: $tenant;

// Fetch SaaS Global Settings
$settingsRows = $master->query("SELECT setting_key, setting_value FROM saas_global_settings")->fetchAll();
$settings = [];
foreach ($settingsRows as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Read Custom Clinic Pricing (decided by Super Admin), falling back to global defaults
$trialMonths = !empty($currentTenant['custom_trial_months']) ? intval($currentTenant['custom_trial_months']) : intval($settings['trial_duration_months'] ?? 1);
$trialDoctors = !empty($currentTenant['custom_trial_doctors']) ? intval($currentTenant['custom_trial_doctors']) : intval($settings['trial_max_doctors'] ?? 2);

$monthlyPrice = !empty($currentTenant['custom_monthly_price']) ? floatval($currentTenant['custom_monthly_price']) : floatval($settings['monthly_price'] ?? 1499);
$monthlyDoctors = !empty($currentTenant['custom_monthly_doctors']) ? intval($currentTenant['custom_monthly_doctors']) : intval($settings['monthly_max_doctors'] ?? 3);

$yearlyPrice = !empty($currentTenant['custom_yearly_price']) ? floatval($currentTenant['custom_yearly_price']) : floatval($settings['yearly_price'] ?? 14999);
$yearlyDoctors = !empty($currentTenant['custom_yearly_doctors']) ? intval($currentTenant['custom_yearly_doctors']) : intval($settings['yearly_max_doctors'] ?? 10);
$yearlyBonus = isset($currentTenant['custom_yearly_bonus_months']) && $currentTenant['custom_yearly_bonus_months'] !== null ? intval($currentTenant['custom_yearly_bonus_months']) : intval($settings['yearly_default_bonus_months'] ?? 2);
$totalYearlyMonths = 12 + $yearlyBonus;

$lifetimePrice = !empty($currentTenant['custom_lifetime_price']) ? floatval($currentTenant['custom_lifetime_price']) : floatval($settings['one_time_price'] ?? 49999);
$lifetimeDoctors = isset($currentTenant['custom_lifetime_doctors']) && $currentTenant['custom_lifetime_doctors'] !== null ? intval($currentTenant['custom_lifetime_doctors']) : intval($settings['one_time_max_doctors'] ?? 0);

// Option A: Pricing is Exclusive of 18% GST (Standard Indian B2B SaaS SAC: 998314)
$gstRate = 18.00;

$monthlyBase = $monthlyPrice;
$monthlyGst = round($monthlyBase * ($gstRate / 100), 2);
$monthlyTotal = $monthlyBase + $monthlyGst;

$yearlyBase = $yearlyPrice;
$yearlyGst = round($yearlyBase * ($gstRate / 100), 2);
$yearlyTotal = $yearlyBase + $yearlyGst;

$lifetimeBase = $lifetimePrice;
$lifetimeGst = round($lifetimeBase * ($gstRate / 100), 2);
$lifetimeTotal = $lifetimeBase + $lifetimeGst;

// Doctor Add-on Rates: Rs. 25 per month or Rs. 250 per annum
$addonMonthlyPrice = !empty($currentTenant['custom_addon_doctor_monthly_price']) 
    ? floatval($currentTenant['custom_addon_doctor_monthly_price']) 
    : floatval($settings['addon_doctor_monthly_price'] ?? 25);

$addonYearlyPrice = !empty($currentTenant['custom_addon_doctor_yearly_price']) 
    ? floatval($currentTenant['custom_addon_doctor_yearly_price']) 
    : floatval($settings['addon_doctor_yearly_price'] ?? 250);

// Resolve current active doctor quota based on plan and custom/global settings plus any purchased add-ons
$activePlanType = $currentTenant['plan_type'] ?? 'trial';
$baseQuota = intval($currentTenant['max_doctors'] ?? 0);
if ($activePlanType === 'trial') {
    $baseQuota = $trialDoctors;
} elseif ($activePlanType === 'monthly') {
    $baseQuota = $monthlyDoctors;
} elseif ($activePlanType === 'yearly') {
    $baseQuota = $yearlyDoctors;
} elseif ($activePlanType === 'one_time') {
    $baseQuota = $lifetimeDoctors;
}

$addonDoctors = intval($currentTenant['addon_doctors'] ?? 0);
$activeQuota = ($baseQuota > 0) ? ($baseQuota + $addonDoctors) : 0;

if ($activeQuota > 0 && $activeQuota !== intval($currentTenant['max_doctors'] ?? 0)) {
    try {
        $master->prepare("UPDATE tenants SET max_doctors = ? WHERE id = ?")->execute([$activeQuota, $currentTenant['id']]);
        $currentTenant['max_doctors'] = $activeQuota;
    } catch (Exception $e) {}
}

$razorpayKey = !empty($currentTenant['custom_razorpay_key_id']) 
    ? $currentTenant['custom_razorpay_key_id'] 
    : ($settings['razorpay_key_id'] ?? '');
$offlineContact = $settings['offline_payment_contact'] ?? 'Phone: +91 98765 43210';
$offlineBank = $settings['offline_bank_details'] ?? '';

$isExpired = !empty($currentTenant['is_expired']);
$isLifetime = !empty($currentTenant['is_lifetime']) && $currentTenant['is_lifetime'] == 1;
$endsAt = !empty($currentTenant['subscription_ends_at']) ? strtotime($currentTenant['subscription_ends_at']) : null;

// Add-on Co-Termed Calculation:
// "whichever plan they are it will append based on plan ends calculate and get the money"
$daysRemaining = ($endsAt && $endsAt > time()) ? ceil(($endsAt - time()) / 86400) : 0;
$monthsRemaining = max(1, ceil($daysRemaining / 30.4167));

$addonRatePerDoctor = $addonMonthlyPrice;
$addonPeriodLabel = '1 Month';

if ($activePlanType === 'yearly') {
    if ($monthsRemaining >= 10) {
        $addonRatePerDoctor = $addonYearlyPrice; // ₹250 annual rate
        $addonPeriodLabel = $monthsRemaining . ' Months (Annual Rate)';
    } else {
        $addonRatePerDoctor = min($monthsRemaining * $addonMonthlyPrice, $addonYearlyPrice);
        $addonPeriodLabel = $monthsRemaining . ' Month' . ($monthsRemaining > 1 ? 's' : '') . ' Remaining';
    }
} elseif ($activePlanType === 'monthly') {
    $addonRatePerDoctor = $addonMonthlyPrice; // ₹25 / month
    $addonPeriodLabel = 'Current Month until ' . ($endsAt ? date('d M Y', $endsAt) : 'renewal');
} elseif ($activePlanType === 'trial') {
    $addonRatePerDoctor = $monthsRemaining * $addonMonthlyPrice;
    $addonPeriodLabel = $monthsRemaining . ' Month' . ($monthsRemaining > 1 ? 's' : '') . ' (Trial Period)';
}
?>

<div class="content-header">
    <div>
        <ul class="breadcrumb">
            <li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li>
            <li>Subscription</li>
        </ul>
        <h1><i class="fas fa-crown" style="color: #f59e0b;"></i> Subscription & Renewal Plans</h1>
    </div>
    <?php if ($currentUserRole === ROLE_SUPER_ADMIN): ?>
    <a href="<?= BASE_URL ?>/modules/admin/subscriptions.php" class="btn btn-primary">
        <i class="fas fa-sliders-h"></i> Super Admin Controls
    </a>
    <?php endif; ?>
</div>

<!-- Status Banner -->
<?php if ($isExpired): ?>
<div class="alert alert-danger mb-24" style="background: #fef2f2; border-left: 5px solid #dc2626; padding: 20px; border-radius: 10px;">
    <div style="display: flex; align-items: center; gap: 16px;">
        <div style="width: 48px; height: 48px; border-radius: 50%; background: #fee2e2; display: flex; align-items: center; justify-content: center; font-size: 24px; color: #dc2626; flex-shrink: 0;">
            <i class="fas fa-lock"></i>
        </div>
        <div>
            <h3 style="margin: 0 0 4px; color: #991b1b; font-size: 18px;">Subscription Lapsed / Expired</h3>
            <p style="margin: 0; color: #7f1d1d; font-size: 14px;">
                Your clinic's subscription access expired on <strong><?= $endsAt ? date('d M Y', $endsAt) : 'Recently' ?></strong>. 
                Choose an upgrade plan below to immediately re-enable appointments, doctor access, patient records, and billing.
            </p>
        </div>
    </div>
</div>
<?php elseif ($isLifetime): ?>
<div class="alert alert-info mb-24" style="background: #e0f2fe; border-left: 5px solid #0284c7; padding: 18px; border-radius: 10px;">
    <div style="display: flex; align-items: center; gap: 14px;">
        <i class="fas fa-infinity" style="font-size: 28px; color: #0284c7;"></i>
        <div>
            <h4 style="margin: 0 0 4px; color: #0369a1; font-size: 16px;">Lifetime Perpetual License Active</h4>
            <p style="margin: 0; font-size: 13px; color: #075985;">Your clinic has permanent unlimited access. No renewal is ever needed.</p>
        </div>
    </div>
</div>
<?php else: ?>
<div class="alert alert-success mb-24" style="background: #f0fdf4; border-left: 5px solid #16a34a; padding: 18px; border-radius: 10px;">
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
        <div style="display: flex; align-items: center; gap: 14px;">
            <i class="fas fa-check-circle" style="font-size: 28px; color: #16a34a;"></i>
            <div>
                <h4 style="margin: 0 0 4px; color: #15803d; font-size: 16px;">Current Plan: <span style="text-transform: capitalize;"><?= sanitizeOutput($currentTenant['plan_type']) ?></span></h4>
                <p style="margin: 0; font-size: 13px; color: #166534;">
                    Active until <strong><?= $endsAt ? date('d M Y', $endsAt) : 'Unlimited' ?></strong> &bull; Quota: <strong><?= $activeQuota > 0 ? $activeQuota . ' Doctors' : 'Unlimited' ?></strong>
                </p>
            </div>
        </div>
        <div style="font-size: 13px; font-weight: 600; color: #15803d;">
            Renew anytime to extend your validity ahead of time!
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Plan Cards Grid -->
<div class="grid-3 gap-24 mb-32" style="align-items: stretch; margin-top: 24px;">

    <!-- 1. Monthly Plan -->
    <div class="card" style="border: 2px solid <?= $activePlanType === 'monthly' ? '#16a34a' : '#e5e7eb' ?>; border-radius: 16px; transition: transform 0.2s, box-shadow 0.2s; display: flex; flex-direction: column;">
        <div class="card-body" style="padding: 32px 28px; flex: 1; display: flex; flex-direction: column;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <?php if ($activePlanType === 'monthly'): ?>
                <span class="badge badge-success" style="font-weight: 700; font-size: 12px; padding: 6px 12px; border-radius: 20px;">
                    <i class="fas fa-check-circle"></i> Your Current Plan
                </span>
                <?php else: ?>
                <span class="badge" style="background: #dcfce7; color: #15803d; font-weight: 700; font-size: 12px; padding: 6px 12px; border-radius: 20px;">
                    Monthly Flexibility
                </span>
                <?php endif; ?>
                <i class="fas fa-calendar-alt" style="font-size: 22px; color: #059669;"></i>
            </div>
            
            <h3 style="font-size: 22px; margin: 0 0 8px; color: var(--text);">Monthly Plan</h3>
            <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 24px;">Ideal for smaller clinics seeking month-to-month flexibility.</p>
            
            <div style="margin-bottom: 20px;">
                <span style="font-size: 34px; font-weight: 800; color: var(--text);">₹<?= number_format($monthlyBase, 0) ?></span>
                <span style="font-size: 14px; color: var(--text-muted);">/ month</span>
                <div style="font-size: 12px; color: var(--text-muted); margin-top: 4px; line-height: 1.4;">
                    <span>+ 18% GST (₹<?= number_format($monthlyGst, 2) ?>)</span> &bull; 
                    <strong style="color: var(--text);">Total: ₹<?= number_format($monthlyTotal, 2) ?></strong>
                </div>
            </div>

            <ul style="list-style: none; padding: 0; margin: 0 0 32px; flex: 1; font-size: 14px; line-height: 2;">
                <li><i class="fas fa-check" style="color: #059669; margin-right: 10px;"></i> Up to <strong><?= $monthlyDoctors ?> Doctors</strong></li>
                <li><i class="fas fa-check" style="color: #059669; margin-right: 10px;"></i> Full Patient Management</li>
                <li><i class="fas fa-check" style="color: #059669; margin-right: 10px;"></i> Appointments & Scheduling</li>
                <li><i class="fas fa-check" style="color: #059669; margin-right: 10px;"></i> Digital Prescriptions</li>
                <li><i class="fas fa-check" style="color: #059669; margin-right: 10px;"></i> Invoicing & Patient Billing</li>
                <li><i class="fas fa-check" style="color: #059669; margin-right: 10px;"></i> WhatsApp & SMS Notifications</li>
            </ul>

            <button type="button" class="btn btn-outline" style="width: 100%; padding: 12px; font-size: 15px; font-weight: 700;" onclick="selectPlan('monthly', <?= $monthlyBase ?>, <?= $monthlyGst ?>, <?= $monthlyTotal ?>, 'Monthly Plan')">
                <i class="fas fa-<?= $activePlanType === 'monthly' ? 'sync-alt' : 'bolt' ?>"></i> <?= $activePlanType === 'monthly' ? 'Renew Monthly Plan' : 'Upgrade to Monthly' ?>
            </button>
        </div>
    </div>

    <!-- 2. Yearly Plan (Featured) -->
    <div class="card" style="border: 2px solid #00838f; border-radius: 16px; box-shadow: 0 12px 35px rgba(0, 131, 143, 0.2); display: flex; flex-direction: column; overflow: hidden; position: relative;">
        <!-- Prominent Top Recommended Ribbon Header -->
        <div style="background: linear-gradient(135deg, #00838f, #00695c); color: white; text-align: center; padding: 10px 16px; font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.8px; display: flex; align-items: center; justify-content: center; gap: 8px;">
            <i class="fas fa-star" style="color: #fbbf24;"></i> <?= $activePlanType === 'yearly' ? 'Current Active Plan' : 'Recommended Upgrade &bull; ' . $totalYearlyMonths . ' Months Access' ?>
        </div>
        
        <div class="card-body" style="padding: 28px 28px 32px; flex: 1; display: flex; flex-direction: column;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <?php if ($activePlanType === 'yearly'): ?>
                <span class="badge badge-success" style="font-weight: 700; font-size: 12px; padding: 6px 12px; border-radius: 20px;">
                    <i class="fas fa-check-circle"></i> Your Current Plan
                </span>
                <?php elseif ($yearlyBonus > 0): ?>
                <span class="badge" style="background: #fef3c7; color: #b45309; font-weight: 700; font-size: 12px; padding: 6px 12px; border-radius: 20px;">
                    Includes +<?= $yearlyBonus ?> Bonus Months
                </span>
                <?php else: ?>
                <span class="badge" style="background: #e0f2fe; color: #0369a1; font-weight: 700; font-size: 12px; padding: 6px 12px; border-radius: 20px;">
                    Annual Value
                </span>
                <?php endif; ?>
                <i class="fas fa-crown" style="font-size: 24px; color: #d97706;"></i>
            </div>
            
            <h3 style="font-size: 22px; margin: 0 0 8px; color: var(--text);">Yearly Plan</h3>
            <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 24px;">Complete hospital & clinic suite <?= $yearlyBonus > 0 ? "with {$yearlyBonus} months free bonus access." : "for a full year." ?></p>
            
            <div style="margin-bottom: 20px;">
                <span style="font-size: 34px; font-weight: 800; color: #00838f;">₹<?= number_format($yearlyBase, 0) ?></span>
                <span style="font-size: 14px; color: var(--text-muted);">/ year (<?= $totalYearlyMonths ?> Months)</span>
                <div style="font-size: 12px; color: var(--text-muted); margin-top: 4px; line-height: 1.4;">
                    <span>+ 18% GST (₹<?= number_format($yearlyGst, 2) ?>)</span> &bull; 
                    <strong style="color: #00838f;">Total: ₹<?= number_format($yearlyTotal, 2) ?></strong>
                </div>
            </div>

            <ul style="list-style: none; padding: 0; margin: 0 0 32px; flex: 1; font-size: 14px; line-height: 2;">
                <li><i class="fas fa-check" style="color: #00838f; margin-right: 10px;"></i> Up to <strong><?= $yearlyDoctors ?> Doctors</strong></li>
                <li><i class="fas fa-check" style="color: #00838f; margin-right: 10px;"></i> <strong><?= $totalYearlyMonths ?> Full Months Access</strong> <?= $yearlyBonus > 0 ? "(12 + {$yearlyBonus} Bonus)" : '' ?></li>
                <li><i class="fas fa-check" style="color: #00838f; margin-right: 10px;"></i> Multi-Branch Management</li>
                <li><i class="fas fa-check" style="color: #00838f; margin-right: 10px;"></i> Dental Chart & Treatment Plans</li>
                <li><i class="fas fa-check" style="color: #00838f; margin-right: 10px;"></i> Vaccination Schedules</li>
                <li><i class="fas fa-check" style="color: #00838f; margin-right: 10px;"></i> Priority 24/7 Technical Support</li>
            </ul>

            <button type="button" class="btn btn-primary" style="width: 100%; padding: 12px; font-size: 15px; font-weight: 700; background: linear-gradient(135deg, #00838f, #00695c); border: none;" onclick="selectPlan('yearly', <?= $yearlyBase ?>, <?= $yearlyGst ?>, <?= $yearlyTotal ?>, 'Yearly Plan (<?= $totalYearlyMonths ?> Months)')">
                <i class="fas fa-crown"></i> <?= $activePlanType === 'yearly' ? "Renew Yearly ({$totalYearlyMonths} Months)" : "Upgrade to Yearly ({$totalYearlyMonths} Months)" ?>
            </button>
        </div>
    </div>

    <!-- 3. One-Time Lifetime Plan -->
    <div class="card" style="border: 2px solid <?= $isLifetime ? '#0284c7' : '#e5e7eb' ?>; border-radius: 16px; display: flex; flex-direction: column;">
        <div class="card-body" style="padding: 32px 28px; flex: 1; display: flex; flex-direction: column;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <?php if ($isLifetime): ?>
                <span class="badge badge-success" style="font-weight: 700; font-size: 12px; padding: 6px 12px; border-radius: 20px;">
                    <i class="fas fa-check-circle"></i> Permanent Lifetime Active
                </span>
                <?php else: ?>
                <span class="badge" style="background: #e0f2fe; color: #0369a1; font-weight: 700; font-size: 12px; padding: 6px 12px; border-radius: 20px;">
                    Perpetual License
                </span>
                <?php endif; ?>
                <i class="fas fa-infinity" style="font-size: 22px; color: #0284c7;"></i>
            </div>
            
            <h3 style="font-size: 22px; margin: 0 0 8px; color: var(--text);">One-Time Lifetime</h3>
            <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 24px;">Pay once and own forever. Zero renewals. Unlimited forever.</p>
            
            <div style="margin-bottom: 20px;">
                <span style="font-size: 34px; font-weight: 800; color: #0284c7;">₹<?= number_format($lifetimeBase, 0) ?></span>
                <span style="font-size: 14px; color: var(--text-muted);">one-time</span>
                <div style="font-size: 12px; color: var(--text-muted); margin-top: 4px; line-height: 1.4;">
                    <span>+ 18% GST (₹<?= number_format($lifetimeGst, 2) ?>)</span> &bull; 
                    <strong style="color: #0284c7;">Total: ₹<?= number_format($lifetimeTotal, 2) ?></strong>
                </div>
            </div>

            <ul style="list-style: none; padding: 0; margin: 0 0 32px; flex: 1; font-size: 14px; line-height: 2;">
                <li><i class="fas fa-check" style="color: #0284c7; margin-right: 10px;"></i> <strong>Unlimited Doctors</strong></li>
                <li><i class="fas fa-check" style="color: #0284c7; margin-right: 10px;"></i> <strong>Lifetime Perpetual Access</strong></li>
                <li><i class="fas fa-check" style="color: #0284c7; margin-right: 10px;"></i> Unlimited Branches & Locations</li>
                <li><i class="fas fa-check" style="color: #0284c7; margin-right: 10px;"></i> Custom Clinic Branding & Domain</li>
                <li><i class="fas fa-check" style="color: #0284c7; margin-right: 10px;"></i> All Future Feature Updates Included</li>
                <li><i class="fas fa-check" style="color: #0284c7; margin-right: 10px;"></i> Dedicated Account Manager</li>
            </ul>

            <?php if ($isLifetime): ?>
            <button type="button" class="btn btn-outline" style="width: 100%; padding: 12px; font-size: 15px; font-weight: 700; border-color: #0284c7; color: #0284c7;" disabled>
                <i class="fas fa-check-circle"></i> Permanent Lifetime Active
            </button>
            <?php else: ?>
            <button type="button" class="btn btn-outline" style="width: 100%; padding: 12px; font-size: 15px; font-weight: 700; border-color: #0284c7; color: #0284c7;" onclick="selectPlan('one_time', <?= $lifetimeBase ?>, <?= $lifetimeGst ?>, <?= $lifetimeTotal ?>, 'One-Time Lifetime License')">
                <i class="fas fa-infinity"></i> Upgrade to Lifetime Access
            </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- DOCTOR CAPACITY ADD-ON PACK (Option A GST) -->
<!-- ============================================ -->
<div id="doctor-addons" class="card mb-32" style="border-radius: 16px; border: 2px solid #0891b2; background: linear-gradient(135deg, #ffffff 0%, #f0fdfa 100%); box-shadow: 0 10px 25px rgba(8, 145, 178, 0.08); overflow: hidden;">
    <div style="background: linear-gradient(135deg, #0891b2, #0e7490); color: white; padding: 18px 28px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
        <div style="display: flex; align-items: center; gap: 14px;">
            <div style="width: 44px; height: 44px; border-radius: 50%; background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center; font-size: 22px;">
                <i class="fas fa-user-plus"></i>
            </div>
            <div>
                <h3 style="margin: 0 0 2px; font-size: 19px; color: white; font-weight: 800;">Doctor Capacity Add-On Pack</h3>
                <div style="font-size: 13px; opacity: 0.9;">Append extra doctor slots to your existing plan without upgrading whole tiers</div>
            </div>
        </div>
        <div style="display: flex; gap: 10px; align-items: center;">
            <span class="badge" style="background: rgba(255,255,255,0.25); color: white; font-size: 12px; font-weight: 700; padding: 6px 14px; border-radius: 20px;">
                ₹25 / doctor / month &bull; ₹250 / doctor / annum (+18% GST)
            </span>
        </div>
    </div>

    <div class="card-body" style="padding: 28px;">
        <?php if ($isLifetime): ?>
        <div class="alert alert-info" style="margin: 0; background: #e0f2fe; color: #0369a1; border-left: 4px solid #0284c7; padding: 16px; border-radius: 8px;">
            <i class="fas fa-infinity" style="margin-right: 8px; font-size: 18px;"></i>
            Your clinic has a <strong>Permanent Lifetime License</strong> with <strong>Unlimited Doctors</strong> included. No add-on slots needed!
        </div>
        <?php elseif ($isExpired): ?>
        <div class="alert alert-warning" style="margin: 0; background: #fffbeb; color: #92400e; border-left: 4px solid #f59e0b; padding: 16px; border-radius: 8px;">
            <i class="fas fa-exclamation-triangle" style="margin-right: 8px; font-size: 18px;"></i>
            Your clinic subscription has expired. Please renew your Monthly or Yearly plan above before purchasing doctor add-on packs.
        </div>
        <?php else: ?>
        <div class="grid-2 gap-24" style="align-items: center;">
            <div>
                <h4 style="font-size: 16px; margin: 0 0 8px; color: var(--text);"><i class="fas fa-sliders-h" style="color: #0891b2;"></i> Select Extra Doctor Slots to Add:</h4>
                <p style="font-size: 13px; color: var(--text-muted); margin: 0 0 16px; line-height: 1.5;">
                    Current Active Quota: <strong><?= $activeQuota ?> Doctors</strong>. 
                    Add-on slots will co-terminate and append automatically to your current plan ending on 
                    <strong><?= $endsAt ? date('d M Y', $endsAt) : 'N/A' ?></strong> 
                    (<strong><?= $addonPeriodLabel ?></strong>).
                </p>

                <!-- Quantity Selector -->
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
                    <button type="button" class="btn btn-outline" onclick="adjustAddonDoctors(-1)" style="width: 44px; height: 44px; padding: 0; font-size: 20px; font-weight: 700; display: flex; align-items: center; justify-content: center; border-radius: 10px;">-</button>
                    <input type="number" id="addonDoctorCount" value="1" min="1" max="50" style="width: 80px; height: 44px; text-align: center; font-size: 20px; font-weight: 800; border: 2px solid #0891b2; border-radius: 10px; color: #0891b2;" oninput="updateAddonCalc()">
                    <button type="button" class="btn btn-outline" onclick="adjustAddonDoctors(1)" style="width: 44px; height: 44px; padding: 0; font-size: 20px; font-weight: 700; display: flex; align-items: center; justify-content: center; border-radius: 10px;">+</button>
                    <span style="font-size: 14px; font-weight: 600; color: var(--text);">Extra Doctor Slot(s)</span>
                </div>

                <!-- Quick pills -->
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <button type="button" class="btn btn-sm btn-outline" onclick="setAddonDoctors(1)">+1 Doctor</button>
                    <button type="button" class="btn btn-sm btn-outline" onclick="setAddonDoctors(2)">+2 Doctors</button>
                    <button type="button" class="btn btn-sm btn-outline" onclick="setAddonDoctors(3)">+3 Doctors</button>
                    <button type="button" class="btn btn-sm btn-outline" onclick="setAddonDoctors(5)">+5 Doctors</button>
                    <button type="button" class="btn btn-sm btn-outline" onclick="setAddonDoctors(10)">+10 Doctors</button>
                </div>
            </div>

            <!-- Live Price & Checkout Calculation Box -->
            <div style="background: white; border: 2px solid #0891b2; border-radius: 14px; padding: 20px 24px; box-shadow: 0 4px 15px rgba(8, 145, 178, 0.08);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; padding-bottom: 10px; border-bottom: 1px solid #f1f5f9;">
                    <span style="font-size: 13px; font-weight: 700; color: #0891b2; text-transform: uppercase; letter-spacing: 0.5px;">Add-on Price Calculation</span>
                    <span class="badge" style="background: #e0f2fe; color: #0369a1; font-size: 11px; font-weight: 700;">SAC: 998314</span>
                </div>

                <div style="display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 8px; color: var(--text);">
                    <span>Rate Applied (Co-termed):</span>
                    <span style="font-weight: 600;">₹<?= number_format($addonRatePerDoctor, 2) ?> / doc (<?= $addonPeriodLabel ?>)</span>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 8px; color: var(--text);">
                    <span>Base Fee (<span id="calcDocCount">1</span> Doc &times; ₹<?= number_format($addonRatePerDoctor, 2) ?>):</span>
                    <strong id="calcBaseFee">₹<?= number_format($addonRatePerDoctor, 2) ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 12px; color: var(--text-muted);">
                    <span>GST @ 18%:</span>
                    <span id="calcGstFee" style="font-weight: 600;">₹<?= number_format(round($addonRatePerDoctor * 0.18, 2), 2) ?></span>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; padding-top: 10px; border-top: 2px dashed #e2e8f0; margin-bottom: 18px;">
                    <div>
                        <div style="font-size: 11px; text-transform: uppercase; color: var(--text-muted); font-weight: 700;">Total Payable</div>
                        <div style="font-size: 11px; color: #059669; font-weight: 600;">New Limit: <strong id="calcNewQuota"><?= $activeQuota + 1 ?></strong> Doctors</div>
                    </div>
                    <div style="font-size: 26px; font-weight: 800; color: #0891b2;" id="calcTotalFee">
                        ₹<?= number_format(round($addonRatePerDoctor * 1.18, 2), 2) ?>
                    </div>
                </div>

                <button type="button" class="btn btn-primary" style="width: 100%; padding: 12px; font-size: 15px; font-weight: 700; background: linear-gradient(135deg, #0891b2, #0e7490); border: none;" onclick="buyAddonPack()">
                    <i class="fas fa-bolt"></i> Purchase Add-on Slots Now
                </button>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ============================================ -->
<!-- CASH ON HAND & DIRECT BANK TRANSFER BOX -->
<!-- ============================================ -->
<div class="card" style="border-radius: 16px; border: 1px solid #d1d5db; background: linear-gradient(180deg, #ffffff, #f9fafb);">
    <div class="card-body" style="padding: 32px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 16px;">
            <div style="display: flex; align-items: center; gap: 16px;">
                <div style="width: 52px; height: 52px; border-radius: 12px; background: #dcfce7; display: flex; align-items: center; justify-content: center; font-size: 26px; color: #15803d;">
                    <i class="fas fa-hand-holding-usd"></i>
                </div>
                <div>
                    <h3 style="margin: 0 0 4px; font-size: 20px; color: var(--text);">Prefer Paying via COD (Cash on Hand)?</h3>
                    <p style="margin: 0; color: var(--text-muted); font-size: 14px;">
                        Hand over payment directly via COD / Cash to your Feature Gen Care administrator.
                    </p>
                </div>
            </div>
            <div class="d-flex gap-12">
                <a href="tel:<?= preg_replace('/[^0-9\+]/', '', $offlineContact) ?>" class="btn btn-outline" style="background: white;">
                    <i class="fas fa-phone-alt"></i> Call Support
                </a>
                <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $offlineContact) ?>?text=Hello,%20I%20want%20to%20renew%20Feature%20Gen%20Care%20subscription%20for%20clinic%20<?= urlencode($currentTenant['clinic_name']) ?>" target="_blank" class="btn btn-success" style="background: #25d366; border-color: #22c55e; color: white;">
                    <i class="fab fa-whatsapp"></i> Chat on WhatsApp
                </a>
            </div>
        </div>

        <div class="grid-2 gap-20" style="background: white; border: 1px solid #e5e7eb; padding: 20px; border-radius: 10px;">
            <div>
                <h4 style="margin: 0 0 8px; font-size: 14px; text-transform: uppercase; color: var(--primary); font-weight: 700;">
                    <i class="fas fa-id-card"></i> Administrator Contact
                </h4>
                <p style="font-size: 14px; margin: 0; line-height: 1.6;">
                    <?= nl2br(sanitizeOutput($offlineContact)) ?>
                </p>
            </div>
            <div>
                <h4 style="margin: 0 0 8px; font-size: 14px; text-transform: uppercase; color: #059669; font-weight: 700;">
                    <i class="fas fa-university"></i> Bank & UPI Details
                </h4>
                <p style="font-size: 13px; margin: 0; line-height: 1.6; white-space: pre-line;">
                    <?= sanitizeOutput($offlineBank) ?>
                </p>
            </div>
        </div>

        <div style="margin-top: 16px; font-size: 13px; color: var(--text-muted); display: flex; align-items: center; gap: 8px;">
            <i class="fas fa-info-circle" style="color: var(--info);"></i>
            <span>Upon cash handover or bank confirmation, your Super Admin will instantly activate your account and issue an official printable receipt.</span>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- PAYMENT HISTORY & OFFICIAL RECEIPTS -->
<!-- ============================================ -->
<?php
$stmtHist = $master->prepare("
    SELECT * FROM tenant_subscription_payments 
    WHERE tenant_id = ? 
    ORDER BY id DESC
");
$stmtHist->execute([$currentTenant['id']]);
$pastPayments = $stmtHist->fetchAll();
?>
<?php if (!empty($pastPayments)): ?>
<div class="card mt-32" style="border-radius: 16px; border: 1px solid #e5e7eb;">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; padding: 20px 24px; background: #fafafa; border-bottom: 1px solid #f0f0f0;">
        <h3 style="margin: 0; font-size: 18px; display: flex; align-items: center; gap: 8px;">
            <i class="fas fa-receipt" style="color: var(--primary);"></i> Subscription Payment History & Official Receipts
        </h3>
        <span class="badge" style="background: #e0f2fe; color: #0369a1; font-weight: 700;"><?= count($pastPayments) ?> Invoices</span>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>Receipt #</th>
                        <th>Plan</th>
                        <th>Validity Period</th>
                        <th>Doctor Quota</th>
                        <th>Amount Paid</th>
                        <th>Payment Mode</th>
                        <th>Date</th>
                        <th>Official Receipt</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pastPayments as $p): ?>
                    <tr>
                        <td><strong><?= sanitizeOutput($p['payment_reference'] ?: ('REC-' . $p['id'])) ?></strong></td>
                        <td>
                            <span style="text-transform: capitalize; font-weight: 600;"><?= sanitizeOutput($p['plan_type']) ?> Plan</span>
                            <?php if ($p['bonus_months_granted'] > 0): ?>
                                <span class="badge" style="background: #fef3c7; color: #b45309; font-size: 11px;">+<?= $p['bonus_months_granted'] ?>m Bonus</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($p['plan_type'] === 'one_time' || empty($p['period_end'])): ?>
                                <span style="color: #059669; font-weight: 700;"><i class="fas fa-infinity"></i> Lifetime Perpetual</span>
                            <?php else: ?>
                                <?= date('d M Y', strtotime($p['period_start'])) ?> &rarr; <strong><?= date('d M Y', strtotime($p['period_end'])) ?></strong>
                            <?php endif; ?>
                        </td>
                        <td><strong><?= $p['doctor_limit_granted'] > 0 ? $p['doctor_limit_granted'] . ' Doctors' : 'Unlimited' ?></strong></td>
                        <td style="font-weight: 700; color: #059669;">
                            ₹<?= number_format($p['amount'], 2) ?>
                            <?php if (!empty($p['gst_amount']) && $p['gst_amount'] > 0): ?>
                            <div style="font-size: 11px; color: var(--text-muted); font-weight: 500;">
                                Base: ₹<?= number_format($p['base_amount'], 2) ?> + 18% GST: ₹<?= number_format($p['gst_amount'], 2) ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge" style="background: #f3f4f6; text-transform: capitalize;"><?= ($p['payment_mode'] === 'cash_on_hand') ? 'COD (Cash)' : ucfirst(str_replace('_', ' ', $p['payment_mode'])) ?></span></td>
                        <td><?= date('d M Y', strtotime($p['created_at'])) ?></td>
                        <td>
                            <a href="<?= BASE_URL ?>/modules/admin/print_subscription_receipt.php?id=<?= $p['id'] ?>" target="_blank" class="btn btn-sm btn-outline">
                                <i class="fas fa-print"></i> View Receipt
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ============================================ -->
<!-- CHECKOUT / PAYMENT MODAL -->
<!-- ============================================ -->
<div id="checkoutModal" style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(0,0,0,0.6); align-items:center; justify-content:center;">
    <div class="card" style="width: 540px; max-width: 95vw; animation: slideUp 0.2s ease;">
        <div class="card-header">
            <h3><i class="fas fa-shopping-cart" style="color: var(--primary);"></i> Complete Subscription Renewal</h3>
            <button type="button" class="btn btn-sm btn-ghost" onclick="closeCheckoutModal()" style="font-size: 20px;">&times;</button>
        </div>
        <div class="card-body">
            <div style="background: var(--bg-secondary); padding: 18px 20px; border-radius: 12px; margin-bottom: 20px; border: 1px solid var(--border-color);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <span style="font-size: 16px; font-weight: 700;" id="modalPlanName">Yearly Plan</span>
                    <span class="badge" style="background: #e0f2fe; color: #0369a1; font-weight: 700; font-size: 11px;">SAC: 998314</span>
                </div>
                <div style="font-size: 12px; color: var(--text-muted); margin-bottom: 14px; padding-bottom: 12px; border-bottom: 1px dashed var(--border-color);">
                    Tenant Clinic: <strong><?= sanitizeOutput($currentTenant['clinic_name']) ?></strong> (<?= sanitizeOutput($currentTenant['subdomain']) ?>.featuregen.com)
                </div>

                <!-- Tax Breakdown Lines (Option A: Exclusive of GST) -->
                <div style="display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 8px; color: var(--text);">
                    <span>Base Subscription Fee:</span>
                    <span id="modalBasePrice" style="font-weight: 600;">₹0.00</span>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 12px; color: var(--text-muted);">
                    <span>GST @ 18% (SAC 998314 - SaaS Services):</span>
                    <span id="modalGstAmount" style="font-weight: 600;">₹0.00</span>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; padding-top: 10px; border-top: 1px solid var(--border-color);">
                    <span style="font-size: 14px; font-weight: 700; color: var(--text);">Total Payable Amount Due:</span>
                    <span style="font-size: 22px; font-weight: 800; color: #00838f;" id="modalTotalPrice">₹0.00</span>
                </div>
            </div>

            <?php if (!empty($razorpayKey)): ?>
            <div class="mb-20">
                <button type="button" id="razorpayBtn" class="btn btn-primary" style="width: 100%; padding: 14px; font-size: 16px; font-weight: 700; background: #00838f;">
                    <i class="fas fa-bolt"></i> <span id="razorpayBtnText">Pay Online via Razorpay</span>
                </button>
                <div style="text-align: center; margin-top: 8px; font-size: 12px; color: var(--text-muted);">
                    Supports UPI (GPay/PhonePe/Paytm), Credit/Debit Cards, Net Banking & Wallets
                </div>
            </div>
            <?php else: ?>
            <div class="mb-20" style="background: #f8fafc; border: 1px dashed #cbd5e1; padding: 14px; border-radius: 8px; text-align: center;">
                <div style="font-size: 13px; font-weight: 600; color: #475569; margin-bottom: 4px;">
                    <i class="fas fa-bolt" style="color: #0891b2;"></i> Online Gateway Offline
                </div>
                <div style="font-size: 12px; color: #64748b;">
                    Razorpay Key has not been entered yet. Contact administrator to configure your Razorpay Key ID or use direct transfer below.
                </div>
            </div>
            <?php endif; ?>

            <div style="border-top: 1px solid var(--border-color); padding-top: 16px;">
                <h4 style="font-size: 14px; margin: 0 0 8px;"><i class="fas fa-hand-holding-usd" style="color: #059669;"></i> Paying via COD (Cash on Hand)?</h4>
                <p style="font-size: 13px; color: var(--text-muted); margin: 0 0 12px; line-height: 1.5;">
                    Total Payable: <strong style="color: #059669;" id="modalCashTotal">₹0.00</strong> (Includes 18% GST). Contact your Feature Gen Care administrator for direct COD collection and official tax receipt.
                </p>
                <div style="display: flex; gap: 8px;">
                    <a href="tel:<?= preg_replace('/[^0-9\+]/', '', $offlineContact) ?>" class="btn btn-sm btn-outline" style="flex: 1; justify-content: center;">
                        <i class="fas fa-phone"></i> Call Admin
                    </a>
                    <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $offlineContact) ?>" target="_blank" class="btn btn-sm btn-success" style="flex: 1; justify-content: center; background: #25d366; color: white; border: none;">
                        <i class="fab fa-whatsapp"></i> WhatsApp
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
let selectedPlan = {
    type: 'yearly',
    addon_doctors: 0,
    basePrice: <?= $yearlyBase ?>,
    gstAmount: <?= $yearlyGst ?>,
    totalPrice: <?= $yearlyTotal ?>,
    name: 'Yearly Plan (<?= $totalYearlyMonths ?> Months)'
};

function selectPlan(type, basePrice, gstAmount, totalPrice, name) {
    selectedPlan = { type, addon_doctors: 0, basePrice, gstAmount, totalPrice, name };
    document.getElementById('modalPlanName').textContent = name;
    document.getElementById('modalBasePrice').textContent = '₹' + basePrice.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    document.getElementById('modalGstAmount').textContent = '₹' + gstAmount.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    document.getElementById('modalTotalPrice').textContent = '₹' + totalPrice.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    
    const cashTotal = document.getElementById('modalCashTotal');
    if (cashTotal) cashTotal.textContent = '₹' + totalPrice.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    
    const rzpText = document.getElementById('razorpayBtnText');
    if (rzpText) rzpText.textContent = 'Pay ₹' + totalPrice.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' Online via Razorpay';
    
    document.getElementById('checkoutModal').style.display = 'flex';
}

function selectAddon(count, basePrice, gstAmount, totalPrice, name) {
    selectedPlan = {
        type: 'doctor_addon',
        addon_doctors: count,
        basePrice: basePrice,
        gstAmount: gstAmount,
        totalPrice: totalPrice,
        name: name
    };
    document.getElementById('modalPlanName').textContent = name;
    document.getElementById('modalBasePrice').textContent = '₹' + basePrice.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    document.getElementById('modalGstAmount').textContent = '₹' + gstAmount.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    document.getElementById('modalTotalPrice').textContent = '₹' + totalPrice.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    
    const cashTotal = document.getElementById('modalCashTotal');
    if (cashTotal) cashTotal.textContent = '₹' + totalPrice.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    
    const rzpText = document.getElementById('razorpayBtnText');
    if (rzpText) rzpText.textContent = 'Pay ₹' + totalPrice.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' Online via Razorpay';
    
    document.getElementById('checkoutModal').style.display = 'flex';
}

function closeCheckoutModal() {
    document.getElementById('checkoutModal').style.display = 'none';
}

// Addon Calculator Functions
const addonRatePerDoctor = <?= json_encode($addonRatePerDoctor) ?>;
const addonPeriodLabel = <?= json_encode($addonPeriodLabel) ?>;
const activeQuota = <?= json_encode($activeQuota) ?>;

function adjustAddonDoctors(delta) {
    const input = document.getElementById('addonDoctorCount');
    if (!input) return;
    let val = (parseInt(input.value) || 1) + delta;
    if (val < 1) val = 1;
    if (val > 100) val = 100;
    input.value = val;
    updateAddonCalc();
}

function setAddonDoctors(val) {
    const input = document.getElementById('addonDoctorCount');
    if (!input) return;
    input.value = val;
    updateAddonCalc();
}

function updateAddonCalc() {
    const input = document.getElementById('addonDoctorCount');
    if (!input) return;
    const count = parseInt(input.value) || 1;
    const baseFee = Math.round(count * addonRatePerDoctor * 100) / 100;
    const gstFee = Math.round(baseFee * 18) / 100;
    const totalFee = Math.round((baseFee + gstFee) * 100) / 100;
    
    const docCountEl = document.getElementById('calcDocCount');
    const baseFeeEl = document.getElementById('calcBaseFee');
    const gstFeeEl = document.getElementById('calcGstFee');
    const totalFeeEl = document.getElementById('calcTotalFee');
    const newQuotaEl = document.getElementById('calcNewQuota');
    
    if (docCountEl) docCountEl.textContent = count;
    if (baseFeeEl) baseFeeEl.textContent = '₹' + baseFee.toLocaleString('en-IN', { minimumFractionDigits: 2 });
    if (gstFeeEl) gstFeeEl.textContent = '₹' + gstFee.toLocaleString('en-IN', { minimumFractionDigits: 2 });
    if (totalFeeEl) totalFeeEl.textContent = '₹' + totalFee.toLocaleString('en-IN', { minimumFractionDigits: 2 });
    if (newQuotaEl) newQuotaEl.textContent = (activeQuota + count);
}

function buyAddonPack() {
    const input = document.getElementById('addonDoctorCount');
    const count = input ? (parseInt(input.value) || 1) : 1;
    const baseFee = Math.round(count * addonRatePerDoctor * 100) / 100;
    const gstFee = Math.round(baseFee * 18) / 100;
    const totalFee = Math.round((baseFee + gstFee) * 100) / 100;
    const title = '+' + count + ' Doctor Slots Add-On Pack (' + addonPeriodLabel + ')';
    selectAddon(count, baseFee, gstFee, totalFee, title);
}

const rzpKey = <?= json_encode($razorpayKey) ?>;
const razorpayBtn = document.getElementById('razorpayBtn');

if (razorpayBtn && rzpKey) {
    razorpayBtn.addEventListener('click', function() {
        const isAddon = (selectedPlan.type === 'doctor_addon');
        const descSuffix = isAddon ? ' - Doctor Add-on Pack (Incl. 18% GST)' : ' - Subscription Renewal (Incl. 18% GST)';
        const options = {
            "key": rzpKey,
            "amount": Math.round(selectedPlan.totalPrice * 100),
            "currency": "INR",
            "name": "Feature Gen Care",
            "description": selectedPlan.name + descSuffix,
            "image": "<?= ASSETS_URL ?>/images/favicon.svg",
            "handler": function (response) {
                // Post to verification script
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '<?= BASE_URL ?>/modules/subscription/verify_payment.php';
                
                const fields = {
                    razorpay_payment_id: response.razorpay_payment_id,
                    plan_type: selectedPlan.type,
                    addon_doctors: selectedPlan.addon_doctors || 0,
                    amount: selectedPlan.totalPrice,
                    base_amount: selectedPlan.basePrice,
                    gst_rate: 18.00,
                    gst_amount: selectedPlan.gstAmount
                };
                
                for (const key in fields) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = key;
                    input.value = fields[key];
                    form.appendChild(input);
                }
                document.body.appendChild(form);
                form.submit();
            },
            "prefill": {
                "name": <?= json_encode(getSession('full_name', 'Admin')) ?>,
                "email": <?= json_encode(getSession('email', '')) ?>
            },
            "theme": {
                "color": "#0891b2"
            }
        };
        const rzp = new Razorpay(options);
        rzp.open();
    });
}
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
