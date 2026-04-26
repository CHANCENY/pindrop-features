-- Pig Farmer Records Management Schema

CREATE TABLE IF NOT EXISTS `pig_farmer_pigs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `tag_number` VARCHAR(50) NOT NULL UNIQUE,
    `breed` VARCHAR(100),
    `gender` ENUM('Male', 'Female') NOT NULL,
    `birth_date` DATE,
    `status` ENUM('Active', 'Sold', 'Deceased') DEFAULT 'Active',
    `weight` DECIMAL(10, 2),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS `pig_farmer_health_records` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `pig_id` INT NOT NULL,
    `checkup_date` DATE NOT NULL,
    `condition` TEXT,
    `treatment` TEXT,
    `notes` TEXT,
    FOREIGN KEY (`pig_id`) REFERENCES `pig_farmer_pigs`(`id`) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS `pig_farmer_feeding_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `pig_id` INT NOT NULL,
    `feed_type` VARCHAR(100),
    `quantity` DECIMAL(10, 2),
    `feed_date` DATE NOT NULL,
    FOREIGN KEY (`pig_id`) REFERENCES `pig_farmer_pigs`(`id`) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS `pig_farmer_finances` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `transaction_date` DATE NOT NULL,
    `type` ENUM('Income', 'Expense') NOT NULL,
    `category` VARCHAR(100) NOT NULL,
    `amount` DECIMAL(15, 2) NOT NULL,
    `description` TEXT,
    `pig_id` INT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`pig_id`) REFERENCES `pig_farmer_pigs`(`id`) ON DELETE SET NULL
    );