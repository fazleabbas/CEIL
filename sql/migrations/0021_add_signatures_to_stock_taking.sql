-- Migration: 0021_add_signatures_to_stock_taking
-- Description: Add e-signature + verifier/approver workflow to stock taking
-- Date: 2026-05-25
-- Author: System
--
-- Adds:
--   * user_permissions.can_verify (new permission key, parallel to can_approve)
--   * stock_taking.prepared_by_signature (base64 PNG)
--   * stock_taking.verified_by / verified_at / verified_by_signature
--   * stock_taking.approved_by / approved_at / approved_by_signature
--   * Foreign keys for verified_by and approved_by -> users(id) ON DELETE SET NULL
--
-- All operations are idempotent (INFORMATION_SCHEMA guards). All new columns
-- are nullable so existing rows and existing SELECT * queries are unaffected.

-- ------------------------------------------------------------------
-- 1. user_permissions.can_verify
-- ------------------------------------------------------------------
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_permissions' AND COLUMN_NAME = 'can_verify') = 0,
    'ALTER TABLE `user_permissions` ADD COLUMN `can_verify` TINYINT(1) NOT NULL DEFAULT 0 AFTER `can_approve`',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------------
-- 2. stock_taking.prepared_by_signature
-- ------------------------------------------------------------------
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_taking' AND COLUMN_NAME = 'prepared_by_signature') = 0,
    'ALTER TABLE `stock_taking` ADD COLUMN `prepared_by_signature` LONGTEXT NULL DEFAULT NULL AFTER `created_by`',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------------
-- 3. stock_taking.verified_by
-- ------------------------------------------------------------------
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_taking' AND COLUMN_NAME = 'verified_by') = 0,
    'ALTER TABLE `stock_taking` ADD COLUMN `verified_by` INT(11) NULL DEFAULT NULL',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------------
-- 4. stock_taking.verified_at
-- ------------------------------------------------------------------
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_taking' AND COLUMN_NAME = 'verified_at') = 0,
    'ALTER TABLE `stock_taking` ADD COLUMN `verified_at` TIMESTAMP NULL DEFAULT NULL',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------------
-- 5. stock_taking.verified_by_signature
-- ------------------------------------------------------------------
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_taking' AND COLUMN_NAME = 'verified_by_signature') = 0,
    'ALTER TABLE `stock_taking` ADD COLUMN `verified_by_signature` LONGTEXT NULL DEFAULT NULL',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------------
-- 6. stock_taking.approved_by
-- ------------------------------------------------------------------
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_taking' AND COLUMN_NAME = 'approved_by') = 0,
    'ALTER TABLE `stock_taking` ADD COLUMN `approved_by` INT(11) NULL DEFAULT NULL',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------------
-- 7. stock_taking.approved_at
-- ------------------------------------------------------------------
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_taking' AND COLUMN_NAME = 'approved_at') = 0,
    'ALTER TABLE `stock_taking` ADD COLUMN `approved_at` TIMESTAMP NULL DEFAULT NULL',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------------
-- 8. stock_taking.approved_by_signature
-- ------------------------------------------------------------------
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_taking' AND COLUMN_NAME = 'approved_by_signature') = 0,
    'ALTER TABLE `stock_taking` ADD COLUMN `approved_by_signature` LONGTEXT NULL DEFAULT NULL',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------------
-- 9. Foreign key: stock_taking.verified_by -> users.id
-- ------------------------------------------------------------------
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_taking'
       AND COLUMN_NAME = 'verified_by' AND REFERENCED_TABLE_NAME = 'users') = 0,
    'ALTER TABLE `stock_taking` ADD CONSTRAINT `stock_taking_verified_by_fk` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------------
-- 10. Foreign key: stock_taking.approved_by -> users.id
-- ------------------------------------------------------------------
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_taking'
       AND COLUMN_NAME = 'approved_by' AND REFERENCED_TABLE_NAME = 'users') = 0,
    'ALTER TABLE `stock_taking` ADD CONSTRAINT `stock_taking_approved_by_fk` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------------
-- 11. Helpful indexes (idempotent)
-- ------------------------------------------------------------------
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_taking' AND INDEX_NAME = 'idx_st_verified_by') = 0,
    'ALTER TABLE `stock_taking` ADD INDEX `idx_st_verified_by` (`verified_by`)',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_taking' AND INDEX_NAME = 'idx_st_approved_by') = 0,
    'ALTER TABLE `stock_taking` ADD INDEX `idx_st_approved_by` (`approved_by`)',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
