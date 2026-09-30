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
        <div style="padding: 24px 28px 28px;">
            <div style="position: relative; margin-bottom: 6px;">
                <i class="fas fa-search" style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 14px; z-index: 2; pointer-events: none;"></i>
                <input type="text" id="patientSearch" class="form-control" placeholder="Search by name, phone, or patient ID..." autocomplete="off"
                       style="padding-left: 38px; padding-right: 36px; height: 46px; font-size: 14px; border: 2px solid #e2e8f0; border-radius: 10px; transition: all 0.2s; background: #f8fafc;"
                       onfocus="this.style.borderColor='#0891b2'; this.style.background='white'; this.style.boxShadow='0 0 0 3px rgba(8,145,178,0.1)'"
                       onblur="this.style.borderColor='#e2e8f0'; this.style.background='#f8fafc'; this.style.boxShadow='none'">
                <button type="button" id="clearSearchBtn" style="display: none; position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #94a3b8; cursor: pointer; padding: 4px; font-size: 14px; z-index: 3;" title="Clear">
                    <i class="fas fa-times-circle"></i>
                </button>

                <!-- Compact Dropdown Results: constrained directly to this input box -->
                <div id="searchResults" style="display:none; position:absolute; top: calc(100% + 4px); left: 0; right: 0; width: 100%; background: white; border: 1px solid #e2e8f0; border-radius: 10px; box-shadow: 0 10px 28px rgba(0,0,0,0.12); z-index: 9999; max-height: 280px; overflow-y: auto; text-align: left;"></div>
            </div>
            <div style="display: flex; align-items: center; gap: 6px; margin-top: 6px;">
                <i class="fas fa-info-circle" style="color: #94a3b8; font-size: 11px;"></i>
                <span style="color: #94a3b8; font-size: 11px;">Type at least 2 characters to search</span>
            </div>
        </div>

        <!-- Quick Tip -->
        <div style="padding: 0 28px 24px;">
            <div style="background: linear-gradient(135deg, #f0fdfa 0%, #ecfeff 100%); border: 1px solid #99f6e4; border-radius: 10px; padding: 12px 14px; display: flex; align-items: flex-start; gap: 10px;">
                <i class="fas fa-lightbulb" style="color: #0d9488; font-size: 13px; margin-top: 2px; flex-shrink: 0;"></i>
                <div style="font-size: 11.5px; color: #0f766e; line-height: 1.4;">
                    <strong>Quick Tip:</strong> Search by phone number for fastest results, or use the patient ID (e.g. PT001000).
                </div>
            </div>
        </div>
    </div>

    <style>
    @keyframes toothPulse {
        0%, 100% { transform: scale(1); }
        50% { transform: scale(1.08); }
    }
    #searchResults::-webkit-scrollbar {
        width: 6px;
    }
    #searchResults::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 3px;
    }
    </style>

    <script>
    const searchInput = document.getElementById('patientSearch');
    const resultsDiv = document.getElementById('searchResults');
    const clearBtn = document.getElementById('clearSearchBtn');
    let debounceTimer;

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function getInitials(name) {
        return name.split(' ').filter(Boolean).map(w => w.charAt(0)).join('').substring(0, 2).toUpperCase() || 'P';
    }

    function getAvatarColor(name) {
        const colors = ['#0891b2','#7c3aed','#db2777','#ea580c','#0d9488','#2563eb','#c026d3','#059669'];
        let hash = 0;
        for (let i = 0; i < name.length; i++) hash = name.charCodeAt(i) + ((hash << 5) - hash);
        return colors[Math.abs(hash) % colors.length];
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', function() {
            searchInput.value = '';
            resultsDiv.style.display = 'none';
            clearBtn.style.display = 'none';
            searchInput.focus();
        });
    }

    searchInput.addEventListener('input', function() {
        clearTimeout(debounceTimer);
        const query = this.value.trim();
        
        if (query.length > 0) {
            clearBtn.style.display = 'block';
        } else {
            clearBtn.style.display = 'none';
        }

        if (query.length < 2) {
            resultsDiv.style.display = 'none';
            return;
        }

        // Show loading state
        resultsDiv.innerHTML = `
            <div style="padding: 14px; text-align: center; color: #64748b; font-size: 12px;">
                <i class="fas fa-spinner fa-spin" style="margin-right: 6px; color: #0891b2;"></i> Searching patients...
            </div>`;
        resultsDiv.style.display = 'block';

        debounceTimer = setTimeout(() => {
            fetch(`<?= BASE_URL ?>/modules/patients/search_ajax.php?q=${encodeURIComponent(query)}`)
                .then(r => r.json())
                .then(data => {
                    resultsDiv.innerHTML = '';
                    if (data && data.length > 0) {
                        // Compact results header
                        resultsDiv.innerHTML += `
                            <div style="padding: 6px 12px; background: #f8fafc; border-bottom: 1px solid #f1f5f9; font-size: 10.5px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; border-radius: 9px 9px 0 0; display: flex; justify-content: space-between; align-items: center;">
                                <span><i class="fas fa-users" style="margin-right: 4px;"></i> ${data.length} patient${data.length > 1 ? 's' : ''} found</span>
                                <span style="font-size: 10px; color: #94a3b8; text-transform: none; font-weight: 400;">Select patient</span>
                            </div>`;

                        data.forEach(p => {
                            const fullName = escapeHtml(p.first_name) + ' ' + escapeHtml(p.last_name || '');
                            const initials = getInitials(fullName);
                            const avatarColor = getAvatarColor(fullName);
                            const metaParts = [];
                            if (p.gender) metaParts.push(p.gender);
                            if (p.age) metaParts.push(p.age + ' yrs');
                            if (p.blood_group) metaParts.push(`<span style="background:#fef2f2; color:#dc2626; padding:0 4px; border-radius:3px; font-size:9.5px; font-weight:700;">${escapeHtml(p.blood_group)}</span>`);

                            const div = document.createElement('div');
                            div.style.cssText = 'padding: 8px 12px; border-bottom: 1px solid #f8fafc; cursor: pointer; display: flex; align-items: center; gap: 10px; transition: background 0.12s ease;';
                            div.onmouseover = () => { div.style.background = '#f0fdfa'; };
                            div.onmouseout = () => { div.style.background = 'white'; };
                            div.onclick = () => window.location.href = `chart.php?patient_id=${p.id}`;
                            div.innerHTML = `
                                <div style="width: 28px; height: 28px; border-radius: 6px; background: ${avatarColor}; color: white; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; flex-shrink: 0;">
                                    ${initials}
                                </div>
                                <div style="flex: 1; min-width: 0;">
                                    <div style="font-weight: 600; font-size: 13px; color: #1e293b; display: flex; align-items: center; gap: 6px; line-height: 1.2;">
                                        <span style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${fullName}</span>
                                        <span style="background: #e0f2fe; color: #0369a1; padding: 1px 5px; border-radius: 4px; font-size: 10px; font-weight: 700; flex-shrink: 0;">${escapeHtml(p.patient_uid)}</span>
                                    </div>
                                    <div style="font-size: 11px; color: #64748b; margin-top: 2px; display: flex; align-items: center; gap: 6px; line-height: 1.2;">
                                        <span><i class="fas fa-phone-alt" style="font-size: 9px; margin-right: 3px; color: #94a3b8;"></i>${escapeHtml(p.phone || '—')}</span>
                                        ${metaParts.length > 0 ? '<span style="color:#cbd5e1;">•</span> ' + metaParts.join(' <span style="color:#cbd5e1;">•</span> ') : ''}
                                    </div>
                                </div>
                                <div style="flex-shrink: 0; padding-left: 4px;">
                                    <i class="fas fa-chevron-right" style="color: #cbd5e1; font-size: 10px;"></i>
                                </div>
                            `;
                            resultsDiv.appendChild(div);
                        });
                        resultsDiv.style.display = 'block';
                    } else {
                        resultsDiv.innerHTML = `
                            <div style="padding: 20px 14px; text-align: center;">
                                <i class="fas fa-user-slash" style="font-size: 22px; color: #cbd5e1; margin-bottom: 6px;"></i>
                                <div style="font-size: 13px; color: #64748b; font-weight: 600;">No patients found</div>
                                <div style="font-size: 11px; color: #94a3b8; margin-top: 2px;">Try a different name, phone, or patient ID</div>
                                <a href="<?= BASE_URL ?>/modules/patients/add.php" target="_blank" class="btn btn-sm btn-primary" style="margin-top: 10px; font-size: 11.5px; padding: 4px 12px;">
                                    <i class="fas fa-plus"></i> Register New Patient
                                </a>
                            </div>`;
                        resultsDiv.style.display = 'block';
                    }
                })
                .catch(() => {
                    resultsDiv.innerHTML = `
                        <div style="padding: 14px; text-align: center; color: #ef4444; font-size: 12px;">
                            <i class="fas fa-exclamation-circle" style="margin-right: 4px;"></i> Error searching patients. Please try again.
                        </div>`;
                    resultsDiv.style.display = 'block';
                });
        }, 220);
    });

    // Close search on click outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('#patientSearch') && !e.target.closest('#searchResults') && !e.target.closest('#clearSearchBtn')) {
            resultsDiv.style.display = 'none';
        }
    });

    // Close search on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
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

