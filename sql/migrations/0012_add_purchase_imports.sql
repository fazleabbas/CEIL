-- Migration: 0012_add_purchase_imports
-- Description: Add purchase imports tables for import costing with landed cost calculation
-- Date: 2026-02-18

-- 1a. purchase_imports header table
CREATE TABLE IF NOT EXISTS `purchase_imports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `import_number` varchar(50) NOT NULL,
  `import_date` date NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `location_id` int(11) NOT NULL,
  `cost_centre_id` int(11) DEFAULT NULL,
  `currency_id` int(11) DEFAULT NULL,
  `exchange_rate_to_base` decimal(18,8) DEFAULT 1.00000000,
  `po_number` varchar(50) DEFAULT NULL,
  `total_fob_amount` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Total FOB value of all items',
  `total_additional_costs` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Sum of all additional cost lines',
  `total_landed_amount` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'FOB + Additional Costs',
  `costing_factor` decimal(18,8) NOT NULL DEFAULT 1.00000000 COMMENT 'total_landed / total_fob',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_import_number` (`import_number`),
  KEY `idx_pi_date` (`import_date`),
  KEY `idx_pi_supplier` (`supplier_id`),
  KEY `idx_pi_location` (`location_id`),
  KEY `idx_pi_date_location` (`import_date`, `location_id`),
  CONSTRAINT `pi_supplier_fk` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  CONSTRAINT `pi_location_fk` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`),
  CONSTRAINT `pi_cost_centre_fk` FOREIGN KEY (`cost_centre_id`) REFERENCES `cost_centres` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pi_created_by_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1b. purchase_import_details - line items with FOB and landed cost
CREATE TABLE IF NOT EXISTS `purchase_import_details` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `import_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` decimal(15,3) NOT NULL,
  `fob_rate_per_unit` decimal(15,2) NOT NULL COMMENT 'FOB price per unit',
  `fob_amount` decimal(15,2) GENERATED ALWAYS AS (`quantity` * `fob_rate_per_unit`) STORED,
  `landed_rate_per_unit` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'FOB rate * costing factor',
  `landed_amount` decimal(15,2) GENERATED ALWAYS AS (`quantity` * `landed_rate_per_unit`) STORED,
  `selling_price` decimal(15,2) DEFAULT NULL COMMENT 'User-entered selling price per unit',
  `profit_per_unit` decimal(15,2) GENERATED ALWAYS AS (CASE WHEN `selling_price` IS NOT NULL THEN `selling_price` - `landed_rate_per_unit` ELSE NULL END) STORED,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pid_import` (`import_id`),
  KEY `idx_pid_product` (`product_id`),
  CONSTRAINT `pid_import_fk` FOREIGN KEY (`import_id`) REFERENCES `purchase_imports` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pid_product_fk` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1c. purchase_import_costs - additional cost lines (freight, customs, port charges, etc.)
CREATE TABLE IF NOT EXISTS `purchase_import_costs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `import_id` int(11) NOT NULL,
  `description` varchar(200) NOT NULL COMMENT 'e.g. Freight & Inspection, Customs Fee, Port Charges',
  `amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pic_import` (`import_id`),
  CONSTRAINT `pic_import_fk` FOREIGN KEY (`import_id`) REFERENCES `purchase_imports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1d. Add 'purchase_import' to stock_batches transaction_type enum
ALTER TABLE `stock_batches` MODIFY COLUMN `transaction_type`
  enum('opening','purchase','adjustment','transfer_in','transfer_out','consumption','purchase_import') NOT NULL;
