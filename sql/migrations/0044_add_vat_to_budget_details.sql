-- Migration: 0044_add_vat_to_budget_details
-- Description: Per-line VAT on budget allocations, so the VAT agreed at budget
--              stage flows into the Purchase / Purchase Import / Purchase Order
--              raised from that budget.
-- Date: 2026-08-09
--
-- WHY TWO COLUMNS
-- vat_percent is what the user normally types (18), vat_amount is the money that
-- actually counts. Both are stored, mirroring purchase_import_costs
-- (migration 0017), which is the only other per-line VAT in this system:
--   - keeping the percent lets the amount RE-DERIVE when the approver edits the
--     quantity or rate in the approve modal, instead of going silently stale;
--   - keeping the amount allows an odd figure that is not a clean percentage,
--     and is what every total and every downstream prefill reads.
-- vat_percent is NULL when the user typed an amount directly.
--
-- SAFE BY CONSTRUCTION
-- Additive and defaulted to 0.00, so every existing budget's arithmetic is
-- unchanged: SUM(quantity * rate + vat_amount) == SUM(quantity * rate) for all
-- rows written before this migration.
--
-- NOTE the remaining/over-allocation logic is untouched by this. Every consumer
-- of budget_details (getBudgetSupplierRemaining, getBudgetPoOutstanding,
-- getRequisitionBudgetedQty) tracks QUANTITIES only; rate — and now VAT — ride
-- along as passenger data for prefill and display. There is no
-- "budget consumed by value" anywhere that this could corrupt.
--
-- `budgets` itself is NOT touched: it has no total column at all, every budget
-- total in the app is derived on the fly.
--
-- NOTE: keep statement separators out of these comments. executeMigration()
-- in api/run-migrations.php splits the file on the separator character BEFORE
-- it strips comments, so a stray one inside a comment breaks the migration on
-- any tenant that takes the PDO path instead of the mysql CLI path.

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'budget_details'
       AND COLUMN_NAME = 'vat_percent') = 0,
    'ALTER TABLE `budget_details` ADD COLUMN `vat_percent` decimal(6,3) DEFAULT NULL COMMENT ''VAT rate typed by the user, NULL when a flat amount was entered'' AFTER `rate`',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'budget_details'
       AND COLUMN_NAME = 'vat_amount') = 0,
    'ALTER TABLE `budget_details` ADD COLUMN `vat_amount` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT ''VAT on this allocation line. Pro-rated by quantity when the line is partly converted.'' AFTER `vat_percent`',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
