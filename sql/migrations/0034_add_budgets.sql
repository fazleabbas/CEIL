-- Migration: 0034_add_budgets
-- Description: Add the "Budget" document — a supplier-allocation & pricing plan
--              created from an APPROVED requisition voucher. Each line allocates
--              a requisition product to a supplier (and a procurement type:
--              purchase or import) with a quantity and rate. A budget goes
--              through its own approval (draft -> pending_approval -> approved /
--              rejected); once approved, purchases/imports are raised from it
--              (grouped per supplier+type). Purchases/imports link back via a
--              nullable budget_id. Seeds the 'budget' module permission for Admin
--              and Manager plus the BUD numbering prefix.
-- Date: 2026-06-16
--
-- Additive. The budget_id columns are nullable so existing purchase/import
-- create + SELECT flows are unaffected. FK/column adds are idempotent.

-- =============================================
-- 1. Setting for Budget number prefix
-- =============================================
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `description`)
VALUES ('budget_prefix', 'BUD', 'Prefix for Budget numbering (e.g. BUD000001)');

-- =============================================
-- 2. Budgets (header)
-- =============================================
CREATE TABLE IF NOT EXISTS `budgets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `budget_number` varchar(50) NOT NULL,
  `budget_date` date NOT NULL,
  `requisition_id` int(11) NOT NULL COMMENT 'Approved requisition this budget sources',
  `location_id` int(11) NOT NULL COMMENT 'Copied from requisition destination',
  `cost_centre_id` int(11) DEFAULT NULL,
  `status` enum('draft','pending_approval','approved','rejected') NOT NULL DEFAULT 'draft',
  `remarks` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `sent_for_approval_by` int(11) DEFAULT NULL,
  `sent_for_approval_at` timestamp NULL DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `rejected_by` int(11) DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `reject_reason` text DEFAULT NULL,
  `prepared_by_signature` mediumtext DEFAULT NULL,
  `approved_by_signature` mediumtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_budget_number` (`budget_number`),
  KEY `idx_budget_requisition` (`requisition_id`),
  KEY `idx_budget_location` (`location_id`),
  KEY `idx_budget_cost_centre` (`cost_centre_id`),
  KEY `idx_budget_status` (`status`),
  KEY `idx_budget_date` (`budget_date`),
  KEY `idx_budget_created_by` (`created_by`),
  CONSTRAINT `budget_requisition_fk` FOREIGN KEY (`requisition_id`) REFERENCES `requisition_vouchers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `budget_location_fk` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`),
  CONSTRAINT `budget_cost_centre_fk` FOREIGN KEY (`cost_centre_id`) REFERENCES `cost_centres` (`id`) ON DELETE SET NULL,
  CONSTRAINT `budget_created_by_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `budget_sent_by_fk` FOREIGN KEY (`sent_for_approval_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `budget_approved_by_fk` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `budget_rejected_by_fk` FOREIGN KEY (`rejected_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- 3. Budget Details (supplier allocations)
-- =============================================
CREATE TABLE IF NOT EXISTS `budget_details` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `budget_id` int(11) NOT NULL,
  `requisition_detail_id` int(11) DEFAULT NULL,
  `product_id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `procurement_type` enum('purchase','import') NOT NULL DEFAULT 'purchase',
  `quantity` decimal(15,3) NOT NULL,
  `rate` decimal(15,2) NOT NULL DEFAULT 0.00,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_budget_alloc` (`budget_id`, `product_id`, `supplier_id`, `procurement_type`),
  KEY `idx_bd_budget` (`budget_id`),
  KEY `idx_bd_reqdetail` (`requisition_detail_id`),
  KEY `idx_bd_product` (`product_id`),
  KEY `idx_bd_supplier` (`supplier_id`),
  CONSTRAINT `bd_budget_fk` FOREIGN KEY (`budget_id`) REFERENCES `budgets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bd_reqdetail_fk` FOREIGN KEY (`requisition_detail_id`) REFERENCES `requisition_voucher_details` (`id`) ON DELETE SET NULL,
  CONSTRAINT `bd_product_fk` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `bd_supplier_fk` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- 4. Link purchases / purchase_imports back to a budget
-- =============================================
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchases' AND COLUMN_NAME = 'budget_id') = 0,
    'ALTER TABLE `purchases` ADD COLUMN `budget_id` INT(11) NULL DEFAULT NULL AFTER `requisition_id`, ADD KEY `idx_purchases_budget` (`budget_id`)',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchases' AND CONSTRAINT_NAME = 'purchases_budget_fk') = 0,
    'ALTER TABLE `purchases` ADD CONSTRAINT `purchases_budget_fk` FOREIGN KEY (`budget_id`) REFERENCES `budgets` (`id`) ON DELETE SET NULL',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_imports' AND COLUMN_NAME = 'budget_id') = 0,
    'ALTER TABLE `purchase_imports` ADD COLUMN `budget_id` INT(11) NULL DEFAULT NULL AFTER `requisition_id`, ADD KEY `idx_purchase_imports_budget` (`budget_id`)',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_imports' AND CONSTRAINT_NAME = 'purchase_imports_budget_fk') = 0,
    'ALTER TABLE `purchase_imports` ADD CONSTRAINT `purchase_imports_budget_fk` FOREIGN KEY (`budget_id`) REFERENCES `budgets` (`id`) ON DELETE SET NULL',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =============================================
-- 5. Seed 'budget' module permission for Admin and Manager
-- =============================================
INSERT IGNORE INTO `user_permissions` (`user_id`, `module_name`, `can_view`, `can_create`, `can_edit`, `can_delete`, `can_approve`)
SELECT u.id, 'budget', 1, 1, 1, 1, 1
FROM `users` u INNER JOIN `roles` r ON u.role_id = r.id WHERE r.name = 'Admin';

INSERT IGNORE INTO `user_permissions` (`user_id`, `module_name`, `can_view`, `can_create`, `can_edit`, `can_delete`, `can_approve`)
SELECT u.id, 'budget', 1, 1, 1, 1, 1
FROM `users` u INNER JOIN `roles` r ON u.role_id = r.id WHERE r.name = 'Manager';
