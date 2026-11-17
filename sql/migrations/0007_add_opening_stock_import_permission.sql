-- Migration: 0007_add_opening_stock_import_permission
-- Description: Grant can_import permission on opening_stocks module for users who have create permission
-- Date: 2026-02-10

-- Grant import permission to users who already have create permission on opening_stocks
UPDATE `user_permissions`
SET `can_import` = 1
WHERE `module_name` = 'opening_stocks' AND `can_create` = 1;

-- For users that don't have an opening_stocks row yet but are admins,
-- ensure opening_stocks row exists with import permission
-- (Admins typically already have all module rows, so this is a safety net)
INSERT IGNORE INTO `user_permissions` (`user_id`, `module_name`, `can_view`, `can_create`, `can_edit`, `can_delete`, `can_import`)
SELECT u.id, 'opening_stocks', 1, 1, 1, 1, 1
FROM `users` u
WHERE u.role_id = 1
AND u.id NOT IN (
    SELECT user_id FROM `user_permissions` WHERE module_name = 'opening_stocks'
);
