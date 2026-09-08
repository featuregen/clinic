<?php
/**
 * Patient Profile View - Advanced Clinic Suite
 */
require_once dirname(dirname(__DIR__)) . '/config/session.php';
requirePermission('patients.view');

$db = db();
$clinicId = getCurrentClinicId();
$patientId = intval($_GET['id'] ?? 0);

$patient = $db->fetch("SELECT * FROM patients WHERE id = ? AND clinic_id = ?", [$patientId, $clinicId]);
if (!$patient) {
    setFlashMessage('error', 'Patient not found.');
    header('Location: ' . BASE_URL . '/modules/patients/list.php');
    exit;
}

$pageTitle = $patient['first_name'] . ' ' . ($patient['last_name'] ?? '');
require_once dirname(dirname(__DIR__)) . '/includes/header.php';

// Fetch related data
$appointments = $db->fetchAll(
    "SELECT a.*, u.full_name as doctor_name FROM appointments a 
     JOIN doctors d ON a.doctor_id = d.id JOIN users u ON d.user_id = u.id
     WHERE a.patient_id = ? ORDER BY a.appointment_date DESC, a.appointment_time DESC LIMIT 20", [$patientId]
);

$prescriptions = $db->fetchAll(
    "SELECT pr.*, u.full_name as doctor_name FROM prescriptions pr
     JOIN doctors d ON pr.doctor_id = d.id JOIN users u ON d.user_id = u.id
     WHERE pr.patient_id = ? ORDER BY pr.prescription_date DESC LIMIT 20", [$patientId]
);

$invoices = $db->fetchAll(
    "SELECT * FROM invoices WHERE patient_id = ? ORDER BY invoice_date DESC LIMIT 20", [$patientId]
);

$medicalHistory = $db->fetchAll("SELECT * FROM patient_medical_history WHERE patient_id = ? ORDER BY created_at DESC", [$patientId]);
$allergies = $db->fetchAll("SELECT * FROM patient_allergies WHERE patient_id = ? ORDER BY severity DESC", [$patientId]);
$vitals = $db->fetchAll("SELECT * FROM patient_vitals WHERE patient_id = ? ORDER BY recorded_at DESC LIMIT 10", [$patientId]);
$documents = $db->fetchAll("SELECT * FROM patient_documents WHERE patient_id = ? ORDER BY created_at DESC", [$patientId]);

$age = $patient['date_of_birth'] ? calculateAge($patient['date_of_birth']) : ($patient['age'] ?? '-');
?>

<div class="content-header">
    <div>
        <ul class="breadcrumb">
            <li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li>
            <li><a href="<?= BASE_URL ?>/modules/patients/list.php">Patients</a></li>
            <li><?= sanitizeOutput($pageTitle) ?></li>
        </ul>
        <h1><?= sanitizeOutput($pageTitle) ?></h1>
    </div>
    <div class="d-flex gap-8">
        <a href="<?= BASE_URL ?>/modules/appointments/book.php?patient_id=<?= $patientId ?>" class="btn btn-primary btn-sm">
            <i class="fas fa-calendar-plus"></i> Book Appointment
        </a>

        <a href="<?= BASE_URL ?>/modules/dental/chart.php?patient_id=<?= $patientId ?>" class="btn btn-outline btn-sm">
            <i class="fas fa-tooth"></i> Dental Chart
        </a>
        <a href="<?= BASE_URL ?>/modules/patients/add.php?id=<?= $patientId ?>" class="btn btn-outline btn-sm">
            <i class="fas fa-pen"></i> Edit
        </a>
    </div>
</div>

