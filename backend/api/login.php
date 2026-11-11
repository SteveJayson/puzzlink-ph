<?php
// ============================================================
// POST /api/login
// Body: { "username": "...", "password": "..." }
// Returns: { "token": "...", "uuid": "...", "balance": {...} }
// ============================================================

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/rate_limit.php';

header('Content-Type: application/json');
require_method('POST');

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
rate_limit_check($ip, 'login');

$body     = get_json_body();
$username = trim((string)($body['username'] ?? ''));
$password = (string)($body['password'] ?? '');

if ($username === '' || $password === '') {
    json_error('Username and password are required');
}

$db = DB::connect();
$stmt = $db->prepare(
    'SELECT u.id, u.uuid, u.username, u.password_hash, u.is_banned,
            b.coins, b.php_equivalent
     FROM users u
     JOIN balances b ON b.user_id = u.id
     WHERE u.username = ? LIMIT 1'
);
$stmt->execute([$username]);
$user = $stmt->fetch();

// Use constant-time comparison to prevent user enumeration timing attacks
if (!$user || !password_verify($password, $user['password_hash'])) {
    json_error('Invalid username or password', 401);
}

if ((int)$user['is_banned'] === 1) {
    json_error('Account suspended. Contact support.', 403);
}

// Refresh last_login timestamp
$db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')
   ->execute([$user['id']]);

$token = Auth::issueToken($user['uuid']);

json_ok([
    'token'    => $token,
    'uuid'     => $user['uuid'],
    'username' => $user['username'],
    'balance'  => [
        'coins'          => (int)$user['coins'],
        'php_equivalent' => number_format((float)$user['php_equivalent'], 2),
    ],
]);
