# Database Reset Scripts

This folder contains SQL scripts to reset your inventory system database for testing purposes.

## Available Scripts

### 1. `reset_database.sql` - Partial Reset (Recommended for Testing)

**What it does:**
- ✅ Deletes all transactional data (sales, purchases, payments, receipts)
- ✅ Deletes all stock data (batches, opening stocks, stock taking)
- ✅ Deletes test users (keeps admin)
- ✅ Deletes test suppliers and customers (keeps default "Cash" customer)
- ✅ Resets ledger account balances
- ✅ Keeps products, categories, and locations (if uncommented)
- ✅ Resets auto-increment counters

**What it keeps:**
- Admin user (username: admin)
- Admin and User roles
- Default Cash customer (id=1)
- Default Cash ledger account (id=1)
- Products, categories, locations (optional - can be deleted by uncommenting)

**Use this when:**
- You want to test transactions from scratch
- You want to keep your master data setup (products, categories, locations)
- You're doing daily/weekly testing cycles

### 2. `reset_database_complete.sql` - Complete Fresh Start

**What it does:**
- ✅ Deletes EVERYTHING from all tables
- ✅ Re-inserts essential system data:
  - Admin user (admin / admin123)
  - Admin and User roles
  - Default Cash account
  - Default Cash customer
  - System settings
- ✅ Resets all auto-increment counters to 1

**Use this when:**
- You want to start completely from scratch
- You're setting up a new instance
- You want to test the complete workflow from setup to transactions

## How to Use

### Option 1: Using phpMyAdmin
1. Login to phpMyAdmin
2. Select your `inventory_system` database
3. Click on "SQL" tab
4. Copy and paste the content of your chosen script
5. Click "Go" to execute

### Option 2: Using MySQL Command Line
```bash
mysql -u root -p inventory_system < reset_database.sql
```
or
```bash
mysql -u root -p inventory_system < reset_database_complete.sql
```

### Option 3: Using MySQL Workbench
1. Open MySQL Workbench
2. Connect to your database
3. File → Open SQL Script
4. Select your chosen script
5. Execute (Lightning bolt icon or Ctrl+Shift+Enter)

## ⚠️ Important Warnings

1. **ALWAYS BACKUP YOUR DATABASE FIRST!**
   ```bash
   mysqldump -u root -p inventory_system > backup_$(date +%Y%m%d_%H%M%S).sql
   ```

2. **These scripts are IRREVERSIBLE!**
   - Once executed, deleted data cannot be recovered (unless you have a backup)

3. **Test in Development Environment First**
   - Never run these on production database
   - Always test on a copy/test database first

## After Running Reset Scripts

### If you used `reset_database.sql`:
1. Login as admin
2. Verify all master data is intact
3. Start entering new transactions
4. Opening stocks will need to be re-entered

### If you used `reset_database_complete.sql`:
1. Login with: **admin / admin123**
2. **CHANGE PASSWORD IMMEDIATELY**
3. Add the following in order:
   - Locations (Masters → Locations)
   - Product Categories (Masters → Categories)
   - Products (Masters → Products)
   - Suppliers (Masters → Suppliers)
   - Customers (Masters → Customers)
   - Opening Stocks (Masters → Opening Stocks)
4. Start transactions

## Verification Queries

After running any reset script, run these queries to verify:

```sql
-- Check record counts
SELECT 'Customers' as 'Table', COUNT(*) as 'Records' FROM customers
UNION ALL SELECT 'Suppliers', COUNT(*) FROM suppliers
UNION ALL SELECT 'Products', COUNT(*) FROM products
UNION ALL SELECT 'Sales', COUNT(*) FROM sales
UNION ALL SELECT 'Purchases', COUNT(*) FROM purchases
UNION ALL SELECT 'Payments', COUNT(*) FROM payments
UNION ALL SELECT 'Receipts', COUNT(*) FROM receipts
UNION ALL SELECT 'Users', COUNT(*) FROM users
UNION ALL SELECT 'Roles', COUNT(*) FROM roles;

-- Check admin user exists
SELECT id, username, name, role_id FROM users WHERE id = 1;

-- Check default customer exists
SELECT id, ledger_account_id FROM customers WHERE id = 1;

-- Check ledger account balance
SELECT id, name, current_balance FROM ledger_accounts WHERE id = 1;
```

## Customization

You can customize the scripts by:

1. **Keeping specific test data:**
   - Comment out the DELETE/TRUNCATE statements for tables you want to keep
   
2. **Deleting specific master data:**
   - Uncomment the TRUNCATE statements for products/categories/locations
   
3. **Changing default values:**
   - Modify the INSERT statements in `reset_database_complete.sql`

## Support

If you encounter any issues:
1. Check MySQL error logs
2. Verify foreign key constraints are disabled (script handles this)
3. Ensure you have proper database permissions
4. Make sure no other connections are using the tables

## Quick Reference

| Script | Best For | Keeps Masters | Keeps Transactions |
|--------|----------|---------------|-------------------|
| `reset_database.sql` | Regular testing | ✅ Yes* | ❌ No |
| `reset_database_complete.sql` | Fresh install | ❌ No | ❌ No |

*Products, categories, locations can be kept (default) or deleted (uncomment)

---
**Remember: Always backup before resetting!**
