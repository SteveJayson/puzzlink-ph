-- ============================================================
-- PuzzLink PH - Complete Database Schema (All Phases)
-- ============================================================
--
-- HOW TO IMPORT ON SHARED HOSTING (phpMyAdmin):
--   1. Create the database manually in phpMyAdmin first
--   2. Select (click) that database so it is active
--   3. Go to Import tab and upload this file
--
-- DO NOT run CREATE DATABASE yourself — your hosting user
-- does not have that permission. The database must already
-- exist and be selected before you import.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET sql_mode = '';

-- ============================================================
-- USERS
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid            CHAR(36)        NOT NULL UNIQUE,
    username        VARCHAR(50)     NOT NULL UNIQUE,
    email           VARCHAR(255)    DEFAULT NULL,
    password_hash   VARCHAR(255)    NOT NULL,
    gcash_number    VARCHAR(20)     DEFAULT NULL,
    maya_number     VARCHAR(20)     DEFAULT NULL,
    device_id       VARCHAR(255)    DEFAULT NULL,
    android_id      VARCHAR(64)     DEFAULT NULL,
    firebase_uid    VARCHAR(128)    DEFAULT NULL,
    country_code    CHAR(2)         NOT NULL DEFAULT 'PH',
    is_banned       TINYINT(1)      NOT NULL DEFAULT 0,
    ban_reason      VARCHAR(255)    DEFAULT NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at   TIMESTAMP       NULL,
    INDEX idx_uuid (uuid),
    INDEX idx_device_id (device_id),
    INDEX idx_android_id (android_id),
    UNIQUE INDEX idx_firebase_uid (firebase_uid)
) ENGINE=InnoDB;

