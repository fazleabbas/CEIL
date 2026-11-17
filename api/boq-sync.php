<?php
/**
 * BOQ Sync API — status actions for the Bill of Quantities (migration 0051).
 * Actions: send_for_approval, approve, reject, revise.
 *
 * BOQ create/edit is handled by the boq.php web form directly; this API covers
 * the lifecycle transitions invoked from boq_view.php. Delete stays on
 * boq_list.php's POST handler.
 *
 * No approve-with-edit: a BOQ line carries breakdowns that must sum to its
 * total, so the approver approves or rejects.
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
$data = $input['data'] ?? $input;

function boq_sanitizeSig($value) {
    if (!is_string($value) || $value === '') return null;
    if (!preg_match('#^data:image/(png|jpeg|jpg);base64,[A-Za-z0-9+/=]+$#', $value)) return null;
    if (strlen($value) > 2 * 1024 * 1024) return null;
    return $value;
}

// Fetch the BOQ and run the checks every action shares.
function boq_fetchForAction($conn, $data) {
    if (!boqApprovalAvailable($conn)) throw new Exception(__('boq_approval_unavailable'));
    $stmt = $conn->prepare("SELECT * FROM boqs WHERE id = ?");
    $stmt->execute([(int)($data['id'] ?? 0)]);
    $boq = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$boq) throw new Exception(__('boq_not_found'));
    if (!canAccessLocation((int)$boq['location_id'])) throw new Exception('No permission');
    return $boq;
}

function boq_notifyCreator($boq, $type, $title, $message) {
    if (empty($boq['created_by']) || (int)$boq['created_by'] === (int)getUserId()) return;
    try {
        createNotification(
            (int)$boq['created_by'],
            $type,
            $title,
            $message,
            BASE_URL . 'modules/transactions/boq_view.php?id=' . (int)$boq['id'],
            (int)$boq['id'],
            'boq'
        );
    } catch (Exception $e) {
        error_log('boq notify failed: ' . $e->getMessage());
    }
}

try {
    switch ($action) {
        case 'send_for_approval': boqSendForApproval($conn, $data); break;
        case 'approve':           boqApprove($conn, $data); break;
        case 'reject':            boqReject($conn, $data); break;
        case 'revise':            boqRevise($conn, $data); break;
        default:
            throw new Exception('Invalid action: ' . $action);
    }
} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function boqSendForApproval($conn, $data) {
    // Cheap pre-check so a user with no BOQ access cannot probe ids.
    if (!hasPermission('boq', 'view')) throw new Exception('No permission');
    $boq = boq_fetchForAction($conn, $data);
    if ($boq['status'] !== 'draft') throw new Exception(__('boq_only_draft_send'));
    if (!boqCanSendForApproval($boq)) throw new Exception('No permission');
    $id = (int)$boq['id'];

    $cnt = $conn->prepare("SELECT COUNT(*) FROM boq_details WHERE boq_id = ?");
    $cnt->execute([$id]);
    if ((int)$cnt->fetchColumn() === 0) throw new Exception(__('please_add_at_least_one_item'));

    $stmt = $conn->prepare("UPDATE boqs SET status = 'pending_approval', sent_for_approval_by = ?, sent_for_approval_at = NOW() WHERE id = ? AND status = 'draft'");
    $stmt->execute([getUserId(), $id]);
    if ($stmt->rowCount() === 0) throw new Exception(__('boq_only_draft_send'));
    logAudit('boq', 'send_for_approval', $id);

    // Best-effort: a notification failure must not undo the transition above.
    try {
        notifyApprovers(
            'boq',
            'boq_approval',
            'BOQ Pending Approval',
            'BOQ ' . $boq['boq_number'] . ' requires your approval',
            BASE_URL . 'modules/transactions/boq_view.php?id=' . $id,
            $id,
            'boq'
        );
    } catch (Exception $e) {
        error_log('boqSendForApproval notify failed: ' . $e->getMessage());
    }

    echo json_encode(['success' => true, 'message' => __('boq_sent_for_approval')]);
}

/**
 * Pending -> approved. Clears approved_snapshot, which is the moment a revised
 * BOQ's quantities take effect for BOM balances.
 */
