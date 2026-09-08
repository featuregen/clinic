-- ============================================
-- Advanced Clinic Suite - Seed Data
-- ============================================

USE clinic_suite;

-- ============================================
-- 1. Default Clinic
-- ============================================
INSERT INTO clinics (name, tagline, email, phone, address, city, state, pincode, registration_no)
VALUES ('Advanced Clinic Suite', 'Your Health, Our Priority', 'admin@clinicsuite.com', '+91 9876543210',
        '123 Medical Plaza, Health Street', 'Mumbai', 'Maharashtra', '400001', 'CLN-2025-001');

-- ============================================
-- 2. Default Branch
-- ============================================
INSERT INTO branches (clinic_id, name, code, phone, address, city, state, pincode)
VALUES (1, 'Main Branch', 'MAIN', '+91 9876543210', '123 Medical Plaza, Health Street', 'Mumbai', 'Maharashtra', '400001');

-- ============================================
-- 3. Roles
-- ============================================
INSERT INTO roles (name, display_name, description, is_system) VALUES
('super_admin', 'Super Admin', 'Full system access with all permissions', 1),
('admin', 'Admin', 'Administrative access with most permissions', 1),
('doctor', 'Doctor', 'Doctor with clinical access', 1),
('receptionist', 'Receptionist', 'Front desk and appointment management', 1),
('accountant', 'Accountant', 'Billing and financial management', 1),
('nurse', 'Nurse', 'Clinical support and patient vitals', 1),
('lab_technician', 'Lab Technician', 'Lab test management', 1);

-- ============================================
-- 4. Permissions
-- ============================================
INSERT INTO permissions (module, name, display_name) VALUES
-- Dashboard
('dashboard', 'dashboard.view', 'View Dashboard'),
('dashboard', 'dashboard.analytics', 'View Analytics'),
-- Clinic
('clinic', 'clinic.settings', 'Manage Clinic Settings'),
('clinic', 'clinic.branches', 'Manage Branches'),
-- Doctors
('doctors', 'doctors.view', 'View Doctors'),
('doctors', 'doctors.create', 'Add Doctors'),
('doctors', 'doctors.edit', 'Edit Doctors'),
('doctors', 'doctors.delete', 'Delete Doctors'),
('doctors', 'doctors.schedule', 'Manage Doctor Schedules'),
-- Staff
('staff', 'staff.view', 'View Staff'),
('staff', 'staff.create', 'Add Staff'),
('staff', 'staff.edit', 'Edit Staff'),
('staff', 'staff.delete', 'Delete Staff'),
('staff', 'staff.roles', 'Manage Roles'),
-- Patients
('patients', 'patients.view', 'View Patients'),
('patients', 'patients.create', 'Add Patients'),
('patients', 'patients.edit', 'Edit Patients'),
('patients', 'patients.delete', 'Delete Patients'),
('patients', 'patients.documents', 'Manage Patient Documents'),
('patients', 'patients.history', 'View Patient History'),
-- Appointments
('appointments', 'appointments.view', 'View Appointments'),
('appointments', 'appointments.create', 'Book Appointments'),
('appointments', 'appointments.edit', 'Edit Appointments'),
('appointments', 'appointments.cancel', 'Cancel Appointments'),
('appointments', 'appointments.queue', 'Manage Queue'),
-- Prescriptions
('prescriptions', 'prescriptions.view', 'View Prescriptions'),
('prescriptions', 'prescriptions.create', 'Create Prescriptions'),
('prescriptions', 'prescriptions.edit', 'Edit Prescriptions'),
('prescriptions', 'prescriptions.templates', 'Manage Templates'),
-- Billing
('billing', 'billing.view', 'View Invoices'),
('billing', 'billing.create', 'Create Invoices'),
('billing', 'billing.edit', 'Edit Invoices'),
('billing', 'billing.payments', 'Record Payments'),
('billing', 'billing.refund', 'Process Refunds'),
-- Reports
('reports', 'reports.view', 'View Reports'),
('reports', 'reports.export', 'Export Reports'),
-- Dental
('dental', 'dental.view', 'View Dental Charts'),
('dental', 'dental.manage', 'Manage Dental Treatments'),
-- Vaccination
('vaccination', 'vaccination.view', 'View Vaccinations'),
('vaccination', 'vaccination.manage', 'Manage Vaccinations'),
-- Communication
('communication', 'communication.view', 'View Communications'),
('communication', 'communication.send', 'Send Communications'),
('communication', 'communication.templates', 'Manage Message Templates'),
-- Admin
('admin', 'admin.settings', 'System Settings'),
('admin', 'admin.audit', 'View Audit Logs'),
('admin', 'admin.backup', 'Manage Backups'),
('admin', 'admin.api', 'API Management');

