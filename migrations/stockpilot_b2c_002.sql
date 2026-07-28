ALTER TABLE promotions ADD COLUMN IF NOT EXISTS applicable_to ENUM('all','category','product') DEFAULT 'all';
ALTER TABLE promotions ADD COLUMN IF NOT EXISTS applicable_ids TEXT DEFAULT NULL;
CREATE TABLE IF NOT EXISTS loyalty_tiers (
    tier_id INT AUTO_INCREMENT PRIMARY KEY,
    store_id INT NOT NULL,
    tier_name VARCHAR(50) NOT NULL,
    min_points INT NOT NULL DEFAULT 0,
    earn_multiplier DECIMAL(3,2) DEFAULT 1.00,
    benefits TEXT,
    FOREIGN KEY (store_id) REFERENCES stores(store_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS loyalty_transactions (
    txn_id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    store_id INT NOT NULL,
    txn_type ENUM('earned','redeemed','expired','adjusted') NOT NULL,
    points INT NOT NULL,
    reference_type VARCHAR(50),
    reference_id INT,
    description VARCHAR(255),
    balance_after INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_lt_customer (customer_id),
    FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS payment_transactions (
    txn_id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    customer_id INT NOT NULL,
    store_id INT NOT NULL,
    gateway VARCHAR(30) NOT NULL DEFAULT 'upi',
    upi_transaction_id VARCHAR(255),
    amount DECIMAL(12,2) NOT NULL,
    currency VARCHAR(10) DEFAULT 'INR',
    status ENUM('initiated','success','failed','pending') NOT NULL DEFAULT 'initiated',
    response_code VARCHAR(20),
    response_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_pt_order (order_id),
    FOREIGN KEY (order_id) REFERENCES customer_orders(order_id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS product_reviews (
    review_id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    customer_id INT NOT NULL,
    store_id INT NOT NULL,
    order_id INT DEFAULT NULL,
    rating TINYINT NOT NULL,
    review_text TEXT,
    is_verified_purchase TINYINT(1) DEFAULT 0,
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_review (product_id, customer_id, order_id),
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS invoice_items (
    invoice_item_id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id BIGINT UNSIGNED NOT NULL,
    product_id INT DEFAULT NULL,
    description VARCHAR(255),
    quantity DECIMAL(10,2) DEFAULT 1.00,
    unit_price DECIMAL(12,2) DEFAULT 0.00,
    total_price DECIMAL(12,2) DEFAULT 0.00,
    FOREIGN KEY (invoice_id) REFERENCES invoices(invoice_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS tax_rates (
    tax_id INT AUTO_INCREMENT PRIMARY KEY,
    store_id INT NOT NULL,
    tax_name VARCHAR(100) NOT NULL,
    tax_type ENUM('percentage','fixed') DEFAULT 'percentage',
    rate DECIMAL(6,3) NOT NULL,
    is_default TINYINT(1) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    FOREIGN KEY (store_id) REFERENCES stores(store_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS store_settings (
    setting_id INT AUTO_INCREMENT PRIMARY KEY,
    store_id INT NOT NULL,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT,
    setting_type ENUM('string','number','boolean','json') DEFAULT 'string',
    UNIQUE KEY uq_store_setting (store_id, setting_key),
    FOREIGN KEY (store_id) REFERENCES stores(store_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE products ADD COLUMN IF NOT EXISTS category_id INT DEFAULT NULL;
