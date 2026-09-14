<?php
/**
 * Subscription Payment Receipt & Tax Invoice - Feature Gen Care
 * Super Admin & Clinic Admin
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
    SELECT p.*, t.clinic_name, t.subdomain, t.plan_type as current_plan, t.max_doctors as current_max_doctors,
           t.gst_number as clinic_gstin, t.pan_number as clinic_pan, t.billing_address as clinic_address,
           t.db_name as tenant_db_name
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

// Fetch Global SaaS Platform Legal & GST details
$platformSettings = [];
try {
    $settingsStmt = $master->query("SELECT setting_key, setting_value FROM saas_global_settings");
    if ($settingsStmt) {
        $platformSettings = $settingsStmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }
} catch (Exception $e) {}

$platformName = !empty($platformSettings['platform_company_name']) ? $platformSettings['platform_company_name'] : 'Feature Gen Technologies';
$platformGstin = !empty($platformSettings['platform_gstin']) ? strtoupper(trim($platformSettings['platform_gstin'])) : '';
$platformPan = !empty($platformSettings['platform_pan']) ? strtoupper(trim($platformSettings['platform_pan'])) : '';
$platformAddress = !empty($platformSettings['platform_address']) ? $platformSettings['platform_address'] : 'Chennai, Tamil Nadu, India';
$platformState = !empty($platformSettings['platform_state']) ? $platformSettings['platform_state'] : 'Tamil Nadu (State Code: 33)';

// Fallback: If clinic GST or Address is not populated on tenants table, check the clinic tenant database directly
if (empty($payment['clinic_gstin']) || empty($payment['clinic_address'])) {
    try {
        $tenantDbName = $payment['tenant_db_name'] ?? ('clinic_' . $payment['subdomain']);
        $tHost = DB_HOST;
        $tUser = DB_USER;
        $tPass = DB_PASS;
        $tPdo = new PDO("mysql:host={$tHost};dbname={$tenantDbName};charset=utf8mb4", $tUser, $tPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        $cRow = $tPdo->query("SELECT address, city, state, pincode, pan_number, gst_number FROM clinics LIMIT 1")->fetch();
        if ($cRow) {
            if (empty($payment['clinic_gstin']) && !empty($cRow['gst_number'])) {
                $payment['clinic_gstin'] = strtoupper(trim($cRow['gst_number']));
            }
            if (empty($payment['clinic_pan']) && !empty($cRow['pan_number'])) {
                $payment['clinic_pan'] = strtoupper(trim($cRow['pan_number']));
            }
            if (empty($payment['clinic_address'])) {
                $addrParts = array_filter([$cRow['address'] ?? '', $cRow['city'] ?? '', $cRow['state'] ?? '', $cRow['pincode'] ?? '']);
                if (!empty($addrParts)) {
                    $payment['clinic_address'] = implode(', ', $addrParts);
                }
            }
        }
    } catch (Exception $e) {
        // Graceful fallback
    }
}

$modeLabels = [
    'cash_on_hand' => 'COD (Cash on Hand / Direct Handover)',
    'razorpay' => 'Razorpay Online Gateway'
];
$modeLabel = $modeLabels[$payment['payment_mode']] ?? ucfirst(str_replace('_', ' ', $payment['payment_mode']));

// Option A: Indian SaaS GST Calculation (SAC 998314 - 18% GST)
$totalPaid = floatval($payment['amount'] ?? 0);
$baseAmount = !empty($payment['base_amount']) && floatval($payment['base_amount']) > 0 
    ? floatval($payment['base_amount']) 
    : round($totalPaid / 1.18, 2);

$gstRate = !empty($payment['gst_rate']) && floatval($payment['gst_rate']) > 0 
    ? floatval($payment['gst_rate']) 
    : 18.00;

$gstAmount = !empty($payment['gst_amount']) && floatval($payment['gst_amount']) > 0 
    ? floatval($payment['gst_amount']) 
    : round($totalPaid - $baseAmount, 2);

$cgstRate = round($gstRate / 2, 2); // 9%
$sgstRate = round($gstRate / 2, 2); // 9%
$cgstAmount = round($gstAmount / 2, 2);
$sgstAmount = round($gstAmount - $cgstAmount, 2);

// Indian Rupee in Words Helper
function formatRupeesInWords(float $number): string {
    $decimal = round($number - ($no = floor($number)), 2) * 100;
    $digits_length = strlen((string)$no);
    $i = 0;
    $str = array();
    $words = array(
        0 => '', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four', 5 => 'Five', 
        6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine', 10 => 'Ten', 
        11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen', 14 => 'Fourteen', 
        15 => 'Fifteen', 16 => 'Sixteen', 17 => 'Seventeen', 18 => 'Eighteen', 
        19 => 'Nineteen', 20 => 'Twenty', 30 => 'Thirty', 40 => 'Forty', 
        50 => 'Fifty', 60 => 'Sixty', 70 => 'Seventy', 80 => 'Eighty', 
        90 => 'Ninety'
    );
    $digits = array('', 'Hundred', 'Thousand', 'Lakh', 'Crore');
    while ($i < $digits_length) {
        $divider = ($i == 2) ? 10 : 100;
        $number = floor($no % $divider);
        $no = floor($no / $divider);
        $i += $divider == 10 ? 1 : 2;
        if ($number) {
            $counter = count($str);
            $plural = ($counter && $number > 9) ? 's' : null;
            $hundred = ($counter == 1 && !empty($str[0])) ? ' and ' : null;
            $str[] = ($number < 21) ? $words[$number] . ' ' . $digits[$counter] . $plural . ' ' . $hundred
                : $words[floor($number / 10) * 10] . ' ' . $words[$number % 10] . ' ' . $digits[$counter] . $plural . ' ' . $hundred;
        } else {
            $str[] = null;
        }
    }
    $str = array_reverse($str);
    $result = trim(implode('', array_filter($str)));
    $points = ($decimal > 0) ? " and " . ($words[floor($decimal / 10) * 10] . " " . $words[$decimal % 10]) . ' Paise' : '';
    return 'Rupees ' . ($result ?: 'Zero') . $points . ' Only';
}

$amountInWords = formatRupeesInWords($totalPaid);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tax Invoice - <?= sanitizeOutput($payment['payment_reference'] ?: ('REC-' . $payment['id'])) ?> - Feature Gen Care</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, Roboto, sans-serif; }
        body { background: #f3f4f6; padding: 30px 15px; color: #1f2937; }
        .receipt-card {
            max-width: 800px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.08);
            border: 1px solid #e5e7eb;
            overflow: hidden;
            position: relative;
        }
        .receipt-header {
            background: linear-gradient(135deg, #00838f, #004d40);
            color: #ffffff;
            padding: 24px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .brand-title { font-size: 22px; font-weight: 800; letter-spacing: 0.5px; display: flex; align-items: center; gap: 10px; }
        .brand-subtitle { font-size: 12px; opacity: 0.9; margin-top: 4px; }
        .receipt-badge {
            background: rgba(255,255,255,0.2);
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border: 1px solid rgba(255,255,255,0.3);
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .receipt-body { padding: 32px; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
        .info-group label { display: block; font-size: 11px; text-transform: uppercase; color: #6b7280; font-weight: 700; margin-bottom: 4px; }
        .info-group p { font-size: 14px; color: #111827; font-weight: 600; }
        .table-receipt {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0 16px;
        }
        .table-receipt th {
            background: #f8fafc;
            text-align: left;
            padding: 12px 14px;
            font-size: 11px;
            text-transform: uppercase;
            color: #475569;
            border-bottom: 1px solid #e2e8f0;
            letter-spacing: 0.5px;
        }
        .table-receipt td {
            padding: 14px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 13px;
        }
        .amount-highlight {
            font-size: 22px;
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
            font-size: 12px;
            display: inline-block;
            transform: rotate(-2deg);
        }
        .receipt-footer {
            background: #f8fafc;
            padding: 18px 32px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 11px;
            color: #64748b;
        }
        .actions-bar {
            max-width: 800px;
            margin: 20px auto 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .btn {
            padding: 10px 18px;
            border-radius: 8px;
            font-size: 13px;
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
        
        @page {
            size: A4 portrait;
            margin: 10mm;
        }
        @media print {
            body { background: white !important; padding: 0 !important; }
            .receipt-card { box-shadow: none !important; border: 1px solid #cbd5e1 !important; max-width: 100% !important; margin: 0 !important; }
            .actions-bar { display: none !important; }
            .receipt-header { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .receipt-footer { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .entity-card { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .stamp-box { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
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
    <div style="display: flex; gap: 8px;">
        <button onclick="window.print()" class="btn btn-primary">
            <i class="fas fa-print"></i> Print / Save as PDF
        </button>
    </div>
</div>

<div class="receipt-card" style="margin-top: 16px;">
    <!-- Top Header Banner -->
    <div class="receipt-header">
        <div>
            <div class="brand-title">
                <i class="fas fa-file-invoice-dollar"></i> TAX INVOICE & RECEIPT
            </div>
            <div class="brand-subtitle">
                <?= sanitizeOutput($platformName) ?> &bull; Feature Gen Care Cloud Clinic Suite
            </div>
        </div>
        <div class="receipt-badge">
            <i class="fas fa-check-circle"></i> PAID IN FULL
        </div>
    </div>
    
    <div class="receipt-body">
        <!-- Invoice Metadata Row -->
        <div class="grid-2" style="background: #f8fafc; padding: 14px 18px; border-radius: 8px; border: 1px solid #e2e8f0; margin-bottom: 20px;">
            <div class="info-group">
                <label>Tax Invoice / Receipt No.</label>
                <p style="font-size: 15px; color: #00838f; font-family: monospace; letter-spacing: 0.5px;">
                    <?= sanitizeOutput($payment['payment_reference'] ?: ('REC-' . date('Y') . '-' . str_pad($payment['id'], 4, '0', STR_PAD_LEFT))) ?>
                </p>
                <div style="font-size: 11px; color: #64748b; margin-top: 2px;">
                    Service Accounting Code: <strong>SAC 998314</strong>
                </div>
            </div>
            <div class="info-group" style="text-align: right;">
                <label>Date & Time of Issue</label>
                <p><?= date('d F Y, h:i A', strtotime($payment['created_at'])) ?></p>
                <div style="font-size: 11px; color: #059669; font-weight: 700; margin-top: 2px;">
                    <i class="fas fa-shield-alt"></i> <?= $modeLabel ?>
                </div>
            </div>
        </div>

        <!-- DUAL ENTITY CARDS: BILLED BY (SUPPLIER) vs BILLED TO (RECIPIENT) -->
        <div class="grid-2" style="gap: 16px; margin-bottom: 20px;">
            <!-- Supplier: Feature Gen Technologies -->
            <div class="entity-card" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <span style="font-size: 11px; font-weight: 800; text-transform: uppercase; color: #0284c7; letter-spacing: 0.5px;">
                        <i class="fas fa-building"></i> BILLED BY (SUPPLIER)
                    </span>
                    <span style="font-size: 10px; font-weight: 700; background: #e0f2fe; color: #0369a1; padding: 2px 6px; border-radius: 4px;">
                        SaaS Platform
                    </span>
                </div>
                <div style="font-size: 14px; font-weight: 800; color: #0f172a; margin-bottom: 4px;">
                    <?= sanitizeOutput($platformName) ?>
                </div>
                <div style="font-size: 12px; color: #475569; margin-bottom: 8px; line-height: 1.4;">
                    <?= nl2br(sanitizeOutput($platformAddress)) ?>
                    <?php if (!empty($platformState)): ?>
                        <div style="margin-top: 2px;">State: <strong><?= sanitizeOutput($platformState) ?></strong></div>
                    <?php endif; ?>
                </div>
                <div style="padding-top: 8px; border-top: 1px dashed #cbd5e1; font-size: 12px; display: flex; flex-direction: column; gap: 3px;">
                    <div>
                        <span style="color: #64748b; font-weight: 600;">Supplier GSTIN:</span>
                        <?php if (!empty($platformGstin)): ?>
                            <strong style="color: #0f172a; font-family: monospace; font-size: 12.5px; letter-spacing: 0.5px;"><?= sanitizeOutput($platformGstin) ?></strong>
                        <?php else: ?>
                            <span style="color: #64748b; font-size: 11px; font-style: italic;">(Pending Global Config)</span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($platformPan)): ?>
                    <div>
                        <span style="color: #64748b; font-weight: 600;">Supplier PAN:</span>
                        <strong style="color: #0f172a; font-family: monospace; font-size: 12px;"><?= sanitizeOutput($platformPan) ?></strong>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recipient: Client Clinic -->
            <div class="entity-card" style="background: #f0fdfa; border: 1px solid #ccfbf1; border-radius: 8px; padding: 16px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <span style="font-size: 11px; font-weight: 800; text-transform: uppercase; color: #0d9488; letter-spacing: 0.5px;">
                        <i class="fas fa-hospital-user"></i> BILLED TO (RECIPIENT)
                    </span>
                    <?php if (!empty($payment['clinic_gstin'])): ?>
                    <span style="font-size: 10px; font-weight: 700; background: #d1fae5; color: #065f46; padding: 2px 6px; border-radius: 4px;">
                        <i class="fas fa-check-circle"></i> B2B Registered
                    </span>
                    <?php else: ?>
                    <span style="font-size: 10px; font-weight: 700; background: #f1f5f9; color: #64748b; padding: 2px 6px; border-radius: 4px;">
                        B2C Unregistered
                    </span>
                    <?php endif; ?>
                </div>
                <div style="font-size: 14px; font-weight: 800; color: #134e4a; margin-bottom: 4px;">
                    <?= sanitizeOutput($payment['clinic_name']) ?>
                </div>
                <div style="font-size: 12px; color: #475569; margin-bottom: 8px; line-height: 1.4;">
                    <?php if (!empty($payment['clinic_address'])): ?>
                        <?= nl2br(sanitizeOutput($payment['clinic_address'])) ?>
                    <?php else: ?>
                        <span style="color: #64748b; font-style: italic;">Clinic Address on Record</span>
                    <?php endif; ?>
                    <div style="color: #0d9488; font-weight: 600; margin-top: 2px;">
                        Portal: <strong><?= sanitizeOutput($payment['subdomain']) ?></strong>.featuregen.com
                    </div>
                </div>
                <div style="padding-top: 8px; border-top: 1px dashed #99f6e4; font-size: 12px; display: flex; flex-direction: column; gap: 3px;">
                    <div>
                        <span style="color: #64748b; font-weight: 600;">Recipient GSTIN:</span>
                        <?php if (!empty($payment['clinic_gstin'])): ?>
                            <strong style="color: #047857; font-family: monospace; font-size: 12.5px; letter-spacing: 0.5px;"><?= sanitizeOutput($payment['clinic_gstin']) ?></strong>
                        <?php else: ?>
                            <span style="color: #64748b; font-style: italic; font-weight: 600;">Unregistered (B2C)</span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($payment['clinic_pan'])): ?>
                    <div>
                        <span style="color: #64748b; font-weight: 600;">Recipient PAN:</span>
                        <strong style="color: #134e4a; font-family: monospace; font-size: 12px;"><?= sanitizeOutput($payment['clinic_pan']) ?></strong>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Item Table -->
        <table class="table-receipt">
            <thead>
                <tr>
                    <th>Item Description</th>
                    <th>SAC</th>
                    <th>Doctor Quota</th>
                    <th>Validity Period</th>
                    <th style="text-align: right;">Taxable Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <?php if ($payment['plan_type'] === 'doctor_addon'): ?>
                        <strong style="font-size: 13px; color: #0891b2;">Doctor Capacity Add-On Pack</strong>
                        <div style="font-size: 12px; color: #6b7280; margin-top: 2px;">
                            +<?= intval($payment['doctor_limit_granted']) ?> Additional Doctor Slot(s) for Feature Gen Care
                        </div>
                        <div style="font-size: 11px; color: #0891b2; font-weight: 600; margin-top: 2px;">
                            <i class="fas fa-bolt"></i> Co-termed with active clinic subscription
                        </div>
                        <?php else: ?>
                        <strong style="text-transform: capitalize; font-size: 13px; color: #111827;"><?= sanitizeOutput($payment['plan_type']) ?> Plan Subscription</strong>
                        <div style="font-size: 12px; color: #6b7280; margin-top: 2px;">
                            Feature Gen Care Cloud Healthcare Suite
                        </div>
                        <?php if ($payment['bonus_months_granted'] > 0): ?>
                        <div style="font-size: 11px; color: #059669; font-weight: 600; margin-top: 2px;">
                            <i class="fas fa-gift"></i> +<?= $payment['bonus_months_granted'] ?> Bonus Months Included
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span style="font-weight: 700; color: #475569; background: #f1f5f9; padding: 2px 7px; border-radius: 4px; font-size: 11.5px; font-family: monospace;">998314</span>
                    </td>
                    <td>
                        <?php if ($payment['plan_type'] === 'doctor_addon'): ?>
                        <strong style="color: #0891b2;">+<?= $payment['doctor_limit_granted'] ?> Extra Slots</strong>
                        <?php else: ?>
                        <strong><?= $payment['doctor_limit_granted'] > 0 ? $payment['doctor_limit_granted'] . ' Doctors' : 'Unlimited' ?></strong>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($payment['plan_type'] === 'one_time' || empty($payment['period_end'])): ?>
                            <span style="color: #059669; font-weight: 700;"><i class="fas fa-infinity"></i> Lifetime Perpetual</span>
                        <?php elseif ($payment['plan_type'] === 'doctor_addon'): ?>
                            Co-termed until<br><strong><?= date('d M Y', strtotime($payment['period_end'])) ?></strong>
                        <?php else: ?>
                            <?= date('d M Y', strtotime($payment['period_start'])) ?> &rarr;<br><strong><?= date('d M Y', strtotime($payment['period_end'])) ?></strong>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: right; font-weight: 700; font-size: 14px; color: #111827;">
                        ₹<?= number_format($baseAmount, 2) ?>
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- Indian B2B SaaS Tax Computation & Words Section -->
        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; margin: 20px 0 24px; flex-wrap: wrap;">
            <!-- Left Box: Amount in words and ITC eligibility -->
            <div style="flex: 1; min-width: 280px;">
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 16px; margin-bottom: 12px;">
                    <div style="font-size: 11px; text-transform: uppercase; color: #64748b; font-weight: 700; margin-bottom: 4px;">
                        Amount Chargeable in Words:
                    </div>
                    <div style="font-size: 13px; font-weight: 700; color: #0f172a; line-height: 1.4;">
                        <?= sanitizeOutput($amountInWords) ?>
                    </div>
                </div>
                
                <div style="font-size: 11px; color: #475569; line-height: 1.5; background: #fff; border-left: 3px solid #0284c7; padding: 8px 12px; border-radius: 0 6px 6px 0; border-top: 1px solid #f1f5f9; border-right: 1px solid #f1f5f9; border-bottom: 1px solid #f1f5f9;">
                    <strong>SAC 998314:</strong> Information Technology (IT) Software as a Service (SaaS).<br>
                    <?php if (!empty($payment['clinic_gstin'])): ?>
                        <span style="color: #047857; font-weight: 600;"><i class="fas fa-check-circle"></i> Input Tax Credit (ITC) is eligible for recipient GSTIN under GSTR-2B.</span>
                    <?php else: ?>
                        <span>Recipient is unregistered under GST law; supply treated as B2C transaction.</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right Box: Tax Breakdown and Grand Total -->
            <div style="width: 330px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px;">
                <div style="display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 8px; color: #4b5563;">
                    <span>Taxable Base Value:</span>
                    <strong style="color: #111827;">₹<?= number_format($baseAmount, 2) ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 6px; color: #4b5563;">
                    <span>CGST @ <?= number_format($cgstRate, 1) ?>%:</span>
                    <span>₹<?= number_format($cgstAmount, 2) ?></span>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 8px; color: #4b5563;">
                    <span>SGST @ <?= number_format($sgstRate, 1) ?>%:</span>
                    <span>₹<?= number_format($sgstAmount, 2) ?></span>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 10px; color: #0284c7; padding-bottom: 8px; border-bottom: 1px dashed #cbd5e1;">
                    <span>Total 18% GST (SAC 998314):</span>
                    <span style="font-weight: 700;">₹<?= number_format($gstAmount, 2) ?></span>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; padding-top: 4px;">
                    <span style="font-size: 14px; font-weight: 800; color: #111827;">Grand Total Paid:</span>
                    <span class="amount-highlight">₹<?= number_format($totalPaid, 2) ?></span>
                </div>
            </div>
        </div>
        
        <?php if (!empty($payment['notes'])): ?>
        <div style="margin-bottom: 20px; padding: 12px 16px; background: #fffbeb; border-radius: 6px; border-left: 3px solid #f59e0b; font-size: 12.5px;">
            <strong>Payment Notes:</strong> <?= nl2br(sanitizeOutput($payment['notes'])) ?>
        </div>
        <?php endif; ?>
        
        <!-- Signatory & Official Stamp -->
        <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-top: 20px; padding-top: 20px; border-top: 1px solid #e5e7eb;">
            <div class="stamp-box">
                <i class="fas fa-check-circle"></i> PAYMENT RECEIVED &bull; VERIFIED
            </div>
            <div style="text-align: right;">
                <div style="height: 36px; font-style: italic; font-family: cursive; font-size: 18px; color: #374151;">
                    <?= sanitizeOutput($platformName) ?>
                </div>
                <div style="border-top: 1px solid #9ca3af; width: 200px; margin-left: auto; padding-top: 4px; font-size: 11px; color: #6b7280; font-weight: 600;">
                    Authorized Signatory
                </div>
            </div>
        </div>
    </div>
    
    <!-- Footer Note -->
    <div class="receipt-footer">
        <div>Feature Gen Care is an enterprise product of <?= sanitizeOutput($platformName) ?>. Computer-generated tax invoice.</div>
        <div>Support: support@featuregen.com</div>
    </div>
</div>

</body>
</html>
