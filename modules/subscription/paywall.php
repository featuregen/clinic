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
$monthlyPrice = !empty($currentTenant['custom_monthly_price']) ? floatval($currentTenant['custom_monthly_price']) : floatval($settings['monthly_price'] ?? 1499);
$monthlyDoctors = !empty($currentTenant['custom_monthly_doctors']) ? intval($currentTenant['custom_monthly_doctors']) : intval($settings['monthly_max_doctors'] ?? 3);

$yearlyPrice = !empty($currentTenant['custom_yearly_price']) ? floatval($currentTenant['custom_yearly_price']) : floatval($settings['yearly_price'] ?? 14999);
$yearlyDoctors = !empty($currentTenant['custom_yearly_doctors']) ? intval($currentTenant['custom_yearly_doctors']) : intval($settings['yearly_max_doctors'] ?? 10);
$yearlyBonus = isset($currentTenant['custom_yearly_bonus_months']) && $currentTenant['custom_yearly_bonus_months'] !== null ? intval($currentTenant['custom_yearly_bonus_months']) : intval($settings['yearly_default_bonus_months'] ?? 2);
$totalYearlyMonths = 12 + $yearlyBonus;

$lifetimePrice = !empty($currentTenant['custom_lifetime_price']) ? floatval($currentTenant['custom_lifetime_price']) : floatval($settings['one_time_price'] ?? 49999);
$lifetimeDoctors = isset($currentTenant['custom_lifetime_doctors']) && $currentTenant['custom_lifetime_doctors'] !== null ? intval($currentTenant['custom_lifetime_doctors']) : intval($settings['one_time_max_doctors'] ?? 0);

$razorpayKey = $settings['razorpay_key_id'] ?? '';
$offlineContact = $settings['offline_payment_contact'] ?? 'Phone: +91 98765 43210';
$offlineBank = $settings['offline_bank_details'] ?? '';

