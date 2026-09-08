<?php
/**
 * User-Level Permission Overrides - Advanced Clinic Suite
 * Admin can grant/deny specific permissions for individual users,
 * overriding what their role normally allows.
 */
require_once dirname(dirname(__DIR__)) . '/config/session.php';
requireAuth();
requireRole([ROLE_SUPER_ADMIN, ROLE_ADMIN]);

$db = db();
$clinicId = getCurrentClinicId();

$targetUserId = intval($_GET['user_id'] ?? 0);
if (!$targetUserId) {
    header('Location: ' . BASE_URL . '/modules/admin/users.php');
    exit;
}

$targetUser = $db->fetch(
    "SELECT u.id, u.full_name, u.username, u.role, r.display_name as role_label, u.is_active
     FROM users u JOIN roles r ON u.role_id = r.id
     WHERE u.id = ? AND u.clinic_id = ?",
    [$targetUserId, $clinicId]
);
if (!$targetUser) {
    setFlashMessage('error', 'User not found.');
    header('Location: ' . BASE_URL . '/modules/admin/users.php');
    exit;
}
if ($targetUser['role'] === 'super_admin') {
    setFlashMessage('error', 'Cannot modify Super Admin permissions.');
    header('Location: ' . BASE_URL . '/modules/admin/users.php');
    exit;
}

