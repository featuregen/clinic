<?php
/**
 * Prescription Templates List
 */
$pageTitle = 'Templates';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';
requirePermission('prescriptions.templates');

$db = db();
$clinicId = getCurrentClinicId();
$userId = $_SESSION['user_id'];
$isAdmin = in_array(getCurrentUserRole(), ['admin', 'clinic_admin', 'super_admin', ROLE_ADMIN, ROLE_SUPER_ADMIN]);

// Fetch templates logic
$where = "WHERE t.clinic_id = ?";
$params = [$clinicId];

if (!$isAdmin) {
    // Doctors see their own + clinic templates
    $where .= " AND (t.scope = 'clinic' OR t.doctor_id = ?)";
    $params[] = getCurrentDoctorId() ?: 0;
}

$templates = $db->fetchAll(
    "SELECT t.*, u.full_name as created_by_name 
     FROM prescription_templates t 
     LEFT JOIN users u ON t.doctor_id = u.id 
     $where 
     ORDER BY t.scope, t.name", 
    $params
);

// Handle delete success/error messages from query params
if (isset($_GET['msg']) && $_GET['msg'] === 'deleted') {
    echo '<script>document.addEventListener("DOMContentLoaded", function(){ showToast("Template deleted successfully", "success"); });</script>';
}
if (isset($_GET['error'])) {
    echo '<script>document.addEventListener("DOMContentLoaded", function(){ showToast("Failed to delete template", "error"); });</script>';
}
?>

<div class="content-header">
    <div>
        <ul class="breadcrumb">
            <li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li>
            <li>Templates</li>
        </ul>
        <h1>Prescription Templates</h1>
    </div>
    <a href="<?= BASE_URL ?>/modules/templates/add.php" class="btn btn-primary">
        <i class="fas fa-plus"></i> New Template
    </a>
</div>

<div class="grid-3 gap-24">
    <?php if (empty($templates)): ?>
    <div class="card" style="grid-column: span 3;">
        <div class="empty-state">
            <i class="fas fa-file-prescription"></i>
            <h3>No templates found</h3>
            <p>Create templates to speed up prescription writing</p>
            <a href="add.php" class="btn btn-primary mt-16">Create Template</a>
        </div>
    </div>
    <?php else: foreach ($templates as $t): 
        $tData = json_decode($t['template_data'] ?? '[]', true);
        $meds = !empty($tData['medicines']) ? $tData['medicines'] : json_decode($t['medicines'] ?? '[]', true);
        $tests = !empty($tData['lab_tests']) ? $tData['lab_tests'] : json_decode($t['lab_tests'] ?? '[]', true);
        $medCount = is_array($meds) ? count($meds) : 0;
        $testCount = is_array($tests) ? count($tests) : 0;
    ?>
    <div class="card" style="overflow: visible;">
        <div class="card-body">
            <div class="d-flex justify-between align-start mb-12">
                <div>
                    <span class="badge badge-<?= $t['scope'] === 'clinic' ? 'primary' : 'secondary' ?> mb-8">
                        <?= ucfirst($t['scope']) ?>
                    </span>
                    <h3 class="mb-4"><?= sanitizeOutput($t['name']) ?></h3>
                    <p class="text-sm text-muted"><?= sanitizeOutput($t['description'] ?? '') ?></p>
                </div>
                <div class="dropdown" style="position: relative;">
                    <button class="btn btn-sm btn-ghost btn-icon" onclick="toggleTemplateMenu(this); event.stopPropagation();">
                        <i class="fas fa-ellipsis-v"></i>
                    </button>
                    <div class="dropdown-menu dropdown-menu-right" style="min-width: 160px;">
                        <a href="add.php?id=<?= $t['id'] ?>" class="dropdown-item">
                            <i class="fas fa-pen" style="color: var(--primary);"></i> Edit Template
                        </a>
                        <div class="dropdown-divider"></div>
                        <a href="#" onclick="confirmDelete(<?= $t['id'] ?>, '<?= addslashes(sanitizeOutput($t['name'])) ?>'); return false;" class="dropdown-item text-danger">
                            <i class="fas fa-trash"></i> Delete Template
                        </a>
                    </div>
                </div>
            </div>
            
            <div class="d-flex gap-16 mt-16 text-sm text-muted">
                <span><i class="fas fa-pills text-success"></i> <?= $medCount ?> Medicines</span>
                <span><i class="fas fa-flask text-info"></i> <?= $testCount ?> Tests</span>
            </div>
            
            <?php if ($t['scope'] === 'personal' && $t['doctor_id']): ?>
            <div class="mt-12 text-xs text-muted">
                Personal Template
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; endif; ?>
</div>

<script>
function toggleTemplateMenu(btn) {
    const menu = btn.nextElementSibling;
    // Close all other open menus
    document.querySelectorAll('.dropdown-menu.show').forEach(m => {
        if (m !== menu) m.classList.remove('show');
    });
    menu.classList.toggle('show');
}

// Close dropdown on outside click
document.addEventListener('click', function() {
    document.querySelectorAll('.dropdown-menu.show').forEach(m => m.classList.remove('show'));
});

function confirmDelete(id, name) {
    // Close dropdown first
    document.querySelectorAll('.dropdown-menu.show').forEach(m => m.classList.remove('show'));
    
    if (confirm('Delete template "' + name + '"? This cannot be undone.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'delete.php';
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'id';
        input.value = id;
        form.appendChild(input);
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
