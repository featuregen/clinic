<?php
/**
 * System Admin Dashboard - Advanced Clinic Suite
 * Super Admin Only
 */
$pageTitle = 'System Admin';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';

$role = getCurrentUserRole();
if ($role !== ROLE_SUPER_ADMIN) {
    header('Location: ' . BASE_URL . '/modules/auth/403.php');
    exit;
}

$db = db();
$clinicId = getCurrentClinicId();

// Stats
try {
    $totalUsers = $db->fetch("SELECT COUNT(*) as c FROM users WHERE clinic_id = ?", [$clinicId])['c'];
    $activeDoctors = $db->fetch("SELECT COUNT(*) as c FROM doctors d JOIN users u ON d.user_id = u.id WHERE d.clinic_id = ? AND u.is_active = 1", [$clinicId])['c'];
    $totalPatients = $db->fetch("SELECT COUNT(*) as c FROM patients WHERE clinic_id = ?", [$clinicId])['c'];
    $auditCount = $db->fetch("SELECT COUNT(*) as c FROM audit_logs WHERE clinic_id = ?", [$clinicId])['c'];
    $totalRoles = $db->fetch("SELECT COUNT(*) as c FROM roles WHERE is_active = 1")['c'];
    $totalPermissions = $db->fetch("SELECT COUNT(*) as c FROM permissions")['c'];
} catch (Exception $e) {
    $totalUsers = $activeDoctors = $totalPatients = $auditCount = $totalRoles = $totalPermissions = 0;
}

// Recent audit logs
try {
    $recentLogs = $db->fetchAll(
        "SELECT a.*, u.full_name as user_name FROM audit_logs a
         LEFT JOIN users u ON a.user_id = u.id
         WHERE a.clinic_id = ? ORDER BY a.created_at DESC LIMIT 20", [$clinicId]
    );
} catch (Exception $e) { $recentLogs = []; }
?>

<div class="content-header">
    <div>
        <ul class="breadcrumb">
            <li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li>
            <li>System Admin</li>
        </ul>
        <h1><i class="fas fa-cogs" style="color: var(--primary);"></i> System Administration</h1>
    </div>
</div>

<!-- Stats Grid -->
<div class="grid-3 mb-24" style="gap: 20px;">
    <div class="stat-card">
        <div class="stat-icon primary"><i class="fas fa-users"></i></div>
        <div class="stat-details">
            <div class="stat-label">Total Users</div>
            <div class="stat-value"><?= $totalUsers ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon success"><i class="fas fa-user-md"></i></div>
        <div class="stat-details">
            <div class="stat-label">Active Doctors</div>
            <div class="stat-value"><?= $activeDoctors ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon info"><i class="fas fa-users"></i></div>
        <div class="stat-details">
            <div class="stat-label">Total Patients</div>
            <div class="stat-value"><?= $totalPatients ?></div>
        </div>
    </div>
</div>

<!-- Quick Links -->
<div class="grid-4 mb-24" style="gap: 20px;">
    <a href="<?= BASE_URL ?>/modules/admin/users.php" class="card" style="text-decoration: none; transition: transform 0.2s;">
        <div class="card-body text-center" style="padding: 28px;">
            <div style="width: 56px; height: 56px; border-radius: 12px; background: linear-gradient(135deg, var(--primary), var(--primary-dark)); display: flex; align-items: center; justify-content: center; margin: 0 auto 12px; color: white; font-size: 22px;">
                <i class="fas fa-users-cog"></i>
            </div>
            <h3>User Management</h3>
            <p class="text-muted" style="font-size: 13px; margin-top: 4px;">Manage all system users, toggle active status</p>
        </div>
    </a>
    <a href="<?= BASE_URL ?>/modules/admin/roles.php" class="card" style="text-decoration: none; transition: transform 0.2s;">
        <div class="card-body text-center" style="padding: 28px;">
            <div style="width: 56px; height: 56px; border-radius: 12px; background: linear-gradient(135deg, var(--warning), #d97706); display: flex; align-items: center; justify-content: center; margin: 0 auto 12px; color: white; font-size: 22px;">
                <i class="fas fa-shield-alt"></i>
            </div>
            <h3>Role Permissions</h3>
            <p class="text-muted" style="font-size: 13px; margin-top: 4px;">Control which features each role can access</p>
        </div>
    </a>
    <a href="<?= BASE_URL ?>/modules/staff/add.php" class="card" style="text-decoration: none; transition: transform 0.2s;">
        <div class="card-body text-center" style="padding: 28px;">
            <div style="width: 56px; height: 56px; border-radius: 12px; background: linear-gradient(135deg, var(--success), #059669); display: flex; align-items: center; justify-content: center; margin: 0 auto 12px; color: white; font-size: 22px;">
                <i class="fas fa-user-plus"></i>
            </div>
            <h3>Add Staff</h3>
            <p class="text-muted" style="font-size: 13px; margin-top: 4px;">Create new user accounts with role assignment</p>
        </div>
    </a>
    <a href="<?= BASE_URL ?>/modules/doctors/add.php" class="card" style="text-decoration: none; transition: transform 0.2s;">
        <div class="card-body text-center" style="padding: 28px;">
            <div style="width: 56px; height: 56px; border-radius: 12px; background: linear-gradient(135deg, var(--info), #0284c7); display: flex; align-items: center; justify-content: center; margin: 0 auto 12px; color: white; font-size: 22px;">
                <i class="fas fa-user-md"></i>
            </div>
            <h3>Add Doctor</h3>
            <p class="text-muted" style="font-size: 13px; margin-top: 4px;">Register new doctors with specialty & fees</p>
        </div>
    </a>
