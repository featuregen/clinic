<?php
/**
 * Dental Chart - Advanced Clinic Suite
 */
require_once dirname(dirname(__DIR__)) . '/config/session.php';
requirePermission('dental.view');

$db = db();
$clinicId = getCurrentClinicId();
$patientId = intval($_GET['patient_id'] ?? 0);

if (!$patientId) {
    // Show Search UI instead of redirect
    $pageTitle = 'Dental - Select Patient';
    require_once dirname(dirname(__DIR__)) . '/includes/header.php';
    ?>
    <div class="content-header">
        <div>
            <ul class="breadcrumb">
                <li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li>
                <li>Dental</li>
            </ul>
            <h1><i class="fas fa-tooth" style="color: var(--primary);"></i> Select Patient</h1>
        </div>
    </div>

    <div class="card" style="max-width: 600px; margin: 0 auto; text-align: center; padding: 40px;">
        <i class="fas fa-user-injured" style="font-size: 48px; color: var(--text-secondary); margin-bottom: 24px;"></i>
        <h2 style="margin-bottom: 16px;">Search Patient for Dental Chart</h2>
        <p class="text-muted mb-24">Please search and select a patient to view or edit their dental chart.</p>
        
        <div style="position: relative; max-width: 400px; margin: 0 auto;">
            <input type="text" id="patientSearch" class="form-control" placeholder="Search by name, phone, or ID..." style="padding-left: 40px; height: 48px; font-size: 16px;">
            <i class="fas fa-search" style="position: absolute; left: 16px; top: 16px; color: #999;"></i>
            <div id="searchResults" style="display:none; position:absolute; top: 100%; left:0; right:0; background:white; border:1px solid #eee; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); z-index: 100; max-height: 300px; overflow-y: auto; text-align: left;"></div>
        </div>
    </div>

    <script>
    const searchInput = document.getElementById('patientSearch');
    const resultsDiv = document.getElementById('searchResults');
    let debounceTimer;

    searchInput.addEventListener('input', function() {
        clearTimeout(debounceTimer);
        const query = this.value.trim();
        
        if (query.length < 2) {
            resultsDiv.style.display = 'none';
            return;
        }

        debounceTimer = setTimeout(() => {
            fetch(`<?= BASE_URL ?>/modules/patients/search_ajax.php?q=${encodeURIComponent(query)}`)
                .then(r => r.json())
                .then(data => {
                    resultsDiv.innerHTML = '';
                    if (data.length > 0) {
                        data.forEach(p => {
                            const div = document.createElement('div');
                            div.className = 'p-12';
                            div.style.borderBottom = '1px solid #eee';
                            div.style.cursor = 'pointer';
                            div.style.transition = 'background 0.2s';
                            div.onmouseover = () => div.style.background = '#f9f9f9';
                            div.onmouseout = () => div.style.background = 'white';
                            div.onclick = () => window.location.href = `chart.php?patient_id=${p.id}`;
                            div.innerHTML = `
                                <div class="font-bold">${p.first_name} ${p.last_name || ''}</div>
                                <div class="text-muted" style="font-size: 12px;">
                                    ID: ${p.patient_uid} | Phone: ${p.phone}
                                </div>
                            `;
                            resultsDiv.appendChild(div);
                        });
                        resultsDiv.style.display = 'block';
                    } else {
                        resultsDiv.innerHTML = '<div class="p-12 text-muted text-center">No patients found</div>';
                        resultsDiv.style.display = 'block';
                    }
                });
        }, 300);
    });

    // Close search on click outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('#patientSearch') && !e.target.closest('#searchResults')) {
            resultsDiv.style.display = 'none';
        }
    });
    </script>
    <?php
    require_once INCLUDES_PATH . '/footer.php';
    exit;
}

$patient = $db->fetch("SELECT * FROM patients WHERE id = ? AND clinic_id = ?", [$patientId, $clinicId]);
if (!$patient) {
    die("Patient not found");
}

// Fetch existing chart data
$chartData = $db->fetchAll("SELECT * FROM dental_charts WHERE patient_id = ?", [$patientId]);
$toothStatus = [];
foreach ($chartData as $row) {
    $toothStatus[$row['tooth_number']] = $row;
}

// Fetch treatments
$treatments = $db->fetchAll("SELECT * FROM dental_treatments WHERE patient_id = ? ORDER BY treatment_date DESC", [$patientId]);

$pageTitle = 'Dental Chart';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';

$adultTeethTop = range(1, 16);
$adultTeethBottom = range(32, 17); // Reverse order for bottom right to left
?>

<div class="content-header">
    <div>
        <ul class="breadcrumb">
            <li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li>
            <li><a href="<?= BASE_URL ?>/modules/dental/chart.php">Dental</a></li>
            <li><?= sanitizeOutput($patient['first_name'] . ' ' . $patient['last_name']) ?></li>
        </ul>
        <h1><i class="fas fa-tooth" style="color: var(--primary);"></i> Dental Chart: <?= sanitizeOutput($patient['first_name'] . ' ' . $patient['last_name']) ?></h1>
    </div>
    <button class="btn btn-primary" onclick="window.print()">
        <i class="fas fa-print"></i> Print Chart
    </button>
