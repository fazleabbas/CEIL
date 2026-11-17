-- Migration: 0032_add_bom
-- Description: Add Bill of Materials (BOM) module — header, line items (with an
--              editable rate, auto-filled from weighted-average cost), and
--              per-line quantity breakdowns (free-text label + qty that must
--              sum to the line total). Mirrors the BOQ module; a future
--              requisition voucher can reference a BOM. Seeds the 'bom' module
--              permission for Admin and Manager, plus the BOM numbering prefix.
-- Date: 2026-06-14

-- =============================================
-- 1. Setting for BOM number prefix
-- =============================================
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `description`)
VALUES ('bom_prefix', 'BOM', 'Prefix for Bill of Materials numbering (e.g. BOM000001)');

-- =============================================
-- 2. BOM (header)
-- =============================================
CREATE TABLE IF NOT EXISTS `boms` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bom_number` varchar(50) NOT NULL,
  `bom_date` date NOT NULL,
  `location_id` int(11) NOT NULL,
  `cost_centre_id` int(11) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_bom_number` (`bom_number`),
  KEY `idx_bom_location` (`location_id`),
  KEY `idx_bom_cost_centre` (`cost_centre_id`),
  KEY `idx_bom_date` (`bom_date`),
  KEY `idx_bom_created_by` (`created_by`),
  CONSTRAINT `bom_location_fk` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`),
  CONSTRAINT `bom_cost_centre_fk` FOREIGN KEY (`cost_centre_id`) REFERENCES `cost_centres` (`id`) ON DELETE SET NULL,
  CONSTRAINT `bom_created_by_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- 3. BOM Details (line items)
-- =============================================
CREATE TABLE IF NOT EXISTS `bom_details` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bom_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `total_quantity` decimal(15,3) NOT NULL,
  `rate` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Unit rate, auto-filled from avg cost but editable',
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_bomd_bom` (`bom_id`),
  KEY `idx_bomd_product` (`product_id`),
  CONSTRAINT `bomd_bom_fk` FOREIGN KEY (`bom_id`) REFERENCES `boms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bomd_product_fk` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- 4. BOM Detail Breakdowns (label + qty splits)
-- =============================================
CREATE TABLE IF NOT EXISTS `bom_detail_breakdowns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bom_detail_id` int(11) NOT NULL,
  `label` varchar(255) NOT NULL COMMENT 'Free-text area/floor e.g. Ground floor',
  `quantity` decimal(15,3) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_bomb_detail` (`bom_detail_id`),
  CONSTRAINT `bomb_detail_fk` FOREIGN KEY (`bom_detail_id`) REFERENCES `bom_details` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- 5. Seed 'bom' module permission for Admin and Manager
-- =============================================
INSERT IGNORE INTO `user_permissions` (`user_id`, `module_name`, `can_view`, `can_create`, `can_edit`, `can_delete`)
SELECT u.id, 'bom', 1, 1, 1, 1
FROM `users` u INNER JOIN `roles` r ON u.role_id = r.id WHERE r.name = 'Admin';

INSERT IGNORE INTO `user_permissions` (`user_id`, `module_name`, `can_view`, `can_create`, `can_edit`, `can_delete`)
SELECT u.id, 'bom', 1, 1, 1, 1
FROM `users` u INNER JOIN `roles` r ON u.role_id = r.id WHERE r.name = 'Manager';
