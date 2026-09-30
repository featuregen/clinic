<?php
/**
 * Master Data Management - Feature Gen Care
 * Manages: Departments, Specialties, Medicines, Diagnoses, Lab Tests, Services
 * Features:
 *   1. Single Add/Edit with Audit Logging
 *   2. Delete with safety checks & Audit Logging
 *   3. Bulk Excel / CSV Import for all tabs with live preview & duplicate handling
 *   4. Sample Template Generator (Excel/CSV) for all tabs
 *   5. Master Data Audit Trail Modal
 */
require_once dirname(dirname(__DIR__)) . '/config/session.php';
requireAuth();
requireRole([ROLE_SUPER_ADMIN, ROLE_ADMIN]);

$db = db();
$clinicId = getCurrentClinicId();

// Determine active tab
$tab = $_GET['tab'] ?? 'departments';
$validTabs = ['departments', 'specialties', 'medicines', 'diagnoses', 'lab_tests', 'services'];
if (!in_array($tab, $validTabs)) $tab = 'departments';

// Global tab configurations, fields, aliases, and sample data
$tabConfig = [
    'departments' => [
        'icon' => 'fa-building',
        'label' => 'Departments',
        'singular' => 'Department',
        'table' => 'departments',
        'color' => 'var(--primary)',
        'clinic_scoped' => true,
        'unique_field' => 'name',
        'fields' => [
            'name' => ['label' => 'Department Name', 'required' => true, 'type' => 'text', 'aliases' => ['name', 'department', 'department_name', 'dept', 'dept_name', 'title']],
            'description' => ['label' => 'Description', 'required' => false, 'type' => 'text', 'aliases' => ['description', 'desc', 'details', 'about', 'notes']],
        ],
        'sample_data' => [
            ['name' => 'Cardiology', 'description' => 'Heart and cardiovascular disorders.'],
            ['name' => 'Pediatrics', 'description' => 'Comprehensive infant, child, and adolescent healthcare.'],
            ['name' => 'Dermatology', 'description' => 'Skin, hair, and nail health.'],
            ['name' => 'Orthopedics', 'description' => 'Care for musculoskeletal system and bones.'],
            ['name' => 'General Medicine', 'description' => 'Primary care for adults and families.'],
        ]
    ],
    'specialties' => [
        'icon' => 'fa-stethoscope',
        'label' => 'Specialties',
        'singular' => 'Specialty',
        'table' => 'specialties',
        'color' => 'var(--accent)',
        'clinic_scoped' => false,
        'unique_field' => 'name',
        'fields' => [
            'name' => ['label' => 'Specialty Name', 'required' => true, 'type' => 'text', 'aliases' => ['name', 'specialty', 'specialty_name', 'specialization', 'field']],
            'code' => ['label' => 'Code', 'required' => false, 'type' => 'text', 'aliases' => ['code', 'specialty_code', 'short_code']],
            'description' => ['label' => 'Description', 'required' => false, 'type' => 'text', 'aliases' => ['description', 'desc', 'details', 'notes']],
        ],
        'sample_data' => [
            ['name' => 'Cardiology', 'code' => 'CARDIO', 'description' => 'Cardiovascular diseases and management'],
            ['name' => 'Orthopedics', 'code' => 'ORTHO', 'description' => 'Bones, joints, and skeletal system'],
            ['name' => 'Neurology', 'code' => 'NEURO', 'description' => 'Brain, nerves, and neurological disorders'],
            ['name' => 'Dentistry', 'code' => 'DENT', 'description' => 'Oral health, odontogram, and dental surgery'],
            ['name' => 'Gynecology', 'code' => 'GYN', 'description' => 'Women reproductive health'],
        ]
    ],
    'medicines' => [
        'icon' => 'fa-pills',
        'label' => 'Medicines',
        'singular' => 'Medicine',
        'table' => 'medicines',
        'color' => 'var(--success)',
        'clinic_scoped' => false,
        'unique_field' => 'name',
        'fields' => [
            'name' => ['label' => 'Medicine Name', 'required' => true, 'type' => 'text', 'aliases' => ['name', 'medicine', 'medicine_name', 'drug_name', 'item_name']],
            'generic_name' => ['label' => 'Generic Name', 'required' => false, 'type' => 'text', 'aliases' => ['generic_name', 'generic', 'composition', 'molecule', 'salt']],
            'brand' => ['label' => 'Brand / Manufacturer', 'required' => false, 'type' => 'text', 'aliases' => ['brand', 'brand_name', 'manufacturer', 'company']],
            'dosage_form' => ['label' => 'Dosage Form', 'required' => false, 'type' => 'select', 'default' => 'Tablet', 'aliases' => ['dosage_form', 'form', 'type']],
            'strength' => ['label' => 'Strength', 'required' => false, 'type' => 'text', 'aliases' => ['strength', 'dosage', 'potency', 'power']],
            'category' => ['label' => 'Category', 'required' => false, 'type' => 'text', 'aliases' => ['category', 'drug_category', 'class', 'group']],
        ],
        'sample_data' => [
            ['name' => 'Paracetamol 500mg', 'generic_name' => 'Paracetamol', 'brand' => 'Crocin', 'dosage_form' => 'Tablet', 'strength' => '500mg', 'category' => 'Analgesic / Antipyretic'],
            ['name' => 'Amoxicillin 250mg', 'generic_name' => 'Amoxicillin', 'brand' => 'Novamox', 'dosage_form' => 'Capsule', 'strength' => '250mg', 'category' => 'Antibiotic'],
            ['name' => 'Cetirizine 5mg/5ml', 'generic_name' => 'Cetirizine', 'brand' => 'Zyrtec', 'dosage_form' => 'Syrup', 'strength' => '5mg/5ml', 'category' => 'Antihistamine'],
            ['name' => 'Omeprazole 20mg', 'generic_name' => 'Omeprazole', 'brand' => 'Omez', 'dosage_form' => 'Capsule', 'strength' => '20mg', 'category' => 'Antacid / PPI'],
            ['name' => 'Ibuprofen 400mg', 'generic_name' => 'Ibuprofen', 'brand' => 'Brufen', 'dosage_form' => 'Tablet', 'strength' => '400mg', 'category' => 'NSAID'],
        ]
    ],
    'diagnoses' => [
        'icon' => 'fa-diagnoses',
        'label' => 'Diagnoses',
        'singular' => 'Diagnosis',
        'table' => 'diagnoses',
        'color' => 'var(--warning)',
        'clinic_scoped' => false,
        'unique_field' => 'name',
        'fields' => [
            'name' => ['label' => 'Diagnosis Name', 'required' => true, 'type' => 'text', 'aliases' => ['name', 'diagnosis', 'diagnosis_name', 'condition', 'disease']],
            'icd_code' => ['label' => 'ICD Code', 'required' => false, 'type' => 'text', 'aliases' => ['icd_code', 'icd', 'code', 'icd10', 'icd_10']],
            'category' => ['label' => 'Category', 'required' => false, 'type' => 'text', 'aliases' => ['category', 'classification', 'group', 'system']],
            'description' => ['label' => 'Description', 'required' => false, 'type' => 'text', 'aliases' => ['description', 'desc', 'notes', 'details']],
        ],
        'sample_data' => [
            ['name' => 'Acute Nasopharyngitis (Common Cold)', 'icd_code' => 'J00', 'category' => 'Respiratory', 'description' => 'Viral upper respiratory tract infection.'],
            ['name' => 'Essential (Primary) Hypertension', 'icd_code' => 'I10', 'category' => 'Cardiovascular', 'description' => 'Chronic high arterial blood pressure.'],
            ['name' => 'Type 2 Diabetes Mellitus', 'icd_code' => 'E11', 'category' => 'Endocrine', 'description' => 'Non-insulin dependent metabolic diabetes.'],
            ['name' => 'Acute Gastritis', 'icd_code' => 'K29.0', 'category' => 'Gastrointestinal', 'description' => 'Sudden irritation or inflammation of stomach lining.'],
            ['name' => 'Acute Bronchitis', 'icd_code' => 'J20', 'category' => 'Respiratory', 'description' => 'Short-term inflammation of the bronchi.'],
        ]
    ],
    'lab_tests' => [
        'icon' => 'fa-flask',
        'label' => 'Lab Tests',
        'singular' => 'Lab Test',
        'table' => 'lab_tests',
        'color' => 'var(--info)',
        'clinic_scoped' => false,
        'unique_field' => 'name',
        'fields' => [
            'name' => ['label' => 'Test Name', 'required' => true, 'type' => 'text', 'aliases' => ['name', 'test_name', 'lab_test', 'test', 'investigation']],
            'code' => ['label' => 'Test Code', 'required' => false, 'type' => 'text', 'aliases' => ['code', 'test_code', 'short_code']],
            'category' => ['label' => 'Category', 'required' => false, 'type' => 'text', 'aliases' => ['category', 'department', 'section', 'group']],
            'price' => ['label' => 'Price (₹)', 'required' => false, 'type' => 'number', 'default' => 0.00, 'aliases' => ['price', 'cost', 'fee', 'rate', 'amount']],
            'description' => ['label' => 'Description', 'required' => false, 'type' => 'text', 'aliases' => ['description', 'desc', 'details', 'notes']],
        ],
        'sample_data' => [
            ['name' => 'Complete Blood Count (CBC)', 'code' => 'CBC', 'category' => 'Hematology', 'price' => 350.00, 'description' => 'Full hemogram including WBC, RBC, Platelets, Hb.'],
            ['name' => 'Fasting Blood Sugar (FBS)', 'code' => 'FBS', 'category' => 'Biochemistry', 'price' => 120.00, 'description' => 'Blood glucose level after overnight fasting.'],
            ['name' => 'Lipid Profile', 'code' => 'LIPID', 'category' => 'Biochemistry', 'price' => 650.00, 'description' => 'Total cholesterol, HDL, LDL, Triglycerides.'],
            ['name' => 'Thyroid Stimulating Hormone (TSH)', 'code' => 'TSH', 'category' => 'Serology', 'price' => 300.00, 'description' => 'Screening for thyroid dysfunction.'],
            ['name' => 'Urine Routine Examination', 'code' => 'URINE-RE', 'category' => 'Clinical Pathology', 'price' => 150.00, 'description' => 'Physical, chemical, and microscopic urine analysis.'],
        ]
    ],
    'services' => [
        'icon' => 'fa-concierge-bell',
        'label' => 'Services',
        'singular' => 'Service',
        'table' => 'services',
        'color' => 'var(--danger)',
        'clinic_scoped' => true,
        'unique_field' => 'name',
        'fields' => [
            'name' => ['label' => 'Service Name', 'required' => true, 'type' => 'text', 'aliases' => ['name', 'service_name', 'service', 'item_name', 'procedure']],
            'category' => ['label' => 'Category', 'required' => false, 'type' => 'select', 'default' => 'service', 'aliases' => ['category', 'type', 'service_type']],
            'default_price' => ['label' => 'Default Price (₹)', 'required' => false, 'type' => 'number', 'default' => 0.00, 'aliases' => ['default_price', 'price', 'cost', 'fee', 'rate', 'amount']],
            'description' => ['label' => 'Description', 'required' => false, 'type' => 'text', 'aliases' => ['description', 'desc', 'details', 'notes']],
        ],
        'sample_data' => [
            ['name' => 'General Doctor Consultation', 'category' => 'consultation', 'default_price' => 400.00, 'description' => 'Standard outpatient clinical consultation.'],
            ['name' => 'Specialist Follow-up Consultation', 'category' => 'consultation', 'default_price' => 300.00, 'description' => 'Follow-up visit within 7 days.'],
            ['name' => 'Dental Scaling & Polishing', 'category' => 'dental', 'default_price' => 1200.00, 'description' => 'Full mouth teeth ultrasonic cleaning.'],
            ['name' => 'Wound Dressing & Suture Care', 'category' => 'procedure', 'default_price' => 500.00, 'description' => 'Aseptic wound cleaning, dressing, and suture check.'],
            ['name' => 'Nebulization Session', 'category' => 'procedure', 'default_price' => 200.00, 'description' => 'Bronchodilator inhalation therapy.'],
        ]
    ]
];

