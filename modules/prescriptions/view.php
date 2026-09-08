<?php
/**
 * Prescription View & Print - Advanced Clinic Suite
 */
require_once dirname(dirname(__DIR__)) . '/config/session.php';
requirePermission('prescriptions.view');

$db = db();
$clinicId = getCurrentClinicId();
$rxId = intval($_GET['id'] ?? 0);

$rx = $db->fetch(
    "SELECT pr.*, p.first_name, p.last_name, p.patient_uid, p.phone as patient_phone,
            p.gender, p.date_of_birth, p.age, p.blood_group, p.address, p.city,
            u.full_name as doctor_name, u.qualification, s.name as specialty,
            d.registration_number
     FROM prescriptions pr
     JOIN patients p ON pr.patient_id = p.id
     JOIN doctors d ON pr.doctor_id = d.id
     JOIN users u ON d.user_id = u.id
     LEFT JOIN specialties s ON d.specialty_id = s.id
     WHERE pr.id = ? AND pr.clinic_id = ?", [$rxId, $clinicId]
);

if (!$rx) {
    setFlashMessage('error', 'Prescription not found.');
    header('Location: ' . BASE_URL . '/modules/prescriptions/list.php');
    exit;
}

$pageTitle = 'Prescription #' . $rxId;
require_once dirname(dirname(__DIR__)) . '/includes/header.php';

$medicines = $db->fetchAll("SELECT * FROM prescription_medicines WHERE prescription_id = ?", [$rxId]);
$tests = $db->fetchAll("SELECT * FROM prescription_tests WHERE prescription_id = ?", [$rxId]);

$clinic = $db->fetch("SELECT * FROM clinics WHERE id = ?", [$clinicId]);
$patientAge = $rx['date_of_birth'] ? calculateAge($rx['date_of_birth']) : ($rx['age'] ?? '-');
?>

<div class="content-header">
    <div>
        <ul class="breadcrumb">
            <li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li>
            <li><a href="<?= BASE_URL ?>/modules/prescriptions/list.php">Prescriptions</a></li>
            <li>View</li>
        </ul>
        <h1>Prescription Details</h1>
    </div>
    <div class="d-flex gap-8">
        <a href="<?= BASE_URL ?>/modules/prescriptions/create.php?id=<?= $rxId ?>" class="btn btn-outline btn-sm"><i class="fas fa-pen"></i> Edit</a>
        <button class="btn btn-primary btn-sm" onclick="printContent('prescriptionPrint')"><i class="fas fa-print"></i> Print</button>
    </div>
</div>

