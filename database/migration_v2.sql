-- ============================================================
-- PuzzLink PH - Migration v1 -> v2
-- Run this AFTER schema.sql is already applied.
-- Safe to run on a live DB (uses IF NOT EXISTS / IF EXISTS).
-- ============================================================

USE puzzlink_ph;

-- ------------------------------------------------------------
-- 1. Add firebase_uid to users
--    Allows anonymous device-based login via Firebase Auth
--    without requiring a username/password on first install.
-- ------------------------------------------------------------
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS firebase_uid VARCHAR(128) DEFAULT NULL
        COMMENT 'Firebase anonymous or social UID',
    ADD COLUMN IF NOT EXISTS android_id   VARCHAR(64)  DEFAULT NULL
        COMMENT 'Android Settings.Secure.ANDROID_ID',
    ADD UNIQUE INDEX IF NOT EXISTS idx_firebase_uid (firebase_uid);

-- ------------------------------------------------------------
-- 2. Add gcash_name to payout_requests
--    Required by most GCash B2C disbursement APIs for name
--    matching / compliance.
-- ------------------------------------------------------------
ALTER TABLE payout_requests
    ADD COLUMN IF NOT EXISTS payout_name VARCHAR(100) DEFAULT NULL
        COMMENT 'Account holder name for GCash/Maya compliance'
        AFTER payout_number;

-- ------------------------------------------------------------
-- 3. DEVICE_FINGERPRINTS (new table)
--    Separate from users to support multi-account detection.
--    One device can be linked to multiple user rows so we can
--    flag and audit the relationship without hard-blocking.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS device_fingerprints (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED    NOT NULL,
    hardware_id     VARCHAR(255)    DEFAULT NULL,   -- Build.SERIAL or equivalent
    android_id      VARCHAR(64)     DEFAULT NULL,   -- Settings.Secure.ANDROID_ID
    device_model    VARCHAR(100)    DEFAULT NULL,   -- e.g., "Samsung Galaxy A14"
    os_version      VARCHAR(20)     DEFAULT NULL,   -- e.g., "Android 13"
    app_version     VARCHAR(20)     DEFAULT NULL,
    ip_address      VARCHAR(45)     DEFAULT NULL,
    is_flagged      TINYINT(1)      NOT NULL DEFAULT 0,
    flag_reason     VARCHAR(255)    DEFAULT NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at    TIMESTAMP       NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_df_user (user_id),
    INDEX idx_df_android_id (android_id),
    INDEX idx_df_hardware_id (hardware_id),
    CONSTRAINT fk_df_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB COMMENT='Per-device fingerprint log for multi-account detection';

-- ------------------------------------------------------------
-- 4. APP_SESSIONS (new table)
--    Tracks install + session events. Useful for:
--    - DAU/MAU analytics
--    - Detecting emulator installs (emulators show identical
--      hardware IDs across many accounts)
--    - Spotting VPN/proxy abuse via IP geolocation
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS app_sessions (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED    NOT NULL,
    fingerprint_id  INT UNSIGNED    DEFAULT NULL,
    session_token   VARCHAR(64)     NOT NULL UNIQUE,  -- random, single-use session tag
    ip_address      VARCHAR(45)     DEFAULT NULL,
    is_emulator     TINYINT(1)      NOT NULL DEFAULT 0,
    is_vpn          TINYINT(1)      NOT NULL DEFAULT 0,
    started_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at        TIMESTAMP       NULL,
    INDEX idx_sess_user (user_id),
    CONSTRAINT fk_sess_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 5. PUZZLE_LEVELS (new table)
--    Server-authoritative puzzle catalogue.
--    Client downloads level data; server can update without
--    an APK update (content delivery).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS puzzle_levels (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    level_number    INT UNSIGNED    NOT NULL UNIQUE,
    category        VARCHAR(50)     DEFAULT 'general',  -- e.g., 'tagalog', 'english'
    difficulty      ENUM('easy','medium','hard') NOT NULL DEFAULT 'easy',
    grid_data       JSON            NOT NULL,            -- puzzle grid + solution
    coin_reward     INT UNSIGNED    NOT NULL DEFAULT 20,
    is_active       TINYINT(1)      NOT NULL DEFAULT 1,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pl_number (level_number),
    INDEX idx_pl_difficulty (difficulty)
) ENGINE=InnoDB COMMENT='Server-side puzzle catalogue (updatable without APK)';

-- ============================================================
-- Verification query — run to confirm all tables exist:
-- SELECT TABLE_NAME FROM information_schema.TABLES
-- WHERE TABLE_SCHEMA = 'puzzlink_ph' ORDER BY TABLE_NAME;
-- ============================================================
