<?php
/**
 * Doctor List - Feature Gen Care
 */
$pageTitle = 'Doctors';
require_once dirname(dirname(__DIR__)) . '/config/session.php';
requireAuth();
requirePermission('doctors.view');

$db = db();
$clinicId = getCurrentClinicId();

// Handle Toggle Doctor Status (Activate / Deactivate) BEFORE header output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_status') {
    requirePermission('doctors.edit');
    $doctorId = (int)($_POST['doctor_id'] ?? 0);
    $currentStatus = (int)($_POST['current_status'] ?? 0);
    $newStatus = $currentStatus === 1 ? 0 : 1;

    $tenantData = $db->tenantInfo ?? [];
    $maxDoctors = isset($tenantData['max_doctors']) ? intval($tenantData['max_doctors']) : 0;

    // If activating, verify quota limit
    if ($newStatus === 1 && $maxDoctors > 0) {
        $activeDocCount = $db->fetch(
            "SELECT COUNT(*) as c FROM doctors d JOIN users u ON d.user_id = u.id WHERE d.clinic_id = ? AND u.is_active = 1",
            [$clinicId]
        )['c'] ?? 0;
        if ($activeDocCount >= $maxDoctors) {
            setFlashMessage('error', "Cannot activate doctor: Your subscription quota allows a maximum of {$maxDoctors} active doctor slot(s) (currently using {$activeDocCount}/{$maxDoctors}). Please upgrade your plan or deactivate another doctor first.");
            header('Location: ' . BASE_URL . '/modules/doctors/list.php');
            exit;
        }
    }

    $docUser = $db->fetch(
        "SELECT d.id, d.user_id, u.full_name FROM doctors d JOIN users u ON d.user_id = u.id WHERE d.id = ? AND d.clinic_id = ?",
        [$doctorId, $clinicId]
    );
    if ($docUser) {
        $db->query("UPDATE users SET is_active = ? WHERE id = ?", [$newStatus, $docUser['user_id']]);
        if ($newStatus === 0) {
            $db->query("UPDATE doctors SET is_available = 0 WHERE id = ?", [$doctorId]);
        } else {
            $db->query("UPDATE doctors SET is_available = 1 WHERE id = ?", [$doctorId]);
        }
        logAudit('toggle_status', 'doctor', 'doctors', $doctorId, null, null, ($newStatus ? 'Activated' : 'Deactivated') . " Dr. {$docUser['full_name']}");
        setFlashMessage('success', "Dr. {$docUser['full_name']} has been " . ($newStatus ? 'activated' : 'deactivated') . " successfully.");
    } else {
        setFlashMessage('error', 'Doctor record not found.');
    }
    header('Location: ' . BASE_URL . '/modules/doctors/list.php');
    exit;
}

require_once dirname(dirname(__DIR__)) . '/includes/header.php';

$doctors = $db->fetchAll(
    "SELECT d.*, u.full_name, u.email, u.phone, u.profile_image, u.last_login, u.is_active,
            s.name as specialty_name, dept.name as department_name, b.name as branch_name,
            (SELECT COUNT(*) FROM appointments WHERE doctor_id = d.id AND appointment_date = CURDATE()) as today_appointments,
            (SELECT COUNT(*) FROM appointments WHERE doctor_id = d.id) as total_appointments
     FROM doctors d
     JOIN users u ON d.user_id = u.id
     LEFT JOIN specialties s ON d.specialty_id = s.id
     LEFT JOIN departments dept ON d.department_id = dept.id
     LEFT JOIN branches b ON u.branch_id = b.id
     WHERE d.clinic_id = ?
     ORDER BY u.is_active DESC, u.full_name ASC", [$clinicId]
);

$tenantData = $db->tenantInfo ?? [];
$maxDoctors = isset($tenantData['max_doctors']) ? intval($tenantData['max_doctors']) : 0;
$activeDocs = 0;
foreach ($doctors as $d) {
    if (!empty($d['is_active'])) {
        $activeDocs++;
    }
}
$isQuotaFull = ($maxDoctors > 0 && $activeDocs >= $maxDoctors);

