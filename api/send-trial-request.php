<?php
/**
 * FIS LITE - Trial Request Handler
 * Sends trial request form data to info@fabonic.co.tz
 * Also sends WhatsApp notification via Fabonic WhatsApp API
 * 
 * Security Features:
 * - CSRF token validation
 * - Honeypot spam protection
 * - Rate limiting (session-based)
 * - Input validation & sanitization
 * - Referrer check
 * - Input length limits
 */

// WhatsApp API Configuration
define('WHATSAPP_API_URL', 'https://wa.fis.co.tz');
define('WHATSAPP_API_KEY', 'wapi_live_fee0acbcee73a74e0cb854bacc4c47938d91d3e597dab95e');
define('WHATSAPP_TEMPLATE_NAME', 'fis_trial_request');
define('WHATSAPP_TEMPLATE_LANG', 'en');
define('WHATSAPP_NOTIFY_NUMBER', '255786110021');

// Customer confirmation templates
define('WHATSAPP_CUSTOMER_TEMPLATE_EN', 'fis_trial_confirmation');
define('WHATSAPP_CUSTOMER_TEMPLATE_SW', 'fis_trial_confirmation_sw');

// Central Database Configuration (for drip campaign)
define('CENTRAL_DB_HOST', 'localhost');
define('CENTRAL_DB_NAME', 'fiscotz_litefis');
define('CENTRAL_DB_USER', 'fiscotz_liteuser');
define('CENTRAL_DB_PASS', '9,Qgt%RMjry4WqY5');

/**
 * Get central database connection for storing trial requests
 * @return PDO|null
 */
function getCentralDbConnection() {
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
        error_log('Central DB connection failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Format phone number for WhatsApp (Tanzania format)
 * @param string $phone Raw phone number
 * @return string Formatted phone (255...)
 */
function formatPhoneForWhatsApp($phone) {
    $phoneClean = preg_replace('/[^0-9]/', '', $phone);
    if (substr($phoneClean, 0, 1) === '0') {
        $phoneClean = '255' . substr($phoneClean, 1);
    }
    if (strlen($phoneClean) === 9) {
        $phoneClean = '255' . $phoneClean;
    }
    return $phoneClean;
}

/**
 * Generate a clean subdomain from business name
 * @param string $businessName Raw business name
 * @return string Clean subdomain (lowercase, no special chars)
 */
function generateSubdomain($businessName) {
    // Remove common suffixes
    $name = preg_replace('/\b(ltd|limited|co|company|inc|llc|plc)\b/i', '', $businessName);
    // Convert to lowercase, remove special chars, keep only alphanumeric
    $subdomain = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $name));
    // Limit length
    $subdomain = substr($subdomain, 0, 20);
    return $subdomain ?: 'client';
}

/**
 * Check if subdomain is available (not already used)
 * @param PDO $pdo Database connection
 * @param string $subdomain Subdomain to check
 * @return bool True if available
 */
function isSubdomainAvailable($pdo, $subdomain) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM trial_requests WHERE tenant_subdomain = :subdomain");
    $stmt->execute(['subdomain' => $subdomain]);
    return $stmt->fetchColumn() == 0;
}

/**
 * Generate unique subdomain for a business
 * @param PDO $pdo Database connection
 * @param string $businessName Business name
 * @return string Available subdomain
 */
function generateUniqueSubdomain($pdo, $businessName) {
    $baseSubdomain = generateSubdomain($businessName);
    
    // Try base subdomain first
    if (isSubdomainAvailable($pdo, $baseSubdomain)) {
        return $baseSubdomain;
    }
    
    // Try with 'ltd' suffix
    $withSuffix = $baseSubdomain . 'ltd';
    if (isSubdomainAvailable($pdo, $withSuffix)) {
        return $withSuffix;
    }
    
    // Try with numbers
    for ($i = 1; $i <= 99; $i++) {
        $numbered = $baseSubdomain . $i;
        if (isSubdomainAvailable($pdo, $numbered)) {
            return $numbered;
        }
    }
    
    // Fallback: use timestamp
    return $baseSubdomain . time();
}

/**
 * Validate subdomain format (must match check-subdomain.php logic)
 */
