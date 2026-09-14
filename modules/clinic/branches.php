<?php
/**
 * Branch Management - Feature Gen Care
 */
require_once dirname(dirname(__DIR__)) . '/config/session.php';
requireAuth();
requireRole([ROLE_SUPER_ADMIN, ROLE_ADMIN]);

$db = db();
$clinicId = getCurrentClinicId();

// Self-healing: Ensure branches table exists
try {
    $db->query("
        CREATE TABLE IF NOT EXISTS branches (
            id INT AUTO_INCREMENT PRIMARY KEY,
            clinic_id INT NOT NULL,
            name VARCHAR(200) NOT NULL,
            code VARCHAR(20) DEFAULT NULL,
            email VARCHAR(150) DEFAULT NULL,
            phone VARCHAR(20) DEFAULT NULL,
            address TEXT DEFAULT NULL,
            city VARCHAR(100) DEFAULT NULL,
            state VARCHAR(100) DEFAULT NULL,
            pincode VARCHAR(10) DEFAULT NULL,
            working_hours_start TIME DEFAULT '09:00:00',
            working_hours_end TIME DEFAULT '21:00:00',
            working_days VARCHAR(50) DEFAULT '1,2,3,4,5,6',
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_branch_clinic (clinic_id)
        ) ENGINE=InnoDB;
    ");
} catch (Exception $e) {
    error_log("Branches Table Check: " . $e->getMessage());
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'create') {
            $name = sanitize($_POST['name'] ?? '');
            $code = strtoupper(sanitize($_POST['code'] ?? ''));
            $phone = sanitize($_POST['phone'] ?? '');
            $email = sanitize($_POST['email'] ?? '');
            $address = sanitize($_POST['address'] ?? '');
            $city = sanitize($_POST['city'] ?? '');
            $state = sanitize($_POST['state'] ?? '');
            $pincode = sanitize($_POST['pincode'] ?? '');
            $workingHoursStart = sanitize($_POST['working_hours_start'] ?? '09:00:00');
            $workingHoursEnd = sanitize($_POST['working_hours_end'] ?? '21:00:00');
            $workingDays = isset($_POST['working_days']) ? implode(',', (array)$_POST['working_days']) : '1,2,3,4,5,6';
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            
            if (empty($name)) {
                throw new Exception("Branch name is required.");
            }
            
            $db->query(
                "INSERT INTO branches (clinic_id, name, code, phone, email, address, city, state, pincode, working_hours_start, working_hours_end, working_days, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$clinicId, $name, $code, $phone, $email, $address, $city, $state, $pincode, $workingHoursStart, $workingHoursEnd, $workingDays, $isActive]
            );
            
            logAudit('create', 'branch', 'branches', $db->lastInsertId());
            setFlashMessage('success', "Branch '{$name}' created successfully.");
            header('Location: ' . BASE_URL . '/modules/clinic/branches.php');
            exit;
            
        } elseif ($action === 'update') {
            $branchId = (int)($_POST['branch_id'] ?? 0);
            $name = sanitize($_POST['name'] ?? '');
            $code = strtoupper(sanitize($_POST['code'] ?? ''));
            $phone = sanitize($_POST['phone'] ?? '');
            $email = sanitize($_POST['email'] ?? '');
            $address = sanitize($_POST['address'] ?? '');
            $city = sanitize($_POST['city'] ?? '');
            $state = sanitize($_POST['state'] ?? '');
            $pincode = sanitize($_POST['pincode'] ?? '');
            $workingHoursStart = sanitize($_POST['working_hours_start'] ?? '09:00:00');
            $workingHoursEnd = sanitize($_POST['working_hours_end'] ?? '21:00:00');
            $workingDays = isset($_POST['working_days']) ? implode(',', (array)$_POST['working_days']) : '1,2,3,4,5,6';
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            
            if (empty($name)) {
                throw new Exception("Branch name is required.");
            }
            
            $db->query(
                "UPDATE branches SET name=?, code=?, phone=?, email=?, address=?, city=?, state=?, pincode=?, working_hours_start=?, working_hours_end=?, working_days=?, is_active=?
                 WHERE id=? AND clinic_id=?",
                [$name, $code, $phone, $email, $address, $city, $state, $pincode, $workingHoursStart, $workingHoursEnd, $workingDays, $isActive, $branchId, $clinicId]
            );
            
            logAudit('update', 'branch', 'branches', $branchId);
            setFlashMessage('success', "Branch '{$name}' updated successfully.");
            header('Location: ' . BASE_URL . '/modules/clinic/branches.php');
            exit;
            
        } elseif ($action === 'toggle_status') {
            $branchId = (int)($_POST['branch_id'] ?? 0);
            $currentStatus = (int)($_POST['current_status'] ?? 0);
            $newStatus = $currentStatus === 1 ? 0 : 1;
            
            $db->query("UPDATE branches SET is_active=? WHERE id=? AND clinic_id=?", [$newStatus, $branchId, $clinicId]);
            logAudit('toggle_status', 'branch', 'branches', $branchId);
            setFlashMessage('success', "Branch status updated.");
            header('Location: ' . BASE_URL . '/modules/clinic/branches.php');
            exit;
        }
    } catch (Exception $e) {
        setFlashMessage('error', $e->getMessage());
    }
}

