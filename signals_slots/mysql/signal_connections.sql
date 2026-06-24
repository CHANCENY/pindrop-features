-- =========================================================
-- SIGNAL CONNECTIONS
-- =========================================================
-- Admin-wired mapping of a signal key to a slot id.
-- mode: 'sync'  → slot runs immediately in the same request
--       'async' → a row is pushed to signal_queue, drained by cron
-- =========================================================

CREATE TABLE `signal_connections` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `signal_key` VARCHAR(255) NOT NULL,
    `slot_id`    VARCHAR(255) NOT NULL,
    `mode`       ENUM('sync','async') NOT NULL DEFAULT 'sync',
    `active`     TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_signal_slot` (`signal_key`, `slot_id`),
    KEY `idx_conn_signal` (`signal_key`),
    KEY `idx_conn_active` (`active`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