-- ============================================
-- 5. Super Admin gets ALL permissions
-- ============================================
INSERT INTO role_permissions (role_id, permission_id)
SELECT 1, id FROM permissions;

-- Admin gets most permissions (except admin-only)
INSERT INTO role_permissions (role_id, permission_id)
SELECT 2, id FROM permissions WHERE module NOT IN ('admin');

-- Doctor permissions
INSERT INTO role_permissions (role_id, permission_id)
SELECT 3, id FROM permissions WHERE name IN (
    'dashboard.view', 'patients.view', 'patients.history',
    'appointments.view', 'appointments.edit',
    'prescriptions.view', 'prescriptions.create', 'prescriptions.edit', 'prescriptions.templates',
    'dental.view', 'dental.manage', 'vaccination.view', 'vaccination.manage',
    'billing.view'
);

-- Receptionist permissions
INSERT INTO role_permissions (role_id, permission_id)
SELECT 4, id FROM permissions WHERE name IN (
    'dashboard.view', 'patients.view', 'patients.create', 'patients.edit',
    'appointments.view', 'appointments.create', 'appointments.edit', 'appointments.cancel', 'appointments.queue',
    'billing.view', 'billing.create', 'billing.payments',
    'communication.view', 'communication.send'
);

-- Accountant permissions
INSERT INTO role_permissions (role_id, permission_id)
SELECT 5, id FROM permissions WHERE name IN (
    'dashboard.view', 'dashboard.analytics',
    'billing.view', 'billing.create', 'billing.edit', 'billing.payments', 'billing.refund',
    'reports.view', 'reports.export',
    'patients.view'
);

-- Nurse permissions
INSERT INTO role_permissions (role_id, permission_id)
SELECT 6, id FROM permissions WHERE name IN (
    'dashboard.view', 'patients.view', 'patients.history',
    'appointments.view', 'appointments.queue',
    'prescriptions.view', 'vaccination.view', 'vaccination.manage'
);

-- ============================================
-- 6. Specialties
-- ============================================
INSERT INTO specialties (name, code, description, has_custom_form) VALUES
('General Medicine', 'GEN', 'General medical practice and primary care', 0),
('Pediatrics', 'PED', 'Medical care for infants, children, and adolescents', 1),
('Gynecology', 'GYN', 'Women\'s health and reproductive medicine', 1),
('Orthopedics', 'ORT', 'Musculoskeletal system disorders', 1),
('Dermatology', 'DER', 'Skin, hair, and nail conditions', 1),
('Dentistry', 'DEN', 'Oral health and dental care', 1),
('Cardiology', 'CAR', 'Heart and cardiovascular conditions', 1),
('ENT', 'ENT', 'Ear, Nose, and Throat disorders', 0),
('Ophthalmology', 'OPH', 'Eye care and vision disorders', 1),
('Neurology', 'NEU', 'Nervous system disorders', 0),
('Psychiatry', 'PSY', 'Mental health and behavioral disorders', 0),
('Urology', 'URO', 'Urinary tract and male reproductive disorders', 0),
('Pulmonology', 'PUL', 'Respiratory system disorders', 0),
('Endocrinology', 'END', 'Hormonal and metabolic disorders', 0),
('Gastroenterology', 'GAS', 'Digestive system disorders', 0);

-- ============================================
-- 7. Default Admin User (password: admin123)
-- ============================================
INSERT INTO users (clinic_id, branch_id, role_id, role, username, password, full_name, email, phone, gender)
VALUES (1, 1, 1, 'super_admin', 'admin', '$2y$10$caAnrB9LA1iWRsdg1e0hpOTDFhDIN0E1joTW98c3e9kYA3moGP4Li', 
        'System Administrator', 'admin@clinicsuite.com', '+91 9876543210', 'Male');