function validateSubdomainFormat($subdomain) {
    $subdomain = strtolower(trim($subdomain));
    $subdomain = preg_replace('/[^a-z0-9]/', '', $subdomain);
    
    if (strlen($subdomain) < 3 || strlen($subdomain) > 20) {
        return false;
    }
    
    if (!preg_match('/^[a-z][a-z0-9]*$/', $subdomain)) {
        return false;
    }
    
    $reserved = ['admin', 'api', 'www', 'demo', 'test', 'staging', 'dev', 'app',
        'mail', 'email', 'smtp', 'ftp', 'ssh', 'cpanel', 'webmail',
        'support', 'help', 'billing', 'payment', 'pay', 'checkout',
        'login', 'register', 'signup', 'signin', 'auth', 'oauth',
        'static', 'assets', 'cdn', 'media', 'images', 'img', 'files',
        'download', 'downloads', 'upload', 'uploads',
        'fis', 'fislite', 'lite', 'fabonic', 'fabapps',
        'root', 'system', 'null', 'undefined', 'default'];
    
    if (in_array($subdomain, $reserved)) {
        return false;
    }
    
    // Blocked words (profanity, obscene, offensive)
    $blocked = [
        'fuck', 'shit', 'ass', 'bitch', 'damn', 'crap', 'piss', 'dick', 'cock',
        'pussy', 'asshole', 'bastard', 'slut', 'whore', 'cunt', 'fag', 'faggot',
        'nigger', 'nigga', 'retard', 'porn', 'xxx', 'sex', 'sexy', 'nude', 'naked',
        'penis', 'vagina', 'boob', 'tits', 'dildo', 'viagra', 'cialis',
        'malaya', 'kahaba', 'kuma', 'mboo', 'mavi', 'matako', 'fisi', 'punda',
        'mjinga', 'mwizi', 'shetani', 'kafiri',
        'hitler', 'nazi', 'isis', 'terrorist', 'kill', 'murder', 'rape', 'death',
        'hate', 'racist', 'abuse', 'scam', 'fraud', 'fake', 'spam', 'virus', 'hack', 'hacker'
    ];
    
    foreach ($blocked as $word) {
        if ($subdomain === $word || strpos($subdomain, $word) !== false) {
            return false;
        }
    }
    
    return $subdomain;
}

/**
 * Store trial request in central database for drip campaign
 * @param array $data Trial request data
 * @param string $referenceNo Reference number
 * @param string $ipAddress IP address
 * @return bool Success status
 */
function storeTrialRequest($data, $referenceNo, $ipAddress) {
    $logFile = __DIR__ . '/trial-request.log';
    $logMsg = function($msg) use ($logFile) {
        $line = "[" . date('Y-m-d H:i:s') . "] " . $msg . "\n";
        file_put_contents($logFile, $line, FILE_APPEND);
        error_log($msg);
    };

    $logMsg("=== STORE TRIAL REQUEST START ===");
    $logMsg("Reference: {$referenceNo}");
    $logMsg("Business: " . ($data['business'] ?? 'N/A'));
    $logMsg("Email: " . ($data['email'] ?? 'N/A'));

    $pdo = getCentralDbConnection();
    if (!$pdo) {
        $logMsg("ERROR: Failed to connect to central database");
        return false;
    }
    $logMsg("Database connection successful");

    try {
        // Use user-provided subdomain if valid, otherwise generate one
        $userSubdomain = isset($data['subdomain']) ? validateSubdomainFormat($data['subdomain']) : false;
        $logMsg("User subdomain input: " . ($data['subdomain'] ?? 'none') . " | Validated: " . ($userSubdomain ?: 'false'));

        if ($userSubdomain && isSubdomainAvailable($pdo, $userSubdomain)) {
            $suggestedSubdomain = $userSubdomain;
            $logMsg("Using user-provided subdomain: {$suggestedSubdomain}");
        } else {
            // Fallback to auto-generation
            $suggestedSubdomain = generateUniqueSubdomain($pdo, $data['business']);
            $logMsg("Auto-generated subdomain: {$suggestedSubdomain}");
        }

        $logMsg("Preparing INSERT with status='pending'");

        $stmt = $pdo->prepare("
            INSERT INTO trial_requests
            (reference_no, fullname, business_name, email, phone, phone_formatted,
             locations, users, business_type, estimated_price, message, language,
             tenant_subdomain, trial_start_date, trial_end_date, status, ip_address, source)
            VALUES
            (:reference_no, :fullname, :business_name, :email, :phone, :phone_formatted,
             :locations, :users, :business_type, :estimated_price, :message, :language,
             :tenant_subdomain, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 14 DAY), 'pending', :ip_address, 'landing_page')
        ");

        $params = [
            'reference_no' => $referenceNo,
            'fullname' => $data['fullname'],
            'business_name' => $data['business'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'phone_formatted' => formatPhoneForWhatsApp($data['phone']),
            'locations' => intval($data['locations'] ?? 1),
            'users' => intval($data['users'] ?? 5),
            'business_type' => $data['businessType'] ?: null,
            'estimated_price' => $data['estimatedPrice'] ?: 'TZS 15,000',
            'message' => $data['message'] ?: null,
            'language' => $data['language'] ?? 'sw',
            'tenant_subdomain' => $suggestedSubdomain,
            'ip_address' => $ipAddress
        ];

        $stmt->execute($params);
        $insertId = $pdo->lastInsertId();

        $logMsg("INSERT successful! Row ID: {$insertId}");

        // Verify the insert
        $verify = $pdo->prepare("SELECT id, status, tenant_subdomain FROM trial_requests WHERE id = ?");
        $verify->execute([$insertId]);
        $row = $verify->fetch();
        $logMsg("Verification - ID: {$row['id']}, Status: '{$row['status']}', Subdomain: {$row['tenant_subdomain']}");

        if (empty($row['status'])) {
            $logMsg("WARNING: Status is empty after INSERT! Database may have different column definition.");
        }

        $logMsg("=== STORE TRIAL REQUEST SUCCESS ===");
        return true;
    } catch (PDOException $e) {
        $logMsg("ERROR: PDO Exception - " . $e->getMessage());
        $logMsg("=== STORE TRIAL REQUEST FAILED ===");
        return false;
    }
}

/**
 * Send WhatsApp template message for trial request (using API Key auth)
 * @param array $formData Form data with keys: fullname, business, email, phone, locations, users, estimatedPrice, businessType, message
 * @return bool Success status
 */
function sendWhatsAppNotification($formData) {
    $url = WHATSAPP_API_URL . '/api/messages/send-template';
    
    // Build body parameters matching template: {{1}} to {{9}}
    $bodyParameters = [
        ['type' => 'text', 'text' => $formData['fullname'] ?? 'N/A'],
        ['type' => 'text', 'text' => $formData['business'] ?? 'N/A'],
        ['type' => 'text', 'text' => $formData['email'] ?? 'N/A'],
        ['type' => 'text', 'text' => $formData['phone'] ?? 'N/A'],
        ['type' => 'text', 'text' => $formData['locations'] ?? '1'],
        ['type' => 'text', 'text' => $formData['users'] ?? '5'],
        ['type' => 'text', 'text' => $formData['estimatedPrice'] ?? 'TZS 15,000'],
        ['type' => 'text', 'text' => $formData['businessType'] ?: 'Not specified'],
        ['type' => 'text', 'text' => $formData['message'] ?: 'No message']
    ];
    
    // New API format
    $payload = json_encode([
        'to' => WHATSAPP_NOTIFY_NUMBER,
        'template_name' => WHATSAPP_TEMPLATE_NAME,
        'language' => WHATSAPP_TEMPLATE_LANG,
        'components' => [
            [
                'type' => 'body',
                'parameters' => $bodyParameters
            ]
        ]
    ]);
    
    error_log('WhatsApp: Sending payload - ' . $payload);
    
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
    $curlError = curl_error($ch);
    curl_close($ch);
    
    error_log('WhatsApp Send Response - HTTP: ' . $httpCode . ' Response: ' . $response);
    
    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        if (isset($data['success']) && $data['success'] === true) {
            error_log('WhatsApp: Message sent successfully');
            return true;
        }
    }
    
    error_log('WhatsApp API Send Failed - HTTP: ' . $httpCode . ' cURL Error: ' . $curlError . ' Response: ' . $response);
    return false;
}

