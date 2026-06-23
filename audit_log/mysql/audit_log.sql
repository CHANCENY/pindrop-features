-- =========================================================
-- AUDIT LOG ENTRIES
-- =========================================================
-- Stores every security-relevant event emitted by the
-- framework (login, logout, failed auth, user lifecycle,
-- plugin installs) as well as any entry written manually
-- by other plugins via AuditLogService::log().
-- =========================================================

CREATE TABLE `audit_log_entries` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Actor (nullable — e.g. anonymous failed login has no user)
    `user_id`       BIGINT UNSIGNED          NULL,
    `username`      VARCHAR(255)             NULL,

    -- What happened
    `action`        VARCHAR(100)             NOT NULL,
    `resource_type` VARCHAR(100)             NULL,
    `resource_id`   VARCHAR(255)             NULL,

    -- Where from
    `ip_address`    VARCHAR(45)              NULL,
    `user_agent`    VARCHAR(512)             NULL,

    -- Extra structured data (JSON blob, plugin-specific)
    `payload`       JSON                     NULL,

    -- Severity: info | warning | critical
    `severity`      ENUM('info','warning','critical') NOT NULL DEFAULT 'info',

    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),

    KEY `idx_audit_user_id`   (`user_id`),
    KEY `idx_audit_action`    (`action`),
    KEY `idx_audit_severity`  (`severity`),
    KEY `idx_audit_created`   (`created_at`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
