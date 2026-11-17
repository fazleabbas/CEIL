SELECT p.name AS product, l.name AS location,
       os.quantity, os.rate_per_unit AS opening_rate,
       ROUND(later.paid_rate, 2)                        AS later_paid_rate,
       ROUND(os.rate_per_unit / later.paid_rate, 1)     AS times_higher,
       ROUND(os.quantity * (os.rate_per_unit - later.paid_rate), 2) AS overstated_by
FROM opening_stocks os
JOIN products p  ON p.id = os.product_id
LEFT JOIN locations l ON l.id = os.location_id
JOIN (
    SELECT product_id, SUM(amt) / SUM(qty) AS paid_rate FROM (
        SELECT pd.product_id, SUM(pd.quantity) AS qty, SUM(pd.quantity * pd.rate_per_unit) AS amt
          FROM purchase_details pd GROUP BY pd.product_id
        UNION ALL
        SELECT d.product_id, SUM(d.quantity),
               SUM(d.quantity * d.landed_rate_per_unit * pi.exchange_rate_to_base)
          FROM purchase_import_details d
          JOIN purchase_imports pi ON pi.id = d.import_id GROUP BY d.product_id
    ) x GROUP BY product_id
) later ON later.product_id = os.product_id AND later.paid_rate > 0
WHERE os.rate_per_unit > later.paid_rate * 3
ORDER BY os.quantity * (os.rate_per_unit - later.paid_rate) DESC
LIMIT 15;
