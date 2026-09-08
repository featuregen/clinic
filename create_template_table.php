require_once __DIR__ . '/config/database.php';

try {
    $pdo = db()->getConnection();
    
    $sql = "CREATE TABLE IF NOT EXISTS prescription_templates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        clinic_id INT NOT NULL,
        name VARCHAR(100) NOT NULL,
        scope ENUM('clinic', 'personal') DEFAULT 'personal',
        doctor_id INT NULL,
        medicines JSON,
        lab_tests JSON,
        description TEXT,
        is_active TINYINT DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_clinic (clinic_id),
        KEY idx_scope (scope),
        KEY idx_doctor (doctor_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    
    $pdo->exec($sql);
    echo "Table prescription_templates created or already exists.\n";
    
} catch (\PDOException $e) {
    throw new \PDOException($e->getMessage(), (int)$e->getCode());
}
