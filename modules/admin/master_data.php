<?php
/**
 * Master Data Management - Advanced Clinic Suite
 * Manages: Departments, Specialties, Medicines, Diagnoses, Lab Tests
 */
require_once dirname(dirname(__DIR__)) . '/config/session.php';
requireAuth();
requireRole([ROLE_SUPER_ADMIN, ROLE_ADMIN]);

$db = db();
$clinicId = getCurrentClinicId();

// Determine active tab
$tab = $_GET['tab'] ?? 'departments';
$validTabs = ['departments', 'specialties', 'medicines', 'diagnoses', 'lab_tests', 'services'];
if (!in_array($tab, $validTabs)) $tab = 'departments';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = intval($_POST['id'] ?? 0);
    
    try {
        switch ($tab) {
            case 'departments':
                if ($action === 'add') {
                    $db->query("INSERT INTO departments (clinic_id, name, description) VALUES (?, ?, ?)",
                        [$clinicId, sanitize($_POST['name']), sanitize($_POST['description'] ?? '')]);
                    setFlashMessage('success', 'Department added.');
                } elseif ($action === 'edit' && $id) {
                    $db->query("UPDATE departments SET name=?, description=? WHERE id=? AND clinic_id=?",
                        [sanitize($_POST['name']), sanitize($_POST['description'] ?? ''), $id, $clinicId]);
                    setFlashMessage('success', 'Department updated.');
                } elseif ($action === 'toggle' && $id) {
                    $db->query("UPDATE departments SET is_active = NOT is_active WHERE id=? AND clinic_id=?", [$id, $clinicId]);
                    setFlashMessage('success', 'Status toggled.');
                }
                break;

            case 'specialties':
                if ($action === 'add') {
                    $db->query("INSERT INTO specialties (name, code, description) VALUES (?, ?, ?)",
                        [sanitize($_POST['name']), sanitize($_POST['code'] ?? ''), sanitize($_POST['description'] ?? '')]);
                    setFlashMessage('success', 'Specialty added.');
                } elseif ($action === 'edit' && $id) {
                    $db->query("UPDATE specialties SET name=?, code=?, description=? WHERE id=?",
                        [sanitize($_POST['name']), sanitize($_POST['code'] ?? ''), sanitize($_POST['description'] ?? ''), $id]);
                    setFlashMessage('success', 'Specialty updated.');
                } elseif ($action === 'toggle' && $id) {
                    $db->query("UPDATE specialties SET is_active = NOT is_active WHERE id=?", [$id]);
                    setFlashMessage('success', 'Status toggled.');
                }
                break;

            case 'medicines':
                if ($action === 'add') {
                    $db->query("INSERT INTO medicines (name, generic_name, brand, dosage_form, strength, category) VALUES (?, ?, ?, ?, ?, ?)",
                        [sanitize($_POST['name']), sanitize($_POST['generic_name'] ?? ''), sanitize($_POST['brand'] ?? ''),
                         sanitize($_POST['dosage_form'] ?? 'Tablet'), sanitize($_POST['strength'] ?? ''), sanitize($_POST['category'] ?? '')]);
                    setFlashMessage('success', 'Medicine added.');
                } elseif ($action === 'edit' && $id) {
                    $db->query("UPDATE medicines SET name=?, generic_name=?, brand=?, dosage_form=?, strength=?, category=? WHERE id=?",
                        [sanitize($_POST['name']), sanitize($_POST['generic_name'] ?? ''), sanitize($_POST['brand'] ?? ''),
                         sanitize($_POST['dosage_form'] ?? 'Tablet'), sanitize($_POST['strength'] ?? ''), sanitize($_POST['category'] ?? ''), $id]);
                    setFlashMessage('success', 'Medicine updated.');
                } elseif ($action === 'toggle' && $id) {
                    $db->query("UPDATE medicines SET is_active = NOT is_active WHERE id=?", [$id]);
                    setFlashMessage('success', 'Status toggled.');
                }
                break;

            case 'diagnoses':
                if ($action === 'add') {
                    $db->query("INSERT INTO diagnoses (name, icd_code, category, description) VALUES (?, ?, ?, ?)",
                        [sanitize($_POST['name']), sanitize($_POST['icd_code'] ?? ''), sanitize($_POST['category'] ?? ''), sanitize($_POST['description'] ?? '')]);
                    setFlashMessage('success', 'Diagnosis added.');
                } elseif ($action === 'edit' && $id) {
                    $db->query("UPDATE diagnoses SET name=?, icd_code=?, category=?, description=? WHERE id=?",
                        [sanitize($_POST['name']), sanitize($_POST['icd_code'] ?? ''), sanitize($_POST['category'] ?? ''), sanitize($_POST['description'] ?? ''), $id]);
                    setFlashMessage('success', 'Diagnosis updated.');
                } elseif ($action === 'toggle' && $id) {
                    $db->query("UPDATE diagnoses SET is_active = NOT is_active WHERE id=?", [$id]);
                    setFlashMessage('success', 'Status toggled.');
                }
                break;

            case 'lab_tests':
                if ($action === 'add') {
                    $db->query("INSERT INTO lab_tests (name, code, category, price, description) VALUES (?, ?, ?, ?, ?)",
                        [sanitize($_POST['name']), sanitize($_POST['code'] ?? ''), sanitize($_POST['category'] ?? ''),
                         floatval($_POST['price'] ?? 0), sanitize($_POST['description'] ?? '')]);
                    setFlashMessage('success', 'Lab test added.');
                } elseif ($action === 'edit' && $id) {
                    $db->query("UPDATE lab_tests SET name=?, code=?, category=?, price=?, description=? WHERE id=?",
                        [sanitize($_POST['name']), sanitize($_POST['code'] ?? ''), sanitize($_POST['category'] ?? ''),
                         floatval($_POST['price'] ?? 0), sanitize($_POST['description'] ?? ''), $id]);
                    setFlashMessage('success', 'Lab test updated.');
                } elseif ($action === 'toggle' && $id) {
                    $db->query("UPDATE lab_tests SET is_active = NOT is_active WHERE id=?", [$id]);
                    setFlashMessage('success', 'Status toggled.');
                }
                break;

            case 'services':
                if ($action === 'add') {
                    $db->query("INSERT INTO services (clinic_id, name, category, default_price, description) VALUES (?, ?, ?, ?, ?)",
                        [$clinicId, sanitize($_POST['name']), sanitize($_POST['category'] ?? 'other'),
                         floatval($_POST['default_price'] ?? 0), sanitize($_POST['description'] ?? '')]);
                    setFlashMessage('success', 'Service added.');
                } elseif ($action === 'edit' && $id) {
                    $db->query("UPDATE services SET name=?, category=?, default_price=?, description=? WHERE id=? AND clinic_id=?",
                        [sanitize($_POST['name']), sanitize($_POST['category'] ?? 'other'),
                         floatval($_POST['default_price'] ?? 0), sanitize($_POST['description'] ?? ''), $id, $clinicId]);
                    setFlashMessage('success', 'Service updated.');
                } elseif ($action === 'toggle' && $id) {
                    $db->query("UPDATE services SET is_active = NOT is_active WHERE id=? AND clinic_id=?", [$id, $clinicId]);
                    setFlashMessage('success', 'Status toggled.');
                }
                break;
        }
        header("Location: " . BASE_URL . "/modules/admin/master_data.php?tab=$tab");
        exit;
    } catch (Exception $e) {
        setFlashMessage('error', 'Error: ' . $e->getMessage());
    }
}

