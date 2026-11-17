-- Migration: 0006_add_brand_to_products
-- Description: Add brand (free-text) column to products table
-- Date: 2026-02-10

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'brand') = 0,
    'ALTER TABLE products ADD COLUMN brand VARCHAR(100) DEFAULT NULL AFTER name',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
