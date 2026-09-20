<?php
/**
 * Add/Edit Prescription Template
 */
$pageTitle = 'Manage Template';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';
requirePermission('prescriptions.templates');

$db = db();
$clinicId = getCurrentClinicId();
$isAdmin = in_array(getCurrentUserRole(), ['admin', 'clinic_admin', 'super_admin', ROLE_ADMIN, ROLE_SUPER_ADMIN]);
$doctorId = getCurrentDoctorId();

$id = $_GET['id'] ?? 0;
$template = [];
$isEdit = false;

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = sanitize($_POST['name'] ?? '');
    $scope = sanitize($_POST['scope'] ?? 'personal');
    $description = sanitize($_POST['description'] ?? '');
    
    // Process Medicines
    $medicines = [];
    if (!empty($_POST['med_name'])) {
        foreach ($_POST['med_name'] as $k => $v) {
            if (!empty($v)) {
                $medicines[] = [
                    'name' => sanitize($v),
                    'dosage' => sanitize($_POST['med_dosage'][$k] ?? ''),
                    'frequency' => sanitize($_POST['med_frequency'][$k] ?? ''),
                    'duration' => sanitize($_POST['med_duration'][$k] ?? ''),
                    'route' => sanitize($_POST['med_route'][$k] ?? 'oral'),
                    'instruction' => sanitize($_POST['med_instruction'][$k] ?? '')
                ];
            }
        }
    }
    
    // Process Tests
    $tests = [];
    if (!empty($_POST['test_name'])) {
        foreach ($_POST['test_name'] as $k => $v) {
            if (!empty($v)) {
                $tests[] = [
                    'name' => sanitize($v),
                    'instruction' => sanitize($_POST['test_instruction'][$k] ?? '')
                ];
            }
        }
    }
    
    // Combine into template_data
    $templateData = json_encode([
        'medicines' => $medicines,
        'lab_tests' => $tests
    ]);
    
    try {
        if ($id) {
            // Update
            $db->query(
                "UPDATE prescription_templates SET name=?, scope=?, description=?, template_data=? WHERE id=? AND clinic_id=?", 
                [$name, $scope, $description, $templateData, $id, $clinicId]
            );
        } else {
            // Insert
            $db->query(
                "INSERT INTO prescription_templates (clinic_id, name, scope, doctor_id, description, template_data) VALUES (?, ?, ?, ?, ?, ?)",
                [$clinicId, $name, $scope, $doctorId, $description, $templateData]
            );
        }
        echo "<script>window.location.href='list.php';</script>";
        exit;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Fetch Existing Data
if ($id) {
    $template = $db->fetch("SELECT * FROM prescription_templates WHERE id = ? AND clinic_id = ?", [$id, $clinicId]);
    if ($template) {
        $isEdit = true;
        $data = json_decode($template['template_data'] ?? '[]', true);
        $template['medicines'] = $data['medicines'] ?? [];
        $template['lab_tests'] = $data['lab_tests'] ?? [];
    }
}

// Fetch Master Data for Datalists
$medList = $db->fetchAll("SELECT name FROM medicines WHERE is_active = 1 ORDER BY name");
$testList = $db->fetchAll("SELECT name FROM lab_tests WHERE is_active = 1 ORDER BY name");
?>

<div class="content-header">
    <div>
        <ul class="breadcrumb">
            <li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li>
            <li><a href="list.php">Templates</a></li>
            <li><?= $isEdit ? 'Edit' : 'Add' ?> Template</li>
        </ul>
        <h1><?= $isEdit ? 'Edit' : 'Add' ?> Template</h1>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <form method="POST">
            <div class="row">
                <div class="col-md-6 form-group">
                    <label>Template Name <span class="required">*</span></label>
                    <input type="text" name="name" class="form-control" required value="<?= $template['name'] ?? '' ?>">
                </div>
                
                <div class="col-md-6 form-group">
                    <label>Scope</label>
                    <select name="scope" class="form-control" <?= !$isAdmin ? 'disabled' : '' ?>>
                        <option value="personal" <?= ($template['scope'] ?? 'personal') === 'personal' ? 'selected' : '' ?>>Personal (Only you)</option>
                        <?php if ($isAdmin): ?>
                        <option value="clinic" <?= ($template['scope'] ?? '') === 'clinic' ? 'selected' : '' ?>>Clinic-wide (All Doctors)</option>
                        <?php endif; ?>
                    </select>
                    <?php if (!$isAdmin): ?><input type="hidden" name="scope" value="personal"><?php endif; ?>
                    <small class="text-muted text-xs">Personal: visible only to creator. Clinic-wide: available to all clinic doctors.</small>
                </div>
                
                <div class="col-md-12 form-group">
                    <label>Description</label>
                    <textarea name="description" class="form-control" rows="2"><?= $template['description'] ?? '' ?></textarea>
                </div>
            </div>
            
            <hr class="my-24">
            
            <!-- Medicines Section - matches prescription create form -->
            <div class="d-flex justify-between align-center mb-16">
                <h3><i class="fas fa-pills" style="color: var(--success);"></i> Medicines</h3>
                <button type="button" class="btn btn-outline btn-sm" onclick="addMedicine()"><i class="fas fa-plus"></i> Add Medicine</button>
            </div>
            
            <div id="medicineContainer">
                <?php 
                $meds = $template['medicines'] ?? [];
                if (!empty($meds)) {
                    foreach ($meds as $med) {
                ?>
                <div class="medicine-row" style="border: 1px solid var(--border-color); border-radius: 8px; padding: 12px 16px; margin-bottom: 12px; background: var(--bg-secondary);">
                    <div class="form-row mb-12">
                        <div class="form-group">
                            <input type="text" name="med_name[]" class="form-control" placeholder="Medicine name" list="medList" value="<?= sanitizeOutput($med['name'] ?? '') ?>">
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
                    <div style="display: flex; gap: 12px; align-items: center;">
                        <div class="form-group mb-0" style="flex: 1;">
                            <input type="text" name="med_duration[]" class="form-control" placeholder="Duration (e.g. 5 days)" value="<?= sanitizeOutput($med['duration'] ?? '') ?>">
                        </div>
                        <div class="form-group mb-0" style="flex: 1;">
                            <select name="med_route[]" class="form-control">
                                <option value="oral" <?= ($med['route'] ?? 'oral') === 'oral' ? 'selected' : '' ?>>Oral</option>
                                <option value="injection" <?= ($med['route'] ?? '') === 'injection' ? 'selected' : '' ?>>Injection</option>
                                <option value="topical" <?= ($med['route'] ?? '') === 'topical' ? 'selected' : '' ?>>Topical</option>
                                <option value="inhalation" <?= ($med['route'] ?? '') === 'inhalation' ? 'selected' : '' ?>>Inhalation</option>
                            </select>
                        </div>
                        <div class="form-group mb-0" style="flex: 1.5;">
                            <input type="text" name="med_instruction[]" class="form-control" placeholder="Instructions" value="<?= sanitizeOutput($med['instruction'] ?? '') ?>">
                        </div>
                        <div class="form-group mb-0" style="flex-shrink: 0;">
                            <button type="button" class="btn btn-sm btn-ghost text-danger" onclick="this.closest('.medicine-row').remove()" title="Remove"><i class="fas fa-trash"></i></button>
                        </div>
                    </div>
                </div>
                <?php }} ?>
            </div>

            <hr class="my-24">
            
            <!-- Lab Tests Section -->
            <div class="d-flex justify-between align-center mb-16">
                <h3><i class="fas fa-flask" style="color: var(--warning);"></i> Lab Tests</h3>
                <button type="button" class="btn btn-outline btn-sm" onclick="addTest()"><i class="fas fa-plus"></i> Add Test</button>
            </div>
            
            <div id="testContainer">
                <?php
                $tests = $template['lab_tests'] ?? [];
                if (!empty($tests)) {
                    foreach ($tests as $test) {
                ?>
                <div class="test-row mb-12" style="display: flex; gap: 12px; align-items: center;">
                    <div class="form-group mb-0" style="flex: 1;">
                        <input type="text" name="test_name[]" class="form-control" placeholder="Test Name" list="testList" value="<?= sanitizeOutput($test['name'] ?? '') ?>">
                    </div>
                    <div class="form-group mb-0" style="flex: 1;">
                        <input type="text" name="test_instruction[]" class="form-control" placeholder="Instruction" value="<?= sanitizeOutput($test['instruction'] ?? '') ?>">
                    </div>
                    <div class="form-group mb-0" style="flex-shrink: 0;">
                        <button type="button" class="btn btn-ghost text-danger" onclick="this.closest('.test-row').remove()"><i class="fas fa-trash"></i></button>
                    </div>
                </div>
                <?php }} ?>
            </div>
            
            <div class="mt-24 d-flex justify-end gap-16">
                <a href="list.php" class="btn btn-outline">Cancel</a>
                <button type="submit" class="btn btn-primary">Save Template</button>
            </div>
        </form>
    </div>
</div>

<!-- Datalists -->
<datalist id="medList">
    <?php foreach ($medList as $m) echo "<option value='{$m['name']}'>"; ?>
</datalist>
<datalist id="testList">
    <?php foreach ($testList as $t) echo "<option value='{$t['name']}'>"; ?>
</datalist>

<script>
function addMedicine() {
    const html = `
    <div class="medicine-row" style="border: 1px solid var(--border-color); border-radius: 8px; padding: 12px 16px; margin-bottom: 12px; background: var(--bg-secondary);">
        <div class="form-row mb-12">
            <div class="form-group">
                <input type="text" name="med_name[]" class="form-control" placeholder="Medicine name" list="medList">
            </div>
            <div class="form-group">
                <input type="text" name="med_dosage[]" class="form-control" placeholder="Dosage (e.g. 500mg)">
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
        <div style="display: flex; gap: 12px; align-items: center;">
            <div class="form-group mb-0" style="flex: 1;">
                <input type="text" name="med_duration[]" class="form-control" placeholder="Duration (e.g. 5 days)">
            </div>
            <div class="form-group mb-0" style="flex: 1;">
                <select name="med_route[]" class="form-control">
                    <option value="oral">Oral</option>
                    <option value="injection">Injection</option>
                    <option value="topical">Topical</option>
                    <option value="inhalation">Inhalation</option>
                </select>
            </div>
            <div class="form-group mb-0" style="flex: 1.5;">
                <input type="text" name="med_instruction[]" class="form-control" placeholder="Instructions">
            </div>
            <div class="form-group mb-0" style="flex-shrink: 0;">
                <button type="button" class="btn btn-sm btn-ghost text-danger" onclick="this.closest('.medicine-row').remove()" title="Remove"><i class="fas fa-trash"></i></button>
            </div>
        </div>
    </div>`;
    document.getElementById('medicineContainer').insertAdjacentHTML('beforeend', html);
}

function addTest() {
    const html = `
    <div class="test-row mb-12" style="display: flex; gap: 12px; align-items: center;">
        <div class="form-group mb-0" style="flex: 1;">
            <input type="text" name="test_name[]" class="form-control" placeholder="Test Name" list="testList">
        </div>
        <div class="form-group mb-0" style="flex: 1;">
            <input type="text" name="test_instruction[]" class="form-control" placeholder="Instruction">
        </div>
        <div class="form-group mb-0" style="flex-shrink: 0;">
            <button type="button" class="btn btn-ghost text-danger" onclick="this.closest('.test-row').remove()"><i class="fas fa-trash"></i></button>
        </div>
    </div>`;
    document.getElementById('testContainer').insertAdjacentHTML('beforeend', html);
}
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
<!-- deployed Sun Sep 20 21:52:31 IST 2026 -->
