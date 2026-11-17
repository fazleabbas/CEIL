-- Migration: 0052_decode_html_entities_in_master_data
-- Description: Master data was saved through clean(), which stores an HTML-encoded
--              copy, so screens showed the encoded text instead of the ampersand
--              or apostrophe that was typed. Those save paths now use cleanText().
--              This repairs the rows already stored.
-- Date: 2026-11-09
--
-- Ampersand, double quote and apostrophe entities are decoded. The ampersand is
-- decoded three times over, because editing an encoded value and saving it
-- encoded it again. Angle-bracket entities are deliberately left alone, so this
-- can never put a tag into the data.
--
-- UPDATE IGNORE: where a decoded name or code would collide with a UNIQUE value
-- that already exists, that one row is left as it is rather than merged or
-- failing the migration. updated_at is assigned to itself, so a repair does not
-- make every record look edited today.
--
-- NOTE: the entities are built with CHAR(59) on purpose. executeMigration() in
-- api/run-migrations.php splits this file on the statement separator without
-- looking inside strings, so that character cannot appear in a literal.

SET @amp = CONCAT('&amp', CHAR(59));
SET @quot = CONCAT('&quot', CHAR(59));
SET @apos = CONCAT('&#039', CHAR(59));

-- ---------------------------------------------------------------- locations
UPDATE IGNORE `locations` SET `name` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`name`, @amp, '&'), @amp, '&'), @amp, '&'), @quot, '"'), @apos, ''''), `updated_at` = `updated_at` WHERE INSTR(`name`, @amp) > 0 OR INSTR(`name`, @quot) > 0 OR INSTR(`name`, @apos) > 0;
UPDATE IGNORE `locations` SET `address` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`address`, @amp, '&'), @amp, '&'), @amp, '&'), @quot, '"'), @apos, ''''), `updated_at` = `updated_at` WHERE INSTR(`address`, @amp) > 0 OR INSTR(`address`, @quot) > 0 OR INSTR(`address`, @apos) > 0;
UPDATE IGNORE `locations` SET `phone` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`phone`, @amp, '&'), @amp, '&'), @amp, '&'), @quot, '"'), @apos, ''''), `updated_at` = `updated_at` WHERE INSTR(`phone`, @amp) > 0 OR INSTR(`phone`, @quot) > 0 OR INSTR(`phone`, @apos) > 0;

-- ---------------------------------------------------------------- cost_centres
UPDATE IGNORE `cost_centres` SET `code` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`code`, @amp, '&'), @amp, '&'), @amp, '&'), @quot, '"'), @apos, ''''), `updated_at` = `updated_at` WHERE INSTR(`code`, @amp) > 0 OR INSTR(`code`, @quot) > 0 OR INSTR(`code`, @apos) > 0;
UPDATE IGNORE `cost_centres` SET `name` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`name`, @amp, '&'), @amp, '&'), @amp, '&'), @quot, '"'), @apos, ''''), `updated_at` = `updated_at` WHERE INSTR(`name`, @amp) > 0 OR INSTR(`name`, @quot) > 0 OR INSTR(`name`, @apos) > 0;
UPDATE IGNORE `cost_centres` SET `description` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`description`, @amp, '&'), @amp, '&'), @amp, '&'), @quot, '"'), @apos, ''''), `updated_at` = `updated_at` WHERE INSTR(`description`, @amp) > 0 OR INSTR(`description`, @quot) > 0 OR INSTR(`description`, @apos) > 0;

-- ---------------------------------------------------------------- customers
UPDATE IGNORE `customers` SET `contact_person` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`contact_person`, @amp, '&'), @amp, '&'), @amp, '&'), @quot, '"'), @apos, ''''), `updated_at` = `updated_at` WHERE INSTR(`contact_person`, @amp) > 0 OR INSTR(`contact_person`, @quot) > 0 OR INSTR(`contact_person`, @apos) > 0;
UPDATE IGNORE `customers` SET `phone` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`phone`, @amp, '&'), @amp, '&'), @amp, '&'), @quot, '"'), @apos, ''''), `updated_at` = `updated_at` WHERE INSTR(`phone`, @amp) > 0 OR INSTR(`phone`, @quot) > 0 OR INSTR(`phone`, @apos) > 0;
UPDATE IGNORE `customers` SET `email` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`email`, @amp, '&'), @amp, '&'), @amp, '&'), @quot, '"'), @apos, ''''), `updated_at` = `updated_at` WHERE INSTR(`email`, @amp) > 0 OR INSTR(`email`, @quot) > 0 OR INSTR(`email`, @apos) > 0;
UPDATE IGNORE `customers` SET `address` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`address`, @amp, '&'), @amp, '&'), @amp, '&'), @quot, '"'), @apos, ''''), `updated_at` = `updated_at` WHERE INSTR(`address`, @amp) > 0 OR INSTR(`address`, @quot) > 0 OR INSTR(`address`, @apos) > 0;
UPDATE IGNORE `customers` SET `gstin` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`gstin`, @amp, '&'), @amp, '&'), @amp, '&'), @quot, '"'), @apos, ''''), `updated_at` = `updated_at` WHERE INSTR(`gstin`, @amp) > 0 OR INSTR(`gstin`, @quot) > 0 OR INSTR(`gstin`, @apos) > 0;
UPDATE IGNORE `customers` SET `pan` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`pan`, @amp, '&'), @amp, '&'), @amp, '&'), @quot, '"'), @apos, ''''), `updated_at` = `updated_at` WHERE INSTR(`pan`, @amp) > 0 OR INSTR(`pan`, @quot) > 0 OR INSTR(`pan`, @apos) > 0;

