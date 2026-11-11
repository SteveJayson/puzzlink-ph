<?php
// ============================================================
// router.php — PHP built-in server router
// Used by Render (php -S) instead of Apache .htaccess
// Mimics the same routing logic as index.php + .htaccess
// ============================================================

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Block direct access to internal directories
$blocked = ['config', 'middleware', 'helpers', 'anticheat', 'services'];
foreach ($blocked as $dir) {
    if (str_starts_with(ltrim($uri, '/'), $dir . '/')) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
}

// Admin panel — serve PHP files directly
if (str_starts_with($uri, '/admin/')) {
    $file = __DIR__ . $uri;
    if (is_file($file)) {
        require $file;
        return true;
    }
    http_response_code(404);
    exit;
}

// API routes — pass through index.php
if (str_starts_with($uri, '/api/')) {
    require __DIR__ . '/index.php';
    return true;
}

// Static files (privacy policy etc)
if (is_file(__DIR__ . $uri)) {
    return false; // let built-in server handle it
}

// Default
http_response_code(404);
echo json_encode(['error' => 'Not found']);
