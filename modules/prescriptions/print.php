<?php
/**
 * Prescription Print View
 * Feature Gen Care
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

// Resolve clinic logo
$rxLogoUrl = '';
$rxLogo = $clinic['logo'] ?? '';
if (!empty($rxLogo)) {
    $rxClean = ltrim($rxLogo, '/');
    $rxUrl = (strpos($rxClean, 'clinics/') === 0) ? (UPLOADS_URL . '/' . $rxClean) : (UPLOADS_URL . '/clinics/' . $rxClean);
    $rxPath = (strpos($rxClean, 'clinics/') === 0) ? (UPLOADS_PATH . '/' . $rxClean) : (UPLOADS_PATH . '/clinics/' . $rxClean);
    if (file_exists($rxPath)) {
        $rxLogoUrl = $rxUrl;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prescription #<?= $rxId ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #0e7490;
            --primary-dark: #155e75;
            --primary-light: #cffafe;
            --accent: #0891b2;
            --text: #1e293b;
            --text-muted: #64748b;
            --text-light: #94a3b8;
            --border: #e2e8f0;
            --bg-soft: #f8fafc;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f1f5f9; font-family: 'Inter', system-ui, -apple-system, sans-serif; color: var(--text); font-size: 13px; line-height: 1.5; }

        .print-page {
            max-width: 800px;
            margin: 20px auto;
            background: white;
            box-shadow: 0 4px 24px rgba(0,0,0,0.08);
            border-radius: 8px;
            overflow: hidden;
        }

        @media print {
            body { background: white; }
            .no-print { display: none !important; }
            .print-page { margin: 0; box-shadow: none; border-radius: 0; max-width: 100%; }
            body { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        }

        /* ===== LETTERHEAD ===== */
        .letterhead {
            position: relative;
            overflow: hidden;
        }
        .letterhead-band {
            height: 6px;
            background: linear-gradient(90deg, var(--primary-dark), var(--accent), #06b6d4);
        }
        .letterhead-content {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 20px 36px 18px;
            border-bottom: 2px solid var(--primary);
        }
        .letterhead-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .clinic-logo {
            width: 60px;
            height: 60px;
            object-fit: contain;
            border-radius: 8px;
        }
        .clinic-logo-placeholder {
            width: 56px;
            height: 56px;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 22px;
            font-weight: 800;
            flex-shrink: 0;
        }
        .clinic-info h1 {
            font-size: 20px;
            font-weight: 800;
            color: var(--primary-dark);
            letter-spacing: -0.3px;
            margin-bottom: 2px;
        }
        .clinic-info .tagline {
            font-size: 11px;
            color: var(--text-light);
            font-weight: 500;
            margin-bottom: 4px;
        }
        .clinic-info .contact-row {
            font-size: 11px;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .clinic-info .contact-row i {
            font-size: 9px;
            color: var(--accent);
            margin-right: 3px;
        }
        .letterhead-right {
            text-align: right;
            flex-shrink: 0;
        }
        .doctor-name {
            font-size: 16px;
            font-weight: 700;
            color: var(--primary-dark);
            margin-bottom: 3px;
        }
        .doctor-meta {
            font-size: 11.5px;
            color: var(--text-muted);
            line-height: 1.6;
        }
        .doctor-meta .reg-badge {
            display: inline-block;
            background: var(--primary-light);
            color: var(--primary-dark);
            padding: 1px 7px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 600;
            margin-top: 3px;
        }

        /* ===== PATIENT INFO BAR ===== */
        .patient-bar {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 14px 36px;
            background: var(--bg-soft);
            border-bottom: 1px solid var(--border);
        }
        .patient-bar .info-group {
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
        }
        .patient-bar .info-item {
            display: flex;
            align-items: baseline;
            gap: 5px;
        }
        .patient-bar .info-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-light);
            font-weight: 600;
        }
        .patient-bar .info-value {
            font-size: 13px;
            font-weight: 600;
            color: var(--text);
        }
        .rx-date-badge {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .rx-date-badge .date-item {
            text-align: right;
        }
        .rx-date-badge .date-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-light);
            font-weight: 600;
        }
        .rx-date-badge .date-value {
            font-size: 13px;
            font-weight: 700;
            color: var(--text);
        }

        /* ===== BODY CONTENT ===== */
        .print-body {
            padding: 24px 36px 20px;
        }

        .rx-section { margin-bottom: 24px; }
        .rx-title {
            font-size: 13px;
            font-weight: 700;
            color: var(--primary-dark);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding-bottom: 6px;
            margin-bottom: 12px;
            border-bottom: 1.5px solid var(--border);
            display: flex;
            align-items: center;
            gap: 7px;
        }
        .rx-title i { font-size: 12px; color: var(--accent); }

        .clinical-info {
            display: grid;
            grid-template-columns: 1fr;
            gap: 6px;
            margin-bottom: 24px;
        }
        .clinical-row {
            display: flex;
            gap: 8px;
            font-size: 13px;
            line-height: 1.6;
        }
        .clinical-row .label {
            font-weight: 700;
            color: var(--text);
            white-space: nowrap;
            min-width: 130px;
        }
        .clinical-row .value { color: #334155; }

        /* Medicine Table */
        .med-table { width: 100%; border-collapse: collapse; margin-bottom: 0; }
        .med-table th {
            background: var(--bg-soft);
            color: var(--text-muted);
            font-weight: 700;
            font-size: 10.5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 8px 10px;
            text-align: left;
            border-bottom: 1.5px solid var(--border);
        }
        .med-table td {
            padding: 9px 10px;
            font-size: 12.5px;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
        }
        .med-table tr:last-child td { border-bottom: none; }
        .med-table .med-name { font-weight: 700; color: var(--text); }
        .med-table .med-idx {
            width: 28px;
            font-weight: 700;
            color: var(--text-light);
            font-size: 11px;
        }

        /* Tests */
        .test-list { list-style: none; padding: 0; }
        .test-item {
            padding: 7px 0;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
        }
        .test-item:last-child { border-bottom: none; }
        .test-name { font-weight: 600; color: var(--text); }
        .test-note { color: var(--text-muted); font-size: 12px; }

        /* Advice box */
        .advice-box {
            background: var(--bg-soft);
            border-left: 3px solid var(--accent);
            padding: 12px 16px;
            border-radius: 0 6px 6px 0;
            font-size: 13px;
            color: #334155;
            line-height: 1.6;
        }
        .advice-box .advice-label {
            font-weight: 700;
            color: var(--primary-dark);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 4px;
        }

        /* Follow up */
        .followup-bar {
            text-align: center;
            padding: 10px;
            background: var(--primary-light);
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            color: var(--primary-dark);
            margin-bottom: 24px;
        }
        .followup-bar i { margin-right: 5px; }

        /* Signature */
        .signature-area {
            margin-top: 50px;
            display: flex;
            justify-content: flex-end;
            padding-bottom: 10px;
        }
        .signature-block {
            text-align: center;
            min-width: 200px;
        }
        .signature-line {
            border-top: 1.5px solid var(--text-muted);
            padding-top: 6px;
        }
        .signature-name {
            font-weight: 700;
            font-size: 13px;
            color: var(--text);
        }
        .signature-title {
            font-size: 11px;
            color: var(--text-muted);
        }

        /* Footer band */
        .footer-band {
            height: 4px;
            background: linear-gradient(90deg, var(--primary-dark), var(--accent), #06b6d4);
            margin-top: 20px;
        }
    </style>
</head>
<body onload="requestAnimationFrame(() => window.print())">

    <!-- Actions (Hidden in Print) -->
    <div class="no-print" style="max-width: 800px; margin: 16px auto 0; text-align: right; display: flex; justify-content: flex-end; gap: 8px;">
        <button onclick="window.print()" style="padding: 8px 20px; background: var(--primary); color: white; border: none; border-radius: 6px; font-weight: 600; font-size: 13px; cursor: pointer; display: flex; align-items: center; gap: 6px;">
            <i class="fas fa-print"></i> Print
        </button>
        <button onclick="window.close()" style="padding: 8px 20px; background: white; color: var(--text-muted); border: 1px solid var(--border); border-radius: 6px; font-weight: 600; font-size: 13px; cursor: pointer;">
            Close
        </button>
    </div>

    <div class="print-page">
        <!-- ===== LETTERHEAD ===== -->
        <div class="letterhead">
            <div class="letterhead-band"></div>
            <div class="letterhead-content">
                <div class="letterhead-left">
                    <?php if (!empty($rxLogoUrl)): ?>
                        <img src="<?= $rxLogoUrl ?>" alt="<?= sanitizeOutput($clinic['name'] ?? APP_NAME) ?>" class="clinic-logo" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex'">
                        <div class="clinic-logo-placeholder" style="display: none;">
                            <?= strtoupper(substr($clinic['name'] ?? 'C', 0, 1)) ?>
                        </div>
                    <?php else: ?>
                        <div class="clinic-logo-placeholder">
                            <?= strtoupper(substr($clinic['name'] ?? 'C', 0, 1)) ?>
                        </div>
                    <?php endif; ?>
                    <div class="clinic-info">
                        <h1><?= sanitizeOutput($clinic['name'] ?? APP_NAME) ?></h1>
                        <?php if (!empty($clinic['tagline'])): ?>
                            <div class="tagline"><?= sanitizeOutput($clinic['tagline']) ?></div>
                        <?php endif; ?>
                        <div class="contact-row">
                            <?php if (!empty($clinic['address'])): ?>
                                <span><i class="fas fa-map-marker-alt"></i><?= sanitizeOutput($clinic['address']) ?><?php if (!empty($clinic['city'])) echo ', ' . sanitizeOutput($clinic['city']); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="contact-row" style="margin-top: 2px;">
                            <?php if (!empty($clinic['phone'])): ?>
                                <span><i class="fas fa-phone-alt"></i><?= sanitizeOutput($clinic['phone']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($clinic['email'])): ?>
                                <span><i class="fas fa-envelope"></i><?= sanitizeOutput($clinic['email']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($clinic['website'])): ?>
                                <span><i class="fas fa-globe"></i><?= sanitizeOutput($clinic['website']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="letterhead-right">
                    <div class="doctor-name">Dr. <?= sanitizeOutput($rx['doctor_name']) ?></div>
                    <div class="doctor-meta">
                        <?php if (!empty($rx['qualification'])): ?>
                            <?= sanitizeOutput($rx['qualification']) ?><br>
                        <?php endif; ?>
                        <?php if (!empty($rx['specialty'])): ?>
                            <?= sanitizeOutput($rx['specialty']) ?><br>
                        <?php endif; ?>
                        <?php if (!empty($rx['registration_number'])): ?>
                            <span class="reg-badge">Reg: <?= sanitizeOutput($rx['registration_number']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== PATIENT INFO BAR ===== -->
        <table style="width: 100%; border-collapse: collapse; background: var(--bg-soft); border-bottom: 1px solid var(--border);" cellpadding="0" cellspacing="0">
            <tr>
                <td style="padding: 14px 36px;">
                    <table style="border-collapse: collapse; font-size: 13px;" cellpadding="0" cellspacing="0">
                        <tr>
                            <td style="padding-right: 24px;">
                                <span style="font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-light); font-weight: 600;">Patient</span>
                                <span style="font-weight: 600; color: var(--text); margin-left: 5px;"><?= sanitizeOutput($rx['first_name'] . ' ' . $rx['last_name']) ?></span>
                            </td>
                            <td style="padding-right: 24px;">
                                <span style="font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-light); font-weight: 600;">ID</span>
                                <span style="font-weight: 600; color: var(--text); margin-left: 5px;"><?= sanitizeOutput($rx['patient_uid']) ?></span>
                            </td>
                            <td style="padding-right: 24px;">
                                <span style="font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-light); font-weight: 600;">Age/Sex</span>
                                <span style="font-weight: 600; color: var(--text); margin-left: 5px;"><?= $patientAge ?> / <?= sanitizeOutput($rx['gender'] ?? '-') ?></span>
                            </td>
                            <td style="padding-right: 24px;">
                                <span style="font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-light); font-weight: 600;">Mobile</span>
                                <span style="font-weight: 600; color: var(--text); margin-left: 5px;"><?= sanitizeOutput($rx['patient_phone']) ?></span>
                            </td>
                            <?php if (!empty($rx['blood_group'])): ?>
                            <td>
                                <span style="font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-light); font-weight: 600;">Blood</span>
                                <span style="font-weight: 600; color: #dc2626; margin-left: 5px;"><?= sanitizeOutput($rx['blood_group']) ?></span>
                            </td>
                            <?php endif; ?>
                        </tr>
                    </table>
                </td>
                <td style="padding: 14px 36px; text-align: right; white-space: nowrap;">
                    <table style="border-collapse: collapse; font-size: 13px; margin-left: auto;" cellpadding="0" cellspacing="0">
                        <tr>
                            <td style="padding-right: 20px; text-align: right;">
                                <div style="font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-light); font-weight: 600;">Date</div>
                                <div style="font-weight: 700; color: var(--text);"><?= formatDate($rx['prescription_date']) ?></div>
                            </td>
                            <td style="text-align: right;">
                                <div style="font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-light); font-weight: 600;">Rx ID</div>
                                <div style="font-weight: 700; color: var(--text);">#<?= $rxId ?></div>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <!-- ===== BODY ===== -->
        <div class="print-body">

            <!-- Clinical Info -->
            <?php if ($rx['chief_complaints'] || $rx['diagnosis'] || $rx['examination_findings']): ?>
            <div class="clinical-info">
                <?php if ($rx['chief_complaints']): ?>
                <div class="clinical-row">
                    <span class="label">Chief Complaints:</span>
                    <span class="value"><?= sanitizeOutput($rx['chief_complaints']) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($rx['diagnosis']): ?>
                <div class="clinical-row">
                    <span class="label">Diagnosis:</span>
                    <span class="value"><?= sanitizeOutput($rx['diagnosis']) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($rx['examination_findings']): ?>
                <div class="clinical-row">
                    <span class="label">O/E:</span>
                    <span class="value"><?= sanitizeOutput($rx['examination_findings']) ?></span>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Medicines -->
            <?php if (!empty($medicines)): ?>
            <div class="rx-section">
                <div class="rx-title"><i class="fas fa-prescription-bottle-alt"></i> Rx — Medicines</div>
                <table class="med-table">
                    <thead>
                        <tr>
                            <th class="med-idx">#</th>
                            <th>Medicine</th>
                            <th>Dosage</th>
                            <th>Frequency</th>
                            <th>Duration</th>
                            <th>Instructions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($medicines as $i => $med): ?>
                    <tr>
                        <td class="med-idx"><?= $i + 1 ?></td>
                        <td class="med-name"><?= sanitizeOutput($med['medicine_name']) ?></td>
                        <td><?= sanitizeOutput($med['dosage'] ?? '-') ?></td>
                        <td><?= sanitizeOutput($med['frequency']) ?></td>
                        <td><?= sanitizeOutput($med['duration'] ?? '-') ?></td>
                        <td style="color: var(--text-muted); font-size: 11.5px;"><?= sanitizeOutput($med['instructions'] ?? '-') ?></td>
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
                <ul class="test-list">
                    <?php foreach ($tests as $j => $t): ?>
                    <li class="test-item">
                        <span class="test-name"><?= ($j + 1) ?>. <?= sanitizeOutput($t['test_name']) ?></span>
                        <span class="test-note"><?= sanitizeOutput($t['instructions'] ?? '') ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <!-- Advice -->
            <?php if ($rx['advice']): ?>
            <div class="rx-section">
                <div class="advice-box">
                    <div class="advice-label"><i class="fas fa-comment-medical" style="margin-right: 4px;"></i> Advice</div>
                    <?= nl2br(sanitizeOutput($rx['advice'])) ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Follow Up -->
            <?php if ($rx['follow_up_date']): ?>
            <div class="rx-section">
                <div class="followup-bar">
                    <i class="fas fa-calendar-check"></i> Follow-up: <?= formatDate($rx['follow_up_date']) ?>
                    <?php if ($rx['follow_up_notes']): ?>
                        <span style="font-weight: 400; font-size: 12px; margin-left: 8px;">(<?= sanitizeOutput($rx['follow_up_notes']) ?>)</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Signature -->
            <div class="signature-area">
                <div class="signature-block">
                    <div class="signature-line">
                        <div class="signature-name">Dr. <?= sanitizeOutput($rx['doctor_name']) ?></div>
                        <div class="signature-title">
                            <?= sanitizeOutput($rx['qualification'] ?? '') ?>
                            <?php if (!empty($rx['specialty'])): ?> • <?= sanitizeOutput($rx['specialty']) ?><?php endif; ?>
                        </div>
                        <?php if (!empty($rx['registration_number'])): ?>
                        <div class="signature-title">Reg: <?= sanitizeOutput($rx['registration_number']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer Band -->
        <div class="footer-band"></div>
    </div>

</body>
</html>
