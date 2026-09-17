<?php
/**
 * Dental Chart - Feature Gen Care
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

    <div class="card" style="max-width: 580px; margin: 0 auto; overflow: visible; border: none; box-shadow: 0 8px 32px rgba(0,0,0,0.08); border-radius: 16px;">
        <!-- Gradient Header -->
        <div style="background: linear-gradient(135deg, #0891b2 0%, #0e7490 50%, #155e75 100%); padding: 36px 32px 28px; text-align: center; border-radius: 16px 16px 0 0; position: relative; overflow: hidden;">
            <div style="position: absolute; top: -20px; right: -20px; width: 120px; height: 120px; background: rgba(255,255,255,0.06); border-radius: 50%;"></div>
            <div style="position: absolute; bottom: -30px; left: -10px; width: 80px; height: 80px; background: rgba(255,255,255,0.04); border-radius: 50%;"></div>
            <div style="width: 64px; height: 64px; background: rgba(255,255,255,0.15); border-radius: 16px; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; backdrop-filter: blur(4px);">
                <i class="fas fa-tooth" style="font-size: 28px; color: white; animation: toothPulse 2s ease-in-out infinite;"></i>
            </div>
            <h2 style="margin: 0 0 6px; color: white; font-size: 22px; font-weight: 800;">Dental Chart</h2>
            <p style="margin: 0; color: rgba(255,255,255,0.75); font-size: 13.5px;">Search and select a patient to view or edit their dental chart</p>
        </div>

        <!-- Search Body -->
        <div style="padding: 28px 32px 32px;">
            <div style="position: relative; margin-bottom: 8px;">
                <i class="fas fa-search" style="position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 15px; z-index: 2; pointer-events: none;"></i>
                <input type="text" id="patientSearch" class="form-control" placeholder="Search by name, phone, or patient ID..." autocomplete="off"
                       style="padding-left: 44px; padding-right: 16px; height: 50px; font-size: 15px; border: 2px solid #e2e8f0; border-radius: 12px; transition: all 0.2s; background: #f8fafc;"
                       onfocus="this.style.borderColor='#0891b2'; this.style.background='white'; this.style.boxShadow='0 0 0 3px rgba(8,145,178,0.1)'"
                       onblur="this.style.borderColor='#e2e8f0'; this.style.background='#f8fafc'; this.style.boxShadow='none'">
            </div>
            <div style="display: flex; align-items: center; gap: 6px; margin-top: 8px;">
                <i class="fas fa-info-circle" style="color: #94a3b8; font-size: 11px;"></i>
                <span style="color: #94a3b8; font-size: 11.5px;">Type at least 2 characters to search</span>
            </div>

            <!-- Dropdown Results -->
            <div id="searchResults" style="display:none; position:absolute; left: 16px; right: 16px; background: white; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 12px 36px rgba(0,0,0,0.15); z-index: 9999; max-height: 340px; overflow-y: auto; text-align: left; margin-top: 4px;"></div>
        </div>

        <!-- Quick Tip -->
        <div style="padding: 0 32px 24px;">
            <div style="background: linear-gradient(135deg, #f0fdfa 0%, #ecfeff 100%); border: 1px solid #99f6e4; border-radius: 10px; padding: 14px 16px; display: flex; align-items: flex-start; gap: 10px;">
                <i class="fas fa-lightbulb" style="color: #0d9488; font-size: 14px; margin-top: 2px; flex-shrink: 0;"></i>
                <div style="font-size: 12px; color: #0f766e; line-height: 1.5;">
                    <strong>Quick Tip:</strong> You can search by phone number for the fastest results, or use the patient ID (e.g. PT001000).
                </div>
            </div>
        </div>
    </div>

    <style>
    @keyframes toothPulse {
        0%, 100% { transform: scale(1); }
        50% { transform: scale(1.08); }
    }
    </style>

    <script>
    const searchInput = document.getElementById('patientSearch');
    const resultsDiv = document.getElementById('searchResults');
    let debounceTimer;

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function getInitials(name) {
        return name.split(' ').map(w => w.charAt(0)).join('').substring(0, 2).toUpperCase();
    }

    function getAvatarColor(name) {
        const colors = ['#0891b2','#7c3aed','#db2777','#ea580c','#0d9488','#2563eb','#c026d3','#059669'];
        let hash = 0;
        for (let i = 0; i < name.length; i++) hash = name.charCodeAt(i) + ((hash << 5) - hash);
        return colors[Math.abs(hash) % colors.length];
    }

    searchInput.addEventListener('input', function() {
        clearTimeout(debounceTimer);
        const query = this.value.trim();
        
        if (query.length < 2) {
            resultsDiv.style.display = 'none';
            return;
        }

        // Show loading state
        resultsDiv.innerHTML = `
            <div style="padding: 20px; text-align: center; color: #64748b; font-size: 13px;">
                <i class="fas fa-spinner fa-spin" style="margin-right: 6px;"></i> Searching patients...
            </div>`;
        resultsDiv.style.display = 'block';

        debounceTimer = setTimeout(() => {
            fetch(`<?= BASE_URL ?>/modules/patients/search_ajax.php?q=${encodeURIComponent(query)}`)
                .then(r => r.json())
                .then(data => {
                    resultsDiv.innerHTML = '';
                    if (data.length > 0) {
                        // Results header
                        resultsDiv.innerHTML += `
                            <div style="padding: 8px 16px; background: #f8fafc; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; border-radius: 12px 12px 0 0;">
                                <i class="fas fa-users" style="margin-right: 4px;"></i> ${data.length} patient${data.length > 1 ? 's' : ''} found
                            </div>`;

                        data.forEach(p => {
                            const fullName = escapeHtml(p.first_name) + ' ' + escapeHtml(p.last_name || '');
                            const initials = getInitials(fullName);
                            const avatarColor = getAvatarColor(fullName);
                            const metaParts = [];
                            if (p.gender) metaParts.push(p.gender);
                            if (p.age) metaParts.push(p.age + ' yrs');
                            if (p.blood_group) metaParts.push(`<span style="background:#fef2f2; color:#dc2626; padding:1px 5px; border-radius:4px; font-size:10px; font-weight:700;">${escapeHtml(p.blood_group)}</span>`);

                            const div = document.createElement('div');
                            div.style.cssText = 'padding: 12px 16px; border-bottom: 1px solid #f1f5f9; cursor: pointer; display: flex; align-items: center; gap: 12px; transition: all 0.15s ease;';
                            div.onmouseover = () => { div.style.background = '#f0fdfa'; div.style.paddingLeft = '20px'; };
                            div.onmouseout = () => { div.style.background = 'white'; div.style.paddingLeft = '16px'; };
                            div.onclick = () => window.location.href = `chart.php?patient_id=${p.id}`;
                            div.innerHTML = `
                                <div style="width: 40px; height: 40px; border-radius: 10px; background: ${avatarColor}; color: white; display: flex; align-items: center; justify-content: center; font-size: 14px; font-weight: 700; flex-shrink: 0;">
                                    ${initials}
                                </div>
                                <div style="flex: 1; min-width: 0;">
                                    <div style="font-weight: 700; font-size: 14px; color: #1e293b; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                        ${fullName}
                                        <span style="background: #e0f2fe; color: #0369a1; padding: 2px 7px; border-radius: 5px; font-size: 10.5px; font-weight: 600;">${escapeHtml(p.patient_uid)}</span>
                                    </div>
                                    <div style="font-size: 12px; color: #64748b; margin-top: 3px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                        <span><i class="fas fa-phone" style="font-size: 10px; margin-right: 3px;"></i>${escapeHtml(p.phone || '—')}</span>
                                        ${metaParts.length > 0 ? '<span style="color:#cbd5e1;">•</span> ' + metaParts.join(' <span style="color:#cbd5e1;">•</span> ') : ''}
                                    </div>
                                </div>
                                <div style="flex-shrink: 0;">
                                    <i class="fas fa-chevron-right" style="color: #cbd5e1; font-size: 12px;"></i>
                                </div>
                            `;
                            resultsDiv.appendChild(div);
                        });
                        resultsDiv.style.display = 'block';
                    } else {
                        resultsDiv.innerHTML = `
                            <div style="padding: 28px 16px; text-align: center;">
                                <i class="fas fa-user-slash" style="font-size: 28px; color: #e2e8f0; margin-bottom: 10px;"></i>
                                <div style="font-size: 14px; color: #64748b; font-weight: 600;">No patients found</div>
                                <div style="font-size: 12px; color: #94a3b8; margin-top: 4px;">Try a different name, phone, or patient ID</div>
                                <a href="<?= BASE_URL ?>/modules/patients/add.php" target="_blank" class="btn btn-sm btn-primary" style="margin-top: 14px; font-size: 12px;">
                                    <i class="fas fa-plus"></i> Register New Patient
                                </a>
                            </div>`;
                        resultsDiv.style.display = 'block';
                    }
                });
        }, 250);
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
