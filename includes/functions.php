<?php
/**
 * Common Functions
 * Reusable functions across the application
 */

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

/**
 * Does THIS request count as user activity?
 *
 * An entry point sets this constant to true BEFORE requiring this file to say
 * "I fire on a timer, not because a human did something". Only
 * ajax/notifications.php does that (it polls every 60s from includes/header.php),
 * and it flips itself back to active when the browser reports real interaction.
 *
 * Without this the idle timeout could never fire: that poll calls session_start()
 * once a minute forever, which is also why PHP's own gc_maxlifetime never
 * expires a session in this app while a tab is open.
 */
if (!defined('SESSION_ACTIVITY_PASSIVE')) {
    define('SESSION_ACTIVITY_PASSIVE', false);
}

// Idle-logout enforcement. Must run before anything reads the session.
enforceSessionTimeout();

// Handle language switch early (before any translations are used)
if (isset($_GET['lang'])) {
    $allowedLangs = ['en', 'sw'];
    if (in_array($_GET['lang'], $allowedLangs)) {
        $_SESSION['lang'] = $_GET['lang'];
    }
}

// Language translations cache
$GLOBALS['lang_cache'] = null;

/**
 * Get current language code
 */
function getCurrentLang() {
    return $_SESSION['lang'] ?? 'en';
}

/**
 * Set current language
 */
function setLang($lang) {
    $allowed = ['en', 'sw'];
    if (in_array($lang, $allowed)) {
        $_SESSION['lang'] = $lang;
        $GLOBALS['lang_cache'] = null; // Clear cache
    }
}

/**
 * Load language file
 */
function loadLang($lang = null) {
    if ($lang === null) {
        $lang = getCurrentLang();
    }
    
    $langFile = __DIR__ . '/../lang/' . $lang . '.php';
    if (file_exists($langFile)) {
        return require $langFile;
    }
    
    // Fallback to English
    return require __DIR__ . '/../lang/en.php';
}

/**
 * Get translation for a key
 * Usage: __('login_title') or __('welcome')
 */
function __($key, $default = null) {
    if ($GLOBALS['lang_cache'] === null) {
        $GLOBALS['lang_cache'] = loadLang();
    }
    
    return $GLOBALS['lang_cache'][$key] ?? $default ?? $key;
}

/**
 * Echo translation for a key
 * Usage: _e('login_title')
 */
function _e($key, $default = null) {
    echo __($key, $default);
}

/**
 * How many minutes of inactivity before the user is signed out.
 * 0 (or anything non-positive) disables the timeout entirely.
 *
 * Default 15. The try/catch matters: this runs on EVERY request, including
 * before the settings table exists on a half-provisioned tenant, and a throw
 * here would take the whole app down.
 */
function getSessionTimeoutMinutes() {
    static $minutes = null;

    if ($minutes === null) {
        // Throwable, not Exception: a missing $conn raises an Error, and this
        // runs on literally every request.
        try {
            $minutes = (int)getSetting('session_timeout_minutes', 15);
        } catch (Throwable $e) {
            $minutes = 15;
        }
        if ($minutes < 0) {
            $minutes = 0;
        }
    }

    return $minutes;
}

/**
 * Sign the user out after N minutes with no activity.
 *
 * Called once, immediately after session_start(). Authoritative — the
 * client-side timer in includes/header.php is only there so the user SEES it
 * happen instead of discovering it on their next click.
 *
 * Deliberately does NOT session_destroy(): clearing user_id is enough for
 * isLoggedIn() to return false, which means every existing gate already does
 * the right thing (header.php redirects pages to login, the ~50 api/ endpoints
 * return their 401) with no changes to any of them. Keeping the session alive
 * also lets us carry the one-shot `session_expired` flag so login.php can
 * explain what happened, and preserves the chosen language.
 */
