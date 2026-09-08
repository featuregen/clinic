<?php
/**
 * Add/Edit Doctor - Advanced Clinic Suite
 * Dual-table: users + doctors
 */
require_once dirname(dirname(__DIR__)) . '/config/session.php';
requireAuth();
requirePermission('doctors.create');

$db = db();
$clinicId = getCurrentClinicId();
$doctor = null;
$user = null;
$isEdit = false;

// Edit mode
if (isset($_GET['id'])) {
    $doctor = $db->fetch(
        "SELECT d.*, u.full_name, u.email, u.phone, u.gender, u.date_of_birth, u.username, u.role,
                u.qualification, u.specialization, u.license_number, u.address, u.profile_image
         FROM doctors d JOIN users u ON d.user_id = u.id
         WHERE d.id = ? AND d.clinic_id = ?", [$_GET['id'], $clinicId]
    );
    if ($doctor) {
        if ($doctor['role'] === 'super_admin') {
            setFlashMessage('error', 'Super Admin cannot be modified.');
            header('Location: ' . BASE_URL . '/modules/doctors/list.php');
            exit;
        }
        $isEdit = true;
    }
}

// Handle form submission BEFORE any output
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = sanitize($_POST['full_name']);
    $email = sanitize($_POST['email'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    $gender = sanitize($_POST['gender'] ?? '');
    $dob = $_POST['date_of_birth'] ?? null;
    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $qualification = sanitize($_POST['qualification'] ?? '');
    $specialization = sanitize($_POST['specialization'] ?? '');
    $licenseNumber = sanitize($_POST['license_number'] ?? '');
    $address = sanitize($_POST['address'] ?? '');
    $specialtyId = intval($_POST['specialty_id'] ?? 0) ?: null;
    $departmentId = intval($_POST['department_id'] ?? 0) ?: null;
    $regNumber = sanitize($_POST['registration_number'] ?? '');
    $consultFee = floatval($_POST['consultation_fee'] ?? 0);
    $followupFee = floatval($_POST['followup_fee'] ?? 0);
    $expYears = intval($_POST['experience_years'] ?? 0);
    $slotDuration = intval($_POST['default_slot_duration'] ?? 15);
    $isAvailable = isset($_POST['is_available']) ? 1 : 0;
    $bio = sanitize($_POST['bio'] ?? '');

    try {
        $db->beginTransaction();

        if ($isEdit) {
            // Update users table
            $db->query(
                "UPDATE users SET full_name=?, email=?, phone=?, gender=?, date_of_birth=?,
                 qualification=?, specialization=?, license_number=?, address=? WHERE id=?",
                [$fullName, $email ?: null, $phone ?: null, $gender ?: null, $dob ?: null,
                 $qualification ?: null, $specialization ?: null, $licenseNumber ?: null, $address ?: null, $doctor['user_id']]
            );

            // Update password only if provided
            if (!empty($password)) {
                $db->query("UPDATE users SET password=? WHERE id=?", [password_hash($password, PASSWORD_DEFAULT), $doctor['user_id']]);
            }

            // Update doctors table
            $db->query(
                "UPDATE doctors SET specialty_id=?, department_id=?, registration_number=?,
                 consultation_fee=?, followup_fee=?, experience_years=?,
                 default_slot_duration=?, is_available=?, bio=? WHERE id=?",
                [$specialtyId, $departmentId, $regNumber ?: null, $consultFee, $followupFee,
                 $expYears, $slotDuration, $isAvailable, $bio ?: null, $doctor['id']]
            );

            logAudit('update', 'doctors', 'doctor', $doctor['id']);
            setFlashMessage('success', 'Doctor updated successfully.');
        } else {
            // Validate required fields
            if (empty($fullName) || empty($username) || empty($password)) {
                throw new Exception('Full name, username, and password are required.');
            }

            // Check username uniqueness
            $existing = $db->fetch("SELECT id FROM users WHERE username = ?", [$username]);
            if ($existing) {
                throw new Exception('Username already exists. Please choose another.');
            }

            // Insert into users table
            $roleId = $db->fetch("SELECT id FROM roles WHERE name = 'doctor'")['id'] ?? 3;
            $db->query(
                "INSERT INTO users (clinic_id, role_id, role, username, password, full_name, email, phone,
                 gender, date_of_birth, qualification, specialization, license_number, address)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                [$clinicId, $roleId, 'doctor', $username, password_hash($password, PASSWORD_DEFAULT),
                 $fullName, $email ?: null, $phone ?: null, $gender ?: null, $dob ?: null,
                 $qualification ?: null, $specialization ?: null, $licenseNumber ?: null, $address ?: null]
            );
            $userId = $db->lastInsertId();

            // Insert into doctors table
            $db->query(
                "INSERT INTO doctors (user_id, clinic_id, specialty_id, department_id, registration_number,
                 consultation_fee, followup_fee, experience_years, default_slot_duration, is_available, bio)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)",
                [$userId, $clinicId, $specialtyId, $departmentId, $regNumber ?: null,
                 $consultFee, $followupFee, $expYears, $slotDuration, $isAvailable, $bio ?: null]
            );
            $newDocId = $db->lastInsertId();

            logAudit('create', 'doctors', 'doctor', $newDocId);
            setFlashMessage('success', 'Doctor registered successfully.');
        }

        $db->commit();
        header('Location: ' . BASE_URL . '/modules/doctors/list.php');
        exit;
    } catch (Exception $e) {
        $db->rollback();
        setFlashMessage('error', 'Error: ' . $e->getMessage());
    }
}

