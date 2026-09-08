<?php
/**
 * Complete Migration & Master Data Seeder
 * Advanced Clinic Suite
 * 
 * Run once: http://localhost:8888/Clinic/clinic-web/migrate.php
 * Safe to re-run — all statements use IF NOT EXISTS / INSERT IGNORE
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/constants.php';

$db = db();
$results = [];

function run($db, $label, $sql, &$results) {
    try {
        $db->query($sql);
        $results[] = ['ok', $label];
    } catch (Exception $e) {
        $results[] = ['err', "$label — " . $e->getMessage()];
    }
}

// ═══════════════════════════════════════════════════════════════
// 1. MISSING TABLES
// ═══════════════════════════════════════════════════════════════

run($db, 'Table: user_permissions', "
CREATE TABLE IF NOT EXISTS user_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    permission_id INT NOT NULL,
    type ENUM('grant','deny') NOT NULL DEFAULT 'grant',
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_perm (user_id, permission_id)
) ENGINE=InnoDB;
", $results);

run($db, 'Table: services (billing item types)', "
CREATE TABLE IF NOT EXISTS services (
    id INT AUTO_INCREMENT PRIMARY KEY,
    clinic_id INT NOT NULL DEFAULT 1,
    name VARCHAR(200) NOT NULL,
    category VARCHAR(50) DEFAULT 'service',
    description TEXT DEFAULT NULL,
    default_price DECIMAL(10,2) DEFAULT 0.00,
    tax_applicable TINYINT(1) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_service_cat (category),
    INDEX idx_service_name (name)
) ENGINE=InnoDB;
", $results);

run($db, 'Table: dental_procedures (master)', "
CREATE TABLE IF NOT EXISTS dental_procedures (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    code VARCHAR(30) DEFAULT NULL,
    category VARCHAR(100) DEFAULT NULL,
    default_cost DECIMAL(10,2) DEFAULT 0.00,
    description TEXT DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
", $results);

// ═══════════════════════════════════════════════════════════════
// 2. MISSING COLUMNS (safe ALTER — catches duplicates)
// ═══════════════════════════════════════════════════════════════

$columns = [
    ["invoices", "discount_percent",  "DECIMAL(5,2) DEFAULT 0.00 AFTER discount_amount"],
    ["invoices", "tax_percent",       "DECIMAL(5,2) DEFAULT 0.00 AFTER discount_percent"],
    ["invoices", "tax_amount",        "DECIMAL(10,2) DEFAULT 0.00 AFTER tax_percent"],
    ["invoices", "payment_mode",      "VARCHAR(30) DEFAULT 'cash' AFTER status"],
    ["invoices", "paid_amount",       "DECIMAL(12,2) DEFAULT 0.00 AFTER total_amount"],
    ["invoice_items", "total_price",  "DECIMAL(10,2) DEFAULT 0.00 AFTER unit_price"],
];

foreach ($columns as [$tbl, $col, $def]) {
    $exists = $db->fetch("SELECT COUNT(*) as c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?", [$tbl, $col]);
    if (($exists['c'] ?? 0) == 0) {
        run($db, "Column: $tbl.$col", "ALTER TABLE `$tbl` ADD COLUMN `$col` $def", $results);
    } else {
        $results[] = ['skip', "Column $tbl.$col already exists"];
    }
}

// ═══════════════════════════════════════════════════════════════
// 3. SEED: SERVICES (dynamic billing item types)
// ═══════════════════════════════════════════════════════════════

try {
    $serviceCount = $db->fetch("SELECT COUNT(*) as c FROM services")['c'] ?? 0;
} catch (Exception $e) {
    $serviceCount = -1;
    $results[] = ['err', "Cannot read services table: " . $e->getMessage()];
}
if ($serviceCount == 0) {
    run($db, 'Seed: Services', "
    INSERT INTO services (clinic_id, name, category, default_price) VALUES
    (1, 'General Consultation',     'consultation', 300.00),
    (1, 'Follow-up Consultation',   'consultation', 200.00),
    (1, 'Emergency Consultation',   'consultation', 500.00),
    (1, 'Tele-Consultation',        'consultation', 250.00),
    (1, 'ECG',                      'procedure',    300.00),
    (1, 'Wound Dressing',           'procedure',    200.00),
    (1, 'Nebulization',             'procedure',    150.00),
    (1, 'Injection (IM/IV)',        'procedure',    100.00),
    (1, 'Ear Syringing',            'procedure',    350.00),
    (1, 'Suturing',                 'procedure',    800.00),
    (1, 'Abscess I and D',          'procedure',    1500.00),
    (1, 'Catheterization',          'procedure',    600.00),
    (1, 'PFT (Pulmonary Function)', 'procedure',    500.00),
    (1, 'Scaling and Polishing',    'dental',       1500.00),
    (1, 'Tooth Extraction (Simple)','dental',       800.00),
    (1, 'Tooth Extraction (Surgical)','dental',     2500.00),
    (1, 'Root Canal Treatment',     'dental',       5000.00),
    (1, 'Dental Filling (Composite)','dental',      1200.00),
    (1, 'Dental Crown (PFM)',       'dental',       4000.00),
    (1, 'Dental Crown (Zirconia)',  'dental',       8000.00),
    (1, 'Teeth Whitening',          'dental',       5000.00),
    (1, 'Dental X-Ray (IOPA)',      'dental',       200.00),
    (1, 'OPG X-Ray',               'dental',       500.00),
    (1, 'Braces (Metal)',           'dental',       30000.00),
    (1, 'Implant (Single)',         'dental',       25000.00),
    (1, 'Fluoride Application',    'dental',        500.00),
    (1, 'Vaccination Administration','vaccination', 100.00),
    (1, 'Sample Collection Fee',    'lab_test',     50.00),
    (1, 'Medical Certificate',      'service',      200.00),
    (1, 'Fitness Certificate',      'service',      300.00),
    (1, 'Home Visit',               'service',      800.00),
    (1, 'Registration Fee',         'other',        100.00),
    (1, 'Room Charges (Per Day)',   'other',        1500.00),
    (1, 'Pharmacy Dispensing',      'other',        0.00)
    ", $results);
} elseif ($serviceCount > 0) {
    $results[] = ['skip', "Services already seeded ($serviceCount rows)"];
}

// ═══════════════════════════════════════════════════════════════
// 4. SEED: DENTAL PROCEDURES
// ═══════════════════════════════════════════════════════════════

$dpCount = $db->fetch("SELECT COUNT(*) as c FROM dental_procedures")['c'] ?? 0;
if ($dpCount == 0) {
    run($db, 'Seed: Dental Procedures', "
    INSERT INTO dental_procedures (name, code, category, default_cost) VALUES
    ('Scaling & Polishing',        'D1110', 'Preventive',    1500.00),
    ('Fluoride Treatment',         'D1206', 'Preventive',    500.00),
    ('Sealant (per tooth)',        'D1351', 'Preventive',    800.00),
    ('Composite Filling',          'D2391', 'Restorative',   1200.00),
    ('Amalgam Filling',            'D2140', 'Restorative',   800.00),
    ('GIC Filling',                'D2330', 'Restorative',   600.00),
    ('Root Canal (Anterior)',      'D3310', 'Endodontics',   3500.00),
    ('Root Canal (Premolar)',      'D3320', 'Endodontics',   4500.00),
    ('Root Canal (Molar)',         'D3330', 'Endodontics',   6000.00),
    ('Re-Root Canal Treatment',    'D3346', 'Endodontics',   7000.00),
    ('Simple Extraction',          'D7140', 'Surgery',       800.00),
    ('Surgical Extraction',        'D7210', 'Surgery',       2500.00),
    ('Wisdom Tooth Extraction',    'D7230', 'Surgery',       4000.00),
    ('Alveoloplasty',              'D7310', 'Surgery',       3000.00),
    ('PFM Crown',                  'D2750', 'Prosthodontics',4000.00),
    ('Zirconia Crown',             'D2740', 'Prosthodontics',8000.00),
    ('All-Ceramic Crown',          'D2610', 'Prosthodontics',10000.00),
    ('FPD Bridge (per unit)',      'D6750', 'Prosthodontics',4500.00),
    ('Complete Denture',           'D5110', 'Prosthodontics',15000.00),
    ('Partial Denture',            'D5211', 'Prosthodontics',8000.00),
    ('Metal Braces',               'D8080', 'Orthodontics',  30000.00),
    ('Ceramic Braces',             'D8090', 'Orthodontics',  45000.00),
    ('Aligners (per arch)',        'D8040', 'Orthodontics',  60000.00),
    ('Deep Cleaning (per quad)',   'D4341', 'Periodontics',  1500.00),
    ('Flap Surgery (per quad)',    'D4240', 'Periodontics',  5000.00),
    ('Bone Grafting',              'D4263', 'Periodontics',  8000.00),
    ('Single Implant',             'D6010', 'Implantology',  25000.00),
    ('Implant Abutment',           'D6056', 'Implantology',  5000.00),
    ('Implant Crown',              'D6065', 'Implantology',  10000.00),
    ('IOPA X-Ray',                 'D0220', 'Diagnostic',    200.00),
    ('OPG X-Ray',                  'D0330', 'Diagnostic',    500.00),
    ('Teeth Whitening (In-Office)','D9972', 'Cosmetic',      5000.00),
    ('Veneer (per tooth)',         'D2961', 'Cosmetic',      8000.00)
    ", $results);
} else {
    $results[] = ['skip', "Dental procedures already seeded ($dpCount rows)"];
}

// ═══════════════════════════════════════════════════════════════
// 5. VERIFY CORE SEED DATA EXISTS
// ═══════════════════════════════════════════════════════════════

// Roles
$roleCount = $db->fetch("SELECT COUNT(*) as c FROM roles")['c'] ?? 0;
if ($roleCount == 0) {
    run($db, 'Seed: Roles', "
    INSERT INTO roles (name, display_name, description, is_system) VALUES
    ('super_admin', 'Super Admin', 'Full system access', 1),
    ('admin', 'Admin', 'Administrative access', 1),
    ('doctor', 'Doctor', 'Clinical access', 1),
    ('receptionist', 'Receptionist', 'Front desk management', 1),
    ('accountant', 'Accountant', 'Financial management', 1),
    ('nurse', 'Nurse', 'Clinical support', 1),
    ('lab_technician', 'Lab Technician', 'Lab management', 1)
    ", $results);
} else {
    $results[] = ['skip', "Roles already exist ($roleCount)"];
}

// Permissions
$permCount = $db->fetch("SELECT COUNT(*) as c FROM permissions")['c'] ?? 0;
if ($permCount == 0) {
    run($db, 'Seed: Permissions', "
    INSERT INTO permissions (module, name, display_name) VALUES
    ('dashboard','dashboard.view','View Dashboard'),('dashboard','dashboard.analytics','View Analytics'),
    ('clinic','clinic.settings','Manage Clinic Settings'),('clinic','clinic.branches','Manage Branches'),
    ('doctors','doctors.view','View Doctors'),('doctors','doctors.create','Add Doctors'),
    ('doctors','doctors.edit','Edit Doctors'),('doctors','doctors.delete','Delete Doctors'),
    ('doctors','doctors.schedule','Manage Doctor Schedules'),
    ('staff','staff.view','View Staff'),('staff','staff.create','Add Staff'),
    ('staff','staff.edit','Edit Staff'),('staff','staff.delete','Delete Staff'),('staff','staff.roles','Manage Roles'),
    ('patients','patients.view','View Patients'),('patients','patients.create','Add Patients'),
    ('patients','patients.edit','Edit Patients'),('patients','patients.delete','Delete Patients'),
    ('patients','patients.documents','Manage Patient Documents'),('patients','patients.history','View Patient History'),
    ('appointments','appointments.view','View Appointments'),('appointments','appointments.create','Book Appointments'),
    ('appointments','appointments.edit','Edit Appointments'),('appointments','appointments.cancel','Cancel Appointments'),
    ('appointments','appointments.queue','Manage Queue'),
    ('prescriptions','prescriptions.view','View Prescriptions'),('prescriptions','prescriptions.create','Create Prescriptions'),
    ('prescriptions','prescriptions.edit','Edit Prescriptions'),('prescriptions','prescriptions.templates','Manage Templates'),
    ('billing','billing.view','View Invoices'),('billing','billing.create','Create Invoices'),
    ('billing','billing.edit','Edit Invoices'),('billing','billing.payments','Record Payments'),('billing','billing.refund','Process Refunds'),
    ('reports','reports.view','View Reports'),('reports','reports.export','Export Reports'),
    ('dental','dental.view','View Dental Charts'),('dental','dental.manage','Manage Dental Treatments'),
    ('vaccination','vaccination.view','View Vaccinations'),('vaccination','vaccination.manage','Manage Vaccinations'),
    ('communication','communication.view','View Communications'),('communication','communication.send','Send Communications'),
    ('communication','communication.templates','Manage Message Templates'),
    ('admin','admin.settings','System Settings'),('admin','admin.audit','View Audit Logs'),
    ('admin','admin.backup','Manage Backups'),('admin','admin.api','API Management')
    ", $results);
} else {
    $results[] = ['skip', "Permissions already exist ($permCount)"];
}

// ═══════════════════════════════════════════════════════════════
// RENDER RESULTS
// ═══════════════════════════════════════════════════════════════
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>Migration Results</title>
<style>
body{font-family:'Inter',system-ui,sans-serif;background:#0f172a;color:#e2e8f0;padding:40px;max-width:800px;margin:0 auto}
h1{color:#7dd3fc;margin-bottom:24px}
.r{padding:10px 16px;border-radius:8px;margin-bottom:8px;font-size:14px;display:flex;gap:12px;align-items:center}
.ok{background:#064e3b;border:1px solid #10b981}.ok::before{content:'✅'}
.err{background:#7f1d1d;border:1px solid #ef4444}.err::before{content:'❌'}
.skip{background:#1e293b;border:1px solid #334155;color:#94a3b8}.skip::before{content:'⏭️'}
a{color:#7dd3fc;text-decoration:none;display:inline-block;margin-top:24px;padding:10px 20px;background:#1e40af;border-radius:8px}
.summary{background:#1e293b;padding:16px;border-radius:12px;margin-bottom:24px;display:flex;gap:24px}
.summary div{text-align:center}.summary .num{font-size:28px;font-weight:700}.summary .lbl{font-size:12px;color:#94a3b8}
</style></head><body>
<h1>🏥 Migration & Seed Results</h1>
<?php
$ok = count(array_filter($results, fn($r) => $r[0] === 'ok'));
$err = count(array_filter($results, fn($r) => $r[0] === 'err'));
$skip = count(array_filter($results, fn($r) => $r[0] === 'skip'));
?>
<div class="summary">
    <div><div class="num" style="color:#10b981"><?= $ok ?></div><div class="lbl">Applied</div></div>
    <div><div class="num" style="color:#f59e0b"><?= $skip ?></div><div class="lbl">Skipped</div></div>
    <div><div class="num" style="color:#ef4444"><?= $err ?></div><div class="lbl">Errors</div></div>
</div>
<?php foreach ($results as [$type, $msg]): ?>
<div class="r <?= $type ?>"><?= htmlspecialchars($msg) ?></div>
<?php endforeach; ?>
<a href="modules/admin/index.php">← Back to Admin</a>
</body></html>
