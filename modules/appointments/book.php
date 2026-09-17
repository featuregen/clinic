<?php
/**
 * Book Appointment - Feature Gen Care
 */
require_once dirname(dirname(__DIR__)) . '/config/session.php';
requireAuth();
requirePermission('appointments.create');

$db = db();
$clinicId = getCurrentClinicId();

// Handle form submission BEFORE any output
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $patientId = intval($_POST['patient_id']);
    $doctorId = intval($_POST['doctor_id']);
    $date = sanitize($_POST['appointment_date']);
    $time = sanitize($_POST['appointment_time']);
    $type = sanitize($_POST['appointment_type'] ?? 'new');
    $reason = sanitize($_POST['visit_reason'] ?? '');
    $fee = floatval($_POST['consultation_fee'] ?? 0);
    
    if (!$patientId || !$doctorId || !$date || !$time) {
        setFlashMessage('error', 'Please fill all required fields.');
    } else {
        // Server-side duplicate time slot check
        $doctor = $db->fetch("SELECT default_slot_duration FROM doctors WHERE id = ? AND clinic_id = ?", [$doctorId, $clinicId]);
        $slotDuration = intval($doctor['default_slot_duration'] ?? 15);
        $requestedStart = strtotime("$date $time");
        $requestedEnd = $requestedStart + ($slotDuration * 60);
        $endTimeStr = date('H:i:s', $requestedEnd);
        
        $conflicting = $db->fetch(
            "SELECT a.id, a.appointment_time, TIME_FORMAT(a.appointment_time, '%h:%i %p') as display_time,
                    CONCAT(p.first_name, ' ', IFNULL(p.last_name, '')) as patient_name
             FROM appointments a
             JOIN patients p ON a.patient_id = p.id
             WHERE a.doctor_id = ? AND a.appointment_date = ? AND a.clinic_id = ?
               AND a.status NOT IN ('cancelled', 'no_show')
               AND (
                   (a.appointment_time <= ? AND ADDTIME(a.appointment_time, SEC_TO_TIME(? * 60)) > ?)
                   OR (? < ADDTIME(a.appointment_time, SEC_TO_TIME(? * 60)) AND ? >= a.appointment_time)
               )
             LIMIT 1",
            [$doctorId, $date, $clinicId,
             $time, $slotDuration, $time,
             $time, $slotDuration, $time]
        );
        
        if ($conflicting) {
            setFlashMessage('error', "Time slot conflict! {$conflicting['display_time']} is already booked for {$conflicting['patient_name']}. Please choose a different time.");
        } else {
            try {
                $tokenNumber = generateTokenNumber($doctorId, $date);
                
                $db->query(
                    "INSERT INTO appointments (clinic_id, patient_id, doctor_id, appointment_date, appointment_time, end_time,
                     token_number, appointment_type, visit_reason, consultation_fee, booked_by, source)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [$clinicId, $patientId, $doctorId, $date, $time, $endTimeStr, $tokenNumber, $type, $reason, $fee, getCurrentUserId(), 'web']
                );
                
                $appointmentId = $db->lastInsertId();
                logAudit('create', 'appointments', 'appointment', $appointmentId);
                setFlashMessage('success', "Appointment booked successfully! Token #$tokenNumber");
                header('Location: ' . BASE_URL . '/modules/appointments/list.php?date=' . $date);
                exit;
            } catch (Exception $e) {
                setFlashMessage('error', 'Error: ' . $e->getMessage());
            }
        }
    }
}

// NOW include header (outputs HTML) — after any potential redirect
$pageTitle = 'Book Appointment';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';

// Pre-fill patient if provided
$prefilledPatient = null;
if (isset($_GET['patient_id'])) {
    $prefilledPatient = $db->fetch("SELECT * FROM patients WHERE id = ? AND clinic_id = ?", [$_GET['patient_id'], $clinicId]);
}

// Fetch doctors
$doctors = $db->fetchAll(
    "SELECT d.id, d.consultation_fee, d.followup_fee, d.default_slot_duration, u.full_name, s.name as specialty
     FROM doctors d JOIN users u ON d.user_id = u.id LEFT JOIN specialties s ON d.specialty_id = s.id
     WHERE d.clinic_id = ? AND d.is_available = 1 AND u.is_active = 1 ORDER BY u.full_name",
    [$clinicId]
);
?>

<div class="content-header">
    <div>
        <ul class="breadcrumb">
            <li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li>
            <li><a href="<?= BASE_URL ?>/modules/appointments/list.php">Appointments</a></li>
            <li>Book Appointment</li>
        </ul>
        <h1>Book New Appointment</h1>
    </div>
