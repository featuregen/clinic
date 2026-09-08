<?php
/**
 * Sidebar Navigation
 * Advanced Clinic Suite - Role-Based Menu
 */

$role = getCurrentUserRole();
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$currentModule = '';
$pathParts = explode('/', $_SERVER['REQUEST_URI'] ?? '');
foreach ($pathParts as $part) {
    if (in_array($part, ['dashboard','clinic','doctors','staff','patients','appointments','prescriptions','templates','billing','reports','dental','vaccination','calculators','communication','admin'])) {
        $currentModule = $part;
        break;
    }
}

function isActiveMenu($module) {
    global $currentModule;
    return $currentModule === $module ? 'active' : '';
}
?>
<?php
$db = db();
$clinic = $db->fetch("SELECT name, logo FROM clinics WHERE id = ?", [getCurrentClinicId()]);
?>
<aside class="sidebar">
    <div class="sidebar-brand" style="flex-direction: column; align-items: center; gap: 6px; padding: 16px 12px;">
        <?php if (!empty($clinic['logo'])): ?>
            <img src="<?= UPLOADS_URL ?>/clinics/<?= sanitizeOutput($clinic['logo']) ?>" alt="Clinic Logo" style="max-height: 48px; max-width: 140px; object-fit: contain;">
        <?php else: ?>
            <div class="brand-logo"><i class="fas fa-hospital"></i></div>
        <?php endif; ?>
        <div class="brand-text" style="text-align: center; font-size: 13px; line-height: 1.3; white-space: normal; word-break: break-word;">
            <?= sanitizeOutput($clinic['name'] ?? APP_NAME) ?>
            <small style="display: block; font-size: 10px; opacity: 0.7;">Clinic Management</small>
        </div>
    </div>
    
    <nav class="sidebar-nav">
        <!-- Main -->
        <div class="nav-section">
            <div class="nav-section-title">Main</div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/modules/dashboard/index.php" class="nav-link <?= isActiveMenu('dashboard') ?>">
                    <i class="fas fa-th-large"></i>
                    <span>Dashboard</span>
                </a>
            </div>
        </div>
        
        <!-- Patient Care -->
        <div class="nav-section">
            <div class="nav-section-title">Patient Care</div>
            
            <?php if (hasPermission('patients.view')): ?>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/modules/patients/list.php" class="nav-link <?= isActiveMenu('patients') ?>">
                    <i class="fas fa-users"></i>
                    <span>Patients</span>
                </a>
            </div>
            <?php endif; ?>
            
            <?php if (hasPermission('appointments.view')): ?>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/modules/appointments/list.php" class="nav-link <?= isActiveMenu('appointments') ?>">
                    <i class="fas fa-calendar-check"></i>
                    <span>Appointments</span>
                </a>
            </div>
            <?php endif; ?>
            
            <?php if (hasPermission('prescriptions.view')): ?>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/modules/prescriptions/list.php" class="nav-link <?= isActiveMenu('prescriptions') ?>">
                    <i class="fas fa-file-prescription"></i>
                    <span>Prescriptions</span>
                </a>
            </div>
            <?php endif; ?>
            
            <?php if (hasPermission('prescriptions.templates')): ?>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/modules/templates/list.php" class="nav-link <?= isActiveMenu('templates') ?>">
                    <i class="fas fa-clone"></i>
                    <span>Templates</span>
                </a>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Specialty -->
        <div class="nav-section">
            <div class="nav-section-title">Specialty</div>
            
            <?php if (hasPermission('dental.view')): ?>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/modules/dental/chart.php" class="nav-link <?= isActiveMenu('dental') ?>">
                    <i class="fas fa-tooth"></i>
                    <span>Dental</span>
                </a>
            </div>
            <?php endif; ?>
            
            <?php if (hasPermission('vaccination.view')): ?>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/modules/vaccination/schedule.php" class="nav-link <?= isActiveMenu('vaccination') ?>">
                    <i class="fas fa-syringe"></i>
                    <span>Vaccination</span>
                </a>
            </div>
