<?php
/**
 * FIS LITE - Tenant Provisioning System
 * Automatically creates subdomain, database, and configures new tenant
 * 
 * This script is called after a trial request is approved or automatically
 */

// Prevent direct web access
if (php_sapi_name() !== 'cli' && !defined('PROVISION_ALLOWED')) {
    if (!isset($_GET['key']) || $_GET['key'] !== 'PROVISION_SECRET_KEY_2026') {
        http_response_code(403);
        die('Access denied');
    }
}

// ============================================
// CONFIGURATION
// ============================================
define('CPANEL_HOST', 'myserver.fabdemo.co.tz');
define('CPANEL_PORT', 2083);
define('CPANEL_USER', 'fiscotz');
define('CPANEL_API_TOKEN', '44DJ956WLUXC5GOQAQUBTKHS4NVEOMWP'); // Paste your cPanel API token here
define('CPANEL_DOMAIN', 'fis.co.tz');

define('TEMPLATE_PATH', '/home/fiscotz/tenant_template');
define('TENANTS_PATH', '/home/fiscotz/public_html/tenants');
// Schema is read directly from main app (not tenant_template) - only needed during provisioning
define('BASE_SCHEMA_PATH', '/home/fiscotz/lite.fis.co.tz/sql/tenant_schema.sql');

define('CENTRAL_DB_HOST', 'localhost');
define('CENTRAL_DB_NAME', 'fiscotz_litefis');
define('CENTRAL_DB_USER', 'fiscotz_liteuser');
define('CENTRAL_DB_PASS', '9,Qgt%RMjry4WqY5');

define('WHATSAPP_API_URL', 'https://wa.fis.co.tz');
define('WHATSAPP_API_KEY', 'wapi_live_fee0acbcee73a74e0cb854bacc4c47938d91d3e597dab95e');

// ============================================
// LOGGING
// ============================================
function logProvision($message, $type = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logLine = "[{$timestamp}] [{$type}] {$message}\n";
    echo $logLine;
    error_log($logLine, 3, __DIR__ . '/provision.log');
}

// ============================================
// CPANEL API FUNCTIONS
// ============================================

/**
 * Make cPanel API call
 */
function cpanelApi($module, $function, $params = []) {
    $url = "https://" . CPANEL_HOST . ":" . CPANEL_PORT . "/execute/{$module}/{$function}";
    
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => [
            'Authorization: cpanel ' . CPANEL_USER . ':' . CPANEL_API_TOKEN
        ],
        CURLOPT_TIMEOUT => 60
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        logProvision("cPanel API Error: {$error}", 'ERROR');
        return ['status' => 0, 'errors' => [$error]];
    }
    
    $result = json_decode($response, true);
    
    if ($httpCode !== 200) {
        logProvision("cPanel API HTTP {$httpCode}: {$response}", 'ERROR');
        return ['status' => 0, 'errors' => ["HTTP {$httpCode}"]];
    }
    
    return $result;
}

/**
 * Create subdomain
 */
function createSubdomain($subdomain) {
    logProvision("Creating subdomain: {$subdomain}." . CPANEL_DOMAIN);

    $result = cpanelApi('SubDomain', 'addsubdomain', [
        'domain' => $subdomain,
        'rootdomain' => CPANEL_DOMAIN,
        'dir' => "public_html/tenants/{$subdomain}"
    ]);

    if (isset($result['status']) && $result['status'] == 1) {
        logProvision("Subdomain created successfully");
        return true;
    }

    // Check if error is because subdomain already exists
    $error = $result['errors'][0] ?? 'Unknown error';
    if (strpos($error, 'already exists') !== false || strpos($error, 'DNS entry') !== false) {
        logProvision("Subdomain already exists, skipping creation", 'WARNING');
        return true;
    }

    logProvision("Failed to create subdomain: {$error}", 'ERROR');
    return false;
}

/**
 * Create MySQL database
 */
