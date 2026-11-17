-- =====================================================================
-- MOVE ALL ACTIVITY FROM LOCATION 88 -> LOCATION 19
--
--   FROM : 88  "AKEST Containers"   (created by mistake)
--   TO   : 19  "AKEST Store"        (where it should have been)
--
-- Run the sections IN ORDER. Sections 1-3 are READ-ONLY; nothing is
-- written until section 5. Do not skip section 4 (the snapshot) -- it is
-- the only way back.
--
-- SCOPE: rows whose location is EXACTLY 88. The four CHILD locations of
-- 88 (33, 34, 35, 36) are NOT touched -- see section 8b.
--
-- The general ledger is NOT affected: transactions / transaction_details
-- carry no location column, so no figure in any financial report moves.
-- What moves is stock location and document destination.
-- =====================================================================

SET @FROM_LOC := 88;
SET @TO_LOC   := 19;


-- =====================================================================
-- SECTION 1 -- SANITY: both locations must exist
-- =====================================================================
SELECT id, name, parent_id, is_active
FROM locations
WHERE id IN (@FROM_LOC, @TO_LOC);


-- =====================================================================
-- SECTION 2 -- BEFORE COUNTS (keep this output; section 7 must mirror it)
-- =====================================================================
SELECT 'stock_batches' AS table_name, COUNT(*) AS rows_on_88 FROM stock_batches WHERE location_id = @FROM_LOC
UNION ALL SELECT 'purchases',              COUNT(*) FROM purchases            WHERE location_id             = @FROM_LOC
UNION ALL SELECT 'purchase_imports',       COUNT(*) FROM purchase_imports     WHERE location_id             = @FROM_LOC
UNION ALL SELECT 'purchase_orders',        COUNT(*) FROM purchase_orders      WHERE location_id             = @FROM_LOC
UNION ALL SELECT 'sales',                  COUNT(*) FROM sales                WHERE location_id             = @FROM_LOC
UNION ALL SELECT 'consumption_vouchers',   COUNT(*) FROM consumption_vouchers WHERE location_id             = @FROM_LOC
UNION ALL SELECT 'stock_adjustments',      COUNT(*) FROM stock_adjustments    WHERE location_id             = @FROM_LOC
UNION ALL SELECT 'stock_taking',           COUNT(*) FROM stock_taking         WHERE location_id             = @FROM_LOC
UNION ALL SELECT 'stock_transfers (from)', COUNT(*) FROM stock_transfers      WHERE from_location_id        = @FROM_LOC
UNION ALL SELECT 'stock_transfers (to)',   COUNT(*) FROM stock_transfers      WHERE to_location_id          = @FROM_LOC
UNION ALL SELECT 'requisition_vouchers',   COUNT(*) FROM requisition_vouchers WHERE destination_location_id = @FROM_LOC
UNION ALL SELECT 'budgets',                COUNT(*) FROM budgets              WHERE location_id             = @FROM_LOC
UNION ALL SELECT 'boms',                   COUNT(*) FROM boms                 WHERE location_id             = @FROM_LOC
UNION ALL SELECT 'boqs',                   COUNT(*) FROM boqs                 WHERE location_id             = @FROM_LOC
UNION ALL SELECT 'opening_stocks',         COUNT(*) FROM opening_stocks       WHERE location_id             = @FROM_LOC
UNION ALL SELECT 'payments',               COUNT(*) FROM payments             WHERE location_id             = @FROM_LOC
UNION ALL SELECT 'receipts',               COUNT(*) FROM receipts             WHERE location_id             = @FROM_LOC
UNION ALL SELECT 'product_pricing',        COUNT(*) FROM product_pricing      WHERE location_id             = @FROM_LOC
UNION ALL SELECT 'user_locations',         COUNT(*) FROM user_locations       WHERE location_id             = @FROM_LOC;


-- =====================================================================
-- SECTION 3 -- PRE-FLIGHT CHECKS. All three must be handled before
--              section 5 runs.
-- =====================================================================

-- 3a. SELF-TRANSFERS. A transfer with 88 on one side and 19 on the other
--     becomes "19 -> 19" once the move is done, which is not a real
--     document. These rows are DELIBERATELY EXCLUDED from section 5 and
--     are left pointing at 88, so you can decide per document.
--     (On the dev copy this is exactly one row: TRF000226, id 230,
--      status draft, 19 -> 88.)
SELECT id, transfer_number, transfer_date, from_location_id, to_location_id,
       status, 'WOULD BECOME A SELF-TRANSFER -- handled in 8a' AS note
FROM stock_transfers
WHERE (from_location_id = @FROM_LOC AND to_location_id = @TO_LOC)
   OR (from_location_id = @TO_LOC   AND to_location_id = @FROM_LOC);

