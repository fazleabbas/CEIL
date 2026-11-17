<?php
/**
 * FIS LITE - Subdomain Availability Checker
 * Checks if a subdomain is available for trial registration
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Use same database config as main app
require_once '../config/database.php';

// Central database uses same credentials
define('CENTRAL_DB_HOST', DB_HOST);
define('CENTRAL_DB_NAME', DB_NAME);
define('CENTRAL_DB_USER', DB_USER);
define('CENTRAL_DB_PASS', DB_PASS);

// Subdomain constraints
define('SUBDOMAIN_MIN_LENGTH', 3);
define('SUBDOMAIN_MAX_LENGTH', 20);
define('RESERVED_SUBDOMAINS', [
    'admin', 'api', 'www', 'demo', 'test', 'staging', 'dev', 'app',
    'mail', 'email', 'smtp', 'ftp', 'ssh', 'cpanel', 'webmail',
    'support', 'help', 'billing', 'payment', 'pay', 'checkout',
    'login', 'register', 'signup', 'signin', 'auth', 'oauth',
    'static', 'assets', 'cdn', 'media', 'images', 'img', 'files',
    'download', 'downloads', 'upload', 'uploads',
    'fis', 'fislite', 'lite', 'fabonic', 'fabapps',
    'root', 'system', 'null', 'undefined', 'default', 'client'
]);

// Blocked words (profanity, obscene, offensive)
define('BLOCKED_WORDS', [
    // English profanity
    'fuck', 'shit', 'ass', 'bitch', 'damn', 'crap', 'piss', 'dick', 'cock',
    'pussy', 'asshole', 'bastard', 'slut', 'whore', 'cunt', 'fag', 'faggot',
    'nigger', 'nigga', 'retard', 'porn', 'xxx', 'sex', 'sexy', 'nude', 'naked',
    'penis', 'vagina', 'boob', 'tits', 'dildo', 'viagra', 'cialis',
    // Swahili profanity
    'malaya', 'kahaba', 'kuma', 'mboo', 'mavi', 'matako', 'fisi', 'punda',
    'mjinga', 'mwizi', 'shetani', 'kafiri',
    // Offensive/hate
    'hitler', 'nazi', 'isis', 'terrorist', 'kill', 'murder', 'rape', 'death',
    'hate', 'racist', 'abuse', 'scam', 'fraud', 'fake', 'spam', 'virus', 'hack', 'hacker'
]);

/**
 * Sanitize and validate subdomain format
 */
function sanitizeSubdomain($subdomain) {
    $subdomain = strtolower(trim($subdomain));
    $subdomain = preg_replace('/[^a-z0-9]/', '', $subdomain);
    return $subdomain;
}

/**
 * Validate subdomain format
 */
function validateSubdomain($subdomain) {
    if (strlen($subdomain) < SUBDOMAIN_MIN_LENGTH) {
        return ['valid' => false, 'error' => 'too_short', 'message' => 'Subdomain must be at least ' . SUBDOMAIN_MIN_LENGTH . ' characters'];
    }
    
    if (strlen($subdomain) > SUBDOMAIN_MAX_LENGTH) {
        return ['valid' => false, 'error' => 'too_long', 'message' => 'Subdomain must be at most ' . SUBDOMAIN_MAX_LENGTH . ' characters'];
    }
    
    if (!preg_match('/^[a-z][a-z0-9]*$/', $subdomain)) {
        return ['valid' => false, 'error' => 'invalid_format', 'message' => 'Subdomain must start with a letter and contain only letters and numbers'];
    }
    
    if (in_array($subdomain, RESERVED_SUBDOMAINS)) {
        return ['valid' => false, 'error' => 'reserved', 'message' => 'This subdomain is reserved'];
    }
    
    // Check for blocked/profane words (exact match or contained within)
    foreach (BLOCKED_WORDS as $blocked) {
        if ($subdomain === $blocked || strpos($subdomain, $blocked) !== false) {
            return ['valid' => false, 'error' => 'inappropriate', 'message' => 'This subdomain contains inappropriate content'];
        }
    }
    
    return ['valid' => true];
}

/**
 * Check if subdomain exists in database
 */
function getDbConnection() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . CENTRAL_DB_HOST . ";dbname=" . CENTRAL_DB_NAME . ";charset=utf8mb4",
                CENTRAL_DB_USER,
                CENTRAL_DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            error_log('Subdomain check DB connection error: ' . $e->getMessage());
            return null;
        }
    }
    return $pdo;
}

function isSubdomainTaken($subdomain) {
    try {
        $pdo = getDbConnection();
        if (!$pdo) return null;
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM trial_requests WHERE tenant_subdomain = :subdomain");
        $stmt->execute(['subdomain' => $subdomain]);
        return $stmt->fetchColumn() > 0;
        
    } catch (PDOException $e) {
        error_log('Subdomain check query error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Check if subdomain contains blocked words
 */
function containsBlockedWord($subdomain) {
    foreach (BLOCKED_WORDS as $blocked) {
        if ($subdomain === $blocked || strpos($subdomain, $blocked) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Generate alternative subdomain suggestions
 */
function generateSuggestions($baseSubdomain, $count = 3) {
    // Don't generate suggestions for inappropriate base words
    if (containsBlockedWord($baseSubdomain)) {
        return [];
    }
    
    $suggestions = [];
    $suffixes = ['ltd', 'tz', 'co', 'hq', 'app', 'biz'];
    
    // Try with common suffixes
    foreach ($suffixes as $suffix) {
        $suggestion = $baseSubdomain . $suffix;
        if (strlen($suggestion) <= SUBDOMAIN_MAX_LENGTH && !isSubdomainTaken($suggestion)) {
            $suggestions[] = $suggestion;
            if (count($suggestions) >= $count) break;
        }
    }
    
    // Try with numbers if still need more
    if (count($suggestions) < $count) {
        for ($i = 1; $i <= 99 && count($suggestions) < $count; $i++) {
            $suggestion = $baseSubdomain . $i;
            if (strlen($suggestion) <= SUBDOMAIN_MAX_LENGTH && !isSubdomainTaken($suggestion)) {
                $suggestions[] = $suggestion;
            }
        }
    }
    
    return $suggestions;
}

// Handle request
$subdomain = $_GET['subdomain'] ?? $_POST['subdomain'] ?? '';
$subdomain = sanitizeSubdomain($subdomain);

if (empty($subdomain)) {
    echo json_encode(['success' => false, 'error' => 'empty', 'message' => 'Subdomain is required']);
    exit;
}

// Validate format
$validation = validateSubdomain($subdomain);
if (!$validation['valid']) {
    echo json_encode([
        'success' => false,
        'available' => false,
        'error' => $validation['error'],
        'message' => $validation['message'],
        'suggestions' => generateSuggestions($subdomain)
    ]);
    exit;
}

// Check availability
$taken = isSubdomainTaken($subdomain);

if ($taken === null) {
    echo json_encode(['success' => false, 'error' => 'db_error', 'message' => 'Unable to check availability']);
    exit;
}

if ($taken) {
    echo json_encode([
        'success' => true,
        'available' => false,
        'subdomain' => $subdomain,
        'message' => 'This subdomain is already taken',
        'suggestions' => generateSuggestions($subdomain)
    ]);
} else {
    echo json_encode([
        'success' => true,
        'available' => true,
        'subdomain' => $subdomain,
        'message' => 'Subdomain is available'
    ]);
}
