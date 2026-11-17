<?php
/**
 * MySQL Connection Test Script
 *
 * Usage:
 * 1. Via URL with parameters:
 *    test-db-connection.php?host=localhost&db=dbname&user=username&pass=password
 *
 * 2. Edit the credentials below and access via browser:
 *    test-db-connection.php
 */

// ============================================
// EDIT THESE FOR DIRECT TESTING
// ============================================
$testHost = 'localhost';
$testDb = 'fiscotz_fabonicltd';
$testUser = 'fiscotz_fabonicl';
$testPass = '8HU]@{@C9b@A;Oxi';

// ============================================
// OR USE URL PARAMETERS
// ============================================
$host = $_GET['host'] ?? $testHost;
$db = $_GET['db'] ?? $testDb;
$user = $_GET['user'] ?? $testUser;
$pass = $_GET['pass'] ?? $testPass;

?>
<!DOCTYPE html>
<html>
<head>
    <title>MySQL Connection Test</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
        .container { max-width: 600px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1 { color: #333; margin-top: 0; }
        .success { background: #d4edda; color: #155724; padding: 15px; border-radius: 4px; border: 1px solid #c3e6cb; }
        .error { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 4px; border: 1px solid #f5c6cb; }
        .info { background: #d1ecf1; color: #0c5460; padding: 15px; border-radius: 4px; border: 1px solid #bee5eb; margin-bottom: 20px; }
        .credentials { background: #f8f9fa; padding: 15px; border-radius: 4px; margin: 15px 0; }
        .credentials strong { display: inline-block; width: 120px; }
        pre { background: #f8f9fa; padding: 10px; border-radius: 4px; overflow-x: auto; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔌 MySQL Connection Test</h1>

        <div class="info">
            <strong>How to use:</strong><br>
            • Edit credentials in this file, OR<br>
            • Use URL: ?host=localhost&db=dbname&user=username&pass=password
        </div>

        <div class="credentials">
            <strong>Host:</strong> <?php echo htmlspecialchars($host); ?><br>
            <strong>Database:</strong> <?php echo htmlspecialchars($db); ?><br>
            <strong>Username:</strong> <?php echo htmlspecialchars($user); ?><br>
            <strong>Password:</strong> <?php echo str_repeat('*', strlen($pass)); ?> (<?php echo strlen($pass); ?> chars)
        </div>

        <h2>Connection Test Results:</h2>

        <?php
        try {
            // Attempt connection
            $startTime = microtime(true);

            $pdo = new PDO(
                "mysql:host={$host};dbname={$db};charset=utf8mb4",
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 5
                ]
            );

            $endTime = microtime(true);
            $connectionTime = round(($endTime - $startTime) * 1000, 2);

            echo '<div class="success">';
            echo '<h3>✓ Connection Successful!</h3>';
            echo '<p>Connected to MySQL database in ' . $connectionTime . ' ms</p>';
            echo '</div>';

            // Get server info
            $serverVersion = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
            $driverName = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

            echo '<h3>Server Information:</h3>';
            echo '<div class="credentials">';
            echo '<strong>MySQL Version:</strong> ' . htmlspecialchars($serverVersion) . '<br>';
            echo '<strong>PDO Driver:</strong> ' . htmlspecialchars($driverName) . '<br>';
            echo '<strong>Connection Status:</strong> Connected<br>';
            echo '</div>';

            // Get database tables
            echo '<h3>Database Tables:</h3>';
            $stmt = $pdo->query("SHOW TABLES");
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (count($tables) > 0) {
                echo '<div class="credentials">';
                echo '<strong>Found ' . count($tables) . ' tables:</strong><br>';
                echo '<pre>' . implode("\n", $tables) . '</pre>';
                echo '</div>';
            } else {
                echo '<div class="info">Database exists but has no tables yet.</div>';
            }

            // Test query
            echo '<h3>Test Query:</h3>';
            $stmt = $pdo->query("SELECT DATABASE() as current_db, NOW() as current_time");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            echo '<div class="credentials">';
            echo '<strong>Current Database:</strong> ' . htmlspecialchars($result['current_db']) . '<br>';
            echo '<strong>Server Time:</strong> ' . htmlspecialchars($result['current_time']) . '<br>';
            echo '</div>';

        } catch (PDOException $e) {
            echo '<div class="error">';
            echo '<h3>✗ Connection Failed!</h3>';
            echo '<p><strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
            echo '<p><strong>Error Code:</strong> ' . $e->getCode() . '</p>';
            echo '</div>';

            echo '<h3>Common Issues:</h3>';
            echo '<div class="info">';
            echo '<strong>1. Invalid credentials:</strong> Check username and password<br>';
            echo '<strong>2. Database does not exist:</strong> Create the database first<br>';
            echo '<strong>3. User has no privileges:</strong> Grant privileges to the user<br>';
            echo '<strong>4. MySQL server not running:</strong> Start MySQL service<br>';
            echo '<strong>5. Wrong host:</strong> Try "localhost" or "127.0.0.1"<br>';
            echo '</div>';
        }
        ?>

        <h3>Quick Links:</h3>
        <div class="info">
            <a href="?host=localhost&db=test&user=root&pass=">Test with default credentials</a>
        </div>
    </div>
</body>
</html>
