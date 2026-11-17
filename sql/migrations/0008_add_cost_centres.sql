-- Migration: 0008_add_cost_centres
-- Description: Add cost centres feature - tables and foreign keys on transaction tables
-- Date: 2026-02-10

-- --------------------------------------------------------
-- Table structure for table `cost_centres`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cost_centres` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` enum('active','completed','on_hold') DEFAULT 'active',
  `budget_amount` decimal(15,2) DEFAULT 0.00,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_cost_centre_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `user_cost_centres`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_cost_centres` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `cost_centre_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_cost_centres_ibfk_1` (`user_id`),
  KEY `user_cost_centres_ibfk_2` (`cost_centre_id`),
  CONSTRAINT `user_cost_centres_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_cost_centres_ibfk_2` FOREIGN KEY (`cost_centre_id`) REFERENCES `cost_centres` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Add cost_centre_id to purchases
-- --------------------------------------------------------
ALTER TABLE `purchases` ADD COLUMN `cost_centre_id` int(11) DEFAULT NULL;
ALTER TABLE `purchases` ADD INDEX `idx_purchases_cost_centre` (`cost_centre_id`);
ALTER TABLE `purchases` ADD CONSTRAINT `purchases_cost_centre_fk` FOREIGN KEY (`cost_centre_id`) REFERENCES `cost_centres` (`id`) ON DELETE SET NULL;

-- --------------------------------------------------------
-- Add cost_centre_id to sales
-- --------------------------------------------------------
ALTER TABLE `sales` ADD COLUMN `cost_centre_id` int(11) DEFAULT NULL;
ALTER TABLE `sales` ADD INDEX `idx_sales_cost_centre` (`cost_centre_id`);
ALTER TABLE `sales` ADD CONSTRAINT `sales_cost_centre_fk` FOREIGN KEY (`cost_centre_id`) REFERENCES `cost_centres` (`id`) ON DELETE SET NULL;

-- --------------------------------------------------------
-- Add cost_centre_id to payments
-- --------------------------------------------------------
ALTER TABLE `payments` ADD COLUMN `cost_centre_id` int(11) DEFAULT NULL;
ALTER TABLE `payments` ADD INDEX `idx_payments_cost_centre` (`cost_centre_id`);
ALTER TABLE `payments` ADD CONSTRAINT `payments_cost_centre_fk` FOREIGN KEY (`cost_centre_id`) REFERENCES `cost_centres` (`id`) ON DELETE SET NULL;

-- --------------------------------------------------------
-- Add cost_centre_id to receipts
-- --------------------------------------------------------
ALTER TABLE `receipts` ADD COLUMN `cost_centre_id` int(11) DEFAULT NULL;
ALTER TABLE `receipts` ADD INDEX `idx_receipts_cost_centre` (`cost_centre_id`);
ALTER TABLE `receipts` ADD CONSTRAINT `receipts_cost_centre_fk` FOREIGN KEY (`cost_centre_id`) REFERENCES `cost_centres` (`id`) ON DELETE SET NULL;

-- --------------------------------------------------------
-- Add cost_centre_id to stock_transfers
-- --------------------------------------------------------
ALTER TABLE `stock_transfers` ADD COLUMN `cost_centre_id` int(11) DEFAULT NULL;
ALTER TABLE `stock_transfers` ADD INDEX `idx_transfers_cost_centre` (`cost_centre_id`);
ALTER TABLE `stock_transfers` ADD CONSTRAINT `transfers_cost_centre_fk` FOREIGN KEY (`cost_centre_id`) REFERENCES `cost_centres` (`id`) ON DELETE SET NULL;
