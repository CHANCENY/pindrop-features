CREATE TABLE recent_log (
                      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

                      title VARCHAR(255) NOT NULL,
                      type VARCHAR(100) NOT NULL,

                      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

                      INDEX idx_type (type),
                      INDEX idx_created_at (created_at)
);