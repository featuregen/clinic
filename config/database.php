<?php
/**
 * Database Configuration & Connection
 * Feature Gen Care
 */

class Database {
    private static $instance = null;
    private $connection;
    
    // Master Database Credentials
    private $masterHost;
    private $masterDbName;
    private $masterUsername;
    private $masterPassword;
    
    // Tenant Database Credentials (Resolved dynamically)
    private $host;
    private $dbname;
    private $username;
    private $password;
    private $charset = 'utf8mb4';
    
    public $tenantInfo = null; // Store resolved tenant info
    
    private function __construct() {
        $this->setMasterCredentials();
        $this->resolveTenantAndConnect();
    }
    
    /**
     * Set Master Database Credentials based on environment
     */
    private function setMasterCredentials() {
        $serverName = $_SERVER['SERVER_NAME'] ?? 'localhost';
        
        if (in_array($serverName, ['localhost', '127.0.0.1'])) {
            // Local development
            $this->masterHost = 'localhost';
            $this->masterDbName = 'clinic_suite_master';
            $this->masterUsername = 'root';
            $this->masterPassword = 'root';
        } else {
            // Production - Update these for live master DB
            $this->masterHost = 'localhost';
            $this->masterDbName = 'u882688268_clinic_master';
            $this->masterUsername = 'u882688268_clinic_master';
            $this->masterPassword = 'ClinicMaster@123';
        }
    }
    