$pageTitle = 'Master Data';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';

// Fetch data for current tab
switch ($tab) {
    case 'departments':
        $items = $db->fetchAll("SELECT * FROM departments WHERE clinic_id = ? ORDER BY name", [$clinicId]);
        break;
    case 'specialties':
        $items = $db->fetchAll("SELECT * FROM specialties ORDER BY name");
        break;
    case 'medicines':
        $items = $db->fetchAll("SELECT * FROM medicines ORDER BY name");
        break;
    case 'diagnoses':
        $items = $db->fetchAll("SELECT * FROM diagnoses ORDER BY name");
        break;
    case 'lab_tests':
        $items = $db->fetchAll("SELECT * FROM lab_tests ORDER BY name");
        break;
    case 'services':
        $items = $db->fetchAll("SELECT * FROM services WHERE clinic_id = ? ORDER BY category, name", [$clinicId]);
        break;
}

$tabConfig = [
    'departments'  => ['icon' => 'fa-building',       'label' => 'Departments',  'color' => 'var(--primary)'],
    'specialties'  => ['icon' => 'fa-stethoscope',     'label' => 'Specialties',  'color' => 'var(--accent)'],
    'medicines'    => ['icon' => 'fa-pills',           'label' => 'Medicines',    'color' => 'var(--success)'],
    'diagnoses'    => ['icon' => 'fa-diagnoses',       'label' => 'Diagnoses',    'color' => 'var(--warning)'],
    'lab_tests'    => ['icon' => 'fa-flask',           'label' => 'Lab Tests',    'color' => 'var(--info)'],
    'services'     => ['icon' => 'fa-concierge-bell',  'label' => 'Services',     'color' => 'var(--danger)'],
];

