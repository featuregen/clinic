-- ============================================
-- Feature Gen Care - Master Database Schema
-- Version: 2.0.0 (Subscription & Multi-Tenant Billing)
-- ============================================

CREATE TABLE IF NOT EXISTS tenants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    clinic_name VARCHAR(200) NOT NULL,
    subdomain VARCHAR(100) NOT NULL UNIQUE,
    db_host VARCHAR(100) NOT NULL DEFAULT 'localhost',
    db_name VARCHAR(100) NOT NULL UNIQUE,
    db_user VARCHAR(100) NOT NULL,
    db_password VARCHAR(255) NOT NULL,
    status ENUM('active', 'suspended', 'pending') DEFAULT 'active',
    
    -- Subscription & Billing
    plan_type ENUM('trial', 'monthly', 'yearly', 'one_time', 'custom') DEFAULT 'trial',
    billing_cycle ENUM('trial', 'monthly', 'yearly', 'one_time') DEFAULT 'trial',
    max_doctors INT DEFAULT 2 COMMENT 'Doctor quota: 0 = unlimited',
    trial_ends_at DATETIME NULL,
    subscription_starts_at DATETIME NULL,
    subscription_ends_at DATETIME NULL COMMENT 'NULL indicates lifetime/perpetual license',
    is_lifetime TINYINT(1) DEFAULT 0,
    bonus_months INT DEFAULT 0 COMMENT 'Negotiated bonus months (e.g. +2 on yearly)',
    plan_amount DECIMAL(10,2) DEFAULT 0.00,
    currency VARCHAR(10) DEFAULT 'INR',
    subscription_status ENUM('trial', 'active', 'expired', 'grace_period', 'suspended') DEFAULT 'trial',
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_subdomain (subdomain),
    INDEX idx_status (status),
    INDEX idx_sub_status (subscription_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Subscription Payments Ledger (Cash on Hand, Bank Transfer, UPI, Cheque, Razorpay)
CREATE TABLE IF NOT EXISTS tenant_subscription_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    plan_type VARCHAR(50) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_mode ENUM('cash_on_hand', 'bank_transfer', 'upi', 'cheque', 'razorpay') NOT NULL,
    payment_reference VARCHAR(100) NULL COMMENT 'Receipt #, UTR, Cheque #, or Razorpay payment_id',
    collected_by VARCHAR(100) NULL COMMENT 'Super Admin or agent name who collected cash',
    period_start DATETIME NOT NULL,
    period_end DATETIME NULL COMMENT 'NULL for lifetime',
    bonus_months_granted INT DEFAULT 0,
    doctor_limit_granted INT DEFAULT 2,
    status ENUM('completed', 'pending', 'refunded') DEFAULT 'completed',
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tenant_payment (tenant_id),
    INDEX idx_payment_mode (payment_mode),
    INDEX idx_reference (payment_reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SaaS Global Settings (Default Plan Pricing, Doctor Quotas, Razorpay Keys, Offline Details)
CREATE TABLE IF NOT EXISTS saas_global_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default Settings Seed
INSERT INTO saas_global_settings (setting_key, setting_value) VALUES
('trial_duration_months', '1'),
('trial_max_doctors', '2'),
('monthly_price', '1499'),
('monthly_max_doctors', '3'),
('yearly_price', '14999'),
('yearly_max_doctors', '10'),
('yearly_default_bonus_months', '2'),
('one_time_price', '49999'),
('one_time_max_doctors', '0'),
('razorpay_key_id', ''),
('razorpay_key_secret', ''),
('offline_payment_contact', 'Phone: +91 98765 43210 | WhatsApp: +91 98765 43210'),
('offline_bank_details', 'Account Name: Feature Gen Technologies\nAccount Number: 123456789012\nBank: HDFC Bank\nIFSC: HDFC0001234\nUPI ID: featuregen@upi')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);

-- Insert a default local tenant for development if not present
INSERT INTO tenants (
    clinic_name, subdomain, db_host, db_name, db_user, db_password, 
    status, plan_type, billing_cycle, max_doctors, subscription_starts_at, 
    subscription_ends_at, subscription_status
)
VALUES (
    'Feature Gen Demo Clinic', 'localhost', 'localhost', 'clinic_suite', 'root', 'root', 
    'active', 'yearly', 'yearly', 10, NOW(), DATE_ADD(NOW(), INTERVAL 14 MONTH), 'active'
)
ON DUPLICATE KEY UPDATE 
    db_name='clinic_suite',
    max_doctors=10,
    subscription_status='active';
