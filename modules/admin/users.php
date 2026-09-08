<?php
/**
 * User Management - Advanced Clinic Suite
 * Super Admin Only
 */
$pageTitle = 'User Management';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';

$role = getCurrentUserRole();
if ($role !== ROLE_SUPER_ADMIN) {
    header('Location: ' . BASE_URL . '/modules/auth/403.php');
    exit;
}

$db = db();
$clinicId = getCurrentClinicId();

// Filters
$search = sanitize($_GET['search'] ?? '');
$roleFilter = sanitize($_GET['role'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));

$where = "WHERE u.clinic_id = ?";
$params = [$clinicId];

if ($search) {
    $where .= " AND (u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
    $s = "%$search%";
    $params = array_merge($params, [$s, $s, $s, $s]);
}
if ($roleFilter) { $where .= " AND r.name = ?"; $params[] = $roleFilter; }
if ($statusFilter !== '') { $where .= " AND u.is_active = ?"; $params[] = intval($statusFilter); }

$total = $db->fetch("SELECT COUNT(*) as c FROM users u JOIN roles r ON u.role_id = r.id $where", $params)['c'];
$pagination = paginate($total, $page);

$users = $db->fetchAll(
    "SELECT u.*, r.name as role_name, r.display_name as role_display
     FROM users u JOIN roles r ON u.role_id = r.id
     $where ORDER BY u.full_name
     LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}", $params
);

// Get all roles for filter dropdown
$allRoles = $db->fetchAll("SELECT name, display_name FROM roles WHERE is_active = 1 ORDER BY display_name");
?>

<div class="content-header">
    <div>
        <ul class="breadcrumb">
            <li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li>
            <li><a href="<?= BASE_URL ?>/modules/admin/index.php">System Admin</a></li>
            <li>User Management</li>
        </ul>
        <h1>User Management</h1>
    </div>
    <div class="d-flex gap-8">
        <a href="<?= BASE_URL ?>/modules/staff/add.php" class="btn btn-primary"><i class="fas fa-user-plus"></i> Add Staff</a>
        <a href="<?= BASE_URL ?>/modules/doctors/add.php" class="btn btn-success"><i class="fas fa-user-md"></i> Add Doctor</a>
    </div>
</div>

<!-- Filters -->
<div class="card mb-24">
    <div class="card-body">
        <form method="GET" class="d-flex gap-12 align-center flex-wrap">
            <div class="header-search" style="max-width: 260px; flex: 1;">
                <i class="fas fa-search"></i>
                <input type="text" name="search" value="<?= sanitizeOutput($search) ?>"
                       placeholder="Search name, username, email..." class="form-control" style="padding-left: 38px;">
            </div>
            <select name="role" class="form-control" style="width: auto;">
                <option value="">All Roles</option>
                <?php foreach ($allRoles as $r): ?>
                <option value="<?= $r['name'] ?>" <?= $roleFilter === $r['name'] ? 'selected' : '' ?>><?= sanitizeOutput($r['display_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="status" class="form-control" style="width: auto;">
                <option value="">All Status</option>
                <option value="1" <?= $statusFilter === '1' ? 'selected' : '' ?>>Active</option>
                <option value="0" <?= $statusFilter === '0' ? 'selected' : '' ?>>Inactive</option>
            </select>
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i></button>
            <a href="<?= BASE_URL ?>/modules/admin/users.php" class="btn btn-outline btn-sm"><i class="fas fa-times"></i></a>
        </form>
    </div>
</div>

<!-- Users Table -->
<div class="card">
    <div class="card-header"><h3>All Users <span class="badge badge-primary"><?= $total ?></span></h3></div>
    <div class="card-body p-0">
        <?php if (empty($users)): ?>
        <div class="empty-state"><i class="fas fa-users"></i><h3>No users found</h3></div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr><th>User</th><th>Role</th><th>Email</th><th>Phone</th><th>Status</th><th>Last Login</th><th>Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                <tr id="user-row-<?= $u['id'] ?>">
                    <td>
                        <div class="patient-cell">
                            <div class="user-avatar" style="width: 36px; height: 36px; font-size: 12px;"><?= getInitials($u['full_name']) ?></div>
                            <div>
                                <div class="font-semibold"><?= sanitizeOutput($u['full_name']) ?></div>
                                <div class="text-muted" style="font-size: 11px;">@<?= sanitizeOutput($u['username']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <?php
                        $roleColors = ['super_admin' => 'danger', 'admin' => 'warning', 'doctor' => 'success', 'receptionist' => 'primary', 'accountant' => 'info', 'nurse' => 'secondary'];
                        $rc = $roleColors[$u['role_name']] ?? 'secondary';
                        ?>
                        <span class="badge badge-<?= $rc ?>"><?= sanitizeOutput($u['role_display']) ?></span>
                    </td>
                    <td style="font-size: 13px;"><?= sanitizeOutput($u['email'] ?? '-') ?></td>
                    <td style="font-size: 13px;"><?= sanitizeOutput($u['phone'] ?? '-') ?></td>
                    <td>
                        <span class="badge badge-<?= $u['is_active'] ? 'success' : 'danger' ?>" id="status-badge-<?= $u['id'] ?>">
                            <?= $u['is_active'] ? 'Active' : 'Inactive' ?>
                        </span>
                    </td>
                    <td style="font-size: 12px;"><?= $u['last_login'] ? timeAgo($u['last_login']) : 'Never' ?></td>
                    <td>
                        <div class="d-flex gap-4">
                            <?php if ($u['role_name'] !== 'super_admin'): ?>
                            <?php
                            $editUrl = ($u['role_name'] === 'doctor')
                                ? BASE_URL . '/modules/doctors/add.php?id=' . ($db->fetch("SELECT id FROM doctors WHERE user_id = ?", [$u['id']])['id'] ?? 0)
                                : BASE_URL . '/modules/staff/add.php?id=' . $u['id'];
                            ?>
                            <a href="<?= $editUrl ?>" class="btn btn-sm btn-ghost" title="Edit"><i class="fas fa-pen"></i></a>
                            <a href="<?= BASE_URL ?>/modules/admin/user_permissions.php?user_id=<?= $u['id'] ?>"
                               class="btn btn-sm btn-ghost" title="Manage Permissions" style="color: var(--warning);">
                                <i class="fas fa-shield-alt"></i>
                            </a>
                            <?php endif; ?>
                            
                            <?php if ($u['id'] != getCurrentUserId() && $u['role_name'] !== 'super_admin'): ?>
                            <button class="btn btn-sm btn-<?= $u['is_active'] ? 'warning' : 'success' ?>"
                                    onclick="toggleUserStatus(<?= $u['id'] ?>, <?= $u['is_active'] ?>)"
                                    title="<?= $u['is_active'] ? 'Deactivate' : 'Activate' ?>">
                                <i class="fas fa-<?= $u['is_active'] ? 'ban' : 'check' ?>"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= renderPagination($pagination, BASE_URL . '/modules/admin/users.php') ?>
        <?php endif; ?>
    </div>
</div>

<script>
function toggleUserStatus(userId, currentStatus) {
    const action = currentStatus ? 'deactivate' : 'activate';
    confirmAction(`Are you sure you want to ${action} this user?`, function() {
        apiCall('<?= BASE_URL ?>/modules/admin/toggle_status.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ user_id: userId })
        }).then(data => {
            if (data.success) {
                showToast(data.message, 'success');
                setTimeout(() => location.reload(), 800);
            } else {
                showToast(data.message || 'Failed to update status', 'error');
            }
        }).catch(err => showToast('Request failed', 'error'));
    });
}
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