// ── AJAX Save ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_save'])) {
    header('Content-Type: application/json');
    try {
        $db->beginTransaction();
        // Clear existing user overrides
        $db->query("DELETE FROM user_permissions WHERE user_id = ?", [$targetUserId]);

        // Insert new overrides (only permissions that differ from role default)
        $rolePerms = $_POST['role_perms'] ?? [];     // which perms the role already has [perm_id => 1]
        $userChecks = $_POST['user_perm'] ?? [];     // user's checkbox values [perm_id => 'grant'|'deny'|'role']

        foreach ($userChecks as $permId => $val) {
            $permId = intval($permId);
            if ($val === 'role') continue; // no override, use role default
            $type = ($val === 'grant') ? 'grant' : 'deny';
            $db->query(
                "INSERT INTO user_permissions (user_id, permission_id, type, created_by) VALUES (?,?,?,?)",
                [$targetUserId, $permId, $type, getCurrentUserId()]
            );
        }
        $db->commit();
        logAudit('update', 'admin', 'user_permissions', $targetUserId);
        echo json_encode(['success' => true, 'message' => 'Permissions saved for ' . $targetUser['full_name']]);
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

$pageTitle = 'User Permissions';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';

// Load all permissions grouped by module
$allPerms = $db->fetchAll("SELECT id, module, name, display_name FROM permissions ORDER BY module, id");
$permsByModule = [];
foreach ($allPerms as $p) {
    $permsByModule[$p['module']][] = $p;
}

// Load role permissions (what the role gives by default)
$roleId = $db->fetch("SELECT role_id FROM users WHERE id = ?", [$targetUserId])['role_id'];
$rolePermsRaw = $db->fetchAll("SELECT permission_id FROM role_permissions WHERE role_id = ?", [$roleId]);
$rolePermIds = array_column($rolePermsRaw, 'permission_id');
$rolePermSet = array_flip($rolePermIds);

// Load existing user overrides
$userPermsRaw = $db->fetchAll("SELECT permission_id, type FROM user_permissions WHERE user_id = ?", [$targetUserId]);
$userPermOverrides = []; // [permission_id => 'grant'|'deny']
foreach ($userPermsRaw as $up) {
    $userPermOverrides[$up['permission_id']] = $up['type'];
}

// Compute effective permissions (what the user actually ends up with)
function effectiveAccess($permId, $rolePermSet, $userPermOverrides) {
    if (isset($userPermOverrides[$permId])) {
        return $userPermOverrides[$permId]; // 'grant' or 'deny'
    }
    return isset($rolePermSet[$permId]) ? 'role_grant' : 'role_deny';
}

function moduleIcon2($module) {
    $icons = [
        'dashboard' => 'fa-th-large', 'clinic' => 'fa-hospital', 'doctors' => 'fa-user-md',
        'staff' => 'fa-id-badge', 'patients' => 'fa-users', 'appointments' => 'fa-calendar-check',
        'prescriptions' => 'fa-file-prescription', 'billing' => 'fa-file-invoice-dollar',
        'reports' => 'fa-chart-bar', 'dental' => 'fa-tooth', 'vaccination' => 'fa-syringe',
        'communication' => 'fa-envelope', 'admin' => 'fa-cogs',
    ];
    return $icons[$module] ?? 'fa-circle';
}
?>

<div class="content-header">
    <div>
        <ul class="breadcrumb">
            <li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li>
            <li><a href="<?= BASE_URL ?>/modules/admin/users.php">Users</a></li>
            <li>User Permissions</li>
        </ul>
        <h1><i class="fas fa-user-lock" style="color: var(--primary);"></i> User Permissions Override</h1>
    </div>
    <a href="<?= BASE_URL ?>/modules/admin/users.php" class="btn btn-outline">
        <i class="fas fa-arrow-left"></i> Back to Users
    </a>
</div>

<!-- User Info Banner -->
<div class="card mb-24" style="border-left: 4px solid var(--primary);">
    <div class="card-body d-flex align-center gap-16">
        <div style="width: 52px; height: 52px; border-radius: 50%; background: var(--primary-bg); display:flex; align-items:center; justify-content:center; flex-shrink:0;">
            <i class="fas fa-user" style="color: var(--primary); font-size: 20px;"></i>
        </div>
        <div style="flex: 1;">
            <div style="font-size: 18px; font-weight: 700;"><?= sanitizeOutput($targetUser['full_name']) ?></div>
            <div style="font-size: 13px; color: var(--text-muted);">
                @<?= sanitizeOutput($targetUser['username']) ?> &nbsp;·&nbsp;
                <span class="badge badge-primary"><?= sanitizeOutput($targetUser['role_label']) ?></span>
            </div>
        </div>
        <div style="text-align: right; font-size: 12px; color: var(--text-muted); line-height: 1.8;">
            <div><i class="fas fa-info-circle" style="color: var(--info);"></i> <strong>Role default</strong> = inherits from <?= sanitizeOutput($targetUser['role_label']) ?> role</div>
            <div><i class="fas fa-plus-circle" style="color: var(--success);"></i> <strong>Grant</strong> = allow even if role doesn't have it</div>
            <div><i class="fas fa-ban" style="color: var(--danger);"></i> <strong>Deny</strong> = block even if role has it</div>
        </div>
    </div>
</div>

<!-- Legend -->
<div class="d-flex gap-16 mb-16 flex-wrap" style="font-size: 13px;">
    <div class="d-flex align-center gap-6">
        <div style="width: 14px; height: 14px; border-radius: 3px; background: #f0fdf4; border: 1.5px solid #86efac;"></div> Role Grant (default)
    </div>
    <div class="d-flex align-center gap-6">
        <div style="width: 14px; height: 14px; border-radius: 3px; background: #fff7ed; border: 1.5px solid #fdba74;"></div> Explicitly Granted (override)
    </div>
    <div class="d-flex align-center gap-6">
        <div style="width: 14px; height: 14px; border-radius: 3px; background: #fef2f2; border: 1.5px solid #fca5a5;"></div> Explicitly Denied (override)
    </div>
    <div class="d-flex align-center gap-6">
        <div style="width: 14px; height: 14px; border-radius: 3px; background: var(--bg-secondary); border: 1.5px solid var(--border-color);"></div> No Access (role default)
    </div>
</div>

<div id="saveStatus" style="display:none;" class="alert mb-16"></div>

<form id="userPermForm">
    <input type="hidden" name="ajax_save" value="1">

    <?php foreach ($permsByModule as $module => $perms): ?>
    <div class="card mb-16">
        <div class="card-header" style="background: var(--bg-secondary); border-bottom: 1px solid var(--border-color);">
            <h3 style="font-size: 14px; font-weight: 600;">
                <i class="fas <?= moduleIcon2($module) ?>" style="color: var(--primary); width: 16px;"></i>
                <?= ucfirst($module) ?>
                <span style="font-size: 11px; font-weight: 400; color: var(--text-muted); margin-left: 8px;"><?= count($perms) ?> permissions</span>
            </h3>
        </div>
        <div class="card-body">
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 10px;">
                <?php foreach ($perms as $perm):
                    $eff = effectiveAccess($perm['id'], $rolePermSet, $userPermOverrides);
                    $hasRoleGrant = isset($rolePermSet[$perm['id']]);
                    $override = $userPermOverrides[$perm['id']] ?? null;

                    // Background color based on effective state
                    if ($override === 'grant') {
                        $bg = '#fff7ed'; $border = '#fdba74'; $indicator = '<i class="fas fa-plus-circle" style="color:#f97316;"></i>';
                    } elseif ($override === 'deny') {
                        $bg = '#fef2f2'; $border = '#fca5a5'; $indicator = '<i class="fas fa-ban" style="color:var(--danger);"></i>';
                    } elseif ($hasRoleGrant) {
                        $bg = '#f0fdf4'; $border = '#86efac'; $indicator = '<i class="fas fa-check-circle" style="color:var(--success);"></i>';
                    } else {
                        $bg = 'var(--bg-secondary)'; $border = 'var(--border-color)'; $indicator = '<i class="fas fa-minus-circle" style="color:var(--text-muted);"></i>';
                    }
                ?>
                <div class="perm-card" data-perm-id="<?= $perm['id'] ?>"
                     style="border: 1.5px solid <?= $border ?>; border-radius: 8px; padding: 10px 12px; background: <?= $bg ?>; transition: all 0.2s;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;">
                        <div style="font-size: 13px; font-weight: 500; color: var(--text-primary); line-height: 1.3;">
                            <?= $indicator ?>
                            <?= sanitizeOutput($perm['display_name']) ?>
                        </div>
                    </div>
                    <!-- 3-way toggle: role default / grant / deny -->
                    <div style="display: flex; gap: 4px;">
                        <label style="flex: 1; text-align: center; cursor: pointer;">
                            <input type="radio" name="user_perm[<?= $perm['id'] ?>]" value="role"
                                   <?= !$override ? 'checked' : '' ?>
                                   onchange="updateCard(<?= $perm['id'] ?>, 'role', <?= $hasRoleGrant ? 'true' : 'false' ?>)"
                                   style="display:none;">
                            <span class="toggle-btn <?= !$override ? 'toggle-active-neutral' : '' ?>"
                                  style="display:block; padding: 3px 6px; border-radius: 4px; font-size: 11px; border: 1px solid var(--border-color); background: <?= !$override ? '#e2e8f0' : 'transparent' ?>;">
                                Role Default
                            </span>
                        </label>
                        <label style="flex: 1; text-align: center; cursor: pointer;">
                            <input type="radio" name="user_perm[<?= $perm['id'] ?>]" value="grant"
                                   <?= $override === 'grant' ? 'checked' : '' ?>
                                   onchange="updateCard(<?= $perm['id'] ?>, 'grant', <?= $hasRoleGrant ? 'true' : 'false' ?>)"
                                   style="display:none;">
                            <span class="toggle-btn <?= $override === 'grant' ? 'toggle-active-grant' : '' ?>"
                                  style="display:block; padding: 3px 6px; border-radius: 4px; font-size: 11px; border: 1px solid <?= $override === 'grant' ? '#f97316' : 'var(--border-color)' ?>; background: <?= $override === 'grant' ? '#fff7ed' : 'transparent' ?>; color: <?= $override === 'grant' ? '#c2410c' : 'inherit' ?>; font-weight: <?= $override === 'grant' ? '600' : '400' ?>;">
                                ✚ Grant
                            </span>
                        </label>
                        <label style="flex: 1; text-align: center; cursor: pointer;">
                            <input type="radio" name="user_perm[<?= $perm['id'] ?>]" value="deny"
                                   <?= $override === 'deny' ? 'checked' : '' ?>
                                   onchange="updateCard(<?= $perm['id'] ?>, 'deny', <?= $hasRoleGrant ? 'true' : 'false' ?>)"
                                   style="display:none;">
                            <span class="toggle-btn <?= $override === 'deny' ? 'toggle-active-deny' : '' ?>"
                                  style="display:block; padding: 3px 6px; border-radius: 4px; font-size: 11px; border: 1px solid <?= $override === 'deny' ? '#ef4444' : 'var(--border-color)' ?>; background: <?= $override === 'deny' ? '#fef2f2' : 'transparent' ?>; color: <?= $override === 'deny' ? '#dc2626' : 'inherit' ?>; font-weight: <?= $override === 'deny' ? '600' : '400' ?>;">
                                ✕ Deny
                            </span>
                        </label>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <div style="position: sticky; bottom: 0; background: var(--bg-primary); border-top: 1px solid var(--border-color); padding: 16px 0; margin-top: 8px; z-index: 100;" class="d-flex justify-end gap-12">
        <a href="<?= BASE_URL ?>/modules/admin/users.php" class="btn btn-outline">Cancel</a>
        <button type="button" class="btn btn-primary btn-lg" onclick="saveUserPerms()">
            <i class="fas fa-save"></i> Save User Permissions
        </button>
    </div>
</form>

<script>
function updateCard(permId, val, hasRoleGrant) {
    const card = document.querySelector(`.perm-card[data-perm-id="${permId}"]`);
    if (!val || val === 'role') {
        // Role default
        card.style.background = hasRoleGrant ? '#f0fdf4' : 'var(--bg-secondary)';
        card.style.borderColor = hasRoleGrant ? '#86efac' : 'var(--border-color)';
    } else if (val === 'grant') {
        card.style.background = '#fff7ed';
        card.style.borderColor = '#fdba74';
    } else if (val === 'deny') {
        card.style.background = '#fef2f2';
        card.style.borderColor = '#fca5a5';
    }
    // Update span styles
    card.querySelectorAll('.toggle-btn').forEach(s => {
        s.style.background = 'transparent';
        s.style.borderColor = 'var(--border-color)';
        s.style.color = 'inherit';
        s.style.fontWeight = '400';
    });
    const active = card.querySelector(`input[value="${val}"]`);
    if (active) {
        const span = active.nextElementSibling;
        if (val === 'grant') { span.style.background = '#fff7ed'; span.style.borderColor = '#f97316'; span.style.color = '#c2410c'; span.style.fontWeight = '600'; }
        else if (val === 'deny') { span.style.background = '#fef2f2'; span.style.borderColor = '#ef4444'; span.style.color = '#dc2626'; span.style.fontWeight = '600'; }
        else { span.style.background = '#e2e8f0'; }
    }
}

function saveUserPerms() {
    const btn = document.querySelector('[onclick="saveUserPerms()"]');
    const status = document.getElementById('saveStatus');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

    const formData = new FormData(document.getElementById('userPermForm'));

    fetch('user_permissions.php?user_id=<?= $targetUserId ?>', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            status.style.display = 'block';
            status.className = 'alert ' + (data.success ? 'alert-success' : 'alert-danger') + ' mb-16';
            status.innerHTML = '<i class="fas fa-' + (data.success ? 'check-circle' : 'exclamation-circle') + '"></i> ' + data.message;
            setTimeout(() => status.style.display = 'none', 4000);
        })
        .catch(() => {
            status.style.display = 'block';
            status.className = 'alert alert-danger mb-16';
            status.innerHTML = 'Network error. Please try again.';
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-save"></i> Save User Permissions';
        });
}
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
