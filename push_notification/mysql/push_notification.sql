CREATE TABLE push_notification_user_allowed (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NOT NULL,
    google_json JSON NULL,
    public_key VARCHAR(500) NOT NULL,
    private_key VARCHAR(500) NOT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY push_notification_user_allowed_user (user_id),
    INDEX idx_user_id (user_id)

    -- Optional if you have users table
    ,FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE push_notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    uid BIGINT UNSIGNED NOT NULL,
    content_json JSON NULL,
  
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_user_push_id (uid)

    -- Optional if you have users table
    ,FOREIGN KEY (uid) REFERENCES users(id) ON DELETE CASCADE
);