</div>

<!-- System Info -->
<div class="grid-2 mb-24" style="gap: 20px;">
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-shield-alt" style="color: var(--warning);"></i> Roles & Permissions</h3></div>
        <div class="card-body">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div style="text-align: center; padding: 16px; background: var(--bg-secondary); border-radius: 8px;">
                    <div style="font-size: 24px; font-weight: 700; color: var(--warning);"><?= $totalRoles ?></div>
                    <div class="text-muted" style="font-size: 12px;">Total Roles</div>
                </div>
                <div style="text-align: center; padding: 16px; background: var(--bg-secondary); border-radius: 8px;">
                    <div style="font-size: 24px; font-weight: 700; color: var(--info);"><?= $totalPermissions ?></div>
                    <div class="text-muted" style="font-size: 12px;">Permissions</div>
                </div>
            </div>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-info-circle" style="color: var(--primary);"></i> System Info</h3></div>
        <div class="card-body">
            <table style="width: 100%; font-size: 13px;">
                <tr><td class="text-muted" style="padding: 6px 0;">App Version</td><td class="font-semibold"><?= APP_VERSION ?></td></tr>
                <tr><td class="text-muted" style="padding: 6px 0;">PHP Version</td><td class="font-semibold"><?= phpversion() ?></td></tr>
                <tr><td class="text-muted" style="padding: 6px 0;">MySQL</td><td class="font-semibold"><?php try { echo $db->fetch("SELECT VERSION() as v")['v']; } catch(Exception $e) { echo 'N/A'; } ?></td></tr>
                <tr><td class="text-muted" style="padding: 6px 0;">Audit Entries</td><td class="font-semibold"><?= number_format($auditCount) ?></td></tr>
            </table>
        </div>
    </div>
</div>

<!-- Recent Audit Logs -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-history" style="color: var(--info);"></i> Recent Activity Log</h3>
    </div>
    <div class="card-body p-0">
        <?php if (empty($recentLogs)): ?>
        <div class="empty-state"><i class="fas fa-clipboard-list"></i><h3>No activity logged yet</h3></div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Time</th><th>User</th><th>Action</th><th>Module</th><th>Description</th><th>IP</th></tr></thead>
                <tbody>
                <?php foreach ($recentLogs as $log): ?>
                <tr>
                    <td style="white-space: nowrap; font-size: 12px;"><?= timeAgo($log['created_at']) ?></td>
                    <td class="font-semibold"><?= sanitizeOutput($log['user_name'] ?? 'System') ?></td>
                    <td>
                        <?php
                        $actionColors = ['create' => 'success', 'update' => 'info', 'delete' => 'danger', 'login' => 'primary', 'logout' => 'secondary', 'payment' => 'warning'];
                        $color = $actionColors[$log['action']] ?? 'secondary';
                        ?>
                        <span class="badge badge-<?= $color ?>"><?= ucfirst($log['action']) ?></span>
                    </td>
                    <td style="font-size: 12px;"><?= ucfirst($log['module'] ?? '-') ?></td>
                    <td style="font-size: 12px; max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?= sanitizeOutput($log['description'] ?? '-') ?></td>
                    <td style="font-size: 11px; color: var(--text-muted);"><?= $log['ip_address'] ?? '-' ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
