<?php
/**
 * Vaccination Schedule - Feature Gen Care
 */
require_once dirname(dirname(__DIR__)) . '/config/session.php';
requirePermission('vaccination.view');

$db = db();
$clinicId = getCurrentClinicId();
$patientId = intval($_GET['patient_id'] ?? 0);
$canManage = hasPermission('vaccination.manage') || hasPermission('vaccination.record');

// Handle vaccination record or delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    $action = $_POST['action'] ?? 'record';
    
    if ($action === 'delete') {
        $recordId = intval($_POST['record_id'] ?? 0);
        $db->query("DELETE FROM patient_vaccinations WHERE id = ? AND patient_id = ?", [$recordId, $patientId]);
        logAudit('delete', 'vaccination', 'patient_vaccination', $recordId);
        setFlashMessage('success', 'Vaccination record deleted successfully.');
        header("Location: " . BASE_URL . "/modules/vaccination/schedule.php?patient_id=" . $patientId);
        exit;
    }
    
    try {
        $vaccineId = intval($_POST['vaccine_id'] ?? 0);
        $vaccDate  = sanitize($_POST['vaccination_date'] ?? date('Y-m-d'));
        $doseNo    = intval($_POST['dose_number'] ?? 1);

        if (!$patientId || !$vaccineId) {
            setFlashMessage('error', 'Please select a patient and vaccine.');
        } else {
            $db->query(
                "INSERT INTO patient_vaccinations (patient_id, vaccine_id, schedule_id, vaccination_date, dose_number, status, batch_number, site, route, administered_by, notes)
                 VALUES (?, ?, ?, ?, ?, 'administered', ?, ?, ?, ?, ?)",
                [
                    $patientId,
                    $vaccineId,
                    intval($_POST['schedule_id']) ?: null,
                    $vaccDate,
                    $doseNo,
                    sanitize($_POST['batch_number'] ?? ''),
                    sanitize($_POST['site'] ?? ''),
                    sanitize($_POST['route'] ?? 'IM'),
                    getCurrentUserId(),
                    sanitize($_POST['notes'] ?? '')
                ]
            );
            logAudit('create', 'vaccination', 'patient_vaccination', $db->lastInsertId());
            setFlashMessage('success', 'Vaccination recorded successfully.');
            header("Location: " . BASE_URL . "/modules/vaccination/schedule.php?patient_id=" . $patientId);
            exit;
        }
    } catch (Exception $e) {
        setFlashMessage('error', 'Error: ' . $e->getMessage());
    }
}

// Vaccines
$vaccines = $db->fetchAll("SELECT * FROM vaccines WHERE is_active = 1 ORDER BY name");

// Vaccine schedules
$schedules = $db->fetchAll(
    "SELECT vs.*, v.name as vaccine_name FROM vaccine_schedules vs
     JOIN vaccines v ON vs.vaccine_id = v.id ORDER BY vs.recommended_age_months ASC, v.name ASC"
);

// Patient details & history
$patient = $patientId ? $db->fetch("SELECT * FROM patients WHERE id = ? AND clinic_id = ?", [$patientId, $clinicId]) : null;
$patientVaccinations = [];
$administeredMap = [];

if ($patient) {
    $patientVaccinations = $db->fetchAll(
        "SELECT pv.*, v.name as vaccine_name, u.full_name as admin_by
         FROM patient_vaccinations pv
         JOIN vaccines v ON pv.vaccine_id = v.id
         LEFT JOIN users u ON pv.administered_by = u.id
         WHERE pv.patient_id = ? ORDER BY pv.vaccination_date DESC, pv.id DESC", [$patientId]
    );

    foreach ($patientVaccinations as $pv) {
        $key = $pv['vaccine_id'] . '_' . $pv['dose_number'];
        $administeredMap[$key] = $pv;
    }
}

$pageTitle = 'Vaccination Management';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="content-header">
    <div>
        <ul class="breadcrumb">
            <li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li>
            <li>Vaccination</li>
        </ul>
        <h1>Vaccination Management</h1>
    </div>
</div>

