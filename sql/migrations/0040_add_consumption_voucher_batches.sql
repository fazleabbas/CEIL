-- Migration: 0040_add_consumption_voucher_batches
-- Description: Record WHICH stock batches each consumption voucher line drew
--              from, so a voucher can be edited (and deleted) by restoring the
--              exact batches it consumed.
--
--              Until now the FIFO loop in modules/transactions/consumption_voucher.php
--              decremented stock_batches.quantity_remaining without recording
--              the source batches, keeping only the resulting weighted
--              rate_per_unit on the detail row. That made a precise reversal
--              impossible, which is why the module had delete-only semantics
--              (delete inserts a NEW batch dated today at the weighted rate,
--              which restores quantity but distorts FIFO order and cost
--              layering) and no edit at all.
-- Date: 2026-07-27
--
-- Additive and consumption-only. Nothing reads this table for stock maths —
-- stock is still stock_batches.quantity_remaining — so sales, purchases,
-- transfers and adjustments are unaffected. Vouchers created BEFORE this
-- migration simply have no rows here; the delete path keeps its original
-- insert-a-new-batch fallback for them.

CREATE TABLE IF NOT EXISTS `consumption_voucher_batches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `voucher_id` int(11) NOT NULL,
  `detail_id` int(11) NOT NULL,
  `batch_id` int(11) NOT NULL COMMENT 'stock_batches row this quantity was drawn from',
  `quantity` decimal(15,3) NOT NULL COMMENT 'Quantity taken from this batch',
  `rate_per_unit` decimal(15,2) NOT NULL COMMENT 'Batch rate at consumption time',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cvb_voucher` (`voucher_id`),
  KEY `idx_cvb_detail` (`detail_id`),
  KEY `idx_cvb_batch` (`batch_id`),
  CONSTRAINT `fk_cvb_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `consumption_vouchers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cvb_detail` FOREIGN KEY (`detail_id`) REFERENCES `consumption_voucher_details` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cvb_batch` FOREIGN KEY (`batch_id`) REFERENCES `stock_batches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