-- ============================================================
-- BALANCES
-- ============================================================
CREATE TABLE IF NOT EXISTS balances (
    user_id         INT UNSIGNED    PRIMARY KEY,
    coins           BIGINT UNSIGNED NOT NULL DEFAULT 0,
    php_equivalent  DECIMAL(10,4)   NOT NULL DEFAULT 0.0000,
    total_earned    DECIMAL(10,4)   NOT NULL DEFAULT 0.0000,
    total_paid_out  DECIMAL(10,4)   NOT NULL DEFAULT 0.0000,
    updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_balance_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- TRANSACTIONS
-- ============================================================
CREATE TABLE IF NOT EXISTS transactions (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED    NOT NULL,
    amount_coins    BIGINT          NOT NULL,
    php_value       DECIMAL(10,4)   NOT NULL DEFAULT 0.0000,
    source_type     ENUM('ad_rewarded','ad_interstitial','offerwall','level_complete','bonus_double','payout_debit','admin_adjustment','referral_bonus') NOT NULL,
    source_ref      VARCHAR(255)    DEFAULT NULL,
    idempotency_key VARCHAR(64)     NOT NULL UNIQUE,
    ip_address      VARCHAR(45)     DEFAULT NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_txn_user (user_id),
    INDEX idx_txn_source (source_type),
    INDEX idx_txn_created (created_at),
    CONSTRAINT fk_txn_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ============================================================
-- PAYOUT REQUESTS
-- ============================================================
CREATE TABLE IF NOT EXISTS payout_requests (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id                 INT UNSIGNED    NOT NULL,
    amount_php              DECIMAL(10,2)   NOT NULL,
    payout_method           ENUM('gcash','maya') NOT NULL,
    payout_number           VARCHAR(20)     NOT NULL,
    payout_name             VARCHAR(100)    DEFAULT NULL,
    status                  ENUM('pending','processing','completed','failed','rejected') NOT NULL DEFAULT 'pending',
    tier                    TINYINT UNSIGNED NOT NULL DEFAULT 1,
    threshold_met           TINYINT(1)      NOT NULL DEFAULT 0,
    rejection_reason        VARCHAR(255)    DEFAULT NULL,
    requested_at            TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at            TIMESTAMP       NULL,
    external_ref            VARCHAR(255)    DEFAULT NULL,
    reviewed_by             INT UNSIGNED    DEFAULT NULL,
    review_notes            TEXT            DEFAULT NULL,
    xendit_ref              VARCHAR(255)    DEFAULT NULL,
    risk_score_at_request   INT UNSIGNED    DEFAULT 0,
    INDEX idx_payout_user (user_id),
    INDEX idx_payout_status (status),
    CONSTRAINT fk_payout_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ============================================================
-- AD EVENTS
-- ============================================================
CREATE TABLE IF NOT EXISTS ad_events (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED    NOT NULL,
    ad_type         ENUM('rewarded','interstitial') NOT NULL,
    ad_network      VARCHAR(50)     DEFAULT NULL,
    impression_id   VARCHAR(255)    NOT NULL,
    rewarded_coins  INT UNSIGNED    NOT NULL DEFAULT 0,
    ssv_verified    TINYINT(1)      NOT NULL DEFAULT 0,
    revenue_usd     DECIMAL(8,6)    DEFAULT NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ad_user_day (user_id, created_at),
    UNIQUE KEY uq_impression (impression_id),
    CONSTRAINT fk_ad_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- OFFERWALL EVENTS
-- ============================================================
CREATE TABLE IF NOT EXISTS offerwall_events (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id             INT UNSIGNED    NOT NULL,
    network             VARCHAR(50)     NOT NULL,
    offer_id            VARCHAR(255)    NOT NULL,
    transaction_id      VARCHAR(255)    NOT NULL UNIQUE,
    usd_value           DECIMAL(8,4)    NOT NULL DEFAULT 0.0000,
    coins_awarded       INT UNSIGNED    NOT NULL DEFAULT 0,
    callback_verified   TINYINT(1)      NOT NULL DEFAULT 0,
    country_code        CHAR(2)         DEFAULT 'PH',
    offer_type          VARCHAR(50)     DEFAULT NULL,
    reversed            TINYINT(1)      NOT NULL DEFAULT 0,
    raw_payload         JSON            DEFAULT NULL,
    created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ow_user (user_id),
    CONSTRAINT fk_ow_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ============================================================
-- LEVEL PROGRESS
-- ============================================================
CREATE TABLE IF NOT EXISTS level_progress (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED    NOT NULL,
    level_id        INT UNSIGNED    NOT NULL,
    completed_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    time_taken_sec  SMALLINT UNSIGNED DEFAULT NULL,
    coins_earned    INT UNSIGNED    NOT NULL DEFAULT 0,
    was_doubled     TINYINT(1)      NOT NULL DEFAULT 0,
    INDEX idx_lp_user (user_id),
    INDEX idx_lp_level (level_id),
    CONSTRAINT fk_lp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- RATE LIMIT LOG
-- ============================================================
CREATE TABLE IF NOT EXISTS rate_limit_log (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier      VARCHAR(255)    NOT NULL,
    endpoint        VARCHAR(100)    NOT NULL,
    hit_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rl_identifier (identifier, endpoint, hit_at)
) ENGINE=InnoDB;

-- ============================================================
-- DEVICE FINGERPRINTS
-- ============================================================
CREATE TABLE IF NOT EXISTS device_fingerprints (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED    NOT NULL,
    hardware_id     VARCHAR(255)    DEFAULT NULL,
    android_id      VARCHAR(64)     DEFAULT NULL,
    device_model    VARCHAR(100)    DEFAULT NULL,
    os_version      VARCHAR(20)     DEFAULT NULL,
    app_version     VARCHAR(20)     DEFAULT NULL,
    ip_address      VARCHAR(45)     DEFAULT NULL,
    is_flagged      TINYINT(1)      NOT NULL DEFAULT 0,
    flag_reason     VARCHAR(255)    DEFAULT NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at    TIMESTAMP       NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_df_user (user_id),
    INDEX idx_df_android_id (android_id),
    CONSTRAINT fk_df_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- APP SESSIONS
-- ============================================================
CREATE TABLE IF NOT EXISTS app_sessions (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED    NOT NULL,
    fingerprint_id  INT UNSIGNED    DEFAULT NULL,
    session_token   VARCHAR(64)     NOT NULL UNIQUE,
    ip_address      VARCHAR(45)     DEFAULT NULL,
    is_emulator     TINYINT(1)      NOT NULL DEFAULT 0,
    is_vpn          TINYINT(1)      NOT NULL DEFAULT 0,
    started_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at        TIMESTAMP       NULL,
    INDEX idx_sess_user (user_id),
    CONSTRAINT fk_sess_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- PUZZLE LEVELS
-- ============================================================
CREATE TABLE IF NOT EXISTS puzzle_levels (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    level_number    INT UNSIGNED    NOT NULL UNIQUE,
    category        VARCHAR(50)     DEFAULT 'general',
    difficulty      ENUM('easy','medium','hard') NOT NULL DEFAULT 'easy',
    grid_data       JSON            NOT NULL,
    coin_reward     INT UNSIGNED    NOT NULL DEFAULT 20,
    is_active       TINYINT(1)      NOT NULL DEFAULT 1,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pl_number (level_number),
    INDEX idx_pl_difficulty (difficulty)
) ENGINE=InnoDB;

-- ============================================================
-- AD REVENUE LOG
-- ============================================================
CREATE TABLE IF NOT EXISTS ad_revenue_log (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED    NOT NULL,
    ad_type         ENUM('rewarded','interstitial','banner') NOT NULL,
    ad_network      VARCHAR(50)     NOT NULL,
    ad_unit_id      VARCHAR(100)    DEFAULT NULL,
    impression_id   VARCHAR(255)    NOT NULL UNIQUE,
    revenue_usd     DECIMAL(10,8)   NOT NULL DEFAULT 0.00000000,
    country_code    CHAR(2)         DEFAULT 'PH',
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rev_user (user_id),
    INDEX idx_rev_date (created_at),
    CONSTRAINT fk_rev_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- OFFERWALL REVERSALS
-- ============================================================
CREATE TABLE IF NOT EXISTS offerwall_reversals (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    original_event_id   BIGINT UNSIGNED NOT NULL,
    user_id             INT UNSIGNED    NOT NULL,
    network             VARCHAR(50)     NOT NULL,
    transaction_id      VARCHAR(255)    NOT NULL UNIQUE,
    coins_reversed      INT UNSIGNED    NOT NULL DEFAULT 0,
    php_reversed        DECIMAL(10,4)   NOT NULL DEFAULT 0.0000,
    reason              VARCHAR(255)    DEFAULT NULL,
    processed_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_owr_user (user_id),
    CONSTRAINT fk_rev_oe FOREIGN KEY (original_event_id) REFERENCES offerwall_events(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ============================================================
-- RISK FLAGS
-- ============================================================
CREATE TABLE IF NOT EXISTS risk_flags (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED    NOT NULL,
    flag_type       ENUM('speed_hack','velocity_coins','velocity_offerwall','multi_account','geo_mismatch','emulator_detected','failed_payout_abuse','suspicious_pattern','manual_review') NOT NULL,
    severity        ENUM('low','medium','high','critical') NOT NULL DEFAULT 'low',
    details         JSON            DEFAULT NULL,
    auto_actioned   TINYINT(1)      NOT NULL DEFAULT 0,
    reviewed        TINYINT(1)      NOT NULL DEFAULT 0,
    reviewer_id     INT UNSIGNED    DEFAULT NULL,
    reviewed_at     TIMESTAMP       NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rf_user (user_id),
    INDEX idx_rf_type (flag_type),
    INDEX idx_rf_reviewed (reviewed, severity),
    CONSTRAINT fk_rf_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- USER RISK SCORES
-- ============================================================
CREATE TABLE IF NOT EXISTS user_risk_scores (
    user_id         INT UNSIGNED    PRIMARY KEY,
    risk_score      INT UNSIGNED    NOT NULL DEFAULT 0,
    last_flag_at    TIMESTAMP       NULL,
    auto_ban_at     TIMESTAMP       NULL,
    updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_urs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- HOURLY EARNINGS
-- ============================================================
CREATE TABLE IF NOT EXISTS hourly_earnings (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED    NOT NULL,
    hour_bucket     DATETIME        NOT NULL,
    coins_earned    INT UNSIGNED    NOT NULL DEFAULT 0,
    php_earned      DECIMAL(10,4)   NOT NULL DEFAULT 0.0000,
    INDEX idx_he_user_hour (user_id, hour_bucket),
    CONSTRAINT fk_he_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- ADMIN USERS
-- ============================================================
CREATE TABLE IF NOT EXISTS admin_users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(50)     NOT NULL UNIQUE,
    password_hash   VARCHAR(255)    NOT NULL,
    email           VARCHAR(255)    NOT NULL UNIQUE,
    role            ENUM('super_admin','admin','reviewer') NOT NULL DEFAULT 'reviewer',
    is_active       TINYINT(1)      NOT NULL DEFAULT 1,
    last_login_at   TIMESTAMP       NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- IP GEOLOCATION CACHE
-- ============================================================
CREATE TABLE IF NOT EXISTS ip_geo_cache (
    ip_address      VARCHAR(45)     PRIMARY KEY,
    country_code    CHAR(2)         DEFAULT NULL,
    country_name    VARCHAR(100)    DEFAULT NULL,
    region          VARCHAR(100)    DEFAULT NULL,
    is_vpn          TINYINT(1)      NOT NULL DEFAULT 0,
    is_datacenter   TINYINT(1)      NOT NULL DEFAULT 0,
    cached_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_geo_country (country_code)
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;

/* Done. 18 tables created.
   To verify: SELECT TABLE_NAME FROM information_schema.TABLES
   WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME; */