$dosageForms = ['Tablet','Capsule','Syrup','Injection','Cream','Ointment','Drops','Inhaler','Powder','Gel','Spray','Suppository','Patch','Other'];
$serviceTypes = [
    'consultation' => 'Consultation', 'procedure' => 'Procedure', 'medicine' => 'Medicine',
    'lab_test' => 'Lab Test', 'vaccination' => 'Vaccination', 'dental' => 'Dental',
    'service' => 'Service', 'other' => 'Other'
];

// ============================================
// 1. SAMPLE TEMPLATE DOWNLOAD (EXCEL / CSV)
// ============================================
if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    $currentTab = $_GET['tab'] ?? $tab;
    if (!isset($tabConfig[$currentTab])) $currentTab = 'departments';
    $cfg = $tabConfig[$currentTab];

    $filename = $currentTab . '_import_template.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    // UTF-8 BOM so Microsoft Excel opens it seamlessly without character glitches
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

    // Headers row
    $headerRow = [];
    foreach ($cfg['fields'] as $fKey => $fInfo) {
        $headerRow[] = $fInfo['label'];
    }
    fputcsv($out, $headerRow);

    // Sample data rows
    foreach ($cfg['sample_data'] as $sRow) {
        $row = [];
        foreach ($cfg['fields'] as $fKey => $fInfo) {
            $row[] = $sRow[$fKey] ?? ($fInfo['default'] ?? '');
        }
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

// Server-side CSV file parser fallback
function parseCsvFileStream($filePath, $fieldsConfig) {
    $rows = [];
    if (!file_exists($filePath) || !is_readable($filePath)) return $rows;
    $handle = fopen($filePath, 'r');
    if (!$handle) return $rows;

    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($handle);
    }

    $firstLine = fgets($handle);
    rewind($handle);
    if ($bom === "\xEF\xBB\xBF") fread($handle, 3);

    $delimiter = ',';
    if (substr_count($firstLine, ';') > substr_count($firstLine, ',')) {
        $delimiter = ';';
    } elseif (substr_count($firstLine, "\t") > substr_count($firstLine, ',')) {
        $delimiter = "\t";
    }

    $rawHeaders = fgetcsv($handle, 0, $delimiter);
    if (!$rawHeaders) {
        fclose($handle);
        return $rows;
    }

    $colMap = [];
    foreach ($rawHeaders as $idx => $headerText) {
        $clean = preg_replace('/[^a-z0-9]/', '', strtolower(trim($headerText)));
        foreach ($fieldsConfig as $fKey => $fInfo) {
            $labelClean = preg_replace('/[^a-z0-9]/', '', strtolower($fInfo['label']));
            if ($clean === $labelClean || $clean === preg_replace('/[^a-z0-9]/', '', $fKey)) {
                $colMap[$idx] = $fKey;
                break;
            }
            if (!empty($fInfo['aliases'])) {
                foreach ($fInfo['aliases'] as $alias) {
                    if ($clean === preg_replace('/[^a-z0-9]/', '', strtolower($alias))) {
                        $colMap[$idx] = $fKey;
                        break 2;
                    }
                }
            }
        }
    }

    while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
        if (count($data) === 1 && trim($data[0]) === '') continue;
        $row = [];
        foreach ($data as $colIdx => $val) {
            if (isset($colMap[$colIdx])) {
                $row[$colMap[$colIdx]] = trim($val);
            }
        }
        if (!empty($row['name'])) {
            $rows[] = $row;
        }
    }
    fclose($handle);
    return $rows;
}

