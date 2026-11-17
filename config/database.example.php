<?php
/**
 * Database Configuration
 * TEMPLATE. Copy to config/database.php and fill in. The real file is not in git.
 */

// Database credentials
define('DB_HOST', 'localhost');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
define('DB_NAME', 'your_db_name');
define('DB_CHARSET', 'utf8mb4');

// Timezone
date_default_timezone_set('Africa/Nairobi');

// Error reporting (set to 0 in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Session configuration
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0); // Set to 1 if using HTTPS

// Application settings
define('APP_NAME', 'FIS - LITE');
define('APP_VERSION', '1.0');
define('BASE_URL', 'https://your-domain/'); // trailing slash required
define('DEMO_MODE', true); // Set to false for production/live setups

// File upload settings
define('UPLOAD_PATH', __DIR__ . '/../uploads/');
define('EXPORT_PATH', __DIR__ . '/../exports/');
define('MAX_FILE_SIZE', 5242880); // 5MB in bytes

// Date format
define('DATE_FORMAT', 'd-m-Y');
define('DATETIME_FORMAT', 'd-m-Y h:i A');
define('DB_DATE_FORMAT', 'Y-m-d');

// Pagination
define('RECORDS_PER_PAGE', 20);

// Currency - fallback symbol used before DB is available
// The actual currency is now DB-driven via the currencies table.
// Use getDefaultCurrency() from functions.php for the live value.
define('CURRENCY_SYMBOL', 'TZS');

// Create database connection
try {
    $conn = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>