$dosageForms = ['Tablet','Capsule','Syrup','Injection','Cream','Ointment','Drops','Inhaler','Powder','Gel','Spray','Suppository','Patch','Other'];
$serviceTypes = [
    'consultation' => 'Consultation', 'procedure' => 'Procedure', 'medicine' => 'Medicine',
    'lab_test' => 'Lab Test', 'vaccination' => 'Vaccination', 'dental' => 'Dental',
    'service' => 'Service', 'other' => 'Other'
];
?>

<div class="content-header">
    <div>
        <ul class="breadcrumb">
            <li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li>
            <li><a href="<?= BASE_URL ?>/modules/admin/index.php">Admin</a></li>
            <li>Master Data</li>
        </ul>
        <h1><i class="fas fa-database" style="color: var(--primary);"></i> Master Data Management</h1>
    </div>
    <button class="btn btn-primary" onclick="showMasterAddModal()">
        <i class="fas fa-plus"></i> Add <?= $tabConfig[$tab]['label'] ?>
    </button>
</div>

<!-- Tab Navigation -->
<div class="tabs mb-24" style="flex-wrap: wrap;">
    <?php foreach ($tabConfig as $key => $cfg): ?>
    <a href="?tab=<?= $key ?>" class="tab-btn <?= $tab === $key ? 'active' : '' ?>" style="text-decoration: none;">
        <i class="fas <?= $cfg['icon'] ?>" style="margin-right: 4px;"></i> <?= $cfg['label'] ?>
        <span class="badge" style="margin-left: 6px; font-size: 10px;"><?= $tab === $key ? count($items) : '' ?></span>
    </a>
    <?php endforeach; ?>
</div>