// Resolve addon doctor price: tenant custom > global setting > hardcoded default
$addonDocPrice = 25;
if (!empty($tenantData['custom_addon_doctor_monthly_price'])) {
    $addonDocPrice = floatval($tenantData['custom_addon_doctor_monthly_price']);
} else {
    try {
        $addonSetting = master_db()->query("SELECT setting_value FROM saas_global_settings WHERE setting_key = 'addon_doctor_monthly_price' LIMIT 1")->fetchColumn();
        if ($addonSetting !== false && is_numeric($addonSetting)) {
            $addonDocPrice = floatval($addonSetting);
        }
    } catch (\Throwable $e) {}
}
$addonDocPriceLabel = (floor($addonDocPrice) == $addonDocPrice) ? number_format($addonDocPrice, 0) : number_format($addonDocPrice, 2);
?>

<div class="content-header">
    <div>
        <ul class="breadcrumb">
            <li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li>
            <li>Doctors</li>
        </ul>
        <div style="display: flex; align-items: center; gap: 14px;">
            <h1 style="margin-bottom: 0;">Doctor Management</h1>
            <span class="badge <?= $isQuotaFull ? 'badge-danger' : 'badge-success' ?>" style="font-size: 12px; padding: 4px 10px; border-radius: 20px;">
                <i class="fas fa-user-md"></i> <?= $activeDocs ?> / <?= $maxDoctors > 0 ? $maxDoctors : '∞' ?> Slots
            </span>
            <?php if ($isQuotaFull): ?>
            <span style="font-size: 12px; color: #dc2626; font-weight: 600;">
                (Limit Reached)
            </span>
            <?php endif; ?>
        </div>
    </div>
    <div class="d-flex gap-8 align-items-center">
        <?php if ($isQuotaFull): ?>
        <a href="<?= BASE_URL ?>/modules/subscription/paywall.php#doctor-addons" class="btn btn-sm" style="background: linear-gradient(135deg, #0891b2, #0e7490); color: white; border: none; font-weight: 700;">
            <i class="fas fa-plus-circle"></i> Add Doctor Slots (+₹<?= $addonDocPriceLabel ?>/mo)
        </a>
        <a href="<?= BASE_URL ?>/modules/subscription/paywall.php" class="btn btn-warning btn-sm" style="background: #d97706; color: white; border: none;">
            <i class="fas fa-arrow-circle-up"></i> Upgrade Plan
        </a>
        <?php endif; ?>
        <?php if (hasPermission('doctors.create')): ?>
        <a href="<?= BASE_URL ?>/modules/doctors/add.php" class="btn btn-primary" <?= $isQuotaFull ? 'style="opacity: 0.7;" title="Limit reached - deactivate an inactive doctor or upgrade to add more"' : '' ?>>
            <i class="fas fa-user-md"></i> Add Doctor
        </a>
        <?php endif; ?>
    </div>
</div>

