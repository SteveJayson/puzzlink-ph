-- ============================================================
-- PuzzLink PH - Migration v3 (Phase 4: Ad Mediation)
-- Run after migration_v2.sql
-- ============================================================

USE puzzlink_ph;

-- ------------------------------------------------------------
-- 1. Add SSV verification flag to ad_events
--    ssv_verified = 1 means AppLovin MAX server confirmed the
--    impression via S2S webhook. 0 = client-side trust only.
-- ------------------------------------------------------------
ALTER TABLE ad_events
    ADD COLUMN IF NOT EXISTS ssv_verified TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = AppLovin MAX SSV webhook confirmed',
    ADD COLUMN IF NOT EXISTS revenue_usd  DECIMAL(8,6) DEFAULT NULL
        COMMENT 'Estimated ad revenue from MAX impression callback';

-- Index for revenue reporting
ALTER TABLE ad_events
    ADD INDEX IF NOT EXISTS idx_ad_ssv (ssv_verified, created_at);

-- ------------------------------------------------------------
-- 2. Expand offerwall_events to track network-specific data
-- ------------------------------------------------------------
ALTER TABLE offerwall_events
    ADD COLUMN IF NOT EXISTS country_code CHAR(2)       DEFAULT 'PH',
    ADD COLUMN IF NOT EXISTS offer_type   VARCHAR(50)   DEFAULT NULL
        COMMENT 'e.g. survey, app_install, video_series',
    ADD COLUMN IF NOT EXISTS reversed     TINYINT(1)    NOT NULL DEFAULT 0
        COMMENT '1 = chargeback/reversal received from network';

-- ------------------------------------------------------------
-- 3. AD_REVENUE_LOG  (new)
--    Tracks estimated revenue per impression for financial
--    reporting and eCPM monitoring. MAX provides this in
--    impression-level revenue callbacks.
-- ------------------------------------------------------------
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
    INDEX idx_rev_network (ad_network),
    CONSTRAINT fk_rev_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB COMMENT='Impression-level revenue tracking from MAX callbacks';

-- ------------------------------------------------------------
-- 4. OFFERWALL_REVERSALS  (new)
--    Tracks chargebacks from offerwall networks.
--    When a reversal arrives, the coins must be deducted if
--    the user still has sufficient balance.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS offerwall_reversals (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    original_event_id  BIGINT UNSIGNED NOT NULL,
    user_id            INT UNSIGNED    NOT NULL,
    network            VARCHAR(50)     NOT NULL,
    transaction_id     VARCHAR(255)    NOT NULL UNIQUE,
    coins_reversed     INT UNSIGNED    NOT NULL DEFAULT 0,
    php_reversed       DECIMAL(10,4)   NOT NULL DEFAULT 0.0000,
    reason             VARCHAR(255)    DEFAULT NULL,
    processed_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rev_user (user_id),
    CONSTRAINT fk_rev_oe FOREIGN KEY (original_event_id)
        REFERENCES offerwall_events(id) ON DELETE RESTRICT
) ENGINE=InnoDB COMMENT='Offerwall chargeback/reversal records';
