<?php
/**
 * Book Appointment - Advanced Clinic Suite
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
        try {
            $tokenNumber = generateTokenNumber($doctorId, $date);
            
            $db->query(
                "INSERT INTO appointments (clinic_id, patient_id, doctor_id, appointment_date, appointment_time, 
                 token_number, appointment_type, visit_reason, consultation_fee, booked_by, source)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$clinicId, $patientId, $doctorId, $date, $time, $tokenNumber, $type, $reason, $fee, getCurrentUserId(), 'web']
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
     WHERE d.clinic_id = ? AND d.is_available = 1 ORDER BY u.full_name",
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
                <div class="form-group">
                    <label class="form-label">Select Patient <span class="required">*</span></label>
                    <select name="patient_id" id="patientSelect" class="form-control" required>
                        <option value="">Search and select patient...</option>
                    </select>
                    <div class="form-text">
                        <a href="<?= BASE_URL ?>/modules/patients/add.php">+ Register New Patient</a>
                    </div>
                </div>
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
// Load patients for search
<?php if (!$prefilledPatient): ?>
fetch(`${BASE_URL}/modules/patients/search_ajax.php?clinic_id=<?= $clinicId ?>`)
    .then(r => r.json())
    .then(patients => {
        const select = document.getElementById('patientSelect');
        patients.forEach(p => {
            const opt = document.createElement('option');
            opt.value = p.id;
            opt.textContent = `${p.patient_uid} - ${p.first_name} ${p.last_name || ''} (${p.phone})`;
            select.appendChild(opt);
        });
    }).catch(() => {});
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
