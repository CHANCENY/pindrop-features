-- Commerce Product Table
-- Stores product information for the commerce store

CREATE TABLE IF NOT EXISTS `commerce_products` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` VARCHAR(36) NOT NULL,
    `store_id` BIGINT UNSIGNED NOT NULL,
    `sku` VARCHAR(100) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `slug` VARCHAR(255) NOT NULL,
    `description` TEXT,
    `short_description` TEXT,
    `type` ENUM('simple', 'variable', 'grouped', 'external', 'bundle') NOT NULL DEFAULT 'simple',
    `status` ENUM('draft', 'pending', 'private', 'publish', 'trash') NOT NULL DEFAULT 'draft',
    `featured` BOOLEAN NOT NULL DEFAULT FALSE,
    `catalog_visibility` ENUM('visible', 'catalog', 'search', 'hidden') NOT NULL DEFAULT 'visible',
    `regular_price` DECIMAL(19,4) DEFAULT NULL,
    `sale_price` DECIMAL(19,4) DEFAULT NULL,
    `sale_price_start_date` DATETIME DEFAULT NULL,
    `sale_price_end_date` DATETIME DEFAULT NULL,
    `tax_status` ENUM('taxable', 'shipping', 'none') NOT NULL DEFAULT 'taxable',
    `tax_class` VARCHAR(100) DEFAULT NULL,
    `manage_stock` BOOLEAN NOT NULL DEFAULT TRUE,
    `stock_quantity` INT UNSIGNED DEFAULT 0,
    `stock_status` ENUM('instock', 'outofstock', 'onbackorder') NOT NULL DEFAULT 'instock',
    `backorders_allowed` BOOLEAN NOT NULL DEFAULT FALSE,
    `sold_individually` BOOLEAN NOT NULL DEFAULT FALSE,
    `weight` DECIMAL(10,2) DEFAULT NULL,
    `dimensions_length` DECIMAL(10,2) DEFAULT NULL,
    `dimensions_width` DECIMAL(10,2) DEFAULT NULL,
    `dimensions_height` DECIMAL(10,2) DEFAULT NULL,
    `shipping_class` VARCHAR(100) DEFAULT NULL,
    `shipping_required` BOOLEAN NOT NULL DEFAULT TRUE,
    `purchase_note` TEXT,
    `menu_order` INT UNSIGNED DEFAULT 0,
    `reviews_allowed` BOOLEAN NOT NULL DEFAULT TRUE,
    `average_rating` DECIMAL(3,2) DEFAULT 0.00,
    `rating_count` INT UNSIGNED DEFAULT 0,
    `total_sales` INT UNSIGNED DEFAULT 0,
    `virtual` BOOLEAN NOT NULL DEFAULT FALSE,
    `downloadable` BOOLEAN NOT NULL DEFAULT FALSE,
    `download_limit` INT UNSIGNED DEFAULT NULL,
    `download_expiry` INT UNSIGNED DEFAULT NULL,
    `external_url` VARCHAR(255) DEFAULT NULL,
    `button_text` VARCHAR(255) DEFAULT NULL,
    `parent_id` BIGINT UNSIGNED DEFAULT NULL,
    `grouped_products` JSON DEFAULT NULL,
    `upsell_products` JSON DEFAULT NULL,
    `cross_sell_products` JSON DEFAULT NULL,
    `categories` JSON DEFAULT NULL,
    `tags` JSON DEFAULT NULL,
    `attributes` JSON DEFAULT NULL,
    `default_attributes` JSON DEFAULT NULL,
    `variations` JSON DEFAULT NULL,
    `meta_data` JSON DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by` BIGINT UNSIGNED DEFAULT NULL,
    `updated_by` BIGINT UNSIGNED DEFAULT NULL,
    `published_at` DATETIME DEFAULT NULL,
    `deleted_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_uuid` (`uuid`),
    UNIQUE KEY `unique_store_sku` (`store_id`, `sku`),
    UNIQUE KEY `unique_store_slug` (`store_id`, `slug`),
    KEY `idx_store_id` (`store_id`),
    KEY `idx_type` (`type`),
    KEY `idx_status` (`status`),
    KEY `idx_featured` (`featured`),
    KEY `idx_catalog_visibility` (`catalog_visibility`),
    KEY `idx_stock_status` (`stock_status`),
    KEY `idx_parent_id` (`parent_id`),
    KEY `idx_created_at` (`created_at`),
    KEY `idx_updated_at` (`updated_at`),
    KEY `idx_published_at` (`published_at`),
    KEY `idx_menu_order` (`menu_order`),
    KEY `idx_total_sales` (`total_sales`),
    KEY `idx_average_rating` (`average_rating`),
    CONSTRAINT `fk_product_store` FOREIGN KEY (`store_id`) REFERENCES `commerce_stores` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_product_parent` FOREIGN KEY (`parent_id`) REFERENCES `commerce_products` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_product_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_product_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Index for full-text search on product name and description
CREATE FULLTEXT INDEX `ft_product_search` ON `commerce_products` (`name`, `description`, `short_description`);

-- Trigger to generate UUID for new products
DELIMITER //
CREATE TRIGGER `before_product_insert` 
BEFORE INSERT ON `commerce_products`
FOR EACH ROW
BEGIN
    IF NEW.uuid IS NULL OR NEW.uuid = '' THEN
        SET NEW.uuid = UUID();
    END IF;
END//
DELIMITER ;

-- Trigger to update slug if empty
DELIMITER //
CREATE TRIGGER `before_product_insert_slug` 
BEFORE INSERT ON `commerce_products`
FOR EACH ROW
BEGIN
    IF NEW.slug IS NULL OR NEW.slug = '' THEN
        SET NEW.slug = LOWER(REPLACE(REPLACE(REPLACE(NEW.name, ' ', '-'), '.', '-'), '_', '-'));
        SET NEW.slug = REGEXP_REPLACE(NEW.slug, '[^a-z0-9-]', '');
        SET NEW.slug = REGEXP_REPLACE(NEW.slug, '-+', '-');
        SET NEW.slug = TRIM(BOTH '-' FROM NEW.slug);
    END IF;
END//
DELIMITER ;

-- Trigger to ensure unique slug within store
DELIMITER //
CREATE TRIGGER `before_product_update_slug` 
BEFORE UPDATE ON `commerce_products`
FOR EACH ROW
BEGIN
    DECLARE slug_count INT DEFAULT 0;
    
    IF NEW.slug IS NULL OR NEW.slug = '' THEN
        SET NEW.slug = LOWER(REPLACE(REPLACE(REPLACE(NEW.name, ' ', '-'), '.', '-'), '_', '-'));
        SET NEW.slug = REGEXP_REPLACE(NEW.slug, '[^a-z0-9-]', '');
        SET NEW.slug = REGEXP_REPLACE(NEW.slug, '-+', '-');
        SET NEW.slug = TRIM(BOTH '-' FROM NEW.slug);
    END IF;
    
    IF NEW.slug != OLD.slug THEN
        SELECT COUNT(*) INTO slug_count 
        FROM `commerce_products` 
        WHERE slug = NEW.slug AND store_id = NEW.store_id AND id != NEW.id;
        
        IF slug_count > 0 THEN
            SET NEW.slug = CONCAT(NEW.slug, '-', NEW.id);
        END IF;
    END IF;
END//
DELIMITER ;