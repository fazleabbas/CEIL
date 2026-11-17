-- =====================================================================
-- LIVE FIX (B): purchase_import stock batches were stored in the IMPORT
-- HEADER currency instead of base currency (TZS)
--
-- Run on cei.fis.co.tz, STEP BY STEP, reading each step's output.
--
-- >>> ORDER MATTERS <<<
--   Upload the fixed api/purchase-imports-sync.php FIRST, then run this
--   script. Do not create or save any purchase import in between: after the
--   code change a save writes an already-converted batch, and this script
--   must not convert it a second time. (It detects that case anyway -- see
--   "THE SAFETY PREDICATE" below -- but keep the window short regardless.)
--
-- WHAT IS WRONG
--   api/purchase-imports-sync.php wrote
--       stock_batches.rate_per_unit = fob_rate x costing_factor
--   which is a figure in the IMPORT'S OWN currency (EUR, USD), with the
--   header exchange rate never applied. Every reader of that column --
--   stock value reports, stock card, FIFO in consumption / sales /
--   transfers, profit & loss -- treats it as BASE currency, and
--   createImportTransaction() (includes/functions.php:2950) already posts
--   imports to the General Ledger in base via amount_base. So imported
--   stock, and the cost of sales on anything issued from it, were
--   understated by the whole exchange rate, and the stock reports
--   disagreed with the ledger on every import.
--
--   Example: product 2799 at location 109 (import IMP000024, EUR @ 3057.642)
--     stored     290.38  ("TZS")
--     correct    166.59 x 1.74307899 x 3057.642 = 887,876.64 TZS per unit
--     6 pcs:     1,742.28  ->  5,327,259.84
--
-- SCOPE -- verified on this database before writing:
--   * purchases: 0 foreign-currency documents. Their batches are already
--     in base. NOT touched.
--   * opening_stocks: 0 foreign-currency documents. NOT touched.
--   * transfer_in batches: 0 carry an import-derived rate (they all copied
--     from TZS purchase/opening batches). NOT touched.
--   * Only transaction_type='purchase_import' batches are affected.
--
-- NOT BACKFILLABLE, handled separately:
--   IMP000012 (id 18) is a USD import saved with exchange_rate_to_base =
--   1.0, so multiplying changes nothing -- and its GENERAL LEDGER entry is
--   wrong too (7,948.34 posted as TZS). It is EXCLUDED here. Fix it by
--   re-saving it through the import form at the agreed rate 2,611.91
--   (the rate IMP000002 uses for the very same date, 2026-04-14); the
--   update path rebuilds its batches AND re-posts its ledger entries.
--
-- THE SAFETY PREDICATE -- why this script cannot double-convert
--   A batch is converted only while its rate still EQUALS the header-currency
--   landed rate held in purchase_import_details.landed_rate_per_unit (which
--   this script never modifies). Once converted, the two no longer match and
--   the row stops qualifying. The UPDATE also recomputes from the pristine
--   rate saved in the backup table rather than from the live value, so
--   re-running STEP 3 is harmless.
--
-- REVERSIBLE: STEP 2 saves every original row; STEP 5 restores it.
-- =====================================================================


-- ---------------------------------------------------------------------
-- STEP 0 -- WHAT IS OUT THERE. Nothing is changed by this step.
-- ---------------------------------------------------------------------
-- 0a. Imports excluded because their rate is <= 1 (cannot be backfilled).
--     Expect exactly one row: IMP000012.
SELECT id, import_number, import_date, currency_id, exchange_rate_to_base
FROM purchase_imports
WHERE currency_id <> 1 AND exchange_rate_to_base <= 1;

-- 0b. Confirm the scope findings still hold. All FOUR MUST be 0.
--     The last one matters because STEP 3 joins purchase_import_details on
--     (import_id, product_id): one detail row produces exactly one batch row,
--     so if a product ever appeared twice on one import the join would no
--     longer be 1:1 and STEP 3 must not be run as written.
SELECT
  (SELECT COUNT(*) FROM (SELECT import_id, product_id FROM purchase_import_details
                          GROUP BY import_id, product_id HAVING COUNT(*) > 1) dup) AS must_be_0_duplicated_product_on_an_import,
  (SELECT COUNT(*) FROM purchases       WHERE currency_id <> 1)                         AS must_be_0_foreign_purchases,
  (SELECT COUNT(*) FROM opening_stocks  WHERE currency_id IS NOT NULL AND currency_id <> 1) AS must_be_0_foreign_opening,
  (SELECT COUNT(*) FROM stock_batches sb
    WHERE sb.transaction_type = 'transfer_in'
      AND EXISTS (SELECT 1 FROM purchase_import_details d
                   WHERE d.product_id = sb.product_id
                     AND ABS(d.landed_rate_per_unit - sb.rate_per_unit) < 0.01)) AS must_be_0_import_derived_transfers;


