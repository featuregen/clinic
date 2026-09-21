<?php
/**
 * View/Manage Support Ticket (Super Admin)
 */
require_once dirname(dirname(__DIR__)) . '/config/session.php';
requireAuth();

if (getCurrentUserRole() !== ROLE_SUPER_ADMIN) {
    setFlashMessage('Access denied', 'error');
    header('Location: ' . BASE_URL . '/modules/dashboard/index.php');
    exit;
}

$db = db();
$adminName = $_SESSION['full_name'] ?? 'Super Admin';
$ticketId = intval($_GET['id'] ?? 0);

try {
    $masterConn = $db->getMasterConnection();
} catch (Exception $e) {
    setFlashMessage('Unable to connect to support system: ' . $e->getMessage(), 'error');
    header('Location: tickets.php');
    exit;
}

// Fetch ticket
$stmt = $masterConn->prepare("SELECT * FROM support_tickets WHERE id = ?");
$stmt->execute([$ticketId]);
$ticket = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ticket) {
    setFlashMessage('Ticket not found', 'error');
    header('Location: tickets.php');
    exit;
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'reply') {
            $message = sanitize($_POST['message'] ?? '');
            $isInternal = !empty($_POST['is_internal']) ? 1 : 0;
            if (!empty($message)) {
                $stmt = $masterConn->prepare("INSERT INTO ticket_replies (ticket_id, user_name, user_role, message, is_internal) VALUES (?, ?, 'super_admin', ?, ?)");
                $stmt->execute([$ticketId, $adminName, $message, $isInternal]);
                $masterConn->prepare("UPDATE support_tickets SET updated_at = NOW() WHERE id = ?")->execute([$ticketId]);
            }
        } elseif ($action === 'status') {
            $newStatus = sanitize($_POST['new_status'] ?? '');
            $validStatuses = ['open', 'in_progress', 'resolved', 'closed'];
            if (in_array($newStatus, $validStatuses)) {
                $extra = '';
                if ($newStatus === 'resolved') $extra = ', resolved_at = NOW()';
                if ($newStatus === 'closed') $extra = ', closed_at = NOW()';
                if ($newStatus === 'open') $extra = ', resolved_at = NULL, closed_at = NULL';
                $masterConn->prepare("UPDATE support_tickets SET status = ?, updated_at = NOW() $extra WHERE id = ?")->execute([$newStatus, $ticketId]);
                
                // Auto-add status change as reply
                $statusLabels = ['open' => 'Open', 'in_progress' => 'In Progress', 'resolved' => 'Resolved', 'closed' => 'Closed'];
                $masterConn->prepare("INSERT INTO ticket_replies (ticket_id, user_name, user_role, message, is_internal) VALUES (?, ?, 'super_admin', ?, 0)")
                    ->execute([$ticketId, $adminName, 'Status changed to: ' . ($statusLabels[$newStatus] ?? $newStatus)]);
            }
        } elseif ($action === 'assign') {
            $masterConn->prepare("UPDATE support_tickets SET assigned_to = ?, status = 'in_progress', updated_at = NOW() WHERE id = ?")->execute([$adminName, $ticketId]);
        } elseif ($action === 'priority') {
            $newPriority = sanitize($_POST['new_priority'] ?? 'medium');
            $masterConn->prepare("UPDATE support_tickets SET priority = ?, updated_at = NOW() WHERE id = ?")->execute([$newPriority, $ticketId]);
        }
    } catch (Exception $e) {
        setFlashMessage('Action failed: ' . $e->getMessage(), 'error');
    }
    
    header('Location: ticket_view.php?id=' . $ticketId . '#replies');
    exit;
}

