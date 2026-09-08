<?php
/**
 * Vaccination Schedule - Advanced Clinic Suite
 */
require_once dirname(dirname(__DIR__)) . '/config/session.php';
requirePermission('vaccination.view');

$db = db();
$clinicId = getCurrentClinicId();
$patientId = intval($_GET['patient_id'] ?? 0);

// Handle vaccination record
if ($_SERVER['REQUEST_METHOD'] === 'POST' && hasPermission('vaccination.record')) {
    try {
        $db->query(
            "INSERT INTO patient_vaccinations (patient_id, vaccine_id, schedule_id, vaccination_date, dose_number, batch_number, site, route, administered_by, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                intval($_POST['patient_id']),
                intval($_POST['vaccine_id']),
                intval($_POST['schedule_id']) ?: null,
                sanitize($_POST['vaccination_date']),
                intval($_POST['dose_number']),
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
    } catch (Exception $e) {
        setFlashMessage('error', 'Error: ' . $e->getMessage());
    }
}

// Vaccines
$vaccines = $db->fetchAll("SELECT * FROM vaccines WHERE is_active = 1 ORDER BY name");

// Vaccine schedules
$schedules = $db->fetchAll(
    "SELECT vs.*, v.name as vaccine_name FROM vaccine_schedules vs
     JOIN vaccines v ON vs.vaccine_id = v.id ORDER BY vs.recommended_age_months ASC"
);

// Patient details & history
$patient = $patientId ? $db->fetch("SELECT * FROM patients WHERE id = ? AND clinic_id = ?", [$patientId, $clinicId]) : null;
$patientVaccinations = [];

if ($patient) {
    $patientVaccinations = $db->fetchAll(
        "SELECT pv.*, v.name as vaccine_name, u.full_name as admin_by
         FROM patient_vaccinations pv
         JOIN vaccines v ON pv.vaccine_id = v.id
         LEFT JOIN users u ON pv.administered_by = u.id
         WHERE pv.patient_id = ? ORDER BY pv.vaccination_date DESC", [$patientId]
    );
}

$pageTitle = 'Vaccination';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="content-header">
    <div>
        <ul class="breadcrumb"><li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li><li>Vaccination</li></ul>
        <h1>Vaccination Management</h1>
    </div>
</div>

<div class="card mb-24">
    <div class="card-body">
        <form method="GET" class="d-flex gap-12 align-center">
            <label class="form-label mb-0">Patient:</label>
            <select name="patient_id" class="form-control" style="max-width: 400px;" onchange="this.form.submit()">
                <option value="">Select Patient...</option>
            </select>
        </form>
    </div>
</div>

<div class="grid-2 gap-24">
    <!-- Schedule -->
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-syringe" style="color: var(--primary);"></i> Vaccine Schedule</h3></div>
        <div class="card-body p-0">
            <?php if (empty($schedules)): ?>
            <div class="empty-state" style="padding: 24px;"><p class="text-muted">No vaccine schedules configured</p></div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Vaccine</th><th>Dose</th><th>Age</th><th>Route</th></tr></thead>
                    <tbody>
                    <?php foreach ($schedules as $s): 
                        $ageText = $s['recommended_age_months'] < 1 ? 'Birth' : ($s['recommended_age_months'] >= 12 ? ($s['recommended_age_months']/12) . ' yr' : $s['recommended_age_months'] . ' mo');
                    ?>
                    <tr>
                        <td class="font-semibold"><?= sanitizeOutput($s['vaccine_name']) ?></td>
                        <td><span class="badge badge-primary">Dose <?= $s['dose_number'] ?></span></td>
                        <td><?= $ageText ?></td>
                        <td><?= sanitizeOutput($s['route'] ?? '-') ?></td>
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
        <div class="card mb-24">
            <div class="card-header"><h3><i class="fas fa-clipboard-check" style="color: var(--success);"></i> Vaccination History</h3></div>
            <div class="card-body p-0">
                <?php if (empty($patientVaccinations)): ?>
                <div class="empty-state" style="padding: 24px;"><p class="text-muted">No vaccinations recorded</p></div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead><tr><th>Date</th><th>Vaccine</th><th>Dose</th><th>By</th></tr></thead>
                        <tbody>
                        <?php foreach ($patientVaccinations as $pv): ?>
                        <tr>
                            <td><?= formatDate($pv['vaccination_date']) ?></td>
                            <td class="font-semibold"><?= sanitizeOutput($pv['vaccine_name']) ?></td>
                            <td><span class="badge badge-info">#<?= $pv['dose_number'] ?></span></td>
                            <td><?= sanitizeOutput($pv['admin_by'] ?? '-') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if (hasPermission('vaccination.record')): ?>
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-plus-circle" style="color: var(--accent);"></i> Record Vaccination</h3></div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="patient_id" value="<?= $patientId ?>">
                    <div class="form-group">
                        <label class="form-label">Vaccine</label>
                        <select name="vaccine_id" class="form-control" required>
                            <option value="">Select Vaccine...</option>
                            <?php foreach ($vaccines as $v): ?>
                            <option value="<?= $v['id'] ?>"><?= sanitizeOutput($v['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Date</label>
                            <input type="date" name="vaccination_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Dose #</label>
                            <input type="number" name="dose_number" class="form-control" value="1" min="1" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Batch #</label>
                            <input type="text" name="batch_number" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Route</label>
                            <select name="route" class="form-control">
                                <option value="IM">Intramuscular (IM)</option>
                                <option value="SC">Subcutaneous (SC)</option>
                                <option value="ID">Intradermal (ID)</option>
                                <option value="oral">Oral</option>
                            </select>
                        </div>
                    </div>
                    <input type="hidden" name="schedule_id" value="0">
                    <div class="form-group">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-syringe"></i> Record Vaccination</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
        
        <?php else: ?>
        <div class="card">
            <div class="empty-state"><i class="fas fa-syringe"></i><h3>Select a patient</h3><p>Choose a patient to view/record vaccinations</p></div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
fetch(`${BASE_URL}/modules/patients/search_ajax.php`)
    .then(r => r.json())
    .then(patients => {
        const sel = document.querySelector('[name="patient_id"]');
        patients.forEach(p => {
            const opt = document.createElement('option');
            opt.value = p.id;
            opt.textContent = `${p.patient_uid} - ${p.first_name} ${p.last_name || ''} (${p.phone})`;
            if (p.id == <?= $patientId ?>) opt.selected = true;
            sel.appendChild(opt);
        });
    });
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
