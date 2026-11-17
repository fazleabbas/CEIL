-- Migration: 0030_add_boq
-- Description: Add Bill of Quantities (BOQ) module — header, line items, and
--              per-line quantity breakdowns (free-text label + qty that must
--              sum to the line total). Standalone CRUD; a future requisition
--              voucher can reference a BOQ (one BOQ -> many RVs). Seeds the
--              'boq' module permission for Admin and Manager, plus the BOQ
--              numbering prefix.
-- Date: 2026-06-12

-- =============================================
-- 1. Setting for BOQ number prefix
-- =============================================
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `description`)
VALUES ('boq_prefix', 'BOQ', 'Prefix for Bill of Quantities numbering (e.g. BOQ000001)');

-- =============================================
-- 2. BOQ (header)
-- =============================================
CREATE TABLE IF NOT EXISTS `boqs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `boq_number` varchar(50) NOT NULL,
  `boq_date` date NOT NULL,
  `location_id` int(11) NOT NULL,
  `cost_centre_id` int(11) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_boq_number` (`boq_number`),
  KEY `idx_boq_location` (`location_id`),
  KEY `idx_boq_cost_centre` (`cost_centre_id`),
  KEY `idx_boq_date` (`boq_date`),
  KEY `idx_boq_created_by` (`created_by`),
  CONSTRAINT `boq_location_fk` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`),
  CONSTRAINT `boq_cost_centre_fk` FOREIGN KEY (`cost_centre_id`) REFERENCES `cost_centres` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boq_created_by_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- 3. BOQ Details (line items)
-- =============================================
CREATE TABLE IF NOT EXISTS `boq_details` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `boq_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `total_quantity` decimal(15,3) NOT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_boqd_boq` (`boq_id`),
  KEY `idx_boqd_product` (`product_id`),
  CONSTRAINT `boqd_boq_fk` FOREIGN KEY (`boq_id`) REFERENCES `boqs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `boqd_product_fk` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- 4. BOQ Detail Breakdowns (label + qty splits)
-- =============================================
CREATE TABLE IF NOT EXISTS `boq_detail_breakdowns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `boq_detail_id` int(11) NOT NULL,
  `label` varchar(255) NOT NULL COMMENT 'Free-text area/floor e.g. Ground floor',
  `quantity` decimal(15,3) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_boqb_detail` (`boq_detail_id`),
  CONSTRAINT `boqb_detail_fk` FOREIGN KEY (`boq_detail_id`) REFERENCES `boq_details` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- 5. Seed 'boq' module permission for Admin and Manager
-- =============================================
INSERT IGNORE INTO `user_permissions` (`user_id`, `module_name`, `can_view`, `can_create`, `can_edit`, `can_delete`)
SELECT u.id, 'boq', 1, 1, 1, 1
FROM `users` u INNER JOIN `roles` r ON u.role_id = r.id WHERE r.name = 'Admin';

INSERT IGNORE INTO `user_permissions` (`user_id`, `module_name`, `can_view`, `can_create`, `can_edit`, `can_delete`)
SELECT u.id, 'boq', 1, 1, 1, 1
FROM `users` u INNER JOIN `roles` r ON u.role_id = r.id WHERE r.name = 'Manager';
