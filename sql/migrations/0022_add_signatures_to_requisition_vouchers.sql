-- Migration: 0022_add_signatures_to_requisition_vouchers
-- Description: Add Prepared By + Approved By e-signature columns to requisition_vouchers
-- Date: 2026-05-25
-- Author: System
--
-- Both columns are nullable LONGTEXT (base64 PNG produced by signature_pad).
-- Existing rows and existing SELECT * queries are unaffected.
-- All operations idempotent via INFORMATION_SCHEMA guards.

-- ------------------------------------------------------------------
-- 1. requisition_vouchers.prepared_by_signature
-- ------------------------------------------------------------------
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'requisition_vouchers' AND COLUMN_NAME = 'prepared_by_signature') = 0,
    'ALTER TABLE `requisition_vouchers` ADD COLUMN `prepared_by_signature` LONGTEXT NULL DEFAULT NULL AFTER `created_by`',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------------
-- 2. requisition_vouchers.approved_by_signature
-- ------------------------------------------------------------------
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'requisition_vouchers' AND COLUMN_NAME = 'approved_by_signature') = 0,
    'ALTER TABLE `requisition_vouchers` ADD COLUMN `approved_by_signature` LONGTEXT NULL DEFAULT NULL AFTER `approved_at`',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
