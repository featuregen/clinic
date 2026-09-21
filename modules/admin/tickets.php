<?php
/**
 * Support Tickets (Super Admin)
 */
require_once dirname(dirname(__DIR__)) . '/config/session.php';
requireAuth();

if (getCurrentUserRole() !== ROLE_SUPER_ADMIN) {
    setFlashMessage('Access denied', 'error');
    header('Location: ' . BASE_URL . '/modules/dashboard/index.php');
    exit;
}

$db = db();
try {
    $masterConn = $db->getMasterConnection();
} catch (Exception $e) {
    setFlashMessage('Unable to connect to support system: ' . $e->getMessage(), 'error');
    header('Location: ' . BASE_URL . '/modules/dashboard/index.php');
    exit;
}

// Filters
$statusFilter = sanitize($_GET['status'] ?? '');
$priorityFilter = sanitize($_GET['priority'] ?? '');
$categoryFilter = sanitize($_GET['category'] ?? '');

$where = "WHERE 1=1";
$params = [];

if ($statusFilter) { $where .= " AND status = ?"; $params[] = $statusFilter; }
if ($priorityFilter) { $where .= " AND priority = ?"; $params[] = $priorityFilter; }
if ($categoryFilter) { $where .= " AND category = ?"; $params[] = $categoryFilter; }