</div>

<div class="grid-2 gap-24">
    <!-- Chart -->
    <div class="card col-span-2">
        <div class="card-header">
            <h3>Odontogram (Adult)</h3>
            <div class="d-flex gap-12">
                <span class="badge badge-success"><i class="fas fa-circle"></i> Healthy</span>
                <span class="badge badge-danger"><i class="fas fa-circle"></i> Decayed</span>
                <span class="badge badge-info"><i class="fas fa-circle"></i> Filled</span>
                <span class="badge badge-warning"><i class="fas fa-circle"></i> Missing</span>
            </div>
        </div>
        <div class="card-body text-center">
            <!-- Top Arch -->
            <div class="teeth-row mb-24">
                <?php foreach ($adultTeethTop as $t): 
                    $status = $toothStatus[$t]['status'] ?? 'healthy';
                    $colorClass = 'tooth-' . $status;
                ?>
                <div class="tooth-container" onclick="openToothModal(<?= $t ?>)">
                    <div class="tooth-number"><?= $t ?></div>
                    <div class="tooth-icon <?= $colorClass ?>"><i class="fas fa-tooth fa-2x"></i></div>
                    <div class="tooth-status"><?= ucfirst($status) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <hr class="mb-24">
            
            <!-- Bottom Arch -->
            <div class="teeth-row">
                <?php foreach ($adultTeethBottom as $t): 
                    $status = $toothStatus[$t]['status'] ?? 'healthy';
                    $colorClass = 'tooth-' . $status;
                ?>
                <div class="tooth-container" onclick="openToothModal(<?= $t ?>)">
                    <div class="tooth-status"><?= ucfirst($status) ?></div>
                    <div class="tooth-icon <?= $colorClass ?>"><i class="fas fa-tooth fa-2x fa-rotate-180"></i></div>
                    <div class="tooth-number"><?= $t ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <!-- Treatments List -->
    <div class="card col-span-2">
        <div class="card-header">
            <h3>Treatment History</h3>
            <button class="btn btn-sm btn-outline" onclick="showTreatmentModal()">Add General Treatment</button>
        </div>
        <div class="card-body p-0">
            <table class="table">
                <thead><tr><th>Date</th><th>Tooth</th><th>Procedure</th><th>Status</th><th>Cost</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($treatments as $t): ?>
                    <tr>
                        <td><?= formatDate($t['treatment_date']) ?></td>
                        <td><?= $t['tooth_number'] ?: 'General' ?></td>
                        <td class="font-semibold"><?= sanitizeOutput($t['procedure_name']) ?></td>
                        <td><span class="badge badge-<?= $t['status'] === 'completed' ? 'success' : ($t['status'] === 'planned' ? 'warning' : 'secondary') ?>"><?= ucfirst($t['status']) ?></span></td>
                        <td><?= formatCurrency($t['cost']) ?></td>
                        <td>
                            <button class="btn btn-sm btn-ghost text-primary" onclick='editTreatment(<?= json_encode($t) ?>)' title="Edit">
                                <i class="fas fa-pen"></i>
                            </button>
                            <button class="btn btn-sm btn-ghost text-danger" onclick="deleteTreatment(<?= $t['id'] ?>)" title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Tooth Modal -->
<div id="toothModal" class="modal-overlay">
    <div class="modal">
        <div class="card-header">
            <h3>Manage Tooth #<span id="modalToothNumber"></span></h3>
            <button class="btn btn-sm btn-ghost" onclick="closeToothModal()">&times;</button>
        </div>
        <div class="card-body">
            <ul class="nav nav-tabs mb-12 d-flex gap-12" style="border-bottom: 1px solid #eee; padding-bottom: 8px;">
                <li><a href="#" onclick="switchTab('status')" class="active font-bold">Status</a></li>
                <li><a href="#" onclick="switchTab('treatment')">Add Treatment</a></li>
            </ul>
            
            <!-- Status Tab -->
            <div id="tab-status">
                <form id="statusForm" onsubmit="saveStatus(event)">
                    <input type="hidden" name="tooth_number" id="statusTooth">
                    <input type="hidden" name="patient_id" value="<?= $patientId ?>">
                    <div class="form-group">
                        <label class="form-label">Condition</label>
                        <select name="status" class="form-control">
                            <option value="healthy">Healthy</option>
                            <option value="decayed">Decayed</option>
                            <option value="filled">Filled</option>
                            <option value="missing">Missing</option>
                            <option value="root_canal">Root Canal</option>
                            <option value="crown">Crown</option>
                            <option value="implant">Implant</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">Update Status</button>
                </form>
            </div>
            
            <!-- Treatment Tab -->
            <div id="tab-treatment" style="display:none;">
                <form id="treatmentForm" onsubmit="saveTreatment(event)">
                    <input type="hidden" name="id" id="treatmentId" value="">
                    <input type="hidden" name="tooth_number" id="treatmentTooth">
                    <input type="hidden" name="patient_id" value="<?= $patientId ?>">
                    <div class="form-group">
                        <label class="form-label">Procedure</label>
                        <input type="text" name="procedure_name" id="t_procedure" class="form-control" required placeholder="e.g. Extraction">
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <select name="status" id="t_status" class="form-control">
                                <option value="planned">Planned</option>
                                <option value="in_progress">In Progress</option>
                                <option value="completed">Completed</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Cost</label>
                            <input type="number" name="cost" id="t_cost" class="form-control" value="0">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-accent btn-block" id="btnSaveTreatment">Save Treatment</button>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
