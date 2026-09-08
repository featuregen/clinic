require_once __DIR__ . '/config/database.php';

try {
    $pdo = db()->getConnection();
    
    // Check columns first
    $stmt = $pdo->query("SHOW COLUMNS FROM patient_vaccinations LIKE 'schedule_id'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE patient_vaccinations ADD COLUMN schedule_id INT NULL AFTER vaccine_id");
        echo "Added schedule_id column.\n";
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM patient_vaccinations LIKE 'route'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE patient_vaccinations ADD COLUMN route VARCHAR(50) NULL AFTER site");
        echo "Added route column.\n";
    }
    
    echo "Table patient_vaccinations updated successfully.\n";
    
} catch (\PDOException $e) {
    echo "Error: " . $e->getMessage();
}
