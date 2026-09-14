<?php
/**
 * REST API Billing & Invoices Endpoints - Feature Gen Care
 * GET  /api/v1/billing.php (list)
 * GET  /api/v1/billing.php?id={id} (details)
 * POST /api/v1/billing.php (create invoice)
 * POST /api/v1/billing.php?action=payment (record payment)
 */
require_once __DIR__ . '/middleware/auth.php';
$auth = authenticateApiRequest();
$db = db();
$clinicId = intval($auth['user']['clinic_id'] ?? 1);
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

try {
    // 1. Record Payment Action
    if ($action === 'payment' && $method === 'POST') {
        $input = getJsonInput();
        $invoiceId = intval($input['invoice_id'] ?? 0);
        $amount = floatval($input['amount'] ?? 0);
        $paymentMode = sanitize($input['payment_mode'] ?? 'cash');
        $referenceNo = sanitize($input['reference_number'] ?? '');
        $notes = sanitize($input['notes'] ?? '');

        if (!$invoiceId || $amount <= 0) {
            jsonResponse(false, null, 'Valid invoice ID and positive payment amount are required', 400);
        }

        $inv = $db->fetch("SELECT * FROM invoices WHERE id = ? AND clinic_id = ?", [$invoiceId, $clinicId]);
        if (!$inv) {
            jsonResponse(false, null, 'Invoice not found', 404);
        }

        $db->beginTransaction();

        // Generate payment receipt number
        $receiptNo = 'REC-' . date('Ymd') . '-' . rand(1000, 9999);

        $db->query(
            "INSERT INTO payments (clinic_id, invoice_id, patient_id, receipt_number, amount, payment_method, transaction_id, payment_date, received_by, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, ?)",
            [$clinicId, $invoiceId, $inv['patient_id'], $receiptNo, $amount, $paymentMode, $referenceNo ?: null, $auth['user']['id'], $notes]
        );

        // Update invoice paid & due amounts
        $newPaid = floatval($inv['paid_amount']) + $amount;
        $newDue = max(0, floatval($inv['total_amount']) - $newPaid);
        $newStatus = ($newDue <= 0.01) ? 'paid' : 'partially_paid';

        $db->query(
            "UPDATE invoices SET paid_amount = ?, due_amount = ?, status = ? WHERE id = ?",
            [$newPaid, $newDue, $newStatus, $invoiceId]
        );

        $db->commit();

        jsonResponse(true, [
            'message' => 'Payment recorded successfully',
            'receipt_number' => $receiptNo,
            'paid_amount' => $newPaid,
            'due_amount' => $newDue,
            'status' => $newStatus
        ]);
    }

    // 2. Fetch Single Invoice Details
    if (isset($_GET['id']) && $method === 'GET') {
        $id = intval($_GET['id']);
        $inv = $db->fetch(
            "SELECT i.*, p.patient_uid, p.first_name, p.last_name, p.phone as patient_phone, p.address as patient_address
             FROM invoices i
             JOIN patients p ON i.patient_id = p.id
             WHERE i.id = ? AND i.clinic_id = ?",
            [$id, $clinicId]
        );
        if (!$inv) {
            jsonResponse(false, null, 'Invoice not found', 404);
        }

        $items = $db->fetchAll("SELECT * FROM invoice_items WHERE invoice_id = ?", [$id]);
        $payments = $db->fetchAll("SELECT * FROM payments WHERE invoice_id = ? ORDER BY payment_date DESC", [$id]);

        jsonResponse(true, [
            'invoice' => $inv,
            'items' => $items,
            'payments' => $payments
        ]);
    }

    // 3. Create Invoice
    if ($method === 'POST') {
        $input = getJsonInput();
        $patientId = intval($input['patient_id'] ?? 0);
        $items = $input['items'] ?? [];
        $taxRate = floatval($input['tax_rate'] ?? 18.0);
        $discountAmount = floatval($input['discount_amount'] ?? 0);
        $initialPayment = floatval($input['initial_payment'] ?? 0);
        $paymentMode = sanitize($input['payment_mode'] ?? 'cash');

        if (!$patientId || empty($items)) {
            jsonResponse(false, null, 'Patient and at least one billable item are required', 400);
        }

        $clinic = $db->fetch("SELECT invoice_prefix, invoice_start_no FROM clinics WHERE id = ?", [$clinicId]);
        $prefix = $clinic['invoice_prefix'] ?? 'INV';
        $startNo = intval($clinic['invoice_start_no'] ?? 1);
        $lastInv = $db->fetch("SELECT id FROM invoices WHERE clinic_id = ? ORDER BY id DESC LIMIT 1", [$clinicId]);
        $nextInvNo = $prefix . '-' . ($startNo + ($lastInv ? intval($lastInv['id']) + 1 : 1));

        $subtotal = 0.00;
        foreach ($items as $it) {
            $qty = max(1, intval($it['quantity'] ?? 1));
            $unitPrice = floatval($it['unit_price'] ?? 0);
            $subtotal += ($qty * $unitPrice);
        }

        $taxableAmount = max(0, $subtotal - $discountAmount);
        $taxAmount = round(($taxableAmount * $taxRate) / 100, 2);
        $totalAmount = round($taxableAmount + $taxAmount, 2);
        $paidAmount = min($totalAmount, $initialPayment);
        $dueAmount = max(0, $totalAmount - $paidAmount);
        $status = ($dueAmount <= 0.01) ? 'paid' : (($paidAmount > 0) ? 'partially_paid' : 'unpaid');

        $db->beginTransaction();

        $db->query(
            "INSERT INTO invoices (clinic_id, patient_id, invoice_number, invoice_date, subtotal, discount_amount, tax_amount, total_amount, paid_amount, due_amount, status, created_by)
             VALUES (?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $clinicId, $patientId, $nextInvNo, $subtotal, $discountAmount,
                $taxAmount, $totalAmount, $paidAmount, $dueAmount, $status, $auth['user']['id']
            ]
        );
        $newInvId = intval($db->lastInsertId());

        // Insert Line Items
        foreach ($items as $it) {
            $itemType = sanitize($it['item_type'] ?? 'consultation');
            $desc = sanitize(trim($it['description'] ?? 'Medical Service'));
            $qty = max(1, intval($it['quantity'] ?? 1));
            $unitPrice = floatval($it['unit_price'] ?? 0);
            $itemTotal = round($qty * $unitPrice, 2);

            $db->query(
                "INSERT INTO invoice_items (invoice_id, item_type, description, quantity, unit_price, total_price)
                 VALUES (?, ?, ?, ?, ?, ?)",
                [$newInvId, $itemType, $desc, $qty, $unitPrice, $itemTotal]
            );
        }

        // Record Initial Payment if made
        if ($paidAmount > 0) {
            $receiptNo = 'REC-' . date('Ymd') . '-' . rand(1000, 9999);
            $db->query(
                "INSERT INTO payments (clinic_id, invoice_id, patient_id, receipt_number, amount, payment_method, payment_date, received_by)
                 VALUES (?, ?, ?, ?, ?, ?, CURDATE(), ?)",
                [$clinicId, $newInvId, $patientId, $receiptNo, $paidAmount, $paymentMode, $auth['user']['id']]
            );
        }

        $db->commit();

        jsonResponse(true, [
            'message' => 'Invoice created successfully',
            'invoice_id' => $newInvId,
            'invoice_number' => $nextInvNo,
            'total_amount' => $totalAmount,
            'due_amount' => $dueAmount,
            'status' => $status
        ], null, 201);
    }

    // 4. List Invoices (Default GET)
    $status = !empty($_GET['status']) ? sanitize($_GET['status']) : null;
    $where = "i.clinic_id = ?";
    $params = [$clinicId];

    if ($status) {
        $where .= " AND i.status = ?";
        $params[] = $status;
    }

    $invoices = $db->fetchAll(
        "SELECT i.id, i.invoice_number, i.invoice_date, i.total_amount, i.paid_amount, i.due_amount, i.status,
                p.id as patient_id, p.patient_uid, p.first_name, p.last_name, p.phone as patient_phone
         FROM invoices i
         JOIN patients p ON i.patient_id = p.id
         WHERE {$where}
         ORDER BY i.id DESC LIMIT 50",
        $params
    );

    jsonResponse(true, $invoices);

} catch (Exception $e) {
    if ($db && $db->getConnection()->inTransaction()) {
        $db->rollback();
    }
    jsonResponse(false, null, 'Billing API Error: ' . $e->getMessage(), 500);
}
