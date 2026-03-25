CREATE TABLE player_logs (
                             id INT AUTO_INCREMENT PRIMARY KEY,
                             user_id INT NULL,
                             video_id INT NOT NULL,
                             current_time_played INT NOT NULL DEFAULT 0,
                             duration INT NOT NULL DEFAULT 0,
                             event VARCHAR(50) NOT NULL,
                             ip_address VARCHAR(45) NULL,
                             user_agent TEXT NULL,
                             media VARCHAR(50) NOT NULL,
                             created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                             updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                             INDEX (video_id),
                             INDEX (user_id)
);