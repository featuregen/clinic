<?php
/**
 * Header Component
 * Advanced Clinic Suite
 */

if (!defined('BASE_URL')) {
    require_once dirname(__DIR__) . '/config/session.php';
}
requireAuth();

$currentUser = [
    'id' => getCurrentUserId(),
    'name' => getSession('full_name', 'User'),
    'role' => getSession('role', 'user'),
    'email' => getSession('email', ''),
    'image' => getSession('profile_image', ''),
    'initials' => getInitials(getSession('full_name', 'U'))
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? $pageTitle . ' - ' : '' ?><?= APP_NAME ?></title>
    <meta name="description" content="<?= APP_TAGLINE ?>">
    <meta name="csrf-token" content="<?= $_SESSION['csrf_token'] ?? '' ?>">
    <link rel="icon" type="image/svg+xml" href="<?= ASSETS_URL ?>/images/favicon.svg">
    <link rel="stylesheet" href="<?= ASSETS_URL ?>/css/style.css?v=2.1">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <?php if (isset($extraCSS)): foreach ($extraCSS as $css): ?>
    <link rel="stylesheet" href="<?= $css ?>">
    <?php endforeach; endif; ?>
    <script>
        const BASE_URL = '<?= BASE_URL ?>';
        const ASSETS_URL = '<?= ASSETS_URL ?>';
        const API_URL = '<?= API_URL ?>';
    </script>
</head>
<body>
<div class="app-wrapper">
    <!-- Sidebar Overlay (Mobile) -->
    <div class="sidebar-overlay"></div>
    
    <?php include __DIR__ . '/sidebar.php'; ?>
    
    <div class="main-content">
        <header class="header">
            <div class="header-left">
                <button class="sidebar-toggle" id="sidebarToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <?php if (isset($pageTitle)): ?>
                <h2 class="page-title"><?= $pageTitle ?></h2>
                <?php endif; ?>
            </div>
            
            <div class="header-search">
                <i class="fas fa-search"></i>
                <input type="text" id="globalSearch" placeholder="Search patients, doctors, appointments...">
            </div>
            
            <div class="header-right">
                <button class="header-btn" title="Notifications" data-dropdown="notifDropdown">
                    <i class="fas fa-bell"></i>
                    <span class="notification-dot"></span>
                </button>
                
                <div class="dropdown">
                    <button class="user-dropdown" data-dropdown="userDropdown">
                        <div class="user-avatar">
                            <?php if ($currentUser['image']): ?>
                                <img src="<?= UPLOADS_URL . '/' . $currentUser['image'] ?>" alt="Profile">
                            <?php else: ?>
                                <?= $currentUser['initials'] ?>
                            <?php endif; ?>
                        </div>
                        <div class="user-info">
                            <div class="user-name"><?= sanitizeOutput($currentUser['name']) ?></div>
                            <div class="user-role"><?= ucfirst(str_replace('_', ' ', $currentUser['role'])) ?></div>
                        </div>
                        <i class="fas fa-chevron-down" style="font-size: 10px; color: var(--text-muted);"></i>
                    </button>
                    
                    <div class="dropdown-menu" id="userDropdown">
                        <a href="<?= BASE_URL ?>/modules/auth/profile.php" class="dropdown-item">
                            <i class="fas fa-user"></i> My Profile
                        </a>
                        <a href="<?= BASE_URL ?>/modules/clinic/settings.php" class="dropdown-item">
                            <i class="fas fa-cog"></i> Settings
                        </a>
                        <div class="dropdown-divider"></div>
                        <a href="<?= BASE_URL ?>/modules/auth/logout.php" class="dropdown-item" style="color: var(--danger);">
                            <i class="fas fa-sign-out-alt"></i> Sign Out
                        </a>
                    </div>
                </div>
                
                <!-- Notification Dropdown -->
                <div class="dropdown-menu" id="notifDropdown" style="min-width: 340px; right: 60px;">
                    <div style="padding: 12px 16px; border-bottom: 1px solid var(--border-color);">
                        <strong>Notifications</strong>
                    </div>
                    <div style="padding: 20px; text-align: center; color: var(--text-muted); font-size: 13px;">
                        <i class="fas fa-bell-slash" style="font-size: 24px; margin-bottom: 8px; display: block; color: var(--gray-300);"></i>
                        No new notifications
                    </div>
                </div>
            </div>
        </header>
        
        <div class="content-area">
            <?php
            // Flash messages
            $flash = getFlashMessage();
            if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] ?>" data-auto-dismiss="5000">
                <i class="fas fa-<?= $flash['type'] === 'success' ? 'check-circle' : ($flash['type'] === 'error' ? 'times-circle' : 'info-circle') ?>"></i>
                <?= sanitizeOutput($flash['message']) ?>
            </div>
            <?php endif; ?>
