<?php
/**
 * View Support Ticket (Clinic Side)
 */
require_once dirname(dirname(__DIR__)) . '/config/app.php';
require_once INCLUDES_PATH . '/functions.php';
require_once CONFIG_PATH . '/database.php';

$db = db();
$userName = $_SESSION['full_name'] ?? 'Unknown';
$ticketId = intval($_GET['id'] ?? 0);

try {
    $masterConn = $db->getMasterConnection();
    $tenantId = intval($db->tenantInfo['id'] ?? 0);
} catch (Exception $e) {
    setFlashMessage('Unable to connect to support system', 'error');
    header('Location: list.php');
    exit;
}

// Fetch ticket
$stmt = $masterConn->prepare("SELECT * FROM support_tickets WHERE id = ? AND tenant_id = ?");
$stmt->execute([$ticketId, $tenantId]);
$ticket = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ticket) {
    setFlashMessage('Ticket not found', 'error');
    header('Location: list.php');
    exit;
}

// Handle reply
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['message'])) {
    $message = sanitize($_POST['message']);
    $stmt = $masterConn->prepare("INSERT INTO ticket_replies (ticket_id, user_name, user_role, message) VALUES (?, ?, 'clinic_admin', ?)");
    $stmt->execute([$ticketId, $userName, $message]);
    
    // Update ticket updated_at
    $masterConn->prepare("UPDATE support_tickets SET updated_at = NOW() WHERE id = ?")->execute([$ticketId]);
    
    header('Location: view.php?id=' . $ticketId . '#replies');
    exit;
}

// Fetch replies (exclude internal notes)
$stmt = $masterConn->prepare("SELECT * FROM ticket_replies WHERE ticket_id = ? AND is_internal = 0 ORDER BY created_at ASC");
$stmt->execute([$ticketId]);
$replies = $stmt->fetchAll(PDO::FETCH_ASSOC);

// NOW include header (after all redirects are done)
$pageTitle = 'View Ticket';
require_once INCLUDES_PATH . '/header.php';

function ticketStatusBadge($status) {
    $map = [
        'open' => ['class' => 'badge-info', 'icon' => 'envelope-open', 'label' => 'Open'],
        'in_progress' => ['class' => 'badge-warning', 'icon' => 'spinner', 'label' => 'In Progress'],
        'resolved' => ['class' => 'badge-success', 'icon' => 'check-circle', 'label' => 'Resolved'],
        'closed' => ['class' => 'badge-secondary', 'icon' => 'lock', 'label' => 'Closed'],
    ];
    $s = $map[$status] ?? $map['open'];
    return '<span class="badge ' . $s['class'] . '" style="font-size: 13px; padding: 6px 14px;"><i class="fas fa-' . $s['icon'] . '"></i> ' . $s['label'] . '</span>';
}

function ticketPriorityBadge($priority) {
    $map = [
        'low' => ['class' => 'badge-secondary', 'label' => 'Low'],
        'medium' => ['class' => 'badge-info', 'label' => 'Medium'],
        'high' => ['class' => 'badge-warning', 'label' => 'High'],
        'critical' => ['class' => 'badge-danger', 'label' => 'Critical'],
    ];
    $p = $map[$priority] ?? $map['medium'];
    return '<span class="badge ' . $p['class'] . '">' . $p['label'] . '</span>';
}

function categoryLabel($cat) {
    $map = ['general' => 'General', 'bug' => 'Bug / Error', 'feature_request' => 'Feature Request', 'billing' => 'Billing', 'account' => 'Account'];
    return $map[$cat] ?? ucfirst($cat);
}
?>

<div class="content-header">
    <div>
        <ul class="breadcrumb">
            <li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li>
            <li><a href="list.php">Support</a></li>
            <li><?= sanitizeOutput($ticket['ticket_uid']) ?></li>
        </ul>
        <h1 style="display: flex; align-items: center; gap: 12px;">
            <?= sanitizeOutput($ticket['ticket_uid']) ?>
            <?= ticketStatusBadge($ticket['status']) ?>
        </h1>
    </div>
    <a href="list.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back</a>
</div>

