<?php
/**
 * Purchase Orders Sync API — lifecycle actions for the Purchase Order document.
 * Actions: send_for_approval, approve (approve-with-edit: per-line qty/rate
 * overrides), reject, revise (rejected -> draft for resubmission), cancel
 * (approved + nothing purchased yet), delete (draft only),
 * link_budget (attach a PO to an approved budget).
 *
 * NOTE ON link_budget: available for a PO of ANY status, as long as it is NOT
 * ALREADY LINKED to a budget. Where purchases have already been raised from
 * the PO they are attached to the same budget in the same transaction, so the
 * quantity already received shows as "Procured" instead of disappearing from
 * the budget's figures.
 *
 * PO create/edit is handled by the purchase_order.php web form directly; this
 * API covers the transitions invoked from purchase_order_view.php. A PO never
 * touches stock or the GL — nothing here writes stock_batches or transactions.
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

function po_sanitizeSig($value) {
    if (!is_string($value) || $value === '') return null;
    if (!preg_match('#^data:image/(png|jpeg|jpg);base64,[A-Za-z0-9+/=]+$#', $value)) return null;
    if (strlen($value) > 2 * 1024 * 1024) return null;
    return $value;
}

function po_fetch($conn, $id) {
    $stmt = $conn->prepare("SELECT * FROM purchase_orders WHERE id = ?");
    $stmt->execute([(int)$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

try {
    switch ($action) {
        case 'send_for_approval': poSendForApproval($conn, $data); break;
        case 'approve':           poApprove($conn, $data); break;
        case 'reject':            poReject($conn, $data); break;
        case 'revise':            poRevise($conn, $data); break;
        case 'cancel':            poCancel($conn, $data); break;
        case 'delete':            poDelete($conn, $data); break;
        case 'link_budget':       poLinkBudget($conn, $data); break;
        default:
            throw new Exception('Invalid action: ' . $action);
    }
} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function poSendForApproval($conn, $data) {
    if (!hasPermission('purchase_orders', 'edit')) throw new Exception('No permission');
    $id = (int)($data['id'] ?? 0);
    $po = po_fetch($conn, $id);
    if (!$po) throw new Exception(__('po_not_found'));
    if ($po['status'] !== 'draft') throw new Exception(__('po_only_draft_send'));

    // Must have at least one line item.
    $cnt = $conn->prepare("SELECT COUNT(*) FROM purchase_order_details WHERE purchase_order_id = ?");
    $cnt->execute([$id]);
    if ((int)$cnt->fetchColumn() === 0) throw new Exception(__('please_add_at_least_one_item'));

    $stmt = $conn->prepare("UPDATE purchase_orders SET status = 'pending_approval', sent_for_approval_by = ?, sent_for_approval_at = NOW() WHERE id = ?");
    $stmt->execute([getUserId(), $id]);
    logAudit('purchase_order', 'send_for_approval', $id);

    // Notify PO approvers (users with can_approve on 'purchase_orders', plus
    // Admins). Best-effort: a notification failure must not block the
    // transition that already committed above.
    try {
        notifyApprovers(
            'purchase_orders',
            'purchase_order_approval',
            'Purchase Order Pending Approval',
            'Purchase order ' . ($po['po_number'] ?? ('#' . $id)) . ' requires your approval',
            BASE_URL . 'modules/transactions/purchase_order_view.php?id=' . $id,
            $id,
            'purchase_order'
        );
    } catch (Exception $e) {
        error_log('poSendForApproval notify failed: ' . $e->getMessage());
    }

    echo json_encode(['success' => true, 'message' => __('po_sent_for_approval')]);
}

function poApprove($conn, $data) {
    if (!hasPermission('purchase_orders', 'approve')) throw new Exception('No permission');
    $id = (int)($data['id'] ?? 0);
    $po = po_fetch($conn, $id);
    if (!$po) throw new Exception(__('po_not_found'));
    if ($po['status'] !== 'pending_approval') throw new Exception(__('po_only_pending_approve'));

    $sig = po_sanitizeSig($data['approved_by_signature'] ?? null);
    // Nothing drawn on the pad — use the approver's signature on file (0041).
    if ($sig === null) {
        $sig = getUserSignature($conn, getUserId());
    }
    $items = is_array($data['items'] ?? null) ? $data['items'] : [];
    $approvalRemarks = clean($data['approval_remarks'] ?? '');

    // The approver may amend payment / delivery terms (migration 0048). A key
    // that is ABSENT leaves the stored value alone; present-but-empty clears it.
    $termsSet = [];
    $termsParams = [];
    foreach (['payment_terms', 'delivery_terms'] as $termsCol) {
        if (array_key_exists($termsCol, $data)) {
            $v = cleanText((string)$data[$termsCol]);
            $termsSet[] = "$termsCol = ?";
            $termsParams[] = ($v !== '') ? $v : null;
        }
    }

    // Existing detail lines of this PO (for validating posted detail_ids and
    // defaulting omitted lines to the requested qty/rate).
    $existing = [];
    $detStmt = $conn->prepare("SELECT id, quantity, rate_per_unit FROM purchase_order_details WHERE purchase_order_id = ?");
    $detStmt->execute([$id]);
    foreach ($detStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $existing[(int)$r['id']] = $r;
    }
    if (empty($existing)) throw new Exception(__('please_add_at_least_one_item'));

    $posted = [];
    foreach ($items as $it) {
        $detailId = (int)($it['detail_id'] ?? 0);
        if ($detailId <= 0 || !isset($existing[$detailId])) continue;
        $posted[$detailId] = $it;
    }

    // Resolve per-line approved qty/rate. Approve-with-edit: the approver may
    // adjust freely; qty 0 rejects that line (nothing to purchase from it).
    // Omitted lines default to the requested values.
    $resolved = [];
    $anyPositive = false;
    $newTotal = 0;
    foreach ($existing as $detailId => $row) {
        $it = $posted[$detailId] ?? null;
        $qty = ($it !== null && isset($it['approved_quantity']) && $it['approved_quantity'] !== '')
            ? floatval($it['approved_quantity'])
            : floatval($row['quantity']);
        $rate = ($it !== null && isset($it['approved_rate']) && $it['approved_rate'] !== '')
            ? floatval($it['approved_rate'])
            : floatval($row['rate_per_unit']);
        if ($qty < 0) throw new Exception(__('po_approve_qty_invalid'));
        if ($rate < 0) $rate = 0;
        $qty = round($qty, 3);
        $rate = round($rate, 2);
        if ($qty > 0) $anyPositive = true;
        $newTotal += $qty * $rate;
        $resolved[$detailId] = ['qty' => $qty, 'rate' => $rate];
    }
    if (!$anyPositive) throw new Exception(__('po_approve_needs_one_line'));
    $newTotal = round($newTotal, 2);

    $conn->beginTransaction();

    $upd = $conn->prepare("UPDATE purchase_order_details SET approved_quantity = ?, approved_rate = ? WHERE id = ? AND purchase_order_id = ?");
    foreach ($resolved as $detailId => $r) {
        $upd->execute([$r['qty'], $r['rate'], $detailId, $id]);
    }

    // Header total reflects the approved lines (VAT stays as entered).
    $stmt = $conn->prepare("
        UPDATE purchase_orders SET
            status = 'approved',
            total_amount = ?,
            approved_by = ?,
            approved_at = NOW(),
            approved_by_signature = ?,
            approval_remarks = ?" . ($termsSet ? ",\n            " . implode(', ', $termsSet) : '') . "
        WHERE id = ?
    ");
    $stmt->execute(array_merge(
        [$newTotal, getUserId(), $sig, ($approvalRemarks !== '' ? $approvalRemarks : null)],
        $termsParams,
        [$id]
    ));

    $conn->commit();
    logAudit('purchase_order', 'approve', $id);

    // Notify the creator (best-effort).
    try {
        if (!empty($po['created_by']) && (int)$po['created_by'] !== (int)getUserId()) {
            createNotification(
                (int)$po['created_by'],
                'purchase_order_approved',
                'Purchase Order Approved',
                'Purchase order ' . ($po['po_number'] ?? ('#' . $id)) . ' has been approved',
                BASE_URL . 'modules/transactions/purchase_order_view.php?id=' . $id,
                $id,
                'purchase_order'
            );
        }
    } catch (Exception $e) {
        error_log('poApprove notify failed: ' . $e->getMessage());
    }

    echo json_encode(['success' => true, 'message' => __('po_approved')]);
}

function poReject($conn, $data) {
    if (!hasPermission('purchase_orders', 'approve')) throw new Exception('No permission');
    $id = (int)($data['id'] ?? 0);
    $po = po_fetch($conn, $id);
    if (!$po) throw new Exception(__('po_not_found'));
    if ($po['status'] !== 'pending_approval') throw new Exception(__('po_only_pending_approve'));

    $reason = clean($data['reject_reason'] ?? '');
    $stmt = $conn->prepare("UPDATE purchase_orders SET status = 'rejected', rejected_by = ?, rejected_at = NOW(), reject_reason = ? WHERE id = ?");
    $stmt->execute([getUserId(), ($reason !== '' ? $reason : null), $id]);
    logAudit('purchase_order', 'reject', $id);

    // Notify the creator (best-effort).
    try {
        if (!empty($po['created_by']) && (int)$po['created_by'] !== (int)getUserId()) {
            createNotification(
                (int)$po['created_by'],
                'purchase_order_rejected',
                'Purchase Order Rejected',
                'Purchase order ' . ($po['po_number'] ?? ('#' . $id)) . ' was rejected' . ($reason !== '' ? ': ' . $reason : ''),
                BASE_URL . 'modules/transactions/purchase_order_view.php?id=' . $id,
                $id,
                'purchase_order'
            );
        }
    } catch (Exception $e) {
        error_log('poReject notify failed: ' . $e->getMessage());
    }

    echo json_encode(['success' => true, 'message' => __('po_rejected')]);
}

/**
 * Revise a rejected PO back to draft so the creator can amend and resubmit.
 * Approval metadata from the rejected round is cleared.
 */
