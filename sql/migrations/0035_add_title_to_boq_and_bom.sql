-- Migration: 0035_add_title_to_boq_and_bom
-- Description: Add an optional free-text `title` to the BOQ and BOM headers so each
--              document can carry a human-friendly name. The requisition voucher
--              BOM dropdown displays this title (falling back to bom_number when the
--              title is empty). Nullable, so existing rows and all existing flows
--              are unaffected.
-- Date: 2026-06-22

-- BOQ header title
ALTER TABLE `boqs`
  ADD COLUMN `title` varchar(255) DEFAULT NULL AFTER `boq_number`;

-- BOM header title
ALTER TABLE `boms`
  ADD COLUMN `title` varchar(255) DEFAULT NULL AFTER `bom_number`;