/**
 * Send WhatsApp confirmation to customer (using API Key auth)
 * @param string $customerPhone Customer phone number
 * @param array $formData Form data with keys: fullname, business, referenceNo, estimatedPrice
 * @param string $language Language code ('en' or 'sw')
 * @return bool Success status
 */
function sendWhatsAppCustomerConfirmation($customerPhone, $formData, $language = 'en') {
    // Format phone number - remove +, spaces, dashes
    $phoneClean = preg_replace('/[^0-9]/', '', $customerPhone);
    // If starts with 0, replace with 255 (Tanzania)
    if (substr($phoneClean, 0, 1) === '0') {
        $phoneClean = '255' . substr($phoneClean, 1);
    }
    // If doesn't start with country code, add 255
    if (strlen($phoneClean) === 9) {
        $phoneClean = '255' . $phoneClean;
    }
    
    $url = WHATSAPP_API_URL . '/api/messages/send-template';
    
    // Select template based on language
    $templateName = ($language === 'sw') ? WHATSAPP_CUSTOMER_TEMPLATE_SW : WHATSAPP_CUSTOMER_TEMPLATE_EN;
    $langCode = ($language === 'sw') ? 'sw' : 'en';
    
    // Build body parameters: {{1}}=Name, {{2}}=Reference, {{3}}=Business, {{4}}=Price
    $bodyParameters = [
        ['type' => 'text', 'text' => $formData['fullname'] ?? 'Customer'],
        ['type' => 'text', 'text' => $formData['referenceNo'] ?? 'N/A'],
        ['type' => 'text', 'text' => $formData['business'] ?? 'N/A'],
        ['type' => 'text', 'text' => $formData['estimatedPrice'] ?? 'TZS 15,000']
    ];
    
    // New API format
    $payload = json_encode([
        'to' => $phoneClean,
        'template_name' => $templateName,
        'language' => $langCode,
        'components' => [
            [
                'type' => 'body',
                'parameters' => $bodyParameters
            ]
        ]
    ]);
    
    error_log('WhatsApp Customer: Sending to ' . $phoneClean . ' payload - ' . $payload);
    
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
    $curlError = curl_error($ch);
    curl_close($ch);
    
    error_log('WhatsApp Customer Response - HTTP: ' . $httpCode . ' Response: ' . $response);
    
    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        if (isset($data['success']) && $data['success'] === true) {
            error_log('WhatsApp: Customer confirmation sent successfully to ' . $phoneClean);
            return true;
        }
    }
    
    error_log('WhatsApp Customer Send Failed - HTTP: ' . $httpCode . ' cURL Error: ' . $curlError);
    return false;
}

// Start session for CSRF and rate limiting
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Access-Control-Allow-Origin: https://fis.co.tz');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Rate limiting - max 3 submissions per 10 minutes per session
$rateLimitKey = 'trial_request_count';
$rateLimitTime = 'trial_request_time';
$maxRequests = 3;
$timeWindow = 600; // 10 minutes

if (!isset($_SESSION[$rateLimitKey])) {
    $_SESSION[$rateLimitKey] = 0;
    $_SESSION[$rateLimitTime] = time();
}

// Reset counter if time window passed
if (time() - $_SESSION[$rateLimitTime] > $timeWindow) {
    $_SESSION[$rateLimitKey] = 0;
    $_SESSION[$rateLimitTime] = time();
}