// Fetch all branches safely
$branches = [];
try {
    $branches = $db->fetchAll(
        "SELECT b.*, 
            (SELECT COUNT(*) FROM users u WHERE u.branch_id = b.id AND u.clinic_id = b.clinic_id) as staff_count,
            (SELECT COUNT(*) FROM appointments a WHERE a.branch_id = b.id AND a.clinic_id = b.clinic_id) as appointment_count
         FROM branches b 
         WHERE b.clinic_id = ? 
         ORDER BY b.is_active DESC, b.id ASC",
        [$clinicId]
    );
} catch (Exception $e) {
    // Fallback if users or appointments don't have branch_id column yet
    try {
        $branches = $db->fetchAll("SELECT * FROM branches WHERE clinic_id = ? ORDER BY is_active DESC, id ASC", [$clinicId]);
        foreach ($branches as &$b) {
            $b['staff_count'] = 0;
            $b['appointment_count'] = 0;
        }
    } catch (Exception $ex) {
        $branches = [];
    }
}

// Auto-seed default branch if none exist
if (empty($branches)) {
    try {
        $clinicInfo = $db->fetch("SELECT * FROM clinics WHERE id = ?", [$clinicId]);
        if ($clinicInfo) {
            $db->query(
                "INSERT INTO branches (clinic_id, name, code, phone, email, address, city, state, pincode, is_active)
                 VALUES (?, ?, 'MAIN', ?, ?, ?, ?, ?, ?, 1)",
                [
                    $clinicId,
                    $clinicInfo['name'] . ' - Main Branch',
                    $clinicInfo['phone'] ?? '',
                    $clinicInfo['email'] ?? '',
                    $clinicInfo['address'] ?? '',
                    $clinicInfo['city'] ?? '',
                    $clinicInfo['state'] ?? '',
                    $clinicInfo['pincode'] ?? ''
                ]
            );
            $branches = $db->fetchAll("SELECT * FROM branches WHERE clinic_id = ? ORDER BY is_active DESC, id ASC", [$clinicId]);
            foreach ($branches as &$b) {
                $b['staff_count'] = 0;
                $b['appointment_count'] = 0;
            }
        }
    } catch (Exception $e) {
        // Ignore seed failure
    }
}

$daysMap = [
    '1' => 'Mon', '2' => 'Tue', '3' => 'Wed', '4' => 'Thu', '5' => 'Fri', '6' => 'Sat', '7' => 'Sun'
];

$pageTitle = 'Manage Branches';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="content-header">
    <div>
        <ul class="breadcrumb">
            <li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li>
            <li><a href="<?= BASE_URL ?>/modules/clinic/settings.php">Clinic Settings</a></li>
            <li>Branches</li>
        </ul>
        <h1><i class="fas fa-code-branch" style="color: var(--primary);"></i> Clinic Locations & Branches</h1>
        <p class="text-muted" style="margin-top: 4px; font-size: 14px;">Manage physical clinic facilities, consultation centres, and multi-location operating hours.</p>
    </div>
    <div>
        <button class="btn btn-primary" onclick="openAddBranchModal()">
            <i class="fas fa-plus"></i> Add New Branch
        </button>
    </div>
</div>

<?php if (empty($branches)): ?>
    <div class="card" style="text-align: center; padding: 48px 24px;">
        <div style="font-size: 48px; color: var(--text-muted); margin-bottom: 16px;">
            <i class="fas fa-hospital-alt"></i>
        </div>
        <h3>No Branches Configured Yet</h3>
        <p class="text-muted" style="max-width: 460px; margin: 0 auto 24px auto;">
            Configure your primary facility or add multiple physical branches so doctors, staff, appointments, and bills can be segregated by location.
        </p>
        <button class="btn btn-primary" onclick="openAddBranchModal()">
            <i class="fas fa-plus"></i> Create First Branch
        </button>
    </div>
