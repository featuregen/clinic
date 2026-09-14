<?php
/**
 * Subscription Payment Receipt - Feature Gen Care
 * Super Admin Only
 */
require_once dirname(dirname(__DIR__)) . '/config/session.php';
requireAuth();
requireRole([ROLE_SUPER_ADMIN, ROLE_ADMIN]);

$paymentId = intval($_GET['id'] ?? 0);
if (!$paymentId) {
    die("Invalid Payment ID.");
}

$currentUserRole = getCurrentUserRole();
$currentTenantId = intval(db()->tenantInfo['id'] ?? 0);

$master = master_db();
$stmt = $master->prepare("
    SELECT p.*, t.clinic_name, t.subdomain, t.plan_type as current_plan, t.max_doctors as current_max_doctors
    FROM tenant_subscription_payments p
    JOIN tenants t ON p.tenant_id = t.id
    WHERE p.id = ?
");
$stmt->execute([$paymentId]);
$payment = $stmt->fetch();

if (!$payment) {
    die("Payment record not found.");
}

// Security: If not Super Admin, ensure receipt belongs to current clinic tenant
if ($currentUserRole !== ROLE_SUPER_ADMIN && intval($payment['tenant_id']) !== $currentTenantId) {
    die("Access denied: You can only view receipts for your own clinic.");
}

$modeLabels = [
    'cash_on_hand' => 'Cash on Hand (Direct Handover)',
    'bank_transfer' => 'Direct Bank Transfer / NEFT / IMPS',
    'upi' => 'UPI Transfer (GPay / PhonePe / Paytm)',
    'cheque' => 'Cheque / Demand Draft',
    'razorpay' => 'Razorpay Online Gateway'
];
$modeLabel = $modeLabels[$payment['payment_mode']] ?? ucfirst(str_replace('_', ' ', $payment['payment_mode']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subscription Receipt - <?= sanitizeOutput($payment['payment_reference'] ?: ('REC-' . $payment['id'])) ?> - Feature Gen Care</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, Roboto, sans-serif; }
        body { background: #f3f4f6; padding: 30px 15px; color: #1f2937; }
        .receipt-card {
            max-width: 720px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.08);
            border: 1px solid #e5e7eb;
            overflow: hidden;
            position: relative;
        }
        .receipt-header {
            background: linear-gradient(135deg, #0097a7, #00695c);
            color: #ffffff;
            padding: 28px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .brand-title { font-size: 22px; font-weight: 800; letter-spacing: 0.5px; }
        .brand-subtitle { font-size: 12px; opacity: 0.85; margin-top: 2px; }
        .receipt-badge {
            background: rgba(255,255,255,0.2);
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .receipt-body { padding: 32px; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px; }
        .info-group label { display: block; font-size: 11px; text-transform: uppercase; color: #6b7280; font-weight: 700; margin-bottom: 4px; }
        .info-group p { font-size: 14px; color: #111827; font-weight: 600; }
        .table-receipt {
            width: 100%;
            border-collapse: collapse;
            margin: 24px 0;
        }
        .table-receipt th {
            background: #f9fafb;
            text-align: left;
            padding: 12px 16px;
            font-size: 12px;
            text-transform: uppercase;
            color: #4b5563;
            border-bottom: 1px solid #e5e7eb;
        }
        .table-receipt td {
            padding: 14px 16px;
            border-bottom: 1px solid #f3f4f6;
            font-size: 14px;
        }
        .amount-highlight {
            font-size: 24px;
            font-weight: 800;
            color: #00838f;
        }
        .stamp-box {
            border: 2px dashed #059669;
            color: #059669;
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 800;
            text-transform: uppercase;
            font-size: 14px;
            display: inline-block;
            transform: rotate(-4deg);
        }
        .receipt-footer {
            background: #f9fafb;
            padding: 20px 32px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
            color: #6b7280;
        }
        .actions-bar {
            max-width: 720px;
            margin: 20px auto 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }
        .btn-primary { background: #00838f; color: white; }
        .btn-primary:hover { background: #00695c; }
        .btn-outline { background: white; border: 1px solid #d1d5db; color: #374151; }
        .btn-outline:hover { background: #f3f4f6; }
        
        @media print {
            body { background: white; padding: 0; }
            .receipt-card { box-shadow: none; border: 1px solid #ccc; max-width: 100%; }
            .actions-bar { display: none; }
        }
    </style>
</head>
<body>

<div class="actions-bar">
    <?php if ($currentUserRole === ROLE_SUPER_ADMIN): ?>
    <a href="<?= BASE_URL ?>/modules/admin/subscriptions.php" class="btn btn-outline">
        <i class="fas fa-arrow-left"></i> Back to Subscriptions
    </a>
    <?php else: ?>
    <a href="<?= BASE_URL ?>/modules/subscription/paywall.php" class="btn btn-outline">
        <i class="fas fa-arrow-left"></i> Back to Plan & Billing
    </a>
    <?php endif; ?>
    <button onclick="window.print()" class="btn btn-primary">
        <i class="fas fa-print"></i> Print Official Receipt
    </button>
</div>

<div class="receipt-card" style="margin-top: 16px;">
    <div class="receipt-header">
        <div>
            <div class="brand-title"><i class="fas fa-heartbeat"></i> Feature Gen Care</div>
            <div class="brand-subtitle">Cloud Clinic Management Platform &bull; Official Subscription Receipt</div>
        </div>
        <div class="receipt-badge">
            <i class="fas fa-check-circle"></i> Official Receipt
        </div>
    </div>
    
    <div class="receipt-body">
        <div class="grid-2">
            <div>
                <div class="info-group">
                    <label>Billed To (Clinic)</label>
                    <p style="font-size: 16px; color: #00838f;"><?= sanitizeOutput($payment['clinic_name']) ?></p>
                    <p style="font-size: 13px; font-weight: 500; color: #6b7280; margin-top: 2px;">
                        Portal: <strong><?= sanitizeOutput($payment['subdomain']) ?></strong>.featuregen.com
                    </p>
                </div>
            </div>
            <div>
                <div class="info-group">
                    <label>Receipt Number</label>
                    <p><?= sanitizeOutput($payment['payment_reference'] ?: ('REC-' . date('Y') . '-' . str_pad($payment['id'], 4, '0', STR_PAD_LEFT))) ?></p>
                </div>
                <div class="info-group" style="margin-top: 12px;">
                    <label>Date of Payment</label>
                    <p><?= date('d F Y, h:i A', strtotime($payment['created_at'])) ?></p>
                </div>
            </div>
        </div>
        
        <div class="grid-2" style="background: #f9fafb; padding: 16px; border-radius: 8px; border: 1px solid #f3f4f6;">
            <div class="info-group">
                <label>Payment Mode</label>
                <p><i class="fas fa-money-bill-wave" style="color: #059669; margin-right: 4px;"></i> <?= $modeLabel ?></p>
            </div>
            <div class="info-group">
                <label>Collected By</label>
                <p><?= sanitizeOutput($payment['collected_by'] ?: 'Super Administrator') ?></p>
            </div>
        </div>
        
        <table class="table-receipt">
            <thead>
                <tr>
                    <th>Subscription Plan Details</th>
                    <th>Doctor Quota</th>
                    <th>Validity Period</th>
                    <th style="text-align: right;">Total Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <strong style="text-transform: capitalize;"><?= sanitizeOutput($payment['plan_type']) ?> Plan</strong>
                        <?php if ($payment['bonus_months_granted'] > 0): ?>
                        <div style="font-size: 12px; color: #059669; font-weight: 600; margin-top: 2px;">
                            +<?= $payment['bonus_months_granted'] ?> Bonus Months Included
                        </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <strong><?= $payment['doctor_limit_granted'] > 0 ? $payment['doctor_limit_granted'] . ' Doctors' : 'Unlimited' ?></strong>
                    </td>
                    <td>
                        <?php if ($payment['plan_type'] === 'one_time' || empty($payment['period_end'])): ?>
                            <span style="color: #059669; font-weight: 700;"><i class="fas fa-infinity"></i> Lifetime Perpetual</span>
                        <?php else: ?>
                            <?= date('d M Y', strtotime($payment['period_start'])) ?> &rarr; <strong><?= date('d M Y', strtotime($payment['period_end'])) ?></strong>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: right;">
                        <span class="amount-highlight">₹<?= number_format($payment['amount'], 2) ?></span>
                    </td>
                </tr>
            </tbody>
        </table>
        
        <?php if (!empty($payment['notes'])): ?>
        <div style="margin-bottom: 24px; padding: 12px 16px; background: #fffbeb; border-radius: 6px; border-left: 3px solid #f59e0b; font-size: 13px;">
            <strong>Payment Notes:</strong> <?= nl2br(sanitizeOutput($payment['notes'])) ?>
        </div>
        <?php endif; ?>
        
        <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-top: 36px; padding-top: 20px; border-top: 1px solid #e5e7eb;">
            <div class="stamp-box">
                <i class="fas fa-check-circle"></i> PAYMENT RECEIVED - PAID IN FULL
            </div>
            <div style="text-align: right;">
                <div style="height: 40px; font-style: italic; font-family: cursive; font-size: 18px; color: #374151;">
                    Feature Gen Care
                </div>
                <div style="border-top: 1px solid #9ca3af; width: 180px; margin-left: auto; padding-top: 4px; font-size: 11px; color: #6b7280; font-weight: 600;">
                    Authorized Signatory
                </div>
            </div>
        </div>
    </div>
    
    <div class="receipt-footer">
        <div>Thank you for choosing Feature Gen Care. This is a computer-generated receipt.</div>
        <div>Support: support@featuregen.com</div>
    </div>
</div>

</body>
</html>