if ($_SESSION[$rateLimitKey] >= $maxRequests) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many requests. Please try again later.']);
    exit;
}

// Honeypot check - if this field is filled, it's a bot
if (!empty($_POST['website_url'])) {
    // Silently reject but return success to fool bots
    echo json_encode(['success' => true, 'message' => 'Request received']);
    exit;
}

// Referrer check (optional - can be bypassed but adds a layer)
$allowedReferrers = ['localhost', 'fis.co.tz', 'www.fis.co.tz', 'fabonic.co.tz', 'lite.fis.co.tz'];
$referrer = isset($_SERVER['HTTP_REFERER']) ? parse_url($_SERVER['HTTP_REFERER'], PHP_URL_HOST) : '';
$referrerValid = false;
foreach ($allowedReferrers as $allowed) {
    if (strpos($referrer, $allowed) !== false || empty($referrer)) {
        $referrerValid = true;
        break;
    }
}
if (!$referrerValid) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid request source']);
    exit;
}

// Get and validate form data with length limits
$fullname = isset($_POST['fullname']) ? substr(trim($_POST['fullname']), 0, 100) : '';
$business = isset($_POST['business']) ? substr(trim($_POST['business']), 0, 150) : '';
$email = isset($_POST['email']) ? substr(trim($_POST['email']), 0, 150) : '';
$phone = isset($_POST['phone']) ? substr(trim($_POST['phone']), 0, 20) : '';
$locations = isset($_POST['locations']) ? substr(trim($_POST['locations']), 0, 10) : '1';
$users = isset($_POST['users']) ? substr(trim($_POST['users']), 0, 10) : '1';
$businessType = isset($_POST['business_type']) ? substr(trim($_POST['business_type']), 0, 50) : '';
$message = isset($_POST['message']) ? substr(trim($_POST['message']), 0, 1000) : '';
$estimatedPrice = isset($_POST['estimated_price']) ? substr(trim($_POST['estimated_price']), 0, 50) : '';
$language = isset($_POST['language']) && $_POST['language'] === 'en' ? 'en' : 'sw';
$subdomain = isset($_POST['subdomain']) ? strtolower(preg_replace('/[^a-z0-9]/i', '', trim($_POST['subdomain']))) : '';

// Validate required fields
if (empty($fullname) || strlen($fullname) < 2) {
    echo json_encode(['success' => false, 'error' => $language === 'sw' ? 'Jina kamili linahitajika' : 'Full name is required']);
    exit;
}

if (empty($business) || strlen($business) < 2) {
    echo json_encode(['success' => false, 'error' => $language === 'sw' ? 'Jina la biashara linahitajika' : 'Business name is required']);
    exit;
}

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'error' => $language === 'sw' ? 'Barua pepe sahihi inahitajika' : 'Valid email address is required']);
    exit;
}

// Validate phone number format (Tanzania: +255, 0, or direct numbers)
$phoneClean = preg_replace('/[^0-9+]/', '', $phone);
if (strlen($phoneClean) < 9 || strlen($phoneClean) > 15) {
    echo json_encode(['success' => false, 'error' => $language === 'sw' ? 'Nambari ya simu sahihi inahitajika' : 'Valid phone number is required']);
    exit;
}

// ============================================
// ABUSE PREVENTION CHECKS
// ============================================
$ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
// Get first IP if multiple (X-Forwarded-For can have comma-separated list)
if (strpos($ipAddress, ',') !== false) {
    $ipAddress = trim(explode(',', $ipAddress)[0]);
}
$phoneFormatted = formatPhoneForWhatsApp($phone);
$emailLower = strtolower(trim($email));

