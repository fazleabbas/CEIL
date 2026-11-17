-- Migration: 0011_add_requisition_vouchers
-- Description: Add requisition voucher tables, notifications, approval permission, and transfer linkage
-- Date: 2026-02-11

-- =============================================
-- 1. Settings for RV prefix
-- =============================================
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `description`)
VALUES ('rv_prefix', 'RV', 'Prefix for requisition voucher numbering (e.g. RV000001)');

-- =============================================
-- 2. Add can_approve column to user_permissions
-- =============================================
ALTER TABLE `user_permissions`
ADD COLUMN `can_approve` tinyint(1) NOT NULL DEFAULT 0 AFTER `can_import`;

-- =============================================
-- 3. Requisition Vouchers (header)
-- =============================================
CREATE TABLE IF NOT EXISTS `requisition_vouchers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `voucher_number` varchar(50) NOT NULL,
  `voucher_date` date NOT NULL,
  `destination_location_id` int(11) NOT NULL COMMENT 'Where goods are needed',
  `cost_centre_id` int(11) DEFAULT NULL,
  `status` enum('draft','pending_approval','approved','closed') NOT NULL DEFAULT 'draft',
  `remarks` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `sent_for_approval_by` int(11) DEFAULT NULL,
  `sent_for_approval_at` timestamp NULL DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `closed_by` int(11) DEFAULT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `close_reason` text DEFAULT NULL COMMENT 'Reason for manual close',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_rv_number` (`voucher_number`),
  KEY `idx_rv_destination` (`destination_location_id`),
  KEY `idx_rv_cost_centre` (`cost_centre_id`),
  KEY `idx_rv_status` (`status`),
  KEY `idx_rv_date` (`voucher_date`),
  KEY `idx_rv_created_by` (`created_by`),
  CONSTRAINT `rv_destination_fk` FOREIGN KEY (`destination_location_id`) REFERENCES `locations` (`id`),
  CONSTRAINT `rv_cost_centre_fk` FOREIGN KEY (`cost_centre_id`) REFERENCES `cost_centres` (`id`) ON DELETE SET NULL,
  CONSTRAINT `rv_created_by_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `rv_sent_by_fk` FOREIGN KEY (`sent_for_approval_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `rv_approved_by_fk` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `rv_closed_by_fk` FOREIGN KEY (`closed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- 4. Requisition Voucher Details (line items)
-- =============================================
CREATE TABLE IF NOT EXISTS `requisition_voucher_details` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `requisition_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `requested_quantity` decimal(15,3) NOT NULL,
  `transferred_quantity` decimal(15,3) NOT NULL DEFAULT 0.000 COMMENT 'Cumulative transferred so far',
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rvd_requisition` (`requisition_id`),
  KEY `idx_rvd_product` (`product_id`),
  CONSTRAINT `rvd_requisition_fk` FOREIGN KEY (`requisition_id`) REFERENCES `requisition_vouchers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rvd_product_fk` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- 5. Notifications (general-purpose)
-- =============================================
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL COMMENT 'Recipient',
  `type` varchar(50) NOT NULL COMMENT 'e.g. requisition_approval, transfer_received',
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `link` varchar(500) DEFAULT NULL COMMENT 'URL to navigate to when clicked',
  `reference_id` int(11) DEFAULT NULL COMMENT 'Related record ID',
  `reference_type` varchar(50) DEFAULT NULL COMMENT 'e.g. requisition_voucher, stock_transfer',
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_notif_user` (`user_id`),
  KEY `idx_notif_read` (`user_id`, `is_read`),
  KEY `idx_notif_type` (`type`),
  KEY `idx_notif_created` (`created_at`),
  CONSTRAINT `notif_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- 6. Add requisition_id to stock_transfers
-- =============================================
ALTER TABLE `stock_transfers`
ADD COLUMN `requisition_id` int(11) DEFAULT NULL AFTER `cost_centre_id`,
ADD KEY `idx_st_requisition` (`requisition_id`),
ADD CONSTRAINT `st_requisition_fk` FOREIGN KEY (`requisition_id`) REFERENCES `requisition_vouchers` (`id`) ON DELETE SET NULL;

-- =============================================
-- 7. Add requisition_detail_id to stock_transfer_details
-- =============================================
ALTER TABLE `stock_transfer_details`
ADD COLUMN `requisition_detail_id` int(11) DEFAULT NULL AFTER `product_id`,
ADD KEY `idx_std_requisition_detail` (`requisition_detail_id`),
ADD CONSTRAINT `std_requisition_detail_fk` FOREIGN KEY (`requisition_detail_id`) REFERENCES `requisition_voucher_details` (`id`) ON DELETE SET NULL;