<!-- Ticket Info Card -->
<div class="grid-2 gap-24 mb-24">
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-info-circle" style="color: var(--primary);"></i> Ticket Details</h3></div>
        <div class="card-body">
            <h2 style="margin-bottom: 16px; font-size: 18px;"><?= sanitizeOutput($ticket['subject']) ?></h2>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px;">
                <div>
                    <span class="text-xs text-muted d-block mb-4">Category</span>
                    <strong><?= categoryLabel($ticket['category']) ?></strong>
                </div>
                <div>
                    <span class="text-xs text-muted d-block mb-4">Priority</span>
                    <?= ticketPriorityBadge($ticket['priority']) ?>
                </div>
                <div>
                    <span class="text-xs text-muted d-block mb-4">Created</span>
                    <strong><?= date('d M Y, h:i A', strtotime($ticket['created_at'])) ?></strong>
                </div>
                <div>
                    <span class="text-xs text-muted d-block mb-4">Last Updated</span>
                    <strong><?= date('d M Y, h:i A', strtotime($ticket['updated_at'])) ?></strong>
                </div>
                <?php if ($ticket['assigned_to']): ?>
                <div>
                    <span class="text-xs text-muted d-block mb-4">Assigned To</span>
                    <strong><?= sanitizeOutput($ticket['assigned_to']) ?></strong>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-align-left" style="color: var(--accent);"></i> Description</h3></div>
        <div class="card-body">
            <div style="white-space: pre-wrap; line-height: 1.7; color: var(--text-secondary); font-size: 14px;">
                <?= sanitizeOutput($ticket['description'] ?: 'No description provided.') ?>
            </div>
        </div>
    </div>
</div>

<!-- Conversation Thread -->
<div class="card mb-24" id="replies">
    <div class="card-header">
        <h3><i class="fas fa-comments" style="color: var(--success);"></i> Conversation <span class="badge badge-primary ml-8"><?= count($replies) ?></span></h3>
    </div>
    <div class="card-body">
        <?php if (empty($replies)): ?>
        <div class="text-center text-muted" style="padding: 30px;">
            <i class="fas fa-comments" style="font-size: 32px; opacity: 0.3; margin-bottom: 8px; display: block;"></i>
            No replies yet. Start the conversation below.
        </div>
        <?php else: foreach ($replies as $reply): 
            $isAdmin = ($reply['user_role'] === 'super_admin');
        ?>
        <div style="display: flex; gap: 14px; margin-bottom: 20px; padding: 16px; border-radius: 12px; background: <?= $isAdmin ? 'linear-gradient(135deg, #ecfdf5, #f0fdf4)' : 'var(--bg-secondary)' ?>; border-left: 3px solid <?= $isAdmin ? '#10b981' : 'var(--primary)' ?>;">
            <div style="width: 38px; height: 38px; border-radius: 50%; background: <?= $isAdmin ? '#10b981' : 'var(--primary)' ?>; color: white; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 14px;">
                <i class="fas fa-<?= $isAdmin ? 'headset' : 'user' ?>"></i>
            </div>
            <div style="flex: 1;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <strong style="font-size: 13px;"><?= sanitizeOutput($reply['user_name']) ?>
                        <?php if ($isAdmin): ?>
                            <span class="badge badge-success" style="font-size: 10px; margin-left: 6px;">Support Team</span>
                        <?php endif; ?>
                    </strong>
                    <span class="text-xs text-muted"><?= date('d M Y, h:i A', strtotime($reply['created_at'])) ?></span>
                </div>
                <div style="white-space: pre-wrap; color: var(--text-secondary); font-size: 14px; line-height: 1.6;">
                    <?= sanitizeOutput($reply['message']) ?>
                </div>
            </div>
        </div>
        <?php endforeach; endif; ?>

        <?php if (!in_array($ticket['status'], ['closed'])): ?>
        <!-- Reply Form -->
        <form method="POST" style="margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--border-color);">
            <div class="form-group">
                <label class="form-label"><i class="fas fa-reply"></i> Your Reply</label>
                <textarea name="message" class="form-control" rows="4" required placeholder="Type your reply here..."></textarea>
            </div>
            <div class="d-flex justify-end">
                <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Send Reply</button>
            </div>
        </form>
        <?php else: ?>
        <div class="text-center text-muted" style="padding: 16px; background: var(--bg-secondary); border-radius: 8px; margin-top: 16px;">
            <i class="fas fa-lock"></i> This ticket is closed. No further replies can be added.
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
