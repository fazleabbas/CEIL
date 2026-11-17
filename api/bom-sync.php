<?php
/**
 * BOM Sync API — status actions for the Bill of Materials (migration 0049).
 * Actions: send_for_approval, approve, reject, revise.
 *
 * BOM create/edit is handled by the bom.php web form directly; this API covers
 * the lifecycle transitions invoked from bom_view.php. Delete stays on
 * bom_list.php's POST handler.
 *
 * No approve-with-edit: a BOM line carries breakdowns that must sum to its
 * total, so the approver approves or rejects (with remarks). The only thing the
 * approver may add is the written reason for a product that goes beyond the BOQ
 * balance, because that balance can move between the save and the approval.
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

function bom_sanitizeSig($value) {
    if (!is_string($value) || $value === '') return null;
    if (!preg_match('#^data:image/(png|jpeg|jpg);base64,[A-Za-z0-9+/=]+$#', $value)) return null;
    if (strlen($value) > 2 * 1024 * 1024) return null;
    return $value;
}

// Fetch the BOM and run the checks every action shares.
function bom_fetchForAction($conn, $data) {
    if (!bomApprovalAvailable($conn)) throw new Exception(__('bom_approval_unavailable'));
    $stmt = $conn->prepare("SELECT * FROM boms WHERE id = ?");
    $stmt->execute([(int)($data['id'] ?? 0)]);
    $bom = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$bom) throw new Exception(__('bom_not_found'));
    if (!canAccessLocation((int)$bom['location_id'])) throw new Exception('No permission');
    return $bom;
}

function bom_notifyCreator($bom, $type, $title, $message) {
    if (empty($bom['created_by']) || (int)$bom['created_by'] === (int)getUserId()) return;
    try {
        createNotification(
            (int)$bom['created_by'],
            $type,
            $title,
            $message,
            BASE_URL . 'modules/transactions/bom_view.php?id=' . (int)$bom['id'],
            (int)$bom['id'],
            'bom'
        );
    } catch (Exception $e) {
        error_log('bom notify failed: ' . $e->getMessage());
    }
}

try {
    switch ($action) {
        case 'send_for_approval': bomSendForApproval($conn, $data); break;
        case 'approve':           bomApprove($conn, $data); break;
        case 'reject':            bomReject($conn, $data); break;
        case 'revise':            bomRevise($conn, $data); break;
        default:
            throw new Exception('Invalid action: ' . $action);
    }
} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function bomSendForApproval($conn, $data) {
    // Cheap pre-check so a user with no BOM access cannot probe ids.
    if (!hasPermission('bom', 'view')) throw new Exception('No permission');
    $bom = bom_fetchForAction($conn, $data);
    if ($bom['status'] !== 'draft') throw new Exception(__('bom_only_draft_send'));
    if (!bomCanSendForApproval($bom)) throw new Exception('No permission');
    $id = (int)$bom['id'];

    $cnt = $conn->prepare("SELECT COUNT(*) FROM bom_details WHERE bom_id = ?");
    $cnt->execute([$id]);
    if ((int)$cnt->fetchColumn() === 0) throw new Exception(__('please_add_at_least_one_item'));

    $stmt = $conn->prepare("UPDATE boms SET status = 'pending_approval', sent_for_approval_by = ?, sent_for_approval_at = NOW() WHERE id = ? AND status = 'draft'");
    $stmt->execute([getUserId(), $id]);
    logAudit('bom', 'send_for_approval', $id);

    // Best-effort: a notification failure must not undo the transition above.
    try {
        notifyApprovers(
            'bom',
            'bom_approval',
            'BOM Pending Approval',
            'BOM ' . $bom['bom_number'] . ' requires your approval',
            BASE_URL . 'modules/transactions/bom_view.php?id=' . $id,
            $id,
            'bom'
        );
    } catch (Exception $e) {
        error_log('bomSendForApproval notify failed: ' . $e->getMessage());
    }

    echo json_encode(['success' => true, 'message' => __('bom_sent_for_approval')]);
}

function bomApprove($conn, $data) {
    if (!hasPermission('bom', 'approve')) throw new Exception('No permission');
    $bom = bom_fetchForAction($conn, $data);
    if ($bom['status'] !== 'pending_approval') throw new Exception(__('bom_only_pending_approve'));
    $id = (int)$bom['id'];

    $sig = bom_sanitizeSig($data['approved_by_signature'] ?? null);
    // Nothing drawn on the pad — use the approver's signature on file (0041).
    // Correct here: the approver IS the logged-in user.
    if ($sig === null) {
        $sig = getUserSignature($conn, getUserId());
    }
    $approveRemark = cleanText((string)($data['approve_remark'] ?? ''));

    $conn->beginTransaction();

    // Re-check the BOQ balance INSIDE the transaction: another BOM on the same
    // BOQ may have been approved since this one was saved. Reasons are keyed by
    // product id, posted as excess_remarks: { "<product_id>": "reason" }.
    $boqId = bomBoqLinkAvailable($conn) ? (int)($bom['boq_id'] ?? 0) : 0;
    if ($boqId > 0) {
        $qtyByProduct = [];
        $storedReasons = [];
        $dStmt = $conn->prepare("SELECT product_id, total_quantity, boq_excess_reason FROM bom_details WHERE bom_id = ?");
        $dStmt->execute([$id]);
        foreach ($dStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $pid = (int)$r['product_id'];
            $qtyByProduct[$pid] = ($qtyByProduct[$pid] ?? 0) + (float)$r['total_quantity'];
            if (!isset($storedReasons[$pid]) && trim((string)$r['boq_excess_reason']) !== '') {
                $storedReasons[$pid] = (string)$r['boq_excess_reason'];
            }
        }

        $over = bomBoqExcessProducts($qtyByProduct, getBoqProductBalances($boqId, $conn, $id));
        $posted = is_array($data['excess_remarks'] ?? null) ? $data['excess_remarks'] : [];
        $fmtQty = function ($n) { return rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.'); };

        $reasonByProduct = [];
        foreach ($over as $pid => $o) {
            $reason = trim((string)($posted[$pid] ?? $posted[(string)$pid] ?? ''));
            $reason = $reason !== '' ? cleanText($reason) : ($storedReasons[$pid] ?? '');
            if ($reason === '') {
                $pn = $conn->prepare("SELECT name FROM products WHERE id = ?");
                $pn->execute([$pid]);
                throw new Exception(sprintf(__('bom_boq_over_needs_reason'), $pn->fetchColumn() ?: ('#' . $pid), $fmtQty($o['qty']), $fmtQty(max($o['balance'], 0))));
            }
            $reasonByProduct[$pid] = $reason;
        }

        // Rewrite on every row of each product. A product no longer over has its
        // reason cleared, so a stale justification cannot outlive its excess.
        $rStmt = $conn->prepare("UPDATE bom_details SET boq_excess_reason = ? WHERE bom_id = ? AND product_id = ?");
        foreach (array_keys($qtyByProduct) as $pid) {
            $rStmt->execute([$reasonByProduct[$pid] ?? null, $id, $pid]);
        }
    }

    $stmt = $conn->prepare("
        UPDATE boms
        SET status = 'approved', approved_by = ?, approved_at = NOW(),
            approved_by_signature = ?, approve_remark = ?
        WHERE id = ? AND status = 'pending_approval'
    ");
    $stmt->execute([getUserId(), $sig, ($approveRemark !== '' ? $approveRemark : null), $id]);
    if ($stmt->rowCount() === 0) throw new Exception(__('bom_only_pending_approve'));

    $conn->commit();
    logAudit('bom', 'approve', $id);
    bom_notifyCreator($bom, 'bom_approved', 'BOM Approved', 'BOM ' . $bom['bom_number'] . ' has been approved');
    echo json_encode(['success' => true, 'message' => __('bom_approved')]);
}

function bomReject($conn, $data) {
    if (!hasPermission('bom', 'approve')) throw new Exception('No permission');
    $bom = bom_fetchForAction($conn, $data);
    if ($bom['status'] !== 'pending_approval') throw new Exception(__('bom_only_pending_approve'));
    $id = (int)$bom['id'];

    $reason = cleanText((string)($data['reject_reason'] ?? ''));
    $stmt = $conn->prepare("UPDATE boms SET status = 'rejected', rejected_by = ?, rejected_at = NOW(), reject_reason = ? WHERE id = ? AND status = 'pending_approval'");
    $stmt->execute([getUserId(), ($reason !== '' ? $reason : null), $id]);
    logAudit('bom', 'reject', $id);
    bom_notifyCreator($bom, 'bom_rejected', 'BOM Rejected', 'BOM ' . $bom['bom_number'] . ' has been rejected' . ($reason !== '' ? ': ' . $reason : ''));
    echo json_encode(['success' => true, 'message' => __('bom_rejected')]);
}

/**
 * Approved / rejected -> draft, so the BOM can be edited and sent again.
 * Clears every approval / rejection field: the old approver's name and
 * signature must not sit on figures they have not seen. Requisitions already
 * linked to the BOM keep their link (see bomLinkError()).
 */
function bomRevise($conn, $data) {
    if (!hasPermission('bom', 'view')) throw new Exception('No permission');
    $bom = bom_fetchForAction($conn, $data);
    if (!in_array($bom['status'], ['approved', 'rejected'], true)) throw new Exception(__('bom_only_approved_rejected_revise'));
    if (!bomCanRevise($bom)) throw new Exception('No permission');
    $id = (int)$bom['id'];

    $stmt = $conn->prepare("
        UPDATE boms
        SET status = 'draft',
            sent_for_approval_by = NULL, sent_for_approval_at = NULL,
            approved_by = NULL, approved_at = NULL, approved_by_signature = NULL, approve_remark = NULL,
            rejected_by = NULL, rejected_at = NULL, reject_reason = NULL
        WHERE id = ? AND status IN ('approved', 'rejected')
    ");
    $stmt->execute([$id]);
    // Distinct action, so a revision of an APPROVED BOM is greppable.
    logAudit('bom', 'revise_from_' . $bom['status'], $id);
    echo json_encode(['success' => true, 'message' => __('bom_revised')]);
}
