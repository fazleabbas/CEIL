<?php
/**
 * FIS LITE - Tenant Template Setup
 * Copies application files from lite.fis.co.tz to tenant_template directory
 * Run this script whenever you update the application to update the template
 */

// Prevent unauthorized access
if (!isset($_GET['key']) || $_GET['key'] !== 'SETUP_SECRET_KEY_2026') {
    http_response_code(403);
    die('Access denied');
}

// ============================================
// CONFIGURATION
// ============================================
define('SOURCE_PATH', '/home/fiscotz/lite.fis.co.tz');  // Your main application
define('TEMPLATE_PATH', '/home/fiscotz/tenant_template');

// Files and directories to EXCLUDE from copying
$EXCLUDE_ITEMS = [
    '.git',
    '.gitignore',
    '.claude',
    'node_modules',
    'vendor',
    'config/database.php',  // We generate this dynamically
    'uploads',              // Will create empty
    'exports',              // Will create empty
    'logs',
    '.env',
    '.htaccess',           // Will create custom one
    'landing.php',         // Trial registration landing page (not for tenants)
    'test.php',
    'cleanup.html',
    'offline-tester.html',
    'test-offline-edit.html',
    'api/setup-tenant-template.php',  // Don't copy setup script to template
    'api/provision-tenant.php',       // Don't copy provision script to template
    'api/auto-provision-cron.php',    // Don't copy auto-provision script to template
    'api/send-trial-request.php',     // Don't copy trial request handler to template
    'TENANT_TEMPLATE_SETUP.md',       // Documentation file
    'AUTO_PROVISION_SETUP.md',        // Auto-provision documentation
    'README.md',                       // Project readme
    'IMPLEMENTATION_GUIDE.md',         // Implementation guide
    'AGENTS.md'                        // Agent documentation
];

// ============================================
// LOGGING
// ============================================
function logSetup($message, $type = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logLine = "[{$timestamp}] [{$type}] {$message}\n";
    echo $logLine;
    error_log($logLine, 3, __DIR__ . '/setup-template.log');
}

// ============================================
// FILE OPERATIONS
// ============================================

/**
 * Check if item should be excluded
 */
function shouldExclude($path, $excludeList) {
    $basename = basename($path);

    foreach ($excludeList as $exclude) {
        if ($basename === $exclude) {
            return true;
        }

        // Check if path contains excluded directory
        if (strpos($path, '/' . $exclude . '/') !== false) {
            return true;
        }
    }

    return false;
}

/**
 * Recursively copy directory
 */
function copyDirectory($source, $destination, $excludeList) {
    if (!is_dir($destination)) {
        if (!mkdir($destination, 0755, true)) {
            logSetup("Failed to create directory: {$destination}", 'ERROR');
            return false;
        }
    }

    $items = scandir($source);
    $copiedCount = 0;
    $skippedCount = 0;

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $sourcePath = $source . '/' . $item;
        $destPath = $destination . '/' . $item;

        // Check if should exclude
        if (shouldExclude($sourcePath, $excludeList)) {
            logSetup("Skipping excluded item: {$item}", 'INFO');
            $skippedCount++;
            continue;
        }

        if (is_dir($sourcePath)) {
            // Recursively copy directory
            copyDirectory($sourcePath, $destPath, $excludeList);
            $copiedCount++;
        } else {
            // Copy file
            if (copy($sourcePath, $destPath)) {
                chmod($destPath, 0644);
                $copiedCount++;
            } else {
                logSetup("Failed to copy file: {$item}", 'WARNING');
            }
        }
    }

    logSetup("Copied {$copiedCount} items, skipped {$skippedCount} items from " . basename($source));
    return true;
}

/**
 * Create empty directories with proper permissions
 */
function createEmptyDirectories($basePath) {
    $directories = [
        'uploads',
        'exports',
        'config'
    ];

    foreach ($directories as $dir) {
        $fullPath = $basePath . '/' . $dir;

        if (!is_dir($fullPath)) {
            if (mkdir($fullPath, 0755, true)) {
                logSetup("Created directory: {$dir}");

                // Create .htaccess for uploads and exports
                if ($dir === 'uploads' || $dir === 'exports') {
                    $htaccess = $fullPath . '/.htaccess';
                    file_put_contents($htaccess, "Options -Indexes\n");
                    logSetup("Created .htaccess in {$dir}");
                }
            } else {
                logSetup("Failed to create directory: {$dir}", 'ERROR');
                return false;
            }
        } else {
            logSetup("Directory already exists: {$dir}", 'INFO');
        }
    }

    return true;
}

/**
 * Create .htaccess for tenant template
 */
