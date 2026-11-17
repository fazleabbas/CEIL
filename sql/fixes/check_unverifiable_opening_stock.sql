-- How much stock value rests on opening rates that NOTHING can corroborate
-- (products never purchased and never imported since).
SELECT COUNT(*) AS rows_, ROUND(SUM(os.quantity * os.rate_per_unit), 2) AS unverifiable_value
FROM opening_stocks os
WHERE NOT EXISTS (SELECT 1 FROM purchase_details pd WHERE pd.product_id = os.product_id)
  AND NOT EXISTS (SELECT 1 FROM purchase_import_details d WHERE d.product_id = os.product_id);

-- the biggest of them, worth an invoice check top-down
SELECT p.name AS product, l.name AS location, os.quantity, os.rate_per_unit,
       ROUND(os.quantity * os.rate_per_unit, 2) AS value
FROM opening_stocks os
JOIN products p ON p.id = os.product_id
LEFT JOIN locations l ON l.id = os.location_id
WHERE NOT EXISTS (SELECT 1 FROM purchase_details pd WHERE pd.product_id = os.product_id)
  AND NOT EXISTS (SELECT 1 FROM purchase_import_details d WHERE d.product_id = os.product_id)
ORDER BY os.quantity * os.rate_per_unit DESC
LIMIT 20;
