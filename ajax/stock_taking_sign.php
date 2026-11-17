<?php
/**
 * Stock Taking E-Signature endpoint
 * Handles 'verify' and 'approve' actions on a stock taking record.
 * Each action requires the corresponding can_verify / can_approve permission.
 * On success, fires the next-stage notification via the bell icon.
 */
require_once '../config/database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not authorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? '';
$takingId = (int)($input['taking_id'] ?? 0);
$signature = $input['signature'] ?? null;

// Validate signature data URL (same rule as transfers-sync.php)
function st_sanitizeSig($value) {
    if (!is_string($value) || $value === '') return null;
    if (!preg_match('#^data:image/(png|jpeg|jpg);base64,[A-Za-z0-9+/=]+$#', $value)) return null;
    if (strlen($value) > 2 * 1024 * 1024) return null;
    return $value;
}

$sig = st_sanitizeSig($signature);

try {
    if ($takingId <= 0) {
        throw new Exception('Invalid stock taking id');
    }
    if (!in_array($action, ['verify', 'approve'], true)) {
        throw new Exception('Invalid action');
    }
    // Nothing drawn on the pad — use the signer's signature on file (0041).
    // The pad is hidden for users who have one, so this is the normal path now;
    // it stays REQUIRED for anyone who does not.
    if ($sig === null) {
        $sig = getUserSignature($conn, getUserId());
    }
    if ($sig === null) {
        throw new Exception('Signature is required');
    }

    // Permission check
    $permAction = ($action === 'verify') ? 'verify' : 'approve';
    if (!hasPermission('stock_taking', $permAction)) {
        echo json_encode(['success' => false, 'error' => 'No permission to ' . $permAction]);
        exit;
    }

    // Load the record
    $stmt = $conn->prepare("
        SELECT id, stock_taking_number, created_by, verified_by, approved_by
        FROM stock_taking
        WHERE id = ?
    ");
    $stmt->execute([$takingId]);
    $taking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$taking) {
        throw new Exception('Stock taking not found');
    }

    $userId = getUserId();
    $userName = getUserData('name') ?: 'A user';
    $tn = $taking['stock_taking_number'] ?: ('#' . $takingId);

    if ($action === 'verify') {
        if (!empty($taking['verified_by'])) {
            throw new Exception('Already verified');
        }
        $upd = $conn->prepare("
            UPDATE stock_taking
            SET verified_by = ?, verified_at = NOW(), verified_by_signature = ?
            WHERE id = ? AND verified_by IS NULL
        ");
        $upd->execute([$userId, $sig, $takingId]);

        if ($upd->rowCount() === 0) {
            throw new Exception('Verification failed (record changed)');
        }

        logAudit('stock_taking', 'verify', $takingId);

        // Notify approvers (next stage)
        notifyApprovers(
            'stock_taking',
            'stock_taking_approval',
            __('stock_taking_ready_for_approval', 'Stock Taking ready for approval'),
            sprintf(
                __('stock_taking_ready_for_approval_msg', 'Stock Taking %s was verified by %s and is ready for approval.'),
                $tn, $userName
            ),
            BASE_URL . 'modules/reports/stock_taking_view.php?id=' . $takingId,
            $takingId,
            'stock_taking'
        );

        echo json_encode(['success' => true, 'message' => 'Verified']);
        exit;
    }

    if ($action === 'approve') {
        if (!empty($taking['approved_by'])) {
            throw new Exception('Already approved');
        }
        // Optional rule: require verification before approval
        if (empty($taking['verified_by'])) {
            throw new Exception('Must be verified before approval');
        }

        $upd = $conn->prepare("
            UPDATE stock_taking
            SET approved_by = ?, approved_at = NOW(), approved_by_signature = ?
            WHERE id = ? AND approved_by IS NULL
        ");
        $upd->execute([$userId, $sig, $takingId]);

        if ($upd->rowCount() === 0) {
            throw new Exception('Approval failed (record changed)');
        }

        logAudit('stock_taking', 'approve', $takingId);

        // Notify creator that approval is complete
        if (!empty($taking['created_by'])) {
            createNotification(
                (int)$taking['created_by'],
                'stock_taking_approved',
                __('stock_taking_approved', 'Stock Taking approved'),
                sprintf(
                    __('stock_taking_approved_msg', 'Your Stock Taking %s has been approved by %s.'),
                    $tn, $userName
                ),
                BASE_URL . 'modules/reports/stock_taking_view.php?id=' . $takingId,
                $takingId,
                'stock_taking'
            );
        }

        echo json_encode(['success' => true, 'message' => 'Approved']);
        exit;
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