// ============================================
// 2. HANDLE POST ACTIONS (ADD, EDIT, DELETE, TOGGLE, BULK IMPORT)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = intval($_POST['id'] ?? 0);
    $cfg = $tabConfig[$tab];
    $table = $cfg['table'];
    $isClinicScoped = $cfg['clinic_scoped'];
    $tabLabel = $cfg['singular'];

    try {
        // ----------------------------------------
        // A. DELETE ACTION (WITH AUDIT LOG & SAFETY CHECK)
        // ----------------------------------------
        if ($action === 'delete') {
            if (!$id) {
                throw new Exception("Invalid record ID for deletion.");
            }

            // Fetch existing record for audit logging
            $oldRecord = $isClinicScoped 
                ? $db->fetch("SELECT * FROM {$table} WHERE id = ? AND clinic_id = ?", [$id, $clinicId])
                : $db->fetch("SELECT * FROM {$table} WHERE id = ?", [$id]);

            if (!$oldRecord) {
                throw new Exception("Record not found or already deleted.");
            }

            // Dependency checks to prevent breaking active data
            if ($tab === 'departments') {
                $linkedDocs = $db->fetch("SELECT COUNT(*) as c FROM doctors WHERE department_id = ? AND clinic_id = ?", [$id, $clinicId])['c'] ?? 0;
                if ($linkedDocs > 0) {
                    throw new Exception("Cannot delete Department '{$oldRecord['name']}': {$linkedDocs} doctor(s) are currently assigned to it. Please reassign the doctors first or deactivate the department.");
                }
            } elseif ($tab === 'specialties') {
                $linkedDocs = $db->fetch("SELECT COUNT(*) as c FROM doctors WHERE specialty_id = ?", [$id])['c'] ?? 0;
                if ($linkedDocs > 0) {
                    throw new Exception("Cannot delete Specialty '{$oldRecord['name']}': {$linkedDocs} doctor(s) are currently assigned to it. Please reassign the doctors first or deactivate the specialty.");
                }
            } elseif ($tab === 'medicines') {
                $linkedRx = $db->fetch("SELECT COUNT(*) as c FROM prescription_medicines WHERE medicine_id = ?", [$id])['c'] ?? 0;
                if ($linkedRx > 0) {
                    throw new Exception("Cannot delete Medicine '{$oldRecord['name']}': It is referenced in {$linkedRx} active prescription(s). You can deactivate it instead to preserve prescription records.");
                }
            } elseif ($tab === 'services') {
                $linkedInv = $db->fetch("SELECT COUNT(*) as c FROM invoice_items WHERE item_id = ? AND item_type = 'service'", [$id])['c'] ?? 0;
                if ($linkedInv > 0) {
                    throw new Exception("Cannot delete Service '{$oldRecord['name']}': It is referenced in {$linkedInv} invoice billing item(s). You can deactivate it instead.");
                }
            }

            // Execute Delete
            try {
                if ($isClinicScoped) {
                    $db->query("DELETE FROM {$table} WHERE id = ? AND clinic_id = ?", [$id, $clinicId]);
                } else {
                    $db->query("DELETE FROM {$table} WHERE id = ?", [$id]);
                }
            } catch (PDOException $pe) {
                if ($pe->getCode() == '23000') {
                    throw new Exception("Cannot delete '{$oldRecord['name']}' because it is linked to other records in the database. Please deactivate it instead.");
                }
                throw $pe;
            }

            // Log Audit
            logAudit(
                'delete',
                'master_data',
                $tab,
                $id,
                $oldRecord,
                null,
                "Deleted {$tabLabel} '{$oldRecord['name']}' (ID: {$id})"
            );

            setFlashMessage('success', "{$tabLabel} '{$oldRecord['name']}' deleted successfully. Action logged in audit trail.");
            header("Location: " . BASE_URL . "/modules/admin/master_data.php?tab=$tab");
            exit;
        }

        // ----------------------------------------
        // B. BULK IMPORT ACTION (WITH AUDIT LOG)
        // ----------------------------------------
        elseif ($action === 'bulk_import') {
            $duplicateAction = sanitize($_POST['duplicate_action'] ?? 'skip'); // 'skip' or 'update'
            $records = [];

            // Case 1: Read client-side parsed JSON
            if (!empty($_POST['records_json'])) {
                $decoded = json_decode($_POST['records_json'], true);
                if (is_array($decoded)) {
                    $records = $decoded;
                }
            }

            // Case 2: Server-side file fallback (CSV)
            if (empty($records) && !empty($_FILES['import_file']['tmp_name']) && is_uploaded_file($_FILES['import_file']['tmp_name'])) {
                $records = parseCsvFileStream($_FILES['import_file']['tmp_name'], $cfg['fields']);
            }

            if (empty($records)) {
                throw new Exception("No valid records found to import. Please check your Excel or CSV file.");
            }

            $importedCount = 0;
            $updatedCount = 0;
            $skippedCount = 0;

            $db->beginTransaction();

            foreach ($records as $row) {
                $name = trim($row['name'] ?? '');
                if ($name === '') {
                    $skippedCount++;
                    continue;
                }

                // Check duplicate
                $existing = $isClinicScoped
                    ? $db->fetch("SELECT * FROM {$table} WHERE clinic_id = ? AND LOWER(name) = LOWER(?)", [$clinicId, $name])
                    : $db->fetch("SELECT * FROM {$table} WHERE LOWER(name) = LOWER(?)", [$name]);

                if ($existing) {
                    if ($duplicateAction === 'update') {
                        // Update existing record
                        switch ($tab) {
                            case 'departments':
                                $db->query("UPDATE departments SET description = ? WHERE id = ? AND clinic_id = ?", [
                                    sanitize($row['description'] ?? $existing['description'] ?? ''),
                                    $existing['id'], $clinicId
                                ]);
                                break;
                            case 'specialties':
                                $db->query("UPDATE specialties SET code = ?, description = ? WHERE id = ?", [
                                    sanitize($row['code'] ?? $existing['code'] ?? ''),
                                    sanitize($row['description'] ?? $existing['description'] ?? ''),
                                    $existing['id']
                                ]);
                                break;
                            case 'medicines':
                                $dosageForm = sanitize($row['dosage_form'] ?? $existing['dosage_form'] ?? 'Tablet');
                                if (!in_array($dosageForm, $dosageForms)) $dosageForm = 'Tablet';
                                $db->query("UPDATE medicines SET generic_name=?, brand=?, dosage_form=?, strength=?, category=? WHERE id=?", [
                                    sanitize($row['generic_name'] ?? $existing['generic_name'] ?? ''),
                                    sanitize($row['brand'] ?? $existing['brand'] ?? ''),
                                    $dosageForm,
                                    sanitize($row['strength'] ?? $existing['strength'] ?? ''),
                                    sanitize($row['category'] ?? $existing['category'] ?? ''),
                                    $existing['id']
                                ]);
                                break;
                            case 'diagnoses':
                                $db->query("UPDATE diagnoses SET icd_code=?, category=?, description=? WHERE id=?", [
                                    sanitize($row['icd_code'] ?? $existing['icd_code'] ?? ''),
                                    sanitize($row['category'] ?? $existing['category'] ?? ''),
                                    sanitize($row['description'] ?? $existing['description'] ?? ''),
                                    $existing['id']
                                ]);
                                break;
                            case 'lab_tests':
                                $price = isset($row['price']) && is_numeric($row['price']) ? floatval($row['price']) : floatval($existing['price'] ?? 0);
                                $db->query("UPDATE lab_tests SET code=?, category=?, price=?, description=? WHERE id=?", [
                                    sanitize($row['code'] ?? $existing['code'] ?? ''),
                                    sanitize($row['category'] ?? $existing['category'] ?? ''),
                                    $price,
                                    sanitize($row['description'] ?? $existing['description'] ?? ''),
                                    $existing['id']
                                ]);
                                break;
                            case 'services':
                                $cat = strtolower(trim($row['category'] ?? $existing['category'] ?? 'service'));
                                if (!array_key_exists($cat, $serviceTypes)) $cat = 'service';
                                $price = isset($row['default_price']) && is_numeric($row['default_price']) ? floatval($row['default_price']) : floatval($existing['default_price'] ?? 0);
                                $db->query("UPDATE services SET category=?, default_price=?, description=? WHERE id=? AND clinic_id=?", [
                                    $cat, $price,
                                    sanitize($row['description'] ?? $existing['description'] ?? ''),
                                    $existing['id'], $clinicId
                                ]);
                                break;
                        }
                        $updatedCount++;
                    } else {
                        $skippedCount++;
                    }
                } else {
                    // Insert new record
                    switch ($tab) {
                        case 'departments':
                            $db->query("INSERT INTO departments (clinic_id, name, description, is_active) VALUES (?, ?, ?, 1)", [
                                $clinicId, sanitize($name), sanitize($row['description'] ?? '')
                            ]);
                            break;
                        case 'specialties':
                            $db->query("INSERT INTO specialties (name, code, description, is_active) VALUES (?, ?, ?, 1)", [
                                sanitize($name), sanitize($row['code'] ?? ''), sanitize($row['description'] ?? '')
                            ]);
                            break;
                        case 'medicines':
                            $dosageForm = sanitize($row['dosage_form'] ?? 'Tablet');
                            if (!in_array($dosageForm, $dosageForms)) $dosageForm = 'Tablet';
                            $db->query("INSERT INTO medicines (name, generic_name, brand, dosage_form, strength, category, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)", [
                                sanitize($name),
                                sanitize($row['generic_name'] ?? ''),
                                sanitize($row['brand'] ?? ''),
                                $dosageForm,
                                sanitize($row['strength'] ?? ''),
                                sanitize($row['category'] ?? '')
                            ]);
                            break;
                        case 'diagnoses':
                            $db->query("INSERT INTO diagnoses (name, icd_code, category, description, is_active) VALUES (?, ?, ?, ?, 1)", [
                                sanitize($name),
                                sanitize($row['icd_code'] ?? ''),
                                sanitize($row['category'] ?? ''),
                                sanitize($row['description'] ?? '')
                            ]);
                            break;
                        case 'lab_tests':
                            $price = isset($row['price']) && is_numeric($row['price']) ? floatval($row['price']) : 0.0;
                            $db->query("INSERT INTO lab_tests (name, code, category, price, description, is_active) VALUES (?, ?, ?, ?, ?, 1)", [
                                sanitize($name),
                                sanitize($row['code'] ?? ''),
                                sanitize($row['category'] ?? ''),
                                $price,
                                sanitize($row['description'] ?? '')
                            ]);
                            break;
                        case 'services':
                            $cat = strtolower(trim($row['category'] ?? 'service'));
                            if (!array_key_exists($cat, $serviceTypes)) $cat = 'service';
                            $price = isset($row['default_price']) && is_numeric($row['default_price']) ? floatval($row['default_price']) : 0.0;
                            $db->query("INSERT INTO services (clinic_id, name, category, default_price, description, is_active) VALUES (?, ?, ?, ?, ?, 1)", [
                                $clinicId,
                                sanitize($name),
                                $cat,
                                $price,
                                sanitize($row['description'] ?? '')
                            ]);
                            break;
                    }
                    $importedCount++;
                }
            }

            $db->commit();

            // Log Audit for Bulk Import
            logAudit(
                'bulk_import',
                'master_data',
                $tab,
                null,
                null,
                ['imported' => $importedCount, 'updated' => $updatedCount, 'skipped' => $skippedCount],
                "Bulk imported {$cfg['label']} from Excel/CSV: {$importedCount} created, {$updatedCount} updated, {$skippedCount} skipped"
            );

            $msg = "Bulk import completed: <strong>{$importedCount} added</strong>";
            if ($updatedCount > 0) $msg .= ", <strong>{$updatedCount} updated</strong>";
            if ($skippedCount > 0) $msg .= ", <strong>{$skippedCount} existing skipped</strong>";
            $msg .= ". Action logged in audit trail.";

            setFlashMessage('success', $msg);
            header("Location: " . BASE_URL . "/modules/admin/master_data.php?tab=$tab");
            exit;
        }

        // ----------------------------------------
        // C. SINGLE ADD / EDIT / TOGGLE ACTIONS (WITH AUDIT LOG)
        // ----------------------------------------
        switch ($tab) {
            case 'departments':
                $name = sanitize($_POST['name'] ?? '');
                $desc = sanitize($_POST['description'] ?? '');

                if ($action === 'add') {
                    $db->query("INSERT INTO departments (clinic_id, name, description) VALUES (?, ?, ?)", [$clinicId, $name, $desc]);
                    $newId = $db->lastInsertId();
                    $newRecord = $db->fetch("SELECT * FROM departments WHERE id = ?", [$newId]);

                    logAudit('create', 'master_data', $tab, $newId, null, $newRecord, "Created Department '{$name}' (ID: {$newId})");
                    setFlashMessage('success', "Department '{$name}' added.");
                } elseif ($action === 'edit' && $id) {
                    $oldRecord = $db->fetch("SELECT * FROM departments WHERE id = ? AND clinic_id = ?", [$id, $clinicId]);
                    if (!$oldRecord) throw new Exception("Department not found.");

                    $db->query("UPDATE departments SET name=?, description=? WHERE id=? AND clinic_id=?", [$name, $desc, $id, $clinicId]);
                    $newRecord = $db->fetch("SELECT * FROM departments WHERE id = ? AND clinic_id = ?", [$id, $clinicId]);

                    logAudit('update', 'master_data', $tab, $id, $oldRecord, $newRecord, "Updated Department '{$name}' (ID: {$id})");
                    setFlashMessage('success', "Department '{$name}' updated.");
                } elseif ($action === 'toggle' && $id) {
                    $oldRecord = $db->fetch("SELECT * FROM departments WHERE id = ? AND clinic_id = ?", [$id, $clinicId]);
                    if ($oldRecord) {
                        $newStatus = $oldRecord['is_active'] ? 0 : 1;
                        $db->query("UPDATE departments SET is_active = ? WHERE id = ? AND clinic_id = ?", [$newStatus, $id, $clinicId]);
                        logAudit('toggle_status', 'master_data', $tab, $id, ['is_active' => $oldRecord['is_active']], ['is_active' => $newStatus], "Toggled status of Department '{$oldRecord['name']}' to " . ($newStatus ? 'Active' : 'Inactive'));
                        setFlashMessage('success', 'Status toggled.');
                    }
                }
                break;

            case 'specialties':
                $name = sanitize($_POST['name'] ?? '');
                $code = sanitize($_POST['code'] ?? '');
                $desc = sanitize($_POST['description'] ?? '');

                if ($action === 'add') {
                    $db->query("INSERT INTO specialties (name, code, description) VALUES (?, ?, ?)", [$name, $code, $desc]);
                    $newId = $db->lastInsertId();
                    $newRecord = $db->fetch("SELECT * FROM specialties WHERE id = ?", [$newId]);

                    logAudit('create', 'master_data', $tab, $newId, null, $newRecord, "Created Specialty '{$name}' (ID: {$newId})");
                    setFlashMessage('success', "Specialty '{$name}' added.");
                } elseif ($action === 'edit' && $id) {
                    $oldRecord = $db->fetch("SELECT * FROM specialties WHERE id = ?", [$id]);
                    if (!$oldRecord) throw new Exception("Specialty not found.");

                    $db->query("UPDATE specialties SET name=?, code=?, description=? WHERE id=?", [$name, $code, $desc, $id]);
                    $newRecord = $db->fetch("SELECT * FROM specialties WHERE id = ?", [$id]);

                    logAudit('update', 'master_data', $tab, $id, $oldRecord, $newRecord, "Updated Specialty '{$name}' (ID: {$id})");
                    setFlashMessage('success', "Specialty '{$name}' updated.");
                } elseif ($action === 'toggle' && $id) {
                    $oldRecord = $db->fetch("SELECT * FROM specialties WHERE id = ?", [$id]);
                    if ($oldRecord) {
                        $newStatus = $oldRecord['is_active'] ? 0 : 1;
                        $db->query("UPDATE specialties SET is_active = ? WHERE id = ?", [$newStatus, $id]);
                        logAudit('toggle_status', 'master_data', $tab, $id, ['is_active' => $oldRecord['is_active']], ['is_active' => $newStatus], "Toggled status of Specialty '{$oldRecord['name']}' to " . ($newStatus ? 'Active' : 'Inactive'));
                        setFlashMessage('success', 'Status toggled.');
                    }
                }
                break;

            case 'medicines':
                $name = sanitize($_POST['name'] ?? '');
                $genName = sanitize($_POST['generic_name'] ?? '');
                $brand = sanitize($_POST['brand'] ?? '');
                $form = sanitize($_POST['dosage_form'] ?? 'Tablet');
                if (!in_array($form, $dosageForms)) $form = 'Tablet';
                $strength = sanitize($_POST['strength'] ?? '');
                $category = sanitize($_POST['category'] ?? '');

                if ($action === 'add') {
                    $db->query("INSERT INTO medicines (name, generic_name, brand, dosage_form, strength, category) VALUES (?, ?, ?, ?, ?, ?)",
                        [$name, $genName, $brand, $form, $strength, $category]);
                    $newId = $db->lastInsertId();
                    $newRecord = $db->fetch("SELECT * FROM medicines WHERE id = ?", [$newId]);

                    logAudit('create', 'master_data', $tab, $newId, null, $newRecord, "Created Medicine '{$name}' (ID: {$newId})");
                    setFlashMessage('success', "Medicine '{$name}' added.");
                } elseif ($action === 'edit' && $id) {
                    $oldRecord = $db->fetch("SELECT * FROM medicines WHERE id = ?", [$id]);
                    if (!$oldRecord) throw new Exception("Medicine not found.");

                    $db->query("UPDATE medicines SET name=?, generic_name=?, brand=?, dosage_form=?, strength=?, category=? WHERE id=?",
                        [$name, $genName, $brand, $form, $strength, $category, $id]);
                    $newRecord = $db->fetch("SELECT * FROM medicines WHERE id = ?", [$id]);

                    logAudit('update', 'master_data', $tab, $id, $oldRecord, $newRecord, "Updated Medicine '{$name}' (ID: {$id})");
                    setFlashMessage('success', "Medicine '{$name}' updated.");
                } elseif ($action === 'toggle' && $id) {
                    $oldRecord = $db->fetch("SELECT * FROM medicines WHERE id = ?", [$id]);
                    if ($oldRecord) {
                        $newStatus = $oldRecord['is_active'] ? 0 : 1;
                        $db->query("UPDATE medicines SET is_active = ? WHERE id = ?", [$newStatus, $id]);
                        logAudit('toggle_status', 'master_data', $tab, $id, ['is_active' => $oldRecord['is_active']], ['is_active' => $newStatus], "Toggled status of Medicine '{$oldRecord['name']}' to " . ($newStatus ? 'Active' : 'Inactive'));
                        setFlashMessage('success', 'Status toggled.');
                    }
                }
                break;

            case 'diagnoses':
                $name = sanitize($_POST['name'] ?? '');
                $icd = sanitize($_POST['icd_code'] ?? '');
                $category = sanitize($_POST['category'] ?? '');
                $desc = sanitize($_POST['description'] ?? '');

                if ($action === 'add') {
                    $db->query("INSERT INTO diagnoses (name, icd_code, category, description) VALUES (?, ?, ?, ?)",
                        [$name, $icd, $category, $desc]);
                    $newId = $db->lastInsertId();
                    $newRecord = $db->fetch("SELECT * FROM diagnoses WHERE id = ?", [$newId]);

                    logAudit('create', 'master_data', $tab, $newId, null, $newRecord, "Created Diagnosis '{$name}' (ID: {$newId})");
                    setFlashMessage('success', "Diagnosis '{$name}' added.");
                } elseif ($action === 'edit' && $id) {
                    $oldRecord = $db->fetch("SELECT * FROM diagnoses WHERE id = ?", [$id]);
                    if (!$oldRecord) throw new Exception("Diagnosis not found.");

                    $db->query("UPDATE diagnoses SET name=?, icd_code=?, category=?, description=? WHERE id=?",
                        [$name, $icd, $category, $desc, $id]);
                    $newRecord = $db->fetch("SELECT * FROM diagnoses WHERE id = ?", [$id]);

                    logAudit('update', 'master_data', $tab, $id, $oldRecord, $newRecord, "Updated Diagnosis '{$name}' (ID: {$id})");
                    setFlashMessage('success', "Diagnosis '{$name}' updated.");
                } elseif ($action === 'toggle' && $id) {
                    $oldRecord = $db->fetch("SELECT * FROM diagnoses WHERE id = ?", [$id]);
                    if ($oldRecord) {
                        $newStatus = $oldRecord['is_active'] ? 0 : 1;
                        $db->query("UPDATE diagnoses SET is_active = ? WHERE id = ?", [$newStatus, $id]);
                        logAudit('toggle_status', 'master_data', $tab, $id, ['is_active' => $oldRecord['is_active']], ['is_active' => $newStatus], "Toggled status of Diagnosis '{$oldRecord['name']}' to " . ($newStatus ? 'Active' : 'Inactive'));
                        setFlashMessage('success', 'Status toggled.');
                    }
                }
                break;

            case 'lab_tests':
                $name = sanitize($_POST['name'] ?? '');
                $code = sanitize($_POST['code'] ?? '');
                $category = sanitize($_POST['category'] ?? '');
                $price = floatval($_POST['price'] ?? 0);
                $desc = sanitize($_POST['description'] ?? '');

                if ($action === 'add') {
                    $db->query("INSERT INTO lab_tests (name, code, category, price, description) VALUES (?, ?, ?, ?, ?)",
                        [$name, $code, $category, $price, $desc]);
                    $newId = $db->lastInsertId();
                    $newRecord = $db->fetch("SELECT * FROM lab_tests WHERE id = ?", [$newId]);

                    logAudit('create', 'master_data', $tab, $newId, null, $newRecord, "Created Lab Test '{$name}' (ID: {$newId})");
                    setFlashMessage('success', "Lab test '{$name}' added.");
                } elseif ($action === 'edit' && $id) {
                    $oldRecord = $db->fetch("SELECT * FROM lab_tests WHERE id = ?", [$id]);
                    if (!$oldRecord) throw new Exception("Lab test not found.");

                    $db->query("UPDATE lab_tests SET name=?, code=?, category=?, price=?, description=? WHERE id=?",
                        [$name, $code, $category, $price, $desc, $id]);
                    $newRecord = $db->fetch("SELECT * FROM lab_tests WHERE id = ?", [$id]);

                    logAudit('update', 'master_data', $tab, $id, $oldRecord, $newRecord, "Updated Lab Test '{$name}' (ID: {$id})");
                    setFlashMessage('success', "Lab test '{$name}' updated.");
                } elseif ($action === 'toggle' && $id) {
                    $oldRecord = $db->fetch("SELECT * FROM lab_tests WHERE id = ?", [$id]);
                    if ($oldRecord) {
                        $newStatus = $oldRecord['is_active'] ? 0 : 1;
                        $db->query("UPDATE lab_tests SET is_active = ? WHERE id = ?", [$newStatus, $id]);
                        logAudit('toggle_status', 'master_data', $tab, $id, ['is_active' => $oldRecord['is_active']], ['is_active' => $newStatus], "Toggled status of Lab Test '{$oldRecord['name']}' to " . ($newStatus ? 'Active' : 'Inactive'));
                        setFlashMessage('success', 'Status toggled.');
                    }
                }
                break;

            case 'services':
                $name = sanitize($_POST['name'] ?? '');
                $category = sanitize($_POST['category'] ?? 'other');
                $price = floatval($_POST['default_price'] ?? 0);
                $desc = sanitize($_POST['description'] ?? '');

                if ($action === 'add') {
                    $db->query("INSERT INTO services (clinic_id, name, category, default_price, description) VALUES (?, ?, ?, ?, ?)",
                        [$clinicId, $name, $category, $price, $desc]);
                    $newId = $db->lastInsertId();
                    $newRecord = $db->fetch("SELECT * FROM services WHERE id = ? AND clinic_id = ?", [$newId, $clinicId]);

                    logAudit('create', 'master_data', $tab, $newId, null, $newRecord, "Created Service '{$name}' (ID: {$newId})");
                    setFlashMessage('success', "Service '{$name}' added.");
                } elseif ($action === 'edit' && $id) {
                    $oldRecord = $db->fetch("SELECT * FROM services WHERE id = ? AND clinic_id = ?", [$id, $clinicId]);
                    if (!$oldRecord) throw new Exception("Service not found.");

                    $db->query("UPDATE services SET name=?, category=?, default_price=?, description=? WHERE id=? AND clinic_id=?",
                        [$name, $category, $price, $desc, $id, $clinicId]);
                    $newRecord = $db->fetch("SELECT * FROM services WHERE id = ? AND clinic_id = ?", [$id, $clinicId]);

                    logAudit('update', 'master_data', $tab, $id, $oldRecord, $newRecord, "Updated Service '{$name}' (ID: {$id})");
                    setFlashMessage('success', "Service '{$name}' updated.");
                } elseif ($action === 'toggle' && $id) {
                    $oldRecord = $db->fetch("SELECT * FROM services WHERE id = ? AND clinic_id = ?", [$id, $clinicId]);
                    if ($oldRecord) {
                        $newStatus = $oldRecord['is_active'] ? 0 : 1;
                        $db->query("UPDATE services SET is_active = ? WHERE id = ? AND clinic_id = ?", [$newStatus, $id, $clinicId]);
                        logAudit('toggle_status', 'master_data', $tab, $id, ['is_active' => $oldRecord['is_active']], ['is_active' => $newStatus], "Toggled status of Service '{$oldRecord['name']}' to " . ($newStatus ? 'Active' : 'Inactive'));
                        setFlashMessage('success', 'Status toggled.');
                    }
                }
                break;
        }

        header("Location: " . BASE_URL . "/modules/admin/master_data.php?tab=$tab");
        exit;
    } catch (Exception $e) {
        if ($db && $action === 'bulk_import') {
            try { $db->rollBack(); } catch(Exception $re) {}
        }
        setFlashMessage('error', $e->getMessage());
        header("Location: " . BASE_URL . "/modules/admin/master_data.php?tab=$tab");
        exit;
    }
}

