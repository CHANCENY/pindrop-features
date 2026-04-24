CREATE TABLE support_team_member (
                                     id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                                     first_name VARCHAR(100) NOT NULL,
                                     last_name VARCHAR(100) NOT NULL,
                                     email VARCHAR(150) NOT NULL UNIQUE,
                                     phone VARCHAR(20) DEFAULT NULL,
                                     uid BIGINT UNSIGNED NOT NULL,
                                     status ENUM('active', 'inactive') DEFAULT 'inactive',
                                     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                     updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP,

                                     CONSTRAINT fk_team_member_uid
                                         FOREIGN KEY (`uid`)
                                             REFERENCES `users`(`id`)
                                             ON DELETE CASCADE
                                             ON UPDATE CASCADE
);

