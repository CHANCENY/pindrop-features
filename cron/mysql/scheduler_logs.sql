CREATE TABLE scheduler_logs (
                                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                                job_name VARCHAR(255) NOT NULL,
                                schedule_id BIGINT UNSIGNED NOT NULL,
                                message_type ENUM('start', 'debug', 'warn', 'error', 'ok', 'info') DEFAULT 'info',
                                message TEXT NULL,
                                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

                                CONSTRAINT fk_scheduler_logs_s
                                    FOREIGN KEY (`schedule_id`)
                                        REFERENCES `scheduler_jobs`(`id`)
                                        ON DELETE CASCADE
                                        ON UPDATE CASCADE
);