<div class="card mb-24">
    <div class="card-body">
        <form method="GET" class="d-flex gap-16 align-center flex-wrap">
            <label class="form-label mb-0" style="font-weight: 600;"><i class="fas fa-user-injured text-primary"></i> Patient:</label>
            <select name="patient_id" class="form-control" style="max-width: 450px;" onchange="this.form.submit()">
                <option value="">Select Patient...</option>
                <?php if ($patient): ?>
                <option value="<?= $patient['id'] ?>" selected>
                    <?= sanitizeOutput($patient['patient_uid'] . ' - ' . $patient['first_name'] . ' ' . ($patient['last_name'] ?? '') . ' (' . $patient['phone'] . ')') ?>
                </option>
                <?php endif; ?>
            </select>
            <?php if ($patient): ?>
            <div style="font-size: 13px; color: var(--text-muted);">
                <span class="badge badge-info" style="font-size: 12px; margin-right: 6px;"><?= sanitizeOutput($patient['patient_uid']) ?></span>
                <strong><?= sanitizeOutput($patient['first_name'] . ' ' . ($patient['last_name'] ?? '')) ?></strong>
                <?= $patient['gender'] ? ' • ' . ucfirst($patient['gender']) : '' ?>
                <?= !empty($patient['age']) ? ' • ' . $patient['age'] . ' yrs' : '' ?>
                <?= !empty($patient['blood_group']) ? ' • <span style="color:var(--danger);font-weight:600;">' . $patient['blood_group'] . '</span>' : '' ?>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="grid-2 gap-24">
    <!-- Schedule -->
    <div class="card">
        <div class="card-header" style="justify-content: space-between;">
            <h3><i class="fas fa-syringe" style="color: var(--primary);"></i> Vaccine Schedule</h3>
            <?php if ($patient): ?>
            <span class="badge badge-primary"><?= count($patientVaccinations) ?> Administered</span>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
            <?php if (empty($schedules)): ?>
            <div class="empty-state" style="padding: 24px;"><p class="text-muted">No vaccine schedules configured</p></div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>Vaccine</th>
                            <th>Dose</th>
                            <th>Age</th>
                            <th>Route</th>
                            <?php if ($patient): ?><th>Status / Action</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($schedules as $s): 
                        $ageText = $s['recommended_age_months'] < 1 ? 'Birth' : ($s['recommended_age_months'] >= 12 ? ($s['recommended_age_months']/12) . ' yr' : $s['recommended_age_months'] . ' mo');
                        $given = $patient ? ($administeredMap[$s['vaccine_id'] . '_' . $s['dose_number']] ?? null) : null;
                    ?>
                    <tr>
                        <td class="font-semibold"><?= sanitizeOutput($s['vaccine_name']) ?></td>
                        <td><span class="badge badge-primary">Dose <?= $s['dose_number'] ?></span></td>
                        <td><?= $ageText ?></td>
                        <td><?= sanitizeOutput($s['route'] ?? '-') ?></td>
                        <?php if ($patient): ?>
                        <td>
                            <?php if ($given): ?>
                                <span class="badge badge-success"><i class="fas fa-check"></i> Given</span>
                                <div class="text-muted" style="font-size: 11px; margin-top: 2px;"><?= formatDate($given['vaccination_date']) ?></div>
                            <?php elseif ($canManage): ?>
                                <button type="button" class="btn btn-sm btn-primary" onclick="quickRecord(<?= $s['vaccine_id'] ?>, <?= $s['dose_number'] ?>, <?= $s['id'] ?>, '<?= addslashes(sanitizeOutput($s['vaccine_name'])) ?>', '<?= sanitizeOutput($s['route'] ?? 'IM') ?>')">
                                    <i class="fas fa-syringe"></i> Record
                                </button>
                            <?php else: ?>
                                <span class="badge badge-secondary">Pending</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Patient History / Record -->
    <div>
        <?php if ($patient): ?>
        
        <!-- Vaccination History Card -->
        <div class="card mb-24">
            <div class="card-header">
                <h3><i class="fas fa-clipboard-check" style="color: var(--success);"></i> Vaccination History</h3>
            </div>
            <div class="card-body p-0">
                <?php if (empty($patientVaccinations)): ?>
                <div class="empty-state" style="padding: 24px;"><p class="text-muted">No vaccinations recorded yet</p></div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Vaccine</th>
                                <th>Dose</th>
                                <th>Batch</th>
                                <th>By</th>
                                <?php if ($canManage): ?><th style="text-align: right;">Action</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($patientVaccinations as $pv): ?>
                        <tr>
                            <td><?= formatDate($pv['vaccination_date']) ?></td>
                            <td class="font-semibold"><?= sanitizeOutput($pv['vaccine_name']) ?></td>
                            <td><span class="badge badge-info">#<?= $pv['dose_number'] ?></span></td>
                            <td style="font-size: 12px;"><?= sanitizeOutput($pv['batch_number'] ?: '-') ?></td>
                            <td style="font-size: 12px;"><?= sanitizeOutput($pv['admin_by'] ?? '-') ?></td>
                            <?php if ($canManage): ?>
                            <td style="text-align: right;">
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this vaccination record?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="record_id" value="<?= $pv['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-ghost text-danger" title="Delete record"><i class="fas fa-trash"></i></button>
                                </form>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Record Vaccination Form Card -->
        <?php if ($canManage): ?>
        <div class="card" id="recordVaccinationCard">
            <div class="card-header">
                <h3><i class="fas fa-plus-circle" style="color: var(--accent);"></i> Record Vaccination</h3>
            </div>
            <div class="card-body">
                <form method="POST" id="vaccinationForm">
                    <input type="hidden" name="action" value="record">
                    <input type="hidden" name="patient_id" value="<?= $patientId ?>">
                    <input type="hidden" name="schedule_id" id="rec_schedule_id" value="0">
                    
                    <div class="form-group">
                        <label class="form-label">Vaccine <span class="text-danger">*</span></label>
                        <select name="vaccine_id" id="rec_vaccine_id" class="form-control" required>
                            <option value="">Select Vaccine...</option>
                            <?php foreach ($vaccines as $v): ?>
                            <option value="<?= $v['id'] ?>"><?= sanitizeOutput($v['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group" style="flex: 1;">
                            <label class="form-label">Date Administered <span class="text-danger">*</span></label>
                            <input type="date" name="vaccination_date" id="rec_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="form-group" style="flex: 1;">
                            <label class="form-label">Dose Number <span class="text-danger">*</span></label>
                            <input type="number" name="dose_number" id="rec_dose" class="form-control" value="1" min="1" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group" style="flex: 1;">
                            <label class="form-label">Route</label>
                            <select name="route" id="rec_route" class="form-control">
                                <option value="IM">Intramuscular (IM)</option>
                                <option value="SC">Subcutaneous (SC)</option>
                                <option value="ID">Intradermal (ID)</option>
                                <option value="oral">Oral</option>
                                <option value="nasal">Intranasal</option>
                            </select>
                        </div>
                        <div class="form-group" style="flex: 1;">
                            <label class="form-label">Site</label>
                            <input type="text" name="site" id="rec_site" class="form-control" placeholder="e.g. Left Deltoid, Thigh">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Batch / Lot Number</label>
                        <input type="text" name="batch_number" id="rec_batch" class="form-control" placeholder="e.g. BATCH-2026-09">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Notes / Reactions</label>
                        <textarea name="notes" id="rec_notes" class="form-control" rows="2" placeholder="Adverse reactions, observations..."></textarea>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-block btn-lg" style="margin-top: 8px;">
                        <i class="fas fa-syringe"></i> Save Vaccination Record
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>
        
        <?php else: ?>
        <div class="card">
            <div class="empty-state" style="padding: 48px 24px; text-align: center;">
                <i class="fas fa-syringe" style="font-size: 48px; color: var(--primary); opacity: 0.6; margin-bottom: 16px;"></i>
                <h3>Select a Patient</h3>
                <p class="text-muted">Choose a patient from the dropdown above to view vaccination history and record administered vaccines.</p>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Load patients for search select
fetch(`${BASE_URL}/modules/patients/search_ajax.php`)
    .then(r => r.json())
    .then(patients => {
        const sel = document.querySelector('[name="patient_id"]');
        const currentId = <?= json_encode($patientId) ?>;
        patients.forEach(p => {
            if (p.id == currentId) return; // already added in PHP
            const opt = document.createElement('option');
            opt.value = p.id;
            opt.textContent = `${p.patient_uid} - ${p.first_name} ${p.last_name || ''} (${p.phone})`;
            sel.appendChild(opt);
        });
    })
    .catch(e => console.error('Patient search load error:', e));

function quickRecord(vaccineId, doseNumber, scheduleId, vaccineName, route) {
    const vSelect = document.getElementById('rec_vaccine_id');
    const dInput  = document.getElementById('rec_dose');
    const sInput  = document.getElementById('rec_schedule_id');
    const rSelect = document.getElementById('rec_route');
    const batchInput = document.getElementById('rec_batch');

    if (vSelect) vSelect.value = vaccineId;
    if (dInput) dInput.value = doseNumber;
    if (sInput) sInput.value = scheduleId || 0;
    if (rSelect && route) rSelect.value = route;

    const card = document.getElementById('recordVaccinationCard');
    if (card) {
        card.scrollIntoView({ behavior: 'smooth', block: 'center' });
        card.style.transition = 'box-shadow 0.3s ease, border-color 0.3s ease';
        card.style.borderColor = 'var(--primary)';
        card.style.boxShadow = '0 0 0 4px rgba(13, 148, 136, 0.25)';
        setTimeout(() => {
            card.style.borderColor = '';
            card.style.boxShadow = '';
        }, 1500);
        if (batchInput) batchInput.focus();
    }
}
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
