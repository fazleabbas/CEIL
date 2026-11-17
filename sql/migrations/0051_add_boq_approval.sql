-- Migration: 0051_add_boq_approval
-- Description: Give the Bill of Quantities an approval workflow, the same
--              shape as the BOM workflow from migration 0049.
-- Date: 2026-11-09
--
-- Draft -> Pending Approval -> Approved / Rejected. Approved or Rejected can be
-- revised back to Draft, and a revised BOQ must be approved again. Approvers are
-- users holding can_approve on the 'boq' module, the checkbox Masters -> Users
-- already shows. No approve-with-edit: breakdowns must sum to each line total.
--
-- EXISTING BOQs BECOME APPROVED. The status column is ADDED with default
-- 'approved', so every existing row lands there in the same statement, and the
-- default is then switched to 'draft' for new rows. Re-running is harmless: the
-- ADD is skipped once the column exists and the default change is a no-op.
--
-- approved_snapshot holds the last approved quantity per product as JSON,
-- written when an APPROVED BOQ is revised and cleared when the revision is
-- approved. While it is set, BOM balances read it instead of the unapproved
-- figures in boq_details. NULL means boq_details is the approved truth.
--
-- NOTE: keep statement separators out of these comments. executeMigration()
-- in api/run-migrations.php splits the file on the separator character BEFORE
-- it strips comments.

-- ---------------------------------------------------------------- status
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boqs' AND COLUMN_NAME = 'status') = 0,
    'ALTER TABLE `boqs` ADD COLUMN `status` ENUM(''draft'',''pending_approval'',''approved'',''rejected'') NOT NULL DEFAULT ''approved'' AFTER `remarks`',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE `boqs` MODIFY COLUMN `status` ENUM('draft','pending_approval','approved','rejected') NOT NULL DEFAULT 'draft';

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boqs' AND INDEX_NAME = 'idx_boq_status') = 0,
    'ALTER TABLE `boqs` ADD KEY `idx_boq_status` (`status`)',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------- send / approve / reject audit columns
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boqs' AND COLUMN_NAME = 'sent_for_approval_by') = 0,
    'ALTER TABLE `boqs` ADD COLUMN `sent_for_approval_by` INT(11) NULL DEFAULT NULL AFTER `status`, ADD COLUMN `sent_for_approval_at` TIMESTAMP NULL DEFAULT NULL AFTER `sent_for_approval_by`',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boqs' AND COLUMN_NAME = 'approved_by') = 0,
    'ALTER TABLE `boqs` ADD COLUMN `approved_by` INT(11) NULL DEFAULT NULL AFTER `sent_for_approval_at`, ADD COLUMN `approved_at` TIMESTAMP NULL DEFAULT NULL AFTER `approved_by`, ADD COLUMN `approved_by_signature` MEDIUMTEXT NULL DEFAULT NULL AFTER `approved_at`, ADD COLUMN `approve_remark` TEXT NULL DEFAULT NULL AFTER `approved_by_signature`',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boqs' AND COLUMN_NAME = 'rejected_by') = 0,
    'ALTER TABLE `boqs` ADD COLUMN `rejected_by` INT(11) NULL DEFAULT NULL AFTER `approve_remark`, ADD COLUMN `rejected_at` TIMESTAMP NULL DEFAULT NULL AFTER `rejected_by`, ADD COLUMN `reject_reason` TEXT NULL DEFAULT NULL AFTER `rejected_at`',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------- last approved quantities
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boqs' AND COLUMN_NAME = 'approved_snapshot') = 0,
    'ALTER TABLE `boqs` ADD COLUMN `approved_snapshot` MEDIUMTEXT NULL DEFAULT NULL COMMENT "Last approved qty per product while a revision is open" AFTER `reject_reason`',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------- foreign keys
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boqs' AND CONSTRAINT_NAME = 'boq_approved_by_fk') = 0,
    'ALTER TABLE `boqs` ADD CONSTRAINT `boq_approved_by_fk` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boqs' AND CONSTRAINT_NAME = 'boq_rejected_by_fk') = 0,
    'ALTER TABLE `boqs` ADD CONSTRAINT `boq_rejected_by_fk` FOREIGN KEY (`rejected_by`) REFERENCES `users` (`id`) ON DELETE SET NULL',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
