-- Units Table for Dynamic Unit Management
-- Run this script to create the units table and migrate existing hardcoded units

-- Create units table
CREATE TABLE IF NOT EXISTS `units` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `name` varchar(50) NOT NULL,
    `is_active` tinyint(1) NOT NULL DEFAULT 1,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migrate existing hardcoded units
INSERT IGNORE INTO `units` (`name`, `is_active`) VALUES
('Piece', 1),
('Kg', 1),
('Gram', 1),
('Liter', 1),
('Milliliter', 1),
('Box', 1),
('Packet', 1),
('Dozen', 1),
('Meter', 1),
('Carton', 1);

-- Add any units that exist in products table but not in units table
INSERT IGNORE INTO `units` (`name`, `is_active`)
SELECT DISTINCT unit, 1 FROM products WHERE unit IS NOT NULL AND unit != '' AND unit NOT IN (SELECT name FROM units);

ALTER TABLE units CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
