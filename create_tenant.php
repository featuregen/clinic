<?php
/**
 * Create Tenant Script (CLI or Admin Use)
 * Advanced Clinic Suite
 * 
 * Usage from CLI: php create_tenant.php "Clinic Name" "subdomain"
 */

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.");
}

if ($argc < 3) {
    die("Usage: php create_tenant.php \"Clinic Name\" \"subdomain\"\n");
}

$clinicName = $argv[1];
$subdomain = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $argv[2]));
$dbName = 'clinic_' . $subdomain;

// Master & Root DB Credentials (update as needed for production)
$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = 'root';
$masterDbName = 'clinic_suite_master';

try {
    echo "Connecting to MySQL server...\n";
    $pdo = new PDO("mysql:host=$dbHost;charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    // 1. Create the tenant database
    echo "Creating tenant database: $dbName...\n";
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    // 2. Read schema file
    $schemaFile = __DIR__ . '/database/schema.sql';
    if (!file_exists($schemaFile)) {
        die("Schema file not found at: $schemaFile\n");
    }
    
    // Switch to new DB
    $pdo->exec("USE `$dbName`");

    // Important: we skip the USE clinic_suite in the schema.sql by running queries directly
    // or by loading schema and replacing the USE statement
    $schemaSql = file_get_contents($schemaFile);
    
    // Hack to prevent schema from switching to default clinic_suite DB
    $schemaSql = str_replace("USE clinic_suite;", "", $schemaSql);
    $schemaSql = str_replace("CREATE DATABASE IF NOT EXISTS clinic_suite;", "", $schemaSql);

    echo "Executing schema on $dbName...\n";
    // Execute multiple statements (PDO must have emulated prepares on for multiple queries at once, or we can use exec)
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
    $pdo->exec($schemaSql);
    
    // 3. Register in Master DB
    echo "Registering tenant in Master Database...\n";
    $pdo->exec("USE `$masterDbName`");
    
    $stmt = $pdo->prepare("INSERT INTO tenants (clinic_name, subdomain, db_host, db_name, db_user, db_password, status) VALUES (?, ?, ?, ?, ?, ?, 'active') ON DUPLICATE KEY UPDATE clinic_name=VALUES(clinic_name)");
    $stmt->execute([$clinicName, $subdomain, $dbHost, $dbName, $dbUser, $dbPass]);

    echo "\nSuccess! Tenant '$clinicName' created successfully.\n";
    echo "Subdomain: $subdomain\n";
    echo "Database: $dbName\n";
    echo "URL: http://$subdomain.localhost/ (Assuming local development)\n";

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage() . "\n");
}