<?php else: ?>
    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 20px;">
        <?php foreach ($branches as $branch): 
            $activeDays = explode(',', $branch['working_days'] ?? '1,2,3,4,5,6');
            $dayLabels = array_map(fn($d) => $daysMap[trim($d)] ?? $d, $activeDays);
        ?>
            <div class="card" style="border-top: 4px solid <?= $branch['is_active'] ? 'var(--primary)' : 'var(--border-color)' ?>; display: flex; flex-direction: column; justify-content: space-between;">
                <div class="card-header" style="display: flex; justify-content: space-between; align-items: flex-start; padding-bottom: 12px;">
                    <div>
                        <h3 style="margin: 0; font-size: 1.15em; display: flex; align-items: center; gap: 8px;">
                            <?= sanitizeOutput($branch['name']) ?>
                            <?php if (!empty($branch['code'])): ?>
                                <span class="badge badge-info" style="font-size: 11px;"><?= sanitizeOutput($branch['code']) ?></span>
                            <?php endif; ?>
                        </h3>
                        <div class="text-muted" style="font-size: 12px; margin-top: 4px;">
                            <?= !empty($branch['city']) ? sanitizeOutput($branch['city']) . (!empty($branch['state']) ? ', ' . sanitizeOutput($branch['state']) : '') : 'Location' ?>
                        </div>
                    </div>
                    <span class="badge badge-<?= $branch['is_active'] ? 'success' : 'secondary' ?>">
                        <?= $branch['is_active'] ? 'Active' : 'Inactive' ?>
                    </span>
                </div>
                
                <div class="card-body" style="padding-top: 8px;">
                    <div style="font-size: 13px; line-height: 1.8;">
                        <?php if (!empty($branch['phone'])): ?>
                            <div><i class="fas fa-phone text-muted" style="width: 18px;"></i> <?= sanitizeOutput($branch['phone']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($branch['email'])): ?>
                            <div><i class="fas fa-envelope text-muted" style="width: 18px;"></i> <?= sanitizeOutput($branch['email']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($branch['address'])): ?>
                            <div style="margin-top: 4px;"><i class="fas fa-map-marker-alt text-muted" style="width: 18px;"></i> <?= sanitizeOutput($branch['address']) ?></div>
                        <?php endif; ?>
                        
                        <div style="margin-top: 10px; padding-top: 8px; border-top: 1px dashed var(--border-color);">
                            <div>
                                <i class="fas fa-clock text-muted" style="width: 18px;"></i> 
                                <?= date('h:i A', strtotime($branch['working_hours_start'] ?? '09:00:00')) ?> - <?= date('h:i A', strtotime($branch['working_hours_end'] ?? '21:00:00')) ?>
                            </div>
                            <div style="font-size: 12px; color: var(--text-muted); margin-left: 22px;">
                                Days: <?= implode(', ', $dayLabels) ?>
                            </div>
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 12px; margin-top: 16px; padding: 8px 12px; background: var(--bg-surface); border-radius: 6px; font-size: 12px;">
                        <div><strong><?= $branch['staff_count'] ?? 0 ?></strong> Staff Members</div>
                        <div style="color: var(--border-color);">|</div>
                        <div><strong><?= $branch['appointment_count'] ?? 0 ?></strong> Appointments</div>
                    </div>
                </div>
                
                <div class="card-footer" style="display: flex; justify-content: space-between; align-items: center; background: #fafbfc;">
                    <form method="POST" style="margin: 0;">
                        <input type="hidden" name="action" value="toggle_status">
                        <input type="hidden" name="branch_id" value="<?= $branch['id'] ?>">
                        <input type="hidden" name="current_status" value="<?= $branch['is_active'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline" style="font-size: 12px;">
                            <i class="fas fa-power-off"></i> <?= $branch['is_active'] ? 'Deactivate' : 'Activate' ?>
                        </button>
                    </form>
                    <button class="btn btn-sm btn-primary" onclick="openEditBranchModal(<?= htmlspecialchars(json_encode($branch)) ?>)">
                        <i class="fas fa-edit"></i> Edit Branch
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Branch Form Modal -->
<div id="branchModal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); overflow-y: auto;">
    <div class="modal-dialog" style="max-width: 600px; margin: 50px auto; background: white; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); overflow: hidden;">
        <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; padding: 16px 24px; border-bottom: 1px solid var(--border-color);">
            <h3 id="modalTitle" style="margin: 0;"><i class="fas fa-code-branch" style="color: var(--primary);"></i> Add New Branch</h3>
            <button type="button" onclick="closeBranchModal()" style="background: none; border: none; font-size: 22px; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>
        <form method="POST" id="branchForm">
            <input type="hidden" name="action" id="formAction" value="create">
            <input type="hidden" name="branch_id" id="branchId" value="">
            
            <div class="modal-body" style="padding: 24px;">
                <div class="form-row">
                    <div class="form-group" style="flex: 2;">
                        <label class="form-label">Branch Name <span style="color: red;">*</span></label>
                        <input type="text" name="name" id="branchName" class="form-control" placeholder="e.g., Downtown Care Clinic" required>
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Branch Code</label>
                        <input type="text" name="code" id="branchCode" class="form-control" placeholder="e.g., BR-01" style="text-transform: uppercase;">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Phone Number</label>
                        <input type="tel" name="phone" id="branchPhone" class="form-control" placeholder="+91 9876543210">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" id="branchEmail" class="form-control" placeholder="branch@featuregen.com">
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Address</label>
                    <textarea name="address" id="branchAddress" class="form-control" rows="2" placeholder="Full street address"></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">City</label>
                        <input type="text" name="city" id="branchCity" class="form-control" placeholder="City">
                    </div>
                    <div class="form-group">
                        <label class="form-label">State</label>
                        <input type="text" name="state" id="branchState" class="form-control" placeholder="State">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Pincode</label>
                        <input type="text" name="pincode" id="branchPincode" class="form-control" placeholder="400001">
                    </div>
                </div>
                
                <div class="form-row" style="margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border-color);">
                    <div class="form-group">
                        <label class="form-label">Working Hours Start</label>
                        <input type="time" name="working_hours_start" id="workingHoursStart" class="form-control" value="09:00">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Working Hours End</label>
                        <input type="time" name="working_hours_end" id="workingHoursEnd" class="form-control" value="21:00">
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Working Days</label>
                    <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                        <?php foreach ($daysMap as $num => $label): ?>
                            <label style="font-size: 13px; display: flex; align-items: center; gap: 4px; cursor: pointer;">
                                <input type="checkbox" name="working_days[]" value="<?= $num ?>" id="day_<?= $num ?>" checked> <?= $label ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="form-group mb-0" style="margin-top: 16px;">
                    <label style="font-size: 14px; font-weight: 600; display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" name="is_active" id="branchIsActive" value="1" checked> Active Branch
                    </label>
                </div>
            </div>
            
            <div class="modal-footer" style="padding: 16px 24px; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; gap: 12px; background: #fafbfc;">
                <button type="button" class="btn btn-outline" onclick="closeBranchModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="modalSubmitBtn"><i class="fas fa-save"></i> Save Branch</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddBranchModal() {
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-code-branch" style="color: var(--primary);"></i> Add New Branch';
    document.getElementById('formAction').value = 'create';
    document.getElementById('branchId').value = '';
    document.getElementById('branchForm').reset();
    document.getElementById('workingHoursStart').value = '09:00';
    document.getElementById('workingHoursEnd').value = '21:00';
    document.getElementById('branchIsActive').checked = true;
    for (let i = 1; i <= 6; i++) {
        const el = document.getElementById('day_' + i);
        if (el) el.checked = true;
    }
    document.getElementById('branchModal').style.display = 'block';
}

