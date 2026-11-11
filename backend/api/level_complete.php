<?php
// ============================================================
// POST /api/level/complete
// Protected. Body: { "level_id": 42, "time_taken_sec": 38, "ad_doubled": false }
// Credits coins server-side after anti-cheat validation.
// ============================================================

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/rate_limit.php';
require_once __DIR__ . '/../anticheat/RiskEngine.php';

header('Content-Type: application/json');
require_method('POST');

$auth    = Auth::require();
$user_id = $auth['user_id'];
$ip      = $_SERVER['REMOTE_ADDR'] ?? '';

rate_limit_check((string)$user_id, 'level_complete');

$body       = get_json_body();
$level_id   = (int)($body['level_id']      ?? 0);
$time_taken = (int)($body['time_taken_sec'] ?? 0);
$ad_doubled = (bool)($body['ad_doubled']    ?? false);

if ($level_id <= 0) {
    json_error('Invalid level_id');
}

$db   = DB::connect();
$risk = new RiskEngine($user_id, $ip);

// --- Anti-cheat gate ---
$rejected = $risk->checkLevelComplete($level_id, $time_taken);
if ($rejected) {
    // Critical cheat signal — silently appear to succeed but credit nothing.
    // The client can't distinguish this from a genuine duplicate submission.
    $stmt = $db->prepare('SELECT coins, php_equivalent FROM balances WHERE user_id = ?');
    $stmt->execute([$user_id]);
    $bal = $stmt->fetch();
    json_ok([
        'coins_earned' => 0,
        'was_doubled'  => false,
        'balance'      => [
            'coins'          => (int)($bal['coins'] ?? 0),
            'php_equivalent' => number_format((float)($bal['php_equivalent'] ?? 0), 2),
        ],
    ]);
}

// --- Daily level cap ---
$stmt = $db->prepare(
    'SELECT COUNT(*) AS cnt FROM level_progress
     WHERE user_id = ? AND completed_at >= CURDATE()'
);
$stmt->execute([$user_id]);
if ((int)$stmt->fetch()['cnt'] >= MAX_LEVELS_PER_DAY) {
    json_error('Daily puzzle limit reached. Come back tomorrow!', 429);
}

// --- Calculate reward ---
$coins_earned = COINS_LEVEL_BASE;
$was_doubled  = 0;

if ($ad_doubled) {
    $stmt = $db->prepare(
        "SELECT id FROM ad_events
         WHERE user_id = ? AND ad_type = 'rewarded'
           AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
         ORDER BY created_at DESC LIMIT 1"
    );
    $stmt->execute([$user_id]);
    if ($stmt->fetch()) {
        $coins_earned *= COINS_DOUBLE_MULT;
        $was_doubled   = 1;
    }
}

// --- Idempotency key: user + level + date ---
$idempotency_key = hash('sha256', "level:{$user_id}:{$level_id}:" . date('Y-m-d'));

$db->beginTransaction();
try {
    $stmt = $db->prepare('SELECT id FROM transactions WHERE idempotency_key = ?');
    $stmt->execute([$idempotency_key]);
    if ($stmt->fetch()) {
        $db->rollBack();
        json_error('Level already credited for today', 409);
    }

    $php_value = $coins_earned * PHP_PER_COIN;

    $db->prepare(
        'INSERT INTO transactions
            (user_id, amount_coins, php_value, source_type, source_ref, idempotency_key, ip_address)
         VALUES (?, ?, ?, \'level_complete\', ?, ?, ?)'
    )->execute([$user_id, $coins_earned, $php_value, "level_{$level_id}", $idempotency_key, $ip]);

    $db->prepare(
        'UPDATE balances
         SET coins = coins + ?, php_equivalent = php_equivalent + ?, total_earned = total_earned + ?
         WHERE user_id = ?'
    )->execute([$coins_earned, $php_value, $php_value, $user_id]);

    $db->prepare(
        'INSERT INTO level_progress (user_id, level_id, time_taken_sec, coins_earned, was_doubled)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$user_id, $level_id, $time_taken ?: null, $coins_earned, $was_doubled]);

    $db->commit();

    // Record earning for velocity tracking (after commit, non-critical)
    $risk->recordEarning($coins_earned, $php_value);

} catch (Throwable $e) {
    $db->rollBack();
    error_log('level_complete failed: ' . $e->getMessage());
    json_error('Could not credit reward. Please try again.', 500);
}

$stmt = $db->prepare('SELECT coins, php_equivalent FROM balances WHERE user_id = ?');
$stmt->execute([$user_id]);
$balance = $stmt->fetch();

json_ok([
    'coins_earned' => $coins_earned,
    'was_doubled'  => (bool)$was_doubled,
    'balance'      => [
        'coins'          => (int)$balance['coins'],
        'php_equivalent' => number_format((float)$balance['php_equivalent'], 2),
    ],
]);