-- ---------------------------------------------------------------------
-- STEP 1 -- LOOK BEFORE YOU LEAP. Exactly the rows STEP 3 will convert.
-- ---------------------------------------------------------------------
SELECT sb.id, pi.import_number, p.name AS product, l.name AS location,
       sb.quantity_in, sb.rate_per_unit AS rate_now_header_currency,
       pi.exchange_rate_to_base AS fx,
       ROUND(sb.rate_per_unit * pi.exchange_rate_to_base, 2) AS rate_after_base
FROM stock_batches sb
JOIN purchase_imports pi ON pi.id = sb.transaction_id
JOIN products p          ON p.id  = sb.product_id
LEFT JOIN locations l    ON l.id  = sb.location_id
WHERE sb.transaction_type = 'purchase_import'
  AND pi.exchange_rate_to_base > 1
  AND EXISTS (SELECT 1 FROM purchase_import_details d
               WHERE d.import_id  = sb.transaction_id
                 AND d.product_id = sb.product_id
                 AND ABS(d.landed_rate_per_unit - sb.rate_per_unit) < 0.01)
ORDER BY pi.import_number, p.name;

SELECT COUNT(*) AS rows_to_convert,
       (SELECT COUNT(*) FROM stock_batches WHERE transaction_type = 'purchase_import') AS total_import_batches
FROM stock_batches sb
JOIN purchase_imports pi ON pi.id = sb.transaction_id
WHERE sb.transaction_type = 'purchase_import'
  AND pi.exchange_rate_to_base > 1
  AND EXISTS (SELECT 1 FROM purchase_import_details d
               WHERE d.import_id  = sb.transaction_id
                 AND d.product_id = sb.product_id
                 AND ABS(d.landed_rate_per_unit - sb.rate_per_unit) < 0.01);

-- Baseline for check 4c. Note these counts down.
SELECT transaction_type, COUNT(*) AS rows_
FROM stock_batches GROUP BY transaction_type ORDER BY transaction_type;


-- ---------------------------------------------------------------------
-- STEP 2 -- BACKUP the original rows, ids and timestamps intact.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS stock_batches_import_fx_backup LIKE stock_batches;

INSERT IGNORE INTO stock_batches_import_fx_backup
    (id, location_id, product_id, batch_date, transaction_type, transaction_id,
     quantity_in, quantity_remaining, rate_per_unit, created_at, updated_at)
SELECT sb.id, sb.location_id, sb.product_id, sb.batch_date, sb.transaction_type,
       sb.transaction_id, sb.quantity_in, sb.quantity_remaining, sb.rate_per_unit,
       sb.created_at, sb.updated_at
FROM stock_batches sb
JOIN purchase_imports pi ON pi.id = sb.transaction_id
WHERE sb.transaction_type = 'purchase_import'
  AND pi.exchange_rate_to_base > 1
  AND EXISTS (SELECT 1 FROM purchase_import_details d
               WHERE d.import_id  = sb.transaction_id
                 AND d.product_id = sb.product_id
                 AND ABS(d.landed_rate_per_unit - sb.rate_per_unit) < 0.01);

-- Must equal rows_to_convert from STEP 1.
SELECT COUNT(*) AS rows_backed_up FROM stock_batches_import_fx_backup;


