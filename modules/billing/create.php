<?php
/**
 * Create Invoice - Advanced Clinic Suite
 */
require_once dirname(dirname(__DIR__)) . '/config/session.php';
requireAuth();
requirePermission('billing.create');

$db = db();
$clinicId = getCurrentClinicId();

$patientId = intval($_REQUEST['patient_id'] ?? 0);
$prefilledPatient = $patientId ? $db->fetch("SELECT * FROM patients WHERE id = ? AND clinic_id = ?", [$patientId, $clinicId]) : null;

// Handle POST BEFORE any output
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db->beginTransaction();
        
        $patientId = intval($_POST['patient_id']);
        $invoiceNumber = generateInvoiceNumber();
        $subtotal = 0;
        
        // Calculate items total 
        $itemNames = $_POST['item_name'] ?? [];
        $itemQtys  = $_POST['item_qty']  ?? [];
        $itemRates = $_POST['item_rate'] ?? [];
        
        foreach ($itemNames as $i => $name) {
            if (empty($name)) continue;
            $subtotal += floatval($itemQtys[$i] ?? 1) * floatval($itemRates[$i] ?? 0);
        }
        
        $discountPercent = floatval($_POST['discount_percent'] ?? 0);
        $discountAmount  = ($subtotal * $discountPercent) / 100;
        
        $gstEnabled    = !empty($_POST['gst_enabled']);
        $gstPercent    = $gstEnabled ? floatval($_POST['gst_percent'] ?? DEFAULT_GST_RATE) : 0;
        $taxableAmount = $subtotal - $discountAmount;
        $gstAmount     = ($taxableAmount * $gstPercent) / 100;
        $totalAmount   = $taxableAmount + $gstAmount;
        
        // Create invoice
        $paidAmount = floatval($_POST['paid_amount'] ?? 0);
        if ($paidAmount > $totalAmount) $paidAmount = $totalAmount;
        $dueAmount = $totalAmount - $paidAmount;
        $invoiceStatus = ($paidAmount >= $totalAmount) ? 'paid' : (($paidAmount > 0) ? 'partial' : 'due');
        $paymentMode = sanitize($_POST['payment_mode'] ?? 'cash');
        $transactionId = sanitize($_POST['transaction_id'] ?? '');

        $db->query(
            "INSERT INTO invoices (clinic_id, patient_id, invoice_number, invoice_date, subtotal, discount_percent, discount_amount, tax_percent, tax_amount, total_amount, paid_amount, due_amount, status, payment_mode, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$clinicId, $patientId, $invoiceNumber, date('Y-m-d'), $subtotal, $discountPercent, $discountAmount, $gstPercent, $gstAmount, $totalAmount, $paidAmount, $dueAmount, $invoiceStatus, $paymentMode, sanitize($_POST['notes'] ?? ''), getCurrentUserId()]
        );
        $invoiceId = $db->lastInsertId();
        
        // Insert items
        foreach ($itemNames as $i => $name) {
            if (empty($name)) continue;
            $qty  = floatval($itemQtys[$i] ?? 1);
            $rate = floatval($itemRates[$i] ?? 0);
            $db->query(
                "INSERT INTO invoice_items (invoice_id, item_name, item_type, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?)",
                [$invoiceId, sanitize($name), sanitize($_POST['item_type'][$i] ?? 'service'), $qty, $rate, $qty * $rate]
            );
        }

        // Auto-create payment record if paid
        if ($paidAmount > 0) {
            $db->query(
                "INSERT INTO payments (clinic_id, invoice_id, patient_id, payment_date, amount, payment_mode, transaction_id, received_by) VALUES (?,?,?,?,?,?,?,?)",
                [$clinicId, $invoiceId, $patientId, date('Y-m-d'), $paidAmount, $paymentMode, $transactionId ?: null, getCurrentUserId()]
            );
        }

        // Mark linked appointment fees as collected
        $appointmentIds = array_filter(array_map('intval', $_POST['appointment_ids'] ?? []));
        foreach ($appointmentIds as $apptId) {
            $db->query(
                "UPDATE appointments SET fee_status = 'paid' WHERE id = ? AND clinic_id = ?",
                [$apptId, $clinicId]
            );
        }
        
        $db->commit();
        logAudit('create', 'billing', 'invoice', $invoiceId);
        setFlashMessage('success', "Invoice $invoiceNumber created successfully." . ($paidAmount > 0 ? " Payment of " . CURRENCY_SYMBOL . " " . number_format($paidAmount, 2) . " recorded." : ''));
        header("Location: " . BASE_URL . "/modules/billing/view.php?id=$invoiceId");
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        setFlashMessage('error', 'Error: ' . $e->getMessage());
    }
}