<div class="grid-3 gap-24">
    <?php if (empty($doctors)): ?>
    <div class="card" style="grid-column: span 3;">
        <div class="empty-state"><i class="fas fa-user-md"></i><h3>No doctors registered</h3><p>Add your first doctor to get started</p></div>
    </div>
    <?php else: foreach ($doctors as $doc): 
        $isDocActive = !empty($doc['is_active']);
    ?>
    <div class="card" style="<?= !$isDocActive ? 'opacity: 0.82; background: #fafafa; border: 1px dashed #cbd5e1;' : '' ?>">
        <div class="card-body text-center" style="padding: 32px 24px;">
            <div class="user-avatar" style="width: 72px; height: 72px; font-size: 24px; margin: 0 auto 12px; <?= !$isDocActive ? 'filter: grayscale(1); opacity: 0.7;' : '' ?>">
                <?php if ($doc['profile_image']): ?>
                    <img src="<?= UPLOADS_URL . '/' . $doc['profile_image'] ?>" alt="Photo">
                <?php else: ?>
                    <?= getInitials($doc['full_name']) ?>
                <?php endif; ?>
            </div>
            <h3 style="<?= !$isDocActive ? 'color: var(--text-muted);' : '' ?>">Dr. <?= sanitizeOutput($doc['full_name']) ?></h3>
            <p class="text-muted" style="font-size: 13px;"><?= sanitizeOutput($doc['specialty_name'] ?? 'General') ?></p>
            <p style="font-size: 12px; color: var(--text-muted);"><?= sanitizeOutput($doc['qualification'] ?? '') ?></p>
            <div>
                <?php if (!empty($doc['branch_name'])): ?>
                    <span class="badge badge-info" style="font-size: 11px;"><i class="fas fa-map-marker-alt"></i> <?= sanitizeOutput($doc['branch_name']) ?></span>
                <?php else: ?>
                    <span class="badge badge-secondary" style="font-size: 11px;"><i class="fas fa-network-wired"></i> All Branches</span>
                <?php endif; ?>
            </div>
            
            <div style="display: flex; gap: 16px; justify-content: center; margin: 16px 0;">
                <div style="text-align: center;">
                    <div class="stat-value" style="font-size: 1.1rem;"><?= $doc['today_appointments'] ?></div>
                    <div class="text-muted" style="font-size: 11px;">Today</div>
                </div>
                <div style="text-align: center;">
                    <div class="stat-value" style="font-size: 1.1rem;"><?= $doc['total_appointments'] ?></div>
                    <div class="text-muted" style="font-size: 11px;">Total</div>
                </div>
                <div style="text-align: center;">
                    <div class="stat-value" style="font-size: 1.1rem;"><?= formatCurrency($doc['consultation_fee']) ?></div>
                    <div class="text-muted" style="font-size: 11px;">Fee</div>
                </div>
            </div>
            
            <div class="d-flex gap-8 justify-center" style="flex-wrap: wrap;">
                <?php if (!$isDocActive): ?>
                    <span class="badge" style="background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; font-weight: 700; font-size: 11px;">
                        <i class="fas fa-ban"></i> Deactivated
                    </span>
                <?php else: ?>
                    <span class="badge badge-<?= $doc['is_available'] ? 'success' : 'danger' ?>" style="font-size: 11px;">
                        <?= $doc['is_available'] ? 'Available' : 'Unavailable' ?>
                    </span>
                <?php endif; ?>
                <?php if ($doc['department_name']): ?>
                <span class="badge badge-secondary" style="font-size: 11px;"><?= sanitizeOutput($doc['department_name']) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-footer d-flex justify-center gap-8" style="flex-wrap: wrap;">
            <a href="<?= BASE_URL ?>/modules/appointments/list.php?doctor=<?= $doc['id'] ?>" class="btn btn-sm btn-outline"><i class="fas fa-calendar"></i> Appointments</a>
            <?php if (hasPermission('doctors.edit')): ?>
            <a href="<?= BASE_URL ?>/modules/doctors/add.php?id=<?= $doc['id'] ?>" class="btn btn-sm btn-ghost" title="Edit Doctor Profile"><i class="fas fa-pen"></i></a>
            
            <form method="POST" style="display: inline; margin: 0;" onsubmit="return confirm('Are you sure you want to <?= $isDocActive ? 'deactivate' : 'activate' ?> Dr. <?= htmlspecialchars(addslashes($doc['full_name']), ENT_QUOTES) ?>? <?= $isDocActive ? 'They will not be able to log in or take appointments, and will free up 1 doctor slot.' : 'This will consume 1 active doctor slot.' ?>');">
                <input type="hidden" name="action" value="toggle_status">
                <input type="hidden" name="doctor_id" value="<?= $doc['id'] ?>">
                <input type="hidden" name="current_status" value="<?= $isDocActive ? 1 : 0 ?>">
                <button type="submit" class="btn btn-sm" style="<?= $isDocActive ? 'background: #fef2f2; color: #dc2626; border: 1px solid #fecaca;' : 'background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0;' ?>" title="<?= $isDocActive ? 'Deactivate Doctor (free up quota)' : 'Activate Doctor' ?>">
                    <i class="fas <?= $isDocActive ? 'fa-user-slash' : 'fa-user-check' ?>"></i> <?= $isDocActive ? 'Deactivate' : 'Activate' ?>
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; endif; ?>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
