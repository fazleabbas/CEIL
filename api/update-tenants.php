<?php
/**
 * FIS LITE - Update Tenants Script
 * Pushes file updates from tenant_template to all provisioned tenant subdomains
 * 
 * Run via cron (after sync-template-cron.php):
 *   curl -s "https://lite.fis.co.tz/api/update-tenants.php?key=UPDATE_SECRET_KEY_2026"
 * 
 * Or via CLI:
 *   php update-tenants.php --cron
 * 
 * Options:
 *   ?tenant=subdomain  - Update single tenant only
 *   ?dry-run=1        - Show what would be updated without making changes
 */

$isCLI = php_sapi_name() === 'cli';

// Authentication
if ($isCLI) {
    if (!in_array('--cron', $argv ?? [])) {
        die("Usage: php update-tenants.php --cron [--tenant=subdomain] [--dry-run]\n");
    }
    $singleTenant = null;
    $dryRun = false;
    foreach ($argv as $arg) {
        if (strpos($arg, '--tenant=') === 0) {
            $singleTenant = substr($arg, 9);
        }
        if ($arg === '--dry-run') {
            $dryRun = true;
        }
    }
} else {
    if (!isset($_GET['key']) || $_GET['key'] !== 'UPDATE_SECRET_KEY_2026') {
        http_response_code(403);
        die(json_encode(['success' => false, 'error' => 'Access denied']));
    }
    $singleTenant = $_GET['tenant'] ?? null;
    $dryRun = isset($_GET['dry-run']) && $_GET['dry-run'] === '1';
}

// ============================================
// CONFIGURATION
// ============================================
define('TEMPLATE_PATH', '/home/fiscotz/tenant_template');
define('TENANTS_PATH', '/home/fiscotz/public_html/tenants');
define('LOG_FILE', __DIR__ . '/update-tenants.log');

// Central database for tenant list
define('CENTRAL_DB_HOST', 'localhost');
define('CENTRAL_DB_NAME', 'fiscotz_litefis');
define('CENTRAL_DB_USER', 'fiscotz_liteuser');
define('CENTRAL_DB_PASS', '9,Qgt%RMjry4WqY5');

// Files and directories to EXCLUDE from updating (tenant-specific data)
$EXCLUDE_ITEMS = [
    // Tenant-specific configuration
    'config/database.php',
    'config',  // Entire config directory (has tenant credentials)
    
    // Tenant data directories
    'uploads',
    'exports',
    'logs',
    
    // Environment files
    '.env',
    '.htaccess',
    
    // Tenant-specific service worker cache
    'service-worker.js',  // May have tenant-specific cache names
    
    // Any local SQLite or cache files
    '*.db',
    '*.sqlite',
    'cache',
    
    // Version control (shouldn't exist but just in case)
    '.git',
    '.gitignore'
];

// Files to ALWAYS update (override exclusions if needed)
$FORCE_UPDATE = [
    // Core application files that must be consistent
    // Add specific files here if they should always be updated
];

// ============================================
// LOGGING
// ============================================
function logUpdate($message, $type = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logLine = "[{$timestamp}] [{$type}] {$message}\n";
    
    global $isCLI;
    if ($isCLI) {
        echo $logLine;
    }
    
    error_log($logLine, 3, LOG_FILE);
}

// ============================================
// DATABASE CONNECTION
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
        logUpdate("Database connection failed: " . $e->getMessage(), 'ERROR');
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
// FILE OPERATIONS
// ============================================

function shouldExclude($relativePath, $excludeList) {
    $basename = basename($relativePath);
    
    foreach ($excludeList as $exclude) {
        // Exact match on basename
        if ($basename === $exclude) {
            return true;
        }
        
        // Relative path match
        if ($relativePath === $exclude) {
            return true;
        }
        
        // Starts with excluded directory
        if (strpos($relativePath, $exclude . '/') === 0) {
            return true;
        }
        
        // Wildcard pattern matching (e.g., *.db)
        if (strpos($exclude, '*') !== false) {
            $pattern = str_replace('*', '.*', $exclude);
            if (preg_match('/^' . $pattern . '$/', $basename)) {
                return true;
            }
        }
    }
    
    return false;
}