// NOW include header — after any potential redirect
$pageTitle = 'Create Invoice';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';

// Load all patients for dropdown (when no prefilled patient)
if (!$prefilledPatient) {
    $patients = $db->fetchAll("SELECT id, patient_uid, first_name, last_name, phone FROM patients WHERE clinic_id = ? ORDER BY first_name", [$clinicId]);
}

// Load service categories dynamically from services table
try {
    $serviceCategories = $db->fetchAll("SELECT DISTINCT category FROM services WHERE clinic_id = ? AND is_active = 1 ORDER BY category", [$clinicId]);
    $serviceCategories = array_column($serviceCategories, 'category');
} catch (Exception $e) {
    $serviceCategories = [];
}
if (empty($serviceCategories)) {
    $serviceCategories = ['consultation','procedure','medicine','lab_test','vaccination','dental','service','other'];
}
$categoryLabels = [
    'consultation' => 'Consultation', 'procedure' => 'Procedure', 'medicine' => 'Medicine',
    'lab_test' => 'Lab Test', 'vaccination' => 'Vaccination', 'dental' => 'Dental',
    'service' => 'Service', 'other' => 'Other'
];

// Load all services for autocomplete
try {
    $allServices = $db->fetchAll("SELECT name, category, default_price FROM services WHERE clinic_id = ? AND is_active = 1 ORDER BY name", [$clinicId]);
} catch (Exception $e) {
    $allServices = [];
}
?>

<div class="content-header">
    <div>
        <ul class="breadcrumb">
            <li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li>
            <li><a href="<?= BASE_URL ?>/modules/billing/list.php">Billing</a></li>
            <li>Create Invoice</li>
        </ul>
        <h1>Create New Invoice</h1>
    </div>
</div>

