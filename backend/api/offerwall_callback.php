<?php
// ============================================================
// /api/offerwall/callback
// SERVER-TO-SERVER endpoint. NEVER called by the game client.
//
// Supported networks:
//   tapjoy     — GET  ?network=tapjoy
//   adjoe      — POST ?network=adjoe   (JSON body, HMAC header)
//   ironsource — GET  ?network=ironsource
//   cpx        — GET  ?network=cpx     (CPX Research)
//   wannads    — GET  ?network=wannads
//
// All handlers:
//   1. Verify cryptographic signature BEFORE reading any value
//   2. Resolve user by UUID
//   3. Deduplicate by transaction_id (UNIQUE constraint)
//   4. Award coins inside a DB transaction
//   5. Return "1" or "OK" on success (network-specific)
// ============================================================

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

// No JSON content-type header here — most offerwall networks
// expect plain text "1" or "OK" as the success response.
$request_method = $_SERVER['REQUEST_METHOD'];
if (!in_array($request_method, ['GET', 'POST'], true)) {
    http_response_code(405);
    echo 'FAIL';
    exit;
}

$network = strtolower(trim($_GET['network'] ?? ''));
$allowed = ['tapjoy', 'adjoe', 'ironsource', 'cpx', 'wannads'];
if (!in_array($network, $allowed, true)) {
    http_response_code(400);
    echo 'FAIL';
    exit;
}

// Read raw body once (used by HMAC networks)
$raw_body = file_get_contents('php://input');

// ============================================================
// NETWORK-SPECIFIC PARAMETER PARSING + SIGNATURE VERIFICATION
// ============================================================

$user_uuid      = '';
$offer_id       = '';
$transaction_id = '';
$usd_value      = 0.0;
$sig_valid      = false;

switch ($network) {

    // ----------------------------------------------------------
    // TAPJOY
    // Docs: https://dev.tapjoy.com/en/server-side-rewards
    // GET ?snuid=UUID&currency=AMOUNT&id=TXID&verifier=MD5HASH
    // Signature: md5(snuid + currency + id + secret)
    // ----------------------------------------------------------
    case 'tapjoy':
        $snuid    = (string)($_GET['snuid']    ?? '');
        $currency = (string)($_GET['currency'] ?? '0');
        $txid     = (string)($_GET['id']       ?? '');
        $verifier = (string)($_GET['verifier'] ?? '');
        $secret   = OW_SECRET_TAPJOY;

        $user_uuid      = $snuid;
        $offer_id       = (string)($_GET['offer_id'] ?? 'tapjoy_offer');
        $transaction_id = $txid;
        // Tapjoy sends virtual currency units defined in their dashboard.
        // We configure 1 Tapjoy VC unit = $0.001 USD in the Tapjoy portal.
        $usd_value      = (float)$currency * 0.001;
        $sig_valid      = hash_equals(
            md5($snuid . $currency . $txid . $secret),
            $verifier
        );
        break;

    // ----------------------------------------------------------
    // ADJOE
    // Docs: https://docs.adjoe.io/api/s2s-callback
    // POST JSON body, HMAC-SHA256 in X-Adjoe-Signature header
    // ----------------------------------------------------------
    case 'adjoe':
        $body      = json_decode($raw_body, true) ?? [];
        $signature = (string)($_SERVER['HTTP_X_ADJOE_SIGNATURE'] ?? '');
        $secret    = OW_SECRET_ADJOE;

        $user_uuid      = (string)($body['user_id']     ?? '');
        $offer_id       = (string)($body['placement_id'] ?? '');
        $transaction_id = (string)($body['event_id']     ?? '');
        $usd_value      = (float)($body['payout_usd']   ?? 0);
        $sig_valid      = $secret !== '' && hash_equals(
            hash_hmac('sha256', $raw_body, $secret),
            $signature
        );
        break;

    // ----------------------------------------------------------
    // IRONSOURCE / Unity LevelPlay
    // Docs: https://developers.is.com/ironsource-mobile/general/server-to-server-callbacks
    // GET ?userId=UUID&offerId=X&transId=Y&reward=Z&signature=HASH
    // Signature: md5(userId + reward + transId + secret)
    // ----------------------------------------------------------
    case 'ironsource':
        $user_id  = (string)($_GET['userId']    ?? '');
        $reward   = (string)($_GET['reward']    ?? '0');
        $trans_id = (string)($_GET['transId']   ?? '');
        $sig      = (string)($_GET['signature'] ?? '');
        $secret   = OW_SECRET_IRONSRC;

        $user_uuid      = $user_id;
        $offer_id       = (string)($_GET['offerId'] ?? '');
        $transaction_id = $trans_id;
        $usd_value      = (float)$reward * 0.001;  // IronSource sends virtual currency
        $sig_valid      = hash_equals(
            md5($user_id . $reward . $trans_id . $secret),
            $sig
        );
        break;

    // ----------------------------------------------------------
    // CPX RESEARCH
    // Docs: https://publisher.cpx-research.com/pages/s2s-postback
    // GET ?ext_user_id=UUID&status=1&trans_id=X&payout=Y&hash=HASH
    // Signature: md5(trans_id + secret)
    // Only fire when status=1 (completed)
    // ----------------------------------------------------------
    case 'cpx':
        $ext_user_id = (string)($_GET['ext_user_id'] ?? '');
        $status      = (string)($_GET['status']      ?? '0');
        $trans_id    = (string)($_GET['trans_id']    ?? '');
        $payout      = (string)($_GET['payout']      ?? '0');   // USD value from CPX
        $hash        = (string)($_GET['hash']        ?? '');
        $secret      = OW_SECRET_CPX;

        // Only process completed surveys (status=1); status=2 = reversed
        if ($status !== '1') {
            // Chargeback or incomplete — log and return OK to suppress retries
            error_log("CPX non-complete callback: status={$status} tx={$trans_id}");
            echo '1';
            exit;
        }

        $user_uuid      = $ext_user_id;
        $offer_id       = (string)($_GET['survey_id'] ?? 'cpx_survey');
        $transaction_id = $trans_id;
        $usd_value      = (float)$payout;
        $sig_valid      = hash_equals(
            md5($trans_id . $secret),
            $hash
        );
        break;

    // ----------------------------------------------------------
    // WANNADS
    // Docs: https://www.wannads.com/publishers/server-side
    // GET ?user_id=UUID&transaction_id=X&reward=Y&signature=HASH
    // Signature: sha1(transaction_id + secret)
    // ----------------------------------------------------------
    case 'wannads':
        $w_user_id  = (string)($_GET['user_id']       ?? '');
        $w_trans_id = (string)($_GET['transaction_id'] ?? '');
        $w_reward   = (string)($_GET['reward']         ?? '0');  // USD
        $w_sig      = (string)($_GET['signature']      ?? '');
        $secret     = OW_SECRET_WANNADS;

        $user_uuid      = $w_user_id;
        $offer_id       = (string)($_GET['offer_id'] ?? 'wannads_offer');
        $transaction_id = $w_trans_id;
        $usd_value      = (float)$w_reward;
        $sig_valid      = hash_equals(
            sha1($w_trans_id . $secret),
            $w_sig
        );
        break;
}

