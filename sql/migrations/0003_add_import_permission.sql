-- Migration: Add can_import column to user_permissions table
-- Run this on existing databases to enable the product import feature

-- Add can_import column if it doesn't exist
ALTER TABLE `user_permissions` 
ADD COLUMN IF NOT EXISTS `can_import` tinyint(1) DEFAULT 0 AFTER `can_delete`;

-- For MySQL versions that don't support IF NOT EXISTS in ALTER TABLE, use this instead:
-- ALTER TABLE `user_permissions` ADD COLUMN `can_import` tinyint(1) DEFAULT 0 AFTER `can_delete`;

-- Grant import permission to users who already have create permission on products
UPDATE `user_permissions` 
SET `can_import` = 1 
WHERE `module_name` = 'products' AND `can_create` = 1;
