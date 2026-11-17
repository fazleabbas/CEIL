-- Migration: 0036_add_manual_journal_vouchers
-- Description: Enable manually-created journal vouchers (Money → Journal
--              Vouchers → "New Journal Voucher"). These are standalone
--              double-entry vouchers with no parent source document.
--
--              Changes (all additive / backward-compatible):
--                1) Add 'manual_journal' to transactions.voucher_type ENUM.
--                2) Make transactions.voucher_id NULLABLE so manual JVs (which
--                   have no parent voucher) can store NULL. The existing
--                   uniq_voucher (voucher_type, voucher_id) key tolerates many
--                   NULLs, so manual JVs never collide. Existing rows keep
--                   their non-null voucher_id untouched.
--                3) Grant create/edit/delete on 'journal_vouchers' to Admin so
--                   the new actions are usable. Manager stays view-only.
-- Date: 2026-06-24

-- --------------------------------------------------------
-- 1) Extend voucher_type ENUM with 'manual_journal'
--    (MODIFY is naturally idempotent — running twice is a no-op.)
-- --------------------------------------------------------
ALTER TABLE `transactions`
  MODIFY COLUMN `voucher_type`
  ENUM('purchase','sales','receipt','payment','purchase_import','journal','manual_journal') NOT NULL;

-- --------------------------------------------------------
-- 2) Make voucher_id NULLABLE (manual JVs have no parent voucher_id)
--    Idempotent — re-running just re-applies the same definition.
-- --------------------------------------------------------
ALTER TABLE `transactions`
  MODIFY COLUMN `voucher_id` int(11) NULL;

-- --------------------------------------------------------
-- 3) Grant create/edit/delete on journal_vouchers to Admin.
--    INSERT IGNORE first (covers any Admin missing the row), then UPDATE
--    (flips the flags on existing view-only rows seeded by migration 0018).
-- --------------------------------------------------------
INSERT IGNORE INTO `user_permissions` (`user_id`, `module_name`, `can_view`, `can_create`, `can_edit`, `can_delete`)
SELECT u.id, 'journal_vouchers', 1, 1, 1, 1
FROM `users` u INNER JOIN `roles` r ON u.role_id = r.id WHERE r.name = 'Admin';

UPDATE `user_permissions` up
INNER JOIN `users` u ON up.user_id = u.id
INNER JOIN `roles` r ON u.role_id = r.id
SET up.can_create = 1, up.can_edit = 1, up.can_delete = 1
WHERE up.module_name = 'journal_vouchers' AND r.name = 'Admin';
