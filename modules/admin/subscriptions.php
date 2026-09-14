<?php
/**
 * Subscription & Billing Control Plane - Feature Gen Care
 * Super Admin Only - Scoped to Current Clinic Tenant
 */
$pageTitle = 'Subscriptions & Billing';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';

$role = getCurrentUserRole();
if ($role !== ROLE_SUPER_ADMIN) {
    header('Location: ' . BASE_URL . '/modules/auth/403.php');
    exit;
}

$master = master_db();
$tenantInfo = db()->tenantInfo;
$currentTenantId = intval($tenantInfo['id'] ?? 0);
$currentTab = sanitize($_GET['tab'] ?? 'tenants');
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
$tenants = $thisTenant ? [$thisTenant] : [];

// ============================================
// POST ACTION HANDLERS
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = sanitize($_POST['action'] ?? '');
    
    // 1. UPDATE TENANT PLAN & DOCTOR LIMITS
    if ($action === 'update_plan') {
        $tenantId = intval($_POST['tenant_id'] ?? $t['id']);
        $planType = sanitize($_POST['plan_type'] ?? 'trial');
        $billingCycle = sanitize($_POST['billing_cycle'] ?? 'trial');
        $maxDoctors = intval($_POST['max_doctors'] ?? 2);
        $bonusMonths = intval($_POST['bonus_months'] ?? 0);
        $planAmount = floatval($_POST['plan_amount'] ?? 0);
        $isLifetime = isset($_POST['is_lifetime']) ? 1 : 0;
        $subStatus = sanitize($_POST['subscription_status'] ?? 'active');
        $expiryDate = !empty($_POST['subscription_ends_at']) ? $_POST['subscription_ends_at'] . ' 23:59:59' : null;
        
        if ($isLifetime || $planType === 'one_time') {
            $isLifetime = 1;
            $expiryDate = null;
            $subStatus = 'active';
        }
        
        try {
            $stmt = $master->prepare("
                UPDATE tenants 
                SET plan_type = ?,
                    billing_cycle = ?,
                    max_doctors = ?,
                    bonus_months = ?,
                    plan_amount = ?,
                    is_lifetime = ?,
                    subscription_ends_at = ?,
                    subscription_status = ?,
                    status = 'active',
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $planType, $billingCycle, $maxDoctors, $bonusMonths, $planAmount,
                $isLifetime, $expiryDate, $subStatus, $tenantId
            ]);
            
            setFlashMessage('success', 'Clinic subscription plan updated successfully.');
            header("Location: " . BASE_URL . "/modules/admin/subscriptions.php?tab=tenants");
            exit;
        } catch (Exception $e) {
            $errorMsg = 'Error updating plan: ' . $e->getMessage();
        }
    }
    
    // 2. RECORD PAYMENT (Cash on Hand, Bank Transfer, Cheque, UPI, Razorpay)
    elseif ($action === 'record_payment') {
        $tenantId = intval($_POST['tenant_id'] ?? $t['id']);
        $amount = floatval($_POST['amount'] ?? 0);
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
            
            $stmtT = $master->prepare("SELECT * FROM tenants WHERE id = ?");
            $stmtT->execute([$tenantId]);
            $tenantRow = $stmtT->fetch();
            
            if (!$tenantRow) {
                throw new Exception("Clinic tenant not found.");
            }
            
            $now = time();
            $currentEnd = !empty($tenantRow['subscription_ends_at']) ? strtotime($tenantRow['subscription_ends_at']) : 0;
            $periodStart = ($currentEnd > $now) ? date('Y-m-d H:i:s', $currentEnd) : date('Y-m-d H:i:s');
            $startTs = strtotime($periodStart);
            $newEnd = null;
            $isLifetime = 0;
            $planType = $tenantRow['plan_type'];
            
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
            } elseif ($periodType === 'custom') {
                $customDays = intval($_POST['custom_days'] ?? 30);
                $newEnd = date('Y-m-d 23:59:59', strtotime("+{$customDays} days", $startTs));
            }
            
            // Insert Payment record
            $stmtP = $master->prepare("
                INSERT INTO tenant_subscription_payments (
                    tenant_id, plan_type, amount, payment_mode, payment_reference,
                    collected_by, period_start, period_end, bonus_months_granted,
                    doctor_limit_granted, status, notes, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', ?, ?)
            ");
            $stmtP->execute([
                $tenantId, $planType, $amount, $paymentMode, $reference,
                $collectedBy, $periodStart, $newEnd, $bonusMonths,
                $doctorLimit, $notes, $paymentDate . ' ' . date('H:i:s')
            ]);
            $newPaymentId = $master->lastInsertId();
            
            // Update Tenant validity
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
                $isLifetime, $newEnd, $tenantId
            ]);
            
            $master->commit();
            setFlashMessage('success', "Payment of ₹" . number_format($amount, 2) . " recorded successfully! Receipt #$reference issued.");
            header("Location: " . BASE_URL . "/modules/admin/print_subscription_receipt.php?id=" . $newPaymentId);
            exit;
        } catch (Exception $e) {
            $master->rollBack();
            $errorMsg = 'Error recording payment: ' . $e->getMessage();
        }
    }
    
    // 3. UPDATE SAAS GLOBAL SETTINGS
    elseif ($action === 'update_settings') {
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
            setFlashMessage('success', 'SaaS Global Settings updated successfully.');
            header("Location: " . BASE_URL . "/modules/admin/subscriptions.php?tab=settings");
            exit;
        } catch (Exception $e) {
            $errorMsg = 'Error saving settings: ' . $e->getMessage();
        }
    }
}