function createDatabase($dbName) {
    logProvision("Creating database: {$dbName}");

    $result = cpanelApi('Mysql', 'create_database', [
        'name' => $dbName
    ]);

    if (isset($result['status']) && $result['status'] == 1) {
        logProvision("Database created successfully");
        return true;
    }

    // Check if error is because database already exists
    $error = $result['errors'][0] ?? 'Unknown error';
    if (strpos($error, 'already exists') !== false) {
        logProvision("Database already exists, skipping creation", 'WARNING');
        return true;
    }

    logProvision("Failed to create database: {$error}", 'ERROR');
    return false;
}

/**
 * Create MySQL user
 */
function createDbUser($username, $password) {
    logProvision("Creating database user: {$username}");

    $result = cpanelApi('Mysql', 'create_user', [
        'name' => $username,
        'password' => $password
    ]);

    if (isset($result['status']) && $result['status'] == 1) {
        logProvision("Database user created successfully");
        return true;
    }

    // Check if error is because user already exists
    $error = $result['errors'][0] ?? 'Unknown error';
    if (strpos($error, 'already exists') !== false) {
        logProvision("Database user already exists, updating password", 'WARNING');

        // Update the password for existing user
        $updateResult = cpanelApi('Mysql', 'set_password', [
            'user' => $username,
            'password' => $password
        ]);

        if (isset($updateResult['status']) && $updateResult['status'] == 1) {
            logProvision("Database user password updated successfully");
            return true;
        } else {
            $updateError = $updateResult['errors'][0] ?? 'Unknown error';
            logProvision("Failed to update user password: {$updateError}", 'ERROR');
            return false;
        }
    }

    logProvision("Failed to create database user: {$error}", 'ERROR');
    return false;
}

/**
 * Grant user privileges on database
 */
function grantDbPrivileges($dbName, $username) {
    logProvision("Granting privileges on {$dbName} to {$username}");

    $result = cpanelApi('Mysql', 'set_privileges_on_database', [
        'user' => $username,
        'database' => $dbName,
        'privileges' => 'ALL PRIVILEGES'
    ]);

    if (isset($result['status']) && $result['status'] == 1) {
        logProvision("Privileges granted successfully");
        return true;
    }

    // Check if error is because privileges already set (treat as success)
    $error = $result['errors'][0] ?? 'Unknown error';
    if (strpos($error, 'already') !== false) {
        logProvision("Privileges already granted, continuing", 'WARNING');
        return true;
    }

    logProvision("Failed to grant privileges: {$error}", 'ERROR');
    return false;
}

/**
 * Generate random password
 */
function generatePassword($length = 16) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $password;
}

/**
 * Copy template files to tenant directory
 */
function copyTemplateFiles($subdomain, $forceOverwrite = false) {
    $source = TEMPLATE_PATH;
    $destination = TENANTS_PATH . '/' . $subdomain;

    logProvision("Copying template files from {$source} to {$destination}");

    // Check if application files already exist (look for index.php as indicator)
    if (!$forceOverwrite && file_exists($destination . '/index.php')) {
        logProvision("Template files already exist (index.php found), skipping copy", 'WARNING');
        return true;
    }

    if ($forceOverwrite) {
        logProvision("Force overwrite enabled, copying all files", 'WARNING');
    }

    // Create destination directory if it doesn't exist
    if (!is_dir($destination)) {
        mkdir($destination, 0755, true);
    }

    // Use shell command for recursive copy (with overwrite)
    $command = "cp -rf {$source}/* {$destination}/";
    exec($command, $output, $returnCode);

    if ($returnCode === 0) {
        logProvision("Template files copied successfully");

        // Verify that index.php was copied
        if (!file_exists($destination . '/index.php')) {
            logProvision("Warning: index.php not found after copy. Template directory might be empty.", 'WARNING');
        }

        return true;
    }

    logProvision("Failed to copy template files: " . implode("\n", $output), 'ERROR');
    return false;
}

/**
 * Update tenant config file
 */
