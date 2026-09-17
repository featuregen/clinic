<?php
/**
 * Appointment List & Calendar - Feature Gen Care
 */
$pageTitle = 'Appointments';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';
requirePermission('appointments.view');

$db = db();
$clinicId = getCurrentClinicId();
$today = date('Y-m-d');

// Filters - From/To date range (default: today)
$filterFromDate = sanitize($_GET['from_date'] ?? ($_GET['date'] ?? $today));
$filterToDate = sanitize($_GET['to_date'] ?? $filterFromDate);
$filterDoctor = intval($_GET['doctor'] ?? 0);
$filterStatus = sanitize($_GET['status'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));

// Ensure from <= to
if ($filterFromDate > $filterToDate) {
    $temp = $filterFromDate;
    $filterFromDate = $filterToDate;
    $filterToDate = $temp;
}

$isDateRange = ($filterFromDate !== $filterToDate);

$where = "WHERE a.clinic_id = ?";
$params = [$clinicId];

$where .= " AND a.appointment_date >= ? AND a.appointment_date <= ?";
$params[] = $filterFromDate;
$params[] = $filterToDate;

if ($filterDoctor) {
    $where .= " AND a.doctor_id = ?";
    $params[] = $filterDoctor;
}
if ($filterStatus) {
    $where .= " AND a.status = ?";
    $params[] = $filterStatus;
}

// If doctor role, filter by their own
if (getCurrentUserRole() === ROLE_DOCTOR) {
    $doctorRecord = $db->fetch("SELECT id FROM doctors WHERE user_id = ?", [getCurrentUserId()]);
    if ($doctorRecord) {
        $where .= " AND a.doctor_id = ?";
        $params[] = $doctorRecord['id'];
    }
}

$totalCount = $db->fetch("SELECT COUNT(*) as total FROM appointments a $where", $params)['total'];
$pagination = paginate($totalCount, $page);

$appointments = $db->fetchAll(
    "SELECT a.*, p.first_name, p.last_name, p.patient_uid, p.phone as patient_phone,
            u.full_name as doctor_name, s.name as specialty_name
     FROM appointments a
     JOIN patients p ON a.patient_id = p.id
     JOIN doctors d ON a.doctor_id = d.id
     JOIN users u ON d.user_id = u.id
     LEFT JOIN specialties s ON d.specialty_id = s.id
     $where ORDER BY a.appointment_date ASC, a.appointment_time ASC
     LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}",
    $params
);

// Doctors list for filter
$doctors = $db->fetchAll(
    "SELECT d.id, u.full_name FROM doctors d JOIN users u ON d.user_id = u.id WHERE d.clinic_id = ? AND d.is_available = 1",
    [$clinicId]
);

// Stats for the selected date range
$statsParams = [$clinicId, $filterFromDate, $filterToDate];
$dayStats = $db->fetchAll(
    "SELECT status, COUNT(*) as count FROM appointments WHERE clinic_id = ? AND appointment_date >= ? AND appointment_date <= ? GROUP BY status",
    $statsParams
);
$statsMap = array_column($dayStats, 'count', 'status');

// Build date display text
if ($isDateRange) {
    $dateDisplayText = formatDate($filterFromDate) . ' — ' . formatDate($filterToDate);
} else {
    $dateDisplayText = formatDate($filterFromDate);
}
?>

<div class="content-header">
    <div>
        <ul class="breadcrumb">
            <li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li>
            <li>Appointments</li>
        </ul>
        <h1>Appointment Management</h1>
    </div>
    <?php if (hasPermission('appointments.create')): ?>
    <a href="<?= BASE_URL ?>/modules/appointments/book.php" class="btn btn-primary">
        <i class="fas fa-calendar-plus"></i> Book Appointment
    </a>
    <?php endif; ?>
</div>

