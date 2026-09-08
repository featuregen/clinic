<?php
/**
 * Create/Edit Prescription - Advanced Clinic Suite
 */
require_once dirname(dirname(__DIR__)) . '/config/session.php';
requireAuth();
requirePermission('prescriptions.create');

$db = db();
$clinicId = getCurrentClinicId();
$isEdit = false;
$prescription = null;

// Prefill from appointment
$appointmentId = intval($_REQUEST['appointment_id'] ?? 0);
$patientId = intval($_REQUEST['patient_id'] ?? 0);

if (isset($_GET['id'])) {
    $prescription = $db->fetch("SELECT * FROM prescriptions WHERE id = ? AND clinic_id = ?", [$_GET['id'], $clinicId]);
    if ($prescription) {
        $isEdit = true;
        $patientId = $prescription['patient_id'];
        $appointmentId = $prescription['appointment_id'];
    }
}

// Handle POST BEFORE any output
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db->beginTransaction();
        
        $doctorId = intval($_POST['doctor_id']);
        $rxData = [
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
            'doctor_id' => $doctorId,
            'appointment_id' => $appointmentId ?: null,
            'prescription_date' => date('Y-m-d'),
            'diagnosis' => sanitize($_POST['diagnosis'] ?? ''),
            'chief_complaints' => sanitize($_POST['chief_complaints'] ?? ''),
            'examination_findings' => sanitize($_POST['examination'] ?? ''),
            'advice' => sanitize($_POST['advice'] ?? ''),
            'follow_up_date' => $_POST['follow_up_date'] ?: null,
            'follow_up_notes' => sanitize($_POST['follow_up_notes'] ?? ''),
            'clinical_notes' => sanitize($_POST['notes'] ?? ''),
            'status' => 'draft'
        ];
        
        if ($isEdit) {
            $setClauses = [];
            $updateParams = [];
            foreach ($rxData as $key => $value) {
                $setClauses[] = "$key = ?";
                $updateParams[] = $value;
            }
            $updateParams[] = $prescription['id'];
            $db->query("UPDATE prescriptions SET " . implode(', ', $setClauses) . " WHERE id = ?", $updateParams);
            $rxId = $prescription['id'];
            
            // Delete old medicines and tests
            $db->query("DELETE FROM prescription_medicines WHERE prescription_id = ?", [$rxId]);
            $db->query("DELETE FROM prescription_tests WHERE prescription_id = ?", [$rxId]);
        } else {
            $columns = implode(', ', array_keys($rxData));
            $placeholders = implode(', ', array_fill(0, count($rxData), '?'));
            $db->query("INSERT INTO prescriptions ($columns) VALUES ($placeholders)", array_values($rxData));
            $rxId = $db->lastInsertId();
        }
        
        // Insert medicines
        if (!empty($_POST['med_name'])) {
            foreach ($_POST['med_name'] as $i => $medName) {
                if (empty($medName)) continue;
                $db->query(
                    "INSERT INTO prescription_medicines (prescription_id, medicine_id, medicine_name, dosage, frequency, duration, route, instructions)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        $rxId,
                        intval($_POST['med_id'][$i] ?? 0) ?: null,
                        sanitize($medName),
                        sanitize($_POST['med_dosage'][$i] ?? ''),
                        sanitize($_POST['med_frequency'][$i] ?? ''),
                        sanitize($_POST['med_duration'][$i] ?? ''),
                        sanitize($_POST['med_route'][$i] ?? 'oral'),
                        sanitize($_POST['med_instructions'][$i] ?? '')
                    ]
                );
            }
        }
        
        // Insert tests
        if (!empty($_POST['test_name'])) {
            foreach ($_POST['test_name'] as $i => $testName) {
                if (empty($testName)) continue;
                $db->query(
                    "INSERT INTO prescription_tests (prescription_id, test_id, test_name, instructions)
                     VALUES (?, ?, ?, ?)",
                    [
                        $rxId,
                        intval($_POST['test_id'][$i] ?? 0) ?: null,
                        sanitize($testName),
                        sanitize($_POST['test_instructions'][$i] ?? '')
                    ]
                );
            }
        }
        
        // Update appointment status
        if ($appointmentId) {
            $db->query("UPDATE appointments SET status = 'completed' WHERE id = ?", [$appointmentId]);
        }
        
        $db->commit();
        logAudit($isEdit ? 'update' : 'create', 'prescriptions', 'prescription', $rxId);
        setFlashMessage('success', 'Prescription ' . ($isEdit ? 'updated' : 'created') . ' successfully.');
        header("Location: " . BASE_URL . "/modules/prescriptions/view.php?id=$rxId");
        exit;
        
    } catch (Exception $e) {
        $db->rollBack();
        setFlashMessage('error', 'Error: ' . $e->getMessage());
    }
}

