<?php
// ============================================================
// GET /api/ad/ssv
// AppLovin MAX Server-Side Verification (SSV) webhook.
// Called by AppLovin servers when a rewarded ad is completed.
// THIS IS NOT CALLED BY THE CLIENT.
//
// AppLovin MAX SSV docs:
//   https://dash.applovin.com/documentation/mediation/s2s-rewarded
//
// Query params (from AppLovin):
//   user_id       — the UUID we passed as setUserId() in SDK
//   ad_unit_id    — your rewarded ad unit
//   event_id      — unique impression ID (use as transaction key)
//   event_token   — HMAC-SHA256 signature
//   reward_amount — reward amount (virtual currency you configured)
//   reward_label  — reward label (e.g. "Coins")
//   revenue       — estimated revenue in USD
//   country_code  — user's country
//
// Signature verification:
//   HMAC-SHA256(event_id + ":" + user_id, your_ssv_key)
// ============================================================

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

$user_uuid    = (string)($_GET['user_id']       ?? '');
$ad_unit_id   = (string)($_GET['ad_unit_id']    ?? '');
$event_id     = (string)($_GET['event_id']      ?? '');
$event_token  = (string)($_GET['event_token']   ?? '');
$revenue      = (float)($_GET['revenue']        ?? 0.0);
$reward_amt   = (int)($_GET['reward_amount']     ?? 0);
$country_code = substr((string)($_GET['country_code'] ?? 'PH'), 0, 2);

// --- Signature verification ---
$ssv_key = getenv('APPLOVIN_SSV_KEY') ?: '';
if ($ssv_key === '') {
    error_log('[SSV] APPLOVIN_SSV_KEY not set — accepting all (configure in production)');
    $sig_valid = true;
} else {
    $expected  = hash_hmac('sha256', $event_id . ':' . $user_uuid, $ssv_key);
    $sig_valid = hash_equals($expected, $event_token);
}

if (!$sig_valid) {
    error_log("[SSV] Signature failure: event_id={$event_id} user={$user_uuid}");
    http_response_code(403);
    echo 'invalid_signature';
    exit;
}

if ($user_uuid === '' || $event_id === '') {
    http_response_code(400);
    echo 'missing_params';
    exit;
}

$db = DB::connect();

// --- Resolve user ---
$stmt = $db->prepare('SELECT id FROM users WHERE uuid = ? AND is_banned = 0 LIMIT 1');
$stmt->execute([$user_uuid]);
$user = $stmt->fetch();
if (!$user) {
    // Unknown user — ACK to prevent retries
    echo 'OK';
    exit;
}
$user_id = (int)$user['id'];

$db->beginTransaction();
try {
    // Check daily cap
    $cap  = MAX_REWARDED_ADS_PER_DAY;
    $stmt = $db->prepare(
        "SELECT COUNT(*) AS cnt FROM ad_events
         WHERE user_id = ? AND ad_type = 'rewarded' AND created_at >= CURDATE()"
    );
    $stmt->execute([$user_id]);
    if ((int)$stmt->fetch()['cnt'] >= $cap) {
        $db->rollBack();
        // Return 200 to prevent AppLovin retrying a legitimate but cap-exceeded request
        echo 'OK';
        exit;
    }

    // Insert with ssv_verified = 1 — this is the gold-standard record
    $db->prepare(
        "INSERT INTO ad_events
            (user_id, ad_type, ad_network, impression_id, rewarded_coins, ssv_verified)
         VALUES (?, 'rewarded', 'applovin', ?, ?, 1)"
    )->execute([$user_id, $event_id, COINS_REWARDED_AD]);

    // Insert transaction
    $php_value = COINS_REWARDED_AD * PHP_PER_COIN;
    $idem_key  = hash('sha256', "ssv:rewarded:{$event_id}");

    $db->prepare(
        "INSERT INTO transactions
            (user_id, amount_coins, php_value, source_type, source_ref, idempotency_key, ip_address)
         VALUES (?, ?, ?, 'ad_rewarded', ?, ?, ?)"
    )->execute([
        $user_id, COINS_REWARDED_AD, $php_value,
        $event_id, $idem_key,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ]);

    // Update balance
    $db->prepare(
        'UPDATE balances
         SET coins = coins + ?, php_equivalent = php_equivalent + ?, total_earned = total_earned + ?
         WHERE user_id = ?'
    )->execute([COINS_REWARDED_AD, $php_value, $php_value, $user_id]);

    $db->commit();
    echo 'OK';

} catch (Throwable $e) {
    $db->rollBack();
    if (str_contains($e->getMessage(), 'Duplicate')) {
        // Already processed — idempotent
        echo 'OK';
        exit;
    }
    error_log('[SSV] exception: ' . $e->getMessage());
    http_response_code(500);
    echo 'FAIL';
}