</div>

<form method="POST" class="card">
    <div class="card-body">
        <div class="grid-2 gap-24">
            <!-- Left: Patient & Doctor -->
            <div>
                <h4 class="mb-16"><i class="fas fa-user" style="color: var(--primary);"></i> Patient Details</h4>
                
                <?php if ($prefilledPatient): ?>
                <input type="hidden" name="patient_id" value="<?= $prefilledPatient['id'] ?>">
                <div class="alert alert-info mb-16">
                    <i class="fas fa-user"></i>
                    <div>
                        <strong><?= sanitizeOutput($prefilledPatient['first_name'] . ' ' . ($prefilledPatient['last_name'] ?? '')) ?></strong>
                        <div style="font-size: 12px;"><?= sanitizeOutput($prefilledPatient['patient_uid']) ?> | <?= sanitizeOutput($prefilledPatient['phone']) ?></div>
                    </div>
                </div>
                <?php else: ?>
                <div id="patientSearchSection" class="form-group mb-16" style="position: relative;">
                    <label class="form-label font-semibold">Select Patient <span class="required">*</span></label>
                    <div style="position: relative;">
                        <i class="fas fa-search" style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--text-muted); pointer-events: none; z-index: 2;"></i>
                        <input type="text" id="patientSearchInput" class="form-control" placeholder="Search by Phone, Patient ID, or Name..." autocomplete="off" style="padding-left: 38px; padding-right: 36px;">
                        <span id="searchClearBtn" onclick="resetPatientSearchInput()" style="display:none; position: absolute; right: 12px; top: 50%; transform: translateY(-50%); cursor: pointer; color: var(--text-muted); font-size: 16px; z-index: 3;" title="Clear search">&times;</span>
                    </div>

                    <!-- Live Dropdown Results -->
                    <div id="patientSearchResults" style="display: none; position: absolute; top: calc(100% + 4px); left: 0; right: 0; background: var(--bg-card, #ffffff); border: 1px solid var(--border-color, #cbd5e1); border-radius: 8px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.2); max-height: 300px; overflow-y: auto; z-index: 9999;"></div>

                    <div class="d-flex justify-between align-center mt-8">
                        <small class="text-muted text-xs"><i class="fas fa-info-circle"></i> Type phone (e.g. 98765...) or Patient ID (e.g. PAT-...) to avoid name duplicates</small>
                        <a href="<?= BASE_URL ?>/modules/patients/add.php" target="_blank" class="text-xs" style="color: var(--primary); font-weight: 600;">+ Register New Patient</a>
                    </div>
                </div>

                <!-- Selected Patient Confirmation Card -->
                <div id="selectedPatientCard" class="alert alert-success mb-16" style="display: none; justify-content: space-between; align-items: center; border-left: 4px solid var(--success); padding: 12px 16px;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="width: 36px; height: 36px; border-radius: 50%; background: #dcfce7; color: #15803d; display: flex; align-items: center; justify-content: center; font-size: 15px; font-weight: 700; flex-shrink: 0;">
                            <i class="fas fa-check"></i>
                        </div>
                        <div>
                            <div style="font-size: 11px; text-transform: uppercase; font-weight: 700; color: #16a34a; letter-spacing: 0.5px;">Patient Selected</div>
                            <strong id="cardPatientName" style="font-size: 15px; color: var(--text-primary);"></strong>
                            <div style="font-size: 12px; color: var(--text-muted); margin-top: 2px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                <span class="badge badge-primary" id="cardPatientUid" style="font-size: 11px;"></span>
                                <span><i class="fas fa-phone text-muted"></i> <strong id="cardPatientPhone"></strong></span>
                                <span id="cardPatientMeta"></span>
                            </div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline" onclick="changePatientSelection()" style="font-size: 12px; background: white; flex-shrink: 0;">
                        <i class="fas fa-sync-alt"></i> Change
                    </button>
                </div>

                <input type="hidden" name="patient_id" id="selectedPatientId" required>
                <?php endif; ?>
                
                <h4 class="mb-16 mt-24"><i class="fas fa-user-md" style="color: var(--accent);"></i> Doctor & Schedule</h4>
                <div class="form-group">
                    <label class="form-label">Select Doctor <span class="required">*</span></label>
                    <select name="doctor_id" id="doctorSelect" class="form-control" required onchange="updateFee()">
                        <option value="">Choose a doctor...</option>
                        <?php foreach ($doctors as $doc): ?>
                        <option value="<?= $doc['id'] ?>" 
                                data-fee="<?= $doc['consultation_fee'] ?>" 
                                data-followup="<?= $doc['followup_fee'] ?>"
                                data-slot="<?= $doc['default_slot_duration'] ?>">
                            Dr. <?= sanitizeOutput($doc['full_name']) ?> - <?= sanitizeOutput($doc['specialty'] ?? 'General') ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Date <span class="required">*</span></label>
                        <input type="date" name="appointment_date" class="form-control" 
                               value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Time <span class="required">*</span></label>
                        <input type="time" name="appointment_time" class="form-control" value="<?= date('H:i') ?>" required>
                    </div>
                </div>
            </div>
            
            <!-- Right: Details -->
            <div>
                <h4 class="mb-16"><i class="fas fa-info-circle" style="color: var(--info);"></i> Appointment Details</h4>
                
                <div class="form-group">
                    <label class="form-label">Appointment Type</label>
                    <select name="appointment_type" class="form-control" onchange="updateFee()">
                        <option value="new">New Consultation</option>
                        <option value="followup">Follow-up</option>
                        <option value="walk_in">Walk-in</option>
                        <option value="emergency">Emergency</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Consultation Fee</label>
                    <div style="position: relative;">
                        <span style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-muted);">₹</span>
                        <input type="number" name="consultation_fee" id="consultFee" class="form-control" 
                               value="0" step="0.01" style="padding-left: 28px;">
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Visit Reason</label>
                    <textarea name="visit_reason" class="form-control" rows="3" placeholder="Reason for visit..."></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="2" placeholder="Additional notes..."></textarea>
                </div>
            </div>
        </div>
    </div>
    
    <div class="card-footer d-flex justify-end gap-12">
        <a href="<?= BASE_URL ?>/modules/appointments/list.php" class="btn btn-outline">Cancel</a>
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-calendar-check"></i> Book Appointment
        </button>
    </div>
