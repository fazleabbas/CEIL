-- Migration: 0013_add_hierarchy_to_locations_and_cost_centres
-- Description: Add parent_id to locations and cost_centres for unlimited nesting hierarchy
-- Date: 2026-02-18

-- --------------------------------------------------------
-- Add parent_id to locations table
-- --------------------------------------------------------
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'locations' AND COLUMN_NAME = 'parent_id') = 0,
    'ALTER TABLE `locations` ADD COLUMN `parent_id` int(11) DEFAULT NULL AFTER `id`',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index on parent_id for locations
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'locations' AND INDEX_NAME = 'idx_locations_parent') = 0,
    'ALTER TABLE `locations` ADD INDEX `idx_locations_parent` (`parent_id`)',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add foreign key for locations parent_id
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'locations' AND CONSTRAINT_NAME = 'fk_location_parent') = 0,
    'ALTER TABLE `locations` ADD CONSTRAINT `fk_location_parent` FOREIGN KEY (`parent_id`) REFERENCES `locations` (`id`) ON DELETE SET NULL',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------
-- Add parent_id to cost_centres table
-- --------------------------------------------------------
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cost_centres' AND COLUMN_NAME = 'parent_id') = 0,
    'ALTER TABLE `cost_centres` ADD COLUMN `parent_id` int(11) DEFAULT NULL AFTER `id`',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index on parent_id for cost_centres
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cost_centres' AND INDEX_NAME = 'idx_cost_centres_parent') = 0,
    'ALTER TABLE `cost_centres` ADD INDEX `idx_cost_centres_parent` (`parent_id`)',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add foreign key for cost_centres parent_id
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cost_centres' AND CONSTRAINT_NAME = 'fk_cost_centre_parent') = 0,
    'ALTER TABLE `cost_centres` ADD CONSTRAINT `fk_cost_centre_parent` FOREIGN KEY (`parent_id`) REFERENCES `cost_centres` (`id`) ON DELETE SET NULL',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