<!-- Patient Summary Card -->
<div class="card mb-24">
    <div class="card-body">
        <div class="d-flex gap-20 align-center flex-wrap">
            <div class="user-avatar" style="width: 72px; height: 72px; font-size: 24px;">
                <?= getInitials($patient['first_name'] . ' ' . ($patient['last_name'] ?? '')) ?>
            </div>
            <div style="flex: 1;">
                <h2 style="margin-bottom: 4px;"><?= sanitizeOutput($pageTitle) ?></h2>
                <div class="d-flex gap-16 flex-wrap" style="color: var(--text-secondary); font-size: 13px;">
                    <span><i class="fas fa-id-card"></i> <?= sanitizeOutput($patient['patient_uid']) ?></span>
                    <span><i class="fas fa-phone"></i> <?= sanitizeOutput($patient['phone']) ?></span>
                    <?php if ($patient['email']): ?><span><i class="fas fa-envelope"></i> <?= sanitizeOutput($patient['email']) ?></span><?php endif; ?>
                    <?php if ($patient['gender']): ?><span><i class="fas fa-venus-mars"></i> <?= $patient['gender'] ?></span><?php endif; ?>
                    <span><i class="fas fa-birthday-cake"></i> Age: <?= $age ?></span>
                    <?php if ($patient['blood_group']): ?><span class="badge badge-danger"><?= $patient['blood_group'] ?></span><?php endif; ?>
                </div>
            </div>
            <div class="grid-3 gap-16" style="min-width: 360px;">
                <div class="text-center">
                    <div class="stat-value" style="font-size: 1.25rem;"><?= count($appointments) ?></div>
                    <div class="text-muted" style="font-size: 11px;">Visits</div>
                </div>
                <div class="text-center">
                    <div class="stat-value" style="font-size: 1.25rem;"><?= count($prescriptions) ?></div>
                    <div class="text-muted" style="font-size: 11px;">Prescriptions</div>
                </div>
                <div class="text-center">
                    <div class="stat-value" style="font-size: 1.25rem;"><?= count($invoices) ?></div>
                    <div class="text-muted" style="font-size: 11px;">Invoices</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Tabs -->