function openEditBranchModal(branch) {
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit" style="color: var(--primary);"></i> Edit Branch';
    document.getElementById('formAction').value = 'update';
    document.getElementById('branchId').value = branch.id;
    document.getElementById('branchName').value = branch.name || '';
    document.getElementById('branchCode').value = branch.code || '';
    document.getElementById('branchPhone').value = branch.phone || '';
    document.getElementById('branchEmail').value = branch.email || '';
    document.getElementById('branchAddress').value = branch.address || '';
    document.getElementById('branchCity').value = branch.city || '';
    document.getElementById('branchState').value = branch.state || '';
    document.getElementById('branchPincode').value = branch.pincode || '';
    document.getElementById('workingHoursStart').value = branch.working_hours_start ? branch.working_hours_start.substring(0, 5) : '09:00';
    document.getElementById('workingHoursEnd').value = branch.working_hours_end ? branch.working_hours_end.substring(0, 5) : '21:00';
    document.getElementById('branchIsActive').checked = branch.is_active == 1;
    
    // Days
    const activeDays = (branch.working_days || '1,2,3,4,5,6').split(',');
    for (let i = 1; i <= 7; i++) {
        const el = document.getElementById('day_' + i);
        if (el) {
            el.checked = activeDays.includes(String(i));
        }
    }
    
    document.getElementById('branchModal').style.display = 'block';
}

function closeBranchModal() {
    document.getElementById('branchModal').style.display = 'none';
}

window.onclick = function(event) {
    const modal = document.getElementById('branchModal');
    if (event.target === modal) {
        closeBranchModal();
    }
}
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