<form method="POST" id="invoiceForm">
    <div class="grid-3 gap-24">
        <!-- Items (2-col span) -->
        <div style="grid-column: span 2;">
            <div class="card mb-24">
                <div class="card-header">
                    <h3><i class="fas fa-user" style="color: var(--primary);"></i> Patient</h3>
                </div>
                <div class="card-body">
                    <?php if ($prefilledPatient): ?>
                    <input type="hidden" name="patient_id" value="<?= $prefilledPatient['id'] ?>">
                    <div class="alert alert-info">
                        <strong><?= sanitizeOutput($prefilledPatient['first_name'] . ' ' . ($prefilledPatient['last_name'] ?? '')) ?></strong>
                        | <?= sanitizeOutput($prefilledPatient['patient_uid']) ?>
                        | <?= sanitizeOutput($prefilledPatient['phone']) ?>
                    </div>
                    <?php else: ?>
                    <select name="patient_id" class="form-control" required onchange="loadPatientHistory(this.value)">
                        <option value="">Select Patient...</option>
                        <?php foreach ($patients as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= sanitizeOutput($p['patient_uid'] . ' - ' . $p['first_name'] . ' ' . ($p['last_name'] ?? '') . ' (' . $p['phone'] . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Patient History -->
            <div class="card mb-24" id="historyCard" style="display:none;">
                <div class="card-header" style="justify-content: space-between;">
                    <h3><i class="fas fa-history" style="color: var(--info);"></i> Last 5 Invoices</h3>
                    <div id="outstandingDueBadge" style="display:none;font-size:14px;font-weight:600;color:var(--danger);background:var(--danger-bg);padding:6px 12px;border-radius:12px;border:1px solid var(--danger-light);">
                        Outstanding Dues: <span id="outstandingDueValue">₹ 0.00</span>
                    </div>
                </div>
                <div class="card-body">
                    <table class="table" style="font-size: 13px;">
                        <thead><tr><th>Date</th><th>Invoice #</th><th>Total</th><th>Paid</th><th>Due</th><th>Status</th><th>Action</th></tr></thead>
                        <tbody id="historyTableBody"></tbody>
                    </table>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-list" style="color: var(--success);"></i> Invoice Items</h3>
                    <button type="button" class="btn btn-sm btn-primary" onclick="addItem()"><i class="fas fa-plus"></i> Add Item</button>
                </div>
                <div class="card-body" id="itemsContainer">
                    <div class="item-row d-flex gap-12 align-center mb-12" data-index="0">
                        <div class="form-group mb-0" style="flex: 2; position: relative;">
                            <input type="text" name="item_name[]" class="form-control svc-autocomplete" placeholder="Type service name..." required autocomplete="off" oninput="showSuggestions(this)" onfocus="showSuggestions(this)">
                            <div class="svc-suggestions"></div>
                        </div>
                        <div class="form-group mb-0" style="flex: 0.8;">
                            <select name="item_type[]" class="form-control">
                                <?php foreach ($serviceCategories as $cat): ?>
                                <option value="<?= $cat ?>"><?= $categoryLabels[$cat] ?? ucfirst(str_replace('_',' ',$cat)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group mb-0" style="flex: 0.5;">
                            <input type="number" name="item_qty[]" class="form-control" value="1" min="1" oninput="calculateTotal()">
                        </div>
                        <div class="form-group mb-0" style="flex: 0.7;">
                            <input type="number" name="item_rate[]" class="form-control" placeholder="Rate" step="0.01" oninput="calculateTotal()">
                        </div>
                        <div class="form-group mb-0" style="flex: 0.3;">
                            <button type="button" class="btn btn-sm btn-ghost text-danger" onclick="removeItem(this)"><i class="fas fa-trash"></i></button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Summary -->
        <div>
            <div class="card" style="position: sticky; top: 100px;">
                <div class="card-header"><h3><i class="fas fa-receipt" style="color: var(--warning);"></i> Summary</h3></div>
                <div class="card-body">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                        <span>Subtotal</span>
                        <strong id="subtotal">₹ 0.00</strong>
                    </div>
                    
                    <div class="form-group mb-12">
                        <label class="form-label" style="font-size: 12px;">Discount %</label>
                        <input type="number" name="discount_percent" class="form-control" value="0" min="0" max="100" step="0.5" oninput="calculateTotal()">
                    </div>
                    
                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px; color: var(--danger);">
                        <span>Discount</span>
                        <span id="discountAmt">- ₹ 0.00</span>
                    </div>
                    
                    <label style="font-size: 12px; display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                        <input type="checkbox" name="gst_enabled" onchange="calculateTotal()"> Enable GST
                    </label>
                    <input type="hidden" name="gst_percent" value="<?= DEFAULT_GST_RATE ?>">
                    
                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px;" id="gstRow" hidden>
                        <span>GST (<?= DEFAULT_GST_RATE ?>%)</span>
                        <span id="gstAmt">₹ 0.00</span>
                    </div>
                    
                    <hr style="margin: 12px 0; border-color: var(--border-color);">
                    
                    <div style="display: flex; justify-content: space-between; font-size: 1.25rem;">
                        <strong>Total</strong>
                        <strong style="color: var(--primary);" id="grandTotal">₹ 0.00</strong>
                    </div>
                    
                    <div class="form-group mt-16">
                        <label class="form-label" style="font-size: 12px;">Payment Mode</label>
                        <select name="payment_mode" id="paymentMode" class="form-control" onchange="toggleRefNumber()">
                            <?php foreach (PAYMENT_MODES as $k => $v): ?>
                            <option value="<?= $k ?>"><?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group" id="refNumberGroup" style="display:none;">
                        <label class="form-label" style="font-size: 12px;">Reference / Transaction No.</label>
                        <input type="text" name="transaction_id" class="form-control" placeholder="UPI ID / Card last 4 / Cheque No.">
                    </div>
                    
                    <hr style="margin: 12px 0; border-color: var(--border-color);">
                    
                    <div class="form-group">
                        <label class="form-label" style="font-size: 12px;">Paid Amount</label>
                        <input type="number" name="paid_amount" id="paidAmount" class="form-control" value="0" min="0" step="0.01" oninput="updateDueDisplay()" style="font-weight: 700; font-size: 16px; text-align: center;">
                    </div>
                    
                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px; color: var(--danger);">
                        <span>Balance Due</span>
                        <strong id="balanceDue">₹ 0.00</strong>
                    </div>

                    <div class="form-group">
                        <label class="form-label" style="font-size: 12px;">Notes</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                    
                    <button type="submit" class="btn btn-success btn-block btn-lg mt-16" onclick="document.getElementById('paidAmount').value = document.getElementById('grandTotalValue').value">
                        <i class="fas fa-check-circle"></i> Pay & Generate Invoice
                    </button>
                    <button type="submit" class="btn btn-outline btn-block mt-8" onclick="document.getElementById('paidAmount').value = 0">
                        <i class="fas fa-file-invoice"></i> Generate Invoice (Due)
                    </button>
                    <input type="hidden" id="grandTotalValue" value="0">
                </div>
            </div>
        </div>
    </div>
</form>

<style>
#itemsContainer { overflow: visible; }
#itemsContainer .item-row { position: relative; }
#itemsContainer.card-body { overflow: visible; }
.card:has(#itemsContainer) { overflow: visible; }
.svc-suggestions {
    display: none;
    position: absolute;
    top: 100%;
    left: 0; right: 0;
    background: white;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.15);
    z-index: 1000;
    max-height: 220px;
    overflow-y: auto;
}
.svc-suggestions .svc-item {
    padding: 10px 14px;
    cursor: pointer;
    border-bottom: 1px solid #f0f0f0;
    transition: background 0.15s;
}
.svc-suggestions .svc-item:last-child { border-bottom: none; }
.svc-suggestions .svc-item:hover { background: #f0f9ff; }
.svc-suggestions .svc-item .svc-name { font-weight: 600; font-size: 13px; }
.svc-suggestions .svc-item .svc-meta { font-size: 11px; color: var(--text-muted); margin-top: 2px; }
.svc-suggestions .svc-item .svc-price { float: right; font-weight: 700; color: var(--primary); font-size: 13px; }
</style>

<script>
// Ensure formatCurrency is always available
if (typeof formatCurrency !== 'function') {
    function formatCurrency(amount) {
        return '₹ ' + parseFloat(amount || 0).toFixed(2);
    }
}

function toggleRefNumber() {
    var mode = document.getElementById('paymentMode').value;
    document.getElementById('refNumberGroup').style.display = (mode === 'cash') ? 'none' : 'block';
}

// All services data for autocomplete
var allServices = <?= json_encode($allServices) ?>;
var categoryLabelsJS = <?= json_encode($categoryLabels) ?>;

// Dynamic category options from PHP
var categoryOptions = <?= json_encode(array_map(function($cat) use ($categoryLabels) {
    return ['value' => $cat, 'label' => $categoryLabels[$cat] ?? ucfirst(str_replace('_',' ',$cat))];
}, $serviceCategories)) ?>;

function getCategoryOptionsHtml(selected) {
    return categoryOptions.map(function(c) {
        return '<option value="' + c.value + '"' + (c.value === selected ? ' selected' : '') + '>' + c.label + '</option>';
    }).join('');
}

var itemIndex = 1;

function addItem() {
    var container = document.getElementById('itemsContainer');
    var row = document.createElement('div');
    row.className = 'item-row d-flex gap-12 align-center mb-12';
    row.setAttribute('data-index', itemIndex);
    row.innerHTML =
        '<div class="form-group mb-0" style="flex:2; position:relative;"><input type="text" name="item_name[]" class="form-control svc-autocomplete" placeholder="Type service name..." required autocomplete="off" oninput="showSuggestions(this)" onfocus="showSuggestions(this)"><div class="svc-suggestions"></div></div>' +
        '<div class="form-group mb-0" style="flex:0.8;"><select name="item_type[]" class="form-control">' + getCategoryOptionsHtml('') + '</select></div>' +
        '<div class="form-group mb-0" style="flex:0.5;"><input type="number" name="item_qty[]" class="form-control" value="1" min="1" oninput="calculateTotal()"></div>' +
        '<div class="form-group mb-0" style="flex:0.7;"><input type="number" name="item_rate[]" class="form-control" placeholder="Rate" step="0.01" oninput="calculateTotal()"></div>' +
        '<div class="form-group mb-0" style="flex:0.3;"><button type="button" class="btn btn-sm btn-ghost text-danger" onclick="removeItem(this)"><i class="fas fa-trash"></i></button></div>';
    container.appendChild(row);
    itemIndex++;
}

function showSuggestions(input) {
    var query = input.value.trim().toLowerCase();
    var sugBox = input.parentElement.querySelector('.svc-suggestions');
    if (query.length < 1) { sugBox.style.display = 'none'; return; }
    var matches = allServices.filter(function(s) { return s.name.toLowerCase().indexOf(query) !== -1; });
    if (matches.length === 0) { sugBox.style.display = 'none'; return; }
    var html = '';
    matches.slice(0, 8).forEach(function(s) {
        var catLabel = categoryLabelsJS[s.category] || s.category;
        html += '<div class="svc-item" onclick="selectService(this, \'' + s.name.replace(/'/g, "\\'") + '\', \'' + s.category + '\', ' + s.default_price + ')">' +
            '<span class="svc-price">' + formatCurrency(s.default_price) + '</span>' +
            '<div class="svc-name">' + s.name + '</div>' +
            '<div class="svc-meta">' + catLabel + '</div></div>';
    });
    sugBox.innerHTML = html;
    sugBox.style.display = 'block';
}

function selectService(el, name, category, price) {
    var row = el.closest('.item-row');
    row.querySelector('[name="item_name[]"]').value = name;
    var catSelect = row.querySelector('[name="item_type[]"]');
    if (catSelect) catSelect.value = category;
    var rateInput = row.querySelector('[name="item_rate[]"]');
    if (rateInput) { rateInput.value = price; }
    el.closest('.svc-suggestions').style.display = 'none';
    calculateTotal();
}

// Close suggestions when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.classList.contains('svc-autocomplete')) {
        var boxes = document.querySelectorAll('.svc-suggestions');
        for (var i = 0; i < boxes.length; i++) boxes[i].style.display = 'none';
    }
});

function removeItem(btn) {
    btn.closest('.item-row').remove();
    calculateTotal();
}

function calculateTotal() {
    var qtys = document.querySelectorAll('[name="item_qty[]"]');
    var rates = document.querySelectorAll('[name="item_rate[]"]');
    var subtotal = 0;
    qtys.forEach(function(q, i) { subtotal += (parseFloat(q.value) || 0) * (parseFloat(rates[i] ? rates[i].value : 0) || 0); });

    document.getElementById('subtotal').textContent = formatCurrency(subtotal);

    var discount = parseFloat(document.querySelector('[name="discount_percent"]').value) || 0;
    var discountAmt = (subtotal * discount) / 100;
    var taxable = subtotal - discountAmt;
    document.getElementById('discountAmt').textContent = '- ' + formatCurrency(discountAmt);

    var gstEnabled = document.querySelector('[name="gst_enabled"]').checked;
    var gstPercent = gstEnabled ? <?= DEFAULT_GST_RATE ?> : 0;
    var gstAmt = (taxable * gstPercent) / 100;
    var grandTotal = taxable + gstAmt;

    document.getElementById('gstRow').hidden = !gstEnabled;
    document.getElementById('gstAmt').textContent = formatCurrency(gstAmt);
    document.getElementById('grandTotal').textContent = formatCurrency(grandTotal);
    document.getElementById('grandTotalValue').value = grandTotal.toFixed(2);
    updateDueDisplay();
}

function updateDueDisplay() {
    var total = parseFloat(document.getElementById('grandTotalValue').value) || 0;
    var paid = parseFloat(document.getElementById('paidAmount').value) || 0;
    if (paid > total) { document.getElementById('paidAmount').value = total.toFixed(2); paid = total; }
    var due = total - paid;
    document.getElementById('balanceDue').textContent = formatCurrency(due);
    document.getElementById('balanceDue').style.color = due > 0 ? 'var(--danger)' : 'var(--success)';
}

function getStatusBadgeHtml(status) {
    status = status || 'due';
    var badges = {
        'paid': '<span class="badge badge-success"><i class="fas fa-check"></i> Paid</span>',
        'partial': '<span class="badge badge-warning"><i class="fas fa-minus-circle"></i> Partial</span>',
        'due': '<span class="badge badge-danger"><i class="fas fa-exclamation-circle"></i> Due</span>',
        'overdue': '<span class="badge badge-danger"><i class="fas fa-exclamation-triangle"></i> Overdue</span>'
    };
    return badges[status] || '<span class="badge badge-secondary">' + status + '</span>';
}

function formatDateDateOnly(dateString) {
    if (!dateString) return '-';
    var date = new Date(dateString);
    return date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

function loadPatientHistory(patientId) {
    var card = document.getElementById('historyCard');
    var tbody = document.getElementById('historyTableBody');

    if (!patientId) { card.style.display = 'none'; return; }

    fetch('get_patient_history.php?patient_id=' + patientId)
        .then(function(response) {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.text();
        })
        .then(function(text) {
            console.log('Patient history response:', text);
            var data;
            try { data = JSON.parse(text); } catch(e) {
                console.error('Invalid JSON response:', text);
                return;
            }

            var invoices = data.history || [];
            var totalDue = parseFloat(data.total_due) || 0;
            var pendingAppts = data.pending_appointments || [];

            // Outstanding Dues Badge
            var dueBadge = document.getElementById('outstandingDueBadge');
            if (totalDue > 0) {
                document.getElementById('outstandingDueValue').textContent = formatCurrency(totalDue);
                dueBadge.style.display = 'inline-block';
            } else {
                dueBadge.style.display = 'none';
            }

            // Auto-inject pending appointment fees
            document.querySelectorAll('.appt-fee-row').forEach(function(r) { r.remove(); });
            var oldNotice = document.getElementById('apptFeeNotice');
            if (oldNotice) oldNotice.style.display = 'none';

            if (pendingAppts.length > 0) {
                var container = document.getElementById('itemsContainer');
                pendingAppts.forEach(function(appt) {
                    var label = 'Consultation - Dr. ' + appt.doctor_name + (appt.specialty ? ' (' + appt.specialty + ')' : '');
                    var fee = parseFloat(appt.consultation_fee) || 0;
                    var row = document.createElement('div');
                    row.className = 'item-row appt-fee-row d-flex gap-12 align-center mb-12';
                    row.style.cssText = 'background:#f0fdf4; border:1px solid #86efac; border-radius:8px; padding:8px 10px;';
                    row.innerHTML =
                        '<div class="form-group mb-0" style="flex:2;"><input type="text" name="item_name[]" class="form-control" value="' + label + '" readonly style="background:transparent;border-color:transparent;font-weight:600;"></div>' +
                        '<div class="form-group mb-0" style="flex:0.8;"><select name="item_type[]" class="form-control"><option value="consultation" selected>Consultation</option></select></div>' +
                        '<div class="form-group mb-0" style="flex:0.5;"><input type="number" name="item_qty[]" class="form-control" value="1" min="1" readonly oninput="calculateTotal()"></div>' +
                        '<div class="form-group mb-0" style="flex:0.7;"><input type="number" name="item_rate[]" class="form-control" value="' + fee + '" readonly oninput="calculateTotal()" style="font-weight:600;color:#16a34a;"></div>' +
                        '<div class="form-group mb-0" style="flex:0.3;"><span style="font-size:11px;color:#16a34a;white-space:nowrap;"><i class="fas fa-check-circle"></i> Auto</span></div>' +
                        '<input type="hidden" name="appointment_ids[]" value="' + appt.id + '">';
                    container.insertBefore(row, container.firstChild);
                });
                calculateTotal();

                // Notice banner
                var notice = document.getElementById('apptFeeNotice');
                if (!notice) {
                    notice = document.createElement('div');
                    notice.id = 'apptFeeNotice';
                    notice.className = 'alert';
                    notice.style.cssText = 'background:#f0fdf4; border:1px solid #86efac; color:#166534; margin-bottom:12px; font-size:13px;';
                    container.parentNode.insertBefore(notice, container);
                }
                notice.innerHTML = '<i class="fas fa-calendar-check"></i> <strong>' + pendingAppts.length + '</strong> pending consultation fee' + (pendingAppts.length > 1 ? 's' : '') + ' auto-added from completed appointment' + (pendingAppts.length > 1 ? 's' : '') + '.';
                notice.style.display = 'block';
            }

            // Invoice History Table
            if (invoices.length > 0) {
                tbody.innerHTML = invoices.map(function(inv) {
                    return '<tr>' +
                        '<td>' + formatDateDateOnly(inv.invoice_date) + '</td>' +
                        '<td>' + inv.invoice_number + '</td>' +
                        '<td class="font-semibold">' + formatCurrency(inv.total_amount || 0) + '</td>' +
                        '<td class="text-success">' + formatCurrency(inv.paid_amount || 0) + '</td>' +
                        '<td class="text-danger">' + formatCurrency(inv.due_amount || 0) + '</td>' +
                        '<td>' + getStatusBadgeHtml(inv.status) + '</td>' +
                        '<td><a href="view.php?id=' + inv.id + '" target="_blank" class="btn btn-sm btn-outline"><i class="fas fa-eye"></i></a></td>' +
                    '</tr>';
                }).join('');
                card.style.display = 'block';
            } else {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No previous billing history found.</td></tr>';
                card.style.display = (totalDue > 0 || pendingAppts.length > 0) ? 'block' : 'none';
            }
        })
        .catch(function(err) {
            console.error('Error fetching patient history:', err);
        });
}

// Load history if patient is prefilled
<?php if ($prefilledPatient): ?>
loadPatientHistory(<?= $prefilledPatient['id'] ?>);
<?php endif; ?>
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
