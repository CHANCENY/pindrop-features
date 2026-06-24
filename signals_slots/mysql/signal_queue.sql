-- =========================================================
-- SIGNAL QUEUE  (async deliveries)
-- =========================================================
-- Rows are written by SignalBus::emit() for async connections
-- and drained by SignalQueueSubscriber on each cron tick.
-- status: pending → processing → done | failed
-- =========================================================

CREATE TABLE `signal_queue` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `signal_key`   VARCHAR(255) NOT NULL,
    `slot_id`      VARCHAR(255) NOT NULL,
    `payload`      JSON NULL,
    `status`       ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
    `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `processed_at` TIMESTAMP NULL,

    PRIMARY KEY (`id`),
    KEY `idx_queue_status`  (`status`),
    KEY `idx_queue_created` (`created_at`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
