-- Migration: 0027_add_approval_remarks_to_requisition_details
-- Description: Let approvers attach a per-line remark at approval time WITHOUT
--              touching the requester's original `remarks` (which is shown on the
--              delivery note and view). Stored in a new, separate nullable column.
-- Date: 2026-05-30
-- Author: System
--
-- Idempotent via INFORMATION_SCHEMA guard. Column is nullable so existing rows
-- and existing SELECT * queries are completely unaffected.

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'requisition_voucher_details'
       AND COLUMN_NAME = 'approval_remarks') = 0,
    'ALTER TABLE `requisition_voucher_details` ADD COLUMN `approval_remarks` TEXT NULL DEFAULT NULL COMMENT ''Approver per-line remark (set at approval)'' AFTER `approved_quantity`',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
