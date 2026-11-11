<?php
// ============================================================
// PuzzLink PH - Game Constants & Economy Config
// All coin-to-PHP ratios live here. Change in one place.
// ============================================================

declare(strict_types=1);

// --- Exchange Rate (update periodically) ---
define('PHP_PER_USD', 58.0);

// --- Coin Economy ---
// 1 coin = ₱0.001  =>  1000 coins = ₱1.00
define('COINS_PER_PHP',    1000);
define('PHP_PER_COIN',     1 / COINS_PER_PHP);

// --- Level Rewards ---
define('COINS_LEVEL_BASE', 20);     // base coins per puzzle solved
define('COINS_DOUBLE_MULT', 2);     // multiplier when rewarded ad is watched

// --- Ad Rewards ---
define('COINS_REWARDED_AD',      50);   // coins per rewarded video
define('COINS_INTERSTITIAL_AD',  10);   // passive interstitial credit

// --- Daily Caps (anti-abuse) ---
define('MAX_REWARDED_ADS_PER_DAY',     20);   // ~₱1.00/day from ads
define('MAX_INTERSTITIAL_ADS_PER_DAY', 60);
define('MAX_LEVELS_PER_DAY',           100);

// --- Payout Thresholds ---
define('PAYOUT_TIER1_PHP',  50.00);    // first-ever cashout
define('PAYOUT_TIER2_PHP', 300.00);    // subsequent cashouts

// --- JWT ---
define('JWT_SECRET',  getenv('JWT_SECRET') ?: 'CHANGE_ME_IN_PRODUCTION');
define('JWT_TTL_SEC', 86400);  // 24 hours

// --- Rate Limits ---
define('RATE_LIMIT_WINDOW_SEC',  60);
define('RATE_LIMIT_MAX_HITS',    30);  // per endpoint per minute per user/IP

// --- Anti-Cheat Thresholds ---
define('ANTICHEAT_MIN_LEVEL_SEC',          8);     // faster than 8s = suspicious
define('ANTICHEAT_MAX_COINS_PER_HOUR',  2000);     // ~₱2/hr hard velocity cap
define('ANTICHEAT_MAX_LEVELS_PER_HOUR',   30);     // max 30 puzzles/hr (1 per 2min)
define('ANTICHEAT_MAX_OFFERS_PER_30MIN',   3);     // max 3 offerwall completions/30min
define('ANTICHEAT_HIGH_VALUE_OFFER_USD', 5.00);    // hold any offer > $5 for review
define('ANTICHEAT_MAX_ACCOUNTS_PER_DEVICE', 2);    // allow 2 accounts per device (family)

// --- Risk Score Thresholds ---
define('RISK_SCORE_REVIEW',   100);   // score >= 100: flag for admin review
define('RISK_SCORE_AUTOBAN',  200);   // score >= 200: immediate auto-ban

// --- Offerwall HMAC Secrets (set via environment) ---
define('OW_SECRET_TAPJOY',  getenv('OW_SECRET_TAPJOY')  ?: '');
define('OW_SECRET_ADJOE',   getenv('OW_SECRET_ADJOE')   ?: '');
define('OW_SECRET_IRONSRC', getenv('OW_SECRET_IRONSRC') ?: '');
define('OW_SECRET_CPX',     getenv('OW_SECRET_CPX')     ?: '');
define('OW_SECRET_WANNADS', getenv('OW_SECRET_WANNADS') ?: '');
