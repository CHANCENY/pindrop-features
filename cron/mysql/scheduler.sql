CREATE TABLE schedulers (
                            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

                            name VARCHAR(255) NOT NULL,
                            category VARCHAR(100) NOT NULL,
                            description TEXT,

                            timezone VARCHAR(100) NOT NULL,
                            environment VARCHAR(50) NOT NULL,

                            definition TEXT NOT NULL,

                            retry_on_failed VARCHAR(50) DEFAULT 'No retry',

                            delay_seconds INT UNSIGNED DEFAULT 0,

                            notify TINYINT(1) DEFAULT 0, -- 1 = on, 0 = off

                            email VARCHAR(255),

                            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                            INDEX idx_name (name),
                            INDEX idx_category (category),
                            INDEX idx_environment (environment)
);