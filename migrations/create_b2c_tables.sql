-- ============================================================
-- StockPilot B2C Upgrade – required tables
-- Run once: mysql -u root inventory_management < create_b2c_tables.sql
-- ============================================================

-- Cart line items (carts header already exists)
CREATE TABLE IF NOT EXISTS cart_items (
    cart_item_id   INT AUTO_INCREMENT PRIMARY KEY,
    cart_id        INT          NOT NULL,
    product_id     INT          NOT NULL,
    quantity       INT          NOT NULL DEFAULT 1,
    added_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (cart_id)    REFERENCES carts(cart_id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id)   ON DELETE CASCADE,
    UNIQUE KEY uq_cart_product (cart_id, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Wishlists
CREATE TABLE IF NOT EXISTS wishlists (
    wishlist_id    INT AUTO_INCREMENT PRIMARY KEY,
    customer_id    INT          NOT NULL,
    store_id       INT          NOT NULL,
    product_id     INT          NOT NULL,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE KEY uq_wish (customer_id, store_id, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Customer addresses
CREATE TABLE IF NOT EXISTS addresses (
    address_id     INT AUTO_INCREMENT PRIMARY KEY,
    customer_id    INT          NOT NULL,
    store_id       INT          NOT NULL DEFAULT 0,
    label          VARCHAR(50)  DEFAULT 'Home',
    full_name      VARCHAR(255) NOT NULL,
    phone          VARCHAR(20)  DEFAULT NULL,
    address_line1  VARCHAR(255) NOT NULL,
    address_line2  VARCHAR(255) DEFAULT NULL,
    city           VARCHAR(100) NOT NULL,
    state          VARCHAR(100) NOT NULL,
    postal_code    VARCHAR(20)  NOT NULL,
    country        VARCHAR(100) NOT NULL DEFAULT 'India',
    is_default     TINYINT(1)   NOT NULL DEFAULT 0,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_addr_cust (customer_id, store_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Customer orders (B2C, separate from POS sales)
CREATE TABLE IF NOT EXISTS customer_orders (
    order_id           INT AUTO_INCREMENT PRIMARY KEY,
    order_number       VARCHAR(20)  NOT NULL UNIQUE,
    store_id           INT          NOT NULL,
    customer_id        INT          NOT NULL,
    shipping_address_id INT         DEFAULT NULL,
    subtotal           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    discount_amount    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    tax_amount         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_amount       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    coupon_code        VARCHAR(50)  DEFAULT NULL,
    payment_method     VARCHAR(50)  DEFAULT 'cod',
    payment_status     ENUM('pending','paid','refunded') NOT NULL DEFAULT 'pending',
    status             ENUM('placed','confirmed','processing','shipped','delivered','cancelled','returned')
                       NOT NULL DEFAULT 'placed',
    notes              TEXT         DEFAULT NULL,
    created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_co_store  (store_id),
    INDEX idx_co_cust   (customer_id),
    INDEX idx_co_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Customer order line items
CREATE TABLE IF NOT EXISTS customer_order_items (
    order_item_id  INT AUTO_INCREMENT PRIMARY KEY,
    order_id       INT          NOT NULL,
    product_id     INT          NOT NULL,
    product_name   VARCHAR(255) NOT NULL,
    sku            VARCHAR(255) DEFAULT NULL,
    quantity       INT          NOT NULL DEFAULT 1,
    unit_price     DECIMAL(10,2) NOT NULL,
    total_price    DECIMAL(12,2) NOT NULL,
    image_url      VARCHAR(500) DEFAULT NULL,
    FOREIGN KEY (order_id) REFERENCES customer_orders(order_id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Order status history
CREATE TABLE IF NOT EXISTS order_status_history (
    history_id     INT AUTO_INCREMENT PRIMARY KEY,
    order_id       INT          NOT NULL,
    status         VARCHAR(30)  NOT NULL,
    note           TEXT         DEFAULT NULL,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES customer_orders(order_id) ON DELETE CASCADE,
    INDEX idx_osh_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Promotions / coupons
CREATE TABLE IF NOT EXISTS promotions (
    promo_id           INT AUTO_INCREMENT PRIMARY KEY,
    store_id           INT          NOT NULL,
    promo_name         VARCHAR(255) NOT NULL,
    promo_code         VARCHAR(50)  NOT NULL,
    discount_type      ENUM('percentage','fixed') NOT NULL DEFAULT 'percentage',
    discount_value     DECIMAL(10,2) NOT NULL,
    max_discount_amount DECIMAL(10,2) DEFAULT NULL,
    min_order_amount   DECIMAL(10,2) DEFAULT 0.00,
    start_date         DATE         NOT NULL,
    end_date           DATE         NOT NULL,
    usage_limit        INT          DEFAULT NULL,
    per_customer_limit INT          DEFAULT NULL,
    usage_count        INT          NOT NULL DEFAULT 0,
    is_active          TINYINT(1)   NOT NULL DEFAULT 1,
    created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_promo_code (store_id, promo_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Coupon usage tracking
CREATE TABLE IF NOT EXISTS coupon_usage (
    usage_id       INT AUTO_INCREMENT PRIMARY KEY,
    promo_id       INT          NOT NULL,
    customer_id    INT          NOT NULL,
    order_id       INT          NOT NULL,
    discount_amount DECIMAL(10,2) NOT NULL,
    used_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (promo_id) REFERENCES promotions(promo_id),
    FOREIGN KEY (order_id) REFERENCES customer_orders(order_id),
    INDEX idx_cu_cust (promo_id, customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