// NOW include header (outputs HTML) — after any potential redirect
$pageTitle = 'Prescription';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';

// Get patient info
$patient = $patientId ? $db->fetch("SELECT * FROM patients WHERE id = ? AND clinic_id = ?", [$patientId, $clinicId]) : null;

// Get appointment info
$appointment = $appointmentId ? $db->fetch("SELECT * FROM appointments WHERE id = ?", [$appointmentId]) : null;

// Fetch doctors
$doctors = $db->fetchAll(
    "SELECT d.id, u.full_name, s.name as specialty FROM doctors d JOIN users u ON d.user_id = u.id LEFT JOIN specialties s ON d.specialty_id = s.id WHERE d.clinic_id = ?", [$clinicId]
);

// Medicines list
$medicines = $db->fetchAll("SELECT * FROM medicines WHERE is_active = 1 ORDER BY name");

// Diagnoses
$diagnoses = $db->fetchAll("SELECT * FROM diagnoses WHERE is_active = 1 ORDER BY name");

// Lab tests
$labTests = $db->fetchAll("SELECT * FROM lab_tests WHERE is_active = 1 ORDER BY name");

// Existing prescription medicines
$rxMedicines = $isEdit ? $db->fetchAll("SELECT pm.*, m.name as med_name FROM prescription_medicines pm LEFT JOIN medicines m ON pm.medicine_id = m.id WHERE pm.prescription_id = ?", [$prescription['id']]) : [];
$rxTests = $isEdit ? $db->fetchAll("SELECT pt.*, lt.name as test_name FROM prescription_tests pt LEFT JOIN lab_tests lt ON pt.test_id = lt.id WHERE pt.prescription_id = ?", [$prescription['id']]) : [];

// Templates
$templates = $db->fetchAll("SELECT * FROM prescription_templates WHERE clinic_id = ? OR scope = 'clinic' ORDER BY name", [$clinicId]);
?>

<div class="content-header">
    <div>
        <ul class="breadcrumb">
            <li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li>
            <li><a href="<?= BASE_URL ?>/modules/prescriptions/list.php">Prescriptions</a></li>
            <li><?= $isEdit ? 'Edit' : 'New' ?> Prescription</li>
        </ul>
        <h1><?= $isEdit ? 'Edit' : 'Write' ?> Prescription</h1>
    </div>
</div>

<?php if ($patient): ?>
<div class="alert alert-info mb-24">
    <i class="fas fa-user"></i>
    <div>
        <strong><?= sanitizeOutput($patient['first_name'] . ' ' . ($patient['last_name'] ?? '')) ?></strong> 
        | <?= sanitizeOutput($patient['patient_uid']) ?> 
        | Age: <?= $patient['date_of_birth'] ? calculateAge($patient['date_of_birth']) : ($patient['age'] ?? '-') ?>
        | <?= sanitizeOutput($patient['gender'] ?? '-') ?>
        <?php if ($patient['blood_group']): ?> | <span class="badge badge-danger"><?= $patient['blood_group'] ?></span><?php endif; ?>
    </div>
</div>
<?php endif; ?>

