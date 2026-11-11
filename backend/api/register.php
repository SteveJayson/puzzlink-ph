<?php
// ============================================================
// POST /api/register
// Body: { "username": "...", "password": "...", "device_id": "..." }
// Returns: { "token": "...", "uuid": "..." }
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
rate_limit_check($ip, 'register');

$body    = get_json_body();
$username  = trim((string)($body['username']  ?? ''));
$password  = (string)($body['password']  ?? '');
$device_id = trim((string)($body['device_id'] ?? ''));

// --- Validation ---
if (strlen($username) < 3 || strlen($username) > 50) {
    json_error('Username must be 3–50 characters');
}
if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
    json_error('Username may only contain letters, numbers, and underscores');
}
if (strlen($password) < 8) {
    json_error('Password must be at least 8 characters');
}

$db = DB::connect();

// --- Check uniqueness ---
$stmt = $db->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
$stmt->execute([$username]);
if ($stmt->fetch()) {
    json_error('Username already taken', 409);
}

// --- Soft device-fingerprint block (too many accounts from one device) ---
if ($device_id !== '') {
    $stmt = $db->prepare('SELECT COUNT(*) AS cnt FROM users WHERE device_id = ?');
    $stmt->execute([$device_id]);
    if ((int)$stmt->fetch()['cnt'] >= 3) {
        json_error('Too many accounts registered from this device', 403);
    }
}

// --- Create user ---
$uuid          = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
    random_int(0, 0xffff), random_int(0, 0xffff),
    random_int(0, 0xffff),
    random_int(0, 0x0fff) | 0x4000,
    random_int(0, 0x3fff) | 0x8000,
    random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
);
$password_hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

$db->beginTransaction();
try {
    $stmt = $db->prepare(
        'INSERT INTO users (uuid, username, password_hash, device_id)
         VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$uuid, $username, $password_hash, $device_id ?: null]);
    $user_id = (int)$db->lastInsertId();

    // Create balance row
    $db->prepare('INSERT INTO balances (user_id) VALUES (?)')->execute([$user_id]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    error_log('register failed: ' . $e->getMessage());
    json_error('Registration failed. Please try again.', 500);
}

$token = Auth::issueToken($uuid);
json_ok(['token' => $token, 'uuid' => $uuid, 'username' => $username], 201);
