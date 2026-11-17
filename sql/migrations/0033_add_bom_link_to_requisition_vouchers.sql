-- Migration: 0033_add_bom_link_to_requisition_vouchers
-- Description: Optionally link a requisition voucher to a Bill of Materials (BOM)
--              so the RV create/approve screens can show, per product line, the
--              BOM quantity, how much has already been transferred against that
--              BOM, and the remaining balance. Additive and nullable — existing
--              requisitions and queries are unaffected.
-- Date: 2026-06-14
--
-- Idempotent via INFORMATION_SCHEMA guards (column + FK added only if absent).

-- 1. Add nullable bom_id column
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'requisition_vouchers'
       AND COLUMN_NAME = 'bom_id') = 0,
    'ALTER TABLE `requisition_vouchers` ADD COLUMN `bom_id` INT(11) NULL DEFAULT NULL AFTER `cost_centre_id`, ADD KEY `idx_rv_bom` (`bom_id`)',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Add the foreign key (only if it does not already exist)
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'requisition_vouchers'
       AND CONSTRAINT_NAME = 'rv_bom_fk') = 0,
    'ALTER TABLE `requisition_vouchers` ADD CONSTRAINT `rv_bom_fk` FOREIGN KEY (`bom_id`) REFERENCES `boms` (`id`) ON DELETE SET NULL',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
