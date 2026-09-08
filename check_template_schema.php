<?php
require_once 'config/database.php';
$db = db();
try {
    $stmt = $db->query("DESCRIBE prescription_templates");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
        echo $col['Field'] . " " . $col['Type'] . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