$abusePdo = getCentralDbConnection();
if ($abusePdo) {
    // Check 1: Phone number already used
    $stmt = $abusePdo->prepare("SELECT COUNT(*) FROM trial_requests WHERE phone_formatted = :phone");
    $stmt->execute(['phone' => $phoneFormatted]);
    if ($stmt->fetchColumn() > 0) {
        echo json_encode([
            'success' => false, 
            'error' => $language === 'sw' 
                ? 'Nambari hii ya simu tayari imesajiliwa. Wasiliana nasi kwa msaada: +255 786 110 021' 
                : 'This phone number is already registered. Contact us for assistance: +255 786 110 021'
        ]);
        exit;
    }
    
    // Check 2: Email already used
    $stmt = $abusePdo->prepare("SELECT COUNT(*) FROM trial_requests WHERE LOWER(email) = :email");
    $stmt->execute(['email' => $emailLower]);
    if ($stmt->fetchColumn() > 0) {
        echo json_encode([
            'success' => false, 
            'error' => $language === 'sw' 
                ? 'Barua pepe hii tayari imesajiliwa. Wasiliana nasi kwa msaada: +255 786 110 021' 
                : 'This email is already registered. Contact us for assistance: +255 786 110 021'
        ]);
        exit;
    }
    
    // Check 3: IP rate limit - max 3 trials per IP per 24 hours
    $stmt = $abusePdo->prepare("
        SELECT COUNT(*) FROM trial_requests 
        WHERE ip_address = :ip AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $stmt->execute(['ip' => $ipAddress]);
    if ($stmt->fetchColumn() >= 3) {
        error_log('ABUSE BLOCKED: IP ' . $ipAddress . ' exceeded 3 trials in 24h');
        echo json_encode([
            'success' => false, 
            'error' => $language === 'sw' 
                ? 'Umezidi kikomo cha maombi. Jaribu tena baadaye au wasiliana nasi: +255 786 110 021' 
                : 'Request limit exceeded. Try again later or contact us: +255 786 110 021'
        ]);
        exit;
    }
}

// Sanitize inputs for email
$fullname = htmlspecialchars($fullname, ENT_QUOTES, 'UTF-8');
$business = htmlspecialchars($business, ENT_QUOTES, 'UTF-8');
$emailSafe = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
$phone = htmlspecialchars($phone, ENT_QUOTES, 'UTF-8');
$locations = htmlspecialchars($locations, ENT_QUOTES, 'UTF-8');
$users = htmlspecialchars($users, ENT_QUOTES, 'UTF-8');
$businessType = htmlspecialchars($businessType, ENT_QUOTES, 'UTF-8');
$message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
$estimatedPrice = htmlspecialchars($estimatedPrice, ENT_QUOTES, 'UTF-8');

// Generate reference number
$referenceNo = 'FIS-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 6));

// Increment rate limit counter
$_SESSION[$rateLimitKey]++;

// Email configuration
$to = 'info@fabonic.co.tz';
$subject = 'FIS LITE Trial Request [' . $referenceNo . '] - ' . $business;

// Get IP address safely
$ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
$ipAddress = htmlspecialchars($ipAddress, ENT_QUOTES, 'UTF-8');

// Store trial request in central database for drip campaign
$trialData = [
    'fullname' => $fullname,
    'business' => $business,
    'email' => $email,
    'phone' => $phone,
    'locations' => $locations,
    'users' => $users,
    'businessType' => $businessType,
    'estimatedPrice' => $estimatedPrice,
    'message' => $message,
    'language' => $language,
    'subdomain' => $subdomain
];
$storedInDb = storeTrialRequest($trialData, $referenceNo, $ipAddress);
if (!$storedInDb) {
    error_log('FIS LITE: Failed to store trial request in central DB - Ref: ' . $referenceNo);
}

// Build professional HTML email to admin
$adminEmailBody = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; font-family: \'Segoe UI\', Arial, sans-serif; background-color: #f4f4f4;">
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; margin: 20px auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.1);">
        <!-- Header -->
        <tr>
            <td style="background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%); padding: 30px; text-align: center;">
                <img src="https://fabonic.co.tz/wp-content/uploads/2021/12/logo.png" alt="Fabonic" style="height: 40px; margin-bottom: 15px;">
                <h1 style="color: white; margin: 0; font-size: 24px; font-weight: 600;">New Trial Request</h1>
                <p style="color: rgba(255,255,255,0.9); margin: 10px 0 0; font-size: 14px;">Reference: ' . $referenceNo . '</p>
            </td>
        </tr>
        
        <!-- Content -->
        <tr>
            <td style="padding: 30px;">
                <!-- Contact Info -->
                <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 25px;">
                    <tr>
                        <td style="padding: 15px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #0d6efd;">
                            <h3 style="margin: 0 0 15px; color: #333; font-size: 16px;">Contact Information</h3>
                            <table width="100%" cellpadding="5" cellspacing="0">
                                <tr>
                                    <td width="120" style="color: #666; font-size: 14px;">Full Name:</td>
                                    <td style="color: #333; font-weight: 600; font-size: 14px;">' . $fullname . '</td>
                                </tr>
                                <tr>
                                    <td style="color: #666; font-size: 14px;">Business:</td>
                                    <td style="color: #333; font-weight: 600; font-size: 14px;">' . $business . '</td>
                                </tr>
                                <tr>
                                    <td style="color: #666; font-size: 14px;">Email:</td>
                                    <td style="font-size: 14px;"><a href="mailto:' . $emailSafe . '" style="color: #0d6efd;">' . $emailSafe . '</a></td>
                                </tr>
                                <tr>
                                    <td style="color: #666; font-size: 14px;">Phone:</td>
                                    <td style="font-size: 14px;"><a href="tel:' . $phone . '" style="color: #0d6efd;">' . $phone . '</a></td>
                                </tr>
                                <tr>
                                    <td style="color: #666; font-size: 14px;">Business Type:</td>
                                    <td style="color: #333; font-size: 14px;">' . ($businessType ?: 'Not specified') . '</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
                
                <!-- Requirements -->
                <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 25px;">
                    <tr>
                        <td style="padding: 15px; background: #e8f4fd; border-radius: 8px;">
                            <h3 style="margin: 0 0 15px; color: #333; font-size: 16px;">Requirements</h3>
                            <table width="100%" cellpadding="10" cellspacing="0">
                                <tr>
                                    <td width="50%" style="text-align: center; background: white; border-radius: 8px; padding: 15px;">
                                        <div style="font-size: 28px; color: #0d6efd; font-weight: 700;">' . $locations . '</div>
                                        <div style="color: #666; font-size: 12px;">Locations</div>
                                    </td>
                                    <td width="50%" style="text-align: center; background: white; border-radius: 8px; padding: 15px;">
                                        <div style="font-size: 28px; color: #0d6efd; font-weight: 700;">' . $users . '</div>
                                        <div style="color: #666; font-size: 12px;">Users</div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
                
                <!-- Price Estimate -->
                <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 25px;">
                    <tr>
                        <td style="padding: 20px; background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%); border-radius: 8px; text-align: center;">
                            <div style="color: rgba(255,255,255,0.9); font-size: 12px; margin-bottom: 5px;">ESTIMATED MONTHLY PRICE</div>
                            <div style="color: white; font-size: 32px; font-weight: 700;">' . ($estimatedPrice ?: 'TZS 15,000') . '</div>
                        </td>
                    </tr>
                </table>
                
                ' . ($message ? '
                <!-- Message -->
                <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 25px;">
                    <tr>
                        <td style="padding: 15px; background: #fff3cd; border-radius: 8px; border-left: 4px solid #ffc107;">
                            <h3 style="margin: 0 0 10px; color: #856404; font-size: 14px;">Additional Message</h3>
                            <p style="margin: 0; color: #856404; font-size: 14px;">' . nl2br($message) . '</p>
                        </td>
                    </tr>
                </table>
                ' : '') . '
                
                <!-- Quick Actions -->
                <table width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                        <td style="text-align: center; padding: 20px 0;">
                            <a href="https://wa.me/255' . preg_replace('/[^0-9]/', '', $phone) . '?text=Habari%20' . urlencode($fullname) . ',%20asante%20kwa%20kuomba%20FIS%20LITE%20trial.%20Ref:%20' . $referenceNo . '" 
                               style="display: inline-block; padding: 12px 25px; background: #25D366; color: white; text-decoration: none; border-radius: 50px; font-weight: 600; margin: 5px;">
                                Reply via WhatsApp
                            </a>
                            <a href="mailto:' . $emailSafe . '?subject=Re:%20FIS%20LITE%20Trial%20Request%20[' . $referenceNo . ']" 
                               style="display: inline-block; padding: 12px 25px; background: #0d6efd; color: white; text-decoration: none; border-radius: 50px; font-weight: 600; margin: 5px;">
                                Reply via Email
                            </a>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
        
        <!-- Footer -->
        <tr>
            <td style="background: #f8f9fa; padding: 20px; text-align: center; border-top: 1px solid #e9ecef;">
                <p style="margin: 0 0 5px; color: #666; font-size: 12px;">Submitted: ' . date('d M Y, H:i:s') . ' | IP: ' . $ipAddress . '</p>
                <p style="margin: 0; color: #666; font-size: 12px;">Language: ' . ($language === 'sw' ? 'Swahili' : 'English') . '</p>
            </td>
        </tr>
    </table>
