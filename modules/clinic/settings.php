<?php
/**
 * Clinic Settings - Feature Gen Care
 */
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

require_once dirname(dirname(__DIR__)) . '/config/session.php';
requireAuth();
requireRole([ROLE_SUPER_ADMIN, ROLE_ADMIN]);

$db = db();
$pdo = $db->getConnection();

// 1. Direct Self-Healing for Tenant DB 'clinics' table
try {
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'clinics'")->fetch();
    if ($tableCheck) {
        $cols = $pdo->query("SHOW COLUMNS FROM clinics")->fetchAll(PDO::FETCH_COLUMN);
        $colDefinitions = [
            'logo' => "VARCHAR(255) NULL AFTER name",
            'email' => "VARCHAR(150) NULL",
            'phone' => "VARCHAR(20) NULL",
            'address' => "TEXT NULL",
            'city' => "VARCHAR(100) NULL",
            'state' => "VARCHAR(100) NULL",
            'pincode' => "VARCHAR(10) NULL",
            'website' => "VARCHAR(200) NULL",
            'pan_number' => "VARCHAR(20) NULL",
            'gst_number' => "VARCHAR(20) NULL"
        ];
        foreach ($colDefinitions as $colName => $colDef) {
            if (!in_array($colName, $cols)) {
                $pdo->exec("ALTER TABLE clinics ADD COLUMN {$colName} {$colDef}");
            }
        }
    } else {
        // Table doesn't exist - create it
        $pdo->exec("CREATE TABLE IF NOT EXISTS clinics (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(200) NOT NULL,
            logo VARCHAR(255) NULL,
            email VARCHAR(150) NULL,
            phone VARCHAR(20) NULL,
            address TEXT NULL,
            city VARCHAR(100) NULL,
            state VARCHAR(100) NULL,
            pincode VARCHAR(10) NULL,
            website VARCHAR(200) NULL,
            pan_number VARCHAR(20) NULL,
            gst_number VARCHAR(20) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB;");
    }
} catch (Exception $e) {
    error_log("Settings schema self-healing error: " . $e->getMessage());
}

$clinicId = getCurrentClinicId();
$clinic = $db->fetch("SELECT * FROM clinics WHERE id = ?", [$clinicId]);

// Guarantee that row exists in clinics table for this clinicId
if (!$clinic) {
    $initialName = !empty($db->tenantInfo['clinic_name']) ? $db->tenantInfo['clinic_name'] : 'Feature Gen Care';
    $initialGst = $db->tenantInfo['gst_number'] ?? null;
    $initialPan = $db->tenantInfo['pan_number'] ?? null;
    $initialAddr = $db->tenantInfo['billing_address'] ?? null;
    try {
        $pdo = $db->getConnection();
        $pdo->prepare("INSERT INTO clinics (id, name, address, pan_number, gst_number) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE name=VALUES(name)")
            ->execute([$clinicId, $initialName, $initialAddr, $initialPan, $initialGst]);
        $_SESSION['clinic_id'] = $clinicId;
        $clinic = $db->fetch("SELECT * FROM clinics WHERE id = ?", [$clinicId]);
    } catch (Exception $e) {
        $clinic = [];
    }
}

// Fallback to tenantInfo from master DB if any fields are empty
$tenantInfo = $db->tenantInfo ?? [];
if (!empty($tenantInfo)) {
    if (empty($clinic['name']) && !empty($tenantInfo['clinic_name'])) {
        $clinic['name'] = $tenantInfo['clinic_name'];
    }
    if (empty($clinic['gst_number']) && !empty($tenantInfo['gst_number'])) {
        $clinic['gst_number'] = $tenantInfo['gst_number'];
    }
    if (empty($clinic['pan_number']) && !empty($tenantInfo['pan_number'])) {
        $clinic['pan_number'] = $tenantInfo['pan_number'];
    }
    if (empty($clinic['address']) && !empty($tenantInfo['billing_address'])) {
        $clinic['address'] = $tenantInfo['billing_address'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $name = sanitize($_POST['name'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $phone = sanitize($_POST['phone'] ?? '');
        $address = sanitize($_POST['address'] ?? '');
        $city = sanitize($_POST['city'] ?? '');
        $state = sanitize($_POST['state'] ?? '');
        $pincode = sanitize($_POST['pincode'] ?? '');
        $website = sanitize($_POST['website'] ?? '');
        $panNumber = strtoupper(sanitize(trim($_POST['pan_number'] ?? '')));
        $gstNumber = strtoupper(sanitize(trim($_POST['gst_number'] ?? '')));

        $logoFilename = $clinic['logo'] ?? null;
        if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
            $uploadStatus = uploadFile($_FILES['logo_file'], 'clinics');
            if ($uploadStatus['success']) {
                $logoFilename = $uploadStatus['filename'];
            } else {
                throw new Exception('Logo upload failed: ' . $uploadStatus['error']);
            }
        }

        // Save to tenant database 'clinics' table for this exact clinicId
        $existingClinic = $db->fetch("SELECT id FROM clinics WHERE id = ?", [$clinicId]);

        if ($existingClinic) {
            $db->query(
                "UPDATE clinics SET name=?, email=?, phone=?, address=?, city=?, state=?, pincode=?, website=?, pan_number=?, gst_number=?, logo=COALESCE(?, logo) WHERE id=?",
                [
                    $name, $email, $phone, $address, $city, $state,
                    $pincode, $website, $panNumber, $gstNumber,
                    $logoFilename, $clinicId
                ]
            );
            $_SESSION['clinic_id'] = $clinicId;
        } else {
            $db->query(
                "INSERT INTO clinics (id, name, email, phone, address, city, state, pincode, website, pan_number, gst_number, logo) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $clinicId, $name, $email, $phone, $address, $city, $state,
                    $pincode, $website, $panNumber, $gstNumber,
                    $logoFilename
                ]
            );
            $_SESSION['clinic_id'] = $clinicId;
        }

        // Sync clinic name, tax & address details to Master DB tenants table for subscription tax invoices
        $tenantId = intval($db->tenantInfo['id'] ?? 0);
        if ($tenantId > 0) {
            try {
                $master = master_db();
                $master->prepare("UPDATE tenants SET clinic_name = COALESCE(NULLIF(?, ''), clinic_name), gst_number = ?, pan_number = ?, billing_address = ? WHERE id = ?")
                       ->execute([
                           $name,
                           $gstNumber,
                           $panNumber,
                           $address,
                           $tenantId
                       ]);
            } catch (Exception $e) {
                error_log("Master DB tenant sync error: " . $e->getMessage());
            }
        }

        logAudit('update', 'settings', 'clinic', $targetClinicId ?? $clinicId);
        setFlashMessage('success', 'Clinic settings updated successfully.');
        header('Location: ' . BASE_URL . '/modules/clinic/settings.php?saved=' . time());
        exit;
    } catch (Exception $e) {
        setFlashMessage('error', 'Error: ' . $e->getMessage());
    }
}

// Retain values if rendering after POST failure
$valName = isset($_POST['name']) ? sanitize($_POST['name']) : ($clinic['name'] ?? '');
$valEmail = isset($_POST['email']) ? sanitize($_POST['email']) : ($clinic['email'] ?? '');
$valPhone = isset($_POST['phone']) ? sanitize($_POST['phone']) : ($clinic['phone'] ?? '');
$valAddress = isset($_POST['address']) ? sanitize($_POST['address']) : ($clinic['address'] ?? '');
$valCity = isset($_POST['city']) ? sanitize($_POST['city']) : ($clinic['city'] ?? '');
$valState = isset($_POST['state']) ? sanitize($_POST['state']) : ($clinic['state'] ?? '');
$valPincode = isset($_POST['pincode']) ? sanitize($_POST['pincode']) : ($clinic['pincode'] ?? '');
$valWebsite = isset($_POST['website']) ? sanitize($_POST['website']) : ($clinic['website'] ?? '');
$valPan = isset($_POST['pan_number']) ? strtoupper(sanitize(trim($_POST['pan_number']))) : ($clinic['pan_number'] ?? '');
$valGst = isset($_POST['gst_number']) ? strtoupper(sanitize(trim($_POST['gst_number']))) : ($clinic['gst_number'] ?? '');

$pageTitle = 'Clinic Settings';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="content-header">
    <div>
        <ul class="breadcrumb"><li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li><li>Clinic Settings</li></ul>
        <h1>Clinic Settings</h1>
    </div>
</div>

<div class="grid-3 gap-24">
    <div style="grid-column: span 2;">
        <form method="POST" action="<?= BASE_URL ?>/modules/clinic/settings.php" enctype="multipart/form-data" class="card">
            <div class="card-header"><h3><i class="fas fa-hospital" style="color: var(--primary);"></i> Clinic Information</h3></div>
            <div class="card-body">
                <div class="form-group mb-24" style="text-align: center; border: 1px dashed var(--border-color); padding: 20px; border-radius: 8px;">
                    <?php if (!empty($clinic['logo'])): ?>
                        <div class="mb-12">
                            <img src="<?= UPLOADS_URL ?>/clinics/<?= sanitizeOutput($clinic['logo']) ?>" alt="Clinic Logo" style="max-height: 80px; max-width: 100%; object-fit: contain;">
                        </div>
                    <?php endif; ?>
                    <label class="form-label d-block">Clinic Logo</label>
                    <input type="file" name="logo_file" class="form-control" accept="image/*" style="max-width: 300px; margin: 0 auto;">
                    <div class="text-muted mt-8" style="font-size: 11px;">Recommended height: 40px. Format: PNG or JPG. Max 2MB.</div>
                </div>
                <div class="form-group">
                    <label class="form-label">Clinic Name</label>
                    <input type="text" name="name" class="form-control" value="<?= sanitizeOutput($valName) ?>" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" value="<?= sanitizeOutput($valEmail) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Phone</label>
                        <input type="tel" name="phone" class="form-control" value="<?= sanitizeOutput($valPhone) ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Address</label>
                    <textarea name="address" class="form-control" rows="2"><?= sanitizeOutput($valAddress) ?></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">City</label>
                        <input type="text" name="city" class="form-control" value="<?= sanitizeOutput($valCity) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">State</label>
                        <input type="text" name="state" class="form-control" value="<?= sanitizeOutput($valState) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Pincode</label>
                        <input type="text" name="pincode" class="form-control" value="<?= sanitizeOutput($valPincode) ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Website</label>
                        <input type="url" name="website" class="form-control" value="<?= sanitizeOutput($valWebsite) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">PAN Number</label>
                        <input type="text" name="pan_number" class="form-control" value="<?= sanitizeOutput($valPan) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">GST Number</label>
                        <input type="text" name="gst_number" class="form-control" value="<?= sanitizeOutput($valGst) ?>">
                    </div>
                </div>
            </div>
            <div class="card-footer">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Settings</button>
            </div>
        </form>
    </div>
    
    <div>
        <div class="card mb-24">
            <div class="card-header"><h3><i class="fas fa-info-circle" style="color: var(--info);"></i> Quick Info</h3></div>
            <div class="card-body">
                <table style="font-size: 13px; width: 100%; line-height: 2;">
                    <tr><td class="text-muted">App Version</td><td style="text-align: right;"><?= APP_VERSION ?></td></tr>
                    <tr><td class="text-muted">Clinic ID</td><td style="text-align: right;"><?= $clinicId ?></td></tr>
                    <tr><td class="text-muted">Database</td><td style="text-align: right;"><span class="badge badge-success">Connected</span></td></tr>
                    <tr><td class="text-muted">PHP Version</td><td style="text-align: right;"><?= PHP_VERSION ?></td></tr>
                    <tr><td class="text-muted">Server</td><td style="text-align: right;"><?= in_array($_SERVER['SERVER_NAME'], ['localhost', '127.0.0.1']) ? 'Local' : 'Production' ?></td></tr>
                </table>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-link" style="color: var(--accent);"></i> Quick Links</h3></div>
            <div class="card-body">
                <a href="<?= BASE_URL ?>/modules/clinic/branches.php" class="btn btn-outline btn-block mb-8"><i class="fas fa-code-branch"></i> Manage Branches</a>
                <a href="<?= BASE_URL ?>/modules/admin/index.php" class="btn btn-outline btn-block mb-8"><i class="fas fa-cogs"></i> System Admin</a>
                <a href="<?= BASE_URL ?>/modules/communication/templates.php" class="btn btn-outline btn-block mb-8"><i class="fas fa-envelope"></i> Message Templates</a>
                <a href="<?= BASE_URL ?>/modules/reports/daily.php" class="btn btn-outline btn-block"><i class="fas fa-chart-bar"></i> Reports</a>
            </div>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
