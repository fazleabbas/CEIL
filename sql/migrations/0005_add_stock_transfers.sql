-- Migration: 0005_add_stock_transfers
-- Description: Add stock transfers feature for inter-location stock movement
-- Date: 2026-02-04
-- Author: System

-- --------------------------------------------------------
-- Add allow_negative_stock setting
-- --------------------------------------------------------
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `description`)
VALUES ('allow_negative_stock', '0', 'Allow transfers even when source location has insufficient stock (0=No, 1=Yes)');

-- --------------------------------------------------------
-- Table structure for table `stock_transfers`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `stock_transfers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transfer_number` varchar(50) NOT NULL,
  `transfer_date` date NOT NULL,
  `from_location_id` int(11) NOT NULL,
  `to_location_id` int(11) NOT NULL,
  `vehicle_number` varchar(50) DEFAULT NULL,
  `driver_name` varchar(100) DEFAULT NULL,
  `driver_phone` varchar(20) DEFAULT NULL,
  `status` enum('draft','in_transit','received','cancelled') NOT NULL DEFAULT 'draft',
  `remarks` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `dispatched_by` int(11) DEFAULT NULL,
  `dispatched_at` timestamp NULL DEFAULT NULL,
  `received_by` int(11) DEFAULT NULL,
  `received_at` timestamp NULL DEFAULT NULL,
  `cancelled_by` int(11) DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `cancel_reason` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `transfer_number` (`transfer_number`),
  KEY `from_location_id` (`from_location_id`),
  KEY `to_location_id` (`to_location_id`),
  KEY `created_by` (`created_by`),
  KEY `status` (`status`),
  KEY `transfer_date` (`transfer_date`),
  CONSTRAINT `stock_transfers_ibfk_1` FOREIGN KEY (`from_location_id`) REFERENCES `locations` (`id`),
  CONSTRAINT `stock_transfers_ibfk_2` FOREIGN KEY (`to_location_id`) REFERENCES `locations` (`id`),
  CONSTRAINT `stock_transfers_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `stock_transfers_ibfk_4` FOREIGN KEY (`dispatched_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `stock_transfers_ibfk_5` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `stock_transfers_ibfk_6` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `stock_transfer_details`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `stock_transfer_details` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transfer_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `dispatched_quantity` decimal(15,3) NOT NULL,
  `rate_per_unit` decimal(15,2) NOT NULL COMMENT 'FIFO cost at time of dispatch',
  `dispatched_amount` decimal(15,2) GENERATED ALWAYS AS (`dispatched_quantity` * `rate_per_unit`) STORED,
  `received_quantity` decimal(15,3) DEFAULT NULL COMMENT 'Actual quantity received (can be less than dispatched)',
  `received_amount` decimal(15,2) GENERATED ALWAYS AS (`received_quantity` * `rate_per_unit`) STORED,
  `shortage_quantity` decimal(15,3) GENERATED ALWAYS AS (`dispatched_quantity` - IFNULL(`received_quantity`, `dispatched_quantity`)) STORED,
  `shortage_reason` text DEFAULT NULL COMMENT 'Reason for shortage if received < dispatched',
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `transfer_id` (`transfer_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `stock_transfer_details_ibfk_1` FOREIGN KEY (`transfer_id`) REFERENCES `stock_transfers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `stock_transfer_details_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Modify stock_batches to support transfer transaction types
-- --------------------------------------------------------
-- Note: This ALTER modifies the enum to add transfer_in and transfer_out
-- Run this only if the column doesn't already have these values

ALTER TABLE `stock_batches`
MODIFY COLUMN `transaction_type` enum('opening','purchase','adjustment','transfer_in','transfer_out') NOT NULL;