-- 3b. UNIQUE-KEY COLLISION: opening_stocks is UNIQUE (location_id, product_id).
--     Any row here means BOTH locations hold an opening stock for the same
--     product; the move would fail with a duplicate-key error. MUST RETURN
--     ZERO ROWS. If it does not, merge those products by hand first.
SELECT o88.product_id, o88.quantity AS qty_on_88, o19.quantity AS qty_on_19
FROM opening_stocks o88
JOIN opening_stocks o19
  ON o19.product_id = o88.product_id AND o19.location_id = @TO_LOC
WHERE o88.location_id = @FROM_LOC;

-- 3c. UNIQUE-KEY COLLISION: product_pricing is UNIQUE (product_id, location_id).
--     Rows here are resolved by section 5, which DROPS the location-88
--     price and keeps the price already set on 19. Review the list before
--     letting it do that.
SELECT p88.product_id, p88.id AS pricing_id_on_88, p19.id AS pricing_id_on_19
FROM product_pricing p88
JOIN product_pricing p19
  ON p19.product_id = p88.product_id AND p19.location_id = @TO_LOC
WHERE p88.location_id = @FROM_LOC;


-- =====================================================================
-- SECTION 4 -- SNAPSHOT (REQUIRED). Full copies of every row about to
--              change, so the move can be undone. Keep these tables
--              until you are satisfied; section 9 drops them.
-- =====================================================================
DROP TABLE IF EXISTS bak88_stock_batches;
DROP TABLE IF EXISTS bak88_purchases;
DROP TABLE IF EXISTS bak88_purchase_imports;
DROP TABLE IF EXISTS bak88_purchase_orders;
DROP TABLE IF EXISTS bak88_sales;
DROP TABLE IF EXISTS bak88_consumption_vouchers;
DROP TABLE IF EXISTS bak88_stock_adjustments;
DROP TABLE IF EXISTS bak88_stock_taking;
DROP TABLE IF EXISTS bak88_stock_transfers;
DROP TABLE IF EXISTS bak88_requisition_vouchers;
DROP TABLE IF EXISTS bak88_budgets;
DROP TABLE IF EXISTS bak88_boms;
DROP TABLE IF EXISTS bak88_boqs;
DROP TABLE IF EXISTS bak88_opening_stocks;
DROP TABLE IF EXISTS bak88_payments;
DROP TABLE IF EXISTS bak88_receipts;
DROP TABLE IF EXISTS bak88_product_pricing;
DROP TABLE IF EXISTS bak88_user_locations;

CREATE TABLE bak88_stock_batches        AS SELECT * FROM stock_batches        WHERE location_id             = @FROM_LOC;
CREATE TABLE bak88_purchases            AS SELECT * FROM purchases            WHERE location_id             = @FROM_LOC;
CREATE TABLE bak88_purchase_imports     AS SELECT * FROM purchase_imports     WHERE location_id             = @FROM_LOC;
CREATE TABLE bak88_purchase_orders      AS SELECT * FROM purchase_orders      WHERE location_id             = @FROM_LOC;
CREATE TABLE bak88_sales                AS SELECT * FROM sales                WHERE location_id             = @FROM_LOC;
CREATE TABLE bak88_consumption_vouchers AS SELECT * FROM consumption_vouchers WHERE location_id             = @FROM_LOC;
CREATE TABLE bak88_stock_adjustments    AS SELECT * FROM stock_adjustments    WHERE location_id             = @FROM_LOC;
CREATE TABLE bak88_stock_taking         AS SELECT * FROM stock_taking         WHERE location_id             = @FROM_LOC;
CREATE TABLE bak88_requisition_vouchers AS SELECT * FROM requisition_vouchers WHERE destination_location_id = @FROM_LOC;
CREATE TABLE bak88_budgets              AS SELECT * FROM budgets              WHERE location_id             = @FROM_LOC;
CREATE TABLE bak88_boms                 AS SELECT * FROM boms                 WHERE location_id             = @FROM_LOC;
CREATE TABLE bak88_boqs                 AS SELECT * FROM boqs                 WHERE location_id             = @FROM_LOC;
CREATE TABLE bak88_opening_stocks       AS SELECT * FROM opening_stocks       WHERE location_id             = @FROM_LOC;
CREATE TABLE bak88_payments             AS SELECT * FROM payments             WHERE location_id             = @FROM_LOC;
CREATE TABLE bak88_receipts             AS SELECT * FROM receipts             WHERE location_id             = @FROM_LOC;
CREATE TABLE bak88_product_pricing      AS SELECT * FROM product_pricing      WHERE location_id             = @FROM_LOC;
CREATE TABLE bak88_user_locations       AS SELECT * FROM user_locations       WHERE location_id             = @FROM_LOC;
CREATE TABLE bak88_stock_transfers      AS SELECT * FROM stock_transfers
                                            WHERE from_location_id = @FROM_LOC OR to_location_id = @FROM_LOC;