$pageTitle = 'Master Data';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';

// Fetch items for current tab
switch ($tab) {
    case 'departments':
        $items = $db->fetchAll("SELECT * FROM departments WHERE clinic_id = ? ORDER BY name", [$clinicId]);
        break;
    case 'specialties':
        $items = $db->fetchAll("SELECT * FROM specialties ORDER BY name");
        break;
    case 'medicines':
        $items = $db->fetchAll("SELECT * FROM medicines ORDER BY name");
        break;
    case 'diagnoses':
        $items = $db->fetchAll("SELECT * FROM diagnoses ORDER BY name");
        break;
    case 'lab_tests':
        $items = $db->fetchAll("SELECT * FROM lab_tests ORDER BY name");
        break;
    case 'services':
        $items = $db->fetchAll("SELECT * FROM services WHERE clinic_id = ? ORDER BY category, name", [$clinicId]);
        break;
}

// Fetch recent master data audit logs
try {
    $masterAuditLogs = $db->fetchAll(
        "SELECT a.*, u.full_name as user_name, u.role as user_role 
         FROM audit_logs a 
         LEFT JOIN users u ON a.user_id = u.id 
         WHERE (a.module = 'master_data' OR a.entity_type IN ('departments','specialties','medicines','diagnoses','lab_tests','services')) 
           AND (a.clinic_id = ? OR a.clinic_id IS NULL)
         ORDER BY a.created_at DESC 
         LIMIT 50",
        [$clinicId]
    );
} catch (Exception $e) {
    $masterAuditLogs = [];
}
?>

