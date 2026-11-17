-- Migration: 0028_add_bucket_c_to_import_costs
-- Description: Add a third additional-cost bucket 'C' to purchase_import_costs.
--              Bucket C = "Other Costs (not added to costing)" — paid to a
--              third-party beneficiary and posted as its own journal voucher
--              (DR expense / DR VAT / CR beneficiary), exactly like Bucket B,
--              but EXCLUDED from the import's costing factor / landed cost.
--              Schema-only; no existing rows are touched.
-- Date: 2026-05-30

-- =====================================================================
-- Extend the bucket ENUM from ('A','B') to ('A','B','C').
-- MODIFY is naturally idempotent — re-running is a no-op. Default stays 'B'
-- so any code path that inserts without specifying a bucket is unchanged,
-- and all existing A/B rows keep their value.
-- =====================================================================
ALTER TABLE `purchase_import_costs`
  MODIFY COLUMN `bucket` ENUM('A','B','C') NOT NULL DEFAULT 'B';
