-- Migration: 0038_add_purchase_orders
-- Description: Add the "Purchase Order" (PO) document under Transactions.
--              A PO is a commitment document: it NEVER touches stock
--              (stock_batches) or GL (transactions) — only the Purchase(s)
--              created from it do. Two entry paths:
--                1. Direct PO: draft -> pending_approval -> approved/rejected
--                   (approve-with-edit like requisition vouchers) -> converted
--                   to Purchase(s), auto-closed when fully received.
--                2. Budget PO: raised from an APPROVED budget (per supplier),
--                   saved directly as 'approved' (budget approval covers it),
--                   links back via budget_id.
--              Partial receiving: one PO -> many purchases; per-line
--              purchased_quantity accrues; purchases link back via a nullable
--              purchase_order_id (NOT the existing free-text purchases.po_number,
--              which is an external supplier PO reference and stays as-is).
--              Seeds the 'purchase_orders' module permission for Admin and
--              Manager plus the PO numbering prefix.
-- Date: 2026-07-15
--
-- Additive. purchases.purchase_order_id is nullable so existing purchase
-- create + SELECT flows are unaffected. FK/column adds are idempotent.

-- =============================================
-- 1. Setting for PO number prefix
-- =============================================
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `description`)
VALUES ('po_prefix', 'PO', 'Prefix for Purchase Order numbering (e.g. PO000001)');

-- =============================================
-- 2. Purchase Orders (header)
-- =============================================
CREATE TABLE IF NOT EXISTS `purchase_orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `po_number` varchar(50) NOT NULL,
  `po_date` date NOT NULL,
  `expected_date` date DEFAULT NULL COMMENT 'Expected delivery date',
  `supplier_id` int(11) NOT NULL,
  `location_id` int(11) NOT NULL COMMENT 'Delivery location',
  `cost_centre_id` int(11) DEFAULT NULL,
  `currency_id` int(11) DEFAULT NULL,
  `exchange_rate_to_base` decimal(18,8) NOT NULL DEFAULT 1.00000000,
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Goods total (sum of line amounts)',
  `vat_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `status` enum('draft','pending_approval','approved','rejected','closed','cancelled') NOT NULL DEFAULT 'draft',
  `budget_id` int(11) DEFAULT NULL COMMENT 'Set when raised from an approved budget (PO skips approval)',
  `remarks` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `sent_for_approval_by` int(11) DEFAULT NULL,
  `sent_for_approval_at` timestamp NULL DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `approval_remarks` text DEFAULT NULL,
  `rejected_by` int(11) DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `reject_reason` text DEFAULT NULL,
  `closed_by` int(11) DEFAULT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `close_reason` text DEFAULT NULL COMMENT 'Reason for manual close/cancel',
  `prepared_by_signature` mediumtext DEFAULT NULL,
  `approved_by_signature` mediumtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_po_number` (`po_number`),
  KEY `idx_po_supplier` (`supplier_id`),
  KEY `idx_po_location` (`location_id`),
  KEY `idx_po_cost_centre` (`cost_centre_id`),
  KEY `idx_po_status` (`status`),
  KEY `idx_po_date` (`po_date`),
  KEY `idx_po_budget` (`budget_id`),
  KEY `idx_po_created_by` (`created_by`),
  CONSTRAINT `po_supplier_fk` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  CONSTRAINT `po_location_fk` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`),
  CONSTRAINT `po_cost_centre_fk` FOREIGN KEY (`cost_centre_id`) REFERENCES `cost_centres` (`id`) ON DELETE SET NULL,
  CONSTRAINT `po_currency_fk` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`),
  CONSTRAINT `po_budget_fk` FOREIGN KEY (`budget_id`) REFERENCES `budgets` (`id`) ON DELETE SET NULL,
  CONSTRAINT `po_created_by_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `po_sent_by_fk` FOREIGN KEY (`sent_for_approval_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `po_approved_by_fk` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `po_rejected_by_fk` FOREIGN KEY (`rejected_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `po_closed_by_fk` FOREIGN KEY (`closed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- 3. Purchase Order Details (line items)
-- =============================================
CREATE TABLE IF NOT EXISTS `purchase_order_details` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `purchase_order_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` decimal(15,3) NOT NULL COMMENT 'Ordered quantity as entered by creator',
  `rate_per_unit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `approved_quantity` decimal(15,3) DEFAULT NULL COMMENT 'Approver override (approve-with-edit); NULL = as requested',
  `approved_rate` decimal(15,2) DEFAULT NULL COMMENT 'Approver override; NULL = as requested',
  `purchased_quantity` decimal(15,3) NOT NULL DEFAULT 0.000 COMMENT 'Cumulative qty converted to purchases so far',
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pod_po` (`purchase_order_id`),
  KEY `idx_pod_product` (`product_id`),
  CONSTRAINT `pod_po_fk` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pod_product_fk` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- 4. Link purchases back to a purchase order
--    (idempotent: guarded via INFORMATION_SCHEMA)
-- =============================================
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchases' AND COLUMN_NAME = 'purchase_order_id') = 0,
    'ALTER TABLE `purchases` ADD COLUMN `purchase_order_id` INT(11) NULL DEFAULT NULL AFTER `budget_id`, ADD KEY `idx_purchases_po` (`purchase_order_id`)',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchases' AND CONSTRAINT_NAME = 'purchases_po_fk') = 0,
    'ALTER TABLE `purchases` ADD CONSTRAINT `purchases_po_fk` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE SET NULL',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =============================================
-- 5. Seed 'purchase_orders' module permission for Admin and Manager
-- =============================================
INSERT IGNORE INTO `user_permissions` (`user_id`, `module_name`, `can_view`, `can_create`, `can_edit`, `can_delete`, `can_approve`)
SELECT u.id, 'purchase_orders', 1, 1, 1, 1, 1
FROM `users` u INNER JOIN `roles` r ON u.role_id = r.id WHERE r.name = 'Admin';

INSERT IGNORE INTO `user_permissions` (`user_id`, `module_name`, `can_view`, `can_create`, `can_edit`, `can_delete`, `can_approve`)
SELECT u.id, 'purchase_orders', 1, 1, 1, 1, 1
FROM `users` u INNER JOIN `roles` r ON u.role_id = r.id WHERE r.name = 'Manager';
