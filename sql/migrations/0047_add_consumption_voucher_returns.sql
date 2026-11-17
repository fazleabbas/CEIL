-- Migration: 0047_add_consumption_voucher_returns
-- Description: Let material issued on a consumption voucher be returned to
--              stock as its own dated document, instead of being edited out of
--              the original voucher.
--
--              Editing the original rewrites history: the voucher then claims
--              only the net quantity ever went out, the return date is lost,
--              and the stock movement is backdated to the original document.
-- Date: 2026-09-21
--
-- A return is an ordinary `consumption_vouchers` row carrying NEGATIVE
-- quantities and flagged with return_of_voucher_id. That shape is deliberate:
-- consumption is aggregated as a plain SUM(quantity) in eight report files
-- (stock_report, stock_report_by_type, product_stock_card,
-- product_location_breakdown, cost_centre_report, cost_centre_comparison and
-- others). Negative rows net out there automatically, so NONE of those reports
-- had to be touched. A separate returns table would have been invisible to all
-- of them and would have left consumption permanently overstated.
--
-- Stock is given back on the exact batches it was drawn from, in reverse order
-- of consumption. The return writes its own rows into
-- `consumption_voucher_batches` with NEGATIVE quantity against the same
-- batch_id, which means cvRestoreStock() in includes/functions.php reverses a
-- deleted return correctly with no change: it adds the link quantity back, and
-- a negative link subtracts the stock the return had given.
--
-- ON DELETE RESTRICT on return_of_voucher_id is a safety net beneath the
-- application gate. Deleting a voucher that has been partly returned would
-- restore the FULL issued quantity on top of what already came back, leaving
-- more in stock than ever went out.
--
-- Both columns are nullable and every existing row keeps NULL, so ordinary
-- consumption vouchers behave exactly as before.
--
-- NOTE: keep statement separators out of these comments. executeMigration()
-- in api/run-migrations.php splits the file on the separator character BEFORE
-- it strips comments, so a stray one inside a comment breaks the migration on
-- any tenant that takes the PDO path instead of the mysql CLI path.

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consumption_vouchers' AND COLUMN_NAME = 'return_of_voucher_id') = 0,
    'ALTER TABLE `consumption_vouchers` ADD COLUMN `return_of_voucher_id` INT(11) NULL DEFAULT NULL COMMENT "Set when this voucher is a return against another voucher" AFTER `project_manager_signature`',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consumption_vouchers' AND INDEX_NAME = 'idx_cv_return_of') = 0,
    'ALTER TABLE `consumption_vouchers` ADD KEY `idx_cv_return_of` (`return_of_voucher_id`)',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consumption_vouchers' AND CONSTRAINT_NAME = 'fk_cv_return_of') = 0,
    'ALTER TABLE `consumption_vouchers` ADD CONSTRAINT `fk_cv_return_of` FOREIGN KEY (`return_of_voucher_id`) REFERENCES `consumption_vouchers` (`id`) ON DELETE RESTRICT',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consumption_voucher_details' AND COLUMN_NAME = 'return_of_detail_id') = 0,
    'ALTER TABLE `consumption_voucher_details` ADD COLUMN `return_of_detail_id` INT(11) NULL DEFAULT NULL COMMENT "Original voucher line this line returns against" AFTER `remarks`',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consumption_voucher_details' AND INDEX_NAME = 'idx_cvd_return_of') = 0,
    'ALTER TABLE `consumption_voucher_details` ADD KEY `idx_cvd_return_of` (`return_of_detail_id`)',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consumption_voucher_details' AND CONSTRAINT_NAME = 'fk_cvd_return_of') = 0,
    'ALTER TABLE `consumption_voucher_details` ADD CONSTRAINT `fk_cvd_return_of` FOREIGN KEY (`return_of_detail_id`) REFERENCES `consumption_voucher_details` (`id`) ON DELETE RESTRICT',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('cvr_prefix', 'CVR');
