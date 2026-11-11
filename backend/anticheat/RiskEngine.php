<?php
// ============================================================
// RiskEngine.php
// Central anti-cheat and fraud detection service.
// Called from API endpoints BEFORE crediting any coins.
//
// SCORING SYSTEM
// --------------
// Every suspicious signal adds points to the user's risk_score.
// Thresholds (defined in constants.php):
//   RISK_SCORE_REVIEW  = 100  → flag for admin review
//   RISK_SCORE_AUTOBAN = 200  → immediate auto-ban
//
// All checks are silent to the client — never reveal detection
// logic or thresholds in API responses.
// ============================================================

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

class RiskEngine {

    private PDO $db;
    private int $user_id;
    private string $ip;

    public function __construct(int $user_id, string $ip = '') {
        $this->db      = DB::connect();
        $this->user_id = $user_id;
        $this->ip      = $ip ?: ($_SERVER['REMOTE_ADDR'] ?? '');
    }

    // ============================================================
    // PUBLIC CHECKS — call from API endpoints
    // ============================================================

    /**
     * Run all level-completion checks.
     * Returns true if the submission should be REJECTED (high-confidence cheat).
     * Returns false if it should proceed (may still be flagged for review).
     */
    public function checkLevelComplete(int $level_id, int $time_taken_sec): bool {
        $reject = false;

        // 1. Speed check
        if ($time_taken_sec > 0 && $time_taken_sec < ANTICHEAT_MIN_LEVEL_SEC) {
            $severity = $time_taken_sec <= 2 ? 'critical' : 'high';
            $this->flag('speed_hack', $severity, [
                'level_id'       => $level_id,
                'time_taken_sec' => $time_taken_sec,
                'threshold_sec'  => ANTICHEAT_MIN_LEVEL_SEC,
            ]);
            if ($severity === 'critical') {
                $reject = true;
            }
        }

        // 2. Hourly coin velocity
        if ($this->isVelocityExceeded('coins', ANTICHEAT_MAX_COINS_PER_HOUR)) {
            $this->flag('velocity_coins', 'high', [
                'window' => '1 hour',
                'cap'    => ANTICHEAT_MAX_COINS_PER_HOUR,
            ]);
        }

        // 3. Hourly level velocity (too many levels per hour)
        if ($this->isLevelVelocityExceeded()) {
            $this->flag('velocity_coins', 'medium', [
                'type'    => 'level_velocity',
                'cap'     => ANTICHEAT_MAX_LEVELS_PER_HOUR,
            ]);
        }

        // 4. Geo check (non-blocking — just flags)
        $this->checkGeo();

        // 5. Apply accumulated score and check for auto-ban
        $this->applyScoreActions();

        return $reject;
    }

    /**
     * Run checks before crediting an offerwall reward.
     * Returns true if the reward should be held for review.
     */
    public function checkOfferwallReward(float $usd_value): bool {
        $hold = false;

        // Rapid-fire completion: >3 offers in 30 minutes
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS cnt FROM offerwall_events
             WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
        );
        $stmt->execute([$this->user_id]);
        $recent = (int)$stmt->fetch()['cnt'];

        if ($recent >= ANTICHEAT_MAX_OFFERS_PER_30MIN) {
            $this->flag('velocity_offerwall', 'high', [
                'offers_in_30min' => $recent,
                'cap'             => ANTICHEAT_MAX_OFFERS_PER_30MIN,
                'usd_value'       => $usd_value,
            ]);
            $hold = true;
        }

        // High-value single offer (>$5) — always hold for review
        if ($usd_value > ANTICHEAT_HIGH_VALUE_OFFER_USD) {
            $this->flag('suspicious_pattern', 'medium', [
                'reason'    => 'high_value_offer',
                'usd_value' => $usd_value,
            ]);
            $hold = true;
        }

