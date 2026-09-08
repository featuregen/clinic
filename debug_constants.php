<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>Constant Check</h1>";
echo "Reading file directly:<br>";
$content = file_get_contents(__DIR__ . '/config/constants.php');
// Extract line 58
$lines = explode("\n", $content);
foreach ($lines as $i => $line) {
    if (strpos($line, 'CURRENCY_SYMBOL') !== false) {
        echo "Line " . ($i+1) . ": " . htmlspecialchars($line) . "<br>";
    }
}

echo "<br>Including file:<br>";
require_once __DIR__ . '/config/constants.php';
echo "Constant Value: [" . CURRENCY_SYMBOL . "]<br>";
?>
