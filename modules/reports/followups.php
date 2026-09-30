<?php
/**
 * Patient Follow-up Report - Feature Gen Care
 * Comprehensive report of scheduled patient follow-up dates, overdue visits, and completion tracking.
 */
require_once dirname(dirname(__DIR__)) . '/config/session.php';
requirePermission('reports.view');

$db = db();
$clinicId = getCurrentClinicId();
$today = date('Y-m-d');

// Fetch clinic info for print letterhead and notifications
$clinic = $db->fetch("SELECT * FROM clinics WHERE id = ?", [$clinicId]);
if (!$clinic && !empty($db->tenantInfo)) {
    $clinic = [
        'name' => $db->tenantInfo['clinic_name'] ?? APP_NAME,
        'address' => '',
        'phone' => '',
        'email' => '',
        'logo' => null,
        'gst_number' => ''
    ];
}

// Filter inputs
$preset = sanitize($_GET['preset'] ?? 'all');
$filterDoctor = intval($_GET['doctor_id'] ?? 0);
$filterStatus = sanitize($_GET['status'] ?? '');
$search = trim(sanitize($_GET['search'] ?? ''));
$fromDate = sanitize($_GET['from'] ?? '');
$toDate = sanitize($_GET['to'] ?? '');

// Handle preset dates
if ($preset === 'today') {
    $fromDate = $today;
    $toDate = $today;
} elseif ($preset === 'tomorrow') {
    $fromDate = date('Y-m-d', strtotime('+1 day'));
    $toDate = date('Y-m-d', strtotime('+1 day'));
} elseif ($preset === 'this_week') {
    $fromDate = date('Y-m-d', strtotime('monday this week'));
    $toDate = date('Y-m-d', strtotime('sunday this week'));
} elseif ($preset === 'next_7_days') {
    $fromDate = $today;
    $toDate = date('Y-m-d', strtotime('+7 days'));
} elseif ($preset === 'this_month') {
    $fromDate = date('Y-m-01');
    $toDate = date('Y-m-t');
} elseif ($preset === 'overdue') {
    $toDate = date('Y-m-d', strtotime('-1 day'));
    if (empty($filterStatus)) {
        $filterStatus = 'overdue';
    }
}

// Fetch doctors for filter dropdown
$doctors = $db->fetchAll(
    "SELECT d.id, u.full_name, sp.name as specialization
     FROM doctors d
     JOIN users u ON d.user_id = u.id
     LEFT JOIN specialties sp ON d.specialty_id = sp.id
     WHERE d.clinic_id = ?
     ORDER BY u.full_name ASC",
    [$clinicId]
);

// Unified Query for Follow-up Records
// Combines Prescriptions with follow_up_date AND Appointments of type 'followup'
$subAppt = "(SELECT a.id FROM appointments a 
             WHERE a.patient_id = pr.patient_id 
               AND a.clinic_id = pr.clinic_id 
               AND a.id != COALESCE(pr.appointment_id, 0)
               AND (a.appointment_date >= pr.follow_up_date OR (a.appointment_type = 'followup' AND a.appointment_date >= pr.prescription_date))
               AND a.status IN ('confirmed','checked_in','in_progress','completed')
             ORDER BY a.appointment_date ASC LIMIT 1)";

$subApptDate = "(SELECT a.appointment_date FROM appointments a 
                 WHERE a.patient_id = pr.patient_id 
                   AND a.clinic_id = pr.clinic_id 
                   AND a.id != COALESCE(pr.appointment_id, 0)
                   AND (a.appointment_date >= pr.follow_up_date OR (a.appointment_type = 'followup' AND a.appointment_date >= pr.prescription_date))
                   AND a.status IN ('confirmed','checked_in','in_progress','completed')
                 ORDER BY a.appointment_date ASC LIMIT 1)";

$subApptStatus = "(SELECT a.status FROM appointments a 
                   WHERE a.patient_id = pr.patient_id 
                     AND a.clinic_id = pr.clinic_id 
                     AND a.id != COALESCE(pr.appointment_id, 0)
                     AND (a.appointment_date >= pr.follow_up_date OR (a.appointment_type = 'followup' AND a.appointment_date >= pr.prescription_date))
                     AND a.status IN ('confirmed','checked_in','in_progress','completed')
                   ORDER BY a.appointment_date ASC LIMIT 1)";

