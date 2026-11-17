-- Migration: 0046_add_project_manager_to_consumption_vouchers
-- Description: Record the Project Manager / Engineer who authorises a material
--              consumption, so the printed voucher and its delivery note can
--              carry that person's name and signature.
--
--              Consumption vouchers have no approval workflow of any kind and
--              no signature column at all. The delivery note prints three
--              blank ruled boxes and the view page prints one Prepared By box
--              filled from the creator's saved signature. The person who
--              actually authorises material leaving a site is the project
--              manager or engineer, and they are frequently not a user of this
--              system, so the name is free text rather than a link to `users`.
-- Date: 2026-09-21
--
-- Additive and nullable. Every existing voucher keeps a NULL project manager,
-- and both printables render exactly as they do today for those rows because
-- the new sign-off box is only emitted when a name is present.
--
-- project_manager_signature holds the same base64 PNG data-URL format as the
-- existing *_signature columns on requisition_vouchers / stock_transfers /
-- budgets / purchase_orders. It is written ONLY from what is drawn on the pad
-- at entry time. It deliberately does NOT fall back to the creator's saved
-- signature the way requisition_voucher.php does for Prepared By: the preparer
-- there IS the logged-in user, whereas the project manager is a free-text
-- third party, and stamping the creator's e-signature into this box would
-- assert that someone signed who did not.
--
-- NOTE: keep statement separators out of these comments. executeMigration()
-- in api/run-migrations.php splits the file on the separator character BEFORE
-- it strips comments, so a stray one inside a comment breaks the migration on
-- any tenant that takes the PDO path instead of the mysql CLI path.

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consumption_vouchers' AND COLUMN_NAME = 'project_manager_name') = 0,
    'ALTER TABLE `consumption_vouchers` ADD COLUMN `project_manager_name` VARCHAR(150) NULL DEFAULT NULL AFTER `remarks`',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consumption_vouchers' AND COLUMN_NAME = 'project_manager_signature') = 0,
    'ALTER TABLE `consumption_vouchers` ADD COLUMN `project_manager_signature` LONGTEXT NULL DEFAULT NULL AFTER `project_manager_name`',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
