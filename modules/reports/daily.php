<?php
/**
 * Reports - Daily Summary - Advanced Clinic Suite
 */
$pageTitle = 'Reports';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';
requirePermission('reports.view');

$db = db();
$clinicId = getCurrentClinicId();
$fromDate = sanitize($_GET['from'] ?? date('Y-m-d'));
$toDate = sanitize($_GET['to'] ?? date('Y-m-d'));

// Stats over date range
try {
    $dayAppts = $db->fetch("SELECT COUNT(*) as total, SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as completed, SUM(CASE WHEN status='cancelled' THEN 1 ELSE 0 END) as cancelled FROM appointments WHERE clinic_id=? AND appointment_date BETWEEN ? AND ?", [$clinicId, $fromDate, $toDate]);
    $dayRevenue = $db->fetch("SELECT COALESCE(SUM(amount),0) as total FROM payments WHERE clinic_id=? AND payment_date BETWEEN ? AND ?", [$clinicId, $fromDate, $toDate])['total'];
    $dayPatients = $db->fetch("SELECT COUNT(*) as total FROM patients WHERE clinic_id=? AND DATE(created_at) BETWEEN ? AND ?", [$clinicId, $fromDate, $toDate])['total'];
    $dayInvoices = $db->fetch("SELECT COALESCE(SUM(total_amount),0) as billed, COALESCE(SUM(paid_amount),0) as collected, COALESCE(SUM(due_amount),0) as due FROM invoices WHERE clinic_id=? AND invoice_date BETWEEN ? AND ?", [$clinicId, $fromDate, $toDate]);

    // Monthly revenue trend
    $monthlyRevenue = $db->fetchAll(
        "SELECT DATE_FORMAT(payment_date, '%Y-%m') as month, SUM(amount) as total FROM payments WHERE clinic_id=? AND payment_date BETWEEN ? AND ? GROUP BY month ORDER BY month",
        [$clinicId, $fromDate, $toDate]
    );

    // Doctor-wise collection
    $doctorRevenue = $db->fetchAll(
        "SELECT u.full_name, COUNT(a.id) as appointments, COALESCE(SUM(a.consultation_fee),0) as fees
         FROM appointments a JOIN doctors d ON a.doctor_id=d.id JOIN users u ON d.user_id=u.id
         WHERE a.clinic_id=? AND a.appointment_date BETWEEN ? AND ? GROUP BY d.id ORDER BY fees DESC",
        [$clinicId, $fromDate, $toDate]
    );

    // Payment mode breakdown
    $paymentModes = $db->fetchAll(
        "SELECT payment_mode, COUNT(*) as count, SUM(amount) as total FROM payments WHERE clinic_id=? AND payment_date BETWEEN ? AND ? GROUP BY payment_mode",
        [$clinicId, $fromDate, $toDate]
    );
} catch (Exception $e) {
    $dayAppts = ['total' => 0, 'completed' => 0, 'cancelled' => 0];
    $dayRevenue = $dayPatients = 0;
    $dayInvoices = ['billed' => 0, 'collected' => 0, 'due' => 0];
    $monthlyRevenue = $doctorRevenue = $paymentModes = [];
}
?>

<div class="content-header">
    <div>
        <ul class="breadcrumb"><li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li><li>Reports</li></ul>
        <h1>Reports & Analytics</h1>
    </div>
</div>

<!-- Date Filters -->
<div class="card mb-24">
    <div class="card-body">
        <form method="GET" class="d-flex gap-12 align-center flex-wrap">
            <label class="form-label mb-0">Date Range:</label>
            <input type="date" name="from" class="form-control" value="<?= $fromDate ?>" style="width: auto;">
            <span>to</span>
            <input type="date" name="to" class="form-control" value="<?= $toDate ?>" style="width: auto;">
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-sync-alt"></i> Apply</button>
        </form>
    </div>
</div>

<!-- Summary -->
<h3 class="mb-16"><i class="fas fa-calendar-day" style="color: var(--primary);"></i> Summary <?= ($fromDate === $toDate) ? '- ' . formatDate($fromDate) : '- ' . formatDate($fromDate) . ' to ' . formatDate($toDate) ?></h3>
<div class="grid-4 mb-24">
    <div class="stat-card"><div class="stat-icon primary"><i class="fas fa-calendar-check"></i></div><div class="stat-details"><div class="stat-label">Appointments</div><div class="stat-value"><?= $dayAppts['total'] ?? 0 ?></div><div class="stat-change"><?= $dayAppts['completed'] ?? 0 ?> completed, <?= $dayAppts['cancelled'] ?? 0 ?> cancelled</div></div></div>
    <div class="stat-card"><div class="stat-icon success"><i class="fas fa-rupee-sign"></i></div><div class="stat-details"><div class="stat-label">Revenue</div><div class="stat-value"><?= formatCurrency($dayRevenue) ?></div></div></div>
    <div class="stat-card"><div class="stat-icon info"><i class="fas fa-user-plus"></i></div><div class="stat-details"><div class="stat-label">New Patients</div><div class="stat-value"><?= $dayPatients ?></div></div></div>
    <div class="stat-card"><div class="stat-icon warning"><i class="fas fa-file-invoice-dollar"></i></div><div class="stat-details"><div class="stat-label">Billed / Due</div><div class="stat-value"><?= formatCurrency($dayInvoices['billed'] ?? 0) ?></div><div class="stat-change"><?= formatCurrency($dayInvoices['due'] ?? 0) ?> pending</div></div></div>
</div>

<div class="grid-2 gap-24 mb-24">
    <!-- Doctor Revenue -->
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-user-md" style="color: var(--accent);"></i> Doctor-wise Summary</h3></div>
        <div class="card-body p-0">
            <?php if (empty($doctorRevenue)): ?>
            <div class="empty-state" style="padding: 24px;"><p class="text-muted">No data available</p></div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Doctor</th><th>Appointments</th><th>Revenue</th></tr></thead>
                    <tbody>
                    <?php foreach ($doctorRevenue as $dr): ?>
                    <tr><td>Dr. <?= sanitizeOutput($dr['full_name']) ?></td><td><?= $dr['appointments'] ?></td><td class="font-semibold"><?= formatCurrency($dr['fees']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Payment Modes -->
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-wallet" style="color: var(--success);"></i> Payment Mode Breakdown</h3></div>
        <div class="card-body p-0">
            <?php if (empty($paymentModes)): ?>
            <div class="empty-state" style="padding: 24px;"><p class="text-muted">No payment data</p></div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Mode</th><th>Count</th><th>Amount</th></tr></thead>
                    <tbody>
                    <?php foreach ($paymentModes as $pm): ?>
                    <tr><td><span class="badge badge-secondary"><?= ucfirst($pm['payment_mode']) ?></span></td><td><?= $pm['count'] ?></td><td class="font-semibold"><?= formatCurrency($pm['total']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