$baseSql = "
    SELECT 
        'prescription' AS source_type,
        pr.id AS source_id,
        pr.patient_id,
        pr.doctor_id,
        pr.prescription_number AS ref_number,
        pr.prescription_date AS recorded_date,
        pr.follow_up_date,
        pr.follow_up_notes AS notes,
        pr.diagnosis,
        pr.chief_complaints,
        p.patient_uid,
        p.first_name,
        p.last_name,
        p.phone,
        p.email,
        p.gender,
        p.date_of_birth,
        p.age,
        u.full_name AS doctor_name,
        sp.name AS specialization,
        $subAppt AS next_appt_id,
        $subApptDate AS next_appt_date,
        $subApptStatus AS next_appt_status,
        CASE
            WHEN $subApptStatus = 'completed' THEN 'completed'
            WHEN $subApptStatus IN ('confirmed','checked_in','in_progress','scheduled') THEN 'booked'
            WHEN pr.follow_up_date = CURRENT_DATE() THEN 'due_today'
            WHEN pr.follow_up_date < CURRENT_DATE() THEN 'overdue'
            ELSE 'upcoming'
        END AS follow_up_status
    FROM prescriptions pr
    JOIN patients p ON pr.patient_id = p.id
    JOIN doctors d ON pr.doctor_id = d.id
    JOIN users u ON d.user_id = u.id
    LEFT JOIN specialties sp ON d.specialty_id = sp.id
    WHERE pr.clinic_id = ? AND pr.follow_up_date IS NOT NULL

    UNION ALL

    SELECT 
        'appointment' AS source_type,
        a.id AS source_id,
        a.patient_id,
        a.doctor_id,
        CONCAT('APT-', a.id) AS ref_number,
        DATE(a.created_at) AS recorded_date,
        a.appointment_date AS follow_up_date,
        a.visit_reason AS notes,
        NULL AS diagnosis,
        NULL AS chief_complaints,
        p.patient_uid,
        p.first_name,
        p.last_name,
        p.phone,
        p.email,
        p.gender,
        p.date_of_birth,
        p.age,
        u.full_name AS doctor_name,
        sp.name AS specialization,
        a.id AS next_appt_id,
        a.appointment_date AS next_appt_date,
        a.status AS next_appt_status,
        CASE
            WHEN a.status = 'completed' THEN 'completed'
            WHEN a.status IN ('confirmed','checked_in','in_progress','scheduled') THEN 'booked'
            WHEN a.appointment_date = CURRENT_DATE() THEN 'due_today'
            WHEN a.appointment_date < CURRENT_DATE() THEN 'overdue'
            ELSE 'upcoming'
        END AS follow_up_status
    FROM appointments a
    JOIN patients p ON a.patient_id = p.id
    JOIN doctors d ON a.doctor_id = d.id
    JOIN users u ON d.user_id = u.id
    LEFT JOIN specialties sp ON d.specialty_id = sp.id
    WHERE a.clinic_id = ? AND a.appointment_type = 'followup'
      AND NOT EXISTS (
          SELECT 1 FROM prescriptions pr 
          WHERE pr.patient_id = a.patient_id 
            AND pr.clinic_id = a.clinic_id 
            AND pr.follow_up_date = a.appointment_date
      )
";

// Build filters on top of the union
$where = " WHERE 1=1 ";
$params = [$clinicId, $clinicId];

if ($filterDoctor > 0) {
    $where .= " AND fu.doctor_id = ? ";
    $params[] = $filterDoctor;
}

if (!empty($filterStatus)) {
    $where .= " AND fu.follow_up_status = ? ";
    $params[] = $filterStatus;
}

if (!empty($fromDate)) {
    $where .= " AND fu.follow_up_date >= ? ";
    $params[] = $fromDate;
}

if (!empty($toDate)) {
    $where .= " AND fu.follow_up_date <= ? ";
    $params[] = $toDate;
}

if (!empty($search)) {
    $where .= " AND (fu.first_name LIKE ? OR fu.last_name LIKE ? OR CONCAT(fu.first_name, ' ', fu.last_name) LIKE ? OR fu.patient_uid LIKE ? OR fu.phone LIKE ? OR fu.ref_number LIKE ?) ";
    $term = "%$search%";
    $params = array_merge($params, [$term, $term, $term, $term, $term, $term]);
}

// Calculate Summary Statistics for the clinic
$statsSql = "
    SELECT 
        COUNT(*) AS total_count,
        SUM(CASE WHEN fu.follow_up_status = 'due_today' THEN 1 ELSE 0 END) AS due_today_count,
        SUM(CASE WHEN fu.follow_up_status = 'upcoming' THEN 1 ELSE 0 END) AS upcoming_count,
        SUM(CASE WHEN fu.follow_up_status = 'overdue' THEN 1 ELSE 0 END) AS overdue_count,
        SUM(CASE WHEN fu.follow_up_status = 'booked' THEN 1 ELSE 0 END) AS booked_count,
        SUM(CASE WHEN fu.follow_up_status = 'completed' THEN 1 ELSE 0 END) AS completed_count
    FROM ($baseSql) AS fu