-- ============================================
-- 8. Sample Medicines (Common)
-- ============================================
INSERT INTO medicines (name, generic_name, dosage_form, strength, category) VALUES
('Paracetamol 500mg', 'Paracetamol', 'Tablet', '500mg', 'Analgesic'),
('Paracetamol 650mg', 'Paracetamol', 'Tablet', '650mg', 'Analgesic'),
('Amoxicillin 500mg', 'Amoxicillin', 'Capsule', '500mg', 'Antibiotic'),
('Amoxicillin 250mg', 'Amoxicillin', 'Capsule', '250mg', 'Antibiotic'),
('Azithromycin 500mg', 'Azithromycin', 'Tablet', '500mg', 'Antibiotic'),
('Azithromycin 250mg', 'Azithromycin', 'Tablet', '250mg', 'Antibiotic'),
('Cetirizine 10mg', 'Cetirizine', 'Tablet', '10mg', 'Antihistamine'),
('Montelukast 10mg', 'Montelukast', 'Tablet', '10mg', 'Antiasthmatic'),
('Omeprazole 20mg', 'Omeprazole', 'Capsule', '20mg', 'Antacid'),
('Pantoprazole 40mg', 'Pantoprazole', 'Tablet', '40mg', 'Antacid'),
('Ranitidine 150mg', 'Ranitidine', 'Tablet', '150mg', 'Antacid'),
('Metformin 500mg', 'Metformin', 'Tablet', '500mg', 'Antidiabetic'),
('Metformin 1000mg', 'Metformin', 'Tablet', '1000mg', 'Antidiabetic'),
('Amlodipine 5mg', 'Amlodipine', 'Tablet', '5mg', 'Antihypertensive'),
('Atenolol 50mg', 'Atenolol', 'Tablet', '50mg', 'Beta Blocker'),
('Losartan 50mg', 'Losartan', 'Tablet', '50mg', 'Antihypertensive'),
('Ibuprofen 400mg', 'Ibuprofen', 'Tablet', '400mg', 'NSAID'),
('Diclofenac 50mg', 'Diclofenac', 'Tablet', '50mg', 'NSAID'),
('Dolo 650', 'Paracetamol', 'Tablet', '650mg', 'Analgesic'),
('Crocin 500', 'Paracetamol', 'Tablet', '500mg', 'Analgesic'),
('Augmentin 625mg', 'Amoxicillin+Clavulanate', 'Tablet', '625mg', 'Antibiotic'),
('Cefixime 200mg', 'Cefixime', 'Tablet', '200mg', 'Antibiotic'),
('Doxycycline 100mg', 'Doxycycline', 'Capsule', '100mg', 'Antibiotic'),
('Metronidazole 400mg', 'Metronidazole', 'Tablet', '400mg', 'Antibiotic'),
('Ondansetron 4mg', 'Ondansetron', 'Tablet', '4mg', 'Antiemetic'),
('Domperidone 10mg', 'Domperidone', 'Tablet', '10mg', 'Antiemetic'),
('Prednisolone 10mg', 'Prednisolone', 'Tablet', '10mg', 'Corticosteroid'),
('Multivitamin', 'Multivitamin', 'Tablet', '-', 'Supplement'),
('Calcium + Vitamin D3', 'Calcium Carbonate + Cholecalciferol', 'Tablet', '500mg+250IU', 'Supplement'),
('Iron + Folic Acid', 'Ferrous Sulphate + Folic Acid', 'Tablet', '100mg+0.5mg', 'Supplement');

-- ============================================
-- 9. Sample Diagnoses
-- ============================================
INSERT INTO diagnoses (name, icd_code, category) VALUES
('Upper Respiratory Tract Infection', 'J06.9', 'Respiratory'),
('Acute Pharyngitis', 'J02.9', 'Respiratory'),
('Acute Bronchitis', 'J20.9', 'Respiratory'),
('Pneumonia', 'J18.9', 'Respiratory'),
('Allergic Rhinitis', 'J30.9', 'Respiratory'),
('Asthma', 'J45.9', 'Respiratory'),
('Hypertension', 'I10', 'Cardiovascular'),
('Type 2 Diabetes Mellitus', 'E11.9', 'Endocrine'),
('Gastritis', 'K29.7', 'Digestive'),
('Gastroesophageal Reflux Disease', 'K21.0', 'Digestive'),
('Urinary Tract Infection', 'N39.0', 'Genitourinary'),
('Migraine', 'G43.9', 'Neurological'),
('Tension Headache', 'G44.2', 'Neurological'),
('Low Back Pain', 'M54.5', 'Musculoskeletal'),
('Osteoarthritis', 'M19.9', 'Musculoskeletal'),
('Dermatitis', 'L30.9', 'Dermatological'),
('Eczema', 'L30.0', 'Dermatological'),
('Fungal Infection', 'B49', 'Dermatological'),
('Conjunctivitis', 'H10.9', 'Ophthalmological'),
('Otitis Media', 'H66.9', 'ENT'),
('Dental Caries', 'K02.9', 'Dental'),
('Gingivitis', 'K05.1', 'Dental'),
('Iron Deficiency Anemia', 'D50.9', 'Hematological'),
('Anxiety Disorder', 'F41.9', 'Psychiatric'),
('Fever of Unknown Origin', 'R50.9', 'General');

