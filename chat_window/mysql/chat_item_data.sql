CREATE TABLE chat_item_data (
                                     id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                                     cid BIGINT UNSIGNED NOT NULL,
                                     message_type ENUM('customer', 'support') DEFAULT 'customer',
                                     content TEXT NULL,
                                     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                     updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP,

                                     CONSTRAINT fk_chat_m_cid
                                        FOREIGN KEY (`cid`)
                                            REFERENCES `chat_item`(`id`)
                                            ON DELETE CASCADE
                                            ON UPDATE CASCADE
);

