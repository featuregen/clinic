<?php
/**
 * Add/Edit Staff - Advanced Clinic Suite
 */
require_once dirname(dirname(__DIR__)) . '/config/session.php';
requireAuth();
requirePermission('staff.create');

$db = db();
$clinicId = getCurrentClinicId();
$staff = null;
$isEdit = false;

// Edit mode
if (isset($_GET['id'])) {
    $staff = $db->fetch("SELECT * FROM users WHERE id = ? AND clinic_id = ?", [$_GET['id'], $clinicId]);
    if ($staff) {
        if ($staff['role'] === 'super_admin') {
            setFlashMessage('error', 'Super Admin cannot be modified.');
            header('Location: ' . BASE_URL . '/modules/admin/users.php');
            exit;
        }
        $isEdit = true;
    }
}

// Handle form submission BEFORE any output
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = sanitize($_POST['full_name']);
    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $email = sanitize($_POST['email'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    $gender = sanitize($_POST['gender'] ?? '');
    $dob = $_POST['date_of_birth'] ?? null;
    $roleId = intval($_POST['role_id'] ?? 0);
    $branchId = intval($_POST['branch_id'] ?? 0) ?: null;
    $address = sanitize($_POST['address'] ?? '');
    $qualification = sanitize($_POST['qualification'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    // Get role name
    $roleRow = $db->fetch("SELECT name FROM roles WHERE id = ?", [$roleId]);
    $roleName = $roleRow['name'] ?? 'receptionist';

    try {
        if ($isEdit) {
            $db->query(
                "UPDATE users SET full_name=?, email=?, phone=?, gender=?, date_of_birth=?,
                 role_id=?, role=?, branch_id=?, address=?, qualification=?, is_active=? WHERE id=?",
                [$fullName, $email ?: null, $phone ?: null, $gender ?: null, $dob ?: null,
                 $roleId, $roleName, $branchId, $address ?: null, $qualification ?: null, $isActive, $staff['id']]
            );

            if (!empty($password)) {
                $db->query("UPDATE users SET password=? WHERE id=?", [password_hash($password, PASSWORD_DEFAULT), $staff['id']]);
            }

            logAudit('update', 'staff', 'user', $staff['id']);
            setFlashMessage('success', 'Staff member updated successfully.');
        } else {
            if (empty($fullName) || empty($username) || empty($password) || !$roleId) {
                throw new Exception('Full name, username, password, and role are required.');
            }

            $existing = $db->fetch("SELECT id FROM users WHERE username = ?", [$username]);
            if ($existing) {
                throw new Exception('Username already exists.');
            }

            $db->query(
                "INSERT INTO users (clinic_id, role_id, role, username, password, full_name, email, phone,
                 gender, date_of_birth, branch_id, address, qualification, is_active)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                [$clinicId, $roleId, $roleName, $username, password_hash($password, PASSWORD_DEFAULT),
                 $fullName, $email ?: null, $phone ?: null, $gender ?: null, $dob ?: null,
                 $branchId, $address ?: null, $qualification ?: null, $isActive]
            );
            $newId = $db->lastInsertId();

            logAudit('create', 'staff', 'user', $newId);
            setFlashMessage('success', 'Staff member added successfully.');
        }

        header('Location: ' . BASE_URL . '/modules/staff/list.php');
        exit;
    } catch (Exception $e) {
        setFlashMessage('error', 'Error: ' . $e->getMessage());
    }
}

// NOW include header — after any potential redirect
$pageTitle = $isEdit ? 'Edit Staff' : 'Add Staff';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';

// Load roles and departments for dropdowns
$roles = $db->fetchAll("SELECT id, name, display_name FROM roles WHERE is_active = 1 AND name NOT IN ('super_admin', 'doctor') ORDER BY display_name");
$branches = $db->fetchAll("SELECT id, name FROM branches WHERE clinic_id = ? AND is_active = 1 ORDER BY name", [$clinicId]);
?>

<div class="content-header">
    <div>
        <ul class="breadcrumb">
            <li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li>
            <li><a href="<?= BASE_URL ?>/modules/staff/list.php">Staff</a></li>
            <li><?= $isEdit ? 'Edit' : 'Add' ?> Staff</li>
        </ul>
        <h1><?= $isEdit ? 'Edit Staff Member' : 'Add New Staff Member' ?></h1>
    </div>
</div>

<form method="POST" action="" class="card">
    <div class="card-body">
        <!-- Account Info -->
        <h4 class="mb-16"><i class="fas fa-user-shield" style="color: var(--primary);"></i> Account Information</h4>
        <div class="form-row mb-24">
            <div class="form-group">
                <label class="form-label">Full Name <span class="required">*</span></label>
                <input type="text" name="full_name" class="form-control" required
                       value="<?= sanitizeOutput($staff['full_name'] ?? '') ?>" placeholder="Full name">
            </div>
            <div class="form-group">
                <label class="form-label">Username <span class="required">*</span></label>
                <input type="text" name="username" class="form-control" <?= $isEdit ? 'readonly' : 'required' ?>
                       value="<?= sanitizeOutput($staff['username'] ?? '') ?>" placeholder="Login username">
            </div>
            <div class="form-group">
                <label class="form-label">Password <?= $isEdit ? '' : '<span class="required">*</span>' ?></label>
                <div style="position: relative;">
                    <input type="password" name="password" id="staffPassword" class="form-control" <?= $isEdit ? '' : 'required' ?>
                           placeholder="<?= $isEdit ? 'Leave blank to keep' : 'Set password' ?>" style="padding-right: 40px;">
                    <button type="button" onclick="togglePassword('staffPassword', this)" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: var(--text-muted);">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- Role & Department -->
        <h4 class="mb-16"><i class="fas fa-id-badge" style="color: var(--warning);"></i> Role & Assignment</h4>
        <div class="form-row mb-24">
            <div class="form-group">
                <label class="form-label">Role <span class="required">*</span></label>
                <select name="role_id" class="form-control" required>
                    <option value="">Select Role</option>
                    <?php foreach ($roles as $r): ?>
                    <option value="<?= $r['id'] ?>" <?= ($staff['role_id'] ?? '') == $r['id'] ? 'selected' : '' ?>>
                        <?= sanitizeOutput($r['display_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Branch</label>
                <select name="branch_id" id="branchSelect" class="form-control">
                    <option value="">All Branches</option>
                    <?php foreach ($branches as $branch): ?>
                    <option value="<?= $branch['id'] ?>" <?= ($staff['branch_id'] ?? '') == $branch['id'] ? 'selected' : '' ?>><?= sanitizeOutput($branch['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" style="margin-bottom: 8px;">Status</label>
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                    <input type="checkbox" name="is_active" value="1"
                           <?= ($staff['is_active'] ?? 1) ? 'checked' : '' ?>>
                    <span>Active</span>
                </label>
            </div>
        </div>

        <!-- Personal Info -->
        <h4 class="mb-16"><i class="fas fa-user" style="color: var(--info);"></i> Personal Information</h4>
        <div class="form-row mb-24">
            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control"
                       value="<?= sanitizeOutput($staff['email'] ?? '') ?>" placeholder="email@clinic.com">
            </div>
            <div class="form-group">
                <label class="form-label">Phone</label>
                <input type="tel" name="phone" class="form-control"
                       value="<?= sanitizeOutput($staff['phone'] ?? '') ?>" placeholder="+91 9876543210">
            </div>
            <div class="form-group">
                <label class="form-label">Gender</label>
                <select name="gender" class="form-control">
                    <option value="">Select Gender</option>
                    <?php foreach (GENDER_OPTIONS as $g): ?>
                    <option value="<?= $g ?>" <?= ($staff['gender'] ?? '') === $g ? 'selected' : '' ?>><?= $g ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-row mb-24">
            <div class="form-group">
                <label class="form-label">Date of Birth</label>
                <input type="date" name="date_of_birth" class="form-control"
                       value="<?= $staff['date_of_birth'] ?? '' ?>" max="<?= date('Y-m-d') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Qualification</label>
                <input type="text" name="qualification" class="form-control"
                       value="<?= sanitizeOutput($staff['qualification'] ?? '') ?>" placeholder="Education/degree">
            </div>
            <div class="form-group">
                <!-- spacer -->
            </div>
        </div>
        <div class="form-row">
            <div class="form-group" style="grid-column: span 3;">
                <label class="form-label">Address</label>
                <textarea name="address" class="form-control" rows="2" placeholder="Full address"><?= sanitizeOutput($staff['address'] ?? '') ?></textarea>
            </div>
        </div>
    </div>

    <div class="card-footer d-flex justify-end gap-12">
        <a href="<?= BASE_URL ?>/modules/staff/list.php" class="btn btn-outline">Cancel</a>
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-<?= $isEdit ? 'save' : 'user-plus' ?>"></i>
            <?= $isEdit ? 'Update Staff' : 'Add Staff' ?>
        </button>
    </div>
</form>

<!-- Quick Add Modal -->
<div id="quickAddModal" style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
    <div class="card" style="width: 500px; max-width: 90vw; animation: slideUp 0.3s ease;">
        <div class="card-header">
            <h3><i class="fas fa-plus-circle" style="color: var(--primary);"></i> Add <span id="quickAddTitle">Item</span></h3>
            <button type="button" class="btn btn-sm btn-ghost" onclick="closeQuickAdd()" style="font-size: 18px;">&times;</button>
        </div>
        <div class="card-body">
            <div class="form-group mb-16">
                <label class="form-label">Name <span class="required">*</span></label>
                <input type="text" id="quickAddName" class="form-control" placeholder="Enter name...">
            </div>
            <div class="form-group mb-16">
                <label class="form-label">Code</label>
                <input type="text" id="quickAddCode" class="form-control" placeholder="e.g. CARDIO">
            </div>
            <div class="form-group">
                <label class="form-label">Description</label>
                <textarea id="quickAddDescription" class="form-control" rows="3" placeholder="Description..."></textarea>
            </div>
            <input type="hidden" id="quickAddType">
        </div>
        <div class="card-footer d-flex justify-end gap-12">
            <button type="button" class="btn btn-outline" onclick="closeQuickAdd()">Cancel</button>
            <button type="button" class="btn btn-primary" onclick="saveQuickAdd()">Save</button>
        </div>
    </div>
</div>

<script>
function openQuickAdd(type) {
    const title = type.charAt(0).toUpperCase() + type.slice(1);
    document.getElementById('quickAddTitle').textContent = title;
    document.getElementById('quickAddType').value = type;
    document.getElementById('quickAddName').value = '';
    document.getElementById('quickAddCode').value = '';
    document.getElementById('quickAddDescription').value = '';
    document.getElementById('quickAddModal').style.display = 'flex';
    setTimeout(() => document.getElementById('quickAddName').focus(), 100);
}

function closeQuickAdd() {
    document.getElementById('quickAddModal').style.display = 'none';
}

function saveQuickAdd() {
    const type = document.getElementById('quickAddType').value;
    const name = document.getElementById('quickAddName').value;
    const code = document.getElementById('quickAddCode').value;
    const description = document.getElementById('quickAddDescription').value;
    
    if (!name) {
        alert('Please enter a name');
        return;
    }
    
    // API Call
    const formData = new FormData();
    formData.append('type', type);
    formData.append('name', name);
    formData.append('code', code);
    formData.append('description', description);
    
    fetch('<?= BASE_URL ?>/modules/admin/api/add_master_data.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.id) {
            // Success
            const selectId = type + 'Select'; // specialtySelect or departmentSelect
            const select = document.getElementById(selectId);
            
            if (select) {
                const option = new Option(data.name, data.id, true, true);
                select.add(option);
            }
            
            closeQuickAdd();
        } else {
            alert(data.error || 'Failed to add item');
        }
    })
    .catch(err => {
        console.error(err);
        alert('An error occurred');
    });
}
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