function updateTenantConfig($subdomain, $dbName, $dbUser, $dbPass, $adminUser, $adminPass) {
    $configDir = TENANTS_PATH . "/{$subdomain}/config";
    $configPath = $configDir . "/database.php";

    // Create config directory if it doesn't exist
    if (!is_dir($configDir)) {
        logProvision("Creating config directory: {$configDir}");
        if (!mkdir($configDir, 0755, true)) {
            logProvision("Failed to create config directory", 'ERROR');
            return false;
        }
    }

    logProvision("Updating config file: {$configPath}");
    
    $baseUrl = "https://{$subdomain}." . CPANEL_DOMAIN . "/";
    
    $configContent = "<?php
/**
 * Database Configuration
 * Tenant: {$subdomain}
 * Generated: " . date('Y-m-d H:i:s') . "
 */

// Database credentials
define('DB_HOST', 'localhost');
define('DB_USER', '{$dbUser}');
define('DB_PASS', '{$dbPass}');
define('DB_NAME', '{$dbName}');
define('DB_CHARSET', 'utf8mb4');

// Timezone
date_default_timezone_set('Africa/Nairobi');

// Error reporting (set to 0 in production)
error_reporting(0);
ini_set('display_errors', 0);

// Session configuration
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 1);

// Application settings
define('APP_NAME', 'FIS - LITE');
define('APP_VERSION', '1.0');
define('BASE_URL', '{$baseUrl}');
define('DEMO_MODE', false);

// File upload settings
define('UPLOAD_PATH', __DIR__ . '/../uploads/');
define('EXPORT_PATH', __DIR__ . '/../exports/');
define('MAX_FILE_SIZE', 5242880);

// Date format
define('DATE_FORMAT', 'd-m-Y');
define('DATETIME_FORMAT', 'd-m-Y h:i A');
define('DB_DATE_FORMAT', 'Y-m-d');

// Pagination
define('RECORDS_PER_PAGE', 20);

// Currency
define('CURRENCY_SYMBOL', 'TZS.');

