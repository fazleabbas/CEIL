-- ========================================
-- INVENTORY SYSTEM - RESET TO CLEAN STATE
-- ========================================
-- This script will delete all transactional and test data
-- while keeping essential system configuration
-- ========================================

SET FOREIGN_KEY_CHECKS = 0;

-- ========================================
-- TRANSACTIONAL DATA (Safe to Delete)
-- ========================================

-- Sales and related data
DELETE FROM `sale_details`;
DELETE FROM `sales`;

-- Purchases and related data
DELETE FROM `purchase_details`;
DELETE FROM `purchases`;

-- Payments and Receipts
DELETE FROM `payments`;
DELETE FROM `receipts`;

-- Stock related
DELETE FROM `stock_batches`;
DELETE FROM `opening_stocks`;
DELETE FROM `stock_taking_details`;
DELETE FROM `stock_taking`;

-- Audit logs (optional - keep if you want history)
DELETE FROM `audit_log`;

-- ========================================
-- MASTER DATA (Delete test entries only)
-- ========================================

-- Delete all customers EXCEPT the default "Cash" customer (id=1)
DELETE FROM `customers` WHERE id != 1;

-- Delete all suppliers (can keep if you want)
DELETE FROM `suppliers`;

-- Delete all ledger accounts EXCEPT the default "Cash" account (id=1)
DELETE FROM `ledger_accounts` WHERE id != 1;

-- Reset Cash account balance to initial state
UPDATE `ledger_accounts` 
SET current_balance = opening_balance,
    updated_at = NOW()
WHERE id = 1;


-- Delete product pricing (location-specific prices)
DELETE FROM `product_pricing`;

-- Delete all products (uncomment if you want to start fresh)
DELETE FROM `products`;

-- Delete all product categories (uncomment if you want to start fresh)
DELETE FROM `product_categories`;

-- Delete all locations (uncomment if you want to start fresh)
DELETE FROM `locations`;

-- ========================================
-- USER DATA (Keep admin, delete test users)
-- ========================================

-- Delete user permissions for non-admin users
DELETE FROM `user_permissions` WHERE user_id != 1;

-- Delete user locations for non-admin users
DELETE FROM `user_locations` WHERE user_id != 1;

-- Delete all users EXCEPT admin (id=1)
DELETE FROM `users` WHERE id != 1;

-- Delete all roles EXCEPT Admin and User (id=1 and id=2)
DELETE FROM `roles` WHERE id > 2;

-- ========================================
-- RESET AUTO INCREMENT VALUES
-- ========================================

-- Reset auto increment for transactional tables
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

-- Reset auto increment for master data (based on what you kept)
-- Uncomment these if you truncated the respective tables
ALTER TABLE `products` AUTO_INCREMENT = 1;
ALTER TABLE `product_categories` AUTO_INCREMENT = 1;
ALTER TABLE `locations` AUTO_INCREMENT = 1;

SET FOREIGN_KEY_CHECKS = 1;

-- ========================================
-- VERIFICATION QUERIES
-- ========================================
-- Run these to verify the cleanup

SELECT 'Customers' as 'Table', COUNT(*) as 'Records' FROM customers
UNION ALL
SELECT 'Suppliers', COUNT(*) FROM suppliers
UNION ALL
SELECT 'Products', COUNT(*) FROM products
UNION ALL
SELECT 'Categories', COUNT(*) FROM product_categories
UNION ALL
SELECT 'Locations', COUNT(*) FROM locations
UNION ALL
SELECT 'Sales', COUNT(*) FROM sales
UNION ALL
SELECT 'Purchases', COUNT(*) FROM purchases
UNION ALL
SELECT 'Payments', COUNT(*) FROM payments
UNION ALL
SELECT 'Receipts', COUNT(*) FROM receipts
UNION ALL
SELECT 'Stock Batches', COUNT(*) FROM stock_batches
UNION ALL
SELECT 'Opening Stocks', COUNT(*) FROM opening_stocks
UNION ALL
SELECT 'Users', COUNT(*) FROM users
UNION ALL
SELECT 'Roles', COUNT(*) FROM roles
UNION ALL
SELECT 'Ledger Accounts', COUNT(*) FROM ledger_accounts;

-- ========================================
-- NOTES:
-- ========================================
-- 1. This script keeps:
--    - Admin user (id=1)
--    - Admin and User roles (id=1,2)
--    - Default Cash customer (id=1)
--    - Default Cash ledger account (id=1)
--    - All products, categories, and locations (commented out)
--
-- 2. If you want to also delete master data, uncomment
--    the respective TRUNCATE statements above
--
-- 3. After running this, you can start entering fresh
--    transactions without losing your basic setup
--
-- 4. Always backup your database before running this!
-- ========================================