";
$globalStats = $db->fetch($statsSql, [$clinicId, $clinicId]) ?: [
    'total_count' => 0,
    'due_today_count' => 0,
    'upcoming_count' => 0,
    'overdue_count' => 0,
    'booked_count' => 0,
    'completed_count' => 0
];

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exportSql = "SELECT * FROM ($baseSql) AS fu $where ORDER BY fu.follow_up_date ASC";
    $exportRows = $db->fetchAll($exportSql, $params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="patient_followup_report_' . date('Y-m-d') . '.csv"');
    
    $out = fopen('php://output', 'w');
    // Header row
    fputcsv($out, [
        'Follow-up Date',
        'Status',
        'Patient UID',
        'Patient Name',
        'Phone',
        'Gender',
        'Age',
        'Doctor Name',
        'Specialization',
        'Visit / Prescription #',
        'Prescription Date',
        'Diagnosis',
        'Follow-up Notes / Reason',
        'Next Appointment Date',
        'Next Appointment Status'
    ]);

    foreach ($exportRows as $r) {
        $pAge = $r['date_of_birth'] ? calculateAge($r['date_of_birth']) : ($r['age'] ?: '-');
        fputcsv($out, [
            $r['follow_up_date'],
            strtoupper(str_replace('_', ' ', $r['follow_up_status'])),
            $r['patient_uid'],
            trim($r['first_name'] . ' ' . $r['last_name']),
            $r['phone'],
            $r['gender'] ?: '-',
            $pAge,
            'Dr. ' . $r['doctor_name'],
            $r['specialization'] ?: '-',
            $r['ref_number'] ?: '-',
            $r['recorded_date'],
            $r['diagnosis'] ?: ($r['chief_complaints'] ?: '-'),
            $r['notes'] ?: '-',
            $r['next_appt_date'] ?: '-',
            $r['next_appt_status'] ?: '-'
        ]);
    }
    fclose($out);
    exit;
}

// Pagination for regular web view
$countSql = "SELECT COUNT(*) as total FROM ($baseSql) AS fu $where";
$totalRecords = $db->fetch($countSql, $params)['total'] ?? 0;

$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 25;
$pagination = paginate($totalRecords, $page, $perPage);

$querySql = "SELECT * FROM ($baseSql) AS fu $where ORDER BY fu.follow_up_date ASC LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}";
$records = $db->fetchAll($querySql, $params);

// Resolve clinic logo for print header
$bLogo = $clinic['logo'] ?? '';
$bLogoUrl = '';
if (!empty($bLogo)) {
    $bClean = ltrim($bLogo, '/');
    $bUrl = (strpos($bClean, 'clinics/') === 0) ? (UPLOADS_URL . '/' . $bClean) : (UPLOADS_URL . '/clinics/' . $bClean);
    $bPath = (strpos($bClean, 'clinics/') === 0) ? (UPLOADS_PATH . '/' . $bClean) : (UPLOADS_PATH . '/clinics/' . $bClean);
    if (file_exists($bPath)) {
        $bLogoUrl = $bUrl;
    }
}
$printHeaderStyle = $clinic['print_header_style'] ?? 'logo_with_name';

// Helpers for status presentation
function getFollowUpBadge($status, $followUpDate) {
    global $today;
    switch ($status) {
        case 'due_today':
            return '<span class="badge badge-warning" style="background:#fef3c7; color:#b45309; border:1px solid #fde68a; font-weight:700;"><i class="fas fa-bell"></i> Due Today</span>';
        case 'upcoming':
            $diffDays = (int)((strtotime($followUpDate) - strtotime($today)) / 86400);
            $sub = ($diffDays === 1) ? 'Tomorrow' : "In {$diffDays} days";
            return '<span class="badge badge-info" style="background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; font-weight:600;"><i class="fas fa-clock"></i> Upcoming (' . $sub . ')</span>';
        case 'overdue':
            $diffDays = (int)((strtotime($today) - strtotime($followUpDate)) / 86400);
            $sub = ($diffDays === 1) ? 'Yesterday' : "{$diffDays} days ago";
            return '<span class="badge badge-danger" style="background:#fee2e2; color:#b91c1c; border:1px solid #fecaca; font-weight:700;"><i class="fas fa-exclamation-triangle"></i> Overdue (' . $sub . ')</span>';
        case 'booked':
            return '<span class="badge badge-primary" style="background:#dbeafe; color:#1e40af; border:1px solid #bfdbfe; font-weight:600;"><i class="fas fa-calendar-check"></i> Booked</span>';
        case 'completed':
            return '<span class="badge badge-success" style="background:#d1fae5; color:#047857; border:1px solid #a7f3d0; font-weight:700;"><i class="fas fa-check-circle"></i> Completed</span>';
        default:
            return '<span class="badge badge-secondary">' . ucfirst($status) . '</span>';
    }
}

$pageTitle = 'Patient Follow-up Report';
require_once INCLUDES_PATH . '/header.php';
?>