<div class="content-header">
    <div>
        <ul class="breadcrumb">
            <li><a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a></li>
            <li><a href="<?= BASE_URL ?>/modules/admin/index.php">Admin</a></li>
            <li>Master Data</li>
        </ul>
        <h1><i class="fas fa-database" style="color: var(--primary);"></i> Master Data Management</h1>
    </div>
    <div class="d-flex gap-8 flex-wrap align-center">
        <!-- Download Sample Template -->
        <a href="?tab=<?= $tab ?>&action=download_template" class="btn btn-outline" title="Download Excel/CSV template with sample rows">
            <i class="fas fa-download"></i> Sample Template
        </a>
        <!-- Bulk Import from Excel/CSV -->
        <button type="button" class="btn btn-outline" onclick="showBulkImportModal()" style="border-color: #107c41; color: #107c41; font-weight: 600;">
            <i class="fas fa-file-excel"></i> Bulk Import from Excel
        </button>
        <!-- View Audit Trail -->
        <button type="button" class="btn btn-outline" onclick="showMasterAuditModal()" title="View audit log for master data changes">
            <i class="fas fa-history" style="color: #6366f1;"></i> Audit Trail
            <?php if (!empty($masterAuditLogs)): ?>
            <span class="badge" style="background: #eef2ff; color: #4f46e5; margin-left: 4px; font-size: 11px;"><?= count($masterAuditLogs) ?></span>
            <?php endif; ?>
        </button>
        <!-- Add Single Item -->
        <button type="button" class="btn btn-primary" onclick="showMasterAddModal()">
            <i class="fas fa-plus"></i> Add <?= $tabConfig[$tab]['singular'] ?>
        </button>
    </div>
</div>

<!-- Tab Navigation -->
<div class="tabs mb-24" style="flex-wrap: wrap;">
    <?php foreach ($tabConfig as $key => $cfg): ?>
    <a href="?tab=<?= $key ?>" class="tab-btn <?= $tab === $key ? 'active' : '' ?>" style="text-decoration: none;">
        <i class="fas <?= $cfg['icon'] ?>" style="margin-right: 4px;"></i> <?= $cfg['label'] ?>
        <span class="badge" style="margin-left: 6px; font-size: 10px;"><?= $tab === $key ? count($items) : '' ?></span>
    </a>
    <?php endforeach; ?>
</div>

<!-- Table Card with Live Filter -->
<div class="card">
    <div class="card-header d-flex justify-between align-center flex-wrap gap-12" style="border-bottom: 1px solid var(--border-color); padding: 14px 20px;">
        <div class="d-flex align-center gap-12">
            <h3 style="margin: 0; font-size: 15px; font-weight: 700;">
                <i class="fas <?= $tabConfig[$tab]['icon'] ?>" style="color: <?= $tabConfig[$tab]['color'] ?>; margin-right: 6px;"></i>
                <?= $tabConfig[$tab]['label'] ?> List
            </h3>
            <span class="badge badge-secondary" id="recordCountBadge"><?= count($items) ?> Records</span>
        </div>
        <div class="d-flex align-center gap-8">
            <div style="position: relative; width: 260px;">
                <i class="fas fa-search" style="position: absolute; left: 10px; top: 10px; color: var(--text-muted); font-size: 13px;"></i>
                <input type="text" id="tableFilterInput" class="form-control" placeholder="Search <?= strtolower($tabConfig[$tab]['label']) ?>..." onkeyup="filterMasterTable()" style="padding-left: 32px; height: 34px; font-size: 13px;">
            </div>
        </div>
    </div>
    <div class="card-body" style="overflow-x: auto; padding: 0;">
        <table class="table" id="masterDataTable" style="margin: 0;">
            <thead>
                <tr>
                    <th style="width: 50px;">#</th>
                    <th>Name</th>
                    <?php if ($tab === 'specialties'): ?><th>Code</th><?php endif; ?>
                    <?php if ($tab === 'medicines'): ?><th>Generic Name</th><th>Brand</th><th>Form</th><th>Strength</th><?php endif; ?>
                    <?php if ($tab === 'diagnoses'): ?><th>ICD Code</th><th>Category</th><?php endif; ?>
                    <?php if ($tab === 'lab_tests'): ?><th>Code</th><th>Category</th><th>Price</th><?php endif; ?>
                    <?php if ($tab === 'services'): ?><th>Category</th><th>Default Price</th><th>Description</th><?php endif; ?>
                    <?php if ($tab === 'departments'): ?><th>Description</th><?php endif; ?>
                    <th style="width: 100px;">Status</th>
                    <th style="text-align: right; width: 110px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                <tr id="emptyRow"><td colspan="10" class="text-center text-muted" style="padding: 40px;">No <?= strtolower($tabConfig[$tab]['label']) ?> found. Click "Add" or "Bulk Import" to create.</td></tr>
                <?php else: ?>
                <?php foreach ($items as $i => $item): ?>
                <tr class="master-row">
                    <td class="text-muted row-index"><?= $i + 1 ?></td>
                    <td class="font-semibold col-search"><?= sanitizeOutput($item['name']) ?></td>

                    <?php if ($tab === 'specialties'): ?>
                    <td class="col-search"><code><?= sanitizeOutput($item['code'] ?? '-') ?></code></td>
                    <?php endif; ?>

                    <?php if ($tab === 'medicines'): ?>
                    <td class="col-search"><?= sanitizeOutput($item['generic_name'] ?? '-') ?></td>
                    <td class="col-search"><?= sanitizeOutput($item['brand'] ?? '-') ?></td>
                    <td><span class="badge badge-primary"><?= sanitizeOutput($item['dosage_form'] ?? '-') ?></span></td>
                    <td class="col-search"><?= sanitizeOutput($item['strength'] ?? '-') ?></td>
                    <?php endif; ?>

                    <?php if ($tab === 'diagnoses'): ?>
                    <td class="col-search"><code><?= sanitizeOutput($item['icd_code'] ?? '-') ?></code></td>
                    <td class="col-search"><?= sanitizeOutput($item['category'] ?? '-') ?></td>
                    <?php endif; ?>

                    <?php if ($tab === 'lab_tests'): ?>
                    <td class="col-search"><code><?= sanitizeOutput($item['code'] ?? '-') ?></code></td>
                    <td class="col-search"><?= sanitizeOutput($item['category'] ?? '-') ?></td>
                    <td>₹ <?= number_format($item['price'] ?? 0, 2) ?></td>
                    <?php endif; ?>

                    <?php if ($tab === 'services'): ?>
                    <td><span class="badge badge-primary"><?= $serviceTypes[$item['category']] ?? ucfirst(str_replace('_',' ',$item['category'])) ?></span></td>
                    <td>₹ <?= number_format($item['default_price'] ?? 0, 2) ?></td>
                    <td class="text-muted col-search"><?= sanitizeOutput(mb_strimwidth($item['description'] ?? '-', 0, 40, '...')) ?></td>
                    <?php endif; ?>

                    <?php if ($tab === 'departments'): ?>
                    <td class="text-muted col-search"><?= sanitizeOutput(mb_strimwidth($item['description'] ?? '-', 0, 60, '...')) ?></td>
                    <?php endif; ?>

                    <td>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= $item['id'] ?>">
                            <button type="submit" class="badge <?= $item['is_active'] ? 'badge-success' : 'badge-danger' ?>" style="cursor:pointer; border:none;" title="Click to toggle status">
                                <?= $item['is_active'] ? 'Active' : 'Inactive' ?>
                            </button>
                        </form>
                    </td>
                    <td style="text-align: right; white-space: nowrap;">
                        <button type="button" class="btn btn-sm btn-outline" title="Edit" onclick='showMasterEditModal(<?= json_encode($item) ?>)'>
                            <i class="fas fa-pen"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline text-danger" title="Delete record" onclick='confirmDeleteMaster(<?= $item['id'] ?>, <?= json_encode($item['name']) ?>)' style="margin-left: 4px; border-color: #fecaca; color: #dc2626;">
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