<form method="POST" id="prescriptionForm">
    <input type="hidden" name="patient_id" value="<?= $patientId ?>">
    <input type="hidden" name="appointment_id" value="<?= $appointmentId ?>">
    
    <div class="grid-2 gap-24">
        <!-- Left -->
        <div>
            <div class="card mb-24">
                <div class="card-header"><h3><i class="fas fa-stethoscope" style="color: var(--primary);"></i> Clinical Details</h3></div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label">Doctor <span class="required">*</span></label>
                        <select name="doctor_id" class="form-control" required>
                            <?php foreach ($doctors as $doc): ?>
                            <option value="<?= $doc['id'] ?>" <?= ($prescription['doctor_id'] ?? ($appointment['doctor_id'] ?? '')) == $doc['id'] ? 'selected' : '' ?>>
                                Dr. <?= sanitizeOutput($doc['full_name']) ?> (<?= sanitizeOutput($doc['specialty'] ?? 'General') ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Chief Complaints</label>
                        <textarea name="chief_complaints" class="form-control" rows="2"><?= sanitizeOutput($prescription['chief_complaints'] ?? '') ?></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Diagnosis</label>
                        <textarea name="diagnosis" class="form-control" rows="2"><?= sanitizeOutput($prescription['diagnosis'] ?? '') ?></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Examination Findings</label>
                        <textarea name="examination" class="form-control" rows="2"><?= sanitizeOutput($prescription['examination_findings'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>
            
            <!-- Medicines -->
            <div class="card mb-24">
                <div class="card-header">
                    <h3><i class="fas fa-pills" style="color: var(--success);"></i> Medicines</h3>
                    <button type="button" class="btn btn-sm btn-primary" onclick="addMedicine()">
                        <i class="fas fa-plus"></i> Add Medicine
                    </button>
                </div>
                <div class="card-body" id="medicinesContainer">
                    <?php if (!empty($rxMedicines)): foreach ($rxMedicines as $idx => $med): ?>
                    <div class="medicine-row" data-index="<?= $idx ?>">
                        <div class="form-row mb-12">
                            <div class="form-group">
                                <input type="hidden" name="med_id[]" value="<?= $med['medicine_id'] ?>">
                                <input type="text" name="med_name[]" class="form-control" placeholder="Medicine name" value="<?= sanitizeOutput($med['medicine_name'] ?? $med['med_name'] ?? '') ?>" required>
                            </div>
                            <div class="form-group">
                                <input type="text" name="med_dosage[]" class="form-control" placeholder="Dosage (e.g. 500mg)" value="<?= sanitizeOutput($med['dosage'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <select name="med_frequency[]" class="form-control">
                                    <option value="OD" <?= ($med['frequency'] ?? '') === 'OD' ? 'selected' : '' ?>>OD (Once Daily)</option>
                                    <option value="BD" <?= ($med['frequency'] ?? '') === 'BD' ? 'selected' : '' ?>>BD (Twice Daily)</option>
                                    <option value="TDS" <?= ($med['frequency'] ?? '') === 'TDS' ? 'selected' : '' ?>>TDS (Thrice Daily)</option>
                                    <option value="QID" <?= ($med['frequency'] ?? '') === 'QID' ? 'selected' : '' ?>>QID (Four Times)</option>
                                    <option value="SOS" <?= ($med['frequency'] ?? '') === 'SOS' ? 'selected' : '' ?>>SOS (As Needed)</option>
                                    <option value="HS" <?= ($med['frequency'] ?? '') === 'HS' ? 'selected' : '' ?>>HS (At Bedtime)</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-row mb-12">
                            <div class="form-group">
                                <input type="text" name="med_duration[]" class="form-control" placeholder="Duration (e.g. 5 days)" value="<?= sanitizeOutput($med['duration'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <select name="med_route[]" class="form-control">
                                    <option value="oral" <?= ($med['route'] ?? 'oral') === 'oral' ? 'selected' : '' ?>>Oral</option>
                                    <option value="injection" <?= ($med['route'] ?? '') === 'injection' ? 'selected' : '' ?>>Injection</option>
                                    <option value="topical" <?= ($med['route'] ?? '') === 'topical' ? 'selected' : '' ?>>Topical</option>
                                    <option value="inhalation" <?= ($med['route'] ?? '') === 'inhalation' ? 'selected' : '' ?>>Inhalation</option>
                                </select>
                            </div>
                            <div class="form-group" style="position: relative;">
                                <input type="text" name="med_instructions[]" class="form-control" placeholder="Instructions" value="<?= sanitizeOutput($med['instructions'] ?? '') ?>">
                                <button type="button" class="btn btn-sm btn-ghost text-danger" style="position: absolute; right: -40px; top: 50%; transform: translateY(-50%);" onclick="this.closest('.medicine-row').remove()"><i class="fas fa-trash"></i></button>
                            </div>
                        </div>
                        <hr style="border-color: var(--border-color); margin: 8px 0;">
                    </div>
                    <?php endforeach; else: ?>
                    <p class="text-muted text-center" id="noMedicines">Click "Add Medicine" to add medications</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Right -->
        <div>
            <!-- Lab Tests -->
            <div class="card mb-24">
                <div class="card-header">
                    <h3><i class="fas fa-flask" style="color: var(--warning);"></i> Lab Tests</h3>
                    <button type="button" class="btn btn-sm btn-primary" onclick="addLabTest()">
                        <i class="fas fa-plus"></i> Add Test
                    </button>
                </div>
                <div class="card-body" id="testsContainer">
                    <?php if (!empty($rxTests)): foreach ($rxTests as $test): ?>
                    <div class="test-row form-row mb-12">
                        <div class="form-group">
                            <input type="hidden" name="test_id[]" value="<?= $test['test_id'] ?>">
                            <input type="text" name="test_name[]" class="form-control" value="<?= sanitizeOutput($test['test_name']) ?>">
                        </div>
                        <div class="form-group" style="position: relative;">
                            <input type="text" name="test_instructions[]" class="form-control" placeholder="Instructions" value="<?= sanitizeOutput($test['instructions'] ?? '') ?>">
                            <button type="button" class="btn btn-sm btn-ghost text-danger" style="position: absolute; right: -40px; top: 50%; transform: translateY(-50%);" onclick="this.closest('.test-row').remove()"><i class="fas fa-trash"></i></button>
                        </div>
                    </div>
                    <?php endforeach; else: ?>
                    <p class="text-muted text-center" id="noTests">No lab tests added</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Advice & Follow-up -->
            <div class="card mb-24">
                <div class="card-header"><h3><i class="fas fa-clipboard-list" style="color: var(--accent);"></i> Advice & Follow-up</h3></div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label">Advice</label>
                        <textarea name="advice" class="form-control" rows="3"><?= sanitizeOutput($prescription['advice'] ?? '') ?></textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Follow-up Date</label>
                            <input type="date" name="follow_up_date" class="form-control" value="<?= $prescription['follow_up_date'] ?? '' ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Follow-up Notes</label>
                            <input type="text" name="follow_up_notes" class="form-control" value="<?= sanitizeOutput($prescription['follow_up_notes'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2"><?= sanitizeOutput($prescription['clinical_notes'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>
            
            <!-- Actions -->
            <div class="d-flex justify-end gap-12">
                <a href="<?= BASE_URL ?>/modules/prescriptions/list.php" class="btn btn-outline">Cancel</a>
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="fas fa-save"></i> <?= $isEdit ? 'Update' : 'Save' ?> Prescription
                </button>
            </div>
        </div>
    </div>
</form>

<script>
let medIndex = <?= !empty($rxMedicines) ? count($rxMedicines) : 0 ?>;

function addMedicine() {
    document.getElementById('noMedicines')?.remove();
    const container = document.getElementById('medicinesContainer');
    const html = `
    <div class="medicine-row" data-index="${medIndex}">
        <div class="form-row mb-12">
            <div class="form-group">
                <input type="hidden" name="med_id[]" value="0">
                <input type="text" name="med_name[]" class="form-control" placeholder="Medicine name" required list="medicineList">
            </div>
            <div class="form-group">
                <input type="text" name="med_dosage[]" class="form-control" placeholder="Dosage">
            </div>
            <div class="form-group">
                <select name="med_frequency[]" class="form-control">
                    <option value="OD">OD (Once Daily)</option>
                    <option value="BD">BD (Twice Daily)</option>
                    <option value="TDS">TDS (Thrice Daily)</option>
                    <option value="QID">QID (Four Times)</option>
                    <option value="SOS">SOS (As Needed)</option>
                    <option value="HS">HS (At Bedtime)</option>
                </select>
            </div>
        </div>
        <div class="form-row mb-12">
            <div class="form-group">
                <input type="text" name="med_duration[]" class="form-control" placeholder="Duration">
            </div>
            <div class="form-group">
                <select name="med_route[]" class="form-control">
                    <option value="oral">Oral</option>
                    <option value="injection">Injection</option>
                    <option value="topical">Topical</option>
                    <option value="inhalation">Inhalation</option>
                </select>
            </div>
            <div class="form-group" style="position: relative;">
                <input type="text" name="med_instructions[]" class="form-control" placeholder="Instructions">
                <button type="button" class="btn btn-sm btn-ghost text-danger" style="position: absolute; right: -40px; top: 50%; transform: translateY(-50%);" onclick="this.closest('.medicine-row').remove()"><i class="fas fa-trash"></i></button>
            </div>
        </div>
        <hr style="border-color: var(--border-color); margin: 8px 0;">
    </div>`;
    container.insertAdjacentHTML('beforeend', html);
    medIndex++;
}

function addLabTest() {
    document.getElementById('noTests')?.remove();
    const container = document.getElementById('testsContainer');
    const html = `
    <div class="test-row form-row mb-12">
        <div class="form-group">
            <input type="hidden" name="test_id[]" value="0">
            <input type="text" name="test_name[]" class="form-control" placeholder="Test name" required list="testList">
        </div>
        <div class="form-group" style="position: relative;">
            <input type="text" name="test_instructions[]" class="form-control" placeholder="Instructions">
            <button type="button" class="btn btn-sm btn-ghost text-danger" style="position: absolute; right: -40px; top: 50%; transform: translateY(-50%);" onclick="this.closest('.test-row').remove()"><i class="fas fa-trash"></i></button>
        </div>
    </div>`;
    container.insertAdjacentHTML('beforeend', html);
}
</script>

<!-- Datalists for autocomplete -->
<datalist id="medicineList">
    <?php foreach ($medicines as $med): ?>
    <option value="<?= sanitizeOutput($med['name']) ?>">
    <?php endforeach; ?>
</datalist>
<datalist id="testList">
    <?php foreach ($labTests as $test): ?>
    <option value="<?= sanitizeOutput($test['name']) ?>">
    <?php endforeach; ?>
</datalist>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
