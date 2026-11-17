<?php
/**
 * Login Debug Script
 *
 * Usage: Place this in the TENANT subdomain directory (not main app)
 * Access: https://subdomain.fis.co.tz/debug-login.php?username=admin&password=yourpassword
 */

// For testing, you can hardcode credentials here
$testUsername = $_GET['username'] ?? 'admin';
$testPassword = $_GET['password'] ?? '';

?>
<!DOCTYPE html>
<html>
<head>
    <title>Login Debug</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1 { color: #333; margin-top: 0; }
        .success { background: #d4edda; color: #155724; padding: 10px; border-radius: 4px; margin: 10px 0; }
        .error { background: #f8d7da; color: #721c24; padding: 10px; border-radius: 4px; margin: 10px 0; }
        .warning { background: #fff3cd; color: #856404; padding: 10px; border-radius: 4px; margin: 10px 0; }
        .info { background: #d1ecf1; color: #0c5460; padding: 10px; border-radius: 4px; margin: 10px 0; }
        pre { background: #f8f9fa; padding: 10px; border-radius: 4px; overflow-x: auto; }
        .section { margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 4px; }
        table { width: 100%; border-collapse: collapse; }
        table td { padding: 8px; border-bottom: 1px solid #ddd; }
        table td:first-child { font-weight: bold; width: 200px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Login Debug Tool</h1>

        <div class="info">
            <strong>How to use:</strong><br>
            URL: ?username=admin&password=yourpassword
        </div>

        <div class="section">
            <h3>Test Credentials</h3>
            <table>
                <tr>
                    <td>Username:</td>
                    <td><?php echo htmlspecialchars($testUsername); ?></td>
                </tr>
                <tr>
                    <td>Password:</td>
                    <td><?php echo $testPassword ? str_repeat('*', strlen($testPassword)) . ' (' . strlen($testPassword) . ' chars)' : '<span style="color: red;">NOT PROVIDED</span>'; ?></td>
                </tr>
            </table>
        </div>

        <?php
        // Step 1: Check if config file exists
        echo '<div class="section">';
        echo '<h3>Step 1: Check Config File</h3>';

        $configPath = __DIR__ . '/config/database.php';
        if (file_exists($configPath)) {
            echo '<div class="success">✓ Config file found: ' . $configPath . '</div>';

            require_once $configPath;

            if (defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER') && defined('DB_PASS')) {
                echo '<div class="success">✓ Database constants defined</div>';
                echo '<table>';
                echo '<tr><td>DB_HOST:</td><td>' . htmlspecialchars(DB_HOST) . '</td></tr>';
                echo '<tr><td>DB_NAME:</td><td>' . htmlspecialchars(DB_NAME) . '</td></tr>';
                echo '<tr><td>DB_USER:</td><td>' . htmlspecialchars(DB_USER) . '</td></tr>';
                echo '<tr><td>DB_PASS:</td><td>' . str_repeat('*', strlen(DB_PASS)) . ' (' . strlen(DB_PASS) . ' chars)</td></tr>';
                echo '</table>';
            } else {
                echo '<div class="error">✗ Database constants not defined</div>';
                echo '</div></div></body></html>';
                exit;
            }
        } else {
            echo '<div class="error">✗ Config file not found: ' . $configPath . '</div>';
            echo '</div></div></body></html>';
            exit;
        }
        echo '</div>';

        // Step 2: Test database connection
        echo '<div class="section">';
        echo '<h3>Step 2: Test Database Connection</h3>';

        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            echo '<div class="success">✓ Database connection successful</div>';
        } catch (PDOException $e) {
            echo '<div class="error">✗ Database connection failed: ' . htmlspecialchars($e->getMessage()) . '</div>';
            echo '</div></div></body></html>';
            exit;
        }
        echo '</div>';

        // Step 3: Check if users table exists
        echo '<div class="section">';
        echo '<h3>Step 3: Check Users Table</h3>';

        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
            $tableExists = $stmt->fetch();

            if ($tableExists) {
                echo '<div class="success">✓ Users table exists</div>';
            } else {
                echo '<div class="error">✗ Users table not found</div>';
                echo '</div></div></body></html>';
                exit;
            }
        } catch (PDOException $e) {
            echo '<div class="error">✗ Error checking table: ' . htmlspecialchars($e->getMessage()) . '</div>';
            echo '</div></div></body></html>';
            exit;
        }
        echo '</div>';

        // Step 4: Find user by username
        echo '<div class="section">';
        echo '<h3>Step 4: Find User "' . htmlspecialchars($testUsername) . '"</h3>';

        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username");
            $stmt->execute(['username' => $testUsername]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                echo '<div class="success">✓ User found</div>';
                echo '<table>';
                echo '<tr><td>ID:</td><td>' . htmlspecialchars($user['id']) . '</td></tr>';
                echo '<tr><td>Username:</td><td>' . htmlspecialchars($user['username']) . '</td></tr>';
                echo '<tr><td>Name:</td><td>' . htmlspecialchars($user['name'] ?? 'N/A') . '</td></tr>';
                echo '<tr><td>Email:</td><td>' . htmlspecialchars($user['email'] ?? 'N/A') . '</td></tr>';
                echo '<tr><td>Role ID:</td><td>' . htmlspecialchars($user['role_id']) . '</td></tr>';
                echo '<tr><td>Is Active:</td><td>' . ($user['is_active'] ? '<span style="color: green;">Yes (1)</span>' : '<span style="color: red;">No (0)</span>') . '</td></tr>';
                echo '<tr><td>Password Hash:</td><td style="font-family: monospace; font-size: 11px;">' . htmlspecialchars(substr($user['password'], 0, 50)) . '...</td></tr>';
                echo '<tr><td>Hash Length:</td><td>' . strlen($user['password']) . ' chars</td></tr>';
                echo '<tr><td>Hash Algorithm:</td><td>' . (strpos($user['password'], '$2y$') === 0 ? 'bcrypt (PASSWORD_DEFAULT)' : 'Unknown') . '</td></tr>';
                echo '</table>';
            } else {
                echo '<div class="error">✗ User "' . htmlspecialchars($testUsername) . '" not found</div>';
                echo '<div class="info">Available users in database:</div>';

                $stmt = $pdo->query("SELECT username FROM users");
                $allUsers = $stmt->fetchAll(PDO::FETCH_COLUMN);
                echo '<pre>' . implode("\n", $allUsers) . '</pre>';

                echo '</div></div></body></html>';
                exit;
            }
        } catch (PDOException $e) {
            echo '<div class="error">✗ Error finding user: ' . htmlspecialchars($e->getMessage()) . '</div>';
            echo '</div></div></body></html>';
            exit;
        }
        echo '</div>';

        // Step 5: Check if user is active
        echo '<div class="section">';
        echo '<h3>Step 5: Check User Status</h3>';

        if (!$user['is_active']) {
            echo '<div class="error">✗ User account is INACTIVE</div>';
            echo '<p>This user cannot login because is_active = 0</p>';
        } else {
            echo '<div class="success">✓ User account is ACTIVE</div>';
        }

        if ($user['role_id'] != 1) {
            echo '<div class="warning">⚠ User role_id is ' . $user['role_id'] . ' (expected 1 for admin)</div>';
        } else {
            echo '<div class="success">✓ User has admin role (role_id = 1)</div>';
        }
        echo '</div>';

        // Step 6: Test password verification (if password provided)
        if ($testPassword) {
            echo '<div class="section">';
            echo '<h3>Step 6: Test Password Verification</h3>';

            $isValid = password_verify($testPassword, $user['password']);

            if ($isValid) {
                echo '<div class="success">';
                echo '<h3>✓ PASSWORD MATCHES!</h3>';
                echo '<p>The provided password is correct for user "' . htmlspecialchars($testUsername) . '"</p>';
                echo '<p><strong>Login should work with these credentials.</strong></p>';
                echo '</div>';
            } else {
                echo '<div class="error">';
                echo '<h3>✗ PASSWORD DOES NOT MATCH!</h3>';
                echo '<p>The provided password is INCORRECT for user "' . htmlspecialchars($testUsername) . '"</p>';
                echo '</div>';

                echo '<div class="warning">';
                echo '<h4>Debugging Information:</h4>';
                echo '<table>';
                echo '<tr><td>Password you entered:</td><td>' . htmlspecialchars($testPassword) . '</td></tr>';
                echo '<tr><td>Password length:</td><td>' . strlen($testPassword) . ' chars</td></tr>';
                echo '<tr><td>Password in database:</td><td>Hashed (cannot retrieve)</td></tr>';
                echo '</table>';
                echo '<p><strong>Possible issues:</strong></p>';
                echo '<ul>';
                echo '<li>Password in email may be different from what\'s in database</li>';
                echo '<li>Copy-paste may have added extra spaces</li>';
                echo '<li>Password may have been changed after email was sent</li>';
                echo '<li>Check provision log to see what password was generated</li>';
                echo '</ul>';
                echo '</div>';

                // Show how to manually reset password
                echo '<div class="info">';
                echo '<h4>Manual Password Reset:</h4>';
                echo '<p>To set a new password for this user, run this SQL:</p>';
                $newHash = password_hash('NewPassword123', PASSWORD_DEFAULT);
                echo '<pre>UPDATE users SET password = \'' . $newHash . '\' WHERE username = \'admin\';</pre>';
                echo '<p>Then login with username: <strong>admin</strong> and password: <strong>NewPassword123</strong></p>';
                echo '</div>';
            }
            echo '</div>';
        } else {
            echo '<div class="section">';
            echo '<h3>Step 6: Password Not Provided</h3>';
            echo '<div class="warning">⚠ Add ?password=yourpassword to URL to test password verification</div>';
            echo '</div>';
        }

        // Step 7: Check session/login system
        echo '<div class="section">';
        echo '<h3>Step 7: System Information</h3>';
        echo '<table>';
        echo '<tr><td>PHP Version:</td><td>' . phpversion() . '</td></tr>';
        echo '<tr><td>Session Support:</td><td>' . (function_exists('session_start') ? 'Yes' : 'No') . '</td></tr>';
        echo '<tr><td>PDO Support:</td><td>' . (class_exists('PDO') ? 'Yes' : 'No') . '</td></tr>';
        echo '<tr><td>password_verify():</td><td>' . (function_exists('password_verify') ? 'Yes' : 'No') . '</td></tr>';
        echo '<tr><td>Current URL:</td><td>' . htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'N/A') . '</td></tr>';
        echo '<tr><td>Document Root:</td><td>' . htmlspecialchars($_SERVER['DOCUMENT_ROOT'] ?? 'N/A') . '</td></tr>';
        echo '</table>';
        echo '</div>';
        ?>

        <div class="section">
            <h3>Summary</h3>
            <?php if ($testPassword && isset($isValid) && $isValid): ?>
                <div class="success">
                    <h3>✓ ALL CHECKS PASSED</h3>
                    <p>Login should work. If it doesn't, check:</p>
                    <ul>
                        <li>Login form is posting to correct URL</li>
                        <li>Session is being started properly</li>
                        <li>No redirect issues</li>
                    </ul>
                </div>
            <?php else: ?>
                <div class="warning">
                    <p>Review the checks above to identify the login issue.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
