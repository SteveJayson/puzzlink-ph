-- ============================================================
-- PuzzLink PH - Migration v4 (Phase 5: Anti-Cheat & Payouts)
-- Run after migration_v3.sql
-- ============================================================

USE puzzlink_ph;

-- ------------------------------------------------------------
-- 1. RISK_FLAGS
--    One row per risk event. Multiple flags accumulate a
--    risk_score on the user. The admin dashboard shows this.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS risk_flags (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED    NOT NULL,
    flag_type       ENUM(
                        'speed_hack',           -- puzzle solved impossibly fast
                        'velocity_coins',       -- earned too many coins per hour
                        'velocity_offerwall',   -- completed too many offers too fast
                        'multi_account',        -- same device, multiple accounts
                        'geo_mismatch',         -- IP geolocation != PH
                        'emulator_detected',    -- emulator fingerprint signals
                        'failed_payout_abuse',  -- repeated failed payout attempts
                        'suspicious_pattern',   -- ML/rule-based catch-all
                        'manual_review'         -- admin-raised flag
                    ) NOT NULL,
    severity        ENUM('low','medium','high','critical') NOT NULL DEFAULT 'low',
    details         JSON            DEFAULT NULL,  -- context: IP, device, values
    auto_actioned   TINYINT(1)      NOT NULL DEFAULT 0,  -- 1 = system auto-banned
    reviewed        TINYINT(1)      NOT NULL DEFAULT 0,  -- 1 = admin reviewed
    reviewer_id     INT UNSIGNED    DEFAULT NULL,
    reviewed_at     TIMESTAMP       NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rf_user (user_id),
    INDEX idx_rf_type (flag_type),
    INDEX idx_rf_reviewed (reviewed, severity),
    CONSTRAINT fk_rf_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB COMMENT='Anti-cheat risk event log';

-- ------------------------------------------------------------
-- 2. USER_RISK_SCORES
--    Running tally. Updated by triggers / anti-cheat engine.
--    score >= 100 triggers auto-ban review.
--    score >= 200 triggers immediate auto-ban.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_risk_scores (
    user_id         INT UNSIGNED    PRIMARY KEY,
    risk_score      INT UNSIGNED    NOT NULL DEFAULT 0,
    last_flag_at    TIMESTAMP       NULL,
    auto_ban_at     TIMESTAMP       NULL,
    updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_urs_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 3. HOURLY_EARNINGS
--    Rolling hourly earnings tracker for velocity enforcement.
--    Faster to query than summing transactions every request.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS hourly_earnings (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED    NOT NULL,
    hour_bucket     DATETIME        NOT NULL,  -- truncated to the hour: 2026-11-11 14:00:00
    coins_earned    INT UNSIGNED    NOT NULL DEFAULT 0,
    php_earned      DECIMAL(10,4)   NOT NULL DEFAULT 0.0000,
    INDEX idx_he_user_hour (user_id, hour_bucket),
    CONSTRAINT fk_he_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB COMMENT='Per-hour earnings for velocity cap enforcement';

-- ------------------------------------------------------------
-- 4. ADMIN_USERS
--    Staff accounts for the admin dashboard.
--    Completely separate from game user accounts.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admin_users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(50)     NOT NULL UNIQUE,
    password_hash   VARCHAR(255)    NOT NULL,
    email           VARCHAR(255)    NOT NULL UNIQUE,
    role            ENUM('super_admin','admin','reviewer') NOT NULL DEFAULT 'reviewer',
    is_active       TINYINT(1)      NOT NULL DEFAULT 1,
    last_login_at   TIMESTAMP       NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB COMMENT='Admin dashboard staff accounts';

-- ------------------------------------------------------------
-- 5. Enrich payout_requests with admin processing columns
-- ------------------------------------------------------------
ALTER TABLE payout_requests
    ADD COLUMN IF NOT EXISTS reviewed_by    INT UNSIGNED  DEFAULT NULL
        COMMENT 'admin_users.id who approved/rejected',
    ADD COLUMN IF NOT EXISTS review_notes   TEXT          DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS xendit_ref     VARCHAR(255)  DEFAULT NULL
        COMMENT 'Xendit disbursement reference ID',
    ADD COLUMN IF NOT EXISTS risk_score_at_request INT UNSIGNED DEFAULT 0
        COMMENT 'User risk score at time of request (snapshot)';

ALTER TABLE payout_requests
    ADD INDEX IF NOT EXISTS idx_pr_reviewed_by (reviewed_by);

-- ------------------------------------------------------------
-- 6. Add risk_score snapshot to users for fast dashboard queries
-- ------------------------------------------------------------
-- (risk score is maintained in user_risk_scores; no column needed on users)

-- ------------------------------------------------------------
-- 7. IP_GEOLOCATION_CACHE
--    Cache MaxMind / ip-api.com results to avoid per-request
--    geolocation API calls (rate-limited on free tiers).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ip_geo_cache (
    ip_address      VARCHAR(45)     PRIMARY KEY,
    country_code    CHAR(2)         DEFAULT NULL,
    country_name    VARCHAR(100)    DEFAULT NULL,
    region          VARCHAR(100)    DEFAULT NULL,
    is_vpn          TINYINT(1)      NOT NULL DEFAULT 0,
    is_datacenter   TINYINT(1)      NOT NULL DEFAULT 0,
    cached_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_geo_country (country_code)
) ENGINE=InnoDB COMMENT='Cached IP geolocation results';