// Create database connection
try {
    \$conn = new PDO(
        \"mysql:host=\" . DB_HOST . \";dbname=\" . DB_NAME . \";charset=\" . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException \$e) {
    die(\"Connection failed: \" . \$e->getMessage());
}
?>";

    if (file_put_contents($configPath, $configContent)) {
        logProvision("Config file updated successfully");
        return true;
    }
    
    logProvision("Failed to update config file", 'ERROR');
    return false;
}

/**
 * Import base schema into tenant database
 */
function importBaseSchema($dbName, $dbUser, $dbPass) {
    logProvision("=== SCHEMA IMPORT START ===");
    logProvision("Target database: {$dbName}");
    logProvision("Database user: {$dbUser}");

    $schemaPath = BASE_SCHEMA_PATH;

    // Step 1: Verify schema file exists
    logProvision("Schema file path: {$schemaPath}");
    if (!file_exists($schemaPath)) {
        logProvision("CRITICAL: Schema file does not exist at {$schemaPath}", 'ERROR');

        // Check alternative locations
        $altPaths = [
            __DIR__ . '/../sql/tenant_schema.sql',
            '/home/fiscotz/public_html/sql/tenant_schema.sql',
            dirname(__DIR__) . '/sql/tenant_schema.sql'
        ];

        foreach ($altPaths as $altPath) {
            logProvision("Checking alternative path: {$altPath}");
            if (file_exists($altPath)) {
                logProvision("Found schema at alternative location: {$altPath}", 'WARNING');
                $schemaPath = $altPath;
                break;
            }
        }

        if (!file_exists($schemaPath)) {
            logProvision("Schema file not found in any location. Aborting.", 'ERROR');
            return false;
        }
    }

    // Step 2: Log schema file details
    $fileSize = filesize($schemaPath);
    $fileModified = date('Y-m-d H:i:s', filemtime($schemaPath));
    logProvision("Schema file size: " . number_format($fileSize) . " bytes");
    logProvision("Schema file last modified: {$fileModified}");

    // Count CREATE TABLE statements in schema
    $schemaContent = file_get_contents($schemaPath);
    $tableCount = preg_match_all('/CREATE TABLE/i', $schemaContent, $matches);
    logProvision("Schema contains {$tableCount} CREATE TABLE statements");

    // Step 3: Check database connection and existing tables
    logProvision("Connecting to database to check existing tables...");
    try {
        $pdo = new PDO(
            "mysql:host=localhost;dbname={$dbName};charset=utf8mb4",
            $dbUser,
            $dbPass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        logProvision("Database connection successful");

        $stmt = $pdo->query("SHOW TABLES");
        $existingTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $existingCount = count($existingTables);

        if ($existingCount > 0) {
            logProvision("Database already has {$existingCount} tables: " . implode(', ', $existingTables), 'WARNING');
            logProvision("Skipping schema import to prevent data loss", 'WARNING');
            logProvision("=== SCHEMA IMPORT SKIPPED ===");
            return true;
        }

        logProvision("Database is empty, proceeding with schema import");
    } catch (PDOException $e) {
        logProvision("Database connection error: " . $e->getMessage(), 'ERROR');
        logProvision("Cannot verify database state, will attempt import anyway", 'WARNING');
    }

    // Step 4: Check if mysql command is available
    logProvision("Checking if mysql command is available...");
    exec("which mysql 2>&1", $whichOutput, $whichReturn);
    if ($whichReturn !== 0) {
        // Try Windows path
        exec("where mysql 2>&1", $whichOutput, $whichReturn);
    }
    if ($whichReturn === 0) {
        logProvision("MySQL CLI found at: " . implode('', $whichOutput));
    } else {
        logProvision("MySQL CLI not found in PATH, import may fail", 'WARNING');
    }

    // Step 5: Execute schema import
    $startTime = microtime(true);
    logProvision("Executing schema import command...");

    // Log command without password for security
    $safeCommand = "mysql -u {$dbUser} -p'***' {$dbName} < {$schemaPath}";
    logProvision("Command (password hidden): {$safeCommand}");

    $command = "mysql -u {$dbUser} -p'{$dbPass}' {$dbName} < {$schemaPath} 2>&1";
    exec($command, $output, $returnCode);

    $duration = round(microtime(true) - $startTime, 2);
    logProvision("Command execution completed in {$duration} seconds");
    logProvision("Return code: {$returnCode}");

    if (!empty($output)) {
        logProvision("Command output: " . implode("\n", $output));
    }

    // Step 6: Verify import results
    if ($returnCode === 0) {
        logProvision("MySQL command executed successfully (return code 0)");

        // Verify tables were created
        try {
            $pdo = new PDO(
                "mysql:host=localhost;dbname={$dbName};charset=utf8mb4",
                $dbUser,
                $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            $stmt = $pdo->query("SHOW TABLES");
            $createdTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $createdCount = count($createdTables);

            logProvision("Tables created: {$createdCount}");

            if ($createdCount > 0) {
                logProvision("Table list: " . implode(', ', $createdTables));

                // Verify critical tables exist
                $criticalTables = ['users', 'roles', 'locations', 'products', 'sales', 'purchases'];
                $missingCritical = [];
                foreach ($criticalTables as $table) {
                    if (!in_array($table, $createdTables)) {
                        $missingCritical[] = $table;
                    }
                }

                if (!empty($missingCritical)) {
                    logProvision("WARNING: Critical tables missing: " . implode(', ', $missingCritical), 'WARNING');
                } else {
                    logProvision("All critical tables verified present");
                }

                // Check if default data was inserted
                $stmt = $pdo->query("SELECT COUNT(*) FROM roles");
                $roleCount = $stmt->fetchColumn();
                logProvision("Default roles inserted: {$roleCount}");

                $stmt = $pdo->query("SELECT COUNT(*) FROM locations");
                $locationCount = $stmt->fetchColumn();
                logProvision("Default locations inserted: {$locationCount}");

                $stmt = $pdo->query("SELECT COUNT(*) FROM units");
                $unitCount = $stmt->fetchColumn();
                logProvision("Default units inserted: {$unitCount}");

            } else {
                logProvision("CRITICAL: No tables found after import!", 'ERROR');
                logProvision("The schema file may be empty or contain only comments", 'ERROR');
                return false;
            }

        } catch (PDOException $e) {
            logProvision("Could not verify tables after import: " . $e->getMessage(), 'WARNING');
        }

        logProvision("=== SCHEMA IMPORT SUCCESS ===");
        return true;
    }

    // Import failed
    logProvision("=== SCHEMA IMPORT FAILED ===", 'ERROR');
    logProvision("Return code: {$returnCode}", 'ERROR');
    logProvision("Error output: " . implode("\n", $output), 'ERROR');

    // Provide troubleshooting hints
    if (strpos(implode('', $output), 'Access denied') !== false) {
        logProvision("HINT: Database credentials may be incorrect", 'ERROR');
    } elseif (strpos(implode('', $output), 'Unknown database') !== false) {
        logProvision("HINT: Database may not exist yet", 'ERROR');
    } elseif (strpos(implode('', $output), 'command not found') !== false) {
        logProvision("HINT: MySQL client not installed or not in PATH", 'ERROR');
    } elseif (strpos(implode('', $output), 'syntax error') !== false) {
        logProvision("HINT: Schema file may contain SQL syntax errors", 'ERROR');
    }

    return false;
}

/**
 * Create admin user in tenant database
 */
function createAdminUser($dbName, $dbUser, $dbPass, $adminUsername, $adminPassword, $fullName, $email, $phone) {
    logProvision("Creating admin user: {$adminUsername}");

    try {
        $pdo = new PDO(
            "mysql:host=localhost;dbname={$dbName};charset=utf8mb4",
            $dbUser,
            $dbPass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        // Check if admin user already exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = :username");
        $stmt->execute(['username' => $adminUsername]);
        $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existingUser) {
            logProvision("Admin user already exists, skipping creation", 'WARNING');
            return true;
        }

        $hashedPassword = password_hash($adminPassword, PASSWORD_DEFAULT);

        // Insert user (note: column is 'name' not 'full_name', and no location_id column)
        $stmt = $pdo->prepare("
            INSERT INTO users (username, password, name, email, phone, role_id, is_active)
            VALUES (:username, :password, :name, :email, :phone, 1, 1)
        ");

        $stmt->execute([
            'username' => $adminUsername,
            'password' => $hashedPassword,
            'name' => $fullName,
            'email' => $email,
            'phone' => $phone
        ]);

        $userId = $pdo->lastInsertId();

        // Link user to Main Store location (location_id = 1)
        $stmt = $pdo->prepare("
            INSERT INTO user_locations (user_id, location_id)
            VALUES (:user_id, 1)
        ");
        $stmt->execute(['user_id' => $userId]);

        logProvision("Admin user created successfully with ID: {$userId}");
        return true;
    } catch (PDOException $e) {
        logProvision("Failed to create admin user: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

/**
 * Update trial request status in central database
 */
function updateTrialRequestStatus($trialRequestId, $status, $tenantProvisioned = true) {
    try {
        $pdo = new PDO(
            "mysql:host=" . CENTRAL_DB_HOST . ";dbname=" . CENTRAL_DB_NAME . ";charset=utf8mb4",
            CENTRAL_DB_USER,
            CENTRAL_DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        $stmt = $pdo->prepare("
            UPDATE trial_requests 
            SET status = :status, 
                tenant_provisioned = :provisioned,
                tenant_provisioned_at = NOW()
            WHERE id = :id
        ");
        
        $stmt->execute([
            'status' => $status,
            'provisioned' => $tenantProvisioned ? 1 : 0,
            'id' => $trialRequestId
        ]);
        
        return true;
    } catch (PDOException $e) {
        logProvision("Failed to update trial request status: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

/**
 * Send WhatsApp notification with login URL (using API Key auth)
 */
function sendSetupWhatsApp($phone, $name, $loginUrl, $language = 'en') {
    // Template: fis_setup_complete (2 params: name, url)
    $templateName = ($language === 'sw') ? 'fis_setup_complete_sw' : 'fis_setup_complete';
    
    // New API format
    $payload = json_encode([
        'to' => $phone,
        'template_name' => $templateName,
        'language' => $language,
        'components' => [
            [
                'type' => 'body',
                'parameters' => [
                    ['type' => 'text', 'text' => $name],
                    ['type' => 'text', 'text' => $loginUrl]
                ]
            ]
        ]
    ]);
    
    $ch = curl_init(WHATSAPP_API_URL . '/api/messages/send-template');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-API-Key: ' . WHATSAPP_API_KEY
        ],
        CURLOPT_TIMEOUT => 30
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        if (isset($data['success']) && $data['success'] === true) {
            logProvision("WhatsApp credentials sent successfully");
            return true;
        }
    }
    
    logProvision("Failed to send WhatsApp: HTTP {$httpCode} - {$response}", 'ERROR');
    return false;
}

/**
 * Send email with login credentials
 */
function sendCredentialsEmail($email, $name, $loginUrl, $username, $password, $businessName) {
    $subject = "Your FIS LITE Account is Ready! - {$businessName}";
    
    $body = "
<!DOCTYPE html>
<html>
<head><meta charset='UTF-8'></head>
<body style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;'>
    <div style='background: linear-gradient(135deg, #0d6efd, #0a58ca); padding: 30px; text-align: center; border-radius: 10px 10px 0 0;'>
        <h1 style='color: white; margin: 0;'>🎉 Your Account is Ready!</h1>
    </div>
    
    <div style='background: #f8f9fa; padding: 30px; border-radius: 0 0 10px 10px;'>
        <p>Dear <strong>{$name}</strong>,</p>
        
        <p>Great news! Your FIS LITE account for <strong>{$businessName}</strong> has been set up and is ready to use.</p>
        
        <div style='background: white; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #0d6efd;'>
            <h3 style='margin-top: 0; color: #333;'>Your Login Details</h3>
            <p><strong>URL:</strong> <a href='{$loginUrl}'>{$loginUrl}</a></p>
            <p><strong>Username:</strong> {$username}</p>
            <p><strong>Password:</strong> {$password}</p>
        </div>
        
        <p style='color: #dc3545;'><strong>⚠️ Important:</strong> Please change your password after first login!</p>
        
        <div style='text-align: center; margin: 30px 0;'>
            <a href='{$loginUrl}' style='background: #0d6efd; color: white; padding: 15px 30px; text-decoration: none; border-radius: 50px; font-weight: bold;'>Login Now</a>
        </div>
        
        <p>Your 14-day free trial has started. Need help getting started? Reply to this email or WhatsApp us at +255 786 110 021.</p>
        
        <p>Best regards,<br><strong>FIS LITE Team</strong></p>
    </div>
</body>
</html>";

    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        'From: FIS LITE <info@fabonic.co.tz>',
        'Reply-To: info@fabonic.co.tz'
    ];
    
    if (mail($email, $subject, $body, implode("\r\n", $headers))) {
        logProvision("Credentials email sent to {$email}");
        return true;
    }
    
    logProvision("Failed to send credentials email", 'ERROR');
    return false;
}

// ============================================
// INCLUDE MIGRATION RUNNER
// ============================================
require_once __DIR__ . '/run-migrations.php';

// ============================================
// MAIN PROVISIONING FUNCTION
// ============================================

/**
 * Provision a new tenant
 */
function provisionTenant($trialRequestId) {
    logProvision("========================================");
    logProvision("Starting provisioning for Trial Request ID: {$trialRequestId}");
    logProvision("========================================");

    // Check if force re-provision is requested
    $forceReprovision = isset($_GET['force']) && $_GET['force'] === 'yes';

    // Get trial request details from central DB
    try {
        $pdo = new PDO(
            "mysql:host=" . CENTRAL_DB_HOST . ";dbname=" . CENTRAL_DB_NAME . ";charset=utf8mb4",
            CENTRAL_DB_USER,
            CENTRAL_DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $stmt = $pdo->prepare("SELECT * FROM trial_requests WHERE id = :id");
        $stmt->execute(['id' => $trialRequestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            logProvision("Trial request not found", 'ERROR');
            return false;
        }

        if ($request['tenant_provisioned'] && !$forceReprovision) {
            logProvision("Tenant already provisioned (use force=yes to re-provision)", 'WARNING');
            return false;
        }

        if ($forceReprovision) {
            logProvision("Force re-provisioning enabled", 'WARNING');
        }
        
    } catch (PDOException $e) {
        logProvision("Database error: " . $e->getMessage(), 'ERROR');
        return false;
    }
    
    $subdomain = $request['tenant_subdomain'];
    $fullName = $request['fullname'];
    $businessName = $request['business_name'];
    $email = $request['email'];
    $phone = $request['phone_formatted'];
    $language = $request['language'] ?? 'en';
    
    logProvision("Provisioning tenant: {$subdomain} for {$businessName}");
    
    // Generate credentials
    $dbName = CPANEL_USER . '_' . $subdomain;
    $dbUser = CPANEL_USER . '_' . substr($subdomain, 0, 8);
    $dbPass = generatePassword(16);
    $adminUsername = 'admin';
    $adminPassword = generatePassword(12);
    
    // Step 1: Create subdomain
    if (!createSubdomain($subdomain)) {
        logProvision("Provisioning failed at subdomain creation", 'ERROR');
        return false;
    }
    
    // Step 2: Create database
    if (!createDatabase($dbName)) {
        logProvision("Provisioning failed at database creation", 'ERROR');
        return false;
    }
    
    // Step 3: Create database user
    if (!createDbUser($dbUser, $dbPass)) {
        logProvision("Provisioning failed at user creation", 'ERROR');
        return false;
    }
    
    // Step 4: Grant privileges
    if (!grantDbPrivileges($dbName, $dbUser)) {
        logProvision("Provisioning failed at privilege grant", 'ERROR');
        return false;
    }
    
    // Step 5: Copy template files
    if (!copyTemplateFiles($subdomain, $forceReprovision)) {
        logProvision("Provisioning failed at file copy", 'ERROR');
        return false;
    }
    
    // Step 6: Update config
    if (!updateTenantConfig($subdomain, $dbName, $dbUser, $dbPass, $adminUsername, $adminPassword)) {
        logProvision("Provisioning failed at config update", 'ERROR');
        return false;
    }
    
    // Step 7: Import schema
    if (!importBaseSchema($dbName, $dbUser, $dbPass)) {
        logProvision("Provisioning failed at schema import", 'ERROR');
        return false;
    }

    // Step 7.5: Run migrations (applies all changes since base schema was frozen)
    logProvision("Running database migrations...");
    if (!runMigrationsForNewTenant($dbName, $dbUser, $dbPass)) {
        logProvision("Some migrations failed, but continuing with provisioning", 'WARNING');
        // Don't fail provisioning for migration errors - tenant can still function
        // and migrations can be re-run later
    }

    // Step 8: Create admin user
    if (!createAdminUser($dbName, $dbUser, $dbPass, $adminUsername, $adminPassword, $fullName, $email, $phone)) {
        logProvision("Provisioning failed at admin user creation", 'ERROR');
        return false;
    }
    
    // Step 9: Update trial request status
    updateTrialRequestStatus($trialRequestId, 'active', true);
    
    // Step 10: Send credentials
    $loginUrl = "https://{$subdomain}." . CPANEL_DOMAIN . "/";
    
    sendCredentialsEmail($email, $fullName, $loginUrl, $adminUsername, $adminPassword, $businessName);
    sendSetupWhatsApp($phone, $fullName, $loginUrl, $language);
    
    logProvision("========================================");
    logProvision("Provisioning completed successfully!");
    logProvision("Subdomain: {$subdomain}." . CPANEL_DOMAIN);
    logProvision("Database: {$dbName}");
    logProvision("Admin: {$adminUsername}");
    logProvision("========================================");
    
    return true;
}

// ============================================
// CLI / WEB EXECUTION
// ============================================

if (php_sapi_name() === 'cli') {
    // CLI execution
    if (isset($argv[1])) {
        $trialRequestId = intval($argv[1]);
        provisionTenant($trialRequestId);
    } else {
        echo "Usage: php provision-tenant.php <trial_request_id>\n";
    }
} elseif (isset($_GET['id'])) {
    // Web execution (for testing)
    echo "<pre>";
    $trialRequestId = intval($_GET['id']);
    provisionTenant($trialRequestId);
    echo "</pre>";
}
