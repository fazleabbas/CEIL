-- Migration: 0050_split_reports_permission_per_report
-- Description: Replace the single "reports" permission with one View right per
--              report. Everyone who holds "reports" today receives all nine new
--              rights, so nobody loses access, and the old row is then removed.
--
-- Idempotent: once the "reports" rows are gone, a re-run copies and deletes
-- nothing, so rights removed later in Masters -> Users are never given back.
-- Until this runs, canViewReport() keeps honouring the old row.

INSERT INTO user_permissions (user_id, module_name, can_view)
SELECT p.user_id, r.module_name, 1
FROM user_permissions p
CROSS JOIN (
    SELECT 'report_stock_report' AS module_name
    UNION ALL SELECT 'report_stock_report_by_type'
    UNION ALL SELECT 'report_dead_stock'
    UNION ALL SELECT 'report_stock_value'
    UNION ALL SELECT 'report_profit_loss'
    UNION ALL SELECT 'report_cost_centre_report'
    UNION ALL SELECT 'report_cost_centre_comparison'
    UNION ALL SELECT 'report_product_analysis'
    UNION ALL SELECT 'report_ledger_report'
) r
WHERE p.module_name = 'reports'
  AND p.can_view = 1
  AND NOT EXISTS (
      SELECT 1 FROM user_permissions x
      WHERE x.user_id = p.user_id AND x.module_name = r.module_name
  );

DELETE FROM user_permissions WHERE module_name = 'reports';
