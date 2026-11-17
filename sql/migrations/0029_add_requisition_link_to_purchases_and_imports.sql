-- Migration: 0029_add_requisition_link_to_purchases_and_imports
-- Description: Link a purchase / purchase import back to the requisition voucher
--              it was created from. Enables "Create Purchase/Import" from the
--              requisition view (items prefilled) and lets the requisition show
--              what procurement doc was raised against it.
-- Date: 2026-06-01
-- Author: System
--
-- Both columns are nullable so every existing create/SELECT * flow is unaffected
-- (they simply stay NULL). FK uses ON DELETE SET NULL so deleting a requisition
-- never blocks/deletes a purchase. Idempotent via INFORMATION_SCHEMA guards.

-- 1. purchases.requisition_id ------------------------------------------------
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'purchases'
       AND COLUMN_NAME = 'requisition_id') = 0,
    'ALTER TABLE `purchases` ADD COLUMN `requisition_id` INT(11) NULL DEFAULT NULL AFTER `cost_centre_id`, ADD KEY `idx_purchases_requisition` (`requisition_id`)',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'purchases'
       AND CONSTRAINT_NAME = 'purchases_requisition_fk') = 0,
    'ALTER TABLE `purchases` ADD CONSTRAINT `purchases_requisition_fk` FOREIGN KEY (`requisition_id`) REFERENCES `requisition_vouchers` (`id`) ON DELETE SET NULL',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. purchase_imports.requisition_id -----------------------------------------
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'purchase_imports'
       AND COLUMN_NAME = 'requisition_id') = 0,
    'ALTER TABLE `purchase_imports` ADD COLUMN `requisition_id` INT(11) NULL DEFAULT NULL AFTER `cost_centre_id`, ADD KEY `idx_purchase_imports_requisition` (`requisition_id`)',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'purchase_imports'
       AND CONSTRAINT_NAME = 'purchase_imports_requisition_fk') = 0,
    'ALTER TABLE `purchase_imports` ADD CONSTRAINT `purchase_imports_requisition_fk` FOREIGN KEY (`requisition_id`) REFERENCES `requisition_vouchers` (`id`) ON DELETE SET NULL',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