.teeth-row { display: flex; justify-content: center; gap: 8px; flex-wrap: wrap; }
.tooth-container { cursor: pointer; text-align: center; padding: 8px; border-radius: 8px; transition: background 0.2s; }
.tooth-container:hover { background: #f0f9ff; }
.tooth-number { font-size: 12px; font-weight: bold; color: #666; }
.tooth-icon { color: #ddd; transition: color 0.3s; }
.tooth-status { font-size: 10px; height: 14px; }

/* Status Colors */
.tooth-healthy { color: var(--success); }
.tooth-decayed { color: var(--danger); }
.tooth-filled { color: var(--info); }
.tooth-missing { color: #999; opacity: 0.5; }
.tooth-root_canal { color: var(--warning); }
.tooth-crown { color: var(--accent); }

/* Override modal for dental - use card styling inside */
#toothModal .modal { padding: 0; }
#toothModal .modal .card-header,
#toothModal .modal .card-body { padding: 20px; }
</style>

<script>
let currentTooth = 0;

function showTreatmentModal() {
    currentTooth = 0;
    document.getElementById('modalToothNumber').textContent = 'General';
    // Hide status tab for general treatment, go straight to treatment
    document.getElementById('tab-status').style.display = 'none';
    document.getElementById('tab-treatment').style.display = 'block';
    
    // Update active tab link styling
    document.querySelectorAll('.nav-tabs a').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.nav-tabs a')[1].classList.add('active'); // Select Treatment tab
    
    // Reset form
    document.getElementById('treatmentForm').reset();
    document.getElementById('treatmentId').value = '';
    document.getElementById('statusTooth').value = '';
    document.getElementById('treatmentTooth').value = '';
    document.getElementById('btnSaveTreatment').textContent = 'Add Treatment';
    
    document.getElementById('toothModal').classList.add('active');
}

function editTreatment(t) {
    currentTooth = t.tooth_number || 0;
    document.getElementById('modalToothNumber').textContent = currentTooth ? currentTooth : 'General';
    
    // Hide status tab
    document.getElementById('tab-status').style.display = 'none';
    document.getElementById('tab-treatment').style.display = 'block';
    
    document.querySelectorAll('.nav-tabs a').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.nav-tabs a')[1].classList.add('active');
    
    // Fill form
    document.getElementById('treatmentId').value = t.id;
    document.getElementById('treatmentTooth').value = t.tooth_number;
    document.getElementById('t_procedure').value = t.procedure_name;
    document.getElementById('t_status').value = t.status;
    document.getElementById('t_cost').value = t.cost;
    document.getElementById('btnSaveTreatment').textContent = 'Update Treatment';
    
    document.getElementById('toothModal').classList.add('active');
}

function openToothModal(tooth) {
    currentTooth = tooth;
    document.getElementById('modalToothNumber').textContent = tooth;
    document.getElementById('statusTooth').value = tooth;
    document.getElementById('treatmentTooth').value = tooth;
    document.getElementById('toothModal').classList.add('active');
    switchTab('status'); // Reset to status tab
}

function closeToothModal() {
    document.getElementById('toothModal').classList.remove('active');
}

function switchTab(tab) {
    document.getElementById('tab-status').style.display = tab === 'status' ? 'block' : 'none';
    document.getElementById('tab-treatment').style.display = tab === 'treatment' ? 'block' : 'none';
}

function saveStatus(e) {
    e.preventDefault();
    const formData = new FormData(e.target);
    fetch('save_chart.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(res => {
        if(res.success) {
            location.reload();
        } else {
            alert('Error: ' + res.error);
        }
    });
}

function saveTreatment(e) {
    e.preventDefault();
    const formData = new FormData(e.target);
    fetch('save_treatment.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(res => {
        if(res.success) {
            location.reload();
        } else {
            alert('Error: ' + res.error);
        }
    });
}

function deleteTreatment(id) {
    if(!confirm('Are you sure you want to delete this treatment record?')) return;
    
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', id);
    
    fetch('save_treatment.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(res => {
        if(res.success) {
            location.reload();
        } else {
            alert('Error deleting treatment');
        }
    });
}
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
