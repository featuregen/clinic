<?php
/**
 * Prescription Templates List
 */
$pageTitle = 'Templates';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';
requirePermission('templates.view');

$db = db();
$clinicId = getCurrentClinicId();
$userId = $_SESSION['user_id'];
$isAdmin = (getCurrentUserRole() === 'clinic_admin' || getCurrentUserRole() === 'super_admin');

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
        $meds = json_decode($t['medicines'] ?? '[]', true);
        $tests = json_decode($t['lab_tests'] ?? '[]', true);
        $medCount = is_array($meds) ? count($meds) : 0;
        $testCount = is_array($tests) ? count($tests) : 0;
    ?>
    <div class="card">
        <div class="card-body">
            <div class="d-flex justify-between align-start mb-12">
                <div>
                    <span class="badge badge-<?= $t['scope'] === 'clinic' ? 'primary' : 'secondary' ?> mb-8">
                        <?= ucfirst($t['scope']) ?>
                    </span>
                    <h3 class="mb-4"><?= sanitizeOutput($t['name']) ?></h3>
                    <p class="text-sm text-muted"><?= sanitizeOutput($t['description'] ?? '') ?></p>
                </div>
                <div class="dropdown">
                    <button class="btn btn-sm btn-ghost btn-icon"><i class="fas fa-ellipsis-v"></i></button>
                    <div class="dropdown-menu dropdown-menu-right">
                        <a href="add.php?id=<?= $t['id'] ?>" class="dropdown-item"><i class="fas fa-pen"></i> Edit</a>
                        <a href="#" onclick="confirmDelete(<?= $t['id'] ?>)" class="dropdown-item text-danger"><i class="fas fa-trash"></i> Delete</a>
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
function confirmDelete(id) {
    if (confirm('Are you sure you want to delete this template?')) {
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
