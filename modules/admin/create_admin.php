<?php
/**
 * Create Clinic Admin - Feature Gen Care
 * Super Admin Only: Provision an Administrator Account for the Client
 */
require_once dirname(dirname(__DIR__)) . '/config/session.php';
requireAuth();

// Only Super Admin can provision a Clinic Admin account
if (getCurrentUserRole() !== ROLE_SUPER_ADMIN) {
    header('Location: ' . BASE_URL . '/modules/auth/403.php');
    exit;
}

$db = db();
$clinicId = getCurrentClinicId();
$createdAccount = null;

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = sanitize($_POST['full_name'] ?? '');
    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $email = sanitize($_POST['email'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    $branchId = intval($_POST['branch_id'] ?? 0) ?: null;

    try {
        if (empty($fullName) || empty($username) || empty($password)) {
            throw new Exception("Full Name, Username, and Password are required.");
        }

        // Check if username already exists
        $existing = $db->fetch("SELECT id FROM users WHERE username = ?", [$username]);
        if ($existing) {
            throw new Exception("Username '@{$username}' is already taken. Please choose another.");
        }

        // Get admin role ID
        $roleRow = $db->fetch("SELECT id FROM roles WHERE name = 'admin'");
        $roleId = $roleRow['id'] ?? 2;

        // Auto-assign single branch if not specified
        $branches = $db->fetchAll("SELECT id, name FROM branches WHERE clinic_id = ? AND is_active = 1 ORDER BY name", [$clinicId]);
        if (!$branchId && count($branches) === 1) {
            $branchId = $branches[0]['id'];
        }

        $db->query(
            "INSERT INTO users (clinic_id, branch_id, role_id, role, username, password, full_name, email, phone, is_active)
             VALUES (?, ?, ?, 'admin', ?, ?, ?, ?, ?, 1)",
            [
                $clinicId,
                $branchId,
                $roleId,
                $username,
                password_hash($password, PASSWORD_DEFAULT),
                $fullName,
                $email ?: null,
                $phone ?: null
            ]
        );

        $newUserId = $db->lastInsertId();
        logAudit('create_admin', 'users', 'user', $newUserId);

        // Fetch clinic details for the handover message
        $clinicInfo = $db->fetch("SELECT name FROM clinics WHERE id = ?", [$clinicId]);
        $clinicName = $clinicInfo['name'] ?? APP_NAME;
        $loginUrl = BASE_URL . '/index.php';

        $createdAccount = [
            'full_name'   => $fullName,
            'username'    => $username,
            'password'    => $password,
            'email'       => $email,
            'phone'       => $phone,
            'clinic_name' => $clinicName,
            'login_url'   => $loginUrl
        ];

        setFlashMessage('success', "Clinic Admin account '@{$username}' created successfully.");
    } catch (Exception $e) {
        setFlashMessage('error', $e->getMessage());
    }
}

// Fetch available branches
$branches = $db->fetchAll("SELECT id, name FROM branches WHERE clinic_id = ? AND is_active = 1 ORDER BY name", [$clinicId]);

$pageTitle = 'Create Clinic Admin';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="content-header">
    <div>
        <ul class="breadcrumb">
            <li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li>
            <li><a href="<?= BASE_URL ?>/modules/admin/index.php">System Admin</a></li>
            <li><a href="<?= BASE_URL ?>/modules/admin/users.php">Users</a></li>
            <li>Create Clinic Admin</li>
        </ul>
        <h1><i class="fas fa-user-shield" style="color: #d97706;"></i> Provision Client Administrator</h1>
        <p class="text-muted" style="margin-top: 4px; font-size: 14px;">
            Create an <strong>Admin account for your client</strong>. The client will have full operational control over patients, appointments, doctors, billing, and clinic operations, while subscription pricing and branch additions remain strictly under Super Admin.
        </p>
    </div>
</div>

<?php if ($createdAccount): ?>
    <!-- Client Handover Card -->
    <div class="card mb-24" style="border: 2px solid #10b981; background: #f0fdf4;">
        <div class="card-header" style="background: #dcfce7; border-bottom: 1px solid #bbf7d0; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; color: #166534; display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-check-circle"></i> Account Provisioned & Ready for Handover
            </h3>
            <button class="btn btn-sm btn-primary" onclick="copyHandoverCredentials()">
                <i class="fas fa-copy"></i> Copy Onboarding Note
            </button>
        </div>
        <div class="card-body" style="padding: 24px;">
            <p style="color: #14532d; margin-bottom: 16px;">
                Share these login credentials directly with your client:
            </p>
            <div style="background: white; border: 1px solid #86efac; border-radius: 8px; padding: 18px; font-family: monospace; font-size: 14px; line-height: 1.8;" id="credentialBox">
<strong>Portal Login:</strong> <?= $createdAccount['login_url'] ?><br>
<strong>Clinic:</strong> <?= sanitizeOutput($createdAccount['clinic_name']) ?><br>
<strong>Admin Name:</strong> <?= sanitizeOutput($createdAccount['full_name']) ?><br>
<strong>Username:</strong> <?= sanitizeOutput($createdAccount['username']) ?><br>
<strong>Password:</strong> <?= htmlspecialchars($createdAccount['password']) ?><br>
<strong>Role:</strong> Clinic Administrator (Full Access)
            </div>
            <div style="margin-top: 16px; display: flex; gap: 12px;">
                <a href="<?= BASE_URL ?>/modules/admin/users.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Back to User List
                </a>
                <button class="btn btn-primary" onclick="window.print()">
                    <i class="fas fa-print"></i> Print Welcome Sheet
                </button>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="grid-3 gap-24">
    <div style="grid-column: span 2;">
        <form method="POST" action="" class="card">
            <div class="card-header">
                <h3><i class="fas fa-user-plus" style="color: var(--primary);"></i> Client Admin Details</h3>
            </div>
            <div class="card-body">
                <div class="form-row mb-20">
                    <div class="form-group" style="flex: 2;">
                        <label class="form-label">Administrator Full Name <span style="color: red;">*</span></label>
                        <input type="text" name="full_name" class="form-control" placeholder="e.g. Dr. Rajesh Sharma or Clinic Manager" required>
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Username <span style="color: red;">*</span></label>
                        <input type="text" name="username" id="adminUsername" class="form-control" placeholder="e.g. admin or clinicadmin" required>
                    </div>
                </div>

                <div class="form-row mb-20">
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Password <span style="color: red;">*</span></label>
                        <div style="position: relative;">
                            <input type="text" name="password" id="adminPassword" class="form-control" placeholder="Set temporary password" required style="padding-right: 120px;">
                            <button type="button" onclick="generatePassword()" class="btn btn-sm btn-outline" style="position: absolute; right: 6px; top: 50%; transform: translateY(-50%); font-size: 11px; padding: 4px 8px;">
                                <i class="fas fa-random"></i> Generate
                            </button>
                        </div>
                        <small class="text-muted" style="display: block; margin-top: 4px;">Client can change this password after first login.</small>
                    </div>
                </div>

                <div class="form-row mb-20">
                    <div class="form-group">
                        <label class="form-label">Email Address</label>
                        <input type="email" name="email" class="form-control" placeholder="client@clinic.com">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Phone Number</label>
                        <input type="tel" name="phone" class="form-control" placeholder="+91 9876543210">
                    </div>
                </div>

                <?php if (count($branches) > 1): ?>
                <div class="form-group mb-20">
                    <label class="form-label">Primary Branch Assignment</label>
                    <select name="branch_id" class="form-control">
                        <option value="">All Branches (Oversees Entire Clinic)</option>
                        <?php foreach ($branches as $branch): ?>
                        <option value="<?= $branch['id'] ?>"><?= sanitizeOutput($branch['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php else: ?>
                    <input type="hidden" name="branch_id" value="<?= $branches[0]['id'] ?? '' ?>">
                <?php endif; ?>
            </div>
            
            <div class="card-footer" style="display: flex; justify-content: space-between; align-items: center; background: #fafbfc;">
                <a href="<?= BASE_URL ?>/modules/admin/users.php" class="btn btn-outline">Cancel</a>
                <button type="submit" class="btn btn-primary" style="background: #d97706; border-color: #b45309;">
                    <i class="fas fa-user-shield"></i> Create Clinic Admin Account
                </button>
            </div>
        </form>
    </div>

    <div>
        <div class="card mb-20">
            <div class="card-header">
                <h3><i class="fas fa-shield-alt" style="color: var(--primary);"></i> Admin Permissions</h3>
            </div>
            <div class="card-body" style="font-size: 13px; line-height: 1.8;">
                <p class="text-muted">The <strong>Clinic Admin</strong> role grants full operational capabilities to your client:</p>
                <ul style="padding-left: 18px; margin: 0;">
                    <li>Patient Registrations & Profiles</li>
                    <li>Appointments & Doctor Calendars</li>
                    <li>Digital Prescriptions & Templates</li>
                    <li>Dental & Vaccination Charts</li>
                    <li>Patient Invoicing & Collection</li>
                    <li>Doctor & Staff Management</li>
                    <li>Clinic Profile & Letterhead</li>
                    <li>View Branch Directory (Read-only)</li>
                </ul>
            </div>
        </div>

        <div class="card" style="background: #fffbeb; border: 1px solid #fde68a;">
            <div class="card-body" style="font-size: 13px; color: #92400e; line-height: 1.6;">
                <i class="fas fa-lock" style="font-size: 18px; float: left; margin-right: 10px; margin-top: 2px;"></i>
                <strong>Protected Boundaries:</strong> Clinic Admins <u>cannot</u> see SaaS subscription prices, negotiate bonus months, record cash-on-hand renewals, or create/delete physical branches. Those controls remain 100% exclusive to you (Super Admin).
            </div>
        </div>
    </div>
</div>

<script>
function generatePassword() {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$%';
    let pass = '';
    for (let i = 0; i < 10; i++) {
        pass += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    document.getElementById('adminPassword').value = pass;
}

function copyHandoverCredentials() {
    const box = document.getElementById('credentialBox');
    const text = box.innerText;
    navigator.clipboard.writeText(text).then(() => {
        alert('Client onboarding credentials copied to clipboard!');
    });
}
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
