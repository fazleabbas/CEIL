-- =====================================================================
-- Highest-value stock, per product PER LOCATION, with where it came from.
-- Read-only. Use it to walk the biggest numbers with the storekeeper.
--
-- qty / value use the same formula as the stock value pages: quantity from
-- the movement tables, rate from that location's own batches (falling back
-- to the product-wide average where the location has no usable rate).
--
-- suppliers / documents list EVERY purchase and import of that product,
-- company-wide -- not only the ones that fed this location. Stock moves
-- between stores by transfer, so the goods in one store may have arrived on
-- a document booked to another.
-- =====================================================================
SELECT p.name                                   AS product,
       l.name                                   AS location,
       ROUND(net.net_qty, 3)                    AS qty,
       ROUND(net.net_qty * COALESCE(NULLIF(sbl.avg_rate,0), sbp.avg_rate, 0), 2) AS value,
       src.suppliers,
       src.documents
FROM (        SELECT location_id, product_id, SUM(signed_qty) AS net_qty
        FROM (
            SELECT location_id, product_id, quantity AS signed_qty
              FROM opening_stocks
              WHERE stock_date <= CURDATE()
            UNION ALL
            SELECT pu.location_id, pd.product_id, pd.quantity
              FROM purchase_details pd
              JOIN purchases pu ON pd.purchase_id = pu.id
              WHERE pu.purchase_date <= CURDATE()
            UNION ALL
            SELECT pi.location_id, pid.product_id, pid.quantity
              FROM purchase_import_details pid
              JOIN purchase_imports pi ON pid.import_id = pi.id
              WHERE pi.import_date <= CURDATE()
            UNION ALL
            SELECT s.location_id, sd.product_id, -sd.quantity
              FROM sale_details sd
              JOIN sales s ON sd.sale_id = s.id
              WHERE s.sale_date <= CURDATE()
            UNION ALL
            SELECT t.to_location_id AS location_id, td.product_id, td.received_quantity
              FROM stock_transfer_details td
              JOIN stock_transfers t ON td.transfer_id = t.id
              WHERE t.status = 'received' AND DATE(t.received_at) <= CURDATE()
            UNION ALL
            SELECT t.from_location_id AS location_id, td.product_id, -td.dispatched_quantity
              FROM stock_transfer_details td
              JOIN stock_transfers t ON td.transfer_id = t.id
              WHERE t.status IN ('in_transit', 'received') AND DATE(t.dispatched_at) <= CURDATE()
            UNION ALL
            SELECT sa.location_id, sad.product_id,
                   CASE WHEN sad.adjustment_type = 'increase' THEN sad.quantity ELSE -sad.quantity END
              FROM stock_adjustment_details sad
              JOIN stock_adjustments sa ON sad.adjustment_id = sa.id
              WHERE sa.adjustment_date <= CURDATE()
            UNION ALL
            SELECT cv.location_id, cvd.product_id, -cvd.quantity
              FROM consumption_voucher_details cvd
              JOIN consumption_vouchers cv ON cvd.voucher_id = cv.id
              WHERE cv.voucher_date <= CURDATE()
        ) movements
        GROUP BY location_id, product_id) net
JOIN products p  ON p.id = net.product_id AND p.is_active = 1
LEFT JOIN locations l ON l.id = net.location_id
LEFT JOIN (SELECT product_id, location_id, SUM(quantity_in*rate_per_unit)/NULLIF(SUM(quantity_in),0) AS avg_rate
           FROM stock_batches WHERE batch_date <= CURDATE() GROUP BY product_id, location_id) sbl
       ON sbl.product_id = net.product_id AND sbl.location_id = net.location_id
LEFT JOIN (SELECT product_id, SUM(quantity_in*rate_per_unit)/NULLIF(SUM(quantity_in),0) AS avg_rate
           FROM stock_batches WHERE batch_date <= CURDATE() GROUP BY product_id) sbp
       ON sbp.product_id = net.product_id
LEFT JOIN (
    SELECT product_id,
           GROUP_CONCAT(DISTINCT supplier ORDER BY supplier SEPARATOR ' | ') AS suppliers,
           GROUP_CONCAT(DISTINCT doc      ORDER BY doc      SEPARATOR ' | ') AS documents
    FROM (
        -- suppliers.name does not exist: the name lives on ledger_accounts
        SELECT pd.product_id, la.name AS supplier, pu.purchase_number AS doc
          FROM purchase_details pd
          JOIN purchases pu ON pu.id = pd.purchase_id
          LEFT JOIN suppliers s        ON s.id  = pu.supplier_id
          LEFT JOIN ledger_accounts la ON la.id = s.ledger_account_id
        UNION
        SELECT pid.product_id, la.name, pi.import_number
          FROM purchase_import_details pid
          JOIN purchase_imports pi ON pi.id = pid.import_id
          LEFT JOIN suppliers s        ON s.id  = pi.supplier_id
          LEFT JOIN ledger_accounts la ON la.id = s.ledger_account_id
    ) z GROUP BY product_id
) src ON src.product_id = net.product_id
WHERE net.net_qty <> 0
ORDER BY net.net_qty * COALESCE(NULLIF(sbl.avg_rate,0), sbp.avg_rate, 0) DESC
LIMIT 25;
