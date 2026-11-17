-- ========================================
-- INVENTORY SYSTEM - COMPLETE FRESH START
-- ========================================
-- This script will reset EVERYTHING to initial state
-- Use this when you want to start completely fresh
-- ========================================

SET FOREIGN_KEY_CHECKS = 0;

-- ========================================
-- DELETE ALL DATA FROM ALL TABLES
-- ========================================

-- Transactional data
TRUNCATE TABLE `sale_details`;
TRUNCATE TABLE `sales`;
TRUNCATE TABLE `purchase_details`;
TRUNCATE TABLE `purchases`;
TRUNCATE TABLE `payments`;
TRUNCATE TABLE `receipts`;
TRUNCATE TABLE `stock_batches`;
TRUNCATE TABLE `opening_stocks`;
TRUNCATE TABLE `stock_taking_details`;
TRUNCATE TABLE `stock_taking`;
TRUNCATE TABLE `audit_log`;

-- Master data
TRUNCATE TABLE `product_pricing`;
TRUNCATE TABLE `products`;
TRUNCATE TABLE `product_categories`;
TRUNCATE TABLE `suppliers`;
TRUNCATE TABLE `customers`;
TRUNCATE TABLE `ledger_accounts`;
TRUNCATE TABLE `locations`;

-- User data
TRUNCATE TABLE `user_permissions`;
TRUNCATE TABLE `user_locations`;
TRUNCATE TABLE `users`;
TRUNCATE TABLE `roles`;

-- Settings (will be re-inserted)
TRUNCATE TABLE `settings`;

SET FOREIGN_KEY_CHECKS = 1;

-- ========================================
-- RE-INSERT ESSENTIAL SYSTEM DATA
-- ========================================

-- Insert default roles
INSERT INTO `roles` (`id`, `name`, `description`, `is_active`) VALUES
(1, 'Admin', 'Full system access', 1),
(2, 'User', 'Limited access based on permissions', 1);

-- Insert default admin user (password: admin123)
INSERT INTO `users` (`id`, `name`, `username`, `password`, `email`, `role_id`, `is_active`) VALUES
(1, 'System Admin', 'admin', '$2y$10$TCZh2LnGrPTdmjc5Sm7u2edLaGGp00vbGgeytcS6bTSo1RcqryaMC', 'admin@example.com', 1, 1);

-- Insert system settings
INSERT INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES
('company_name', 'FIS - LITE', 'Company name'),
('currency_symbol', 'TZS.', 'Currency symbol'),
('date_format', 'd-m-Y', 'Date format for display'),
('valuation_method', 'FIFO', 'Stock valuation method: FIFO, LIFO, or AVERAGE'),
('auto_generate_numbers', '1', 'Auto generate transaction numbers'),
('purchase_prefix', 'PUR', 'Purchase number prefix'),
('sale_prefix', 'SAL', 'Sale number prefix'),
('payment_prefix', 'PAY', 'Payment number prefix'),
('receipt_prefix', 'REC', 'Receipt number prefix'),
('stock_taking_prefix', 'ST', 'Stock taking number prefix');

-- Insert default Cash ledger account
INSERT INTO `ledger_accounts` (`id`, `name`, `account_type`, `opening_balance`, `current_balance`, `is_active`) VALUES
(1, 'Cash', 'cash', 0.00, 0.00, 1);

-- Insert default Cash customer (required for walk-in sales)
INSERT INTO `customers` (`id`, `ledger_account_id`, `contact_person`, `phone`, `email`, `address`, `is_active`) VALUES
(1, 1, 'Walk-in Customer', '', '', '', 1);

-- ========================================
-- RESET AUTO INCREMENT VALUES
-- ========================================

ALTER TABLE `roles` AUTO_INCREMENT = 3;
ALTER TABLE `users` AUTO_INCREMENT = 2;
ALTER TABLE `ledger_accounts` AUTO_INCREMENT = 2;
ALTER TABLE `customers` AUTO_INCREMENT = 2;
ALTER TABLE `suppliers` AUTO_INCREMENT = 1;
ALTER TABLE `locations` AUTO_INCREMENT = 1;
ALTER TABLE `product_categories` AUTO_INCREMENT = 1;
ALTER TABLE `products` AUTO_INCREMENT = 1;
ALTER TABLE `product_pricing` AUTO_INCREMENT = 1;
ALTER TABLE `sales` AUTO_INCREMENT = 1;
ALTER TABLE `sale_details` AUTO_INCREMENT = 1;
ALTER TABLE `purchases` AUTO_INCREMENT = 1;
ALTER TABLE `purchase_details` AUTO_INCREMENT = 1;
ALTER TABLE `payments` AUTO_INCREMENT = 1;
ALTER TABLE `receipts` AUTO_INCREMENT = 1;
ALTER TABLE `stock_batches` AUTO_INCREMENT = 1;
ALTER TABLE `opening_stocks` AUTO_INCREMENT = 1;
ALTER TABLE `stock_taking` AUTO_INCREMENT = 1;
ALTER TABLE `stock_taking_details` AUTO_INCREMENT = 1;
ALTER TABLE `audit_log` AUTO_INCREMENT = 1;
ALTER TABLE `user_permissions` AUTO_INCREMENT = 1;
ALTER TABLE `user_locations` AUTO_INCREMENT = 1;
ALTER TABLE `settings` AUTO_INCREMENT = 11;

-- ========================================
-- VERIFICATION
-- ========================================

SELECT 'Database Reset Complete!' as 'Status';
SELECT '' as '';
SELECT 'ESSENTIAL DATA CREATED:' as 'Info';
SELECT '- Admin user: admin / admin123' as '';
SELECT '- 2 Roles: Admin, User' as '';
SELECT '- 1 Ledger Account: Cash' as '';
SELECT '- 1 Customer: Cash (Walk-in)' as '';
SELECT '- System settings configured' as '';
SELECT '' as '';
SELECT 'You can now add:' as 'Next Steps';
SELECT '1. Locations' as '';
SELECT '2. Product Categories' as '';
SELECT '3. Products' as '';
SELECT '4. Suppliers' as '';
SELECT '5. Customers' as '';
SELECT '6. Opening Stocks' as '';
SELECT '7. Start Transactions!' as '';

-- ========================================
-- NOTES:
-- ========================================
-- Default Login: admin / admin123
-- Change password immediately after first login!
-- ========================================
