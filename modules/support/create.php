<?php
/**
 * Create Support Ticket
 */
$pageTitle = 'New Support Ticket';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';

$db = db();
$userName = $_SESSION['full_name'] ?? 'Unknown';
$userId = $_SESSION['user_id'] ?? 0;
$error = '';

try {
    $masterConn = $db->getMasterConnection();
    $tenantId = intval($db->tenantInfo['id'] ?? 0);
    $clinicName = $db->tenantInfo['clinic_name'] ?? '';
} catch (Exception $e) {
    $error = 'Unable to connect to support system. Please try again later.';
    $masterConn = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $masterConn) {
    $subject = sanitize($_POST['subject'] ?? '');
    $category = sanitize($_POST['category'] ?? 'general');
    $priority = sanitize($_POST['priority'] ?? 'medium');
    $description = sanitize($_POST['description'] ?? '');

    if (empty($subject)) {
        $error = 'Subject is required';
    } else {
        try {
            // Generate ticket UID
            $lastTicket = $masterConn->query("SELECT ticket_uid FROM support_tickets ORDER BY id DESC LIMIT 1")->fetch();
            $nextNum = 1;
            if ($lastTicket) {
                $parts = explode('-', $lastTicket['ticket_uid']);
                $nextNum = intval(end($parts)) + 1;
            }
            $ticketUid = 'TKT-' . str_pad($nextNum, 5, '0', STR_PAD_LEFT);

            $stmt = $masterConn->prepare("INSERT INTO support_tickets (ticket_uid, tenant_id, user_id, user_name, clinic_name, subject, description, category, priority) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$ticketUid, $tenantId, $userId, $userName, $clinicName, $subject, $description, $category, $priority]);

            // Send email notification to super admin
            $to = 'ga.featuregen@gmail.com';
            $emailSubject = "[{$ticketUid}] New Support Ticket: {$subject}";
            $emailBody = "New Support Ticket Created\n";
            $emailBody .= "================================\n\n";
            $emailBody .= "Ticket ID: {$ticketUid}\n";
            $emailBody .= "Clinic: {$clinicName}\n";
            $emailBody .= "Created By: {$userName}\n";
            $emailBody .= "Category: " . ucfirst(str_replace('_', ' ', $category)) . "\n";
            $emailBody .= "Priority: " . ucfirst($priority) . "\n\n";
            $emailBody .= "Subject: {$subject}\n\n";
            $emailBody .= "Description:\n{$description}\n\n";
            $emailBody .= "================================\n";
            $emailBody .= "Login to manage: https://democlinic.featuregen.com/modules/admin/tickets.php";
            $headers = "From: noreply@featuregen.com\r\n";
            $headers .= "Reply-To: noreply@featuregen.com\r\n";
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
            @mail($to, $emailSubject, $emailBody, $headers);

            setFlashMessage('Ticket ' . $ticketUid . ' created successfully!', 'success');
            header('Location: list.php');
            exit;
        } catch (Exception $e) {
            $error = 'Failed to create ticket: ' . $e->getMessage();
        }
    }
}
?>

<div class="content-header">
    <div>
        <ul class="breadcrumb">
            <li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li>
            <li><a href="list.php">Support</a></li>
            <li>New Ticket</li>
        </ul>
        <h1>Raise a Support Ticket</h1>
    </div>
</div>

<?php if (!empty($error)): ?>
<div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= sanitizeOutput($error) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h3><i class="fas fa-headset" style="color: var(--primary);"></i> Ticket Details</h3></div>
    <div class="card-body">
        <form method="POST">
            <div class="form-row">
                <div class="form-group" style="flex: 2;">
                    <label class="form-label">Subject <span class="required">*</span></label>
                    <input type="text" name="subject" class="form-control" required placeholder="Briefly describe your issue" value="<?= sanitizeOutput($_POST['subject'] ?? '') ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Category</label>
                    <select name="category" class="form-control">
                        <option value="general">General</option>
                        <option value="bug">Bug / Error</option>
                        <option value="feature_request">Feature Request</option>
                        <option value="billing">Billing</option>
                        <option value="account">Account</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Priority</label>
                    <select name="priority" class="form-control">
                        <option value="low">Low</option>
                        <option value="medium" selected>Medium</option>
                        <option value="high">High</option>
                        <option value="critical">Critical</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Description</label>
                <textarea name="description" class="form-control" rows="6" placeholder="Explain your issue in detail. Include steps to reproduce if it's a bug."><?= sanitizeOutput($_POST['description'] ?? '') ?></textarea>
            </div>

            <div class="d-flex justify-end gap-16 mt-24">
                <a href="list.php" class="btn btn-outline">Cancel</a>
                <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Submit Ticket</button>
            </div>
        </form>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
