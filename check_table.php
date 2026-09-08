<?php
require_once __DIR__ . '/config/session.php';
try {
    $db = db();
    $stmt = $db->query("DESCRIBE prescription_templates");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Table exists. Columns:\n";
    foreach ($columns as $col) {
        echo $col['Field'] . " (" . $col['Type'] . ")\n";
    }
} catch (Exception $e) {
    echo "Table does not exist or error: " . $e->getMessage();
}
