<?php
/**
 * FIS LITE - Sync Template Cron
 * Automatically syncs files from lite.fis.co.tz to tenant_template
 * 
 * Run via cron: 
 *   curl -s "https://lite.fis.co.tz/api/sync-template-cron.php?key=SYNC_SECRET_KEY_2026"
 * 
 * Or schedule in cPanel cron (every hour):
 *   0 * * * * /usr/local/bin/php /home/fiscotz/lite.fis.co.tz/api/sync-template-cron.php --cron
 */

// Allow CLI execution
$isCLI = php_sapi_name() === 'cli';

// Authentication
if ($isCLI) {
    // CLI mode - check for --cron flag
    if (!in_array('--cron', $argv ?? [])) {
        die("Usage: php sync-template-cron.php --cron\n");
    }
} else {
    // Web mode - check secret key
    if (!isset($_GET['key']) || $_GET['key'] !== 'SYNC_SECRET_KEY_2026') {
        http_response_code(403);
        die(json_encode(['success' => false, 'error' => 'Access denied']));
    }
}

// ============================================
// CONFIGURATION
// ============================================
define('SOURCE_PATH', '/home/fiscotz/lite.fis.co.tz');
define('TEMPLATE_PATH', '/home/fiscotz/tenant_template');
define('LOG_FILE', __DIR__ . '/sync-template.log');

// Files and directories to EXCLUDE from syncing
$EXCLUDE_ITEMS = [
    // Version control & IDE
    '.git',
    '.gitignore',
    '.claude',
    '.vscode',
    
    // Dependencies (should be managed separately if needed)
    'node_modules',
    'vendor',
    
    // Tenant-specific config (generated per tenant)
    'config/database.php',
    
    // Data directories (created empty, contain tenant data)
    'uploads',
    'exports',
    'logs',
    
    // Environment files
    '.env',
    '.htaccess',  // Custom htaccess is created for template
    
    // Master-only files (not for tenants)
    'landing.php',
    'test.php',
    'cleanup.html',
    'offline-tester.html',
    'test-offline-edit.html',
    
    // Admin/provisioning scripts (master only)
    'api/setup-tenant-template.php',
    'api/provision-tenant.php',
    'api/auto-provision-cron.php',
    'api/send-trial-request.php',
    'api/sync-template-cron.php',   // This script
    'api/update-tenants.php',        // Tenant update script
    'api/check-subdomain.php',       // Subdomain checker
    
    // Documentation (not needed for tenants)
    'TENANT_TEMPLATE_SETUP.md',
    'AUTO_PROVISION_SETUP.md',
    'README.md',
    'IMPLEMENTATION_GUIDE.md',
    'AGENTS.md',

    // SQL schema files (only needed during provisioning, not in tenant app)
    'sql'
];

// ============================================
// LOGGING
// ============================================
function logSync($message, $type = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logLine = "[{$timestamp}] [{$type}] {$message}\n";
    
    global $isCLI;
    if ($isCLI) {
        echo $logLine;
    }
    
    error_log($logLine, 3, LOG_FILE);
}

// ============================================
// FILE OPERATIONS
// ============================================

function shouldExclude($path, $excludeList) {
    $basename = basename($path);
    
    foreach ($excludeList as $exclude) {
        // Exact match on basename
        if ($basename === $exclude) {
            return true;
        }
        
        // Check relative path match (for nested exclusions like 'config/database.php')
        $relativePath = str_replace(SOURCE_PATH . '/', '', $path);
        if ($relativePath === $exclude) {
            return true;
        }
        
        // Check if path contains excluded directory
        if (strpos($path, '/' . $exclude . '/') !== false) {
            return true;
        }
    }
    
    return false;
}

function syncDirectory($source, $destination, $excludeList, &$stats) {
    if (!is_dir($destination)) {
        if (!mkdir($destination, 0755, true)) {
            logSync("Failed to create directory: {$destination}", 'ERROR');
            return false;
        }
        $stats['dirs_created']++;
    }
    
    $items = scandir($source);
    
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        
        $sourcePath = $source . '/' . $item;
        $destPath = $destination . '/' . $item;
        
        if (shouldExclude($sourcePath, $excludeList)) {
            $stats['skipped']++;
            continue;
        }
        
        if (is_dir($sourcePath)) {
            syncDirectory($sourcePath, $destPath, $excludeList, $stats);
        } else {
            // Check if file needs updating (compare modification times)
            $needsUpdate = true;
            
            if (file_exists($destPath)) {
                $sourceTime = filemtime($sourcePath);
                $destTime = filemtime($destPath);
                
                // Also compare file sizes for extra safety
                $sourceSize = filesize($sourcePath);
                $destSize = filesize($destPath);
                
                if ($sourceTime <= $destTime && $sourceSize === $destSize) {
                    $needsUpdate = false;
                    $stats['unchanged']++;
                }
            }
            
            if ($needsUpdate) {
                if (copy($sourcePath, $destPath)) {
                    chmod($destPath, 0644);
                    touch($destPath, filemtime($sourcePath)); // Preserve modification time
                    $stats['updated']++;
                    logSync("Updated: " . str_replace(SOURCE_PATH . '/', '', $sourcePath));
                } else {
                    logSync("Failed to copy: {$item}", 'ERROR');
                    $stats['errors']++;
                }
            }
        }
    }
    
    return true;
}

