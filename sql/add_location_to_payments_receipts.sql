-- Add location_id column to payments table
-- This allows tracking which location the payment/expense belongs to
-- NULL means the payment applies to all locations (company-wide expense)

ALTER TABLE `payments`
ADD COLUMN `location_id` INT(11) DEFAULT NULL AFTER `to_account_id`,
ADD INDEX `idx_payments_location` (`location_id`),
ADD CONSTRAINT `payments_location_fk` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE SET NULL;

-- Add location_id column to receipts table
-- This allows tracking which location the receipt belongs to
-- NULL means the receipt applies to all locations

ALTER TABLE `receipts`
ADD COLUMN `location_id` INT(11) DEFAULT NULL AFTER `to_account_id`,
ADD INDEX `idx_receipts_location` (`location_id`),
ADD CONSTRAINT `receipts_location_fk` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE SET NULL;
