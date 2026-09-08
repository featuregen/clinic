<?php
/**
 * Prescription List - Advanced Clinic Suite
 */
$pageTitle = 'Prescriptions';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';
requirePermission('prescriptions.view');

$db = db();
$clinicId = getCurrentClinicId();
$search = sanitize($_GET['search'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));

$where = "WHERE pr.clinic_id = ?";
$params = [$clinicId];

if ($search) {
    $where .= " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR p.patient_uid LIKE ? OR pr.diagnosis LIKE ?)";
    $s = "%$search%";
    $params = array_merge($params, [$s, $s, $s, $s]);
}

if (getCurrentUserRole() === ROLE_DOCTOR) {
    $doc = $db->fetch("SELECT id FROM doctors WHERE user_id = ?", [getCurrentUserId()]);
    if ($doc) { $where .= " AND pr.doctor_id = ?"; $params[] = $doc['id']; }
}

$total = $db->fetch("SELECT COUNT(*) as total FROM prescriptions pr JOIN patients p ON pr.patient_id = p.id $where", $params)['total'];
$pagination = paginate($total, $page);

$prescriptions = $db->fetchAll(
    "SELECT pr.*, p.first_name, p.last_name, p.patient_uid, u.full_name as doctor_name,
            (SELECT COUNT(*) FROM prescription_medicines WHERE prescription_id = pr.id) as med_count
     FROM prescriptions pr 
     JOIN patients p ON pr.patient_id = p.id 
     JOIN doctors d ON pr.doctor_id = d.id 
     JOIN users u ON d.user_id = u.id
     $where ORDER BY pr.prescription_date DESC, pr.created_at DESC
     LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}", $params
);
?>

<div class="content-header">
    <div>
        <ul class="breadcrumb">
            <li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li>
            <li>Prescriptions</li>
        </ul>
        <h1>Prescriptions</h1>
    </div>
    <a href="<?= BASE_URL ?>/modules/prescriptions/create.php" class="btn btn-primary">
        <i class="fas fa-prescription"></i> New Prescription
    </a>
</div>

<div class="card mb-24">
    <div class="card-body">
        <form method="GET" class="d-flex gap-12">
            <div class="header-search" style="max-width: 400px; flex: 1;">
                <i class="fas fa-search"></i>
                <input type="text" name="search" value="<?= sanitizeOutput($search) ?>" 
                       placeholder="Search patient, diagnosis..." class="form-control" style="padding-left: 38px; border-radius: 50px;">
            </div>
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i></button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3>All Prescriptions <span class="badge badge-primary"><?= $total ?></span></h3>
    </div>
    <div class="card-body p-0">
        <?php if (empty($prescriptions)): ?>
        <div class="empty-state"><i class="fas fa-file-prescription"></i><h3>No prescriptions found</h3></div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr><th>Date</th><th>Patient</th><th>Doctor</th><th>Diagnosis</th><th>Medicines</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($prescriptions as $rx): ?>
                <tr>
                    <td><?= formatDate($rx['prescription_date']) ?></td>
                    <td>
                        <a href="<?= BASE_URL ?>/modules/patients/view.php?id=<?= $rx['patient_id'] ?>" class="font-semibold">
                            <?= sanitizeOutput($rx['first_name'] . ' ' . $rx['last_name']) ?>
                        </a>
                        <div class="text-muted" style="font-size: 11px;"><?= sanitizeOutput($rx['patient_uid']) ?></div>
                    </td>
                    <td>Dr. <?= sanitizeOutput($rx['doctor_name']) ?></td>
                    <td><?= sanitizeOutput(truncateText($rx['diagnosis'] ?? '-', 40)) ?></td>
                    <td><span class="badge badge-info"><?= $rx['med_count'] ?> meds</span></td>
                    <td><?= getStatusBadge($rx['status'], 'prescription') ?></td>
                    <td>
                        <a href="<?= BASE_URL ?>/modules/prescriptions/view.php?id=<?= $rx['id'] ?>" class="btn btn-sm btn-ghost"><i class="fas fa-eye"></i></a>
                        <a href="<?= BASE_URL ?>/modules/prescriptions/create.php?id=<?= $rx['id'] ?>" class="btn btn-sm btn-ghost"><i class="fas fa-pen"></i></a>
                        <a href="<?= BASE_URL ?>/modules/prescriptions/print.php?id=<?= $rx['id'] ?>" target="_blank" class="btn btn-sm btn-ghost"><i class="fas fa-print"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= renderPagination($pagination, BASE_URL . '/modules/prescriptions/list.php') ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