<div class="card">
    <div class="card-header" style="padding-bottom: 0; border-bottom: none;">
        <div class="tabs" style="margin-bottom: 0; border-bottom: 2px solid var(--border-color); width: 100%;">
            <button class="tab-btn active" data-tab="tab-visits">Visits</button>
            <button class="tab-btn" data-tab="tab-prescriptions">Prescriptions</button>
            <button class="tab-btn" data-tab="tab-billing">Billing</button>
            <button class="tab-btn" data-tab="tab-medical">Medical History</button>
            <button class="tab-btn" data-tab="tab-vitals">Vitals</button>
            <button class="tab-btn" data-tab="tab-documents">Documents</button>
        </div>
    </div>
    <div class="card-body">
        <!-- Visits Tab -->
        <div class="tab-content active" id="tab-visits">
            <?php if (empty($appointments)): ?>
            <div class="empty-state"><i class="fas fa-calendar-times"></i><h3>No visits recorded</h3></div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Date</th><th>Time</th><th>Doctor</th><th>Type</th><th>Status</th><th>Reason</th></tr></thead>
                    <tbody>
                    <?php foreach ($appointments as $apt): ?>
                    <tr>
                        <td><?= formatDate($apt['appointment_date']) ?></td>
                        <td><?= formatTime($apt['appointment_time']) ?></td>
                        <td><?= sanitizeOutput($apt['doctor_name']) ?></td>
                        <td><span class="badge badge-secondary"><?= ucfirst($apt['appointment_type']) ?></span></td>
                        <td><?= getStatusBadge($apt['status']) ?></td>
                        <td><?= sanitizeOutput(truncateText($apt['visit_reason'] ?? '-', 40)) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Prescriptions Tab -->
        <div class="tab-content" id="tab-prescriptions">
            <?php if (empty($prescriptions)): ?>
            <div class="empty-state"><i class="fas fa-file-prescription"></i><h3>No prescriptions</h3></div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Date</th><th>Doctor</th><th>Diagnosis</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($prescriptions as $rx): ?>
                    <tr>
                        <td><?= formatDate($rx['prescription_date']) ?></td>
                        <td><?= sanitizeOutput($rx['doctor_name']) ?></td>
                        <td><?= sanitizeOutput(truncateText($rx['diagnosis'] ?? '-', 40)) ?></td>
                        <td><?= getStatusBadge($rx['status'], 'payment') ?></td>
                        <td>
                            <a href="<?= BASE_URL ?>/modules/prescriptions/view.php?id=<?= $rx['id'] ?>" class="btn btn-sm btn-ghost"><i class="fas fa-eye"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Billing Tab -->
        <div class="tab-content" id="tab-billing">
            <?php if (empty($invoices)): ?>
            <div class="empty-state"><i class="fas fa-file-invoice"></i><h3>No invoices</h3></div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Invoice #</th><th>Date</th><th>Total</th><th>Paid</th><th>Due</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($invoices as $inv): ?>
                    <tr>
                        <td class="font-semibold"><?= sanitizeOutput($inv['invoice_number']) ?></td>
                        <td><?= formatDate($inv['invoice_date']) ?></td>
                        <td><?= formatCurrency($inv['total_amount']) ?></td>
                        <td class="text-success"><?= formatCurrency($inv['paid_amount']) ?></td>
                        <td class="text-danger font-semibold"><?= formatCurrency($inv['due_amount']) ?></td>
                        <td><?= getStatusBadge($inv['status'], 'payment') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Medical History Tab -->
        <div class="tab-content" id="tab-medical">
            <div class="grid-2 gap-24">
                <div>
                    <h4 class="mb-16"><i class="fas fa-notes-medical" style="color: var(--primary);"></i> Medical Conditions</h4>
                    <?php if (empty($medicalHistory)): ?>
                    <p class="text-muted">No medical history recorded.</p>
                    <?php else: foreach ($medicalHistory as $mh): ?>
                    <div class="alert alert-<?= $mh['status'] === 'active' ? 'warning' : 'info' ?>">
                        <div>
                            <strong><?= sanitizeOutput($mh['condition_name']) ?></strong>
                            <span class="badge badge-<?= $mh['condition_type'] === 'chronic' ? 'danger' : 'secondary' ?>" style="margin-left: 8px;"><?= ucfirst($mh['condition_type']) ?></span>
                            <?php if ($mh['notes']): ?><div style="font-size: 12px; margin-top: 4px;"><?= sanitizeOutput($mh['notes']) ?></div><?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
                <div>
                    <h4 class="mb-16"><i class="fas fa-allergies" style="color: var(--danger);"></i> Allergies</h4>
                    <?php if (empty($allergies)): ?>
                    <p class="text-muted">No allergies recorded.</p>
                    <?php else: foreach ($allergies as $al): ?>
                    <div class="alert alert-danger">
                        <div>
                            <strong><?= sanitizeOutput($al['allergen']) ?></strong>
                            <span class="badge badge-<?= $al['severity'] === 'severe' ? 'danger' : ($al['severity'] === 'moderate' ? 'warning' : 'info') ?>"><?= ucfirst($al['severity']) ?></span>
                            <?php if ($al['reaction']): ?><div style="font-size: 12px; margin-top: 4px;">Reaction: <?= sanitizeOutput($al['reaction']) ?></div><?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Vitals Tab -->
        <div class="tab-content" id="tab-vitals">
            <?php if (empty($vitals)): ?>
            <div class="empty-state"><i class="fas fa-heartbeat"></i><h3>No vitals recorded</h3></div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Date</th><th>Weight</th><th>Height</th><th>BMI</th><th>BP</th><th>Pulse</th><th>Temp</th><th>SpO2</th></tr></thead>
                    <tbody>
                    <?php foreach ($vitals as $v): ?>
                    <tr>
                        <td><?= formatDateTime($v['recorded_at']) ?></td>
                        <td><?= $v['weight'] ? $v['weight'] . ' kg' : '-' ?></td>
                        <td><?= $v['height'] ? $v['height'] . ' cm' : '-' ?></td>
                        <td><?= $v['bmi'] ?? '-' ?></td>
                        <td><?= $v['blood_pressure_systolic'] ? $v['blood_pressure_systolic'] . '/' . $v['blood_pressure_diastolic'] : '-' ?></td>
                        <td><?= $v['pulse_rate'] ? $v['pulse_rate'] . ' bpm' : '-' ?></td>
                        <td><?= $v['temperature'] ? $v['temperature'] . '°F' : '-' ?></td>
                        <td><?= $v['spo2'] ? $v['spo2'] . '%' : '-' ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Documents Tab -->
        <div class="tab-content" id="tab-documents">
            <?php if (empty($documents)): ?>
            <div class="empty-state"><i class="fas fa-folder-open"></i><h3>No documents uploaded</h3></div>
            <?php else: ?>
            <div class="grid-3 gap-16">
                <?php foreach ($documents as $doc): ?>
                <div class="card">
                    <div class="card-body text-center">
                        <i class="fas fa-file-<?= in_array($doc['file_type'], ['pdf']) ? 'pdf' : 'image' ?>" style="font-size: 36px; color: var(--primary); margin-bottom: 8px;"></i>
                        <div class="font-semibold"><?= sanitizeOutput($doc['title']) ?></div>
                        <div class="text-muted" style="font-size: 11px;"><?= ucfirst(str_replace('_', ' ', $doc['document_type'])) ?></div>
                        <div class="text-muted" style="font-size: 11px;"><?= formatDate($doc['created_at']) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