</body>
</html>';

// Email headers
$headers = [
    'MIME-Version: 1.0',
    'Content-type: text/html; charset=UTF-8',
    'From: FIS LITE Website <noreply@fis.co.tz>',
    'Reply-To: ' . $email,
    'X-Mailer: PHP/' . phpversion(),
    'X-Priority: 1'
];

// Send email to admin
$mailSent = @mail($to, $subject, $adminEmailBody, implode("\r\n", $headers));

if ($mailSent) {
    // Send WhatsApp notification to admin
    $whatsAppData = [
        'fullname' => $fullname,
        'business' => $business,
        'email' => $emailSafe,
        'phone' => $phone,
        'locations' => $locations,
        'users' => $users,
        'estimatedPrice' => $estimatedPrice ?: 'TZS 15,000',
        'businessType' => $businessType,
        'message' => $message
    ];
    $whatsAppSent = sendWhatsAppNotification($whatsAppData);
    if (!$whatsAppSent) {
        error_log('FIS LITE Trial Request - WhatsApp admin notification failed for: ' . $email . ' Ref: ' . $referenceNo);
    }
    
    // Send WhatsApp confirmation to customer
    $customerWhatsAppData = [
        'fullname' => $fullname,
        'referenceNo' => $referenceNo,
        'business' => $business,
        'estimatedPrice' => $estimatedPrice ?: 'TZS 15,000'
    ];
    $customerWhatsAppSent = sendWhatsAppCustomerConfirmation($phone, $customerWhatsAppData, $language);
    if (!$customerWhatsAppSent) {
        error_log('FIS LITE Trial Request - WhatsApp customer confirmation failed for: ' . $phone . ' Ref: ' . $referenceNo);
    }
    
    // Send professional confirmation email to customer
    if ($language === 'sw') {
        $customerSubject = 'Asante kwa Kuomba FIS LITE Trial - Ref: ' . $referenceNo;
        $greeting = 'Habari ' . $fullname . ',';
        $thankYou = 'Asante kwa kupendezwa na <strong>FIS LITE</strong> - Mfumo wetu wa Usimamizi wa Bidhaa.';
        $received = 'Tumeipokea ombi lako la majaribio ya bure. Timu yetu itawasiliana nawe ndani ya <strong>masaa 24</strong>.';
        $refLabel = 'Nambari ya Rejea:';
        $detailsTitle = 'Maelezo ya Ombi Lako';
        $businessLabel = 'Biashara:';
        $locationsLabel = 'Maeneo:';
        $usersLabel = 'Watumiaji:';
        $priceLabel = 'Bei ya Mwezi:';
        $whileWaiting = 'Wakati Unasubiri';
        $tryDemo = 'Jaribu demo yetu ya moja kwa moja kuona vipengele vyote';
        $tryDemoBtn = 'Jaribu Demo Sasa';
        $whatsappText = 'Unahitaji msaada wa haraka? Wasiliana nasi kupitia WhatsApp';
        $whatsappBtn = 'Ongea WhatsApp';
        $questions = 'Una maswali? Jibu barua pepe hii au piga simu +255 786 110 021';
        $thanks = 'Asante kwa kuchagua FIS LITE!';
        $team = 'Timu ya FIS LITE';
    } else {
        $customerSubject = 'Thank You for Your FIS LITE Trial Request - Ref: ' . $referenceNo;
        $greeting = 'Dear ' . $fullname . ',';
        $thankYou = 'Thank you for your interest in <strong>FIS LITE</strong> - Our Inventory Management System.';
        $received = 'We have received your free trial request. Our team will contact you within <strong>24 hours</strong>.';
        $refLabel = 'Reference Number:';
        $detailsTitle = 'Your Request Details';
        $businessLabel = 'Business:';
        $locationsLabel = 'Locations:';
        $usersLabel = 'Users:';
        $priceLabel = 'Monthly Price:';
        $whileWaiting = 'While You Wait';
        $tryDemo = 'Try our live demo to explore all features';
        $tryDemoBtn = 'Try Demo Now';
        $whatsappText = 'Need immediate assistance? Contact us via WhatsApp';
        $whatsappBtn = 'Chat on WhatsApp';
        $questions = 'Have questions? Reply to this email or call +255 786 110 021';
        $thanks = 'Thank you for choosing FIS LITE!';
        $team = 'The FIS LITE Team';
    }
    
    $customerBody = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; font-family: \'Segoe UI\', Arial, sans-serif; background-color: #f4f4f4;">
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; margin: 20px auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.1);">
        <!-- Header -->
        <tr>
            <td style="background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%); padding: 40px 30px; text-align: center;">
                <img src="https://fabonic.co.tz/wp-content/uploads/2021/12/logo.png" alt="Fabonic" style="height: 45px; margin-bottom: 20px;">
                <h1 style="color: white; margin: 0; font-size: 28px; font-weight: 600;">FIS LITE</h1>
                <p style="color: rgba(255,255,255,0.9); margin: 10px 0 0; font-size: 14px;">Fabonic Information System - LITE</p>
            </td>
        </tr>
        
        <!-- Success Icon -->
        <tr>
            <td style="text-align: center; padding: 30px 30px 0;">
                <div style="width: 70px; height: 70px; background: #d4edda; border-radius: 50%; margin: 0 auto; display: flex; align-items: center; justify-content: center;">
                    <span style="font-size: 35px; line-height: 70px;">✓</span>
                </div>
            </td>
        </tr>
        
        <!-- Content -->
        <tr>
            <td style="padding: 30px;">
                <p style="font-size: 18px; color: #333; margin: 0 0 15px;">' . $greeting . '</p>
                <p style="font-size: 15px; color: #555; margin: 0 0 15px; line-height: 1.6;">' . $thankYou . '</p>
                <p style="font-size: 15px; color: #555; margin: 0 0 25px; line-height: 1.6;">' . $received . '</p>
                
                <!-- Reference Number -->
                <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 25px;">
                    <tr>
                        <td style="background: #e8f4fd; padding: 15px 20px; border-radius: 8px; text-align: center;">
                            <span style="color: #666; font-size: 12px;">' . $refLabel . '</span>
                            <div style="color: #0d6efd; font-size: 20px; font-weight: 700; margin-top: 5px;">' . $referenceNo . '</div>
                        </td>
                    </tr>
                </table>
                
                <!-- Request Details -->
                <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 25px; border: 1px solid #e9ecef; border-radius: 8px; overflow: hidden;">
                    <tr>
                        <td style="background: #f8f9fa; padding: 12px 15px; font-weight: 600; color: #333; font-size: 14px; border-bottom: 1px solid #e9ecef;">' . $detailsTitle . '</td>
                    </tr>
                    <tr>
                        <td style="padding: 15px;">
                            <table width="100%" cellpadding="8" cellspacing="0">
                                <tr>
                                    <td width="40%" style="color: #666; font-size: 14px;">' . $businessLabel . '</td>
                                    <td style="color: #333; font-weight: 600; font-size: 14px;">' . $business . '</td>
                                </tr>
                                <tr>
                                    <td style="color: #666; font-size: 14px; border-top: 1px solid #f0f0f0; padding-top: 12px;">' . $locationsLabel . '</td>
                                    <td style="color: #333; font-size: 14px; border-top: 1px solid #f0f0f0; padding-top: 12px;">' . $locations . '</td>
                                </tr>
                                <tr>
                                    <td style="color: #666; font-size: 14px; border-top: 1px solid #f0f0f0; padding-top: 12px;">' . $usersLabel . '</td>
                                    <td style="color: #333; font-size: 14px; border-top: 1px solid #f0f0f0; padding-top: 12px;">' . $users . '</td>
                                </tr>
                                <tr>
                                    <td style="color: #666; font-size: 14px; border-top: 1px solid #f0f0f0; padding-top: 12px;">' . $priceLabel . '</td>
                                    <td style="color: #28a745; font-weight: 700; font-size: 16px; border-top: 1px solid #f0f0f0; padding-top: 12px;">' . ($estimatedPrice ?: 'TZS 15,000') . '</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
                
                <!-- While You Wait Section -->
                <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 25px;">
                    <tr>
                        <td style="background: #f8f9fa; padding: 20px; border-radius: 8px;">
                            <h3 style="margin: 0 0 15px; color: #333; font-size: 16px;">' . $whileWaiting . '</h3>
                            
                            <table width="100%" cellpadding="10" cellspacing="0">
                                <tr>
                                    <td style="background: white; border-radius: 8px; padding: 15px; margin-bottom: 10px;">
                                        <p style="margin: 0 0 10px; color: #555; font-size: 14px;">' . $tryDemo . '</p>
                                        <a href="https://lite.fis.co.tz" style="display: inline-block; padding: 10px 20px; background: #0d6efd; color: white; text-decoration: none; border-radius: 50px; font-size: 13px; font-weight: 600;">' . $tryDemoBtn . '</a>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="background: white; border-radius: 8px; padding: 15px; margin-top: 10px;">
                                        <p style="margin: 0 0 10px; color: #555; font-size: 14px;">' . $whatsappText . '</p>
                                        <a href="https://wa.me/255786110021" style="display: inline-block; padding: 10px 20px; background: #25D366; color: white; text-decoration: none; border-radius: 50px; font-size: 13px; font-weight: 600;">' . $whatsappBtn . '</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
                
                <p style="font-size: 14px; color: #666; margin: 0 0 20px; text-align: center;">' . $questions . '</p>
                
                <p style="font-size: 15px; color: #333; margin: 20px 0 5px; text-align: center;">' . $thanks . '</p>
                <p style="font-size: 14px; color: #0d6efd; margin: 0; text-align: center; font-weight: 600;">' . $team . '</p>
            </td>
        </tr>
        
        <!-- Footer -->
        <tr>
            <td style="background: #1a1a2e; padding: 25px; text-align: center;">
                <img src="https://fabonic.co.tz/wp-content/uploads/2021/12/logo.png" alt="Fabonic" style="height: 30px; margin-bottom: 15px; opacity: 0.9;">
                <p style="margin: 0 0 10px; color: rgba(255,255,255,0.8); font-size: 13px;"><strong>Fabonic Ltd</strong></p>
                <p style="margin: 0 0 5px; color: rgba(255,255,255,0.6); font-size: 12px;">Ghadeer Towers, Indira Gandhi Street</p>
                <p style="margin: 0 0 15px; color: rgba(255,255,255,0.6); font-size: 12px;">Dar es Salaam, Tanzania</p>
                <p style="margin: 0; color: rgba(255,255,255,0.6); font-size: 12px;">
                    <a href="tel:+255786110021" style="color: rgba(255,255,255,0.8); text-decoration: none;">+255 786 110 021</a> | 
                    <a href="mailto:info@fabonic.co.tz" style="color: rgba(255,255,255,0.8); text-decoration: none;">info@fabonic.co.tz</a>
                </p>
                <div style="margin-top: 15px;">
                    <a href="https://www.facebook.com/fabonic/" style="display: inline-block; width: 30px; height: 30px; background: rgba(255,255,255,0.1); border-radius: 50%; margin: 0 5px; line-height: 30px; color: white; text-decoration: none; font-size: 14px;">f</a>
                    <a href="https://www.instagram.com/fabonic/" style="display: inline-block; width: 30px; height: 30px; background: rgba(255,255,255,0.1); border-radius: 50%; margin: 0 5px; line-height: 30px; color: white; text-decoration: none; font-size: 14px;">ig</a>
                    <a href="https://wa.me/255786110021" style="display: inline-block; width: 30px; height: 30px; background: rgba(255,255,255,0.1); border-radius: 50%; margin: 0 5px; line-height: 30px; color: white; text-decoration: none; font-size: 14px;">wa</a>
                </div>
            </td>
        </tr>
    </table>
    
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; margin: 0 auto;">
        <tr>
            <td style="padding: 20px; text-align: center;">
                <p style="margin: 0; color: #999; font-size: 11px;">This email was sent because you requested a free trial of FIS LITE.</p>
                <p style="margin: 5px 0 0; color: #999; font-size: 11px;">© ' . date('Y') . ' Fabonic Ltd. All rights reserved.</p>
            </td>
        </tr>
    </table>
</body>
</html>';
    
    $customerHeaders = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        'From: FIS LITE <info@fabonic.co.tz>',
        'Reply-To: info@fabonic.co.tz',
        'X-Mailer: PHP/' . phpversion()
    ];
    
    @mail($email, $customerSubject, $customerBody, implode("\r\n", $customerHeaders));
    
    echo json_encode([
        'success' => true,
        'message' => 'Trial request sent successfully',
        'reference' => $referenceNo
    ]);
} else {
    error_log('FIS LITE Trial Request Failed - To: ' . $to . ' | From: ' . $email . ' | Time: ' . date('Y-m-d H:i:s'));
    
    echo json_encode([
        'success' => false,
        'error' => $language === 'sw' 
            ? 'Imeshindwa kutuma. Tafadhali wasiliana nasi kupitia WhatsApp: +255 786 110 021' 
            : 'Failed to send. Please contact us via WhatsApp: +255 786 110 021'
    ]);
}
