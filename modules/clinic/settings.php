<?php
/**
 * Clinic Settings - Advanced Clinic Suite
 */
require_once dirname(dirname(__DIR__)) . '/config/session.php';
requireAuth();
requireRole([ROLE_SUPER_ADMIN, ROLE_ADMIN]);

$db = db();
$clinicId = getCurrentClinicId();
$clinic = $db->fetch("SELECT * FROM clinics WHERE id = ?", [$clinicId]) ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $logoFilename = $clinic['logo'] ?? null;
        if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
            $uploadStatus = uploadFile($_FILES['logo_file'], 'clinics');
            if ($uploadStatus['success']) {
                $logoFilename = $uploadStatus['filename'];
            } else {
                throw new Exception('Logo upload failed: ' . $uploadStatus['error']);
            }
        }

        $db->query(
            "UPDATE clinics SET name=?, email=?, phone=?, address=?, city=?, state=?, pincode=?, website=?, pan_number=?, gst_number=?, logo=? WHERE id=?",
            [
                sanitize($_POST['name']), sanitize($_POST['email'] ?? ''), sanitize($_POST['phone'] ?? ''),
                sanitize($_POST['address'] ?? ''), sanitize($_POST['city'] ?? ''), sanitize($_POST['state'] ?? ''),
                sanitize($_POST['pincode'] ?? ''), sanitize($_POST['website'] ?? ''),
                sanitize($_POST['pan_number'] ?? ''), sanitize($_POST['gst_number'] ?? ''),
                $logoFilename, $clinicId
            ]
        );
        logAudit('update', 'settings', 'clinic', $clinicId);
        setFlashMessage('success', 'Clinic settings updated successfully.');
        header('Location: ' . BASE_URL . '/modules/clinic/settings.php');
        exit;
    } catch (Exception $e) {
        setFlashMessage('error', 'Error: ' . $e->getMessage());
    }
}

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
        <form method="POST" enctype="multipart/form-data" class="card">
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
                    <input type="text" name="name" class="form-control" value="<?= sanitizeOutput($clinic['name'] ?? '') ?>" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" value="<?= sanitizeOutput($clinic['email'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Phone</label>
                        <input type="tel" name="phone" class="form-control" value="<?= sanitizeOutput($clinic['phone'] ?? '') ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Address</label>
                    <textarea name="address" class="form-control" rows="2"><?= sanitizeOutput($clinic['address'] ?? '') ?></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">City</label>
                        <input type="text" name="city" class="form-control" value="<?= sanitizeOutput($clinic['city'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">State</label>
                        <input type="text" name="state" class="form-control" value="<?= sanitizeOutput($clinic['state'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Pincode</label>
                        <input type="text" name="pincode" class="form-control" value="<?= sanitizeOutput($clinic['pincode'] ?? '') ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Website</label>
                        <input type="url" name="website" class="form-control" value="<?= sanitizeOutput($clinic['website'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">PAN Number</label>
                        <input type="text" name="pan_number" class="form-control" value="<?= sanitizeOutput($clinic['pan_number'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">GST Number</label>
                        <input type="text" name="gst_number" class="form-control" value="<?= sanitizeOutput($clinic['gst_number'] ?? '') ?>">
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
                <a href="<?= BASE_URL ?>/modules/admin/index.php" class="btn btn-outline btn-block mb-8"><i class="fas fa-cogs"></i> System Admin</a>
                <a href="<?= BASE_URL ?>/modules/communication/templates.php" class="btn btn-outline btn-block mb-8"><i class="fas fa-envelope"></i> Message Templates</a>
                <a href="<?= BASE_URL ?>/modules/reports/daily.php" class="btn btn-outline btn-block"><i class="fas fa-chart-bar"></i> Reports</a>
            </div>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
