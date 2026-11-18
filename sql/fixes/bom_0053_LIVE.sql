-- LIVE version of migration 0053 (BOM approved snapshot + end of auto approval).
-- Plain statements for phpMyAdmin or the mysql CLI.
-- Re-run: the UPDATE matches nothing, and the ALTER fails with
-- #1060 Duplicate column name, which means it is already applied.
SELECT DATABASE();

-- BOMs that migration 0049 approved by itself (no approver, no approval date)
SELECT id, bom_number, status FROM boms
WHERE status = 'approved' AND approved_at IS NULL AND approved_by IS NULL;

UPDATE boms
SET status = 'pending_approval', updated_at = updated_at
WHERE status = 'approved' AND approved_at IS NULL AND approved_by IS NULL;

SELECT id, bom_number, status, approved_at FROM boms ORDER BY id;

ALTER TABLE boms ADD COLUMN approved_snapshot MEDIUMTEXT NULL DEFAULT NULL
    COMMENT 'Last approved qty per product while a revision is open' AFTER reject_reason;
