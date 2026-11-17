# Auto-Provision Setup Guide

## Overview
The auto-provisioning system automatically provisions tenants from pending trial requests every 5 minutes using a cron job.

## How It Works

1. **User submits trial form** on [landing.php](landing.php)
   - Trial request saved to database with `status = 'pending'` and `tenant_provisioned = 0`
   - Notifications sent to admin and customer

2. **Cron job runs every 5 minutes** ([api/auto-provision-cron.php](api/auto-provision-cron.php))
   - Checks for pending trial requests
   - Provisions up to 5 tenants per run
   - Updates status to `provisioning` during process
   - Calls [api/provision-tenant.php](api/provision-tenant.php) for each pending request

3. **Provisioning completes**
   - Subdomain created
   - Database set up
   - Files deployed
   - Admin user created
   - Credentials sent via email and WhatsApp
   - Status updated to `active` and `tenant_provisioned = 1`

## Setup Instructions

### Method 1: cPanel Cron Jobs (Recommended)

1. **Log into cPanel**

2. **Navigate to Cron Jobs**
   - Find "Cron Jobs" under "Advanced" section

3. **Add New Cron Job**
   - **Common Settings**: Custom
   - **Minute**: */5 (every 5 minutes)
   - **Hour**: * (every hour)
   - **Day**: * (every day)
   - **Month**: * (every month)
   - **Weekday**: * (every weekday)
   - **Command**:
     ```bash
     /usr/bin/php /home/fiscotz/lite.fis.co.tz/api/auto-provision-cron.php
     ```

4. **Save Cron Job**

5. **Verify Setup**
   - Wait 5 minutes
   - Check log file: `api/auto-provision-cron.log`
   - Should see entries like: `[2026-01-25 14:30:00] [INFO] No pending trial requests found`

### Method 2: URL-Based Cron (Alternative)

If your server doesn't support command-line cron jobs, use a URL-based approach:

1. **Set up cron job to call URL**:
   ```bash
   curl -s "https://lite.fis.co.tz/api/auto-provision-cron.php?key=CRON_SECRET_KEY_2026"
   ```

2. **Or use external cron service** (like cron-job.org):
   - URL: `https://lite.fis.co.tz/api/auto-provision-cron.php?key=CRON_SECRET_KEY_2026`
   - Schedule: Every 5 minutes

### Method 3: Manual Execution (Testing)

To test the cron script manually:

**Via Command Line**:
```bash
php /home/fiscotz/lite.fis.co.tz/api/auto-provision-cron.php
```

**Via Browser** (for testing only):
```
https://lite.fis.co.tz/api/auto-provision-cron.php?key=CRON_SECRET_KEY_2026
```

## Configuration

### Change Provisioning Frequency

Edit the cron schedule in cPanel:
- Every 5 minutes: `*/5 * * * *`
- Every 10 minutes: `*/10 * * * *`
- Every 15 minutes: `*/15 * * * *`
- Every hour: `0 * * * *`

### Change Max Provisions Per Run

Edit [api/auto-provision-cron.php](api/auto-provision-cron.php):
```php
define('MAX_PER_RUN', 5);  // Change this number
```

### Change Secret Key

Edit [api/auto-provision-cron.php](api/auto-provision-cron.php):
```php
if (!isset($_GET['key']) || $_GET['key'] !== 'CRON_SECRET_KEY_2026') {
    // Change CRON_SECRET_KEY_2026 to your own secret
```

## Monitoring

### Check Cron Job Logs

**Main cron log**:
```
api/auto-provision-cron.log
```

Shows:
- When cron runs
- How many pending requests found
- Which requests are being provisioned
- Success/failure counts

**Individual provision logs**:
```
api/provision-1.log
api/provision-2.log
...
```

Shows detailed provisioning output for each trial request.

### Check Trial Request Status

Query the database:
```sql
SELECT id, fullname, tenant_subdomain, status, tenant_provisioned, created_at
FROM trial_requests
WHERE status = 'pending' OR status = 'provisioning'
ORDER BY created_at DESC;
```

