<?php
// ============================================================
// POST /api/payout/request
// Protected. Body: { "method": "gcash|maya",
//                    "number": "09xxxxxxxxx | +639xxxxxxxxx",
//                    "name":   "Juan dela Cruz" }
// Validates threshold, runs risk check, deducts balance.
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

rate_limit_check((string)$user_id, 'payout_request');

$body        = get_json_body();
$method      = strtolower(trim((string)($body['method'] ?? '')));
$raw_number  = trim((string)($body['number'] ?? ''));
$payout_name = trim((string)($body['name']   ?? ''));

// --- Validate payout method ---
if (!in_array($method, ['gcash', 'maya'], true)) {
    json_error('method must be "gcash" or "maya"');
}

// --- Normalize Philippine mobile number ---
// Accept: 09XXXXXXXXX  or  +639XXXXXXXXX  or  639XXXXXXXXX
$digits = preg_replace('/\D/', '', $raw_number);
if (str_starts_with($digits, '639') && strlen($digits) === 12) {
    $normalized = '0' . substr($digits, 2);  // +639XX → 09XX
} else {
    $normalized = $digits;
}
if (!preg_match('/^09\d{9}$/', $normalized)) {
    json_error('Invalid Philippine mobile number. Use 09XXXXXXXXX or +639XXXXXXXXX format.');
}

// --- Validate account name ---
if (strlen($payout_name) < 2 || strlen($payout_name) > 100) {
    json_error('Account holder name is required (2–100 characters)');
}

// --- Anti-cheat / risk gate ---
$risk   = new RiskEngine($user_id, $ip);
$check  = $risk->checkPayoutRequest();
if (!$check['allow']) {
    json_error($check['reason'], 403);
}

$db = DB::connect();
$db->beginTransaction();
try {
    $stmt = $db->prepare(
        'SELECT b.coins, b.php_equivalent,
                (SELECT COUNT(*) FROM payout_requests WHERE user_id = b.user_id AND status = \'completed\') AS completed_payouts,
                (SELECT COUNT(*) FROM payout_requests WHERE user_id = b.user_id AND status IN (\'pending\',\'processing\')) AS active_payouts,
                COALESCE(urs.risk_score, 0) AS risk_score
         FROM balances b
         LEFT JOIN user_risk_scores urs ON urs.user_id = b.user_id
         WHERE b.user_id = ? FOR UPDATE'
    );
    $stmt->execute([$user_id]);
    $balance = $stmt->fetch();

    if (!$balance) {
        $db->rollBack();
        json_error('Account not found', 404);
    }

    if ((int)$balance['active_payouts'] > 0) {
        $db->rollBack();
        json_error('You already have a pending payout. Please wait for it to complete.', 409);
    }

    $php_balance       = (float)$balance['php_equivalent'];
    $completed_payouts = (int)$balance['completed_payouts'];
    $risk_score        = (int)$balance['risk_score'];
    $is_first_cashout  = ($completed_payouts === 0);
    $tier              = $is_first_cashout ? 1 : 2;
    $threshold         = $is_first_cashout ? PAYOUT_TIER1_PHP : PAYOUT_TIER2_PHP;

    if ($php_balance < $threshold) {
        $db->rollBack();
        json_error(sprintf(
            'Insufficient balance. Need ₱%.2f to cash out. Current balance: ₱%.2f',
            $threshold, $php_balance
        ), 402);
    }

    $payout_amount  = $threshold;
    $coins_to_debit = (int)ceil($payout_amount * COINS_PER_PHP);
    $idem_key       = hash('sha256', "payout:{$user_id}:" . time());

    $db->prepare(
        'INSERT INTO payout_requests
            (user_id, amount_php, payout_method, payout_number, payout_name, tier, threshold_met, risk_score_at_request)
         VALUES (?, ?, ?, ?, ?, ?, 1, ?)'
    )->execute([$user_id, $payout_amount, $method, $normalized, $payout_name, $tier, $risk_score]);
    $payout_id = $db->lastInsertId();

    $db->prepare(
        'INSERT INTO transactions
            (user_id, amount_coins, php_value, source_type, source_ref, idempotency_key, ip_address)
         VALUES (?, ?, ?, \'payout_debit\', ?, ?, ?)'
    )->execute([$user_id, -$coins_to_debit, -$payout_amount, "payout_{$payout_id}", $idem_key, $ip]);

    $db->prepare(
        'UPDATE balances
         SET coins = coins - ?, php_equivalent = php_equivalent - ?, total_paid_out = total_paid_out + ?
         WHERE user_id = ?'
    )->execute([$coins_to_debit, $payout_amount, $payout_amount, $user_id]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    error_log('payout_request failed: ' . $e->getMessage());
    json_error('Failed to submit payout request. Please try again.', 500);
}

json_ok([
    'payout_id'  => (int)$payout_id,
    'amount_php' => number_format($payout_amount, 2),
    'method'     => $method,
    'number'     => $normalized,
    'status'     => 'pending',
    'message'    => sprintf(
        'Your ₱%.2f %s payout is queued. Expected within 1–3 business days.',
        $payout_amount, strtoupper($method)
    ),
]);
