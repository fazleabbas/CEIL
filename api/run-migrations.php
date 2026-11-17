<?php
/**
 * FIS LITE - Database Migration Runner
 * Applies pending migrations to all tenant databases
 *
 * Run via CLI:
 *   php run-migrations.php --cron
 *   php run-migrations.php --cron --tenant=subdomain
 *   php run-migrations.php --cron --dry-run
 *
 * Run via Web:
 *   https://lite.fis.co.tz/api/run-migrations.php?key=MIGRATION_SECRET_KEY_2026
 *   https://lite.fis.co.tz/api/run-migrations.php?key=MIGRATION_SECRET_KEY_2026&tenant=subdomain
 *   https://lite.fis.co.tz/api/run-migrations.php?key=MIGRATION_SECRET_KEY_2026&dry-run=1
 */

$isCLI = php_sapi_name() === 'cli';

// Skip authentication if being included from another script (e.g., provision-tenant.php)
$isIncluded = (basename($_SERVER['SCRIPT_FILENAME'] ?? '') !== basename(__FILE__));

// Default values
$singleTenant = null;
$dryRun = false;

// Authentication - only when running directly
if (!$isIncluded) {
    if ($isCLI) {
        if (!in_array('--cron', $argv ?? [])) {
            die("Usage: php run-migrations.php --cron [--tenant=subdomain] [--dry-run]\n");
        }
        foreach ($argv as $arg) {
            if (strpos($arg, '--tenant=') === 0) {
                $singleTenant = substr($arg, 9);
            }
            if ($arg === '--dry-run') {
                $dryRun = true;
            }
        }
    } else {
        if (!isset($_GET['key']) || $_GET['key'] !== 'MIGRATION_SECRET_KEY_2026') {
            http_response_code(403);
            die(json_encode(['success' => false, 'error' => 'Access denied']));
        }
        $singleTenant = $_GET['tenant'] ?? null;
        $dryRun = isset($_GET['dry-run']) && $_GET['dry-run'] === '1';
    }
}

// ============================================
// CONFIGURATION
// ============================================
define('MIGRATIONS_PATH', dirname(__DIR__) . '/sql/migrations');
define('LOG_FILE', __DIR__ . '/run-migrations.log');

// Central database for tenant list (skip if already defined by parent script)
if (!defined('CENTRAL_DB_HOST')) define('CENTRAL_DB_HOST', 'localhost');
if (!defined('CENTRAL_DB_NAME')) define('CENTRAL_DB_NAME', 'fiscotz_litefis');
if (!defined('CENTRAL_DB_USER')) define('CENTRAL_DB_USER', 'fiscotz_liteuser');
if (!defined('CENTRAL_DB_PASS')) define('CENTRAL_DB_PASS', '9,Qgt%RMjry4WqY5');

// cPanel username prefix for database names
if (!defined('CPANEL_USER')) define('CPANEL_USER', 'fiscotz');

// ============================================
// LOGGING
// ============================================
function logMigration($message, $type = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logLine = "[{$timestamp}] [{$type}] {$message}\n";

    global $isCLI;
    if ($isCLI) {
        echo $logLine;
    }

    error_log($logLine, 3, LOG_FILE);
}

// ============================================
// DATABASE CONNECTIONS
// ============================================
function getCentralDb() {
    try {
        $pdo = new PDO(
            "mysql:host=" . CENTRAL_DB_HOST . ";dbname=" . CENTRAL_DB_NAME . ";charset=utf8mb4",
            CENTRAL_DB_USER,
            CENTRAL_DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        return $pdo;
    } catch (PDOException $e) {
        logMigration("Central database connection failed: " . $e->getMessage(), 'ERROR');
        return null;
    }
}

function getTenantDb($dbName, $dbUser, $dbPass) {
    try {
        $pdo = new PDO(
            "mysql:host=localhost;dbname={$dbName};charset=utf8mb4",
            $dbUser,
            $dbPass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true
            ]
        );
        return $pdo;
    } catch (PDOException $e) {
        logMigration("Tenant database connection failed for {$dbName}: " . $e->getMessage(), 'ERROR');
        return null;
    }
}

