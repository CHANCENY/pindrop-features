CREATE TABLE episodes (
                          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

                          imdb_id BIGINT UNSIGNED NOT NULL,
                          series_id BIGINT UNSIGNED NOT NULL,
                          season_id BIGINT UNSIGNED NOT NULL,

                          name VARCHAR(255) NOT NULL,
                          video_id BIGINT UNSIGNED NULL,
                          overview TEXT NULL,

                          episode_number INT UNSIGNED NOT NULL,
                          season_number INT UNSIGNED NOT NULL,

                          episode_type VARCHAR(50) DEFAULT 'standard',

                          air_date DATE NULL,
                          runtime INT UNSIGNED NULL,

                          production_code VARCHAR(50) NULL,
                          still_path VARCHAR(255) NULL,

                          vote_average DECIMAL(3,1) DEFAULT 0.0,
                          vote_count INT UNSIGNED DEFAULT 0,

                          crew JSON NULL,
                          guest_stars JSON NULL,

                          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                          UNIQUE KEY unique_episode (season_id, episode_number),
                          INDEX (series_id),
                          INDEX (season_id),
                          INDEX (imdb_id),

                          FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE CASCADE,
                          FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE CASCADE

);