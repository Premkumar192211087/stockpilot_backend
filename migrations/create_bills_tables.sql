-- =====================================================
-- StockPilot Database Migration v2.0
-- PO & Bills Automation + Schema Improvements
-- =====================================================
-- Run this against the `inventory_management` database
-- Date: 2026-06-04
-- =====================================================

-- 1. Create bills table (vendor bills, separate from customer invoices)
CREATE TABLE IF NOT EXISTS `bills` (
  `bill_id` INT AUTO_INCREMENT PRIMARY KEY,
  `store_id` INT NOT NULL,
  `bill_number` VARCHAR(60) NOT NULL,
  `supplier_id` INT DEFAULT NULL,
  `po_id` INT DEFAULT NULL,
  `bill_date` DATE NOT NULL,
  `due_date` DATE DEFAULT NULL,
  `subtotal` DECIMAL(12,2) DEFAULT 0.00,
  `tax` DECIMAL(12,2) DEFAULT 0.00,
  `discount` DECIMAL(12,2) DEFAULT 0.00,
  `total` DECIMAL(12,2) DEFAULT 0.00,
  `amount_paid` DECIMAL(12,2) DEFAULT 0.00,
  `status` ENUM('draft','pending','partial','paid','overdue','cancelled') DEFAULT 'pending',
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_bills_store` (`store_id`),
  INDEX `idx_bills_supplier` (`supplier_id`),
  INDEX `idx_bills_po` (`po_id`),
  INDEX `idx_bills_status` (`status`),
  INDEX `idx_bills_due_date` (`due_date`),
  CONSTRAINT `fk_bills_store` FOREIGN KEY (`store_id`) REFERENCES `stores`(`store_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bills_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`(`supplier_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_bills_po` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders`(`po_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Create bill_items table
CREATE TABLE IF NOT EXISTS `bill_items` (
  `bill_item_id` INT AUTO_INCREMENT PRIMARY KEY,
  `bill_id` INT NOT NULL,
  `product_id` INT DEFAULT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `quantity` DECIMAL(10,2) DEFAULT 1.00,
  `unit_price` DECIMAL(12,2) DEFAULT 0.00,
  `total_price` DECIMAL(12,2) DEFAULT 0.00,
  INDEX `idx_bill_items_bill` (`bill_id`),
  INDEX `idx_bill_items_product` (`product_id`),
  CONSTRAINT `fk_bill_items_bill` FOREIGN KEY (`bill_id`) REFERENCES `bills`(`bill_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bill_items_product` FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Add payment_days column to suppliers for auto due-date calculation
-- (The payment_terms column exists as VARCHAR but we need a numeric column for calculations)
ALTER TABLE `suppliers` ADD COLUMN IF NOT EXISTS `payment_days` INT DEFAULT 30
  COMMENT 'Number of days for payment due date calculation';

-- 4. Add bill_id column to payments for bill-payment linking
ALTER TABLE `payments` ADD COLUMN IF NOT EXISTS `bill_id` INT DEFAULT NULL
  COMMENT 'Link to vendor bill for payment tracking';

-- 5. Add index on payments.bill_id for faster lookups
-- (Only add if column was just created - safe to run multiple times)
-- ALTER TABLE `payments` ADD INDEX `idx_payments_bill` (`bill_id`);

-- 6. Seed some sample bill data for store_id=1
INSERT INTO `bills` (`store_id`, `bill_number`, `supplier_id`, `po_id`, `bill_date`, `due_date`, `subtotal`, `total`, `amount_paid`, `status`) VALUES
(1, 'BILL-20250502-001', 101, 101, '2025-05-02', '2025-06-01', 2750.00, 2750.00, 2750.00, 'paid'),
(1, 'BILL-20250504-001', 102, 102, '2025-05-04', '2025-06-03', 1800.00, 1800.00, 1800.00, 'paid'),
(1, 'BILL-20250505-001', 103, 103, '2025-05-05', '2025-06-04', 1400.00, 1400.00, 1400.00, 'paid');

-- 7. Seed bill items matching existing PO items
INSERT INTO `bill_items` (`bill_id`, `product_id`, `quantity`, `unit_price`, `total_price`) VALUES
(1, 101, 50, 35.00, 1750.00),
(1, 102, 50, 20.00, 1000.00),
(2, 103, 60, 10.00, 600.00),
(2, 104, 30, 40.00, 1200.00),
(3, 105, 100, 7.00, 700.00);

-- =====================================================
-- Schema Analysis & Decision Log
-- =====================================================
-- 
-- DECISION 1: bills vs invoices
-- ✅ Created separate `bills` table. Invoices are customer-facing (sales),
--    bills are vendor-facing (purchases). They share similar structures but
--    serve different workflows and have different FK relationships.
--
-- DECISION 2: payment_days on suppliers
-- ✅ Added `payment_days` INT column. The existing `payment_terms` VARCHAR
--    stores human-readable text ("Net 30", "Net 60"). payment_days provides
--    the numeric value for auto-calculating bill due dates.
--    Default: 30 days (matches "Net 30").
--
-- DECISION 3: bill_id on payments
-- ✅ Added `bill_id` to payments table. This allows linking vendor payments
--    to specific bills, enabling auto-update of bill status (partial/paid)
--    when payments are recorded. Existing payments data is unaffected (NULL).
--
-- DECISION 4: No changes to purchase_orders/purchase_order_items schema
-- ✅ The existing PO schema is fine. The inventory update bug was in the PHP
--    code (receive_purchase_order.php), not the schema. quantity_received
--    tracking was already built into purchase_order_items.
--
-- DECISION 5: Stock movements on receive
-- ⚠️ TODO (Phase 2): The receive_purchase_order.php should also INSERT into
--    stock_movements for proper audit trail. Currently it updates products.quantity
--    directly. This is functional but bypasses the movement log.
--
-- =====================================================
