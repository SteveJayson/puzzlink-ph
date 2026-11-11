<?php
// ============================================================
// POST /api/ad/reward
// Protected. Body: { "ad_type": "rewarded|interstitial",
//                    "ad_network": "admob", "impression_id": "..." }
// Server credits coins after verifying daily cap.
// ============================================================

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/rate_limit.php';

header('Content-Type: application/json');
require_method('POST');

$auth    = Auth::require();
$user_id = $auth['user_id'];

rate_limit_check((string)$user_id, 'ad_reward');

$body         = get_json_body();
$ad_type      = (string)($body['ad_type']      ?? '');
$ad_network   = substr(trim((string)($body['ad_network']   ?? 'unknown')), 0, 50);
$impression_id = trim((string)($body['impression_id'] ?? ''));

$allowed_types = ['rewarded', 'interstitial'];
if (!in_array($ad_type, $allowed_types, true)) {
    json_error('Invalid ad_type. Must be "rewarded" or "interstitial"');
}
if ($impression_id === '') {
    json_error('impression_id is required');
}

$db = DB::connect();

// --- Deduplicate by impression_id ---
$stmt = $db->prepare('SELECT id FROM ad_events WHERE impression_id = ?');
$stmt->execute([$impression_id]);
if ($stmt->fetch()) {
    json_error('Impression already credited', 409);
}

// --- Daily cap per ad type ---
$cap  = ($ad_type === 'rewarded') ? MAX_REWARDED_ADS_PER_DAY : MAX_INTERSTITIAL_ADS_PER_DAY;
$stmt = $db->prepare(
    'SELECT COUNT(*) AS cnt FROM ad_events
     WHERE user_id = ? AND ad_type = ? AND created_at >= CURDATE()'
);
$stmt->execute([$user_id, $ad_type]);
if ((int)$stmt->fetch()['cnt'] >= $cap) {
    json_error("Daily {$ad_type} ad limit reached", 429);
}

$coins_earned = ($ad_type === 'rewarded') ? COINS_REWARDED_AD : COINS_INTERSTITIAL_AD;
$php_value    = $coins_earned * PHP_PER_COIN;
$source_type  = ($ad_type === 'rewarded') ? 'ad_rewarded' : 'ad_interstitial';
$idem_key     = hash('sha256', "ad:{$user_id}:{$impression_id}");

$db->beginTransaction();
try {
    $db->prepare(
        'INSERT INTO ad_events (user_id, ad_type, ad_network, impression_id, rewarded_coins)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$user_id, $ad_type, $ad_network, $impression_id, $coins_earned]);

    $db->prepare(
        'INSERT INTO transactions
            (user_id, amount_coins, php_value, source_type, source_ref, idempotency_key, ip_address)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $user_id, $coins_earned, $php_value,
        $source_type, $impression_id, $idem_key,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ]);

    $db->prepare(
        'UPDATE balances
         SET coins = coins + ?, php_equivalent = php_equivalent + ?, total_earned = total_earned + ?
         WHERE user_id = ?'
    )->execute([$coins_earned, $php_value, $php_value, $user_id]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    error_log('ad_reward failed: ' . $e->getMessage());
    json_error('Failed to credit reward', 500);
}

$stmt = $db->prepare('SELECT coins, php_equivalent FROM balances WHERE user_id = ?');
$stmt->execute([$user_id]);
$balance = $stmt->fetch();

json_ok([
    'coins_earned' => $coins_earned,
    'balance'      => [
        'coins'          => (int)$balance['coins'],
        'php_equivalent' => number_format((float)$balance['php_equivalent'], 2),
    ],
]);
