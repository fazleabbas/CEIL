-- Migration: 0020_add_signatures_to_stock_transfers
-- Description: Add e-signature columns (prepared_by, dispatched_by, received_by) to stock_transfers
-- Date: 2026-05-25
-- Author: System
--
-- All columns are nullable LONGTEXT (base64 PNG produced by signature_pad).
-- Existing rows are unaffected; new feature is purely additive.

-- prepared_by_signature
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_transfers' AND COLUMN_NAME = 'prepared_by_signature') = 0,
    'ALTER TABLE `stock_transfers` ADD COLUMN `prepared_by_signature` LONGTEXT NULL DEFAULT NULL AFTER `created_by`',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- dispatched_by_signature
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_transfers' AND COLUMN_NAME = 'dispatched_by_signature') = 0,
    'ALTER TABLE `stock_transfers` ADD COLUMN `dispatched_by_signature` LONGTEXT NULL DEFAULT NULL AFTER `dispatched_at`',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- received_by_signature
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_transfers' AND COLUMN_NAME = 'received_by_signature') = 0,
    'ALTER TABLE `stock_transfers` ADD COLUMN `received_by_signature` LONGTEXT NULL DEFAULT NULL AFTER `received_at`',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
