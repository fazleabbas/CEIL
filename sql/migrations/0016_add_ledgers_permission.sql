-- Migration: 0016_add_ledgers_permission
-- Description: Seed 'ledgers' module permission for Admin (full) and Manager (view-only)
-- Date: 2026-05-07

INSERT IGNORE INTO `user_permissions` (`user_id`, `module_name`, `can_view`, `can_create`, `can_edit`, `can_delete`)
SELECT u.id, 'ledgers', 1, 1, 1, 1
FROM `users` u INNER JOIN `roles` r ON u.role_id = r.id WHERE r.name = 'Admin';

INSERT IGNORE INTO `user_permissions` (`user_id`, `module_name`, `can_view`, `can_create`, `can_edit`, `can_delete`)
SELECT u.id, 'ledgers', 1, 0, 0, 0
FROM `users` u INNER JOIN `roles` r ON u.role_id = r.id WHERE r.name = 'Manager';
