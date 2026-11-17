-- Migration: 0042_widen_settings_value_for_banner
-- Description: Widen settings.setting_value from TEXT to LONGTEXT so the company
--              print banner (setting key `company_banner`) can live in it.
-- Date: 2026-08-08
--
-- WHY a data URL and not a file in uploads/:
-- the banner is stored as a base64 image data URL, exactly like users.signature
-- (migration 0041) and the *_signature columns on documents. Browsers routinely
-- skip externally-referenced images when printing, and this app also runs
-- offline as a PWA — a src="uploads/banner.png" would silently print blank in
-- both cases, which is precisely the case this feature exists for. UPLOAD_PATH
-- has no directory on disk anyway.
--
-- A downscaled colour banner is ~100-400 KB base64, well over TEXT's 65,535
-- byte ceiling, hence LONGTEXT. This is a WIDENING change only: TEXT is a strict
-- subset of LONGTEXT, so no existing row can be truncated or altered by it, and
-- every existing read/write of the table keeps working untouched.
--
-- Idempotent: re-running is a no-op once DATA_TYPE is already longtext.
--
-- NOTE: the banner is deliberately NOT read through getSetting(). That helper
-- loads the ENTIRE settings table into memory on first call, so a banner row
-- would be pulled into every single page request. getCompanyBanner() in
-- includes/functions.php fetches it on its own, and getSetting()'s bulk query
-- excludes the key. Nothing else in the app reads `company_banner`.
--
-- NOTE: keep statement separators out of these comments. executeMigration()
-- in api/run-migrations.php splits the file on the separator character BEFORE
-- it strips comments, so a stray one inside a comment breaks the migration on
-- any tenant that takes the PDO path instead of the mysql CLI path.

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'settings'
       AND COLUMN_NAME = 'setting_value' AND DATA_TYPE = 'longtext') = 0,
    'ALTER TABLE `settings` MODIFY COLUMN `setting_value` LONGTEXT NULL DEFAULT NULL',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Seed the branding keys the print letterhead reads. company_name is normally
-- already there; company_address / company_phone are read by the voucher
-- printables but were never seeded, so they only existed if someone INSERTed
-- them by hand. INSERT IGNORE relies on the UNIQUE key on setting_key and so
-- never overwrites a value a tenant has already set.
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES
    ('company_name',    'Company Name', 'Company name shown on printed documents'),
    ('company_address', '',             'Company address shown on printed documents'),
    ('company_phone',   '',             'Company phone shown on printed documents'),
    ('company_banner',  NULL,           'Base64 data URL of the letterhead banner printed on outward-facing documents');
