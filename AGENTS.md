# AGENTS.md

## Project Overview
PHP 7.4 inventory management system with MySQL, Bootstrap 5, and jQuery. Mobile-first PWA with offline sync support.

## Commands
- **Run locally:** Access via XAMPP at `http://localhost/simple_fis_sync_mesum_test/`
- **No test framework:** Manual testing via browser

## Architecture
- `config/database.php` - PDO connection, constants (BASE_URL, DB credentials, date formats)
- `includes/functions.php` - Shared utilities (auth, permissions, CSRF, audit logging, formatting)
- `modules/` - UI pages organized by domain: auth/, masters/, transactions/, payments/, reports/
- `api/` - JSON REST endpoints for CRUD and sync operations (e.g., `products.php`, `*-sync.php`)
- `ajax/` - AJAX handlers for UI interactions
- **Database:** MySQL with tables: products, purchases, sales, stock_batches, users, audit_log, etc.

## Migrations
- **Location:** `sql/migrations/`
- **Naming:** Files must be numbered sequentially: `0001_description.sql`, `0002_description.sql`, etc.
- **Check existing files** before creating a new migration to determine the next sequence number
- A cron job picks up and runs migrations in order based on this numbering

## Code Style
- **PHP:** camelCase functions, snake_case DB columns, PSR-style spacing
- **Imports:** Always include `config/database.php` first, then `includes/functions.php`
- **Security:** Use `clean()` for input sanitization, prepared statements (PDO), CSRF via `csrfField()`
- **Responses:** API endpoints use `jsonResponse()` or direct `json_encode()` with proper HTTP codes
- **Auth:** Check `isLoggedIn()` and `hasPermission($module, $action)` at start of protected endpoints
- **Errors:** Use try/catch with PDOException, return JSON `{success: false, error: message}`
- **Audit:** Log changes via `logAudit($module, $action, $recordId, $oldValues, $newValues)`