function boqApprove($conn, $data) {
    if (!hasPermission('boq', 'approve')) throw new Exception('No permission');
    $boq = boq_fetchForAction($conn, $data);
    if ($boq['status'] !== 'pending_approval') throw new Exception(__('boq_only_pending_approve'));
    $id = (int)$boq['id'];

    $sig = boq_sanitizeSig($data['approved_by_signature'] ?? null);
    // Nothing drawn on the pad: use the approver's signature on file.
    // Correct here, because the approver IS the logged-in user.
    if ($sig === null) {
        $sig = getUserSignature($conn, getUserId());
    }
    $approveRemark = cleanText((string)($data['approve_remark'] ?? ''));

    $stmt = $conn->prepare("
        UPDATE boqs
        SET status = 'approved', approved_by = ?, approved_at = NOW(),
            approved_by_signature = ?, approve_remark = ?, approved_snapshot = NULL
        WHERE id = ? AND status = 'pending_approval'
    ");
    $stmt->execute([getUserId(), $sig, ($approveRemark !== '' ? $approveRemark : null), $id]);
    if ($stmt->rowCount() === 0) throw new Exception(__('boq_only_pending_approve'));

    logAudit('boq', 'approve', $id);
    boq_notifyCreator($boq, 'boq_approved', 'BOQ Approved', 'BOQ ' . $boq['boq_number'] . ' has been approved');
    echo json_encode(['success' => true, 'message' => __('boq_approved')]);
}

/**
 * Pending -> rejected. approved_snapshot is deliberately left alone: a rejected
 * revision keeps the previous approved quantities in force.
 */
function boqReject($conn, $data) {
    if (!hasPermission('boq', 'approve')) throw new Exception('No permission');
    $boq = boq_fetchForAction($conn, $data);
    if ($boq['status'] !== 'pending_approval') throw new Exception(__('boq_only_pending_approve'));
    $id = (int)$boq['id'];

    $reason = cleanText((string)($data['reject_reason'] ?? ''));
    if (trim($reason) === '') throw new Exception(__('boq_reject_reason_required'));

    $stmt = $conn->prepare("UPDATE boqs SET status = 'rejected', rejected_by = ?, rejected_at = NOW(), reject_reason = ? WHERE id = ? AND status = 'pending_approval'");
    $stmt->execute([getUserId(), $reason, $id]);
    if ($stmt->rowCount() === 0) throw new Exception(__('boq_only_pending_approve'));

    logAudit('boq', 'reject', $id);
    boq_notifyCreator($boq, 'boq_rejected', 'BOQ Rejected', 'BOQ ' . $boq['boq_number'] . ' has been rejected: ' . $reason);
    echo json_encode(['success' => true, 'message' => __('boq_rejected')]);
}

/**
 * Approved / rejected -> draft, so the BOQ can be edited and sent again.
 * Clears every approval / rejection field: the old approver's name and
 * signature must not sit on figures they have not seen.
 *
 * Revising an APPROVED BOQ saves its quantities first. Revising a REJECTED one
 * keeps whatever snapshot it has, because its boq_details are unapproved.
 */
function boqRevise($conn, $data) {
    if (!hasPermission('boq', 'view')) throw new Exception('No permission');
    $boq = boq_fetchForAction($conn, $data);
    if (!in_array($boq['status'], ['approved', 'rejected'], true)) throw new Exception(__('boq_only_approved_rejected_revise'));
    if (!boqCanRevise($boq)) throw new Exception('No permission');
    $id = (int)$boq['id'];

    $conn->beginTransaction();

    $lock = $conn->prepare("SELECT status FROM boqs WHERE id = ? FOR UPDATE");
    $lock->execute([$id]);
    $current = $lock->fetchColumn();
    if (!in_array($current, ['approved', 'rejected'], true)) throw new Exception(__('boq_only_approved_rejected_revise'));

    if ($current === 'approved') {
        $qtyStmt = $conn->prepare("SELECT product_id, SUM(total_quantity) AS qty FROM boq_details WHERE boq_id = ? GROUP BY product_id");
        $qtyStmt->execute([$id]);
        $snapshot = [];
        foreach ($qtyStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $snapshot[(int)$r['product_id']] = (float)$r['qty'];
        }
        $conn->prepare("UPDATE boqs SET approved_snapshot = ? WHERE id = ?")->execute([json_encode((object)$snapshot), $id]);
    }

    $stmt = $conn->prepare("
        UPDATE boqs
        SET status = 'draft',
            sent_for_approval_by = NULL, sent_for_approval_at = NULL,
            approved_by = NULL, approved_at = NULL, approved_by_signature = NULL, approve_remark = NULL,
            rejected_by = NULL, rejected_at = NULL, reject_reason = NULL
        WHERE id = ? AND status IN ('approved', 'rejected')
    ");
    $stmt->execute([$id]);

    $conn->commit();
    // Distinct action, so a revision of an APPROVED BOQ is greppable.
    logAudit('boq', 'revise_from_' . $current, $id);
    echo json_encode(['success' => true, 'message' => __('boq_revised')]);
}
