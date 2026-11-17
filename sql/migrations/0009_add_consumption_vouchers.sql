-- Migration: 0009_add_consumption_vouchers
-- Description: Add consumption vouchers tables and update stock_batches enum
-- Date: 2026-02-10

-- 1a. consumption_vouchers header table
CREATE TABLE IF NOT EXISTS `consumption_vouchers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `voucher_number` varchar(50) NOT NULL,
  `voucher_date` date NOT NULL,
  `location_id` int(11) NOT NULL,
  `cost_centre_id` int(11) DEFAULT NULL,
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `remarks` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_voucher_number` (`voucher_number`),
  KEY `idx_cv_location` (`location_id`),
  KEY `idx_cv_cost_centre` (`cost_centre_id`),
  KEY `idx_cv_date` (`voucher_date`),
  CONSTRAINT `cv_location_fk` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`),
  CONSTRAINT `cv_cost_centre_fk` FOREIGN KEY (`cost_centre_id`) REFERENCES `cost_centres` (`id`) ON DELETE SET NULL,
  CONSTRAINT `cv_created_by_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1b. consumption_voucher_details table
CREATE TABLE IF NOT EXISTS `consumption_voucher_details` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `voucher_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` decimal(15,3) NOT NULL,
  `rate_per_unit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cvd_voucher` (`voucher_id`),
  KEY `idx_cvd_product` (`product_id`),
  CONSTRAINT `cvd_voucher_fk` FOREIGN KEY (`voucher_id`) REFERENCES `consumption_vouchers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cvd_product_fk` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1c. Add 'consumption' to stock_batches transaction_type enum
ALTER TABLE `stock_batches` MODIFY COLUMN `transaction_type`
  enum('opening','purchase','adjustment','transfer_in','transfer_out','consumption') NOT NULL;
