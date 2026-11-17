<?php
/**
 * SMTP Settings save + test endpoint. Admin-only.
 *
 * Actions:
 *   - save:      Persists the smtp_* keys (and app_base_url) into the
 *                settings table. Only listed keys are touched.
 *   - send_test: Sends a test email to the currently logged-in admin
 *                using the saved settings.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/mailer.php';
require_once '../includes/email_templates.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if (getUserData('role_name') !== 'Admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admins only']);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? '';

// Allow-list of editable setting keys — anything else in payload is ignored.
$allowedKeys = [
    'smtp_enabled', 'smtp_host', 'smtp_port', 'smtp_encryption',
    'smtp_username', 'smtp_password', 'smtp_from_email', 'smtp_from_name',
    'app_base_url',
];

try {
    if ($action === 'save') {
        $data = $input['data'] ?? [];
        if (!is_array($data)) throw new Exception('Invalid payload');

        // Light validation
        if (!empty($data['smtp_from_email']) && !filter_var($data['smtp_from_email'], FILTER_VALIDATE_EMAIL)) {
            throw new Exception('From Email is not a valid email address.');
        }
        if (!empty($data['smtp_port']) && !ctype_digit((string)$data['smtp_port'])) {
            throw new Exception('Port must be numeric.');
        }
        if (!empty($data['smtp_encryption']) && !in_array($data['smtp_encryption'], ['tls','ssl','none'], true)) {
            throw new Exception('Encryption must be tls, ssl, or none.');
        }

        $conn->beginTransaction();
        try {
            // UPSERT each allowed key. We use ON DUPLICATE KEY because the
            // settings table has setting_key as UNIQUE.
            $stmt = $conn->prepare("
                INSERT INTO settings (setting_key, setting_value)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");

            foreach ($allowedKeys as $key) {
                if (!array_key_exists($key, $data)) continue;
                $value = $data[$key];
                if (is_bool($value)) $value = $value ? '1' : '0';
                if (is_array($value)) continue; // not expected
                $stmt->execute([$key, (string)$value]);
            }

            $conn->commit();
            logAudit('settings', 'update_smtp', getUserId());
            echo json_encode(['success' => true, 'message' => 'Settings saved']);
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }

    } elseif ($action === 'send_test') {
        // Resolve recipient = current user's email
        $stmt = $conn->prepare("SELECT email, name AS full_name FROM users WHERE id = ?");
        $stmt->execute([getUserId()]);
        $me = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$me || empty($me['email'])) {
            throw new Exception('Your user account has no email address on file — cannot send test.');
        }

        // Optional connection probe gives a clearer error than send()
        $probe = testSmtpConnection();
        if (!$probe['ok']) {
            throw new Exception('SMTP connection failed: ' . $probe['error']);
        }

        $html = renderSmtpTestEmail($me['full_name'] ?: 'there');
        $ok = sendEmail($me['email'], $me['full_name'] ?: null, 'FIS — SMTP Test Email', $html);

        if (!$ok) {
            throw new Exception('PHPMailer reported failure — check server error log for details.');
        }
        echo json_encode(['success' => true, 'sent_to' => $me['email']]);

    } else {
        throw new Exception('Invalid action');
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