-- ============================================
-- 10. Sample Lab Tests
-- ============================================
INSERT INTO lab_tests (name, code, category, price) VALUES
('Complete Blood Count (CBC)', 'CBC', 'Hematology', 350.00),
('Blood Glucose Fasting', 'BGF', 'Biochemistry', 100.00),
('Blood Glucose PP', 'BGPP', 'Biochemistry', 100.00),
('HbA1c', 'HBA1C', 'Biochemistry', 600.00),
('Lipid Profile', 'LIPID', 'Biochemistry', 500.00),
('Liver Function Test', 'LFT', 'Biochemistry', 600.00),
('Kidney Function Test', 'KFT', 'Biochemistry', 600.00),
('Thyroid Profile (T3, T4, TSH)', 'THYROID', 'Biochemistry', 700.00),
('Urine Routine & Microscopy', 'URINE', 'Pathology', 150.00),
('Blood Urea', 'UREA', 'Biochemistry', 150.00),
('Serum Creatinine', 'CREAT', 'Biochemistry', 200.00),
('Serum Uric Acid', 'URICACID', 'Biochemistry', 200.00),
('Erythrocyte Sedimentation Rate', 'ESR', 'Hematology', 100.00),
('C-Reactive Protein (CRP)', 'CRP', 'Immunology', 500.00),
('Vitamin D', 'VITD', 'Biochemistry', 1200.00),
('Vitamin B12', 'VITB12', 'Biochemistry', 800.00),
('Chest X-Ray', 'CXR', 'Radiology', 400.00),
('ECG', 'ECG', 'Cardiology', 300.00),
('Ultrasound Abdomen', 'USG-ABD', 'Radiology', 1000.00),
('Dengue NS1 Antigen', 'DENGUE', 'Serology', 600.00);

-- ============================================
-- 11. Sample Vaccines
-- ============================================
INSERT INTO vaccines (name, code, disease, dosage, route) VALUES
('BCG', 'BCG', 'Tuberculosis', '0.05 mL', 'Intradermal'),
('OPV', 'OPV', 'Poliomyelitis', '2 drops', 'Oral'),
('IPV', 'IPV', 'Poliomyelitis', '0.5 mL', 'Intramuscular'),
('Hepatitis B', 'HEPB', 'Hepatitis B', '0.5 mL', 'Intramuscular'),
('DPT', 'DPT', 'Diphtheria, Pertussis, Tetanus', '0.5 mL', 'Intramuscular'),
('Pentavalent', 'PENTA', 'DPT + HepB + Hib', '0.5 mL', 'Intramuscular'),
('Measles', 'MEASLES', 'Measles', '0.5 mL', 'Subcutaneous'),
('MMR', 'MMR', 'Measles, Mumps, Rubella', '0.5 mL', 'Subcutaneous'),
('Rotavirus', 'ROTA', 'Rotavirus Gastroenteritis', '1 mL', 'Oral'),
('PCV', 'PCV', 'Pneumococcal Disease', '0.5 mL', 'Intramuscular'),
('Varicella', 'VAR', 'Chickenpox', '0.5 mL', 'Subcutaneous'),
('Hepatitis A', 'HEPA', 'Hepatitis A', '0.5 mL', 'Intramuscular'),
('Typhoid', 'TYP', 'Typhoid', '0.5 mL', 'Intramuscular'),
('Influenza', 'FLU', 'Influenza', '0.5 mL', 'Intramuscular'),
('HPV', 'HPV', 'Human Papillomavirus', '0.5 mL', 'Intramuscular');