function syncToTenant($templatePath, $tenantPath, $excludeList, &$stats, $dryRun = false) {
    if (!is_dir($templatePath)) {
        logUpdate("Template path not found: {$templatePath}", 'ERROR');
        return false;
    }
    
    if (!is_dir($tenantPath)) {
        logUpdate("Tenant path not found: {$tenantPath}", 'ERROR');
        return false;
    }
    
    return syncDirectory($templatePath, $tenantPath, $templatePath, $excludeList, $stats, $dryRun);
}

function syncDirectory($source, $destination, $basePath, $excludeList, &$stats, $dryRun) {
    if (!is_dir($destination)) {
        if ($dryRun) {
            logUpdate("[DRY-RUN] Would create directory: {$destination}");
            $stats['dirs_created']++;
        } else {
            if (!mkdir($destination, 0755, true)) {
                logUpdate("Failed to create directory: {$destination}", 'ERROR');
                return false;
            }
            $stats['dirs_created']++;
        }
    }
    
    $items = scandir($source);
    
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        
        $sourcePath = $source . '/' . $item;
        $destPath = $destination . '/' . $item;
        $relativePath = str_replace($basePath . '/', '', $sourcePath);
        
        // Check exclusions
        if (shouldExclude($relativePath, $excludeList)) {
            $stats['skipped']++;
            continue;
        }
        
        if (is_dir($sourcePath)) {
            syncDirectory($sourcePath, $destPath, $basePath, $excludeList, $stats, $dryRun);
        } else {
            // Check if file needs updating
            $needsUpdate = true;
            
            if (file_exists($destPath)) {
                $sourceTime = filemtime($sourcePath);
                $destTime = filemtime($destPath);
                $sourceSize = filesize($sourcePath);
                $destSize = filesize($destPath);
                
                // Compare by modification time and size
                if ($sourceTime <= $destTime && $sourceSize === $destSize) {
                    $needsUpdate = false;
                    $stats['unchanged']++;
                }
            }
            
            if ($needsUpdate) {
                if ($dryRun) {
                    logUpdate("[DRY-RUN] Would update: {$relativePath}");
                    $stats['updated']++;
                } else {
                    if (copy($sourcePath, $destPath)) {
                        chmod($destPath, 0644);
                        touch($destPath, filemtime($sourcePath));
                        $stats['updated']++;
                    } else {
                        logUpdate("Failed to copy: {$relativePath}", 'ERROR');
                        $stats['errors']++;
                    }
                }
            }
        }
    }
    
    return true;
}

function cleanOrphanedFiles($source, $destination, $basePath, $excludeList, &$stats, $dryRun) {
    if (!is_dir($destination)) {
        return;
    }
    
    $items = scandir($destination);
    
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        
        $sourcePath = $source . '/' . $item;
        $destPath = $destination . '/' . $item;
        $relativePath = str_replace($basePath . '/', '', $destPath);
        
        // Skip excluded items (don't remove tenant-specific files)
        if (shouldExclude($relativePath, $excludeList)) {
            continue;
        }
        
        // If source doesn't exist, this file was removed from template
        if (!file_exists($sourcePath)) {
            if ($dryRun) {
                logUpdate("[DRY-RUN] Would remove orphaned: {$relativePath}");
                $stats['removed']++;
            } else {
                if (is_dir($destPath)) {
                    deleteDirectory($destPath);
                    logUpdate("Removed orphaned directory: {$relativePath}");
                } else {
                    unlink($destPath);
                    logUpdate("Removed orphaned file: {$relativePath}");
                }
                $stats['removed']++;
            }
        } elseif (is_dir($destPath)) {
            cleanOrphanedFiles($sourcePath, $destPath, $basePath, $excludeList, $stats, $dryRun);
        }
    }
}

function deleteDirectory($dir) {
    if (!is_dir($dir)) {
        return;
    }
    
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            deleteDirectory($path);
        } else {
            unlink($path);
        }
    }
    
    rmdir($dir);
}

