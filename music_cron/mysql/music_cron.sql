CREATE TABLE music_cron_spotify_download (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    url                 VARCHAR(700) NOT NULL,
    status              ENUM('pending', 'downloading', 'downloaded') NOT NULL DEFAULT 'pending',
    downloaded_size     INT UNSIGNED,
    download_total_size INT UNSIGNED,
    album_name          VARCHAR(255) NOT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;