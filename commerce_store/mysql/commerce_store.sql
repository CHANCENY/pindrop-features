-- Commerce Store Table Schema
-- Stores commerce store information linked to users

CREATE TABLE IF NOT EXISTS `commerce_stores` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL COMMENT 'Unique identifier for the store',
    `user_id` BIGINT UNSIGNED NOT NULL COMMENT 'Reference to the store owner (user)',
    `store_name` VARCHAR(255) NOT NULL COMMENT 'Store display name',
    `store_slug` VARCHAR(255) NOT NULL COMMENT 'URL-friendly store identifier',
    `store_description` TEXT NULL DEFAULT NULL COMMENT 'Store description',
    `store_logo_url` VARCHAR(500) NULL DEFAULT NULL COMMENT 'Store logo image URL',
    `store_banner_url` VARCHAR(500) NULL DEFAULT NULL COMMENT 'Store banner image URL',
    `store_email` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Store contact email',
    `store_phone` VARCHAR(20) NULL DEFAULT NULL COMMENT 'Store contact phone',
    `store_website` VARCHAR(500) NULL DEFAULT NULL COMMENT 'Store website URL',
    `store_address` TEXT NULL DEFAULT NULL COMMENT 'Store physical address',
    `store_city` VARCHAR(100) NULL DEFAULT NULL COMMENT 'Store city',
    `store_state` VARCHAR(100) NULL DEFAULT NULL COMMENT 'Store state/province',
    `store_country` VARCHAR(2) NULL DEFAULT NULL COMMENT 'Store country code (ISO 3166-1 alpha-2)',
    `store_postal_code` VARCHAR(20) NULL DEFAULT NULL COMMENT 'Store postal/zip code',
    `currency` VARCHAR(3) NOT NULL DEFAULT 'USD' COMMENT 'Store default currency (ISO 4217)',
    `timezone` VARCHAR(50) NULL DEFAULT NULL COMMENT 'Store timezone',
    `language` VARCHAR(10) NULL DEFAULT NULL COMMENT 'Store default language',
    `business_type` ENUM('individual', 'company', 'partnership', 'corporation', 'non_profit') NOT NULL DEFAULT 'individual' COMMENT 'Business type',
    `business_registration_number` VARCHAR(100) NULL DEFAULT NULL COMMENT 'Business registration number',
    `tax_id` VARCHAR(100) NULL DEFAULT NULL COMMENT 'Tax identification number',
    `store_status` ENUM('active', 'inactive', 'suspended', 'pending_approval', 'rejected', 'closed') NOT NULL DEFAULT 'pending_approval' COMMENT 'Store status',
    `is_featured` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Featured store flag',
    `is_verified` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Store verification status',
    `verified_at` DATETIME NULL DEFAULT NULL COMMENT 'Store verification timestamp',
    `rating_average` DECIMAL(3,2) NOT NULL DEFAULT 0.00 COMMENT 'Average customer rating',
    `rating_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Total number of ratings',
    `total_sales` DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Total sales amount',
    `total_orders` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Total number of orders',
    `commission_rate` DECIMAL(5,4) NOT NULL DEFAULT 0.0000 COMMENT 'Commission rate (decimal)',
    `settings` JSON NULL DEFAULT NULL COMMENT 'Store settings as JSON',
    `social_links` JSON NULL DEFAULT NULL COMMENT 'Social media links as JSON',
    `business_hours` JSON NULL DEFAULT NULL COMMENT 'Business hours as JSON',
    `shipping_policies` JSON NULL DEFAULT NULL COMMENT 'Shipping policies as JSON',
    `return_policies` JSON NULL DEFAULT NULL COMMENT 'Return policies as JSON',
    `payment_methods` JSON NULL DEFAULT NULL COMMENT 'Accepted payment methods as JSON',
    `metadata` JSON NULL DEFAULT NULL COMMENT 'Additional metadata as JSON',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Store creation timestamp',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Store update timestamp',
    `deleted_at` DATETIME NULL DEFAULT NULL COMMENT 'Soft delete timestamp',
    
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_uuid` (`uuid`),
    UNIQUE KEY `uk_user_id` (`user_id`),
    UNIQUE KEY `uk_store_slug` (`store_slug`),
    INDEX `idx_store_name` (`store_name`),
    INDEX `idx_store_status` (`store_status`),
    INDEX `idx_is_featured` (`is_featured`),
    INDEX `idx_is_verified` (`is_verified`),
    INDEX `idx_rating_average` (`rating_average`),
    INDEX `idx_total_sales` (`total_sales`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_deleted_at` (`deleted_at`),
    INDEX `idx_store_country` (`store_country`),
    INDEX `idx_business_type` (`business_type`),
    
    CONSTRAINT `fk_commerce_stores_user_id` 
        FOREIGN KEY (`user_id`) 
        REFERENCES `users` (`id`) 
        ON DELETE CASCADE 
        ON UPDATE CASCADE,
    
    CONSTRAINT `chk_store_status` CHECK (`store_status` IN ('active', 'inactive', 'suspended', 'pending_approval', 'rejected', 'closed')),
    CONSTRAINT `chk_business_type` CHECK (`business_type` IN ('individual', 'company', 'partnership', 'corporation', 'non_profit')),
    CONSTRAINT `chk_rating_average` CHECK (`rating_average` >= 0.00 AND `rating_average` <= 5.00),
    CONSTRAINT `chk_commission_rate` CHECK (`commission_rate` >= 0.0000 AND `commission_rate` <= 1.0000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Commerce stores table linked to users';

-- Store Categories Table (Many-to-Many relationship)
CREATE TABLE IF NOT EXISTS `commerce_store_categories` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL COMMENT 'Unique identifier for the store category',
    `store_id` BIGINT UNSIGNED NOT NULL COMMENT 'Reference to the store',
    `category_name` VARCHAR(255) NOT NULL COMMENT 'Category name',
    `category_slug` VARCHAR(255) NOT NULL COMMENT 'URL-friendly category identifier',
    `category_description` TEXT NULL DEFAULT NULL COMMENT 'Category description',
    `category_image_url` VARCHAR(500) NULL DEFAULT NULL COMMENT 'Category image URL',
    `parent_id` BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Parent category ID for nested categories',
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Display sort order',
    `is_active` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Category active status',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Category creation timestamp',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Category update timestamp',
    `deleted_at` DATETIME NULL DEFAULT NULL COMMENT 'Soft delete timestamp',
    
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_uuid` (`uuid`),
    UNIQUE KEY `uk_store_category_slug` (`store_id`, `category_slug`),
    INDEX `idx_store_id` (`store_id`),
    INDEX `idx_category_name` (`category_name`),
    INDEX `idx_parent_id` (`parent_id`),
    INDEX `idx_sort_order` (`sort_order`),
    INDEX `idx_is_active` (`is_active`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_deleted_at` (`deleted_at`),
    
    CONSTRAINT `fk_commerce_store_categories_store_id` 
        FOREIGN KEY (`store_id`) 
        REFERENCES `commerce_stores` (`id`) 
        ON DELETE CASCADE 
        ON UPDATE CASCADE,
    
    CONSTRAINT `fk_commerce_store_categories_parent_id` 
        FOREIGN KEY (`parent_id`) 
        REFERENCES `commerce_store_categories` (`id`) 
        ON DELETE SET NULL 
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Store categories for organizing products';

-- Store Staff Table (Users who can manage the store)
CREATE TABLE IF NOT EXISTS `commerce_store_staff` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL COMMENT 'Unique identifier for the staff member',
    `store_id` BIGINT UNSIGNED NOT NULL COMMENT 'Reference to the store',
    `user_id` BIGINT UNSIGNED NOT NULL COMMENT 'Reference to the user (staff member)',
    `role` ENUM('owner', 'manager', 'employee', 'support') NOT NULL DEFAULT 'employee' COMMENT 'Staff role in the store',
    `permissions` JSON NULL DEFAULT NULL COMMENT 'Staff permissions as JSON',
    `is_active` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Staff member active status',
    `hired_at` DATETIME NULL DEFAULT NULL COMMENT 'Hire date timestamp',
    `terminated_at` DATETIME NULL DEFAULT NULL COMMENT 'Termination date timestamp',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Staff assignment timestamp',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Staff update timestamp',
    `deleted_at` DATETIME NULL DEFAULT NULL COMMENT 'Soft delete timestamp',
    
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_uuid` (`uuid`),
    UNIQUE KEY `uk_store_user` (`store_id`, `user_id`),
    INDEX `idx_store_id` (`store_id`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_role` (`role`),
    INDEX `idx_is_active` (`is_active`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_deleted_at` (`deleted_at`),
    
    CONSTRAINT `fk_commerce_store_staff_store_id` 
        FOREIGN KEY (`store_id`) 
        REFERENCES `commerce_stores` (`id`) 
        ON DELETE CASCADE 
        ON UPDATE CASCADE,
    
    CONSTRAINT `fk_commerce_store_staff_user_id` 
        FOREIGN KEY (`user_id`) 
        REFERENCES `users` (`id`) 
        ON DELETE CASCADE 
        ON UPDATE CASCADE,
    
    CONSTRAINT `chk_staff_role` CHECK (`role` IN ('owner', 'manager', 'employee', 'support'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Store staff members and their roles';

-- Triggers for automatic slug generation and rating updates
DELIMITER //

CREATE TRIGGER IF NOT EXISTS `tr_commerce_stores_before_insert`
BEFORE INSERT ON `commerce_stores`
FOR EACH ROW
BEGIN
    IF NEW.store_slug IS NULL THEN
        SET NEW.store_slug = LOWER(REPLACE(REPLACE(REPLACE(NEW.store_name, ' ', '-'), '_', '-'), '--', '-'));
    END IF;
END//

CREATE TRIGGER IF NOT EXISTS `tr_commerce_stores_before_update`
BEFORE UPDATE ON `commerce_stores`
FOR EACH ROW
BEGIN
    IF NEW.store_slug IS NULL OR (NEW.store_name <> OLD.store_name) THEN
        SET NEW.store_slug = LOWER(REPLACE(REPLACE(REPLACE(NEW.store_name, ' ', '-'), '_', '-'), '--', '-'));
    END IF;
END//

CREATE TRIGGER IF NOT EXISTS `tr_commerce_store_categories_before_insert`
BEFORE INSERT ON `commerce_store_categories`
FOR EACH ROW
BEGIN
    IF NEW.category_slug IS NULL THEN
        SET NEW.category_slug = LOWER(REPLACE(REPLACE(REPLACE(NEW.category_name, ' ', '-'), '_', '-'), '--', '-'));
    END IF;
END//

CREATE TRIGGER IF NOT EXISTS `tr_commerce_store_categories_before_update`
BEFORE UPDATE ON `commerce_store_categories`
FOR EACH ROW
BEGIN
    IF NEW.category_slug IS NULL OR (NEW.category_name <> OLD.category_name) THEN
        SET NEW.category_slug = LOWER(REPLACE(REPLACE(REPLACE(NEW.category_name, ' ', '-'), '_', '-'), '--', '-'));
    END IF;
END//

DELIMITER ;

-- Add full-text search indexes for store search (optional)
-- ALTER TABLE `commerce_stores` ADD FULLTEXT `ft_search` (`store_name`, `store_description`);
-- ALTER TABLE `commerce_store_categories` ADD FULLTEXT `ft_search` (`category_name`, `category_description`);