function enforceSessionTimeout() {
    if (empty($_SESSION['user_id'])) {
        return;
    }

    $minutes = getSessionTimeoutMinutes();
    if ($minutes <= 0) {
        return;   // disabled — behaves exactly as the app always did
    }

    $now     = time();
    $timeout = $minutes * 60;
    $last    = $_SESSION['last_activity'] ?? null;

    if ($last !== null && ($now - (int)$last) > $timeout) {
        $userId = $_SESSION['user_id'];

        unset($_SESSION['user_id'], $_SESSION['user_data'], $_SESSION['last_activity']);
        $_SESSION['session_expired'] = 1;   // read + cleared by modules/auth/login.php

        try {
            logAudit('auth', 'session_timeout', $userId);
        } catch (Throwable $e) {
            // Never let an audit failure block the sign-out.
        }
        return;
    }

    // Only a real interaction pushes the clock forward. A passive request (the
    // 60-second notification poll with no interaction since the last one) is
    // allowed to check expiry above but must not postpone it.
    if (!SESSION_ACTIVITY_PASSIVE) {
        $_SESSION['last_activity'] = $now;
    }
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Get current user ID
 */
function getUserId() {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Get current user data
 */
function getUserData($key = null) {
    if ($key) {
        return $_SESSION['user_data'][$key] ?? null;
    }
    return $_SESSION['user_data'] ?? [];
}

/**
 * Check if user has permission for a module
 */
function hasPermission($module, $action = 'view') {
    global $conn;
    
    // Admin has all permissions
    if (getUserData('role_name') == 'Admin') {
        return true;
    }
    
    $userId = getUserId();
    if (!$userId) {
        return false;
    }
    
    $column = 'can_' . $action; // can_view, can_create, can_edit, can_delete
    
    $stmt = $conn->prepare("SELECT $column FROM user_permissions WHERE user_id = ? AND module_name = ?");
    $stmt->execute([$userId, $module]);
    $result = $stmt->fetch();
    
    return $result && $result[$column] == 1;
}

/**
 * The per-report View rights, as right key => label lang key. Each is stored in
 * user_permissions as module_name 'report_<key>'.
 *
 * 'stock_report' also covers Product Stock Card and Location Breakdown, which
 * have no menu entry and are only reached by clicking through. 'stock_value'
 * covers the breakdown page and its three drill-down pages.
 */
function getReportRights() {
    return [
        'stock_report' => 'stock_report',
        'stock_report_by_type' => 'stock_report_by_type',
        'dead_stock' => 'dead_stock',
        'stock_value' => 'total_stock_value',
        'profit_loss' => 'profit_loss',
        'cost_centre_report' => 'cost_centre_report',
        'cost_centre_comparison' => 'cost_centre_comparison',
        'product_analysis' => 'product_analysis',
        'ledger_report' => 'ledger_report',
    ];
}

/**
 * Whether the current user may view one report.
 *
 * The old 'reports' row still admits every report until migration 0050 has run,
 * so uploading the code first cannot lock anyone out. The migration deletes that
 * row, and Masters -> Users no longer offers it.
 *
 * Rights are read once per request because the menu checks every report on
 * every page load.
 */
function canViewReport($key) {
    global $conn;
    static $granted = null;

    if (getUserData('role_name') == 'Admin') {
        return true;
    }
    if ($granted === null) {
        $granted = [];
        $userId = getUserId();
        if ($userId) {
            $stmt = $conn->prepare("
                SELECT module_name FROM user_permissions
                WHERE user_id = ? AND can_view = 1
                  AND (module_name = 'reports' OR module_name LIKE 'report\\_%')
            ");
            $stmt->execute([$userId]);
            $granted = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
        }
    }
    return isset($granted['reports']) || isset($granted['report_' . $key]);
}

/**
 * Whether the current user may view at least one report (drives the menu).
 */
function canViewAnyReport() {
    foreach (array_keys(getReportRights()) as $key) {
        if (canViewReport($key)) {
            return true;
        }
    }
    return false;
}

/**
 * Check if user can access a location
 */
function canAccessLocation($locationId) {
    global $conn;
    
    // Admin can access all locations
    if (getUserData('role_name') == 'Admin') {
        return true;
    }
    
    $userId = getUserId();
    if (!$userId) {
        return false;
    }
    
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM user_locations WHERE user_id = ? AND location_id = ?");
    $stmt->execute([$userId, $locationId]);
    $result = $stmt->fetch();
    
    return $result['count'] > 0;
}

/**
 * Redirect to a page
 */
function redirect($url) {
    header("Location: " . BASE_URL . $url);
    exit();
}

/**
 * Display alert message
 */
function setAlert($message, $type = 'success') {
    $_SESSION['alert'] = [
        'message' => $message,
        'type' => $type // success, error, warning, info
    ];
}

/**
 * Get and clear alert message
 */
function getAlert() {
    if (isset($_SESSION['alert'])) {
        $alert = $_SESSION['alert'];
        unset($_SESSION['alert']);
        return $alert;
    }
    return null;
}

/**
 * Sanitize input
 */
function clean($data) {
    if (is_array($data)) {
        return array_map('clean', $data);
    }
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

/**
 * Sanitize text for storage WITHOUT HTML-encoding.
 *
 * Strips tags and trims, but stores the real characters (e.g. apostrophes,
 * quotes) instead of HTML entities like &#039;. Use this for free-text fields
 * that are always escaped at output time (escapeHtml in JS, htmlspecialchars
 * in server-rendered views). Storing raw avoids the "9&#039;&#039;" corruption
 * and the progressive double-encoding caused by re-saving clean()'d values.
 */
function cleanText($data) {
    if (is_array($data)) {
        return array_map('cleanText', $data);
    }
    return trim(strip_tags($data));
}

/**
 * Format date for display
 */
function formatDate($date, $format = DATE_FORMAT) {
    if (empty($date) || $date == '0000-00-00') {
        return '';
    }
    return date($format, strtotime($date));
}

/**
 * Format datetime for display
 */
function formatDateTime($datetime, $format = DATETIME_FORMAT) {
    if (empty($datetime) || $datetime == '0000-00-00 00:00:00') {
        return '';
    }
    return date($format, strtotime($datetime));
}

/**
 * Format number for display
 */
function formatNumber($number, $decimals = 2) {
    return number_format($number, $decimals);
}

/**
 * Format currency
 * @param float $amount
 * @param int $decimals
 * @param array|null $currency Currency row from DB (with 'symbol', 'decimal_places'). Null = default currency.
 */
function formatCurrency($amount, $decimals = 2, $currency = null) {
    if ($currency === null) {
        $currency = getDefaultCurrency();
    }
    if ($currency) {
        $symbol = $currency['symbol'];
        $dp = isset($currency['decimal_places']) ? (int)$currency['decimal_places'] : $decimals;
    } else {
        $symbol = CURRENCY_SYMBOL;
        $dp = $decimals;
    }
    return $symbol . ' ' . formatNumber($amount, $dp);
}

/**
 * Get the default currency row (cached per request)
 * @return array|null Currency row with id, code, name, symbol, decimal_places
 */
function getDefaultCurrency() {
    global $conn;
    static $defaultCurrency = null;
    static $loaded = false;

    if (!$loaded) {
        $loaded = true;
        try {
            $stmt = $conn->prepare("SELECT * FROM currencies WHERE is_default = 1 AND is_active = 1 LIMIT 1");
            $stmt->execute();
            $defaultCurrency = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Exception $e) {
            $defaultCurrency = null;
        }
    }
    return $defaultCurrency;
}

/**
 * Get a currency by its ID (cached per request)
 * @param int $currencyId
 * @return array|null
 */
function getCurrency($currencyId) {
    global $conn;
    static $cache = [];

    if ($currencyId === null) {
        return getDefaultCurrency();
    }

    $currencyId = (int)$currencyId;
    if (!isset($cache[$currencyId])) {
        $stmt = $conn->prepare("SELECT * FROM currencies WHERE id = ?");
        $stmt->execute([$currencyId]);
        $cache[$currencyId] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    return $cache[$currencyId];
}

/**
 * Get all active currencies
 * @return array
 */
function getAllCurrencies() {
    global $conn;
    static $currencies = null;

    if ($currencies === null) {
        $stmt = $conn->query("SELECT * FROM currencies WHERE is_active = 1 ORDER BY is_default DESC, code ASC");
        $currencies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    return $currencies;
}

/**
 * Get exchange rate between two currencies for a given date.
 * Returns the latest rate on or before $date.
 * If from and to are the same currency, returns 1.
 *
 * @param int $fromCurrencyId
 * @param int $toCurrencyId
 * @param string $date Date in Y-m-d format
 * @return float|null Rate, or null if not found
 */
function getExchangeRate($fromCurrencyId, $toCurrencyId, $date) {
    global $conn;

    $fromCurrencyId = (int)$fromCurrencyId;
    $toCurrencyId = (int)$toCurrencyId;

    if ($fromCurrencyId === $toCurrencyId) {
        return 1.0;
    }

    $stmt = $conn->prepare("
        SELECT rate FROM exchange_rates
        WHERE from_currency_id = ? AND to_currency_id = ? AND effective_date <= ?
        ORDER BY effective_date DESC
        LIMIT 1
    ");
    $stmt->execute([$fromCurrencyId, $toCurrencyId, $date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        return floatval($row['rate']);
    }

    // Try reverse rate
    $stmt->execute([$toCurrencyId, $fromCurrencyId, $date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row && floatval($row['rate']) != 0) {
        return 1.0 / floatval($row['rate']);
    }

    return null;
}

/**
 * Get the exchange rate to the base (default) currency for a given currency and date.
 * Used when saving transactions to store the rate snapshot.
 *
 * @param int $currencyId The transaction currency
 * @param string $date Date in Y-m-d format
 * @return float Exchange rate (1.0 if same as base currency)
 */
function getExchangeRateToBase($currencyId, $date) {
    $defaultCurrency = getDefaultCurrency();
    if (!$defaultCurrency) {
        return 1.0;
    }

    $currencyId = (int)$currencyId;
    if ($currencyId === (int)$defaultCurrency['id']) {
        return 1.0;
    }

    $rate = getExchangeRate($currencyId, $defaultCurrency['id'], $date);
    return $rate !== null ? $rate : 1.0;
}

/**
 * Convert an amount to the base currency using a given rate
 *
 * @param float $amount Amount in transaction currency
 * @param float $exchangeRateToBase The rate stored on the transaction
 * @return float Amount in base currency
 */
function convertToBaseCurrency($amount, $exchangeRateToBase) {
    return floatval($amount) * floatval($exchangeRateToBase);
}

/**
 * Convert date from display format to database format
 */
function dbDate($date) {
    if (empty($date)) return '';
    return date('Y-m-d', strtotime($date));
}

/**
 * Get next transaction number
 */
function getNextNumber($table, $column, $prefix) { 
    global $conn;
    
    $stmt = $conn->prepare("SELECT $column FROM $table WHERE $column LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $result = $stmt->fetch();
    
    if ($result) {
        $lastNumber = $result[$column];
        $numericPart = intval(substr($lastNumber, strlen($prefix))) + 1;
    } else {
        $numericPart = 1;
    }
    
    return $prefix . str_pad($numericPart, 6, '0', STR_PAD_LEFT);
}

/**
 * Log audit trail
 */
function logAudit($module, $action, $recordId = null, $oldValues = null, $newValues = null) {
    global $conn;
    
    $userId = getUserId();
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    $stmt = $conn->prepare("
        INSERT INTO audit_log (user_id, module, action, record_id, old_values, new_values, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $userId,
        $module,
        $action,
        $recordId,
        $oldValues ? json_encode($oldValues) : null,
        $newValues ? json_encode($newValues) : null,
        $ipAddress,
        $userAgent
    ]);
}

/**
 * Check CSRF token
 */
function checkCSRF() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            die('CSRF token validation failed');
        }
    }
}

/**
 * Generate CSRF token
 */
function generateCSRF() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Get CSRF input field
 */
function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . generateCSRF() . '">';
}

/**
 * Upload file
 */
function uploadFile($file, $allowedTypes = ['xlsx', 'xls', 'csv']) {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Upload failed with error code: ' . $file['error']];
    }
    
    if ($file['size'] > MAX_FILE_SIZE) {
        return ['success' => false, 'message' => 'File size exceeds maximum allowed size'];
    }
    
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowedTypes)) {
        return ['success' => false, 'message' => 'File type not allowed'];
    }
    
    $filename = uniqid() . '_' . time() . '.' . $extension;
    $filepath = UPLOAD_PATH . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['success' => true, 'filename' => $filename, 'filepath' => $filepath];
    }
    
    return ['success' => false, 'message' => 'Failed to move uploaded file'];
}

/**
 * Delete file
 */
function deleteFile($filename, $directory = UPLOAD_PATH) {
    $filepath = $directory . $filename;
    if (file_exists($filepath)) {
        return unlink($filepath);
    }
    return false;
}

/**
 * Get setting value
 *
 * NOTE: this loads the WHOLE settings table on first call, so anything large
 * would be pulled into every page request. `company_banner` (a base64 image
 * data URL, see migration 0042) is therefore excluded here and read on demand
 * by getCompanyBanner() instead — never fetch it through this function.
 */
function getSetting($key, $default = null) {
    global $conn;

    static $settings = [];

    if (empty($settings)) {
        $stmt = $conn->query("SELECT setting_key, setting_value FROM settings WHERE setting_key <> 'company_banner'");
        while ($row = $stmt->fetch()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }

    return $settings[$key] ?? $default;
}

/**
 * The letterhead banner printed on outward-facing documents, as a base64 image
 * data URL — or null when none is uploaded (callers then fall back to the
 * company name/address/phone text lines).
 *
 * Fetched on its own rather than through getSetting() because it is big; only
 * the handful of pages that actually print a letterhead pay for it. The
 * try/catch keeps tenants that have not run migration 0042 working: a TEXT
 * column simply never held a banner, so null is the correct answer there too.
 */
function getCompanyBanner() {
    global $conn;

    static $banner = false; // false = not looked up yet, null = looked up, none set

    if ($banner === false) {
        $banner = null;
        try {
            $stmt = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'company_banner' LIMIT 1");
            $value = $stmt->fetchColumn();
            if (is_string($value) && $value !== '') {
                $banner = sanitizeImageDataUrl($value);
            }
        } catch (PDOException $e) {
            $banner = null;
        }
    }

    return $banner;
}

/**
 * Print the company letterhead banner on an outward-facing document.
 *
 * Deliberately renders NOTHING and returns false when no banner is uploaded, so
 * every caller reads:
 *
 *     <div class="its-own-letterhead">
 *         <?php if (!renderPrintBanner()): ?>
 *             ...the page's existing company name / address / phone markup...
 *         <?php endif; ?>
 *     </div>
 *
 * That keeps each document's existing letterhead byte-for-byte intact for
 * tenants with no banner, while a tenant that uploads one gets the artwork
 * INSTEAD of the text lines (the banner already carries the address and phone).
 *
 * @param  string $extraClass extra CSS class for context-specific sizing
 * @return bool   true if a banner was rendered
 */
function renderPrintBanner($extraClass = '') {
    $banner = getCompanyBanner();
    if ($banner === null) {
        return false;
    }

    $class = 'app-letterhead-banner' . ($extraClass !== '' ? ' ' . $extraClass : '');
    echo '<img src="' . htmlspecialchars($banner, ENT_QUOTES) . '"'
       . ' class="' . htmlspecialchars($class, ENT_QUOTES) . '"'
       . ' alt="' . htmlspecialchars(getSetting('company_name', ''), ENT_QUOTES) . '">';

    return true;
}

/**
 * The letterhead as a self-contained HTML string, for the print windows that
 * JavaScript builds from scratch (customer/supplier statements, POS receipt).
 *
 * Those popups are written with document.write() into a blank window, so they
 * never see assets/css/style.css — the markup here is paired with
 * getPrintLetterheadCss() and both have to be injected together.
 *
 * Unlike renderPrintBanner(), this ALWAYS returns a letterhead: the JS
 * documents had no company identity of any kind before, so the text lines are
 * the sensible fallback rather than nothing.
 *
 * @return string may be '' only when no branding has been configured at all
 */
function getPrintLetterheadHtml() {
    $banner = getCompanyBanner();

    if ($banner !== null) {
        return '<div class="app-lh"><img src="' . htmlspecialchars($banner, ENT_QUOTES) . '" alt=""></div>';
    }

    $name    = (string)getSetting('company_name', '');
    $address = (string)getSetting('company_address', '');
    $phone   = (string)getSetting('company_phone', '');

    if ($name === '' && $address === '' && $phone === '') {
        return '';
    }

    $html = '<div class="app-lh">';
    if ($name !== '') {
        $html .= '<div class="app-lh-name">' . htmlspecialchars($name) . '</div>';
    }
    if ($address !== '') {
        $html .= '<div class="app-lh-line">' . htmlspecialchars($address) . '</div>';
    }
    if ($phone !== '') {
        $html .= '<div class="app-lh-line">' . htmlspecialchars(__('phone')) . ': ' . htmlspecialchars($phone) . '</div>';
    }
    return $html . '</div>';
}

/**
 * CSS for getPrintLetterheadHtml(), for injection into a JS-built print window.
 *
 * max-width AND max-height with no explicit width/height, so the aspect ratio
 * is preserved whatever gets uploaded.
 *
 * @param string $maxHeight height cap — drop it for narrow thermal rolls
 */
function getPrintLetterheadCss($maxHeight = '55mm') {
    $maxHeight = preg_replace('/[^0-9a-z.]/i', '', (string)$maxHeight);

    return '.app-lh { text-align: center; border-bottom: 2px solid #333; padding-bottom: 10px; margin-bottom: 15px; }'
         . '.app-lh img { display: block; margin: 0 auto; max-width: 100%; max-height: ' . $maxHeight . ';'
         . ' -webkit-print-color-adjust: exact; print-color-adjust: exact; }'
         . '.app-lh-name { font-size: 18px; font-weight: bold; }'
         . '.app-lh-line { font-size: 11px; color: #555; margin-top: 2px; }';
}

/**
 * Validate a base64 image data URL before it is echoed into a src attribute or
 * written to the DB. Same guard as sanitizeSignatureValue(), with a ceiling
 * sized for a full-width letterhead rather than a signature cut-out.
 *
 * @return string|null the value if it is a well-formed PNG/JPEG data URL
 */
function sanitizeImageDataUrl($value, $maxBytes = 2097152) {
    if (!is_string($value)) {
        return null;
    }
    $value = trim($value);
    if ($value === '' || strlen($value) > $maxBytes) {
        return null;
    }
    if (!preg_match('#^data:image/(png|jpeg|jpg);base64,[A-Za-z0-9+/=]+$#', $value)) {
        return null;
    }
    return $value;
}

/**
 * Update setting value
 */
function updateSetting($key, $value) {
    global $conn;
    
    $stmt = $conn->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
    return $stmt->execute([$value, $key]);
}

/**
 * Generate random password
 */
function generatePassword($length = 8) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%';
    return substr(str_shuffle(str_repeat($chars, ceil($length / strlen($chars)))), 0, $length);
}

/**
 * Send JSON response
 */
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

/**
 * Pagination helper
 */
function paginate($totalRecords, $currentPage = 1, $recordsPerPage = RECORDS_PER_PAGE) {
    $totalPages = ceil($totalRecords / $recordsPerPage);
    $currentPage = max(1, min($currentPage, $totalPages));
    $offset = ($currentPage - 1) * $recordsPerPage;
    
    return [
        'total_records' => $totalRecords,
        'total_pages' => $totalPages,
        'current_page' => $currentPage,
        'records_per_page' => $recordsPerPage,
        'offset' => $offset,
        'has_prev' => $currentPage > 1,
        'has_next' => $currentPage < $totalPages,
        'prev_page' => $currentPage - 1,
        'next_page' => $currentPage + 1
    ];
}

/**
 * Get user's accessible cost centres
 */
function getUserCostCentres() {
    global $conn;

    // Admin can access all cost centres
    if (getUserData('role_name') == 'Admin') {
        $stmt = $conn->query("SELECT id, parent_id, code, name FROM cost_centres WHERE is_active = 1 ORDER BY name");
        return $stmt->fetchAll();
    }

    $userId = getUserId();
    $stmt = $conn->prepare("
        SELECT cc.id, cc.parent_id, cc.code, cc.name
        FROM cost_centres cc
        INNER JOIN user_cost_centres ucc ON cc.id = ucc.cost_centre_id
        WHERE ucc.user_id = ? AND cc.is_active = 1
        ORDER BY cc.name
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/**
 * Check if user can access a cost centre
 */
function canAccessCostCentre($costCentreId) {
    global $conn;

    // Admin can access all cost centres
    if (getUserData('role_name') == 'Admin') {
        return true;
    }

    $userId = getUserId();
    if (!$userId) {
        return false;
    }

    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM user_cost_centres WHERE user_id = ? AND cost_centre_id = ?");
    $stmt->execute([$userId, $costCentreId]);
    $result = $stmt->fetch();

    return $result['count'] > 0;
}

/**
 * Get user's accessible locations
 */
function getUserLocations() {
    global $conn;

    // Admin can access all locations
    if (getUserData('role_name') == 'Admin') {
        $stmt = $conn->query("SELECT id, parent_id, name FROM locations WHERE is_active = 1 ORDER BY name");
        return $stmt->fetchAll();
    }

    $userId = getUserId();
    $stmt = $conn->prepare("
        SELECT l.id, l.parent_id, l.name
        FROM locations l
        INNER JOIN user_locations ul ON l.id = ul.location_id
        WHERE ul.user_id = ? AND l.is_active = 1
        ORDER BY l.name
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/**
 * Get the IDs of the current user's accessible locations that have a given
 * location_type ('site', 'warehouse', 'others'). Exact-type match only — the
 * location hierarchy (parent_id) is intentionally NOT walked here.
 *
 * Respects the same access control as getUserLocations(): Admin sees all active
 * locations of the type; other users only their assigned locations.
 *
 * @param string $type One of 'site', 'warehouse', 'others'
 * @return int[] Array of location IDs (empty if none / invalid type)
 */
function getUserLocationTypeIds($type) {
    global $conn;

    $validTypes = ['site', 'warehouse', 'others'];
    if (!in_array($type, $validTypes, true)) {
        return [];
    }

    if (getUserData('role_name') == 'Admin') {
        $stmt = $conn->prepare("SELECT id FROM locations WHERE is_active = 1 AND location_type = ?");
        $stmt->execute([$type]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    $userId = getUserId();
    $stmt = $conn->prepare("
        SELECT l.id
        FROM locations l
        INNER JOIN user_locations ul ON l.id = ul.location_id
        WHERE ul.user_id = ? AND l.is_active = 1 AND l.location_type = ?
    ");
    $stmt->execute([$userId, $type]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Escape special characters for CSV
 */
function escapeCsv($value) {
    if (strpos($value, ',') !== false || strpos($value, '"') !== false || strpos($value, "\n") !== false) {
        return '"' . str_replace('"', '""', $value) . '"';
    }
    return $value;
}

/**
 * Calculate stock on-the-fly for a product at a location
 * Stock = Opening + Purchases + Adjustments(increase) + Transfer In
 *       - Sales - Adjustments(decrease) - Transfer Out
 * 
 * @param int $productId
 * @param int $locationId
 * @param string|null $asOfDate Optional date to calculate stock as of (default: today)
 * @return array ['quantity' => float, 'breakdown' => array]
 */
function getProductStockOnFly($productId, $locationId, $asOfDate = null) {
    global $conn;
    
    if ($asOfDate === null) {
        $asOfDate = date('Y-m-d');
    }
    
    // Opening stock
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(quantity), 0) 
        FROM opening_stocks
        WHERE product_id = ? AND location_id = ? AND stock_date <= ?
    ");
    $stmt->execute([$productId, $locationId, $asOfDate]);
    $opening = floatval($stmt->fetchColumn());
    
    // Purchases
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(pd.quantity), 0)
        FROM purchase_details pd
        INNER JOIN purchases p ON pd.purchase_id = p.id
        WHERE pd.product_id = ? AND p.location_id = ? AND p.purchase_date <= ?
    ");
    $stmt->execute([$productId, $locationId, $asOfDate]);
    $purchases = floatval($stmt->fetchColumn());

    // Imports
    // Imports write stock_batches exactly like purchases do, so they are real
    // stock. Omitting them understated every imported product here.
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(pid.quantity), 0)
        FROM purchase_import_details pid
        INNER JOIN purchase_imports pi ON pid.import_id = pi.id
        WHERE pid.product_id = ? AND pi.location_id = ? AND pi.import_date <= ?
    ");
    $stmt->execute([$productId, $locationId, $asOfDate]);
    $imports = floatval($stmt->fetchColumn());
    
    // Sales
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(sd.quantity), 0)
        FROM sale_details sd
        INNER JOIN sales s ON sd.sale_id = s.id
        WHERE sd.product_id = ? AND s.location_id = ? AND s.sale_date <= ?
    ");
    $stmt->execute([$productId, $locationId, $asOfDate]);
    $sales = floatval($stmt->fetchColumn());
    
    // Adjustment increases
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(sad.quantity), 0)
        FROM stock_adjustment_details sad
        INNER JOIN stock_adjustments sa ON sad.adjustment_id = sa.id
        WHERE sad.product_id = ? AND sa.location_id = ? AND sad.adjustment_type = 'increase' AND sa.adjustment_date <= ?
    ");
    $stmt->execute([$productId, $locationId, $asOfDate]);
    $adjIncrease = floatval($stmt->fetchColumn());
    
    // Adjustment decreases
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(sad.quantity), 0)
        FROM stock_adjustment_details sad
        INNER JOIN stock_adjustments sa ON sad.adjustment_id = sa.id
        WHERE sad.product_id = ? AND sa.location_id = ? AND sad.adjustment_type = 'decrease' AND sa.adjustment_date <= ?
    ");
    $stmt->execute([$productId, $locationId, $asOfDate]);
    $adjDecrease = floatval($stmt->fetchColumn());
    
    // Transfer in (received transfers TO this location)
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(td.received_quantity), 0)
        FROM stock_transfer_details td
        INNER JOIN stock_transfers t ON td.transfer_id = t.id
        WHERE td.product_id = ? AND t.to_location_id = ? AND t.status = 'received' AND DATE(t.received_at) <= ?
    ");
    $stmt->execute([$productId, $locationId, $asOfDate]);
    $transferIn = floatval($stmt->fetchColumn());
    
    // Transfer out (dispatched transfers FROM this location)
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(td.dispatched_quantity), 0)
        FROM stock_transfer_details td
        INNER JOIN stock_transfers t ON td.transfer_id = t.id
        WHERE td.product_id = ? AND t.from_location_id = ? AND t.status IN ('in_transit', 'received') AND DATE(t.dispatched_at) <= ?
    ");
    $stmt->execute([$productId, $locationId, $asOfDate]);
    $transferOut = floatval($stmt->fetchColumn());

    // Consumption voucher decreases
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(cvd.quantity), 0)
        FROM consumption_voucher_details cvd
        INNER JOIN consumption_vouchers cv ON cvd.voucher_id = cv.id
        WHERE cvd.product_id = ? AND cv.location_id = ? AND cv.voucher_date <= ?
    ");
    $stmt->execute([$productId, $locationId, $asOfDate]);
    $consumption = floatval($stmt->fetchColumn());

    $quantity = $opening + $purchases + $imports + $adjIncrease + $transferIn - $sales - $adjDecrease - $transferOut - $consumption;

    return [
        'quantity' => $quantity,
        'breakdown' => [
            'opening' => $opening,
            'purchases' => $purchases,
            'imports' => $imports,
            'sales' => $sales,
            'adj_increase' => $adjIncrease,
            'adj_decrease' => $adjDecrease,
            'transfer_in' => $transferIn,
            'transfer_out' => $transferOut,
            'consumption' => $consumption
        ]
    ];
}

/**
 * Get total stock for a product across all locations or specific location
 * 
 * @param int $productId
 * @param int|null $locationId Optional specific location
 * @return float
 */
function getProductTotalStock($productId, $locationId = null) {
    global $conn;
    
    if ($locationId !== null) {
        $result = getProductStockOnFly($productId, $locationId);
        return $result['quantity'];
    }
    
    // Get all locations and sum stock
    $stmt = $conn->query("SELECT id FROM locations WHERE is_active = 1");
    $locations = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $total = 0;
    foreach ($locations as $locId) {
        $result = getProductStockOnFly($productId, $locId);
        $total += $result['quantity'];
    }
    
    return $total;
}

/**
 * Get all products with stock at a location (for reports/lists)
 * Returns array of products with their calculated stock
 * 
 * @param int $locationId
 * @param string|null $asOfDate
 * @return array
 */
function getLocationStock($locationId, $asOfDate = null) {
    global $conn;
    
    if ($asOfDate === null) {
        $asOfDate = date('Y-m-d');
    }
    
    // Get all active products
    $stmt = $conn->query("SELECT id, name, unit FROM products WHERE is_active = 1 ORDER BY name");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $result = [];
    foreach ($products as $product) {
        $stock = getProductStockOnFly($product['id'], $locationId, $asOfDate);
        $result[] = [
            'product_id' => $product['id'],
            'product_name' => $product['name'],
            'unit' => $product['unit'],
            'quantity' => $stock['quantity'],
            'breakdown' => $stock['breakdown']
        ];
    }

    return $result;
}

/**
 * Fast bulk product-count-per-location (stock > 0) using a single aggregated query.
 * Mirrors the math of getProductStockOnFly(): opening + purchases + adj_inc + transfer_in
 *                                             - sales - adj_dec - transfer_out - consumption.
 * Returns [locationId => count]. Locations with zero stocked products are omitted.
 *
 * @param string|null $asOfDate YYYY-MM-DD; defaults to today
 * @return array<int,int>
 */
function getProductCountsPerLocation($asOfDate = null) {
    global $conn;

    if ($asOfDate === null) {
        $asOfDate = date('Y-m-d');
    }

    $sql = "
        SELECT location_id, COUNT(*) AS product_count
        FROM (
            SELECT location_id, product_id, SUM(qty) AS net_qty
            FROM (
                SELECT location_id, product_id, quantity AS qty
                FROM opening_stocks
                WHERE stock_date <= :d1

                UNION ALL
                SELECT p.location_id, pd.product_id, pd.quantity
                FROM purchase_details pd
                INNER JOIN purchases p ON pd.purchase_id = p.id
                WHERE p.purchase_date <= :d2

                UNION ALL
                SELECT s.location_id, sd.product_id, -sd.quantity
                FROM sale_details sd
                INNER JOIN sales s ON sd.sale_id = s.id
                WHERE s.sale_date <= :d3

                UNION ALL
                SELECT sa.location_id, sad.product_id, sad.quantity
                FROM stock_adjustment_details sad
                INNER JOIN stock_adjustments sa ON sad.adjustment_id = sa.id
                WHERE sad.adjustment_type = 'increase' AND sa.adjustment_date <= :d4

                UNION ALL
                SELECT sa.location_id, sad.product_id, -sad.quantity
                FROM stock_adjustment_details sad
                INNER JOIN stock_adjustments sa ON sad.adjustment_id = sa.id
                WHERE sad.adjustment_type = 'decrease' AND sa.adjustment_date <= :d5

                UNION ALL
                SELECT t.to_location_id, td.product_id, td.received_quantity
                FROM stock_transfer_details td
                INNER JOIN stock_transfers t ON td.transfer_id = t.id
                WHERE t.status = 'received' AND DATE(t.received_at) <= :d6

                UNION ALL
                SELECT t.from_location_id, td.product_id, -td.dispatched_quantity
                FROM stock_transfer_details td
                INNER JOIN stock_transfers t ON td.transfer_id = t.id
                WHERE t.status IN ('in_transit','received') AND DATE(t.dispatched_at) <= :d7

                UNION ALL
                SELECT cv.location_id, cvd.product_id, -cvd.quantity
                FROM consumption_voucher_details cvd
                INNER JOIN consumption_vouchers cv ON cvd.voucher_id = cv.id
                WHERE cv.voucher_date <= :d8
            ) AS movements
            WHERE product_id IN (SELECT id FROM products WHERE is_active = 1)
            GROUP BY location_id, product_id
            HAVING SUM(qty) > 0
        ) AS stocked
        GROUP BY location_id
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':d1' => $asOfDate, ':d2' => $asOfDate, ':d3' => $asOfDate, ':d4' => $asOfDate,
        ':d5' => $asOfDate, ':d6' => $asOfDate, ':d7' => $asOfDate, ':d8' => $asOfDate,
    ]);

    $counts = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $counts[(int)$row['location_id']] = (int)$row['product_count'];
    }
    return $counts;
}

/**
 * Shared UNION ALL of all stock-moving sources, parameterised by :d<n> dates.
 * Used by aggregate stock helpers to avoid repeating the same SQL.
 */
function _stockMovementsUnionSql() {
    return "
        SELECT location_id, product_id, quantity AS qty
        FROM opening_stocks WHERE stock_date <= :d1
        UNION ALL
        SELECT p.location_id, pd.product_id, pd.quantity
        FROM purchase_details pd INNER JOIN purchases p ON pd.purchase_id = p.id
        WHERE p.purchase_date <= :d2
        UNION ALL
        SELECT s.location_id, sd.product_id, -sd.quantity
        FROM sale_details sd INNER JOIN sales s ON sd.sale_id = s.id
        WHERE s.sale_date <= :d3
        UNION ALL
        SELECT sa.location_id, sad.product_id, sad.quantity
        FROM stock_adjustment_details sad INNER JOIN stock_adjustments sa ON sad.adjustment_id = sa.id
        WHERE sad.adjustment_type = 'increase' AND sa.adjustment_date <= :d4
        UNION ALL
        SELECT sa.location_id, sad.product_id, -sad.quantity
        FROM stock_adjustment_details sad INNER JOIN stock_adjustments sa ON sad.adjustment_id = sa.id
        WHERE sad.adjustment_type = 'decrease' AND sa.adjustment_date <= :d5
        UNION ALL
        SELECT t.to_location_id, td.product_id, td.received_quantity
        FROM stock_transfer_details td INNER JOIN stock_transfers t ON td.transfer_id = t.id
        WHERE t.status = 'received' AND DATE(t.received_at) <= :d6
        UNION ALL
        SELECT t.from_location_id, td.product_id, -td.dispatched_quantity
        FROM stock_transfer_details td INNER JOIN stock_transfers t ON td.transfer_id = t.id
        WHERE t.status IN ('in_transit','received') AND DATE(t.dispatched_at) <= :d7
        UNION ALL
        SELECT cv.location_id, cvd.product_id, -cvd.quantity
        FROM consumption_voucher_details cvd INNER JOIN consumption_vouchers cv ON cvd.voucher_id = cv.id
        WHERE cv.voucher_date <= :d8
        UNION ALL
        SELECT pi.location_id, pid.product_id, pid.quantity
        FROM purchase_import_details pid INNER JOIN purchase_imports pi ON pid.import_id = pi.id
        WHERE pi.import_date <= :d9
    ";
}

/**
 * Fast bulk "products with negative stock" across active products/locations.
 * Replaces CROSS JOIN + per-row getProductStockOnFly() loop.
 * Returns rows with product_name, location_name, unit, current_stock (formatted string).
 *
 * @param int $limit  Max rows to return (sorted by stock ascending — most negative first)
 * @return array
 */
function getNegativeStockItems($limit = 10) {
    global $conn;
    $asOfDate = date('Y-m-d');
    $sql = "
        SELECT p.name AS product_name, l.name AS location_name, p.unit, x.net_qty AS current_stock
        FROM (
            SELECT location_id, product_id, SUM(qty) AS net_qty
            FROM (" . _stockMovementsUnionSql() . ") m
            GROUP BY location_id, product_id
            HAVING SUM(qty) < 0
        ) x
        INNER JOIN products  p ON p.id = x.product_id  AND p.is_active = 1
        INNER JOIN locations l ON l.id = x.location_id AND l.is_active = 1
        ORDER BY x.net_qty ASC
        LIMIT " . (int)$limit;

    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':d1' => $asOfDate, ':d2' => $asOfDate, ':d3' => $asOfDate, ':d4' => $asOfDate,
        ':d5' => $asOfDate, ':d6' => $asOfDate, ':d7' => $asOfDate, ':d8' => $asOfDate,
        ':d9' => $asOfDate,
    ]);

    $items = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $items[] = [
            'product_name'  => $row['product_name'],
            'location_name' => $row['location_name'],
            'current_stock' => formatNumber((float)$row['current_stock'], 2),
            'unit'          => $row['unit'],
        ];
    }
    return $items;
}