function createHtaccess($basePath) {
    $htaccessPath = $basePath . '/.htaccess';

    $htaccessContent = "# FIS LITE - Tenant .htaccess
# Force HTTPS
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

# Prevent directory listing
Options -Indexes

# Protect config files
<FilesMatch \"^(database|config)\\.php$\">
    Order Allow,Deny
    Deny from all
</FilesMatch>

# Block access to SQL files
<FilesMatch \"\\.sql$\">
    Order Allow,Deny
    Deny from all
</FilesMatch>

# Default index file
DirectoryIndex index.php

# PHP settings
php_value upload_max_filesize 5M
php_value post_max_size 5M
php_value max_execution_time 300
php_value memory_limit 128M
";

    if (file_put_contents($htaccessPath, $htaccessContent)) {
        logSetup("Created .htaccess file");
        return true;
    }

    logSetup("Failed to create .htaccess file", 'ERROR');
    return false;
}

/**
 * Create README in tenant_template
 */
function createReadme($basePath) {
    $readmePath = $basePath . '/README.txt';

    $readmeContent = "FIS LITE - TENANT TEMPLATE
============================

This directory contains the template files used for provisioning new tenants.

DO NOT MODIFY FILES IN THIS DIRECTORY DIRECTLY!

To update the template:
1. Make changes to the main application at /home/fiscotz/lite.fis.co.tz
2. Run the setup script: api/setup-tenant-template.php?key=SETUP_SECRET_KEY_2026
3. The template will be automatically updated

Files in this directory are copied to each new tenant subdomain during provisioning.

Last updated: " . date('Y-m-d H:i:s') . "
";

    file_put_contents($readmePath, $readmeContent);
    logSetup("Created README.txt");
}

// ============================================
// MAIN SETUP FUNCTION
// ============================================

function setupTenantTemplate() {
    global $EXCLUDE_ITEMS;

    logSetup("========================================");
    logSetup("Starting Tenant Template Setup");
    logSetup("========================================");
    logSetup("Source: " . SOURCE_PATH);
    logSetup("Destination: " . TEMPLATE_PATH);
    logSetup("========================================");

    // Step 1: Check if source exists
    if (!is_dir(SOURCE_PATH)) {
        logSetup("Source directory does not exist: " . SOURCE_PATH, 'ERROR');
        return false;
    }

    // Step 2: Create or clean template directory
    if (is_dir(TEMPLATE_PATH)) {
        logSetup("Template directory exists, will update files", 'INFO');
    } else {
        logSetup("Creating template directory");
        if (!mkdir(TEMPLATE_PATH, 0755, true)) {
            logSetup("Failed to create template directory", 'ERROR');
            return false;
        }
    }

    // Step 3: Copy files
    logSetup("Copying files from source to template...");
    if (!copyDirectory(SOURCE_PATH, TEMPLATE_PATH, $EXCLUDE_ITEMS)) {
        logSetup("Failed to copy files", 'ERROR');
        return false;
    }

    // Step 4: Create empty directories
    logSetup("Creating empty directories...");
    if (!createEmptyDirectories(TEMPLATE_PATH)) {
        logSetup("Failed to create directories", 'ERROR');
        return false;
    }

    // Step 5: Create .htaccess
    logSetup("Creating .htaccess file...");
    if (!createHtaccess(TEMPLATE_PATH)) {
        logSetup("Failed to create .htaccess", 'ERROR');
        return false;
    }

    // Step 6: Create README
    logSetup("Creating README...");
    createReadme(TEMPLATE_PATH);

    // Step 7: Set permissions
    logSetup("Setting permissions...");
    exec("chmod -R 755 " . TEMPLATE_PATH);
    exec("find " . TEMPLATE_PATH . " -type f -exec chmod 644 {} \\;");

    logSetup("========================================");
    logSetup("Tenant Template Setup Completed Successfully!");
    logSetup("========================================");
    logSetup("Template Location: " . TEMPLATE_PATH);
    logSetup("Ready for tenant provisioning");
    logSetup("========================================");

    return true;
}

// ============================================
// EXECUTION
// ============================================

echo "<pre>";
$success = setupTenantTemplate();
echo "</pre>";

if ($success) {
    echo "<h2 style='color: green;'>✓ Setup completed successfully!</h2>";
    echo "<p>The tenant template has been updated at: <strong>" . TEMPLATE_PATH . "</strong></p>";
    echo "<p>You can now provision new tenants using provision-tenant.php</p>";
} else {
    echo "<h2 style='color: red;'>✗ Setup failed!</h2>";
    echo "<p>Check the log file for details: api/setup-template.log</p>";
}
