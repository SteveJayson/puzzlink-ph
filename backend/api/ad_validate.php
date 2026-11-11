<?php
// ============================================================
// POST /api/ad/validate
// Protected. Called by the client immediately after MAX/AdMob
// fires an impression callback to get server confirmation
// before displaying the "reward granted" UI.
//
// AppLovin MAX provides a server-side callback (SSV) for
// rewarded ads. When SSV is configured:
//   1. Client receives impression_id from SDK
//   2. Client POSTs impression_id here
//   3. This endpoint checks our ad_events table for a matching
//      SSV record (written by the MAX SSV webhook below)
//   4. Returns { validated: true/false }
//
// For non-SSV networks (or when SSV isn't configured yet),
// falls back to trusting the impression_id from the client
// but still rate-limits and deduplicates.
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

rate_limit_check((string)$user_id, 'ad_validate');

$body          = get_json_body();
$impression_id = trim((string)($body['impression_id'] ?? ''));
$ad_type       = strtolower(trim((string)($body['ad_type'] ?? '')));
$ad_network    = substr(trim((string)($body['ad_network'] ?? 'applovin')), 0, 50);

if ($impression_id === '') {
    json_error('impression_id is required');
}
if (!in_array($ad_type, ['rewarded', 'interstitial'], true)) {
    json_error('ad_type must be rewarded or interstitial');
}

$db = DB::connect();

// --- Check for SSV pre-confirmation ---
// When AppLovin MAX SSV is active, the SSV webhook writes a record
// into ad_events BEFORE the client calls this endpoint.
$stmt = $db->prepare(
    'SELECT id, ssv_verified FROM ad_events
     WHERE impression_id = ? AND user_id = ? LIMIT 1'
);
$stmt->execute([$impression_id, $user_id]);
$existing = $stmt->fetch();

if ($existing) {
    // Already recorded — return validation status
    json_ok([
        'validated'    => true,
        'ssv_verified' => (bool)$existing['ssv_verified'],
        'already_credited' => true,
    ]);
}

// --- Not yet seen — record it and trust (non-SSV path) ---
// Daily cap enforcement
$cap  = ($ad_type === 'rewarded') ? MAX_REWARDED_ADS_PER_DAY : MAX_INTERSTITIAL_ADS_PER_DAY;
$stmt = $db->prepare(
    'SELECT COUNT(*) AS cnt FROM ad_events
     WHERE user_id = ? AND ad_type = ? AND created_at >= CURDATE()'
);
$stmt->execute([$user_id, $ad_type]);
if ((int)$stmt->fetch()['cnt'] >= $cap) {
    json_ok(['validated' => false, 'reason' => 'daily_cap_reached']);
}

// Record impression (ssv_verified = 0 means client-side trust)
try {
    $coins = ($ad_type === 'rewarded') ? COINS_REWARDED_AD : COINS_INTERSTITIAL_AD;
    $db->prepare(
        'INSERT INTO ad_events
            (user_id, ad_type, ad_network, impression_id, rewarded_coins, ssv_verified)
         VALUES (?, ?, ?, ?, ?, 0)'
    )->execute([$user_id, $ad_type, $ad_network, $impression_id, $coins]);

    json_ok(['validated' => true, 'ssv_verified' => false, 'already_credited' => false]);
} catch (Throwable $e) {
    // Duplicate key = impression already recorded by SSV webhook (race condition)
    if (str_contains($e->getMessage(), 'Duplicate')) {
        json_ok(['validated' => true, 'ssv_verified' => true, 'already_credited' => true]);
    }
    error_log('ad_validate error: ' . $e->getMessage());
    json_error('Validation failed', 500);
}
