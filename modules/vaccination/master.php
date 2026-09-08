<?php
/**
 * Vaccine Master Data - Advanced Clinic Suite
 * Manages: Vaccines, Vaccine Schedules
 */
require_once dirname(dirname(__DIR__)) . '/config/session.php';
requireAuth();
requireRole([ROLE_SUPER_ADMIN, ROLE_ADMIN]);

$db = db();
$clinicId = getCurrentClinicId();

// Determine active tab
$tab = $_GET['tab'] ?? 'vaccines';
$validTabs = ['vaccines', 'vaccine_schedules'];
if (!in_array($tab, $validTabs)) $tab = 'vaccines';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = intval($_POST['id'] ?? 0);
    
    try {
        switch ($tab) {
            case 'vaccines':
                if ($action === 'add') {
                    $db->query("INSERT INTO vaccines (name, code, manufacturer, description) VALUES (?, ?, ?, ?)",
                        [sanitize($_POST['name']), sanitize($_POST['code'] ?? ''), sanitize($_POST['manufacturer'] ?? ''), sanitize($_POST['description'] ?? '')]);
                    setFlashMessage('success', 'Vaccine added.');
                } elseif ($action === 'edit' && $id) {
                    $db->query("UPDATE vaccines SET name=?, code=?, manufacturer=?, description=? WHERE id=?",
                        [sanitize($_POST['name']), sanitize($_POST['code'] ?? ''), sanitize($_POST['manufacturer'] ?? ''), sanitize($_POST['description'] ?? ''), $id]);
                    setFlashMessage('success', 'Vaccine updated.');
                } elseif ($action === 'toggle' && $id) {
                    $db->query("UPDATE vaccines SET is_active = NOT is_active WHERE id=?", [$id]);
                    setFlashMessage('success', 'Status toggled.');
                }
                break;

            case 'vaccine_schedules':
                if ($action === 'add') {
                    $db->query("INSERT INTO vaccine_schedules (vaccine_id, dose_number, recommended_age_months, route, notes) VALUES (?, ?, ?, ?, ?)",
                        [intval($_POST['vaccine_id']), intval($_POST['dose_number']), floatval($_POST['recommended_age_months']), 
                         sanitize($_POST['route'] ?? ''), sanitize($_POST['notes'] ?? '')]);
                    setFlashMessage('success', 'Schedule added.');
                } elseif ($action === 'edit' && $id) {
                    $db->query("UPDATE vaccine_schedules SET vaccine_id=?, dose_number=?, recommended_age_months=?, route=?, notes=? WHERE id=?",
                        [intval($_POST['vaccine_id']), intval($_POST['dose_number']), floatval($_POST['recommended_age_months']), 
                         sanitize($_POST['route'] ?? ''), sanitize($_POST['notes'] ?? ''), $id]);
                    setFlashMessage('success', 'Schedule updated.');
                } elseif ($action === 'delete' && $id) {
                    $db->query("DELETE FROM vaccine_schedules WHERE id=?", [$id]);
                    setFlashMessage('success', 'Schedule deleted.');
                }
                break;
        }
        header("Location: " . BASE_URL . "/modules/vaccination/master.php?tab=$tab");
        exit;
    } catch (Exception $e) {
        setFlashMessage('error', 'Error: ' . $e->getMessage());
    }
}

$pageTitle = 'Vaccination Settings';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';

// Fetch data for current tab
switch ($tab) {
    case 'vaccines':
        $items = $db->fetchAll("SELECT * FROM vaccines ORDER BY name");
        break;
    case 'vaccine_schedules':
        $items = $db->fetchAll("SELECT vs.*, v.name as vaccine_name FROM vaccine_schedules vs LEFT JOIN vaccines v ON vs.vaccine_id = v.id ORDER BY vs.recommended_age_months");
        break;
}

// Fetch lists for dropdowns
$vaccineList = [];
if ($tab === 'vaccine_schedules') {
    $vaccineList = $db->fetchAll("SELECT id, name FROM vaccines WHERE is_active = 1 ORDER BY name");
}

$tabConfig = [
    'vaccines'     => ['icon' => 'fa-syringe',         'label' => 'Vaccines',     'color' => '#E91E63'],
    'vaccine_schedules' => ['icon' => 'fa-calendar-alt', 'label' => 'Schedules',  'color' => '#9C27B0'],
];

?>

