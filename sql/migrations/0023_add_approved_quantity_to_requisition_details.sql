-- Migration: 0023_add_approved_quantity_to_requisition_details
-- Description: Add approved_quantity column so approvers can reduce per-line
--              quantities at approval time. Downstream dispatch caps will read
--              COALESCE(approved_quantity, requested_quantity) so legacy rows
--              with NULL behave exactly as before.
-- Date: 2026-05-25
-- Author: System
--
-- Idempotent via INFORMATION_SCHEMA guard. Column is nullable so existing
-- rows are unaffected and existing SELECT * queries continue to work.

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'requisition_voucher_details'
       AND COLUMN_NAME = 'approved_quantity') = 0,
    'ALTER TABLE `requisition_voucher_details` ADD COLUMN `approved_quantity` DECIMAL(15,3) NULL DEFAULT NULL AFTER `requested_quantity`',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
