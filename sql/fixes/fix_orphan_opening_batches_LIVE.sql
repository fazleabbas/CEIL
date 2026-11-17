-- =====================================================================
-- LIVE FIX: orphan 'opening' rows in stock_batches inflate stock value
--
-- Run on cei.fis.co.tz (phpMyAdmin or mysql CLI), against the tenant DB.
-- Run STEP BY STEP, reading the output of each step before running the next.
--
-- WHAT IS WRONG
--   Every opening stock is meant to be TWO rows: one in `opening_stocks`
--   (the document) and one matching `stock_batches` row of
--   transaction_type='opening' (the FIFO/costing layer). Both are written
--   together by modules/masters/opening_stocks.php and
--   api/opening_stocks-import.php, keyed on (location_id, product_id).
--
--   113 stock_batches rows have NO opening_stocks row behind them. They are
--   invisible on every screen (all quantity reports read opening_stocks, not
--   batches) but the stock-value reports derive their RATE from stock_batches
--   with a product-wide average -- no location filter:
--       SUM(quantity_in * rate_per_unit) / SUM(quantity_in)   [by product_id]
--   so these dead rows are averaged into every location holding that product.
--
--   Worked example (product 2799, SWITCHGEAR with accesory):
--     5 orphan rows @ 1,547,062,234.00 sit at locations 99/100/111/112/113.
--     Location 109 holds only 6 pcs from import IMP000024.
--     (5 x 1,547,062,234 + 6 x 393.98) / 11 = 703,210,321.26  <-- the rate
--     the report printed, x 6 pcs = TZS 4,219,261,927.57 of stock value at a
--     location whose real holding is worth about TZS 5.3 million.
--
--   Tenant-wide these 113 rows carry TZS 10,416,837,055.50 of phantom value
--   across 105 products.
--
-- WHY DELETING THEM IS SAFE (all verified before this script was written)
--   * transaction_id IS NULL on all 113 -- they link to no document.
--   * quantity_remaining = quantity_in on all 113 -- not one has ever been
--     drawn from by FIFO, so no consumption, sale or transfer depends on them.
--   * consumption_voucher_batches.batch_id is the ONLY foreign key into
--     stock_batches, and ZERO of the 113 are referenced by it.
--   * No quantity figure anywhere moves: every quantity report sums the
--     movement tables (opening_stocks, purchase_details, ...), and these rows
--     have no opening_stocks row, so the app already reports zero stock for
--     them. Only the phantom VALUE goes away.
--
-- WHAT IT DOES NOT TOUCH
--   opening_stocks; every legitimate 'opening' batch that HAS an
--   opening_stocks row; purchase / purchase_import / transfer / adjustment /
--   consumption batches; transactions; any document table.
--
-- REVERSIBLE: STEP 2 copies every row into stock_batches_orphan_backup
-- before STEP 3 deletes it. STEP 5 puts them back under their ORIGINAL ids.
-- =====================================================================


-- ---------------------------------------------------------------------
-- STEP 0 -- SAFETY AUDIT. Every number below MUST be 0. If any is not,
-- STOP and do not run STEP 3; the assumptions above no longer hold.
-- ---------------------------------------------------------------------
SELECT
    SUM(sb.transaction_id IS NOT NULL)                    AS must_be_0_linked_to_a_document,
    SUM(sb.quantity_remaining <> sb.quantity_in)          AS must_be_0_already_drawn_from,
    SUM(EXISTS (SELECT 1 FROM consumption_voucher_batches c
                 WHERE c.batch_id = sb.id))               AS must_be_0_used_by_consumption
FROM stock_batches sb
WHERE sb.transaction_type = 'opening'
  AND NOT EXISTS (SELECT 1 FROM opening_stocks os
                   WHERE os.product_id  = sb.product_id
                     AND os.location_id = sb.location_id);


-- ---------------------------------------------------------------------
-- STEP 1 -- LOOK BEFORE YOU LEAP. These are the exact rows STEP 3 deletes.
-- Expected: 113 rows, TZS 10,416,837,055.50. If 0 rows, stop -- already done.
-- ---------------------------------------------------------------------
SELECT sb.id, p.name AS product, l.name AS location, sb.batch_date,
       sb.quantity_in, sb.rate_per_unit,
       ROUND(sb.quantity_in * sb.rate_per_unit, 2) AS phantom_value
FROM stock_batches sb
JOIN products p       ON p.id = sb.product_id
LEFT JOIN locations l ON l.id = sb.location_id
WHERE sb.transaction_type = 'opening'
  AND NOT EXISTS (SELECT 1 FROM opening_stocks os
                   WHERE os.product_id  = sb.product_id
                     AND os.location_id = sb.location_id)
ORDER BY sb.quantity_in * sb.rate_per_unit DESC;

