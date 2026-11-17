-- Fix trial_requests status enum to include 'provisioning' status
-- Run this on the production database

ALTER TABLE `trial_requests` 
MODIFY COLUMN `status` enum('pending','provisioning','active','converted','expired','cancelled') DEFAULT 'pending';

-- Also fix the current broken record (ID 34)
UPDATE `trial_requests` 
SET `status` = 'pending' 
WHERE `id` = 34 AND `status` = '';