<div class="content-header">
    <div>
        <ul class="breadcrumb">
            <li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li>
            <li><a href="<?= BASE_URL ?>/modules/vaccination/schedule.php">Vaccination</a></li>
            <li>Settings</li>
        </ul>
        <h1><i class="fas fa-syringe" style="color: var(--primary);"></i> Vaccination Settings</h1>
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
                    <?php if ($tab !== 'vaccine_schedules'): ?><th>Name</th><?php endif; ?>
                    <?php if ($tab === 'vaccines'): ?><th>Code</th><th>Manufacturer</th><?php endif; ?>
                    <?php if ($tab === 'vaccine_schedules'): ?><th>Vaccine</th><th>Dose #</th><th>Age (Months)</th><th>Route</th><?php endif; ?>
                    
                    <?php if ($tab !== 'vaccine_schedules'): ?><th>Status</th><?php endif; ?>
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
                    
                    <?php if ($tab !== 'vaccine_schedules'): ?>
                    <td class="font-semibold"><?= sanitizeOutput($item['name']) ?></td>
                    <?php endif; ?>

                    <?php if ($tab === 'vaccines'): ?>
                    <td><code><?= sanitizeOutput($item['code'] ?? '-') ?></code></td>
                    <td><?= sanitizeOutput($item['manufacturer'] ?? '-') ?></td>
                    <?php endif; ?>

                    <?php if ($tab === 'vaccine_schedules'): ?>
                    <td class="font-semibold"><?= sanitizeOutput($item['vaccine_name']) ?></td>
                    <td><span class="badge badge-info">Dose <?= $item['dose_number'] ?></span></td>
                    <td><?= $item['recommended_age_months'] ?> months</td>
                    <td><?= sanitizeOutput($item['route'] ?? '-') ?></td>
                    <?php endif; ?>

                    <?php if ($tab !== 'vaccine_schedules'): ?>
                    <td>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= $item['id'] ?>">
                            <button type="submit" class="badge <?= $item['is_active'] ? 'badge-success' : 'badge-danger' ?>" style="cursor:pointer; border:none;">
                                <?= $item['is_active'] ? 'Active' : 'Inactive' ?>
                            </button>
                        </form>
                    </td>
                    <?php endif; ?>
                    
                    <td style="text-align: right;">
                        <button class="btn btn-sm btn-outline" onclick='showMasterEditModal(<?= json_encode($item) ?>)'>
                            <i class="fas fa-pen"></i>
                        </button>
                        <?php if ($tab === 'vaccine_schedules'): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete schedule?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $item['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline text-danger">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add/Edit Modal -->
<div id="masterModal" style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
    <div class="card" style="width: 500px; max-width: 95vw; max-height: 90vh; overflow-y: auto; animation: slideUp 0.3s ease;">
        <div class="card-header">
            <h3 id="modalTitle"><i class="fas <?= $tabConfig[$tab]['icon'] ?>" style="color: <?= $tabConfig[$tab]['color'] ?>;"></i> <span id="modalAction">Add</span> <?= rtrim($tabConfig[$tab]['label'], 's') ?></h3>
            <button type="button" class="btn btn-sm btn-ghost" onclick="hideMasterModal()" style="font-size: 18px;">&times;</button>
        </div>
        <form method="POST" id="masterForm">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="formId" value="">
            <div class="card-body">

                <?php if ($tab === 'vaccines'): ?>
                <div class="form-group">
                    <label class="form-label">Name <span class="required">*</span></label>
                    <input type="text" name="name" id="f_name" class="form-control" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Code</label>
                        <input type="text" name="code" id="f_code" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Manufacturer</label>
                        <input type="text" name="manufacturer" id="f_manufacturer" class="form-control">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" id="f_description" class="form-control" rows="2"></textarea>
                </div>
                <?php endif; ?>

                <?php if ($tab === 'vaccine_schedules'): ?>
                <div class="form-group">
                    <label class="form-label">Vaccine <span class="required">*</span></label>
                    <select name="vaccine_id" id="f_vaccine_id" class="form-control" required>
                        <option value="">Select Vaccine</option>
                        <?php foreach ($vaccineList as $v): ?>
                        <option value="<?= $v['id'] ?>"><?= sanitizeOutput($v['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Dose Number</label>
                        <input type="number" name="dose_number" id="f_dose_number" class="form-control" value="1" min="1">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Age (Months)</label>
                        <input type="number" name="recommended_age_months" id="f_recommended_age_months" class="form-control" step="0.1" value="0">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Route</label>
                    <select name="route" id="f_route" class="form-control">
                        <option value="IM">Intramuscular (IM)</option>
                        <option value="SC">Subcutaneous (SC)</option>
                        <option value="ID">Intradermal (ID)</option>
                        <option value="Oral">Oral</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" id="f_notes" class="form-control" rows="2"></textarea>
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
    const fields = ['name', 'code', 'description', 'manufacturer', 'vaccine_id', 'dose_number', 'recommended_age_months', 'route', 'notes'];
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
