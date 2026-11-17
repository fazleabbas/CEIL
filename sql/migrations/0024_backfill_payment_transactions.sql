-- Migration: 0024_backfill_payment_transactions
-- Description: Backfill `transactions` + `transaction_details` rows for legacy
--              payment vouchers that were created before createPaymentTransaction()
--              was wired into the payments flow. Mirrors the logic in
--              includes/functions.php::createPaymentTransaction().
-- Date: 2026-05-26
--
-- IDEMPOTENT:
--   * `transactions` has UNIQUE KEY uniq_voucher (voucher_type, voucher_id)
--     (see migration 0015), so re-running this script will NOT create
--     duplicate transaction rows for the same payment.
--   * `transaction_details` rows are only inserted for transactions that
--     were created by THIS run (we filter on payments whose voucher row
--     did not exist before — i.e. NOT EXISTS in transactions).
--   * Safe to run multiple times.

-- --------------------------------------------------------
-- 1) Insert one `transactions` row per missing payment
-- --------------------------------------------------------
INSERT INTO `transactions`
    (`date`, `voucher_id`, `voucher_type`, `voucher_number`, `narration`,
     `cost_centre_id`, `status`, `created_by`)
SELECT
    p.`payment_date`,
    p.`id`,
    'payment',
    p.`payment_number`,
    CONCAT('Payment ', p.`payment_number`),
    p.`cost_centre_id`,
    'active',
    p.`created_by`
FROM `payments` p
WHERE NOT EXISTS (
    SELECT 1
    FROM `transactions` t
    WHERE t.`voucher_type` = 'payment'
      AND t.`voucher_id`   = p.`id`
);

-- --------------------------------------------------------
-- 2) Insert the two DR/CR `transaction_details` rows for each
--    payment-transaction that currently has NO detail rows.
--    (This catches both freshly-backfilled headers from step 1
--     AND any older headers that lost their details.)
--
--    Mirrors createPaymentTransaction():
--      DR to_account_id     (supplier / expense ledger)
--      CR from_account_id   (cash / bank ledger)
--    rate_of_exchange = COALESCE(NULLIF(exchange_rate_to_base, 0), 1)
--    amount_base      = amount * rate
-- --------------------------------------------------------

-- 2a) DR row -> to_account_id
INSERT INTO `transaction_details`
    (`transaction_id`, `ledger_id`, `currency_id`, `rate_of_exchange`,
     `amount`, `amount_base`, `dr_cr`)
SELECT
    t.`id`,
    p.`to_account_id`,
    p.`currency_id`,
    COALESCE(NULLIF(p.`exchange_rate_to_base`, 0), 1) AS rate,
    p.`amount`,
    p.`amount` * COALESCE(NULLIF(p.`exchange_rate_to_base`, 0), 1),
    'DR'
FROM `transactions` t
JOIN `payments` p
  ON p.`id` = t.`voucher_id`
WHERE t.`voucher_type` = 'payment'
  AND NOT EXISTS (
      SELECT 1
      FROM `transaction_details` td
      WHERE td.`transaction_id` = t.`id`
  );

-- 2b) CR row -> from_account_id
--     (Filter on "exactly 1 detail row" — the DR we just inserted —
--      so re-runs don't add a second CR.)
INSERT INTO `transaction_details`
    (`transaction_id`, `ledger_id`, `currency_id`, `rate_of_exchange`,
     `amount`, `amount_base`, `dr_cr`)
SELECT
    t.`id`,
    p.`from_account_id`,
    p.`currency_id`,
    COALESCE(NULLIF(p.`exchange_rate_to_base`, 0), 1) AS rate,
    p.`amount`,
    p.`amount` * COALESCE(NULLIF(p.`exchange_rate_to_base`, 0), 1),
    'CR'
FROM `transactions` t
JOIN `payments` p
  ON p.`id` = t.`voucher_id`
WHERE t.`voucher_type` = 'payment'
  AND (
      SELECT COUNT(*)
      FROM `transaction_details` td
      WHERE td.`transaction_id` = t.`id`
  ) = 1
  AND NOT EXISTS (
      SELECT 1
      FROM `transaction_details` td2
      WHERE td2.`transaction_id` = t.`id`
        AND td2.`dr_cr`          = 'CR'
  );
