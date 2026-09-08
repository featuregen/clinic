-- ============================================
-- Advanced Clinic Suite - Master Database Schema
-- Version: 1.0.0
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
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_subdomain (subdomain),
    INDEX idx_status (status)
) ENGINE=InnoDB;

-- Insert a default local tenant for development
INSERT INTO tenants (clinic_name, subdomain, db_host, db_name, db_user, db_password, status)
VALUES ('Local Clinic Dev', 'localhost', 'localhost', 'clinic_suite', 'root', 'root', 'active')
ON DUPLICATE KEY UPDATE db_name='clinic_suite';
