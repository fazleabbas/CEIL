-- Migration: 0026_add_rejection_to_requisition_vouchers
-- Description: Allow approvers to REJECT a pending requisition. Adds a terminal
--              'rejected' value to the status ENUM and dedicated audit columns
--              (rejected_by / rejected_at / reject_reason), mirroring the existing
--              closed_* columns. Reject reason is optional.
-- Date: 2026-05-30
-- Author: System
--
-- Idempotent: ENUM modify is safe to re-run; column adds are guarded via
-- INFORMATION_SCHEMA so existing rows and existing SELECT * queries are
-- unaffected (all new columns are nullable).

-- 1. Extend status ENUM with 'rejected' (placed before 'closed').
--    MODIFY is idempotent — re-running keeps the same definition.
ALTER TABLE `requisition_vouchers`
    MODIFY `status` enum('draft','pending_approval','approved','rejected','closed')
    NOT NULL DEFAULT 'draft';

-- 2. rejected_by (FK to users, ON DELETE SET NULL like other actor columns)
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'requisition_vouchers'
       AND COLUMN_NAME = 'rejected_by') = 0,
    'ALTER TABLE `requisition_vouchers` ADD COLUMN `rejected_by` int(11) DEFAULT NULL AFTER `approved_at`',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. rejected_at
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'requisition_vouchers'
       AND COLUMN_NAME = 'rejected_at') = 0,
    'ALTER TABLE `requisition_vouchers` ADD COLUMN `rejected_at` timestamp NULL DEFAULT NULL AFTER `rejected_by`',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. reject_reason (optional)
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'requisition_vouchers'
       AND COLUMN_NAME = 'reject_reason') = 0,
    'ALTER TABLE `requisition_vouchers` ADD COLUMN `reject_reason` text DEFAULT NULL COMMENT ''Reason for rejection (optional)'' AFTER `rejected_at`',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5. Foreign key for rejected_by (guarded — only add if absent)
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'requisition_vouchers'
       AND CONSTRAINT_NAME = 'rv_rejected_by_fk') = 0,
    'ALTER TABLE `requisition_vouchers` ADD CONSTRAINT `rv_rejected_by_fk` FOREIGN KEY (`rejected_by`) REFERENCES `users` (`id`) ON DELETE SET NULL',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
