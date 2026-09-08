<?php
/**
 * Doctor List - Advanced Clinic Suite
 */
$pageTitle = 'Doctors';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';
requirePermission('doctors.view');

$db = db();
$clinicId = getCurrentClinicId();

$doctors = $db->fetchAll(
    "SELECT d.*, u.full_name, u.email, u.phone, u.profile_image, u.last_login,
            s.name as specialty_name, dept.name as department_name,
            (SELECT COUNT(*) FROM appointments WHERE doctor_id = d.id AND appointment_date = CURDATE()) as today_appointments,
            (SELECT COUNT(*) FROM appointments WHERE doctor_id = d.id) as total_appointments
     FROM doctors d
     JOIN users u ON d.user_id = u.id
     LEFT JOIN specialties s ON d.specialty_id = s.id
     LEFT JOIN departments dept ON d.department_id = dept.id
     WHERE d.clinic_id = ?
     ORDER BY u.full_name", [$clinicId]
);
?>

<div class="content-header">
    <div>
        <ul class="breadcrumb">
            <li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li>
            <li>Doctors</li>
        </ul>
        <h1>Doctor Management</h1>
    </div>
    <?php if (hasPermission('doctors.create')): ?>
    <a href="<?= BASE_URL ?>/modules/doctors/add.php" class="btn btn-primary"><i class="fas fa-user-md"></i> Add Doctor</a>
    <?php endif; ?>
</div>

<div class="grid-3 gap-24">
    <?php if (empty($doctors)): ?>
    <div class="card" style="grid-column: span 3;">
        <div class="empty-state"><i class="fas fa-user-md"></i><h3>No doctors registered</h3><p>Add your first doctor to get started</p></div>
    </div>
    <?php else: foreach ($doctors as $doc): ?>
    <div class="card">
        <div class="card-body text-center" style="padding: 32px 24px;">
            <div class="user-avatar" style="width: 72px; height: 72px; font-size: 24px; margin: 0 auto 12px;">
                <?php if ($doc['profile_image']): ?>
                    <img src="<?= UPLOADS_URL . '/' . $doc['profile_image'] ?>" alt="Photo">
                <?php else: ?>
                    <?= getInitials($doc['full_name']) ?>
                <?php endif; ?>
            </div>
            <h3>Dr. <?= sanitizeOutput($doc['full_name']) ?></h3>
            <p class="text-muted" style="font-size: 13px;"><?= sanitizeOutput($doc['specialty_name'] ?? 'General') ?></p>
            <p style="font-size: 12px; color: var(--text-muted);"><?= sanitizeOutput($doc['qualification'] ?? '') ?></p>
            
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
            
            <div class="d-flex gap-8 justify-center">
                <span class="badge badge-<?= $doc['is_available'] ? 'success' : 'danger' ?>">
                    <?= $doc['is_available'] ? 'Available' : 'Unavailable' ?>
                </span>
                <?php if ($doc['department_name']): ?>
                <span class="badge badge-secondary"><?= sanitizeOutput($doc['department_name']) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-footer d-flex justify-center gap-8">
            <a href="<?= BASE_URL ?>/modules/appointments/list.php?doctor=<?= $doc['id'] ?>" class="btn btn-sm btn-outline"><i class="fas fa-calendar"></i> Appointments</a>
            <?php if (hasPermission('doctors.edit')): ?>
            <a href="<?= BASE_URL ?>/modules/doctors/add.php?id=<?= $doc['id'] ?>" class="btn btn-sm btn-ghost"><i class="fas fa-pen"></i></a>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; endif; ?>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