<!-- Data Table -->
<div class="card">
    <div class="card-body" style="overflow-x: auto;">
        <table class="table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Name</th>
                    <?php if ($tab === 'specialties'): ?><th>Code</th><?php endif; ?>
                    <?php if ($tab === 'medicines'): ?><th>Generic Name</th><th>Brand</th><th>Form</th><th>Strength</th><?php endif; ?>
                    <?php if ($tab === 'diagnoses'): ?><th>ICD Code</th><th>Category</th><?php endif; ?>
                    <?php if ($tab === 'lab_tests'): ?><th>Code</th><th>Category</th><th>Price</th><?php endif; ?>
                    <?php if ($tab === 'services'): ?><th>Category</th><th>Default Price</th><th>Description</th><?php endif; ?>
                    <?php if ($tab === 'departments'): ?><th>Description</th><?php endif; ?>
                    <th>Status</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                <tr><td colspan="10" class="text-center text-muted" style="padding: 40px;">No <?= strtolower($tabConfig[$tab]['label']) ?> found. Click "Add" to create one.</td></tr>
                <?php else: ?>
                <?php foreach ($items as $i => $item): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td class="font-semibold"><?= sanitizeOutput($item['name']) ?></td>

                    <?php if ($tab === 'specialties'): ?>
                    <td><code><?= sanitizeOutput($item['code'] ?? '-') ?></code></td>
                    <?php endif; ?>

                    <?php if ($tab === 'medicines'): ?>
                    <td><?= sanitizeOutput($item['generic_name'] ?? '-') ?></td>
                    <td><?= sanitizeOutput($item['brand'] ?? '-') ?></td>
                    <td><span class="badge badge-primary"><?= sanitizeOutput($item['dosage_form'] ?? '-') ?></span></td>
                    <td><?= sanitizeOutput($item['strength'] ?? '-') ?></td>
                    <?php endif; ?>

                    <?php if ($tab === 'diagnoses'): ?>
                    <td><code><?= sanitizeOutput($item['icd_code'] ?? '-') ?></code></td>
                    <td><?= sanitizeOutput($item['category'] ?? '-') ?></td>
                    <?php endif; ?>

                    <?php if ($tab === 'lab_tests'): ?>
                    <td><code><?= sanitizeOutput($item['code'] ?? '-') ?></code></td>
                    <td><?= sanitizeOutput($item['category'] ?? '-') ?></td>
                    <td>₹ <?= number_format($item['price'] ?? 0, 2) ?></td>
                    <?php endif; ?>

                    <?php if ($tab === 'services'): ?>
                    <td><span class="badge badge-primary"><?= $serviceTypes[$item['category']] ?? ucfirst(str_replace('_',' ',$item['category'])) ?></span></td>
                    <td>₹ <?= number_format($item['default_price'] ?? 0, 2) ?></td>
                    <td class="text-muted"><?= sanitizeOutput(mb_strimwidth($item['description'] ?? '-', 0, 40, '...')) ?></td>
                    <?php endif; ?>

                    <?php if ($tab === 'departments'): ?>
                    <td class="text-muted"><?= sanitizeOutput(mb_strimwidth($item['description'] ?? '-', 0, 60, '...')) ?></td>
                    <?php endif; ?>

                    <td>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= $item['id'] ?>">
                            <button type="submit" class="badge <?= $item['is_active'] ? 'badge-success' : 'badge-danger' ?>" style="cursor:pointer; border:none;">
                                <?= $item['is_active'] ? 'Active' : 'Inactive' ?>
                            </button>
                        </form>
                    </td>
                    <td style="text-align: right;">
                        <button class="btn btn-sm btn-outline" onclick='showMasterEditModal(<?= json_encode($item) ?>)'>
                            <i class="fas fa-pen"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add/Edit Modal -->
