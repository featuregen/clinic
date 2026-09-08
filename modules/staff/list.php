<?php
/**
 * Staff List - Advanced Clinic Suite
 */
$pageTitle = 'Staff';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';
requirePermission('staff.view');

$db = db();
$clinicId = getCurrentClinicId();

$staff = $db->fetchAll(
    "SELECT u.*, r.name as role_name FROM users u
     JOIN roles r ON u.role_id = r.id
     WHERE u.clinic_id = ? ORDER BY u.full_name", [$clinicId]
);
?>

<div class="content-header">
    <div>
        <ul class="breadcrumb"><li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li><li>Staff</li></ul>
        <h1>Staff Management</h1>
    </div>
    <?php if (hasPermission('staff.create')): ?>
    <a href="<?= BASE_URL ?>/modules/staff/add.php" class="btn btn-primary"><i class="fas fa-user-plus"></i> Add Staff</a>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header"><h3>All Staff <span class="badge badge-primary"><?= count($staff) ?></span></h3></div>
    <div class="card-body p-0">
        <?php if (empty($staff)): ?>
        <div class="empty-state"><i class="fas fa-id-badge"></i><h3>No staff found</h3></div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Staff</th><th>Role</th><th>Email</th><th>Phone</th><th>Status</th><th>Last Login</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($staff as $s): ?>
                <tr>
                    <td>
                        <div class="patient-cell">
                            <div class="user-avatar" style="width: 36px; height: 36px; font-size: 12px;"><?= getInitials($s['full_name']) ?></div>
                            <div>
                                <div class="font-semibold"><?= sanitizeOutput($s['full_name']) ?></div>
                                <div class="text-muted" style="font-size: 11px;">@<?= sanitizeOutput($s['username']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td><span class="badge badge-secondary"><?= ucfirst(str_replace('_', ' ', $s['role_name'])) ?></span></td>
                    <td><?= sanitizeOutput($s['email'] ?? '-') ?></td>
                    <td><?= sanitizeOutput($s['phone'] ?? '-') ?></td>
                    <td><span class="badge badge-<?= $s['is_active'] ? 'success' : 'danger' ?>"><?= $s['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                    <td><?= $s['last_login'] ? timeAgo($s['last_login']) : 'Never' ?></td>
                    <td>
                        <?php if (hasPermission('staff.edit')): ?>
                        <a href="<?= BASE_URL ?>/modules/staff/add.php?id=<?= $s['id'] ?>" class="btn btn-sm btn-ghost"><i class="fas fa-pen"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
