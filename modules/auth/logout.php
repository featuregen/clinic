<?php
/**
 * Logout Handler
 * Advanced Clinic Suite
 */

require_once dirname(dirname(__DIR__)) . '/config/session.php';
require_once dirname(dirname(__DIR__)) . '/config/database.php';

if (isLoggedIn()) {
    logAudit('logout', 'auth', 'user', getCurrentUserId(), null, null, 'User logged out');
}

destroySession();
header('Location: ' . BASE_URL . '/index.php');
exit;
?>
