-- Stock Adjustments Tables
-- Run this SQL to add stock adjustment functionality

-- --------------------------------------------------------
-- Table structure for table `stock_adjustments`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `stock_adjustments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `adjustment_number` varchar(50) NOT NULL,
  `location_id` int(11) NOT NULL,
  `adjustment_date` date NOT NULL,
  `stock_taking_id` int(11) DEFAULT NULL COMMENT 'Link to stock_taking if created from variance',
  `remarks` text DEFAULT NULL,
  `total_adjustment_amount` decimal(15,2) DEFAULT 0.00,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `adjustment_number` (`adjustment_number`),
  KEY `location_id` (`location_id`),
  KEY `stock_taking_id` (`stock_taking_id`),
  KEY `created_by` (`created_by`),
  KEY `adjustment_date` (`adjustment_date`),
  CONSTRAINT `stock_adjustments_ibfk_1` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`),
  CONSTRAINT `stock_adjustments_ibfk_2` FOREIGN KEY (`stock_taking_id`) REFERENCES `stock_taking` (`id`) ON DELETE SET NULL,
  CONSTRAINT `stock_adjustments_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `stock_adjustment_details`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `stock_adjustment_details` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `adjustment_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `adjustment_type` enum('increase','decrease') NOT NULL,
  `quantity` decimal(15,3) NOT NULL,
  `rate_per_unit` decimal(15,2) NOT NULL,
  `adjustment_amount` decimal(15,2) GENERATED ALWAYS AS (`quantity` * `rate_per_unit`) STORED,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `adjustment_id` (`adjustment_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `stock_adjustment_details_ibfk_1` FOREIGN KEY (`adjustment_id`) REFERENCES `stock_adjustments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `stock_adjustment_details_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Modify stock_batches to support adjustment transaction type
-- --------------------------------------------------------

ALTER TABLE `stock_batches` 
MODIFY COLUMN `transaction_type` enum('opening','purchase','adjustment') NOT NULL;

