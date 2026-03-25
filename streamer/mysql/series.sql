CREATE TABLE series (
                        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

                        title VARCHAR(255) NOT NULL,

                        genres JSON NOT NULL,

                        start_year DATE NULL,
                        end_year DATE NULL,

                        rating DECIMAL(3,1) DEFAULT 0.0,

                        status VARCHAR(50) NOT NULL,

                        description TEXT NULL,

                        language VARCHAR(10) NULL,
                        country VARCHAR(10) NULL,

                        poster VARCHAR(255) NULL,
                        thumbnail_path VARCHAR(255) NULL,

                        season_count INT UNSIGNED DEFAULT 0,

                        imdb_id VARCHAR(255) NULL,

                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                        INDEX (title),
                        INDEX (status),
                        INDEX (rating)
);