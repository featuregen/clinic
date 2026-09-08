-- ============================================
-- Advanced Clinic Suite - Database Schema
-- Version: 1.0.0
-- ============================================

-- ============================================
-- 1. CLINIC & BRANCH MANAGEMENT
-- ============================================

CREATE TABLE IF NOT EXISTS clinics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    tagline VARCHAR(300) DEFAULT NULL,
    logo VARCHAR(500) DEFAULT NULL,
    email VARCHAR(150) DEFAULT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    alt_phone VARCHAR(20) DEFAULT NULL,
    website VARCHAR(200) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    city VARCHAR(100) DEFAULT NULL,
    state VARCHAR(100) DEFAULT NULL,
    pincode VARCHAR(10) DEFAULT NULL,
    country VARCHAR(100) DEFAULT 'India',
    registration_no VARCHAR(100) DEFAULT NULL,
    gst_number VARCHAR(20) DEFAULT NULL,
    pan_number VARCHAR(20) DEFAULT NULL,
    primary_color VARCHAR(10) DEFAULT '#4F46E5',
    secondary_color VARCHAR(10) DEFAULT '#7C3AED',
    currency_symbol VARCHAR(5) DEFAULT '₹',
    date_format VARCHAR(20) DEFAULT 'd/m/Y',
    time_format VARCHAR(10) DEFAULT '12h',
    timezone VARCHAR(50) DEFAULT 'Asia/Kolkata',
    prescription_header TEXT DEFAULT NULL,
    prescription_footer TEXT DEFAULT NULL,
    invoice_header TEXT DEFAULT NULL,
    invoice_footer TEXT DEFAULT NULL,
    invoice_prefix VARCHAR(10) DEFAULT 'INV',
    invoice_start_no INT DEFAULT 1,
    patient_id_prefix VARCHAR(10) DEFAULT 'PT',
    patient_id_start_no INT DEFAULT 1000,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS branches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    clinic_id INT NOT NULL,
    name VARCHAR(200) NOT NULL,
    code VARCHAR(20) DEFAULT NULL,
    email VARCHAR(150) DEFAULT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    city VARCHAR(100) DEFAULT NULL,
    state VARCHAR(100) DEFAULT NULL,
    pincode VARCHAR(10) DEFAULT NULL,
    working_hours_start TIME DEFAULT '09:00:00',
    working_hours_end TIME DEFAULT '21:00:00',
    working_days VARCHAR(50) DEFAULT '1,2,3,4,5,6',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================
-- 2. ROLES & PERMISSIONS (RBAC)
-- ============================================

CREATE TABLE IF NOT EXISTS roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    display_name VARCHAR(100) NOT NULL,
    description TEXT DEFAULT NULL,
    is_system TINYINT(1) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    module VARCHAR(50) NOT NULL,
    name VARCHAR(100) NOT NULL UNIQUE,
    display_name VARCHAR(150) NOT NULL,
    description TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS role_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NOT NULL,
    permission_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE,
    UNIQUE KEY unique_role_permission (role_id, permission_id)
) ENGINE=InnoDB;

-- ============================================
-- 3. SPECIALTIES & DEPARTMENTS
-- ============================================

