-- =============================================================
-- StockPilot — B2C Upgrade Migration
-- Run against `inventory_management` database
-- Date: 2026-06-04
-- =============================================================

-- ---------------------------------------------------------------
-- 1. products — add B2C columns
-- ---------------------------------------------------------------
ALTER TABLE `products`
  ADD COLUMN IF NOT EXISTS `description` TEXT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `is_visible_to_customers` TINYINT(1) NOT NULL DEFAULT 1;

-- ---------------------------------------------------------------
-- 2. carts — customer shopping carts
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `carts` (
  `cart_id`     INT AUTO_INCREMENT PRIMARY KEY,
  `customer_id` INT NOT NULL,
  `store_id`    INT NOT NULL,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_cart_customer_store` (`customer_id`, `store_id`),
  CONSTRAINT `fk_carts_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`customer_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_carts_store`    FOREIGN KEY (`store_id`)    REFERENCES `stores`(`store_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- 3. cart_items
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cart_items` (
  `cart_item_id` INT AUTO_INCREMENT PRIMARY KEY,
  `cart_id`      INT NOT NULL,
  `product_id`   INT NOT NULL,
  `quantity`     INT NOT NULL DEFAULT 1,
  `added_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_cart_product` (`cart_id`, `product_id`),
  CONSTRAINT `fk_cart_items_cart`    FOREIGN KEY (`cart_id`)    REFERENCES `carts`(`cart_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cart_items_product` FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- 4. wishlists
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wishlists` (
  `wishlist_id`  INT AUTO_INCREMENT PRIMARY KEY,
  `customer_id`  INT NOT NULL,
  `product_id`   INT NOT NULL,
  `store_id`     INT NOT NULL,
  `added_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_wishlist_customer_product` (`customer_id`, `product_id`),
  CONSTRAINT `fk_wishlists_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`customer_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wishlists_product`  FOREIGN KEY (`product_id`)  REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- 5. addresses — multi-address support for customers
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `addresses` (
  `address_id`    INT AUTO_INCREMENT PRIMARY KEY,
  `customer_id`   INT NOT NULL,
  `label`         VARCHAR(50) DEFAULT 'Home',
  `full_name`     VARCHAR(255),
  `phone`         VARCHAR(20),
  `address_line1` VARCHAR(255) NOT NULL,
  `address_line2` VARCHAR(255) DEFAULT NULL,
  `city`          VARCHAR(100) NOT NULL,
  `state`         VARCHAR(100) NOT NULL,
  `postal_code`   VARCHAR(20) NOT NULL,
  `country`       VARCHAR(100) DEFAULT 'India',
  `is_default`    TINYINT(1) DEFAULT 0,
  `address_type`  ENUM('shipping','billing','both') DEFAULT 'both',
  INDEX `idx_addresses_customer` (`customer_id`),
  CONSTRAINT `fk_addresses_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`customer_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- 6. customer_orders — B2C order lifecycle
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `customer_orders` (
  `order_id`            INT AUTO_INCREMENT PRIMARY KEY,
  `order_number`        VARCHAR(50) NOT NULL UNIQUE,
  `customer_id`         INT NOT NULL,
  `store_id`            INT NOT NULL,
  `order_date`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `status`              ENUM('placed','confirmed','processing','packed','shipped','delivered','cancelled','returned')
                        NOT NULL DEFAULT 'placed',
  `subtotal`            DECIMAL(12,2) NOT NULL,
  `tax_amount`          DECIMAL(10,2) DEFAULT 0.00,
  `discount_amount`     DECIMAL(10,2) DEFAULT 0.00,
  `shipping_cost`       DECIMAL(10,2) DEFAULT 0.00,
  `total_amount`        DECIMAL(12,2) NOT NULL,
  `payment_method`      VARCHAR(50) DEFAULT NULL,
  `payment_status`      ENUM('pending','paid','failed','refunded') DEFAULT 'pending',
  `payment_reference`   VARCHAR(255) DEFAULT NULL,
  `shipping_address_id` INT DEFAULT NULL,
  `billing_address_id`  INT DEFAULT NULL,
  `coupon_code`         VARCHAR(50) DEFAULT NULL,
  `notes`               TEXT DEFAULT NULL,
  `estimated_delivery`  DATE DEFAULT NULL,
  `created_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_cust_orders_customer` (`customer_id`, `status`),
  INDEX `idx_cust_orders_store`    (`store_id`, `status`),
  CONSTRAINT `fk_cust_orders_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`customer_id`),
  CONSTRAINT `fk_cust_orders_store`    FOREIGN KEY (`store_id`)    REFERENCES `stores`(`store_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- 7. customer_order_items
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `customer_order_items` (
  `order_item_id`  INT AUTO_INCREMENT PRIMARY KEY,
  `order_id`       INT NOT NULL,
  `product_id`     INT NOT NULL,
  `product_name`   VARCHAR(255) NOT NULL,
  `quantity`       INT NOT NULL,
  `unit_price`     DECIMAL(10,2) NOT NULL,
  `discount_amount` DECIMAL(10,2) DEFAULT 0.00,
  `total_price`    DECIMAL(12,2) NOT NULL,
  INDEX `idx_cust_order_items_order` (`order_id`),
  CONSTRAINT `fk_cust_order_items_order`   FOREIGN KEY (`order_id`)   REFERENCES `customer_orders`(`order_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cust_order_items_product` FOREIGN KEY (`product_id`) REFERENCES `products`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- 8. order_status_history
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `order_status_history` (
  `history_id`  INT AUTO_INCREMENT PRIMARY KEY,
  `order_id`    INT NOT NULL,
  `old_status`  VARCHAR(30) DEFAULT NULL,
  `new_status`  VARCHAR(30) NOT NULL,
  `changed_by`  INT DEFAULT NULL,
  `notes`       TEXT DEFAULT NULL,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_status_history_order` (`order_id`),
  CONSTRAINT `fk_status_history_order` FOREIGN KEY (`order_id`) REFERENCES `customer_orders`(`order_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- 9. promotions
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `promotions` (
  `promo_id`           INT AUTO_INCREMENT PRIMARY KEY,
  `store_id`           INT NOT NULL,
  `promo_code`         VARCHAR(50) NOT NULL,
  `promo_name`         VARCHAR(255) NOT NULL,
  `description`        TEXT DEFAULT NULL,
  `discount_type`      ENUM('percentage','fixed','free_shipping') NOT NULL,
  `discount_value`     DECIMAL(10,2) NOT NULL,
  `min_order_amount`   DECIMAL(10,2) DEFAULT 0.00,
  `max_discount_amount` DECIMAL(10,2) DEFAULT NULL,
  `start_date`         DATETIME NOT NULL,
  `end_date`           DATETIME NOT NULL,
  `usage_limit`        INT DEFAULT NULL,
  `usage_count`        INT DEFAULT 0,
  `per_customer_limit` INT DEFAULT 1,
  `is_active`          TINYINT(1) DEFAULT 1,
  `created_at`         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_promo_code_store` (`promo_code`, `store_id`),
  CONSTRAINT `fk_promotions_store` FOREIGN KEY (`store_id`) REFERENCES `stores`(`store_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- 10. coupon_usage
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `coupon_usage` (
  `usage_id`         INT AUTO_INCREMENT PRIMARY KEY,
  `promo_id`         INT NOT NULL,
  `customer_id`      INT NOT NULL,
  `order_id`         INT DEFAULT NULL,
  `discount_applied` DECIMAL(10,2) NOT NULL,
  `used_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_coupon_usage_promo`    FOREIGN KEY (`promo_id`)    REFERENCES `promotions`(`promo_id`),
  CONSTRAINT `fk_coupon_usage_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
