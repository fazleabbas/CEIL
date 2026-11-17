-- Migration: 0048_add_terms_to_purchase_orders
-- Description: Separate Payment Terms and Delivery Terms fields on purchase
--              orders. Until now users typed both into the free-text remarks
--              column as prose, so the printed PO carried them as one run-on
--              line mixed in with unrelated notes.
-- Date: 2026-10-16
--
-- Additive and nullable. Existing POs keep NULL terms and render exactly as
-- they do today, because the view and print only emit a terms row when a
-- value is present. Terms already typed into remarks are deliberately left
-- where they are (user-confirmed) - no backfill.
--
-- TEXT, not VARCHAR - terms are multi-line (e.g. a staged payment schedule).
--
-- The defaults that pre-fill a NEW purchase order live in `settings` under
-- po_default_payment_terms / po_default_delivery_terms and are edited on
-- Company Settings. They are not seeded here - getSetting() falls back to an
-- empty string, so a tenant that never sets them sees blank boxes.
--
-- NOTE: keep statement separators out of these comments. executeMigration()
-- in api/run-migrations.php splits the file on the separator character BEFORE
-- it strips comments.

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_orders' AND COLUMN_NAME = 'payment_terms') = 0,
    'ALTER TABLE `purchase_orders` ADD COLUMN `payment_terms` TEXT NULL DEFAULT NULL AFTER `remarks`',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_orders' AND COLUMN_NAME = 'delivery_terms') = 0,
    'ALTER TABLE `purchase_orders` ADD COLUMN `delivery_terms` TEXT NULL DEFAULT NULL AFTER `payment_terms`',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
