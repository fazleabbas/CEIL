<?php
/**
 * FIS LITE - Drip Campaign Cron Job
 * 
 * Sends scheduled WhatsApp messages to trial users based on their trial day.
 * Run this script daily via cron: 0 9 * * * php /path/to/cron-drip-campaign.php
 * 
 * Recommended time: 9:00 AM EAT (East Africa Time)
 */

// Set timezone to East Africa Time (Dar es Salaam)
date_default_timezone_set('Africa/Dar_es_Salaam');

// Prevent web access (add ?test=SECRET to allow browser testing)
if (php_sapi_name() !== 'cli' && !defined('CRON_ALLOWED')) {
    if (!isset($_GET['test']) || $_GET['test'] !== 'FIS2026TEST@BULB420') {
        http_response_code(403);
        die('Access denied. CLI only.');
    }
    echo "<pre>"; // Format output for browser
}

// Configuration
define('CENTRAL_DB_HOST', 'localhost');
define('CENTRAL_DB_NAME', 'fiscotz_litefis');
define('CENTRAL_DB_USER', 'fiscotz_liteuser');
define('CENTRAL_DB_PASS', '9,Qgt%RMjry4WqY5');

define('WHATSAPP_API_URL', 'https://wa.fis.co.tz');
define('WHATSAPP_API_KEY', 'wapi_live_fee0acbcee73a74e0cb854bacc4c47938d91d3e597dab95e');

define('DEFAULT_TENANT_URL', 'https://lite.fis.co.tz');
define('TENANT_DOMAIN_SUFFIX', '.fis.co.tz');

/**
 * Check if a URL is live/accessible
 * @param string $url URL to check
 * @return bool True if live
 */
function isUrlLive($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY => true, // HEAD request
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // 200, 301, 302 are acceptable
    return ($httpCode >= 200 && $httpCode < 400);
}

// Logging
function logMessage($message, $type = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logLine = "[{$timestamp}] [{$type}] {$message}\n";
    echo $logLine;
    error_log($logLine, 3, __DIR__ . '/drip-campaign.log');
}

/**
 * Get database connection
 */
