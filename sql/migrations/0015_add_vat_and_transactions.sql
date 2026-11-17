-- Migration: 0015_add_vat_and_transactions
-- Description: Add VAT amount to purchases, extend ledger account types, and create transactions / transaction_details tables for double-entry accounting (Ledger Report)
-- Date: 2026-05-07

-- --------------------------------------------------------
-- 1) Add vat_amount to purchases
-- --------------------------------------------------------
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchases' AND COLUMN_NAME = 'vat_amount') = 0,
    'ALTER TABLE `purchases` ADD COLUMN `vat_amount` decimal(15,2) NOT NULL DEFAULT 0.00 AFTER `total_amount`',
    'SELECT 1'
));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------
-- 2) Extend ledger_accounts.account_type ENUM with 'purchase','sales','duties'
--    (MODIFY is naturally idempotent — running twice is a no-op.)
-- --------------------------------------------------------
ALTER TABLE `ledger_accounts`
  MODIFY COLUMN `account_type` ENUM('supplier','customer','expense','cash','bank','purchase','sales','duties') NOT NULL;

-- --------------------------------------------------------
-- 3) Create `transactions` table (one row per voucher)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `voucher_id` int(11) NOT NULL,
  `voucher_type` enum('purchase','sales','receipt','payment') NOT NULL,
  `voucher_number` varchar(50) DEFAULT NULL,
  `narration` text DEFAULT NULL,
  `cost_centre_id` int(11) DEFAULT NULL,
  `status` enum('active','cancelled') NOT NULL DEFAULT 'active',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_voucher` (`voucher_type`, `voucher_id`),
  KEY `idx_tx_date` (`date`),
  KEY `idx_tx_cost_centre` (`cost_centre_id`),
  CONSTRAINT `tx_cost_centre_fk` FOREIGN KEY (`cost_centre_id`) REFERENCES `cost_centres` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tx_user_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 4) Create `transaction_details` table (DR/CR rows per transaction)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `transaction_details` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_id` int(11) NOT NULL,
  `ledger_id` int(11) NOT NULL,
  `currency_id` int(11) DEFAULT NULL,
  `rate_of_exchange` decimal(18,8) NOT NULL DEFAULT 1.00000000,
  `amount` decimal(15,2) NOT NULL,
  `amount_base` decimal(15,2) NOT NULL,
  `dr_cr` enum('DR','CR') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_td_transaction` (`transaction_id`),
  KEY `idx_td_ledger` (`ledger_id`),
  KEY `idx_td_currency` (`currency_id`),
  CONSTRAINT `td_transaction_fk` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `td_ledger_fk` FOREIGN KEY (`ledger_id`) REFERENCES `ledger_accounts` (`id`),
  CONSTRAINT `td_currency_fk` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
