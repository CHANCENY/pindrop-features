CREATE TABLE movies (
                        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

                        title VARCHAR(255) NOT NULL,
                        genres JSON NOT NULL,
                        release_date DATETIME NOT NULL,
                        duration INT UNSIGNED NOT NULL,

                        rating DECIMAL(3,1) DEFAULT NULL,
                        popularity DECIMAL(10,4) DEFAULT NULL,

                        description TEXT NULL,

                        video_file_id BIGINT UNSIGNED NOT NULL,
                        video_path VARCHAR(500) DEFAULT NULL,

                        thumbnail_path VARCHAR(500) DEFAULT NULL,

                        imdb_id VARCHAR(20) DEFAULT NULL,

                        featured TINYINT(1) DEFAULT 0,

                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                        INDEX idx_title (title),
                        INDEX idx_release_date (release_date),
                        INDEX idx_featured (featured),
                        INDEX idx_imdb (imdb_id)
);