<!-- ============================================ -->
<!-- 1. ADD / EDIT RECORD MODAL                   -->
<!-- ============================================ -->
<div id="masterModal" class="modal-overlay">
    <div class="modal" style="max-width: 540px;">
        <div class="modal-header">
            <h3 id="modalTitle" style="margin: 0;"><i class="fas <?= $tabConfig[$tab]['icon'] ?>" style="color: <?= $tabConfig[$tab]['color'] ?>; margin-right: 6px;"></i> <span id="modalAction">Add</span> <?= $tabConfig[$tab]['singular'] ?></h3>
            <button type="button" class="modal-close" onclick="hideMasterModal()">&times;</button>
        </div>
        <form method="POST" id="masterForm">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="formId" value="">
            <div class="modal-body" style="padding: 20px 24px; max-height: 70vh; overflow-y: auto;">

                <!-- Common: Name -->
                <div class="form-group mb-16">
                    <label class="form-label font-semibold">Name <span class="required text-danger">*</span></label>
                    <input type="text" name="name" id="f_name" class="form-control" required placeholder="Enter <?= strtolower($tabConfig[$tab]['singular']) ?> name">
                </div>

                <?php if ($tab === 'specialties'): ?>
                <div class="form-group mb-16">
                    <label class="form-label font-semibold">Code</label>
                    <input type="text" name="code" id="f_code" class="form-control" placeholder="e.g. CARDIO">
                </div>
                <div class="form-group mb-16">
                    <label class="form-label font-semibold">Description</label>
                    <textarea name="description" id="f_description" class="form-control" rows="3" placeholder="Brief clinical details..."></textarea>
                </div>
                <?php endif; ?>

                <?php if ($tab === 'departments'): ?>
                <div class="form-group mb-16">
                    <label class="form-label font-semibold">Description</label>
                    <textarea name="description" id="f_description" class="form-control" rows="3" placeholder="Department overview and purpose..."></textarea>
                </div>
                <?php endif; ?>

                <?php if ($tab === 'medicines'): ?>
                <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px;">
                    <div class="form-group">
                        <label class="form-label font-semibold">Generic Name</label>
                        <input type="text" name="generic_name" id="f_generic_name" class="form-control" placeholder="e.g. Paracetamol">
                    </div>
                    <div class="form-group">
                        <label class="form-label font-semibold">Brand / Manufacturer</label>
                        <input type="text" name="brand" id="f_brand" class="form-control" placeholder="e.g. Crocin / GSK">
                    </div>
                </div>
                <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px;">
                    <div class="form-group">
                        <label class="form-label font-semibold">Dosage Form</label>
                        <select name="dosage_form" id="f_dosage_form" class="form-control">
                            <?php foreach ($dosageForms as $df): ?>
                            <option value="<?= $df ?>"><?= $df ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label font-semibold">Strength</label>
                        <input type="text" name="strength" id="f_strength" class="form-control" placeholder="e.g. 500mg, 5ml">
                    </div>
                </div>
                <div class="form-group mb-16">
                    <label class="form-label font-semibold">Category</label>
                    <input type="text" name="category" id="f_category" class="form-control" placeholder="e.g. Analgesic, Antibiotic">
                </div>
                <?php endif; ?>

                <?php if ($tab === 'diagnoses'): ?>
                <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px;">
                    <div class="form-group">
                        <label class="form-label font-semibold">ICD Code</label>
                        <input type="text" name="icd_code" id="f_icd_code" class="form-control" placeholder="e.g. J06.9">
                    </div>
                    <div class="form-group">
                        <label class="form-label font-semibold">Category</label>
                        <input type="text" name="category" id="f_category" class="form-control" placeholder="e.g. Respiratory">
                    </div>
                </div>
                <div class="form-group mb-16">
                    <label class="form-label font-semibold">Description</label>
                    <textarea name="description" id="f_description" class="form-control" rows="3" placeholder="Diagnostic criteria or clinical notes..."></textarea>
                </div>
                <?php endif; ?>

                <?php if ($tab === 'lab_tests'): ?>
                <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px;">
                    <div class="form-group">
                        <label class="form-label font-semibold">Code</label>
                        <input type="text" name="code" id="f_code" class="form-control" placeholder="e.g. CBC">
                    </div>
                    <div class="form-group">
                        <label class="form-label font-semibold">Category</label>
                        <input type="text" name="category" id="f_category" class="form-control" placeholder="e.g. Hematology">
                    </div>
                </div>
                <div class="form-group mb-16">
                    <label class="form-label font-semibold">Price (₹)</label>
                    <input type="number" name="price" id="f_price" class="form-control" step="0.01" min="0" value="0">
                </div>
                <div class="form-group mb-16">
                    <label class="form-label font-semibold">Description</label>
                    <textarea name="description" id="f_description" class="form-control" rows="3" placeholder="Test procedure or specimen requirements..."></textarea>
                </div>
                <?php endif; ?>

                <?php if ($tab === 'services'): ?>
                <div class="form-group mb-16">
                    <label class="form-label font-semibold">Category <span class="required text-danger">*</span></label>
                    <input type="text" name="category" id="f_category" class="form-control" required list="categoryList" placeholder="Select or type new category">
                    <datalist id="categoryList">
                        <?php foreach ($serviceTypes as $k => $v): ?>
                        <option value="<?= $k ?>"><?= $v ?></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="form-group mb-16">
                    <label class="form-label font-semibold">Default Price (₹)</label>
                    <input type="number" name="default_price" id="f_default_price" class="form-control" step="0.01" min="0" value="0">
                </div>
                <div class="form-group mb-16">
                    <label class="form-label font-semibold">Description</label>
                    <textarea name="description" id="f_description" class="form-control" rows="3" placeholder="Service billing notes..."></textarea>
                </div>
                <?php endif; ?>

            </div>
            <div class="modal-footer" style="padding: 16px 24px; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; gap: 12px;">
                <button type="button" class="btn btn-outline" onclick="hideMasterModal()">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save <?= $tabConfig[$tab]['singular'] ?></button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================ -->
