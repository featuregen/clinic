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
                                <button class="btn btn-sm btn-ghost" title="Quick View" onclick="openQuickView(<?= $p['id'] ?>)">
                                    <i class="fas fa-eye"></i>
                                </button>
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

<!-- Patient Quick View Panel -->
<div class="qv-overlay" id="qvOverlay" onclick="closeQuickView()"></div>
<div class="qv-panel" id="qvPanel">
    <div class="qv-header">
        <div class="qv-close" onclick="closeQuickView()"><i class="fas fa-times"></i></div>
    </div>
    <div class="qv-loading" id="qvLoading">
        <i class="fas fa-spinner fa-spin" style="font-size: 28px; color: var(--primary);"></i>
        <p style="margin-top: 12px; color: var(--text-muted);">Loading patient...</p>
    </div>
    <div class="qv-body" id="qvBody" style="display:none;">
        <!-- Patient Header -->
        <div class="qv-patient-header">
            <div class="qv-avatar" id="qvAvatar"></div>
            <div class="qv-patient-meta">
                <h3 id="qvName" class="qv-patient-name"></h3>
                <span class="badge badge-secondary" id="qvUid"></span>
                <span class="qv-status-badge" id="qvStatus"></span>
            </div>
        </div>

        <!-- Tabs -->
        <div class="qv-tabs">
            <button class="qv-tab active" data-tab="overview" onclick="switchQvTab('overview', this)">Overview</button>
            <button class="qv-tab" data-tab="medical" onclick="switchQvTab('medical', this)">Medical</button>
            <button class="qv-tab" data-tab="prescriptions" onclick="switchQvTab('prescriptions', this)">Prescriptions</button>
        </div>

        <!-- Overview Tab -->
        <div class="qv-tab-content active" id="qvTabOverview">
            <div class="qv-info-grid" id="qvInfoGrid"></div>

            <div class="qv-section" id="qvVisitSection">
                <div class="qv-info-row">
                    <i class="fas fa-calendar-check" style="color: var(--success);"></i>
                    <span class="qv-info-label">Last Visit</span>
                    <span class="qv-info-value" id="qvLastVisit">-</span>
                </div>
                <div class="qv-info-row">
                    <i class="fas fa-calendar-alt" style="color: var(--info);"></i>
                    <span class="qv-info-label">Next Appointment</span>
                    <span class="qv-info-value" id="qvNextAppt">-</span>
                </div>
                <div class="qv-info-row">
                    <i class="fas fa-clipboard-list" style="color: var(--primary);"></i>
                    <span class="qv-info-label">Total Visits</span>
                    <span class="qv-info-value" id="qvVisitCount">0</span>
                </div>
            </div>

            <div class="qv-section" id="qvDueSection" style="display:none;">
                <div class="qv-due-alert">
                    <i class="fas fa-exclamation-circle"></i>
                    Outstanding Dues: <strong id="qvDueAmount">₹ 0</strong>
                </div>
            </div>
        </div>

        <!-- Medical Tab -->
        <div class="qv-tab-content" id="qvTabMedical">
            <div class="qv-section">
                <h4 class="qv-section-title"><i class="fas fa-allergies"></i> Allergies</h4>
                <div id="qvAllergies" class="qv-tag-list"><span class="text-muted" style="font-size:13px;">None recorded</span></div>
            </div>
            <div class="qv-section">
                <h4 class="qv-section-title"><i class="fas fa-heartbeat"></i> Medical Conditions</h4>
                <div id="qvConditions" class="qv-tag-list"><span class="text-muted" style="font-size:13px;">None recorded</span></div>
            </div>
        </div>

        <!-- Prescriptions Tab -->
        <div class="qv-tab-content" id="qvTabPrescriptions">
            <div id="qvPrescriptions">
                <p class="text-muted" style="font-size: 13px; text-align: center; padding: 20px 0;">No prescriptions found</p>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="qv-actions">
            <h4 class="qv-section-title" style="margin-bottom: 10px;"><i class="fas fa-bolt"></i> Quick Actions</h4>
            <div class="qv-action-grid" id="qvActionGrid"></div>
        </div>

        <!-- View Full Profile -->
        <div class="qv-footer">
            <a id="qvFullProfileLink" href="#" class="btn btn-primary btn-block">
                <i class="fas fa-external-link-alt"></i> View Full Profile
            </a>
        </div>
    </div>
</div>

