<?php
/**
 * Patient List - Feature Gen Care
 */
$pageTitle = 'Patients';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';
requirePermission('patients.view');

$db = db();
$clinicId = getCurrentClinicId();

// Search & Filters
$search = sanitize($_GET['search'] ?? '');
$gender = sanitize($_GET['gender'] ?? '');
$bloodGroup = sanitize($_GET['blood_group'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));

// Build query
$where = "WHERE p.clinic_id = ?";
$params = [$clinicId];

if ($search) {
    $where .= " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR p.patient_uid LIKE ? OR p.phone LIKE ? OR p.email LIKE ?)";
    $searchParam = "%$search%";
    $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam, $searchParam]);
}
if ($gender) {
    $where .= " AND p.gender = ?";
    $params[] = $gender;
}
if ($bloodGroup) {
    $where .= " AND p.blood_group = ?";
    $params[] = $bloodGroup;
}

// Count total
$totalCount = $db->fetch("SELECT COUNT(*) as total FROM patients p $where", $params)['total'];
$pagination = paginate($totalCount, $page);

// Fetch patients
$patients = $db->fetchAll(
    "SELECT p.*, 
            (SELECT COUNT(*) FROM appointments WHERE patient_id = p.id) as visit_count,
            (SELECT MAX(appointment_date) FROM appointments WHERE patient_id = p.id) as last_visit
     FROM patients p $where 
     ORDER BY p.created_at DESC 
     LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}",
    $params
);

// --- Patient Statistics for Overview Cards (Real Data) ---
try {
    // 1. Total Patients & Trend
    $statsTotalPatients = (int)($db->fetch("SELECT COUNT(*) as c FROM patients WHERE clinic_id = ?", [$clinicId])['c'] ?? 0);
    $statsPatientsThisMonth = (int)($db->fetch(
        "SELECT COUNT(*) as c FROM patients WHERE clinic_id = ? AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01 00:00:00')",
        [$clinicId]
    )['c'] ?? 0);
    $statsPatientsLastMonth = (int)($db->fetch(
        "SELECT COUNT(*) as c FROM patients WHERE clinic_id = ? 
         AND created_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01 00:00:00')
         AND created_at < DATE_FORMAT(CURDATE(), '%Y-%m-01 00:00:00')",
        [$clinicId]
    )['c'] ?? 0);

    // Patient base growth vs last month
    $prevPatientBase = $statsTotalPatients - $statsPatientsThisMonth;
    if ($prevPatientBase > 0) {
        $growthPct = round(($statsPatientsThisMonth / $prevPatientBase) * 100);
        $patientTrend = [
            'percent' => abs($growthPct) . '%',
            'is_up' => $growthPct >= 0,
            'label' => 'from last month'
        ];
    } elseif ($statsTotalPatients > 0) {
        $patientTrend = ['percent' => '100%', 'is_up' => true, 'label' => 'from last month'];
    } else {
        $patientTrend = ['percent' => '0%', 'is_up' => true, 'label' => 'from last month'];
    }

    // 2. Today's Appointments & Trend vs yesterday
    $statsTodayAppts = (int)($db->fetch(
        "SELECT COUNT(*) as c FROM appointments WHERE clinic_id = ? AND appointment_date = CURDATE()",
        [$clinicId]
    )['c'] ?? 0);
    $statsYesterdayAppts = (int)($db->fetch(
        "SELECT COUNT(*) as c FROM appointments WHERE clinic_id = ? AND appointment_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY)",
        [$clinicId]
    )['c'] ?? 0);

    if ($statsYesterdayAppts > 0) {
        $apptPct = round((($statsTodayAppts - $statsYesterdayAppts) / $statsYesterdayAppts) * 100);
        $apptTrend = [
            'percent' => abs($apptPct) . '%',
            'is_up' => $apptPct >= 0,
            'label' => 'from yesterday'
        ];
    } elseif ($statsTodayAppts > 0) {
        $apptTrend = ['percent' => '100%', 'is_up' => true, 'label' => 'from yesterday'];
    } else {
        $apptTrend = ['percent' => '0%', 'is_up' => true, 'label' => 'from yesterday'];
    }

    // 3. New Patients (This Month) & Trend vs last month
    $statsNewThisMonth = $statsPatientsThisMonth;
    if ($statsPatientsLastMonth > 0) {
        $newPct = round((($statsNewThisMonth - $statsPatientsLastMonth) / $statsPatientsLastMonth) * 100);
        $newPatientTrend = [
            'percent' => abs($newPct) . '%',
            'is_up' => $newPct >= 0,
            'label' => 'from last month'
        ];
    } elseif ($statsNewThisMonth > 0) {
        $newPatientTrend = ['percent' => '100%', 'is_up' => true, 'label' => 'from last month'];
    } else {
        $newPatientTrend = ['percent' => '0%', 'is_up' => true, 'label' => 'from last month'];
    }

    // 4. Total Visits & Trend vs last month
    $statsTotalVisits = (int)($db->fetch(
        "SELECT COUNT(*) as c FROM appointments WHERE clinic_id = ?",
        [$clinicId]
    )['c'] ?? 0);
    $statsVisitsThisMonth = (int)($db->fetch(
        "SELECT COUNT(*) as c FROM appointments WHERE clinic_id = ? AND appointment_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')",
        [$clinicId]
    )['c'] ?? 0);
    $statsVisitsLastMonth = (int)($db->fetch(
        "SELECT COUNT(*) as c FROM appointments WHERE clinic_id = ? 
         AND appointment_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01')
         AND appointment_date < DATE_FORMAT(CURDATE(), '%Y-%m-01')",
        [$clinicId]
    )['c'] ?? 0);

    if ($statsVisitsLastMonth > 0) {
        $visitPct = round((($statsVisitsThisMonth - $statsVisitsLastMonth) / $statsVisitsLastMonth) * 100);
        $visitTrend = [
            'percent' => abs($visitPct) . '%',
            'is_up' => $visitPct >= 0,
            'label' => 'from last month'
        ];
    } elseif ($statsVisitsThisMonth > 0) {
        $visitTrend = ['percent' => '100%', 'is_up' => true, 'label' => 'from last month'];
    } else {
        $visitTrend = ['percent' => '0%', 'is_up' => true, 'label' => 'from last month'];
    }
} catch (Exception $e) {
    $statsTotalPatients = $statsTodayAppts = $statsNewThisMonth = $statsTotalVisits = 0;
    $patientTrend = $apptTrend = $newPatientTrend = $visitTrend = [
        'percent' => '0%',
        'is_up' => true,
        'label' => 'from last month'
    ];
}
?>

