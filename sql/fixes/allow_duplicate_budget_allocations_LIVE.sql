-- =====================================================================
-- LIVE FIX: allow the same product + supplier + type on several rows of
--           one budget
--
-- Run on cei.fis.co.tz against the tenant database. Same effect as
-- sql/migrations/0045_allow_duplicate_budget_allocations.sql.
--
-- DELIBERATELY AVOIDS INFORMATION_SCHEMA. The 0044 migration guards itself with
-- a SELECT against INFORMATION_SCHEMA.COLUMNS, and on this server that returned
--     #1044 - Access denied for user 'root'@'localhost' to database
--             'information_schema'
-- so this script uses SHOW INDEX instead, which needs no extra privilege, and
-- plain ALTER statements you run yourself.
--
-- WHAT IT DOES
--   Drops the UNIQUE key uniq_budget_alloc from budget_details and puts a
--   plain (non-unique) index on the same four columns in its place.
--
-- WHAT IT DOES NOT DO
--   No column is added, dropped or altered. No row is read, written or
--   deleted. Existing budgets are untouched, and over-allocation is still
--   blocked by the application, which sums a product's rows together before
--   checking them against the requisition.
--
-- THE CODE MUST GO UP WITH IT. Upload these five files at the same time, or a
-- product split across two rows will show an understated remaining quantity on
-- the purchase / PO / import prefills:
--   modules/transactions/budget.php
--   modules/transactions/purchases.php
--   modules/transactions/purchase_order.php
--   modules/transactions/purchase_import_form.php
--   includes/functions.php
--
-- Take a backup first if you want one:
--   mysqldump -u USER -p DBNAME budget_details > budget_details_backup.sql
-- =====================================================================


-- ---------------------------------------------------------------------
-- STEP 1 - LOOK FIRST. Run this on its own.
--
-- Expect a block of 4 rows named uniq_budget_alloc with Non_unique = 0.
-- If nothing named uniq_budget_alloc comes back, the fix has already been
-- applied and you can stop here.
-- ---------------------------------------------------------------------
SHOW INDEX FROM `budget_details`;


-- ---------------------------------------------------------------------
-- STEP 2 - REMOVE THE CONSTRAINT.
--
-- If this reports "check that column/key exists", the index is already gone
-- and there is nothing to do - that error is harmless.
-- ---------------------------------------------------------------------
ALTER TABLE `budget_details` DROP INDEX `uniq_budget_alloc`;


-- ---------------------------------------------------------------------
-- STEP 3 - PUT BACK A NON-UNIQUE INDEX ON THE SAME COLUMNS.
--
-- Same columns in the same order, so every query that used the old index
-- still has one to use. Only the uniqueness is gone.
-- If this reports "Duplicate key name", it has already been added - harmless.
-- ---------------------------------------------------------------------
ALTER TABLE `budget_details`
  ADD KEY `idx_bd_alloc` (`budget_id`, `product_id`, `supplier_id`, `procurement_type`);


-- ---------------------------------------------------------------------
-- STEP 4 - VERIFY.
--
-- uniq_budget_alloc must be ABSENT, and idx_bd_alloc must be present on 4
-- rows with Non_unique = 1.
-- ---------------------------------------------------------------------
SHOW INDEX FROM `budget_details`;


-- ---------------------------------------------------------------------
-- STEP 5 - NOTHING WAS LOST. Row count and totals before and after this
-- script must be identical - it only touches indexes.
-- ---------------------------------------------------------------------
SELECT COUNT(*) AS detail_rows,
       ROUND(SUM(`quantity` * `rate`), 2) AS goods_value,
       ROUND(SUM(`vat_amount`), 2) AS vat_value
FROM `budget_details`;
