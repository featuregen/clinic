<?php
/**
 * Invoice Print View - Feature Gen Care
 */
require_once dirname(dirname(__DIR__)) . '/config/session.php';
requirePermission('billing.view');

$db = db();
$clinicId = getCurrentClinicId();
$id = intval($_GET['id'] ?? 0);

if (!$id) {
    die("Invalid Invoice ID.");
}

// Fetch invoice
$invoice = $db->fetch(
    "SELECT i.*, p.first_name, p.last_name, p.patient_uid, p.phone as patient_phone, p.email as patient_email,
            p.address as patient_address, p.city as patient_city, p.state as patient_state,
            p.gender, p.age, p.date_of_birth,
            u.full_name as doctor_name, cr.full_name as created_by_name
     FROM invoices i
     JOIN patients p ON i.patient_id = p.id
     LEFT JOIN doctors d ON i.doctor_id = d.id
     LEFT JOIN users u ON d.user_id = u.id
     LEFT JOIN users cr ON i.created_by = cr.id
     WHERE i.id = ? AND i.clinic_id = ?",
    [$id, $clinicId]
);

if (!$invoice) {
    die("Invoice not found.");
}

// Fetch clinic info
$clinic = $db->fetch("SELECT * FROM clinics WHERE id = ?", [$clinicId]);

// Fetch line items
$items = $db->fetchAll("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id ASC", [$id]);

// Fetch payment history
$payments = $db->fetchAll(
    "SELECT py.*, u.full_name as received_by_name FROM payments py
     LEFT JOIN users u ON py.received_by = u.id
     WHERE py.invoice_id = ? ORDER BY py.payment_date ASC, py.id ASC",
    [$id]
);

// Resolve clinic logo
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