</form>

<script>
// Patient Search and Autocomplete Logic
<?php if (!$prefilledPatient): ?>
const searchInput = document.getElementById('patientSearchInput');
const resultsBox = document.getElementById('patientSearchResults');
const hiddenIdInput = document.getElementById('selectedPatientId');
const selectedPatientCard = document.getElementById('selectedPatientCard');
const patientSearchSection = document.getElementById('patientSearchSection');
const clearBtn = document.getElementById('searchClearBtn');

let searchDebounceTimer = null;
let currentPatientsList = [];

function fetchAndRenderPatients(query = '') {
    resultsBox.innerHTML = '<div style="padding: 12px 16px; color: var(--text-muted); font-size: 13px;"><i class="fas fa-spinner fa-spin"></i> Searching patients...</div>';
    resultsBox.style.display = 'block';

    fetch(`${BASE_URL}/modules/patients/search_ajax.php?q=${encodeURIComponent(query)}`)
        .then(r => r.json())
        .then(patients => {
            currentPatientsList = patients || [];
            renderPatientResults(currentPatientsList, query);
        })
        .catch(() => {
            resultsBox.innerHTML = '<div style="padding: 12px 16px; color: var(--danger); font-size: 13px;">Failed to search patients.</div>';
        });
}

function renderPatientResults(patients, query) {
    if (!patients || patients.length === 0) {
        resultsBox.innerHTML = `
            <div style="padding: 18px 16px; text-align: center; color: var(--text-muted); font-size: 13px;">
                <i class="fas fa-user-slash mb-4" style="font-size: 22px; opacity: 0.4;"></i>
                <div>No patient found matching "<strong>${escapeHtml(query)}</strong>"</div>
                <div style="margin-top: 10px;">
                    <a href="${BASE_URL}/modules/patients/add.php" target="_blank" class="btn btn-sm btn-primary">+ Register New Patient</a>
                </div>
            </div>`;
        resultsBox.style.display = 'block';
        return;
    }

    let html = '';
    if (!query) {
        html += `<div style="padding: 6px 14px; background: var(--bg-surface, #f8fafc); border-bottom: 1px solid var(--border-color, #e2e8f0); font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Recent Patients</div>`;
    } else {
        html += `<div style="padding: 6px 14px; background: var(--bg-surface, #f8fafc); border-bottom: 1px solid var(--border-color, #e2e8f0); font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Matches Found (${patients.length})</div>`;
    }

    patients.forEach((p, idx) => {
        const metaParts = [];
        if (p.gender) metaParts.push(p.gender);
        if (p.age) metaParts.push(p.age + ' yrs');
        if (p.blood_group) metaParts.push(`<span class="badge badge-danger" style="font-size: 9px; padding: 1px 4px;">${p.blood_group}</span>`);
        const metaText = metaParts.join(' • ');

        html += `
        <div class="patient-search-item" data-index="${idx}" style="padding: 10px 14px; border-bottom: 1px solid var(--border-color, #f1f5f9); cursor: pointer; display: flex; justify-content: space-between; align-items: center; transition: background 0.15s;" onmouseover="this.style.background='var(--bg-surface, #f1f5f9)'" onmouseout="this.style.background='transparent'">
            <div style="flex: 1; min-width: 0; padding-right: 12px;">
                <div style="font-weight: 700; font-size: 14px; color: var(--text-primary); display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                    <span>${escapeHtml(p.first_name)} ${escapeHtml(p.last_name || '')}</span>
                    <span class="badge badge-primary" style="font-size: 11px; font-weight: 600; padding: 2px 6px;">${escapeHtml(p.patient_uid)}</span>
                </div>
                <div style="font-size: 12px; color: var(--text-muted); margin-top: 3px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                    <span><i class="fas fa-phone" style="font-size: 10px;"></i> <strong style="color: var(--text-primary);">${escapeHtml(p.phone || '-')}</strong></span>
                    ${metaText ? `<span>${metaText}</span>` : ''}
                </div>
            </div>
            <button type="button" class="btn btn-xs btn-primary" style="flex-shrink: 0; pointer-events: none;">
                Select <i class="fas fa-check" style="font-size: 10px; margin-left: 2px;"></i>
            </button>
        </div>`;
    });

    resultsBox.innerHTML = html;
    resultsBox.style.display = 'block';

    resultsBox.querySelectorAll('.patient-search-item').forEach(el => {
        el.addEventListener('click', function(e) {
            e.stopPropagation();
            const idx = parseInt(this.getAttribute('data-index'));
            selectPatient(currentPatientsList[idx]);
        });
    });
}