$tickets = [];
try {
    $stmt = $masterConn->prepare("SELECT * FROM support_tickets $where ORDER BY 
        CASE status WHEN 'open' THEN 1 WHEN 'in_progress' THEN 2 WHEN 'resolved' THEN 3 WHEN 'closed' THEN 4 END,
        CASE priority WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 END,
        created_at DESC");
    $stmt->execute($params);
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Counts
$counts = ['open' => 0, 'in_progress' => 0, 'resolved' => 0, 'closed' => 0];
try {
    $stmt = $masterConn->query("SELECT status, COUNT(*) as cnt FROM support_tickets GROUP BY status");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $counts[$row['status']] = $row['cnt'];
    }
} catch (Exception $e) {}
$totalTickets = array_sum($counts);

// Handle quick status changes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    $tId = intval($_POST['ticket_id'] ?? 0);
    $action = $_POST['action'];
    
    try {
        if ($action === 'assign') {
            $masterConn->prepare("UPDATE support_tickets SET assigned_to = ?, status = 'in_progress', updated_at = NOW() WHERE id = ?")
                ->execute([$_SESSION['full_name'] ?? 'Super Admin', $tId]);
        } elseif ($action === 'resolve') {
            $masterConn->prepare("UPDATE support_tickets SET status = 'resolved', resolved_at = NOW(), updated_at = NOW() WHERE id = ?")
                ->execute([$tId]);
        } elseif ($action === 'close') {
            $masterConn->prepare("UPDATE support_tickets SET status = 'closed', closed_at = NOW(), updated_at = NOW() WHERE id = ?")
                ->execute([$tId]);
        } elseif ($action === 'reopen') {
            $masterConn->prepare("UPDATE support_tickets SET status = 'open', resolved_at = NULL, closed_at = NULL, updated_at = NOW() WHERE id = ?")
                ->execute([$tId]);
        }
    } catch (Exception $e) {
        setFlashMessage('Action failed: ' . $e->getMessage(), 'error');
    }
    header('Location: tickets.php' . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit;
}

// NOW include header (after all redirects are done)
$pageTitle = 'Support Tickets';
require_once INCLUDES_PATH . '/header.php';

function tStatusBadge($status) {
    $map = [
        'open' => ['class' => 'badge-info', 'icon' => 'envelope-open', 'label' => 'Open'],
        'in_progress' => ['class' => 'badge-warning', 'icon' => 'spinner', 'label' => 'In Progress'],
        'resolved' => ['class' => 'badge-success', 'icon' => 'check-circle', 'label' => 'Resolved'],
        'closed' => ['class' => 'badge-secondary', 'icon' => 'lock', 'label' => 'Closed'],
    ];
    $s = $map[$status] ?? $map['open'];
    return '<span class="badge ' . $s['class'] . '"><i class="fas fa-' . $s['icon'] . '"></i> ' . $s['label'] . '</span>';
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
?>

<div class="content-header">
    <div>
        <ul class="breadcrumb">
            <li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li>
            <li>Support Tickets</li>
        </ul>
        <h1>Support Tickets</h1>
    </div>
</div>

<!-- Stats -->
<div class="grid-4 gap-16 mb-24">
    <a href="?status=" class="stat-card" style="text-decoration:none;">
        <div class="stat-icon primary"><i class="fas fa-ticket-alt"></i></div>
        <div class="stat-details">
            <div class="stat-number"><?= $totalTickets ?></div>
            <div class="stat-label">Total</div>
        </div>
    </a>
    <a href="?status=open" class="stat-card" style="text-decoration:none; <?= $counts['open'] > 0 ? 'border-left: 3px solid #0891b2;' : '' ?>">
        <div class="stat-icon info"><i class="fas fa-envelope-open"></i></div>
        <div class="stat-details">
            <div class="stat-number"><?= $counts['open'] ?></div>
            <div class="stat-label">Open</div>
        </div>
    </a>
    <a href="?status=in_progress" class="stat-card" style="text-decoration:none;">
        <div class="stat-icon warning"><i class="fas fa-spinner"></i></div>
        <div class="stat-details">
            <div class="stat-number"><?= $counts['in_progress'] ?></div>
            <div class="stat-label">In Progress</div>
        </div>
    </a>
    <a href="?status=resolved" class="stat-card" style="text-decoration:none;">
        <div class="stat-icon success"><i class="fas fa-check-circle"></i></div>
        <div class="stat-details">
            <div class="stat-number"><?= $counts['resolved'] ?></div>
            <div class="stat-label">Resolved</div>
        </div>
    </a>
</div>

<!-- Filters -->
<div class="card mb-24">
    <div class="card-body" style="padding: 12px 20px;">
        <form method="GET" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
            <select name="status" class="form-control" style="width: auto; min-width: 140px;" onchange="this.form.submit()">
                <option value="">All Status</option>
                <option value="open" <?= $statusFilter === 'open' ? 'selected' : '' ?>>Open</option>
                <option value="in_progress" <?= $statusFilter === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                <option value="resolved" <?= $statusFilter === 'resolved' ? 'selected' : '' ?>>Resolved</option>
                <option value="closed" <?= $statusFilter === 'closed' ? 'selected' : '' ?>>Closed</option>
            </select>
            <select name="priority" class="form-control" style="width: auto; min-width: 140px;" onchange="this.form.submit()">
                <option value="">All Priority</option>
                <option value="critical" <?= $priorityFilter === 'critical' ? 'selected' : '' ?>>Critical</option>
                <option value="high" <?= $priorityFilter === 'high' ? 'selected' : '' ?>>High</option>
                <option value="medium" <?= $priorityFilter === 'medium' ? 'selected' : '' ?>>Medium</option>
                <option value="low" <?= $priorityFilter === 'low' ? 'selected' : '' ?>>Low</option>
            </select>
            <select name="category" class="form-control" style="width: auto; min-width: 160px;" onchange="this.form.submit()">
                <option value="">All Categories</option>
                <option value="bug" <?= $categoryFilter === 'bug' ? 'selected' : '' ?>>Bug</option>
                <option value="feature_request" <?= $categoryFilter === 'feature_request' ? 'selected' : '' ?>>Feature Request</option>
                <option value="billing" <?= $categoryFilter === 'billing' ? 'selected' : '' ?>>Billing</option>
                <option value="account" <?= $categoryFilter === 'account' ? 'selected' : '' ?>>Account</option>
                <option value="general" <?= $categoryFilter === 'general' ? 'selected' : '' ?>>General</option>
            </select>
            <?php if ($statusFilter || $priorityFilter || $categoryFilter): ?>
            <a href="tickets.php" class="btn btn-sm btn-ghost text-danger"><i class="fas fa-times"></i> Clear</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Tickets Table -->
<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Ticket ID</th>
                    <th>Clinic</th>
                    <th>Subject</th>
                    <th>Category</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>Assigned</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($tickets)): ?>
                <tr>
                    <td colspan="9" class="text-center text-muted" style="padding: 40px;">
                        <i class="fas fa-ticket-alt" style="font-size: 32px; opacity: 0.3; margin-bottom: 12px; display: block;"></i>
                        No tickets found.
                    </td>
                </tr>
                <?php else: foreach ($tickets as $t): ?>
                <tr>
                    <td><strong style="color: var(--primary);"><?= $t['ticket_uid'] ?></strong></td>
                    <td>
                        <div class="text-sm font-semibold"><?= sanitizeOutput($t['clinic_name']) ?></div>
                        <div class="text-xs text-muted"><?= sanitizeOutput($t['user_name']) ?></div>
                    </td>
                    <td>
                        <a href="ticket_view.php?id=<?= $t['id'] ?>" class="font-semibold"><?= sanitizeOutput(truncateText($t['subject'], 40)) ?></a>
                    </td>
                    <td class="text-sm"><?= ucfirst(str_replace('_', ' ', $t['category'])) ?></td>
                    <td><?= tPriorityBadge($t['priority']) ?></td>
                    <td><?= tStatusBadge($t['status']) ?></td>
                    <td class="text-sm"><?= sanitizeOutput($t['assigned_to'] ?: '—') ?></td>
                    <td class="text-sm text-muted"><?= date('d M, h:i A', strtotime($t['created_at'])) ?></td>
                    <td>
                        <div class="d-flex gap-4">
                            <a href="ticket_view.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-ghost" title="View"><i class="fas fa-eye"></i></a>
                            <?php if ($t['status'] === 'open'): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="ticket_id" value="<?= $t['id'] ?>">
                                <input type="hidden" name="action" value="assign">
                                <button class="btn btn-sm btn-ghost text-warning" title="Assign to me"><i class="fas fa-hand-point-right"></i></button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
