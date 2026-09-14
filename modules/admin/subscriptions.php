<?php
/**
 * Subscription & Billing Control Plane - Feature Gen Care
 * Super Admin Only
 */
$pageTitle = 'Subscriptions & Billing';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';

$role = getCurrentUserRole();
if ($role !== ROLE_SUPER_ADMIN) {
    header('Location: ' . BASE_URL . '/modules/auth/403.php');
    exit;
}

$master = master_db();
$currentTab = sanitize($_GET['tab'] ?? 'tenants');
$successMsg = '';
$errorMsg = '';

// ============================================
// POST ACTION HANDLERS
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = sanitize($_POST['action'] ?? '');
    
    // 1. UPDATE TENANT PLAN & DOCTOR LIMITS
    if ($action === 'update_plan') {
        $tenantId = intval($_POST['tenant_id'] ?? 0);
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
            
            setFlashMessage('success', 'Tenant subscription plan updated successfully.');
            header("Location: " . BASE_URL . "/modules/admin/subscriptions.php?tab=tenants");
            exit;
        } catch (Exception $e) {
            $errorMsg = 'Error updating plan: ' . $e->getMessage();
        }
    }
    
    // 2. RECORD PAYMENT (Cash on Hand, Bank Transfer, Cheque, UPI, Razorpay)
    elseif ($action === 'record_payment') {
        $tenantId = intval($_POST['tenant_id'] ?? 0);
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
            
            // Fetch current tenant
            $stmtT = $master->prepare("SELECT * FROM tenants WHERE id = ?");
            $stmtT->execute([$tenantId]);
            $tenant = $stmtT->fetch();
            
            if (!$tenant) {
                throw new Exception("Clinic tenant not found.");
            }
            
            $now = time();
            $currentEnd = !empty($tenant['subscription_ends_at']) ? strtotime($tenant['subscription_ends_at']) : 0;
            $periodStart = ($currentEnd > $now) ? date('Y-m-d H:i:s', $currentEnd) : date('Y-m-d H:i:s');
            $startTs = strtotime($periodStart);
            $newEnd = null;
            $isLifetime = 0;
            $planType = $tenant['plan_type'];
            
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
            setFlashMessage('success', "Payment of ₹" . number_format($amount, 2) . " logged successfully! Receipt #$reference issued.");
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
// FETCH DATA FOR DISPLAY
// ============================================
// 1. Tenants Roster
$tenants = $master->query("SELECT * FROM tenants ORDER BY id DESC")->fetchAll();

// 2. SaaS Settings
$settingsRows = $master->query("SELECT setting_key, setting_value FROM saas_global_settings")->fetchAll();
$settings = [];
foreach ($settingsRows as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// 3. Payment History Ledger
$payments = $master->query("
    SELECT p.*, t.clinic_name, t.subdomain 
    FROM tenant_subscription_payments p 
    JOIN tenants t ON p.tenant_id = t.id 
    ORDER BY p.id DESC 
    LIMIT 100
")->fetchAll();

// Stats calculations
$totalClinics = count($tenants);
$activeClinics = 0;
$expiringSoonClinics = 0;
$expiredClinics = 0;
$lifetimeClinics = 0;
$now = time();

foreach ($tenants as $t) {
    if (!empty($t['is_lifetime'])) {
        $lifetimeClinics++;
        $activeClinics++;
    } elseif (!empty($t['subscription_ends_at'])) {
        $expTime = strtotime($t['subscription_ends_at']);
        if ($expTime < $now) {
            $expiredClinics++;
        } else {
            $activeClinics++;
            if ($expTime - $now <= (7 * 86400)) {
                $expiringSoonClinics++;
            }
        }
    } else {
        $activeClinics++;
    }
}

$totalRevenue = $master->query("SELECT COALESCE(SUM(amount), 0) as tot FROM tenant_subscription_payments WHERE status = 'completed'")->fetch()['tot'] ?? 0;
?>

<div class="content-header">
    <div>
        <ul class="breadcrumb">
            <li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li>
            <li><a href="<?= BASE_URL ?>/modules/admin/index.php">System Admin</a></li>
            <li>Subscriptions & Billing</li>
        </ul>
        <h1><i class="fas fa-credit-card" style="color: var(--primary);"></i> SaaS Subscriptions & Billing Control Plane</h1>
    </div>
    <div class="d-flex gap-8">
        <button class="btn btn-success" onclick="openPaymentModal()"><i class="fas fa-hand-holding-usd"></i> Record Payment (Cash/Offline)</button>
        <a href="<?= BASE_URL ?>/modules/admin/create_admin.php" class="btn btn-warning" style="background: #d97706; border-color: #b45309; color: white;"><i class="fas fa-user-shield"></i> Create Clinic Admin</a>
    </div>
</div>

<?php if ($errorMsg): ?>
<div class="alert alert-error mb-20"><i class="fas fa-times-circle"></i> <?= sanitizeOutput($errorMsg) ?></div>
<?php endif; ?>

<!-- Stat Metrics -->
<div class="grid-4 mb-24" style="gap: 18px;">
    <div class="stat-card">
        <div class="stat-icon primary"><i class="fas fa-hospital-user"></i></div>
        <div class="stat-details">
            <div class="stat-label">Total Clinics</div>
            <div class="stat-value"><?= $totalClinics ?></div>
            <div class="stat-change"><?= $activeClinics ?> currently active</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon warning"><i class="fas fa-clock"></i></div>
        <div class="stat-details">
            <div class="stat-label">Expiring Soon (≤7d)</div>
            <div class="stat-value" style="color: #d97706;"><?= $expiringSoonClinics ?></div>
            <div class="stat-change"><?= $expiredClinics ?> expired clinics</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon info"><i class="fas fa-infinity"></i></div>
        <div class="stat-details">
            <div class="stat-label">Lifetime Licenses</div>
            <div class="stat-value" style="color: #0284c7;"><?= $lifetimeClinics ?></div>
            <div class="stat-change">One-Time Perpetual</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon success"><i class="fas fa-rupee-sign"></i></div>
        <div class="stat-details">
            <div class="stat-label">Total SaaS Collections</div>
            <div class="stat-value" style="color: #059669;">₹<?= number_format($totalRevenue, 2) ?></div>
            <div class="stat-change"><?= count($payments) ?> transactions logged</div>
        </div>
    </div>
</div>

<!-- Tabs Navigation -->
<div class="card mb-24">
    <div style="display: flex; border-bottom: 1px solid var(--border-color); background: var(--bg-secondary); border-radius: 12px 12px 0 0;">
        <a href="?tab=tenants" class="tab-link <?= $currentTab === 'tenants' ? 'active' : '' ?>">
            <i class="fas fa-building"></i> Clinic Subscriptions (<?= count($tenants) ?>)
        </a>
        <a href="?tab=payments" class="tab-link <?= $currentTab === 'payments' ? 'active' : '' ?>">
            <i class="fas fa-receipt"></i> Payment Ledger (<?= count($payments) ?>)
        </a>
        <a href="?tab=settings" class="tab-link <?= $currentTab === 'settings' ? 'active' : '' ?>">
            <i class="fas fa-sliders-h"></i> SaaS Global Settings & Plans
        </a>
    </div>

    <!-- TAB 1: CLINIC SUBSCRIPTIONS ROSTER -->
    <?php if ($currentTab === 'tenants'): ?>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table" style="margin: 0;">
                <thead>
                    <tr>
                        <th>Clinic & Subdomain</th>
                        <th>Plan Tier</th>
                        <th>Doctor Quota</th>
                        <th>Validity / Expiry</th>
                        <th>Status</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($tenants)): ?>
                    <tr><td colspan="6" class="text-center text-muted" style="padding: 30px;">No clinics registered.</td></tr>
                    <?php else: foreach ($tenants as $t): 
                        $isLife = !empty($t['is_lifetime']) && $t['is_lifetime'] == 1;
                        $endsAt = !empty($t['subscription_ends_at']) ? strtotime($t['subscription_ends_at']) : null;
                        $isExp = (!$isLife && $endsAt && $endsAt < $now);
                        $daysLeft = $endsAt ? ceil(($endsAt - $now) / 86400) : null;
                    ?>
                    <tr>
                        <td>
                            <strong style="font-size: 14px; color: var(--primary);"><?= sanitizeOutput($t['clinic_name']) ?></strong>
                            <div style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">
                                <i class="fas fa-globe"></i> <strong><?= sanitizeOutput($t['subdomain']) ?></strong>.featuregen.com
                            </div>
                        </td>
                        <td>
                            <?php if ($isLife || $t['plan_type'] === 'one_time'): ?>
                                <span class="badge" style="background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; font-weight: 700;">
                                    <i class="fas fa-infinity"></i> Lifetime
                                </span>
                            <?php elseif ($t['plan_type'] === 'yearly'): ?>
                                <span class="badge" style="background: #fef3c7; color: #b45309; border: 1px solid #fde68a; font-weight: 700;">
                                    <i class="fas fa-crown"></i> Yearly <?= $t['bonus_months'] > 0 ? '(+' . $t['bonus_months'] . 'm Bonus)' : '' ?>
                                </span>
                            <?php elseif ($t['plan_type'] === 'monthly'): ?>
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
                            <span class="badge badge-info" style="font-size: 12px; padding: 4px 10px;">
                                <i class="fas fa-user-md"></i> <?= $t['max_doctors'] > 0 ? $t['max_doctors'] . ' Doctors Max' : '∞ Unlimited' ?>
                            </span>
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
                            <?php elseif ($t['status'] === 'suspended'): ?>
                                <span class="badge badge-danger"><i class="fas fa-lock"></i> Suspended</span>
                            <?php else: ?>
                                <span class="badge badge-success"><i class="fas fa-check-circle"></i> Active</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: right; white-space: nowrap;">
                            <button class="btn btn-sm btn-outline" onclick='openPlanModal(<?= json_encode($t) ?>)'>
                                <i class="fas fa-sliders-h"></i> Plan & Quota
                            </button>
                            <button class="btn btn-sm btn-success" onclick='openPaymentModalFor(<?= json_encode($t) ?>)' style="background: #059669; color: white;">
                                <i class="fas fa-money-bill-wave"></i> Record Cash
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- TAB 2: PAYMENT HISTORY LEDGER -->
    <?php if ($currentTab === 'payments'): ?>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table" style="margin: 0;">
                <thead>
                    <tr>
                        <th>Receipt #</th>
                        <th>Clinic Tenant</th>
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
                    <tr><td colspan="8" class="text-center text-muted" style="padding: 30px;">No payment records logged yet.</td></tr>
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
                            <strong style="color: var(--primary);"><?= sanitizeOutput($p['clinic_name']) ?></strong>
                            <div style="font-size: 11px; color: var(--text-muted);"><?= sanitizeOutput($p['subdomain']) ?></div>
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
<!-- ============================================ -->
<div id="planModal" style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(0,0,0,0.6); align-items:center; justify-content:center;">
    <div class="card" style="width: 580px; max-width: 95vw; animation: slideUp 0.2s ease;">
        <div class="card-header">
            <h3><i class="fas fa-sliders-h" style="color: var(--primary);"></i> Manage Subscription & Doctor Quota</h3>
            <button type="button" class="btn btn-sm btn-ghost" onclick="closePlanModal()" style="font-size: 20px;">&times;</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="update_plan">
            <input type="hidden" name="tenant_id" id="planTenantId">
            <div class="card-body">
                <div style="background: var(--bg-secondary); padding: 12px 16px; border-radius: 8px; margin-bottom: 20px;">
                    <div style="font-size: 11px; text-transform: uppercase; color: var(--text-muted); font-weight: 700;">Target Clinic</div>
                    <div id="planClinicName" style="font-size: 16px; font-weight: 700; color: var(--primary);"></div>
                    <div id="planSubdomain" style="font-size: 12px; color: var(--text-muted);"></div>
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

                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" name="is_lifetime" id="planIsLifetime" value="1" onchange="handleLifetimeToggle()">
                        <span style="font-weight: 600;">Grant Lifetime Perpetual License (Never Expires)</span>
                    </label>
                </div>
            </div>
            <div class="card-footer d-flex justify-end gap-12">
                <button type="button" class="btn btn-outline" onclick="closePlanModal()">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Subscription</button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================ -->
<!-- MODAL 2: RECORD PAYMENT (CASH ON HAND ETC) -->
<!-- ============================================ -->
<div id="paymentModal" style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(0,0,0,0.6); align-items:center; justify-content:center;">
    <div class="card" style="width: 620px; max-width: 95vw; animation: slideUp 0.2s ease;">
        <div class="card-header" style="background: linear-gradient(135deg, #059669, #047857); color: white;">
            <h3 style="color: white;"><i class="fas fa-hand-holding-usd"></i> Record Subscription Payment (Cash / Offline)</h3>
            <button type="button" class="btn btn-sm btn-ghost" onclick="closePaymentModal()" style="font-size: 20px; color: white;">&times;</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="record_payment">
            <div class="card-body">
                <div class="form-group mb-16">
                    <label class="form-label">Select Clinic Tenant <span class="required">*</span></label>
                    <select name="tenant_id" id="payTenantSelect" class="form-control" required onchange="onPaymentTenantSelected()">
                        <option value="">-- Select Clinic --</option>
                        <?php foreach ($tenants as $t): ?>
                        <option value="<?= $t['id'] ?>" data-tenant='<?= json_encode($t) ?>'>
                            <?= sanitizeOutput($t['clinic_name']) ?> (<?= sanitizeOutput($t['subdomain']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
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
                        <input type="number" name="amount" id="payAmount" class="form-control" step="0.01" placeholder="e.g. 15000" required>
                    </div>
                </div>

                <div class="grid-2 gap-16 mb-16">
                    <div class="form-group">
                        <label class="form-label">Receipt / Ref Number</label>
                        <input type="text" name="payment_reference" id="payReference" class="form-control" value="REC-<?= date('Y') ?>-<?= rand(1000, 9999) ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Collected By (Agent / Super Admin) <span class="required">*</span></label>
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
                        <input type="number" name="bonus_months_granted" id="payBonusMonths" class="form-control" value="2" min="0">
                    </div>
                </div>

                <div class="grid-2 gap-16 mb-16">
                    <div class="form-group">
                        <label class="form-label">Doctor Limit Granted <span class="required">*</span></label>
                        <input type="number" name="doctor_limit_granted" id="payDoctorLimit" class="form-control" value="10" min="1" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Payment Date <span class="required">*</span></label>
                        <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>

                <div class="form-group mb-0">
                    <label class="form-label">Payment & Handover Notes</label>
                    <textarea name="notes" class="form-control" rows="2" placeholder="e.g. Cash collected at clinic branch; handed over directly."></textarea>
                </div>
            </div>
            <div class="card-footer d-flex justify-end gap-12">
                <button type="button" class="btn btn-outline" onclick="closePaymentModal()">Cancel</button>
                <button type="submit" class="btn btn-success" style="background: #059669; color: white;">
                    <i class="fas fa-check-circle"></i> Confirm Payment & Issue Receipt
                </button>
            </div>
        </form>
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
@keyframes slideUp {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>

<script>
function openPlanModal(tenant) {
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
}

function closePlanModal() {
    document.getElementById('planModal').style.display = 'none';
}

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

function openPaymentModal() {
    document.getElementById('paymentModal').style.display = 'flex';
}

function openPaymentModalFor(tenant) {
    document.getElementById('payTenantSelect').value = tenant.id;
    onPaymentTenantSelected();
    openPaymentModal();
}

function closePaymentModal() {
    document.getElementById('paymentModal').style.display = 'none';
}

function onPaymentTenantSelected() {
    const select = document.getElementById('payTenantSelect');
    const selectedOption = select.options[select.selectedIndex];
    if (selectedOption && selectedOption.dataset.tenant) {
        const tenant = JSON.parse(selectedOption.dataset.tenant);
        if (tenant.max_doctors) {
            document.getElementById('payDoctorLimit').value = tenant.max_doctors;
        }
        if (tenant.plan_type === 'yearly') {
            document.getElementById('payAmount').value = <?= json_encode($settings['yearly_price'] ?? '14999') ?>;
            document.getElementById('payPeriodType').value = '12_months';
        } else if (tenant.plan_type === 'monthly') {
            document.getElementById('payAmount').value = <?= json_encode($settings['monthly_price'] ?? '1499') ?>;
            document.getElementById('payPeriodType').value = '1_month';
        }
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
