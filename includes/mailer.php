<?php
/**
 * Thin PHPMailer wrapper.
 *
 * Reads SMTP credentials from the `settings` table (smtp_* keys). If SMTP
 * is disabled or credentials are blank, sendEmail() returns false silently
 * and logs to PHP error_log — the caller (notification helpers) keeps
 * working so bell notifications are unaffected.
 *
 * NOTE: This file is intentionally standalone. It does NOT replace the
 * legacy mail() call in api/send-trial-request.php (that public landing
 * form is unrelated to the in-app notification flow).
 */

require_once __DIR__ . '/../vendor/PHPMailer/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Send a single HTML email via the configured SMTP server.
 *
 * @param string      $toEmail  Recipient address
 * @param string|null $toName   Recipient display name (optional)
 * @param string      $subject  Email subject
 * @param string      $htmlBody Full HTML body
 * @param string|null $altBody  Optional plain-text fallback (auto-stripped from HTML if null)
 * @return bool true on success, false on any failure or when SMTP is disabled
 */
function sendEmail($toEmail, $toName, $subject, $htmlBody, $altBody = null) {
    // Basic validation — don't even instantiate PHPMailer for bad input.
    if (!is_string($toEmail) || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        error_log('sendEmail: invalid recipient address: ' . print_r($toEmail, true));
        return false;
    }

    // Gate: SMTP must be enabled and configured.
    $enabled   = (int)getSetting('smtp_enabled', 0);
    $host      = (string)getSetting('smtp_host', '');
    $port      = (int)getSetting('smtp_port', 587);
    $enc       = strtolower((string)getSetting('smtp_encryption', 'tls'));
    $username  = (string)getSetting('smtp_username', '');
    $password  = (string)getSetting('smtp_password', '');
    $fromEmail = (string)getSetting('smtp_from_email', '');
    $fromName  = (string)getSetting('smtp_from_name', 'FIS');

    if ($enabled !== 1 || $host === '' || $fromEmail === '') {
        // Silent skip — caller (e.g. notification helper) keeps the bell
        // notification flow intact. We log so admins can debug if needed.
        error_log('sendEmail: SMTP disabled or not fully configured; skipping send to ' . $toEmail);
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->Port       = $port > 0 ? $port : 587;
        $mail->SMTPAuth   = ($username !== '');
        if ($mail->SMTPAuth) {
            $mail->Username = $username;
            $mail->Password = $password;
        }

        if ($enc === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($enc === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        }

        $mail->CharSet  = 'UTF-8';
        $mail->Timeout  = 15;

        $mail->setFrom($fromEmail, $fromName ?: 'FIS');
        $mail->addAddress($toEmail, $toName ?: '');

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $altBody !== null ? $altBody : trim(strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $htmlBody)));

        return $mail->send();
    } catch (PHPMailerException $e) {
        error_log('sendEmail PHPMailer error to ' . $toEmail . ': ' . $mail->ErrorInfo);
        return false;
    } catch (Throwable $e) {
        error_log('sendEmail exception to ' . $toEmail . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * Best-effort SMTP connection test for the admin settings page.
 * Returns ['ok' => bool, 'error' => string|null].
 */
function testSmtpConnection() {
    $enabled = (int)getSetting('smtp_enabled', 0);
    if ($enabled !== 1) {
        return ['ok' => false, 'error' => 'SMTP is disabled in settings.'];
    }

    $host = (string)getSetting('smtp_host', '');
    $port = (int)getSetting('smtp_port', 587);
    $enc  = strtolower((string)getSetting('smtp_encryption', 'tls'));
    $user = (string)getSetting('smtp_username', '');
    $pass = (string)getSetting('smtp_password', '');

    if ($host === '') {
        return ['ok' => false, 'error' => 'SMTP host is not configured.'];
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->Port = $port > 0 ? $port : 587;
        $mail->SMTPAuth = ($user !== '');
        if ($mail->SMTPAuth) {
            $mail->Username = $user;
            $mail->Password = $pass;
        }
        if ($enc === 'ssl')      $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        elseif ($enc === 'tls')  $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        else                     { $mail->SMTPSecure = false; $mail->SMTPAutoTLS = false; }
        $mail->Timeout = 10;

        if ($mail->smtpConnect()) {
            $mail->smtpClose();
            return ['ok' => true, 'error' => null];
        }
        return ['ok' => false, 'error' => 'Connection refused.'];
    } catch (PHPMailerException $e) {
        return ['ok' => false, 'error' => $mail->ErrorInfo ?: $e->getMessage()];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
