require_once __DIR__ . '/config/database.php';

try {
    $pdo = db()->getConnection();
    
    echo "Table: vaccines\n";
    $stmt = $pdo->query("DESCRIBE vaccines");
    foreach ($stmt->fetchAll() as $col) {
        echo $col['Field'] . " " . $col['Type'] . " " . $col['Null'] . "\n";
    }
    
    echo "\nTable: vaccine_schedules\n";
    $stmt = $pdo->query("DESCRIBE vaccine_schedules");
    foreach ($stmt->fetchAll() as $col) {
        echo $col['Field'] . " " . $col['Type'] . " " . $col['Null'] . "\n";
    }
    
} catch (\PDOException $e) {
    echo "Error: " . $e->getMessage();
}
