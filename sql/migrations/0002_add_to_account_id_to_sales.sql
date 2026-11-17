-- Migration: Add to_account_id to sales table
-- This stores the cash/bank account used for automatic receipt creation
-- Date: 2026-01-30

ALTER TABLE `sales`
ADD COLUMN `to_account_id` INT(11) DEFAULT NULL AFTER `payment_mode`,
ADD INDEX `idx_sales_to_account` (`to_account_id`);

-- Add foreign key constraint (optional - comment out if causing issues)
-- ALTER TABLE `sales`
-- ADD CONSTRAINT `fk_sales_to_account` FOREIGN KEY (`to_account_id`) REFERENCES `ledger_accounts` (`id`) ON DELETE SET NULL;