<?php endif; ?>
            
            <?php if (in_array($role, [ROLE_SUPER_ADMIN, ROLE_ADMIN])): ?>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/modules/vaccination/master.php" class="nav-link <?= strpos($currentPage, 'master.php') !== false && $currentModule == 'vaccination' ? 'active' : '' ?>">
                    <i class="fas fa-vial"></i>
                    <span>Vaccine Master</span>
                </a>
            </div>
            <?php endif; ?>
            
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/modules/calculators/index.php" class="nav-link <?= isActiveMenu('calculators') ?>">
                    <i class="fas fa-calculator"></i>
                    <span>Calculators</span>
                </a>
            </div>
        </div>
        
        <!-- Finance -->
        <?php if (hasPermission('billing.view') || hasPermission('reports.view')): ?>
        <div class="nav-section">
            <div class="nav-section-title">Finance</div>
            
            <?php if (hasPermission('billing.view')): ?>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/modules/billing/list.php" class="nav-link <?= isActiveMenu('billing') ?>">
                    <i class="fas fa-file-invoice-dollar"></i>
                    <span>Billing</span>
                </a>
            </div>
            <?php endif; ?>
            
            <?php if (hasPermission('reports.view')): ?>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/modules/reports/daily.php" class="nav-link <?= isActiveMenu('reports') ?>">
                    <i class="fas fa-chart-bar"></i>
                    <span>Reports</span>
                </a>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- Management -->
        <?php if (hasPermission('doctors.view') || hasPermission('staff.view')): ?>
        <div class="nav-section">
            <div class="nav-section-title">Management</div>
            
            <?php if (hasPermission('doctors.view')): ?>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/modules/doctors/list.php" class="nav-link <?= isActiveMenu('doctors') ?>">
                    <i class="fas fa-user-md"></i>
                    <span>Doctors</span>
                </a>
            </div>
            <?php endif; ?>
            
            <?php if (hasPermission('staff.view')): ?>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/modules/staff/list.php" class="nav-link <?= isActiveMenu('staff') ?>">
                    <i class="fas fa-id-badge"></i>
                    <span>Staff</span>
                </a>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- Communication -->
        <?php if (hasPermission('communication.view')): ?>
        <div class="nav-section">
            <div class="nav-section-title">Communication</div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/modules/communication/templates.php" class="nav-link <?= isActiveMenu('communication') ?>">
                    <i class="fas fa-envelope"></i>
                    <span>Messages</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/modules/communication/logs.php" class="nav-link <?= isActiveMenu('communication') ?>">
                    <i class="fas fa-history"></i>
                    <span>Logs</span>
                </a>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Administration -->
        <?php if (in_array($role, [ROLE_SUPER_ADMIN, ROLE_ADMIN])): ?>
        <div class="nav-section">
            <div class="nav-section-title">Administration</div>
            
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/modules/admin/master_data.php" class="nav-link <?= strpos($currentPage, 'master_data.php') !== false ? 'active' : '' ?>">
                    <i class="fas fa-database"></i>
                    <span>Master Data</span>
                </a>
            </div>

            <div class="nav-item">
                <a href="<?= BASE_URL ?>/modules/clinic/settings.php" class="nav-link <?= isActiveMenu('clinic') ?>">
                    <i class="fas fa-hospital-alt"></i>
                    <span>Clinic Settings</span>
                </a>
            </div>
            
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/modules/admin/roles.php" class="nav-link <?= strpos($currentPage, 'roles.php') !== false ? 'active' : '' ?>">
                    <i class="fas fa-shield-alt"></i>
                    <span>Role Permissions</span>
                </a>
            </div>

            <div class="nav-item">
                <a href="<?= BASE_URL ?>/modules/admin/users.php" class="nav-link <?= strpos($currentPage, 'users.php') !== false && $currentModule === 'admin' ? 'active' : '' ?>">
                    <i class="fas fa-users-cog"></i>
                    <span>Users</span>
                </a>
            </div>

            <?php if ($role === ROLE_SUPER_ADMIN): ?>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>/modules/admin/index.php" class="nav-link <?= isActiveMenu('admin') ?>">
                    <i class="fas fa-cogs"></i>
                    <span>System Admin</span>
                </a>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </nav>
</aside>
