-- Migration: 0003_add_location_to_payments_receipts
-- Description: Add location_id column to payments and receipts tables
-- Date: 2026-01-29

-- Add location_id to payments table (if column doesn't exist)
-- Using a procedure to handle "column already exists" gracefully

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'payments'
     AND COLUMN_NAME = 'location_id') = 0,
    'ALTER TABLE `payments` ADD COLUMN `location_id` INT(11) DEFAULT NULL',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index for payments location_id (if not exists)
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'payments'
     AND INDEX_NAME = 'idx_payments_location') = 0,
    'ALTER TABLE `payments` ADD INDEX `idx_payments_location` (`location_id`)',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add location_id to receipts table (if column doesn't exist)
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'receipts'
     AND COLUMN_NAME = 'location_id') = 0,
    'ALTER TABLE `receipts` ADD COLUMN `location_id` INT(11) DEFAULT NULL',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index for receipts location_id (if not exists)
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'receipts'
     AND INDEX_NAME = 'idx_receipts_location') = 0,
    'ALTER TABLE `receipts` ADD INDEX `idx_receipts_location` (`location_id`)',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
