<?php
/**
 * FIS LITE - Auto Provision Cron Job
 * Automatically provisions pending trial requests
 *
 * SETUP IN CPANEL CRON JOBS:
 * Command: /usr/bin/php /home/fiscotz/lite.fis.co.tz/api/auto-provision-cron.php
 * Schedule: Run every 5 minutes (cron: asterisk-slash-5 asterisk asterisk asterisk asterisk)
 *
 * Or via URL (less secure):
 * curl -s "https://lite.fis.co.tz/api/auto-provision-cron.php?key=CRON_SECRET_KEY_2026"
 */

// ============================================
// SECURITY
// ============================================

// Log script start
$startTime = date('Y-m-d H:i:s');
error_log("[{$startTime}] [INFO] Auto-provision cron script started\n", 3, __DIR__ . '/auto-provision-cron.log');

// If running via web, require secret key
if (php_sapi_name() !== 'cli') {
    error_log("[{$startTime}] [INFO] Running via web (not CLI)\n", 3, __DIR__ . '/auto-provision-cron.log');
    if (!isset($_GET['key']) || $_GET['key'] !== 'CRON_SECRET_KEY_2026') {
        error_log("[{$startTime}] [ERROR] Access denied - invalid key\n", 3, __DIR__ . '/auto-provision-cron.log');
        http_response_code(403);
        die('Access denied');
    }
    error_log("[{$startTime}] [INFO] Security check passed\n", 3, __DIR__ . '/auto-provision-cron.log');
} else {
    error_log("[{$startTime}] [INFO] Running via CLI\n", 3, __DIR__ . '/auto-provision-cron.log');
}

// ============================================
// CONFIGURATION
// ============================================

define('BASE_PATH', dirname(__DIR__));
error_log("[{$startTime}] [INFO] BASE_PATH: " . BASE_PATH . "\n", 3, __DIR__ . '/auto-provision-cron.log');

if (!file_exists(BASE_PATH . '/config/database.php')) {
    error_log("[{$startTime}] [ERROR] Config file not found: " . BASE_PATH . "/config/database.php\n", 3, __DIR__ . '/auto-provision-cron.log');
    die('Config file not found');
}

require_once BASE_PATH . '/config/database.php';
error_log("[{$startTime}] [INFO] Config loaded successfully\n", 3, __DIR__ . '/auto-provision-cron.log');

// Central database credentials (from main config)
if (!defined('DB_HOST')) {
    error_log("[{$startTime}] [ERROR] DB_HOST not defined in config\n", 3, __DIR__ . '/auto-provision-cron.log');
    die('DB_HOST not defined');
}

define('CENTRAL_DB_HOST', DB_HOST);
define('CENTRAL_DB_NAME', DB_NAME);
define('CENTRAL_DB_USER', DB_USER);
define('CENTRAL_DB_PASS', DB_PASS);

error_log("[{$startTime}] [INFO] Database constants defined: " . CENTRAL_DB_HOST . "/" . CENTRAL_DB_NAME . "\n", 3, __DIR__ . '/auto-provision-cron.log');

// Max tenants to provision per run (to avoid timeout)
define('MAX_PER_RUN', 5);
error_log("[{$startTime}] [INFO] MAX_PER_RUN set to: " . MAX_PER_RUN . "\n", 3, __DIR__ . '/auto-provision-cron.log');

// ============================================
// LOGGING
// ============================================

function logCron($message, $type = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logLine = "[{$timestamp}] [{$type}] {$message}\n";

    // Output for CLI
    echo $logLine;

    // Write to log file
    error_log($logLine, 3, __DIR__ . '/auto-provision-cron.log');
}

// ============================================
// PROVISIONING LOGIC
// ============================================

/**
 * Get pending trial requests that need provisioning
 */
