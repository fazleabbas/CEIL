-- =====================================================================
-- PREVIEW for fix C (read-only -- changes nothing).
--
-- Fix C stops valuing a location's stock at a rate averaged across EVERY
-- location holding that product, and values it at that location's own
-- batches instead. Run this BEFORE uploading the five PHP files to see
-- exactly which locations move and by how much.
--
-- old_value = what the pages show today (blended product-wide rate)
-- new_value = what they will show after the fix (each location's own rate)
-- =====================================================================
SELECT l.name AS location,
       ROUND(o.v, 2)            AS old_value,
       ROUND(n.v, 2)            AS new_value,
       ROUND(n.v - o.v, 2)      AS change_,
       CASE WHEN o.v <> 0 THEN ROUND((n.v - o.v) / ABS(o.v) * 100, 1) END AS change_pct
FROM (
    SELECT net.location_id, COALESCE(SUM(net.net_qty * COALESCE(sbp.avg_rate, 0)), 0) AS v
    FROM (
        SELECT location_id, product_id, SUM(signed_qty) AS net_qty
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
        GROUP BY location_id, product_id
    ) net
    JOIN products p ON p.id = net.product_id AND p.is_active = 1
    LEFT JOIN (SELECT product_id, SUM(quantity_in*rate_per_unit)/NULLIF(SUM(quantity_in),0) AS avg_rate
               FROM stock_batches WHERE batch_date <= CURDATE() GROUP BY product_id) sbp
           ON sbp.product_id = net.product_id
    GROUP BY net.location_id
) o
JOIN (
    SELECT net.location_id,
           COALESCE(SUM(net.net_qty * COALESCE(NULLIF(sbl.avg_rate, 0), sbp.avg_rate, 0)), 0) AS v
    FROM (
        SELECT location_id, product_id, SUM(signed_qty) AS net_qty
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
        GROUP BY location_id, product_id
    ) net
    JOIN products p ON p.id = net.product_id AND p.is_active = 1
    LEFT JOIN (SELECT product_id, location_id, SUM(quantity_in*rate_per_unit)/NULLIF(SUM(quantity_in),0) AS avg_rate
               FROM stock_batches WHERE batch_date <= CURDATE() GROUP BY product_id, location_id) sbl
           ON sbl.product_id = net.product_id AND sbl.location_id = net.location_id
    LEFT JOIN (SELECT product_id, SUM(quantity_in*rate_per_unit)/NULLIF(SUM(quantity_in),0) AS avg_rate
               FROM stock_batches WHERE batch_date <= CURDATE() GROUP BY product_id) sbp
           ON sbp.product_id = net.product_id
    GROUP BY net.location_id
) n ON n.location_id = o.location_id
LEFT JOIN locations l ON l.id = o.location_id
ORDER BY ABS(n.v - o.v) DESC;