    /**
     * Connect to Master DB, resolve tenant, then connect to Tenant DB
     */
    private function resolveTenantAndConnect() {
        try {
            // 1. Connect to Master DB
            $masterDsn = "mysql:host={$this->masterHost};dbname={$this->masterDbName};charset={$this->charset}";
            $masterConn = new PDO($masterDsn, $this->masterUsername, $this->masterPassword, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            
            // 2. Determine Subdomain
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $host = explode(':', $host)[0];
            
            $subdomain = 'localhost';
            if ($host === 'localhost' || $host === '127.0.0.1') {
                $subdomain = 'localhost';
            } else {
                $parts = explode('.', $host);
                if ($parts[0] === 'www' && isset($parts[1])) {
                    $subdomain = $parts[1];
                } else {
                    $subdomain = $parts[0];
                }
            }
            
            // Allow mobile/API clients to override tenant via X-Tenant-Subdomain header
            $headerSubdomain = $_SERVER['HTTP_X_TENANT_SUBDOMAIN'] ?? null;
            if (!empty($headerSubdomain)) {
                $subdomain = $headerSubdomain;
            }
            
            // Self-heal Master DB Subscription Schema & Settings
            $this->ensureMasterSubscriptionsSchema($masterConn);
            
            // 3. Query Tenant
            $stmt = $masterConn->prepare("SELECT * FROM tenants WHERE subdomain = ?");
            $stmt->execute([$subdomain]);
            $tenant = $stmt->fetch();
            
            // Fallback for live demo domain if needed
            if (!$tenant && $subdomain !== 'localhost') {
                $stmt = $masterConn->prepare("SELECT * FROM tenants WHERE subdomain = 'democlinic' LIMIT 1");
                $stmt->execute();
                $tenant = $stmt->fetch();
            }
            
            if (!$tenant) {
                die("<h1>Clinic Not Found</h1><p>We couldn't find a clinic associated with the URL: " . htmlspecialchars($host) . "</p>");
            }
            
            if ($tenant['status'] !== 'active') {
                die("<h1>Account Suspended</h1><p>This clinic's account is currently suspended or inactive. Please contact support.</p>");
            }
            
            // Calculate Subscription Expiration Status
            $isLifetime = !empty($tenant['is_lifetime']) && intval($tenant['is_lifetime']) === 1;
            $isExpired = false;
            $now = time();
            
            if (!$isLifetime && ($tenant['plan_type'] ?? '') !== 'one_time') {
                $expiryTime = !empty($tenant['subscription_ends_at']) 
                    ? strtotime($tenant['subscription_ends_at']) 
                    : (!empty($tenant['trial_ends_at']) ? strtotime($tenant['trial_ends_at']) : null);
                
                if ($expiryTime !== null && $expiryTime < $now) {
                    $isExpired = true;
                }
            }
            $tenant['is_expired'] = $isExpired;
            
            // Dynamically resolve and synchronize doctor quota based on active plan and custom terms / global settings
            $planType = $tenant['plan_type'] ?? 'trial';
            $effMaxDoc = isset($tenant['max_doctors']) ? intval($tenant['max_doctors']) : 0;
            
            if ($planType === 'trial') {
                if (!empty($tenant['custom_trial_doctors'])) {
                    $effMaxDoc = intval($tenant['custom_trial_doctors']);
                } else {
                    $stmtS = $masterConn->prepare("SELECT setting_value FROM saas_global_settings WHERE setting_key = 'trial_max_doctors' LIMIT 1");
                    $stmtS->execute();
                    $val = $stmtS->fetchColumn();
                    if ($val !== false && $val !== null && is_numeric($val)) {
                        $effMaxDoc = intval($val);
                    }
                }
            } elseif ($planType === 'monthly') {
                if (!empty($tenant['custom_monthly_doctors'])) {
                    $effMaxDoc = intval($tenant['custom_monthly_doctors']);
                }
            } elseif ($planType === 'yearly') {
                if (!empty($tenant['custom_yearly_doctors'])) {
                    $effMaxDoc = intval($tenant['custom_yearly_doctors']);
                }
            } elseif ($planType === 'one_time') {
                if (isset($tenant['custom_lifetime_doctors']) && $tenant['custom_lifetime_doctors'] !== null) {
                    $effMaxDoc = intval($tenant['custom_lifetime_doctors']);
                }
            }
            
            // Add addon doctor slots to the effective quota
            $addonDoctors = isset($tenant['addon_doctors']) ? intval($tenant['addon_doctors']) : 0;
            if ($addonDoctors > 0) {
                $effMaxDoc += $addonDoctors;
            }
            
            if ($effMaxDoc > 0 && $effMaxDoc !== intval($tenant['max_doctors'] ?? 0)) {
                $tenant['max_doctors'] = $effMaxDoc;
                try {
                    $stmtSync = $masterConn->prepare("UPDATE tenants SET max_doctors = ? WHERE id = ?");
                    $stmtSync->execute([$effMaxDoc, $tenant['id']]);
                } catch (Exception $e) {
                    // Best effort sync
                }
            }
            
            // Save tenant info globally accessible in the object
            $this->tenantInfo = $tenant;
            
            // 4. Set Tenant Credentials
            $this->host = $tenant['db_host'];
            $this->dbname = $tenant['db_name'];
            $this->username = $tenant['db_user'];
            $this->password = $tenant['db_password'];
            
            // 5. Connect to Tenant DB
            $tenantDsn = "mysql:host={$this->host};dbname={$this->dbname};charset={$this->charset}";
            $this->connection = new PDO($tenantDsn, $this->username, $this->password, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$this->charset}"
            ]);
            
            // Self-heal tenant DB schema for clinics table
            $this->ensureTenantClinicsSchema($this->connection);

            // Close master connection
            $masterConn = null;
            
        } catch (PDOException $e) {
            error_log("Database Connection Error: " . $e->getMessage());
            die("<h1>System Error</h1><p>Database connection failed. Please check configuration.</p>");
        }
    }
    
    /**
     * Self-healing tenant database schema for clinics table
     */
    private function ensureTenantClinicsSchema($tenantConn) {
        try {
            $tableCheck = $tenantConn->query("SHOW TABLES LIKE 'clinics'")->fetch();
            if ($tableCheck) {
                $cols = $tenantConn->query("SHOW COLUMNS FROM clinics")->fetchAll(PDO::FETCH_COLUMN);
                $colDefinitions = [
                    'logo' => "VARCHAR(255) NULL AFTER name",
                    'email' => "VARCHAR(150) NULL",
                    'phone' => "VARCHAR(20) NULL",
                    'address' => "TEXT NULL",
                    'city' => "VARCHAR(100) NULL",
                    'state' => "VARCHAR(100) NULL",
                    'pincode' => "VARCHAR(10) NULL",
                    'website' => "VARCHAR(200) NULL",
                    'pan_number' => "VARCHAR(20) NULL",
                    'gst_number' => "VARCHAR(20) NULL"
                ];

                foreach ($colDefinitions as $colName => $colDef) {
                    if (!in_array($colName, $cols)) {
                        $tenantConn->exec("ALTER TABLE clinics ADD COLUMN {$colName} {$colDef}");
                    }
                }

                // Ensure a clinics row exists for this tenant's ID so FK constraints on users/doctors don't fail
                $tenantId = intval($this->tenantInfo['id'] ?? 0);
                if ($tenantId > 0) {
                    $existingRow = $tenantConn->prepare("SELECT id FROM clinics WHERE id = ?");
                    $existingRow->execute([$tenantId]);
                    if (!$existingRow->fetch()) {
                        // Copy data from row id=1 if it exists, otherwise create fresh
                        $oldRow = $tenantConn->query("SELECT * FROM clinics WHERE id = 1")->fetch();
                        if ($oldRow) {
                            $clinicName = $oldRow['name'] ?: ($this->tenantInfo['clinic_name'] ?? 'My Clinic');
                            $stmt = $tenantConn->prepare("INSERT INTO clinics (id, name, logo, email, phone, address, city, state, pincode, website, pan_number, gst_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE id=id");
                            $stmt->execute([$tenantId, $clinicName, $oldRow['logo'] ?? null, $oldRow['email'] ?? null, $oldRow['phone'] ?? null, $oldRow['address'] ?? null, $oldRow['city'] ?? null, $oldRow['state'] ?? null, $oldRow['pincode'] ?? null, $oldRow['website'] ?? null, $oldRow['pan_number'] ?? null, $oldRow['gst_number'] ?? null]);
                        } else {
                            $clinicName = $this->tenantInfo['clinic_name'] ?? 'My Clinic';
                            $stmt = $tenantConn->prepare("INSERT INTO clinics (id, name) VALUES (?, ?) ON DUPLICATE KEY UPDATE id=id");
                            $stmt->execute([$tenantId, $clinicName]);
                        }
                    }

                    // Migrate legacy rows from clinic_id=1 to the correct tenant ID
                    if ($tenantId > 1) {
                        $tablesToMigrate = ['departments', 'branches', 'doctors', 'users', 'patients', 'appointments', 'invoices', 'invoice_items', 'payments', 'services', 'prescriptions', 'dental_charts', 'dental_treatments', 'dental_procedures', 'doctor_schedules', 'doctor_leaves', 'patient_allergies', 'patient_medical_history', 'patient_documents', 'patient_vaccinations', 'medicines', 'lab_tests', 'diagnoses', 'message_templates', 'communication_logs'];
                        foreach ($tablesToMigrate as $tbl) {
                            try {
                                $tblCheck = $tenantConn->query("SHOW TABLES LIKE '{$tbl}'")->fetch();
                                if ($tblCheck) {
                                    $colCheck = $tenantConn->query("SHOW COLUMNS FROM `{$tbl}` LIKE 'clinic_id'")->fetch();
                                    if ($colCheck) {
                                        $tenantConn->exec("UPDATE `{$tbl}` SET clinic_id = {$tenantId} WHERE clinic_id = 1");
                                    }
                                }
                            } catch (\Throwable $e) {
                                // Skip tables that don't exist or have issues
                            }
                        }
                    }
                }

                // Fix null/empty prescription statuses
                try {
                    $tenantConn->exec("UPDATE prescriptions SET status = 'completed' WHERE status IS NULL OR status = '' OR status = 'draft'");
                } catch (\Throwable $e) {}
            }
        } catch (Exception $e) {
            error_log("Tenant clinics schema check error: " . $e->getMessage());
        }
    }
    
    /**
     * Self-healing master database migrations for subscriptions and global settings
     */
    private function ensureMasterSubscriptionsSchema($masterConn) {
        try {
            // Check if max_doctors column exists in tenants
            $colCheck = $masterConn->query("SHOW COLUMNS FROM tenants LIKE 'max_doctors'")->fetch();
            if (!$colCheck) {
                $masterConn->exec("ALTER TABLE tenants 
                    ADD COLUMN plan_type ENUM('trial', 'monthly', 'yearly', 'one_time', 'custom') DEFAULT 'trial' AFTER status,
                    ADD COLUMN billing_cycle ENUM('trial', 'monthly', 'yearly', 'one_time') DEFAULT 'trial' AFTER plan_type,
                    ADD COLUMN max_doctors INT DEFAULT 2 AFTER billing_cycle,
                    ADD COLUMN trial_ends_at DATETIME NULL AFTER max_doctors,
                    ADD COLUMN subscription_starts_at DATETIME NULL AFTER trial_ends_at,
                    ADD COLUMN subscription_ends_at DATETIME NULL AFTER subscription_starts_at,
                    ADD COLUMN is_lifetime TINYINT(1) DEFAULT 0 AFTER subscription_ends_at,
                    ADD COLUMN bonus_months INT DEFAULT 0 AFTER is_lifetime,
                    ADD COLUMN plan_amount DECIMAL(10,2) DEFAULT 0.00 AFTER bonus_months,
                    ADD COLUMN currency VARCHAR(10) DEFAULT 'INR' AFTER plan_amount,
                    ADD COLUMN subscription_status ENUM('trial', 'active', 'expired', 'grace_period', 'suspended') DEFAULT 'trial' AFTER currency");
                
                // Seed initial subscription for existing tenants
                $masterConn->exec("UPDATE tenants 
                    SET subscription_starts_at = NOW(),
                        trial_ends_at = DATE_ADD(NOW(), INTERVAL 3 MONTH),
                        subscription_ends_at = DATE_ADD(NOW(), INTERVAL 3 MONTH),
                        subscription_status = 'active',
                        max_doctors = 5
                    WHERE subscription_ends_at IS NULL AND is_lifetime = 0");
            }
            
            // Check if custom clinic plan pricing columns exist
            $colCustomCheck = $masterConn->query("SHOW COLUMNS FROM tenants LIKE 'custom_monthly_price'")->fetch();
            if (!$colCustomCheck) {
                $masterConn->exec("ALTER TABLE tenants 
                    ADD COLUMN custom_monthly_price DECIMAL(10,2) NULL,
                    ADD COLUMN custom_monthly_doctors INT NULL,
                    ADD COLUMN custom_yearly_price DECIMAL(10,2) NULL,
                    ADD COLUMN custom_yearly_doctors INT NULL,
                    ADD COLUMN custom_yearly_bonus_months INT NULL,
                    ADD COLUMN custom_lifetime_price DECIMAL(10,2) NULL,
                    ADD COLUMN custom_lifetime_doctors INT NULL,
                    ADD COLUMN custom_trial_months INT NULL,
                    ADD COLUMN custom_trial_doctors INT NULL");
            }
            
            // Ensure tenant_subscription_payments exists
            $masterConn->exec("CREATE TABLE IF NOT EXISTS tenant_subscription_payments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT NOT NULL,
                plan_type VARCHAR(50) NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                payment_mode ENUM('cash_on_hand', 'bank_transfer', 'upi', 'cheque', 'razorpay') NOT NULL,
                payment_reference VARCHAR(100) NULL,
                collected_by VARCHAR(100) NULL,
                period_start DATETIME NOT NULL,
                period_end DATETIME NULL,
                bonus_months_granted INT DEFAULT 0,
                doctor_limit_granted INT DEFAULT 2,
                status ENUM('completed', 'pending', 'refunded') DEFAULT 'completed',
                notes TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_tenant (tenant_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            
            // Check if gst_amount column exists in tenant_subscription_payments
            $colGstCheck = $masterConn->query("SHOW COLUMNS FROM tenant_subscription_payments LIKE 'gst_amount'")->fetch();
            if (!$colGstCheck) {
                $masterConn->exec("ALTER TABLE tenant_subscription_payments 
                    ADD COLUMN base_amount DECIMAL(10,2) DEFAULT 0.00 AFTER amount,
                    ADD COLUMN gst_rate DECIMAL(5,2) DEFAULT 18.00 AFTER base_amount,
                    ADD COLUMN gst_amount DECIMAL(10,2) DEFAULT 0.00 AFTER gst_rate");
            }
            
            // Ensure status column in tenant_subscription_payments allows 'cancelled' (change from restrictive ENUM to VARCHAR(50))
            try {
                $masterConn->exec("ALTER TABLE tenant_subscription_payments MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'completed'");
                $masterConn->exec("UPDATE tenant_subscription_payments SET status = 'cancelled' WHERE status = '' OR status = 'refunded' OR status NOT IN ('completed', 'pending')");
            } catch (\Throwable $e) {}

            // Check if addon_doctors column exists in tenants
            $colAddonCheck = $masterConn->query("SHOW COLUMNS FROM tenants LIKE 'addon_doctors'")->fetch();
            if (!$colAddonCheck) {
                $masterConn->exec("ALTER TABLE tenants 
                    ADD COLUMN addon_doctors INT DEFAULT 0 AFTER max_doctors,
                    ADD COLUMN custom_addon_doctor_monthly_price DECIMAL(10,2) NULL,
                    ADD COLUMN custom_addon_doctor_yearly_price DECIMAL(10,2) NULL");
            }
            
            // Check if custom_razorpay_key_id column exists in tenants
            $colRzpCheck = $masterConn->query("SHOW COLUMNS FROM tenants LIKE 'custom_razorpay_key_id'")->fetch();
            if (!$colRzpCheck) {
                $masterConn->exec("ALTER TABLE tenants 
                    ADD COLUMN custom_razorpay_key_id VARCHAR(100) NULL,
                    ADD COLUMN custom_razorpay_key_secret VARCHAR(100) NULL");
            }
            
            // Check if gst_number column exists in tenants
            $colTenantGst = $masterConn->query("SHOW COLUMNS FROM tenants LIKE 'gst_number'")->fetch();
            if (!$colTenantGst) {
                $masterConn->exec("ALTER TABLE tenants 
                    ADD COLUMN gst_number VARCHAR(20) NULL,
                    ADD COLUMN pan_number VARCHAR(20) NULL,
                    ADD COLUMN billing_address TEXT NULL");
            }
            
            // Ensure saas_global_settings exists
            $masterConn->exec("CREATE TABLE IF NOT EXISTS saas_global_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(100) UNIQUE NOT NULL,
                setting_value TEXT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            
            // Seed settings if empty
            $count = $masterConn->query("SELECT COUNT(*) as c FROM saas_global_settings")->fetch()['c'] ?? 0;
            if ($count == 0) {
                $stmt = $masterConn->prepare("INSERT INTO saas_global_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                $defaultSettings = [
                    'trial_duration_months' => '1',
                    'trial_max_doctors' => '2',
                    'monthly_price' => '1499',
                    'monthly_max_doctors' => '3',
                    'yearly_price' => '14999',
                    'yearly_max_doctors' => '10',
                    'yearly_default_bonus_months' => '2',
                    'one_time_price' => '49999',
                    'one_time_max_doctors' => '0',
                    'addon_doctor_monthly_price' => '25',
                    'addon_doctor_yearly_price' => '250',
                    'razorpay_key_id' => 'rzp_test_S2WE1vnYYcKVAm',
                    'razorpay_key_secret' => 'IkgTWkbFrmzpT0Gg0j3gvKK7',
                    'platform_company_name' => 'Feature Gen Technologies',
                    'platform_gstin' => '',
                    'platform_pan' => '',
                    'platform_address' => 'Chennai, Tamil Nadu, India',
                    'platform_state' => 'Tamil Nadu (State Code: 33)',
                    'offline_payment_contact' => 'Phone: +91 98765 43210 | WhatsApp: +91 98765 43210',
                    'offline_bank_details' => "Account Name: Feature Gen Technologies\nAccount Number: 123456789012\nBank: HDFC Bank\nIFSC: HDFC0001234\nUPI ID: featuregen@upi"
                ];
                foreach ($defaultSettings as $k => $v) {
                    $stmt->execute([$k, $v]);
                }
            } else {
                // Ensure addon pricing and platform legal keys exist
                $masterConn->exec("INSERT IGNORE INTO saas_global_settings (setting_key, setting_value) VALUES 
                    ('addon_doctor_monthly_price', '25'),
                    ('addon_doctor_yearly_price', '250'),
                    ('platform_company_name', 'Feature Gen Technologies'),
                    ('platform_gstin', ''),
                    ('platform_pan', ''),
                    ('platform_address', 'Chennai, Tamil Nadu, India'),
                    ('platform_state', 'Tamil Nadu (State Code: 33)')");
                
                // Auto-populate Razorpay test keys if currently empty
                $masterConn->exec("UPDATE saas_global_settings SET setting_value = 'rzp_test_S2WE1vnYYcKVAm' WHERE setting_key = 'razorpay_key_id' AND (setting_value = '' OR setting_value IS NULL)");
                $masterConn->exec("UPDATE saas_global_settings SET setting_value = 'IkgTWkbFrmzpT0Gg0j3gvKK7' WHERE setting_key = 'razorpay_key_secret' AND (setting_value = '' OR setting_value IS NULL)");
            }
        } catch (PDOException $e) {
            error_log("Master DB Self-healing migration error: " . $e->getMessage());
        }
    }
    
    /**
     * Get Master Database Connection
     */
    public function getMasterConnection() {
        $this->setMasterCredentials();
        $masterDsn = "mysql:host={$this->masterHost};dbname={$this->masterDbName};charset={$this->charset}";
        return new PDO($masterDsn, $this->masterUsername, $this->masterPassword, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    
    /**
     * Get singleton instance
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Get PDO connection
     */
    public function getConnection() {
        return $this->connection;
    }
    
    /**
     * Execute a query with parameters
     */
    public function query($sql, $params = []) {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("Query Error: " . $e->getMessage() . " | SQL: " . $sql);
            throw $e;
        }
    }
    
    /**
     * Fetch all results
     */
    public function fetchAll($sql, $params = []) {
        return $this->query($sql, $params)->fetchAll();
    }
    
    /**
     * Fetch single row
     */
    public function fetch($sql, $params = []) {
        return $this->query($sql, $params)->fetch();
    }
    
    /**
     * Get last insert ID
     */
    public function lastInsertId() {
        return $this->connection->lastInsertId();
    }
    
    /**
     * Begin transaction
     */
    public function beginTransaction() {
        return $this->connection->beginTransaction();
    }
    
    /**
     * Commit transaction
     */
    public function commit() {
        return $this->connection->commit();
    }
    
    /**
     * Rollback transaction
     */
    public function rollback() {
        return $this->connection->rollBack();
    }
    
    /**
     * Get row count from last query
     */
    public function rowCount($sql, $params = []) {
        return $this->query($sql, $params)->rowCount();
    }
    
    // Prevent cloning
    private function __clone() {}
}

// Helper function for quick access
function db() {
    return Database::getInstance();
}

// Helper function for Master DB connection
function master_db() {
    return Database::getInstance()->getMasterConnection();
}
?>