<!-- 2. DELETE CONFIRMATION MODAL                 -->
<!-- ============================================ -->
<div id="deleteModal" class="modal-overlay">
    <div class="modal" style="max-width: 440px;">
        <div class="modal-header" style="background: #fef2f2; border-bottom: 1px solid #fee2e2;">
            <h3 style="color: #991b1b; margin: 0; display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-exclamation-triangle"></i> Delete <?= $tabConfig[$tab]['singular'] ?>
            </h3>
            <button type="button" class="modal-close" onclick="hideDeleteModal()">&times;</button>
        </div>
        <form method="POST" id="deleteForm">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="deleteRecordId" value="">
            <div class="modal-body" style="padding: 20px 24px;">
                <p style="margin-top: 0; font-size: 14px; color: var(--text-primary);">
                    Are you sure you want to delete <strong id="deleteRecordName" style="color: #b91c1c;"></strong>?
                </p>
                <div style="background: #fffbeb; border: 1px solid #fef3c7; border-radius: 8px; padding: 12px; font-size: 12px; color: #92400e; display: flex; gap: 8px; align-items: flex-start;">
                    <i class="fas fa-shield-alt" style="margin-top: 2px;"></i>
                    <div>
                        <strong>Audit Protection:</strong> This deletion will be permanently recorded in the system audit log with your user details, timestamp, and previous values.
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="padding: 14px 24px; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-outline" onclick="hideDeleteModal()">Cancel</button>
                <button type="submit" class="btn btn-danger" style="background: #dc2626; border-color: #b91c1c; color: white;">
                    <i class="fas fa-trash"></i> Delete Record
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================ -->
<!-- 3. BULK EXCEL / CSV IMPORT MODAL             -->
<!-- ============================================ -->
<div id="bulkImportModal" class="modal-overlay">
    <div class="modal modal-lg" style="max-width: 820px; max-height: 90vh;">
        <div class="modal-header" style="background: #f0fdf4; border-bottom: 1px solid #dcfce7;">
            <h3 style="color: #166534; margin: 0; display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-file-excel" style="color: #107c41;"></i> Bulk Import <?= $tabConfig[$tab]['label'] ?> from Excel / CSV
            </h3>
            <button type="button" class="modal-close" onclick="hideBulkImportModal()">&times;</button>
        </div>
        <form method="POST" id="bulkImportForm" enctype="multipart/form-data">
            <input type="hidden" name="action" value="bulk_import">
            <input type="hidden" name="records_json" id="bulkRecordsJson" value="">
            
            <div class="modal-body" style="padding: 20px 24px; max-height: 65vh; overflow-y: auto;">
                
                <!-- Step 1: Download Template Helper -->
                <div class="d-flex justify-between align-center flex-wrap gap-12 mb-16" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 16px;">
                    <div>
                        <div class="font-semibold" style="font-size: 13.5px; color: #1e293b;">Need the template format?</div>
                        <div class="text-muted" style="font-size: 12px;">Download pre-formatted Excel/CSV template with sample headers and data.</div>
                    </div>
                    <a href="?tab=<?= $tab ?>&action=download_template" class="btn btn-sm btn-outline" style="border-color: #cbd5e1; background: white;">
                        <i class="fas fa-download"></i> Download <?= $tabConfig[$tab]['singular'] ?> Template
                    </a>
                </div>

                <!-- Step 2: Drag & Drop Zone -->
                <div id="dropZone" class="mb-20" style="border: 2px dashed #94a3b8; border-radius: 12px; padding: 28px 20px; text-align: center; background: #fafafa; cursor: pointer; transition: all 0.2s ease;">
                    <i class="fas fa-cloud-upload-alt" style="font-size: 38px; color: #107c41; margin-bottom: 10px;"></i>
                    <div style="font-weight: 600; font-size: 15px; color: #334155; margin-bottom: 4px;">Choose an Excel (.xlsx, .xls) or CSV (.csv) file</div>
                    <div style="font-size: 12.5px; color: #64748b; margin-bottom: 14px;">or drag and drop your file here</div>
                    <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('bulkFileInput').click()" style="background: white;">
                        <i class="fas fa-folder-open"></i> Browse File
                    </button>
                    <input type="file" id="bulkFileInput" name="import_file" accept=".xlsx, .xls, .csv, text/csv, application/vnd.openxmlformats-officedocument.spreadsheetml.sheet, application/vnd.ms-excel" style="display: none;" onchange="handleBulkFileSelect(event)">
                </div>

                <!-- Duplicate Handling Option -->
                <div class="form-group mb-20" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 16px;">
                    <label class="form-label font-semibold" style="font-size: 13px; margin-bottom: 6px;">Duplicate Handling (Matching Name):</label>
                    <div class="d-flex gap-20 flex-wrap" style="font-size: 13px;">
                        <label style="cursor: pointer; display: flex; align-items: center; gap: 6px;">
                            <input type="radio" name="duplicate_action" value="skip" checked> Skip existing records (recommended)
                        </label>
                        <label style="cursor: pointer; display: flex; align-items: center; gap: 6px;">
                            <input type="radio" name="duplicate_action" value="update"> Update existing records with new data
                        </label>
                    </div>
                </div>

                <!-- Step 3: Live Preview Area (Dynamic SheetJS) -->
                <div id="importPreviewArea" style="display: none;">
                    <div class="d-flex justify-between align-center flex-wrap gap-12 mb-10">
                        <div class="d-flex gap-8 align-center flex-wrap">
                            <span class="badge badge-primary" id="previewFileBadge"><i class="fas fa-file"></i> <span id="previewFileName"></span></span>
                            <span class="badge badge-success" id="previewValidBadge"><i class="fas fa-check-circle"></i> <span id="previewValidCount">0</span> Valid to Import</span>
                            <span class="badge badge-warning" id="previewSkippedBadge" style="display: none;"><i class="fas fa-exclamation-triangle"></i> <span id="previewSkippedCount">0</span> Skipped (No Name)</span>
                        </div>
                        <div class="text-muted" style="font-size: 12px;">Showing first 5 rows preview</div>
                    </div>

                    <div style="border: 1px solid #e2e8f0; border-radius: 8px; overflow-x: auto; max-height: 250px;">
                        <table class="table" style="font-size: 12px; margin: 0;">
                            <thead style="background: #f1f5f9;" id="previewTableHead">
                                <!-- Populated dynamically -->
                            </thead>
                            <tbody id="previewTableBody">
                                <!-- Populated dynamically -->
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
            <div class="modal-footer" style="padding: 14px 24px; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; gap: 12px;">
                <button type="button" class="btn btn-outline" onclick="hideBulkImportModal()">Cancel</button>
                <button type="submit" class="btn btn-success" id="btnSubmitBulk" disabled style="background: #107c41; border-color: #0e6b37; color: white;">
                    <i class="fas fa-file-import"></i> <span id="btnSubmitBulkText">Import Records</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================ -->
<!-- 4. MASTER DATA AUDIT TRAIL MODAL             -->
<!-- ============================================ -->
<div id="auditModal" class="modal-overlay">
    <div class="modal modal-xl" style="max-width: 950px; max-height: 90vh;">
        <div class="modal-header" style="background: #faf5ff; border-bottom: 1px solid #f3e8ff;">
            <div class="d-flex align-center gap-10">
                <i class="fas fa-history" style="color: #7c3aed; font-size: 18px;"></i>
                <div>
                    <h3 style="color: #581c87; margin: 0; font-size: 16px;">Master Data Audit Trail</h3>
                    <div style="font-size: 11.5px; color: #7e22ce;">Tracked records of additions, updates, deletions, and bulk imports</div>
                </div>
            </div>
            <button type="button" class="modal-close" onclick="hideMasterAuditModal()">&times;</button>
        </div>
        <div class="modal-body" style="padding: 0; max-height: 70vh; overflow-y: auto;">
            <?php if (empty($masterAuditLogs)): ?>
            <div class="text-center text-muted" style="padding: 60px 20px;">
                <i class="fas fa-clipboard-check" style="font-size: 40px; color: #cbd5e1; margin-bottom: 12px;"></i>
                <div>No audit logs recorded yet for master data.</div>
            </div>
            <?php else: ?>
            <table class="table" style="font-size: 12.5px; margin: 0;">
                <thead style="background: #f8fafc; position: sticky; top: 0; z-index: 10;">
                    <tr>
                        <th style="width: 140px;">Timestamp</th>
                        <th style="width: 130px;">User</th>
                        <th style="width: 110px;">Action</th>
                        <th style="width: 110px;">Entity</th>
                        <th>Details</th>
                        <th style="width: 80px; text-align: right;">Changes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($masterAuditLogs as $log): ?>
                    <?php
                    $actionBadge = 'badge-secondary';
                    $actionColor = '#64748b';
                    $actionIcon = 'fa-info-circle';
                    switch ($log['action']) {
                        case 'create':
                            $actionBadge = 'badge-success';
                            $actionIcon = 'fa-plus-circle';
                            break;
                        case 'update':
                            $actionBadge = 'badge-info';
                            $actionIcon = 'fa-pen';
                            break;
                        case 'delete':
                            $actionBadge = 'badge-danger';
                            $actionIcon = 'fa-trash';
                            break;
                        case 'bulk_import':
                            $actionBadge = 'badge-primary';
                            $actionIcon = 'fa-file-excel';
                            break;
                        case 'toggle_status':
                            $actionBadge = 'badge-warning';
                            $actionIcon = 'fa-toggle-on';
                            break;
                    }
                    ?>
                    <tr>
                        <td class="text-muted" style="white-space: nowrap;">
                            <?= date('d M Y, h:i A', strtotime($log['created_at'])) ?>
                        </td>
                        <td>
                            <div class="font-semibold" style="color: #0f172a;"><?= sanitizeOutput($log['user_name'] ?? 'System') ?></div>
                            <div class="text-muted" style="font-size: 10.5px; text-transform: capitalize;"><?= sanitizeOutput($log['user_role'] ?? 'Admin') ?></div>
                        </td>
                        <td>
                            <span class="badge <?= $actionBadge ?>" style="font-size: 11px; text-transform: capitalize; display: inline-flex; align-items: center; gap: 4px;">
                                <i class="fas <?= $actionIcon ?>"></i> <?= str_replace('_', ' ', $log['action']) ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge badge-secondary" style="font-size: 11px; text-transform: capitalize;">
                                <?= sanitizeOutput($log['entity_type'] ?? 'Master') ?>
                            </span>
                        </td>
                        <td style="color: #334155;">
                            <?= sanitizeOutput($log['description'] ?? '-') ?>
                        </td>
                        <td style="text-align: right;">
                            <?php if (!empty($log['old_values']) || !empty($log['new_values'])): ?>
                            <button type="button" class="btn btn-xs btn-ghost" onclick="toggleAuditRow(<?= $log['id'] ?>)" title="View change details" style="font-size: 11px;">
                                <i class="fas fa-code"></i> Diff
                            </button>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if (!empty($log['old_values']) || !empty($log['new_values'])): ?>
                    <tr id="auditDiffRow_<?= $log['id'] ?>" style="display: none; background: #f8fafc;">
                        <td colspan="6" style="padding: 12px 20px;">
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; font-size: 11.5px;">
                                <?php if (!empty($log['old_values'])): ?>
                                <div style="background: #fff1f2; border: 1px solid #fecdd3; border-radius: 6px; padding: 10px;">
                                    <div class="font-semibold" style="color: #9f1239; margin-bottom: 6px;"><i class="fas fa-history"></i> Previous Values (Before)</div>
                                    <pre style="margin: 0; white-space: pre-wrap; font-family: monospace; font-size: 11px; color: #881337;"><?= sanitizeOutput(json_encode(json_decode($log['old_values']), JSON_PRETTY_PRINT)) ?></pre>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($log['new_values'])): ?>
                                <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 10px;">
                                    <div class="font-semibold" style="color: #166534; margin-bottom: 6px;"><i class="fas fa-check"></i> New Values (After)</div>
                                    <pre style="margin: 0; white-space: pre-wrap; font-family: monospace; font-size: 11px; color: #14532d;"><?= sanitizeOutput(json_encode(json_decode($log['new_values']), JSON_PRETTY_PRINT)) ?></pre>
                                </div>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <div class="modal-footer" style="padding: 12px 24px; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end;">
            <button type="button" class="btn btn-outline" onclick="hideMasterAuditModal()">Close</button>
        </div>
    </div>
