<?php
/**
 * Dashboard - Feature Gen Care
 * Real-time stats, charts, and today's overview
 */

$pageTitle = 'Dashboard';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';

$db = db();
$clinicId = getCurrentClinicId();
$today = date('Y-m-d');
$role = getCurrentUserRole();

// Fetch stats
try {
    // Today's appointments count
    $todayAppointments = $db->fetch(
        "SELECT COUNT(*) as total FROM appointments WHERE clinic_id = ? AND appointment_date = ?",
        [$clinicId, $today]
    )['total'] ?? 0;

    // Total patients
    $totalPatients = $db->fetch(
        "SELECT COUNT(*) as total FROM patients WHERE clinic_id = ?",
        [$clinicId]
    )['total'] ?? 0;

    // Today's revenue
    $todayRevenue = $db->fetch(
        "SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE clinic_id = ? AND payment_date = ?",
        [$clinicId, $today]
    )['total'] ?? 0;

    // Outstanding dues
    $totalDues = $db->fetch(
        "SELECT COALESCE(SUM(due_amount), 0) as total FROM invoices WHERE clinic_id = ? AND status IN ('due','partial','overdue')",
        [$clinicId]
    )['total'] ?? 0;

    // Active doctors
    $activeDoctors = $db->fetch(
        "SELECT COUNT(*) as total FROM doctors d JOIN users u ON d.user_id = u.id WHERE d.clinic_id = ? AND d.is_available = 1 AND u.is_active = 1",
        [$clinicId]
    )['total'] ?? 0;

    // Monthly revenue
    $monthRevenue = $db->fetch(
        "SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE clinic_id = ? AND MONTH(payment_date) = MONTH(CURDATE()) AND YEAR(payment_date) = YEAR(CURDATE())",
        [$clinicId]
    )['total'] ?? 0;

    // Today's appointments list
    $todayAppointmentsList = $db->fetchAll(
        "SELECT a.*, p.first_name, p.last_name, p.patient_uid, p.phone as patient_phone,
                u.full_name as doctor_name
         FROM appointments a
         JOIN patients p ON a.patient_id = p.id
         JOIN doctors d ON a.doctor_id = d.id
         JOIN users u ON d.user_id = u.id
         WHERE a.clinic_id = ? AND a.appointment_date = ?
         ORDER BY a.appointment_time ASC
         LIMIT 10",
        [$clinicId, $today]
    );

    // Recent patients
    $recentPatients = $db->fetchAll(
        "SELECT * FROM patients WHERE clinic_id = ? ORDER BY created_at DESC LIMIT 5",
        [$clinicId]
    );

    // Appointment status breakdown
    $statusBreakdown = $db->fetchAll(
        "SELECT status, COUNT(*) as count FROM appointments WHERE clinic_id = ? AND appointment_date = ? GROUP BY status",
        [$clinicId, $today]
    );

    // Conversion rate: completed appointments / total appointments this month
    $monthlyConversion = $db->fetch(
        "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
         FROM appointments 
         WHERE clinic_id = ? AND MONTH(appointment_date) = MONTH(CURDATE()) AND YEAR(appointment_date) = YEAR(CURDATE())",
        [$clinicId]
    );
    $convTotal = intval($monthlyConversion['total'] ?? 0);
    $convCompleted = intval($monthlyConversion['completed'] ?? 0);
    $conversionRate = $convTotal > 0 ? round(($convCompleted / $convTotal) * 100) : 0;

} catch (Exception $e) {
    // Tables may not exist yet - show zeros
    $todayAppointments = $totalPatients = $todayRevenue = $totalDues = $activeDoctors = $monthRevenue = 0;
    $todayAppointmentsList = $recentPatients = $statusBreakdown = [];
    $conversionRate = 0;
}
?>

<div class="content-header">
    <div>
        <ul class="breadcrumb">
            <li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Home</a></li>
            <li>Dashboard</li>
        </ul>
        <h1>Welcome back, <?= sanitizeOutput(explode(' ', getSession('full_name', 'User'))[0]) ?>! 👋</h1>
    </div>
    <div class="d-flex gap-8">
        <a href="<?= BASE_URL ?>/modules/appointments/book.php" class="btn btn-primary">
            <i class="fas fa-plus"></i> New Appointment
        </a>
        <a href="<?= BASE_URL ?>/modules/patients/add.php" class="btn btn-outline">
            <i class="fas fa-user-plus"></i> Add Patient
        </a>
    </div>
</div>

