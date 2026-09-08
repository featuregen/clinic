<?php
/**
 * Add/Edit Prescription Template
 */
$pageTitle = 'Manage Template';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';
requirePermission('templates.manage');

$db = db();
$clinicId = getCurrentClinicId();
$userId = $_SESSION['user_id'];
$isAdmin = (getCurrentUserRole() === 'clinic_admin' || getCurrentUserRole() === 'super_admin');
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
                    'route' => sanitize($_POST['med_route'][$k] ?? ''),
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
                        <option value="personal" <?= ($template['scope'] ?? '') === 'personal' ? 'selected' : '' ?>>Personal</option>
                        <?php if ($isAdmin): ?>
                        <option value="clinic" <?= ($template['scope'] ?? '') === 'clinic' ? 'selected' : '' ?>>Clinic-wide</option>
                        <?php endif; ?>
                    </select>
                    <?php if (!$isAdmin): ?><input type="hidden" name="scope" value="personal"><?php endif; ?>
                </div>
                
                <div class="col-md-12 form-group">
                    <label>Description</label>
                    <textarea name="description" class="form-control" rows="2"><?= $template['description'] ?? '' ?></textarea>
                </div>
            </div>
            
            <hr class="my-24">
            
            <!-- Medicines Section -->
            <div class="d-flex justify-between align-center mb-16">
                <h3>Medicines</h3>
                <button type="button" class="btn btn-outline btn-sm" onclick="addMedicine()"><i class="fas fa-plus"></i> Add Medicine</button>
            </div>
            
            <div id="medicineContainer">
                <?php 
                $meds = $template['medicines'] ?? [];
                if (!empty($meds)) {
                    foreach ($meds as $med) {
                        // Render existing rows - logic replicated in JS
                        echo "<div class='medicine-row row mb-12 g-2'>
                            <div class='col-md-3'><input type='text' name='med_name[]' class='form-control' placeholder='Medicine Name' list='medList' value='{$med['name']}'></div>
                            <div class='col-md-2'><input type='text' name='med_dosage[]' class='form-control' placeholder='Dosage' value='{$med['dosage']}'></div>
                            <div class='col-md-2'>
                                <select name='med_frequency[]' class='form-control'>
                                    <option value='OD' " . (($med['frequency'] ?? '')=='OD'?'selected':'') . ">OD</option>
                                    <option value='BD' " . (($med['frequency'] ?? '')=='BD'?'selected':'') . ">BD</option>
                                    <option value='TDS' " . (($med['frequency'] ?? '')=='TDS'?'selected':'') . ">TDS</option>
                                    <option value='QID' " . (($med['frequency'] ?? '')=='QID'?'selected':'') . ">QID</option>
                                    <option value='SOS' " . (($med['frequency'] ?? '')=='SOS'?'selected':'') . ">SOS</option>
                                    <option value='HS' " . (($med['frequency'] ?? '')=='HS'?'selected':'') . ">HS</option>
                                </select>
                            </div>
                            <div class='col-md-2'><input type='text' name='med_duration[]' class='form-control' placeholder='Duration' value='{$med['duration']}'></div>
                            <div class='col-md-2'><input type='text' name='med_instruction[]' class='form-control' placeholder='Instruction' value='{$med['instruction']}'></div>
                            <div class='col-md-1'><button type='button' class='btn btn-ghost text-danger' onclick='this.closest(\".medicine-row\").remove()'><i class=\"fas fa-trash\"></i></button></div>
                        </div>";
                    }
                }
                ?>
            </div>

            <hr class="my-24">
            
            <!-- Lab Tests Section -->
            <div class="d-flex justify-between align-center mb-16">
                <h3>Lab Tests</h3>
                <button type="button" class="btn btn-outline btn-sm" onclick="addTest()"><i class="fas fa-plus"></i> Add Test</button>
            </div>
            
            <div id="testContainer">
                <?php
                $tests = $template['lab_tests'] ?? [];
                if (!empty($tests)) {
                    foreach ($tests as $test) {
                        echo "<div class='test-row row mb-12 g-2'>
                            <div class='col-md-5'><input type='text' name='test_name[]' class='form-control' placeholder='Test Name' list='testList' value='{$test['name']}'></div>
                            <div class='col-md-6'><input type='text' name='test_instruction[]' class='form-control' placeholder='Instruction' value='{$test['instruction']}'></div>
                            <div class='col-md-1'><button type='button' class='btn btn-ghost text-danger' onclick='this.closest(\".test-row\").remove()'><i class=\"fas fa-trash\"></i></button></div>
                        </div>";
                    }
                }
                ?>
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
    <div class='medicine-row row mb-12 g-2'>
        <div class='col-md-3'><input type='text' name='med_name[]' class='form-control' placeholder='Medicine Name' list='medList'></div>
        <div class='col-md-2'><input type='text' name='med_dosage[]' class='form-control' placeholder='Dosage'></div>
        <div class='col-md-2'>
            <select name='med_frequency[]' class='form-control'>
                <option value='OD'>OD</option>
                <option value='BD'>BD</option>
                <option value='TDS'>TDS</option>
                <option value='QID'>QID</option>
                <option value='SOS'>SOS</option>
                <option value='HS'>HS</option>
            </select>
        </div>
        <div class='col-md-2'><input type='text' name='med_duration[]' class='form-control' placeholder='Duration'></div>
        <div class='col-md-2'><input type='text' name='med_instruction[]' class='form-control' placeholder='Instruction'></div>
        <div class='col-md-1'><button type='button' class='btn btn-ghost text-danger' onclick='this.closest(".medicine-row").remove()'><i class="fas fa-trash"></i></button></div>
    </div>`;
    document.getElementById('medicineContainer').insertAdjacentHTML('beforeend', html);
}

function addTest() {
    const html = `
    <div class='test-row row mb-12 g-2'>
        <div class='col-md-5'><input type='text' name='test_name[]' class='form-control' placeholder='Test Name' list='testList'></div>
        <div class='col-md-6'><input type='text' name='test_instruction[]' class='form-control' placeholder='Instruction'></div>
        <div class='col-md-1'><button type='button' class='btn btn-ghost text-danger' onclick='this.closest(".test-row").remove()'><i class="fas fa-trash"></i></button></div>
    </div>`;
    document.getElementById('testContainer').insertAdjacentHTML('beforeend', html);
}
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
