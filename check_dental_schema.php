require_once __DIR__ . '/config/database.php';

try {
    $pdo = db()->getConnection();
    
    echo "Table: dental_charts\n";
    $stmt = $pdo->query("DESCRIBE dental_charts");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
        echo $col['Field'] . " " . $col['Type'] . "\n";
    }

    echo "\nTable: dental_treatments\n";
    $stmt = $pdo->query("DESCRIBE dental_treatments");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
        echo $col['Field'] . " " . $col['Type'] . "\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
