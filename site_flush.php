<?php
/**
 * Site Flush - Clear Cache & Session
 */
// 1. Clear PHP Session
session_start();
session_unset();
session_destroy();
write_close();
setcookie(session_name(),'',0,'/');
session_regenerate_id(true);

// 2. Clear Opcache (if function exists)
if (function_exists('opcache_reset')) {
    opcache_reset();
}

// 3. Send Clear-Site-Data Header (Clear cookies, cache, storage, executionContexts)
header('Clear-Site-Data: "cache", "cookies", "storage", "executionContexts"');
header('Cache-Control: no-cache, no-store, must-revalidate'); // HTTP 1.1.
header('Pragma: no-cache'); // HTTP 1.0.
header('Expires: 0'); // Proxies.

?>
<!DOCTYPE html>
<html>
<head>
    <title>Site Flushed</title>
    <style>
        body { font-family: system-ui, sans-serif; text-align: center; padding: 50px; background: #f0fdf4; color: #166534; }
        h1 { margin-bottom: 10px; }
        .icon { font-size: 48px; margin-bottom: 20px; }
    </style>
    <meta http-equiv="refresh" content="2;url=index.php">
</head>
<body>
    <div class="icon">🧹✨</div>
    <h1>Site Cache & Session Cleared</h1>
    <p>You have been logged out and browser cache cleared.</p>
    <p>Redirecting to login...</p>
</body>
</html>
