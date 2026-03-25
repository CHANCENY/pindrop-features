CREATE TABLE playlists (
                           id INT AUTO_INCREMENT PRIMARY KEY,
                           title VARCHAR(255) NOT NULL,
                           description TEXT NULL,
                           session_token VARCHAR(255) NULL, -- for anonymous users

                           is_public TINYINT(1) DEFAULT 0,

                           created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                           updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE playlist_items (
                                id INT AUTO_INCREMENT PRIMARY KEY,
                                playlist_id INT NOT NULL,
                                video_id INT NOT NULL,
                                position INT DEFAULT 0,

                                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

                                FOREIGN KEY (playlist_id) REFERENCES playlists(id) ON DELETE CASCADE
);