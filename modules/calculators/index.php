<?php
/**
 * Medical Calculators - Advanced Clinic Suite
 */
$pageTitle = 'Medical Calculators';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';
requireAuth();
?>

<div class="content-header">
    <div>
        <h1>Medical Calculators</h1>
        <p class="text-muted" style="font-size:13px; margin-top:4px;">Quick clinical calculation tools</p>
    </div>
</div>

<style>
    .calc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(380px, 1fr)); gap: 24px; }
    .calc-card { background: var(--bg-card); border-radius: var(--border-radius-lg); border: 1px solid var(--border-color); overflow: hidden; }
    .calc-card-header {
        padding: 18px 24px;
        border-bottom: 1px solid var(--border-color);
        display: flex; align-items: center; gap: 12px;
        background: var(--gray-50);
    }
    .calc-card-header .calc-icon {
        width: 42px; height: 42px; border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
        font-size: 18px; color: #fff;
    }
    .calc-card-header h3 { font-size: 15px; font-weight: 600; }
    .calc-card-header p { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
    .calc-body { padding: 24px; }
    .calc-body .form-group { margin-bottom: 16px; }
    .calc-body .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .calc-result {
        margin-top: 16px; padding: 16px; border-radius: 10px;
        background: var(--primary-50); border: 1px solid var(--primary-200);
        text-align: center; display: none;
    }
    .calc-result.show { display: block; animation: fadeIn 0.3s ease; }
    .calc-result .result-value { font-size: 2rem; font-weight: 700; color: var(--primary); }
    .calc-result .result-label { font-size: 13px; color: var(--text-secondary); margin-top: 4px; }
    .calc-result .result-category {
        display: inline-block; margin-top: 8px;
        padding: 4px 14px; border-radius: 50px;
        font-size: 12px; font-weight: 600;
    }
    .bmi-underweight { background: #DBEAFE; color: #2563EB; }
    .bmi-normal { background: var(--success-bg); color: var(--success); }
    .bmi-overweight { background: var(--warning-bg); color: var(--warning); }
    .bmi-obese { background: var(--danger-bg); color: var(--danger); }

    .dose-safe { background: var(--success-bg); color: var(--success); }
    .dose-high { background: var(--danger-bg); color: var(--danger); }
    .dose-table { width: 100%; margin-top: 12px; font-size: 13px; }
    .dose-table th { font-size: 11px; text-transform: uppercase; color: var(--text-muted); padding: 6px 8px; text-align: left; }
    .dose-table td { padding: 6px 8px; border-bottom: 1px solid var(--border-color); }

    .gest-weeks { font-size: 2rem; font-weight: 700; color: var(--primary); }
    .gest-edd { font-size: 1.1rem; font-weight: 600; color: var(--accent); margin-top: 6px; }
    .gest-trimester { display: inline-block; margin-top: 8px; padding: 4px 14px; border-radius: 50px; font-size: 12px; font-weight: 600; }
    .tri-1 { background: #DBEAFE; color: #2563EB; }
    .tri-2 { background: var(--warning-bg); color: var(--warning); }
    .tri-3 { background: #FCE7F3; color: #DB2777; }
</style>

<div class="calc-grid">

    <!-- BMI Calculator -->
    <div class="calc-card">
        <div class="calc-card-header">
            <div class="calc-icon" style="background: linear-gradient(135deg, #0097A7, #00838F);">
                <i class="fas fa-weight"></i>
            </div>
            <div>
                <h3>BMI Calculator</h3>
                <p>Body Mass Index</p>
            </div>
        </div>
        <div class="calc-body">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Weight (kg)</label>
                    <input type="number" id="bmiWeight" class="form-control" placeholder="e.g. 70" step="0.1" min="1">
                </div>
                <div class="form-group">
                    <label class="form-label">Height (cm)</label>
                    <input type="number" id="bmiHeight" class="form-control" placeholder="e.g. 170" step="0.1" min="1">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Age (years)</label>
                    <input type="number" id="bmiAge" class="form-control" placeholder="e.g. 30" min="1">
                </div>
                <div class="form-group">
                    <label class="form-label">Gender</label>
                    <select id="bmiGender" class="form-control">
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                    </select>
                </div>
            </div>
            <button class="btn btn-primary btn-block" onclick="calcBMI()">
                <i class="fas fa-calculator"></i> Calculate BMI
            </button>
            <div class="calc-result" id="bmiResult">
                <div class="result-value" id="bmiValue"></div>
                <div class="result-label">kg/m²</div>
                <div class="result-category" id="bmiCategory"></div>
                <table class="dose-table" style="margin-top: 16px;">
                    <tr><th>Category</th><th>BMI Range</th></tr>
                    <tr><td>Underweight</td><td>&lt; 18.5</td></tr>
                    <tr><td>Normal weight</td><td>18.5 – 24.9</td></tr>
                    <tr><td>Overweight</td><td>25.0 – 29.9</td></tr>
                    <tr><td>Obese</td><td>≥ 30.0</td></tr>
                </table>
            </div>
        </div>
    </div>

    <!-- Pediatric Dosage Calculator -->
    <div class="calc-card">
        <div class="calc-card-header">
            <div class="calc-icon" style="background: linear-gradient(135deg, #06B6D4, #0891B2);">
                <i class="fas fa-pills"></i>
            </div>
            <div>
                <h3>Dosage Calculator</h3>
                <p>Pediatric dose by weight</p>
            </div>
        </div>
        <div class="calc-body">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Patient Weight (kg)</label>
                    <input type="number" id="doseWeight" class="form-control" placeholder="e.g. 12" step="0.1" min="0.1">
                </div>
                <div class="form-group">
                    <label class="form-label">Dose (mg/kg)</label>
                    <input type="number" id="doseMgKg" class="form-control" placeholder="e.g. 10" step="0.1" min="0.1">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Frequency / day</label>
                    <select id="doseFreq" class="form-control">
                        <option value="1">Once (OD)</option>
                        <option value="2">Twice (BD)</option>
                        <option value="3" selected>Thrice (TID)</option>
                        <option value="4">Four times (QID)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Max Daily (mg) <small style="color:var(--text-muted)">(optional)</small></label>
                    <input type="number" id="doseMax" class="form-control" placeholder="e.g. 500" step="1">
                </div>
            </div>
            <button class="btn btn-primary btn-block" onclick="calcDose()">
                <i class="fas fa-calculator"></i> Calculate Dose
            </button>
            <div class="calc-result" id="doseResult">
                <div class="result-value" id="doseValue"></div>
                <div class="result-label" id="doseLabel">per dose</div>
                <div class="result-category" id="doseSafety"></div>
                <table class="dose-table" id="doseTable">
                    <tr><th>Detail</th><th>Value</th></tr>
                </table>
            </div>
        </div>
    </div>

    <!-- Gestational Age / EDD Calculator -->
    <div class="calc-card">
        <div class="calc-card-header">
            <div class="calc-icon" style="background: linear-gradient(135deg, #EC4899, #DB2777);">
                <i class="fas fa-baby"></i>
            </div>
            <div>
                <h3>Gestational Age / EDD</h3>
                <p>Expected Delivery Date (Naegele's Rule)</p>
            </div>
        </div>
        <div class="calc-body">
            <div class="form-group">
                <label class="form-label">Last Menstrual Period (LMP)</label>
                <input type="date" id="gestLMP" class="form-control">
            </div>
            <button class="btn btn-primary btn-block" onclick="calcGest()">
                <i class="fas fa-calculator"></i> Calculate
            </button>
            <div class="calc-result" id="gestResult">
                <div class="gest-weeks" id="gestWeeks"></div>
                <div class="result-label" id="gestDays"></div>
                <div class="gest-edd" id="gestEDD"></div>
                <div class="gest-trimester" id="gestTrimester"></div>
            </div>
        </div>
    </div>

    <!-- Creatinine Clearance (Cockcroft-Gault) -->
    <div class="calc-card">
        <div class="calc-card-header">
            <div class="calc-icon" style="background: linear-gradient(135deg, #F59E0B, #D97706);">
                <i class="fas fa-kidneys"></i>
            </div>
            <div>
                <h3>Creatinine Clearance</h3>
                <p>Cockcroft-Gault Equation</p>
            </div>
        </div>
        <div class="calc-body">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Age (years)</label>
                    <input type="number" id="crAge" class="form-control" placeholder="e.g. 55" min="1">
                </div>
                <div class="form-group">
                    <label class="form-label">Weight (kg)</label>
                    <input type="number" id="crWeight" class="form-control" placeholder="e.g. 70" step="0.1" min="1">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Serum Creatinine (mg/dL)</label>
                    <input type="number" id="crCreatinine" class="form-control" placeholder="e.g. 1.2" step="0.01" min="0.1">
                </div>
                <div class="form-group">
                    <label class="form-label">Gender</label>
                    <select id="crGender" class="form-control">
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                    </select>
                </div>
            </div>
            <button class="btn btn-primary btn-block" onclick="calcCrCl()">
                <i class="fas fa-calculator"></i> Calculate CrCl
            </button>
            <div class="calc-result" id="crResult">
                <div class="result-value" id="crValue"></div>
                <div class="result-label">mL/min</div>
                <div class="result-category" id="crCategory"></div>
            </div>
        </div>
    </div>

    <!-- Unit Converter -->
    <div class="calc-card">
        <div class="calc-card-header">
            <div class="calc-icon" style="background: linear-gradient(135deg, #10B981, #059669);">
                <i class="fas fa-exchange-alt"></i>
            </div>
            <div>
                <h3>Unit Converter</h3>
                <p>Common medical unit conversions</p>
            </div>
        </div>
        <div class="calc-body">
            <div class="form-group">
                <label class="form-label">Conversion Type</label>
                <select id="convType" class="form-control" onchange="updateConvLabels()">
                    <option value="temp">Temperature (°F ↔ °C)</option>
                    <option value="weight">Weight (lbs ↔ kg)</option>
                    <option value="height">Height (in ↔ cm)</option>
                    <option value="glucose">Glucose (mg/dL ↔ mmol/L)</option>
                    <option value="cholesterol">Cholesterol (mg/dL ↔ mmol/L)</option>
                    <option value="creatinine">Creatinine (mg/dL ↔ µmol/L)</option>
                </select>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" id="convLabelA">°F</label>
                    <input type="number" id="convA" class="form-control" placeholder="Enter value" step="any" oninput="convertA()">
                </div>
                <div class="form-group">
                    <label class="form-label" id="convLabelB">°C</label>
                    <input type="number" id="convB" class="form-control" placeholder="Result" step="any" oninput="convertB()">
                </div>
            </div>
        </div>
    </div>

    <!-- Ideal Body Weight -->
    <div class="calc-card">
        <div class="calc-card-header">
            <div class="calc-icon" style="background: linear-gradient(135deg, #00ACC1, #0097A7);">
                <i class="fas fa-balance-scale"></i>
            </div>
            <div>
                <h3>Ideal Body Weight</h3>
                <p>Devine Formula</p>
            </div>
        </div>
        <div class="calc-body">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Height (cm)</label>
                    <input type="number" id="ibwHeight" class="form-control" placeholder="e.g. 170" step="0.1" min="100">
                </div>
                <div class="form-group">
                    <label class="form-label">Gender</label>
                    <select id="ibwGender" class="form-control">
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                    </select>
                </div>
            </div>
            <button class="btn btn-primary btn-block" onclick="calcIBW()">
                <i class="fas fa-calculator"></i> Calculate IBW
            </button>
            <div class="calc-result" id="ibwResult">
                <div class="result-value" id="ibwValue"></div>
                <div class="result-label">kg (Ideal Body Weight)</div>
            </div>
        </div>
    </div>

</div>

<script>
// ── BMI ──
function calcBMI() {
    const w = parseFloat(document.getElementById('bmiWeight').value);
    const h = parseFloat(document.getElementById('bmiHeight').value) / 100;
    if (!w || !h) return alert('Please enter weight and height');
    const bmi = (w / (h * h)).toFixed(1);
    let cat = '', cls = '';
    if (bmi < 18.5) { cat = 'Underweight'; cls = 'bmi-underweight'; }
    else if (bmi < 25) { cat = 'Normal weight'; cls = 'bmi-normal'; }
    else if (bmi < 30) { cat = 'Overweight'; cls = 'bmi-overweight'; }
    else { cat = 'Obese'; cls = 'bmi-obese'; }
    document.getElementById('bmiValue').textContent = bmi;
    const catEl = document.getElementById('bmiCategory');
    catEl.textContent = cat;
    catEl.className = 'result-category ' + cls;
    document.getElementById('bmiResult').classList.add('show');
}

// ── Dosage ──
function calcDose() {
    const w = parseFloat(document.getElementById('doseWeight').value);
    const mgkg = parseFloat(document.getElementById('doseMgKg').value);
    const freq = parseInt(document.getElementById('doseFreq').value);
    const max = parseFloat(document.getElementById('doseMax').value) || null;
    if (!w || !mgkg) return alert('Please enter weight and dose');
    const totalDaily = w * mgkg;
    const perDose = (totalDaily / freq).toFixed(1);
    const isOver = max && totalDaily > max;
    document.getElementById('doseValue').textContent = perDose + ' mg';
    document.getElementById('doseLabel').textContent = 'per dose (' + freq + 'x/day)';
    const safety = document.getElementById('doseSafety');
    if (isOver) {
        safety.textContent = '⚠ Exceeds max daily dose (' + max + ' mg)';
        safety.className = 'result-category dose-high';
    } else {
        safety.textContent = '✓ Within safe range';
        safety.className = 'result-category dose-safe';
    }
    const tbody = document.getElementById('doseTable');
    tbody.innerHTML = '<tr><th>Detail</th><th>Value</th></tr>' +
        '<tr><td>Total daily dose</td><td>' + totalDaily.toFixed(1) + ' mg</td></tr>' +
        '<tr><td>Per dose</td><td>' + perDose + ' mg</td></tr>' +
        '<tr><td>Frequency</td><td>' + freq + ' times/day</td></tr>' +
        (max ? '<tr><td>Max daily allowed</td><td>' + max + ' mg</td></tr>' : '');
    document.getElementById('doseResult').classList.add('show');
}

// ── Gestational Age ──
function calcGest() {
    const lmpStr = document.getElementById('gestLMP').value;
    if (!lmpStr) return alert('Please select LMP date');
    const lmp = new Date(lmpStr);
    const today = new Date();
    if (lmp > today) return alert('LMP cannot be in the future');
    const diffDays = Math.floor((today - lmp) / (1000 * 60 * 60 * 24));
    const weeks = Math.floor(diffDays / 7);
    const days = diffDays % 7;
    // Naegele's Rule: +7 days, −3 months, +1 year
    const edd = new Date(lmp);
    edd.setDate(edd.getDate() + 280);
    const eddStr = edd.toLocaleDateString('en-IN', { day: 'numeric', month: 'long', year: 'numeric' });
    document.getElementById('gestWeeks').textContent = weeks + ' weeks ' + days + ' days';
    document.getElementById('gestDays').textContent = 'Gestational age (' + diffDays + ' total days)';
    document.getElementById('gestEDD').textContent = 'EDD: ' + eddStr;
    const tri = document.getElementById('gestTrimester');
    if (weeks < 13) { tri.textContent = '1st Trimester'; tri.className = 'gest-trimester tri-1'; }
    else if (weeks < 28) { tri.textContent = '2nd Trimester'; tri.className = 'gest-trimester tri-2'; }
    else { tri.textContent = '3rd Trimester'; tri.className = 'gest-trimester tri-3'; }
    document.getElementById('gestResult').classList.add('show');
}

// ── Creatinine Clearance ──
function calcCrCl() {
    const age = parseInt(document.getElementById('crAge').value);
    const w = parseFloat(document.getElementById('crWeight').value);
    const scr = parseFloat(document.getElementById('crCreatinine').value);
    const gender = document.getElementById('crGender').value;
    if (!age || !w || !scr) return alert('Please fill all fields');
    let crcl = ((140 - age) * w) / (72 * scr);
    if (gender === 'female') crcl *= 0.85;
    crcl = crcl.toFixed(1);
    let cat = '', cls = '';
    if (crcl >= 90) { cat = 'Normal'; cls = 'bmi-normal'; }
    else if (crcl >= 60) { cat = 'Mild impairment'; cls = 'bmi-overweight'; }
    else if (crcl >= 30) { cat = 'Moderate impairment'; cls = 'bmi-obese'; }
    else { cat = 'Severe impairment'; cls = 'bmi-obese'; }
    document.getElementById('crValue').textContent = crcl;
    const catEl = document.getElementById('crCategory');
    catEl.textContent = cat;
    catEl.className = 'result-category ' + cls;
    document.getElementById('crResult').classList.add('show');
}

// ── Unit Converter ──
const convMap = {
    temp:        { a: '°F', b: '°C', aToB: v => (v - 32) * 5/9, bToA: v => v * 9/5 + 32 },
    weight:      { a: 'lbs', b: 'kg', aToB: v => v * 0.4536, bToA: v => v / 0.4536 },
    height:      { a: 'inches', b: 'cm', aToB: v => v * 2.54, bToA: v => v / 2.54 },
    glucose:     { a: 'mg/dL', b: 'mmol/L', aToB: v => v / 18.0182, bToA: v => v * 18.0182 },
    cholesterol: { a: 'mg/dL', b: 'mmol/L', aToB: v => v / 38.67, bToA: v => v * 38.67 },
    creatinine:  { a: 'mg/dL', b: 'µmol/L', aToB: v => v * 88.4, bToA: v => v / 88.4 }
};

function updateConvLabels() {
    const t = document.getElementById('convType').value;
    document.getElementById('convLabelA').textContent = convMap[t].a;
    document.getElementById('convLabelB').textContent = convMap[t].b;
    document.getElementById('convA').value = '';
    document.getElementById('convB').value = '';
}

function convertA() {
    const t = document.getElementById('convType').value;
    const v = parseFloat(document.getElementById('convA').value);
    document.getElementById('convB').value = isNaN(v) ? '' : convMap[t].aToB(v).toFixed(2);
}

function convertB() {
    const t = document.getElementById('convType').value;
    const v = parseFloat(document.getElementById('convB').value);
    document.getElementById('convA').value = isNaN(v) ? '' : convMap[t].bToA(v).toFixed(2);
}

// ── Ideal Body Weight ──
function calcIBW() {
    const h = parseFloat(document.getElementById('ibwHeight').value);
    const g = document.getElementById('ibwGender').value;
    if (!h || h < 100) return alert('Please enter a valid height (100+ cm)');
    const inches = h / 2.54;
    const over60 = inches - 60;
    let ibw;
    if (g === 'male') ibw = 50 + 2.3 * (over60 > 0 ? over60 : 0);
    else ibw = 45.5 + 2.3 * (over60 > 0 ? over60 : 0);
    document.getElementById('ibwValue').textContent = ibw.toFixed(1);
    document.getElementById('ibwResult').classList.add('show');
}
</script>

<?php require_once dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
