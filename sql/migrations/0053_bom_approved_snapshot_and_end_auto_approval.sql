-- Migration: 0053_bom_approved_snapshot_and_end_auto_approval
-- Description: Keep a copy of a BOM's approved quantities while a revision is
--              open, and send the BOMs that migration 0049 approved by itself
--              to a real approver.
-- Date: 2026-11-18
--
-- APPROVED SNAPSHOT
-- boms.approved_snapshot holds the last approved quantity per product as JSON
-- (product_id to quantity). It is written when an APPROVED BOM is revised and
-- cleared only when the BOM is approved again. While it is set, the BOM issue
-- summary and the requisition BOM balance read it instead of bom_details, so
-- quantities nobody approved never reach them. Same design as
-- boqs.approved_snapshot (0051).
--
-- END OF AUTO APPROVAL
-- 0049 added boms.status with default 'approved', so every BOM that existed
-- then became approved with no approver and no approval date ("Auto Approved"
-- on screen). Those BOMs go to pending_approval, so an approver signs each one
-- off. A BOM approved by a person always has approved_at set and is never
-- touched. A re-run matches nothing, because the rows are no longer approved.
-- Requisitions already linked to these BOMs keep their link (bomLinkError()).
-- updated_at is assigned to itself so the correction does not make every BOM
-- look edited today.
--
-- NOTE: keep statement separators out of these comments and strings.
-- executeMigration() in api/run-migrations.php splits the file on that
-- character BEFORE it strips comments.

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boms' AND COLUMN_NAME = 'approved_snapshot') = 0,
    'ALTER TABLE `boms` ADD COLUMN `approved_snapshot` MEDIUMTEXT NULL DEFAULT NULL COMMENT "Last approved qty per product while a revision is open" AFTER `reject_reason`',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE `boms`
SET `status` = 'pending_approval',
    `updated_at` = `updated_at`
WHERE `status` = 'approved'
  AND `approved_at` IS NULL
  AND `approved_by` IS NULL;
