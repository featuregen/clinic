<?php
/**
 * Database Configuration & Connection
 * Advanced Clinic Suite
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
            // Strip port if present
            $host = explode(':', $host)[0];
            
            $parts = explode('.', $host);
            // If just 'localhost', use 'localhost'. Else use the first part (subdomain)
            $subdomain = (count($parts) > 1 && $parts[0] !== 'www') ? $parts[0] : $parts[0];
            
            // 3. Query Tenant
            $stmt = $masterConn->prepare("SELECT * FROM tenants WHERE subdomain = ?");
            $stmt->execute([$subdomain]);
            $tenant = $stmt->fetch();
            
            if (!$tenant) {
                die("<h1>Clinic Not Found</h1><p>We couldn't find a clinic associated with the URL: " . htmlspecialchars($host) . "</p>");
            }
            
            if ($tenant['status'] !== 'active') {
                die("<h1>Account Suspended</h1><p>This clinic's account is currently suspended or inactive. Please contact support.</p>");
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
            
            // Close master connection
            $masterConn = null;
            
        } catch (PDOException $e) {
            error_log("Database Connection Error: " . $e->getMessage());
            die("<h1>System Error</h1><p>Database connection failed. Please check configuration.</p>");
        }
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
?>