<!-- Day Stats -->
<div class="grid-4 mb-24">
    <div class="stat-card">
        <div class="stat-icon primary"><i class="fas fa-calendar-day"></i></div>
        <div class="stat-details">
            <div class="stat-label">Total</div>
            <div class="stat-value"><?= array_sum($statsMap) ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon success"><i class="fas fa-check-circle"></i></div>
        <div class="stat-details">
            <div class="stat-label">Completed</div>
            <div class="stat-value"><?= $statsMap['completed'] ?? 0 ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon warning"><i class="fas fa-clock"></i></div>
        <div class="stat-details">
            <div class="stat-label">Waiting</div>
            <div class="stat-value"><?= ($statsMap['scheduled'] ?? 0) + ($statsMap['confirmed'] ?? 0) + ($statsMap['checked_in'] ?? 0) ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon danger"><i class="fas fa-times-circle"></i></div>
        <div class="stat-details">
            <div class="stat-label">Cancelled</div>
            <div class="stat-value"><?= ($statsMap['cancelled'] ?? 0) + ($statsMap['no_show'] ?? 0) ?></div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-24">
    <div class="card-body">
        <form method="GET" class="d-flex gap-12 align-center flex-wrap">
            <div class="form-group mb-0">
                <label class="form-label" style="font-size: 11px; font-weight: 600; margin-bottom: 4px; color: var(--text-muted);">From Date</label>
                <input type="date" name="from_date" class="form-control" value="<?= $filterFromDate ?>" style="width: 165px;">
            </div>
            <div class="form-group mb-0">
                <label class="form-label" style="font-size: 11px; font-weight: 600; margin-bottom: 4px; color: var(--text-muted);">To Date</label>
                <input type="date" name="to_date" class="form-control" value="<?= $filterToDate ?>" style="width: 165px;">
            </div>
            <div class="form-group mb-0">
                <label class="form-label" style="font-size: 11px; font-weight: 600; margin-bottom: 4px; color: var(--text-muted);">Doctor</label>
                <select name="doctor" class="form-control" style="width: auto; min-width: 160px;">
                    <option value="">All Doctors</option>
                    <?php foreach ($doctors as $doc): ?>
                    <option value="<?= $doc['id'] ?>" <?= $filterDoctor == $doc['id'] ? 'selected' : '' ?>><?= sanitizeOutput($doc['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group mb-0">
                <label class="form-label" style="font-size: 11px; font-weight: 600; margin-bottom: 4px; color: var(--text-muted);">Status</label>
                <select name="status" class="form-control" style="width: auto; min-width: 140px;">
                    <option value="">All Status</option>
                    <?php foreach (APPOINTMENT_STATUS as $key => $label): ?>
                    <option value="<?= $key ?>" <?= $filterStatus === $key ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group mb-0" style="align-self: flex-end;">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Filter</button>
            </div>
            <div class="form-group mb-0" style="align-self: flex-end;">
                <a href="<?= BASE_URL ?>/modules/appointments/list.php" class="btn btn-outline btn-sm"><i class="fas fa-times"></i> Clear</a>
            </div>
            <!-- Quick date shortcuts -->
            <div class="form-group mb-0" style="align-self: flex-end; display: flex; gap: 6px;">
                <a href="<?= BASE_URL ?>/modules/appointments/list.php?from_date=<?= $today ?>&to_date=<?= $today ?>" 
                   class="btn btn-sm <?= ($filterFromDate === $today && $filterToDate === $today) ? 'btn-primary' : 'btn-outline' ?>" 
                   style="font-size: 11px; padding: 4px 10px;">Today</a>
                <a href="<?= BASE_URL ?>/modules/appointments/list.php?from_date=<?= date('Y-m-d', strtotime('monday this week')) ?>&to_date=<?= date('Y-m-d', strtotime('sunday this week')) ?>" 
                   class="btn btn-sm btn-outline" style="font-size: 11px; padding: 4px 10px;">This Week</a>
                <a href="<?= BASE_URL ?>/modules/appointments/list.php?from_date=<?= date('Y-m-01') ?>&to_date=<?= date('Y-m-t') ?>" 
                   class="btn btn-sm btn-outline" style="font-size: 11px; padding: 4px 10px;">This Month</a>
            </div>
        </form>
    </div>
</div>

<!-- Appointments Table -->
<div class="card">
    <div class="card-header">
        <h3>Appointments for <?= $dateDisplayText ?> <span class="badge badge-primary"><?= $totalCount ?></span></h3>
    </div>
    <div class="card-body p-0">
        <?php if (empty($appointments)): ?>
        <div class="empty-state">
            <i class="fas fa-calendar-times"></i>
            <h3>No appointments found</h3>
            <p>No appointments match your filter criteria</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Token</th>
                        <th>Patient</th>
                        <?php if ($isDateRange): ?><th>Date</th><?php endif; ?>
                        <th>Time</th>
                        <th>Doctor</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Fee</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php 
                $prevDate = '';
                foreach ($appointments as $apt): 
                    $aptDate = $apt['appointment_date'];
                ?>
                <?php if ($isDateRange && $aptDate !== $prevDate): 
                    $prevDate = $aptDate;
                    $colSpan = 9;
                ?>
                <tr style="background: var(--bg-surface, #f8fafc);">
                    <td colspan="<?= $colSpan ?>" style="font-weight: 700; font-size: 13px; color: var(--primary); padding: 8px 16px;">
                        <i class="fas fa-calendar-day" style="margin-right: 4px;"></i> <?= formatDate($aptDate) ?>
                    </td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td><span class="badge badge-primary">#<?= $apt['token_number'] ?? '-' ?></span></td>
                    <td>
                        <div class="patient-cell">
                            <div class="user-avatar" style="width: 32px; height: 32px; font-size: 11px;">
                                <?= getInitials($apt['first_name'] . ' ' . $apt['last_name']) ?>
                            </div>
                            <div>
                                <a href="<?= BASE_URL ?>/modules/patients/view.php?id=<?= $apt['patient_id'] ?>" class="font-semibold">
                                    <?= sanitizeOutput($apt['first_name'] . ' ' . $apt['last_name']) ?>
                                </a>
                                <div class="text-muted" style="font-size: 11px;"><?= sanitizeOutput($apt['patient_uid']) ?></div>
                            </div>
                        </div>
                    </td>
                    <?php if ($isDateRange): ?>
                    <td style="font-size: 12px; white-space: nowrap;"><?= date('d M', strtotime($aptDate)) ?></td>
                    <?php endif; ?>
                    <td><strong><?= formatTime($apt['appointment_time']) ?></strong></td>
                    <td>
                        <?= sanitizeOutput($apt['doctor_name']) ?>
                        <?php if ($apt['specialty_name']): ?>
                        <div class="text-muted" style="font-size: 11px;"><?= sanitizeOutput($apt['specialty_name']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge badge-secondary"><?= ucfirst($apt['appointment_type']) ?></span></td>
                    <td><?= getStatusBadge($apt['status']) ?></td>
                    <td>
                        <?= formatCurrency($apt['consultation_fee']) ?>
                        <span class="badge badge-<?= $apt['fee_status'] === 'paid' ? 'success' : 'warning' ?>" style="font-size: 10px;"><?= ucfirst($apt['fee_status']) ?></span>
                    </td>
                    <td>
                        <div class="actions">
                            <?php if ($apt['status'] === 'scheduled' || $apt['status'] === 'confirmed'): ?>
                            <button class="btn btn-sm btn-success" title="Check In" onclick="updateStatus(<?= $apt['id'] ?>, 'checked_in')">
                                <i class="fas fa-sign-in-alt"></i>
                            </button>
                            <?php endif; ?>
                            <?php if ($apt['status'] === 'checked_in' || $apt['status'] === 'in_progress'): ?>
                            <a href="<?= BASE_URL ?>/modules/prescriptions/create.php?appointment_id=<?= $apt['id'] ?>&patient_id=<?= $apt['patient_id'] ?>" 
                               class="btn btn-sm btn-primary" title="Write Prescription">
                                <i class="fas fa-prescription"></i>
                            </a>
                            <?php endif; ?>
                            <?php if (!in_array($apt['status'], ['completed', 'cancelled'])): ?>
                            <button class="btn btn-sm btn-ghost" title="Cancel" onclick="updateStatus(<?= $apt['id'] ?>, 'cancelled')">
                                <i class="fas fa-times"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= renderPagination($pagination, BASE_URL . '/modules/appointments/list.php') ?>
        <?php endif; ?>
    </div>
</div>

<script>
async function updateStatus(appointmentId, status) {
    if (status === 'cancelled' && !confirm('Are you sure you want to cancel this appointment?')) return;
    
    try {
        const response = await fetch(`${BASE_URL}/modules/appointments/update_status.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: appointmentId, status: status })
        });
        const data = await response.json();
        if (data.success) {
            showToast(data.message, 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast(data.error, 'error');
        }
    } catch (error) {
        showToast('Failed to update status', 'error');
    }
}
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>