        $this->applyScoreActions();
        return $hold;
    }

    /**
     * Run checks before accepting a payout request.
     * Returns array: ['allow' => bool, 'reason' => string]
     */
    public function checkPayoutRequest(): array {
        // Check current risk score
        $score = $this->getCurrentRiskScore();
        if ($score >= RISK_SCORE_REVIEW) {
            return [
                'allow'  => false,
                'reason' => 'Account is under review. Please contact support.',
            ];
        }

        // Repeated failed payout attempts (3+ rejected payouts)
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS cnt FROM payout_requests
             WHERE user_id = ? AND status = 'rejected'"
        );
        $stmt->execute([$this->user_id]);
        $rejected = (int)$stmt->fetch()['cnt'];

        if ($rejected >= ANTICHEAT_MAX_REJECTED_PAYOUTS) {
            $this->flag('failed_payout_abuse', 'high', [
                'rejected_count' => $rejected,
            ]);
            $this->applyScoreActions();
            return [
                'allow'  => false,
                'reason' => 'Payout temporarily locked. Please contact support.',
            ];
        }

        // Multi-account check: same payout number used by another account
        $stmt = $this->db->prepare(
            "SELECT COUNT(DISTINCT user_id) AS cnt FROM payout_requests
             WHERE payout_number = (
                 SELECT payout_number FROM payout_requests WHERE user_id = ? LIMIT 1
             ) AND user_id != ?"
        );
        $stmt->execute([$this->user_id, $this->user_id]);
        if ((int)$stmt->fetch()['cnt'] > 0) {
            $this->flag('multi_account', 'high', [
                'reason' => 'payout_number_shared',
            ]);
            // Don't block — payout numbers can be legitimately shared in PH households.
            // Just flag for review.
        }

        $this->checkGeo();
        $this->applyScoreActions();
        return ['allow' => true, 'reason' => ''];
    }

    /**
     * Check if the device associated with this user is flagged.
     * Run on login / device_register.
     */
    public function checkDeviceMultiAccount(): void {
        $stmt = $this->db->prepare(
            'SELECT COUNT(DISTINCT user_id) AS cnt
             FROM device_fingerprints
             WHERE android_id = (
                 SELECT android_id FROM users WHERE id = ? LIMIT 1
             ) AND android_id IS NOT NULL'
        );
        $stmt->execute([$this->user_id]);
        $count = (int)$stmt->fetch()['cnt'];

        if ($count > ANTICHEAT_MAX_ACCOUNTS_PER_DEVICE) {
            $this->flag('multi_account', 'high', [
                'accounts_on_device' => $count,
                'cap'                => ANTICHEAT_MAX_ACCOUNTS_PER_DEVICE,
            ]);
        }

        // Emulator signals: check device_fingerprints for emulator markers
        $stmt = $this->db->prepare(
            "SELECT device_model, os_version FROM device_fingerprints
             WHERE user_id = ? ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute([$this->user_id]);
        $fp = $stmt->fetch();
        if ($fp) {
            $model = strtolower((string)($fp['device_model'] ?? ''));
            $emulator_markers = ['sdk_gphone', 'android sdk', 'emulator', 'generic', 'bluestacks'];
            foreach ($emulator_markers as $marker) {
                if (str_contains($model, $marker)) {
                    $this->flag('emulator_detected', 'medium', [
                        'device_model' => $fp['device_model'],
                    ]);
                    break;
                }
            }
        }

        $this->applyScoreActions();
    }

    // ============================================================
    // HOURLY VELOCITY TRACKING
    // ============================================================

    /**
     * Record earned coins into the hourly bucket.
     * Call AFTER the transaction is committed.
     */
    public function recordEarning(int $coins, float $php_value): void {
        $hour = date('Y-m-d H:00:00');
        $this->db->prepare(
            'INSERT INTO hourly_earnings (user_id, hour_bucket, coins_earned, php_earned)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                coins_earned = coins_earned + VALUES(coins_earned),
                php_earned   = php_earned   + VALUES(php_earned)'
        )->execute([$this->user_id, $hour, $coins, $php_value]);
    }

    // ============================================================
    // PRIVATE HELPERS
    // ============================================================

    private function isVelocityExceeded(string $metric, int $cap): bool {
        $hour = date('Y-m-d H:00:00');
        $stmt = $this->db->prepare(
            'SELECT coins_earned FROM hourly_earnings
             WHERE user_id = ? AND hour_bucket = ?'
        );
        $stmt->execute([$this->user_id, $hour]);
        $row = $stmt->fetch();
        return $row && (int)$row['coins_earned'] >= $cap;
    }

    private function isLevelVelocityExceeded(): bool {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS cnt FROM level_progress
             WHERE user_id = ? AND completed_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
        $stmt->execute([$this->user_id]);
        return (int)$stmt->fetch()['cnt'] >= ANTICHEAT_MAX_LEVELS_PER_HOUR;
    }

    private function checkGeo(): void {
        if ($this->ip === '' || $this->ip === '127.0.0.1') {
            return;
        }

        // Check cache first
        $stmt = $this->db->prepare(
            'SELECT country_code, is_vpn, is_datacenter FROM ip_geo_cache WHERE ip_address = ?'
        );
        $stmt->execute([$this->ip]);
        $geo = $stmt->fetch();

        if (!$geo) {
            // Look up via ip-api.com (free tier: 1,000/min, no key needed)
            $geo = $this->lookupGeo($this->ip);
        }

        if (!$geo) {
            return;
        }

        // Flag non-PH traffic
        if (!empty($geo['country_code']) && $geo['country_code'] !== 'PH') {
            $this->flag('geo_mismatch', 'medium', [
                'ip'           => $this->ip,
                'country_code' => $geo['country_code'],
            ]);
        }

        // Flag VPN / datacenter IP
        if (!empty($geo['is_vpn']) || !empty($geo['is_datacenter'])) {
            $this->flag('suspicious_pattern', 'medium', [
                'ip'             => $this->ip,
                'is_vpn'         => (bool)$geo['is_vpn'],
                'is_datacenter'  => (bool)$geo['is_datacenter'],
            ]);
        }
    }

    private function lookupGeo(string $ip): ?array {
        $url = "http://ip-api.com/json/{$ip}?fields=status,countryCode,proxy,hosting";
        $ctx = stream_context_create(['http' => ['timeout' => 2]]);
        $res = @file_get_contents($url, false, $ctx);
        if (!$res) {
            return null;
        }
        $data = json_decode($res, true);
        if (!$data || $data['status'] !== 'success') {
            return null;
        }

        $geo = [
            'country_code'  => $data['countryCode'] ?? null,
            'is_vpn'        => (int)($data['proxy']   ?? 0),
            'is_datacenter' => (int)($data['hosting'] ?? 0),
        ];

        // Cache result
        try {
            $this->db->prepare(
                'INSERT INTO ip_geo_cache (ip_address, country_code, is_vpn, is_datacenter)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    country_code = VALUES(country_code),
                    is_vpn = VALUES(is_vpn),
                    is_datacenter = VALUES(is_datacenter),
                    cached_at = NOW()'
            )->execute([$ip, $geo['country_code'], $geo['is_vpn'], $geo['is_datacenter']]);
        } catch (Throwable) {}

        return $geo;
    }

    private function flag(string $type, string $severity, array $details = []): void {
        // Score weights per severity
        $weights = ['low' => 10, 'medium' => 25, 'high' => 50, 'critical' => 100];
        $score   = $weights[$severity] ?? 10;

        try {
            $this->db->prepare(
                'INSERT INTO risk_flags (user_id, flag_type, severity, details)
                 VALUES (?, ?, ?, ?)'
            )->execute([
                $this->user_id,
                $type,
                $severity,
                json_encode($details + ['ip' => $this->ip]),
            ]);

            // Upsert risk score
            $this->db->prepare(
                'INSERT INTO user_risk_scores (user_id, risk_score, last_flag_at)
                 VALUES (?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    risk_score   = risk_score + ?,
                    last_flag_at = NOW()'
            )->execute([$this->user_id, $score, $score]);

        } catch (Throwable $e) {
            error_log('RiskEngine::flag failed: ' . $e->getMessage());
        }
    }

    private function getCurrentRiskScore(): int {
        $stmt = $this->db->prepare(
            'SELECT risk_score FROM user_risk_scores WHERE user_id = ?'
        );
        $stmt->execute([$this->user_id]);
        $row = $stmt->fetch();
        return $row ? (int)$row['risk_score'] : 0;
    }

    private function applyScoreActions(): void {
        $score = $this->getCurrentRiskScore();

        if ($score >= RISK_SCORE_AUTOBAN) {
            // Auto-ban: update users table and log the action
            $this->db->prepare(
                "UPDATE users SET is_banned = 1, ban_reason = 'Auto-banned: risk_score={$score}'
                 WHERE id = ? AND is_banned = 0"
            )->execute([$this->user_id]);

            $this->db->prepare(
                'UPDATE user_risk_scores SET auto_ban_at = NOW() WHERE user_id = ? AND auto_ban_at IS NULL'
            )->execute([$this->user_id]);

            error_log("Auto-ban: user_id={$this->user_id} risk_score={$score}");

        } elseif ($score >= RISK_SCORE_REVIEW) {
            // Queue for admin review by inserting a manual_review flag (once)
            $stmt = $this->db->prepare(
                "SELECT id FROM risk_flags WHERE user_id = ? AND flag_type = 'manual_review'
                 AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) LIMIT 1"
            );
            $stmt->execute([$this->user_id]);
            if (!$stmt->fetch()) {
                $this->flag('manual_review', 'medium', [
                    'reason'     => 'risk_score_threshold',
                    'risk_score' => $score,
                ]);
            }
        }
    }
}