CREATE TABLE IF NOT EXISTS specialties (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(20) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    icon VARCHAR(50) DEFAULT NULL,
    has_custom_form TINYINT(1) DEFAULT 0,
    form_config JSON DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    clinic_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT DEFAULT NULL,
    head_doctor_id INT DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================
-- 4. USERS (ALL STAFF)
-- ============================================

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    clinic_id INT NOT NULL DEFAULT 1,
    branch_id INT DEFAULT NULL,
    role_id INT NOT NULL,
    role VARCHAR(30) NOT NULL DEFAULT 'receptionist',
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(150) DEFAULT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    gender ENUM('Male','Female','Other') DEFAULT NULL,
    date_of_birth DATE DEFAULT NULL,
    profile_image VARCHAR(500) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    qualification VARCHAR(200) DEFAULT NULL,
    specialization VARCHAR(200) DEFAULT NULL,
    license_number VARCHAR(100) DEFAULT NULL,
    signature_image VARCHAR(500) DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    last_login TIMESTAMP NULL DEFAULT NULL,
    failed_attempts INT DEFAULT 0,
    locked_until TIMESTAMP NULL DEFAULT NULL,
    password_changed_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (clinic_id) REFERENCES clinics(id),
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB;

-- ============================================
-- 5. DOCTOR PROFILES
-- ============================================

CREATE TABLE IF NOT EXISTS doctors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    clinic_id INT NOT NULL DEFAULT 1,
    specialty_id INT DEFAULT NULL,
    department_id INT DEFAULT NULL,
    registration_number VARCHAR(100) DEFAULT NULL,
    consultation_fee DECIMAL(10,2) DEFAULT 0.00,
    followup_fee DECIMAL(10,2) DEFAULT 0.00,
    experience_years INT DEFAULT 0,
    bio TEXT DEFAULT NULL,
    default_slot_duration INT DEFAULT 15,
    max_patients_per_slot INT DEFAULT 1,
    is_available TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (clinic_id) REFERENCES clinics(id),
    FOREIGN KEY (specialty_id) REFERENCES specialties(id) ON DELETE SET NULL,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS doctor_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    doctor_id INT NOT NULL,
    branch_id INT DEFAULT NULL,
    day_of_week TINYINT NOT NULL COMMENT '0=Sun,1=Mon,...,6=Sat',
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    slot_duration INT DEFAULT 15,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS doctor_leaves (
    id INT AUTO_INCREMENT PRIMARY KEY,
    doctor_id INT NOT NULL,
    leave_date DATE NOT NULL,
    start_time TIME DEFAULT NULL,
    end_time TIME DEFAULT NULL,
    is_full_day TINYINT(1) DEFAULT 1,
    reason VARCHAR(300) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================
-- 6. PATIENTS
-- ============================================

CREATE TABLE IF NOT EXISTS patients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    clinic_id INT NOT NULL DEFAULT 1,
    patient_uid VARCHAR(20) NOT NULL UNIQUE,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) DEFAULT NULL,
    email VARCHAR(150) DEFAULT NULL,
    phone VARCHAR(20) NOT NULL,
    alt_phone VARCHAR(20) DEFAULT NULL,
    gender ENUM('Male','Female','Other') DEFAULT NULL,
    date_of_birth DATE DEFAULT NULL,
    age INT DEFAULT NULL,
    blood_group ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-') DEFAULT NULL,
    marital_status ENUM('Single','Married','Divorced','Widowed') DEFAULT NULL,
    occupation VARCHAR(100) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    city VARCHAR(100) DEFAULT NULL,
    state VARCHAR(100) DEFAULT NULL,
    pincode VARCHAR(10) DEFAULT NULL,
    profile_image VARCHAR(500) DEFAULT NULL,
    emergency_contact_name VARCHAR(150) DEFAULT NULL,
    emergency_contact_phone VARCHAR(20) DEFAULT NULL,
    emergency_contact_relation VARCHAR(50) DEFAULT NULL,
    insurance_provider VARCHAR(200) DEFAULT NULL,
    insurance_policy_no VARCHAR(100) DEFAULT NULL,
    insurance_expiry DATE DEFAULT NULL,
    referred_by VARCHAR(200) DEFAULT NULL,
    risk_level ENUM('Low','Medium','High','Critical') DEFAULT 'Low',
    notes TEXT DEFAULT NULL,
    family_group_id INT DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (clinic_id) REFERENCES clinics(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS patient_medical_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    condition_name VARCHAR(200) NOT NULL,
    condition_type ENUM('chronic','past','surgical','family') DEFAULT 'past',
    diagnosed_date DATE DEFAULT NULL,
    status ENUM('active','resolved','managed') DEFAULT 'active',
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS patient_allergies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    allergy_type ENUM('drug','food','environmental','other') DEFAULT 'drug',
    allergen VARCHAR(200) NOT NULL,
    severity ENUM('mild','moderate','severe') DEFAULT 'moderate',
    reaction VARCHAR(300) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS patient_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    document_type ENUM('lab_report','scan','prescription','insurance','id_proof','other') DEFAULT 'other',
    title VARCHAR(200) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_type VARCHAR(10) DEFAULT NULL,
    file_size INT DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    uploaded_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS patient_vitals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    appointment_id INT DEFAULT NULL,
    weight DECIMAL(5,2) DEFAULT NULL COMMENT 'in kg',
    height DECIMAL(5,2) DEFAULT NULL COMMENT 'in cm',
    bmi DECIMAL(5,2) DEFAULT NULL,
    temperature DECIMAL(4,1) DEFAULT NULL COMMENT 'in °F',
    blood_pressure_systolic INT DEFAULT NULL,
    blood_pressure_diastolic INT DEFAULT NULL,
    pulse_rate INT DEFAULT NULL,
    respiratory_rate INT DEFAULT NULL,
    spo2 DECIMAL(5,2) DEFAULT NULL,
    blood_sugar DECIMAL(6,2) DEFAULT NULL,
    recorded_by INT DEFAULT NULL,
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================
-- 7. APPOINTMENTS
-- ============================================

CREATE TABLE IF NOT EXISTS appointments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    clinic_id INT NOT NULL DEFAULT 1,
    branch_id INT DEFAULT NULL,
    patient_id INT NOT NULL,
    doctor_id INT NOT NULL,
    appointment_date DATE NOT NULL,
    appointment_time TIME NOT NULL,
    end_time TIME DEFAULT NULL,
    token_number INT DEFAULT NULL,
    appointment_type ENUM('new','followup','walk_in','online','emergency') DEFAULT 'new',
    status ENUM('scheduled','confirmed','checked_in','in_progress','completed','cancelled','no_show','rescheduled') DEFAULT 'scheduled',
    visit_reason TEXT DEFAULT NULL,
    consultation_fee DECIMAL(10,2) DEFAULT 0.00,
    fee_status ENUM('paid','due','waived') DEFAULT 'due',
    notes TEXT DEFAULT NULL,
    cancelled_reason TEXT DEFAULT NULL,
    rescheduled_from INT DEFAULT NULL,
    booked_by INT DEFAULT NULL,
    source ENUM('web','mobile','walk_in','phone') DEFAULT 'web',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (clinic_id) REFERENCES clinics(id),
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (patient_id) REFERENCES patients(id),
    FOREIGN KEY (doctor_id) REFERENCES doctors(id),
    FOREIGN KEY (booked_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_appointment_date (appointment_date),
    INDEX idx_doctor_date (doctor_id, appointment_date),
    INDEX idx_patient (patient_id)
) ENGINE=InnoDB;

-- ============================================
-- 8. MEDICINES & DIAGNOSES MASTER
-- ============================================

CREATE TABLE IF NOT EXISTS medicines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    generic_name VARCHAR(200) DEFAULT NULL,
    brand VARCHAR(150) DEFAULT NULL,
    category VARCHAR(100) DEFAULT NULL,
    dosage_form ENUM('Tablet','Capsule','Syrup','Injection','Cream','Ointment','Drops','Inhaler','Powder','Gel','Spray','Suppository','Patch','Other') DEFAULT 'Tablet',
    strength VARCHAR(50) DEFAULT NULL,
    manufacturer VARCHAR(200) DEFAULT NULL,
    composition TEXT DEFAULT NULL,
    instructions TEXT DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    usage_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_medicine_name (name),
    INDEX idx_generic_name (generic_name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS diagnoses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(300) NOT NULL,
    icd_code VARCHAR(20) DEFAULT NULL,
    category VARCHAR(100) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    usage_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_diagnosis_name (name),
    INDEX idx_icd_code (icd_code)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS lab_tests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    code VARCHAR(30) DEFAULT NULL,
    category VARCHAR(100) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    price DECIMAL(10,2) DEFAULT 0.00,
    is_active TINYINT(1) DEFAULT 1,
    usage_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================
-- 9. PRESCRIPTIONS
-- ============================================

CREATE TABLE IF NOT EXISTS prescriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    clinic_id INT NOT NULL DEFAULT 1,
    patient_id INT NOT NULL,
    doctor_id INT NOT NULL,
    appointment_id INT DEFAULT NULL,
    prescription_number VARCHAR(30) DEFAULT NULL,
    prescription_date DATE NOT NULL,
    chief_complaints TEXT DEFAULT NULL,
    examination_findings TEXT DEFAULT NULL,
    diagnosis TEXT DEFAULT NULL,
    clinical_notes TEXT DEFAULT NULL,
    advice TEXT DEFAULT NULL,
    follow_up_date DATE DEFAULT NULL,
    follow_up_notes TEXT DEFAULT NULL,
    specialty_data JSON DEFAULT NULL COMMENT 'Specialty-specific form data',
    status ENUM('draft','finalized','sent') DEFAULT 'draft',
    template_id INT DEFAULT NULL,
    version INT DEFAULT 1,
    is_printed TINYINT(1) DEFAULT 0,
    is_sent TINYINT(1) DEFAULT 0,
    sent_via VARCHAR(50) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (clinic_id) REFERENCES clinics(id),
    FOREIGN KEY (patient_id) REFERENCES patients(id),
    FOREIGN KEY (doctor_id) REFERENCES doctors(id),
    FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE SET NULL,
    INDEX idx_prescription_patient (patient_id),
    INDEX idx_prescription_doctor (doctor_id),
    INDEX idx_prescription_date (prescription_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS prescription_medicines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    prescription_id INT NOT NULL,
    medicine_id INT DEFAULT NULL,
    medicine_name VARCHAR(200) NOT NULL,
    dosage VARCHAR(100) DEFAULT NULL,
    frequency VARCHAR(100) DEFAULT NULL,
    duration VARCHAR(100) DEFAULT NULL,
    route VARCHAR(50) DEFAULT NULL,
    timing VARCHAR(100) DEFAULT NULL COMMENT 'Before/After meals',
    quantity INT DEFAULT NULL,
    instructions TEXT DEFAULT NULL,
    sort_order INT DEFAULT 0,
    FOREIGN KEY (prescription_id) REFERENCES prescriptions(id) ON DELETE CASCADE,
    FOREIGN KEY (medicine_id) REFERENCES medicines(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS prescription_tests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    prescription_id INT NOT NULL,
    test_id INT DEFAULT NULL,
    test_name VARCHAR(200) NOT NULL,
    instructions TEXT DEFAULT NULL,
    sort_order INT DEFAULT 0,
    FOREIGN KEY (prescription_id) REFERENCES prescriptions(id) ON DELETE CASCADE,
    FOREIGN KEY (test_id) REFERENCES lab_tests(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS prescription_diagnoses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    prescription_id INT NOT NULL,
    diagnosis_id INT DEFAULT NULL,
    diagnosis_name VARCHAR(300) NOT NULL,
    icd_code VARCHAR(20) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    FOREIGN KEY (prescription_id) REFERENCES prescriptions(id) ON DELETE CASCADE,
    FOREIGN KEY (diagnosis_id) REFERENCES diagnoses(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================
-- 10. PRESCRIPTION TEMPLATES
-- ============================================

CREATE TABLE IF NOT EXISTS prescription_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    clinic_id INT NOT NULL DEFAULT 1,
    doctor_id INT DEFAULT NULL,
    specialty_id INT DEFAULT NULL,
    name VARCHAR(200) NOT NULL,
    description TEXT DEFAULT NULL,
    scope ENUM('personal','specialty','clinic') DEFAULT 'personal',
    template_data JSON NOT NULL,
    usage_count INT DEFAULT 0,
    is_default TINYINT(1) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (clinic_id) REFERENCES clinics(id),
    FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE SET NULL,
    FOREIGN KEY (specialty_id) REFERENCES specialties(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================
-- 11. BILLING & PAYMENTS
-- ============================================

CREATE TABLE IF NOT EXISTS invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    clinic_id INT NOT NULL DEFAULT 1,
    branch_id INT DEFAULT NULL,
    patient_id INT NOT NULL,
    doctor_id INT DEFAULT NULL,
    appointment_id INT DEFAULT NULL,
    invoice_number VARCHAR(30) NOT NULL UNIQUE,
    invoice_date DATE NOT NULL,
    due_date DATE DEFAULT NULL,
    subtotal DECIMAL(12,2) DEFAULT 0.00,
    discount_amount DECIMAL(10,2) DEFAULT 0.00,
    discount_type ENUM('fixed','percentage') DEFAULT 'fixed',
    discount_percentage DECIMAL(5,2) DEFAULT 0.00,
    tax_amount DECIMAL(10,2) DEFAULT 0.00,
    total_amount DECIMAL(12,2) DEFAULT 0.00,
    paid_amount DECIMAL(12,2) DEFAULT 0.00,
    due_amount DECIMAL(12,2) DEFAULT 0.00,
    status ENUM('paid','partial','due','overdue','cancelled','refunded') DEFAULT 'due',
    notes TEXT DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (clinic_id) REFERENCES clinics(id),
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (patient_id) REFERENCES patients(id),
    FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE SET NULL,
    FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_invoice_date (invoice_date),
    INDEX idx_invoice_patient (patient_id),
    INDEX idx_invoice_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS invoice_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    item_type ENUM('consultation','procedure','lab_test','medicine','vaccination','dental','custom') DEFAULT 'custom',
    item_name VARCHAR(200) NOT NULL,
    description TEXT DEFAULT NULL,
    quantity INT DEFAULT 1,
    unit_price DECIMAL(10,2) DEFAULT 0.00,
    discount DECIMAL(10,2) DEFAULT 0.00,
    tax_rate DECIMAL(5,2) DEFAULT 0.00,
    tax_amount DECIMAL(10,2) DEFAULT 0.00,
    total DECIMAL(10,2) DEFAULT 0.00,
    sort_order INT DEFAULT 0,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    clinic_id INT NOT NULL DEFAULT 1,
    invoice_id INT NOT NULL,
    patient_id INT NOT NULL,
    payment_date DATE NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_mode ENUM('cash','card','upi','bank','insurance','online','cheque') DEFAULT 'cash',
    transaction_id VARCHAR(100) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    received_by INT DEFAULT NULL,
    is_refund TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (clinic_id) REFERENCES clinics(id),
    FOREIGN KEY (invoice_id) REFERENCES invoices(id),
    FOREIGN KEY (patient_id) REFERENCES patients(id),
    FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_payment_date (payment_date)
) ENGINE=InnoDB;

-- ============================================
-- 12. DENTAL MODULE
-- ============================================

CREATE TABLE IF NOT EXISTS dental_charts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    tooth_number INT NOT NULL,
    tooth_type ENUM('adult','child') DEFAULT 'adult',
    status ENUM('healthy','decayed','filled','missing','crown','bridge','implant','root_canal','extraction_needed') DEFAULT 'healthy',
    surface VARCHAR(20) DEFAULT NULL COMMENT 'M,D,O,B,L combinations',
    notes TEXT DEFAULT NULL,
    last_treatment_date DATE DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    UNIQUE KEY unique_patient_tooth (patient_id, tooth_number, tooth_type)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS dental_treatments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    doctor_id INT NOT NULL,
    tooth_number INT DEFAULT NULL,
    procedure_name VARCHAR(200) NOT NULL,
    procedure_code VARCHAR(20) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    status ENUM('planned','in_progress','completed','cancelled') DEFAULT 'planned',
    treatment_date DATE DEFAULT NULL,
    cost DECIMAL(10,2) DEFAULT 0.00,
    visit_number INT DEFAULT 1,
    total_visits INT DEFAULT 1,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (doctor_id) REFERENCES doctors(id)
) ENGINE=InnoDB;

-- ============================================
-- 13. VACCINATION MODULE
-- ============================================

CREATE TABLE IF NOT EXISTS vaccines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    code VARCHAR(30) DEFAULT NULL,
    disease VARCHAR(200) DEFAULT NULL,
    manufacturer VARCHAR(200) DEFAULT NULL,
    dosage VARCHAR(100) DEFAULT NULL,
    route VARCHAR(50) DEFAULT NULL,
    storage_temp VARCHAR(50) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS vaccine_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vaccine_id INT NOT NULL,
    dose_number INT DEFAULT 1,
    recommended_age_months INT DEFAULT NULL,
    recommended_age_label VARCHAR(50) DEFAULT NULL,
    min_gap_days INT DEFAULT 0 COMMENT 'Min days from previous dose',
    is_mandatory TINYINT(1) DEFAULT 1,
    notes TEXT DEFAULT NULL,
    FOREIGN KEY (vaccine_id) REFERENCES vaccines(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS patient_vaccinations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    vaccine_id INT NOT NULL,
    dose_number INT DEFAULT 1,
    vaccination_date DATE DEFAULT NULL,
    scheduled_date DATE DEFAULT NULL,
    status ENUM('scheduled','administered','missed','overdue') DEFAULT 'scheduled',
    batch_number VARCHAR(50) DEFAULT NULL,
    administered_by INT DEFAULT NULL,
    site VARCHAR(50) DEFAULT NULL,
    reaction VARCHAR(300) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (vaccine_id) REFERENCES vaccines(id),
    FOREIGN KEY (administered_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_vaccination_patient (patient_id),
    INDEX idx_vaccination_status (status)
) ENGINE=InnoDB;

-- ============================================
-- 14. COMMUNICATION ENGINE
-- ============================================

CREATE TABLE IF NOT EXISTS message_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    clinic_id INT NOT NULL DEFAULT 1,
    name VARCHAR(200) NOT NULL,
    event_trigger VARCHAR(100) DEFAULT NULL COMMENT 'appointment_booked, bill_generated, etc.',
    channel ENUM('sms','whatsapp','email','all') DEFAULT 'sms',
    subject VARCHAR(300) DEFAULT NULL,
    body TEXT NOT NULL,
    variables TEXT DEFAULT NULL COMMENT 'JSON list of available variables',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (clinic_id) REFERENCES clinics(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS communication_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    clinic_id INT NOT NULL DEFAULT 1,
    patient_id INT DEFAULT NULL,
    channel ENUM('sms','whatsapp','email') NOT NULL,
    recipient VARCHAR(200) NOT NULL,
    subject VARCHAR(300) DEFAULT NULL,
    message TEXT NOT NULL,
    template_id INT DEFAULT NULL,
    event_type VARCHAR(100) DEFAULT NULL,
    status ENUM('queued','sent','delivered','failed','read') DEFAULT 'queued',
    external_id VARCHAR(200) DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    sent_at TIMESTAMP NULL DEFAULT NULL,
    delivered_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (clinic_id) REFERENCES clinics(id),
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE SET NULL,
    FOREIGN KEY (template_id) REFERENCES message_templates(id) ON DELETE SET NULL,
    INDEX idx_comm_patient (patient_id),
    INDEX idx_comm_status (status),
    INDEX idx_comm_date (created_at)
) ENGINE=InnoDB;

-- ============================================
-- 15. AUDIT & SYSTEM
-- ============================================

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    clinic_id INT DEFAULT NULL,
    action VARCHAR(50) NOT NULL COMMENT 'create, update, delete, login, logout, etc.',
    module VARCHAR(50) DEFAULT NULL,
    entity_type VARCHAR(50) DEFAULT NULL,
    entity_id INT DEFAULT NULL,
    old_values JSON DEFAULT NULL,
    new_values JSON DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(500) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_user (user_id),
    INDEX idx_audit_action (action),
    INDEX idx_audit_date (created_at),
    INDEX idx_audit_entity (entity_type, entity_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    clinic_id INT NOT NULL DEFAULT 1,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT DEFAULT NULL,
    setting_group VARCHAR(50) DEFAULT 'general',
    data_type ENUM('string','integer','boolean','json','text') DEFAULT 'string',
    description TEXT DEFAULT NULL,
    is_public TINYINT(1) DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_clinic_setting (clinic_id, setting_key),
    FOREIGN KEY (clinic_id) REFERENCES clinics(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS api_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(500) NOT NULL,
    device_info VARCHAR(300) DEFAULT NULL,
    expires_at TIMESTAMP NOT NULL,
    last_used_at TIMESTAMP NULL DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token (token(255))
) ENGINE=InnoDB;

-- ============================================
-- 16. PATIENT APP NOTIFICATIONS
-- ============================================

CREATE TABLE IF NOT EXISTS push_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    body TEXT NOT NULL,
    data JSON DEFAULT NULL,
    type VARCHAR(50) DEFAULT 'general',
    is_read TINYINT(1) DEFAULT 0,
    sent_at TIMESTAMP NULL DEFAULT NULL,
    read_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS patient_devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    device_token VARCHAR(500) NOT NULL,
    device_type ENUM('ios','android') NOT NULL,
    device_model VARCHAR(100) DEFAULT NULL,
    app_version VARCHAR(20) DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================
-- 17. PATIENT AUTH (MOBILE APP)
-- ============================================

CREATE TABLE IF NOT EXISTS patient_auth (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL UNIQUE,
    phone VARCHAR(20) NOT NULL UNIQUE,
    password VARCHAR(255) DEFAULT NULL,
    otp VARCHAR(10) DEFAULT NULL,
    otp_expires_at TIMESTAMP NULL DEFAULT NULL,
    is_verified TINYINT(1) DEFAULT 0,
    last_login TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
) ENGINE=InnoDB;
