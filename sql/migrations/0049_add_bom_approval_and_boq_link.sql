-- Migration: 0049_add_bom_approval_and_boq_link
-- Description: Give the Bill of Materials an approval workflow, and let a BOM
--              be raised against a Bill of Quantities so each BOM line can show
--              the BOQ quantity still available for it.
-- Date: 2026-10-23
--
-- APPROVAL
-- Draft -> Pending Approval -> Approved / Rejected. Rejected or Approved can be
-- revised back to Draft (an approved BOM then needs approving again). Only an
-- APPROVED BOM is offered in the requisition voucher BOM dropdowns. Approvers
-- are users holding can_approve on the 'bom' module, the same checkbox
-- Masters -> Users already shows. No approve-with-edit: a BOM line carries
-- breakdowns that must sum to its total, so the approver approves or rejects.
--
-- EXISTING BOMs BECOME APPROVED. The status column is ADDED with default
-- 'approved', so every existing row lands there in the same statement, and the
-- default is then switched to 'draft' for new rows. Existing BOMs are already
-- linked to requisitions, and making them drafts would drop them out of the
-- requisition dropdown overnight. Re-running is harmless: the ADD is skipped
-- once the column exists, and the default change is a no-op.
--
-- BOQ LINK
-- boms.boq_id is optional (existing BOMs have none). ON DELETE SET NULL, so
-- deleting a BOQ unlinks its BOMs rather than deleting them.
-- bom_details.boq_excess_reason holds the written reason required when a
-- product on the BOM goes beyond the BOQ balance left for it. It is stored on
-- every row of that product, the same way budget_details.remarks stores the
-- over-requisition reason.
--
-- NOTE: keep statement separators out of these comments. executeMigration()
-- in api/run-migrations.php splits the file on the separator character BEFORE
-- it strips comments.

-- ---------------------------------------------------------------- status
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boms' AND COLUMN_NAME = 'status') = 0,
    'ALTER TABLE `boms` ADD COLUMN `status` ENUM(''draft'',''pending_approval'',''approved'',''rejected'') NOT NULL DEFAULT ''approved'' AFTER `remarks`',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE `boms` MODIFY COLUMN `status` ENUM('draft','pending_approval','approved','rejected') NOT NULL DEFAULT 'draft';

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boms' AND INDEX_NAME = 'idx_bom_status') = 0,
    'ALTER TABLE `boms` ADD KEY `idx_bom_status` (`status`)',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------- send / approve / reject audit columns
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boms' AND COLUMN_NAME = 'sent_for_approval_by') = 0,
    'ALTER TABLE `boms` ADD COLUMN `sent_for_approval_by` INT(11) NULL DEFAULT NULL AFTER `status`, ADD COLUMN `sent_for_approval_at` TIMESTAMP NULL DEFAULT NULL AFTER `sent_for_approval_by`',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boms' AND COLUMN_NAME = 'approved_by') = 0,
    'ALTER TABLE `boms` ADD COLUMN `approved_by` INT(11) NULL DEFAULT NULL AFTER `sent_for_approval_at`, ADD COLUMN `approved_at` TIMESTAMP NULL DEFAULT NULL AFTER `approved_by`, ADD COLUMN `approved_by_signature` MEDIUMTEXT NULL DEFAULT NULL AFTER `approved_at`, ADD COLUMN `approve_remark` TEXT NULL DEFAULT NULL AFTER `approved_by_signature`',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boms' AND COLUMN_NAME = 'rejected_by') = 0,
    'ALTER TABLE `boms` ADD COLUMN `rejected_by` INT(11) NULL DEFAULT NULL AFTER `approve_remark`, ADD COLUMN `rejected_at` TIMESTAMP NULL DEFAULT NULL AFTER `rejected_by`, ADD COLUMN `reject_reason` TEXT NULL DEFAULT NULL AFTER `rejected_at`',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boms' AND CONSTRAINT_NAME = 'bom_approved_by_fk') = 0,
    'ALTER TABLE `boms` ADD CONSTRAINT `bom_approved_by_fk` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boms' AND CONSTRAINT_NAME = 'bom_rejected_by_fk') = 0,
    'ALTER TABLE `boms` ADD CONSTRAINT `bom_rejected_by_fk` FOREIGN KEY (`rejected_by`) REFERENCES `users` (`id`) ON DELETE SET NULL',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------- BOQ link
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boms' AND COLUMN_NAME = 'boq_id') = 0,
    'ALTER TABLE `boms` ADD COLUMN `boq_id` INT(11) NULL DEFAULT NULL COMMENT "Bill of Quantities this BOM is raised against" AFTER `cost_centre_id`',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boms' AND INDEX_NAME = 'idx_bom_boq') = 0,
    'ALTER TABLE `boms` ADD KEY `idx_bom_boq` (`boq_id`)',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boms' AND CONSTRAINT_NAME = 'bom_boq_fk') = 0,
    'ALTER TABLE `boms` ADD CONSTRAINT `bom_boq_fk` FOREIGN KEY (`boq_id`) REFERENCES `boqs` (`id`) ON DELETE SET NULL',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bom_details' AND COLUMN_NAME = 'boq_excess_reason') = 0,
    'ALTER TABLE `bom_details` ADD COLUMN `boq_excess_reason` TEXT NULL DEFAULT NULL COMMENT "Why this product goes beyond the BOQ balance" AFTER `remarks`',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