// ============================================
// FETCH DATA FOR DISPLAY (CURRENT CLINIC ONLY)
// ============================================
// 1. SaaS Settings
$settingsRows = $master->query("SELECT setting_key, setting_value FROM saas_global_settings")->fetchAll();
$settings = [];
foreach ($settingsRows as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// 2. Payment History Ledger for THIS clinic only
$stmtPay = $master->prepare("
    SELECT p.*, t.clinic_name, t.subdomain 
    FROM tenant_subscription_payments p 
    JOIN tenants t ON p.tenant_id = t.id 
    WHERE p.tenant_id = ?
    ORDER BY p.id DESC 
    LIMIT 100
");
$stmtPay->execute([$t['id'] ?? $currentTenantId]);
$payments = $stmtPay->fetchAll();

// 3. Stats for THIS clinic
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
$stmtRev->execute([$t['id'] ?? $currentTenantId]);
$thisClinicRevenue = $stmtRev->fetch()['tot'] ?? 0;
?>

<div class="content-header">
    <div>
        <ul class="breadcrumb">
            <li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li>
            <li><a href="<?= BASE_URL ?>/modules/admin/index.php">System Admin</a></li>
            <li>Subscriptions & Billing</li>
        </ul>
        <h1><i class="fas fa-credit-card" style="color: var(--primary);"></i> <?= sanitizeOutput($t['clinic_name']) ?> &bull; Subscription & Billing</h1>
    </div>
    <div class="d-flex gap-8">
        <button class="btn btn-success" onclick="openPaymentModal()"><i class="fas fa-hand-holding-usd"></i> Record Payment (Cash on Hand)</button>
        <button class="btn btn-primary" onclick='openPlanModal(<?= json_encode($t) ?>)'><i class="fas fa-sliders-h"></i> Change Plan & Quota</button>
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
            <div class="stat-label">Active Plan Tier</div>
            <div class="stat-value" style="font-size: 20px; text-transform: capitalize;">
                <?= sanitizeOutput($t['plan_type'] ?? 'trial') ?>
                <?= ($t['bonus_months'] ?? 0) > 0 ? '(+' . $t['bonus_months'] . 'm)' : '' ?>
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
            <div class="stat-label">Doctor Slots Quota</div>
            <div class="stat-value"><?= $activeDocCount ?> / <?= ($t['max_doctors'] > 0 ? $t['max_doctors'] : '∞') ?></div>
            <div class="stat-change">
                <?= $t['max_doctors'] > 0 ? max(0, $t['max_doctors'] - $activeDocCount) . ' slots remaining' : 'Unlimited slots' ?>
            </div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon <?= $isExp ? 'danger' : 'warning' ?>"><i class="fas fa-clock"></i></div>
        <div class="stat-details">
            <div class="stat-label">Plan Validity / Expiry</div>
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
            <div class="stat-change"><?= count($payments) ?> receipts logged</div>
        </div>
    </div>
</div>

<!-- Tabs Navigation -->
<div class="card mb-24">
    <div style="display: flex; border-bottom: 1px solid var(--border-color); background: var(--bg-secondary); border-radius: 12px 12px 0 0;">
        <a href="?tab=tenants" class="tab-link <?= $currentTab === 'tenants' ? 'active' : '' ?>">
            <i class="fas fa-building"></i> Clinic Subscription
        </a>
        <a href="?tab=payments" class="tab-link <?= $currentTab === 'payments' ? 'active' : '' ?>">
            <i class="fas fa-receipt"></i> Payment Receipts (<?= count($payments) ?>)
        </a>
        <a href="?tab=settings" class="tab-link <?= $currentTab === 'settings' ? 'active' : '' ?>">
            <i class="fas fa-sliders-h"></i> SaaS Global Settings & Plans
        </a>
    </div>

    <!-- TAB 1: CLINIC SUBSCRIPTION (THIS CLINIC ONLY) -->
    <?php if ($currentTab === 'tenants'): ?>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table" style="margin: 0;">
                <thead>
                    <tr>
                        <th>Clinic & Subdomain</th>
                        <th>Current Plan</th>
                        <th>Doctor Quota</th>
                        <th>Validity / Expiration</th>
                        <th>Status</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>
                            <strong style="font-size: 15px; color: var(--primary);"><?= sanitizeOutput($t['clinic_name']) ?></strong>
                            <div style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">
                                <i class="fas fa-globe"></i> <strong><?= sanitizeOutput($t['subdomain']) ?></strong>.featuregen.com
                            </div>
                        </td>
                        <td>
                            <?php if ($isLife || ($t['plan_type'] ?? '') === 'one_time'): ?>
                                <span class="badge" style="background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; font-weight: 700;">
                                    <i class="fas fa-infinity"></i> Lifetime
                                </span>
                            <?php elseif (($t['plan_type'] ?? '') === 'yearly'): ?>
                                <span class="badge" style="background: #fef3c7; color: #b45309; border: 1px solid #fde68a; font-weight: 700;">
                                    <i class="fas fa-crown"></i> Yearly <?= ($t['bonus_months'] ?? 0) > 0 ? '(+' . $t['bonus_months'] . 'm Bonus)' : '' ?>
                                </span>
                            <?php elseif (($t['plan_type'] ?? '') === 'monthly'): ?>
                                <span class="badge" style="background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; font-weight: 700;">
                                    <i class="fas fa-calendar-alt"></i> Monthly
                                </span>
                            <?php else: ?>
                                <span class="badge" style="background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; font-weight: 700;">
                                    <i class="fas fa-vial"></i> Free Trial
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-info" style="font-size: 12px; padding: 5px 12px;">
                                <i class="fas fa-user-md"></i> <?= $t['max_doctors'] > 0 ? $t['max_doctors'] . ' Doctors Max' : '∞ Unlimited' ?>
                            </span>
                            <div style="font-size: 11px; color: var(--text-muted); margin-top: 4px;">
                                Currently using: <strong><?= $activeDocCount ?></strong>
                            </div>
                        </td>
                        <td>
                            <?php if ($isLife): ?>
                                <span style="color: #0369a1; font-weight: 700;"><i class="fas fa-check-circle"></i> Never Expires</span>
                            <?php elseif ($endsAt): ?>
                                <div><strong><?= date('d M Y', $endsAt) ?></strong></div>
                                <?php if ($isExp): ?>
                                    <span class="badge badge-danger" style="font-size: 11px;">Expired <?= abs($daysLeft) ?>d ago</span>
                                <?php elseif ($daysLeft <= 7): ?>
                                    <span class="badge badge-warning" style="font-size: 11px;"><?= $daysLeft ?> days left</span>
                                <?php else: ?>
                                    <span class="badge badge-success" style="font-size: 11px;"><?= $daysLeft ?> days remaining</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">Not Set</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isExp): ?>
                                <span class="badge badge-danger"><i class="fas fa-ban"></i> Expired</span>
                            <?php elseif (($t['status'] ?? '') === 'suspended'): ?>
                                <span class="badge badge-danger"><i class="fas fa-lock"></i> Suspended</span>
                            <?php else: ?>
                                <span class="badge badge-success"><i class="fas fa-check-circle"></i> Active</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: right; white-space: nowrap;">
                            <button class="btn btn-sm btn-outline" onclick='openPlanModal(<?= json_encode($t) ?>)'>
                                <i class="fas fa-sliders-h"></i> Plan & Quota
                            </button>
                            <button class="btn btn-sm btn-success" onclick="openPaymentModal()" style="background: #059669; color: white;">
                                <i class="fas fa-money-bill-wave"></i> Record Cash
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
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
                            'cash_on_hand' => '<span class="badge" style="background:#dcfce7; color:#15803d; border:1px solid #bbf7d0; font-weight:700;"><i class="fas fa-hand-holding-usd"></i> Cash on Hand</span>',
                            'bank_transfer' => '<span class="badge" style="background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; font-weight:700;"><i class="fas fa-university"></i> Bank Transfer</span>',
                            'upi' => '<span class="badge" style="background:#f3e8ff; color:#7e22ce; border:1px solid #e9d5ff; font-weight:700;"><i class="fas fa-mobile-alt"></i> UPI Transfer</span>',
                            'cheque' => '<span class="badge" style="background:#fef3c7; color:#b45309; border:1px solid #fde68a; font-weight:700;"><i class="fas fa-money-check-alt"></i> Cheque/DD</span>',
                            'razorpay' => '<span class="badge" style="background:#cffafe; color:#0e7490; border:1px solid #a5f3fc; font-weight:700;"><i class="fas fa-bolt"></i> Razorpay</span>'
                        ];
                    ?>
                    <tr>
                        <td>
                            <strong><?= sanitizeOutput($p['payment_reference'] ?: ('REC-' . $p['id'])) ?></strong>
                        </td>
                        <td>
                            <span style="font-size: 15px; font-weight: 800; color: #059669;">₹<?= number_format($p['amount'], 2) ?></span>
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
                            <a href="<?= BASE_URL ?>/modules/admin/print_subscription_receipt.php?id=<?= $p['id'] ?>" target="_blank" class="btn btn-sm btn-outline">
                                <i class="fas fa-print"></i> Receipt
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- TAB 3: SAAS GLOBAL SETTINGS -->
    <?php if ($currentTab === 'settings'): ?>
    <div class="card-body">
        <form method="POST" action="">
            <input type="hidden" name="action" value="update_settings">
            
            <h4 class="mb-16" style="color: var(--primary);"><i class="fas fa-tags"></i> Default SaaS Plan Pricing & Doctor Quotas</h4>
            <div class="grid-2 gap-24 mb-24">
                <!-- Free Trial -->
                <div class="card" style="background: var(--bg-secondary); border: 1px solid var(--border-color);">
                    <div class="card-body">
                        <h4 style="margin: 0 0 12px; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-vial" style="color: #64748b;"></i> 1. Free Trial Tier
                        </h4>
                        <div class="grid-2 gap-12">
                            <div class="form-group">
                                <label class="form-label">Trial Duration (Months)</label>
                                <input type="number" name="trial_duration_months" class="form-control" value="<?= sanitizeOutput($settings['trial_duration_months'] ?? '1') ?>" min="1" max="12" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Max Doctors Quota</label>
                                <input type="number" name="trial_max_doctors" class="form-control" value="<?= sanitizeOutput($settings['trial_max_doctors'] ?? '2') ?>" min="1" required>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Monthly Plan -->
                <div class="card" style="background: var(--bg-secondary); border: 1px solid var(--border-color);">
                    <div class="card-body">
                        <h4 style="margin: 0 0 12px; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-calendar-alt" style="color: #059669;"></i> 2. Monthly Plan Tier
                        </h4>
                        <div class="grid-2 gap-12">
                            <div class="form-group">
                                <label class="form-label">Monthly Price (₹)</label>
                                <input type="number" name="monthly_price" class="form-control" value="<?= sanitizeOutput($settings['monthly_price'] ?? '1499') ?>" step="0.01" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Max Doctors Quota</label>
                                <input type="number" name="monthly_max_doctors" class="form-control" value="<?= sanitizeOutput($settings['monthly_max_doctors'] ?? '3') ?>" min="1" required>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Yearly Plan -->
                <div class="card" style="background: var(--bg-secondary); border: 1px solid var(--border-color);">
                    <div class="card-body">
                        <h4 style="margin: 0 0 12px; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-crown" style="color: #d97706;"></i> 3. Yearly Plan Tier (+Bonus Months)
                        </h4>
                        <div class="grid-3 gap-12">
                            <div class="form-group">
                                <label class="form-label">Yearly Price (₹)</label>
                                <input type="number" name="yearly_price" class="form-control" value="<?= sanitizeOutput($settings['yearly_price'] ?? '14999') ?>" step="0.01" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Bonus Months</label>
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
                            <i class="fas fa-infinity" style="color: #0284c7;"></i> 4. One-Time Lifetime License Tier
                        </h4>
                        <div class="grid-2 gap-12">
                            <div class="form-group">
                                <label class="form-label">One-Time Cost (₹)</label>
                                <input type="number" name="one_time_price" class="form-control" value="<?= sanitizeOutput($settings['one_time_price'] ?? '49999') ?>" step="0.01" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Max Doctors (0 = Unlimited)</label>
                                <input type="number" name="one_time_max_doctors" class="form-control" value="<?= sanitizeOutput($settings['one_time_max_doctors'] ?? '0') ?>" min="0" required>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Razorpay Gateway Integration -->
            <h4 class="mb-16" style="color: var(--primary);"><i class="fas fa-bolt"></i> Razorpay Payment Gateway Settings</h4>
            <div class="grid-2 gap-24 mb-24">
                <div class="form-group">
                    <label class="form-label">Razorpay Key ID</label>
                    <input type="text" name="razorpay_key_id" class="form-control" value="<?= sanitizeOutput($settings['razorpay_key_id'] ?? '') ?>" placeholder="rzp_live_xxxxxxxx">
                </div>
                <div class="form-group">
                    <label class="form-label">Razorpay Key Secret</label>
                    <input type="password" name="razorpay_key_secret" class="form-control" value="<?= sanitizeOutput($settings['razorpay_key_secret'] ?? '') ?>" placeholder="Secret Key">
                </div>
            </div>

            <!-- Offline Payment Instructions -->
            <h4 class="mb-16" style="color: var(--primary);"><i class="fas fa-university"></i> Cash on Hand & Bank Transfer Info (Shown on Paywall)</h4>
            <div class="grid-2 gap-24 mb-24">
                <div class="form-group">
                    <label class="form-label">Contact Details (Phone / WhatsApp)</label>
                    <input type="text" name="offline_payment_contact" class="form-control" value="<?= sanitizeOutput($settings['offline_payment_contact'] ?? '') ?>" placeholder="Phone: +91 98765 43210 | WhatsApp: +91 98765 43210">
                </div>
                <div class="form-group">
                    <label class="form-label">Bank Details & UPI ID</label>
                    <textarea name="offline_bank_details" class="form-control" rows="3" placeholder="Account Name, Number, IFSC, UPI ID"><?= sanitizeOutput($settings['offline_bank_details'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="d-flex justify-end">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save SaaS Settings
                </button>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>

<!-- ============================================ -->
<!-- MODAL 1: PLAN & DOCTOR LIMIT ADJUSTER -->
<!-- Fully Scrollable & Always Centered/Closeable -->
<!-- ============================================ -->
<div id="planModal" class="custom-modal-backdrop" style="display:none;">
    <div class="custom-modal-dialog" style="width: 580px;">
        <div class="card modal-card">
            <div class="card-header modal-header-bar">
                <h3><i class="fas fa-sliders-h" style="color: var(--primary);"></i> Plan & Doctor Quota Settings</h3>
                <button type="button" class="modal-close-btn" onclick="closePlanModal()" aria-label="Close">&times;</button>
            </div>
            <form method="POST" action="" class="modal-form-wrap">
                <input type="hidden" name="action" value="update_plan">
                <input type="hidden" name="tenant_id" id="planTenantId" value="<?= $t['id'] ?>">
                
                <div class="card-body modal-scroll-body">
                    <div style="background: var(--bg-secondary); padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; border: 1px solid var(--border-color);">
                        <div style="font-size: 11px; text-transform: uppercase; color: var(--text-muted); font-weight: 700;">Target Clinic</div>
                        <div id="planClinicName" style="font-size: 16px; font-weight: 700; color: var(--primary);"><?= sanitizeOutput($t['clinic_name']) ?></div>
                        <div id="planSubdomain" style="font-size: 12px; color: var(--text-muted);"><?= sanitizeOutput($t['subdomain']) ?>.featuregen.com</div>
                    </div>

                    <div class="grid-2 gap-16 mb-16">
                        <div class="form-group">
                            <label class="form-label">Plan Tier <span class="required">*</span></label>
                            <select name="plan_type" id="planTypeSelect" class="form-control" onchange="handlePlanTypeChange()">
                                <option value="trial">Free Trial</option>
                                <option value="monthly">Monthly Plan</option>
                                <option value="yearly">Yearly Plan</option>
                                <option value="one_time">One-Time Lifetime Cost</option>
                                <option value="custom">Custom Plan</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Doctor Quota (`max_doctors`) <span class="required">*</span></label>
                            <input type="number" name="max_doctors" id="planMaxDoctors" class="form-control" min="0" required>
                            <small class="text-muted">Enter 0 for unlimited doctors</small>
                        </div>
                    </div>

                    <div class="grid-2 gap-16 mb-16">
                        <div class="form-group" id="bonusMonthsGroup">
                            <label class="form-label">Negotiated Bonus Months</label>
                            <input type="number" name="bonus_months" id="planBonusMonths" class="form-control" min="0" value="0">
                            <small class="text-muted">e.g. +2 bonus months on yearly</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Agreed Price (₹)</label>
                            <input type="number" name="plan_amount" id="planAmount" class="form-control" step="0.01" value="0.00">
                        </div>
                    </div>

                    <div class="grid-2 gap-16 mb-16" id="expiryGroup">
                        <div class="form-group">
                            <label class="form-label">Expiry Date</label>
                            <input type="date" name="subscription_ends_at" id="planExpiryDate" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Subscription Status</label>
                            <select name="subscription_status" id="planSubStatus" class="form-control">
                                <option value="active">Active</option>
                                <option value="trial">Trial</option>
                                <option value="expired">Expired</option>
                                <option value="suspended">Suspended</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group mb-0">
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; background: var(--bg-secondary); padding: 12px; border-radius: 8px;">
                            <input type="checkbox" name="is_lifetime" id="planIsLifetime" value="1" onchange="handleLifetimeToggle()">
                            <span style="font-weight: 600; color: var(--text);">Grant Lifetime Perpetual License (Never Expires)</span>
                        </label>
                    </div>
                </div>
                
                <div class="card-footer modal-footer-bar">
                    <button type="button" class="btn btn-outline" onclick="closePlanModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Plan Settings</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODAL 2: RECORD PAYMENT (CASH ON HAND ETC) -->
<!-- Fully Scrollable & Always Centered/Closeable -->
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

                    <div class="grid-2 gap-16 mb-16">
                        <div class="form-group">
                            <label class="form-label">Payment Mode <span class="required">*</span></label>
                            <select name="payment_mode" class="form-control" required>
                                <option value="cash_on_hand" selected>💵 Cash on Hand (Direct Handover)</option>
                                <option value="bank_transfer">🏦 Bank Transfer / NEFT / IMPS</option>
                                <option value="upi">📱 UPI (GPay / PhonePe / Paytm)</option>
                                <option value="cheque">📝 Cheque / Demand Draft</option>
                                <option value="razorpay">⚡ Razorpay Online</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Amount Collected (₹) <span class="required">*</span></label>
                            <input type="number" name="amount" id="payAmount" class="form-control" step="0.01" value="<?= sanitizeOutput($settings['yearly_price'] ?? '14999') ?>" placeholder="e.g. 15000" required>
                        </div>
                    </div>

                    <div class="grid-2 gap-16 mb-16">
                        <div class="form-group">
                            <label class="form-label">Receipt / Reference #</label>
                            <input type="text" name="payment_reference" id="payReference" class="form-control" value="REC-<?= date('Y') ?>-<?= rand(1000, 9999) ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Collected By <span class="required">*</span></label>
                            <input type="text" name="collected_by" class="form-control" value="<?= sanitizeOutput(getSession('full_name', 'Super Admin')) ?>" required>
                        </div>
                    </div>

                    <div class="grid-2 gap-16 mb-16">
                        <div class="form-group">
                            <label class="form-label">Validity Period to Grant <span class="required">*</span></label>
                            <select name="period_type" id="payPeriodType" class="form-control" onchange="handlePaymentPeriodChange()">
                                <option value="12_months" selected>Yearly Extension (12 Months + Bonus)</option>
                                <option value="1_month">Monthly Extension (1 Month)</option>
                                <option value="lifetime">One-Time Lifetime License</option>
                                <option value="custom">Custom Days</option>
                            </select>
                        </div>
                        <div class="form-group" id="payBonusGroup">
                            <label class="form-label">Bonus Months Granted</label>
                            <input type="number" name="bonus_months_granted" id="payBonusMonths" class="form-control" value="<?= sanitizeOutput($settings['yearly_default_bonus_months'] ?? '2') ?>" min="0">
                        </div>
                    </div>

                    <div class="grid-2 gap-16 mb-16">
                        <div class="form-group">
                            <label class="form-label">Doctor Limit Granted <span class="required">*</span></label>
                            <input type="number" name="doctor_limit_granted" id="payDoctorLimit" class="form-control" value="<?= sanitizeOutput($settings['yearly_max_doctors'] ?? ($t['max_doctors'] ?? '10')) ?>" min="1" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Payment Date <span class="required">*</span></label>
                            <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>

                    <div class="form-group mb-0">
                        <label class="form-label">Payment & Handover Notes</label>
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

/* ============================================ */
/* PERFECT SCROLLABLE & RESPONSIVE MODAL SYSTEM */
/* ============================================ */
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
// ============================================
// MODAL CONTROLS & EVENT LISTENERS
// ============================================

function openPlanModal(tenantData) {
    const tenant = tenantData || <?= json_encode($t) ?>;
    document.getElementById('planTenantId').value = tenant.id;
    document.getElementById('planClinicName').textContent = tenant.clinic_name;
    document.getElementById('planSubdomain').textContent = tenant.subdomain + '.featuregen.com';
    document.getElementById('planTypeSelect').value = tenant.plan_type || 'trial';
    document.getElementById('planMaxDoctors').value = tenant.max_doctors !== undefined ? tenant.max_doctors : 2;
    document.getElementById('planBonusMonths').value = tenant.bonus_months || 0;
    document.getElementById('planAmount').value = tenant.plan_amount || '0.00';
    document.getElementById('planSubStatus').value = tenant.subscription_status || 'active';
    
    if (tenant.subscription_ends_at) {
        document.getElementById('planExpiryDate').value = tenant.subscription_ends_at.split(' ')[0];
    } else {
        document.getElementById('planExpiryDate').value = '';
    }
    
    document.getElementById('planIsLifetime').checked = (tenant.is_lifetime == 1 || tenant.plan_type === 'one_time');
    handleLifetimeToggle();
    handlePlanTypeChange();
    
    document.getElementById('planModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closePlanModal() {
    document.getElementById('planModal').style.display = 'none';
    document.body.style.overflow = '';
}

function openPaymentModal() {
    document.getElementById('paymentModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closePaymentModal() {
    document.getElementById('paymentModal').style.display = 'none';
    document.body.style.overflow = '';
}

// Click backdrop outside modal card to close
document.getElementById('planModal').addEventListener('click', function(e) {
    if (e.target === this || e.target.classList.contains('custom-modal-dialog')) {
        closePlanModal();
    }
});

document.getElementById('paymentModal').addEventListener('click', function(e) {
    if (e.target === this || e.target.classList.contains('custom-modal-dialog')) {
        closePaymentModal();
    }
});

// ESC key closes any open modal
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closePlanModal();
        closePaymentModal();
    }
});

function handlePlanTypeChange() {
    const pType = document.getElementById('planTypeSelect').value;
    const bonusGroup = document.getElementById('bonusMonthsGroup');
    if (pType === 'yearly') {
        bonusGroup.style.display = 'block';
    } else {
        bonusGroup.style.display = 'none';
    }
    if (pType === 'one_time') {
        document.getElementById('planIsLifetime').checked = true;
    }
    handleLifetimeToggle();
}

function handleLifetimeToggle() {
    const isLife = document.getElementById('planIsLifetime').checked;
    const expGroup = document.getElementById('expiryGroup');
    if (isLife) {
        expGroup.style.opacity = '0.4';
        document.getElementById('planExpiryDate').disabled = true;
    } else {
        expGroup.style.opacity = '1';
        document.getElementById('planExpiryDate').disabled = false;
    }
}

function handlePaymentPeriodChange() {
    const period = document.getElementById('payPeriodType').value;
    const bonusGroup = document.getElementById('payBonusGroup');
    if (period === '12_months') {
        bonusGroup.style.display = 'block';
        document.getElementById('payAmount').value = <?= json_encode($settings['yearly_price'] ?? '14999') ?>;
        document.getElementById('payDoctorLimit').value = <?= json_encode($settings['yearly_max_doctors'] ?? '10') ?>;
    } else if (period === '1_month') {
        bonusGroup.style.display = 'none';
        document.getElementById('payAmount').value = <?= json_encode($settings['monthly_price'] ?? '1499') ?>;
        document.getElementById('payDoctorLimit').value = <?= json_encode($settings['monthly_max_doctors'] ?? '3') ?>;
    } else if (period === 'lifetime') {
        bonusGroup.style.display = 'none';
        document.getElementById('payAmount').value = <?= json_encode($settings['one_time_price'] ?? '49999') ?>;
        document.getElementById('payDoctorLimit').value = <?= json_encode($settings['one_time_max_doctors'] ?? '0') ?>;
    } else {
        bonusGroup.style.display = 'none';
    }
}
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
