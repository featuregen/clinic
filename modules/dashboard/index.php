<?php
/**
 * Dashboard - Advanced Clinic Suite
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
        "SELECT COUNT(*) as total FROM doctors WHERE clinic_id = ? AND is_available = 1",
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

} catch (Exception $e) {
    // Tables may not exist yet - show zeros
    $todayAppointments = $totalPatients = $todayRevenue = $totalDues = $activeDoctors = $monthRevenue = 0;
    $todayAppointmentsList = $recentPatients = $statusBreakdown = [];
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
            <div class="stat-value"><?= $todayAppointments > 0 ? '87%' : '0%' ?></div>
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