// ============================================
// GET PROVISIONED TENANTS
// ============================================
function getProvisionedTenants($pdo, $singleTenant = null) {
    $sql = "SELECT id, tenant_subdomain, business_name, status, tenant_provisioned_at
            FROM trial_requests
            WHERE tenant_provisioned = 1
            AND tenant_subdomain IS NOT NULL";

    if ($singleTenant) {
        $sql .= " AND tenant_subdomain = :subdomain";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['subdomain' => $singleTenant]);
    } else {
        $sql .= " ORDER BY tenant_provisioned_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
    }

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================
// GET TENANT DATABASE CREDENTIALS
// ============================================
function getTenantDbCredentials($subdomain) {
    // Database naming convention: fiscotz_subdomain
    // User naming convention: fiscotz_first8chars
    $dbName = CPANEL_USER . '_' . $subdomain;
    $dbUser = CPANEL_USER . '_' . substr($subdomain, 0, 8);

    // Try to read credentials from tenant's config file
    $configPath = "/home/fiscotz/public_html/tenants/{$subdomain}/config/database.php";

    if (file_exists($configPath)) {
        $configContent = file_get_contents($configPath);

        // Extract DB credentials using regex
        if (preg_match("/define\s*\(\s*['\"]DB_NAME['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $configContent, $matches)) {
            $dbName = $matches[1];
        }
        if (preg_match("/define\s*\(\s*['\"]DB_USER['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $configContent, $matches)) {
            $dbUser = $matches[1];
        }
        if (preg_match("/define\s*\(\s*['\"]DB_PASS['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $configContent, $matches)) {
            $dbPass = $matches[1];
            return ['db_name' => $dbName, 'db_user' => $dbUser, 'db_pass' => $dbPass];
        }
    }

    logMigration("Could not read credentials for tenant: {$subdomain}", 'ERROR');
    return null;
}

// ============================================
// MIGRATIONS TABLE MANAGEMENT
// ============================================
function ensureMigrationsTable($pdo) {
    $sql = "CREATE TABLE IF NOT EXISTS `migrations` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `migration` VARCHAR(255) NOT NULL,
        `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `migration_unique` (`migration`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $pdo->exec($sql);
    return true;
}

function getAppliedMigrations($pdo) {
    $stmt = $pdo->query("SELECT migration FROM migrations ORDER BY migration ASC");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function recordMigration($pdo, $migrationName) {
    $stmt = $pdo->prepare("INSERT INTO migrations (migration) VALUES (:migration)");
    return $stmt->execute(['migration' => $migrationName]);
}

// ============================================
// MIGRATION FILE OPERATIONS
// ============================================
function getMigrationFiles() {
    $migrations = [];

    if (!is_dir(MIGRATIONS_PATH)) {
        logMigration("Migrations directory not found: " . MIGRATIONS_PATH, 'WARNING');
        return $migrations;
    }

    $files = scandir(MIGRATIONS_PATH);

    foreach ($files as $file) {
        // Match files like: 0001_migration_name.sql
        if (preg_match('/^(\d{4})_(.+)\.sql$/', $file, $matches)) {
            $migrations[] = [
                'file' => $file,
                'number' => $matches[1],
                'name' => $matches[2],
                'path' => MIGRATIONS_PATH . '/' . $file
            ];
        }
    }

    // Sort by migration number
    usort($migrations, function($a, $b) {
        return strcmp($a['number'], $b['number']);
    });

    return $migrations;
}

function executeMigration($pdo, $migrationPath, $dryRun = false, $dbName = null, $dbUser = null, $dbPass = null) {
    $sql = file_get_contents($migrationPath);

    if (empty(trim($sql))) {
        return ['success' => true, 'message' => 'Empty migration file'];
    }

    if ($dryRun) {
        return ['success' => true, 'message' => 'Would execute migration'];
    }

    // Use mysql CLI if credentials provided (more reliable for multi-statement SQL)
    if ($dbName && $dbUser && $dbPass) {
        $command = "mysql -u {$dbUser} -p'{$dbPass}' {$dbName} < " . escapeshellarg($migrationPath) . " 2>&1";
        exec($command, $output, $returnCode);
        
        if ($returnCode === 0) {
            return ['success' => true, 'message' => 'Migration executed via CLI'];
        } else {
            return ['success' => false, 'message' => implode("\n", $output)];
        }
    }

    // Fallback to PDO (for backwards compatibility)
    try {
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            function($stmt) {
                return !empty($stmt) && $stmt !== '';
            }
        );

        foreach ($statements as $statement) {
            $cleanStatement = preg_replace('/--.*$/m', '', $statement);
            $cleanStatement = preg_replace('/\/\*.*?\*\//s', '', $cleanStatement);
            $cleanStatement = trim($cleanStatement);

            if (empty($cleanStatement)) {
                continue;
            }

            $pdo->exec($statement);
        }

        return ['success' => true, 'message' => 'Migration executed successfully'];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// ============================================
// MAIN MIGRATION RUNNER
// ============================================
function runMigrationsForTenant($subdomain, $dryRun = false) {
    $credentials = getTenantDbCredentials($subdomain);

    if (!$credentials) {
        return ['success' => false, 'error' => 'Could not get database credentials'];
    }

    $pdo = getTenantDb($credentials['db_name'], $credentials['db_user'], $credentials['db_pass']);

    if (!$pdo) {
        return ['success' => false, 'error' => 'Could not connect to tenant database'];
    }

    // Ensure migrations table exists
    ensureMigrationsTable($pdo);

    // Get applied migrations
    $appliedMigrations = getAppliedMigrations($pdo);

    // Get all migration files
    $allMigrations = getMigrationFiles();

    // Find pending migrations
    $pendingMigrations = array_filter($allMigrations, function($migration) use ($appliedMigrations) {
        return !in_array($migration['file'], $appliedMigrations);
    });

    $results = [
        'success' => true,
        'applied' => [],
        'skipped' => count($appliedMigrations),
        'failed' => []
    ];

    foreach ($pendingMigrations as $migration) {
        $migrationName = $migration['file'];

        logMigration("  Applying: {$migrationName}" . ($dryRun ? " [DRY-RUN]" : ""));

        // Use mysql CLI with credentials for reliable execution
        $result = executeMigration($pdo, $migration['path'], $dryRun, $credentials['db_name'], $credentials['db_user'], $credentials['db_pass']);

        if ($result['success']) {
            if (!$dryRun) {
                recordMigration($pdo, $migrationName);
            }
            $results['applied'][] = $migrationName;
        } else {
            logMigration("  FAILED: {$result['message']}", 'ERROR');
            $results['failed'][] = ['migration' => $migrationName, 'error' => $result['message']];
            $results['success'] = false;
            // Stop on first failure to maintain consistency
            break;
        }
    }

    return $results;
}

function runAllMigrations($singleTenant = null, $dryRun = false) {
    $startTime = microtime(true);

    $results = [
        'success' => true,
        'tenants_processed' => 0,
        'tenants_failed' => 0,
        'total_migrations_applied' => 0,
        'details' => []
    ];

    logMigration("========================================");
    logMigration("Starting Migration Runner" . ($dryRun ? " [DRY-RUN]" : ""));
    logMigration("========================================");

    // Check migrations directory
    if (!is_dir(MIGRATIONS_PATH)) {
        logMigration("Creating migrations directory: " . MIGRATIONS_PATH);
        mkdir(MIGRATIONS_PATH, 0755, true);
    }

    // Get migration files
    $allMigrations = getMigrationFiles();
    logMigration("Found " . count($allMigrations) . " migration file(s)");

    if (empty($allMigrations)) {
        logMigration("No migration files to apply");
        $results['message'] = 'No migrations to apply';
        return $results;
    }

    // Connect to central database
    $pdo = getCentralDb();
    if (!$pdo) {
        $results['success'] = false;
        $results['error'] = 'Central database connection failed';
        return $results;
    }

    // Get provisioned tenants
    $tenants = getProvisionedTenants($pdo, $singleTenant);

    if (empty($tenants)) {
        logMigration("No provisioned tenants found" . ($singleTenant ? " matching '{$singleTenant}'" : ""));
        $results['message'] = 'No tenants to migrate';
        return $results;
    }

    logMigration("Found " . count($tenants) . " tenant(s) to process");

    // Process each tenant
    foreach ($tenants as $tenant) {
        $subdomain = $tenant['tenant_subdomain'];

        logMigration("----------------------------------------");
        logMigration("Processing tenant: {$subdomain} ({$tenant['business_name']})");

        $tenantResult = runMigrationsForTenant($subdomain, $dryRun);

        if ($tenantResult['success']) {
            $results['tenants_processed']++;
            $appliedCount = count($tenantResult['applied']);
            $results['total_migrations_applied'] += $appliedCount;

            if ($appliedCount > 0) {
                logMigration("  Applied {$appliedCount} migration(s)");
            } else {
                logMigration("  Already up to date (skipped {$tenantResult['skipped']})");
            }
        } else {
            $results['tenants_failed']++;
            logMigration("  FAILED: " . ($tenantResult['error'] ?? 'Unknown error'), 'ERROR');
        }

        $results['details'][$subdomain] = $tenantResult;
    }

    $duration = round(microtime(true) - $startTime, 2);

    logMigration("========================================");
    logMigration("Migration Complete in {$duration}s");
    logMigration("Tenants: {$results['tenants_processed']} OK, {$results['tenants_failed']} failed");
    logMigration("Total migrations applied: {$results['total_migrations_applied']}");
    logMigration("========================================");

    $results['duration'] = $duration;
    $results['success'] = $results['tenants_failed'] === 0;

    return $results;
}

// ============================================
// STANDALONE MIGRATION FUNCTION (for provision-tenant.php)
// ============================================
/**
 * Run all migrations for a single tenant database
 * Called from provision-tenant.php after importing base schema
 *
 * @param string $dbName Database name
 * @param string $dbUser Database user
 * @param string $dbPass Database password
 * @return bool Success status
 */
function runMigrationsForNewTenant($dbName, $dbUser, $dbPass) {
    logMigration("Running migrations for new tenant database: {$dbName}");

    try {
        $pdo = new PDO(
            "mysql:host=localhost;dbname={$dbName};charset=utf8mb4",
            $dbUser,
            $dbPass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true
            ]
        );
    } catch (PDOException $e) {
        logMigration("Could not connect to tenant database: " . $e->getMessage(), 'ERROR');
        return false;
    }

    // Ensure migrations table exists
    ensureMigrationsTable($pdo);

    // Get all migration files
    $allMigrations = getMigrationFiles();

    if (empty($allMigrations)) {
        logMigration("No migration files found, skipping");
        return true;
    }

    logMigration("Found " . count($allMigrations) . " migration(s) to apply");

    $appliedCount = 0;
    $failedCount = 0;

    foreach ($allMigrations as $migration) {
        $migrationName = $migration['file'];

        logMigration("  Applying: {$migrationName}");

        // Use mysql CLI with credentials for reliable execution
        $result = executeMigration($pdo, $migration['path'], false, $dbName, $dbUser, $dbPass);

        if ($result['success']) {
            recordMigration($pdo, $migrationName);
            $appliedCount++;
        } else {
            logMigration("  FAILED: {$result['message']}", 'ERROR');
            $failedCount++;
            // Continue with other migrations (they might be independent)
        }
    }

    logMigration("Migrations complete: {$appliedCount} applied, {$failedCount} failed");

    return $failedCount === 0;
}

// ============================================
// EXECUTION
// ============================================
// Only run if executed directly (not included from provision-tenant.php)
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $result = runAllMigrations($singleTenant, $dryRun);

    if ($isCLI) {
        exit($result['success'] ? 0 : 1);
    } else {
        header('Content-Type: application/json');
        echo json_encode($result, JSON_PRETTY_PRINT);
    }
}
