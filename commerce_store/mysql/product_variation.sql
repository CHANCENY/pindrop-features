-- Commerce Product Variation Table
-- Stores product variations for variable products

CREATE TABLE IF NOT EXISTS `commerce_product_variations` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` VARCHAR(36) NOT NULL,
    `product_id` BIGINT UNSIGNED NOT NULL,
    `sku` VARCHAR(100) DEFAULT NULL,
    `name` VARCHAR(255) DEFAULT NULL,
    `slug` VARCHAR(255) DEFAULT NULL,
    `description` TEXT,
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
    `virtual` BOOLEAN NOT NULL DEFAULT FALSE,
    `downloadable` BOOLEAN NOT NULL DEFAULT FALSE,
    `download_limit` INT UNSIGNED DEFAULT NULL,
    `download_expiry` INT UNSIGNED DEFAULT NULL,
    `image_id` BIGINT UNSIGNED DEFAULT NULL,
    `attributes` JSON DEFAULT NULL,
    `meta_data` JSON DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by` BIGINT UNSIGNED DEFAULT NULL,
    `updated_by` BIGINT UNSIGNED DEFAULT NULL,
    `published_at` DATETIME DEFAULT NULL,
    `deleted_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_uuid` (`uuid`),
    UNIQUE KEY `unique_product_sku` (`product_id`, `sku`),
    KEY `idx_product_id` (`product_id`),
    KEY `idx_status` (`status`),
    KEY `idx_featured` (`featured`),
    KEY `idx_catalog_visibility` (`catalog_visibility`),
    KEY `idx_stock_status` (`stock_status`),
    KEY `idx_image_id` (`image_id`),
    KEY `idx_created_at` (`created_at`),
    KEY `idx_updated_at` (`updated_at`),
    KEY `idx_published_at` (`published_at`),
    KEY `idx_menu_order` (`menu_order`),
    CONSTRAINT `fk_variation_product` FOREIGN KEY (`product_id`) REFERENCES `commerce_products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_variation_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_variation_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Product Variation Attributes Table
-- Stores specific attributes for each variation

CREATE TABLE IF NOT EXISTS `commerce_variation_attributes` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `variation_id` BIGINT UNSIGNED NOT NULL,
    `attribute_name` VARCHAR(100) NOT NULL,
    `attribute_value` VARCHAR(255) NOT NULL,
    `attribute_type` ENUM('text', 'number', 'boolean', 'select', 'multiselect') NOT NULL DEFAULT 'text',
    `attribute_order` INT UNSIGNED DEFAULT 0,
    `is_visible` BOOLEAN NOT NULL DEFAULT TRUE,
    `is_variation` BOOLEAN NOT NULL DEFAULT TRUE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_variation_attribute` (`variation_id`, `attribute_name`),
    KEY `idx_variation_id` (`variation_id`),
    KEY `idx_attribute_name` (`attribute_name`),
    KEY `idx_attribute_type` (`attribute_type`),
    KEY `idx_attribute_order` (`attribute_order`),
    KEY `idx_is_visible` (`is_visible`),
    KEY `idx_is_variation` (`is_variation`),
    CONSTRAINT `fk_variation_attribute_variation` FOREIGN KEY (`variation_id`) REFERENCES `commerce_product_variations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Product Variation Images Table
-- Stores images for product variations

CREATE TABLE IF NOT EXISTS `commerce_variation_images` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `variation_id` BIGINT UNSIGNED NOT NULL,
    `image_url` VARCHAR(500) NOT NULL,
    `image_alt` VARCHAR(255) DEFAULT NULL,
    `image_title` VARCHAR(255) DEFAULT NULL,
    `image_order` INT UNSIGNED DEFAULT 0,
    `is_featured` BOOLEAN NOT NULL DEFAULT FALSE,
    `image_width` INT UNSIGNED DEFAULT NULL,
    `image_height` INT UNSIGNED DEFAULT NULL,
    `file_size` BIGINT UNSIGNED DEFAULT NULL,
    `mime_type` VARCHAR(100) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_variation_id` (`variation_id`),
    KEY `idx_image_order` (`image_order`),
    KEY `idx_is_featured` (`is_featured`),
    KEY `idx_created_at` (`created_at`),
    CONSTRAINT `fk_variation_image_variation` FOREIGN KEY (`variation_id`) REFERENCES `commerce_product_variations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Trigger to generate UUID for new variations
DELIMITER //
CREATE TRIGGER `before_variation_insert` 
BEFORE INSERT ON `commerce_product_variations`
FOR EACH ROW
BEGIN
    IF NEW.uuid IS NULL OR NEW.uuid = '' THEN
        SET NEW.uuid = UUID();
    END IF;
END//
DELIMITER ;

-- Trigger to generate variation slug if empty
DELIMITER //
CREATE TRIGGER `before_variation_insert_slug` 
BEFORE INSERT ON `commerce_product_variations`
FOR EACH ROW
BEGIN
    IF NEW.slug IS NULL OR NEW.slug = '' THEN
        IF NEW.name IS NOT NULL AND NEW.name != '' THEN
            SET NEW.slug = LOWER(REPLACE(REPLACE(REPLACE(NEW.name, ' ', '-'), '.', '-'), '_', '-'));
            SET NEW.slug = REGEXP_REPLACE(NEW.slug, '[^a-z0-9-]', '');
            SET NEW.slug = REGEXP_REPLACE(NEW.slug, '-+', '-');
            SET NEW.slug = TRIM(BOTH '-' FROM NEW.slug);
        ELSE
            SET NEW.slug = CONCAT('variation-', NEW.id);
        END IF;
    END IF;
END//
DELIMITER ;

-- Trigger to ensure unique variation slug within product
DELIMITER //
CREATE TRIGGER `before_variation_update_slug` 
BEFORE UPDATE ON `commerce_product_variations`
FOR EACH ROW
BEGIN
    DECLARE slug_count INT DEFAULT 0;
    
    IF NEW.slug IS NULL OR NEW.slug = '' THEN
        IF NEW.name IS NOT NULL AND NEW.name != '' THEN
            SET NEW.slug = LOWER(REPLACE(REPLACE(REPLACE(NEW.name, ' ', '-'), '.', '-'), '_', '-'));
            SET NEW.slug = REGEXP_REPLACE(NEW.slug, '[^a-z0-9-]', '');
            SET NEW.slug = REGEXP_REPLACE(NEW.slug, '-+', '-');
            SET NEW.slug = TRIM(BOTH '-' FROM NEW.slug);
        ELSE
            SET NEW.slug = CONCAT('variation-', NEW.id);
        END IF;
    END IF;
    
    IF NEW.slug != OLD.slug THEN
        SELECT COUNT(*) INTO slug_count 
        FROM `commerce_product_variations` 
        WHERE slug = NEW.slug AND product_id = NEW.product_id AND id != NEW.id;
        
        IF slug_count > 0 THEN
            SET NEW.slug = CONCAT(NEW.slug, '-', NEW.id);
        END IF;
    END IF;
END//
DELIMITER ;

-- Trigger to ensure at least one variation attribute exists
DELIMITER //
CREATE TRIGGER `before_variation_insert_attributes` 
BEFORE INSERT ON `commerce_product_variations`
FOR EACH ROW
BEGIN
    DECLARE attr_count INT DEFAULT 0;
    
    SELECT COUNT(*) INTO attr_count
    FROM `commerce_variation_attributes`
    WHERE variation_id = NEW.id;
    
    IF attr_count = 0 THEN
        -- Insert default attribute if none exist
        INSERT INTO `commerce_variation_attributes` (
            variation_id, attribute_name, attribute_value, attribute_type, attribute_order, is_visible, is_variation
        ) VALUES (
            NEW.id, 'default', 'default', 'text', 0, TRUE, TRUE
        );
    END IF;
END//
DELIMITER ;