-- =====================================================================
-- SECTION 5 -- THE MOVE. One transaction: it all lands or none of it does.
--
-- EVERY update on a table carrying ON UPDATE CURRENT_TIMESTAMP reassigns
-- updated_at = updated_at. Without that, a pure location correction would
-- restamp every one of these documents as edited today.
-- (Those tables: stock_batches, purchase_orders, requisition_vouchers,
--  budgets, stock_transfers, locations.)
-- =====================================================================
START TRANSACTION;

-- ---- Stock. These rows ARE the stock on hand; after this the quantity
-- ---- shows under location 19. batch_date is untouched, so the FIFO
-- ---- order of the merged stack stays chronological.
UPDATE stock_batches
   SET location_id = @TO_LOC, updated_at = updated_at
 WHERE location_id = @FROM_LOC;

-- ---- Transaction documents
UPDATE purchases            SET location_id = @TO_LOC WHERE location_id = @FROM_LOC;
UPDATE purchase_imports     SET location_id = @TO_LOC WHERE location_id = @FROM_LOC;
UPDATE sales                SET location_id = @TO_LOC WHERE location_id = @FROM_LOC;
UPDATE consumption_vouchers SET location_id = @TO_LOC WHERE location_id = @FROM_LOC;
UPDATE stock_adjustments    SET location_id = @TO_LOC WHERE location_id = @FROM_LOC;
UPDATE stock_taking         SET location_id = @TO_LOC WHERE location_id = @FROM_LOC;
UPDATE boms                 SET location_id = @TO_LOC WHERE location_id = @FROM_LOC;
UPDATE boqs                 SET location_id = @TO_LOC WHERE location_id = @FROM_LOC;
UPDATE payments             SET location_id = @TO_LOC WHERE location_id = @FROM_LOC;
UPDATE receipts             SET location_id = @TO_LOC WHERE location_id = @FROM_LOC;

UPDATE purchase_orders
   SET location_id = @TO_LOC, updated_at = updated_at
 WHERE location_id = @FROM_LOC;

UPDATE budgets
   SET location_id = @TO_LOC, updated_at = updated_at
 WHERE location_id = @FROM_LOC;

UPDATE requisition_vouchers
   SET destination_location_id = @TO_LOC, updated_at = updated_at
 WHERE destination_location_id = @FROM_LOC;

-- ---- Transfers. The second condition on each is what stops a 19 <-> 88
-- ---- document collapsing into "19 -> 19" (see 3a).
-- ---- Transfers still in draft / in_transit will now DELIVER into 19.
UPDATE stock_transfers
   SET from_location_id = @TO_LOC, updated_at = updated_at
 WHERE from_location_id = @FROM_LOC
   AND to_location_id  <> @TO_LOC;

UPDATE stock_transfers
   SET to_location_id = @TO_LOC, updated_at = updated_at
 WHERE to_location_id   = @FROM_LOC
   AND from_location_id <> @TO_LOC;

-- ---- Opening stock: safe to move outright once 3b returned no rows.
UPDATE opening_stocks SET location_id = @TO_LOC WHERE location_id = @FROM_LOC;

-- ---- Product pricing is UNIQUE (product_id, location_id): drop the
-- ---- location-88 price where 19 already prices that product, then move
-- ---- the rest. Keeps the price the real location was already using.
DELETE p88 FROM product_pricing p88
JOIN product_pricing p19
  ON p19.product_id = p88.product_id AND p19.location_id = @TO_LOC
WHERE p88.location_id = @FROM_LOC;

UPDATE product_pricing SET location_id = @TO_LOC WHERE location_id = @FROM_LOC;

-- ---- User access. Same shape: drop the 88 grant for anyone who can
-- ---- already reach 19 (otherwise they get a duplicate row -- this table
-- ---- has NO unique key to stop it), then move whoever is left.
DELETE ul88 FROM user_locations ul88
JOIN user_locations ul19
  ON ul19.user_id = ul88.user_id AND ul19.location_id = @TO_LOC
WHERE ul88.location_id = @FROM_LOC;

UPDATE user_locations SET location_id = @TO_LOC WHERE location_id = @FROM_LOC;

COMMIT;


-- =====================================================================
-- SECTION 6 -- AUDIT TRAIL (optional but recommended). Records that this
--              correction happened, so the change is explainable later.
--              Set user_id to whoever authorised it.
-- =====================================================================
-- INSERT INTO audit_logs (user_id, module, action, record_id, details, created_at)
-- VALUES (1, 'locations', 'merge', 88,
--         'Location 88 (AKEST Containers) created in error -- all activity moved to location 19 (AKEST Store)',
--         NOW());


