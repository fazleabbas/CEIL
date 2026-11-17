-- Migration: 0041_add_signature_to_users
-- Description: Give every user a signature on file in the users master, so the
--              e-signature slots on documents no longer have to be drawn by
--              hand on every single voucher.
--
--              Until now each signature was drawn on a canvas at the moment of
--              signing (assets/js/signature_pad.js) and stored as a base64 PNG
--              data URL on the document row itself — requisition_vouchers,
--              stock_transfers, stock_taking, budgets and purchase_orders all
--              have their own *_signature columns. That stays exactly as it is:
--              documents keep their own snapshot, so re-uploading a signature
--              later never rewrites history. This column is only the SOURCE the
--              snapshot is taken from when nothing was drawn.
-- Date: 2026-08-01
--
-- Additive and nullable. Existing rows and every `SELECT * FROM users` are
-- unaffected, and tenants that have not run this migration keep working —
-- getUserSignature() in includes/functions.php swallows the missing-column
-- error and returns null, which makes every signature pad behave exactly as it
-- did before.
--
-- Holds the same base64 PNG data-URL format as the existing *_signature
-- columns. Uploads are normalised (downscaled + re-encoded to PNG) by
-- modules/masters/users_form.php before they get here.
--
-- NOTE: keep statement separators out of these comments. executeMigration()
-- in api/run-migrations.php splits the file on the separator character BEFORE
-- it strips comments, so a stray one inside a comment breaks the migration on
-- any tenant that takes the PDO path instead of the mysql CLI path.

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'signature') = 0,
    'ALTER TABLE `users` ADD COLUMN `signature` LONGTEXT NULL DEFAULT NULL AFTER `phone`',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
