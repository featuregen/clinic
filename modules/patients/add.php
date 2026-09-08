<?php
/**
 * Add/Edit Patient - Advanced Clinic Suite
 */
require_once dirname(dirname(__DIR__)) . '/config/session.php';
requireAuth();
requirePermission('patients.create');

$db = db();
$clinicId = getCurrentClinicId();
$patient = null;
$isEdit = false;

// Edit mode
if (isset($_GET['id'])) {
    $patient = $db->fetch("SELECT * FROM patients WHERE id = ? AND clinic_id = ?", [$_GET['id'], $clinicId]);
    if ($patient) {
        $isEdit = true;
    }
}

// Handle form submission BEFORE any output
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'clinic_id' => $clinicId,
        'first_name' => sanitize($_POST['first_name']),
        'last_name' => sanitize($_POST['last_name'] ?? ''),
        'email' => sanitize($_POST['email'] ?? ''),
        'phone' => sanitize($_POST['phone']),
        'alt_phone' => sanitize($_POST['alt_phone'] ?? ''),
        'gender' => sanitize($_POST['gender'] ?? ''),
        'date_of_birth' => $_POST['date_of_birth'] ?? null,
        'blood_group' => $_POST['blood_group'] ?? null,
        'marital_status' => $_POST['marital_status'] ?? null,
        'occupation' => sanitize($_POST['occupation'] ?? ''),
        'address' => sanitize($_POST['address'] ?? ''),
        'city' => sanitize($_POST['city'] ?? ''),
        'state' => sanitize($_POST['state'] ?? ''),
        'pincode' => sanitize($_POST['pincode'] ?? ''),
        'emergency_contact_name' => sanitize($_POST['emergency_contact_name'] ?? ''),
        'emergency_contact_phone' => sanitize($_POST['emergency_contact_phone'] ?? ''),
        'emergency_contact_relation' => sanitize($_POST['emergency_contact_relation'] ?? ''),
        'insurance_provider' => sanitize($_POST['insurance_provider'] ?? ''),
        'insurance_policy_no' => sanitize($_POST['insurance_policy_no'] ?? ''),
        'insurance_expiry' => $_POST['insurance_expiry'] ?? null,
        'referred_by' => sanitize($_POST['referred_by'] ?? ''),
        'notes' => sanitize($_POST['notes'] ?? '')
    ];

    // Calculate age from DOB
    if (!empty($data['date_of_birth'])) {
        $data['age'] = calculateAge($data['date_of_birth']);
    }

    try {
        if ($isEdit) {
            $setClauses = [];
            $updateParams = [];
            foreach ($data as $key => $value) {
                $setClauses[] = "$key = ?";
                $updateParams[] = $value ?: null;
            }
            $updateParams[] = $patient['id'];
            
            $db->query(
                "UPDATE patients SET " . implode(', ', $setClauses) . " WHERE id = ?",
                $updateParams
            );
            logAudit('update', 'patients', 'patient', $patient['id']);
            setFlashMessage('success', 'Patient updated successfully.');
        } else {
            $data['patient_uid'] = generatePatientId();
            
            $columns = implode(', ', array_keys($data));
            $placeholders = implode(', ', array_fill(0, count($data), '?'));
            
            $db->query(
                "INSERT INTO patients ($columns) VALUES ($placeholders)",
                array_map(function($v) { return $v ?: null; }, array_values($data))
            );
            
            $newId = $db->lastInsertId();
            logAudit('create', 'patients', 'patient', $newId);
            setFlashMessage('success', 'Patient registered successfully with ID: ' . $data['patient_uid']);
        }
        
        header('Location: ' . BASE_URL . '/modules/patients/list.php');
        exit;
    } catch (Exception $e) {
        setFlashMessage('error', 'Error: ' . $e->getMessage());
    }
}

// NOW include header (which outputs HTML) — after any potential redirect
$pageTitle = $isEdit ? 'Edit Patient' : 'Add Patient';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="content-header">
    <div>
        <ul class="breadcrumb">
            <li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li>
            <li><a href="<?= BASE_URL ?>/modules/patients/list.php">Patients</a></li>
            <li><?= $isEdit ? 'Edit' : 'Add' ?> Patient</li>
        </ul>
        <h1><?= $isEdit ? 'Edit Patient' : 'Register New Patient' ?></h1>
    </div>
</div>

