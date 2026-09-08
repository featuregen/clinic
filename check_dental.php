require_once __DIR__ . '/config/database.php';

try {
    $pdo = db()->getConnection();
    $stmt = $pdo->query("SHOW TABLES LIKE '%dental%'");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $stmt2 = $pdo->query("SHOW TABLES LIKE '%tooth%'");
    $tables2 = $stmt2->fetchAll(PDO::FETCH_COLUMN);
    
    echo "Dental Tables: " . implode(', ', $tables) . "\n";
    echo "Tooth Tables: " . implode(', ', $tables2) . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
