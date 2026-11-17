-- Migration: 0014_add_hierarchy_to_product_categories
-- Description: Add parent_id to product_categories for unlimited nesting hierarchy
-- Date: 2026-02-25

-- --------------------------------------------------------
-- Add parent_id to product_categories table
-- --------------------------------------------------------
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_categories' AND COLUMN_NAME = 'parent_id') = 0,
    'ALTER TABLE `product_categories` ADD COLUMN `parent_id` int(11) DEFAULT NULL AFTER `id`',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index on parent_id for product_categories
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_categories' AND INDEX_NAME = 'idx_product_categories_parent') = 0,
    'ALTER TABLE `product_categories` ADD INDEX `idx_product_categories_parent` (`parent_id`)',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add foreign key for product_categories parent_id
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_categories' AND CONSTRAINT_NAME = 'fk_product_category_parent') = 0,
    'ALTER TABLE `product_categories` ADD CONSTRAINT `fk_product_category_parent` FOREIGN KEY (`parent_id`) REFERENCES `product_categories` (`id`) ON DELETE SET NULL',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
