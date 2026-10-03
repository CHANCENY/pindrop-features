CREATE TABLE yt_dlp_links (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    link                VARCHAR(500) NOT NULL,
    local_path          VARCHAR(500) NOT NULL,
    name                VARCHAR(500) NOT NULL,
    status              ENUM('pending', 'downloading', 'completed', 'failed') NOT NULL DEFAULT 'pending',
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_link (link)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;