<div class="content-header">
    <div>
        <ul class="breadcrumb">
            <li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li>
            <li>Patients</li>
        </ul>
        <h1>Patient Management</h1>
    </div>
    <?php if (hasPermission('patients.create')): ?>
    <a href="<?= BASE_URL ?>/modules/patients/add.php" class="btn btn-primary">
        <i class="fas fa-user-plus"></i> Add New Patient
    </a>
    <?php endif; ?>
</div>

<!-- Patient Stats Overview Cards -->
<div class="patient-stats-grid">
    <!-- Total Patients -->
    <div class="patient-stat-card">
        <div class="patient-stat-icon icon-blue">
            <i class="fas fa-users"></i>
        </div>
        <div class="patient-stat-info">
            <span class="patient-stat-label">Total Patients</span>
            <div class="patient-stat-data">
                <span class="patient-stat-value"><?= number_format($statsTotalPatients) ?></span>
                <div class="patient-stat-trend">
                    <span class="trend-badge <?= $patientTrend['is_up'] ? 'trend-up' : 'trend-down' ?>">
                        <i class="fas <?= $patientTrend['is_up'] ? 'fa-arrow-up' : 'fa-arrow-down' ?>"></i> <?= $patientTrend['percent'] ?>
                    </span>
                    <span class="trend-subtext"><?= $patientTrend['label'] ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Today's Appointments -->
    <div class="patient-stat-card">
        <div class="patient-stat-icon icon-teal">
            <i class="fas fa-calendar-check"></i>
        </div>
        <div class="patient-stat-info">
            <span class="patient-stat-label">Today's Appointments</span>
            <div class="patient-stat-data">
                <span class="patient-stat-value"><?= number_format($statsTodayAppts) ?></span>
                <div class="patient-stat-trend">
                    <span class="trend-badge <?= $apptTrend['is_up'] ? 'trend-up' : 'trend-down' ?>">
                        <i class="fas <?= $apptTrend['is_up'] ? 'fa-arrow-up' : 'fa-arrow-down' ?>"></i> <?= $apptTrend['percent'] ?>
                    </span>
                    <span class="trend-subtext"><?= $apptTrend['label'] ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- New Patients (This Month) -->
    <div class="patient-stat-card">
        <div class="patient-stat-icon icon-green">
            <i class="fas fa-user-plus"></i>
        </div>
        <div class="patient-stat-info">
            <span class="patient-stat-label">New Patients (This Month)</span>
            <div class="patient-stat-data">
                <span class="patient-stat-value"><?= number_format($statsNewThisMonth) ?></span>
                <div class="patient-stat-trend">
                    <span class="trend-badge <?= $newPatientTrend['is_up'] ? 'trend-up' : 'trend-down' ?>">
                        <i class="fas <?= $newPatientTrend['is_up'] ? 'fa-arrow-up' : 'fa-arrow-down' ?>"></i> <?= $newPatientTrend['percent'] ?>
                    </span>
                    <span class="trend-subtext"><?= $newPatientTrend['label'] ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Total Visits -->
    <div class="patient-stat-card">
        <div class="patient-stat-icon icon-indigo">
            <i class="fas fa-folder-open"></i>
        </div>
        <div class="patient-stat-info">
            <span class="patient-stat-label">Total Visits</span>
            <div class="patient-stat-data">
                <span class="patient-stat-value"><?= number_format($statsTotalVisits) ?></span>
                <div class="patient-stat-trend">
                    <span class="trend-badge <?= $visitTrend['is_up'] ? 'trend-up' : 'trend-down' ?>">
                        <i class="fas <?= $visitTrend['is_up'] ? 'fa-arrow-up' : 'fa-arrow-down' ?>"></i> <?= $visitTrend['percent'] ?>
                    </span>
                    <span class="trend-subtext"><?= $visitTrend['label'] ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-24">
    <div class="card-body">
        <form method="GET" class="d-flex gap-12 align-center flex-wrap">
            <div class="header-search" style="max-width: 300px; flex: 1;">
                <i class="fas fa-search"></i>
                <input type="text" name="search" value="<?= sanitizeOutput($search) ?>" 
                       placeholder="Search by name, ID, phone..." class="form-control" 
                       style="padding-left: 38px; border-radius: 50px;">
            </div>
            <select name="gender" class="form-control" style="width: auto; min-width: 140px;">
                <option value="">All Gender</option>
                <?php foreach (GENDER_OPTIONS as $g): ?>
                <option value="<?= $g ?>" <?= $gender === $g ? 'selected' : '' ?>><?= $g ?></option>
                <?php endforeach; ?>
            </select>
            <select name="blood_group" class="form-control" style="width: auto; min-width: 140px;">
                <option value="">All Blood Groups</option>
                <?php foreach (BLOOD_GROUPS as $bg): ?>
                <option value="<?= $bg ?>" <?= $bloodGroup === $bg ? 'selected' : '' ?>><?= $bg ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Filter</button>
            <a href="<?= BASE_URL ?>/modules/patients/list.php" class="btn btn-outline btn-sm"><i class="fas fa-times"></i> Clear</a>
        </form>
    </div>
