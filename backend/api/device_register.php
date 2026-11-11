<?php
// ============================================================
// POST /api/device/register
// Called on first app launch (or after app reinstall).
// Creates or retrieves a user account tied to a device.
// 
// Body: {
//   "android_id":    "abc123...",   -- Settings.Secure.ANDROID_ID
//   "hardware_id":   "...",          -- Build.SERIAL (may be null on Android 10+)
//   "device_model":  "Samsung Galaxy A14",
//   "os_version":    "13",
//   "app_version":   "1.0.0",
//   "firebase_uid":  "..."           -- optional; from Firebase anonymous auth
// }
// Returns: { "token": "...", "uuid": "...", "is_new_user": true }
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
rate_limit_check($ip, 'device_register');

$body        = get_json_body();
$android_id  = trim((string)($body['android_id']   ?? ''));
$hardware_id = trim((string)($body['hardware_id']  ?? ''));
$model       = substr(trim((string)($body['device_model'] ?? '')), 0, 100);
$os_ver      = substr(trim((string)($body['os_version']   ?? '')), 0, 20);
$app_ver     = substr(trim((string)($body['app_version']  ?? '')), 0, 20);
$firebase_uid = trim((string)($body['firebase_uid'] ?? ''));

if ($android_id === '' && $hardware_id === '' && $firebase_uid === '') {
    json_error('At least one device identifier is required (android_id, hardware_id, or firebase_uid)');
}

$db = DB::connect();
$db->beginTransaction();

try {
    $user       = null;
    $is_new_user = false;

    // --- 1. Try to match existing user by firebase_uid first ---
    if ($firebase_uid !== '') {
        $stmt = $db->prepare('SELECT id, uuid, is_banned FROM users WHERE firebase_uid = ? LIMIT 1');
        $stmt->execute([$firebase_uid]);
        $user = $stmt->fetch() ?: null;
    }

    // --- 2. Fall back to android_id match ---
    if (!$user && $android_id !== '') {
        $stmt = $db->prepare('SELECT id, uuid, is_banned FROM users WHERE android_id = ? LIMIT 1');
        $stmt->execute([$android_id]);
        $user = $stmt->fetch() ?: null;
    }

    // --- 3. Still nothing — create a new anonymous user ---
    if (!$user) {
        // Check how many accounts share this android_id (abuse signal)
        if ($android_id !== '') {
            $stmt = $db->prepare('SELECT COUNT(*) AS cnt FROM users WHERE android_id = ?');
            $stmt->execute([$android_id]);
            if ((int)$stmt->fetch()['cnt'] >= 3) {
                $db->rollBack();
                json_error('Account limit reached for this device', 403);
            }
        }

        $uuid     = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff), random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
        );
        // Auto-generate a username; can be changed in profile later
        $username = 'player_' . substr(str_replace('-', '', $uuid), 0, 8);

        // Devices don't have a password — they auth via device token only.
        // A bcrypt hash of a random 32-byte secret is stored (never used for login).
        $password_hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);

        $stmt = $db->prepare(
            'INSERT INTO users
                (uuid, username, password_hash, device_id, android_id, firebase_uid)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $uuid,
            $username,
            $password_hash,
            $android_id ?: ($hardware_id ?: null),
            $android_id ?: null,
            $firebase_uid ?: null,
        ]);
        $user_id = (int)$db->lastInsertId();

        $db->prepare('INSERT INTO balances (user_id) VALUES (?)')->execute([$user_id]);

        $user        = ['id' => $user_id, 'uuid' => $uuid, 'is_banned' => 0];
        $is_new_user = true;
    }

    if ((int)$user['is_banned'] === 1) {
        $db->rollBack();
        json_error('This device has been suspended', 403);
    }

    $user_id = (int)$user['id'];

    // --- 4. Log / upsert device fingerprint ---
    $stmt = $db->prepare(
        'INSERT INTO device_fingerprints
            (user_id, hardware_id, android_id, device_model, os_version, app_version, ip_address)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            last_seen_at = NOW(), ip_address = VALUES(ip_address), app_version = VALUES(app_version)'
    );
    // No true UNIQUE key to trigger ON DUPLICATE here without android_id; just insert
    $stmt->execute([
        $user_id,
        $hardware_id ?: null,
        $android_id  ?: null,
        $model       ?: null,
        $os_ver      ?: null,
        $app_ver     ?: null,
        $ip,
    ]);

    // --- 5. Create app session ---
    $session_token = bin2hex(random_bytes(32));
    $db->prepare(
        'INSERT INTO app_sessions (user_id, session_token, ip_address) VALUES (?, ?, ?)'
    )->execute([$user_id, $session_token, $ip]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    error_log('device_register failed: ' . $e->getMessage());
    json_error('Registration failed. Please try again.', 500);
}

$token = Auth::issueToken($user['uuid']);

json_ok([
    'token'       => $token,
    'uuid'        => $user['uuid'],
    'is_new_user' => $is_new_user,
], $is_new_user ? 201 : 200);