function poRevise($conn, $data) {
    if (!hasPermission('purchase_orders', 'edit')) throw new Exception('No permission');
    $id = (int)($data['id'] ?? 0);
    $po = po_fetch($conn, $id);
    if (!$po) throw new Exception(__('po_not_found'));
    if ($po['status'] !== 'rejected') throw new Exception(__('po_only_rejected_revise'));

    $stmt = $conn->prepare("
        UPDATE purchase_orders SET
            status = 'draft',
            sent_for_approval_by = NULL, sent_for_approval_at = NULL,
            rejected_by = NULL, rejected_at = NULL
        WHERE id = ?
    ");
    $stmt->execute([$id]);
    logAudit('purchase_order', 'revise', $id);
    echo json_encode(['success' => true, 'message' => __('po_revised')]);
}

/**
 * Cancel an approved PO that has nothing purchased against it yet.
 */
function poCancel($conn, $data) {
    if (!hasPermission('purchase_orders', 'edit')) throw new Exception('No permission');
    $id = (int)($data['id'] ?? 0);
    $po = po_fetch($conn, $id);
    if (!$po) throw new Exception(__('po_not_found'));
    if ($po['status'] !== 'approved') throw new Exception(__('po_only_approved_cancel'));

    // Nothing may have been purchased against it.
    $chk = $conn->prepare("SELECT COALESCE(SUM(purchased_quantity), 0) FROM purchase_order_details WHERE purchase_order_id = ?");
    $chk->execute([$id]);
    if ((float)$chk->fetchColumn() > 0.0005) throw new Exception(__('po_has_purchases_no_cancel'));

    $reason = clean($data['cancel_reason'] ?? '');
    $stmt = $conn->prepare("UPDATE purchase_orders SET status = 'cancelled', closed_by = ?, closed_at = NOW(), close_reason = ? WHERE id = ?");
    $stmt->execute([getUserId(), ($reason !== '' ? $reason : null), $id]);
    logAudit('purchase_order', 'cancel', $id);
    echo json_encode(['success' => true, 'message' => __('po_cancelled')]);
}

function poDelete($conn, $data) {
    if (!hasPermission('purchase_orders', 'delete')) throw new Exception('No permission');
    $id = (int)($data['id'] ?? 0);
    $po = po_fetch($conn, $id);
    if (!$po) throw new Exception(__('po_not_found'));
    if ($po['status'] !== 'draft') throw new Exception(__('po_only_draft_delete'));

    $conn->prepare("DELETE FROM purchase_orders WHERE id = ?")->execute([$id]);
    logAudit('purchase_order', 'delete', $id);
    echo json_encode(['success' => true, 'message' => __('purchase_order_deleted')]);
}

/**
 * Attach a PO to an approved budget after the fact.
 *
 * Available for a PO of ANY status, as long as it is NOT ALREADY LINKED.
 *
 * If purchases were already raised from the PO, those purchase rows are
 * attached to the same budget in the SAME transaction. That backfill is not
 * optional: purchases created from an unlinked PO carry budget_id = NULL, so
 * linking the PO alone would count its outstanding quantity as "On PO" while
 * the quantity already received never showed as "Procured" — the budget would
 * report far more left than it really has.
 *
 * Cancelled and rejected POs may be linked too, but nothing counts them:
 * getBudgetPoOutstanding() only sums draft/pending_approval/approved, and they
 * have no purchases. The link is then a record of intent, not a commitment.
 *
 * The PO's own status is never changed by linking — a draft still has to go
 * through approval, a closed PO stays closed.
 */
function poLinkBudget($conn, $data) {
    if (!hasPermission('purchase_orders', 'edit')) throw new Exception('No permission');

    $id       = (int)($data['id'] ?? 0);
    $budgetId = (int)($data['budget_id'] ?? 0);

    $po = po_fetch($conn, $id);
    if (!$po) throw new Exception(__('po_not_found'));
    if (!empty($po['budget_id'])) {
        throw new Exception(__('po_link_already_linked'));
    }

    // Purchases already raised from this PO — they get moved onto the budget
    // together with it. Refuse if any of them is already on a DIFFERENT budget:
    // silently re-pointing someone else's budget figures is never right.
    $linkedPurchases = [];
    if (po_link_hasPurchaseCol($conn, 'purchase_order_id')) {
        $pstmt = $conn->prepare("SELECT id, purchase_number, budget_id FROM purchases WHERE purchase_order_id = ?");
        $pstmt->execute([$id]);
        $linkedPurchases = $pstmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($linkedPurchases as $lp) {
            if (!empty($lp['budget_id']) && (int)$lp['budget_id'] !== $budgetId) {
                throw new Exception(sprintf(__('po_link_purchase_other_budget'), $lp['purchase_number']));
            }
        }
    }

    $bStmt = $conn->prepare("SELECT id, budget_number, status, requisition_id FROM budgets WHERE id = ?");
    $bStmt->execute([$budgetId]);
    $budget = $bStmt->fetch(PDO::FETCH_ASSOC);
    if (!$budget) throw new Exception(__('budget_not_found'));
    if ($budget['status'] !== 'approved') throw new Exception(__('po_link_budget_must_be_approved'));

    // Budget allocations for THIS PO's supplier, with this PO's own outstanding
    // excluded so it is measured as if it were not yet counted.
    $remaining = getBudgetSupplierRemaining($budgetId, (int)$po['supplier_id'], $conn, $id);
    if (!$remaining) throw new Exception(__('po_link_no_allocations'));

    $dStmt = $conn->prepare("
        SELECT pod.product_id, COALESCE(pod.approved_quantity, pod.quantity) AS qty, p.name AS product_name
        FROM purchase_order_details pod
        JOIN products p ON pod.product_id = p.id
        WHERE pod.purchase_order_id = ?
    ");
    $dStmt->execute([$id]);
    $lines = $dStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$lines) throw new Exception(__('please_add_at_least_one_item'));

    // Every product must be on the budget for this supplier, and within its
    // remaining quantity. Quantities are summed per product first, because a PO
    // may legitimately carry the same product on more than one line.
    $wanted = [];
    $names  = [];
    foreach ($lines as $ln) {
        $pid           = (int)$ln['product_id'];
        $wanted[$pid]  = ($wanted[$pid] ?? 0) + (float)$ln['qty'];
        $names[$pid]   = $ln['product_name'];
    }

    $errors = [];
    foreach ($wanted as $pid => $qty) {
        if (!isset($remaining[$pid])) {
            $errors[] = sprintf(__('po_link_product_not_budgeted'), $names[$pid]);
            continue;
        }
        if ($qty > $remaining[$pid]['remaining'] + 0.0005) {
            $errors[] = sprintf(
                __('po_budget_over_remaining'),
                $names[$pid],
                formatNumber($qty, 2),
                formatNumber(max($remaining[$pid]['remaining'], 0), 2)
            );
        }
    }
    if ($errors) throw new Exception(implode(' ', $errors));

    // PO and its purchases move onto the budget together, or not at all.
    $conn->beginTransaction();

    $conn->prepare("UPDATE purchase_orders SET budget_id = ? WHERE id = ?")->execute([$budgetId, $id]);

    // Backfill the already-raised purchases so the quantity received shows as
    // "Procured" against the budget. Mirrors what api/purchases-sync.php does
    // when a purchase is created from a PO that was linked from the start:
    // budget_id, plus the budget's requisition_id when the purchase has none.
    $backfilled = 0;
    if ($linkedPurchases) {
        $hasBudgetCol = po_link_hasPurchaseCol($conn, 'budget_id');
        $hasReqCol    = po_link_hasPurchaseCol($conn, 'requisition_id');
        if ($hasBudgetCol) {
            $upd = $conn->prepare("UPDATE purchases SET budget_id = ? WHERE id = ? AND (budget_id IS NULL OR budget_id = ?)");
            $reqUpd = ($hasReqCol && !empty($budget['requisition_id']))
                ? $conn->prepare("UPDATE purchases SET requisition_id = ? WHERE id = ? AND requisition_id IS NULL")
                : null;
            foreach ($linkedPurchases as $lp) {
                $upd->execute([$budgetId, (int)$lp['id'], $budgetId]);
                if ($reqUpd) $reqUpd->execute([(int)$budget['requisition_id'], (int)$lp['id']]);
                $backfilled++;
            }
        }
    }

    logAudit('purchase_order', 'link_budget', $id, null, [
        'budget_id'            => $budgetId,
        'purchases_backfilled' => $backfilled,
    ]);

    $conn->commit();

    $message = sprintf(__('po_linked_to_budget'), $budget['budget_number']);
    if ($backfilled > 0) {
        $message .= ' ' . sprintf(__('po_link_purchases_backfilled'), $backfilled);
    }
    echo json_encode(['success' => true, 'message' => $message]);
}

/**
 * Does the purchases table have this column? Guards tenants that have not run
 * the relevant migration (purchase_order_id came with 0038, budget_id earlier).
 */
function po_link_hasPurchaseCol($conn, $column) {
    static $cache = [];
    if (!array_key_exists($column, $cache)) {
        try {
            $s = $conn->prepare("
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchases' AND COLUMN_NAME = ?
            ");
            $s->execute([$column]);
            $cache[$column] = ((int)$s->fetchColumn() > 0);
        } catch (PDOException $e) {
            $cache[$column] = false;
        }
    }
    return $cache[$column];
}
