<?php
/**
 * Save the logged-in user's OWN signature to their user master (migration 0041).
 *
 * Backs the "Save to my profile" checkbox on the signing screens
 * (includes/signature_upload_control.php), which needs an endpoint because
 * those screens submit JSON via fetch and cannot post a multipart form.
 * The self-service page modules/auth/my_signature.php does NOT use this — it is
 * a normal POST-to-self form.
 *
 * Actions:
 *   - save:  store the supplied data URL as this user's signature
 *   - clear: remove it
 *
 * SCOPE: the target is ALWAYS getUserId(). There is no user_id parameter, by
 * design — this endpoint can never write to somebody else's record, so it needs
 * no `users` permission and every logged-in user may call it.
 *
 * Unlike the app's other JSON sync endpoints (which are session-only) this one
 * checks a CSRF token: it writes to a user's identity record, and a signature
 * planted by a forged request would then be stamped on documents in their name.
 * The token is emitted as window.__SIG_CSRF by the shared control.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? '';

// checkCSRF() reads $_POST and die()s with plain text, which would break the
// JSON contract — compare the token from the JSON body instead.
$token = $input['csrf_token'] ?? '';
if (!is_string($token) || $token === '' || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF token validation failed']);
    exit;
}

try {
    if ($action === 'save') {
        // Same rule every signature column is validated against. The value is a
        // canvas export, so it is already downscaled to pad size.
        $signature = sanitizeSignatureValue($input['signature'] ?? null);
        if ($signature === null) {
            throw new Exception(__('signature_invalid_type'));
        }

        if (!saveUserSignature($conn, getUserId(), $signature)) {
            // Pre-0041 tenant: no column to write to.
            throw new Exception(__('signature_not_supported'));
        }

        logAudit('users', 'update_own_signature', getUserId());
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'clear') {
        if (!saveUserSignature($conn, getUserId(), null)) {
            throw new Exception(__('signature_not_supported'));
        }

        logAudit('users', 'clear_own_signature', getUserId());
        echo json_encode(['success' => true]);
        exit;
    }

    throw new Exception('Unknown action');
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