-- =====================================================================
-- SECTION 7 -- VERIFY. Every count must now be 0, EXCEPT stock_transfers,
--              which keeps only the self-transfer rows listed in 3a.
-- =====================================================================
SELECT 'rows still on 88' AS check_name,
       (SELECT COUNT(*) FROM stock_batches        WHERE location_id             = @FROM_LOC)
     + (SELECT COUNT(*) FROM purchases            WHERE location_id             = @FROM_LOC)
     + (SELECT COUNT(*) FROM purchase_imports     WHERE location_id             = @FROM_LOC)
     + (SELECT COUNT(*) FROM purchase_orders      WHERE location_id             = @FROM_LOC)
     + (SELECT COUNT(*) FROM sales                WHERE location_id             = @FROM_LOC)
     + (SELECT COUNT(*) FROM consumption_vouchers WHERE location_id             = @FROM_LOC)
     + (SELECT COUNT(*) FROM stock_adjustments    WHERE location_id             = @FROM_LOC)
     + (SELECT COUNT(*) FROM stock_taking         WHERE location_id             = @FROM_LOC)
     + (SELECT COUNT(*) FROM requisition_vouchers WHERE destination_location_id = @FROM_LOC)
     + (SELECT COUNT(*) FROM budgets              WHERE location_id             = @FROM_LOC)
     + (SELECT COUNT(*) FROM boms                 WHERE location_id             = @FROM_LOC)
     + (SELECT COUNT(*) FROM boqs                 WHERE location_id             = @FROM_LOC)
     + (SELECT COUNT(*) FROM opening_stocks       WHERE location_id             = @FROM_LOC)
     + (SELECT COUNT(*) FROM payments             WHERE location_id             = @FROM_LOC)
     + (SELECT COUNT(*) FROM receipts             WHERE location_id             = @FROM_LOC)
     + (SELECT COUNT(*) FROM product_pricing      WHERE location_id             = @FROM_LOC)
     + (SELECT COUNT(*) FROM user_locations       WHERE location_id             = @FROM_LOC)
       AS must_be_zero,
       (SELECT COUNT(*) FROM stock_transfers
         WHERE from_location_id = @FROM_LOC OR to_location_id = @FROM_LOC)
       AS transfers_left_on_88_self_transfers_only;

-- No transfer may point at itself. MUST RETURN ZERO ROWS.
SELECT id, transfer_number, from_location_id, to_location_id,
       'SELF-TRANSFER -- MUST BE ZERO ROWS' AS problem
FROM stock_transfers
WHERE from_location_id = to_location_id;

-- Stock now standing at 19. The products that came off 88 should appear
-- here, added onto whatever 19 already held.
SELECT sb.product_id, p.name AS product_name,
       SUM(sb.quantity_remaining) AS qty_remaining_on_19
FROM stock_batches sb
JOIN products p ON p.id = sb.product_id
WHERE sb.location_id = @TO_LOC
GROUP BY sb.product_id, p.name
ORDER BY p.name;


-- =====================================================================
-- SECTION 8 -- LEFTOVERS. Decide each one; NOT done automatically.
-- =====================================================================

-- 8a. The self-transfer(s) from 3a still point at 88. Pick ONE:
--     (i) delete the document -- only safe while it is still draft,
--         because a received transfer has already written stock_batches:
-- DELETE FROM stock_transfer_details WHERE transfer_id = 230;
-- DELETE FROM stock_transfers        WHERE id = 230 AND status = 'draft';
--     (ii) or leave it and cancel it in the app.

-- 8b. Location 88 has FOUR CHILD locations. They hold NO transactions,
--     only user-access rows, and are NOT moved by this script. If 88 is
--     ever deleted they become root-level (FK is ON DELETE SET NULL), so
--     re-parent them first if you want them under 19.
SELECT id, name, parent_id FROM locations WHERE parent_id = @FROM_LOC;
-- UPDATE locations SET parent_id = @TO_LOC, updated_at = updated_at WHERE parent_id = @FROM_LOC;

-- 8c. Retire location 88 so nobody posts to it again. DEACTIVATE rather
--     than DELETE -- every FK above is ON DELETE RESTRICT, and the row is
--     still the safest thing for the audit trail to point at.
-- UPDATE locations SET is_active = 0, updated_at = updated_at WHERE id = @FROM_LOC;


-- =====================================================================
-- SECTION 9 -- CLEANUP. Only after the app has been checked and looks right.
-- =====================================================================
-- DROP TABLE bak88_stock_batches, bak88_purchases, bak88_purchase_imports,
--            bak88_purchase_orders, bak88_sales, bak88_consumption_vouchers,
--            bak88_stock_adjustments, bak88_stock_taking, bak88_stock_transfers,
--            bak88_requisition_vouchers, bak88_budgets, bak88_boms, bak88_boqs,
--            bak88_opening_stocks, bak88_payments, bak88_receipts,
--            bak88_product_pricing, bak88_user_locations;
