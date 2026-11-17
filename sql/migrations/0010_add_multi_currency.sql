-- Migration: 0010_add_multi_currency
-- Description: Add multi-currency support - currencies master, exchange rates, and currency columns on all monetary tables
-- Date: 2026-02-10

-- --------------------------------------------------------
-- Table structure for table `currencies`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `currencies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(3) NOT NULL COMMENT 'ISO 4217 code: USD, TZS, EUR, etc.',
  `name` varchar(50) NOT NULL,
  `symbol` varchar(10) NOT NULL,
  `decimal_places` tinyint(1) NOT NULL DEFAULT 2,
  `is_default` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Exactly one row should be 1',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_currency_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default currency (TZS)
INSERT IGNORE INTO `currencies` (`code`, `name`, `symbol`, `decimal_places`, `is_default`, `is_active`)
VALUES ('TZS', 'Tanzanian Shilling', 'TZS', 0, 1, 1);

-- --------------------------------------------------------
-- Table structure for table `exchange_rates`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `exchange_rates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `from_currency_id` int(11) NOT NULL,
  `to_currency_id` int(11) NOT NULL,
  `rate` decimal(18,8) NOT NULL COMMENT 'How many to_currency units per 1 from_currency unit',
  `effective_date` date NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_rate_per_date` (`from_currency_id`, `to_currency_id`, `effective_date`),
  KEY `idx_er_from` (`from_currency_id`),
  KEY `idx_er_to` (`to_currency_id`),
  KEY `idx_er_date` (`effective_date`),
  CONSTRAINT `exchange_rates_from_fk` FOREIGN KEY (`from_currency_id`) REFERENCES `currencies` (`id`),
  CONSTRAINT `exchange_rates_to_fk` FOREIGN KEY (`to_currency_id`) REFERENCES `currencies` (`id`),
  CONSTRAINT `exchange_rates_user_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Add currency_id and exchange_rate_to_base to sales
-- --------------------------------------------------------
ALTER TABLE `sales` ADD COLUMN `currency_id` int(11) DEFAULT NULL;
ALTER TABLE `sales` ADD COLUMN `exchange_rate_to_base` decimal(18,8) NOT NULL DEFAULT 1.00000000;
ALTER TABLE `sales` ADD INDEX `idx_sales_currency` (`currency_id`);
ALTER TABLE `sales` ADD CONSTRAINT `sales_currency_fk` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`);

-- --------------------------------------------------------
-- Add currency_id and exchange_rate_to_base to purchases
-- --------------------------------------------------------
ALTER TABLE `purchases` ADD COLUMN `currency_id` int(11) DEFAULT NULL;
ALTER TABLE `purchases` ADD COLUMN `exchange_rate_to_base` decimal(18,8) NOT NULL DEFAULT 1.00000000;
ALTER TABLE `purchases` ADD INDEX `idx_purchases_currency` (`currency_id`);
ALTER TABLE `purchases` ADD CONSTRAINT `purchases_currency_fk` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`);

-- --------------------------------------------------------
-- Add currency_id and exchange_rate_to_base to payments
-- --------------------------------------------------------
ALTER TABLE `payments` ADD COLUMN `currency_id` int(11) DEFAULT NULL;
ALTER TABLE `payments` ADD COLUMN `exchange_rate_to_base` decimal(18,8) NOT NULL DEFAULT 1.00000000;
ALTER TABLE `payments` ADD INDEX `idx_payments_currency` (`currency_id`);
ALTER TABLE `payments` ADD CONSTRAINT `payments_currency_fk` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`);

-- --------------------------------------------------------
-- Add currency_id and exchange_rate_to_base to receipts
-- --------------------------------------------------------
ALTER TABLE `receipts` ADD COLUMN `currency_id` int(11) DEFAULT NULL;
ALTER TABLE `receipts` ADD COLUMN `exchange_rate_to_base` decimal(18,8) NOT NULL DEFAULT 1.00000000;
ALTER TABLE `receipts` ADD INDEX `idx_receipts_currency` (`currency_id`);
ALTER TABLE `receipts` ADD CONSTRAINT `receipts_currency_fk` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`);

-- --------------------------------------------------------
-- Add currency_id and exchange_rate_to_base to stock_transfers
-- --------------------------------------------------------
ALTER TABLE `stock_transfers` ADD COLUMN `currency_id` int(11) DEFAULT NULL;
ALTER TABLE `stock_transfers` ADD COLUMN `exchange_rate_to_base` decimal(18,8) NOT NULL DEFAULT 1.00000000;
ALTER TABLE `stock_transfers` ADD INDEX `idx_transfers_currency` (`currency_id`);
ALTER TABLE `stock_transfers` ADD CONSTRAINT `stock_transfers_currency_fk` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`);

-- --------------------------------------------------------
-- Add currency_id and exchange_rate_to_base to stock_adjustments
-- --------------------------------------------------------
ALTER TABLE `stock_adjustments` ADD COLUMN `currency_id` int(11) DEFAULT NULL;
ALTER TABLE `stock_adjustments` ADD COLUMN `exchange_rate_to_base` decimal(18,8) NOT NULL DEFAULT 1.00000000;
ALTER TABLE `stock_adjustments` ADD INDEX `idx_adjustments_currency` (`currency_id`);
ALTER TABLE `stock_adjustments` ADD CONSTRAINT `stock_adjustments_currency_fk` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`);

