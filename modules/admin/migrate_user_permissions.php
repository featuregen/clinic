<?php
/**
 * Migration: Add user_permissions table for individual user overrides
 * Run once at: /Clinic/clinic-web/modules/admin/migrate_user_permissions.php
 */
require_once dirname(dirname(__DIR__)) . '/config/session.php';
requireAuth();
requireRole([ROLE_SUPER_ADMIN]);

$db = db();

$sql = "CREATE TABLE IF NOT EXISTS user_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    permission_id INT NOT NULL,
    type ENUM('grant','deny') NOT NULL DEFAULT 'grant'
        COMMENT 'grant = allow even if role does not have it; deny = block even if role has it',
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_perm (user_id, permission_id)
) ENGINE=InnoDB;";

try {
    $db->query($sql);
    echo "<p style='color:green;font-family:monospace;'>✅ user_permissions table created (or already exists).</p>";
    echo "<p><a href='" . BASE_URL . "/modules/admin/index.php'>← Back to Admin</a></p>";
} catch (Exception $e) {
    echo "<p style='color:red;font-family:monospace;'>❌ Error: " . $e->getMessage() . "</p>";
}