**Status values**:
- `pending`: Waiting to be provisioned
- `provisioning`: Currently being provisioned
- `active`: Successfully provisioned

## Troubleshooting

### Problem: Cron job not running

**Check cron is set up correctly**:
1. Log into cPanel
2. Navigate to Cron Jobs
3. Verify command path is correct: `/home/fiscotz/lite.fis.co.tz/api/auto-provision-cron.php`

**Check log file**:
- If `auto-provision-cron.log` is empty, cron isn't running
- Check cPanel email for cron error messages

### Problem: Tenants not being provisioned

**Check log file** (`api/auto-provision-cron.log`):
- Look for error messages
- Check if pending requests are found

**Check trial_requests table**:
```sql
SELECT * FROM trial_requests WHERE status = 'pending';
```

**Check provision logs** (`api/provision-1.log`, etc.):
- Look for specific provisioning errors
- Check database credentials, cPanel API access, etc.

### Problem: Some provisions fail

**Check individual provision log**:
```
api/provision-{id}.log
```

Common issues:
- Subdomain already exists (re-run with `force=yes`)
- Database credentials wrong
- Template files missing (run setup-tenant-template.php)
- Schema import failed

**Manual re-provision**:
```
https://lite.fis.co.tz/api/provision-tenant.php?key=PROVISION_SECRET_KEY_2026&id=1&force=yes
```

### Problem: exec() disabled on server

If your server has `exec()` disabled, switch to HTTP method:

Edit [api/auto-provision-cron.php](api/auto-provision-cron.php):
```php
// Change this line in runAutoProvision() function:
if (provisionTenantViaHttp($request['id'])) {  // Use HTTP method
```

## Security Notes

1. **Secret Keys**: Change all secret keys in production:
   - `CRON_SECRET_KEY_2026` in auto-provision-cron.php
   - `PROVISION_SECRET_KEY_2026` in provision-tenant.php
   - `SETUP_SECRET_KEY_2026` in setup-tenant-template.php

2. **File Permissions**: Ensure scripts are not publicly writable:
   ```bash
   chmod 644 api/*.php
   ```

3. **Log Files**: Logs contain sensitive info (passwords). Protect them:
   - Add to .htaccess to block web access
   - Regularly rotate/clean old logs

4. **Rate Limiting**: The script provisions max 5 tenants per run to avoid server overload

## Complete Workflow

```
User Fills Form (landing.php)
    ↓
Trial Request Saved (status: pending, provisioned: 0)
    ↓
Notifications Sent (admin + customer)
    ↓
[Wait up to 5 minutes]
    ↓
Cron Job Runs (auto-provision-cron.php)
    ↓
Finds Pending Requests
    ↓
Marks as "provisioning"
    ↓
Calls provision-tenant.php for each
    ↓
Provisioning Completes
    ↓
Status: active, provisioned: 1
    ↓
Credentials Sent (email + WhatsApp)
    ↓
Tenant Can Login
```

## Testing the Complete Flow

1. **Submit trial request** via landing.php
2. **Check database**: Trial request should have `status = 'pending'`
3. **Wait 5 minutes** (or run cron manually)
4. **Check logs**: `auto-provision-cron.log` and `provision-{id}.log`
5. **Check database**: Trial request should have `status = 'active'` and `tenant_provisioned = 1`
6. **Visit subdomain**: Should show FIS Lite login page
7. **Login**: Use credentials from email/WhatsApp

## Disabling Auto-Provisioning

To temporarily disable auto-provisioning:

**Option 1: Disable cron job**
- Log into cPanel → Cron Jobs
- Delete or comment out the cron job

**Option 2: Rename script**
```bash
mv api/auto-provision-cron.php api/auto-provision-cron.php.disabled
```

**Option 3: Change secret key**
- Edit auto-provision-cron.php and change the key
- Cron will fail auth check

## Re-enabling Manual Provisioning

You can still manually provision tenants even with auto-provisioning enabled:
```
https://lite.fis.co.tz/api/provision-tenant.php?key=PROVISION_SECRET_KEY_2026&id=1
```

Both methods work independently.