<div class="card" id="prescriptionPrint">
    <div class="card-body" style="padding: 32px;">
        <!-- Header -->
        <div style="text-align: center; margin-bottom: 24px; padding-bottom: 16px; border-bottom: 2px solid var(--primary);">
            <h2 style="margin-bottom: 4px; color: var(--primary);"><?= sanitizeOutput($clinic['name'] ?? APP_NAME) ?></h2>
            <p style="color: var(--text-secondary); font-size: 13px;"><?= sanitizeOutput($clinic['address'] ?? '') ?></p>
            <p style="color: var(--text-secondary); font-size: 12px;">
                <?php if (!empty($clinic['phone'])): ?>Ph: <?= sanitizeOutput($clinic['phone']) ?><?php endif; ?>
                <?php if (!empty($clinic['email'])): ?> | <?= sanitizeOutput($clinic['email']) ?><?php endif; ?>
            </p>
        </div>
        
        <!-- Patient & Doctor Info -->
        <div class="grid-2 mb-24" style="gap: 24px;">
            <div>
                <h4 style="color: var(--primary); margin-bottom: 8px;">Patient</h4>
                <table style="font-size: 13px; line-height: 1.8;">
                    <tr><td style="color: var(--text-muted); width: 100px;">Name</td><td><strong><?= sanitizeOutput($rx['first_name'] . ' ' . $rx['last_name']) ?></strong></td></tr>
                    <tr><td style="color: var(--text-muted);">ID</td><td><?= sanitizeOutput($rx['patient_uid']) ?></td></tr>
                    <tr><td style="color: var(--text-muted);">Age/Sex</td><td><?= $patientAge ?> / <?= sanitizeOutput($rx['gender'] ?? '-') ?></td></tr>
                    <tr><td style="color: var(--text-muted);">Phone</td><td><?= sanitizeOutput($rx['patient_phone']) ?></td></tr>
                </table>
            </div>
            <div style="text-align: right;">
                <h4 style="color: var(--primary); margin-bottom: 8px;">Doctor</h4>
                <table style="font-size: 13px; line-height: 1.8; margin-left: auto;">
                    <tr><td style="text-align: right;"><strong>Dr. <?= sanitizeOutput($rx['doctor_name']) ?></strong></td></tr>
                    <tr><td style="text-align: right; color: var(--text-muted);"><?= sanitizeOutput($rx['specialty'] ?? '') ?></td></tr>
                    <tr><td style="text-align: right; color: var(--text-muted);"><?= sanitizeOutput($rx['qualification'] ?? '') ?></td></tr>
                    <tr><td style="text-align: right; color: var(--text-muted);">Reg: <?= sanitizeOutput($rx['registration_number'] ?? '') ?></td></tr>
                </table>
            </div>
        </div>
        
        <div style="display: flex; justify-content: space-between; padding: 8px 12px; background: var(--bg-secondary); border-radius: 8px; margin-bottom: 20px; font-size: 13px;">
            <span><strong>Date:</strong> <?= formatDate($rx['prescription_date']) ?></span>
            <span><strong>Rx ID:</strong> #<?= $rxId ?></span>
        </div>
        
        <!-- Clinical -->
        <?php if ($rx['chief_complaints']): ?>
        <div class="mb-16"><span style="font-weight: 600; color: var(--text-primary);">Chief Complaints:</span> <span><?= sanitizeOutput($rx['chief_complaints']) ?></span></div>
        <?php endif; ?>
        
        <?php if ($rx['diagnosis']): ?>
        <div class="mb-16"><span style="font-weight: 600; color: var(--text-primary);">Diagnosis:</span> <span><?= sanitizeOutput($rx['diagnosis']) ?></span></div>
        <?php endif; ?>
        
        <?php if ($rx['examination_findings']): ?>
        <div class="mb-16"><span style="font-weight: 600; color: var(--text-primary);">Examination:</span> <span><?= sanitizeOutput($rx['examination_findings']) ?></span></div>
        <?php endif; ?>
        
        <!-- Medicines -->
        <?php if (!empty($medicines)): ?>
        <h4 style="color: var(--primary); margin: 20px 0 12px;"><i class="fas fa-prescription"></i> Rx - Medicines</h4>
        <table class="table" style="margin-bottom: 20px;">
            <thead>
                <tr><th>#</th><th>Medicine</th><th>Dosage</th><th>Frequency</th><th>Duration</th><th>Route</th><th>Instructions</th></tr>
            </thead>
            <tbody>
            <?php foreach ($medicines as $i => $med): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td class="font-semibold"><?= sanitizeOutput($med['medicine_name']) ?></td>
                <td><?= sanitizeOutput($med['dosage'] ?? '-') ?></td>
                <td><span class="badge badge-primary"><?= sanitizeOutput($med['frequency']) ?></span></td>
                <td><?= sanitizeOutput($med['duration'] ?? '-') ?></td>
                <td><?= ucfirst(sanitizeOutput($med['route'] ?? 'oral')) ?></td>
                <td><?= sanitizeOutput($med['instructions'] ?? '-') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        
        <!-- Lab Tests -->
        <?php if (!empty($tests)): ?>
        <h4 style="color: var(--warning); margin: 20px 0 12px;"><i class="fas fa-flask"></i> Lab Tests</h4>
        <table class="table" style="margin-bottom: 20px;">
            <thead><tr><th>#</th><th>Test</th><th>Instructions</th></tr></thead>
            <tbody>
            <?php foreach ($tests as $i => $test): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td class="font-semibold"><?= sanitizeOutput($test['test_name']) ?></td>
                <td><?= sanitizeOutput($test['instructions'] ?? '-') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        
        <!-- Advice -->
        <?php if ($rx['advice']): ?>
        <div style="background: #f0f9ff; border-left: 4px solid var(--info); padding: 12px 16px; border-radius: 0 8px 8px 0; margin: 20px 0;">
            <strong>Advice:</strong> <?= sanitizeOutput($rx['advice']) ?>
        </div>
        <?php endif; ?>
        
        <!-- Follow-up -->
        <?php if ($rx['follow_up_date']): ?>
        <div style="margin-top: 20px; text-align: center; padding: 12px; background: var(--bg-secondary); border-radius: 8px; font-size: 14px;">
            <i class="fas fa-calendar-alt" style="color: var(--primary);"></i>
            <strong>Follow-up:</strong> <?= formatDate($rx['follow_up_date']) ?>
            <?= $rx['follow_up_notes'] ? ' - ' . sanitizeOutput($rx['follow_up_notes']) : '' ?>
        </div>
        <?php endif; ?>
        
        <!-- Footer -->
        <div style="margin-top: 40px; display: flex; justify-content: space-between; align-items: flex-end;">
            <div style="font-size: 11px; color: var(--text-muted);">
                Generated by <?= APP_NAME ?> on <?= date('d M Y H:i') ?>
            </div>
            <div style="text-align: center;">
                <div style="width: 200px; border-top: 1px solid var(--gray-400); padding-top: 4px; font-size: 12px;">
                    Doctor's Signature
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