// Re-fetch after possible changes
try {
    $stmt = $masterConn->prepare("SELECT * FROM support_tickets WHERE id = ?");
    $stmt->execute([$ticketId]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fetch ALL replies (including internal for super admin)
    $stmt = $masterConn->prepare("SELECT * FROM ticket_replies WHERE ticket_id = ? ORDER BY created_at ASC");
    $stmt->execute([$ticketId]);
    $replies = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $replies = [];
}

// NOW include header (after all redirects are done)
$pageTitle = 'View Ticket';
require_once INCLUDES_PATH . '/header.php';

function tStatusBadge($status) {
    $map = [
        'open' => ['class' => 'badge-info', 'icon' => 'envelope-open', 'label' => 'Open'],
        'in_progress' => ['class' => 'badge-warning', 'icon' => 'spinner', 'label' => 'In Progress'],
        'resolved' => ['class' => 'badge-success', 'icon' => 'check-circle', 'label' => 'Resolved'],
        'closed' => ['class' => 'badge-secondary', 'icon' => 'lock', 'label' => 'Closed'],
    ];
    $s = $map[$status] ?? $map['open'];
    return '<span class="badge ' . $s['class'] . '" style="font-size: 13px; padding: 6px 14px;"><i class="fas fa-' . $s['icon'] . '"></i> ' . $s['label'] . '</span>';
}

function tPriorityBadge($priority) {
    $map = [
        'low' => ['class' => 'badge-secondary', 'label' => 'Low'],
        'medium' => ['class' => 'badge-info', 'label' => 'Medium'],
        'high' => ['class' => 'badge-warning', 'label' => 'High'],
        'critical' => ['class' => 'badge-danger', 'label' => 'Critical'],
    ];
    $p = $map[$priority] ?? $map['medium'];
    return '<span class="badge ' . $p['class'] . '">' . $p['label'] . '</span>';
}

$categoryLabels = ['general' => 'General', 'bug' => 'Bug / Error', 'feature_request' => 'Feature Request', 'billing' => 'Billing', 'account' => 'Account'];
?>

<div class="content-header">
    <div>
        <ul class="breadcrumb">
            <li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li>
            <li><a href="tickets.php">Support Tickets</a></li>
            <li><?= sanitizeOutput($ticket['ticket_uid']) ?></li>
        </ul>
        <h1 style="display: flex; align-items: center; gap: 12px;">
            <?= sanitizeOutput($ticket['ticket_uid']) ?>
            <?= tStatusBadge($ticket['status']) ?>
        </h1>
    </div>
    <a href="tickets.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back</a>
</div>

<div class="grid-2 gap-24 mb-24">
    <!-- Ticket Details -->
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-info-circle" style="color: var(--primary);"></i> Ticket Details</h3></div>
        <div class="card-body">
            <h2 style="margin-bottom: 16px; font-size: 18px;"><?= sanitizeOutput($ticket['subject']) ?></h2>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px;">
                <div>
                    <span class="text-xs text-muted d-block mb-4">Clinic</span>
                    <strong><?= sanitizeOutput($ticket['clinic_name']) ?></strong>
                    <div class="text-xs text-muted">by <?= sanitizeOutput($ticket['user_name']) ?></div>
                </div>
                <div>
                    <span class="text-xs text-muted d-block mb-4">Category</span>
                    <strong><?= $categoryLabels[$ticket['category']] ?? ucfirst($ticket['category']) ?></strong>
                </div>
                <div>
                    <span class="text-xs text-muted d-block mb-4">Priority</span>
                    <?= tPriorityBadge($ticket['priority']) ?>
                </div>
                <div>
                    <span class="text-xs text-muted d-block mb-4">Assigned To</span>
                    <strong><?= sanitizeOutput($ticket['assigned_to'] ?: 'Unassigned') ?></strong>
                </div>
                <div>
                    <span class="text-xs text-muted d-block mb-4">Created</span>
                    <strong><?= date('d M Y, h:i A', strtotime($ticket['created_at'])) ?></strong>
                </div>
                <div>
                    <span class="text-xs text-muted d-block mb-4">Last Updated</span>
                    <strong><?= date('d M Y, h:i A', strtotime($ticket['updated_at'])) ?></strong>
                </div>
            </div>

            <div style="white-space: pre-wrap; background: var(--bg-secondary); padding: 16px; border-radius: 8px; font-size: 14px; line-height: 1.7; color: var(--text-secondary);">
                <?= sanitizeOutput($ticket['description'] ?: 'No description provided.') ?>
            </div>
        </div>
    </div>

    <!-- Actions Panel -->
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-cogs" style="color: var(--accent);"></i> Manage Ticket</h3></div>
        <div class="card-body">
            <!-- Assign -->
            <?php if (empty($ticket['assigned_to'])): ?>
            <form method="POST" class="mb-16">
                <input type="hidden" name="action" value="assign">
                <button class="btn btn-primary btn-block"><i class="fas fa-hand-point-right"></i> Assign to Me</button>
            </form>
            <?php else: ?>
            <div class="mb-16" style="padding: 12px; background: var(--bg-secondary); border-radius: 8px; text-align: center;">
                <span class="text-xs text-muted d-block mb-4">Currently Assigned To</span>
                <strong style="font-size: 14px;"><?= sanitizeOutput($ticket['assigned_to']) ?></strong>
            </div>
            <?php endif; ?>

            <!-- Status Change -->
            <form method="POST" class="mb-16">
                <input type="hidden" name="action" value="status">
                <label class="form-label text-xs">Change Status</label>
                <div class="d-flex gap-8">
                    <select name="new_status" class="form-control">
                        <option value="open" <?= $ticket['status'] === 'open' ? 'selected' : '' ?>>Open</option>
                        <option value="in_progress" <?= $ticket['status'] === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                        <option value="resolved" <?= $ticket['status'] === 'resolved' ? 'selected' : '' ?>>Resolved</option>
                        <option value="closed" <?= $ticket['status'] === 'closed' ? 'selected' : '' ?>>Closed</option>
                    </select>
                    <button class="btn btn-outline" style="white-space: nowrap;"><i class="fas fa-check"></i> Update</button>
                </div>
            </form>

            <!-- Priority Change -->
            <form method="POST" class="mb-16">
                <input type="hidden" name="action" value="priority">
                <label class="form-label text-xs">Change Priority</label>
                <div class="d-flex gap-8">
                    <select name="new_priority" class="form-control">
                        <option value="low" <?= $ticket['priority'] === 'low' ? 'selected' : '' ?>>Low</option>
                        <option value="medium" <?= $ticket['priority'] === 'medium' ? 'selected' : '' ?>>Medium</option>
                        <option value="high" <?= $ticket['priority'] === 'high' ? 'selected' : '' ?>>High</option>
                        <option value="critical" <?= $ticket['priority'] === 'critical' ? 'selected' : '' ?>>Critical</option>
                    </select>
                    <button class="btn btn-outline" style="white-space: nowrap;"><i class="fas fa-check"></i> Update</button>
                </div>
            </form>

            <!-- Quick Actions -->
            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                <?php if ($ticket['status'] !== 'resolved'): ?>
                <form method="POST"><input type="hidden" name="action" value="status"><input type="hidden" name="new_status" value="resolved">
                    <button class="btn btn-sm btn-success"><i class="fas fa-check-circle"></i> Mark Resolved</button>
                </form>
                <?php endif; ?>
                <?php if ($ticket['status'] !== 'closed'): ?>
                <form method="POST"><input type="hidden" name="action" value="status"><input type="hidden" name="new_status" value="closed">
                    <button class="btn btn-sm btn-secondary"><i class="fas fa-lock"></i> Close Ticket</button>
                </form>
                <?php endif; ?>
                <?php if (in_array($ticket['status'], ['resolved', 'closed'])): ?>
                <form method="POST"><input type="hidden" name="action" value="status"><input type="hidden" name="new_status" value="open">
                    <button class="btn btn-sm btn-info"><i class="fas fa-redo"></i> Reopen</button>
                </form>
                <?php endif; ?>
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
            No conversation yet.
        </div>
        <?php else: foreach ($replies as $reply): 
            $isAdmin = ($reply['user_role'] === 'super_admin');
            $isInternal = !empty($reply['is_internal']);
        ?>
        <div style="display: flex; gap: 14px; margin-bottom: 20px; padding: 16px; border-radius: 12px; background: <?= $isInternal ? 'linear-gradient(135deg, #fef3c7, #fffbeb)' : ($isAdmin ? 'linear-gradient(135deg, #ecfdf5, #f0fdf4)' : 'var(--bg-secondary)') ?>; border-left: 3px solid <?= $isInternal ? '#f59e0b' : ($isAdmin ? '#10b981' : 'var(--primary)') ?>;">
            <div style="width: 38px; height: 38px; border-radius: 50%; background: <?= $isInternal ? '#f59e0b' : ($isAdmin ? '#10b981' : 'var(--primary)') ?>; color: white; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 14px;">
                <i class="fas fa-<?= $isInternal ? 'sticky-note' : ($isAdmin ? 'headset' : 'user') ?>"></i>
            </div>
            <div style="flex: 1;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <strong style="font-size: 13px;"><?= sanitizeOutput($reply['user_name']) ?>
                        <?php if ($isInternal): ?>
                            <span class="badge badge-warning" style="font-size: 10px; margin-left: 6px;">Internal Note</span>
                        <?php elseif ($isAdmin): ?>
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

        <!-- Reply Form -->
        <form method="POST" style="margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--border-color);">
            <input type="hidden" name="action" value="reply">
            <div class="form-group">
                <label class="form-label"><i class="fas fa-reply"></i> Reply to Clinic</label>
                <textarea name="message" class="form-control" rows="4" required placeholder="Type your reply..."></textarea>
            </div>
            <div class="d-flex justify-between align-center">
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px; color: var(--text-muted);">
                    <input type="checkbox" name="is_internal" value="1">
                    <i class="fas fa-eye-slash"></i> Internal note (not visible to clinic)
                </label>
                <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Send Reply</button>
            </div>
        </form>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