<?php if (in_array($role, [ROLE_SUPER_ADMIN, ROLE_ADMIN])): ?>
<?php
$dashTenant = $db->tenantInfo ?? [];
$dashPlan = ucfirst(sanitizeOutput($dashTenant['plan_type'] ?? 'Trial'));
$dashMaxDoc = intval($dashTenant['max_doctors'] ?? 0);
$dashLife = !empty($dashTenant['is_lifetime']) || ($dashTenant['plan_type'] ?? '') === 'one_time';
$dashEnd = !empty($dashTenant['subscription_ends_at']) ? strtotime($dashTenant['subscription_ends_at']) : null;
$dashDays = $dashEnd ? max(0, ceil(($dashEnd - time()) / 86400)) : null;
?>
<div class="card mb-24" style="border: 1px solid rgba(0, 131, 143, 0.25); background: linear-gradient(135deg, #f0fdfa, #ffffff); border-radius: 12px; box-shadow: 0 4px 15px rgba(0, 131, 143, 0.06);">
    <div class="card-body" style="padding: 16px 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
        <div style="display: flex; align-items: center; gap: 14px;">
            <div style="width: 44px; height: 44px; border-radius: 10px; background: rgba(0, 131, 143, 0.12); display: flex; align-items: center; justify-content: center; font-size: 22px; color: #00838f;">
                <i class="fas fa-crown"></i>
            </div>
            <div>
                <div style="font-size: 11px; text-transform: uppercase; color: var(--text-muted); font-weight: 700; letter-spacing: 0.5px;">Current Subscription Plan</div>
                <div style="font-size: 15px; font-weight: 700; color: var(--text); display: flex; align-items: center; flex-wrap: wrap; gap: 8px;">
                    <span style="color: #00838f;"><?= $dashPlan ?> Plan</span>
                    <span class="badge badge-success" style="font-size: 10px; padding: 2px 7px;">Active</span>
                    <span style="font-size: 13px; font-weight: 500; color: var(--text-muted);">
                        &bull; Doctor Quota: <strong><?= $activeDoctors ?> / <?= $dashMaxDoc > 0 ? $dashMaxDoc : '∞' ?> Doctors</strong>
                        <?php if ($dashLife): ?>
                            &bull; <strong>Lifetime Perpetual Access</strong>
                        <?php elseif ($dashEnd): ?>
                            &bull; Renews: <strong><?= date('d M Y', $dashEnd) ?> (<?= $dashDays ?> days left)</strong>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        </div>
        <div class="d-flex gap-8">
            <a href="<?= BASE_URL ?>/modules/subscription/paywall.php" class="btn btn-sm btn-primary" style="background: linear-gradient(135deg, #00838f, #00695c); border: none; font-weight: 700; padding: 8px 16px;">
                <i class="fas fa-arrow-up"></i> Upgrade / Manage Plan
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Stats Cards -->
<div class="grid-4 mb-24">
    <div class="stat-card">
        <div class="stat-icon primary">
            <i class="fas fa-calendar-check"></i>
        </div>
        <div class="stat-details">
            <div class="stat-label">Today's Appointments</div>
            <div class="stat-value"><?= $todayAppointments ?></div>
            <div class="stat-change up"><i class="fas fa-clock"></i> <?= date('d M Y') ?></div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon success">
            <i class="fas fa-users"></i>
        </div>
        <div class="stat-details">
            <div class="stat-label">Total Patients</div>
            <div class="stat-value"><?= formatNumber($totalPatients) ?></div>
            <div class="stat-change up"><i class="fas fa-arrow-up"></i> Active records</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon warning">
            <i class="fas fa-rupee-sign"></i>
        </div>
        <div class="stat-details">
            <div class="stat-label">Today's Revenue</div>
            <div class="stat-value"><?= formatCurrency($todayRevenue) ?></div>
            <div class="stat-change up"><i class="fas fa-chart-line"></i> Collected today</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon danger">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        <div class="stat-details">
            <div class="stat-label">Outstanding Dues</div>
            <div class="stat-value"><?= formatCurrency($totalDues) ?></div>
            <div class="stat-change down"><i class="fas fa-exclamation-circle"></i> Pending collection</div>
        </div>
    </div>
</div>

<!-- Second Row Stats -->
<div class="grid-3 mb-24">
    <div class="stat-card">
        <div class="stat-icon accent">
            <i class="fas fa-user-md"></i>
        </div>
        <div class="stat-details">
            <div class="stat-label">Active Doctors</div>
            <div class="stat-value"><?= $activeDoctors ?></div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon info">
            <i class="fas fa-chart-bar"></i>
        </div>
        <div class="stat-details">
            <div class="stat-label">Monthly Revenue</div>
            <div class="stat-value"><?= formatCurrency($monthRevenue) ?></div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon success">
            <i class="fas fa-percentage"></i>
        </div>
        <div class="stat-details">
            <div class="stat-label">Conversion Rate</div>
            <div class="stat-value"><?= $conversionRate ?>%</div>
        </div>
    </div>
</div>

