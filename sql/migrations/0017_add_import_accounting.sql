-- Migration: 0017_add_import_accounting
-- Description: Add double-entry accounting + VAT to purchase imports.
--              Splits additional costs into Bucket A (supplier-borne, billed
--              on the supplier's invoice) and Bucket B (third-party costs,
--              each becoming its own journal voucher to a separate
--              beneficiary supplier). Schema-only; no code changes yet.
-- Date: 2026-05-17

-- =====================================================================
-- 1) Add vat_amount to purchase_imports (header-level VAT, like purchases)
-- =====================================================================
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_imports' AND COLUMN_NAME = 'vat_amount') = 0,
    'ALTER TABLE `purchase_imports` ADD COLUMN `vat_amount` decimal(15,2) NOT NULL DEFAULT 0.00 AFTER `total_landed_amount`',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =====================================================================
-- 2) Extend purchase_import_costs with Bucket A / B fields
--    All new columns are nullable so existing rows keep working.
-- =====================================================================

-- bucket: 'A' = supplier-borne (rolls up into the main DR Purchase / CR Supplier
--                                transaction), 'B' = third-party (each line is its
--                                own journal voucher to a separate beneficiary).
-- Existing rows default to 'B' (they're conceptually independent costs that did
-- NOT previously affect the supplier ledger).
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_import_costs' AND COLUMN_NAME = 'bucket') = 0,
    "ALTER TABLE `purchase_import_costs` ADD COLUMN `bucket` ENUM('A','B') NOT NULL DEFAULT 'B' AFTER `import_id`",
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ledger_id: which expense/duties ledger this cost line debits
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_import_costs' AND COLUMN_NAME = 'ledger_id') = 0,
    'ALTER TABLE `purchase_import_costs` ADD COLUMN `ledger_id` int(11) DEFAULT NULL AFTER `description`',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- beneficiary_supplier_id: only used for Bucket B (NULL for Bucket A, which
-- credits the import's own supplier)
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_import_costs' AND COLUMN_NAME = 'beneficiary_supplier_id') = 0,
    'ALTER TABLE `purchase_import_costs` ADD COLUMN `beneficiary_supplier_id` int(11) DEFAULT NULL AFTER `ledger_id`',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- currency_id: per-line currency for Bucket B (NULL = inherit from import header)
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_import_costs' AND COLUMN_NAME = 'currency_id') = 0,
    'ALTER TABLE `purchase_import_costs` ADD COLUMN `currency_id` int(11) DEFAULT NULL AFTER `beneficiary_supplier_id`',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- exchange_rate_to_base: matches per-line currency
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_import_costs' AND COLUMN_NAME = 'exchange_rate_to_base') = 0,
    'ALTER TABLE `purchase_import_costs` ADD COLUMN `exchange_rate_to_base` decimal(18,8) DEFAULT 1.00000000 AFTER `currency_id`',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- vat_percent: optional VAT% on the cost line (Bucket B only; Bucket A VAT is
-- captured at the import header)
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_import_costs' AND COLUMN_NAME = 'vat_percent') = 0,
    'ALTER TABLE `purchase_import_costs` ADD COLUMN `vat_percent` decimal(6,3) DEFAULT NULL AFTER `exchange_rate_to_base`',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- vat_amount: computed amount in line currency, persisted for clarity
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_import_costs' AND COLUMN_NAME = 'vat_amount') = 0,
    'ALTER TABLE `purchase_import_costs` ADD COLUMN `vat_amount` decimal(15,2) NOT NULL DEFAULT 0.00 AFTER `vat_percent`',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- FKs for the new columns (idempotent — only added when missing)
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_import_costs' AND CONSTRAINT_NAME = 'pic_ledger_fk') = 0,
    'ALTER TABLE `purchase_import_costs` ADD CONSTRAINT `pic_ledger_fk` FOREIGN KEY (`ledger_id`) REFERENCES `ledger_accounts` (`id`) ON DELETE SET NULL',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_import_costs' AND CONSTRAINT_NAME = 'pic_beneficiary_fk') = 0,
    'ALTER TABLE `purchase_import_costs` ADD CONSTRAINT `pic_beneficiary_fk` FOREIGN KEY (`beneficiary_supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_import_costs' AND CONSTRAINT_NAME = 'pic_currency_fk') = 0,
    'ALTER TABLE `purchase_import_costs` ADD CONSTRAINT `pic_currency_fk` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`) ON DELETE SET NULL',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =====================================================================
-- 3) Extend transactions.voucher_type ENUM with 'purchase_import' + 'journal'
--    'purchase_import' = the main DR Purchase/DR VAT/DR Bucket-A costs/CR Supplier
--                        transaction for an import.
--    'journal'         = generic journal voucher. Used by Bucket B cost lines
--                        and (later) any manual JVs the user may post.
--    MODIFY is naturally idempotent — running twice is a no-op.
-- =====================================================================
ALTER TABLE `transactions`
  MODIFY COLUMN `voucher_type` ENUM('purchase','sales','receipt','payment','purchase_import','journal') NOT NULL;

-- =====================================================================
-- 4) Add source_voucher_* link columns to transactions
--    Lets a child voucher (e.g. Bucket B journal voucher) point back at its
--    parent (the purchase_import). Used to:
--      - Show "Source: IMP-0042" in the Journal Vouchers listing
--      - Cascade-delete every child JV when an import is deleted
--      - Rebuild child JVs cleanly when an import is edited
-- =====================================================================
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'source_voucher_type') = 0,
    "ALTER TABLE `transactions` ADD COLUMN `source_voucher_type` ENUM('purchase','sales','receipt','payment','purchase_import','journal') DEFAULT NULL AFTER `voucher_number`",
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'source_voucher_id') = 0,
    'ALTER TABLE `transactions` ADD COLUMN `source_voucher_id` int(11) DEFAULT NULL AFTER `source_voucher_type`',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Composite index for "find all child vouchers of X" lookups (used by both
-- the Journal Vouchers listing and import edit/delete cascade).
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND INDEX_NAME = 'idx_tx_source') = 0,
    'ALTER TABLE `transactions` ADD INDEX `idx_tx_source` (`source_voucher_type`, `source_voucher_id`)',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
