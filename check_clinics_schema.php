<?php
require_once __DIR__ . '/config/database.php';

try {
    $pdo = db()->getConnection();
    
    // Check if 'logo' column exists in 'clinics' table
    $stmt = $pdo->query("SHOW COLUMNS FROM clinics LIKE 'logo'");
    $exists = $stmt->fetch();
    
    if (!$exists) {
        $pdo->exec("ALTER TABLE clinics ADD COLUMN logo VARCHAR(255) NULL AFTER name");
        echo "Successfully added 'logo' column to 'clinics' table.\n";
    } else {
        echo "'logo' column already exists in 'clinics' table.\n";
    }
    
} catch (PDOException $e) {
    echo "Database Error: " . $e->getMessage() . "\n";
}
