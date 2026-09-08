<?php
/**
 * Billing - Invoice List - Advanced Clinic Suite
 */
$pageTitle = 'Billing';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';
requirePermission('billing.view');

$db = db();
$clinicId = getCurrentClinicId();

$search = sanitize($_GET['search'] ?? '');
$status = sanitize($_GET['status'] ?? '');
$from = sanitize($_GET['from'] ?? '');
$to = sanitize($_GET['to'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));

$where = "WHERE i.clinic_id = ?";
$params = [$clinicId];

if ($search) {
    $where .= " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR i.invoice_number LIKE ? OR p.patient_uid LIKE ?)";
    $s = "%$search%"; $params = array_merge($params, [$s, $s, $s, $s]);
}
if ($status) { $where .= " AND i.status = ?"; $params[] = $status; }
if ($from) { $where .= " AND i.invoice_date >= ?"; $params[] = $from; }
if ($to) { $where .= " AND i.invoice_date <= ?"; $params[] = $to; }

$total = $db->fetch("SELECT COUNT(*) as total FROM invoices i JOIN patients p ON i.patient_id = p.id $where", $params)['total'];
$pagination = paginate($total, $page);

$invoices = $db->fetchAll(
    "SELECT i.*, p.first_name, p.last_name, p.patient_uid, u.full_name as created_by_name
     FROM invoices i JOIN patients p ON i.patient_id = p.id LEFT JOIN users u ON i.created_by = u.id
     $where ORDER BY i.invoice_date DESC, i.created_at DESC
     LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}", $params
);

// Revenue stats
try {
    $today = date('Y-m-d');
    $todayRevenue = $db->fetch("SELECT COALESCE(SUM(amount),0) as total FROM payments WHERE clinic_id = ? AND payment_date = ?", [$clinicId, $today])['total'];
    $monthRevenue = $db->fetch("SELECT COALESCE(SUM(amount),0) as total FROM payments WHERE clinic_id = ? AND MONTH(payment_date)=MONTH(NOW()) AND YEAR(payment_date)=YEAR(NOW())", [$clinicId])['total'];
    $totalDue = $db->fetch("SELECT COALESCE(SUM(due_amount),0) as total FROM invoices WHERE clinic_id = ? AND status IN('due','partial','overdue')", [$clinicId])['total'];
    $totalCollected = $db->fetch("SELECT COALESCE(SUM(paid_amount),0) as total FROM invoices WHERE clinic_id = ?", [$clinicId])['total'];
} catch(Exception $e) { $todayRevenue = $monthRevenue = $totalDue = $totalCollected = 0; }
?>

<div class="content-header">
    <div>
        <ul class="breadcrumb">
            <li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li>
            <li>Billing</li>
        </ul>
        <h1>Billing & Invoices</h1>
    </div>
    <?php if (hasPermission('billing.create')): ?>
    <a href="<?= BASE_URL ?>/modules/billing/create.php" class="btn btn-primary">
        <i class="fas fa-file-invoice"></i> Create Invoice
    </a>
    <?php endif; ?>
</div>

<div class="grid-4 mb-24">
    <div class="stat-card"><div class="stat-icon primary"><i class="fas fa-rupee-sign"></i></div><div class="stat-details"><div class="stat-label">Today's Collection</div><div class="stat-value"><?= formatCurrency($todayRevenue) ?></div></div></div>
    <div class="stat-card"><div class="stat-icon success"><i class="fas fa-chart-line"></i></div><div class="stat-details"><div class="stat-label">This Month</div><div class="stat-value"><?= formatCurrency($monthRevenue) ?></div></div></div>
    <div class="stat-card"><div class="stat-icon warning"><i class="fas fa-clock"></i></div><div class="stat-details"><div class="stat-label">Outstanding Dues</div><div class="stat-value"><?= formatCurrency($totalDue) ?></div></div></div>
    <div class="stat-card"><div class="stat-icon info"><i class="fas fa-wallet"></i></div><div class="stat-details"><div class="stat-label">Total Collected</div><div class="stat-value"><?= formatCurrency($totalCollected) ?></div></div></div>
</div>

<div class="card mb-24">
    <div class="card-body">
        <form method="GET" class="d-flex gap-12 align-center flex-wrap">
            <div class="header-search" style="max-width: 260px; flex: 1;">
                <i class="fas fa-search"></i>
                <input type="text" name="search" value="<?= sanitizeOutput($search) ?>" 
                       placeholder="Search invoice, patient..." class="form-control" style="padding-left: 38px;">
            </div>
            <select name="status" class="form-control" style="width: auto;">
                <option value="">All Status</option>
                <?php foreach (PAYMENT_STATUS as $k => $v): ?>
                <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $v ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="from" class="form-control" value="<?= $from ?>" style="width: auto;" placeholder="From">
            <input type="date" name="to" class="form-control" value="<?= $to ?>" style="width: auto;" placeholder="To">
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i></button>
            <a href="<?= BASE_URL ?>/modules/billing/list.php" class="btn btn-outline btn-sm"><i class="fas fa-times"></i></a>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3>Invoices <span class="badge badge-primary"><?= $total ?></span></h3></div>
    <div class="card-body p-0">
        <?php if (empty($invoices)): ?>
        <div class="empty-state"><i class="fas fa-file-invoice-dollar"></i><h3>No invoices found</h3></div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr><th>Invoice #</th><th>Date</th><th>Patient</th><th>Total</th><th>Paid</th><th>Due</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($invoices as $inv): ?>
                <tr>
                    <td class="font-semibold"><?= sanitizeOutput($inv['invoice_number']) ?></td>
                    <td><?= formatDate($inv['invoice_date']) ?></td>
                    <td>
                        <a href="<?= BASE_URL ?>/modules/patients/view.php?id=<?= $inv['patient_id'] ?>" class="font-semibold"><?= sanitizeOutput($inv['first_name'] . ' ' . $inv['last_name']) ?></a>
                        <div class="text-muted" style="font-size: 11px;"><?= sanitizeOutput($inv['patient_uid']) ?></div>
                    </td>
                    <td class="font-semibold"><?= formatCurrency($inv['total_amount']) ?></td>
                    <td class="text-success"><?= formatCurrency($inv['paid_amount']) ?></td>
                    <td class="text-danger font-semibold"><?= formatCurrency($inv['due_amount']) ?></td>
                    <td><?= getStatusBadge($inv['status'], 'payment') ?></td>
                    <td>
                        <a href="<?= BASE_URL ?>/modules/billing/view.php?id=<?= $inv['id'] ?>" class="btn btn-sm btn-ghost" title="View"><i class="fas fa-eye"></i></a>
                        <?php if ($inv['due_amount'] > 0 && hasPermission('billing.payment')): ?>
                        <a href="<?= BASE_URL ?>/modules/billing/payment.php?id=<?= $inv['id'] ?>" class="btn btn-sm btn-success" title="Record Payment"><i class="fas fa-rupee-sign"></i></a>
                        <?php endif; ?>
                        <button class="btn btn-sm btn-ghost" onclick="printContent('print-<?= $inv['id'] ?>')" title="Print"><i class="fas fa-print"></i></button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= renderPagination($pagination, BASE_URL . '/modules/billing/list.php') ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
