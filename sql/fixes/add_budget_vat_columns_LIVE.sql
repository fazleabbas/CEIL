-- =====================================================================
-- LIVE FIX: "Budget is not saving" — budget_details is missing the two
--           VAT columns that the budget form now writes on every save.
--
-- Run this on cei.fis.co.tz (phpMyAdmin or mysql CLI), against the tenant
-- database. Same columns as sql/migrations/0044_add_vat_to_budget_details.sql,
-- wrapped with inspection + verification queries so you can see exactly what
-- changes before and after.
--
-- >>> BEFORE YOU PASTE THIS IN <<<
-- Click the TENANT DATABASE in phpMyAdmin's left sidebar FIRST, then open the
-- SQL tab. If you run it with no database selected — or while sitting on
-- information_schema — you get
--     #1044 Access denied for user ... to database 'information_schema'
-- That error means the script ran against the wrong database. Nothing was
-- changed. Select the right one and run it again. STEP 0 below confirms it.
--
-- If you run migrations on the server (api/run-migrations.php), migration 0044
-- does the same thing and you do NOT need this file.
--
-- THE SYMPTOM THIS FIXES
--   Saving a budget appears to do nothing at all. No error, no new budget,
--   no edit applied. The Save button just reloads the form.
--
-- WHY
--   The per-line VAT feature (9 Aug 2026) added vat_percent + vat_amount to
--   budget_details, and modules/transactions/budget.php now names both columns
--   in its INSERT. If the code was uploaded but this migration never ran, that
--   INSERT fails with "Unknown column 'vat_percent'", the whole save rolls back,
--   and the error is swallowed silently — budget.php reports failures through
--   setAlert(), and getAlert() is never called anywhere in the app.
--   api/budgets-sync.php (the approve modal) writes the same two columns, so
--   approving a budget fails for the same reason.
--
-- SAFE BY CONSTRUCTION
--   Purely additive. vat_percent defaults to NULL, vat_amount defaults to 0.00,
--   so for every budget written before this runs
--       SUM(quantity * rate + vat_amount) == SUM(quantity * rate)
--   and no existing budget total moves by a cent. STEP 4 proves it.
--
-- WHAT IT DOES NOT TOUCH
--   No data is written or deleted. No other table. `budgets` itself has no
--   total column — every budget total in the app is derived on the fly.
--
-- Take a backup first if you want one:
--   mysqldump -u USER -p DBNAME budget_details > budget_details_backup.sql
-- =====================================================================


-- ---------------------------------------------------------------------
-- STEP 0 — WHICH DATABASE AM I ON. Run this on its own first.
--
-- It must print the tenant database name. If it prints information_schema,
-- or NULL, STOP — pick the correct database in the sidebar and start again.
-- ---------------------------------------------------------------------
SELECT DATABASE() AS running_against;


-- ---------------------------------------------------------------------
-- STEP 1 — LOOK BEFORE YOU LEAP.
--
--   no rows            -> this IS the bug, carry on to STEP 2
--   vat_percent
--   + vat_amount rows  -> the columns are already there, STOP.
--                         Budget saving is failing for some other reason.
-- ---------------------------------------------------------------------
SHOW COLUMNS FROM `budget_details` LIKE 'vat%';


-- ---------------------------------------------------------------------
-- STEP 2 — THE FIX. Run the two statements below.
--
-- These are plain ALTERs on purpose. The guarded PREPARE/EXECUTE form used in
-- the migrations needs information_schema and silently resolves against the
-- wrong database when none is selected — STEP 1 already tells you whether you
-- need these, so the guard buys nothing here and costs clarity.
--
-- If either one reports
--     #1060 Duplicate column name
-- that column already existed. That is harmless — skip it and move on.
--
-- vat_percent is what the user types (18). It is NULL when a flat VAT amount
-- was typed instead, and it is kept so the amount can RE-DERIVE when the
-- approver edits a quantity or rate in the approve modal.
-- vat_amount is the money that actually counts — every budget total and every
-- downstream Purchase / PO / Import prefill reads it.
-- ---------------------------------------------------------------------
ALTER TABLE `budget_details`
    ADD COLUMN `vat_percent` decimal(6,3) DEFAULT NULL
    COMMENT 'VAT rate typed by the user, NULL when a flat amount was entered'
    AFTER `rate`;

ALTER TABLE `budget_details`
    ADD COLUMN `vat_amount` decimal(15,2) NOT NULL DEFAULT 0.00
    COMMENT 'VAT on this allocation line. Pro-rated by quantity when the line is partly converted.'
    AFTER `vat_percent`;


-- ---------------------------------------------------------------------
-- STEP 3 — VERIFY THE SHAPE. Expect exactly these two rows, and note that
-- they must sit immediately after `rate`:
--
--   vat_percent   decimal(6,3)    YES   NULL
--   vat_amount    decimal(15,2)   NO    0.00
-- ---------------------------------------------------------------------
SHOW COLUMNS FROM `budget_details`;


-- ---------------------------------------------------------------------
-- STEP 4 — VERIFY NOTHING MOVED. Every budget that existed before this ran
-- must have exactly the total it had yesterday.
--
-- Expect: lines_with_vat = 0 and total_vat = 0.00 on every row, i.e.
-- goods_total = new_total on every existing budget.
-- (After users start entering VAT this will legitimately stop being 0 —
-- run it straight after STEP 2, before anyone touches a budget.)
-- ---------------------------------------------------------------------
SELECT b.budget_number,
       COUNT(bd.id)                                   AS lines_total,
       SUM(bd.vat_amount <> 0)                        AS lines_with_vat,
       ROUND(SUM(bd.quantity * bd.rate), 2)           AS goods_total,
       ROUND(SUM(bd.vat_amount), 2)                   AS total_vat,
       ROUND(SUM(bd.quantity * bd.rate
                 + bd.vat_amount), 2)                 AS new_total
FROM budgets b
JOIN budget_details bd ON bd.budget_id = b.id
GROUP BY b.id, b.budget_number
ORDER BY b.id;


-- ---------------------------------------------------------------------
-- STEP 5 — Safety net for the same class of problem.
--
-- The 9 Aug upload also carried migration 0043 (budget POs credited the
-- wrong approver). If POs raised from budgets still name their CREATOR as
-- approver, run sql/fixes/fix_budget_po_approver_LIVE.sql as well.
-- Ideally returns 0 rows.
-- ---------------------------------------------------------------------
SELECT po.id, po.po_number, b.budget_number,
       uc.name AS creator_and_approver
FROM purchase_orders po
JOIN budgets b     ON po.budget_id = b.id
LEFT JOIN users uc ON po.created_by = uc.id
WHERE po.budget_id IS NOT NULL
  AND po.approved_at IS NOT NULL
  AND po.approved_by = po.created_by
  AND b.approved_by IS NOT NULL
  AND b.approved_by <> po.created_by
ORDER BY po.id;
