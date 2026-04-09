CREATE TABLE structure_configuration (
                                         id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                                         name VARCHAR(255) NOT NULL,
                                         config LONGBLOB NOT NULL,
                                         config_type VARCHAR(255) NOT NULL,
                                         deleted INT NULL,
    -- Indexes
                                         UNIQUE KEY uniq_name (name)
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;