<style>
/* Quick View Panel */
.qv-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.35);
    z-index: 1000;
    backdrop-filter: blur(2px);
    transition: opacity 0.3s ease;
    opacity: 0;
}
.qv-overlay.show { display: block; opacity: 1; }

.qv-panel {
    position: fixed;
    top: 0;
    right: -420px;
    width: 400px;
    max-width: 92vw;
    height: 100vh;
    background: #fff;
    z-index: 1001;
    box-shadow: -8px 0 30px rgba(0,0,0,0.12);
    transition: right 0.35s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.qv-panel.show { right: 0; }

.qv-header {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    padding: 14px 18px 10px;
    border-bottom: 1px solid #f1f5f9;
}

.qv-close {
    width: 34px; height: 34px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer;
    color: #94a3b8;
    font-size: 16px;
    transition: all 0.2s;
}
.qv-close:hover { background: #f1f5f9; color: #334155; }

.qv-loading {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}

.qv-body {
    flex: 1;
    overflow-y: auto;
    padding: 0 20px 20px;
}

.qv-patient-header {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 16px 0;
}

.qv-avatar {
    width: 56px; height: 56px;
    border-radius: 50%;
    background: linear-gradient(135deg, #0d9488, #0891b2);
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; font-weight: 700;
    flex-shrink: 0;
    letter-spacing: 1px;
}

.qv-patient-name {
    font-size: 18px; font-weight: 700; color: #0f172a;
    margin: 0 0 6px;
}
.qv-patient-meta { flex: 1; }
.qv-patient-meta .badge { margin-right: 6px; }
.qv-status-badge {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}
.qv-status-badge.active { background: #ecfdf5; color: #059669; }
.qv-status-badge.inactive { background: #fef2f2; color: #dc2626; }

/* Tabs */
.qv-tabs {
    display: flex;
    border-bottom: 2px solid #f1f5f9;
    margin-bottom: 16px;
    gap: 4px;
}
.qv-tab {
    padding: 10px 16px;
    font-size: 13px;
    font-weight: 600;
    color: #94a3b8;
    background: none;
    border: none;
    cursor: pointer;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    transition: all 0.2s;
}
.qv-tab:hover { color: #0d9488; }
.qv-tab.active { color: #0d9488; border-bottom-color: #0d9488; }

.qv-tab-content { display: none; }
.qv-tab-content.active { display: block; }

/* Info Grid */
.qv-info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 16px;
}
.qv-info-item {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    font-size: 13px;
}
.qv-info-item i {
    color: #94a3b8;
    font-size: 14px;
    margin-top: 2px;
    width: 16px;
    text-align: center;
    flex-shrink: 0;
}
.qv-info-item .qv-il { color: #64748b; font-size: 11px; display: block; }
.qv-info-item .qv-iv { color: #0f172a; font-weight: 600; font-size: 13px; }

/* Sections */
.qv-section {
    background: #f8fafc;
    border-radius: 12px;
    padding: 14px;
    margin-bottom: 14px;
}
.qv-section-title {
    font-size: 13px; font-weight: 700; color: #334155;
    margin: 0 0 10px; display: flex; align-items: center; gap: 6px;
}
.qv-section-title i { font-size: 13px; color: #64748b; }

.qv-info-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 7px 0;
    font-size: 13px;
}
.qv-info-row:not(:last-child) { border-bottom: 1px solid #e2e8f0; }
.qv-info-row i { width: 16px; text-align: center; flex-shrink: 0; font-size: 13px; }
.qv-info-label { color: #64748b; flex: 1; }
.qv-info-value { color: #0f172a; font-weight: 600; text-align: right; }

/* Due alert */
.qv-due-alert {
    background: #fef2f2;
    color: #dc2626;
    border: 1px solid #fecaca;
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 13px;
    font-weight: 500;
    display: flex; align-items: center; gap: 8px;
}

/* Tags */
.qv-tag-list { display: flex; flex-wrap: wrap; gap: 6px; }
.qv-tag {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 4px 10px; border-radius: 16px;
    font-size: 12px; font-weight: 500;
}
.qv-tag.allergy { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
.qv-tag.allergy.severe { background: #dc2626; color: #fff; border-color: #dc2626; }
.qv-tag.condition { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }

/* Prescription cards */
.qv-rx-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 12px;
    margin-bottom: 10px;
}
.qv-rx-card .qv-rx-date { font-size: 12px; color: #64748b; }
.qv-rx-card .qv-rx-doctor { font-size: 13px; font-weight: 600; color: #0f172a; }
.qv-rx-card .qv-rx-meds { font-size: 12px; color: #64748b; margin-top: 4px; }

/* Quick Actions */
.qv-actions {
    padding-top: 14px;
    margin-top: 4px;
    border-top: 1px solid #f1f5f9;
}
.qv-action-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
}
.qv-action-btn {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    gap: 6px;
    padding: 14px 8px;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    background: #fff;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.2s;
    font-size: 11px; font-weight: 600; color: #334155;
}
.qv-action-btn:hover { background: #f8fafc; border-color: #cbd5e1; transform: translateY(-1px); box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
.qv-action-btn i { font-size: 18px; }
.qv-action-btn.act-appt i { color: #0d9488; }
.qv-action-btn.act-rx i { color: #7c3aed; }
.qv-action-btn.act-bill i { color: #2563eb; }
.qv-action-btn.act-edit i { color: #f59e0b; }

/* Footer */
.qv-footer {
    padding-top: 14px;
    margin-top: 14px;
    border-top: 1px solid #f1f5f9;
}
</style>

<script>
let qvCurrentPatientId = null;

function openQuickView(patientId) {
    qvCurrentPatientId = patientId;
    document.getElementById('qvOverlay').classList.add('show');
    document.getElementById('qvPanel').classList.add('show');
    document.getElementById('qvLoading').style.display = 'flex';
    document.getElementById('qvBody').style.display = 'none';
    document.body.style.overflow = 'hidden';

    // Reset to overview tab
    switchQvTab('overview', document.querySelector('.qv-tab[data-tab="overview"]'));

    fetch(`${BASE_URL}/modules/patients/quick_view.php?id=${patientId}`)
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                closeQuickView();
                showToast(data.error, 'error');
                return;
            }
            renderQuickView(data);
            document.getElementById('qvLoading').style.display = 'none';
            document.getElementById('qvBody').style.display = 'block';
        })
        .catch(err => {
            console.error('Quick view error:', err);
            closeQuickView();
            showToast('Failed to load patient data', 'error');
        });
}

function closeQuickView() {
    document.getElementById('qvOverlay').classList.remove('show');
    document.getElementById('qvPanel').classList.remove('show');
    document.body.style.overflow = '';
    qvCurrentPatientId = null;
}

// Close on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && qvCurrentPatientId) closeQuickView();
});

function switchQvTab(tab, btn) {
    document.querySelectorAll('.qv-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.qv-tab-content').forEach(c => c.classList.remove('active'));
    if (btn) btn.classList.add('active');
    var el = document.getElementById('qvTab' + tab.charAt(0).toUpperCase() + tab.slice(1));
    if (el) el.classList.add('active');
}

function renderQuickView(d) {
    // Avatar & Name
    document.getElementById('qvAvatar').textContent = d.initials || '?';
    document.getElementById('qvName').textContent = d.name;
    document.getElementById('qvUid').textContent = d.patient_uid;
    var statusEl = document.getElementById('qvStatus');
    statusEl.textContent = d.is_active ? 'Active' : 'Inactive';
    statusEl.className = 'qv-status-badge ' + (d.is_active ? 'active' : 'inactive');

    // Full profile link
    document.getElementById('qvFullProfileLink').href = `${BASE_URL}/modules/patients/view.php?id=${d.id}`;

    // Info Grid
    var infoItems = [];
    if (d.age || d.gender) {
        infoItems.push({icon: 'fa-user', label: 'Age / Gender', value: (d.age ? d.age + ' yrs' : '-') + ' / ' + (d.gender || '-')});
    }
    if (d.phone) infoItems.push({icon: 'fa-phone', label: 'Phone', value: d.phone});
    if (d.email) infoItems.push({icon: 'fa-envelope', label: 'Email', value: d.email});
    if (d.blood_group) infoItems.push({icon: 'fa-tint', label: 'Blood Group', value: d.blood_group});
    if (d.address) infoItems.push({icon: 'fa-map-marker-alt', label: 'Address', value: d.address});
    if (d.occupation) infoItems.push({icon: 'fa-briefcase', label: 'Occupation', value: d.occupation});

    var gridHtml = infoItems.map(function(item) {
        return '<div class="qv-info-item"><i class="fas ' + item.icon + '"></i><div><span class="qv-il">' + item.label + '</span><span class="qv-iv">' + escapeHtml(item.value) + '</span></div></div>';
    }).join('');
    document.getElementById('qvInfoGrid').innerHTML = gridHtml;

    // Visit info
    document.getElementById('qvLastVisit').textContent = d.last_visit ? formatQvDate(d.last_visit) : 'No visits yet';
    if (d.next_appointment) {
        document.getElementById('qvNextAppt').innerHTML = formatQvDate(d.next_appointment.date) + ', ' + formatQvTime(d.next_appointment.time) + '<br><span style="font-weight:400;font-size:11px;color:#64748b;">Dr. ' + escapeHtml(d.next_appointment.doctor) + '</span>';
    } else {
        document.getElementById('qvNextAppt').textContent = 'None scheduled';
    }
    document.getElementById('qvVisitCount').textContent = d.visit_count;

    // Outstanding dues
    var dueSection = document.getElementById('qvDueSection');
    if (d.outstanding_due > 0) {
        document.getElementById('qvDueAmount').textContent = '₹ ' + parseFloat(d.outstanding_due).toFixed(2);
        dueSection.style.display = 'block';
    } else {
        dueSection.style.display = 'none';
    }

    // Allergies
    var allergyHtml = '';
    if (d.allergies && d.allergies.length > 0) {
        allergyHtml = d.allergies.map(function(a) {
            var cls = a.severity === 'severe' ? 'allergy severe' : 'allergy';
            return '<span class="qv-tag ' + cls + '">' + escapeHtml(a.allergen) + (a.severity ? ' <small>(' + a.severity + ')</small>' : '') + '</span>';
        }).join('');
    } else {
        allergyHtml = '<span class="text-muted" style="font-size:13px;">None recorded</span>';
    }
    document.getElementById('qvAllergies').innerHTML = allergyHtml;

    // Conditions
    var condHtml = '';
    if (d.conditions && d.conditions.length > 0) {
        condHtml = d.conditions.map(function(c) {
            return '<span class="qv-tag condition">' + escapeHtml(c) + '</span>';
        }).join('');
    } else {
        condHtml = '<span class="text-muted" style="font-size:13px;">None recorded</span>';
    }
    document.getElementById('qvConditions').innerHTML = condHtml;

    // Prescriptions
    var rxHtml = '';
    if (d.recent_prescriptions && d.recent_prescriptions.length > 0) {
        rxHtml = d.recent_prescriptions.map(function(rx) {
            return '<div class="qv-rx-card">' +
                '<div class="d-flex" style="justify-content:space-between;align-items:center;">' +
                    '<span class="qv-rx-doctor"><i class="fas fa-user-md" style="color:#64748b;margin-right:4px;"></i> Dr. ' + escapeHtml(rx.doctor_name) + '</span>' +
                    '<span class="qv-rx-date">' + formatQvDate(rx.prescription_date) + '</span>' +
                '</div>' +
                (rx.medicines ? '<div class="qv-rx-meds"><i class="fas fa-pills" style="margin-right:4px;"></i> ' + escapeHtml(rx.medicines) + '</div>' : '') +
                '</div>';
        }).join('');
    } else {
        rxHtml = '<p class="text-muted" style="font-size: 13px; text-align: center; padding: 20px 0;">No prescriptions found</p>';
    }
    document.getElementById('qvPrescriptions').innerHTML = rxHtml;

    // Quick Actions
    var base = '<?= BASE_URL ?>';
    document.getElementById('qvActionGrid').innerHTML =
        '<a href="' + base + '/modules/appointments/book.php?patient_id=' + d.id + '" class="qv-action-btn act-appt"><i class="fas fa-calendar-plus"></i>Book Appointment</a>' +
        '<a href="' + base + '/modules/billing/create.php?patient_id=' + d.id + '" class="qv-action-btn act-bill"><i class="fas fa-file-invoice-dollar"></i>Create Bill</a>' +
        '<a href="' + base + '/modules/patients/add.php?id=' + d.id + '" class="qv-action-btn act-edit"><i class="fas fa-user-edit"></i>Edit Patient</a>';
}

function formatQvDate(dateStr) {
    if (!dateStr) return '-';
    var d = new Date(dateStr + 'T00:00:00');
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return d.getDate() + ' ' + months[d.getMonth()] + ' ' + d.getFullYear();
}

function formatQvTime(timeStr) {
    if (!timeStr) return '';
    var parts = timeStr.split(':');
    var h = parseInt(parts[0]), m = parts[1];
    var ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    return h + ':' + m + ' ' + ampm;
}

function escapeHtml(text) {
    if (!text) return '';
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
