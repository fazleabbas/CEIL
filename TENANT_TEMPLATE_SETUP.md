# Tenant Template Setup Guide

## Overview
The tenant template setup script copies your application files from `lite.fis.co.tz` to `/home/fiscotz/tenant_template/`. This template is then used to provision new tenant subdomains.

## Initial Setup

### Step 1: Upload Files to Server
1. Upload all files from your local development to `/home/fiscotz/lite.fis.co.tz` on the server
2. Make sure `lite.fis.co.tz` is working correctly

### Step 2: Run Setup Script
Access the setup script via browser:
```
https://lite.fis.co.tz/api/setup-tenant-template.php?key=SETUP_SECRET_KEY_2026
```

This will:
- Copy all application files to `/home/fiscotz/tenant_template/`
- Exclude unnecessary files (config, logs, test files, etc.)
- Create empty `uploads/` and `exports/` directories
- Generate a `.htaccess` file for security
- Set proper file permissions

### Step 3: Verify Template
Check that the template was created successfully:
- Log into cPanel File Manager
- Navigate to `/home/fiscotz/tenant_template/`
- Verify files are present (index.php, modules/, ajax/, api/, assets/, etc.)

## Provisioning New Tenants

After the template is set up, provision new tenants using:
```
https://lite.fis.co.tz/api/provision-tenant.php?key=PROVISION_SECRET_KEY_2026&id=1
```

This will:
1. Create subdomain (e.g., `fabonic.fis.co.tz`)
2. Create database and user
3. **Copy files from tenant_template** to the new subdomain
4. Generate `config/database.php` with tenant-specific credentials
5. Import database schema
6. Create admin user
7. Send credentials via email and WhatsApp

## Updating Application

When you make changes to your application:

### Step 1: Update lite.fis.co.tz
- Upload your updated files to `/home/fiscotz/lite.fis.co.tz`
- Test that everything works

### Step 2: Update Template
Run the setup script again:
```
https://lite.fis.co.tz/api/setup-tenant-template.php?key=SETUP_SECRET_KEY_2026
```

This updates the template so **new tenants** get the latest version.

### Step 3: Update Existing Tenants (Manual for now)
**Note:** Existing tenants are NOT automatically updated. You need to manually update them or create an update script (we can build this later if needed).

## Files Excluded from Template

The following are NOT copied to the template:
- `.git/` - Git repository
- `.claude/` - Claude Code settings
- `config/database.php` - Generated per tenant
- `uploads/` - Created empty
- `exports/` - Created empty
- `logs/` - Not needed
- `.env` - Environment files
- Test files (test.php, cleanup.html, etc.)

## Troubleshooting

### Problem: "Source directory does not exist"
**Solution:** Make sure files are uploaded to `/home/fiscotz/lite.fis.co.tz`

### Problem: "Failed to create directory"
**Solution:** Check file permissions on `/home/fiscotz/` directory

### Problem: Template has no files
**Solution:** Check the log file at `api/setup-template.log` for errors

### Problem: New tenants missing files
**Solution:** Re-run the setup script to update the template

## Security Notes

- The setup script is protected by a secret key
- Change `SETUP_SECRET_KEY_2026` to something secure in production
- The script logs all operations to `api/setup-template.log`
- Sensitive files (config, .env) are automatically excluded

## Directory Structure

```
/home/fiscotz/
├── lite.fis.co.tz/           # Your main application (source)
├── tenant_template/          # Template for new tenants
│   ├── index.php
│   ├── ajax/
│   ├── api/
│   ├── assets/
│   ├── config/               # Empty (generated per tenant)
│   ├── includes/
│   ├── lang/
│   ├── modules/
│   ├── sql/
│   ├── uploads/              # Empty directory
│   ├── exports/              # Empty directory
│   └── .htaccess
└── public_html/tenants/      # Provisioned tenants
    ├── fabonic/              # Tenant 1
    ├── acmecorp/             # Tenant 2
    └── ...
```

## Next Steps

After setting up the template:
1. Test provisioning a new tenant
2. Verify the tenant subdomain works
3. Check database credentials in `config/database.php`
4. Test login with admin credentials
5. Verify all features work correctly

For questions or issues, check the log files:
- Setup log: `api/setup-template.log`
- Provisioning log: `api/provision.log`
