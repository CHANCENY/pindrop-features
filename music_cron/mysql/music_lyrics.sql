CREATE TABLE music_lyrics_cron (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    track_id            INT UNSIGNED NOT NULL,
    status              ENUM('pending', 'in_progress', 'downloaded') NOT NULL DEFAULT 'pending',
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;