<?php
/**
 * Support Tickets List (Clinic Side)
 */
$pageTitle = 'Support';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';

$db = db();
$masterConn = $db->getMasterConnection();
$tenantId = intval($db->tenantInfo['id'] ?? 0);

$statusFilter = sanitize($_GET['status'] ?? '');
$where = "WHERE tenant_id = ?";
$params = [$tenantId];

if ($statusFilter) {
    $where .= " AND status = ?";
    $params[] = $statusFilter;
}

$tickets = [];
try {
    $stmt = $masterConn->prepare("SELECT * FROM support_tickets $where ORDER BY 
        CASE status WHEN 'open' THEN 1 WHEN 'in_progress' THEN 2 WHEN 'resolved' THEN 3 WHEN 'closed' THEN 4 END,
        created_at DESC");
    $stmt->execute($params);
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Counts
$counts = ['open' => 0, 'in_progress' => 0, 'resolved' => 0, 'closed' => 0];
try {
    $stmt = $masterConn->prepare("SELECT status, COUNT(*) as cnt FROM support_tickets WHERE tenant_id = ? GROUP BY status");
    $stmt->execute([$tenantId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $counts[$row['status']] = $row['cnt'];
    }
} catch (Exception $e) {}
$totalTickets = array_sum($counts);

function ticketStatusBadge($status) {
    $map = [
        'open' => ['class' => 'badge-info', 'icon' => 'envelope-open', 'label' => 'Open'],
        'in_progress' => ['class' => 'badge-warning', 'icon' => 'spinner', 'label' => 'In Progress'],
        'resolved' => ['class' => 'badge-success', 'icon' => 'check-circle', 'label' => 'Resolved'],
        'closed' => ['class' => 'badge-secondary', 'icon' => 'lock', 'label' => 'Closed'],
    ];
    $s = $map[$status] ?? $map['open'];
    return '<span class="badge ' . $s['class'] . '"><i class="fas fa-' . $s['icon'] . '"></i> ' . $s['label'] . '</span>';
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

function ticketCategoryLabel($cat) {
    $map = [
        'general' => 'General',
        'bug' => 'Bug / Error',
        'feature_request' => 'Feature Request',
        'billing' => 'Billing',
        'account' => 'Account',
    ];
    return $map[$cat] ?? ucfirst($cat);
}
?>

<div class="content-header">
    <div>
        <ul class="breadcrumb">
            <li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li>
            <li>Support</li>
        </ul>
        <h1>Support Tickets</h1>
    </div>
    <a href="create.php" class="btn btn-primary"><i class="fas fa-plus"></i> New Ticket</a>
</div>

<!-- Stats -->
<div class="grid-4 gap-16 mb-24">
    <a href="?status=" class="stat-card" style="text-decoration:none;">
        <div class="stat-icon primary"><i class="fas fa-ticket-alt"></i></div>
        <div class="stat-details">
            <div class="stat-number"><?= $totalTickets ?></div>
            <div class="stat-label">Total Tickets</div>
        </div>
    </a>
    <a href="?status=open" class="stat-card" style="text-decoration:none;">
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

<!-- Ticket List -->
<div class="card">
    <div class="card-header">
        <h3>
            <?php if ($statusFilter): ?>
                <?= ucfirst(str_replace('_', ' ', $statusFilter)) ?> Tickets
            <?php else: ?>
                All Tickets
            <?php endif; ?>
            <span class="badge badge-primary ml-8"><?= count($tickets) ?></span>
        </h3>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Ticket ID</th>
                    <th>Subject</th>
                    <th>Category</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($tickets)): ?>
                <tr>
                    <td colspan="7" class="text-center text-muted" style="padding: 40px;">
                        <i class="fas fa-ticket-alt" style="font-size: 32px; opacity: 0.3; margin-bottom: 12px; display: block;"></i>
                        No tickets found. <a href="create.php">Create one</a>.
                    </td>
                </tr>
                <?php else: foreach ($tickets as $t): ?>
                <tr>
                    <td><strong style="color: var(--primary);"><?= $t['ticket_uid'] ?></strong></td>
                    <td>
                        <a href="view.php?id=<?= $t['id'] ?>" class="font-semibold"><?= sanitizeOutput(truncateText($t['subject'], 50)) ?></a>
                    </td>
                    <td><span class="text-sm"><?= ticketCategoryLabel($t['category']) ?></span></td>
                    <td><?= ticketPriorityBadge($t['priority']) ?></td>
                    <td><?= ticketStatusBadge($t['status']) ?></td>
                    <td class="text-sm text-muted"><?= formatDate($t['created_at']) ?></td>
                    <td>
                        <a href="view.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-ghost"><i class="fas fa-eye"></i></a>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