function selectPatient(patient) {
    if (!patient) return;
    hiddenIdInput.value = patient.id;
    document.getElementById('cardPatientName').textContent = `${patient.first_name} ${patient.last_name || ''}`;
    document.getElementById('cardPatientUid').textContent = patient.patient_uid;
    document.getElementById('cardPatientPhone').textContent = patient.phone || '-';
    
    const metaParts = [];
    if (patient.gender) metaParts.push(patient.gender);
    if (patient.age) metaParts.push(patient.age + ' yrs');
    if (patient.blood_group) metaParts.push(patient.blood_group);
    document.getElementById('cardPatientMeta').textContent = metaParts.length > 0 ? ('• ' + metaParts.join(' • ')) : '';

    patientSearchSection.style.display = 'none';
    resultsBox.style.display = 'none';
    selectedPatientCard.style.display = 'flex';
}

function changePatientSelection() {
    hiddenIdInput.value = '';
    selectedPatientCard.style.display = 'none';
    patientSearchSection.style.display = 'block';
    searchInput.value = '';
    clearBtn.style.display = 'none';
    searchInput.focus();
    fetchAndRenderPatients('');
}

function resetPatientSearchInput() {
    searchInput.value = '';
    clearBtn.style.display = 'none';
    searchInput.focus();
    fetchAndRenderPatients('');
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

if (searchInput) {
    searchInput.addEventListener('focus', function() {
        fetchAndRenderPatients(this.value.trim());
    });

    searchInput.addEventListener('input', function() {
        const val = this.value.trim();
        clearBtn.style.display = val ? 'block' : 'none';
        clearTimeout(searchDebounceTimer);
        searchDebounceTimer = setTimeout(() => {
            fetchAndRenderPatients(val);
        }, 220);
    });
}

// Close dropdown on outside click
document.addEventListener('click', function(e) {
    if (!e.target.closest('#patientSearchSection')) {
        if (resultsBox) resultsBox.style.display = 'none';
    }
});

// Form validation before submit
document.querySelector('form')?.addEventListener('submit', function(e) {
    const pid = document.querySelector('[name="patient_id"]')?.value;
    if (!pid || pid === '0') {
        e.preventDefault();
        alert('Please search and select a patient first.');
        if (searchInput) {
            patientSearchSection.style.display = 'block';
            searchInput.focus();
        }
        return false;
    }
});
<?php endif; ?>

function updateFee() {
    const select = document.getElementById('doctorSelect');
    const typeSelect = document.querySelector('[name="appointment_type"]');
    const feeInput = document.getElementById('consultFee');
    
    if (select.selectedIndex > 0) {
        const option = select.options[select.selectedIndex];
        const fee = typeSelect.value === 'followup' ? option.dataset.followup : option.dataset.fee;
        feeInput.value = parseFloat(fee || 0).toFixed(2);
    }
}
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