SELECT COUNT(*) AS rows_to_delete,
       ROUND(SUM(sb.quantity_in * sb.rate_per_unit), 2) AS phantom_value_removed
FROM stock_batches sb
WHERE sb.transaction_type = 'opening'
  AND NOT EXISTS (SELECT 1 FROM opening_stocks os
                   WHERE os.product_id  = sb.product_id
                     AND os.location_id = sb.location_id);

-- Baseline for check 4c. Note these counts down before you delete.
SELECT transaction_type, COUNT(*) AS rows_
FROM stock_batches GROUP BY transaction_type ORDER BY transaction_type;


-- ---------------------------------------------------------------------
-- STEP 2 -- BACKUP. Full copy of every row STEP 3 will delete, ids intact.
-- INSERT IGNORE, so re-running this step cannot duplicate or lose anything.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS stock_batches_orphan_backup LIKE stock_batches;

INSERT IGNORE INTO stock_batches_orphan_backup
    (id, location_id, product_id, batch_date, transaction_type, transaction_id,
     quantity_in, quantity_remaining, rate_per_unit, created_at, updated_at)
SELECT sb.id, sb.location_id, sb.product_id, sb.batch_date, sb.transaction_type,
       sb.transaction_id, sb.quantity_in, sb.quantity_remaining, sb.rate_per_unit,
       sb.created_at, sb.updated_at
FROM stock_batches sb
WHERE sb.transaction_type = 'opening'
  AND NOT EXISTS (SELECT 1 FROM opening_stocks os
                   WHERE os.product_id  = sb.product_id
                     AND os.location_id = sb.location_id);

-- Must equal the rows_to_delete figure from STEP 1 before you go on.
SELECT COUNT(*) AS rows_backed_up FROM stock_batches_orphan_backup;


-- ---------------------------------------------------------------------
-- STEP 3 -- THE DELETE. Removes exactly the rows captured in STEP 2, by id.
-- Nothing is deleted that was not backed up first.
-- ---------------------------------------------------------------------
DELETE sb
FROM stock_batches sb
JOIN stock_batches_orphan_backup b ON b.id = sb.id
WHERE sb.transaction_type = 'opening';


-- ---------------------------------------------------------------------
-- STEP 4 -- VERIFY.
-- ---------------------------------------------------------------------
-- 4a. Orphans remaining: must be 0.
SELECT COUNT(*) AS orphans_remaining
FROM stock_batches sb
WHERE sb.transaction_type = 'opening'
  AND NOT EXISTS (SELECT 1 FROM opening_stocks os
                   WHERE os.product_id  = sb.product_id
                     AND os.location_id = sb.location_id);

-- 4b. Every legitimate opening stock still has its batch: must be 0.
SELECT COUNT(*) AS opening_stocks_missing_a_batch
FROM opening_stocks os
WHERE NOT EXISTS (SELECT 1 FROM stock_batches sb
                   WHERE sb.transaction_type = 'opening'
                     AND sb.product_id  = os.product_id
                     AND sb.location_id = os.location_id);

-- 4c. Nothing but 'opening' rows was touched. Every non-opening count must be
--     identical to the baseline you noted at the end of STEP 1.
SELECT transaction_type, COUNT(*) AS rows_
FROM stock_batches GROUP BY transaction_type ORDER BY transaction_type;

-- 4d. The reported symptom. product_id 2799 at location_id 109 was showing
--     rate 703,210,321.26 / value TZS 4,219,261,927.57 for 6 pcs.
--     This is the rate the report will now use (the import batch alone).
SELECT SUM(quantity_in * rate_per_unit) / NULLIF(SUM(quantity_in), 0) AS new_avg_rate,
       SUM(quantity_in) AS qty_in
FROM stock_batches
WHERE product_id = 2799 AND batch_date <= CURDATE();
-- NOTE: the figure this returns is in EUR, because import batches store their
-- rate in the import's own currency and never apply the header FX rate. That
-- is a SEPARATE defect (fix B) and this script does not address it.


-- ---------------------------------------------------------------------
-- STEP 5 -- ROLLBACK, only if you need to undo. Puts every row back under
-- its ORIGINAL id with its ORIGINAL timestamps (updated_at is assigned
-- explicitly -- the column is ON UPDATE CURRENT_TIMESTAMP).
-- ---------------------------------------------------------------------
-- INSERT IGNORE INTO stock_batches
--     (id, location_id, product_id, batch_date, transaction_type, transaction_id,
--      quantity_in, quantity_remaining, rate_per_unit, created_at, updated_at)
-- SELECT id, location_id, product_id, batch_date, transaction_type, transaction_id,
--        quantity_in, quantity_remaining, rate_per_unit, created_at, updated_at
-- FROM stock_batches_orphan_backup;

-- Keep stock_batches_orphan_backup for a while. Drop it only once you are
-- satisfied the stock value reports read correctly:
-- DROP TABLE stock_batches_orphan_backup;
