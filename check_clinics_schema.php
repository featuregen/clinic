<?php
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/config/database.php';

try {
    $db = db();
    $pdo = $db->getConnection();
    
    echo "=== TENANT INFO ===\n";
    print_r($db->tenantInfo);
    
    echo "\n=== COLUMNS IN 'clinics' TABLE ===\n";
    $cols = $pdo->query("SHOW COLUMNS FROM clinics")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo "{$c['Field']} ({$c['Type']})\n";
    }
    
    echo "\n=== ROWS IN 'clinics' TABLE ===\n";
    $rows = $pdo->query("SELECT * FROM clinics")->fetchAll(PDO::FETCH_ASSOC);
    print_r($rows);

    echo "\n=== MASTER TENANTS RECORD ===\n";
    try {
        $master = master_db();
        $tenantId = intval($db->tenantInfo['id'] ?? 0);
        $mRow = $master->query("SELECT id, clinic_name, subdomain, db_name, gst_number, pan_number, billing_address FROM tenants WHERE id = {$tenantId}")->fetch(PDO::FETCH_ASSOC);
        print_r($mRow);
    } catch (Exception $me) {
        echo "Master error: " . $me->getMessage() . "\n";
    }
    
} catch (PDOException $e) {
    echo "Database Error: " . $e->getMessage() . "\n";
}
