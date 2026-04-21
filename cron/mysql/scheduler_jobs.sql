CREATE TABLE scheduler_jobs (
                                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

                                job_name VARCHAR(255) NOT NULL,
                                expression VARCHAR(100) NOT NULL, -- cron expression
                                subscriber VARCHAR(100) NOT NULL,

                                status ENUM('running', 'success', 'failed', 'paused') DEFAULT 'running',
                                job BIGINT UNSIGNED NOT NULL,

                                last_run DATETIME NULL,
                                next_run DATETIME NULL,

                                duration_seconds INT UNSIGNED DEFAULT 0,

                                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                                INDEX idx_job_name (job_name),
                                INDEX idx_status (status),
                                INDEX idx_next_run (next_run),

                                CONSTRAINT fk_scheduler_jobs_user
                                    FOREIGN KEY (`job`)
                                        REFERENCES `schedulers`(`id`)
                                        ON DELETE CASCADE
                                        ON UPDATE CASCADE
);