function cleanOrphanedFiles($source, $destination, $excludeList, &$stats) {
    if (!is_dir($destination)) {
        return;
    }
    
    $items = scandir($destination);
    
    foreach ($items as $item) {
        if ($item === '.' || $item === '..' || $item === 'README.txt') {
            continue;
        }
        
        $sourcePath = $source . '/' . $item;
        $destPath = $destination . '/' . $item;
        
        // Skip excluded items
        if (shouldExclude($destPath, $excludeList)) {
            continue;
        }
        
        // If source doesn't exist, remove from destination
        if (!file_exists($sourcePath)) {
            if (is_dir($destPath)) {
                // Recursively delete directory
                deleteDirectory($destPath);
                logSync("Removed orphaned directory: " . str_replace(TEMPLATE_PATH . '/', '', $destPath));
            } else {
                unlink($destPath);
                logSync("Removed orphaned file: " . str_replace(TEMPLATE_PATH . '/', '', $destPath));
            }
            $stats['removed']++;
        } elseif (is_dir($destPath)) {
            // Recursively check subdirectories
            cleanOrphanedFiles($sourcePath, $destPath, $excludeList, $stats);
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

function ensureDirectories($basePath) {
    $directories = ['uploads', 'exports', 'config'];
    
    foreach ($directories as $dir) {
        $fullPath = $basePath . '/' . $dir;
        if (!is_dir($fullPath)) {
            mkdir($fullPath, 0755, true);
            
            // Create .htaccess for uploads and exports
            if ($dir === 'uploads' || $dir === 'exports') {
                file_put_contents($fullPath . '/.htaccess', "Options -Indexes\n");
            }
        }
    }
}

// ============================================
// MAIN SYNC FUNCTION
// ============================================

function syncTemplate() {
    global $EXCLUDE_ITEMS;
    
    $startTime = microtime(true);
    
    logSync("========================================");
    logSync("Starting Template Sync");
    logSync("========================================");
    
    // Validate source exists
    if (!is_dir(SOURCE_PATH)) {
        logSync("Source directory does not exist: " . SOURCE_PATH, 'ERROR');
        return ['success' => false, 'error' => 'Source not found'];
    }
    
    // Create template directory if needed
    if (!is_dir(TEMPLATE_PATH)) {
        if (!mkdir(TEMPLATE_PATH, 0755, true)) {
            logSync("Failed to create template directory", 'ERROR');
            return ['success' => false, 'error' => 'Cannot create template directory'];
        }
    }
    
    // Initialize stats
    $stats = [
        'updated' => 0,
        'unchanged' => 0,
        'skipped' => 0,
        'removed' => 0,
        'dirs_created' => 0,
        'errors' => 0
    ];
    
    // Sync files
    logSync("Syncing files...");
    syncDirectory(SOURCE_PATH, TEMPLATE_PATH, $EXCLUDE_ITEMS, $stats);
    
    // Clean orphaned files
    logSync("Cleaning orphaned files...");
    cleanOrphanedFiles(SOURCE_PATH, TEMPLATE_PATH, $EXCLUDE_ITEMS, $stats);
    
    // Ensure required directories exist
    ensureDirectories(TEMPLATE_PATH);
    
    $duration = round(microtime(true) - $startTime, 2);
    
    logSync("========================================");
    logSync("Sync Complete in {$duration}s");
    logSync("Updated: {$stats['updated']}, Unchanged: {$stats['unchanged']}, Removed: {$stats['removed']}, Errors: {$stats['errors']}");
    logSync("========================================");
    
    return [
        'success' => $stats['errors'] === 0,
        'stats' => $stats,
        'duration' => $duration
    ];
}

// ============================================
// EXECUTION
// ============================================

$result = syncTemplate();

if ($isCLI) {
    exit($result['success'] ? 0 : 1);
} else {
    header('Content-Type: application/json');
    echo json_encode($result, JSON_PRETTY_PRINT);
}