<div id="masterModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; z-index:9999; background:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
    <div class="card" style="width: 500px; max-width: 95vw; max-height: 90vh; overflow-y: auto; animation: slideUp 0.3s ease; margin: auto;">
        <div class="card-header">
            <h3 id="modalTitle"><i class="fas <?= $tabConfig[$tab]['icon'] ?>" style="color: <?= $tabConfig[$tab]['color'] ?>;"></i> <span id="modalAction">Add</span> <?= rtrim($tabConfig[$tab]['label'], 's') ?></h3>
            <button type="button" class="btn btn-sm btn-ghost" onclick="hideMasterModal()" style="font-size: 18px;">&times;</button>
        </div>
        <form method="POST" id="masterForm">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="formId" value="">
            <div class="card-body">

                <!-- Common: Name -->
                <div class="form-group">
                    <label class="form-label">Name <span class="required">*</span></label>
                    <input type="text" name="name" id="f_name" class="form-control" required>
                </div>

                <?php if ($tab === 'specialties'): ?>
                <div class="form-group">
                    <label class="form-label">Code</label>
                    <input type="text" name="code" id="f_code" class="form-control" placeholder="e.g. CARDIO">
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" id="f_description" class="form-control" rows="2"></textarea>
                </div>
                <?php endif; ?>

                <?php if ($tab === 'departments'): ?>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" id="f_description" class="form-control" rows="2"></textarea>
                </div>
                <?php endif; ?>

                <?php if ($tab === 'medicines'): ?>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Generic Name</label>
                        <input type="text" name="generic_name" id="f_generic_name" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Brand</label>
                        <input type="text" name="brand" id="f_brand" class="form-control">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Dosage Form</label>
                        <select name="dosage_form" id="f_dosage_form" class="form-control">
                            <?php foreach ($dosageForms as $df): ?>
                            <option value="<?= $df ?>"><?= $df ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Strength</label>
                        <input type="text" name="strength" id="f_strength" class="form-control" placeholder="e.g. 500mg">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Category</label>
                    <input type="text" name="category" id="f_category" class="form-control" placeholder="e.g. Antibiotic">
                </div>
                <?php endif; ?>

                <?php if ($tab === 'diagnoses'): ?>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">ICD Code</label>
                        <input type="text" name="icd_code" id="f_icd_code" class="form-control" placeholder="e.g. J06.9">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Category</label>
                        <input type="text" name="category" id="f_category" class="form-control">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" id="f_description" class="form-control" rows="2"></textarea>
                </div>
                <?php endif; ?>

                <?php if ($tab === 'lab_tests'): ?>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Code</label>
                        <input type="text" name="code" id="f_code" class="form-control" placeholder="e.g. CBC">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Category</label>
                        <input type="text" name="category" id="f_category" class="form-control">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Price (₹)</label>
                    <input type="number" name="price" id="f_price" class="form-control" step="0.01" value="0">
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" id="f_description" class="form-control" rows="2"></textarea>
                </div>
                <?php endif; ?>

                <?php if ($tab === 'services'): ?>
                <div class="form-group">
                    <label class="form-label">Category <span class="required">*</span></label>
                    <input type="text" name="category" id="f_category" class="form-control" required
                           list="categoryList" placeholder="Select or type new category">
                    <datalist id="categoryList">
                        <?php foreach ($serviceTypes as $k => $v): ?>
                        <option value="<?= $k ?>"><?= $v ?></option>
                        <?php endforeach; ?>
                    </datalist>
                    <small class="text-muted" style="font-size: 11px; margin-top: 4px; display: block;">
                        Pick an existing type or type a new one (e.g. <em>physiotherapy</em>, <em>radiology</em>)
                    </small>
                </div>
                <div class="form-group">
                    <label class="form-label">Default Price (₹)</label>
                    <input type="number" name="default_price" id="f_default_price" class="form-control" step="0.01" value="0">
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" id="f_description" class="form-control" rows="2"></textarea>
                </div>
                <?php endif; ?>

            </div>
            <div class="card-footer d-flex justify-end gap-12">
                <button type="button" class="btn btn-outline" onclick="hideMasterModal()">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button>
            </div>
        </form>
    </div>
</div>

<style>
@keyframes slideUp {
    from { transform: translateY(30px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}
#masterModal .card { margin: 0; }
</style>

<script>
function showMasterAddModal() {
    document.getElementById('formAction').value = 'add';
    document.getElementById('formId').value = '';
    const titleAction = document.getElementById('modalAction');
    if (titleAction) titleAction.textContent = 'Add';
    document.getElementById('masterForm').reset();
    document.getElementById('masterModal').style.display = 'flex';
}

function showMasterEditModal(item) {
    document.getElementById('formAction').value = 'edit';
    document.getElementById('formId').value = item.id;
    const titleAction = document.getElementById('modalAction');
    if (titleAction) titleAction.textContent = 'Edit';
    
    // Populate all fields
    const fields = ['name', 'code', 'description', 'generic_name', 'brand', 'dosage_form', 'strength', 'category', 'icd_code', 'price', 'default_price'];
    fields.forEach(f => {
        const el = document.getElementById('f_' + f);
        if (el) el.value = item[f] || '';
    });
    
    document.getElementById('masterModal').style.display = 'flex';
}

function hideMasterModal() {
    document.getElementById('masterModal').style.display = 'none';
}

// Close on backdrop click
document.getElementById('masterModal').addEventListener('click', function(e) {
    if (e.target === this) hideMasterModal();
});

// Close on Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') hideMasterModal();
});
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
