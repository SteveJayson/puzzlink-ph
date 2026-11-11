<?php
// ============================================================
// GET /api/balance
// Protected. Returns the user's current coin balance and
// cashout eligibility status.
// ============================================================

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../helpers/response.php';

header('Content-Type: application/json');
require_method('GET');

$auth    = Auth::require();
$user_id = $auth['user_id'];

$db = DB::connect();

$stmt = $db->prepare(
    'SELECT b.coins, b.php_equivalent, b.total_earned, b.total_paid_out,
            (SELECT COUNT(*) FROM payout_requests
             WHERE user_id = b.user_id AND status = \'completed\') AS completed_payouts
     FROM balances b WHERE b.user_id = ?'
);
$stmt->execute([$user_id]);
$balance = $stmt->fetch();

if (!$balance) {
    json_error('Balance record not found', 404);
}

$php_balance       = (float)$balance['php_equivalent'];
$completed_payouts = (int)$balance['completed_payouts'];

// Determine cashout tier and eligibility
$is_first_cashout  = ($completed_payouts === 0);
$threshold         = $is_first_cashout ? PAYOUT_TIER1_PHP : PAYOUT_TIER2_PHP;
$can_cashout       = $php_balance >= $threshold;

json_ok([
    'coins'              => (int)$balance['coins'],
    'php_equivalent'     => number_format($php_balance, 2),
    'total_earned_php'   => number_format((float)$balance['total_earned'], 2),
    'total_paid_out_php' => number_format((float)$balance['total_paid_out'], 2),
    'cashout'            => [
        'can_cashout'        => $can_cashout,
        'threshold_php'      => number_format($threshold, 2),
        'remaining_php'      => number_format(max(0, $threshold - $php_balance), 2),
        'tier'               => $is_first_cashout ? 1 : 2,
        'completed_payouts'  => $completed_payouts,
    ],
]);