</div>

<!-- Load SheetJS for local Excel (.xlsx, .xls) and CSV parsing -->
<script src="<?= ASSETS_URL ?>/js/xlsx.full.min.js"></script>

<script>
// Active tab field configuration for client-side normalization
const activeTabConfig = <?= json_encode($tabConfig[$tab]) ?>;
let parsedBulkRows = [];

// ----------------------------------------------------
// ADD / EDIT MODAL CONTROLS
// ----------------------------------------------------
function showMasterAddModal() {
    document.getElementById('formAction').value = 'add';
    document.getElementById('formId').value = '';
    const titleAction = document.getElementById('modalAction');
    if (titleAction) titleAction.textContent = 'Add';
    document.getElementById('masterForm').reset();
    document.getElementById('masterModal').classList.add('active');
}

function showMasterEditModal(item) {
    document.getElementById('formAction').value = 'edit';
    document.getElementById('formId').value = item.id;
    const titleAction = document.getElementById('modalAction');
    if (titleAction) titleAction.textContent = 'Edit';

    const fields = ['name', 'code', 'description', 'generic_name', 'brand', 'dosage_form', 'strength', 'category', 'icd_code', 'price', 'default_price'];
    fields.forEach(f => {
        const el = document.getElementById('f_' + f);
        if (el) el.value = item[f] !== null && item[f] !== undefined ? item[f] : '';
    });

    document.getElementById('masterModal').classList.add('active');
}

function hideMasterModal() {
    document.getElementById('masterModal').classList.remove('active');
}

// ----------------------------------------------------
// DELETE MODAL CONTROLS
// ----------------------------------------------------
function confirmDeleteMaster(id, name) {
    document.getElementById('deleteRecordId').value = id;
    document.getElementById('deleteRecordName').textContent = name || 'this record';
    document.getElementById('deleteModal').classList.add('active');
}

function hideDeleteModal() {
    document.getElementById('deleteModal').classList.remove('active');
}

// ----------------------------------------------------
// AUDIT TRAIL MODAL CONTROLS
// ----------------------------------------------------
function showMasterAuditModal() {
    document.getElementById('auditModal').classList.add('active');
}

function hideMasterAuditModal() {
    document.getElementById('auditModal').classList.remove('active');
}

function toggleAuditRow(id) {
    const el = document.getElementById('auditDiffRow_' + id);
    if (el) {
        el.style.display = el.style.display === 'none' ? 'table-row' : 'none';
    }
}

// ----------------------------------------------------
// BULK IMPORT MODAL & EXCEL PARSER
// ----------------------------------------------------
function showBulkImportModal() {
    parsedBulkRows = [];
    document.getElementById('bulkRecordsJson').value = '';
    document.getElementById('bulkFileInput').value = '';
    document.getElementById('importPreviewArea').style.display = 'none';
    document.getElementById('btnSubmitBulk').disabled = true;
    document.getElementById('btnSubmitBulkText').textContent = 'Import Records';
    document.getElementById('bulkImportModal').classList.add('active');
}

function hideBulkImportModal() {
    document.getElementById('bulkImportModal').classList.remove('active');
}

// Drag & drop zone highlight
const dropZone = document.getElementById('dropZone');
if (dropZone) {
    ['dragenter', 'dragover'].forEach(eventName => {
        dropZone.addEventListener(eventName, e => {
            e.preventDefault();
            e.stopPropagation();
            dropZone.style.borderColor = '#107c41';
            dropZone.style.background = '#f0fdf4';
        }, false);
    });

    ['dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, e => {
            e.preventDefault();
            e.stopPropagation();
            dropZone.style.borderColor = '#94a3b8';
            dropZone.style.background = '#fafafa';
        }, false);
    });

    dropZone.addEventListener('drop', e => {
        const dt = e.dataTransfer;
        const files = dt.files;
        if (files && files.length > 0) {
            processSelectedFile(files[0]);
        }
    });
}

function handleBulkFileSelect(e) {
    if (e.target.files && e.target.files.length > 0) {
        processSelectedFile(e.target.files[0]);
    }
}

// Normalizes header text for matching
function normalizeKey(str) {
    if (!str) return '';
    return str.toString().trim().toLowerCase().replace(/[^a-z0-9]/g, '');
}

function processSelectedFile(file) {
    const fileName = file.name;
    const fileExt = fileName.split('.').pop().toLowerCase();

    if (!['xlsx', 'xls', 'csv'].includes(fileExt)) {
        alert('Please select an Excel (.xlsx, .xls) or CSV (.csv) file.');
        return;
    }

    const reader = new FileReader();
    reader.onload = function(e) {
        try {
            const data = new Uint8Array(e.target.result);
            const workbook = XLSX.read(data, { type: 'array' });
            const firstSheetName = workbook.SheetNames[0];
            const worksheet = workbook.Sheets[firstSheetName];
            const rawJson = XLSX.utils.sheet_to_json(worksheet, { defval: '' });

            if (!rawJson || rawJson.length === 0) {
                alert('No data found in worksheet. Please check your file.');
                return;
            }

            // Map columns using field config & aliases
            const fields = activeTabConfig.fields;
            const validRecords = [];
            let skippedCount = 0;

            rawJson.forEach(row => {
                const mappedRow = {};
                // Inspect all keys in row
                Object.keys(row).forEach(rawCol => {
                    const normCol = normalizeKey(rawCol);
                    // Match against fields
                    Object.keys(fields).forEach(fKey => {
                        const fInfo = fields[fKey];
                        const normLabel = normalizeKey(fInfo.label);
                        const normKey = normalizeKey(fKey);

                        if (normCol === normLabel || normCol === normKey) {
                            mappedRow[fKey] = row[rawCol];
                            return;
                        }

                        if (fInfo.aliases) {
                            for (let a of fInfo.aliases) {
                                if (normCol === normalizeKey(a)) {
                                    mappedRow[fKey] = row[rawCol];
                                    break;
                                }
                            }
                        }
                    });
                });

                if (mappedRow.name && String(mappedRow.name).trim() !== '') {
                    mappedRow.name = String(mappedRow.name).trim();
                    validRecords.push(mappedRow);
                } else {
                    skippedCount++;
                }
            });

            parsedBulkRows = validRecords;
            document.getElementById('bulkRecordsJson').value = JSON.stringify(validRecords);

            // Update UI preview
            renderBulkPreview(fileName, validRecords, skippedCount);

        } catch (err) {
            console.error('Error parsing file:', err);
            alert('Failed to read file: ' + err.message);
        }
    };
    reader.readAsArrayBuffer(file);
}

function renderBulkPreview(fileName, records, skippedCount) {
    document.getElementById('previewFileName').textContent = fileName;
    document.getElementById('previewValidCount').textContent = records.length;
    document.getElementById('previewSkippedCount').textContent = skippedCount;

    const skippedBadge = document.getElementById('previewSkippedBadge');
    if (skippedCount > 0) {
        skippedBadge.style.display = 'inline-flex';
    } else {
        skippedBadge.style.display = 'none';
    }

    // Build Table Header
    const fields = activeTabConfig.fields;
    const thead = document.getElementById('previewTableHead');
    let headHtml = '<tr><th style="width: 40px;">#</th>';
    Object.keys(fields).forEach(fKey => {
        headHtml += '<th>' + fields[fKey].label + '</th>';
    });
    headHtml += '</tr>';
    thead.innerHTML = headHtml;

    // Build Table Body (preview first 5)
    const tbody = document.getElementById('previewTableBody');
    let bodyHtml = '';
    const previewList = records.slice(0, 5);

    if (previewList.length === 0) {
        bodyHtml = '<tr><td colspan="' + (Object.keys(fields).length + 1) + '" class="text-center text-danger" style="padding: 20px;">No valid records with a "Name" column found in this file.</td></tr>';
    } else {
        previewList.forEach((r, idx) => {
            bodyHtml += '<tr><td class="text-muted">' + (idx + 1) + '</td>';
            Object.keys(fields).forEach(fKey => {
                const val = r[fKey] !== undefined ? r[fKey] : '';
                bodyHtml += '<td>' + (escapeHtml(String(val)) || '<span class="text-muted">-</span>') + '</td>';
            });
            bodyHtml += '</tr>';
        });
    }
    tbody.innerHTML = bodyHtml;

    document.getElementById('importPreviewArea').style.display = 'block';

    const submitBtn = document.getElementById('btnSubmitBulk');
    if (records.length > 0) {
        submitBtn.disabled = false;
        document.getElementById('btnSubmitBulkText').textContent = 'Import ' + records.length + ' ' + activeTabConfig.label;
    } else {
        submitBtn.disabled = true;
        document.getElementById('btnSubmitBulkText').textContent = 'Import Records';
    }
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// ----------------------------------------------------
// LIVE TABLE SEARCH FILTER
// ----------------------------------------------------
function filterMasterTable() {
    const input = document.getElementById('tableFilterInput');
    const filter = input.value.toLowerCase().trim();
    const rows = document.querySelectorAll('.master-row');
    let visibleCount = 0;

    rows.forEach(row => {
        const text = row.innerText.toLowerCase();
        if (text.indexOf(filter) > -1) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    const badge = document.getElementById('recordCountBadge');
    if (badge) {
        badge.textContent = visibleCount + (filter ? ' Matching' : ' Records');
    }
}

// Close modals on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        hideMasterModal();
        hideDeleteModal();
        hideBulkImportModal();
        hideMasterAuditModal();
    }
});

// Close modals on overlay backdrop click
document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.remove('active');
        }
    });
});
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
