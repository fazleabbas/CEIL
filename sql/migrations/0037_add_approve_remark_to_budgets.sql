-- Migration: 0037_add_approve_remark_to_budgets
-- Description: Let the approver attach an optional remark when approving a budget
--              (mirrors reject_reason on the reject path). Purely additive.
-- Date: 2026-07-06
-- Author: System
--
-- Idempotent: the column add is guarded via INFORMATION_SCHEMA. The new column
-- is nullable so existing rows and existing SELECT * queries are unaffected.

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'budgets'
       AND COLUMN_NAME = 'approve_remark') = 0,
    'ALTER TABLE `budgets` ADD COLUMN `approve_remark` text DEFAULT NULL COMMENT ''Optional remark added by the approver'' AFTER `approved_by_signature`',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
