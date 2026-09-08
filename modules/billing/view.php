<?php
/**
 * Invoice View / Print - Advanced Clinic Suite
 */
$pageTitle = 'Invoice Details';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';
requirePermission('billing.view');

$db = db();
$clinicId = getCurrentClinicId();

$id = intval($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . BASE_URL . '/modules/billing/list.php'); exit; }

// Fetch invoice
$invoice = $db->fetch(
    "SELECT i.*, p.first_name, p.last_name, p.patient_uid, p.phone as patient_phone, p.email as patient_email,
            p.address as patient_address, p.city as patient_city, p.state as patient_state,
            u.full_name as doctor_name, cr.full_name as created_by_name
     FROM invoices i
     JOIN patients p ON i.patient_id = p.id
     LEFT JOIN doctors d ON i.doctor_id = d.id
     LEFT JOIN users u ON d.user_id = u.id
     LEFT JOIN users cr ON i.created_by = cr.id
     WHERE i.id = ? AND i.clinic_id = ?",
    [$id, $clinicId]
);

if (!$invoice) { setFlashMessage('error', 'Invoice not found.'); header('Location: ' . BASE_URL . '/modules/billing/list.php'); exit; }

// Fetch clinic info
$clinic = $db->fetch("SELECT * FROM clinics WHERE id = ?", [$clinicId]);

// Fetch line items
$items = $db->fetchAll("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY sort_order, id", [$id]);

// Fetch payment history
$payments = $db->fetchAll(
    "SELECT py.*, u.full_name as received_by_name FROM payments py
     LEFT JOIN users u ON py.received_by = u.id
     WHERE py.invoice_id = ? ORDER BY py.payment_date DESC, py.created_at DESC", [$id]
);
?>

<div class="content-header">
    <div>
        <ul class="breadcrumb">
            <li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li>
            <li><a href="<?= BASE_URL ?>/modules/billing/list.php">Billing</a></li>
            <li>Invoice <?= sanitizeOutput($invoice['invoice_number']) ?></li>
        </ul>
        <h1>Invoice <?= sanitizeOutput($invoice['invoice_number']) ?></h1>
    </div>
    <div class="d-flex gap-8">
        <?php if ($invoice['due_amount'] > 0 && hasPermission('billing.payments')): ?>
        <a href="<?= BASE_URL ?>/modules/billing/payment.php?id=<?= $id ?>" class="btn btn-success">
            <i class="fas fa-rupee-sign"></i> Record Payment
        </a>
        <?php endif; ?>
        <button class="btn btn-primary" onclick="printContent('invoice-print')">
            <i class="fas fa-print"></i> Print
        </button>
    </div>
</div>