function getCentralDb() {
    try {
        $pdo = new PDO(
            "mysql:host=" . CENTRAL_DB_HOST . ";dbname=" . CENTRAL_DB_NAME . ";charset=utf8mb4",
            CENTRAL_DB_USER,
            CENTRAL_DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
        return $pdo;
    } catch (PDOException $e) {
        logMessage("Database connection failed: " . $e->getMessage(), 'ERROR');
        return null;
    }
}

/**
 * Send WhatsApp template message (using API Key auth)
 */
function sendWhatsAppTemplate($phone, $templateName, $langCode, $parameters) {
    $url = WHATSAPP_API_URL . '/api/messages/send-template';
    
    $bodyParameters = [];
    foreach ($parameters as $param) {
        $bodyParameters[] = ['type' => 'text', 'text' => $param];
    }
    
    // New API format
    $payload = json_encode([
        'to' => $phone,
        'template_name' => $templateName,
        'language' => $langCode,
        'components' => [
            [
                'type' => 'body',
                'parameters' => $bodyParameters
            ]
        ]
    ]);
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-API-Key: ' . WHATSAPP_API_KEY
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $data = json_decode($response, true);
    $success = ($httpCode === 200 && isset($data['success']) && $data['success'] === true);
    
    return [
        'success' => $success,
        'http_code' => $httpCode,
        'response' => $response
    ];
}

/**
 * Main drip campaign processor
 */
function processDripCampaign() {
    logMessage("=== Starting Drip Campaign Processing ===");
    
    $pdo = getCentralDb();
    if (!$pdo) {
        logMessage("Cannot proceed without database connection", 'ERROR');
        return;
    }
    
    logMessage("Using WhatsApp API with API Key authentication");
    
    // Get active drip templates
    $templatesStmt = $pdo->query("SELECT * FROM drip_templates WHERE is_active = 1 ORDER BY day_number");
    $templates = $templatesStmt->fetchAll();
    
    if (empty($templates)) {
        logMessage("No active drip templates found", 'WARNING');
        return;
    }
    logMessage("Found " . count($templates) . " active drip templates");
    
    $today = date('Y-m-d');
    $sentCount = 0;
    $failedCount = 0;
    $skippedCount = 0;
    
    foreach ($templates as $template) {
        $dayNumber = $template['day_number'];
        
        // Skip Day 1 - welcome message no longer needed
        if ($dayNumber == 1) {
            logMessage("Skipping Day 1 messages (disabled)");
            continue;
        }
        
        logMessage("Processing Day {$dayNumber} messages...");
        
        // Find trial requests that are on this day of their trial
        // trial_start_date + (day_number - 1) = today
        $targetDate = date('Y-m-d', strtotime("-" . ($dayNumber - 1) . " days"));
        
        // For Day 1: Skip if tenant is already provisioned (they got credentials via WhatsApp/email)
        // For other days: Send to all active trials
        if ($dayNumber == 1) {
            $stmt = $pdo->prepare("
                SELECT tr.* 
                FROM trial_requests tr
                LEFT JOIN drip_log dl ON dl.trial_request_id = tr.id AND dl.day_number = :day_number
                WHERE tr.trial_start_date = :target_date
                AND tr.status IN ('pending', 'active')
                AND tr.tenant_provisioned = 0
                AND dl.id IS NULL
            ");
        } else {
            $stmt = $pdo->prepare("
                SELECT tr.* 
                FROM trial_requests tr
                LEFT JOIN drip_log dl ON dl.trial_request_id = tr.id AND dl.day_number = :day_number
                WHERE tr.trial_start_date = :target_date
                AND tr.status IN ('pending', 'active')
                AND dl.id IS NULL
            ");
        }
        $stmt->execute([
            'day_number' => $dayNumber,
            'target_date' => $targetDate
        ]);
        $requests = $stmt->fetchAll();
        
        logMessage("Found " . count($requests) . " users on trial day {$dayNumber}");
        
        foreach ($requests as $request) {
            $phone = $request['phone_formatted'];
            $name = $request['fullname'];
            $language = $request['language'] ?? 'en';
            $subdomain = $request['tenant_subdomain'];
            
            // Select template based on language
            $templateName = ($language === 'sw') 
                ? $template['template_name_sw'] 
                : $template['template_name_en'];
            $langCode = ($language === 'sw') ? 'sw' : 'en';
            
            // Build parameters based on day
            logMessage("Day number: {$dayNumber}, Subdomain from DB: " . ($subdomain ?: 'NULL'));
            
            if ($dayNumber == 1) {
                // Day 1: Check if tenant URL is live
                $tenantUrl = $subdomain ? "https://{$subdomain}" . TENANT_DOMAIN_SUFFIX : null;
                logMessage("Tenant URL to check: " . ($tenantUrl ?: 'NULL'));
                
                if ($tenantUrl && isUrlLive($tenantUrl)) {
                    $urlToSend = $tenantUrl;
                    logMessage("Tenant URL {$tenantUrl} is LIVE");
                } else {
                    // URL not live - use fallback message
                    $urlToSend = ($language === 'sw') 
                        ? 'Angalia barua pepe yako' 
                        : 'Check your email';
                    logMessage("Tenant URL not live, using fallback: {$urlToSend}");
                }
                
                $parameters = [$name, $urlToSend];
            } else {
                $parameters = [$name];
            }
            
            logMessage("Sending Day {$dayNumber} to {$phone} ({$name}) - Template: {$templateName}");
            logMessage("Parameters: " . json_encode($parameters));
            
            // Send WhatsApp message
            $result = sendWhatsAppTemplate($phone, $templateName, $langCode, $parameters);
            logMessage("API Response: HTTP {$result['http_code']} - " . substr($result['response'], 0, 500));
            
            // Log the result
            $logStmt = $pdo->prepare("
                INSERT INTO drip_log 
                (trial_request_id, drip_template_id, day_number, phone, template_used, status, api_response, error_message)
                VALUES (:trial_id, :template_id, :day, :phone, :template_name, :status, :response, :error)
            ");
            
            if ($result['success']) {
                $logStmt->execute([
                    'trial_id' => $request['id'],
                    'template_id' => $template['id'],
                    'day' => $dayNumber,
                    'phone' => $phone,
                    'template_name' => $templateName,
                    'status' => 'sent',
                    'response' => $result['response'],
                    'error' => null
                ]);
                $sentCount++;
                logMessage("✓ Sent successfully to {$phone}");
            } else {
                $logStmt->execute([
                    'trial_id' => $request['id'],
                    'template_id' => $template['id'],
                    'day' => $dayNumber,
                    'phone' => $phone,
                    'template_name' => $templateName,
                    'status' => 'failed',
                    'response' => $result['response'],
                    'error' => "HTTP {$result['http_code']}"
                ]);
                $failedCount++;
                logMessage("✗ Failed to send to {$phone} - HTTP {$result['http_code']}", 'ERROR');
            }
            
            // Small delay to avoid rate limiting
            usleep(500000); // 0.5 second
        }
    }
    
    logMessage("=== Drip Campaign Complete ===");
    logMessage("Sent: {$sentCount} | Failed: {$failedCount} | Skipped: {$skippedCount}");
}

// Run the campaign
processDripCampaign();
