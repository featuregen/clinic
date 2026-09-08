<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h1>Debug Dashboard V2</h1>";

try {
    require_once __DIR__ . '/config/database.php';
    require_once __DIR__ . '/config/constants.php';
    require_once __DIR__ . '/includes/functions.php'; // For formatCurrency

    $db = db()->getConnection();
    echo "Database Connected.<br>";

    $clinicId = 1; 
    $today = date('Y-m-d');

    // 1. Raw Format Check
    echo "<h2>Currency Format Details</h2>";
    echo "Symbol: [" . CURRENCY_SYMBOL . "]<br>";
    echo "Formatted 123456: [" . formatCurrency(123456) . "]<br>";
    echo "Formatted 262145: [" . formatCurrency(262145) . "]<br>";
    
    // 2. Data Check
    echo "<h2>Data Check (Clinic ID: $clinicId)</h2>";
    
    // Payments Table Check
    $stmt = $db->query("SELECT COUNT(*) as cnt FROM payments");
    echo "Total Rows in Payments: " . $stmt->fetchColumn() . "<br>";
    
    // Today's Revenue
    $sql = "SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE clinic_id = ? AND payment_date = ?";
    $stmt = $db->prepare($sql);
    $stmt->execute([$clinicId, $today]);
    $revenue = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    echo "Today's Revenue Raw: [" . $revenue . "]<br>";
    
    // 3. Outstanding Check
    $sql = "SELECT COALESCE(SUM(due_amount), 0) as total FROM invoices WHERE clinic_id = ? AND status IN ('due','partial','overdue')";
    $stmt = $db->prepare($sql);
    $stmt->execute([$clinicId]);
    $dues = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    echo "Outstanding Dues Raw: [" . $dues . "]<br>";

    // 4. Monthly Check
    $sql = "SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE clinic_id = ? AND MONTH(payment_date) = MONTH(CURDATE()) AND YEAR(payment_date) = YEAR(CURDATE())";
    $stmt = $db->prepare($sql);
    $stmt->execute([$clinicId]);
    $monthly = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    echo "Monthly Revenue Raw: [" . $monthly . "]<br>";

} catch (Throwable $e) {
    echo "<h2>Error</h2>";
    echo $e->getMessage() . "<br>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
