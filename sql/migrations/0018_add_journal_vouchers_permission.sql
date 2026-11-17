-- Migration: 0018_add_journal_vouchers_permission
-- Description: Seed 'journal_vouchers' module permission (view-only) so the
--              new Money → Journal Vouchers menu becomes visible to Admin
--              and Manager. Journal vouchers are auto-generated from
--              purchase imports (no manual create/edit/delete here), so we
--              only grant view rights for now.
-- Date: 2026-05-17

INSERT IGNORE INTO `user_permissions` (`user_id`, `module_name`, `can_view`, `can_create`, `can_edit`, `can_delete`)
SELECT u.id, 'journal_vouchers', 1, 0, 0, 0
FROM `users` u INNER JOIN `roles` r ON u.role_id = r.id WHERE r.name = 'Admin';

INSERT IGNORE INTO `user_permissions` (`user_id`, `module_name`, `can_view`, `can_create`, `can_edit`, `can_delete`)
SELECT u.id, 'journal_vouchers', 1, 0, 0, 0
FROM `users` u INNER JOIN `roles` r ON u.role_id = r.id WHERE r.name = 'Manager';