// ============================================
// MAIN UPDATE FUNCTION
// ============================================

function updateAllTenants($singleTenant = null, $dryRun = false) {
    global $EXCLUDE_ITEMS;
    
    $startTime = microtime(true);
    $results = [
        'success' => true,
        'tenants_processed' => 0,
        'tenants_failed' => 0,
        'total_files_updated' => 0,
        'details' => []
    ];
    
    logUpdate("========================================");
    logUpdate("Starting Tenant Update" . ($dryRun ? " [DRY-RUN]" : ""));
    logUpdate("========================================");
    
    // Check template exists
    if (!is_dir(TEMPLATE_PATH)) {
        logUpdate("Template directory not found: " . TEMPLATE_PATH, 'ERROR');
        $results['success'] = false;
        $results['error'] = 'Template not found';
        return $results;
    }
    
    // Connect to database
    $pdo = getCentralDb();
    if (!$pdo) {
        $results['success'] = false;
        $results['error'] = 'Database connection failed';
        return $results;
    }
    
    // Get provisioned tenants
    $tenants = getProvisionedTenants($pdo, $singleTenant);
    
    if (empty($tenants)) {
        logUpdate("No provisioned tenants found" . ($singleTenant ? " matching '{$singleTenant}'" : ""));
        $results['message'] = 'No tenants to update';
        return $results;
    }
    
    logUpdate("Found " . count($tenants) . " tenant(s) to update");
    
    // Process each tenant
    foreach ($tenants as $tenant) {
        $subdomain = $tenant['tenant_subdomain'];
        $tenantPath = TENANTS_PATH . '/' . $subdomain;
        
        logUpdate("----------------------------------------");
        logUpdate("Updating tenant: {$subdomain} ({$tenant['business_name']})");
        
        // Check tenant directory exists
        if (!is_dir($tenantPath)) {
            logUpdate("Tenant directory not found: {$tenantPath}", 'WARNING');
            $results['tenants_failed']++;
            $results['details'][$subdomain] = ['success' => false, 'error' => 'Directory not found'];
            continue;
        }
        
        // Initialize stats for this tenant
        $stats = [
            'updated' => 0,
            'unchanged' => 0,
            'skipped' => 0,
            'removed' => 0,
            'dirs_created' => 0,
            'errors' => 0
        ];
        
        // Sync files
        $syncResult = syncToTenant(TEMPLATE_PATH, $tenantPath, $EXCLUDE_ITEMS, $stats, $dryRun);
        
        // Clean orphaned files
        cleanOrphanedFiles(TEMPLATE_PATH, $tenantPath, TENANTS_PATH . '/' . $subdomain, $EXCLUDE_ITEMS, $stats, $dryRun);
        
        if ($syncResult && $stats['errors'] === 0) {
            $results['tenants_processed']++;
            $results['total_files_updated'] += $stats['updated'];
            $results['details'][$subdomain] = [
                'success' => true,
                'stats' => $stats
            ];
            logUpdate("Completed: {$stats['updated']} updated, {$stats['unchanged']} unchanged, {$stats['skipped']} skipped");
        } else {
            $results['tenants_failed']++;
            $results['details'][$subdomain] = [
                'success' => false,
                'stats' => $stats
            ];
            logUpdate("Failed with {$stats['errors']} errors", 'ERROR');
        }
    }
    
    $duration = round(microtime(true) - $startTime, 2);
    
    logUpdate("========================================");
    logUpdate("Update Complete in {$duration}s");
    logUpdate("Processed: {$results['tenants_processed']}, Failed: {$results['tenants_failed']}, Files Updated: {$results['total_files_updated']}");
    logUpdate("========================================");
    
    $results['duration'] = $duration;
    $results['success'] = $results['tenants_failed'] === 0;
    
    return $results;
}

// ============================================
// EXECUTION
// ============================================

$result = updateAllTenants($singleTenant, $dryRun);

if ($isCLI) {
    exit($result['success'] ? 0 : 1);
} else {
    header('Content-Type: application/json');
    echo json_encode($result, JSON_PRETTY_PRINT);
}