</div>

<!-- Patient List -->
<div class="card">
    <div class="card-header">
        <h3>All Patients <span class="badge badge-primary"><?= $totalCount ?></span></h3>
    </div>
    <div class="card-body p-0">
        <?php if (empty($patients)): ?>
        <div class="empty-state">
            <i class="fas fa-users"></i>
            <h3>No patients found</h3>
            <p>Register your first patient to get started</p>
            <a href="<?= BASE_URL ?>/modules/patients/add.php" class="btn btn-primary"><i class="fas fa-user-plus"></i> Add Patient</a>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table" id="patientsTable">
                <thead>
                    <tr>
                        <th>Patient</th>
                        <th>Patient ID</th>
                        <th>Phone</th>
                        <th>Gender</th>
                        <th>Age</th>
                        <th>Blood</th>
                        <th>Visits</th>
                        <th>Last Visit</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($patients as $p): ?>
                    <tr>
                        <td>
                            <div class="patient-cell">
                                <div class="user-avatar" style="width: 36px; height: 36px; font-size: 12px;">
                                    <?= getInitials($p['first_name'] . ' ' . ($p['last_name'] ?? '')) ?>
                                </div>
                                <div>
                                    <a href="<?= BASE_URL ?>/modules/patients/view.php?id=<?= $p['id'] ?>" class="font-semibold">
                                        <?= sanitizeOutput($p['first_name'] . ' ' . ($p['last_name'] ?? '')) ?>
                                    </a>
                                    <?php if ($p['email']): ?>
                                    <div class="text-muted" style="font-size: 11px;"><?= sanitizeOutput($p['email']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td><span class="badge badge-secondary"><?= sanitizeOutput($p['patient_uid']) ?></span></td>
                        <td><?= sanitizeOutput($p['phone']) ?></td>
                        <td><?= sanitizeOutput($p['gender'] ?? '-') ?></td>
                        <td><?= $p['date_of_birth'] ? calculateAge($p['date_of_birth']) . 'y' : ($p['age'] ?? '-') ?></td>
                        <td><?= $p['blood_group'] ? '<span class="badge badge-danger">' . $p['blood_group'] . '</span>' : '-' ?></td>
                        <td><?= $p['visit_count'] ?></td>
                        <td><?= $p['last_visit'] ? formatDate($p['last_visit']) : '-' ?></td>
                        <td>
                            <div class="actions">
                                <a href="<?= BASE_URL ?>/modules/patients/view.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-ghost" title="View">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <?php if (hasPermission('patients.edit')): ?>
                                <a href="<?= BASE_URL ?>/modules/patients/add.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-ghost" title="Edit">
                                    <i class="fas fa-pen"></i>
                                </a>
                                <?php endif; ?>
                                <a href="<?= BASE_URL ?>/modules/appointments/book.php?patient_id=<?= $p['id'] ?>" class="btn btn-sm btn-ghost" title="Book Appointment">
                                    <i class="fas fa-calendar-plus"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= renderPagination($pagination, BASE_URL . '/modules/patients/list.php') ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
