-- =========================================================
-- SIGNAL DELIVERY LOG
-- =========================================================
-- One row per slot invocation (sync or async).
-- Failures log the exception message; no retries.
-- =========================================================

CREATE TABLE `signal_delivery_log` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `signal_key`  VARCHAR(255) NOT NULL,
    `slot_id`     VARCHAR(255) NOT NULL,
    `mode`        ENUM('sync','async') NOT NULL,
    `payload`     JSON NULL,
    `success`     TINYINT(1) NOT NULL DEFAULT 0,
    `error`       TEXT NULL,
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_log_signal`  (`signal_key`),
    KEY `idx_log_slot`    (`slot_id`),
    KEY `idx_log_success` (`success`),
    KEY `idx_log_created` (`created_at`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