<form method="POST" action="" class="card">
    <div class="card-body">
        <!-- Personal Information -->
        <h4 class="mb-16"><i class="fas fa-user" style="color: var(--primary);"></i> Personal Information</h4>
        <div class="form-row mb-24">
            <div class="form-group">
                <label class="form-label">First Name <span class="required">*</span></label>
                <input type="text" name="first_name" class="form-control" required
                       value="<?= sanitizeOutput($patient['first_name'] ?? '') ?>" placeholder="Enter first name">
            </div>
            <div class="form-group">
                <label class="form-label">Last Name</label>
                <input type="text" name="last_name" class="form-control"
                       value="<?= sanitizeOutput($patient['last_name'] ?? '') ?>" placeholder="Enter last name">
            </div>
            <div class="form-group">
                <label class="form-label">Phone <span class="required">*</span></label>
                <input type="tel" name="phone" class="form-control" required
                       value="<?= sanitizeOutput($patient['phone'] ?? '') ?>" placeholder="+91 9876543210">
            </div>
        </div>
        
        <div class="form-row mb-24">
            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control"
                       value="<?= sanitizeOutput($patient['email'] ?? '') ?>" placeholder="email@example.com">
            </div>
            <div class="form-group">
                <label class="form-label">Gender</label>
                <select name="gender" class="form-control">
                    <option value="">Select Gender</option>
                    <?php foreach (GENDER_OPTIONS as $g): ?>
                    <option value="<?= $g ?>" <?= ($patient['gender'] ?? '') === $g ? 'selected' : '' ?>><?= $g ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Date of Birth</label>
                <input type="date" name="date_of_birth" class="form-control"
                       value="<?= $patient['date_of_birth'] ?? '' ?>" max="<?= date('Y-m-d') ?>">
            </div>
        </div>
        
        <div class="form-row mb-24">
            <div class="form-group">
                <label class="form-label">Blood Group</label>
                <select name="blood_group" class="form-control">
                    <option value="">Select Blood Group</option>
                    <?php foreach (BLOOD_GROUPS as $bg): ?>
                    <option value="<?= $bg ?>" <?= ($patient['blood_group'] ?? '') === $bg ? 'selected' : '' ?>><?= $bg ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Marital Status</label>
                <select name="marital_status" class="form-control">
                    <option value="">Select</option>
                    <?php foreach (['Single','Married','Divorced','Widowed'] as $ms): ?>
                    <option value="<?= $ms ?>" <?= ($patient['marital_status'] ?? '') === $ms ? 'selected' : '' ?>><?= $ms ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Occupation</label>
                <input type="text" name="occupation" class="form-control"
                       value="<?= sanitizeOutput($patient['occupation'] ?? '') ?>" placeholder="Occupation">
            </div>
        </div>
        
        <!-- Contact & Address -->
        <h4 class="mb-16 mt-24"><i class="fas fa-map-marker-alt" style="color: var(--success);"></i> Address & Contact</h4>
        <div class="form-row mb-24">
            <div class="form-group" style="grid-column: span 2;">
                <label class="form-label">Address</label>
                <textarea name="address" class="form-control" rows="2" placeholder="Full address"><?= sanitizeOutput($patient['address'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Alt. Phone</label>
                <input type="tel" name="alt_phone" class="form-control"
                       value="<?= sanitizeOutput($patient['alt_phone'] ?? '') ?>" placeholder="Alternate phone">
            </div>
        </div>
        
        <div class="form-row mb-24">
            <div class="form-group">
                <label class="form-label">City</label>
                <input type="text" name="city" class="form-control"
                       value="<?= sanitizeOutput($patient['city'] ?? '') ?>" placeholder="City">
            </div>
            <div class="form-group">
                <label class="form-label">State</label>
                <input type="text" name="state" class="form-control"
                       value="<?= sanitizeOutput($patient['state'] ?? '') ?>" placeholder="State">
            </div>
            <div class="form-group">
                <label class="form-label">Pincode</label>
                <input type="text" name="pincode" class="form-control"
                       value="<?= sanitizeOutput($patient['pincode'] ?? '') ?>" placeholder="Pincode">
            </div>
        </div>
        
        <!-- Emergency Contact -->
        <h4 class="mb-16 mt-24"><i class="fas fa-phone-alt" style="color: var(--danger);"></i> Emergency Contact</h4>
        <div class="form-row mb-24">
            <div class="form-group">
                <label class="form-label">Contact Name</label>
                <input type="text" name="emergency_contact_name" class="form-control"
                       value="<?= sanitizeOutput($patient['emergency_contact_name'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Contact Phone</label>
                <input type="tel" name="emergency_contact_phone" class="form-control"
                       value="<?= sanitizeOutput($patient['emergency_contact_phone'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Relation</label>
                <input type="text" name="emergency_contact_relation" class="form-control"
                       value="<?= sanitizeOutput($patient['emergency_contact_relation'] ?? '') ?>" placeholder="e.g. Spouse, Parent">
            </div>
        </div>
        
        <!-- Insurance -->
        <h4 class="mb-16 mt-24"><i class="fas fa-shield-alt" style="color: var(--info);"></i> Insurance Details</h4>
        <div class="form-row mb-24">
            <div class="form-group">
                <label class="form-label">Insurance Provider</label>
                <input type="text" name="insurance_provider" class="form-control"
                       value="<?= sanitizeOutput($patient['insurance_provider'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Policy Number</label>
                <input type="text" name="insurance_policy_no" class="form-control"
                       value="<?= sanitizeOutput($patient['insurance_policy_no'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Insurance Expiry</label>
                <input type="date" name="insurance_expiry" class="form-control"
                       value="<?= $patient['insurance_expiry'] ?? '' ?>">
            </div>
        </div>
        
        <!-- Other -->
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Referred By</label>
                <input type="text" name="referred_by" class="form-control"
                       value="<?= sanitizeOutput($patient['referred_by'] ?? '') ?>">
            </div>
            <div class="form-group" style="grid-column: span 2;">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2"><?= sanitizeOutput($patient['notes'] ?? '') ?></textarea>
            </div>
        </div>
    </div>
    
    <div class="card-footer d-flex justify-end gap-12">
        <a href="<?= BASE_URL ?>/modules/patients/list.php" class="btn btn-outline">Cancel</a>
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-<?= $isEdit ? 'save' : 'user-plus' ?>"></i>
            <?= $isEdit ? 'Update Patient' : 'Register Patient' ?>
        </button>
    </div>
</form>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