/**
 * Fast bulk "products with low stock" (0 < stock < $threshold) across active products/locations.
 * Replaces CROSS JOIN + per-row getProductStockOnFly() loop.
 *
 * @param int   $limit     Max rows (sorted ascending)
 * @param float $threshold Exclusive upper bound for "low" (default 10 to match old behavior)
 * @return array
 */
function getLowStockItems($limit = 5, $threshold = 10) {
    global $conn;
    $asOfDate = date('Y-m-d');
    $sql = "
        SELECT p.name AS product_name, l.name AS location_name, p.unit, x.net_qty AS current_stock
        FROM (
            SELECT location_id, product_id, SUM(qty) AS net_qty
            FROM (" . _stockMovementsUnionSql() . ") m
            GROUP BY location_id, product_id
            HAVING SUM(qty) > 0 AND SUM(qty) < :threshold
        ) x
        INNER JOIN products  p ON p.id = x.product_id  AND p.is_active = 1
        INNER JOIN locations l ON l.id = x.location_id AND l.is_active = 1
        ORDER BY x.net_qty ASC
        LIMIT " . (int)$limit;

    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':d1' => $asOfDate, ':d2' => $asOfDate, ':d3' => $asOfDate, ':d4' => $asOfDate,
        ':d5' => $asOfDate, ':d6' => $asOfDate, ':d7' => $asOfDate, ':d8' => $asOfDate,
        ':d9' => $asOfDate,
        ':threshold' => $threshold,
    ]);

    $items = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $items[] = [
            'product_name'  => $row['product_name'],
            'location_name' => $row['location_name'],
            'current_stock' => formatNumber((float)$row['current_stock'], 2),
            'unit'          => $row['unit'],
        ];
    }
    return $items;
}

// ==================== HIERARCHY / TREE HELPERS ====================

/**
 * Build a nested tree structure from a flat array of items with parent_id.
 * Each item gets a 'children' array and a 'depth' integer.
 *
 * @param array $flatItems Array of assoc arrays, each must have 'id' and 'parent_id'
 * @return array Nested tree (only root-level items, children nested inside)
 */
function buildTree($flatItems, $parentId = null, $depth = 0) {
    $branch = [];
    foreach ($flatItems as $item) {
        $itemParent = isset($item['parent_id']) ? $item['parent_id'] : null;
        if ($itemParent == $parentId) {
            $item['depth'] = $depth;
            $item['children'] = buildTree($flatItems, $item['id'], $depth + 1);
            $branch[] = $item;
        }
    }
    return $branch;
}

/**
 * Flatten a nested tree back into a sorted flat array (depth-first).
 * Useful for rendering <option> lists with indentation.
 *
 * @param array $tree Nested tree from buildTree()
 * @return array Flat array sorted by tree order, each item has 'depth'
 */
function flattenTree($tree) {
    $result = [];
    foreach ($tree as $item) {
        $children = $item['children'];
        unset($item['children']);
        $result[] = $item;
        if (!empty($children)) {
            $result = array_merge($result, flattenTree($children));
        }
    }
    return $result;
}

/**
 * Render <option> tags with indentation for a hierarchical select dropdown.
 *
 * @param array $flatItems Flat array of items (must have 'id', 'parent_id', 'name')
 * @param int|null $selectedId Currently selected item ID
 * @param int|null $excludeId Item ID to exclude (and its descendants) — used to prevent circular refs
 * @param string $nameField The field to use as display name (default: 'name')
 * @param string|null $codeField Optional field to prepend as "CODE - NAME" (e.g., 'code' for cost centres)
 * @return string HTML <option> tags
 */
function renderTreeOptions($flatItems, $selectedId = null, $excludeId = null, $nameField = 'name', $codeField = null) {
    $tree = buildTree($flatItems);
    $sorted = flattenTree($tree);

    // If we need to exclude an item and its descendants, collect those IDs
    $excludeIds = [];
    if ($excludeId !== null) {
        $excludeIds = getDescendantIds($flatItems, $excludeId);
        $excludeIds[] = $excludeId;
    }

    $html = '';
    foreach ($sorted as $item) {
        if (in_array($item['id'], $excludeIds)) {
            continue;
        }
        $indent = str_repeat('— ', $item['depth']);
        $displayName = $item[$nameField];
        if ($codeField !== null && isset($item[$codeField])) {
            $displayName = $item[$codeField] . ' - ' . $displayName;
        }
        $selected = ($selectedId !== null && $item['id'] == $selectedId) ? ' selected' : '';
        $name = htmlspecialchars($indent . $displayName);
        $html .= '<option value="' . $item['id'] . '"' . $selected . '>' . $name . '</option>' . "\n";
    }
    return $html;
}

/**
 * Get all descendant IDs of a given item from a flat array.
 * Works purely in PHP — no extra DB queries.
 *
 * @param array $flatItems Flat array with 'id' and 'parent_id'
 * @param int $parentId The parent ID to find descendants of
 * @return array Array of descendant IDs (children, grandchildren, etc.)
 */
function getDescendantIds($flatItems, $parentId) {
    $descendants = [];
    foreach ($flatItems as $item) {
        $itemParent = isset($item['parent_id']) ? $item['parent_id'] : null;
        if ($itemParent == $parentId && $itemParent !== null) {
            $descendants[] = $item['id'];
            $descendants = array_merge($descendants, getDescendantIds($flatItems, $item['id']));
        }
    }
    return $descendants;
}

/**
 * Get all descendant location IDs for a given location (from DB).
 * Includes the given location ID itself in the result.
 *
 * @param int $locationId Parent location ID
 * @param PDO $conn Database connection
 * @return array Array of location IDs (parent + all descendants)
 */
function getDescendantLocationIds($locationId, $conn) {
    $stmt = $conn->query("SELECT id, parent_id FROM locations WHERE is_active = 1");
    $all = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $descendants = getDescendantIds($all, $locationId);
    array_unshift($descendants, (int)$locationId);
    return $descendants;
}

/**
 * Get all descendant cost centre IDs for a given cost centre (from DB).
 * Includes the given cost centre ID itself in the result.
 *
 * @param int $costCentreId Parent cost centre ID
 * @param PDO $conn Database connection
 * @return array Array of cost centre IDs (parent + all descendants)
 */
function getDescendantCostCentreIds($costCentreId, $conn) {
    $stmt = $conn->query("SELECT id, parent_id FROM cost_centres WHERE is_active = 1");
    $all = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $descendants = getDescendantIds($all, $costCentreId);
    array_unshift($descendants, (int)$costCentreId);
    return $descendants;
}

/**
 * Weighted-average cost (rate per unit) for a product, as used for stock
 * valuation in the stock report. Computed from stock_batches as
 * SUM(quantity_in * rate_per_unit) / SUM(quantity_in), filtered by product,
 * location and date. Used to auto-fill the (editable) BOM line rate.
 *
 * @param int      $productId  Product ID
 * @param int|null $locationId Location ID, or null/0 for all locations
 * @param string   $date       As-of date (Y-m-d); defaults to today
 * @param PDO      $conn       Database connection
 * @return float   Average cost (0 when no inbound batches exist)
 */
function getProductAvgCost($productId, $locationId, $date, $conn) {
    $productId = (int)$productId;
    if ($productId <= 0) {
        return 0.0;
    }
    if (empty($date)) {
        $date = date('Y-m-d');
    }

    $sql = "
        SELECT COALESCE(
            SUM(sb.quantity_in * sb.rate_per_unit) / NULLIF(SUM(sb.quantity_in), 0),
            0
        ) AS avg_rate
        FROM stock_batches sb
        WHERE sb.product_id = ?
          AND sb.batch_date <= ?";
    $params = [$productId, $date];

    if (!empty($locationId)) {
        $sql .= " AND sb.location_id = ?";
        $params[] = (int)$locationId;
    }

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return (float)$stmt->fetchColumn();
}

/**
 * Per-product BOM balances for a given Bill of Materials. For each product on
 * the BOM, returns the BOM quantity, how much has already been transferred
 * against this BOM (summed across ALL requisition vouchers linked to it), and
 * the remaining balance. Used by the requisition create/approve screens.
 *
 * @param int $bomId Bill of Materials ID
 * @param PDO $conn  Database connection
 * @return array Map of product_id => ['bom_qty'=>float, 'transferred'=>float, 'balance'=>float]
 */
