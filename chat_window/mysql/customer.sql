CREATE TABLE customer (
                                     id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                                     first_name VARCHAR(100) NOT NULL,
                                     last_name VARCHAR(100) NOT NULL,
                                     email VARCHAR(150) NOT NULL UNIQUE,
                                     phone VARCHAR(20) DEFAULT NULL,
                                     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                     updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP
);