// ============================================================
// SIGNATURE GATE — reject immediately if invalid
// ============================================================
if (!$sig_valid) {
    error_log(sprintf(
        'Offerwall sig FAIL: network=%s tx=%s ip=%s',
        $network, $transaction_id, $_SERVER['REMOTE_ADDR'] ?? '?'
    ));
    // Return 403 so the network knows this is a permanent failure, not a retry
    http_response_code(403);
    echo 'FAIL';
    exit;
}

// ============================================================
// BASIC FIELD VALIDATION
// ============================================================
if ($user_uuid === '' || $transaction_id === '' || $usd_value <= 0) {
    http_response_code(400);
    echo 'FAIL';
    exit;
}

// Cap single-event USD value to prevent runaway credits from misconfigured dashboards
if ($usd_value > 50.0) {
    error_log("Offerwall oversized payout capped: network={$network} usd={$usd_value} tx={$transaction_id}");
    $usd_value = 50.0;
}

// ============================================================
// DATABASE OPERATIONS
// ============================================================
$db = DB::connect();

// Resolve user (UUID is the public-safe identifier we pass to offerwall SDKs)
$stmt = $db->prepare('SELECT id FROM users WHERE uuid = ? AND is_banned = 0 LIMIT 1');
$stmt->execute([$user_uuid]);
$user = $stmt->fetch();

if (!$user) {
    // Unknown user — return success to silence retries, log for review
    error_log("Offerwall unknown user: network={$network} uuid={$user_uuid} tx={$transaction_id}");
    echo '1';
    exit;
}
$user_id = (int)$user['id'];

// Coin conversion: $1 USD = PHP_PER_USD * COINS_PER_PHP coins
$php_value      = round($usd_value * PHP_PER_USD, 4);
$coins_to_award = (int)round($usd_value * PHP_PER_USD * COINS_PER_PHP);
$idem_key       = hash('sha256', "ow:{$network}:{$transaction_id}");
$raw_payload    = json_encode([
    'get'  => $_GET,
    'body' => $raw_body !== '' ? substr($raw_body, 0, 2000) : null,
]);

$db->beginTransaction();
try {
    // Idempotency check — UNIQUE constraint on transaction_id handles concurrent retries
    $stmt = $db->prepare('SELECT id FROM offerwall_events WHERE transaction_id = ?');
    $stmt->execute([$transaction_id]);
    if ($stmt->fetch()) {
        $db->rollBack();
        echo '1';   // success (already processed)
        exit;
    }

    // Record offerwall event
    $db->prepare(
        'INSERT INTO offerwall_events
            (user_id, network, offer_id, transaction_id, usd_value, coins_awarded, callback_verified, raw_payload)
         VALUES (?, ?, ?, ?, ?, ?, 1, ?)'
    )->execute([$user_id, $network, $offer_id, $transaction_id, $usd_value, $coins_to_award, $raw_payload]);

    // Immutable transaction ledger
    $db->prepare(
        'INSERT INTO transactions
            (user_id, amount_coins, php_value, source_type, source_ref, idempotency_key, ip_address)
         VALUES (?, ?, ?, \'offerwall\', ?, ?, ?)'
    )->execute([
        $user_id,
        $coins_to_award,
        $php_value,
        "{$network}:{$transaction_id}",
        $idem_key,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ]);

    // Update balance (atomic increment — never SET)
    $db->prepare(
        'UPDATE balances
         SET coins          = coins + ?,
             php_equivalent = php_equivalent + ?,
             total_earned   = total_earned + ?
         WHERE user_id = ?'
    )->execute([$coins_to_award, $php_value, $php_value, $user_id]);

    $db->commit();

    // Network-specific success responses
    echo match ($network) {
        'cpx'     => '1',
        'wannads' => '1',
        default   => 'OK',
    };

} catch (Throwable $e) {
    $db->rollBack();
    error_log("offerwall_callback EXCEPTION [{$network}]: " . $e->getMessage());
    http_response_code(500);
    echo 'FAIL';
}
