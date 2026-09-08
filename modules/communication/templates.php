<?php
/**
 * Message Templates - Advanced Clinic Suite
 */
$pageTitle = 'Message Templates';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';
requirePermission('communication.view');

$db = db();
$clinicId = getCurrentClinicId();

// Handle form submission (add/edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add';
    $templateId = intval($_POST['template_id'] ?? 0);
    $name = sanitize($_POST['name'] ?? '');
    $channel = sanitize($_POST['channel'] ?? 'sms');
    $eventTrigger = sanitize($_POST['event_trigger'] ?? '');
    $subject = sanitize($_POST['subject'] ?? '');
    $body = sanitize($_POST['body'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if (empty($name) || empty($body)) {
        setFlashMessage('error', 'Name and body are required.');
    } else {
        try {
            if ($action === 'edit' && $templateId) {
                $db->query(
                    "UPDATE message_templates SET name=?, channel=?, event_trigger=?, subject=?, body=?, is_active=? WHERE id=? AND clinic_id=?",
                    [$name, $channel, $eventTrigger ?: null, $subject ?: null, $body, $isActive, $templateId, $clinicId]
                );
                logAudit('update', 'communication', 'message_template', $templateId);
                setFlashMessage('success', 'Template updated successfully.');
            } else {
                $db->query(
                    "INSERT INTO message_templates (clinic_id, name, channel, event_trigger, subject, body, is_active) VALUES (?,?,?,?,?,?,?)",
                    [$clinicId, $name, $channel, $eventTrigger ?: null, $subject ?: null, $body, $isActive]
                );
                logAudit('create', 'communication', 'message_template', $db->lastInsertId());
                setFlashMessage('success', 'Template created successfully.');
            }
        } catch (Exception $e) {
            setFlashMessage('error', 'Error: ' . $e->getMessage());
        }
    }
    header('Location: ' . BASE_URL . '/modules/communication/templates.php');
    exit;
}

// Handle delete
if (isset($_GET['delete'])) {
    $delId = intval($_GET['delete']);
    try {
        $db->query("DELETE FROM message_templates WHERE id = ? AND clinic_id = ?", [$delId, $clinicId]);
        logAudit('delete', 'communication', 'message_template', $delId);
        setFlashMessage('success', 'Template deleted.');
    } catch (Exception $e) {
        setFlashMessage('error', 'Error: ' . $e->getMessage());
    }
    header('Location: ' . BASE_URL . '/modules/communication/templates.php');
    exit;
}

// Fetch templates
$templates = $db->fetchAll("SELECT * FROM message_templates WHERE clinic_id = ? ORDER BY name", [$clinicId]);

// For edit modal
$editTemplate = null;
if (isset($_GET['edit'])) {
    $editTemplate = $db->fetch("SELECT * FROM message_templates WHERE id = ? AND clinic_id = ?", [$_GET['edit'], $clinicId]);
}

$eventTriggers = [
    'appointment_booked' => 'Appointment Booked',
    'appointment_reminder' => 'Appointment Reminder',
    'appointment_cancelled' => 'Appointment Cancelled',
    'bill_generated' => 'Bill Generated',
    'payment_received' => 'Payment Received',
    'prescription_sent' => 'Prescription Sent',
    'vaccination_due' => 'Vaccination Due',
    'birthday' => 'Birthday Wish',
    'followup_reminder' => 'Follow-up Reminder',
    'custom' => 'Custom / Manual'
];
?>

<div class="content-header">
    <div>
        <ul class="breadcrumb">
            <li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li>
            <li>Message Templates</li>
        </ul>
        <h1>Message Templates</h1>
    </div>
    <div class="d-flex gap-8">
        <a href="<?= BASE_URL ?>/modules/communication/logs.php" class="btn btn-outline"><i class="fas fa-history"></i> View Logs</a>
        <button class="btn btn-primary" onclick="openModal('template-modal')"><i class="fas fa-plus"></i> New Template</button>
    </div>
</div>

<!-- Available Variables Info -->
<div class="card mb-24">
    <div class="card-body" style="padding: 12px 20px;">
        <div style="font-size: 12px; color: var(--text-muted);">
            <strong>Available Variables:</strong>
            <code>{patient_name}</code>, <code>{patient_phone}</code>, <code>{doctor_name}</code>,
            <code>{appointment_date}</code>, <code>{appointment_time}</code>, <code>{clinic_name}</code>,
            <code>{invoice_number}</code>, <code>{amount}</code>, <code>{due_amount}</code>
        </div>
    </div>
</div>

<!-- Templates Table -->
<div class="card">
    <div class="card-header"><h3>All Templates <span class="badge badge-primary"><?= count($templates) ?></span></h3></div>
    <div class="card-body p-0">
        <?php if (empty($templates)): ?>
        <div class="empty-state"><i class="fas fa-envelope-open-text"></i><h3>No templates yet</h3><p>Create your first message template</p></div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Name</th><th>Channel</th><th>Event Trigger</th><th>Status</th><th>Body Preview</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($templates as $t): ?>
                <tr>
                    <td class="font-semibold"><?= sanitizeOutput($t['name']) ?></td>
                    <td>
                        <?php
                        $chColors = ['sms' => 'primary', 'whatsapp' => 'success', 'email' => 'info', 'all' => 'warning'];
                        $chIcons = ['sms' => 'sms', 'whatsapp' => 'fab fa-whatsapp', 'email' => 'envelope', 'all' => 'broadcast-tower'];
                        ?>
                        <span class="badge badge-<?= $chColors[$t['channel']] ?? 'secondary' ?>">
                            <i class="<?= strpos($chIcons[$t['channel']] ?? '', 'fab') === 0 ? $chIcons[$t['channel']] : 'fas fa-' . ($chIcons[$t['channel']] ?? 'comment') ?>"></i>
                            <?= ucfirst($t['channel']) ?>
                        </span>
                    </td>
                    <td style="font-size: 12px;"><?= $eventTriggers[$t['event_trigger']] ?? ($t['event_trigger'] ?: '-') ?></td>
                    <td><span class="badge badge-<?= $t['is_active'] ? 'success' : 'danger' ?>"><?= $t['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                    <td style="font-size: 12px; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                        <?= sanitizeOutput($t['body']) ?>
                    </td>
                    <td>
                        <div class="d-flex gap-4">
                            <a href="?edit=<?= $t['id'] ?>" class="btn btn-sm btn-ghost" title="Edit"><i class="fas fa-pen"></i></a>
                            <a href="?delete=<?= $t['id'] ?>" class="btn btn-sm btn-ghost" title="Delete"
                               onclick="return confirm('Delete this template?')"><i class="fas fa-trash" style="color: var(--danger);"></i></a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add/Edit Template Modal -->
<div class="modal-overlay" id="template-modal" <?= $editTemplate ? 'style="display:flex"' : '' ?>>
    <div class="modal" style="max-width: 600px;">
        <div class="modal-header">
            <h3><?= $editTemplate ? 'Edit' : 'New' ?> Template</h3>
            <button class="modal-close" onclick="closeModal('template-modal')">&times;</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="<?= $editTemplate ? 'edit' : 'add' ?>">
            <?php if ($editTemplate): ?><input type="hidden" name="template_id" value="<?= $editTemplate['id'] ?>"><?php endif; ?>

            <div class="modal-body">
                <div class="form-group mb-16">
                    <label class="form-label">Template Name <span class="required">*</span></label>
                    <input type="text" name="name" class="form-control" required
                           value="<?= sanitizeOutput($editTemplate['name'] ?? '') ?>" placeholder="e.g. Appointment Confirmation">
                </div>

                <div class="form-row mb-16">
                    <div class="form-group">
                        <label class="form-label">Channel</label>
                        <select name="channel" class="form-control">
                            <?php foreach (['sms' => 'SMS', 'whatsapp' => 'WhatsApp', 'email' => 'Email', 'all' => 'All Channels'] as $k => $v): ?>
                            <option value="<?= $k ?>" <?= ($editTemplate['channel'] ?? 'sms') === $k ? 'selected' : '' ?>><?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Event Trigger</label>
                        <select name="event_trigger" class="form-control">
                            <option value="">None (Manual)</option>
                            <?php foreach ($eventTriggers as $k => $v): ?>
                            <option value="<?= $k ?>" <?= ($editTemplate['event_trigger'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group mb-16">
                    <label class="form-label">Subject <span style="font-weight: normal; color: var(--text-muted);">(email only)</span></label>
                    <input type="text" name="subject" class="form-control"
                           value="<?= sanitizeOutput($editTemplate['subject'] ?? '') ?>" placeholder="Email subject line">
                </div>

                <div class="form-group mb-16">
                    <label class="form-label">Message Body <span class="required">*</span></label>
                    <textarea name="body" class="form-control" rows="5" required
                              placeholder="Dear {patient_name}, your appointment with Dr. {doctor_name} is confirmed for {appointment_date} at {appointment_time}."><?= sanitizeOutput($editTemplate['body'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" name="is_active" value="1"
                               <?= ($editTemplate['is_active'] ?? 1) ? 'checked' : '' ?>>
                        <span>Active</span>
                    </label>
                </div>
            </div>

            <div class="modal-footer d-flex justify-end gap-12">
                <button type="button" class="btn btn-outline" onclick="closeModal('template-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> <?= $editTemplate ? 'Update' : 'Create' ?> Template
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
