<?php
/**
 * Delete Template
 */
require_once dirname(dirname(__DIR__)) . '/config/session.php';
requirePermission('templates.delete'); // Or manage

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? 0;
    
    if ($id) {
        $db = db();
        $stmt = $db->query("DELETE FROM prescription_templates WHERE id = ?", [$id]);
        
        if ($stmt) {
            header('Location: list.php?msg=deleted');
        } else {
            header('Location: list.php?error=failed');
        }
    } else {
        header('Location: list.php');
    }
} else {
    header('Location: list.php');
}