<div class="grid-2 gap-24">
    <!-- Today's Appointments -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-calendar-day" style="color: var(--primary); margin-right: 8px;"></i> Today's Appointments</h3>
            <a href="<?= BASE_URL ?>/modules/appointments/list.php" class="btn btn-sm btn-outline">View All</a>
        </div>
        <div class="card-body p-0">
            <?php if (empty($todayAppointmentsList)): ?>
            <div class="empty-state" style="padding: 40px;">
                <i class="fas fa-calendar-times"></i>
                <h3>No appointments today</h3>
                <p>Book an appointment to get started</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Token</th>
                            <th>Patient</th>
                            <th>Time</th>
                            <th>Doctor</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($todayAppointmentsList as $apt): ?>
                        <tr>
                            <td><span class="badge badge-primary">#<?= $apt['token_number'] ?? '-' ?></span></td>
                            <td>
                                <div class="patient-cell">
                                    <div class="user-avatar" style="width: 32px; height: 32px; font-size: 11px;">
                                        <?= getInitials($apt['first_name'] . ' ' . $apt['last_name']) ?>
                                    </div>
                                    <div>
                                        <div class="font-semibold"><?= sanitizeOutput($apt['first_name'] . ' ' . $apt['last_name']) ?></div>
                                        <div class="text-muted" style="font-size: 11px;"><?= sanitizeOutput($apt['patient_uid']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><?= formatTime($apt['appointment_time']) ?></td>
                            <td><?= sanitizeOutput($apt['doctor_name']) ?></td>
                            <td><?= getStatusBadge($apt['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Recent Patients -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-user-clock" style="color: var(--accent); margin-right: 8px;"></i> Recent Patients</h3>
            <a href="<?= BASE_URL ?>/modules/patients/list.php" class="btn btn-sm btn-outline">View All</a>
        </div>
        <div class="card-body p-0">
            <?php if (empty($recentPatients)): ?>
            <div class="empty-state" style="padding: 40px;">
                <i class="fas fa-user-plus"></i>
                <h3>No patients yet</h3>
                <p>Register your first patient</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Patient</th>
                            <th>Phone</th>
                            <th>Registered</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recentPatients as $patient): ?>
                        <tr>
                            <td>
                                <div class="patient-cell">
                                    <div class="user-avatar" style="width: 32px; height: 32px; font-size: 11px; background: linear-gradient(135deg, <?= randomColor() ?>, <?= randomColor() ?>);">
                                        <?= getInitials($patient['first_name'] . ' ' . ($patient['last_name'] ?? '')) ?>
                                    </div>
                                    <div>
                                        <div class="font-semibold"><?= sanitizeOutput($patient['first_name'] . ' ' . ($patient['last_name'] ?? '')) ?></div>
                                        <div class="text-muted" style="font-size: 11px;"><?= sanitizeOutput($patient['patient_uid']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><?= sanitizeOutput($patient['phone']) ?></td>
                            <td><?= timeAgo($patient['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="card mt-24">
    <div class="card-header">
        <h3><i class="fas fa-bolt" style="color: var(--warning); margin-right: 8px;"></i> Quick Actions</h3>
    </div>
    <div class="card-body">
        <div class="grid-4">
            <a href="<?= BASE_URL ?>/modules/patients/add.php" class="stat-card" style="text-decoration: none; cursor: pointer;">
                <div class="stat-icon primary"><i class="fas fa-user-plus"></i></div>
                <div class="stat-details">
                    <div class="stat-label">Register Patient</div>
                    <div style="font-size: 12px; color: var(--text-muted);">Quick registration form</div>
                </div>
            </a>
            <a href="<?= BASE_URL ?>/modules/appointments/book.php" class="stat-card" style="text-decoration: none; cursor: pointer;">
                <div class="stat-icon success"><i class="fas fa-calendar-plus"></i></div>
                <div class="stat-details">
                    <div class="stat-label">Book Appointment</div>
                    <div style="font-size: 12px; color: var(--text-muted);">Schedule a visit</div>
                </div>
            </a>
            <a href="<?= BASE_URL ?>/modules/billing/create.php" class="stat-card" style="text-decoration: none; cursor: pointer;">
                <div class="stat-icon warning"><i class="fas fa-file-invoice"></i></div>
                <div class="stat-details">
                    <div class="stat-label">Create Invoice</div>
                    <div style="font-size: 12px; color: var(--text-muted);">Generate a new bill</div>
                </div>
            </a>
            <a href="<?= BASE_URL ?>/modules/prescriptions/create.php" class="stat-card" style="text-decoration: none; cursor: pointer;">
                <div class="stat-icon accent"><i class="fas fa-prescription"></i></div>
                <div class="stat-details">
                    <div class="stat-label">Write Prescription</div>
                    <div style="font-size: 12px; color: var(--text-muted);">Digital prescription</div>
                </div>
            </a>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
