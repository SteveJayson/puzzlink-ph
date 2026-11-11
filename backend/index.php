<?php
declare(strict_types=1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json');

// Strip /api prefix if present, then clean trailing slash
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = preg_replace('#^/api#', '', $uri);
$uri = rtrim($uri, '/');
if ($uri === '') $uri = '/';

$method = $_SERVER['REQUEST_METHOD'];

$routes = [
    'POST:/register'            => __DIR__ . '/api/register.php',
    'POST:/login'               => __DIR__ . '/api/login.php',
    'POST:/device/register'     => __DIR__ . '/api/device_register.php',
    'POST:/level/complete'      => __DIR__ . '/api/level_complete.php',
    'POST:/ad/reward'           => __DIR__ . '/api/ad_reward.php',
    'POST:/ad/validate'         => __DIR__ . '/api/ad_validate.php',
    'GET:/ad/ssv'               => __DIR__ . '/api/ad_ssv_webhook.php',
    'POST:/offerwall/callback'  => __DIR__ . '/api/offerwall_callback.php',
    'GET:/offerwall/callback'   => __DIR__ . '/api/offerwall_callback.php',
    'POST:/xendit/webhook'      => __DIR__ . '/api/xendit_webhook.php',
    'GET:/balance'              => __DIR__ . '/api/balance.php',
    'GET:/levels'               => __DIR__ . '/api/levels.php',
    'POST:/payout/request'      => __DIR__ . '/api/payout_request.php',
    'GET:/payout/status'        => __DIR__ . '/api/payout_status.php',
];

$key = $method . ':' . $uri;

if (isset($routes[$key])) {
    require $routes[$key];
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Endpoint not found', 'debug_key' => $key]);
}