-- ---------------------------------------------------------------- suppliers
UPDATE IGNORE `suppliers` SET `contact_person` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`contact_person`, @amp, '&'), @amp, '&'), @amp, '&'), @quot, '"'), @apos, ''''), `updated_at` = `updated_at` WHERE INSTR(`contact_person`, @amp) > 0 OR INSTR(`contact_person`, @quot) > 0 OR INSTR(`contact_person`, @apos) > 0;
UPDATE IGNORE `suppliers` SET `phone` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`phone`, @amp, '&'), @amp, '&'), @amp, '&'), @quot, '"'), @apos, ''''), `updated_at` = `updated_at` WHERE INSTR(`phone`, @amp) > 0 OR INSTR(`phone`, @quot) > 0 OR INSTR(`phone`, @apos) > 0;
UPDATE IGNORE `suppliers` SET `email` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`email`, @amp, '&'), @amp, '&'), @amp, '&'), @quot, '"'), @apos, ''''), `updated_at` = `updated_at` WHERE INSTR(`email`, @amp) > 0 OR INSTR(`email`, @quot) > 0 OR INSTR(`email`, @apos) > 0;
UPDATE IGNORE `suppliers` SET `address` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`address`, @amp, '&'), @amp, '&'), @amp, '&'), @quot, '"'), @apos, ''''), `updated_at` = `updated_at` WHERE INSTR(`address`, @amp) > 0 OR INSTR(`address`, @quot) > 0 OR INSTR(`address`, @apos) > 0;
UPDATE IGNORE `suppliers` SET `gstin` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`gstin`, @amp, '&'), @amp, '&'), @amp, '&'), @quot, '"'), @apos, ''''), `updated_at` = `updated_at` WHERE INSTR(`gstin`, @amp) > 0 OR INSTR(`gstin`, @quot) > 0 OR INSTR(`gstin`, @apos) > 0;
UPDATE IGNORE `suppliers` SET `pan` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`pan`, @amp, '&'), @amp, '&'), @amp, '&'), @quot, '"'), @apos, ''''), `updated_at` = `updated_at` WHERE INSTR(`pan`, @amp) > 0 OR INSTR(`pan`, @quot) > 0 OR INSTR(`pan`, @apos) > 0;

-- ---------------------------------------------------------------- ledger_accounts
UPDATE IGNORE `ledger_accounts` SET `name` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`name`, @amp, '&'), @amp, '&'), @amp, '&'), @quot, '"'), @apos, ''''), `updated_at` = `updated_at` WHERE INSTR(`name`, @amp) > 0 OR INSTR(`name`, @quot) > 0 OR INSTR(`name`, @apos) > 0;

-- ---------------------------------------------------------------- products
UPDATE IGNORE `products` SET `name` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`name`, @amp, '&'), @amp, '&'), @amp, '&'), @quot, '"'), @apos, ''''), `updated_at` = `updated_at` WHERE INSTR(`name`, @amp) > 0 OR INSTR(`name`, @quot) > 0 OR INSTR(`name`, @apos) > 0;
UPDATE IGNORE `products` SET `brand` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`brand`, @amp, '&'), @amp, '&'), @amp, '&'), @quot, '"'), @apos, ''''), `updated_at` = `updated_at` WHERE INSTR(`brand`, @amp) > 0 OR INSTR(`brand`, @quot) > 0 OR INSTR(`brand`, @apos) > 0;
UPDATE IGNORE `products` SET `description` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`description`, @amp, '&'), @amp, '&'), @amp, '&'), @quot, '"'), @apos, ''''), `updated_at` = `updated_at` WHERE INSTR(`description`, @amp) > 0 OR INSTR(`description`, @quot) > 0 OR INSTR(`description`, @apos) > 0;

-- ---------------------------------------------------------------- product_categories
UPDATE IGNORE `product_categories` SET `name` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`name`, @amp, '&'), @amp, '&'), @amp, '&'), @quot, '"'), @apos, ''''), `updated_at` = `updated_at` WHERE INSTR(`name`, @amp) > 0 OR INSTR(`name`, @quot) > 0 OR INSTR(`name`, @apos) > 0;
UPDATE IGNORE `product_categories` SET `description` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`description`, @amp, '&'), @amp, '&'), @amp, '&'), @quot, '"'), @apos, ''''), `updated_at` = `updated_at` WHERE INSTR(`description`, @amp) > 0 OR INSTR(`description`, @quot) > 0 OR INSTR(`description`, @apos) > 0;
