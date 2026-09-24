CREATE TABLE mailbox_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    folder VARCHAR(191) NOT NULL,
    uid INT UNSIGNED NOT NULL,
    uidvalidity BIGINT UNSIGNED NOT NULL,

    message_id VARCHAR(255) NULL,
    in_reply_to VARCHAR(255) NULL,

    subject VARCHAR(998) NOT NULL DEFAULT '',
    from_name VARCHAR(255) NULL,
    from_email VARCHAR(255) NULL,
    to_json TEXT NULL,
    cc_json TEXT NULL,
    message_date DATETIME NULL,

    size_bytes INT UNSIGNED DEFAULT 0,
    has_attachments TINYINT(1) DEFAULT 0,
    snippet VARCHAR(500) NOT NULL DEFAULT '',

    is_seen TINYINT(1) DEFAULT 0,
    is_flagged TINYINT(1) DEFAULT 0,
    is_answered TINYINT(1) DEFAULT 0,
    is_draft TINYINT(1) DEFAULT 0,
    is_deleted TINYINT(1) DEFAULT 0,

    raw_cached LONGTEXT NULL,
    cached_at DATETIME NULL,

    synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE INDEX idx_folder_uid (folder, uid),
    INDEX idx_folder_date (folder, message_date),
    INDEX idx_message_id (message_id),
    INDEX idx_is_seen (folder, is_seen)
);
