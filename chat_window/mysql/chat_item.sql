CREATE TABLE chat_item (
                                     id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                                     tm_id BIGINT UNSIGNED NULL,
                                     cid BIGINT UNSIGNED NOT NULL,
                                     status ENUM('open', 'closed', 'resolved', 'abandon') NOT NULL DEFAULT 'open',
                                     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                     updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP,

                                     CONSTRAINT fk_chat_item_tm
                                         FOREIGN KEY (`tm_id`)
                                             REFERENCES `support_team_member`(`id`)
                                             ON DELETE CASCADE
                                             ON UPDATE CASCADE,
                                     CONSTRAINT fk_customer_id
                                        FOREIGN KEY (`cid`)
                                            REFERENCES `customer`(`id`)
                                            ON DELETE CASCADE
                                            ON UPDATE CASCADE
);

