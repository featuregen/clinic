-- ==========================================================
-- Upgrade Script: Master Database Subscription & Billing
-- Run this against `clinic_suite_master` or `u882688268_clinic_master`
-- ==========================================================

-- 1. Add subscription columns to `tenants` if not already present
ALTER TABLE tenants
    ADD COLUMN IF NOT EXISTS plan_type ENUM('trial', 'monthly', 'yearly', 'one_time', 'custom') DEFAULT 'trial' AFTER status,
    ADD COLUMN IF NOT EXISTS billing_cycle ENUM('trial', 'monthly', 'yearly', 'one_time') DEFAULT 'trial' AFTER plan_type,
    ADD COLUMN IF NOT EXISTS max_doctors INT DEFAULT 2 COMMENT 'Doctor quota: 0 = unlimited' AFTER billing_cycle,
    ADD COLUMN IF NOT EXISTS trial_ends_at DATETIME NULL AFTER max_doctors,
    ADD COLUMN IF NOT EXISTS subscription_starts_at DATETIME NULL AFTER trial_ends_at,
    ADD COLUMN IF NOT EXISTS subscription_ends_at DATETIME NULL COMMENT 'NULL indicates lifetime/perpetual' AFTER subscription_starts_at,
    ADD COLUMN IF NOT EXISTS is_lifetime TINYINT(1) DEFAULT 0 AFTER subscription_ends_at,
    ADD COLUMN IF NOT EXISTS bonus_months INT DEFAULT 0 COMMENT 'Negotiated bonus months' AFTER is_lifetime,
    ADD COLUMN IF NOT EXISTS plan_amount DECIMAL(10,2) DEFAULT 0.00 AFTER bonus_months,
    ADD COLUMN IF NOT EXISTS currency VARCHAR(10) DEFAULT 'INR' AFTER plan_amount,
    ADD COLUMN IF NOT EXISTS subscription_status ENUM('trial', 'active', 'expired', 'grace_period', 'suspended') DEFAULT 'trial' AFTER currency;

-- 2. Create `tenant_subscription_payments`
CREATE TABLE IF NOT EXISTS tenant_subscription_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    plan_type VARCHAR(50) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_mode ENUM('cash_on_hand', 'bank_transfer', 'upi', 'cheque', 'razorpay') NOT NULL,
    payment_reference VARCHAR(100) NULL COMMENT 'Receipt #, UTR, Cheque #, or Razorpay payment_id',
    collected_by VARCHAR(100) NULL COMMENT 'Super Admin or agent who collected cash',
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

-- 3. Create `saas_global_settings`
CREATE TABLE IF NOT EXISTS saas_global_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Seed default SaaS settings
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

-- 5. Backfill existing active tenants to a default 3-month trial if subscription_ends_at is unset
UPDATE tenants 
SET subscription_starts_at = NOW(),
    trial_ends_at = DATE_ADD(NOW(), INTERVAL 3 MONTH),
    subscription_ends_at = DATE_ADD(NOW(), INTERVAL 3 MONTH),
    subscription_status = 'active',
    max_doctors = 5
WHERE subscription_ends_at IS NULL AND is_lifetime = 0;
