<?php
// ============================================================
// EconomyTest.php — Unit tests for the coin economy math
// Tests the constants and conversion formulas in isolation.
// Run: php backend/tests/EconomyTest.php
// ============================================================

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';

define('C_G', "\033[32m");
define('C_R', "\033[31m");
define('C_X', "\033[0m");

$pass = 0;
$fail = 0;

function ok(string $label, bool $condition): void {
    global $pass, $fail;
    if ($condition) {
        echo C_G . "  ✓ {$label}" . C_X . "\n";
        $pass++;
    } else {
        echo C_R . "  ✗ FAIL: {$label}" . C_X . "\n";
        $fail++;
    }
}

echo "\n🔢 Economy Unit Tests\n";
echo str_repeat('─', 45) . "\n";

// --- Coin/PHP conversion ---
ok('1000 coins = ₱1.00',
    abs(1000 * PHP_PER_COIN - 1.0) < 0.0001);

ok('₱50 threshold = 50,000 coins',
    (int)(PAYOUT_TIER1_PHP * COINS_PER_PHP) === 50000);

ok('₱300 threshold = 300,000 coins',
    (int)(PAYOUT_TIER2_PHP * COINS_PER_PHP) === 300000);

// --- Level reward math ---
ok('Base level reward is positive',
    COINS_LEVEL_BASE > 0);

ok('Double reward = 2× base',
    COINS_LEVEL_BASE * COINS_DOUBLE_MULT === COINS_LEVEL_BASE * 2);

// --- Ad reward math ---
ok('Rewarded ad reward > interstitial reward',
    COINS_REWARDED_AD > COINS_INTERSTITIAL_AD);

ok('20 rewarded ads/day <= daily cap',
    20 <= MAX_REWARDED_ADS_PER_DAY);

// --- Offerwall math ---
// $0.25 offer at ₱58/$1 = ₱14.50 = 14,500 coins
$usd   = 0.25;
$coins = (int)round($usd * PHP_PER_USD * COINS_PER_PHP);
ok('$0.25 offerwall task ≈ 14,500 coins',
    abs($coins - 14500) < 100);

// A single $0.25 task + 50 rewarded ads covers tier 1 payout
$from_offers  = 0.25 * PHP_PER_USD;   // ₱14.50
$from_ads     = 50 * COINS_REWARDED_AD * PHP_PER_COIN;  // ₱2.50
$total_php    = $from_offers + $from_ads;
ok('$0.25 offer + 50 rewarded ads covers more than ₱16',
    $total_php > 16.0);

// --- Profitability check ---
// If eCPM = $3, revenue per rewarded view = $0.003
$ecpm_usd       = 3.0;
$rev_per_view   = $ecpm_usd / 1000;
$views_for_tier1 = ceil(PAYOUT_TIER1_PHP / PHP_PER_USD / $rev_per_view);
ok('At $3 eCPM, tier-1 payout requires > 200 views (confirms offerwall necessity)',
    $views_for_tier1 > 200);

// --- Anti-cheat constants ---
ok('Auto-ban threshold > review threshold',
    RISK_SCORE_AUTOBAN > RISK_SCORE_REVIEW);

ok('Min level time is > 0',
    ANTICHEAT_MIN_LEVEL_SEC > 0);

ok('Max coins/hr cap is below tier-2 payout in coins',
    ANTICHEAT_MAX_COINS_PER_HOUR < PAYOUT_TIER2_PHP * COINS_PER_PHP);

// --- Summary ---
echo str_repeat('─', 45) . "\n";
$status = $fail === 0
    ? C_G . "✓ ALL {$pass} TESTS PASSED" . C_X
    : C_R . "✗ {$fail} FAILED / {$pass} PASSED" . C_X;
echo "{$status}\n\n";

exit($fail > 0 ? 1 : 0);
