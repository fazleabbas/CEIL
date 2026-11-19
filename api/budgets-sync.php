<?php
/**
 * Budgets Sync API — status actions for the Budget document.
 * Actions: send_for_approval, approve, reject, delete.
 *
 * Budget create/edit is handled by the budget.php web form directly; this API
 * covers the lifecycle transitions invoked from budget_view.php.
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

function budget_sanitizeSig($value) {
    if (!is_string($value) || $value === '') return null;
    if (!preg_match('#^data:image/(png|jpeg|jpg);base64,[A-Za-z0-9+/=]+$#', $value)) return null;
    if (strlen($value) > 2 * 1024 * 1024) return null;
    return $value;
}

function budget_fetch($conn, $id) {
    $stmt = $conn->prepare("SELECT * FROM budgets WHERE id = ?");
    $stmt->execute([(int)$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

try {
    switch ($action) {
        case 'send_for_approval': budgetSendForApproval($conn, $data); break;
        case 'approve':           budgetApprove($conn, $data); break;
        case 'reject':            budgetReject($conn, $data); break;
        case 'delete':            budgetDelete($conn, $data); break;
        default:
            throw new Exception('Invalid action: ' . $action);
    }
} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function budgetSendForApproval($conn, $data) {
    // Cheap pre-check before the row is fetched. The real gate is
    // budgetCanSendForApproval() below, but that needs created_by, so without
    // this any logged-in user could probe ids for existence and status.
    if (!hasPermission('budget', 'view')) throw new Exception('No permission');
    $id = (int)($data['id'] ?? 0);
    $budget = budget_fetch($conn, $id);
    if (!$budget) throw new Exception(__('budget_not_found'));
    if ($budget['status'] !== 'draft') throw new Exception(__('budget_only_draft_send'));
    // 'budget' edit permission, or the creator of THIS budget. The creator override
    // has to reach here as well as the edit form: editing a pending budget withdraws
    // it to draft, so without this a creator could take their own budget out of the
    // approval queue and then have no way to put it back. approve / reject are NOT
    // widened — they stay on the 'budget' approve permission.
    if (!budgetCanSendForApproval($budget)) throw new Exception('No permission');

    // Must have at least one allocation.
    $cnt = $conn->prepare("SELECT COUNT(*) FROM budget_details WHERE budget_id = ?");
    $cnt->execute([$id]);
    if ((int)$cnt->fetchColumn() === 0) throw new Exception(__('budget_needs_one_allocation'));

    // A blacklisted supplier (0054) must be replaced before the budget goes out.
    $blErrors = budgetBlacklistErrors(array_keys(getBudgetBlacklistedSuppliers($id, $conn)), $conn);
    if (!empty($blErrors)) throw new Exception(implode(' ', $blErrors));

    $stmt = $conn->prepare("UPDATE budgets SET status = 'pending_approval', sent_for_approval_by = ?, sent_for_approval_at = NOW() WHERE id = ?");
    $stmt->execute([getUserId(), $id]);
    logAudit('budget', 'send_for_approval', $id);

    // Notify budget approvers (users with can_approve on 'budget', plus Admins)
    // that a budget is waiting. Best-effort: a notification failure must not
    // block the status transition that already committed above.
    try {
        notifyApprovers(
            'budget',
            'budget_approval',
            'Budget Pending Approval',
            'Budget ' . ($budget['budget_number'] ?? ('#' . $id)) . ' requires your approval',
            BASE_URL . 'modules/transactions/budget_view.php?id=' . $id,
            $id,
            'budget'
        );
    } catch (Exception $e) {
        error_log('budgetSendForApproval notify failed: ' . $e->getMessage());
    }

    echo json_encode(['success' => true, 'message' => __('budget_sent_for_approval')]);
}

function budgetApprove($conn, $data) {
    if (!hasPermission('budget', 'approve')) throw new Exception('No permission');
    $id = (int)($data['id'] ?? 0);
    $budget = budget_fetch($conn, $id);
    if (!$budget) throw new Exception(__('budget_not_found'));
    if ($budget['status'] !== 'pending_approval') throw new Exception(__('budget_only_pending_approve'));

    $sig = budget_sanitizeSig($data['approved_by_signature'] ?? null);
    // Nothing drawn on the pad — use the approver's signature on file (0041).
    if ($sig === null) {
        $sig = getUserSignature($conn, getUserId());
    }
    $items = is_array($data['items'] ?? null) ? $data['items'] : [];
    $approveRemark = clean($data['approve_remark'] ?? '');

    // Map this budget's detail lines to their product, so approver edits can be
    // capped per product against the requisition's approved quantity.
    $detProduct = [];
    $dpStmt = $conn->prepare("SELECT id, product_id FROM budget_details WHERE budget_id = ?");
    $dpStmt->execute([$id]);
    foreach ($dpStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $detProduct[(int)$r['id']] = (int)$r['product_id'];
    }

    // Approved qty per product on the source requisition, minus what OTHER
    // budgets of that requisition already took — the max this budget may hold.
    $requisitionId = (int)$budget['requisition_id'];
    $approvedByProduct = [];
    $apStmt = $conn->prepare("
        SELECT product_id, SUM(COALESCE(approved_quantity, requested_quantity)) AS approved
        FROM requisition_voucher_details
        WHERE requisition_id = ?
        GROUP BY product_id
    ");
    $apStmt->execute([$requisitionId]);
    foreach ($apStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $approvedByProduct[(int)$r['product_id']] = (float)$r['approved'];
    }
    $budgetedOther = getRequisitionBudgetedQty($requisitionId, $conn, $id);

    // A supplier blacklisted after this budget was sent (0054) cannot be
    // approved. The approver rejects it so the creator can replace the supplier.
    $blErrors = budgetBlacklistErrors(array_keys(getBudgetBlacklistedSuppliers($id, $conn)), $conn);
    if (!empty($blErrors)) throw new Exception(implode(' ', $blErrors));

    $conn->beginTransaction();

    // Validate + collect per-line qty/rate edits, tallying per-product totals.
    $perProduct = [];
    $updates = [];
    foreach ($items as $it) {
        $detailId = (int)($it['detail_id'] ?? 0);
        if ($detailId <= 0) continue;
        $qty = floatval($it['quantity'] ?? 0);
        $rate = floatval($it['rate'] ?? 0);
        if ($qty <= 0) throw new Exception(__('budget_alloc_needs_qty_all'));
        if ($rate < 0) $rate = 0;

        // Per-line VAT (migration 0044). Same rule as the budget form: when a
        // percent is supplied it wins and the amount is re-derived here, so a
        // stale or hand-crafted amount can never disagree with the stored
        // percent. NULL percent means the approver typed a flat amount.
        $vatPctRaw = $it['vat_percent'] ?? null;
        $vatPct = ($vatPctRaw === null || $vatPctRaw === '') ? null : floatval($vatPctRaw);
        if ($vatPct !== null && ($vatPct < 0 || $vatPct > 100)) {
            throw new Exception(__('budget_alloc_bad_vat_pct_all'));
        }
        $vatAmt = floatval($it['vat_amount'] ?? 0);
        if ($vatAmt < 0) $vatAmt = 0;
        if ($vatPct !== null) {
            $vatAmt = round($qty * $rate * $vatPct / 100, 2);
        }

        $pid = $detProduct[$detailId] ?? 0;
        if ($pid > 0) $perProduct[$pid] = ($perProduct[$pid] ?? 0) + $qty;
        $updates[] = [round($qty, 3), round($rate, 2), $vatPct, round($vatAmt, 2), $detailId];
    }

    // Reasons for going beyond the requisition quantity, keyed by PRODUCT id —
    // the excess is a property of the product total, not of one supplier row.
    // Posted as excess_remarks: { "<product_id>": "reason" }.
    $postedReasons = is_array($data['excess_remarks'] ?? null) ? $data['excess_remarks'] : [];

    // Reasons already stored against this budget, so an approver who leaves a
    // pre-existing over-allocation untouched does not have to retype the reason
    // the creator already gave.
    $storedReasons = [];
    $srStmt = $conn->prepare("SELECT product_id, remarks FROM budget_details WHERE budget_id = ? AND remarks IS NOT NULL AND remarks <> ''");
    $srStmt->execute([$id]);
    foreach ($srStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $spid = (int)$r['product_id'];
        if (!isset($storedReasons[$spid])) $storedReasons[$spid] = (string)$r['remarks'];
    }

    // Going over the requisition quantity is ALLOWED for the approver too — the
    // budget being approved may already be over, and an approver who spots a
    // shortfall must be able to fix it rather than reject. What is refused is an
    // UNEXPLAINED excess, exactly as on the creator's form.
    $fmtQty = function ($n) { return rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.'); };
    $reasonByProduct = [];
    foreach ($perProduct as $pid => $sum) {
        $cap = ($approvedByProduct[$pid] ?? 0) - ($budgetedOther[$pid] ?? 0);
        if ($sum <= $cap + 0.0005) continue;

        $reason = clean((string)($postedReasons[$pid] ?? $postedReasons[(string)$pid] ?? $storedReasons[$pid] ?? ''));
        if ($reason === '') {
            $pn = $conn->prepare("SELECT name FROM products WHERE id = ?");
            $pn->execute([$pid]);
            $pname = $pn->fetchColumn() ?: ('#' . $pid);
            throw new Exception(sprintf(__('budget_over_needs_reason'), $pname, $fmtQty($sum), $fmtQty(max($cap, 0))));
        }
        $reasonByProduct[$pid] = $reason;
    }

    // Apply the edits (only to this budget's own details).
    $upd = $conn->prepare("UPDATE budget_details SET quantity = ?, rate = ?, vat_percent = ?, vat_amount = ? WHERE id = ? AND budget_id = ?");
    foreach ($updates as $u) {
        $upd->execute([$u[0], $u[1], $u[2], $u[3], $u[4], $id]);
    }

    // Rewrite the reason on every row of each product, matching how the creator's
    // form stores it. A product the approver brought back WITHIN its remaining
    // quantity has its reason cleared, so a stale justification cannot outlive
    // the excess it described.
    $remStmt = $conn->prepare("UPDATE budget_details SET remarks = ? WHERE budget_id = ? AND product_id = ?");
    foreach (array_keys($perProduct) as $pid) {
        $remStmt->execute([$reasonByProduct[$pid] ?? null, $id, $pid]);
    }

    $stmt = $conn->prepare("UPDATE budgets SET status = 'approved', approved_by = ?, approved_at = NOW(), approved_by_signature = ?, approve_remark = ? WHERE id = ?");
    $stmt->execute([getUserId(), $sig, ($approveRemark !== '' ? $approveRemark : null), $id]);

    $conn->commit();
    logAudit('budget', 'approve', $id);
    echo json_encode(['success' => true, 'message' => __('budget_approved')]);
}

function budgetReject($conn, $data) {
    if (!hasPermission('budget', 'approve')) throw new Exception('No permission');
    $id = (int)($data['id'] ?? 0);
    $budget = budget_fetch($conn, $id);
    if (!$budget) throw new Exception(__('budget_not_found'));
    if ($budget['status'] !== 'pending_approval') throw new Exception(__('budget_only_pending_approve'));

    $reason = clean($data['reject_reason'] ?? '');
    $stmt = $conn->prepare("UPDATE budgets SET status = 'rejected', rejected_by = ?, rejected_at = NOW(), reject_reason = ? WHERE id = ?");
    $stmt->execute([getUserId(), ($reason !== '' ? $reason : null), $id]);
    logAudit('budget', 'reject', $id);
    echo json_encode(['success' => true, 'message' => __('budget_rejected')]);
}

function budgetDelete($conn, $data) {
    if (!hasPermission('budget', 'delete')) throw new Exception('No permission');
    $id = (int)($data['id'] ?? 0);
    $budget = budget_fetch($conn, $id);
    if (!$budget) throw new Exception(__('budget_not_found'));
    if ($budget['status'] !== 'draft') throw new Exception(__('budget_only_draft_delete'));

    $conn->prepare("DELETE FROM budgets WHERE id = ?")->execute([$id]);
    logAudit('budget', 'delete', $id);
    echo json_encode(['success' => true, 'message' => __('budget_deleted')]);
}
