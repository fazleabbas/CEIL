-- Migration: 0039_add_reverse_transfers
-- Description: Add "Reverse Transfer" — returning goods that were previously
--              transferred to a location back to the source location. A reverse
--              transfer is stored as an ordinary row in `stock_transfers` (so it
--              appears in the transfers list and reuses the transfer view/print
--              and the draft -> in_transit -> received lifecycle), distinguished
--              only by a new nullable `reverse_of_transfer_id` pointing at the
--              original transfer. Its from/to locations are the original's
--              swapped, the cost centre is kept, and each line copies the
--              original line's `requisition_detail_id` so that when the reverse
--              is RECEIVED it can decrement `requisition_voucher_details.
--              transferred_quantity` — which restores the BOM balance and the
--              requisition BOM column live (both read that same field).
-- Date: 2026-07-24
--
-- Strictly additive. `reverse_of_transfer_id` is nullable so every existing
-- stock_transfers INSERT/SELECT flow is unaffected. Column + FK adds are
-- idempotent via INFORMATION_SCHEMA guards. No stock_batches enum change is
-- needed — a reverse reuses transfer_out (dispatch from destination) and
-- transfer_in (receive at source). No new permission module — a reverse is a
-- transfer and reuses the existing `stock_transfer` permissions.

-- =============================================
-- 1. Setting for reverse-transfer number prefix
-- =============================================
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `description`)
VALUES ('reverse_transfer_prefix', 'RT', 'Prefix for Reverse Transfer numbering (e.g. RT000001)');

-- =============================================
-- 2. Add nullable reverse_of_transfer_id to stock_transfers
-- =============================================
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'stock_transfers'
       AND COLUMN_NAME = 'reverse_of_transfer_id') = 0,
    'ALTER TABLE `stock_transfers` ADD COLUMN `reverse_of_transfer_id` INT(11) NULL DEFAULT NULL AFTER `to_location_id`, ADD KEY `idx_st_reverse_of` (`reverse_of_transfer_id`)',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =============================================
-- 3. Add the self-referencing foreign key (only if absent)
-- =============================================
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'stock_transfers'
       AND CONSTRAINT_NAME = 'st_reverse_of_fk') = 0,
    'ALTER TABLE `stock_transfers` ADD CONSTRAINT `st_reverse_of_fk` FOREIGN KEY (`reverse_of_transfer_id`) REFERENCES `stock_transfers` (`id`) ON DELETE SET NULL',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
