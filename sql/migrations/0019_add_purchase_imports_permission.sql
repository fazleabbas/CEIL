-- Migration: 0019_add_purchase_imports_permission
-- Description: Seed 'purchase_imports' module permission (full rights) for
--              Admin users so the Transactions → Purchase Imports menu and
--              all CRUD actions are available out of the box. Non-admin
--              roles get no rights by default and must be granted access
--              via the user permissions form on a per-user basis.
-- Date: 2026-05-20

INSERT IGNORE INTO `user_permissions` (`user_id`, `module_name`, `can_view`, `can_create`, `can_edit`, `can_delete`)
SELECT u.id, 'purchase_imports', 1, 1, 1, 1
FROM `users` u INNER JOIN `roles` r ON u.role_id = r.id WHERE r.name = 'Admin';
