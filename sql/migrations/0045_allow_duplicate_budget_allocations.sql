-- Migration: 0045_allow_duplicate_budget_allocations
-- Description: Let one budget hold the SAME product + supplier + procurement
--              type on more than one allocation row. Drops the UNIQUE key
--              uniq_budget_alloc added by migration 0034 and puts a plain
--              (non-unique) index on the same columns in its place.
-- Date: 2026-08-13
--
-- WHY
-- Users split a single product/supplier allocation across rows for real
-- reasons - different rates for different phases, or simply laying the budget
-- out the way the site does. The uniqueness was never an accounting rule, and
-- nothing in the app depends on it - there is no ON DUPLICATE KEY UPDATE, no
-- INSERT IGNORE and no REPLACE against budget_details anywhere.
--
-- NOT A LOOSENING OF THE REAL CONTROL
-- Over-allocation is still blocked. modules/transactions/budget.php sums a
-- product's rows TOGETHER into perProductAllocated and rejects the budget when
-- that total exceeds the requisition approved quantity minus what other
-- budgets already took, so splitting a line across rows cannot smuggle in extra
-- quantity. The approve API caps the same way, per product across lines.
--
-- THE CODE CHANGE THAT HAD TO LAND WITH THIS
-- Four queries read a budget row-by-row and then subtract per-PRODUCT figures
-- (already purchased, outstanding on open POs). Left ungrouped, a product
-- sitting on two rows would have the whole purchased quantity deducted from
-- EACH of them and the prefills would understate what is still procurable.
-- All four now GROUP BY product with a quantity-weighted rate and summed VAT.
--   includes/functions.php - getBudgetSupplierRemaining()
--   modules/transactions/purchases.php - from_budget prefill
--   modules/transactions/purchase_order.php - from_budget prefill
--   modules/transactions/purchase_import_form.php - from_budget prefill
--
-- SAFE ON EXISTING DATA
-- Dropping a uniqueness constraint can never invalidate rows that already
-- satisfied it, and the replacement index carries the identical columns in the
-- identical order, so every query plan that used uniq_budget_alloc still has
-- one. Existing budgets are untouched.
--
-- NOTE: keep statement separators out of these comments. executeMigration()
-- in api/run-migrations.php splits the file on the separator character BEFORE
-- it strips comments, so a stray one inside a comment breaks the migration on
-- any tenant that takes the PDO path instead of the mysql CLI path.

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'budget_details'
       AND INDEX_NAME = 'uniq_budget_alloc') > 0,
    'ALTER TABLE `budget_details` DROP INDEX `uniq_budget_alloc`',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'budget_details'
       AND INDEX_NAME = 'idx_bd_alloc') = 0,
    'ALTER TABLE `budget_details` ADD KEY `idx_bd_alloc` (`budget_id`, `product_id`, `supplier_id`, `procurement_type`)',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
