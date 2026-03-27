-- Commerce Orders Schema
-- Complete order management system with customer, items, payments, and activity tracking

-- Customer Table (created first to avoid foreign key issues)
-- Customer information and addresses

CREATE TABLE IF NOT EXISTS `commerce_customer` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED DEFAULT NULL,
    `store_id` BIGINT UNSIGNED NOT NULL,
    `customer_type` ENUM('guest', 'registered') NOT NULL DEFAULT 'guest',
    `email` VARCHAR(255) NOT NULL,
    `phone` VARCHAR(50) DEFAULT NULL,
    `first_name` VARCHAR(100) DEFAULT NULL,
    `last_name` VARCHAR(100) DEFAULT NULL,
    `company` VARCHAR(255) DEFAULT NULL,
    `billing_address_1` VARCHAR(255) DEFAULT NULL,
    `billing_address_2` VARCHAR(255) DEFAULT NULL,
    `billing_city` VARCHAR(100) DEFAULT NULL,
    `billing_state` VARCHAR(100) DEFAULT NULL,
    `billing_postcode` VARCHAR(20) DEFAULT NULL,
    `billing_country` VARCHAR(2) DEFAULT NULL,
    `shipping_same_as_billing` BOOLEAN NOT NULL DEFAULT TRUE,
    `shipping_address_1` VARCHAR(255) DEFAULT NULL,
    `shipping_address_2` VARCHAR(255) DEFAULT NULL,
    `shipping_city` VARCHAR(100) DEFAULT NULL,
    `shipping_state` VARCHAR(100) DEFAULT NULL,
    `shipping_postcode` VARCHAR(20) DEFAULT NULL,
    `shipping_country` VARCHAR(2) DEFAULT NULL,
    `total_orders` INT UNSIGNED NOT NULL DEFAULT 0,
    `total_spent` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `last_order_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_customer_store_email` (`store_id`, `email`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_store_id` (`store_id`),
    KEY `idx_email` (`email`),
    KEY `idx_customer_type` (`customer_type`),
    KEY `idx_last_order_at` (`last_order_at`),
    KEY `idx_total_spent` (`total_spent`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Main Orders Table
-- Central table for all order information

CREATE TABLE IF NOT EXISTS `commerce_orders` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_number` VARCHAR(50) NOT NULL UNIQUE,
    `customer_id` BIGINT UNSIGNED DEFAULT NULL,
    `store_id` BIGINT UNSIGNED NOT NULL,
    `status` ENUM('pending', 'processing', 'completed', 'cancelled', 'refunded', 'failed', 'on_hold') NOT NULL DEFAULT 'pending',
    `payment_status` ENUM('pending', 'processing', 'completed', 'failed', 'refunded', 'partially_refunded') NOT NULL DEFAULT 'pending',
    `currency` VARCHAR(3) NOT NULL DEFAULT 'USD',
    `subtotal` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `tax_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `shipping_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `discount_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `total_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `refund_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `adjustments` LONGTEXT DEFAULT NULL COMMENT 'Serialized array of adjustments from Adjustment plugin',
    `customer_ip` VARCHAR(45) DEFAULT NULL,
    `customer_user_agent` TEXT DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `customer_notes` TEXT DEFAULT NULL,
    `admin_notes` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `completed_at` TIMESTAMP NULL DEFAULT NULL,
    `cancelled_at` TIMESTAMP NULL DEFAULT NULL,
    `refunded_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_order_number` (`order_number`),
    KEY `idx_customer_id` (`customer_id`),
    KEY `idx_store_id` (`store_id`),
    KEY `idx_status` (`status`),
    KEY `idx_payment_status` (`payment_status`),
    KEY `idx_currency` (`currency`),
    KEY `idx_total_amount` (`total_amount`),
    KEY `idx_created_at` (`created_at`),
    KEY `idx_updated_at` (`updated_at`),
    KEY `idx_completed_at` (`completed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Order Items Table
-- Individual items within an order

CREATE TABLE IF NOT EXISTS `commerce_order_item` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id` BIGINT UNSIGNED NOT NULL,
    `product_id` BIGINT UNSIGNED NOT NULL,
    `variation_id` BIGINT UNSIGNED DEFAULT NULL,
    `quantity` INT UNSIGNED NOT NULL DEFAULT 1,
    `unit_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `total_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `tax_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `discount_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `item_name` VARCHAR(255) NOT NULL,
    `item_sku` VARCHAR(100) DEFAULT NULL,
    `item_attributes` JSON DEFAULT NULL,
    `weight` DECIMAL(8,2) DEFAULT NULL,
    `dimensions_length` DECIMAL(10,2) DEFAULT NULL,
    `dimensions_width` DECIMAL(10,2) DEFAULT NULL,
    `dimensions_height` DECIMAL(10,2) DEFAULT NULL,
    `shipping_class` VARCHAR(100) DEFAULT NULL,
    `virtual` BOOLEAN NOT NULL DEFAULT FALSE,
    `downloadable` BOOLEAN NOT NULL DEFAULT FALSE,
    `status` ENUM('pending', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded') NOT NULL DEFAULT 'pending',
    `notes` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_order_item` (`order_id`, `product_id`, `variation_id`),
    KEY `idx_order_id` (`order_id`),
    KEY `idx_product_id` (`product_id`),
    KEY `idx_variation_id` (`variation_id`),
    KEY `idx_status` (`status`),
    KEY `idx_created_at` (`created_at`),
    KEY `idx_updated_at` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payment Table
-- Payment transactions and payment method information

CREATE TABLE IF NOT EXISTS `commerce_payment` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id` BIGINT UNSIGNED NOT NULL,
    `payment_method` VARCHAR(100) NOT NULL,
    `payment_gateway` VARCHAR(100) DEFAULT NULL,
    `transaction_id` VARCHAR(255) DEFAULT NULL,
    `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `currency` VARCHAR(3) NOT NULL DEFAULT 'USD',
    `status` ENUM('pending', 'processing', 'completed', 'failed', 'refunded', 'partially_refunded', 'cancelled') NOT NULL DEFAULT 'pending',
    `payment_type` ENUM('authorization', 'capture', 'refund', 'void') NOT NULL DEFAULT 'capture',
    `gateway_response` JSON DEFAULT NULL,
    `gateway_transaction_id` VARCHAR(255) DEFAULT NULL,
    `failure_reason` TEXT DEFAULT NULL,
    `processed_at` TIMESTAMP NULL DEFAULT NULL,
    `refunded_at` TIMESTAMP NULL DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_order_id` (`order_id`),
    KEY `idx_payment_method` (`payment_method`),
    KEY `idx_payment_gateway` (`payment_gateway`),
    KEY `idx_transaction_id` (`transaction_id`),
    KEY `idx_status` (`status`),
    KEY `idx_payment_type` (`payment_type`),
    KEY `idx_amount` (`amount`),
    KEY `idx_created_at` (`created_at`),
    KEY `idx_processed_at` (`processed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Order Activity Table
-- Activity log and status history for orders

CREATE TABLE IF NOT EXISTS `commerce_order_activity` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id` BIGINT UNSIGNED NOT NULL,
    `activity_type` ENUM('status_change', 'payment_update', 'shipping_update', 'note_added', 'customer_update', 'admin_action', 'system_event') NOT NULL,
    `activity_description` TEXT NOT NULL,
    `old_value` TEXT DEFAULT NULL,
    `new_value` TEXT DEFAULT NULL,
    `user_id` BIGINT UNSIGNED DEFAULT NULL,
    `customer_visible` BOOLEAN NOT NULL DEFAULT FALSE,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` TEXT DEFAULT NULL,
    `metadata` JSON DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_order_id` (`order_id`),
    KEY `idx_activity_type` (`activity_type`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_customer_visible` (`customer_visible`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Triggers for automatic order total updates
DELIMITER //

CREATE TRIGGER `before_order_insert` 
BEFORE INSERT ON `commerce_orders`
FOR EACH ROW
BEGIN
    IF NEW.order_number IS NULL OR NEW.order_number = '' THEN
        SET NEW.order_number = CONCAT('ORD', DATE_FORMAT(NOW(), '%Y%m%d'), LPAD(CONNECTION_ID(), 6, '0'));
    END IF;
END//

DELIMITER ;

CREATE TRIGGER `after_order_item_insert_update` 
AFTER INSERT ON `commerce_order_item`
FOR EACH ROW
BEGIN
    UPDATE `commerce_orders` 
    SET 
        subtotal = (SELECT COALESCE(SUM(total_price), 0) FROM `commerce_order_item` WHERE order_id = NEW.order_id),
        total_amount = subtotal + tax_amount + shipping_amount - discount_amount,
        updated_at = NOW()
    WHERE id = NEW.order_id;
END//

CREATE TRIGGER `after_order_item_update` 
AFTER UPDATE ON `commerce_order_item`
FOR EACH ROW
BEGIN
    UPDATE `commerce_orders` 
    SET 
        subtotal = (SELECT COALESCE(SUM(total_price), 0) FROM `commerce_order_item` WHERE order_id = NEW.order_id),
        total_amount = subtotal + tax_amount + shipping_amount - discount_amount,
        updated_at = NOW()
    WHERE id = NEW.order_id;
END//

CREATE TRIGGER `after_order_item_delete` 
AFTER DELETE ON `commerce_order_item`
FOR EACH ROW
BEGIN
    UPDATE `commerce_orders` 
    SET 
        subtotal = (SELECT COALESCE(SUM(total_price), 0) FROM `commerce_order_item` WHERE order_id = OLD.order_id),
        total_amount = subtotal + tax_amount + shipping_amount - discount_amount,
        updated_at = NOW()
    WHERE id = OLD.order_id;
END//

DELIMITER ;

-- Performance indexes
CREATE INDEX `idx_orders_customer_search` ON `commerce_orders` (`customer_id`, `status`, `created_at`);
CREATE INDEX `idx_orders_store_performance` ON `commerce_orders` (`store_id`, `status`, `total_amount`, `created_at`);
CREATE INDEX `idx_customers_email_search` ON `commerce_customer` (`email`, `store_id`, `customer_type`);
CREATE INDEX `idx_customers_performance` ON `commerce_customer` (`store_id`, `total_spent`, `total_orders`, `last_order_at`);
CREATE INDEX `idx_order_items_product_performance` ON `commerce_order_item` (`product_id`, `quantity`, `total_price`, `created_at`);
CREATE INDEX `idx_payment_order_status` ON `commerce_payment` (`order_id`, `status`, `payment_type`);
CREATE INDEX `idx_activity_tracking` ON `commerce_order_activity` (`order_id`, `activity_type`, `created_at`);