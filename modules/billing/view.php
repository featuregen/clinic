<?php
/**
 * Invoice View / Print - Feature Gen Care
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
<!-- Invoice Header - Letterhead -->
        <div style="height: 5px; background: linear-gradient(90deg, #155e75, #0891b2, #06b6d4); border-radius: 4px 4px 0 0; margin: -32px -32px 0 -32px;"></div>
        
        <table style="width: 100%; border-collapse: collapse; margin-top: 20px; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 2px solid var(--primary);" cellpadding="0" cellspacing="0">
            <tr>
                <td style="vertical-align: top;">
                    <?php 
                    $bLogo = $clinic['logo'] ?? '';
                    $bLogoUrl = '';
                    if (!empty($bLogo)) {
                        $bClean = ltrim($bLogo, '/');
                        $bUrl = (strpos($bClean, 'clinics/') === 0) ? (UPLOADS_URL . '/' . $bClean) : (UPLOADS_URL . '/clinics/' . $bClean);
                        $bPath = (strpos($bClean, 'clinics/') === 0) ? (UPLOADS_PATH . '/' . $bClean) : (UPLOADS_PATH . '/clinics/' . $bClean);
                        if (file_exists($bPath)) {
                            $bLogoUrl = $bUrl;
                        }
                    }
                    ?>
                    <table style="border-collapse: collapse;" cellpadding="0" cellspacing="0">
                        <tr>
                            <?php if (!empty($bLogoUrl)): ?>
                            <td style="vertical-align: top; padding-right: 14px;">
                                <img src="<?= $bLogoUrl ?>" alt="Logo" style="height: 52px; width: auto; object-fit: contain; border-radius: 6px;" onerror="this.parentElement.style.display='none'">
                            </td>
                            <?php endif; ?>
                            <td style="vertical-align: top;">
                                <div style="font-size: 18px; font-weight: 800; color: #155e75; margin-bottom: 2px;"><?= sanitizeOutput($clinic['name'] ?? APP_NAME) ?></div>
                                <?php if (!empty($clinic['address'])): ?>
                                <div style="font-size: 11px; color: #64748b; margin-bottom: 2px;">
                                    <?= sanitizeOutput($clinic['address']) ?><?= !empty($clinic['city']) ? ', ' . sanitizeOutput($clinic['city']) : '' ?><?= !empty($clinic['state']) ? ', ' . sanitizeOutput($clinic['state']) : '' ?><?= !empty($clinic['pincode']) ? ' - ' . $clinic['pincode'] : '' ?>
                                </div>
                                <?php endif; ?>
                                <div style="font-size: 11px; color: #64748b;">
                                    <?php if (!empty($clinic['phone'])): ?><span style="margin-right: 10px;">📞 <?= sanitizeOutput($clinic['phone']) ?></span><?php endif; ?>
                                    <?php if (!empty($clinic['email'])): ?><span>✉ <?= sanitizeOutput($clinic['email']) ?></span><?php endif; ?>
                                </div>
                                <?php if (!empty($clinic['gst_number'])): ?>
                                <div style="font-size: 10px; color: #94a3b8; margin-top: 2px;">GSTIN: <?= sanitizeOutput($clinic['gst_number']) ?></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </td>
                <td style="vertical-align: top; text-align: right;">
                    <div style="display: inline-block; background: linear-gradient(135deg, #0891b2, #155e75); color: white; padding: 6px 18px; border-radius: 6px; font-size: 14px; font-weight: 800; letter-spacing: 1px; margin-bottom: 10px;">
                        <?= ($invoice['status'] === 'paid') ? 'RECEIPT' : 'PROFORMA INVOICE' ?>
                    </div>
                    <table style="font-size: 12px; margin-left: auto; border-collapse: collapse;" cellpadding="0" cellspacing="0">
                        <tr><td style="color: #94a3b8; padding: 3px 10px 3px 0; font-weight: 600;">Invoice #</td><td style="font-weight: 700; color: #1e293b;"><?= sanitizeOutput($invoice['invoice_number']) ?></td></tr>
                        <tr><td style="color: #94a3b8; padding: 3px 10px 3px 0; font-weight: 600;">Date</td><td style="color: #334155;"><?= formatDate($invoice['invoice_date']) ?></td></tr>
                        <?php if ($invoice['due_date']): ?>
                        <tr><td style="color: #94a3b8; padding: 3px 10px 3px 0; font-weight: 600;">Due Date</td><td style="color: #334155;"><?= formatDate($invoice['due_date']) ?></td></tr>
                        <?php endif; ?>
                        <tr><td style="color: #94a3b8; padding: 3px 10px 3px 0; font-weight: 600;">Status</td><td><?= getStatusBadge($invoice['status'], 'payment') ?></td></tr>
                    </table>
                </td>
            </tr>
        </table>

        <!-- Patient & Doctor Info -->
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 24px;" cellpadding="0" cellspacing="0">
            <tr>
                <td style="vertical-align: top; width: 50%; padding-right: 12px;">
                    <div style="background: #f8fafc; padding: 14px 16px; border-radius: 8px; border: 1px solid #e2e8f0;">
                        <div style="font-size: 10px; text-transform: uppercase; color: #94a3b8; font-weight: 700; letter-spacing: 0.5px; margin-bottom: 6px;">Bill To</div>
                        <div style="font-weight: 700; font-size: 14px; color: #1e293b;"><?= sanitizeOutput($invoice['first_name'] . ' ' . $invoice['last_name']) ?></div>
                        <div style="font-size: 11.5px; color: #64748b; margin-top: 2px;">ID: <?= sanitizeOutput($invoice['patient_uid']) ?></div>
                        <?php if ($invoice['patient_phone']): ?><div style="font-size: 11.5px; color: #334155; margin-top: 2px;">📞 <?= sanitizeOutput($invoice['patient_phone']) ?></div><?php endif; ?>
                        <?php if ($invoice['patient_email']): ?><div style="font-size: 11.5px; color: #334155;">✉ <?= sanitizeOutput($invoice['patient_email']) ?></div><?php endif; ?>
                        <?php if ($invoice['patient_address']): ?><div style="font-size: 11px; color: #64748b; margin-top: 3px;"><?= sanitizeOutput($invoice['patient_address']) ?></div><?php endif; ?>
                    </div>
                </td>
                <td style="vertical-align: top; width: 50%; padding-left: 12px;">
                    <div style="background: #f8fafc; padding: 14px 16px; border-radius: 8px; border: 1px solid #e2e8f0;">
                        <div style="font-size: 10px; text-transform: uppercase; color: #94a3b8; font-weight: 700; letter-spacing: 0.5px; margin-bottom: 6px;">Doctor / Created By</div>
                        <?php if ($invoice['doctor_name']): ?>
                        <div style="font-weight: 700; font-size: 14px; color: #1e293b;">Dr. <?= sanitizeOutput($invoice['doctor_name']) ?></div>
                        <?php endif; ?>
                        <?php if ($invoice['created_by_name']): ?>
                        <div style="font-size: 11.5px; color: #64748b; margin-top: 2px;">Created by: <?= sanitizeOutput($invoice['created_by_name']) ?></div>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        </table>

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
        <table style="width: 100%; border-collapse: collapse;" cellpadding="0" cellspacing="0">
            <tr>
                <td style="width: 55%;"></td>
                <td style="width: 45%;">
                    <table style="width: 100%; font-size: 13px; border-collapse: collapse;" cellpadding="0" cellspacing="0">
                        <tr><td style="color: #64748b; padding: 6px 0;">Subtotal</td><td style="text-align: right; padding: 6px 0;"><?= formatCurrency($invoice['subtotal']) ?></td></tr>
                        <?php if ($invoice['discount_amount'] > 0): ?>
                        <tr><td style="color: #64748b; padding: 6px 0;">Discount <?= $invoice['discount_type'] === 'percentage' ? '(' . $invoice['discount_percentage'] . '%)' : '' ?></td>
                            <td style="text-align: right; padding: 6px 0; color: #dc2626;">-<?= formatCurrency($invoice['discount_amount']) ?></td></tr>
                        <?php endif; ?>
                        <?php if ($invoice['tax_amount'] > 0): ?>
                        <tr><td style="color: #64748b; padding: 6px 0;">Tax / GST</td><td style="text-align: right; padding: 6px 0;"><?= formatCurrency($invoice['tax_amount']) ?></td></tr>
                        <?php endif; ?>
                        <tr style="border-top: 2px solid #0891b2;">
                            <td style="padding: 10px 0 6px; font-weight: 700; font-size: 15px; color: #1e293b;">Grand Total</td>
                            <td style="text-align: right; padding: 10px 0 6px; font-weight: 800; font-size: 15px; color: #0891b2;"><?= formatCurrency($invoice['total_amount']) ?></td>
                        </tr>
                        <tr><td style="color: #64748b; padding: 4px 0;">Paid</td>
                            <td style="text-align: right; padding: 4px 0; color: #16a34a; font-weight: 600;"><?= formatCurrency($invoice['paid_amount']) ?></td></tr>
                        <tr style="border-top: 1px solid #e2e8f0;">
                            <td style="padding: 8px 0 0; font-weight: 700;">Balance Due</td>
                            <td style="text-align: right; padding: 8px 0 0; font-weight: 800; color: #dc2626; font-size: 15px;"><?= formatCurrency($invoice['due_amount']) ?></td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <!-- Invoice Notes -->
        <?php if ($invoice['notes']): ?>
        <div style="margin-top: 24px; padding: 12px 16px; background: #f8fafc; border-radius: 8px; border-left: 3px solid #0891b2;">
            <strong style="font-size: 10px; text-transform: uppercase; color: #94a3b8; letter-spacing: 0.5px;">Notes</strong>
            <p style="margin-top: 4px; font-size: 13px; color: #334155;"><?= nl2br(sanitizeOutput($invoice['notes'])) ?></p>
        </div>
        <?php endif; ?>

        <!-- Footer band -->
        <div style="height: 4px; background: linear-gradient(90deg, #155e75, #0891b2, #06b6d4); margin: 24px -32px -32px -32px; border-radius: 0 0 4px 4px;"></div>
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
