-- Subscription Management Tables
-- Run this SQL in phpMyAdmin

-- Main subscription table (one per installation)
CREATE TABLE IF NOT EXISTS `subscriptions` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `plan_name` VARCHAR(50) DEFAULT 'Basic',
    `max_users` INT NOT NULL DEFAULT 1,
    `max_locations` INT NOT NULL DEFAULT 1,
    `daily_rate` DECIMAL(10,2) NOT NULL DEFAULT 1000.00,
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `grace_days` INT DEFAULT 5,
    `is_trial` TINYINT(1) DEFAULT 0,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Payment records
CREATE TABLE IF NOT EXISTS `subscription_payments` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `subscription_id` INT NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `payment_method` VARCHAR(50) DEFAULT 'Airtel Money',
    `transaction_ref` VARCHAR(100),
    `payment_date` DATE NOT NULL,
    `period_start` DATE NOT NULL,
    `period_end` DATE NOT NULL,
    `users_count` INT NOT NULL,
    `locations_count` INT NOT NULL,
    `notes` TEXT,
    `confirmed_by` INT,
    `confirmed_at` TIMESTAMP NULL,
    `status` ENUM('pending', 'confirmed', 'rejected') DEFAULT 'pending',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`subscription_id`) REFERENCES `subscriptions`(`id`),
    FOREIGN KEY (`confirmed_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Upgrade requests (for pro-rata additions)
CREATE TABLE IF NOT EXISTS `subscription_upgrades` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `subscription_id` INT NOT NULL,
    `upgrade_type` ENUM('user', 'location') NOT NULL,
    `quantity` INT NOT NULL DEFAULT 1,
    `prorata_amount` DECIMAL(12,2) NOT NULL,
    `remaining_days` INT NOT NULL,
    `payment_status` ENUM('pending', 'confirmed', 'rejected') DEFAULT 'pending',
    `transaction_ref` VARCHAR(100),
    `confirmed_by` INT,
    `confirmed_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`subscription_id`) REFERENCES `subscriptions`(`id`),
    FOREIGN KEY (`confirmed_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default trial subscription
INSERT INTO `subscriptions` (`plan_name`, `max_users`, `max_locations`, `daily_rate`, `start_date`, `end_date`, `is_trial`, `is_active`)
VALUES ('Trial', 1, 1, 1000.00, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 14 DAY), 1, 1);
