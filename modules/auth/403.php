<?php
/**
 * 403 Forbidden Page
 * Advanced Clinic Suite
 */
$pageTitle = 'Access Denied';
?>
<div class="empty-state" style="padding: 100px 20px;">
    <i class="fas fa-shield-alt" style="font-size: 64px; color: var(--danger);"></i>
    <h2 style="margin-top: 16px;">403 - Access Denied</h2>
    <p>You don't have permission to access this page. Contact your administrator if you believe this is an error.</p>
    <a href="<?= BASE_URL ?>/modules/dashboard/index.php" class="btn btn-primary" style="margin-top: 16px;">
        <i class="fas fa-home"></i> Go to Dashboard
    </a>
</div>