// Calculate patient age & determine child status
$patientAge = !empty($patient['date_of_birth']) ? calculateAge($patient['date_of_birth']) : intval($patient['age'] ?? 0);
$patientAge = intval($patientAge);
$isKid = ($patientAge > 0 && $patientAge <= 12);

// Auto-detect default dentition: If child (age <= 12), default to pediatric dentition (20 teeth)
$selectedDentition = sanitize($_GET['dentition'] ?? '');
if (!in_array($selectedDentition, ['adult', 'pediatric', 'mixed'])) {
    $selectedDentition = $isKid ? 'pediatric' : 'adult';
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

// Adult Permanent Teeth: Universal 1-16 top, 32-17 bottom
$adultTeethTop = range(1, 16);
$adultTeethBottom = range(32, 17); // Reverse order for bottom right to left

// Pediatric / Primary Milk Teeth (20 teeth): FDI 51-85 & Universal A-T
$pedTeethTop = [
    ['fdi' => 55, 'letter' => 'A', 'name' => 'Upper Right Second Molar'],
    ['fdi' => 54, 'letter' => 'B', 'name' => 'Upper Right First Molar'],
    ['fdi' => 53, 'letter' => 'C', 'name' => 'Upper Right Canine'],
    ['fdi' => 52, 'letter' => 'D', 'name' => 'Upper Right Lateral Incisor'],
    ['fdi' => 51, 'letter' => 'E', 'name' => 'Upper Right Central Incisor'],
    ['fdi' => 61, 'letter' => 'F', 'name' => 'Upper Left Central Incisor'],
    ['fdi' => 62, 'letter' => 'G', 'name' => 'Upper Left Lateral Incisor'],
    ['fdi' => 63, 'letter' => 'H', 'name' => 'Upper Left Canine'],
    ['fdi' => 64, 'letter' => 'I', 'name' => 'Upper Left First Molar'],
    ['fdi' => 65, 'letter' => 'J', 'name' => 'Upper Left Second Molar'],
];

$pedTeethBottom = [
    ['fdi' => 85, 'letter' => 'T', 'name' => 'Lower Right Second Molar'],
    ['fdi' => 84, 'letter' => 'S', 'name' => 'Lower Right First Molar'],
    ['fdi' => 83, 'letter' => 'R', 'name' => 'Lower Right Canine'],
    ['fdi' => 82, 'letter' => 'Q', 'name' => 'Lower Right Lateral Incisor'],
    ['fdi' => 81, 'letter' => 'P', 'name' => 'Lower Right Central Incisor'],
    ['fdi' => 71, 'letter' => 'O', 'name' => 'Lower Left Central Incisor'],
    ['fdi' => 72, 'letter' => 'N', 'name' => 'Lower Left Lateral Incisor'],
    ['fdi' => 73, 'letter' => 'M', 'name' => 'Lower Left Canine'],
    ['fdi' => 74, 'letter' => 'L', 'name' => 'Lower Left First Molar'],
    ['fdi' => 75, 'letter' => 'K', 'name' => 'Lower Left Second Molar'],
];

function formatToothDisplay($toothNum) {
    if (empty($toothNum)) return '<span class="text-muted">General</span>';
    $primaryLetters = [
        55 => 'A', 54 => 'B', 53 => 'C', 52 => 'D', 51 => 'E',
        61 => 'F', 62 => 'G', 63 => 'H', 64 => 'I', 65 => 'J',
        71 => 'O', 72 => 'N', 73 => 'M', 74 => 'L', 75 => 'K',
        81 => 'P', 82 => 'Q', 83 => 'R', 84 => 'S', 85 => 'T'
    ];
    if (isset($primaryLetters[$toothNum])) {
        return '<span class="font-semibold">Tooth #' . $toothNum . '</span> <span class="badge badge-info" style="font-size: 10px; font-weight: 700; background: #e0f2fe; color: #0369a1; padding: 1px 5px; border-radius: 4px;">Primary ' . $primaryLetters[$toothNum] . '</span>';
    }
    return '<span class="font-semibold">Tooth #' . $toothNum . '</span>';
}
?>

<div class="content-header no-print">
    <div>
        <ul class="breadcrumb">
            <li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li>
            <li><a href="<?= BASE_URL ?>/modules/dental/chart.php">Dental</a></li>
            <li><?= sanitizeOutput($patient['first_name'] . ' ' . $patient['last_name']) ?></li>
        </ul>
        <div class="d-flex align-center gap-12 flex-wrap">
            <h1 style="margin: 0;"><i class="fas fa-tooth" style="color: var(--primary);"></i> Dental Chart: <?= sanitizeOutput($patient['first_name'] . ' ' . $patient['last_name']) ?></h1>
            <span class="badge badge-secondary" style="font-size: 12px; font-weight: 600;"><?= sanitizeOutput($patient['patient_uid']) ?></span>
            <?php if ($isKid): ?>
            <span class="badge badge-info" style="font-size: 11.5px; font-weight: 700; background: #fef3c7; color: #b45309; border: 1px solid #fde68a;">
                <i class="fas fa-child"></i> Pediatric Patient (Age: <?= $patientAge ?> yrs)
            </span>
            <?php elseif ($patientAge > 0): ?>
            <span class="text-muted" style="font-size: 12px;">Age: <?= $patientAge ?> yrs &bull; <?= sanitizeOutput($patient['gender'] ?? '-') ?></span>
            <?php endif; ?>
        </div>
    </div>
    <div class="d-flex gap-8 align-center">
        <a href="<?= BASE_URL ?>/modules/dental/chart.php" class="btn btn-outline" title="Switch Patient">
            <i class="fas fa-search"></i> Switch Patient
        </a>
        <button class="btn btn-primary" onclick="window.print()">
            <i class="fas fa-print"></i> Print Chart
        </button>
    </div>
</div>

<div class="grid-2 gap-24">
    <!-- Odontogram Chart Card -->
    <div class="card col-span-2">
        <div class="card-header d-flex justify-between align-center flex-wrap gap-12">
            <div class="d-flex align-center gap-12 flex-wrap">
                <h3 id="odontogramTitle" style="margin: 0;">
                    Odontogram 
                    <span id="dentitionBadge" class="text-muted" style="font-size: 13px; font-weight: 600;">
                        <?= ($selectedDentition === 'pediatric') ? '(Pediatric - 20 Teeth)' : (($selectedDentition === 'mixed') ? '(Mixed Dentition)' : '(Adult - 32 Teeth)') ?>
                    </span>
                </h3>
            </div>

            <!-- Dentition Toggle Controls -->
            <div class="d-flex align-center gap-12 flex-wrap">
                <div class="dentition-toggle-group">
                    <button type="button" class="dentition-btn <?= ($selectedDentition === 'pediatric') ? 'active' : '' ?>" 
                            onclick="switchDentition('pediatric')" id="btnDentitionPediatric"
                            title="Primary / Deciduous Dentition for Children">
                        <i class="fas fa-child"></i> Pediatric (20 Teeth)
                    </button>
                    <button type="button" class="dentition-btn <?= ($selectedDentition === 'adult') ? 'active' : '' ?>" 
                            onclick="switchDentition('adult')" id="btnDentitionAdult"
                            title="Permanent Dentition for Adults">
                        <i class="fas fa-user"></i> Adult (32 Teeth)
                    </button>
                    <button type="button" class="dentition-btn <?= ($selectedDentition === 'mixed') ? 'active' : '' ?>" 
                            onclick="switchDentition('mixed')" id="btnDentitionMixed"
                            title="Both Primary & Permanent Teeth for Ages 6-12">
                        <i class="fas fa-layer-group"></i> Mixed Dentition
                    </button>
                </div>

                <div class="d-flex gap-8 align-center condition-legend">
                    <span class="badge badge-success"><i class="fas fa-circle"></i> Healthy</span>
                    <span class="badge badge-danger"><i class="fas fa-circle"></i> Decayed</span>
                    <span class="badge badge-info"><i class="fas fa-circle"></i> Filled</span>
                    <span class="badge badge-warning"><i class="fas fa-circle"></i> Missing</span>
                </div>
            </div>
        </div>

        <div class="card-body text-center" style="padding: 24px 12px;">

            <!-- ========================================== -->
            <!-- 1. PEDIATRIC / PRIMARY TEETH VIEW (20 TEETH) -->
            <!-- ========================================== -->
            <div id="view-pediatric" style="<?= ($selectedDentition === 'pediatric') ? 'display:block;' : 'display:none;' ?>">
                <div class="arch-section-header">
                    <span class="arch-tag">MAXILLARY ARCH (PRIMARY UPPER TEETH)</span>
                </div>
                
                <div class="teeth-row mb-24">
                    <?php foreach ($pedTeethTop as $idx => $t): 
                        $status = $toothStatus[$t['fdi']]['status'] ?? 'healthy';
                        $colorClass = 'tooth-' . $status;
                    ?>
                    <?php if ($idx === 5): ?>
                    <div class="arch-midline" title="Midline (Right / Left)"></div>
                    <?php endif; ?>
                    <div class="tooth-container pediatric-tooth" onclick="openToothModal(<?= $t['fdi'] ?>, 'child')">
                        <div class="tooth-number">
                            <span class="fdi-code"><?= $t['fdi'] ?></span>
                            <span class="letter-tag"><?= $t['letter'] ?></span>
                        </div>
                        <div class="tooth-icon <?= $colorClass ?>"><i class="fas fa-tooth fa-2x"></i></div>
                        <div class="tooth-status"><?= ucfirst($status) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="arch-horizontal-divider mb-24">
                    <span class="arch-divider-badge">UPPER / LOWER OCCLUSAL PLANE</span>
                </div>
                
                <div class="teeth-row mb-12">
                    <?php foreach ($pedTeethBottom as $idx => $t): 
                        $status = $toothStatus[$t['fdi']]['status'] ?? 'healthy';
                        $colorClass = 'tooth-' . $status;
                    ?>
                    <?php if ($idx === 5): ?>
                    <div class="arch-midline" title="Midline (Right / Left)"></div>
                    <?php endif; ?>
                    <div class="tooth-container pediatric-tooth" onclick="openToothModal(<?= $t['fdi'] ?>, 'child')">
                        <div class="tooth-status"><?= ucfirst($status) ?></div>
                        <div class="tooth-icon <?= $colorClass ?>"><i class="fas fa-tooth fa-2x fa-rotate-180"></i></div>
                        <div class="tooth-number">
                            <span class="fdi-code"><?= $t['fdi'] ?></span>
                            <span class="letter-tag"><?= $t['letter'] ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="arch-section-header mt-8">
                    <span class="arch-tag">MANDIBULAR ARCH (PRIMARY LOWER TEETH)</span>
                </div>
            </div>

            <!-- ========================================== -->
            <!-- 2. ADULT / PERMANENT TEETH VIEW (32 TEETH)  -->
            <!-- ========================================== -->
            <div id="view-adult" style="<?= ($selectedDentition === 'adult') ? 'display:block;' : 'display:none;' ?>">
                <div class="arch-section-header">
                    <span class="arch-tag">MAXILLARY ARCH (PERMANENT UPPER TEETH)</span>
                </div>

                <!-- Top Arch -->
                <div class="teeth-row mb-24">
                    <?php foreach ($adultTeethTop as $idx => $t): 
                        $status = $toothStatus[$t]['status'] ?? 'healthy';
                        $colorClass = 'tooth-' . $status;
                    ?>
                    <?php if ($idx === 8): ?>
                    <div class="arch-midline" title="Midline (Right / Left)"></div>
                    <?php endif; ?>
                    <div class="tooth-container" onclick="openToothModal(<?= $t ?>, 'adult')">
                        <div class="tooth-number"><?= $t ?></div>
                        <div class="tooth-icon <?= $colorClass ?>"><i class="fas fa-tooth fa-2x"></i></div>
                        <div class="tooth-status"><?= ucfirst($status) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="arch-horizontal-divider mb-24">
                    <span class="arch-divider-badge">UPPER / LOWER OCCLUSAL PLANE</span>
                </div>
                
                <!-- Bottom Arch -->
                <div class="teeth-row mb-12">
                    <?php foreach ($adultTeethBottom as $idx => $t): 
                        $status = $toothStatus[$t]['status'] ?? 'healthy';
                        $colorClass = 'tooth-' . $status;
                    ?>
                    <?php if ($idx === 8): ?>
                    <div class="arch-midline" title="Midline (Right / Left)"></div>
                    <?php endif; ?>
                    <div class="tooth-container" onclick="openToothModal(<?= $t ?>, 'adult')">
                        <div class="tooth-status"><?= ucfirst($status) ?></div>
                        <div class="tooth-icon <?= $colorClass ?>"><i class="fas fa-tooth fa-2x fa-rotate-180"></i></div>
                        <div class="tooth-number"><?= $t ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="arch-section-header mt-8">
                    <span class="arch-tag">MANDIBULAR ARCH (PERMANENT LOWER TEETH)</span>
                </div>
            </div>

            <!-- ========================================== -->
            <!-- 3. MIXED DENTITION VIEW (PRIMARY + PERMANENT) -->
            <!-- ========================================== -->
            <div id="view-mixed" style="<?= ($selectedDentition === 'mixed') ? 'display:block;' : 'display:none;' ?>">
                <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 10px 16px; margin-bottom: 24px; text-align: left; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-info-circle" style="color: #2563eb; font-size: 15px;"></i>
                    <span style="font-size: 12.5px; color: #1e40af; line-height: 1.4;">
                        <strong>Mixed Dentition Mode:</strong> Displays both primary (milk) teeth and permanent teeth. Ideal for children aged 6–12 who are shedding baby teeth while permanent teeth erupt.
                    </span>
                </div>

                <!-- Primary Teeth Section -->
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin-bottom: 28px;">
                    <div style="font-size: 13px; font-weight: 800; color: #0891b2; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 16px; display: flex; align-items: center; justify-content: center; gap: 6px;">
                        <i class="fas fa-child"></i> Primary / Deciduous Arch (20 Milk Teeth)
                    </div>

                    <div class="teeth-row mb-16">
                        <?php foreach ($pedTeethTop as $idx => $t): 
                            $status = $toothStatus[$t['fdi']]['status'] ?? 'healthy';
                            $colorClass = 'tooth-' . $status;
                        ?>
                        <?php if ($idx === 5): ?><div class="arch-midline"></div><?php endif; ?>
                        <div class="tooth-container pediatric-tooth" onclick="openToothModal(<?= $t['fdi'] ?>, 'child')">
                            <div class="tooth-number"><span class="fdi-code"><?= $t['fdi'] ?></span> <span class="letter-tag"><?= $t['letter'] ?></span></div>
                            <div class="tooth-icon <?= $colorClass ?>"><i class="fas fa-tooth fa-2x"></i></div>
                            <div class="tooth-status"><?= ucfirst($status) ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="arch-horizontal-divider mb-16">
                        <span class="arch-divider-badge" style="font-size: 9.5px;">PRIMARY OCCLUSAL PLANE</span>
                    </div>

                    <div class="teeth-row">
                        <?php foreach ($pedTeethBottom as $idx => $t): 
                            $status = $toothStatus[$t['fdi']]['status'] ?? 'healthy';
                            $colorClass = 'tooth-' . $status;
                        ?>
                        <?php if ($idx === 5): ?><div class="arch-midline"></div><?php endif; ?>
                        <div class="tooth-container pediatric-tooth" onclick="openToothModal(<?= $t['fdi'] ?>, 'child')">
                            <div class="tooth-status"><?= ucfirst($status) ?></div>
                            <div class="tooth-icon <?= $colorClass ?>"><i class="fas fa-tooth fa-2x fa-rotate-180"></i></div>
                            <div class="tooth-number"><span class="fdi-code"><?= $t['fdi'] ?></span> <span class="letter-tag"><?= $t['letter'] ?></span></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Permanent Teeth Section -->
                <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px;">
                    <div style="font-size: 13px; font-weight: 800; color: #1e293b; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 16px; display: flex; align-items: center; justify-content: center; gap: 6px;">
                        <i class="fas fa-user"></i> Permanent Arch (32 Adult Teeth)
                    </div>

                    <div class="teeth-row mb-16">
                        <?php foreach ($adultTeethTop as $idx => $t): 
                            $status = $toothStatus[$t]['status'] ?? 'healthy';
                            $colorClass = 'tooth-' . $status;
                        ?>
                        <?php if ($idx === 8): ?><div class="arch-midline"></div><?php endif; ?>
                        <div class="tooth-container" onclick="openToothModal(<?= $t ?>, 'adult')">
                            <div class="tooth-number"><?= $t ?></div>
                            <div class="tooth-icon <?= $colorClass ?>"><i class="fas fa-tooth fa-2x"></i></div>
                            <div class="tooth-status"><?= ucfirst($status) ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="arch-horizontal-divider mb-16">
                        <span class="arch-divider-badge" style="font-size: 9.5px;">PERMANENT OCCLUSAL PLANE</span>
                    </div>

                    <div class="teeth-row">
                        <?php foreach ($adultTeethBottom as $idx => $t): 
                            $status = $toothStatus[$t]['status'] ?? 'healthy';
                            $colorClass = 'tooth-' . $status;
                        ?>
                        <?php if ($idx === 8): ?><div class="arch-midline"></div><?php endif; ?>
                        <div class="tooth-container" onclick="openToothModal(<?= $t ?>, 'adult')">
                            <div class="tooth-status"><?= ucfirst($status) ?></div>
                            <div class="tooth-icon <?= $colorClass ?>"><i class="fas fa-tooth fa-2x fa-rotate-180"></i></div>
                            <div class="tooth-number"><?= $t ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

        </div>
    </div>
    
    <!-- Treatments List -->
    <div class="card col-span-2">
        <div class="card-header d-flex justify-between align-center">
            <h3 style="margin: 0;"><i class="fas fa-history" style="color: var(--primary);"></i> Treatment History</h3>
            <button class="btn btn-sm btn-outline" onclick="showTreatmentModal()">
                <i class="fas fa-plus"></i> Add General Treatment
            </button>
        </div>
        <div class="card-body p-0">
            <table class="table">
                <thead><tr><th>Date</th><th>Tooth</th><th>Procedure</th><th>Status</th><th>Cost</th><th class="no-print">Actions</th></tr></thead>
                <tbody>
                    <?php if (empty($treatments)): ?>
                    <tr><td colspan="6" class="text-center text-muted" style="padding: 24px;">No dental treatments recorded yet.</td></tr>
                    <?php else: ?>
                    <?php foreach ($treatments as $t): ?>
                    <tr>
                        <td><?= formatDate($t['treatment_date']) ?></td>
                        <td><?= formatToothDisplay($t['tooth_number']) ?></td>
                        <td class="font-semibold"><?= sanitizeOutput($t['procedure_name']) ?></td>
                        <td><span class="badge badge-<?= $t['status'] === 'completed' ? 'success' : ($t['status'] === 'planned' ? 'warning' : 'secondary') ?>"><?= ucfirst($t['status']) ?></span></td>
                        <td><?= formatCurrency($t['cost']) ?></td>
                        <td class="no-print">
                            <button class="btn btn-sm btn-ghost text-primary" onclick='editTreatment(<?= json_encode($t) ?>)' title="Edit">
                                <i class="fas fa-pen"></i>
                            </button>
                            <button class="btn btn-sm btn-ghost text-danger" onclick="deleteTreatment(<?= $t['id'] ?>)" title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Tooth Modal -->
<div id="toothModal" class="modal-overlay">
    <div class="modal" style="max-width: 480px;">
        <div class="card-header d-flex justify-between align-center">
            <div>
                <h3 style="margin: 0;">Manage Tooth #<span id="modalToothNumber"></span></h3>
                <div id="modalToothName" style="font-size: 12px; color: var(--primary); font-weight: 600; margin-top: 2px;"></div>
            </div>
            <button class="btn btn-sm btn-ghost" onclick="closeToothModal()">&times;</button>
        </div>
        <div class="card-body">
            <ul class="nav nav-tabs mb-16 d-flex gap-12" style="border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">
                <li><a href="#" onclick="switchTab('status')" class="active font-bold"><i class="fas fa-tooth"></i> Condition Status</a></li>
                <li><a href="#" onclick="switchTab('treatment')"><i class="fas fa-plus-circle"></i> Add Treatment</a></li>
            </ul>
            
            <!-- Status Tab -->
            <div id="tab-status">
                <form id="statusForm" onsubmit="saveStatus(event)">
                    <input type="hidden" name="tooth_number" id="statusTooth">
                    <input type="hidden" name="tooth_type" id="statusToothType" value="adult">
                    <input type="hidden" name="patient_id" value="<?= $patientId ?>">
                    <div class="form-group mb-16">
                        <label class="form-label" style="font-weight: 600;">Tooth Condition</label>
                        <select name="status" class="form-control" id="statusSelect">
                            <option value="healthy">Healthy</option>
                            <option value="decayed">Decayed / Caries</option>
                            <option value="filled">Filled / Restored</option>
                            <option value="missing">Missing / Extracted</option>
                            <option value="root_canal">Root Canal (RCT)</option>
                            <option value="crown">Crown / Bridge</option>
                            <option value="implant">Implant</option>
                            <option value="extraction_needed">Extraction Needed</option>
                        </select>
                    </div>
                    <div class="form-group mb-16">
                        <label class="form-label" style="font-weight: 600;">Clinical Notes / Findings</label>
                        <textarea name="notes" class="form-control" id="statusNotes" rows="2" placeholder="e.g. Occlusal pit caries, sensitive to cold..."></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">
                        <i class="fas fa-save"></i> Update Tooth Status
                    </button>
                </form>
            </div>
            
            <!-- Treatment Tab -->
            <div id="tab-treatment" style="display:none;">
                <form id="treatmentForm" onsubmit="saveTreatment(event)">
                    <input type="hidden" name="id" id="treatmentId" value="">
                    <input type="hidden" name="tooth_number" id="treatmentTooth">
                    <input type="hidden" name="patient_id" value="<?= $patientId ?>">
                    <div class="form-group mb-12">
                        <label class="form-label" style="font-weight: 600;">Procedure Name *</label>
                        <input type="text" name="procedure_name" id="t_procedure" class="form-control" required placeholder="e.g. Composite Restoration / Extraction">
                    </div>
                    <div class="form-row mb-16">
                        <div class="form-group mb-0">
                            <label class="form-label" style="font-weight: 600;">Status</label>
                            <select name="status" id="t_status" class="form-control">
                                <option value="completed">Completed</option>
                                <option value="planned">Planned</option>
                                <option value="in_progress">In Progress</option>
                            </select>
                        </div>
                        <div class="form-group mb-0">
                            <label class="form-label" style="font-weight: 600;">Cost (<?= getCurrencySymbol() ?>)</label>
                            <input type="number" step="0.01" name="cost" id="t_cost" class="form-control" value="0">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block" id="btnSaveTreatment">
                        <i class="fas fa-plus"></i> Save Treatment
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
/* Dentition Toggle Buttons */
.dentition-toggle-group {
    background: #f1f5f9;
    padding: 3px;
    border-radius: 8px;
    display: inline-flex;
    border: 1px solid #cbd5e1;
}
.dentition-btn {
    border: none;
    background: transparent;
    color: #64748b;
    padding: 5px 12px;
    font-size: 12px;
    font-weight: 600;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.15s ease;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.dentition-btn:hover {
    color: #1e293b;
}
.dentition-btn.active {
    background: #0891b2 !important;
    color: #ffffff !important;
    box-shadow: 0 1px 4px rgba(8, 145, 178, 0.3);
}

/* Arch Section Styling */
.arch-section-header {
    text-align: center;
    margin-bottom: 12px;
}
.arch-tag {
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.8px;
    color: #94a3b8;
    text-transform: uppercase;
    background: #f8fafc;
    padding: 3px 12px;
    border-radius: 20px;
    border: 1px solid #e2e8f0;
}
.arch-horizontal-divider {
    position: relative;
    border-top: 1px dashed #cbd5e1;
    margin: 20px 0;
    text-align: center;
}
.arch-divider-badge {
    position: absolute;
    top: -10px;
    left: 50%;
    transform: translateX(-50%);
    background: #ffffff;
    padding: 0 12px;
    font-size: 10px;
    font-weight: 700;
    color: #94a3b8;
    letter-spacing: 0.5px;
}

/* Teeth Grid & Midline */
.teeth-row {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 2px;
    flex-wrap: nowrap;
    padding-bottom: 4px;
    width: 100%;
}
.arch-midline {
    width: 2px;
    height: 70px;
    background: repeating-linear-gradient(to bottom, #94a3b8 0, #94a3b8 4px, transparent 4px, transparent 8px);
    margin: 0 3px;
    flex-shrink: 0;
}
.tooth-container {
    cursor: pointer;
    text-align: center;
    padding: 4px 2px;
    flex: 1 1 0;
    min-width: 0;
    max-width: 52px;
    border-radius: 8px;
    border: 1px solid transparent;
    transition: all 0.15s ease;
    user-select: none;
    overflow: hidden;
}
.tooth-container:hover {
    background: #f0fdfa;
    border-color: #99f6e4;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(8, 145, 178, 0.12);
}
.tooth-number {
    font-size: 11px;
    font-weight: 700;
    color: #475569;
    margin-bottom: 2px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 2px;
}
.fdi-code {
    font-weight: 800;
    color: #0891b2;
}
.letter-tag {
    font-size: 9px;
    background: #e0f2fe;
    color: #0369a1;
    padding: 1px 3px;
    border-radius: 3px;
    font-weight: 800;
}
.tooth-icon {
    color: #cbd5e1;
    transition: color 0.2s ease;
    margin: 3px 0;
}
.tooth-icon .fa-2x {
    font-size: 1.5em;
}
.tooth-status {
    font-size: 9px;
    font-weight: 600;
    height: 14px;
    line-height: 14px;
    color: #64748b;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* Status Colors */
.tooth-healthy { color: #10b981; }
.tooth-decayed { color: #ef4444; }
.tooth-filled { color: #0284c7; }
.tooth-missing { color: #94a3b8; opacity: 0.45; }
.tooth-root_canal { color: #8b5cf6; }
.tooth-crown { color: #f59e0b; }
.tooth-implant { color: #059669; }
.tooth-extraction_needed { color: #b91c1c; }

/* Modal override */
#toothModal .modal { padding: 0; border-radius: 12px; overflow: hidden; }
#toothModal .modal .card-header,
#toothModal .modal .card-body { padding: 18px 20px; }

@media print {
    .content-header .btn, .no-print, .dentition-toggle-group, .condition-legend {
        display: none !important;
    }
}
</style>

<script>
let currentTooth = 0;
let currentToothType = 'adult';
let currentDentition = <?= json_encode($selectedDentition) ?>;
const toothStatusMap = <?= json_encode($toothStatus) ?>;

// Comprehensive anatomical dictionary for both Primary (51..85) and Permanent (1..32)
const toothNames = {
    // Primary / Pediatric Teeth (FDI notation with Universal letters)
    55: 'Primary Upper Right 2nd Molar (A)',
    54: 'Primary Upper Right 1st Molar (B)',
    53: 'Primary Upper Right Canine (C)',
    52: 'Primary Upper Right Lateral Incisor (D)',
    51: 'Primary Upper Right Central Incisor (E)',
    61: 'Primary Upper Left Central Incisor (F)',
    62: 'Primary Upper Left Lateral Incisor (G)',
    63: 'Primary Upper Left Canine (H)',
    64: 'Primary Upper Left 1st Molar (I)',
    65: 'Primary Upper Left 2nd Molar (J)',
    71: 'Primary Lower Left Central Incisor (O)',
    72: 'Primary Lower Left Lateral Incisor (N)',
    73: 'Primary Lower Left Canine (M)',
    74: 'Primary Lower Left 1st Molar (L)',
    75: 'Primary Lower Left 2nd Molar (K)',
    81: 'Primary Lower Right Central Incisor (P)',
    82: 'Primary Lower Right Lateral Incisor (Q)',
    83: 'Primary Lower Right Canine (R)',
    84: 'Primary Lower Right 1st Molar (S)',
    85: 'Primary Lower Right 2nd Molar (T)',

    // Adult Permanent Teeth (Universal 1..32)
    1: 'Upper Right 3rd Molar (Wisdom)',
    2: 'Upper Right 2nd Molar',
    3: 'Upper Right 1st Molar',
    4: 'Upper Right 2nd Premolar',
    5: 'Upper Right 1st Premolar',
    6: 'Upper Right Canine',
    7: 'Upper Right Lateral Incisor',
    8: 'Upper Right Central Incisor',
    9: 'Upper Left Central Incisor',
    10: 'Upper Left Lateral Incisor',
    11: 'Upper Left Canine',
    12: 'Upper Left 1st Premolar',
    13: 'Upper Left 2nd Premolar',
    14: 'Upper Left 1st Molar',
    15: 'Upper Left 2nd Molar',
    16: 'Upper Left 3rd Molar (Wisdom)',
    17: 'Lower Left 3rd Molar (Wisdom)',
    18: 'Lower Left 2nd Molar',
    19: 'Lower Left 1st Molar',
    20: 'Lower Left 2nd Premolar',
    21: 'Lower Left 1st Premolar',
    22: 'Lower Left Canine',
    23: 'Lower Left Lateral Incisor',
    24: 'Lower Left Central Incisor',
    25: 'Lower Right Central Incisor',
    26: 'Lower Right Lateral Incisor',
    27: 'Lower Right Canine',
    28: 'Lower Right 1st Premolar',
    29: 'Lower Right 2nd Premolar',
    30: 'Lower Right 1st Molar',
    31: 'Lower Right 2nd Molar',
    32: 'Lower Right 3rd Molar (Wisdom)'
};

function switchDentition(mode) {
    currentDentition = mode;
    document.getElementById('view-pediatric').style.display = (mode === 'pediatric') ? 'block' : 'none';
    document.getElementById('view-adult').style.display = (mode === 'adult') ? 'block' : 'none';
    document.getElementById('view-mixed').style.display = (mode === 'mixed') ? 'block' : 'none';
    
    document.querySelectorAll('.dentition-btn').forEach(btn => btn.classList.remove('active'));
    if (mode === 'pediatric') document.getElementById('btnDentitionPediatric').classList.add('active');
    if (mode === 'adult') document.getElementById('btnDentitionAdult').classList.add('active');
    if (mode === 'mixed') document.getElementById('btnDentitionMixed').classList.add('active');
    
    const badgeText = (mode === 'pediatric') ? '(Pediatric - 20 Teeth)' : ((mode === 'mixed') ? '(Mixed Dentition)' : '(Adult - 32 Teeth)');
    document.getElementById('dentitionBadge').textContent = badgeText;
    
    // Update URL query state smoothly without reloading
    const url = new URL(window.location);
    url.searchParams.set('dentition', mode);
    window.history.replaceState({}, '', url);
}

function openToothModal(tooth, type) {
    currentTooth = tooth;
    currentToothType = type || ((tooth >= 51 && tooth <= 85) ? 'child' : 'adult');
    
    const toothName = toothNames[tooth] || ('Tooth #' + tooth);
    document.getElementById('modalToothNumber').textContent = tooth;
    document.getElementById('modalToothName').textContent = toothName;
    document.getElementById('statusTooth').value = tooth;
    document.getElementById('statusToothType').value = currentToothType;
    document.getElementById('treatmentTooth').value = tooth;
    
    // Set existing condition if available
    const existing = toothStatusMap[tooth];
    document.getElementById('statusSelect').value = existing ? existing.status : 'healthy';
    document.getElementById('statusNotes').value = existing ? (existing.notes || '') : '';
    
    // Treatment form defaults
    document.getElementById('treatmentId').value = '';
    document.getElementById('t_procedure').value = '';
    document.getElementById('t_cost').value = '0';
    document.getElementById('t_status').value = 'completed';
    document.getElementById('btnSaveTreatment').innerHTML = '<i class="fas fa-plus"></i> Save Treatment';

    document.getElementById('toothModal').classList.add('active');
    switchTab('status');
}

function showTreatmentModal() {
    currentTooth = 0;
    currentToothType = 'adult';
    document.getElementById('modalToothNumber').textContent = 'General';
    document.getElementById('modalToothName').textContent = 'General / Full Mouth Treatment';
    
    document.getElementById('tab-status').style.display = 'none';
    document.getElementById('tab-treatment').style.display = 'block';
    
    document.querySelectorAll('.nav-tabs a').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.nav-tabs a')[1].classList.add('active');
    
    document.getElementById('treatmentForm').reset();
    document.getElementById('treatmentId').value = '';
    document.getElementById('treatmentTooth').value = '';
    document.getElementById('btnSaveTreatment').innerHTML = '<i class="fas fa-plus"></i> Add General Treatment';
    
    document.getElementById('toothModal').classList.add('active');
}

function editTreatment(t) {
    currentTooth = t.tooth_number || 0;
    document.getElementById('modalToothNumber').textContent = currentTooth ? currentTooth : 'General';
    document.getElementById('modalToothName').textContent = currentTooth ? (toothNames[currentTooth] || 'Tooth #' + currentTooth) : 'General Treatment';
    
    document.getElementById('tab-status').style.display = 'none';
    document.getElementById('tab-treatment').style.display = 'block';
    
    document.querySelectorAll('.nav-tabs a').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.nav-tabs a')[1].classList.add('active');
    
    document.getElementById('treatmentId').value = t.id;
    document.getElementById('treatmentTooth').value = t.tooth_number || '';
    document.getElementById('t_procedure').value = t.procedure_name;
    document.getElementById('t_status').value = t.status;
    document.getElementById('t_cost').value = t.cost;
    document.getElementById('btnSaveTreatment').innerHTML = '<i class="fas fa-save"></i> Update Treatment';
    
    document.getElementById('toothModal').classList.add('active');
}

function closeToothModal() {
    document.getElementById('toothModal').classList.remove('active');
}

function switchTab(tab) {
    document.getElementById('tab-status').style.display = tab === 'status' ? 'block' : 'none';
    document.getElementById('tab-treatment').style.display = tab === 'treatment' ? 'block' : 'none';
    
    document.querySelectorAll('.nav-tabs a').forEach(el => el.classList.remove('active'));
    if (tab === 'status') {
        document.querySelectorAll('.nav-tabs a')[0].classList.add('active');
    } else {
        document.querySelectorAll('.nav-tabs a')[1].classList.add('active');
    }
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
            alert('Error: ' + (res.error || 'Failed to save tooth status'));
        }
    })
    .catch(() => alert('Network error saving tooth status'));
}

function saveTreatment(e) {
    e.preventDefault();
    const formData = new FormData(e.target);
    fetch('save_treatment.php', {
        method: 'POST',
        body: formData
    })
    .then(r => {
        if (!r.ok) throw new Error('Server returned ' + r.status);
        return r.text();
    })
    .then(text => {
        try {
            const res = JSON.parse(text);
            if(res.success) {
                location.reload();
            } else {
                alert('Error: ' + (res.error || 'Failed to save treatment'));
            }
        } catch(e) {
            console.error('Server response:', text);
            alert('Server error: ' + text.substring(0, 200));
        }
    })
    .catch(err => alert('Network error: ' + err.message));
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
    })
    .catch(() => alert('Network error deleting treatment'));
}

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeToothModal();
    }
});
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
