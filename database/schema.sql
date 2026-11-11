-- ============================================================
-- PuzzLink PH - MySQL Database Schema
-- Version: 1.0
-- ============================================================

CREATE DATABASE IF NOT EXISTS puzzlink_ph CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE puzzlink_ph;

-- ------------------------------------------------------------
-- USERS
-- Core account table. Stores identity and device info.
-- ------------------------------------------------------------
CREATE TABLE users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid            CHAR(36)        NOT NULL UNIQUE,                -- client-safe public ID
    username        VARCHAR(50)     NOT NULL UNIQUE,
    email           VARCHAR(255)    DEFAULT NULL,                   -- optional; for GCash/Maya linking
    password_hash   VARCHAR(255)    NOT NULL,
    gcash_number    VARCHAR(20)     DEFAULT NULL,                   -- ₱ payout destination
    maya_number     VARCHAR(20)     DEFAULT NULL,
    device_id       VARCHAR(255)    DEFAULT NULL,                   -- fingerprint for anti-cheat
    country_code    CHAR(2)         NOT NULL DEFAULT 'PH',
    is_banned       TINYINT(1)      NOT NULL DEFAULT 0,
    ban_reason      VARCHAR(255)    DEFAULT NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at   TIMESTAMP       NULL,
    INDEX idx_uuid (uuid),
    INDEX idx_device_id (device_id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- BALANCES
-- One row per user. Coin totals are stored server-side only.
-- Never trust client-submitted balance values.
-- ------------------------------------------------------------
CREATE TABLE balances (
    user_id         INT UNSIGNED    PRIMARY KEY,
    coins           BIGINT UNSIGNED NOT NULL DEFAULT 0,     -- raw coin units
    php_equivalent  DECIMAL(10,4)   NOT NULL DEFAULT 0.0000,-- precomputed ₱ value
    total_earned    DECIMAL(10,4)   NOT NULL DEFAULT 0.0000,-- lifetime earnings
    total_paid_out  DECIMAL(10,4)   NOT NULL DEFAULT 0.0000,-- lifetime paid out
    updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_balance_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- TRANSACTIONS
-- Immutable ledger. Every coin credit/debit is recorded here.
-- source_type drives anti-abuse validation.
-- ------------------------------------------------------------
CREATE TABLE transactions (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED    NOT NULL,
    amount_coins    BIGINT          NOT NULL,                -- positive = credit, negative = debit
    php_value       DECIMAL(10,4)   NOT NULL DEFAULT 0.0000,
    source_type     ENUM(
                        'ad_rewarded',      -- watched rewarded video
                        'ad_interstitial',  -- passive interstitial credit
                        'offerwall',        -- completed offerwall task
                        'level_complete',   -- puzzle solved reward
                        'bonus_double',     -- 2x reward from ad watch
                        'payout_debit',     -- deducted on cashout
                        'admin_adjustment', -- manual CS correction
                        'referral_bonus'    -- future: referral program
                    ) NOT NULL,
    source_ref      VARCHAR(255)    DEFAULT NULL,           -- e.g., ad network impression ID
    idempotency_key VARCHAR(64)     NOT NULL UNIQUE,        -- prevent duplicate credits
    ip_address      VARCHAR(45)     DEFAULT NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_txn_user (user_id),
    INDEX idx_txn_source (source_type),
    INDEX idx_txn_created (created_at),
    CONSTRAINT fk_txn_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- PAYOUT REQUESTS
-- Tracks all cashout attempts. Status FSM:
--   pending -> processing -> completed | failed | rejected
-- ------------------------------------------------------------
CREATE TABLE payout_requests (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED    NOT NULL,
    amount_php      DECIMAL(10,2)   NOT NULL,
    payout_method   ENUM('gcash','maya') NOT NULL,
    payout_number   VARCHAR(20)     NOT NULL,               -- copied at request time
    status          ENUM('pending','processing','completed','failed','rejected')
                                    NOT NULL DEFAULT 'pending',
    tier            TINYINT UNSIGNED NOT NULL DEFAULT 1,    -- 1=first cashout, 2+=subsequent
    threshold_met   TINYINT(1)      NOT NULL DEFAULT 0,     -- validated server-side
    rejection_reason VARCHAR(255)   DEFAULT NULL,
    requested_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at    TIMESTAMP       NULL,
    external_ref    VARCHAR(255)    DEFAULT NULL,           -- payment gateway transaction ID
    INDEX idx_payout_user (user_id),
    INDEX idx_payout_status (status),
    CONSTRAINT fk_payout_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- AD_EVENTS
-- Rate-limiting table. Prevents ad reward farming.
-- Records every ad impression claimed per user per day.
-- ------------------------------------------------------------
CREATE TABLE ad_events (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED    NOT NULL,
    ad_type         ENUM('rewarded','interstitial') NOT NULL,
    ad_network      VARCHAR(50)     DEFAULT NULL,           -- e.g., 'admob', 'unity'
    impression_id   VARCHAR(255)    NOT NULL,               -- from ad SDK
    rewarded_coins  INT UNSIGNED    NOT NULL DEFAULT 0,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ad_user_day (user_id, created_at),
    UNIQUE KEY uq_impression (impression_id),               -- deduplicate replays
    CONSTRAINT fk_ad_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- OFFERWALL_EVENTS
-- Tracks completed offerwall tasks from networks (e.g., Adjoe,
-- Tapjoy, IronSource). Callback signature verified server-side.
-- ------------------------------------------------------------
CREATE TABLE offerwall_events (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED    NOT NULL,
    network         VARCHAR(50)     NOT NULL,               -- 'tapjoy', 'adjoe', etc.
    offer_id        VARCHAR(255)    NOT NULL,
    transaction_id  VARCHAR(255)    NOT NULL UNIQUE,        -- network's unique TX ID
    usd_value       DECIMAL(8,4)    NOT NULL DEFAULT 0.0000,
    coins_awarded   INT UNSIGNED    NOT NULL DEFAULT 0,
    callback_verified TINYINT(1)    NOT NULL DEFAULT 0,     -- HMAC/secret verified
    raw_payload     JSON            DEFAULT NULL,           -- store full callback for audit
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ow_user (user_id),
    CONSTRAINT fk_ow_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- LEVEL_PROGRESS
-- Tracks puzzle completions for anti-cheat and reward gating.
-- ------------------------------------------------------------
CREATE TABLE level_progress (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED    NOT NULL,
    level_id        INT UNSIGNED    NOT NULL,
    completed_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    time_taken_sec  SMALLINT UNSIGNED DEFAULT NULL,         -- for anomaly detection
    coins_earned    INT UNSIGNED    NOT NULL DEFAULT 0,
    was_doubled     TINYINT(1)      NOT NULL DEFAULT 0,     -- rewarded video 2x used
    INDEX idx_lp_user (user_id),
    INDEX idx_lp_level (level_id),
    CONSTRAINT fk_lp_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- RATE_LIMIT_LOG
-- Simple token-bucket style log for endpoint abuse detection.
-- ------------------------------------------------------------
CREATE TABLE rate_limit_log (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier      VARCHAR(255)    NOT NULL,               -- user_id or IP
    endpoint        VARCHAR(100)    NOT NULL,
    hit_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rl_identifier (identifier, endpoint, hit_at)
) ENGINE=InnoDB;
