-- =====================================================================
-- LIVE FIX (D): transferred stock landed at the destination valued at ZERO
--
-- Run on cei.fis.co.tz, STEP BY STEP, reading each step's output.
--
-- >>> ORDER MATTERS <<<
--   Upload the fixed api/transfers-sync.php FIRST, then run this script.
--   The code fix stops NEW zero rates being created; this script repairs the
--   ones already there.
--
-- WHAT IS WRONG
--   stock_transfer_details.rate_per_unit was taken from the browser payload
--   and silently defaulted to 0 (api/transfers-sync.php, create action). The
--   requisition-based transfer screen never sent a rate at all, so 53 of its
--   53 lines are zero; the ordinary transfer screen missed it on 37.6% of
--   lines. On receive, that rate is copied straight onto the destination
--   stock_batches row -- so the stock arrived worth nothing.
--
--   Meanwhile the dispatch FIFO walk read the source batches and selected
--   only (id, quantity_remaining): it had the true cost in hand and threw it
--   away. The code fix derives the rate from the layers it consumes.
--
-- SCALE (measured on the 08-Sep-2026 snapshot)
--   235 of 537 transfer detail rows carry rate 0: 136 received, 27 in
--   transit, 72 still draft.
--   The 136 received ones produced 136 stock_batches rows holding 31,563
--   units worth about 368,846,586 -- currently reported as zero.
--   Destinations affected: AKEST Store (by far the largest), ELEWANA Store,
--   Back office, CRDB MAINT STORE, Main Office, M2.
--
-- HOW THE RATE IS RECOVERED -- and its one limitation
--   Transfers do NOT record which batch layers they consumed. Consumption
--   vouchers do (consumption_voucher_batches, migration 0040); transfers
--   never got the equivalent. So the exact FIFO layers are unknowable after
--   the fact, and this script uses the SOURCE location's quantity-weighted
--   average rate for that product, ignoring zero-rate batches. That is the
--   same basis the stock-value reports use, and on the snapshot every one of
--   the 136 resolved to a real rate -- none fell through.
--
-- DRAFT lines are deliberately NOT touched: once the code fix is uploaded,
-- dispatching them derives the correct rate automatically.
--
-- REVERSIBLE: STEP 2 backs up both tables; STEP 5 restores them.
-- =====================================================================


-- ---------------------------------------------------------------------
-- STEP 0 -- THE SHAPE OF THE PROBLEM. Changes nothing.
-- ---------------------------------------------------------------------
-- 0a. Zero-rate detail lines by transfer status.
SELECT t.status, COUNT(*) AS detail_rows, SUM(td.rate_per_unit = 0) AS zero_rate
FROM stock_transfer_details td
JOIN stock_transfers t ON t.id = td.transfer_id
GROUP BY t.status ORDER BY zero_rate DESC;

-- 0b. Zero-valued stock sitting at destinations right now.
SELECT l.name AS destination, COUNT(*) AS zero_batches,
       ROUND(SUM(sb.quantity_in), 3) AS units_in,
       ROUND(SUM(sb.quantity_remaining), 3) AS units_still_there
FROM stock_batches sb
LEFT JOIN locations l ON l.id = sb.location_id
WHERE sb.transaction_type = 'transfer_in' AND sb.rate_per_unit = 0
GROUP BY sb.location_id ORDER BY zero_batches DESC;

-- 0c. Anything that CANNOT be recovered (no usable source rate): must be 0.
--     If this is not 0, those rows are listed and will simply be left alone
--     by STEP 3 -- nothing breaks, they just stay at zero.
SELECT COUNT(*) AS must_be_0_unrecoverable
FROM stock_batches sb
JOIN stock_transfers t ON t.id = sb.transaction_id
LEFT JOIN (
    SELECT location_id, product_id,
           SUM(quantity_in * rate_per_unit) / NULLIF(SUM(quantity_in), 0) AS rate
    FROM stock_batches WHERE rate_per_unit > 0
    GROUP BY location_id, product_id
) src ON src.location_id = t.from_location_id AND src.product_id = sb.product_id
WHERE sb.transaction_type = 'transfer_in' AND sb.rate_per_unit = 0
  AND (src.rate IS NULL OR src.rate <= 0);


-- ---------------------------------------------------------------------
-- STEP 1 -- WHAT STEP 3 WILL DO. Expect 136 rows, about 368,846,586.
-- ---------------------------------------------------------------------
SELECT sb.id AS batch_id, t.transfer_number, fl.name AS from_loc, tl.name AS to_loc,
       p.name AS product, sb.quantity_in, ROUND(src.rate, 2) AS recovered_rate,
       ROUND(sb.quantity_in * src.rate, 2) AS value_restored
FROM stock_batches sb
JOIN stock_transfers t ON t.id = sb.transaction_id
JOIN products p ON p.id = sb.product_id
LEFT JOIN locations fl ON fl.id = t.from_location_id
LEFT JOIN locations tl ON tl.id = t.to_location_id
JOIN (
    SELECT location_id, product_id,
           SUM(quantity_in * rate_per_unit) / NULLIF(SUM(quantity_in), 0) AS rate
    FROM stock_batches WHERE rate_per_unit > 0
    GROUP BY location_id, product_id
) src ON src.location_id = t.from_location_id AND src.product_id = sb.product_id
WHERE sb.transaction_type = 'transfer_in' AND sb.rate_per_unit = 0 AND src.rate > 0
ORDER BY sb.quantity_in * src.rate DESC;

