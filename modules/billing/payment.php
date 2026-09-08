<?php
/**
 * Record Payment - Advanced Clinic Suite
 */
require_once dirname(dirname(__DIR__)) . '/config/session.php';
requireAuth();
requirePermission('billing.payments');

$db = db();
$clinicId = getCurrentClinicId();

$id = intval($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . BASE_URL . '/modules/billing/list.php'); exit; }

// Fetch invoice
$invoice = $db->fetch(
    "SELECT i.*, p.first_name, p.last_name, p.patient_uid
     FROM invoices i JOIN patients p ON i.patient_id = p.id
     WHERE i.id = ? AND i.clinic_id = ?", [$id, $clinicId]
);

if (!$invoice || $invoice['due_amount'] <= 0) {
    setFlashMessage('info', $invoice ? 'No amount due on this invoice.' : 'Invoice not found.');
    header('Location: ' . BASE_URL . '/modules/billing/list.php');
    exit;
}

// Handle payment submission — BEFORE any HTML output
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = floatval($_POST['amount'] ?? 0);
    $paymentMode = sanitize($_POST['payment_mode'] ?? 'cash');
    $transactionId = sanitize($_POST['transaction_id'] ?? '');
    $paymentDate = $_POST['payment_date'] ?? date('Y-m-d');
    $notes = sanitize($_POST['notes'] ?? '');

    if ($amount <= 0) {
        setFlashMessage('error', 'Payment amount must be greater than zero.');
    } elseif ($amount > $invoice['due_amount']) {
        setFlashMessage('error', 'Payment amount cannot exceed due amount of ' . formatCurrency($invoice['due_amount']));
    } else {
        try {
            $db->beginTransaction();

            // Insert payment record
            $db->query(
                "INSERT INTO payments (clinic_id, invoice_id, patient_id, payment_date, amount,
                 payment_mode, transaction_id, notes, received_by) VALUES (?,?,?,?,?,?,?,?,?)",
                [$clinicId, $id, $invoice['patient_id'], $paymentDate, $amount,
                 $paymentMode, $transactionId ?: null, $notes ?: null, getCurrentUserId()]
            );

            // Update invoice totals
            $newPaid = $invoice['paid_amount'] + $amount;
            $newDue = $invoice['total_amount'] - $newPaid;
            $newStatus = ($newDue <= 0) ? 'paid' : 'partial';

            $db->query(
                "UPDATE invoices SET paid_amount=?, due_amount=?, status=? WHERE id=?",
                [$newPaid, max(0, $newDue), $newStatus, $id]
            );

            logAudit('payment', 'billing', 'invoice', $id, null, null,
                     'Payment of ' . formatCurrency($amount) . ' recorded via ' . $paymentMode);

            $db->commit();
            setFlashMessage('success', 'Payment of ' . formatCurrency($amount) . ' recorded successfully.');
            header('Location: ' . BASE_URL . '/modules/billing/view.php?id=' . $id);
            exit;
        } catch (Exception $e) {
            $db->rollback();
            setFlashMessage('error', 'Error: ' . $e->getMessage());
        }
    }
}

// NOW include header — after all potential redirects
$pageTitle = 'Record Payment';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="content-header">
    <div>
        <ul class="breadcrumb">
            <li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li>
            <li><a href="<?= BASE_URL ?>/modules/billing/list.php">Billing</a></li>
            <li><a href="<?= BASE_URL ?>/modules/billing/view.php?id=<?= $id ?>">Invoice <?= sanitizeOutput($invoice['invoice_number']) ?></a></li>
            <li>Record Payment</li>
        </ul>
        <h1>Record Payment</h1>
    </div>
</div>

