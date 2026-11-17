-- =====================================================================
-- Fix C drill-down: WHY does one location's value change?
-- Read-only. Edit the location_id on the line marked <<< EDIT.
--
-- own_rate      = rate from that location's own batches (what fix C will use)
-- blended_rate  = product-wide average across all locations (used today)
-- has_usable_own_rate = 0 means the location has no batch rows for that
--                 product OR its batches carry rate 0. Either way there is no
--                 cost recorded there, so it keeps the blended rate and does
--                 not move. A rate of 0 is NOT treated as "free".
-- =====================================================================
SELECT p.name AS product,
       net.net_qty                                   AS qty,
       ROUND(sbl.avg_rate, 2)                        AS own_rate,
       ROUND(sbp.avg_rate, 2)                        AS blended_rate,
       (NULLIF(sbl.avg_rate, 0) IS NOT NULL)         AS has_usable_own_rate,
       ROUND(net.net_qty * COALESCE(sbp.avg_rate,0), 2)                    AS old_value,
       ROUND(net.net_qty * COALESCE(NULLIF(sbl.avg_rate, 0), sbp.avg_rate, 0), 2)     AS new_value,
       ROUND(net.net_qty * (COALESCE(NULLIF(sbl.avg_rate, 0), sbp.avg_rate, 0)
                          - COALESCE(sbp.avg_rate, 0)), 2)                 AS change_
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
WHERE net.location_id = 19            -- <<< EDIT: the location to explain
  AND net.net_qty <> 0
ORDER BY ABS(net.net_qty * (COALESCE(NULLIF(sbl.avg_rate, 0), sbp.avg_rate, 0) - COALESCE(sbp.avg_rate, 0))) DESC
LIMIT 20;