-- ============================================
-- 12. Vaccine Schedules (Pediatric)
-- ============================================
INSERT INTO vaccine_schedules (vaccine_id, dose_number, recommended_age_months, recommended_age_label, min_gap_days, is_mandatory) VALUES
-- BCG at birth
(1, 1, 0, 'At Birth', 0, 1),
-- OPV
(2, 1, 0, 'At Birth', 0, 1),
(2, 2, 2, '6 Weeks', 28, 1),
(2, 3, 3, '10 Weeks', 28, 1),
(2, 4, 4, '14 Weeks', 28, 1),
-- Hepatitis B
(4, 1, 0, 'At Birth', 0, 1),
(4, 2, 2, '6 Weeks', 28, 1),
(4, 3, 4, '14 Weeks', 56, 1),
-- DPT Boosters
(5, 1, 18, '18 Months', 0, 1),
(5, 2, 60, '5 Years', 0, 1),
-- Measles
(7, 1, 9, '9 Months', 0, 1),
-- MMR
(8, 1, 15, '15 Months', 0, 1),
(8, 2, 60, '5 Years', 0, 1),
-- Rotavirus
(9, 1, 2, '6 Weeks', 0, 0),
(9, 2, 3, '10 Weeks', 28, 0),
(9, 3, 4, '14 Weeks', 28, 0);

-- ============================================
-- 13. Default Message Templates
-- ============================================
INSERT INTO message_templates (clinic_id, name, event_trigger, channel, subject, body, variables) VALUES
(1, 'Appointment Confirmation', 'appointment_booked', 'sms',
 'Appointment Confirmed', 
 'Dear {{patient_name}}, your appointment with Dr. {{doctor_name}} is confirmed for {{date}} at {{time}}. Token #{{token}}. - {{clinic_name}}',
 '["patient_name","doctor_name","date","time","token","clinic_name"]'),
(1, 'Appointment Reminder', 'appointment_reminder', 'sms',
 'Appointment Reminder',
 'Reminder: You have an appointment with Dr. {{doctor_name}} tomorrow ({{date}}) at {{time}}. Please arrive 10 min early. - {{clinic_name}}',
 '["patient_name","doctor_name","date","time","clinic_name"]'),
(1, 'Bill Generated', 'bill_generated', 'sms',
 'Invoice Generated',
 'Dear {{patient_name}}, Invoice #{{invoice_no}} of {{currency}}{{amount}} generated. Due: {{due_date}}. Pay online or at the clinic. - {{clinic_name}}',
 '["patient_name","invoice_no","currency","amount","due_date","clinic_name"]'),
(1, 'Payment Received', 'payment_received', 'sms',
 'Payment Confirmation',
 'Dear {{patient_name}}, payment of {{currency}}{{amount}} received for Invoice #{{invoice_no}}. Thank you! - {{clinic_name}}',
 '["patient_name","currency","amount","invoice_no","clinic_name"]'),
(1, 'Due Reminder', 'due_reminder', 'sms',
 'Payment Due Reminder',
 'Dear {{patient_name}}, you have an outstanding balance of {{currency}}{{amount}} (Invoice #{{invoice_no}}). Kindly clear the dues at your earliest. - {{clinic_name}}',
 '["patient_name","currency","amount","invoice_no","clinic_name"]'),
(1, 'Vaccine Reminder', 'vaccine_reminder', 'sms',
 'Vaccination Due',
 'Dear Parent, {{vaccine_name}} vaccination for {{patient_name}} is due on {{due_date}}. Please visit us at your convenience. - {{clinic_name}}',
 '["patient_name","vaccine_name","due_date","clinic_name"]');

-- ============================================
-- 14. Default System Settings
-- ============================================
INSERT INTO system_settings (clinic_id, setting_key, setting_value, setting_group, data_type) VALUES
(1, 'appointment_slot_duration', '15', 'appointments', 'integer'),
(1, 'appointment_buffer_time', '5', 'appointments', 'integer'),
(1, 'allow_online_booking', '1', 'appointments', 'boolean'),
(1, 'auto_generate_token', '1', 'appointments', 'boolean'),
(1, 'reminder_hours_before', '24', 'appointments', 'integer'),
(1, 'prescription_header_show', '1', 'prescriptions', 'boolean'),
(1, 'prescription_footer_show', '1', 'prescriptions', 'boolean'),
(1, 'prescription_show_vitals', '1', 'prescriptions', 'boolean'),
(1, 'billing_gst_enabled', '0', 'billing', 'boolean'),
(1, 'billing_default_gst_rate', '0', 'billing', 'integer'),
(1, 'sms_enabled', '0', 'communication', 'boolean'),
(1, 'whatsapp_enabled', '0', 'communication', 'boolean'),
(1, 'email_enabled', '1', 'communication', 'boolean'),
(1, 'backup_frequency', 'daily', 'system', 'string'),
(1, 'session_timeout_minutes', '60', 'system', 'integer'),
(1, 'max_login_attempts', '5', 'system', 'integer'),
(1, 'password_min_length', '8', 'system', 'integer');
