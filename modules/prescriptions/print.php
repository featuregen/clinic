<?php
/**
 * Prescription Print View
 * Advanced Clinic Suite
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
    die("Prescription not found.");
}

$medicines = $db->fetchAll("SELECT * FROM prescription_medicines WHERE prescription_id = ?", [$rxId]);
$tests = $db->fetchAll("SELECT * FROM prescription_tests WHERE prescription_id = ?", [$rxId]);
$clinic = $db->fetch("SELECT * FROM clinics WHERE id = ?", [$clinicId]);
$patientAge = $rx['date_of_birth'] ? calculateAge($rx['date_of_birth']) : ($rx['age'] ?? '-');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prescription #<?= $rxId ?></title>
    <link href="<?= BASE_URL ?>/assets/css/style.css?v=<?= filemtime(dirname(dirname(__DIR__)) . '/assets/css/style.css') ?>" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: white; padding: 0; margin: 0; font-family: 'Inter', sans-serif; color: #333; }
        .print-container { max-width: 800px; margin: 0 auto; padding: 40px; }
        @media print {
            body { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
            .no-print { display: none !important; }
            .print-container { padding: 0; max-width: 100%; }
        }
        .header-line { border-bottom: 2px solid var(--primary); padding-bottom: 20px; margin-bottom: 30px; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 32px; margin-bottom: 30px; }
        .rx-section { margin-bottom: 30px; }
        .rx-title { color: var(--primary); font-size: 16px; font-weight: 700; border-bottom: 1px solid #eee; padding-bottom: 8px; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
        .table th { background: #f8fafc; color: #64748b; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; padding: 12px; }
        .table td { border-bottom: 1px solid #f1f5f9; padding: 12px; font-size: 14px; }
    </style>
</head>
<body onload="requestAnimationFrame(() => window.print())">

    <div class="print-container">
        <!-- Actions (Hidden in Print) -->
        <div class="no-print" style="margin-bottom: 20px; text-align: right;">
            <button onclick="window.print()" class="btn btn-primary"><i class="fas fa-print"></i> Print Details</button>
            <button onclick="window.close()" class="btn btn-outline">Close</button>
        </div>

        <!-- Header -->
        <div class="header-line text-center">
            <?php if (!empty($clinic['logo'])): ?>
                <img src="<?= UPLOADS_URL ?>/clinics/<?= sanitizeOutput($clinic['logo']) ?>" alt="<?= sanitizeOutput($clinic['name'] ?? APP_NAME) ?>" style="max-height: 70px; margin-bottom: 8px; object-fit: contain; display: block; margin-left: auto; margin-right: auto;">
            <?php endif; ?>
            <h1 style="color: var(--primary); margin: 0 0 4px 0; font-size: <?= !empty($clinic['logo']) ? '20px' : '28px' ?>;">
                <?= sanitizeOutput($clinic['name'] ?? APP_NAME) ?>
            </h1>
            <?php if (!empty($clinic['tagline'])): ?>
                <div style="color: #94a3b8; font-size: 12px; margin-bottom: 4px;"><?= sanitizeOutput($clinic['tagline']) ?></div>
            <?php endif; ?>
            <div style="color: #64748b; font-size: 13px;">
                <?php if (!empty($clinic['address'])): ?><?= sanitizeOutput($clinic['address']) ?><?php if (!empty($clinic['city'])) echo ', ' . sanitizeOutput($clinic['city']); ?><?php endif; ?>
                <div style="margin-top: 3px;">
                    <?php if (!empty($clinic['phone'])): ?><i class="fas fa-phone-alt" style="font-size: 11px; margin-right: 3px;"></i> <?= sanitizeOutput($clinic['phone']) ?><?php endif; ?>
                    <?php if (!empty($clinic['email'])): ?><span style="margin: 0 8px;">|</span><i class="fas fa-envelope" style="font-size: 11px; margin-right: 3px;"></i> <?= sanitizeOutput($clinic['email']) ?><?php endif; ?>
                </div>
            </div>
        </div>


        <!-- Patient & Doctor Info -->
        <div class="info-grid">
            <div>
                <table style="width: 100%; border-collapse: separate; border-spacing: 0 8px;">
                    <tr><td style="color: #64748b; width: 80px;">Patient:</td><td><strong><?= sanitizeOutput($rx['first_name'] . ' ' . $rx['last_name']) ?></strong></td></tr>
                    <tr><td style="color: #64748b;">ID:</td><td><?= sanitizeOutput($rx['patient_uid']) ?></td></tr>
                    <tr><td style="color: #64748b;">Age/Sex:</td><td><?= $patientAge ?> / <?= sanitizeOutput($rx['gender'] ?? '-') ?></td></tr>
                    <tr><td style="color: #64748b;">Mobile:</td><td><?= sanitizeOutput($rx['patient_phone']) ?></td></tr>
                </table>
            </div>
            <div style="text-align: right;">
                <h3 style="margin: 0 0 4px 0; color: var(--primary);">Dr. <?= sanitizeOutput($rx['doctor_name']) ?></h3>
                <div style="color: #64748b; font-size: 14px;"><?= sanitizeOutput($rx['qualification'] ?? '') ?></div>
                <div style="color: #64748b; font-size: 14px;"><?= sanitizeOutput($rx['specialty'] ?? '') ?></div>
                <div style="color: #64748b; font-size: 14px;">Reg: <?= sanitizeOutput($rx['registration_number'] ?? '') ?></div>
            </div>
        </div>

        <div style="display: flex; justify-content: space-between; border-top: 1px dashed #e2e8f0; border-bottom: 1px dashed #e2e8f0; padding: 12px 0; margin-bottom: 30px; font-size: 14px;">
            <div><strong>Date:</strong> <?= formatDate($rx['prescription_date']) ?></div>
            <div><strong>Rx ID:</strong> #<?= $rxId ?></div>
        </div>

        <!-- Vitals & Complaints -->
        <?php if ($rx['chief_complaints'] || $rx['diagnosis'] || $rx['examination_findings']): ?>
        <div class="rx-section">
            <?php if ($rx['chief_complaints']): ?>
            <div style="margin-bottom: 8px;"><strong>Chief Complaints:</strong> <?= sanitizeOutput($rx['chief_complaints']) ?></div>
            <?php endif; ?>
            <?php if ($rx['diagnosis']): ?>
            <div style="margin-bottom: 8px;"><strong>Diagnosis:</strong> <?= sanitizeOutput($rx['diagnosis']) ?></div>
            <?php endif; ?>
            <?php if ($rx['examination_findings']): ?>
            <div style="margin-bottom: 8px;"><strong>O/E:</strong> <?= sanitizeOutput($rx['examination_findings']) ?></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Medicines -->
        <?php if (!empty($medicines)): ?>
        <div class="rx-section">
            <div class="rx-title"><i class="fas fa-pills"></i> Rx (Medicines)</div>
            <table class="table" style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr>
                        <th style="text-align: left;">Medicine</th>
                        <th style="text-align: left;">Dosage</th>
                        <th style="text-align: left;">Frequency</th>
                        <th style="text-align: left;">Duration</th>
                        <th style="text-align: left;">Instruction</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($medicines as $med): ?>
                <tr>
                    <td style="font-weight: 600;"><?= sanitizeOutput($med['medicine_name']) ?></td>
                    <td><?= sanitizeOutput($med['dosage'] ?? '-') ?></td>
                    <td><?= sanitizeOutput($med['frequency']) ?></td>
                    <td><?= sanitizeOutput($med['duration'] ?? '-') ?></td>
                    <td><?= sanitizeOutput($med['instructions'] ?? '-') ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Lab Tests -->
        <?php if (!empty($tests)): ?>
        <div class="rx-section">
            <div class="rx-title"><i class="fas fa-flask"></i> Lab Tests</div>
            <ul style="list-style: none; padding: 0;">
                <?php foreach ($tests as $t): ?>
                <li style="padding: 8px 0; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between;">
                    <strong><?= sanitizeOutput($t['test_name']) ?></strong>
                    <span style="color: #64748b;"><?= sanitizeOutput($t['instructions']) ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Advice -->
        <?php if ($rx['advice']): ?>
        <div class="rx-section" style="background: #f8fafc; padding: 16px; border-radius: 8px; border-left: 4px solid var(--primary);">
            <strong style="display: block; margin-bottom: 4px;">Advice:</strong>
            <?= nl2br(sanitizeOutput($rx['advice'])) ?>
        </div>
        <?php endif; ?>

        <!-- Follow Up -->
        <?php if ($rx['follow_up_date']): ?>
        <div class="rx-section" style="text-align: center; margin-top: 40px; font-weight: 600;">
            Follow up on: <?= formatDate($rx['follow_up_date']) ?>
            <?php if ($rx['follow_up_notes']): ?>
            <div style="font-weight: 400; font-size: 14px; margin-top: 4px;"><?= sanitizeOutput($rx['follow_up_notes']) ?></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Footer Signature -->
        <div style="margin-top: 80px; display: flex; justify-content: flex-end;">
            <div style="text-align: center;">
                <div style="width: 200px; border-top: 1px solid #ddd; padding-top: 8px;">
                    <strong>Dr. <?= sanitizeOutput($rx['doctor_name']) ?></strong>
                    <div style="font-size: 12px; color: #64748b;">Signature</div>
                </div>
            </div>
        </div>
    </div>

</body>
</html>
