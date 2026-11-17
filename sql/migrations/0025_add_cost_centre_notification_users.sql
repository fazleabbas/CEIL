-- Migration: 0025_add_cost_centre_notification_users
-- Description: Adds per-cost-centre approver and watcher user lists,
--              plus seeds SMTP settings keys for the email notification feature.
-- Date: 2026-05-26
--
-- Behavior:
--   - cost_centre_approvers: users who should get bell + email notifications
--     for requisition vouchers using this cost centre, AND can approve them
--     (overrides global can_approve permission scope for this CC).
--   - cost_centre_watchers: users who should get bell + email notifications
--     once approval is done (informational only, no approval rights).
--   - If a cost centre has NO rows in cost_centre_approvers, the system falls
--     back to the existing notifyApprovers() behavior (anyone with can_approve).
--   - SMTP keys are seeded with empty values; nothing is sent until the
--     admin configures them via the SMTP Settings page.

-- =============================================
-- 1. Cost centre approvers (route-by-CC)
-- =============================================
CREATE TABLE IF NOT EXISTS `cost_centre_approvers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cost_centre_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_cc_approver` (`cost_centre_id`, `user_id`),
  KEY `idx_cca_cc` (`cost_centre_id`),
  KEY `idx_cca_user` (`user_id`),
  CONSTRAINT `cca_cc_fk` FOREIGN KEY (`cost_centre_id`) REFERENCES `cost_centres` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cca_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- 2. Cost centre watchers (informed-on-approval)
-- =============================================
CREATE TABLE IF NOT EXISTS `cost_centre_watchers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cost_centre_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_cc_watcher` (`cost_centre_id`, `user_id`),
  KEY `idx_ccw_cc` (`cost_centre_id`),
  KEY `idx_ccw_user` (`user_id`),
  CONSTRAINT `ccw_cc_fk` FOREIGN KEY (`cost_centre_id`) REFERENCES `cost_centres` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ccw_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- 3. SMTP setting keys (empty defaults; admin fills via UI)
-- =============================================
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES
('smtp_enabled',    '0',  'Enable SMTP email sending (0/1). When 0 only bell notifications are created.'),
('smtp_host',       '',   'SMTP server hostname (e.g. smtp.gmail.com)'),
('smtp_port',       '587','SMTP server port (587 STARTTLS, 465 SMTPS, 25 plain)'),
('smtp_encryption', 'tls','SMTP encryption: tls, ssl, or none'),
('smtp_username',   '',   'SMTP authentication username'),
('smtp_password',   '',   'SMTP authentication password'),
('smtp_from_email', '',   'From email address used in outgoing messages'),
('smtp_from_name',  '',   'From display name used in outgoing messages'),
('app_base_url',    '',   'Public base URL used to build absolute links in emails (e.g. https://yoursubdomain.fis.co.tz/). Leave empty to use server-derived URL.');