<!-- Printable Invoice -->
<div class="card mb-24" id="invoice-print">
    <div class="card-body" style="padding: 32px;">
        <!-- Invoice Header -->
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 32px; border-bottom: 3px solid var(--primary); padding-bottom: 20px;">
            <div style="display: flex; align-items: flex-start; gap: 16px;">
                <?php if (!empty($clinic['logo'])): ?>
                <img src="<?= UPLOADS_URL ?>/<?= $clinic['logo'] ?>" alt="Logo" style="height: 60px; width: auto; object-fit: contain;">
                <?php endif; ?>
                <div>
                    <h2 style="color: var(--primary); margin-bottom: 4px;"><?= sanitizeOutput($clinic['name'] ?? APP_NAME) ?></h2>
                    <?php if ($clinic['tagline']): ?><p style="color: var(--text-muted); font-size: 13px;"><?= sanitizeOutput($clinic['tagline']) ?></p><?php endif; ?>
                    <?php if ($clinic['address']): ?><p style="font-size: 12px; color: var(--text-secondary); margin-top: 8px;">
                        <?= sanitizeOutput($clinic['address']) ?><?= $clinic['city'] ? ', ' . sanitizeOutput($clinic['city']) : '' ?>
                        <?= $clinic['state'] ? ', ' . sanitizeOutput($clinic['state']) : '' ?><?= $clinic['pincode'] ? ' - ' . $clinic['pincode'] : '' ?>
                    </p><?php endif; ?>
                    <?php if ($clinic['phone']): ?><p style="font-size: 12px; color: var(--text-secondary);">
                        <i class="fas fa-phone" style="font-size: 10px;"></i> <?= sanitizeOutput($clinic['phone']) ?>
                        <?= $clinic['email'] ? ' | <i class="fas fa-envelope" style="font-size: 10px;"></i> ' . sanitizeOutput($clinic['email']) : '' ?>
                    </p><?php endif; ?>
                    <?php if ($clinic['gst_number']): ?><p style="font-size: 12px; color: var(--text-muted);">GSTIN: <?= sanitizeOutput($clinic['gst_number']) ?></p><?php endif; ?>
                </div>
            </div>
            <div style="text-align: right;">
                <h3 style="color: var(--primary); margin-bottom: 8px;">INVOICE</h3>
                <table style="font-size: 13px; text-align: left; margin-left: auto;">
                    <tr><td class="text-muted" style="padding-right: 12px;">Invoice #</td><td class="font-semibold"><?= sanitizeOutput($invoice['invoice_number']) ?></td></tr>
                    <tr><td class="text-muted" style="padding-right: 12px;">Date</td><td><?= formatDate($invoice['invoice_date']) ?></td></tr>
                    <?php if ($invoice['due_date']): ?>
                    <tr><td class="text-muted" style="padding-right: 12px;">Due Date</td><td><?= formatDate($invoice['due_date']) ?></td></tr>
                    <?php endif; ?>
                    <tr><td class="text-muted" style="padding-right: 12px;">Status</td><td><?= getStatusBadge($invoice['status'], 'payment') ?></td></tr>
                </table>
            </div>
        </div>

        <!-- Patient & Doctor Info -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 28px;">
            <div style="background: var(--bg-secondary); padding: 16px; border-radius: 8px;">
                <h4 style="font-size: 12px; text-transform: uppercase; color: var(--text-muted); margin-bottom: 8px;">Bill To</h4>
                <div class="font-semibold"><?= sanitizeOutput($invoice['first_name'] . ' ' . $invoice['last_name']) ?></div>
                <div style="font-size: 12px; color: var(--text-muted);">ID: <?= sanitizeOutput($invoice['patient_uid']) ?></div>
                <?php if ($invoice['patient_phone']): ?><div style="font-size: 12px;"><i class="fas fa-phone" style="font-size: 10px;"></i> <?= sanitizeOutput($invoice['patient_phone']) ?></div><?php endif; ?>
                <?php if ($invoice['patient_email']): ?><div style="font-size: 12px;"><i class="fas fa-envelope" style="font-size: 10px;"></i> <?= sanitizeOutput($invoice['patient_email']) ?></div><?php endif; ?>
                <?php if ($invoice['patient_address']): ?><div style="font-size: 12px; margin-top: 4px;"><?= sanitizeOutput($invoice['patient_address']) ?></div><?php endif; ?>
            </div>
            <div style="background: var(--bg-secondary); padding: 16px; border-radius: 8px;">
                <h4 style="font-size: 12px; text-transform: uppercase; color: var(--text-muted); margin-bottom: 8px;">Doctor / Created By</h4>
                <?php if ($invoice['doctor_name']): ?>
                <div class="font-semibold">Dr. <?= sanitizeOutput($invoice['doctor_name']) ?></div>
                <?php endif; ?>
                <?php if ($invoice['created_by_name']): ?>
                <div style="font-size: 12px; color: var(--text-muted);">Created by: <?= sanitizeOutput($invoice['created_by_name']) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Line Items Table -->
        <div class="table-responsive" style="margin-bottom: 24px;">
            <table class="table" style="border: 1px solid var(--border-color);">
                <thead style="background: var(--primary); color: white;">
                    <tr>
                        <th style="color: white;">#</th>
                        <th style="color: white;">Item</th>
                        <th style="color: white;">Type</th>
                        <th style="color: white; text-align: right;">Qty</th>
                        <th style="color: white; text-align: right;">Rate</th>
                        <th style="color: white; text-align: right;">Discount</th>
                        <th style="color: white; text-align: right;">Tax</th>
                        <th style="color: white; text-align: right;">Total</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($items)): ?>
                    <tr><td colspan="8" style="text-align: center; padding: 20px; color: var(--text-muted);">No line items</td></tr>
                <?php else: ?>
                    <?php foreach ($items as $i => $item): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td>
                            <div class="font-semibold"><?= sanitizeOutput($item['item_name']) ?></div>
                            <?php if ($item['description']): ?><div style="font-size: 11px; color: var(--text-muted);"><?= sanitizeOutput($item['description']) ?></div><?php endif; ?>
                        </td>
                        <td><span class="badge badge-secondary" style="font-size: 10px;"><?= ucfirst(str_replace('_', ' ', $item['item_type'])) ?></span></td>
                        <td style="text-align: right;"><?= $item['quantity'] ?></td>
                        <td style="text-align: right;"><?= formatCurrency($item['unit_price']) ?></td>
                        <td style="text-align: right;"><?= $item['discount'] > 0 ? formatCurrency($item['discount']) : '-' ?></td>
                        <td style="text-align: right;"><?= $item['tax_amount'] > 0 ? formatCurrency($item['tax_amount']) : '-' ?></td>
                        <td style="text-align: right; font-weight: 600;"><?= formatCurrency($item['total']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Summary -->
        <div style="display: flex; justify-content: flex-end;">
            <table style="min-width: 300px; font-size: 14px;">
                <tr><td class="text-muted" style="padding: 6px 16px 6px 0;">Subtotal</td><td style="text-align: right; padding: 6px 0;"><?= formatCurrency($invoice['subtotal']) ?></td></tr>
                <?php if ($invoice['discount_amount'] > 0): ?>
                <tr><td class="text-muted" style="padding: 6px 16px 6px 0;">Discount <?= $invoice['discount_type'] === 'percentage' ? '(' . $invoice['discount_percentage'] . '%)' : '' ?></td>
                    <td style="text-align: right; padding: 6px 0; color: var(--danger);">-<?= formatCurrency($invoice['discount_amount']) ?></td></tr>
                <?php endif; ?>
                <?php if ($invoice['tax_amount'] > 0): ?>
                <tr><td class="text-muted" style="padding: 6px 16px 6px 0;">Tax / GST</td><td style="text-align: right; padding: 6px 0;"><?= formatCurrency($invoice['tax_amount']) ?></td></tr>
                <?php endif; ?>
                <tr style="border-top: 2px solid var(--primary);">
                    <td class="font-semibold" style="padding: 10px 16px 6px 0; font-size: 16px;">Grand Total</td>
                    <td style="text-align: right; padding: 10px 0 6px; font-weight: 700; font-size: 16px; color: var(--primary);"><?= formatCurrency($invoice['total_amount']) ?></td>
                </tr>
                <tr><td class="text-muted" style="padding: 4px 16px 4px 0;">Paid</td>
                    <td style="text-align: right; padding: 4px 0; color: var(--success); font-weight: 600;"><?= formatCurrency($invoice['paid_amount']) ?></td></tr>
                <tr style="border-top: 1px solid var(--border-color);">
                    <td class="font-semibold" style="padding: 8px 16px 0 0;">Balance Due</td>
                    <td style="text-align: right; padding: 8px 0 0; font-weight: 700; color: var(--danger); font-size: 16px;"><?= formatCurrency($invoice['due_amount']) ?></td>
                </tr>
            </table>
        </div>

        <!-- Invoice Notes -->
        <?php if ($invoice['notes']): ?>
        <div style="margin-top: 24px; padding: 12px 16px; background: var(--bg-secondary); border-radius: 8px; border-left: 3px solid var(--info);">
            <strong style="font-size: 12px; text-transform: uppercase; color: var(--text-muted);">Notes</strong>
            <p style="margin-top: 4px; font-size: 13px;"><?= nl2br(sanitizeOutput($invoice['notes'])) ?></p>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Payment History -->
<?php if (!empty($payments)): ?>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-history" style="color: var(--success);"></i> Payment History</h3></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Date</th><th>Amount</th><th>Mode</th><th>Transaction ID</th><th>Received By</th><th>Notes</th></tr></thead>
                <tbody>
                <?php foreach ($payments as $pay): ?>
                <tr>
                    <td><?= formatDate($pay['payment_date']) ?></td>
                    <td class="font-semibold text-success"><?= formatCurrency($pay['amount']) ?></td>
                    <td><span class="badge badge-primary"><?= PAYMENT_MODES[$pay['payment_mode']] ?? ucfirst($pay['payment_mode']) ?></span></td>
                    <td><?= $pay['transaction_id'] ? sanitizeOutput($pay['transaction_id']) : '-' ?></td>
                    <td><?= sanitizeOutput($pay['received_by_name'] ?? '-') ?></td>
                    <td><?= $pay['notes'] ? sanitizeOutput($pay['notes']) : '-' ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
