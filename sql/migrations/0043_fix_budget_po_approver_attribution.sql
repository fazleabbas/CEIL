-- Migration: 0043_fix_budget_po_approver_attribution
-- Description: Re-attribute budget-raised purchase orders to the person who
--              actually approved the BUDGET, instead of the person who raised
--              the PO.
-- Date: 2026-08-09
--
-- THE BUG (data only — no schema change)
-- A PO raised from an approved budget is born in 'approved' status, because the
-- budget's approval covers the spend. That part is by design. But
-- modules/transactions/purchase_order.php stamped the CREATOR into approved_by
-- and their signature into approved_by_signature, so the document asserted that
-- the creator approved it — even though raising a budget PO requires no
-- `approve` permission and those users typically have none. On the tenant this
-- was found on, 8 POs named two users with can_approve = 0 as their own
-- approvers, each with approved_at identical to created_at to the second.
--
-- The code is fixed to credit budgets.approved_by going forward. This migration
-- corrects the rows already written.
--
-- SAFETY
-- - Only touches rows that are demonstrably self-stamped: budget_id IS NOT NULL
--   AND approved_by = created_by AND approved_at IS NOT NULL.
-- - Skips rows where the creator genuinely IS the budget's approver
--   (b.approved_by <> po.created_by) — those were already correct.
-- - Skips budgets with no approver, so a NULL can never overwrite a real name.
-- - Idempotent: after it runs, approved_by no longer equals created_by on the
--   affected rows, so a second run matches nothing.
-- - `updated_at = updated_at` is assigned EXPLICITLY. purchase_orders.updated_at
--   is ON UPDATE CURRENT_TIMESTAMP, and an explicit assignment suppresses that,
--   so this correction does not make every affected PO look edited today.
-- - Status, totals, dates, budget_id and every line row are untouched. Only
--   approved_by and approved_by_signature change.
--
-- NOTE: keep statement separators out of these comments. executeMigration()
-- in api/run-migrations.php splits the file on the separator character BEFORE
-- it strips comments, so a stray one inside a comment breaks the migration on
-- any tenant that takes the PDO path instead of the mysql CLI path.

UPDATE `purchase_orders` po
JOIN `budgets` b ON po.budget_id = b.id
SET po.approved_by           = b.approved_by,
    po.approved_by_signature = b.approved_by_signature,
    po.updated_at            = po.updated_at
WHERE po.budget_id IS NOT NULL
  AND po.approved_at IS NOT NULL
  AND po.approved_by = po.created_by
  AND b.approved_by IS NOT NULL
  AND b.approved_by <> po.created_by;
