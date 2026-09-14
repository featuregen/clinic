<?php
/**
 * Reports - Daily Summary - Feature Gen Care
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

    $clinic = $db->fetch("SELECT * FROM clinics WHERE id = ?", [$clinicId]);
} catch (Exception $e) {
    $dayAppts = ['total' => 0, 'completed' => 0, 'cancelled' => 0];
    $dayRevenue = $dayPatients = 0;
    $dayInvoices = ['billed' => 0, 'collected' => 0, 'due' => 0];
    $monthlyRevenue = $doctorRevenue = $paymentModes = [];
    $clinic = null;
}
?>

<style>
@media print {
    .sidebar, .header, .sidebar-overlay, .breadcrumb, .no-print, form, .btn {
        display: none !important;
    }
    .app-wrapper, .main-content, .content-area {
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
        background: #fff !important;
    }
    .print-only-header {
        display: block !important;
    }
    .card {
        box-shadow: none !important;
        border: 1px solid #e5e7eb !important;
        break-inside: avoid;
        margin-bottom: 16px !important;
    }
    .stat-card {
        border: 1px solid #d1d5db !important;
        box-shadow: none !important;
        break-inside: avoid;
        padding: 12px !important;
    }
    .table th, .table td {
        padding: 8px 10px !important;
        border: 1px solid #e5e7eb !important;
    }
    .grid-4 {
        display: grid !important;
        grid-template-columns: repeat(4, 1fr) !important;
        gap: 12px !important;
    }
    .grid-2 {
        display: grid !important;
        grid-template-columns: repeat(2, 1fr) !important;
        gap: 16px !important;
    }
    body {
        font-size: 12px !important;
        background: #fff !important;
        color: #000 !important;
    }
}
</style>

<!-- Print Only Header -->
<div class="print-only-header" style="display: none;">
    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #00838f; padding-bottom: 16px; margin-bottom: 20px;">
        <div>
            <h1 style="font-size: 22px; color: #00838f; margin: 0 0 4px; font-weight: 800;"><?= sanitizeOutput($clinic['name'] ?? APP_NAME) ?></h1>
            <p style="margin: 0; font-size: 12px; color: #4b5563;">
                <?= sanitizeOutput($clinic['address'] ?? '') ?> 
                <?= !empty($clinic['phone']) ? ' &bull; Phone: ' . sanitizeOutput($clinic['phone']) : '' ?>
                <?= !empty($clinic['email']) ? ' &bull; Email: ' . sanitizeOutput($clinic['email']) : '' ?>
            </p>
        </div>
        <div style="text-align: right;">
            <h3 style="margin: 0 0 4px; font-size: 15px; text-transform: uppercase; color: #111827; font-weight: 700;">Performance Summary Report</h3>
            <p style="margin: 0; font-size: 12px; color: #4b5563;">
                Period: <strong><?= formatDate($fromDate) ?></strong> to <strong><?= formatDate($toDate) ?></strong>
            </p>
            <p style="margin: 2px 0 0; font-size: 11px; color: #6b7280;">
                Generated: <?= date('d M Y, h:i A') ?> by <?= sanitizeOutput(getSession('full_name', 'User')) ?>
            </p>
        </div>
    </div>
</div>

<div class="content-header no-print">
    <div>
        <ul class="breadcrumb"><li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li><li>Reports</li></ul>
        <h1>Reports & Analytics</h1>
    </div>
    <button type="button" class="btn btn-primary" onclick="window.print()">
        <i class="fas fa-print"></i> Print Report
    </button>
</div>

<!-- Date Filters -->
<div class="card mb-24 no-print">
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