<style>
/* Print Styles */
@media print {
    .sidebar, .header, .sidebar-overlay, .breadcrumb, .no-print, form, .btn, .pagination, .tab-bar-nav {
        display: none !important;
    }
    .app-wrapper, .main-content, .content-area {
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
        background: #fff !important;
    }
    .print-only-header {
        display: block !important;
    }
    .card {
        box-shadow: none !important;
        border: 1px solid #e2e8f0 !important;
        break-inside: avoid;
        margin-bottom: 16px !important;
    }
    .stat-card {
        border: 1px solid #cbd5e1 !important;
        box-shadow: none !important;
        padding: 10px !important;
    }
    .table th, .table td {
        padding: 8px 10px !important;
        border: 1px solid #e2e8f0 !important;
        font-size: 11px !important;
    }
    body {
        font-size: 11px !important;
        background: #fff !important;
        color: #000 !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}

/* ============================================
   FOLLOW-UP REPORT CUSTOM STYLES
   ============================================ */

/* Filter Form Grid */
.fu-filter-grid {
    display: grid;
    grid-template-columns: 1.5fr 1fr 1fr 130px 130px auto;
    gap: 14px;
    align-items: end;
}
@media (max-width: 1200px) {
    .fu-filter-grid {
        grid-template-columns: 1fr 1fr 1fr;
    }
}
@media (max-width: 768px) {
    .fu-filter-grid {
        grid-template-columns: 1fr;
    }
}

/* Chip Group */
.chip-group {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    align-items: center;
}
.chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 50px;
    font-size: 12.5px;
    font-weight: 600;
    color: var(--text-muted, #64748b);
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    text-decoration: none;
    transition: all 0.15s ease-in-out;
    white-space: nowrap;
}
.chip:hover {
    background: #e2e8f0;
    color: #1e293b;
}
.chip.active {
    background: #0891b2;
    color: #ffffff;
    border-color: #0891b2;
    box-shadow: 0 2px 6px rgba(8, 145, 178, 0.25);
}
.chip-count {
    background: rgba(0,0,0,0.08);
    font-size: 11px;
    padding: 1px 7px;
    border-radius: 20px;
}
.chip.active .chip-count {
    background: rgba(255,255,255,0.25);
    color: #ffffff;
}

/* Action Buttons */
.btn-whatsapp {
    background: #25D366;
    color: #ffffff;
    border: none;
}
.btn-whatsapp:hover {
    background: #1eb854;
    color: #ffffff;
}

/* Patient Cell */
.patient-cell {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.patient-name {
    font-weight: 700;
    color: #0f172a;
    font-size: 13.5px;
}
.patient-meta {
    font-size: 11.5px;
    color: #64748b;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

/* Follow-up Date Cell */
.followup-date-cell {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.fu-date-main {
    font-weight: 800;
    font-size: 13.5px;
    color: #0f172a;
    white-space: nowrap;
}

/* Follow-up table column tuning */
.fu-table th,
.fu-table td {
    vertical-align: top;
    padding: 12px 14px;
}
.fu-table th {
    font-size: 11.5px;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    color: #475569;
    background: #f8fafc;
    white-space: nowrap;
}
</style>

<!-- Printable Header -->
<div class="print-only-header" style="display: none;">
    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #0891b2; padding-bottom: 16px; margin-bottom: 20px;">
        <div style="display: flex; align-items: center; gap: 14px;">
            <?php if (!empty($bLogoUrl)): ?>
            <img src="<?= $bLogoUrl ?>" alt="Logo" style="height: <?= ($printHeaderStyle === 'logo_only') ? '60px' : '50px' ?>; width: auto; object-fit: contain;">
            <?php endif; ?>
            <?php if ($printHeaderStyle !== 'logo_only'): ?>
            <div>
                <h1 style="font-size: 22px; color: #155e75; margin: 0 0 4px; font-weight: 800;"><?= sanitizeOutput($clinic['name'] ?? APP_NAME) ?></h1>
                <p style="margin: 0; font-size: 12px; color: #475569;">
                    <?= sanitizeOutput($clinic['address'] ?? '') ?>
                    <?= !empty($clinic['phone']) ? ' &bull; Phone: ' . sanitizeOutput($clinic['phone']) : '' ?>
                    <?= !empty($clinic['email']) ? ' &bull; Email: ' . sanitizeOutput($clinic['email']) : '' ?>
                </p>
            </div>
            <?php endif; ?>
        </div>
        <div style="text-align: right;">
            <h3 style="margin: 0 0 4px; font-size: 15px; text-transform: uppercase; color: #0f172a; font-weight: 800;">Patient Follow-up Report</h3>
            <p style="margin: 0; font-size: 12px; color: #475569;">
                Status: <strong><?= !empty($filterStatus) ? ucfirst(str_replace('_', ' ', $filterStatus)) : 'All Follow-ups' ?></strong>
                <?= !empty($fromDate) ? ' &bull; From: <strong>' . formatDate($fromDate) . '</strong>' : '' ?>
                <?= !empty($toDate) ? ' To: <strong>' . formatDate($toDate) . '</strong>' : '' ?>
            </p>
            <p style="margin: 2px 0 0; font-size: 11px; color: #94a3b8;">
                Generated: <?= date('d M Y, h:i A') ?> by <?= sanitizeOutput(getSession('full_name', 'Staff')) ?>
            </p>
        </div>
    </div>
</div>

<!-- Page Top Header -->
<div class="content-header no-print">
    <div>
        <ul class="breadcrumb">
            <li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li>
            <li><a href="<?= BASE_URL ?>/modules/reports/daily.php">Reports</a></li>
            <li>Follow-up Report</li>
        </ul>
        <h1>Patient Follow-up Report</h1>
        <p class="text-muted" style="margin: 4px 0 0; font-size: 13px;">
            Monitor patients scheduled for return visits, overdue follow-ups, and post-consultation recovery checks.
        </p>
    </div>
    <div class="d-flex gap-8 align-center">
        <a href="<?= BASE_URL ?>/modules/reports/followups.php?export=csv<?= !empty($_SERVER['QUERY_STRING']) ? '&' . http_build_query(array_diff_key($_GET, ['export' => '', 'page' => ''])) : '' ?>" class="btn btn-outline" title="Download Excel/CSV Spreadsheet">
            <i class="fas fa-file-csv"></i> Export CSV
        </a>
        <button type="button" class="btn btn-primary" onclick="window.print()">
            <i class="fas fa-print"></i> Print Report
        </button>
    </div>
</div>

<!-- Tabs Navigation -->
<div class="tab-bar-nav d-flex gap-8 mb-24 no-print" style="border-bottom: 2px solid #e2e8f0; padding-bottom: 12px;">
    <a href="<?= BASE_URL ?>/modules/reports/daily.php" class="btn btn-ghost" style="font-weight: 600;">
        <i class="fas fa-chart-line"></i> Daily & Financial Summary
    </a>
    <a href="<?= BASE_URL ?>/modules/reports/followups.php" class="btn btn-primary" style="font-weight: 700; box-shadow: 0 2px 8px rgba(8, 145, 178, 0.25);">
        <i class="fas fa-calendar-check"></i> Patient Follow-up Report
    </a>
</div>

<!-- Summary Metric Cards -->
<div class="grid-4 mb-24">
    <div class="stat-card">
        <div class="stat-icon primary"><i class="fas fa-calendar-alt"></i></div>
        <div class="stat-details">
            <div class="stat-label">Total Follow-ups</div>
            <div class="stat-value"><?= number_format($globalStats['total_count'] ?? 0) ?></div>
            <div class="stat-change" style="color: #64748b;">All scheduled returns</div>
        </div>
    </div>
    <div class="stat-card" style="border-left: 4px solid #f59e0b;">
        <div class="stat-icon warning" style="background: #fef3c7; color: #d97706;"><i class="fas fa-bell"></i></div>
        <div class="stat-details">
            <div class="stat-label">Due Today</div>
            <div class="stat-value" style="color: #d97706;"><?= number_format($globalStats['due_today_count'] ?? 0) ?></div>
            <div class="stat-change" style="color: #b45309;"><?= formatDate($today) ?></div>
        </div>
    </div>
    <div class="stat-card" style="border-left: 4px solid #ef4444;">
        <div class="stat-icon danger" style="background: #fee2e2; color: #dc2626;"><i class="fas fa-exclamation-triangle"></i></div>
        <div class="stat-details">
            <div class="stat-label">Overdue / Missed</div>
            <div class="stat-value" style="color: #dc2626;"><?= number_format($globalStats['overdue_count'] ?? 0) ?></div>
            <div class="stat-change" style="color: #b91c1c;">Passed without visit</div>
        </div>
    </div>
    <div class="stat-card" style="border-left: 4px solid #10b981;">
        <div class="stat-icon success" style="background: #d1fae5; color: #059669;"><i class="fas fa-check-circle"></i></div>
        <div class="stat-details">
            <div class="stat-label">Completed / Attended</div>
            <div class="stat-value" style="color: #059669;"><?= number_format($globalStats['completed_count'] ?? 0) ?></div>
            <div class="stat-change" style="color: #047857;">Return visit recorded</div>
        </div>
    </div>
</div>

<!-- Quick Preset Chips -->
<div class="card mb-20 no-print" style="background: #ffffff; border: 1px solid #e2e8f0;">
    <div class="card-body" style="padding: 14px 20px;">
        <div class="d-flex justify-between align-center flex-wrap gap-12">
            <div class="d-flex align-center gap-12 flex-wrap">
                <span style="font-size: 12px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Quick Filters:</span>
                <div class="chip-group">
                    <a href="?preset=all<?= $filterDoctor ? '&doctor_id=' . $filterDoctor : '' ?>" class="chip <?= ($preset === 'all' && empty($filterStatus)) ? 'active' : '' ?>">
                        <i class="fas fa-list"></i> All Follow-ups
                    </a>
                    <a href="?preset=today<?= $filterDoctor ? '&doctor_id=' . $filterDoctor : '' ?>" class="chip <?= ($preset === 'today') ? 'active' : '' ?>">
                        <i class="fas fa-calendar-day"></i> Due Today
                        <?php if (!empty($globalStats['due_today_count'])): ?>
                        <span class="chip-count"><?= $globalStats['due_today_count'] ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="?preset=tomorrow<?= $filterDoctor ? '&doctor_id=' . $filterDoctor : '' ?>" class="chip <?= ($preset === 'tomorrow') ? 'active' : '' ?>">
                        <i class="fas fa-step-forward"></i> Tomorrow
                    </a>
                    <a href="?preset=next_7_days<?= $filterDoctor ? '&doctor_id=' . $filterDoctor : '' ?>" class="chip <?= ($preset === 'next_7_days') ? 'active' : '' ?>">
                        <i class="fas fa-calendar-week"></i> Next 7 Days
                    </a>
                    <a href="?preset=this_month<?= $filterDoctor ? '&doctor_id=' . $filterDoctor : '' ?>" class="chip <?= ($preset === 'this_month') ? 'active' : '' ?>">
                        <i class="fas fa-calendar"></i> This Month
                    </a>
                    <a href="?preset=overdue&status=overdue<?= $filterDoctor ? '&doctor_id=' . $filterDoctor : '' ?>" class="chip <?= ($preset === 'overdue' || $filterStatus === 'overdue') ? 'active' : '' ?>" style="<?= ($preset === 'overdue' || $filterStatus === 'overdue') ? 'background:#dc2626; border-color:#dc2626;' : 'color:#dc2626;' ?>">
                        <i class="fas fa-exclamation-circle"></i> Overdue
                        <?php if (!empty($globalStats['overdue_count'])): ?>
                        <span class="chip-count" style="background:#fee2e2; color:#dc2626;"><?= $globalStats['overdue_count'] ?></span>
                        <?php endif; ?>
                    </a>
                </div>
            </div>
            <?php if (!empty($preset) && $preset !== 'all' || !empty($filterDoctor) || !empty($filterStatus) || !empty($search) || !empty($fromDate) || !empty($toDate)): ?>
            <a href="<?= BASE_URL ?>/modules/reports/followups.php" class="btn btn-sm btn-ghost text-muted">
                <i class="fas fa-times-circle"></i> Clear Filters
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Detailed Filter Controls -->
<div class="card mb-24 no-print">
    <div class="card-body" style="padding: 16px 20px;">
        <form method="GET">
            <input type="hidden" name="preset" value="custom">
            <div class="fu-filter-grid">
                <div class="form-group mb-0">
                    <label class="form-label" style="font-size: 11.5px; font-weight: 700; text-transform: uppercase; color: #475569; letter-spacing: 0.3px;">Search Patient / Rx #</label>
                    <div style="position: relative;">
                        <i class="fas fa-search" style="position: absolute; left: 12px; top: 11px; color: #94a3b8; font-size: 13px;"></i>
                        <input type="text" name="search" class="form-control" style="padding-left: 36px; height: 38px;" 
                               value="<?= sanitizeOutput($search) ?>" placeholder="Name, UID, phone, prescription...">
                    </div>
                </div>

                <div class="form-group mb-0">
                    <label class="form-label" style="font-size: 11.5px; font-weight: 700; text-transform: uppercase; color: #475569; letter-spacing: 0.3px;">Doctor</label>
                    <select name="doctor_id" class="form-control" style="height: 38px;">
                        <option value="">All Doctors</option>
                        <?php foreach ($doctors as $doc): ?>
                        <option value="<?= $doc['id'] ?>" <?= ($filterDoctor == $doc['id']) ? 'selected' : '' ?>>
                            Dr. <?= sanitizeOutput($doc['full_name']) ?> <?= !empty($doc['specialization']) ? '(' . sanitizeOutput($doc['specialization']) . ')' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group mb-0">
                    <label class="form-label" style="font-size: 11.5px; font-weight: 700; text-transform: uppercase; color: #475569; letter-spacing: 0.3px;">Status</label>
                    <select name="status" class="form-control" style="height: 38px;">
                        <option value="">All Statuses</option>
                        <option value="due_today" <?= ($filterStatus === 'due_today') ? 'selected' : '' ?>>Due Today</option>
                        <option value="upcoming" <?= ($filterStatus === 'upcoming') ? 'selected' : '' ?>>Upcoming</option>
                        <option value="overdue" <?= ($filterStatus === 'overdue') ? 'selected' : '' ?>>Overdue</option>
                        <option value="booked" <?= ($filterStatus === 'booked') ? 'selected' : '' ?>>Booked</option>
                        <option value="completed" <?= ($filterStatus === 'completed') ? 'selected' : '' ?>>Completed</option>
                    </select>
                </div>

                <div class="form-group mb-0">
                    <label class="form-label" style="font-size: 11.5px; font-weight: 700; text-transform: uppercase; color: #475569; letter-spacing: 0.3px;">From</label>
                    <input type="date" name="from" class="form-control" value="<?= $fromDate ?>" style="height: 38px;">
                </div>

                <div class="form-group mb-0">
                    <label class="form-label" style="font-size: 11.5px; font-weight: 700; text-transform: uppercase; color: #475569; letter-spacing: 0.3px;">To</label>
                    <input type="date" name="to" class="form-control" value="<?= $toDate ?>" style="height: 38px;">
                </div>

                <div class="form-group mb-0">
                    <label class="form-label" style="visibility: hidden; font-size: 11.5px;">Action</label>
                    <button type="submit" class="btn btn-primary" style="height: 38px; width: 100%; white-space: nowrap;">
                        <i class="fas fa-filter"></i> Apply
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Follow-ups Table Card -->
<div class="card">
    <div class="card-header d-flex justify-between align-center">
        <div>
            <h3 style="margin: 0; font-size: 15px; font-weight: 700; color: #0f172a;">
                <i class="fas fa-calendar-check" style="color: #0891b2; margin-right: 6px;"></i>
                Follow-up Records
                <span class="badge badge-secondary" style="font-size: 12px; margin-left: 6px;"><?= $totalRecords ?> found</span>
            </h3>
        </div>
        <div class="text-muted" style="font-size: 12px;">
            Showing <?= count($records) ?> of <?= $totalRecords ?> records
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($records)): ?>
        <div class="empty-state" style="padding: 48px 24px; text-align: center;">
            <div style="width: 64px; height: 64px; border-radius: 50%; background: #e0f2fe; color: #0891b2; display: flex; align-items: center; justify-content: center; font-size: 24px; margin: 0 auto 16px;">
                <i class="fas fa-calendar-check"></i>
            </div>
            <h3 style="font-size: 16px; font-weight: 700; color: #1e293b; margin-bottom: 6px;">No Follow-up Records Found</h3>
            <p class="text-muted" style="max-width: 420px; margin: 0 auto 16px; font-size: 13px;">
                There are no patient follow-up dates matching your selected filter criteria. When doctors prescribe follow-up dates, they will automatically appear here.
            </p>
            <a href="<?= BASE_URL ?>/modules/reports/followups.php" class="btn btn-outline btn-sm">
                <i class="fas fa-sync-alt"></i> Reset Filters
            </a>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table fu-table" style="margin-bottom: 0; table-layout: fixed; width: 100%;">
                <thead>
                    <tr>
                        <th style="width: 155px;">Follow-up Date</th>
                        <th style="width: 22%;">Patient Details</th>
                        <th style="width: 130px;">Doctor</th>
                        <th style="width: 135px;">Initial Visit</th>
                        <th>Reason / Advice</th>
                        <th style="width: 135px;" class="no-print" style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($records as $row): 
                    $patientAge = $row['date_of_birth'] ? calculateAge($row['date_of_birth']) : ($row['age'] ?: '-');
                    $patientFullName = trim($row['first_name'] . ' ' . $row['last_name']);
                    
                    // Format phone for WhatsApp & Click-to-call
                    $cleanPhone = preg_replace('/[^0-9]/', '', $row['phone']);
                    if (strlen($cleanPhone) === 10) {
                        $waPhone = '91' . $cleanPhone; // default India code if 10 digits
                    } else {
                        $waPhone = $cleanPhone;
                    }
                    
                    $waText = "Hello {$patientFullName},\nThis is a friendly reminder from " . ($clinic['name'] ?? 'Feature Gen Care') . " regarding your scheduled follow-up on " . formatDate($row['follow_up_date']) . (!empty($row['doctor_name']) ? " with Dr. " . $row['doctor_name'] : "") . "." . (!empty($row['notes']) ? "\nAdvice/Notes: " . $row['notes'] : "") . (!empty($clinic['phone']) ? "\nFor queries or rescheduling, please contact us at " . $clinic['phone'] . "." : "");
                    $waUrl = "https://wa.me/{$waPhone}?text=" . rawurlencode($waText);
                ?>
                    <tr style="<?= ($row['follow_up_status'] === 'due_today') ? 'background: #fffbeb;' : (($row['follow_up_status'] === 'overdue') ? 'background: #fff5f5;' : '') ?>">
                        <td>
                            <div class="followup-date-cell">
                                <div class="fu-date-main">
                                    <i class="fas fa-calendar-day" style="color: #0891b2; font-size: 12px; margin-right: 4px;"></i>
                                    <?= formatDate($row['follow_up_date']) ?>
                                </div>
                                <div>
                                    <?= getFollowUpBadge($row['follow_up_status'], $row['follow_up_date']) ?>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="patient-cell">
                                <a href="<?= BASE_URL ?>/modules/patients/view.php?id=<?= $row['patient_id'] ?>" class="patient-name" style="text-decoration: none;">
                                    <?= sanitizeOutput($patientFullName) ?>
                                </a>
                                <div class="patient-meta">
                                    <span class="badge badge-secondary" style="font-size: 11px; padding: 2px 6px;"><?= sanitizeOutput($row['patient_uid']) ?></span>
                                    <span><?= $patientAge ?> / <?= !empty($row['gender']) ? ucfirst($row['gender']) : '-' ?></span>
                                    <?php if (!empty($row['phone'])): ?>
                                    <a href="tel:<?= $cleanPhone ?>" style="color: #0891b2; text-decoration: none; font-weight: 600;" title="Call Patient">
                                        <i class="fas fa-phone-alt" style="font-size: 10px;"></i> <?= sanitizeOutput($row['phone']) ?>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div style="font-weight: 700; color: #1e293b; font-size: 13px;">
                                Dr. <?= sanitizeOutput($row['doctor_name']) ?>
                            </div>
                            <?php if (!empty($row['specialization'])): ?>
                            <div style="font-size: 11.5px; color: #64748b;"><?= sanitizeOutput($row['specialization']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="font-size: 12px; color: #334155; font-weight: 600;">
                                <?= formatDate($row['recorded_date']) ?>
                            </div>
                            <?php if (!empty($row['ref_number'])): ?>
                            <div style="font-size: 11px; color: #64748b;">
                                <?php if ($row['source_type'] === 'prescription'): ?>
                                <a href="<?= BASE_URL ?>/modules/prescriptions/view.php?id=<?= $row['source_id'] ?>" target="_blank" style="color: #0891b2; text-decoration: none;">
                                    <i class="fas fa-prescription"></i> <?= sanitizeOutput($row['ref_number']) ?>
                                </a>
                                <?php else: ?>
                                <span><?= sanitizeOutput($row['ref_number']) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($row['diagnosis'])): ?>
                            <div style="font-size: 11px; color: #475569; max-width: 140px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= sanitizeOutput($row['diagnosis']) ?>">
                                <em><?= sanitizeOutput($row['diagnosis']) ?></em>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td style="word-wrap: break-word; overflow-wrap: break-word;">
                            <?php if (!empty($row['notes'])): ?>
                            <div style="font-size: 12.5px; color: #1e293b; font-weight: 500; line-height: 1.45; max-height: 60px; overflow: hidden; text-overflow: ellipsis;" title="<?= sanitizeOutput($row['notes']) ?>">
                                <?= nl2br(sanitizeOutput(mb_strimwidth($row['notes'], 0, 120, '...'))) ?>
                            </div>
                            <?php else: ?>
                            <span class="text-muted" style="font-size: 12px;">No specific notes recorded</span>
                            <?php endif; ?>
                            
                            <?php if (!empty($row['next_appt_date'])): ?>
                            <div style="margin-top: 6px; font-size: 10.5px; color: #047857; background: #ecfdf5; padding: 3px 7px; border-radius: 4px; display: inline-flex; align-items: center; gap: 4px; max-width: 100%;">
                                <i class="fas fa-calendar-check" style="font-size: 10px; flex-shrink: 0;"></i>
                                <span style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">Appt: <?= formatDate($row['next_appt_date']) ?> (<?= ucfirst($row['next_appt_status']) ?>)</span>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td class="no-print" style="text-align: right;">
                            <div class="d-flex gap-4 justify-end flex-wrap" style="gap: 4px;">
                                <?php if (!empty($row['phone'])): ?>
                                <a href="<?= $waUrl ?>" target="_blank" class="btn btn-sm btn-whatsapp" title="WhatsApp Reminder" style="padding: 5px 7px; font-size: 12px;">
                                    <i class="fab fa-whatsapp"></i>
                                </a>
                                <a href="tel:<?= $cleanPhone ?>" class="btn btn-sm btn-ghost text-primary" title="Call" style="padding: 5px 7px; font-size: 12px;">
                                    <i class="fas fa-phone-alt"></i>
                                </a>
                                <?php endif; ?>
                                <?php if ($row['follow_up_status'] !== 'completed'): ?>
                                <a href="<?= BASE_URL ?>/modules/appointments/book.php?patient_id=<?= $row['patient_id'] ?>&doctor_id=<?= $row['doctor_id'] ?>&date=<?= $row['follow_up_date'] ?>&type=followup" 
                                   class="btn btn-sm btn-outline-primary" title="Book Follow-up" style="padding: 4px 7px; font-size: 11px; white-space: nowrap;">
                                    <i class="fas fa-calendar-plus"></i> Book
                                </a>
                                <?php endif; ?>
                                <?php if ($row['source_type'] === 'prescription'): ?>
                                <a href="<?= BASE_URL ?>/modules/prescriptions/print.php?id=<?= $row['source_id'] ?>" target="_blank" class="btn btn-sm btn-ghost" title="Print Rx" style="padding: 5px 7px; font-size: 12px;">
                                    <i class="fas fa-print"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <?php if ($pagination['total_pages'] > 1): ?>
        <div style="padding: 16px 20px; border-top: 1px solid #e2e8f0;">
            <?= renderPagination($pagination, BASE_URL . '/modules/reports/followups.php?' . http_build_query(array_diff_key($_GET, ['page' => '']))) ?>
        </div>
        <?php endif; ?>
        
        <?php endif; ?>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
