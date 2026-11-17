-- =====================================================================
-- LIVE FIX: budget-raised POs credited the wrong person as approver
--
-- Run this on cei.fis.co.tz (phpMyAdmin or mysql CLI), against the tenant
-- database. Same content as sql/migrations/0043_fix_budget_po_approver_attribution.sql,
-- wrapped with inspection + verification queries so you can see exactly what
-- changes before and after.
--
-- If you run migrations on the server (api/run-migrations.php), migration 0043
-- does the same thing and you do NOT need this file. Running both is harmless —
-- the UPDATE is idempotent.
--
-- WHAT IT DOES
--   A PO raised from an approved budget is born 'approved' (by design — the
--   budget's approval covers the spend), but it stamped the PO's CREATOR into
--   approved_by, even though raising a budget PO needs no `approve` permission.
--   The document therefore claimed a non-approver had approved it.
--   This re-points approved_by (and approved_by_signature) at whoever actually
--   approved the BUDGET.
--
-- WHAT IT DOES NOT TOUCH
--   status, totals, dates, budget_id, created_by, prepared_by_signature,
--   purchase_order_details, and any PO not raised from a budget.
--
-- Take a backup first if you want one:
--   mysqldump -u USER -p DBNAME purchase_orders > purchase_orders_backup.sql
-- =====================================================================


-- ---------------------------------------------------------------------
-- STEP 1 — LOOK BEFORE YOU LEAP. Run this on its own first.
-- Every row listed here will be changed: "creator_now" becomes "will_credit".
-- If this returns 0 rows, there is nothing to fix and you can stop.
-- ---------------------------------------------------------------------
SELECT po.id,
       po.po_number,
       b.budget_number,
       uc.name AS creator_now,
       ub.name AS will_credit,
       po.approved_at
FROM purchase_orders po
JOIN budgets b        ON po.budget_id = b.id
LEFT JOIN users uc    ON po.created_by = uc.id
LEFT JOIN users ub    ON b.approved_by = ub.id
WHERE po.budget_id IS NOT NULL
  AND po.approved_at IS NOT NULL
  AND po.approved_by = po.created_by
  AND b.approved_by IS NOT NULL
  AND b.approved_by <> po.created_by
ORDER BY po.id;


-- ---------------------------------------------------------------------
-- STEP 2 — THE FIX.
--
-- `updated_at = updated_at` is deliberate, not a typo. That column is
-- ON UPDATE CURRENT_TIMESTAMP, and assigning it explicitly suppresses the
-- automatic bump, so this correction does not make every affected PO look
-- as though it was edited today.
-- ---------------------------------------------------------------------
UPDATE purchase_orders po
JOIN budgets b ON po.budget_id = b.id
SET po.approved_by           = b.approved_by,
    po.approved_by_signature = b.approved_by_signature,
    po.updated_at            = po.updated_at
WHERE po.budget_id IS NOT NULL
  AND po.approved_at IS NOT NULL
  AND po.approved_by = po.created_by
  AND b.approved_by IS NOT NULL
  AND b.approved_by <> po.created_by;


-- ---------------------------------------------------------------------
-- STEP 3 — VERIFY. Expect remaining_to_fix = 0.
-- ---------------------------------------------------------------------
SELECT COUNT(*) AS remaining_to_fix
FROM purchase_orders po
JOIN budgets b ON po.budget_id = b.id
WHERE po.budget_id IS NOT NULL
  AND po.approved_at IS NOT NULL
  AND po.approved_by = po.created_by
  AND b.approved_by IS NOT NULL
  AND b.approved_by <> po.created_by;


-- ---------------------------------------------------------------------
-- STEP 4 — The corrected picture. creator and approver should now differ,
-- and approver should match the budget's approver on every row.
-- ---------------------------------------------------------------------
SELECT po.id,
       po.po_number,
       b.budget_number,
       uc.name AS created_by,
       ua.name AS approved_by,
       ub.name AS budget_approved_by,
       CASE WHEN po.approved_by = b.approved_by THEN 'OK' ELSE 'MISMATCH' END AS check_result
FROM purchase_orders po
JOIN budgets b     ON po.budget_id = b.id
LEFT JOIN users uc ON po.created_by = uc.id
LEFT JOIN users ua ON po.approved_by = ua.id
LEFT JOIN users ub ON b.approved_by = ub.id
WHERE po.budget_id IS NOT NULL
ORDER BY po.id;


-- ---------------------------------------------------------------------
-- STEP 5 — Safety net: are there any POs still approved by someone who
-- lacks the approve permission? Admins are excluded because hasPermission()
-- grants them everything regardless of the user_permissions row.
-- Ideally returns 0 rows.
-- ---------------------------------------------------------------------
SELECT po.id, po.po_number, po.budget_id, u.name AS approver,
       r.name AS role, COALESCE(up.can_approve, 0) AS can_approve
FROM purchase_orders po
JOIN users u       ON po.approved_by = u.id
LEFT JOIN roles r  ON u.role_id = r.id
LEFT JOIN user_permissions up
       ON up.user_id = po.approved_by AND up.module_name = 'purchase_orders'
WHERE po.approved_at IS NOT NULL
  AND COALESCE(up.can_approve, 0) = 0
  AND COALESCE(r.name, '') <> 'Admin'
ORDER BY po.id;
