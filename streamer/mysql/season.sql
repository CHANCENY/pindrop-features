CREATE TABLE seasons (
                         id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

                         series_id BIGINT UNSIGNED NOT NULL,

                         title VARCHAR(255) NOT NULL,
                         season_number INT UNSIGNED NOT NULL,

                         vote_average DECIMAL(3,1) DEFAULT 0.0,
                         episode_count INT UNSIGNED DEFAULT 0,

                         air_date DATE NULL,

                         description TEXT NULL,

                         poster VARCHAR(255) NULL,
                         thumbnail_path VARCHAR(255) NULL,

                         imdb_id VARCHAR(255) NULL,

                         created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                         updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                         UNIQUE KEY unique_series_season (series_id, season_number),

                         INDEX (series_id),
                         INDEX (season_number),

                         CONSTRAINT fk_season_series
                             FOREIGN KEY (series_id)
                                 REFERENCES series(id)
                                 ON DELETE CASCADE

);