$isExpired = !empty($currentTenant['is_expired']);
$isLifetime = !empty($currentTenant['is_lifetime']) && $currentTenant['is_lifetime'] == 1;
$endsAt = !empty($currentTenant['subscription_ends_at']) ? strtotime($currentTenant['subscription_ends_at']) : null;
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
                    Active until <strong><?= $endsAt ? date('d M Y', $endsAt) : 'Unlimited' ?></strong> &bull; Quota: <strong><?= $currentTenant['max_doctors'] > 0 ? $currentTenant['max_doctors'] . ' Doctors' : 'Unlimited' ?></strong>
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
<div class="grid-3 gap-24 mb-32" style="align-items: stretch;">

    <!-- 1. Monthly Plan -->
    <div class="card" style="border: 2px solid #e5e7eb; border-radius: 16px; transition: transform 0.2s, box-shadow 0.2s; display: flex; flex-direction: column;">
        <div class="card-body" style="padding: 32px 28px; flex: 1; display: flex; flex-direction: column;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <span class="badge" style="background: #dcfce7; color: #15803d; font-weight: 700; font-size: 12px; padding: 6px 12px; border-radius: 20px;">
                    Monthly Flexibility
                </span>
                <i class="fas fa-calendar-alt" style="font-size: 22px; color: #059669;"></i>
            </div>
            
            <h3 style="font-size: 22px; margin: 0 0 8px; color: var(--text);">Monthly Plan</h3>
            <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 24px;">Ideal for smaller clinics seeking month-to-month flexibility.</p>
            
            <div style="margin-bottom: 24px;">
                <span style="font-size: 36px; font-weight: 800; color: var(--text);">₹<?= number_format($monthlyPrice, 0) ?></span>
                <span style="font-size: 14px; color: var(--text-muted);">/ month</span>
            </div>

            <ul style="list-style: none; padding: 0; margin: 0 0 32px; flex: 1; font-size: 14px; line-height: 2;">
                <li><i class="fas fa-check" style="color: #059669; margin-right: 10px;"></i> Up to <strong><?= $monthlyDoctors ?> Doctors</strong></li>
                <li><i class="fas fa-check" style="color: #059669; margin-right: 10px;"></i> Full Patient Management</li>
                <li><i class="fas fa-check" style="color: #059669; margin-right: 10px;"></i> Appointments & Scheduling</li>
                <li><i class="fas fa-check" style="color: #059669; margin-right: 10px;"></i> Digital Prescriptions</li>
                <li><i class="fas fa-check" style="color: #059669; margin-right: 10px;"></i> Invoicing & Patient Billing</li>
                <li><i class="fas fa-check" style="color: #059669; margin-right: 10px;"></i> WhatsApp & SMS Notifications</li>
            </ul>

            <button type="button" class="btn btn-outline" style="width: 100%; padding: 12px; font-size: 15px; font-weight: 700;" onclick="selectPlan('monthly', <?= $monthlyPrice ?>, 'Monthly Plan')">
                <i class="fas fa-bolt"></i> Choose Monthly
            </button>
        </div>
    </div>

    <!-- 2. Yearly Plan (Featured) -->
    <div class="card" style="border: 2px solid #00838f; border-radius: 16px; box-shadow: 0 10px 30px rgba(0, 131, 143, 0.15); display: flex; flex-direction: column; position: relative;">
        <div style="position: absolute; top: -14px; left: 50%; transform: translateX(-50%); background: linear-gradient(135deg, #00838f, #00695c); color: white; padding: 4px 18px; border-radius: 20px; font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px;">
            <i class="fas fa-star"></i> Most Popular &bull; <?= 12 + $yearlyBonus ?> Months Access
        </div>
        
        <div class="card-body" style="padding: 36px 28px 32px; flex: 1; display: flex; flex-direction: column;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <span class="badge" style="background: #fef3c7; color: #b45309; font-weight: 700; font-size: 12px; padding: 6px 12px; border-radius: 20px;">
                    Includes +<?= $yearlyBonus ?> Bonus Months
                </span>
                <i class="fas fa-crown" style="font-size: 24px; color: #d97706;"></i>
            </div>
            
            <h3 style="font-size: 22px; margin: 0 0 8px; color: var(--text);">Yearly Plan</h3>
            <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 24px;">Complete hospital & clinic suite <?= $yearlyBonus > 0 ? "with {$yearlyBonus} months free bonus access." : "for a full year." ?></p>
            
            <div style="margin-bottom: 24px;">
                <span style="font-size: 36px; font-weight: 800; color: #00838f;">₹<?= number_format($yearlyPrice, 0) ?></span>
                <span style="font-size: 14px; color: var(--text-muted);">/ year (<?= $totalYearlyMonths ?> Months)</span>
            </div>

            <ul style="list-style: none; padding: 0; margin: 0 0 32px; flex: 1; font-size: 14px; line-height: 2;">
                <li><i class="fas fa-check" style="color: #00838f; margin-right: 10px;"></i> Up to <strong><?= $yearlyDoctors ?> Doctors</strong></li>
                <li><i class="fas fa-check" style="color: #00838f; margin-right: 10px;"></i> <strong><?= $totalYearlyMonths ?> Full Months Access</strong> <?= $yearlyBonus > 0 ? "(12 + {$yearlyBonus} Bonus)" : '' ?></li>
                <li><i class="fas fa-check" style="color: #00838f; margin-right: 10px;"></i> Multi-Branch Management</li>
                <li><i class="fas fa-check" style="color: #00838f; margin-right: 10px;"></i> Dental Chart & Treatment Plans</li>
                <li><i class="fas fa-check" style="color: #00838f; margin-right: 10px;"></i> Vaccination Schedules</li>
                <li><i class="fas fa-check" style="color: #00838f; margin-right: 10px;"></i> Priority 24/7 Technical Support</li>
            </ul>

            <button type="button" class="btn btn-primary" style="width: 100%; padding: 12px; font-size: 15px; font-weight: 700; background: linear-gradient(135deg, #00838f, #00695c); border: none;" onclick="selectPlan('yearly', <?= $yearlyPrice ?>, 'Yearly Plan (<?= $totalYearlyMonths ?> Months)')">
                <i class="fas fa-crown"></i> Choose Yearly (<?= $totalYearlyMonths ?> Months)
            </button>
        </div>
    </div>

    <!-- 3. One-Time Lifetime Plan -->
    <div class="card" style="border: 2px solid #e5e7eb; border-radius: 16px; display: flex; flex-direction: column;">
        <div class="card-body" style="padding: 32px 28px; flex: 1; display: flex; flex-direction: column;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <span class="badge" style="background: #e0f2fe; color: #0369a1; font-weight: 700; font-size: 12px; padding: 6px 12px; border-radius: 20px;">
                    Perpetual License
                </span>
                <i class="fas fa-infinity" style="font-size: 22px; color: #0284c7;"></i>
            </div>
            
            <h3 style="font-size: 22px; margin: 0 0 8px; color: var(--text);">One-Time Lifetime</h3>
            <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 24px;">Pay once and own forever. Zero renewals. Unlimited forever.</p>
            
            <div style="margin-bottom: 24px;">
                <span style="font-size: 36px; font-weight: 800; color: #0284c7;">₹<?= number_format($lifetimePrice, 0) ?></span>
                <span style="font-size: 14px; color: var(--text-muted);">one-time</span>
            </div>

            <ul style="list-style: none; padding: 0; margin: 0 0 32px; flex: 1; font-size: 14px; line-height: 2;">
                <li><i class="fas fa-check" style="color: #0284c7; margin-right: 10px;"></i> <strong>Unlimited Doctors</strong></li>
                <li><i class="fas fa-check" style="color: #0284c7; margin-right: 10px;"></i> <strong>Lifetime Perpetual Access</strong></li>
                <li><i class="fas fa-check" style="color: #0284c7; margin-right: 10px;"></i> Unlimited Branches & Locations</li>
                <li><i class="fas fa-check" style="color: #0284c7; margin-right: 10px;"></i> Custom Clinic Branding & Domain</li>
                <li><i class="fas fa-check" style="color: #0284c7; margin-right: 10px;"></i> All Future Feature Updates Included</li>
                <li><i class="fas fa-check" style="color: #0284c7; margin-right: 10px;"></i> Dedicated Account Manager</li>
            </ul>

            <button type="button" class="btn btn-outline" style="width: 100%; padding: 12px; font-size: 15px; font-weight: 700; border-color: #0284c7; color: #0284c7;" onclick="selectPlan('one_time', <?= $lifetimePrice ?>, 'One-Time Lifetime License')">
                <i class="fas fa-infinity"></i> Get Lifetime Access
            </button>
        </div>
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
                    <h3 style="margin: 0 0 4px; font-size: 20px; color: var(--text);">Prefer Paying in Cash on Hand or Direct Bank Transfer?</h3>
                    <p style="margin: 0; color: var(--text-muted); font-size: 14px;">
                        Hand over payment directly in cash to your Feature Gen Care administrator, or transfer via NEFT / UPI.
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
<!-- CHECKOUT / PAYMENT MODAL -->
<!-- ============================================ -->
<div id="checkoutModal" style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(0,0,0,0.6); align-items:center; justify-content:center;">
    <div class="card" style="width: 520px; max-width: 95vw; animation: slideUp 0.2s ease;">
        <div class="card-header">
            <h3><i class="fas fa-shopping-cart" style="color: var(--primary);"></i> Complete Subscription Renewal</h3>
            <button type="button" class="btn btn-sm btn-ghost" onclick="closeCheckoutModal()" style="font-size: 20px;">&times;</button>
        </div>
        <div class="card-body">
            <div style="background: var(--bg-secondary); padding: 16px; border-radius: 8px; margin-bottom: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <span style="font-size: 14px; font-weight: 600;" id="modalPlanName">Yearly Plan</span>
                    <span style="font-size: 22px; font-weight: 800; color: #00838f;" id="modalPlanPrice">₹14,999.00</span>
                </div>
                <div style="font-size: 12px; color: var(--text-muted);">
                    Tenant Clinic: <strong><?= sanitizeOutput($currentTenant['clinic_name']) ?></strong> (<?= sanitizeOutput($currentTenant['subdomain']) ?>.featuregen.com)
                </div>
            </div>

            <?php if (!empty($razorpayKey)): ?>
            <div class="mb-20">
                <button type="button" id="razorpayBtn" class="btn btn-primary" style="width: 100%; padding: 14px; font-size: 16px; font-weight: 700; background: #00838f;">
                    <i class="fas fa-bolt"></i> Pay Online via Razorpay
                </button>
                <div style="text-align: center; margin-top: 8px; font-size: 12px; color: var(--text-muted);">
                    Supports UPI, Credit/Debit Cards, Net Banking, and Wallets
                </div>
            </div>
            <?php endif; ?>

            <div style="border-top: 1px solid var(--border-color); padding-top: 16px;">
                <h4 style="font-size: 14px; margin: 0 0 8px;"><i class="fas fa-hand-holding-usd" style="color: #059669;"></i> Paying via Cash on Hand or Direct Transfer?</h4>
                <p style="font-size: 13px; color: var(--text-muted); margin: 0 0 12px;">
                    Please contact your Feature Gen Care administrator directly. Once cash is collected, your subscription is immediately updated.
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
    price: <?= $yearlyPrice ?>,
    name: 'Yearly Plan (<?= $totalYearlyMonths ?> Months)'
};