$printHeaderStyle = $clinic['print_header_style'] ?? 'logo_with_name';
$patientAge = $invoice['date_of_birth'] ? calculateAge($invoice['date_of_birth']) : ($invoice['age'] ?? '-');
$isReceipt = ($invoice['status'] === 'paid');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isReceipt ? 'Receipt' : 'Invoice' ?> - <?= sanitizeOutput($invoice['invoice_number']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #0891b2;
            --primary-dark: #155e75;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --bg-light: #f8fafc;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: #f1f5f9;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            color: var(--text-main);
            font-size: 13px;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        /* Top Action Bar (hidden on print) */
        .no-print-bar {
            background: #0f172a;
            color: #fff;
            padding: 12px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 2px 10px rgba(0,0,0,0.15);
        }
        .no-print-bar .title { font-weight: 600; font-size: 14px; display: flex; align-items: center; gap: 8px; }
        .no-print-bar .actions { display: flex; gap: 10px; }
        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 16px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            border: none;
            transition: all 0.15s;
        }
        .btn-print { background: #0891b2; color: #fff; }
        .btn-print:hover { background: #0e7490; }
        .btn-close { background: rgba(255,255,255,0.12); color: #fff; }
        .btn-close:hover { background: rgba(255,255,255,0.2); }

        /* Printable Sheet */
        .invoice-sheet {
            max-width: 820px;
            margin: 24px auto 40px;
            background: #ffffff;
            box-shadow: 0 4px 24px rgba(0,0,0,0.08);
            border-radius: 8px;
            overflow: hidden;
            padding: 36px 40px;
        }

        @media print {
            body { background: #fff !important; }
            .no-print-bar { display: none !important; }
            .invoice-sheet {
                margin: 0 !important;
                padding: 24px !important;
                box-shadow: none !important;
                border-radius: 0 !important;
                max-width: 100% !important;
            }
            body { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        }

        /* Top Color Accent */
        .header-band {
            height: 5px;
            background: linear-gradient(90deg, #155e75, #0891b2, #06b6d4);
            border-radius: 4px 4px 0 0;
            margin: -36px -40px 24px -40px;
        }
        @media print {
            .header-band { margin: -24px -24px 20px -24px !important; }
        }

        /* Letterhead Table */
        .letterhead-table { width: 100%; border-collapse: collapse; margin-bottom: 24px; padding-bottom: 20px; border-bottom: 2px solid var(--primary); }
        .clinic-name { font-size: 20px; font-weight: 800; color: #155e75; margin-bottom: 3px; }
        .clinic-meta { font-size: 11.5px; color: #64748b; margin-bottom: 2px; }

        .doc-badge {
            display: inline-block;
            background: linear-gradient(135deg, #0891b2, #155e75);
            color: #fff;
            padding: 6px 18px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 800;
            letter-spacing: 1px;
            margin-bottom: 10px;
        }
        .doc-badge.paid { background: linear-gradient(135deg, #059669, #047857); }
        .doc-badge.due { background: linear-gradient(135deg, #d97706, #b45309); }

        .meta-table { font-size: 12px; margin-left: auto; border-collapse: collapse; }
        .meta-table td { padding: 3px 0; }
        .meta-table td:first-child { color: #94a3b8; font-weight: 600; padding-right: 12px; }
        .meta-table td:last-child { color: var(--text-main); font-weight: 700; text-align: right; }

        /* Patient / Bill To */
        .info-grid {
            display: flex;
            gap: 16px;
            margin-bottom: 24px;
        }
        .info-card {
            flex: 1;
            background: #f8fafc;
            padding: 14px 16px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }
        .info-card-label {
            font-size: 10px;
            text-transform: uppercase;
            color: #94a3b8;
            font-weight: 700;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }
        .info-card-name { font-weight: 700; font-size: 14px; color: #1e293b; margin-bottom: 3px; }
        .info-card-detail { font-size: 11.5px; color: #64748b; line-height: 1.4; }

        /* Items Table */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            overflow: hidden;
        }
        .items-table th {
            background: #0891b2;
            color: #ffffff;
            font-weight: 700;
            font-size: 12px;
            padding: 10px 14px;
            text-align: left;
        }
        .items-table td {
            padding: 11px 14px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 12.5px;
        }
        .items-table tr:nth-child(even) td { background: #fafafa; }
        .items-table tr:last-child td { border-bottom: none; }

        /* Totals */
        .totals-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .totals-table td { padding: 6px 0; }
        .totals-table tr.total-row td {
            padding: 10px 0 8px;
            font-weight: 800;
            font-size: 15px;
            color: #0891b2;
            border-top: 2px solid #0891b2;
        }
        .totals-table tr.due-row td {
            padding: 8px 0 0;
            font-weight: 800;
            font-size: 15px;
            color: #dc2626;
            border-top: 1px solid #e2e8f0;
        }

        /* Payment history */
        .pay-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            margin-top: 12px;
        }
        .pay-table th { background: #f1f5f9; color: #475569; padding: 7px 10px; text-align: left; font-weight: 600; }
        .pay-table td { padding: 7px 10px; border-bottom: 1px solid #f1f5f9; }

        /* Footer */
        .bottom-band {
            height: 4px;
            background: linear-gradient(90deg, #155e75, #0891b2, #06b6d4);
            margin: 32px -40px -36px -40px;
            border-radius: 0 0 4px 4px;
        }
        @media print {
            .bottom-band { margin: 24px -24px -24px -24px !important; }
        }
    </style>
</head>
<body>

<!-- No-Print Toolbar -->
<div class="no-print-bar">
    <div class="title">
        <i class="fas fa-file-invoice"></i>
        <span><?= sanitizeOutput($invoice['invoice_number']) ?> &bull; <?= sanitizeOutput($invoice['first_name'] . ' ' . $invoice['last_name']) ?></span>
    </div>
    <div class="actions">
        <button class="btn-action btn-print" onclick="window.print();">
            <i class="fas fa-print"></i> Print / Save PDF
        </button>
        <button class="btn-action btn-close" onclick="window.close();">
            <i class="fas fa-times"></i> Close
        </button>
    </div>
</div>

<!-- Printable Sheet -->
<div class="invoice-sheet">
    <div class="header-band"></div>

    <!-- Letterhead -->
    <table class="letterhead-table" cellpadding="0" cellspacing="0">
        <tr>
            <td style="vertical-align: top;">
                <table style="border-collapse: collapse;" cellpadding="0" cellspacing="0">
                    <tr>
                        <?php if (!empty($bLogoUrl)): ?>
                        <td style="vertical-align: top; padding-right: <?= ($printHeaderStyle === 'logo_only') ? '0' : '14' ?>px;">
                            <img src="<?= $bLogoUrl ?>" alt="Logo" style="height: <?= ($printHeaderStyle === 'logo_only') ? '64px' : '54px' ?>; width: auto; object-fit: contain; border-radius: 6px;" onerror="this.parentElement.style.display='none'">
                        </td>
                        <?php endif; ?>
                        <?php if ($printHeaderStyle !== 'logo_only'): ?>
                        <td style="vertical-align: top;">
                            <div class="clinic-name"><?= sanitizeOutput($clinic['name'] ?? APP_NAME) ?></div>
                            <?php if (!empty($clinic['address'])): ?>
                            <div class="clinic-meta">
                                <?= sanitizeOutput($clinic['address']) ?><?= !empty($clinic['city']) ? ', ' . sanitizeOutput($clinic['city']) : '' ?><?= !empty($clinic['state']) ? ', ' . sanitizeOutput($clinic['state']) : '' ?><?= !empty($clinic['pincode']) ? ' - ' . $clinic['pincode'] : '' ?>
                            </div>
                            <?php endif; ?>
                            <div class="clinic-meta">
                                <?php if (!empty($clinic['phone'])): ?><span style="margin-right: 12px;">📞 <?= sanitizeOutput($clinic['phone']) ?></span><?php endif; ?>
                                <?php if (!empty($clinic['email'])): ?><span>✉ <?= sanitizeOutput($clinic['email']) ?></span><?php endif; ?>
                            </div>
                            <?php if (!empty($clinic['gst_number'])): ?>
                            <div style="font-size: 10px; color: #94a3b8; margin-top: 2px;">GSTIN: <?= sanitizeOutput($clinic['gst_number']) ?></div>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                </table>
            </td>
            <td style="vertical-align: top; text-align: right;">
                <div class="doc-badge <?= $isReceipt ? 'paid' : ($invoice['due_amount'] > 0 ? 'due' : '') ?>">
                    <?= $isReceipt ? 'PAYMENT RECEIPT' : ($invoice['due_amount'] > 0 ? 'INVOICE (DUE)' : 'TAX INVOICE') ?>
                </div>
                <table class="meta-table" cellpadding="0" cellspacing="0">
                    <tr><td>Invoice #</td><td><?= sanitizeOutput($invoice['invoice_number']) ?></td></tr>
                    <tr><td>Date</td><td><?= formatDate($invoice['invoice_date']) ?></td></tr>
                    <?php if (!empty($invoice['due_date'])): ?>
                    <tr><td>Due Date</td><td><?= formatDate($invoice['due_date']) ?></td></tr>
                    <?php endif; ?>
                    <tr><td>Payment Mode</td><td><?= ucfirst($invoice['payment_mode'] ?? 'Cash') ?></td></tr>
                    <tr><td>Status</td><td style="color: <?= $isReceipt ? '#059669' : '#d97706' ?>;"><?= ucfirst($invoice['status']) ?></td></tr>
                </table>
            </td>
        </tr>
    </table>

    <!-- Info Grid -->
    <div class="info-grid">
        <div class="info-card">
            <div class="info-card-label">Billed To</div>
            <div class="info-card-name"><?= sanitizeOutput($invoice['first_name'] . ' ' . $invoice['last_name']) ?></div>
            <div class="info-card-detail">Patient ID: <strong><?= sanitizeOutput($invoice['patient_uid']) ?></strong></div>
            <?php if (!empty($invoice['patient_phone'])): ?>
            <div class="info-card-detail">Phone: <?= sanitizeOutput($invoice['patient_phone']) ?></div>
            <?php endif; ?>
            <?php if (!empty($patientAge)): ?>
            <div class="info-card-detail">Age / Gender: <?= $patientAge ?> <?= !empty($invoice['gender']) ? ' • ' . ucfirst($invoice['gender']) : '' ?></div>
            <?php endif; ?>
            <?php if (!empty($invoice['patient_address'])): ?>
            <div class="info-card-detail">Address: <?= sanitizeOutput($invoice['patient_address']) ?></div>
            <?php endif; ?>
        </div>
        <div class="info-card">
            <div class="info-card-label">Consulting Doctor / Details</div>
            <?php if (!empty($invoice['doctor_name'])): ?>
            <div class="info-card-name">Dr. <?= sanitizeOutput($invoice['doctor_name']) ?></div>
            <?php endif; ?>
            <?php if (!empty($invoice['created_by_name'])): ?>
            <div class="info-card-detail">Billed By: <?= sanitizeOutput($invoice['created_by_name']) ?></div>
            <?php endif; ?>
            <div class="info-card-detail" style="margin-top: 4px;">Time: <?= date('h:i A', strtotime($invoice['created_at'])) ?></div>
        </div>
    </div>

    <!-- Items Table -->
    <table class="items-table" cellpadding="0" cellspacing="0">
        <thead>
            <tr>
                <th style="width: 40px; text-align: center;">#</th>
                <th>Item / Description</th>
                <th style="width: 120px;">Category</th>
                <th style="width: 60px; text-align: right;">Qty</th>
                <th style="width: 100px; text-align: right;">Rate</th>
                <?php if ($invoice['discount_amount'] > 0): ?><th style="width: 90px; text-align: right;">Discount</th><?php endif; ?>
                <?php if ($invoice['tax_amount'] > 0): ?><th style="width: 90px; text-align: right;">GST</th><?php endif; ?>
                <th style="width: 110px; text-align: right;">Total</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($items)): ?>
            <tr><td colspan="8" style="text-align: center; padding: 20px; color: var(--text-muted);">No items recorded</td></tr>
        <?php else: ?>
            <?php foreach ($items as $idx => $it): 
                $rate = floatval($it['unit_price'] ?? 0);
                $qty = floatval($it['quantity'] ?? 1);
                $lineTotal = floatval($it['total'] ?? ($rate * $qty));
            ?>
            <tr>
                <td style="text-align: center; color: #94a3b8;"><?= $idx + 1 ?></td>
                <td>
                    <div style="font-weight: 600; color: #0f172a;"><?= sanitizeOutput($it['item_name']) ?></div>
                    <?php if (!empty($it['description'])): ?>
                    <div style="font-size: 11px; color: #64748b;"><?= sanitizeOutput($it['description']) ?></div>
                    <?php endif; ?>
                </td>
                <td><span style="background: #f1f5f9; padding: 2px 8px; border-radius: 4px; font-size: 11px; color: #475569;"><?= ucfirst(str_replace('_', ' ', $it['item_type'])) ?></span></td>
                <td style="text-align: right;"><?= $qty ?></td>
                <td style="text-align: right;"><?= formatCurrency($rate) ?></td>
                <?php if ($invoice['discount_amount'] > 0): ?>
                <td style="text-align: right; color: #dc2626;"><?= !empty($it['discount']) && $it['discount'] > 0 ? formatCurrency($it['discount']) : '-' ?></td>
                <?php endif; ?>
                <?php if ($invoice['tax_amount'] > 0): ?>
                <td style="text-align: right;"><?= !empty($it['tax_amount']) && $it['tax_amount'] > 0 ? formatCurrency($it['tax_amount']) : '-' ?></td>
                <?php endif; ?>
                <td style="text-align: right; font-weight: 700; color: #0f172a;"><?= formatCurrency($lineTotal) ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <!-- Totals Table -->
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 24px;" cellpadding="0" cellspacing="0">
        <tr>
            <td style="width: 50%; vertical-align: top; padding-right: 20px;">
                <?php if (!empty($payments)): ?>
                <div style="font-size: 11px; text-transform: uppercase; color: #94a3b8; font-weight: 700; letter-spacing: 0.5px; margin-bottom: 4px;">Payments Received</div>
                <table class="pay-table" cellpadding="0" cellspacing="0">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Mode</th>
                            <th>Ref / Trans #</th>
                            <th style="text-align: right;">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($payments as $pay): ?>
                    <tr>
                        <td><?= formatDate($pay['payment_date']) ?></td>
                        <td><?= PAYMENT_MODES[$pay['payment_mode']] ?? ucfirst($pay['payment_mode']) ?></td>
                        <td><?= $pay['transaction_id'] ? sanitizeOutput($pay['transaction_id']) : '-' ?></td>
                        <td style="text-align: right; font-weight: 700; color: #059669;"><?= formatCurrency($pay['amount']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </td>
            <td style="width: 50%; vertical-align: top;">
                <table class="totals-table" cellpadding="0" cellspacing="0">
                    <tr>
                        <td style="color: #64748b;">Subtotal</td>
                        <td style="text-align: right; font-weight: 600;"><?= formatCurrency($invoice['subtotal']) ?></td>
                    </tr>
                    <?php if ($invoice['discount_amount'] > 0): ?>
                    <tr>
                        <td style="color: #64748b;">Discount <?= !empty($invoice['discount_percent']) ? '(' . $invoice['discount_percent'] . '%)' : '' ?></td>
                        <td style="text-align: right; color: #dc2626; font-weight: 600;">-<?= formatCurrency($invoice['discount_amount']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($invoice['tax_amount'] > 0): ?>
                    <tr>
                        <td style="color: #64748b;">GST / Tax <?= !empty($invoice['tax_percent']) ? '(' . $invoice['tax_percent'] . '%)' : '' ?></td>
                        <td style="text-align: right; font-weight: 600;"><?= formatCurrency($invoice['tax_amount']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr class="total-row">
                        <td>Grand Total</td>
                        <td style="text-align: right;"><?= formatCurrency($invoice['total_amount']) ?></td>
                    </tr>
                    <tr>
                        <td style="color: #64748b; padding-top: 4px;">Paid Amount</td>
                        <td style="text-align: right; color: #059669; font-weight: 700; padding-top: 4px;"><?= formatCurrency($invoice['paid_amount']) ?></td>
                    </tr>
                    <?php if ($invoice['due_amount'] > 0): ?>
                    <tr class="due-row">
                        <td>Balance Due</td>
                        <td style="text-align: right;"><?= formatCurrency($invoice['due_amount']) ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </td>
        </tr>
    </table>

    <!-- Notes -->
    <?php if (!empty($invoice['notes'])): ?>
    <div style="margin-bottom: 24px; padding: 12px 16px; background: #f8fafc; border-radius: 6px; border-left: 3px solid var(--primary);">
        <div style="font-size: 10px; text-transform: uppercase; color: #94a3b8; font-weight: 700; letter-spacing: 0.5px; margin-bottom: 2px;">Notes</div>
        <p style="font-size: 12px; color: #334155; margin: 0;"><?= nl2br(sanitizeOutput($invoice['notes'])) ?></p>
    </div>
    <?php endif; ?>

    <!-- Signature & Disclaimer -->
    <table style="width: 100%; border-collapse: collapse; margin-top: 36px; padding-top: 16px;" cellpadding="0" cellspacing="0">
        <tr>
            <td style="vertical-align: bottom; color: #94a3b8; font-size: 11px;">
                <p>Thank you for choosing <?= sanitizeOutput($clinic['name'] ?? APP_NAME) ?>.</p>
                <p style="margin-top: 2px;">This is a computer-generated document. No signature required.</p>
            </td>
            <td style="vertical-align: bottom; text-align: right;">
                <div style="display: inline-block; text-align: center; border-top: 1px solid #cbd5e1; padding-top: 6px; min-width: 160px; font-size: 11.5px; font-weight: 600; color: #475569;">
                    Authorized Signatory
                </div>
            </td>
        </tr>
    </table>

    <div class="bottom-band"></div>
</div>

<script>
window.addEventListener('load', function() {
    setTimeout(function() {
        window.print();
    }, 250);
});
</script>

</body>
</html>