SELECT COUNT(*) AS batches_to_fix, ROUND(SUM(sb.quantity_in * src.rate), 2) AS value_restored
FROM stock_batches sb
JOIN stock_transfers t ON t.id = sb.transaction_id
JOIN (
    SELECT location_id, product_id,
           SUM(quantity_in * rate_per_unit) / NULLIF(SUM(quantity_in), 0) AS rate
    FROM stock_batches WHERE rate_per_unit > 0
    GROUP BY location_id, product_id
) src ON src.location_id = t.from_location_id AND src.product_id = sb.product_id
WHERE sb.transaction_type = 'transfer_in' AND sb.rate_per_unit = 0 AND src.rate > 0;


-- ---------------------------------------------------------------------
-- STEP 2 -- BACKUP both tables before touching either.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS stock_batches_zero_rate_backup   LIKE stock_batches;
CREATE TABLE IF NOT EXISTS transfer_details_zero_rate_backup LIKE stock_transfer_details;

INSERT IGNORE INTO stock_batches_zero_rate_backup
SELECT sb.* FROM stock_batches sb
WHERE sb.transaction_type = 'transfer_in' AND sb.rate_per_unit = 0;

INSERT IGNORE INTO transfer_details_zero_rate_backup
SELECT td.* FROM stock_transfer_details td
JOIN stock_transfers t ON t.id = td.transfer_id
WHERE td.rate_per_unit = 0 AND t.status IN ('received', 'in_transit');

SELECT (SELECT COUNT(*) FROM stock_batches_zero_rate_backup)    AS batches_backed_up,
       (SELECT COUNT(*) FROM transfer_details_zero_rate_backup) AS details_backed_up;


-- ---------------------------------------------------------------------
-- STEP 3 -- THE REPAIR. Two updates; run them in this order.
--
-- Both recompute from the source location's own rates, which this script
-- never changes, so re-running is harmless. updated_at is assigned to
-- itself so a cost correction does not make every row look edited today.
-- ---------------------------------------------------------------------
-- 3a. The destination stock batches (the 136 valued at zero).
UPDATE stock_batches sb
JOIN stock_transfers t ON t.id = sb.transaction_id
JOIN (
    SELECT location_id, product_id,
           SUM(quantity_in * rate_per_unit) / NULLIF(SUM(quantity_in), 0) AS rate
    FROM stock_batches WHERE rate_per_unit > 0
    GROUP BY location_id, product_id
) src ON src.location_id = t.from_location_id AND src.product_id = sb.product_id
SET sb.rate_per_unit = ROUND(src.rate, 2),
    sb.updated_at    = sb.updated_at
WHERE sb.transaction_type = 'transfer_in' AND sb.rate_per_unit = 0 AND src.rate > 0;

-- 3b. The detail lines behind them, plus the 27 still in transit so they
--     receive at a real cost. Drafts are left for the code fix to handle.
UPDATE stock_transfer_details td
JOIN stock_transfers t ON t.id = td.transfer_id
JOIN (
    SELECT location_id, product_id,
           SUM(quantity_in * rate_per_unit) / NULLIF(SUM(quantity_in), 0) AS rate
    FROM stock_batches WHERE rate_per_unit > 0
    GROUP BY location_id, product_id
) src ON src.location_id = t.from_location_id AND src.product_id = td.product_id
SET td.rate_per_unit = ROUND(src.rate, 2)
WHERE td.rate_per_unit = 0
  AND t.status IN ('received', 'in_transit')
  AND src.rate > 0;


-- ---------------------------------------------------------------------
-- STEP 4 -- VERIFY.
-- ---------------------------------------------------------------------
-- 4a. Zero-valued transfer stock remaining: should be 0 (or only the
--     unrecoverable rows counted in 0c).
SELECT COUNT(*) AS zero_rate_batches_left
FROM stock_batches WHERE transaction_type = 'transfer_in' AND rate_per_unit = 0;

-- 4b. Zero-rate lines left, by status. 'draft' rows SHOULD still be listed --
--     they are fixed automatically when dispatched. received/in_transit
--     should be 0.
SELECT t.status, SUM(td.rate_per_unit = 0) AS zero_rate
FROM stock_transfer_details td
JOIN stock_transfers t ON t.id = td.transfer_id
GROUP BY t.status;

-- 4c. Nothing outside transfer_in was touched: every other type must still
--     show zero_rate = 0, exactly as before.
SELECT transaction_type, COUNT(*) AS rows_, SUM(rate_per_unit = 0) AS zero_rate
FROM stock_batches GROUP BY transaction_type;

-- 4d. No row was stamped as edited today by this script: must be 0.
SELECT COUNT(*) AS batches_stamped_today
FROM stock_batches
WHERE transaction_type = 'transfer_in' AND DATE(updated_at) = CURDATE();

-- 4e. The destinations that gained value.
SELECT l.name AS destination, COUNT(*) AS batches,
       ROUND(SUM(sb.quantity_in * sb.rate_per_unit), 2) AS value_now
FROM stock_batches sb
LEFT JOIN locations l ON l.id = sb.location_id
JOIN stock_batches_zero_rate_backup b ON b.id = sb.id
GROUP BY sb.location_id ORDER BY value_now DESC;


-- ---------------------------------------------------------------------
-- STEP 5 -- ROLLBACK, only if you need to undo.
-- ---------------------------------------------------------------------
-- UPDATE stock_batches sb
-- JOIN stock_batches_zero_rate_backup b ON b.id = sb.id
-- SET sb.rate_per_unit = b.rate_per_unit, sb.updated_at = b.updated_at;
--
-- UPDATE stock_transfer_details td
-- JOIN transfer_details_zero_rate_backup b ON b.id = td.id
-- SET td.rate_per_unit = b.rate_per_unit;

-- Once you are satisfied:
-- DROP TABLE stock_batches_zero_rate_backup;
-- DROP TABLE transfer_details_zero_rate_backup;
