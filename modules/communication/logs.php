<?php
/**
 * Communication Logs - Advanced Clinic Suite
 */
$pageTitle = 'Communication Logs';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';
requirePermission('communication.view');

$db = db();
$clinicId = getCurrentClinicId();

// Filters
$channel = sanitize($_GET['channel'] ?? '');
$status = sanitize($_GET['status'] ?? '');
$from = sanitize($_GET['from'] ?? '');
$to = sanitize($_GET['to'] ?? '');
$search = sanitize($_GET['search'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));

$where = "WHERE cl.clinic_id = ?";
$params = [$clinicId];

if ($channel) { $where .= " AND cl.channel = ?"; $params[] = $channel; }
if ($status) { $where .= " AND cl.status = ?"; $params[] = $status; }
if ($from) { $where .= " AND DATE(cl.created_at) >= ?"; $params[] = $from; }
if ($to) { $where .= " AND DATE(cl.created_at) <= ?"; $params[] = $to; }
if ($search) {
    $where .= " AND (cl.recipient LIKE ? OR cl.subject LIKE ? OR cl.message LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ?)";
    $s = "%$search%"; $params = array_merge($params, [$s, $s, $s, $s, $s]);
}

$total = $db->fetch("SELECT COUNT(*) as c FROM communication_logs cl LEFT JOIN patients p ON cl.patient_id = p.id $where", $params)['c'];
$pagination = paginate($total, $page);

$logs = $db->fetchAll(
    "SELECT cl.*, p.first_name, p.last_name, mt.name as template_name
     FROM communication_logs cl
     LEFT JOIN patients p ON cl.patient_id = p.id
     LEFT JOIN message_templates mt ON cl.template_id = mt.id
     $where ORDER BY cl.created_at DESC
     LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}", $params
);

// Stats
try {
    $totalSent = $db->fetch("SELECT COUNT(*) as c FROM communication_logs WHERE clinic_id = ? AND status IN ('sent','delivered','read')", [$clinicId])['c'];
    $totalFailed = $db->fetch("SELECT COUNT(*) as c FROM communication_logs WHERE clinic_id = ? AND status = 'failed'", [$clinicId])['c'];
    $totalQueued = $db->fetch("SELECT COUNT(*) as c FROM communication_logs WHERE clinic_id = ? AND status = 'queued'", [$clinicId])['c'];
} catch (Exception $e) { $totalSent = $totalFailed = $totalQueued = 0; }
?>

<div class="content-header">
    <div>
        <ul class="breadcrumb">
            <li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li>
            <li><a href="<?= BASE_URL ?>/modules/communication/templates.php">Messages</a></li>
            <li>Logs</li>
        </ul>
        <h1>Communication Logs</h1>
    </div>
    <a href="<?= BASE_URL ?>/modules/communication/templates.php" class="btn btn-outline"><i class="fas fa-clone"></i> Templates</a>
</div>

<!-- Stats -->
<div class="grid-3 mb-24" style="gap: 16px;">
    <div class="stat-card">
        <div class="stat-icon success"><i class="fas fa-check-circle"></i></div>
        <div class="stat-details"><div class="stat-label">Sent / Delivered</div><div class="stat-value"><?= $totalSent ?></div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon danger"><i class="fas fa-times-circle"></i></div>
        <div class="stat-details"><div class="stat-label">Failed</div><div class="stat-value"><?= $totalFailed ?></div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon warning"><i class="fas fa-clock"></i></div>
        <div class="stat-details"><div class="stat-label">Queued</div><div class="stat-value"><?= $totalQueued ?></div></div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-24">
    <div class="card-body">
        <form method="GET" class="d-flex gap-12 align-center flex-wrap">
            <div class="header-search" style="max-width: 220px; flex: 1;">
                <i class="fas fa-search"></i>
                <input type="text" name="search" value="<?= sanitizeOutput($search) ?>"
                       placeholder="Search..." class="form-control" style="padding-left: 38px;">
            </div>
            <select name="channel" class="form-control" style="width: auto;">
                <option value="">All Channels</option>
                <option value="sms" <?= $channel === 'sms' ? 'selected' : '' ?>>SMS</option>
                <option value="whatsapp" <?= $channel === 'whatsapp' ? 'selected' : '' ?>>WhatsApp</option>
                <option value="email" <?= $channel === 'email' ? 'selected' : '' ?>>Email</option>
            </select>
            <select name="status" class="form-control" style="width: auto;">
                <option value="">All Status</option>
                <?php foreach (['queued' => 'Queued', 'sent' => 'Sent', 'delivered' => 'Delivered', 'failed' => 'Failed', 'read' => 'Read'] as $k => $v): ?>
                <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $v ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="from" class="form-control" value="<?= $from ?>" style="width: auto;">
            <input type="date" name="to" class="form-control" value="<?= $to ?>" style="width: auto;">
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i></button>
            <a href="<?= BASE_URL ?>/modules/communication/logs.php" class="btn btn-outline btn-sm"><i class="fas fa-times"></i></a>
        </form>
    </div>
</div>

<!-- Logs Table -->
<div class="card">
    <div class="card-header"><h3>Logs <span class="badge badge-primary"><?= $total ?></span></h3></div>
    <div class="card-body p-0">
        <?php if (empty($logs)): ?>
        <div class="empty-state"><i class="fas fa-inbox"></i><h3>No logs found</h3></div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Date</th><th>Channel</th><th>Recipient</th><th>Patient</th><th>Subject / Template</th><th>Status</th><th>Message</th></tr></thead>
                <tbody>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td style="white-space: nowrap; font-size: 12px;"><?= formatDateTime($log['created_at']) ?></td>
                    <td>
                        <?php
                        $chColors = ['sms' => 'primary', 'whatsapp' => 'success', 'email' => 'info'];
                        ?>
                        <span class="badge badge-<?= $chColors[$log['channel']] ?? 'secondary' ?>"><?= ucfirst($log['channel']) ?></span>
                    </td>
                    <td style="font-size: 13px;"><?= sanitizeOutput($log['recipient']) ?></td>
                    <td>
                        <?php if ($log['first_name']): ?>
                        <a href="<?= BASE_URL ?>/modules/patients/view.php?id=<?= $log['patient_id'] ?>" class="font-semibold"><?= sanitizeOutput($log['first_name'] . ' ' . $log['last_name']) ?></a>
                        <?php else: echo '-'; endif; ?>
                    </td>
                    <td style="font-size: 12px;">
                        <?= sanitizeOutput($log['subject'] ?? ($log['template_name'] ?? '-')) ?>
                    </td>
                    <td>
                        <?php
                        $stColors = ['queued' => 'warning', 'sent' => 'info', 'delivered' => 'success', 'failed' => 'danger', 'read' => 'primary'];
                        ?>
                        <span class="badge badge-<?= $stColors[$log['status']] ?? 'secondary' ?>"><?= ucfirst($log['status']) ?></span>
                        <?php if ($log['error_message']): ?>
                        <div style="font-size: 10px; color: var(--danger); margin-top: 2px;"><?= sanitizeOutput(truncateText($log['error_message'], 40)) ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="font-size: 12px; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                        <?= sanitizeOutput(truncateText($log['message'], 60)) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= renderPagination($pagination, BASE_URL . '/modules/communication/logs.php') ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