function selectPlan(type, price, name) {
    selectedPlan = { type, price, name };
    document.getElementById('modalPlanName').textContent = name;
    document.getElementById('modalPlanPrice').textContent = '₹' + price.toLocaleString('en-IN', { minimumFractionDigits: 2 });
    document.getElementById('checkoutModal').style.display = 'flex';
}

function closeCheckoutModal() {
    document.getElementById('checkoutModal').style.display = 'none';
}

const rzpKey = <?= json_encode($razorpayKey) ?>;
const razorpayBtn = document.getElementById('razorpayBtn');

if (razorpayBtn && rzpKey) {
    razorpayBtn.addEventListener('click', function() {
        const options = {
            "key": rzpKey,
            "amount": Math.round(selectedPlan.price * 100),
            "currency": "INR",
            "name": "Feature Gen Care",
            "description": selectedPlan.name + " - Subscription Renewal",
            "image": "<?= ASSETS_URL ?>/images/favicon.svg",
            "handler": function (response) {
                // Post to verification script
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '<?= BASE_URL ?>/modules/subscription/verify_payment.php';
                
                const fields = {
                    razorpay_payment_id: response.razorpay_payment_id,
                    plan_type: selectedPlan.type,
                    amount: selectedPlan.price
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
                "color": "#00838f"
            }
        };
        const rzp = new Razorpay(options);
        rzp.open();
    });
}
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