function getBomProductBalances($bomId, $conn) {
    $bomId = (int)$bomId;
    $balances = [];
    if ($bomId <= 0) {
        return $balances;
    }

    // BOM quantity per product (a product could appear on more than one line).
    // While a revision is open this is the last APPROVED quantity (0053).
    foreach (getBomApprovedQuantities($bomId, $conn) as $pid => $bomQty) {
        $balances[$pid] = [
            'bom_qty'     => $bomQty,
            'transferred' => 0.0,
            'balance'     => $bomQty,
        ];
    }

    if (empty($balances)) {
        return $balances;
    }

    // Already-transferred quantity per product across every RV linked to this BOM.
    $transStmt = $conn->prepare("
        SELECT rvd.product_id, SUM(rvd.transferred_quantity) AS transferred
        FROM requisition_voucher_details rvd
        INNER JOIN requisition_vouchers rv ON rvd.requisition_id = rv.id
        WHERE rv.bom_id = ?
        GROUP BY rvd.product_id
    ");
    $transStmt->execute([$bomId]);
    foreach ($transStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pid = (int)$row['product_id'];
        if (!isset($balances[$pid])) {
            continue; // product transferred against BOM but no longer on the BOM
        }
        $transferred = (float)$row['transferred'];
        $balances[$pid]['transferred'] = $transferred;
        $balances[$pid]['balance'] = $balances[$pid]['bom_qty'] - $transferred;
    }

    return $balances;
}

/**
 * Whether boms keeps an approved snapshot during a revision (migration 0053).
 */
function bomSnapshotAvailable($conn) {
    static $has = null;
    if ($has === null) {
        try {
            $has = (bool)$conn->query("
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boms' AND COLUMN_NAME = 'approved_snapshot'
            ")->fetchColumn();
        } catch (Throwable $e) {
            $has = false;
        }
    }
    return $has;
}

/**
 * Last approved quantities kept while a revision is open.
 * Same JSON shape as the BOQ snapshot: {product_id: quantity}.
 *
 * @param string|null $json boms.approved_snapshot
 * @return array product_id => quantity, empty when no revision is open
 */
function bomSnapshotQuantities($json) {
    return boqSnapshotQuantities($json);
}

/**
 * The quantity per product a BOM stands for: the last APPROVED quantities while
 * a revision is open (boms.approved_snapshot), otherwise bom_details summed per
 * product. Products keep the order of their first BOM line.
 *
 * @return array product_id => quantity
 */
function getBomApprovedQuantities($bomId, $conn) {
    $bomId = (int)$bomId;
    if ($bomId <= 0) {
        return [];
    }
    if (bomSnapshotAvailable($conn)) {
        $snapStmt = $conn->prepare("SELECT approved_snapshot FROM boms WHERE id = ?");
        $snapStmt->execute([$bomId]);
        $approved = bomSnapshotQuantities($snapStmt->fetchColumn());
        if (!empty($approved)) {
            return $approved;
        }
    }
    $qtyStmt = $conn->prepare("
        SELECT product_id, SUM(total_quantity) AS bom_qty
        FROM bom_details
        WHERE bom_id = ?
        GROUP BY product_id
        ORDER BY MIN(id)
    ");
    $qtyStmt->execute([$bomId]);
    $out = [];
    foreach ($qtyStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int)$row['product_id']] = (float)$row['bom_qty'];
    }
    return $out;
}

/**
 * Whether the BOM issue summary applies: an approved BOM, or one under revision
 * that still holds its approved snapshot. A pre-0049 row (no status) always.
 */
function bomShowsIssueSummary($bom) {
    $status = $bom['status'] ?? null;
    if ($status === null || $status === 'approved') {
        return true;
    }
    return !empty($bom['approved_snapshot']);
}

/**
 * BOM issue summary: per product, the approved BOM quantity, the quantity
 * issued (dispatched on transfers) from requisitions linked to the BOM, and the
 * balance. Issued is requisition_voucher_details.transferred_quantity, which a
 * transfer reversal reduces, so it is the net quantity that left.
 *
 * Products issued against the BOM but not on it are included with
 * on_bom = false and bom_qty 0, after the BOM's own products.
 *
 * @return array product_id => ['name','unit','bom_qty','issued','balance',
 *                               'on_bom','rvs' => [['id','number','qty'], ...]]
 */
function getBomIssueSummary($bomId, $conn) {
    $bomId = (int)$bomId;
    $out = [];
    if ($bomId <= 0) {
        return $out;
    }

    foreach (getBomApprovedQuantities($bomId, $conn) as $pid => $qty) {
        $out[$pid] = ['name' => '', 'unit' => '', 'bom_qty' => $qty, 'issued' => 0.0, 'balance' => $qty, 'on_bom' => true, 'rvs' => []];
    }

    $issStmt = $conn->prepare("
        SELECT rvd.product_id, rv.id AS rv_id, rv.voucher_number, SUM(rvd.transferred_quantity) AS issued
        FROM requisition_voucher_details rvd
        INNER JOIN requisition_vouchers rv ON rvd.requisition_id = rv.id
        WHERE rv.bom_id = ?
        GROUP BY rvd.product_id, rv.id, rv.voucher_number
        HAVING SUM(rvd.transferred_quantity) > 0
        ORDER BY rv.id
    ");
    $issStmt->execute([$bomId]);
    foreach ($issStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pid = (int)$row['product_id'];
        if (!isset($out[$pid])) {
            $out[$pid] = ['name' => '', 'unit' => '', 'bom_qty' => 0.0, 'issued' => 0.0, 'balance' => 0.0, 'on_bom' => false, 'rvs' => []];
        }
        $qty = (float)$row['issued'];
        $out[$pid]['issued'] += $qty;
        $out[$pid]['balance'] = $out[$pid]['bom_qty'] - $out[$pid]['issued'];
        $out[$pid]['rvs'][] = ['id' => (int)$row['rv_id'], 'number' => $row['voucher_number'], 'qty' => $qty];
    }

    if (!empty($out)) {
        $ids = implode(',', array_map('intval', array_keys($out)));
        foreach ($conn->query("SELECT id, name, unit FROM products WHERE id IN ($ids)")->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $out[(int)$p['id']]['name'] = $p['name'];
            $out[(int)$p['id']]['unit'] = $p['unit'];
        }
    }
    return $out;
}

/**
 * Issue progress for a page of BOMs, in 2 queries: how many of each BOM's
 * products are fully issued, and whether any product was issued beyond its BOM
 * quantity. Only BOMs for which bomShowsIssueSummary() holds are included.
 * Uses the same quantities and tolerance as getBomIssueSummary().
 *
 * @param array $boms rows from boms (need id, status, approved_snapshot)
 * @return array bom_id => ['done' => int, 'total' => int, 'over' => bool]
 */
function getBomIssueProgress(array $boms, $conn) {
    $qty = [];
    $liveIds = [];
    foreach ($boms as $b) {
        if (!bomShowsIssueSummary($b)) continue;
        $bid = (int)$b['id'];
        $snap = bomSnapshotQuantities($b['approved_snapshot'] ?? null);
        if (!empty($snap)) {
            $qty[$bid] = $snap;
        } else {
            $qty[$bid] = [];
            $liveIds[] = $bid;
        }
    }
    if (empty($qty)) {
        return [];
    }

    if (!empty($liveIds)) {
        $in = implode(',', array_map('intval', $liveIds));
        foreach ($conn->query("SELECT bom_id, product_id, SUM(total_quantity) AS q FROM bom_details WHERE bom_id IN ($in) GROUP BY bom_id, product_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $qty[(int)$r['bom_id']][(int)$r['product_id']] = (float)$r['q'];
        }
    }

    $issued = [];
    $in = implode(',', array_map('intval', array_keys($qty)));
    foreach ($conn->query("
        SELECT rv.bom_id, rvd.product_id, SUM(rvd.transferred_quantity) AS q
        FROM requisition_voucher_details rvd
        INNER JOIN requisition_vouchers rv ON rvd.requisition_id = rv.id
        WHERE rv.bom_id IN ($in)
        GROUP BY rv.bom_id, rvd.product_id
    ")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $issued[(int)$r['bom_id']][(int)$r['product_id']] = (float)$r['q'];
    }

    $out = [];
    foreach ($qty as $bid => $products) {
        $done = 0;
        $over = false;
        foreach ($products as $pid => $q) {
            $iss = $issued[$bid][$pid] ?? 0.0;
            if ($iss >= $q - 0.0005) $done++;
            if ($iss > $q + 0.0005) $over = true;
        }
        // Issued against the BOM but not on it at all.
        foreach ($issued[$bid] ?? [] as $pid => $iss) {
            if (!isset($products[$pid]) && $iss > 0.0005) $over = true;
        }
        $out[$bid] = ['done' => $done, 'total' => count($products), 'over' => $over];
    }
    return $out;
}

/**
 * Whether boms has the approval workflow (migration 0049). Every BOM status
 * read and write is guarded on this, so a tenant that has not run the
 * migration keeps the old always-editable behaviour.
 */
function bomApprovalAvailable($conn) {
    static $has = null;
    if ($has === null) {
        try {
            $has = (bool)$conn->query("
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boms' AND COLUMN_NAME = 'status'
            ")->fetchColumn();
        } catch (Throwable $e) {
            $has = false;
        }
    }
    return $has;
}

/**
 * Whether boms can be linked to a BOQ (migration 0049).
 */
function bomBoqLinkAvailable($conn) {
    static $has = null;
    if ($has === null) {
        try {
            $has = (bool)$conn->query("
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boms' AND COLUMN_NAME = 'boq_id'
            ")->fetchColumn();
        } catch (Throwable $e) {
            $has = false;
        }
    }
    return $has;
}

/*
 * BOM lifecycle gates. The form, the view and the list all call these, so they
 * can never disagree about who sees a pencil or a button. A row without a
 * `status` key comes from a pre-0049 tenant and keeps the old behaviour.
 *
 *   draft            edit, delete, send for approval
 *   pending_approval approve / reject, and EDIT by an approver (bom.approve):
 *                    the approver corrects quantities on the ordinary form,
 *                    the BOM stays pending, and each such save is logged as
 *                    'edit_by_approver' (getBomApproverChangeLog())
 *   approved         revise (back to draft, needs approving again)
 *   rejected         delete*, revise
 *
 * * Not while approved_snapshot is set (0053): that BOM has an approved
 *   version in force, and deleting it would remove that too.
 */
function bomCanEdit($bom) {
    $status = $bom['status'] ?? null;
    if ($status === null || $status === 'draft') return hasPermission('bom', 'edit');
    if ($status === 'pending_approval') return hasPermission('bom', 'approve');
    return false;
}

/**
 * Whether saving this BOM counts as an approver's edit (logged and shown in
 * the BOM's change history) rather than the creator's ordinary draft edit.
 */
function bomIsApproverEdit($bom) {
    return ($bom['status'] ?? null) === 'pending_approval';
}

/**
 * Changes approvers made to a pending BOM, newest first. Each audit row
 * carries a ready-to-render 'changes' list with product names resolved when
 * it was written. Same shape as getRequisitionChangeLog().
 *
 * @return array List of ['created_at', 'user_name', 'changes' => [...]]
 */
function getBomApproverChangeLog($conn, $bomId) {
    $bomId = (int)$bomId;
    $out = [];
    if ($bomId <= 0) {
        return $out;
    }
    try {
        $stmt = $conn->prepare("
            SELECT a.created_at, a.new_values, u.name AS user_name
            FROM audit_log a
            LEFT JOIN users u ON a.user_id = u.id
            WHERE a.module = 'bom'
              AND a.action = 'edit_by_approver'
              AND a.record_id = ?
            ORDER BY a.id DESC
        ");
        $stmt->execute([$bomId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $decoded = json_decode((string)$row['new_values'], true);
            $changes = (is_array($decoded) && !empty($decoded['changes']) && is_array($decoded['changes']))
                ? $decoded['changes'] : [];
            if (empty($changes)) continue;
            $out[] = ['created_at' => $row['created_at'], 'user_name' => $row['user_name'], 'changes' => $changes];
        }
    } catch (Throwable $e) {
        return [];
    }
    return $out;
}

/**
 * Per-product quantity + rate of a BOM's current lines, with product names,
 * for building an approver change list. Rate is the quantity-weighted rate when
 * a product sits on several lines.
 *
 * @return array product_id => ['name', 'qty', 'rate']
 */
function bomProductTotals($conn, $bomId) {
    $stmt = $conn->prepare("
        SELECT bd.product_id, p.name, SUM(bd.total_quantity) AS qty,
               SUM(bd.total_quantity * bd.rate) AS value
        FROM bom_details bd
        LEFT JOIN products p ON p.id = bd.product_id
        WHERE bd.bom_id = ?
        GROUP BY bd.product_id, p.name
        ORDER BY MIN(bd.id)
    ");
    $stmt->execute([(int)$bomId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $qty = (float)$r['qty'];
        $out[(int)$r['product_id']] = [
            'name' => $r['name'] !== null ? $r['name'] : ('#' . $r['product_id']),
            'qty'  => $qty,
            'rate' => $qty > 0 ? (float)$r['value'] / $qty : 0.0,
        ];
    }
    return $out;
}

/**
 * Compare two bomProductTotals() maps and list what changed:
 * item_added / item_removed / item_qty / item_rate, values pre-formatted.
 */
function bomDiffProductTotals(array $before, array $after) {
    $fq = function ($n) { return rtrim(rtrim(number_format($n, 3, '.', ','), '0'), '.'); };
    $fr = function ($n) { return number_format($n, 2, '.', ','); };
    $changes = [];
    foreach ($after as $pid => $a) {
        if (!isset($before[$pid])) {
            $changes[] = ['type' => 'item_added', 'label' => $a['name'], 'to' => $fq($a['qty'])];
            continue;
        }
        $b = $before[$pid];
        if (abs($b['qty'] - $a['qty']) > 0.0005) {
            $changes[] = ['type' => 'item_qty', 'label' => $a['name'], 'from' => $fq($b['qty']), 'to' => $fq($a['qty'])];
        }
        if (abs($b['rate'] - $a['rate']) > 0.005) {
            $changes[] = ['type' => 'item_rate', 'label' => $a['name'], 'from' => $fr($b['rate']), 'to' => $fr($a['rate'])];
        }
    }
    foreach ($before as $pid => $b) {
        if (!isset($after[$pid])) {
            $changes[] = ['type' => 'item_removed', 'label' => $b['name'], 'from' => $fq($b['qty'])];
        }
    }
    return $changes;
}

function bomCanDelete($bom) {
    if (!hasPermission('bom', 'delete')) return false;
    $status = $bom['status'] ?? null;
    if ($status === null) return true;
    return ($status === 'draft' || $status === 'rejected') && empty($bom['approved_snapshot']);
}

function bomCanSendForApproval($bom) {
    if (($bom['status'] ?? null) !== 'draft') return false;
    return hasPermission('bom', 'create') || hasPermission('bom', 'edit');
}

function bomCanRevise($bom) {
    $status = $bom['status'] ?? null;
    return ($status === 'approved' || $status === 'rejected') && hasPermission('bom', 'edit');
}

/**
 * Why a requisition may not be linked to $newBomId, or null when it may.
 *
 * Only an APPROVED BOM can be newly linked. A link the requisition ALREADY has
 * is always kept, whatever that BOM's status now is: a BOM revised back to
 * draft must not silently drop off the requisitions that were raised against it.
 *
 * @param PDO      $conn
 * @param int|null $newBomId     BOM being saved (null/0 = no BOM)
 * @param int|null $currentBomId BOM the requisition is linked to today
 */
function bomLinkError($conn, $newBomId, $currentBomId = null) {
    $newBomId = (int)$newBomId;
    if ($newBomId <= 0 || $newBomId === (int)$currentBomId) {
        return null;
    }
    if (!bomApprovalAvailable($conn)) {
        return null;
    }
    $stmt = $conn->prepare("SELECT status FROM boms WHERE id = ?");
    $stmt->execute([$newBomId]);
    $status = $stmt->fetchColumn();
    if ($status === false) {
        return __('bom_not_found');
    }
    return $status === 'approved' ? null : __('bom_link_not_approved');
}

/**
 * Whether boqs has the approval workflow (migration 0051). Every BOQ status
 * read and write is guarded on this, so a tenant that has not run the
 * migration keeps the old always-editable behaviour.
 */
function boqApprovalAvailable($conn) {
    static $has = null;
    if ($has === null) {
        try {
            $has = (bool)$conn->query("
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boqs' AND COLUMN_NAME = 'status'
            ")->fetchColumn();
        } catch (Throwable $e) {
            $has = false;
        }
    }
    return $has;
}

/*
 * BOQ lifecycle gates. The form, the view and the list all call these, so they
 * can never disagree about who sees a pencil or a button. A row without a
 * `status` key comes from a pre-0051 tenant and keeps the old behaviour.
 *
 *   draft            edit, delete*, send for approval
 *   pending_approval nothing but approve / reject (boq.approve)
 *   approved         revise (back to draft, needs approving again)
 *   rejected         delete*, revise
 *
 * * Not while approved_snapshot is set: that BOQ has an approved version in
 *   force, and deleting it would remove that too.
 */
function boqCanEdit($boq) {
    if (!hasPermission('boq', 'edit')) return false;
    $status = $boq['status'] ?? null;
    return $status === null || $status === 'draft';
}

function boqCanDelete($boq) {
    if (!hasPermission('boq', 'delete')) return false;
    $status = $boq['status'] ?? null;
    if ($status === null) return true;
    return ($status === 'draft' || $status === 'rejected') && empty($boq['approved_snapshot']);
}

function boqCanSendForApproval($boq) {
    if (($boq['status'] ?? null) !== 'draft') return false;
    return hasPermission('boq', 'create') || hasPermission('boq', 'edit');
}

function boqCanRevise($boq) {
    $status = $boq['status'] ?? null;
    if ($status !== 'approved' && $status !== 'rejected') return false;
    return hasPermission('boq', 'edit') || hasPermission('boq', 'approve');
}

/**
 * Last approved quantities kept while a revision is open.
 *
 * @param string|null $json boqs.approved_snapshot
 * @return array product_id => quantity, empty when no revision is open
 */
function boqSnapshotQuantities($json) {
    if (!is_string($json) || $json === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }
    $out = [];
    foreach ($decoded as $pid => $qty) {
        $out[(int)$pid] = (float)$qty;
    }
    return $out;
}

/**
 * Why a BOM may not be linked to $newBoqId, or null when it may.
 *
 * Only an APPROVED BOQ can be newly linked. A link the BOM ALREADY has is
 * always kept, whatever that BOQ's status now is.
 *
 * @param PDO      $conn
 * @param int|null $newBoqId     BOQ being saved (null/0 = no BOQ)
 * @param int|null $currentBoqId BOQ the BOM is linked to today
 */
function boqLinkError($conn, $newBoqId, $currentBoqId = null) {
    $newBoqId = (int)$newBoqId;
    if ($newBoqId <= 0 || $newBoqId === (int)$currentBoqId) {
        return null;
    }
    if (!boqApprovalAvailable($conn)) {
        return null;
    }
    $stmt = $conn->prepare("SELECT status FROM boqs WHERE id = ?");
    $stmt->execute([$newBoqId]);
    $status = $stmt->fetchColumn();
    if ($status === false) {
        return __('boq_not_found');
    }
    return $status === 'approved' ? null : __('boq_link_not_approved');
}

/**
 * Per-product BOQ balances as seen from one BOM.
 *
 * While a revision of an approved BOQ is open, boq_qty comes from the last
 * approved quantities (boqs.approved_snapshot), so unapproved figures never
 * reach a BOM balance.
 *
 *   boq_qty  = quantity of that product on the BOQ (summed over its lines)
 *   on_other = quantity on OTHER BOMs raised against this BOQ that are
 *              pending approval or approved. Drafts and rejected BOMs claim
 *              nothing, the same rule budgets use against a requisition.
 *   balance  = boq_qty - on_other
 *   in_boq   = whether the product is on the BOQ at all
 *
 * Products that appear only on other BOMs are included with boq_qty 0.
 *
 * @param int $boqId        Bill of Quantities ID
 * @param PDO $conn
 * @param int $excludeBomId The BOM being viewed / edited (0 for a new one)
 * @return array product_id => ['boq_qty','on_other','balance','in_boq']
 */
function getBoqProductBalances($boqId, $conn, $excludeBomId = 0) {
    $boqId = (int)$boqId;
    $out = [];
    if ($boqId <= 0) {
        return $out;
    }

    $qtyStmt = $conn->prepare("
        SELECT product_id, SUM(total_quantity) AS boq_qty
        FROM boq_details
        WHERE boq_id = ?
        GROUP BY product_id
    ");
    $qtyStmt->execute([$boqId]);
    $boqQuantities = [];
    foreach ($qtyStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $boqQuantities[(int)$row['product_id']] = (float)$row['boq_qty'];
    }

    if (boqApprovalAvailable($conn)) {
        $snapStmt = $conn->prepare("SELECT approved_snapshot FROM boqs WHERE id = ?");
        $snapStmt->execute([$boqId]);
        $approved = boqSnapshotQuantities($snapStmt->fetchColumn());
        if (!empty($approved)) {
            $boqQuantities = $approved;
        }
    }

    foreach ($boqQuantities as $pid => $qty) {
        $out[$pid] = [
            'boq_qty'  => $qty,
            'on_other' => 0.0,
            'balance'  => $qty,
            'in_boq'   => true,
        ];
    }

    if (!bomBoqLinkAvailable($conn) || !bomApprovalAvailable($conn)) {
        return $out;
    }

    $usedStmt = $conn->prepare("
        SELECT bd.product_id, SUM(bd.total_quantity) AS used
        FROM bom_details bd
        INNER JOIN boms b ON bd.bom_id = b.id
        WHERE b.boq_id = ?
          AND b.id <> ?
          AND b.status IN ('pending_approval', 'approved')
        GROUP BY bd.product_id
    ");
    $usedStmt->execute([$boqId, (int)$excludeBomId]);
    foreach ($usedStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pid = (int)$row['product_id'];
        if (!isset($out[$pid])) {
            $out[$pid] = ['boq_qty' => 0.0, 'on_other' => 0.0, 'balance' => 0.0, 'in_boq' => false];
        }
        $out[$pid]['on_other'] = (float)$row['used'];
        $out[$pid]['balance'] = $out[$pid]['boq_qty'] - $out[$pid]['on_other'];
    }

    return $out;
}

/**
 * Products of one BOM that go beyond the BOQ balance left for them, i.e. the
 * products that need a written reason. A product NOT on the BOQ at all is
 * flagged "Not in BOQ" on screen but needs no reason (user-confirmed).
 *
 * @param array $qtyByProduct product_id => this BOM's total quantity
 * @param array $balances     from getBoqProductBalances()
 * @return array product_id => ['qty','balance'] for the over products only
 */
function bomBoqExcessProducts(array $qtyByProduct, array $balances) {
    $over = [];
    foreach ($qtyByProduct as $pid => $qty) {
        $b = $balances[(int)$pid] ?? null;
        if ($b === null || empty($b['in_boq'])) {
            continue;
        }
        if ((float)$qty > $b['balance'] + 0.0005) {
            $over[(int)$pid] = ['qty' => (float)$qty, 'balance' => $b['balance']];
        }
    }
    return $over;
}

/**
 * Quantity already returned (via reverse transfers) per product for a given
 * source transfer. A reverse transfer is a stock_transfers row whose
 * reverse_of_transfer_id points at the original transfer; its detail lines'
 * dispatched_quantity is the amount being returned for that product. Cancelled
 * reverses are excluded (their stock was never moved out / was restored).
 *
 * The transfer form forbids the same product on more than one line, so matching
 * by product_id uniquely identifies the original line. Guarded with a try/catch
 * so tenants that have not yet run migration 0039 (no reverse_of_transfer_id
 * column) simply get an empty map rather than an error.
 *
 * @param int $transferId  Original (source) transfer ID
 * @param PDO $conn        Database connection
 * @return array Map of product_id => already-returned quantity (float)
 */
function getTransferReturnedQty($transferId, $conn) {
    $transferId = (int)$transferId;
    $out = [];
    if ($transferId <= 0) {
        return $out;
    }
    try {
        $stmt = $conn->prepare("
            SELECT rd.product_id, SUM(rd.dispatched_quantity) AS returned
            FROM stock_transfers r
            INNER JOIN stock_transfer_details rd ON rd.transfer_id = r.id
            WHERE r.reverse_of_transfer_id = ? AND r.status <> 'cancelled'
            GROUP BY rd.product_id
        ");
        $stmt->execute([$transferId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int)$row['product_id']] = (float)$row['returned'];
        }
    } catch (PDOException $e) {
        return [];
    }
    return $out;
}

/**
 * Quantity already allocated (budgeted) per product across a requisition's
 * other ACTIVE budgets (status pending_approval or approved). Used to compute
 * the remaining-to-budget per product so the same quantity isn't budgeted twice
 * across several budgets. Draft budgets are intentionally excluded (they may be
 * abandoned).
 *
 * @param int $requisitionId  Requisition voucher ID
 * @param PDO $conn           Database connection
 * @param int $excludeBudgetId Budget to exclude (the one being edited), 0 = none
 * @return array Map of product_id => already-budgeted quantity (float)
 */
function getRequisitionBudgetedQty($requisitionId, $conn, $excludeBudgetId = 0) {
    $requisitionId = (int)$requisitionId;
    $excludeBudgetId = (int)$excludeBudgetId;
    $out = [];
    if ($requisitionId <= 0) {
        return $out;
    }
    $sql = "
        SELECT bd.product_id, SUM(bd.quantity) AS qty
        FROM budget_details bd
        INNER JOIN budgets b ON bd.budget_id = b.id
        WHERE b.requisition_id = ?
          AND b.status IN ('pending_approval','approved')
          AND b.id <> ?
        GROUP BY bd.product_id
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$requisitionId, $excludeBudgetId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int)$row['product_id']] = (float)$row['qty'];
    }
    return $out;
}

/**
 * =====================================================================
 * POST-APPROVAL EDITING OF A REQUISITION VOUCHER
 * =====================================================================
 * An APPROVED requisition stays editable until procurement has started
 * against it — i.e. until a budget is raised from it. These helpers are the
 * SINGLE place that decides that, so the form, the view page and the list can
 * never disagree about who is shown a pencil (a list offering an edit the form
 * then refuses with a 302 was a real bug in budget_list.php).
 *
 * The rule: status must be 'approved', the user must hold
 * requisition_vouchers.edit, and NOTHING may yet be linked to the requisition.
 * A budget of ANY status blocks — including draft, because budget_details rows
 * hold requisition_detail_id references to the very lines an edit could delete.
 * Purchases and imports carrying requisition_id block too: they can be raised
 * straight off a requisition without a budget (purchases.php?from_requisition),
 * so leaving them out would be a hole even though no such row exists today.
 */

/**
 * Documents already raised against one or more requisitions, which lock those
 * requisitions from post-approval editing.
 *
 * Batched so a list page can resolve a whole screen of rows in three queries
 * instead of three per row. Each lookup is guarded: a tenant that has not run
 * the budget / import migrations simply contributes no blockers from that
 * table rather than breaking the page.
 *
 * @param array $requisitionIds Requisition voucher IDs
 * @param PDO   $conn           Database connection
 * @return array Map of requisition_id => list of ['type' => string, 'label' => string]
 */
function requisitionProcurementBlockers(array $requisitionIds, $conn) {
    $ids = array_values(array_unique(array_filter(array_map('intval', $requisitionIds))));
    $out = [];
    if (empty($ids)) {
        return $out;
    }
    $in = implode(',', $ids);

    // [table, number column, blocker type]. Each is optional on older schemas.
    $sources = [
        ['budgets',          'budget_number',   'budget'],
        ['purchases',        'purchase_number', 'purchase'],
        ['purchase_imports', 'import_number',   'import'],
    ];

    foreach ($sources as $source) {
        list($table, $numberCol, $type) = $source;
        try {
            $stmt = $conn->query("
                SELECT requisition_id, `$numberCol` AS label
                FROM `$table`
                WHERE requisition_id IN ($in)
                ORDER BY id
            ");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $rid = (int)$row['requisition_id'];
                if ($rid <= 0) continue;
                $out[$rid][] = ['type' => $type, 'label' => (string)$row['label']];
            }
        } catch (Throwable $e) {
            // Table or column absent on this tenant — contributes no blockers.
        }
    }

    return $out;
}

/**
 * Blockers for a single requisition. Empty array = nothing raised against it.
 *
 * @param int $requisitionId Requisition voucher ID
 * @param PDO $conn          Database connection
 * @return array List of ['type' => string, 'label' => string]
 */
function requisitionApprovedEditBlockers($requisitionId, $conn) {
    $all = requisitionProcurementBlockers([$requisitionId], $conn);
    $key = (int)$requisitionId;
    return isset($all[$key]) ? $all[$key] : [];
}

/**
 * Whether the current user may edit this requisition.
 *
 * Draft behaves exactly as it always has (edit permission, no other condition).
 * Approved additionally requires that nothing has been raised against it.
 * Every other status — pending_approval, rejected, closed — is unchanged: not
 * editable, by anyone.
 *
 * @param array      $requisition Requisition row (needs id + status)
 * @param PDO        $conn        Database connection
 * @param array|null $blockers    Pre-fetched blockers, to avoid a re-query on list pages
 * @return bool
 */
function requisitionCanEdit($requisition, $conn, $blockers = null) {
    if (empty($requisition) || !is_array($requisition)) {
        return false;
    }
    if (!hasPermission('requisition_vouchers', 'edit')) {
        return false;
    }

    $status = isset($requisition['status']) ? $requisition['status'] : '';
    if ($status === 'draft') {
        return true;
    }
    if ($status !== 'approved') {
        return false;
    }

    if ($blockers === null) {
        $blockers = requisitionApprovedEditBlockers(isset($requisition['id']) ? $requisition['id'] : 0, $conn);
    }
    return empty($blockers);
}

/**
 * Whether any stock has been dispatched against this requisition. Transferred
 * lines constrain a post-approval edit: their quantity can never drop below
 * what has already gone out, they cannot be deleted, and the destination is
 * frozen (the same rule change_destination already enforces).
 *
 * @param int $requisitionId Requisition voucher ID
 * @param PDO $conn          Database connection
 * @return bool
 */
function requisitionHasTransfers($requisitionId, $conn) {
    $requisitionId = (int)$requisitionId;
    if ($requisitionId <= 0) {
        return false;
    }
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) FROM requisition_voucher_details
            WHERE requisition_id = ? AND transferred_quantity > 0
        ");
        $stmt->execute([$requisitionId]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Read the post-approval change history of a requisition.
 *
 * The log needs NO schema change: audit_log.old_values / new_values have been
 * there since the base schema and were unused app-wide. Each edit writes one
 * row with action 'edit_after_approval' — deliberately distinct from the plain
 * 'update' a draft edit writes, so post-approval amendments are greppable and
 * a draft's ordinary edits never pollute this panel.
 *
 * new_values carries a ready-to-render 'changes' list built at write time with
 * human-readable labels, so the log never depends on a later lookup of a name
 * that may since have changed.
 *
 * @param PDO $conn          Database connection
 * @param int $requisitionId Requisition voucher ID
 * @return array List of ['created_at', 'user_name', 'changes' => [...]]
 */
function getRequisitionChangeLog($conn, $requisitionId) {
    $requisitionId = (int)$requisitionId;
    $out = [];
    if ($requisitionId <= 0) {
        return $out;
    }
    try {
        $stmt = $conn->prepare("
            SELECT a.created_at, a.new_values, u.name AS user_name
            FROM audit_log a
            LEFT JOIN users u ON a.user_id = u.id
            WHERE a.module = 'requisition_voucher'
              AND a.action = 'edit_after_approval'
              AND a.record_id = ?
            ORDER BY a.id DESC
        ");
        $stmt->execute([$requisitionId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $decoded = json_decode((string)$row['new_values'], true);
            $changes = (is_array($decoded) && !empty($decoded['changes']) && is_array($decoded['changes']))
                ? $decoded['changes'] : [];
            if (empty($changes)) continue; // nothing meaningful to show
            $out[] = [
                'created_at' => $row['created_at'],
                'user_name'  => $row['user_name'],
                'changes'    => $changes,
            ];
        }
    } catch (Throwable $e) {
        return [];
    }
    return $out;
}

/**
 * Whether a budget already has any purchase or purchase-import created against
 * it (linked via budget_id). Used to lock an approved budget from further edits
 * once procurement has started, so editing it can't desync the
 * remaining-to-purchase math or the purchase→budget links.
 *
 * @param int $budgetId Budget ID
 * @param PDO $conn Database connection
 * @return bool True if at least one linked purchase or import exists
 */
function budgetHasLinkedPurchases($budgetId, $conn) {
    $budgetId = (int)$budgetId;
    if ($budgetId <= 0) {
        return false;
    }
    $stmt = $conn->prepare("
        SELECT
            (SELECT COUNT(*) FROM purchases WHERE budget_id = ?)
          + (SELECT COUNT(*) FROM purchase_imports WHERE budget_id = ?) AS cnt
    ");
    $stmt->execute([$budgetId, $budgetId]);
    if ((int)$stmt->fetchColumn() > 0) {
        return true;
    }
    // Open purchase orders raised from the budget also lock it — editing the
    // allocations under an outstanding PO would desync the remaining math.
    // Guarded separately so pre-migration-0038 tenants keep working.
    try {
        $poStmt = $conn->prepare("
            SELECT COUNT(*) FROM purchase_orders
            WHERE budget_id = ? AND status IN ('draft','pending_approval','approved')
        ");
        $poStmt->execute([$budgetId]);
        return (int)$poStmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Whether the CURRENT user may edit this budget.
 *
 * Single source of truth for the budget edit gate — the form, the view page and
 * the list all call this, so they can never disagree about who sees a pencil.
 *
 * Two ways in:
 *   1. The 'budget' edit permission (Admins hold every permission implicitly).
 *   2. Being the person who RAISED the budget. This is the app's only
 *      owner-self-edit exception, and it is deliberately limited to the
 *      pre-approval statuses: once a budget is approved the figures are an
 *      approver's signed decision, so the creator changing them afterwards is a
 *      different act from fixing their own draft.
 *
 * An APPROVED budget stays editable only while nothing has been procured
 * against it — budgetHasLinkedPurchases() is the authoritative check there and
 * also counts OPEN purchase orders, which budget_list.php's own purchase_count
 * sub-select does not.
 *
 * Reaching the page at all still requires 'budget' view; that gate is unchanged.
 *
 * @param array $budget Budget row (needs at least id, status, created_by)
 * @param PDO   $conn   Database connection
 * @return bool
 */
function budgetCanEdit($budget, $conn) {
    if (empty($budget) || !is_array($budget)) {
        return false;
    }
    $status = $budget['status'] ?? '';
    $hasEditPerm = hasPermission('budget', 'edit');
    $isCreator = !empty($budget['created_by']) && (int)$budget['created_by'] === (int)getUserId();

    // Pre-approval: the edit permission OR the creator of this budget.
    if ($status === 'draft' || $status === 'pending_approval') {
        return $hasEditPerm || $isCreator;
    }

    // Approved: permission only, and only until procurement starts.
    if ($status === 'approved') {
        return $hasEditPerm && !budgetHasLinkedPurchases((int)($budget['id'] ?? 0), $conn);
    }

    // rejected (and anything else) stays terminal.
    return false;
}

/**
 * Whether the CURRENT user may send this budget for approval.
 *
 * Mirrors budgetCanEdit()'s creator override so the edit right is not a one-way
 * door: a creator who lacks the 'budget' edit permission can knock their own
 * pending budget back to draft by editing it, and must therefore also be able
 * to put it back into the approval queue. Approve / reject are NOT widened —
 * those stay strictly on the 'budget' approve permission.
 *
 * @param array $budget Budget row (needs at least status, created_by)
 * @return bool
 */
function budgetCanSendForApproval($budget) {
    if (empty($budget) || !is_array($budget)) {
        return false;
    }
    if (($budget['status'] ?? '') !== 'draft') {
        return false;
    }
    if (hasPermission('budget', 'edit')) {
        return true;
    }
    return !empty($budget['created_by']) && (int)$budget['created_by'] === (int)getUserId();
}

/**
 * Outstanding (ordered but not yet purchased) quantities sitting on OPEN
 * purchase orders raised from a budget. Open = draft / pending_approval /
 * approved; closed POs are fully received (their purchases carry budget_id and
 * are counted as purchased) and cancelled POs hold nothing. Used so the same
 * budget line can't be procured twice — once via a PO and again via a direct
 * purchase.
 *
 * @param int      $budgetId    Budget ID
 * @param PDO      $conn        Database connection
 * @param int|null $supplierId  Limit to one supplier; keys become product_id
 * @param int|null $excludePoId PO to exclude (the one being edited/saved)
 * @return array Map of 'supplier_id|product_id' => qty (or product_id => qty
 *               when $supplierId is given). Empty pre-migration-0038.
 */
/**
 * Remaining-to-procure per product for one budget + supplier.
 *
 *     remaining = allocated - already purchased - outstanding on open POs
 *
 * Same arithmetic the "Create PO from budget" path runs inline in
 * modules/transactions/purchase_order.php (see the $fromBudgetRemaining block).
 * That path is left untouched on purpose — this helper exists for the
 * link-an-existing-PO-to-a-budget flow, which has to enforce an identical cap.
 *
 * @param int      $budgetId
 * @param int      $supplierId  Only 'purchase'-type allocations for this supplier.
 * @param PDO      $conn
 * @param int|null $excludePoId Ignore this PO's own outstanding quantity, so a
 *                              PO can be measured against the budget as if it
 *                              were not already counted.
 * @return array product_id => ['remaining','allocated','purchased','on_po','rate','name','unit']
 */
function getBudgetSupplierRemaining($budgetId, $supplierId, $conn, $excludePoId = null) {
    $out        = [];
    $budgetId   = (int)$budgetId;
    $supplierId = (int)$supplierId;
    if ($budgetId <= 0 || $supplierId <= 0) {
        return $out;
    }

    // GROUPED BY PRODUCT, and it must stay that way. A budget may hold the same
    // product + supplier + type on SEVERAL rows, and the purchased/on-PO figures
    // below are per PRODUCT — so without this the same purchased quantity would
    // be subtracted from every one of those rows, understating what is still
    // procurable. Rate is quantity-weighted so quantity x rate still equals the
    // budgeted value of the rows; VAT is simply summed.
    $aStmt = $conn->prepare("
        SELECT bd.product_id,
               SUM(bd.quantity) AS quantity,
               ROUND(SUM(bd.quantity * bd.rate) / NULLIF(SUM(bd.quantity), 0), 2) AS rate,
               SUM(bd.vat_amount) AS vat_amount,
               p.name AS product_name, p.unit
        FROM budget_details bd
        JOIN products p ON bd.product_id = p.id
        WHERE bd.budget_id = ? AND bd.supplier_id = ? AND bd.procurement_type = 'purchase' AND p.is_active = 1
        GROUP BY bd.product_id, p.name, p.unit
        ORDER BY p.name
    ");
    $aStmt->execute([$budgetId, $supplierId]);
    $allocs = $aStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$allocs) {
        return $out;
    }

    $createdStmt = $conn->prepare("
        SELECT pd.product_id, COALESCE(SUM(pd.quantity), 0) AS qty
        FROM purchase_details pd
        JOIN purchases pu ON pd.purchase_id = pu.id
        WHERE pu.budget_id = ? AND pu.supplier_id = ?
        GROUP BY pd.product_id
    ");
    $createdStmt->execute([$budgetId, $supplierId]);
    $createdMap = [];
    foreach ($createdStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $createdMap[(int)$r['product_id']] = (float)$r['qty'];
    }

    $poOutMap = getBudgetPoOutstanding($budgetId, $conn, $supplierId, $excludePoId);

    foreach ($allocs as $a) {
        $pid       = (int)$a['product_id'];
        $allocated = (float)$a['quantity'];
        $purchased = $createdMap[$pid] ?? 0;
        $onPo      = $poOutMap[$pid] ?? 0;
        $rem       = $allocated - $purchased - $onPo;
        $out[$pid] = [
            'remaining' => $rem < 0 ? 0 : $rem,
            'allocated' => $allocated,
            'purchased' => $purchased,
            'on_po'     => $onPo,
            'rate'      => (float)$a['rate'],
            // Passenger data for prefill, exactly like 'rate' — the remaining
            // math above is quantity-only and unaffected (migration 0044).
            'vat'       => budgetLineVatForQty($a['vat_amount'] ?? 0, $allocated, $rem < 0 ? 0 : $rem),
            'name'      => $a['product_name'],
            'unit'      => $a['unit'],
        ];
    }
    return $out;
}

function getBudgetPoOutstanding($budgetId, $conn, $supplierId = null, $excludePoId = null) {
    $out = [];
    $budgetId = (int)$budgetId;
    if ($budgetId <= 0) {
        return $out;
    }
    try {
        $sql = "
            SELECT po.supplier_id, pod.product_id,
                   SUM(GREATEST(COALESCE(pod.approved_quantity, pod.quantity) - pod.purchased_quantity, 0)) AS qty
            FROM purchase_orders po
            JOIN purchase_order_details pod ON pod.purchase_order_id = po.id
            WHERE po.budget_id = ? AND po.status IN ('draft','pending_approval','approved')
        ";
        $params = [$budgetId];
        if ($supplierId) {
            $sql .= " AND po.supplier_id = ?";
            $params[] = (int)$supplierId;
        }
        if ($excludePoId) {
            $sql .= " AND po.id != ?";
            $params[] = (int)$excludePoId;
        }
        $sql .= " GROUP BY po.supplier_id, pod.product_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $key = $supplierId ? (int)$r['product_id'] : ($r['supplier_id'] . '|' . $r['product_id']);
            $out[$key] = ($out[$key] ?? 0) + (float)$r['qty'];
        }
    } catch (PDOException $e) {
        // purchase_orders table absent (migration 0038 not applied) -> nothing outstanding
    }
    return $out;
}

/**
 * Open/closed purchase state for a set of APPROVED budgets, in one batch.
 *
 * A budget is closed when every supplier + product + type allocation has been
 * fully purchased or imported through documents linked to that budget. Each
 * allocation stands alone, so buying extra of one product cannot cover a shortage
 * of another. Quantity sitting on a purchase order is NOT purchased, which is why
 * this differs on purpose from the "remaining" figures that subtract open POs.
 *
 * Computed live and never stored, so deleting a purchase reopens the budget.
 *
 * @param  int[] $budgetIds Approved budget IDs
 * @param  PDO   $conn
 * @return array budget_id => 'open' | 'closed'; a budget with no lines is absent
 */
function getBudgetPurchaseStates(array $budgetIds, $conn) {
    $ids = array_values(array_unique(array_filter(array_map('intval', $budgetIds))));
    if (empty($ids)) {
        return [];
    }
    $in = implode(',', $ids);

    $purchased = [];
    foreach ($conn->query("
        SELECT pu.budget_id, pu.supplier_id, pd.product_id, SUM(pd.quantity) AS qty
        FROM purchases pu
        JOIN purchase_details pd ON pd.purchase_id = pu.id
        WHERE pu.budget_id IN ($in)
        GROUP BY pu.budget_id, pu.supplier_id, pd.product_id
    ")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $purchased['purchase|' . $r['budget_id'] . '|' . $r['supplier_id'] . '|' . $r['product_id']] = (float)$r['qty'];
    }
    foreach ($conn->query("
        SELECT pim.budget_id, pim.supplier_id, pid.product_id, SUM(pid.quantity) AS qty
        FROM purchase_imports pim
        JOIN purchase_import_details pid ON pid.import_id = pim.id
        WHERE pim.budget_id IN ($in)
        GROUP BY pim.budget_id, pim.supplier_id, pid.product_id
    ")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $purchased['import|' . $r['budget_id'] . '|' . $r['supplier_id'] . '|' . $r['product_id']] = (float)$r['qty'];
    }

    $states = [];
    foreach ($conn->query("
        SELECT budget_id, supplier_id, product_id, procurement_type, SUM(quantity) AS qty
        FROM budget_details
        WHERE budget_id IN ($in)
        GROUP BY budget_id, supplier_id, product_id, procurement_type
    ")->fetchAll(PDO::FETCH_ASSOC) as $line) {
        $budgetId = (int)$line['budget_id'];
        $key = $line['procurement_type'] . '|' . $budgetId . '|' . $line['supplier_id'] . '|' . $line['product_id'];
        $isShort = (float)$line['qty'] - ($purchased[$key] ?? 0) > 0.0005;
        if ($isShort) {
            $states[$budgetId] = 'open';
        } elseif (!isset($states[$budgetId])) {
            $states[$budgetId] = 'closed';
        }
    }
    return $states;
}

/**
 * How much of a budget line's VAT belongs to the quantity being converted now.
 *
 * A budget line is routinely procured in parts, so its VAT is consumed in step
 * with its quantity: a line of 10 units carrying 180 VAT contributes 72 when 4
 * units are converted, leaving 108 for later. (migration 0044)
 *
 * Kept in one place because five call sites need the identical formula — the
 * purchase / import / PO prefills, getBudgetSupplierRemaining(), and the
 * per-supplier card in budget_view.php.
 *
 * @param  float $lineVat      vat_amount stored on the budget_details row
 * @param  float $allocatedQty the line's full budgeted quantity
 * @param  float $convertQty   quantity being taken now (usually "remaining")
 * @return float rounded to 2dp; 0 when the line has no VAT or no quantity
 */
function budgetLineVatForQty($lineVat, $allocatedQty, $convertQty) {
    $lineVat      = (float)$lineVat;
    $allocatedQty = (float)$allocatedQty;
    $convertQty   = (float)$convertQty;

    if ($lineVat == 0.0 || $allocatedQty <= 0 || $convertQty <= 0) {
        return 0.0;
    }
    // Never hand out more VAT than the line holds, whatever the caller passes.
    if ($convertQty > $allocatedQty) {
        $convertQty = $allocatedQty;
    }
    return round($lineVat * ($convertQty / $allocatedQty), 2);
}

/**
 * Get all descendant category IDs for a given category (from DB).
 * Includes the given category ID itself in the result.
 *
 * @param int $categoryId Parent category ID
 * @param PDO $conn Database connection
 * @return array Array of category IDs (parent + all descendants)
 */
function getDescendantCategoryIds($categoryId, $conn) {
    $stmt = $conn->query("SELECT id, parent_id FROM product_categories WHERE is_active = 1");
    $all = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $descendants = getDescendantIds($all, $categoryId);
    array_unshift($descendants, (int)$categoryId);
    return $descendants;
}

/**
 * Walk up the parent_id chain for a location and return the topmost ancestor's ID.
 * If the given location is itself a root (parent_id IS NULL), its own ID is returned.
 *
 * @param int $locationId
 * @param PDO $conn
 * @return int Root ancestor location ID
 */
function getLocationRootId($locationId, $conn) {
    $locationId = (int)$locationId;
    $stmt = $conn->query("SELECT id, parent_id FROM locations WHERE is_active = 1");
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[(int)$row['id']] = $row['parent_id'] !== null ? (int)$row['parent_id'] : null;
    }
    $currentId = $locationId;
    $guard = 0;
    while (isset($map[$currentId]) && $map[$currentId] !== null) {
        $currentId = $map[$currentId];
        if (++$guard > 1000) break; // safety against accidental cycle
    }
    return $currentId;
}

/**
 * Return the full "family" of locations for a given location: the root ancestor
 * plus every descendant under that root (i.e. parents, children, siblings,
 * cousins — everyone connected through the same root). Includes the given
 * location itself.
 *
 * @param int $locationId
 * @param PDO $conn
 * @return int[] Family location IDs
 */
function getLocationFamilyIds($locationId, $conn) {
    $rootId = getLocationRootId($locationId, $conn);
    return getDescendantLocationIds($rootId, $conn);
}

/**
 * Build dynamic SQL IN clause placeholders and params for an array of IDs.
 * Usage: list($placeholders, $params) = buildInClause($ids);
 *        Then use "WHERE col IN ($placeholders)" with $params
 *
 * @param array $ids Array of integer IDs
 * @return array [placeholders_string, params_array]
 */
function buildInClause($ids) {
    $ids = array_map('intval', $ids);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    return [$placeholders, $ids];
}

/**
 * Get product IDs with non-zero closing stock at a given location as of a date (inclusive).
 *
 * Uses one aggregated UNION ALL query per movement source, then sums per product
 * and keeps only products where the net is != 0. Designed for the stock-taking
 * filter where we want to hide products that have nothing to count.
 *
 * @param int $locationId
 * @param string $date Y-m-d
 * @param PDO $conn
 * @return int[] product IDs
 */
function getProductIdsWithNonZeroStock($locationId, $date, $conn) {
    $locationId = (int)$locationId;
    if ($locationId <= 0 || empty($date)) {
        return [];
    }
    $sql = "
        SELECT product_id
        FROM (
            SELECT product_id, SUM(quantity) AS qty
            FROM opening_stocks
            WHERE location_id = :loc1 AND stock_date <= :d1
            GROUP BY product_id

            UNION ALL
            SELECT pd.product_id, SUM(pd.quantity)
            FROM purchase_details pd
            JOIN purchases p ON pd.purchase_id = p.id
            WHERE p.location_id = :loc2 AND p.purchase_date <= :d2
            GROUP BY pd.product_id

            UNION ALL
            SELECT pid.product_id, SUM(pid.quantity)
            FROM purchase_import_details pid
            JOIN purchase_imports pi ON pid.import_id = pi.id
            WHERE pi.location_id = :loc3 AND pi.import_date <= :d3
            GROUP BY pid.product_id

            UNION ALL
            SELECT sd.product_id, -SUM(sd.quantity)
            FROM sale_details sd
            JOIN sales s ON sd.sale_id = s.id
            WHERE s.location_id = :loc4 AND s.sale_date <= :d4
            GROUP BY sd.product_id

            UNION ALL
            SELECT sad.product_id,
                   SUM(CASE WHEN sad.adjustment_type = 'increase' THEN sad.quantity ELSE -sad.quantity END)
            FROM stock_adjustment_details sad
            JOIN stock_adjustments sa ON sad.adjustment_id = sa.id
            WHERE sa.location_id = :loc5 AND sa.adjustment_date <= :d5
            GROUP BY sad.product_id

            UNION ALL
            SELECT td.product_id, SUM(td.received_quantity)
            FROM stock_transfer_details td
            JOIN stock_transfers t ON td.transfer_id = t.id
            WHERE t.to_location_id = :loc6 AND t.status = 'received' AND DATE(t.received_at) <= :d6
            GROUP BY td.product_id

            UNION ALL
            SELECT td.product_id, -SUM(td.dispatched_quantity)
            FROM stock_transfer_details td
            JOIN stock_transfers t ON td.transfer_id = t.id
            WHERE t.from_location_id = :loc7 AND t.status IN ('in_transit','received') AND DATE(t.dispatched_at) <= :d7
            GROUP BY td.product_id

            UNION ALL
            SELECT cvd.product_id, -SUM(cvd.quantity)
            FROM consumption_voucher_details cvd
            JOIN consumption_vouchers cv ON cvd.voucher_id = cv.id
            WHERE cv.location_id = :loc8 AND cv.voucher_date <= :d8
            GROUP BY cvd.product_id
        ) s
        GROUP BY product_id
        HAVING ABS(SUM(qty)) > 0.0001
    ";
    $stmt = $conn->prepare($sql);
    $params = [];
    for ($i = 1; $i <= 8; $i++) {
        $params[":loc$i"] = $locationId;
        $params[":d$i"]   = $date;
    }
    $stmt->execute($params);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

// ==================== NOTIFICATIONS ====================

/**
 * Create a notification for a specific user
 */
function createNotification($userId, $type, $title, $message, $link = null, $referenceId = null, $referenceType = null) {
    global $conn;
    $stmt = $conn->prepare("
        INSERT INTO notifications (user_id, type, title, message, link, reference_id, reference_type)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$userId, $type, $title, $message, $link, $referenceId, $referenceType]);
}

/**
 * Get unread notification count for current user
 */
function getUnreadNotificationCount() {
    global $conn;
    $userId = getUserId();
    if (!$userId) return 0;

    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * Get recent notifications for current user
 */
function getRecentNotifications($limit = 15) {
    global $conn;
    $userId = getUserId();
    if (!$userId) return [];

    $stmt = $conn->prepare("
        SELECT * FROM notifications
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT ?
    ");
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Notify all users who have can_approve permission on a given module
 */
function notifyApprovers($module, $type, $title, $message, $link = null, $referenceId = null, $referenceType = null) {
    global $conn;
    $stmt = $conn->prepare("
        SELECT DISTINCT u.id
        FROM users u
        LEFT JOIN user_permissions up ON u.id = up.user_id AND up.module_name = ?
        LEFT JOIN roles r ON u.role_id = r.id
        WHERE u.is_active = 1
          AND (up.can_approve = 1 OR r.name = 'Admin')
    ");
    $stmt->execute([$module]);
    $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($userIds as $userId) {
        createNotification($userId, $type, $title, $message, $link, $referenceId, $referenceType);
    }
}

/**
 * Notify all users who have can_verify permission on a given module.
 * Mirrors notifyApprovers(). Safe to call even if can_verify column
 * does not yet exist (try/catch swallows the error).
 */
function notifyVerifiers($module, $type, $title, $message, $link = null, $referenceId = null, $referenceType = null) {
    global $conn;
    try {
        $stmt = $conn->prepare("
            SELECT DISTINCT u.id
            FROM users u
            LEFT JOIN user_permissions up ON u.id = up.user_id AND up.module_name = ?
            LEFT JOIN roles r ON u.role_id = r.id
            WHERE u.is_active = 1
              AND (up.can_verify = 1 OR r.name = 'Admin')
        ");
        $stmt->execute([$module]);
        $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        return; // column not yet migrated — silently skip
    }

    foreach ($userIds as $userId) {
        createNotification($userId, $type, $title, $message, $link, $referenceId, $referenceType);
    }
}

// ============================================================
// Cost-centre-scoped notification routing (added in migration 0025).
// Used by requisition vouchers. Other notification flows (notifyApprovers,
// notifyVerifiers) remain unchanged and unaffected.
// ============================================================

/**
 * Return an array of user rows (id, full_name, email) configured as
 * approvers for the given cost centre. Empty when no CC, no rows, or
 * the table is missing (pre-migration safety).
 */
function getCostCentreApprovers($costCentreId) {
    global $conn;
    if (empty($costCentreId)) return [];
    try {
        $stmt = $conn->prepare("
            SELECT u.id, u.name AS full_name, u.email
            FROM cost_centre_approvers cca
            JOIN users u ON u.id = cca.user_id
            WHERE cca.cost_centre_id = ? AND u.is_active = 1
        ");
        $stmt->execute([(int)$costCentreId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Return an array of user rows (id, full_name, email) configured as
 * watchers for the given cost centre. Empty when no CC, no rows, or
 * the table is missing (pre-migration safety).
 */
function getCostCentreWatchers($costCentreId) {
    global $conn;
    if (empty($costCentreId)) return [];
    try {
        $stmt = $conn->prepare("
            SELECT u.id, u.name AS full_name, u.email
            FROM cost_centre_watchers ccw
            JOIN users u ON u.id = ccw.user_id
            WHERE ccw.cost_centre_id = ? AND u.is_active = 1
        ");
        $stmt->execute([(int)$costCentreId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Check whether the given user is allowed to approve a requisition for a
 * specific cost centre, taking CC override into account.
 *
 *   - If the CC has approvers configured: only those users return true.
 *   - Otherwise: falls back to the existing can_approve permission check.
 *
 * Used by the requisition approve endpoint to enforce override scoping.
 */
function canApproveRequisitionForCostCentre($userId, $costCentreId) {
    global $conn;
    $userId = (int)$userId;
    if ($userId <= 0) return false;

    // Admin always wins.
    if (getUserData('role_name') === 'Admin') return true;

    if (!empty($costCentreId)) {
        try {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM cost_centre_approvers WHERE cost_centre_id = ?");
            $stmt->execute([(int)$costCentreId]);
            $configuredCount = (int)$stmt->fetchColumn();

            if ($configuredCount > 0) {
                $stmt = $conn->prepare("SELECT 1 FROM cost_centre_approvers WHERE cost_centre_id = ? AND user_id = ? LIMIT 1");
                $stmt->execute([(int)$costCentreId, $userId]);
                return (bool)$stmt->fetchColumn();
            }
        } catch (PDOException $e) {
            // table missing — fall through to permission fallback
        }
    }

    // Fallback: existing can_approve permission on requisition_vouchers.
    return hasPermission('requisition_vouchers', 'approve');
}

/**
 * Notify designated approvers when a requisition is sent for approval.
 *
 *   - If the requisition's cost centre has approvers configured, only
 *     those users get notified (bell + email).
 *   - Otherwise, falls back to notifyApprovers() (existing behavior,
 *     anyone with can_approve permission).
 *
 * Email failures are swallowed (bell notification still created).
 *
 * @param array $requisition Full requisition row from getRequisitionVoucherById()
 */
function notifyRequisitionApprovers($requisition) {
    if (empty($requisition) || empty($requisition['id'])) return;

    $reqId = (int)$requisition['id'];
    $voucherNo = $requisition['voucher_number'] ?? ('#' . $reqId);
    $title = 'Requisition Pending Approval';
    $message = 'Requisition ' . $voucherNo . ' requires your approval';
    $link = BASE_URL . 'modules/transactions/requisition_voucher_view.php?id=' . $reqId;

    $ccId = !empty($requisition['cost_centre_id']) ? (int)$requisition['cost_centre_id'] : null;
    $approvers = $ccId ? getCostCentreApprovers($ccId) : [];

    if (!empty($approvers)) {
        // CC override: notify only these users.
        foreach ($approvers as $u) {
            createNotification(
                (int)$u['id'],
                'requisition_approval',
                $title,
                $message,
                $link,
                $reqId,
                'requisition_voucher'
            );
            _sendRequisitionApprovalEmailSafe($u, $requisition);
        }
        return;
    }

    // Fallback: existing behavior (anyone with can_approve).
    notifyApprovers(
        'requisition_vouchers',
        'requisition_approval',
        $title,
        $message,
        $link,
        $reqId,
        'requisition_voucher'
    );

    // Best-effort email broadcast in fallback mode too — fetch the users
    // that notifyApprovers() would have selected and email them.
    global $conn;
    try {
        $stmt = $conn->prepare("
            SELECT DISTINCT u.id, u.name AS full_name, u.email
            FROM users u
            LEFT JOIN user_permissions up ON u.id = up.user_id AND up.module_name = 'requisition_vouchers'
            LEFT JOIN roles r ON u.role_id = r.id
            WHERE u.is_active = 1 AND (up.can_approve = 1 OR r.name = 'Admin')
        ");
        $stmt->execute();
        $fallbackUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($fallbackUsers as $u) {
            _sendRequisitionApprovalEmailSafe($u, $requisition);
        }
    } catch (PDOException $e) {
        error_log('notifyRequisitionApprovers fallback email lookup failed: ' . $e->getMessage());
    }
}

/**
 * Notify the creator + cost-centre watchers once a requisition is approved.
 *
 *   - Creator always gets the existing bell notification (no regression).
 *   - Watchers (if any) get a bell + email copy.
 *
 * @param array $requisition Full requisition row from getRequisitionVoucherById()
 */
function notifyRequisitionApproved($requisition) {
    if (empty($requisition) || empty($requisition['id'])) return;

    global $conn;
    $reqId = (int)$requisition['id'];
    $voucherNo = $requisition['voucher_number'] ?? ('#' . $reqId);
    $link = BASE_URL . 'modules/transactions/requisition_voucher_view.php?id=' . $reqId;

    // 1. Notify the creator (same as old inline behavior).
    if (!empty($requisition['created_by'])) {
        createNotification(
            (int)$requisition['created_by'],
            'requisition_approved',
            'Requisition Approved',
            'Your requisition ' . $voucherNo . ' has been approved',
            $link,
            $reqId,
            'requisition_voucher'
        );
        // Email the creator too (best-effort).
        try {
            $stmt = $conn->prepare("SELECT id, name AS full_name, email FROM users WHERE id = ?");
            $stmt->execute([(int)$requisition['created_by']]);
            $creator = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($creator) {
                _sendRequisitionApprovedEmailSafe($creator, $requisition, 'creator');
            }
        } catch (PDOException $e) {
            error_log('notifyRequisitionApproved creator email lookup failed: ' . $e->getMessage());
        }
    }

    // 2. Notify watchers configured on the cost centre (informational).
    $ccId = !empty($requisition['cost_centre_id']) ? (int)$requisition['cost_centre_id'] : null;
    if ($ccId) {
        $watchers = getCostCentreWatchers($ccId);
        $creatorId = (int)($requisition['created_by'] ?? 0);
        foreach ($watchers as $u) {
            if ((int)$u['id'] === $creatorId) continue; // already handled above
            createNotification(
                (int)$u['id'],
                'requisition_approved_watch',
                'Requisition Approved',
                'Requisition ' . $voucherNo . ' (you are watching) has been approved',
                $link,
                $reqId,
                'requisition_voucher'
            );
            _sendRequisitionApprovedEmailSafe($u, $requisition, 'watcher');
        }
    }
}

/**
 * Notify that a requisition was REJECTED.
 *
 * Mirrors notifyRequisitionApproved(): a bell notification is always created
 * for the creator (and any cost-centre watchers); email is best-effort and
 * never blocks the rejection. Kept self-contained (plain inline email) so it
 * does not depend on a new email template file.
 */
function notifyRequisitionRejected($requisition, $reason = '') {
    if (empty($requisition) || empty($requisition['id'])) return;

    global $conn;
    $reqId = (int)$requisition['id'];
    $voucherNo = $requisition['voucher_number'] ?? ('#' . $reqId);
    $link = BASE_URL . 'modules/transactions/requisition_voucher_view.php?id=' . $reqId;
    $reason = trim((string)$reason);
    $reasonLine = $reason !== '' ? (' Reason: ' . $reason) : '';

    // 1. Notify the creator.
    if (!empty($requisition['created_by'])) {
        createNotification(
            (int)$requisition['created_by'],
            'requisition_rejected',
            'Requisition Rejected',
            'Your requisition ' . $voucherNo . ' has been rejected.' . $reasonLine,
            $link,
            $reqId,
            'requisition_voucher'
        );
        try {
            $stmt = $conn->prepare("SELECT id, name AS full_name, email FROM users WHERE id = ?");
            $stmt->execute([(int)$requisition['created_by']]);
            $creator = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($creator) {
                _sendRequisitionRejectedEmailSafe($creator, $requisition, $reason);
            }
        } catch (PDOException $e) {
            error_log('notifyRequisitionRejected creator email lookup failed: ' . $e->getMessage());
        }
    }

    // 2. Notify cost-centre watchers (informational).
    $ccId = !empty($requisition['cost_centre_id']) ? (int)$requisition['cost_centre_id'] : null;
    if ($ccId) {
        $watchers = getCostCentreWatchers($ccId);
        $creatorId = (int)($requisition['created_by'] ?? 0);
        foreach ($watchers as $u) {
            if ((int)$u['id'] === $creatorId) continue; // already handled above
            createNotification(
                (int)$u['id'],
                'requisition_rejected_watch',
                'Requisition Rejected',
                'Requisition ' . $voucherNo . ' (you are watching) has been rejected.' . $reasonLine,
                $link,
                $reqId,
                'requisition_voucher'
            );
            _sendRequisitionRejectedEmailSafe($u, $requisition, $reason);
        }
    }
}

/**
 * Internal: best-effort "rejected" email. Plain inline HTML (no template
 * dependency); failures are swallowed so they never block the rejection.
 */
function _sendRequisitionRejectedEmailSafe($user, $requisition, $reason = '') {
    if (empty($user['email'])) return;
    if (!function_exists('sendEmail')) require_once __DIR__ . '/mailer.php';

    try {
        $voucherNo = htmlspecialchars($requisition['voucher_number'] ?? ('#' . ($requisition['id'] ?? '')));
        $name = htmlspecialchars($user['full_name'] ?? '');
        $reason = trim((string)$reason);
        $reasonHtml = $reason !== ''
            ? '<p><strong>Reason:</strong> ' . nl2br(htmlspecialchars($reason)) . '</p>'
            : '';
        $html = '<p>Hello ' . $name . ',</p>'
              . '<p>Requisition <strong>' . $voucherNo . '</strong> has been <strong>rejected</strong>.</p>'
              . $reasonHtml
              . '<p>You can review it in ' . htmlspecialchars(APP_NAME) . '.</p>';
        $subject = 'Requisition ' . ($requisition['voucher_number'] ?? '') . ' rejected';
        @sendEmail($user['email'], $user['full_name'] ?? null, $subject, $html);
    } catch (Throwable $e) {
        error_log('_sendRequisitionRejectedEmailSafe failed: ' . $e->getMessage());
    }
}

/**
 * Notify that an approved requisition had its approved quantities REVISED
 * (edited after approval, while still open). Mirrors notifyRequisitionRejected():
 * a bell notification is always created for the creator (and cost-centre
 * watchers); email is best-effort and never blocks the save. Self-contained
 * (plain inline email) so it adds no new template dependency.
 */
function notifyRequisitionRevised($requisition) {
    if (empty($requisition) || empty($requisition['id'])) return;

    global $conn;
    $reqId = (int)$requisition['id'];
    $voucherNo = $requisition['voucher_number'] ?? ('#' . $reqId);
    $link = BASE_URL . 'modules/transactions/requisition_voucher_view.php?id=' . $reqId;

    // 1. Notify the creator.
    if (!empty($requisition['created_by'])) {
        createNotification(
            (int)$requisition['created_by'],
            'requisition_revised',
            'Requisition Quantities Revised',
            'Approved quantities for your requisition ' . $voucherNo . ' have been revised.',
            $link,
            $reqId,
            'requisition_voucher'
        );
        try {
            $stmt = $conn->prepare("SELECT id, name AS full_name, email FROM users WHERE id = ?");
            $stmt->execute([(int)$requisition['created_by']]);
            $creator = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($creator) {
                _sendRequisitionRevisedEmailSafe($creator, $requisition);
            }
        } catch (PDOException $e) {
            error_log('notifyRequisitionRevised creator email lookup failed: ' . $e->getMessage());
        }
    }

    // 2. Notify cost-centre watchers (informational).
    $ccId = !empty($requisition['cost_centre_id']) ? (int)$requisition['cost_centre_id'] : null;
    if ($ccId) {
        $watchers = getCostCentreWatchers($ccId);
        $creatorId = (int)($requisition['created_by'] ?? 0);
        foreach ($watchers as $u) {
            if ((int)$u['id'] === $creatorId) continue; // already handled above
            createNotification(
                (int)$u['id'],
                'requisition_revised_watch',
                'Requisition Quantities Revised',
                'Approved quantities for requisition ' . $voucherNo . ' (you are watching) have been revised.',
                $link,
                $reqId,
                'requisition_voucher'
            );
            _sendRequisitionRevisedEmailSafe($u, $requisition);
        }
    }
}

/**
 * Internal: best-effort "revised" email. Plain inline HTML (no template
 * dependency); failures are swallowed so they never block the save.
 */
function _sendRequisitionRevisedEmailSafe($user, $requisition) {
    if (empty($user['email'])) return;
    if (!function_exists('sendEmail')) require_once __DIR__ . '/mailer.php';

    try {
        $voucherNo = htmlspecialchars($requisition['voucher_number'] ?? ('#' . ($requisition['id'] ?? '')));
        $name = htmlspecialchars($user['full_name'] ?? '');
        $html = '<p>Hello ' . $name . ',</p>'
              . '<p>The approved quantities for requisition <strong>' . $voucherNo . '</strong> have been <strong>revised</strong>.</p>'
              . '<p>You can review the updated quantities in ' . htmlspecialchars(APP_NAME) . '.</p>';
        $subject = 'Requisition ' . ($requisition['voucher_number'] ?? '') . ' quantities revised';
        @sendEmail($user['email'], $user['full_name'] ?? null, $subject, $html);
    } catch (Throwable $e) {
        error_log('_sendRequisitionRevisedEmailSafe failed: ' . $e->getMessage());
    }
}

/**
 * Internal: render + send the "pending approval" email. Mailer and
 * templates are loaded lazily so unrelated code paths aren't affected.
 */
function _sendRequisitionApprovalEmailSafe($user, $requisition) {
    if (empty($user['email'])) return;
    if (!function_exists('sendEmail')) require_once __DIR__ . '/mailer.php';
    if (!function_exists('renderRequisitionApprovalEmail')) require_once __DIR__ . '/email_templates.php';

    try {
        $html = renderRequisitionApprovalEmail($requisition, [
            'name' => $user['full_name'] ?? '',
        ]);
        $subject = 'Requisition ' . ($requisition['voucher_number'] ?? '') . ' pending your approval';
        @sendEmail($user['email'], $user['full_name'] ?? null, $subject, $html);
    } catch (Throwable $e) {
        error_log('_sendRequisitionApprovalEmailSafe failed: ' . $e->getMessage());
    }
}

/**
 * Internal: render + send the "approved" email.
 *
 * @param string $role 'creator' or 'watcher' — adjusts wording.
 */
function _sendRequisitionApprovedEmailSafe($user, $requisition, $role = 'watcher') {
    if (empty($user['email'])) return;
    if (!function_exists('sendEmail')) require_once __DIR__ . '/mailer.php';
    if (!function_exists('renderRequisitionApprovedEmail')) require_once __DIR__ . '/email_templates.php';

    try {
        $html = renderRequisitionApprovedEmail($requisition, [
            'name' => $user['full_name'] ?? '',
            'role' => $role,
        ]);
        $subject = 'Requisition ' . ($requisition['voucher_number'] ?? '') . ' approved';
        @sendEmail($user['email'], $user['full_name'] ?? null, $subject, $html);
    } catch (Throwable $e) {
        error_log('_sendRequisitionApprovedEmailSafe failed: ' . $e->getMessage());
    }
}

// ============================================================
// VAT / Transactions helpers (Ledger Report)
// ============================================================

if (!defined('SYSTEM_LEDGER_PURCHASE')) define('SYSTEM_LEDGER_PURCHASE', 'Purchase Account');
if (!defined('SYSTEM_LEDGER_VAT'))      define('SYSTEM_LEDGER_VAT', 'VAT');
if (!defined('SYSTEM_LEDGER_SALES'))    define('SYSTEM_LEDGER_SALES', 'Sales Account');

/**
 * Find a ledger by exact name; create it if missing. Returns ledger id.
 */
function getOrCreateLedger($conn, $name, $accountType) {
    $stmt = $conn->prepare("SELECT id FROM ledger_accounts WHERE name = ? LIMIT 1");
    $stmt->execute([$name]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return (int)$row['id'];
    }

    $defCur = getDefaultCurrency();
    $currencyId = $defCur ? (int)$defCur['id'] : null;

    $stmt = $conn->prepare("
        INSERT INTO ledger_accounts (name, account_type, currency_id, is_active)
        VALUES (?, ?, ?, 1)
    ");
    $stmt->execute([$name, $accountType, $currencyId]);
    return (int)$conn->lastInsertId();
}

/**
 * Failsafe: ensure system ledgers (Purchase Account, VAT, Sales Account)
 * exist. Idempotent — safe to call before every save that needs them.
 * Returns the ledger ids keyed by purpose.
 */
function ensureSystemLedgers($conn) {
    return [
        'purchase_ledger_id' => getOrCreateLedger($conn, SYSTEM_LEDGER_PURCHASE, 'purchase'),
        'vat_ledger_id'      => getOrCreateLedger($conn, SYSTEM_LEDGER_VAT, 'duties'),
        'sales_ledger_id'    => getOrCreateLedger($conn, SYSTEM_LEDGER_SALES, 'sales'),
    ];
}

/**
 * Delete the transaction row (cascades to transaction_details) for a voucher.
 */
function deleteVoucherTransaction($conn, $voucherType, $voucherId) {
    $stmt = $conn->prepare("DELETE FROM transactions WHERE voucher_type = ? AND voucher_id = ?");
    $stmt->execute([$voucherType, (int)$voucherId]);
}

/**
 * Create transaction + transaction_details rows for a purchase.
 * Caller is responsible for the surrounding DB transaction (beginTransaction/commit).
 *
 *   DR Purchase Account  total_amount
 *   DR VAT               vat_amount        (skipped if 0)
 *   CR Supplier Ledger   total_amount + vat_amount
 *
 * All detail rows share the purchase's currency_id and exchange_rate_to_base.
 */
function createPurchaseTransaction($conn, $purchaseId) {
    $stmt = $conn->prepare("
        SELECT p.id, p.purchase_number, p.purchase_date, p.total_amount, p.vat_amount,
               p.currency_id, p.exchange_rate_to_base, p.cost_centre_id, p.created_by,
               s.ledger_account_id AS supplier_ledger_id
        FROM purchases p
        JOIN suppliers s ON p.supplier_id = s.id
        WHERE p.id = ?
    ");
    $stmt->execute([(int)$purchaseId]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$p) {
        throw new Exception('Purchase not found for transaction logging: ' . $purchaseId);
    }
    if (empty($p['supplier_ledger_id'])) {
        throw new Exception('Supplier has no linked ledger account (purchase id ' . $purchaseId . ')');
    }

    $sys         = ensureSystemLedgers($conn);
    $totalAmount = (float)$p['total_amount'];
    $vatAmount   = (float)$p['vat_amount'];
    $rate        = (float)$p['exchange_rate_to_base'];
    if ($rate <= 0) { $rate = 1.0; }
    $payable     = $totalAmount + $vatAmount;

    $stmt = $conn->prepare("
        INSERT INTO transactions
        (date, voucher_id, voucher_type, voucher_number, narration, cost_centre_id, status, created_by)
        VALUES (?, ?, 'purchase', ?, ?, ?, 'active', ?)
    ");
    $stmt->execute([
        $p['purchase_date'],
        (int)$p['id'],
        $p['purchase_number'],
        'Purchase ' . $p['purchase_number'],
        $p['cost_centre_id'] !== null ? (int)$p['cost_centre_id'] : null,
        $p['created_by'] !== null ? (int)$p['created_by'] : null,
    ]);
    $txId = (int)$conn->lastInsertId();

    $detailStmt = $conn->prepare("
        INSERT INTO transaction_details
        (transaction_id, ledger_id, currency_id, rate_of_exchange, amount, amount_base, dr_cr)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    // DR Purchase Account (goods value)
    $detailStmt->execute([
        $txId, $sys['purchase_ledger_id'], $p['currency_id'], $rate,
        $totalAmount, $totalAmount * $rate, 'DR'
    ]);

    // DR VAT (only if any)
    if ($vatAmount > 0) {
        $detailStmt->execute([
            $txId, $sys['vat_ledger_id'], $p['currency_id'], $rate,
            $vatAmount, $vatAmount * $rate, 'DR'
        ]);
    }

    // CR Supplier (full payable)
    $detailStmt->execute([
        $txId, (int)$p['supplier_ledger_id'], $p['currency_id'], $rate,
        $payable, $payable * $rate, 'CR'
    ]);

    return $txId;
}

/**
 * Create transaction + transaction_details rows for a purchase import.
 * Caller is responsible for the surrounding DB transaction.
 *
 *   DR Purchase Account           total_fob_amount
 *   DR VAT                        vat_amount             (skipped if 0)
 *   DR <Bucket A cost ledger>     each row's amount      (one DR per row)
 *   CR Supplier Ledger            sum of all of the above
 *
 * All amounts are in the import's header currency. amount_base uses the
 * header's exchange_rate_to_base. Bucket B costs are NOT posted here — each
 * Bucket B row gets its own journal voucher (handled separately).
 *
 * Idempotent: caller should deleteVoucherTransaction($conn, 'purchase_import',
 * $importId) before calling this on update.
 */
function createImportTransaction($conn, $importId) {
    $stmt = $conn->prepare("
        SELECT pi.id, pi.import_number, pi.import_date, pi.total_fob_amount,
               pi.vat_amount, pi.currency_id, pi.exchange_rate_to_base,
               pi.cost_centre_id, pi.created_by,
               s.ledger_account_id AS supplier_ledger_id
        FROM purchase_imports pi
        JOIN suppliers s ON pi.supplier_id = s.id
        WHERE pi.id = ?
    ");
    $stmt->execute([(int)$importId]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$p) {
        throw new Exception('Purchase import not found for transaction logging: ' . $importId);
    }
    if (empty($p['supplier_ledger_id'])) {
        throw new Exception('Supplier has no linked ledger account (import id ' . $importId . ')');
    }

    // Bucket A rows: each must have a ledger_id (otherwise skipped — happens
    // for legacy rows whose bucket was defaulted but never reassigned).
    $costsStmt = $conn->prepare("
        SELECT ledger_id, amount
        FROM purchase_import_costs
        WHERE import_id = ? AND bucket = 'A' AND ledger_id IS NOT NULL
        ORDER BY id
    ");
    $costsStmt->execute([(int)$importId]);
    $bucketARows = $costsStmt->fetchAll(PDO::FETCH_ASSOC);

    $sys       = ensureSystemLedgers($conn);
    $totalFob  = (float)$p['total_fob_amount'];
    $vatAmount = (float)$p['vat_amount'];
    $rate      = (float)$p['exchange_rate_to_base'];
    if ($rate <= 0) { $rate = 1.0; }

    $totalBucketA = 0;
    foreach ($bucketARows as $row) {
        $totalBucketA += (float)$row['amount'];
    }
    $payable = $totalFob + $vatAmount + $totalBucketA;

    $stmt = $conn->prepare("
        INSERT INTO transactions
        (date, voucher_id, voucher_type, voucher_number, narration, cost_centre_id, status, created_by)
        VALUES (?, ?, 'purchase_import', ?, ?, ?, 'active', ?)
    ");
    $stmt->execute([
        $p['import_date'],
        (int)$p['id'],
        $p['import_number'],
        'Purchase Import ' . $p['import_number'],
        $p['cost_centre_id'] !== null ? (int)$p['cost_centre_id'] : null,
        $p['created_by'] !== null ? (int)$p['created_by'] : null,
    ]);
    $txId = (int)$conn->lastInsertId();

    $detailStmt = $conn->prepare("
        INSERT INTO transaction_details
        (transaction_id, ledger_id, currency_id, rate_of_exchange, amount, amount_base, dr_cr)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    // DR Purchase Account (FOB goods value)
    $detailStmt->execute([
        $txId, $sys['purchase_ledger_id'], $p['currency_id'], $rate,
        $totalFob, $totalFob * $rate, 'DR'
    ]);

    // DR VAT (only if non-zero)
    if ($vatAmount > 0) {
        $detailStmt->execute([
            $txId, $sys['vat_ledger_id'], $p['currency_id'], $rate,
            $vatAmount, $vatAmount * $rate, 'DR'
        ]);
    }

    // DR each Bucket A cost ledger
    foreach ($bucketARows as $row) {
        $amount = (float)$row['amount'];
        if ($amount <= 0) continue;
        $detailStmt->execute([
            $txId, (int)$row['ledger_id'], $p['currency_id'], $rate,
            $amount, $amount * $rate, 'DR'
        ]);
    }

    // CR Supplier (full payable to the import's supplier)
    $detailStmt->execute([
        $txId, (int)$p['supplier_ledger_id'], $p['currency_id'], $rate,
        $payable, $payable * $rate, 'CR'
    ]);

    return $txId;
}

/**
 * Delete every child voucher that points at a parent via source_voucher_*.
 * Used to cascade-cleanup Bucket B journal vouchers when the parent import
 * is updated or deleted. transaction_details get removed via FK CASCADE.
 */
function deleteChildVouchers($conn, $sourceVoucherType, $sourceVoucherId) {
    $stmt = $conn->prepare("
        DELETE FROM transactions
        WHERE source_voucher_type = ? AND source_voucher_id = ?
    ");
    $stmt->execute([$sourceVoucherType, (int)$sourceVoucherId]);
}

/**
 * Create one journal voucher per Bucket B (third-party) AND Bucket C
 * ("Other Costs — not added to costing") cost line on an import. Both buckets
 * post identical JVs; the only difference is that Bucket C is excluded from
 * the costing factor / landed cost (handled in the sync API totals, not here).
 * Each JV has 2 or 3 transaction_details rows:
 *
 *   DR <expense/duty ledger>         row.amount   (line currency)
 *   DR <VAT system ledger>           row.vat_amount   (skipped if 0)
 *   CR <beneficiary supplier ledger> row.amount + row.vat_amount
 *
 * Each row uses the cost line's own currency + FX rate; amount_base = amount
 * × fx. Journal vouchers are linked back to the parent import via
 * source_voucher_type='purchase_import' + source_voucher_id, so they can be
 * cascade-cleaned and listed under their parent in the Journal Vouchers
 * screen.
 *
 * Legacy Bucket B rows (no ledger_id OR no beneficiary_supplier_id) are
 * skipped — they can't be posted until the user reassigns them.
 *
 * Caller is responsible for the surrounding DB transaction. Returns the
 * created transaction IDs.
 */
function createImportJournalVouchers($conn, $importId) {
    $hdr = $conn->prepare("
        SELECT import_date, cost_centre_id, created_by, import_number
        FROM purchase_imports
        WHERE id = ?
    ");
    $hdr->execute([(int)$importId]);
    $h = $hdr->fetch(PDO::FETCH_ASSOC);
    if (!$h) {
        throw new Exception('Purchase import not found for JV logging: ' . $importId);
    }

    $stmt = $conn->prepare("
        SELECT pic.id, pic.ledger_id, pic.amount, pic.beneficiary_supplier_id,
               pic.currency_id, pic.exchange_rate_to_base, pic.vat_amount,
               s.ledger_account_id AS beneficiary_ledger_id,
               la.name  AS ledger_name,
               bla.name AS beneficiary_name
        FROM purchase_import_costs pic
        LEFT JOIN suppliers       s   ON pic.beneficiary_supplier_id = s.id
        LEFT JOIN ledger_accounts la  ON pic.ledger_id = la.id
        LEFT JOIN ledger_accounts bla ON s.ledger_account_id = bla.id
        WHERE pic.import_id = ?
          AND pic.bucket IN ('B','C')
          AND pic.ledger_id IS NOT NULL
          AND pic.beneficiary_supplier_id IS NOT NULL
        ORDER BY pic.id
    ");
    $stmt->execute([(int)$importId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) return [];

    $sys = ensureSystemLedgers($conn);

    $insertTx = $conn->prepare("
        INSERT INTO transactions
            (date, voucher_id, voucher_type, voucher_number, narration,
             source_voucher_type, source_voucher_id,
             cost_centre_id, status, created_by)
        VALUES (?, ?, 'journal', ?, ?, 'purchase_import', ?, ?, 'active', ?)
    ");

    $insertDetail = $conn->prepare("
        INSERT INTO transaction_details
            (transaction_id, ledger_id, currency_id, rate_of_exchange, amount, amount_base, dr_cr)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $createdIds = [];
    foreach ($rows as $r) {
        if (empty($r['beneficiary_ledger_id'])) {
            throw new Exception("Beneficiary supplier '" . ($r['beneficiary_name'] ?? '?')
                . "' has no linked ledger account (import id " . $importId
                . ", cost line " . $r['id'] . "). Fix in Masters → Suppliers first.");
        }

        $amount     = (float)$r['amount'];
        $vatAmount  = (float)($r['vat_amount'] ?? 0);
        $fx         = (float)($r['exchange_rate_to_base'] ?? 1);
        if ($fx <= 0) { $fx = 1.0; }
        $currencyId = $r['currency_id'] ?? null;
        $payable    = $amount + $vatAmount;

        $jvNumber = getNextNumber('transactions', 'voucher_number', 'JV');
        $narration = sprintf(
            '%s — via Import %s%s',
            $r['ledger_name'] ?? 'Third-party cost',
            $h['import_number'],
            $r['beneficiary_name'] ? ' (paid to ' . $r['beneficiary_name'] . ')' : ''
        );

        $insertTx->execute([
            $h['import_date'],
            (int)$r['id'],
            $jvNumber,
            $narration,
            (int)$importId,
            $h['cost_centre_id'] !== null ? (int)$h['cost_centre_id'] : null,
            $h['created_by']     !== null ? (int)$h['created_by']     : null,
        ]);
        $txId = (int)$conn->lastInsertId();

        // DR <expense/duty ledger>
        $insertDetail->execute([
            $txId, (int)$r['ledger_id'], $currencyId, $fx,
            $amount, $amount * $fx, 'DR'
        ]);

        // DR <VAT system ledger> (skip if 0)
        if ($vatAmount > 0) {
            $insertDetail->execute([
                $txId, $sys['vat_ledger_id'], $currencyId, $fx,
                $vatAmount, $vatAmount * $fx, 'DR'
            ]);
        }

        // CR <beneficiary supplier ledger> = amount + vat
        $insertDetail->execute([
            $txId, (int)$r['beneficiary_ledger_id'], $currencyId, $fx,
            $payable, $payable * $fx, 'CR'
        ]);

        $createdIds[] = $txId;
    }

    return $createdIds;
}

/**
 * Create transaction + transaction_details rows for a payment voucher.
 *
 *   DR <to_account_id>     amount   (supplier liability decreases, or expense
 *                                    debit increases — same DR direction)
 *   CR <from_account_id>   amount   (cash/bank balance decreases)
 *
 * Currency + FX are taken from the payment row. amount_base = amount × rate.
 *
 * Caller is responsible for the surrounding DB transaction. Idempotent if
 * caller invokes deleteVoucherTransaction($conn, 'payment', $id) first.
 */
function createPaymentTransaction($conn, $paymentId) {
    $stmt = $conn->prepare("
        SELECT id, payment_number, payment_date, from_account_id, to_account_id,
               amount, currency_id, exchange_rate_to_base, cost_centre_id, created_by
        FROM payments
        WHERE id = ?
    ");
    $stmt->execute([(int)$paymentId]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$p) {
        throw new Exception('Payment not found for transaction logging: ' . $paymentId);
    }

    $amount = (float)$p['amount'];
    $rate   = (float)($p['exchange_rate_to_base'] ?? 1);
    if ($rate <= 0) { $rate = 1.0; }

    $stmt = $conn->prepare("
        INSERT INTO transactions
            (date, voucher_id, voucher_type, voucher_number, narration,
             cost_centre_id, status, created_by)
        VALUES (?, ?, 'payment', ?, ?, ?, 'active', ?)
    ");
    $stmt->execute([
        $p['payment_date'],
        (int)$p['id'],
        $p['payment_number'],
        'Payment ' . $p['payment_number'],
        $p['cost_centre_id'] !== null ? (int)$p['cost_centre_id'] : null,
        $p['created_by']    !== null ? (int)$p['created_by']    : null,
    ]);
    $txId = (int)$conn->lastInsertId();

    $detailStmt = $conn->prepare("
        INSERT INTO transaction_details
            (transaction_id, ledger_id, currency_id, rate_of_exchange, amount, amount_base, dr_cr)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    // DR to_account (recipient: supplier / expense ledger).
    $detailStmt->execute([
        $txId, (int)$p['to_account_id'], $p['currency_id'], $rate,
        $amount, $amount * $rate, 'DR'
    ]);

    // CR from_account (payer: cash / bank ledger).
    $detailStmt->execute([
        $txId, (int)$p['from_account_id'], $p['currency_id'], $rate,
        $amount, $amount * $rate, 'CR'
    ]);

    return $txId;
}

/**
 * Create transaction + transaction_details rows for a receipt voucher.
 *
 *   DR <to_account_id>     amount   (cash/bank balance increases)
 *   CR <from_account_id>   amount   (customer receivable decreases — customer
 *                                    is DR-normal, CR brings it down)
 *
 * Mirror of createPaymentTransaction, just with the receipt's role.
 * Caller is responsible for the surrounding DB transaction.
 */
function createReceiptTransaction($conn, $receiptId) {
    $stmt = $conn->prepare("
        SELECT id, receipt_number, receipt_date, from_account_id, to_account_id,
               amount, currency_id, exchange_rate_to_base, cost_centre_id, created_by
        FROM receipts
        WHERE id = ?
    ");
    $stmt->execute([(int)$receiptId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        throw new Exception('Receipt not found for transaction logging: ' . $receiptId);
    }

    $amount = (float)$r['amount'];
    $rate   = (float)($r['exchange_rate_to_base'] ?? 1);
    if ($rate <= 0) { $rate = 1.0; }

    $stmt = $conn->prepare("
        INSERT INTO transactions
            (date, voucher_id, voucher_type, voucher_number, narration,
             cost_centre_id, status, created_by)
        VALUES (?, ?, 'receipt', ?, ?, ?, 'active', ?)
    ");
    $stmt->execute([
        $r['receipt_date'],
        (int)$r['id'],
        $r['receipt_number'],
        'Receipt ' . $r['receipt_number'],
        $r['cost_centre_id'] !== null ? (int)$r['cost_centre_id'] : null,
        $r['created_by']    !== null ? (int)$r['created_by']    : null,
    ]);
    $txId = (int)$conn->lastInsertId();

    $detailStmt = $conn->prepare("
        INSERT INTO transaction_details
            (transaction_id, ledger_id, currency_id, rate_of_exchange, amount, amount_base, dr_cr)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    // DR to_account (recipient: cash/bank ledger).
    $detailStmt->execute([
        $txId, (int)$r['to_account_id'], $r['currency_id'], $rate,
        $amount, $amount * $rate, 'DR'
    ]);

    // CR from_account (source: customer ledger).
    $detailStmt->execute([
        $txId, (int)$r['from_account_id'], $r['currency_id'], $rate,
        $amount, $amount * $rate, 'CR'
    ]);

    return $txId;
}

/**
 * Create transaction + transaction_details rows for a sale.
 *
 * The sale's GL effect is ALWAYS the same regardless of payment_mode:
 *
 *   DR <customer's ledger>     total_amount
 *   CR <Sales Account ledger>  total_amount
 *
 * For cash/card sales, sales-sync.php also auto-creates a `receipts` row;
 * that receipt's GL effect (DR Cash / CR Customer) is posted separately via
 * createReceiptTransaction(). The two together net to DR Cash / CR Sales for
 * cash sales, with the customer leg cancelling out. This keeps each voucher
 * self-consistent: editing or deleting either one independently behaves
 * predictably.
 *
 * Caller is responsible for the surrounding DB transaction. Idempotent if
 * caller invokes deleteVoucherTransaction($conn, 'sales', $id) first.
 */
function createSalesTransaction($conn, $saleId) {
    $stmt = $conn->prepare("
        SELECT s.id, s.sale_number, s.sale_date, s.customer_id, s.total_amount,
               s.currency_id, s.exchange_rate_to_base,
               s.cost_centre_id, s.created_by,
               c.ledger_account_id AS customer_ledger_id
        FROM sales s
        LEFT JOIN customers c ON s.customer_id = c.id
        WHERE s.id = ?
    ");
    $stmt->execute([(int)$saleId]);
    $s = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$s) {
        throw new Exception('Sale not found for transaction logging: ' . $saleId);
    }

    $customerLedger = (int)$s['customer_ledger_id'];
    if ($customerLedger <= 0) {
        throw new Exception('Customer has no linked ledger account (sale id ' . $saleId
            . '). Fix in Masters → Customers first.');
    }

    $sys   = ensureSystemLedgers($conn);
    $total = (float)$s['total_amount'];
    $rate  = (float)($s['exchange_rate_to_base'] ?? 1);
    if ($rate <= 0) { $rate = 1.0; }

    $stmt = $conn->prepare("
        INSERT INTO transactions
            (date, voucher_id, voucher_type, voucher_number, narration,
             cost_centre_id, status, created_by)
        VALUES (?, ?, 'sales', ?, ?, ?, 'active', ?)
    ");
    $stmt->execute([
        $s['sale_date'],
        (int)$s['id'],
        $s['sale_number'],
        'Sale ' . $s['sale_number'],
        $s['cost_centre_id'] !== null ? (int)$s['cost_centre_id'] : null,
        $s['created_by']    !== null ? (int)$s['created_by']    : null,
    ]);
    $txId = (int)$conn->lastInsertId();

    $detailStmt = $conn->prepare("
        INSERT INTO transaction_details
            (transaction_id, ledger_id, currency_id, rate_of_exchange, amount, amount_base, dr_cr)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    // DR: customer's receivable ledger.
    $detailStmt->execute([
        $txId, $customerLedger, $s['currency_id'], $rate,
        $total, $total * $rate, 'DR'
    ]);

    // CR: Sales Account (system ledger).
    $detailStmt->execute([
        $txId, $sys['sales_ledger_id'], $s['currency_id'], $rate,
        $total, $total * $rate, 'CR'
    ]);

    return $txId;
}

/**
 * The signature a user has on file in the users master (migration 0041).
 *
 * Returns a base64 PNG data URL, or null when the user has not uploaded one.
 * Documents still store their OWN copy of whatever was signed — this is only
 * the source a snapshot is taken from when nothing was drawn on the pad, so
 * changing a user's signature never rewrites already-signed documents.
 *
 * Guards tenants that have not run migration 0041: the missing column throws,
 * we return null, and every signature pad behaves exactly as it did before.
 *
 * @param PDO      $conn
 * @param int|null $userId Defaults to the logged-in user.
 * @return string|null
 */
function getUserSignature($conn, $userId = null) {
    static $cache = [];

    $userId = (int)($userId ?? getUserId());
    if ($userId <= 0) {
        return null;
    }
    if (array_key_exists($userId, $cache)) {
        return $cache[$userId];
    }

    $sig = null;
    try {
        $stmt = $conn->prepare("SELECT signature FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $value = $stmt->fetchColumn();
        if (is_string($value) && $value !== '') {
            $sig = $value;
        }
    } catch (PDOException $e) {
        // Column not there yet (pre-0041 tenant) — no master signature.
        $sig = null;
    }

    $cache[$userId] = $sig;
    return $sig;
}

/**
 * Validate a signature data URL — the shared rule every signature slot uses.
 *
 * Mirrors the per-file copies that already live in api/transfers-sync.php,
 * api/requisition-vouchers-sync.php, api/budgets-sync.php,
 * api/purchase-orders-sync.php and ajax/stock_taking_sign.php. Those are left
 * alone deliberately; this one is for new code (and for users_form.php).
 *
 * @return string|null The value when it is a valid data URL, null otherwise.
 */
function sanitizeSignatureValue($value) {
    if (!is_string($value) || $value === '') {
        return null;
    }
    if (!preg_match('#^data:image/(png|jpeg|jpg);base64,[A-Za-z0-9+/=]+$#', $value)) {
        return null;
    }
    if (strlen($value) > 2 * 1024 * 1024) {
        return null;
    }
    return $value;
}

/**
 * Turn an uploaded signature image into the base64 PNG data URL the rest of the
 * app already stores (see migration 0041).
 *
 * MOVED here from modules/masters/users_form.php (where it was `usersig_processUpload`)
 * so the admin users form and the self-service "My Signature" page share one
 * validator instead of drifting apart. Behaviour is unchanged.
 *
 * Uploads are downscaled so a phone photo of a signed sheet doesn't land in the
 * DB at full resolution — every document that gets signed takes a COPY of this
 * string, so keeping it small matters.
 *
 * @return array [dataUrl|null, error|null] — both null means "no file sent".
 */
function processSignatureUpload($file) {
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [null, 'Signature upload failed (error code ' . (int)$file['error'] . ')'];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return [null, 'Invalid signature upload'];
    }
    if ($file['size'] > 1024 * 1024) {
        return [null, 'Signature image must be 1 MB or smaller'];
    }

    // Trust the bytes, not the extension.
    $info = @getimagesize($file['tmp_name']);
    if ($info === false || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
        return [null, 'Signature must be a PNG or JPG image'];
    }

    $maxWidth = 600;
    $dataUrl  = null;

    if (function_exists('imagecreatefromstring')) {
        $src = @imagecreatefromstring(file_get_contents($file['tmp_name']));
        if ($src !== false) {
            $w = imagesx($src);
            $h = imagesy($src);
            if ($w > $maxWidth) {
                $newH = max(1, (int)round($h * ($maxWidth / $w)));
                $dst  = imagecreatetruecolor($maxWidth, $newH);
                // Keep a transparent background transparent — signatures are
                // usually cut-outs and a black box would be useless on a print.
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                imagefilledrectangle($dst, 0, 0, $maxWidth, $newH, imagecolorallocatealpha($dst, 255, 255, 255, 127));
                imagecopyresampled($dst, $src, 0, 0, 0, 0, $maxWidth, $newH, $w, $h);
                imagedestroy($src);
                $src = $dst;
            } else {
                imagealphablending($src, false);
                imagesavealpha($src, true);
            }
            ob_start();
            $ok  = imagepng($src);
            $png = ob_get_clean();
            imagedestroy($src);
            if ($ok && $png !== '') {
                $dataUrl = 'data:image/png;base64,' . base64_encode($png);
            }
        }
    }

    if ($dataUrl === null) {
        // No GD (or it choked) — keep the original bytes as-is.
        $mime    = ($info[2] === IMAGETYPE_PNG) ? 'image/png' : 'image/jpeg';
        $dataUrl = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($file['tmp_name']));
    }

    // Same ceiling every signature column is validated against.
    if (sanitizeSignatureValue($dataUrl) === null) {
        return [null, 'Signature image could not be processed — try a smaller PNG or JPG'];
    }

    return [$dataUrl, null];
}

/**
 * Write a signature to a user's own master record (migration 0041).
 *
 * Shared by the self-service page and the "save to my profile" checkbox on the
 * signing screens. Always scoped to one user id by the caller; the PDOException
 * catch keeps pre-0041 tenants working (nothing to store, so nothing breaks).
 *
 * @param  string|null $value data URL, or null to clear
 * @return bool        true when the column exists and the write went through
 */
function saveUserSignature($conn, $userId, $value) {
    $userId = (int)$userId;
    if ($userId <= 0) {
        return false;
    }

    try {
        $stmt = $conn->prepare("UPDATE users SET signature = ? WHERE id = ?");
        $stmt->execute([$value, $userId]);
        return true;
    } catch (PDOException $e) {
        // No signature column on this tenant — nothing to store.
        return false;
    }
}

/**
 * Is the consumption_voucher_batches table (migration 0040) present?
 *
 * Guards tenants that have not run the migration yet: without the table we skip
 * batch tracking and the consumption module behaves exactly as it did before
 * (stock restored as a NEW batch rather than onto the original ones).
 */
function cvBatchTrackingAvailable($conn) {
    static $available = null;
    if ($available === null) {
        try {
            $conn->query("SELECT 1 FROM consumption_voucher_batches LIMIT 1");
            $available = true;
        } catch (PDOException $e) {
            $available = false;
        }
    }
    return $available;
}

/**
 * Reverse a consumption voucher's stock effect, leaving its detail rows alone.
 * Shared by the voucher's edit path and its delete path.
 *
 * Precise path (vouchers with recorded batches): put each quantity back on the
 * batch it came from, preserving FIFO order and cost layering.
 *
 * Fallback (vouchers created before migration 0040, or tenants without the
 * table): the module's original behaviour — add the quantity back as a NEW
 * batch dated today at the stored weighted rate.
 */
function cvRestoreStock($conn, $voucherId, $locationId) {
    $restored = false;

    if (cvBatchTrackingAvailable($conn)) {
        $linksStmt = $conn->prepare("
            SELECT batch_id, quantity FROM consumption_voucher_batches WHERE voucher_id = ?
        ");
        $linksStmt->execute([$voucherId]);
        $links = $linksStmt->fetchAll();

        if (!empty($links)) {
            $restoreStmt = $conn->prepare("
                UPDATE stock_batches
                SET quantity_remaining = quantity_remaining + ?
                WHERE id = ?
            ");
            foreach ($links as $link) {
                $restoreStmt->execute([$link['quantity'], $link['batch_id']]);
            }
            $conn->prepare("DELETE FROM consumption_voucher_batches WHERE voucher_id = ?")
                 ->execute([$voucherId]);
            $restored = true;
        }
    }

    if (!$restored) {
        $detailsStmt = $conn->prepare("
            SELECT product_id, quantity, rate_per_unit
            FROM consumption_voucher_details WHERE voucher_id = ?
        ");
        $detailsStmt->execute([$voucherId]);

        $restoreBatchStmt = $conn->prepare("
            INSERT INTO stock_batches
            (location_id, product_id, batch_date, transaction_type, transaction_id, quantity_in, quantity_remaining, rate_per_unit)
            VALUES (?, ?, CURDATE(), 'consumption', NULL, ?, ?, ?)
        ");
        foreach ($detailsStmt->fetchAll() as $detail) {
            $restoreBatchStmt->execute([
                $locationId, $detail['product_id'],
                $detail['quantity'], $detail['quantity'], $detail['rate_per_unit']
            ]);
        }
    }
}

/* ---------------------------------------------------------------------------
 * Consumption voucher RETURNS (migration 0047)
 *
 * A return is an ordinary consumption voucher carrying NEGATIVE quantities and
 * flagged with return_of_voucher_id. Each of its detail rows points at the
 * original line through return_of_detail_id, and each of its batch links holds
 * a NEGATIVE quantity against the batch the stock originally came from.
 *
 * That sign convention is what lets the rest of the app stay untouched: the
 * eight report queries that aggregate consumption are plain SUM(quantity), so
 * returns net out automatically, and cvRestoreStock() reverses a deleted return
 * correctly because adding a negative link quantity subtracts the stock it had
 * given back.
 * ------------------------------------------------------------------------- */

/**
 * Whether this tenant has run migration 0047. Guarded so a tenant that has not
 * simply never offers returns, rather than erroring on every voucher page.
 *
 * @param PDO $conn Database connection
 * @return bool
 */
function cvReturnsAvailable($conn) {
    static $available = null;
    if ($available === null) {
        try {
            $conn->query("SELECT return_of_voucher_id FROM consumption_vouchers LIMIT 1");
            $conn->query("SELECT return_of_detail_id FROM consumption_voucher_details LIMIT 1");
            $available = true;
        } catch (Throwable $e) {
            $available = false;
        }
    }
    return $available;
}

/**
 * How many returns exist against each of these vouchers.
 *
 * Batched so a list page resolves a whole screen in ONE query instead of one
 * per row, the same reasoning as requisitionProcurementBlockers().
 *
 * @param PDO   $conn       Database connection
 * @param array $voucherIds Consumption voucher IDs
 * @return array Map of voucher_id => count of returns raised against it
 */
function cvReturnCounts($conn, array $voucherIds) {
    $ids = array_values(array_unique(array_filter(array_map('intval', $voucherIds))));
    if (empty($ids) || !cvReturnsAvailable($conn)) {
        return [];
    }

    $out = [];
    try {
        $stmt = $conn->query("
            SELECT return_of_voucher_id AS parent_id, COUNT(*) AS cnt
            FROM consumption_vouchers
            WHERE return_of_voucher_id IN (" . implode(',', $ids) . ")
            GROUP BY return_of_voucher_id
        ");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int)$row['parent_id']] = (int)$row['cnt'];
        }
    } catch (Throwable $e) {
        // Treated as "no returns" rather than breaking the page.
    }
    return $out;
}

/**
 * Whether anything has been returned against this one voucher.
 *
 * @param PDO $conn      Database connection
 * @param int $voucherId Consumption voucher ID
 * @return bool
 */
function cvHasReturns($conn, $voucherId) {
    $counts = cvReturnCounts($conn, [$voucherId]);
    return !empty($counts[(int)$voucherId]);
}

/**
 * Whether the current user may edit or delete this consumption voucher.
 *
 * The ONLY place that decides, so the list, the view and the form can never
 * disagree about who sees a pencil. Two things lock a voucher:
 *
 *  - it has returns against it. Deleting or editing it would restore the FULL
 *    issued quantity on top of what has already come back, leaving more in
 *    stock than ever went out;
 *  - it IS a return. Re-deriving the batch give-back on an edit is a second
 *    copy of the hardest logic in the feature for no real gain, so a wrong
 *    return is deleted and raised again.
 *
 * @param PDO       $conn       Database connection
 * @param array     $voucher    Voucher row (needs id, and return_of_voucher_id when present)
 * @param bool|null $hasReturns Pre-fetched flag, to avoid a re-query on list pages
 * @return bool
 */
function cvCanEdit($conn, $voucher, $hasReturns = null) {
    if (empty($voucher) || !is_array($voucher)) {
        return false;
    }
    if (!empty($voucher['return_of_voucher_id'])) {
        return false;
    }
    if ($hasReturns === null) {
        $hasReturns = cvHasReturns($conn, isset($voucher['id']) ? $voucher['id'] : 0);
    }
    return !$hasReturns;
}

/**
 * Whether the current user may DELETE this consumption voucher.
 *
 * Deliberately NOT the same rule as cvCanEdit(). A return voucher cannot be
 * edited - re-deriving its batch give-back would be a second copy of the
 * hardest logic in the feature - but it MUST stay deletable, because deleting
 * and raising another is the only way to correct one. cvRestoreStock() undoes
 * it correctly: the return holds negative batch links, so adding them back
 * subtracts the stock it had given.
 *
 * What blocks a delete is the same thing that blocks an edit: a voucher that
 * has been returned against. Restoring its full issued quantity on top of what
 * already came back would leave more in stock than ever went out.
 *
 * @param PDO       $conn       Database connection
 * @param array     $voucher    Voucher row
 * @param bool|null $hasReturns Pre-fetched flag, to avoid a re-query on list pages
 * @return bool
 */
function cvCanDelete($conn, $voucher, $hasReturns = null) {
    if (empty($voucher) || !is_array($voucher)) {
        return false;
    }
    if ($hasReturns === null) {
        $hasReturns = cvHasReturns($conn, isset($voucher['id']) ? $voucher['id'] : 0);
    }
    return !$hasReturns;
}

/**
 * Quantity already returned against each line of a voucher.
 *
 * Return lines carry negative quantities, so the sum is negated to give a
 * positive "already returned" figure.
 *
 * @param PDO $conn      Database connection
 * @param int $voucherId Original consumption voucher ID
 * @return array Map of original detail_id => quantity already returned (positive)
 */
function cvReturnedQuantities($conn, $voucherId) {
    $voucherId = (int)$voucherId;
    if ($voucherId <= 0 || !cvReturnsAvailable($conn)) {
        return [];
    }

    $out = [];
    try {
        $stmt = $conn->prepare("
            SELECT rd.return_of_detail_id AS detail_id, COALESCE(SUM(-rd.quantity), 0) AS returned
            FROM consumption_voucher_details rd
            JOIN consumption_voucher_details od ON od.id = rd.return_of_detail_id
            WHERE od.voucher_id = ?
            GROUP BY rd.return_of_detail_id
        ");
        $stmt->execute([$voucherId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int)$row['detail_id']] = (float)$row['returned'];
        }
    } catch (Throwable $e) {
        // No returns recorded.
    }
    return $out;
}

/**
 * Per-batch quantity still available to return, for every line of a voucher.
 *
 * `taken` is what the original consumption drew from that batch; `given_back`
 * is what previous returns have already put back on it. Ordered by link id
 * DESCENDING, i.e. REVERSE order of consumption: the most recently drawn layer
 * is given back first, so the consumption that remains still sits on the
 * earliest layers.
 *
 * @param PDO $conn      Database connection
 * @param int $voucherId Original consumption voucher ID
 * @return array Map of detail_id => list of ['batch_id','rate','available']
 */
function cvReturnableBatches($conn, $voucherId) {
    $voucherId = (int)$voucherId;
    $out = [];
    if ($voucherId <= 0 || !cvBatchTrackingAvailable($conn) || !cvReturnsAvailable($conn)) {
        return $out;
    }

    try {
        $stmt = $conn->prepare("
            SELECT cvb.detail_id,
                   cvb.batch_id,
                   cvb.rate_per_unit,
                   cvb.quantity AS taken,
                   COALESCE((
                       SELECT SUM(-r.quantity)
                       FROM consumption_voucher_batches r
                       JOIN consumption_voucher_details rd ON rd.id = r.detail_id
                       WHERE rd.return_of_detail_id = cvb.detail_id
                         AND r.batch_id = cvb.batch_id
                   ), 0) AS given_back
            FROM consumption_voucher_batches cvb
            JOIN consumption_voucher_details d ON d.id = cvb.detail_id
            WHERE d.voucher_id = ?
            ORDER BY cvb.detail_id, cvb.id DESC
        ");
        $stmt->execute([$voucherId]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $available = (float)$row['taken'] - (float)$row['given_back'];
            if ($available <= 0.0000001) {
                continue;
            }
            $out[(int)$row['detail_id']][] = [
                'batch_id'  => (int)$row['batch_id'],
                'rate'      => (float)$row['rate_per_unit'],
                'available' => $available,
            ];
        }
    } catch (Throwable $e) {
        // Falls back to the new-batch path in the caller.
    }
    return $out;
}

/**
 * BOM / BOQ forms post their lines packed into ONE field, `items_json`.
 * Sent as separate fields a 185-line BOM is 1,117 inputs, and PHP silently
 * DISCARDS everything past max_input_vars (1000 by default) — the tail lines
 * vanished and the line at the cut lost its breakdown. Unpacks into the same
 * parallel arrays the per-field form sends, so the save code is unchanged.
 * Returns null when the field is absent, i.e. fall back to the plain fields.
 */
function unpackBomBoqItems($json) {
    if (!is_string($json) || $json === '') return null;
    $lines = json_decode($json, true);
    if (!is_array($lines)) return null;

    $s = function ($v) { return is_scalar($v) ? (string)$v : ''; };
    $out = ['products' => [], 'total_quantity' => [], 'rate' => [], 'item_remarks' => [],
            'breakdown_label' => [], 'breakdown_qty' => []];
    foreach (array_values($lines) as $i => $l) {
        if (!is_array($l)) continue;
        $out['products'][$i] = $s($l['product'] ?? '');
        $out['total_quantity'][$i] = $s($l['total'] ?? '');
        $out['rate'][$i] = $s($l['rate'] ?? '');
        $out['item_remarks'][$i] = $s($l['remark'] ?? '');
        $out['breakdown_label'][$i] = [];
        $out['breakdown_qty'][$i] = [];
        foreach ((is_array($l['breakdowns'] ?? null) ? $l['breakdowns'] : []) as $b) {
            if (!is_array($b)) continue;
            $out['breakdown_label'][$i][] = $s($b['label'] ?? '');
            $out['breakdown_qty'][$i][] = $s($b['qty'] ?? '');
        }
    }
    return $out;
}

/**
 * True when PHP cut this POST short at max_input_vars. PHP only raises a
 * startup warning and hands the script whatever it kept, so a form that hit
 * the limit must be refused rather than half-saved.
 */
function postInputWasTruncated() {
    $max = (int)ini_get('max_input_vars');
    if ($max <= 0) return false;
    $n = 0;
    array_walk_recursive($_POST, function () use (&$n) { $n++; });
    return $n >= $max;
}
?>