// NOW include header (outputs HTML) — after any potential redirect
$pageTitle = $isEdit ? 'Edit Doctor' : 'Add Doctor';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';

// Load specialties and departments for dropdowns
$specialties = $db->fetchAll("SELECT id, name FROM specialties WHERE is_active = 1 ORDER BY name");
$departments = $db->fetchAll("SELECT id, name FROM departments WHERE clinic_id = ? AND is_active = 1 ORDER BY name", [$clinicId]);
?>

<div class="content-header">
    <div>
        <ul class="breadcrumb">
            <li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li>
            <li><a href="<?= BASE_URL ?>/modules/doctors/list.php">Doctors</a></li>
            <li><?= $isEdit ? 'Edit' : 'Add' ?> Doctor</li>
        </ul>
        <h1><?= $isEdit ? 'Edit Doctor' : 'Register New Doctor' ?></h1>
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
                       value="<?= sanitizeOutput($doctor['full_name'] ?? '') ?>" placeholder="Dr. Full Name">
            </div>
            <div class="form-group">
                <label class="form-label">Username <span class="required">*</span></label>
                <input type="text" name="username" class="form-control" <?= $isEdit ? 'readonly' : 'required' ?>
                       value="<?= sanitizeOutput($doctor['username'] ?? '') ?>" placeholder="Login username">
            </div>
            <div class="form-group">
                <label class="form-label">Password <?= $isEdit ? '' : '<span class="required">*</span>' ?></label>
                <div style="position: relative;">
                    <input type="password" name="password" id="passwordField" class="form-control" <?= $isEdit ? '' : 'required' ?>
                           placeholder="<?= $isEdit ? 'Leave blank to keep' : 'Login password' ?>" style="padding-right: 40px;">
                    <button type="button" onclick="togglePassword('passwordField', this)" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: var(--text-muted);">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- Personal Info -->
        <h4 class="mb-16"><i class="fas fa-user-md" style="color: var(--info);"></i> Personal Information</h4>
        <div class="form-row mb-24">
            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control"
                       value="<?= sanitizeOutput($doctor['email'] ?? '') ?>" placeholder="doctor@clinic.com">
            </div>
            <div class="form-group">
                <label class="form-label">Phone</label>
                <input type="tel" name="phone" class="form-control"
                       value="<?= sanitizeOutput($doctor['phone'] ?? '') ?>" placeholder="+91 9876543210">
            </div>
            <div class="form-group">
                <label class="form-label">Gender</label>
                <select name="gender" class="form-control">
                    <option value="">Select Gender</option>
                    <?php foreach (GENDER_OPTIONS as $g): ?>
                    <option value="<?= $g ?>" <?= ($doctor['gender'] ?? '') === $g ? 'selected' : '' ?>><?= $g ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-row mb-24">
            <div class="form-group">
                <label class="form-label">Date of Birth</label>
                <input type="date" name="date_of_birth" class="form-control"
                       value="<?= $doctor['date_of_birth'] ?? '' ?>" max="<?= date('Y-m-d') ?>">
            </div>
            <div class="form-group" style="grid-column: span 2;">
                <label class="form-label">Address</label>
                <input type="text" name="address" class="form-control"
                       value="<?= sanitizeOutput($doctor['address'] ?? '') ?>" placeholder="Full address">
            </div>
        </div>

        <!-- Professional Info -->
        <h4 class="mb-16 mt-24"><i class="fas fa-stethoscope" style="color: var(--success);"></i> Professional Details</h4>
        <div class="form-row mb-24">
            <div class="form-group">
                <label class="form-label">Specialty</label>
                <div style="display: flex; gap: 8px;">
                    <select name="specialty_id" id="specialtySelect" class="form-control">
                        <option value="">Select Specialty</option>
                        <?php foreach ($specialties as $sp): ?>
                        <option value="<?= $sp['id'] ?>" <?= ($doctor['specialty_id'] ?? '') == $sp['id'] ? 'selected' : '' ?>><?= sanitizeOutput($sp['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn btn-outline" style="padding: 0 12px;" onclick="openQuickAdd('specialty')" title="Add New Specialty">
                        <i class="fas fa-plus"></i>
                    </button>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Qualification</label>
                <input type="text" name="qualification" class="form-control"
                       value="<?= sanitizeOutput($doctor['qualification'] ?? '') ?>" placeholder="MBBS, MD, etc.">
            </div>
            <div class="form-group">
                <label class="form-label">Specialization</label>
                <input type="text" name="specialization" class="form-control"
                       value="<?= sanitizeOutput($doctor['specialization'] ?? '') ?>" placeholder="Area of expertise">
            </div>
        </div>
        <div class="form-row mb-24">
            <div class="form-group">
                <label class="form-label">License Number</label>
                <input type="text" name="license_number" class="form-control"
                       value="<?= sanitizeOutput($doctor['license_number'] ?? '') ?>" placeholder="Medical license #">
            </div>
            <div class="form-group">
                <label class="form-label">Registration Number</label>
                <input type="text" name="registration_number" class="form-control"
                       value="<?= sanitizeOutput($doctor['registration_number'] ?? '') ?>" placeholder="Council reg #">
            </div>
            <div class="form-group">
                <label class="form-label">Experience (Years)</label>
                <input type="number" name="experience_years" class="form-control" min="0"
                       value="<?= $doctor['experience_years'] ?? 0 ?>">
            </div>
        </div>

        <!-- Clinic Settings -->
        <h4 class="mb-16 mt-24"><i class="fas fa-hospital" style="color: var(--warning);"></i> Clinic Settings</h4>
        <div class="form-row mb-24">
            <div class="form-group">
                <label class="form-label">Consultation Fee (<?= CURRENCY_SYMBOL ?>)</label>
                <input type="number" name="consultation_fee" class="form-control" min="0" step="0.01"
                       value="<?= $doctor['consultation_fee'] ?? 0 ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Follow-up Fee (<?= CURRENCY_SYMBOL ?>)</label>
                <input type="number" name="followup_fee" class="form-control" min="0" step="0.01"
                       value="<?= $doctor['followup_fee'] ?? 0 ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Slot Duration (min)</label>
                <select name="default_slot_duration" class="form-control">
                    <?php foreach ([10,15,20,30,45,60] as $d): ?>
                    <option value="<?= $d ?>" <?= ($doctor['default_slot_duration'] ?? 15) == $d ? 'selected' : '' ?>><?= $d ?> min</option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-row mb-24">
            <div class="form-group">
                <label class="form-label">Department</label>
                <div style="display: flex; gap: 8px;">
                    <select name="department_id" id="departmentSelect" class="form-control">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $dept): ?>
                        <option value="<?= $dept['id'] ?>" <?= ($doctor['department_id'] ?? '') == $dept['id'] ? 'selected' : '' ?>><?= sanitizeOutput($dept['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn btn-outline" style="padding: 0 12px;" onclick="openQuickAdd('department')" title="Add New Department">
                        <i class="fas fa-plus"></i>
                    </button>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label" style="margin-bottom: 8px;">Availability</label>
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                    <input type="checkbox" name="is_available" value="1"
                           <?= ($doctor['is_available'] ?? 1) ? 'checked' : '' ?>>
                    <span>Available for appointments</span>
                </label>
            </div>
            <div class="form-group">
                <!-- spacer -->
            </div>
        </div>

        <!-- Bio -->
        <div class="form-row">
            <div class="form-group" style="grid-column: span 3;">
                <label class="form-label">Bio / About</label>
                <textarea name="bio" class="form-control" rows="3"
                          placeholder="Brief professional bio..."><?= sanitizeOutput($doctor['bio'] ?? '') ?></textarea>
            </div>
        </div>
    </div>

    <div class="card-footer d-flex justify-end gap-12">
        <a href="<?= BASE_URL ?>/modules/doctors/list.php" class="btn btn-outline">Cancel</a>
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-<?= $isEdit ? 'save' : 'user-md' ?>"></i>
            <?= $isEdit ? 'Update Doctor' : 'Register Doctor' ?>
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
