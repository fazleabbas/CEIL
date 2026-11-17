-- Migration: 0031_add_location_type_to_locations
-- Description: Add optional location_type (site, warehouse, others) to locations
-- Date: 2026-06-14

-- --------------------------------------------------------
-- Add location_type to locations table (nullable, no default)
-- --------------------------------------------------------
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'locations' AND COLUMN_NAME = 'location_type') = 0,
    'ALTER TABLE `locations` ADD COLUMN `location_type` ENUM(''site'',''warehouse'',''others'') DEFAULT NULL AFTER `phone`',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