-- ---------------------------------------------------------------------
-- STEP 3 -- THE CONVERSION.
--
-- Recomputed from source (fob_rate x costing_factor x fx), NOT by
-- multiplying the stored rate. That is deliberate: landed_rate_per_unit is
-- already rounded to 2 decimals in the header currency, and multiplying a
-- rounded EUR figure by ~3,000 amplifies that rounding into hundreds of
-- thousands of shillings on a large import. Rounding once, at the end, is
-- also byte-for-byte what the fixed PHP now writes
--   round($fobRate * $costingFactor * $fxHeader, 2)
-- so a backfilled batch and a re-saved one agree exactly.
--
-- Safe to re-run: the inputs are the unchanged source columns, so the
-- result is the same every time. updated_at is assigned to itself to
-- suppress the ON UPDATE CURRENT_TIMESTAMP bump -- a currency correction
-- must not make hundreds of batches look edited today.
-- ---------------------------------------------------------------------
UPDATE stock_batches sb
JOIN stock_batches_import_fx_backup b ON b.id = sb.id
JOIN purchase_imports pi              ON pi.id = sb.transaction_id
JOIN purchase_import_details d        ON d.import_id  = sb.transaction_id
                                     AND d.product_id = sb.product_id
SET sb.rate_per_unit = ROUND(d.fob_rate_per_unit * pi.costing_factor * pi.exchange_rate_to_base, 2),
    sb.updated_at    = sb.updated_at
WHERE sb.transaction_type = 'purchase_import'
  AND pi.exchange_rate_to_base > 1;


-- ---------------------------------------------------------------------
-- STEP 4 -- VERIFY.
-- ---------------------------------------------------------------------
-- 4a. Nothing left in header currency (excluding IMP000012): must be 0.
SELECT COUNT(*) AS unconverted_remaining
FROM stock_batches sb
JOIN purchase_imports pi ON pi.id = sb.transaction_id
WHERE sb.transaction_type = 'purchase_import'
  AND pi.exchange_rate_to_base > 1
  AND EXISTS (SELECT 1 FROM purchase_import_details d
               WHERE d.import_id  = sb.transaction_id
                 AND d.product_id = sb.product_id
                 AND ABS(d.landed_rate_per_unit - sb.rate_per_unit) < 0.01);

-- 4b. Each import's batch value must now equal its landed total x its rate.
--     diff must be ~0 on every row (a few cents of rounding is fine).
SELECT pi.import_number, pi.exchange_rate_to_base AS fx,
       ROUND(pi.total_landed_amount * pi.exchange_rate_to_base, 2) AS landed_in_base,
       ROUND(SUM(sb.quantity_in * sb.rate_per_unit), 2)            AS batch_value_base,
       ROUND(SUM(sb.quantity_in * sb.rate_per_unit)
             - pi.total_landed_amount * pi.exchange_rate_to_base, 2) AS diff
FROM purchase_imports pi
JOIN stock_batches sb ON sb.transaction_type = 'purchase_import' AND sb.transaction_id = pi.id
GROUP BY pi.id
ORDER BY ABS(SUM(sb.quantity_in * sb.rate_per_unit)
             - pi.total_landed_amount * pi.exchange_rate_to_base) DESC;

-- 4c. Only purchase_import rows moved. Counts must match the STEP 1 baseline.
SELECT transaction_type, COUNT(*) AS rows_
FROM stock_batches GROUP BY transaction_type ORDER BY transaction_type;

-- 4d. Nothing was edited today by this script: must be 0.
SELECT COUNT(*) AS batches_stamped_today
FROM stock_batches WHERE transaction_type = 'purchase_import' AND DATE(updated_at) = CURDATE();

-- 4e. The reported symptom. Expect rate 887,876.64 and 6 pcs -- the page
--     should now read TZS 5,327,259.84 for product 2799 at location 109.
--     (Where this started: TZS 4,219,261,927.57.)
SELECT SUM(quantity_in * rate_per_unit) / NULLIF(SUM(quantity_in), 0) AS new_avg_rate,
       SUM(quantity_in) AS qty_in
FROM stock_batches WHERE product_id = 2799 AND batch_date <= CURDATE();


-- ---------------------------------------------------------------------
-- STEP 5 -- ROLLBACK, only if you need to undo.
-- ---------------------------------------------------------------------
-- UPDATE stock_batches sb
-- JOIN stock_batches_import_fx_backup b ON b.id = sb.id
-- SET sb.rate_per_unit = b.rate_per_unit,
--     sb.updated_at    = b.updated_at;

-- Keep stock_batches_import_fx_backup until you are satisfied. Then:
-- DROP TABLE stock_batches_import_fx_backup;