-- --------------------------------------------------------
-- Add currency_id and exchange_rate_to_base to consumption_vouchers
-- --------------------------------------------------------
ALTER TABLE `consumption_vouchers` ADD COLUMN `currency_id` int(11) DEFAULT NULL;
ALTER TABLE `consumption_vouchers` ADD COLUMN `exchange_rate_to_base` decimal(18,8) NOT NULL DEFAULT 1.00000000;
ALTER TABLE `consumption_vouchers` ADD INDEX `idx_cv_currency` (`currency_id`);
ALTER TABLE `consumption_vouchers` ADD CONSTRAINT `cv_currency_fk` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`);

-- --------------------------------------------------------
-- Add currency_id and exchange_rate_to_base to opening_stocks
-- --------------------------------------------------------
ALTER TABLE `opening_stocks` ADD COLUMN `currency_id` int(11) DEFAULT NULL;
ALTER TABLE `opening_stocks` ADD COLUMN `exchange_rate_to_base` decimal(18,8) NOT NULL DEFAULT 1.00000000;
ALTER TABLE `opening_stocks` ADD INDEX `idx_os_currency` (`currency_id`);
ALTER TABLE `opening_stocks` ADD CONSTRAINT `os_currency_fk` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`);

-- --------------------------------------------------------
-- Add default_currency_id to products
-- --------------------------------------------------------
ALTER TABLE `products` ADD COLUMN `default_currency_id` int(11) DEFAULT NULL;
ALTER TABLE `products` ADD CONSTRAINT `products_currency_fk` FOREIGN KEY (`default_currency_id`) REFERENCES `currencies` (`id`);

-- --------------------------------------------------------
-- Add currency_id to product_location_prices
-- --------------------------------------------------------
ALTER TABLE `product_location_prices` ADD COLUMN `currency_id` int(11) DEFAULT NULL;
ALTER TABLE `product_location_prices` ADD CONSTRAINT `plp_currency_fk` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`);

-- --------------------------------------------------------
-- Add currency_id to ledger_accounts
-- --------------------------------------------------------
ALTER TABLE `ledger_accounts` ADD COLUMN `currency_id` int(11) DEFAULT NULL;
ALTER TABLE `ledger_accounts` ADD CONSTRAINT `la_currency_fk` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`);

-- --------------------------------------------------------
-- Backfill existing data with default currency
-- --------------------------------------------------------
SET @default_currency_id = (SELECT `id` FROM `currencies` WHERE `is_default` = 1 LIMIT 1);

UPDATE `sales` SET `currency_id` = @default_currency_id WHERE `currency_id` IS NULL;
UPDATE `purchases` SET `currency_id` = @default_currency_id WHERE `currency_id` IS NULL;
UPDATE `payments` SET `currency_id` = @default_currency_id WHERE `currency_id` IS NULL;
UPDATE `receipts` SET `currency_id` = @default_currency_id WHERE `currency_id` IS NULL;
UPDATE `stock_transfers` SET `currency_id` = @default_currency_id WHERE `currency_id` IS NULL;
UPDATE `stock_adjustments` SET `currency_id` = @default_currency_id WHERE `currency_id` IS NULL;
UPDATE `consumption_vouchers` SET `currency_id` = @default_currency_id WHERE `currency_id` IS NULL;
UPDATE `opening_stocks` SET `currency_id` = @default_currency_id WHERE `currency_id` IS NULL;
UPDATE `products` SET `default_currency_id` = @default_currency_id WHERE `default_currency_id` IS NULL;
UPDATE `product_location_prices` SET `currency_id` = @default_currency_id WHERE `currency_id` IS NULL;
UPDATE `ledger_accounts` SET `currency_id` = @default_currency_id WHERE `currency_id` IS NULL;

-- --------------------------------------------------------
-- Add currencies and exchange_rates permissions to Admin users
-- --------------------------------------------------------
INSERT IGNORE INTO `user_permissions` (`user_id`, `module_name`, `can_view`, `can_create`, `can_edit`, `can_delete`)
SELECT u.id, 'currencies', 1, 1, 1, 1
FROM `users` u INNER JOIN `roles` r ON u.role_id = r.id WHERE r.name = 'Admin';

INSERT IGNORE INTO `user_permissions` (`user_id`, `module_name`, `can_view`, `can_create`, `can_edit`, `can_delete`)
SELECT u.id, 'exchange_rates', 1, 1, 1, 1
FROM `users` u INNER JOIN `roles` r ON u.role_id = r.id WHERE r.name = 'Admin';

-- Add view-only currencies and exchange_rates permissions to Manager users
INSERT IGNORE INTO `user_permissions` (`user_id`, `module_name`, `can_view`, `can_create`, `can_edit`, `can_delete`)
SELECT u.id, 'currencies', 1, 0, 0, 0
FROM `users` u INNER JOIN `roles` r ON u.role_id = r.id WHERE r.name = 'Manager';

INSERT IGNORE INTO `user_permissions` (`user_id`, `module_name`, `can_view`, `can_create`, `can_edit`, `can_delete`)
SELECT u.id, 'exchange_rates', 1, 0, 0, 0
FROM `users` u INNER JOIN `roles` r ON u.role_id = r.id WHERE r.name = 'Manager';
