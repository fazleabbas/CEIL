-- Purchase Imports Feature - Database Migration
-- Creates tables: purchase_imports, purchase_import_details, purchase_import_costs

-- --------------------------------------------------------

--
-- Table structure for table `purchase_imports`
--

CREATE TABLE IF NOT EXISTS `purchase_imports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `import_number` varchar(50) NOT NULL,
  `import_date` date NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `location_id` int(11) NOT NULL,
  `cost_centre_id` int(11) DEFAULT NULL,
  `currency_id` int(11) DEFAULT NULL,
  `exchange_rate_to_base` decimal(15,4) NOT NULL DEFAULT 1.0000,
  `po_number` varchar(100) DEFAULT NULL,
  `total_fob_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_additional_costs` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_landed_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `costing_factor` decimal(15,6) NOT NULL DEFAULT 1.000000,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `import_number` (`import_number`),
  KEY `supplier_id` (`supplier_id`),
  KEY `location_id` (`location_id`),
  KEY `cost_centre_id` (`cost_centre_id`),
  KEY `currency_id` (`currency_id`),
  KEY `created_by` (`created_by`),
  KEY `idx_import_date` (`import_date`),
  CONSTRAINT `purchase_imports_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  CONSTRAINT `purchase_imports_ibfk_2` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`),
  CONSTRAINT `purchase_imports_ibfk_3` FOREIGN KEY (`cost_centre_id`) REFERENCES `cost_centres` (`id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_imports_ibfk_4` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_imports_ibfk_5` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_import_details`
--

CREATE TABLE IF NOT EXISTS `purchase_import_details` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `import_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` decimal(15,3) NOT NULL,
  `fob_rate_per_unit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `landed_rate_per_unit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `selling_price` decimal(15,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `import_id` (`import_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `purchase_import_details_ibfk_1` FOREIGN KEY (`import_id`) REFERENCES `purchase_imports` (`id`) ON DELETE CASCADE,
  CONSTRAINT `purchase_import_details_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_import_costs`
--

CREATE TABLE IF NOT EXISTS `purchase_import_costs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `import_id` int(11) NOT NULL,
  `description` varchar(255) NOT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `import_id` (`import_id`),
  CONSTRAINT `purchase_import_costs_ibfk_1` FOREIGN KEY (`import_id`) REFERENCES `purchase_imports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
