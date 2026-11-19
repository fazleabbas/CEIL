-- Migration: 0054_add_supplier_blacklist
-- Description: Let a supplier be blacklisted so it cannot be used on a budget.
-- Date: 2026-11-19
--
-- suppliers.is_blacklisted is separate from is_active on purpose. An inactive
-- supplier disappears from the purchase, PO and payment screens, so what is
-- still owed to it could not be paid. A blacklisted supplier only stops being
-- offered on the budget form, and a budget holding one cannot be saved, sent
-- for approval or approved. Budgets already approved are left alone.
--
-- Every existing supplier gets is_blacklisted = 0, so nothing changes until a
-- user ticks the box in Masters, Suppliers.
--
-- NOTE: keep statement separators out of these comments and strings.

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'suppliers' AND COLUMN_NAME = 'is_blacklisted') = 0,
    'ALTER TABLE `suppliers` ADD COLUMN `is_blacklisted` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_active`',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'suppliers' AND COLUMN_NAME = 'blacklist_reason') = 0,
    'ALTER TABLE `suppliers` ADD COLUMN `blacklist_reason` TEXT NULL DEFAULT NULL AFTER `is_blacklisted`',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'suppliers' AND COLUMN_NAME = 'blacklisted_at') = 0,
    'ALTER TABLE `suppliers` ADD COLUMN `blacklisted_at` DATETIME NULL DEFAULT NULL AFTER `blacklist_reason`',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'suppliers' AND COLUMN_NAME = 'blacklisted_by') = 0,
    'ALTER TABLE `suppliers` ADD COLUMN `blacklisted_by` INT(11) NULL DEFAULT NULL AFTER `blacklisted_at`',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