<div class="grid-2 gap-24">
    <!-- Invoice Summary -->
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-file-invoice" style="color: var(--primary);"></i> Invoice Summary</h3></div>
        <div class="card-body">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div>
                    <div class="text-muted" style="font-size: 12px;">Invoice #</div>
                    <div class="font-semibold"><?= sanitizeOutput($invoice['invoice_number']) ?></div>
                </div>
                <div>
                    <div class="text-muted" style="font-size: 12px;">Patient</div>
                    <div class="font-semibold"><?= sanitizeOutput($invoice['first_name'] . ' ' . $invoice['last_name']) ?></div>
                    <div style="font-size: 11px; color: var(--text-muted);"><?= sanitizeOutput($invoice['patient_uid']) ?></div>
                </div>
                <div>
                    <div class="text-muted" style="font-size: 12px;">Invoice Date</div>
                    <div><?= formatDate($invoice['invoice_date']) ?></div>
                </div>
                <div>
                    <div class="text-muted" style="font-size: 12px;">Status</div>
                    <div><?= getStatusBadge($invoice['status'], 'payment') ?></div>
                </div>
            </div>

            <hr style="margin: 20px 0; border-color: var(--border-color);">

            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px;">
                <div style="text-align: center; padding: 12px; background: var(--bg-secondary); border-radius: 8px;">
                    <div style="font-size: 18px; font-weight: 700; color: var(--primary);"><?= formatCurrency($invoice['total_amount']) ?></div>
                    <div class="text-muted" style="font-size: 11px;">Total</div>
                </div>
                <div style="text-align: center; padding: 12px; background: var(--bg-secondary); border-radius: 8px;">
                    <div style="font-size: 18px; font-weight: 700; color: var(--success);"><?= formatCurrency($invoice['paid_amount']) ?></div>
                    <div class="text-muted" style="font-size: 11px;">Paid</div>
                </div>
                <div style="text-align: center; padding: 12px; background: rgba(239,68,68,0.1); border-radius: 8px; border: 1px solid rgba(239,68,68,0.2);">
                    <div style="font-size: 18px; font-weight: 700; color: var(--danger);"><?= formatCurrency($invoice['due_amount']) ?></div>
                    <div class="text-muted" style="font-size: 11px;">Due</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Payment Form -->
    <form method="POST" action="" class="card">
        <div class="card-header"><h3><i class="fas fa-rupee-sign" style="color: var(--success);"></i> Payment Details</h3></div>
        <div class="card-body">
            <div class="form-group mb-16">
                <label class="form-label">Amount <span class="required">*</span></label>
                <input type="number" name="amount" class="form-control" required min="0.01" step="0.01"
                       max="<?= $invoice['due_amount'] ?>" value="<?= $invoice['due_amount'] ?>"
                       style="font-size: 20px; font-weight: 700; text-align: center;">
            </div>

            <div class="form-group mb-16">
                <label class="form-label">Payment Mode <span class="required">*</span></label>
                <select name="payment_mode" id="payMode" class="form-control" required onchange="toggleRef()">
                    <?php foreach (PAYMENT_MODES as $k => $v): ?>
                    <option value="<?= $k ?>"><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group mb-16" id="refGroup" style="display:none;">
                <label class="form-label">Reference / Transaction No.</label>
                <input type="text" name="transaction_id" class="form-control" placeholder="UPI ref / Card last 4 / Cheque #">
            </div>

            <div class="form-group mb-16">
                <label class="form-label">Payment Date</label>
                <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>

            <div class="form-group mb-16">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2" placeholder="Optional payment notes"></textarea>
            </div>
        </div>
        <div class="card-footer d-flex justify-end gap-12">
            <a href="<?= BASE_URL ?>/modules/billing/view.php?id=<?= $id ?>" class="btn btn-outline">Cancel</a>
            <button type="submit" class="btn btn-success">
                <i class="fas fa-check-circle"></i> Record Payment
            </button>
        </div>
    </form>
</div>

<script>
function toggleRef() {
    var mode = document.getElementById('payMode').value;
    document.getElementById('refGroup').style.display = (mode === 'cash') ? 'none' : 'block';
}
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
