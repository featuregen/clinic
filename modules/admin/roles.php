<?php
/**
 * Role Permissions Manager - Advanced Clinic Suite
 * Admin can grant/revoke permissions per role using a visual matrix.
 */
require_once dirname(dirname(__DIR__)) . '/config/session.php';
requireAuth();
requireRole([ROLE_SUPER_ADMIN, ROLE_ADMIN]);

$db = db();
$clinicId = getCurrentClinicId();

// ── Handle AJAX save ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_save'])) {
    header('Content-Type: application/json');
    try {
        $roleId = intval($_POST['role_id'] ?? 0);
        if (!$roleId) throw new Exception('Invalid role.');

        // Prevent editing super_admin or the current user's own role if it would lock them out
        $roleRow = $db->fetch("SELECT name, is_system FROM roles WHERE id = ?", [$roleId]);
        if (!$roleRow) throw new Exception('Role not found.');
        if ($roleRow['name'] === 'super_admin') throw new Exception('Cannot modify Super Admin permissions.');

        $permIds = array_map('intval', $_POST['permission_ids'] ?? []);

        $db->beginTransaction();
        // Clear existing permissions for this role
        $db->query("DELETE FROM role_permissions WHERE role_id = ?", [$roleId]);
        // Re-insert selected permissions
        if (!empty($permIds)) {
            foreach ($permIds as $pid) {
                if ($pid > 0) {
                    $db->query("INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)", [$roleId, $pid]);
                }
            }
        }
        $db->commit();
        logAudit('update', 'admin', 'role_permissions', $roleId);
        echo json_encode(['success' => true, 'message' => 'Permissions updated for ' . sanitizeOutput($roleRow['name'])]);
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

$pageTitle = 'Role Permissions';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';

// Load all roles (excluding super_admin - always full access)
$roles = $db->fetchAll("SELECT id, name, display_name, is_system FROM roles WHERE is_active = 1 AND name != 'super_admin' ORDER BY id");

// Load all permissions grouped by module
$allPerms = $db->fetchAll("SELECT id, module, name, display_name FROM permissions ORDER BY module, id");

// Group permissions by module
$permsByModule = [];
foreach ($allPerms as $p) {
    $permsByModule[$p['module']][] = $p;
}

// Load existing role_permissions for all roles
$rolePermsRaw = $db->fetchAll("SELECT role_id, permission_id FROM role_permissions");
$rolePermsMap = []; // [role_id][permission_id] = true
foreach ($rolePermsRaw as $rp) {
    $rolePermsMap[$rp['role_id']][$rp['permission_id']] = true;
}

$selectedRoleId = intval($_GET['role_id'] ?? ($roles[0]['id'] ?? 0));
$selectedRole = null;
foreach ($roles as $r) {
    if ($r['id'] == $selectedRoleId) { $selectedRole = $r; break; }
}
?>

<div class="content-header">
    <div>
        <ul class="breadcrumb">
            <li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li>
            <li><a href="<?= BASE_URL ?>/modules/admin/index.php">System Admin</a></li>
            <li>Role Permissions</li>
        </ul>
        <h1><i class="fas fa-shield-alt" style="color: var(--primary);"></i> Role Permissions Manager</h1>
    </div>
</div>

<div class="grid-3 gap-24">
    <!-- Left: Role List -->
    <div>
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-users-cog" style="color: var(--primary);"></i> Roles</h3>
            </div>
            <div class="card-body p-0">
                <?php foreach ($roles as $role): ?>
                <a href="?role_id=<?= $role['id'] ?>"
                   class="d-flex align-center gap-12 p-12"
                   style="border-bottom: 1px solid var(--border-color); text-decoration: none; cursor: pointer;
                          background: <?= $role['id'] == $selectedRoleId ? 'var(--primary-bg)' : 'transparent' ?>;
                          border-left: 3px solid <?= $role['id'] == $selectedRoleId ? 'var(--primary)' : 'transparent' ?>;">
                    <div style="width: 36px; height: 36px; border-radius: 50%; background: var(--primary-bg); display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                        <i class="fas fa-user-tag" style="color: var(--primary); font-size: 14px;"></i>
                    </div>
                    <div>
                        <div style="font-weight: 600; color: var(--text-primary); font-size: 14px;"><?= sanitizeOutput($role['display_name']) ?></div>
                        <div style="font-size: 11px; color: var(--text-muted);"><?= sanitizeOutput($role['name']) ?>
                            <?php $cnt = count($rolePermsMap[$role['id']] ?? []); ?>
                            &nbsp;·&nbsp; <span style="color: var(--success);"><?= $cnt ?> permission<?= $cnt != 1 ? 's' : '' ?></span>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
                <div class="p-12" style="background: var(--bg-secondary); border-radius: 0 0 8px 8px;">
                    <div style="display: flex; align-items: center; gap: 8px; font-size: 12px; color: var(--text-muted);">
                        <i class="fas fa-lock" style="color: var(--warning);"></i>
                        <span>Super Admin always has full access</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Right: Permission Matrix for selected role -->
    <div style="grid-column: span 2;">
        <?php if ($selectedRole): ?>
        <div class="card">
            <div class="card-header" style="justify-content: space-between;">
                <h3>
                    <i class="fas fa-key" style="color: var(--warning);"></i>
                    Permissions for: <span style="color: var(--primary);"><?= sanitizeOutput($selectedRole['display_name']) ?></span>
                </h3>
                <div class="d-flex gap-8 align-center">
                    <button type="button" class="btn btn-sm btn-outline" onclick="toggleAll(true)">
                        <i class="fas fa-check-double"></i> Select All
                    </button>
                    <button type="button" class="btn btn-sm btn-outline" onclick="toggleAll(false)">
                        <i class="fas fa-times"></i> Clear All
                    </button>
                    <button type="button" class="btn btn-sm btn-primary" onclick="savePermissions()" id="saveBtn">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div id="saveStatus" style="display:none;" class="alert mb-16"></div>

                <form id="permForm">
                    <input type="hidden" name="role_id" value="<?= $selectedRole['id'] ?>">
                    <input type="hidden" name="ajax_save" value="1">

                    <?php foreach ($permsByModule as $module => $perms): ?>
                    <div class="mb-24" style="border: 1px solid var(--border-color); border-radius: 8px; overflow: hidden;">
                        <!-- Module Header -->
                        <div style="background: var(--bg-secondary); padding: 10px 16px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid var(--border-color);">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <i class="fas <?= moduleIcon($module) ?>" style="color: var(--primary); width: 16px;"></i>
                                <strong style="text-transform: capitalize; font-size: 14px;"><?= ucfirst($module) ?></strong>
                                <span style="font-size: 11px; color: var(--text-muted);"><?= count($perms) ?> permissions</span>
                            </div>
                            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; font-size: 12px; color: var(--text-muted);">
                                <input type="checkbox" class="module-toggle" data-module="<?= $module ?>"
                                       onchange="toggleModule('<?= $module ?>', this.checked)"
                                       <?= allModuleChecked($module, $perms, $rolePermsMap[$selectedRoleId] ?? []) ? 'checked' : '' ?>>
                                All
                            </label>
                        </div>
                        <!-- Permission Checkboxes -->
                        <div style="padding: 12px 16px; display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 10px;">
                            <?php foreach ($perms as $perm): ?>
                            <?php $checked = isset($rolePermsMap[$selectedRoleId][$perm['id']]); ?>
                            <label class="perm-item" data-module="<?= $module ?>"
                                   style="display: flex; align-items: center; gap: 8px; cursor: pointer;
                                          background: <?= $checked ? 'var(--success-bg, #f0fdf4)' : 'transparent' ?>;
                                          border: 1px solid <?= $checked ? 'var(--success-light, #86efac)' : 'var(--border-color)' ?>;
                                          border-radius: 6px; padding: 8px 10px; transition: all 0.2s; font-size: 13px;">
                                <input type="checkbox" name="permission_ids[]"
                                       value="<?= $perm['id'] ?>"
                                       class="perm-checkbox" data-module="<?= $module ?>"
                                       onchange="onPermChange(this)"
                                       <?= $checked ? 'checked' : '' ?>>
                                <span><?= sanitizeOutput($perm['display_name']) ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </form>
            </div>
            <div class="card-footer d-flex justify-end">
                <button type="button" class="btn btn-primary btn-lg" onclick="savePermissions()" id="saveBtn2">
                    <i class="fas fa-save"></i> Save Permissions
                </button>
            </div>
        </div>
        <?php else: ?>
        <div class="card">
            <div class="card-body empty-state">
                <i class="fas fa-shield-alt" style="font-size: 48px; color: var(--text-muted); margin-bottom: 16px;"></i>
                <p class="text-muted">Select a role from the left to manage its permissions.</p>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function onPermChange(checkbox) {
    const label = checkbox.closest('.perm-item');
    if (checkbox.checked) {
        label.style.background = 'var(--success-bg, #f0fdf4)';
        label.style.borderColor = 'var(--success-light, #86efac)';
    } else {
        label.style.background = 'transparent';
        label.style.borderColor = 'var(--border-color)';
    }
    // Update module-level toggle
    const module = checkbox.dataset.module;
    const all = document.querySelectorAll(`.perm-checkbox[data-module="${module}"]`);
    const allChecked = Array.from(all).every(c => c.checked);
    const toggle = document.querySelector(`.module-toggle[data-module="${module}"]`);
    if (toggle) toggle.checked = allChecked;
}

function toggleModule(module, checked) {
    document.querySelectorAll(`.perm-checkbox[data-module="${module}"]`).forEach(cb => {
        cb.checked = checked;
        onPermChange(cb);
    });
}

function toggleAll(checked) {
    document.querySelectorAll('.perm-checkbox').forEach(cb => {
        cb.checked = checked;
        onPermChange(cb);
    });
    document.querySelectorAll('.module-toggle').forEach(t => t.checked = checked);
}

function savePermissions() {
    const btn = document.getElementById('saveBtn');
    const btn2 = document.getElementById('saveBtn2');
    const status = document.getElementById('saveStatus');

    btn.disabled = btn2.disabled = true;
    btn.innerHTML = btn2.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

    const formData = new FormData(document.getElementById('permForm'));

    fetch(window.location.href, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            status.style.display = 'block';
            if (data.success) {
                status.className = 'alert alert-success mb-16';
                status.innerHTML = '<i class="fas fa-check-circle"></i> ' + data.message;
            } else {
                status.className = 'alert alert-danger mb-16';
                status.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + data.message;
            }
            setTimeout(() => status.style.display = 'none', 4000);
        })
        .catch(() => {
            status.style.display = 'block';
            status.className = 'alert alert-danger mb-16';
            status.innerHTML = '<i class="fas fa-exclamation-circle"></i> Network error. Please try again.';
        })
        .finally(() => {
            btn.disabled = btn2.disabled = false;
            btn.innerHTML = btn2.innerHTML = '<i class="fas fa-save"></i> Save Changes';
        });
}
</script>

<?php
function moduleIcon($module) {
    $icons = [
        'dashboard'     => 'fa-th-large',
        'clinic'        => 'fa-hospital',
        'doctors'       => 'fa-user-md',
        'staff'         => 'fa-id-badge',
        'patients'      => 'fa-users',
        'appointments'  => 'fa-calendar-check',
        'prescriptions' => 'fa-file-prescription',
        'billing'       => 'fa-file-invoice-dollar',
        'reports'       => 'fa-chart-bar',
        'dental'        => 'fa-tooth',
        'vaccination'   => 'fa-syringe',
        'communication' => 'fa-envelope',
        'admin'         => 'fa-cogs',
    ];
    return $icons[$module] ?? 'fa-circle';
}

function allModuleChecked($module, $perms, $rolePerms) {
    foreach ($perms as $p) {
        if (!isset($rolePerms[$p['id']])) return false;
    }
    return true;
}

require_once INCLUDES_PATH . '/footer.php';
?>
