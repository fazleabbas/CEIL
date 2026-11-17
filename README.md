# Inventory Management System

A mobile-first inventory management system built with PHP 7.4, MySQL, and Bootstrap 5.

## Features

- ✅ Mobile-first responsive design
- ✅ Multi-location support
- ✅ User roles and permissions
- ✅ FIFO/LIFO/Average stock valuation
- ✅ Purchase and sales management
- ✅ Payments and receipts tracking
- ✅ Stock taking with variance reports
- ✅ Comprehensive reports
- ✅ Excel import/export
- ✅ Audit trail

## System Requirements

- PHP 7.4 or higher
- MySQL 5.7 or higher / MariaDB 10.3 or higher
- Apache/Nginx web server
- PHP Extensions:
  - PDO
  - PDO_MySQL
  - mbstring
  - json

## Installation

### Step 1: Database Setup

1. Create a new MySQL database named `inventory_system`
2. Import the database schema:
   ```bash
   mysql -u root -p inventory_system < database_schema.sql
   ```

### Step 2: Configure Database Connection

1. Open `config/database.php`
2. Update the following settings:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_USER', 'your_username');
   define('DB_PASS', 'your_password');
   define('DB_NAME', 'inventory_system');
   ```

### Step 3: Update Base URL

1. Open `config/database.php`
2. Update the BASE_URL:
   ```php
   define('BASE_URL', 'http://your-domain.com/inventory-system/');
   ```

### Step 4: Set Permissions

```bash
# Make uploads and exports directories writable
chmod 755 uploads/
chmod 755 exports/

# Secure config directory (optional)
chmod 750 config/
```

### Step 5: Test Installation

1. Open your browser and navigate to your BASE_URL
2. You should be redirected to the login page
3. Login with default credentials:
   - **Username:** admin
   - **Password:** admin123

### Step 6: Change Default Password

⚠️ **IMPORTANT:** Immediately change the admin password after first login!

1. Click on your name in the top-right corner
2. Select "Change Password"
3. Enter a strong new password

## Default User

- **Username:** admin
- **Password:** admin123
- **Role:** Admin (full access)

## Directory Structure

```
inventory-system/
├── config/
│   └── database.php          # Database configuration
├── includes/
│   ├── header.php             # Common header
│   ├── footer.php             # Common footer
│   └── functions.php          # Utility functions
├── assets/
│   ├── css/
│   │   └── style.css          # Custom styles
│   ├── js/
│   │   └── main.js            # JavaScript utilities
│   └── images/
├── modules/
│   ├── auth/
│   │   ├── login.php          # Login page
│   │   ├── logout.php         # Logout
│   │   └── change_password.php
│   ├── masters/               # Master data CRUD
│   ├── transactions/          # Purchases & Sales
│   ├── payments/              # Payments & Receipts
│   └── reports/               # Reports
├── ajax/                      # AJAX handlers
├── uploads/                   # Uploaded files
├── exports/                   # Generated exports
├── .htaccess                  # Apache configuration
└── index.php                  # Dashboard
```

## Security Features

- Password hashing using PHP's password_hash()
- Prepared statements to prevent SQL injection
- CSRF token protection
- Session security
- Input sanitization
- Audit trail logging
- Role-based access control

## Mobile Optimization

- Touch-friendly interface
- Minimum 44px touch targets
- Responsive tables with horizontal scroll
- Optimized navigation for small screens
- Large, easy-to-tap buttons
- 16px font size to prevent zoom on iOS

## Usage Guide

### 1. Initial Setup

After installation, set up your system in this order:

1. **Locations** - Add your warehouses/stores
2. **Categories** - Create product categories
3. **Products** - Add your products
4. **Suppliers** - Add your suppliers
5. **Customers** - Add customers (optional)
6. **Users** - Create user accounts
7. **Opening Stocks** - Enter initial stock

### 2. Daily Operations

**Making a Purchase:**
1. Go to Transactions > Purchases
2. Select date, supplier, and location
3. Add products with quantities and rates
4. Save the purchase

**Making a Sale:**
1. Go to Transactions > Sales
2. Select location once
3. Search and click on products to sell
4. Click multiple times to increase quantity
5. Use minus button to decrease
6. Products are arranged by today's sales

**Stock Taking:**
1. Go to Reports > Stock Taking
2. Select location and date
3. Enter physical stock for each product
4. System automatically calculates variances

### 3. Reports

- **Stock Report:** View current stock by location
- **Stock Taking:** Physical verification with variance
- **Profit & Loss:** Calculate profit using FIFO/LIFO/Average

## User Management

### Creating Users

1. Go to Masters > Users
2. Click "Add New User"
3. Fill in user details
4. Assign role and permissions
5. Select accessible locations

### User Permissions

Users can have granular permissions:
- **View:** Can see the module
- **Create:** Can add new records
- **Edit:** Can modify existing records
- **Delete:** Can delete records

## Troubleshooting

### Login Issues

**Problem:** Can't login with admin credentials
**Solution:** 
- Ensure database was imported correctly
- Check if the users table has the admin record
- Reset password manually in the database

### Permission Errors

**Problem:** "Permission denied" errors
**Solution:**
- Check that uploads/ and exports/ directories are writable
- Verify .htaccess is working
- Check PHP error logs

### Database Connection Failed

**Problem:** Can't connect to database
**Solution:**
- Verify database credentials in config/database.php
- Ensure MySQL service is running
- Check if the database exists

### Images/CSS Not Loading

**Problem:** Styles or images not showing
**Solution:**
- Verify BASE_URL in config/database.php
- Check .htaccess is present
- Clear browser cache

## Support

For issues or questions:
1. Check the documentation
2. Review error logs in PHP error log
3. Check audit_log table for activity logs

## Changelog

### Version 1.0 (Initial Release)
- Complete inventory management system
- Mobile-first responsive design
- Multi-location support
- User roles and permissions
- FIFO/LIFO/Average costing
- Purchase and sales management
- Stock taking with variance
- Comprehensive reports

## License

Proprietary - All rights reserved

## Credits

- Built with PHP 7.4
- Bootstrap 5 for UI
- Bootstrap Icons
- jQuery for DOM manipulation
