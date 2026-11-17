-- FIS LITE Drip Campaign Tables
-- Run this SQL on the central database (litefis) on lite.fis.co.tz

-- Store all trial requests from landing page
CREATE TABLE IF NOT EXISTS `trial_requests` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `reference_no` VARCHAR(50) NOT NULL UNIQUE,
    `fullname` VARCHAR(100) NOT NULL,
    `business_name` VARCHAR(150) NOT NULL,
    `email` VARCHAR(150) NOT NULL,
    `phone` VARCHAR(20) NOT NULL,
    `phone_formatted` VARCHAR(20) NOT NULL COMMENT 'Formatted for WhatsApp (255...)',
    `locations` INT DEFAULT 1,
    `users` INT DEFAULT 5,
    `business_type` VARCHAR(50) DEFAULT NULL,
    `estimated_price` VARCHAR(50) DEFAULT 'TZS 15,000',
    `message` TEXT DEFAULT NULL,
    `language` ENUM('en', 'sw') DEFAULT 'sw',
    `tenant_subdomain` VARCHAR(50) DEFAULT NULL COMMENT 'e.g., clientname (for clientname.fis.co.tz)',
    `tenant_provisioned` TINYINT(1) DEFAULT 0,
    `tenant_provisioned_at` TIMESTAMP NULL,
    `trial_start_date` DATE NOT NULL,
    `trial_end_date` DATE NOT NULL,
    `status` ENUM('pending', 'active', 'converted', 'expired', 'cancelled') DEFAULT 'pending',
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `source` VARCHAR(50) DEFAULT 'landing_page',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_phone` (`phone_formatted`),
    INDEX `idx_status` (`status`),
    INDEX `idx_trial_dates` (`trial_start_date`, `trial_end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Drip message templates configuration
CREATE TABLE IF NOT EXISTS `drip_templates` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `day_number` INT NOT NULL COMMENT 'Day of trial (1, 4, 7, 12, 14)',
    `template_name_en` VARCHAR(100) NOT NULL,
    `template_name_sw` VARCHAR(100) NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `idx_day` (`day_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert the 5 drip templates
INSERT INTO `drip_templates` (`day_number`, `template_name_en`, `template_name_sw`, `description`) VALUES
(1, 'fis_drip_day1', 'fis_drip_day1_sw', 'Welcome + Quick Start'),
(4, 'fis_drip_day4', 'fis_drip_day4_sw', 'Feature Tip - Offline Mode'),
(7, 'fis_drip_day7', 'fis_drip_day7_sw', 'Midpoint Check-in'),
(12, 'fis_drip_day12', 'fis_drip_day12_sw', 'Trial Ending Soon'),
(14, 'fis_drip_day14', 'fis_drip_day14_sw', 'Trial Ended');

-- Log of sent drip messages
CREATE TABLE IF NOT EXISTS `drip_log` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `trial_request_id` INT NOT NULL,
    `drip_template_id` INT NOT NULL,
    `day_number` INT NOT NULL,
    `phone` VARCHAR(20) NOT NULL,
    `template_used` VARCHAR(100) NOT NULL,
    `status` ENUM('sent', 'failed', 'skipped') DEFAULT 'sent',
    `api_response` TEXT DEFAULT NULL,
    `error_message` TEXT DEFAULT NULL,
    `sent_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`trial_request_id`) REFERENCES `trial_requests`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`drip_template_id`) REFERENCES `drip_templates`(`id`),
    INDEX `idx_trial_day` (`trial_request_id`, `day_number`),
    UNIQUE KEY `idx_unique_send` (`trial_request_id`, `day_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