function getPendingRequests() {
    logCron("Connecting to database...");
    logCron("DB Host: " . CENTRAL_DB_HOST);
    logCron("DB Name: " . CENTRAL_DB_NAME);
    logCron("DB User: " . CENTRAL_DB_USER);

    try {
        $pdo = new PDO(
            "mysql:host=" . CENTRAL_DB_HOST . ";dbname=" . CENTRAL_DB_NAME . ";charset=utf8mb4",
            CENTRAL_DB_USER,
            CENTRAL_DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        logCron("Database connected successfully");

        $stmt = $pdo->prepare("
            SELECT id, tenant_subdomain, fullname, email, phone, created_at, tenant_provisioned, status
            FROM trial_requests
            WHERE tenant_provisioned = 0
            AND (status = 'pending' OR status = '' OR status IS NULL)
            ORDER BY created_at ASC
            LIMIT :limit
        ");

        $stmt->bindValue(':limit', MAX_PER_RUN, PDO::PARAM_INT);
        logCron("Executing query with limit: " . MAX_PER_RUN);

        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        logCron("Query returned " . count($results) . " rows");

        // Log details of each result
        foreach ($results as $row) {
            logCron("Found request: ID={$row['id']}, subdomain={$row['tenant_subdomain']}, status={$row['status']}, provisioned={$row['tenant_provisioned']}");
        }

        return $results;

    } catch (PDOException $e) {
        logCron("Database error: " . $e->getMessage(), 'ERROR');
        logCron("Stack trace: " . $e->getTraceAsString(), 'ERROR');
        return [];
    }
}

/**
 * Mark trial request as being provisioned
 */
function markAsProvisioning($requestId) {
    try {
        $pdo = new PDO(
            "mysql:host=" . CENTRAL_DB_HOST . ";dbname=" . CENTRAL_DB_NAME . ";charset=utf8mb4",
            CENTRAL_DB_USER,
            CENTRAL_DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $stmt = $pdo->prepare("
            UPDATE trial_requests
            SET status = 'provisioning',
                updated_at = NOW()
            WHERE id = :id
        ");

        $stmt->execute(['id' => $requestId]);
        return true;

    } catch (PDOException $e) {
        logCron("Failed to mark as provisioning: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

/**
 * Provision a single tenant by calling the provision script
 */
function provisionTenant($requestId) {
    logCron("Provisioning trial request ID: {$requestId}");

    // Mark as provisioning to prevent duplicate runs
    logCron("Marking request {$requestId} as provisioning...");
    if (!markAsProvisioning($requestId)) {
        logCron("Failed to mark as provisioning", 'ERROR');
        return false;
    }
    logCron("Successfully marked as provisioning");

    // Call the main provisioning script
    $provisionScript = __DIR__ . '/provision-tenant.php';
    logCron("Provision script path: {$provisionScript}");

    if (!file_exists($provisionScript)) {
        logCron("Provision script not found: {$provisionScript}", 'ERROR');
        return false;
    }
    logCron("Provision script exists");

    // Execute provisioning in background
    // We use a separate PHP process to avoid memory/timeout issues
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        // Windows: use start /B for background execution
        $command = sprintf(
            'start /B php %s %d > %s\\provision-%d.log 2>&1',
            escapeshellarg($provisionScript),
            $requestId,
            escapeshellarg(__DIR__),
            $requestId
        );
    } else {
        // Linux/Unix: use & for background execution
        $command = sprintf(
            'php %s %d > %s/provision-%d.log 2>&1 &',
            escapeshellarg($provisionScript),
            $requestId,
            escapeshellarg(__DIR__),
            $requestId
        );
    }

    logCron("Executing command: {$command}");
    exec($command, $output, $returnCode);
    logCron("Command return code: {$returnCode}");

    if (!empty($output)) {
        logCron("Command output: " . implode("\n", $output));
    }

    if ($returnCode === 0) {
        logCron("Provisioning started successfully for request ID: {$requestId}");
        return true;
    } else {
        logCron("Failed to start provisioning for request ID: {$requestId} (return code: {$returnCode})", 'ERROR');
        return false;
    }
}

/**
 * Alternative: Call provision script via internal HTTP request
 * Use this if exec() is disabled on your server
 */
function provisionTenantViaHttp($requestId) {
    logCron("Provisioning via HTTP for trial request ID: {$requestId}");

    // Mark as provisioning
    if (!markAsProvisioning($requestId)) {
        return false;
    }

    // Call via HTTP
    $url = sprintf(
        'https://lite.fis.co.tz/api/provision-tenant.php?key=PROVISION_SECRET_KEY_2026&id=%d',
        $requestId
    );

    // Use cURL for async call (don't wait for response)
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 1); // Short timeout - we don't wait
    curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For testing

    curl_exec($ch);
    curl_close($ch);

    logCron("Provisioning HTTP request sent for request ID: {$requestId}");
    return true;
}

// ============================================
// MAIN EXECUTION
// ============================================

function runAutoProvision() {
    logCron("========================================");
    logCron("Starting Auto-Provision Cron Job");
    logCron("========================================");

    // Get pending requests
    $pendingRequests = getPendingRequests();

    if (empty($pendingRequests)) {
        logCron("No pending trial requests found");
        logCron("========================================");
        return;
    }

    $count = count($pendingRequests);
    logCron("Found {$count} pending trial request(s)");

    // Provision each request
    $successCount = 0;
    $failCount = 0;

    foreach ($pendingRequests as $request) {
        logCron("----------------------------------------");
        logCron("Processing: {$request['fullname']} ({$request['tenant_subdomain']})");

        // Use CLI execution method (change to provisionTenantViaHttp if exec is disabled)
        if (provisionTenant($request['id'])) {
            $successCount++;
        } else {
            $failCount++;
        }

        // Small delay between provisions to avoid server overload
        sleep(2);
    }

    logCron("========================================");
    logCron("Auto-Provision Completed");
    logCron("Success: {$successCount}, Failed: {$failCount}");
    logCron("========================================");
}

// ============================================
// RUN
// ============================================

$executionTime = date('Y-m-d H:i:s');
error_log("[{$executionTime}] [INFO] Entering main execution block\n", 3, __DIR__ . '/auto-provision-cron.log');

if (php_sapi_name() === 'cli') {
    // Running from command line
    error_log("[{$executionTime}] [INFO] Calling runAutoProvision() from CLI\n", 3, __DIR__ . '/auto-provision-cron.log');
    runAutoProvision();
    error_log("[{$executionTime}] [INFO] runAutoProvision() completed (CLI)\n", 3, __DIR__ . '/auto-provision-cron.log');
} else {
    // Running from web browser
    error_log("[{$executionTime}] [INFO] Calling runAutoProvision() from web\n", 3, __DIR__ . '/auto-provision-cron.log');
    echo "<pre>";
    runAutoProvision();
    echo "</pre>";
    error_log("[{$executionTime}] [INFO] runAutoProvision() completed (web)\n", 3, __DIR__ . '/auto-provision-cron.log');
}

error_log("[{$executionTime}] [INFO] Script execution finished\n", 3, __DIR__ . '/auto-provision-cron.log');
