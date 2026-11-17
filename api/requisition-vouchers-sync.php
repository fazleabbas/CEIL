<?php
/**
 * Requisition Vouchers Sync API
 * Handles create/update/delete/send_for_approval/approve/make_transfer/close operations
 */
require_once '../config/database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not authorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';
$data = $input['data'] ?? $input; // Support both nested and flat data

/**
 * Validate a signature data URL (base64 PNG/JPEG). Returns cleaned value or null.
 */
function rv_sanitizeSig($value) {
    if (!is_string($value) || $value === '') return null;
    if (!preg_match('#^data:image/(png|jpeg|jpg);base64,[A-Za-z0-9+/=]+$#', $value)) return null;
    if (strlen($value) > 2 * 1024 * 1024) return null;
    return $value;
}

/**
 * Check whether a column exists on a table. Cached per request via static.
 * Lets us emit a COALESCE only when the schema supports it, so this code
 * keeps working if migration 0023 hasn't been applied yet.
 */
function rv_hasColumn($conn, $table, $column) {
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
        ");
        $stmt->execute([$table, $column]);
        $cache[$key] = ((int)$stmt->fetchColumn()) > 0;
    } catch (PDOException $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

try {
    switch ($action) {
        case 'create':
            createRequisitionVoucher($conn, $data);
            break;

        case 'update':
            updateRequisitionVoucher($conn, $data);
            break;

        case 'delete':
            deleteRequisitionVoucher($conn, $data);
            break;

        case 'send_for_approval':
            sendForApproval($conn, $data);
            break;

        case 'approve':
            approveRequisitionVoucher($conn, $data);
            break;

        case 'reject':
            rejectRequisitionVoucher($conn, $data);
            break;

        case 'revise_approval':
            reviseApprovalQuantities($conn, $data);
            break;

        case 'change_destination':
            changeRequisitionDestination($conn, $data);
            break;

        case 'map_bom':
            mapRequisitionBom($conn, $data);
            break;

        case 'make_transfer':
            makeTransfer($conn, $data);
            break;

        case 'close':
            closeRequisitionVoucher($conn, $data);
            break;

        default:
            throw new Exception('Invalid action: ' . $action);
    }

} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Create a new draft requisition voucher
 */
function createRequisitionVoucher($conn, $data) {
    if (!hasPermission('requisition_vouchers', 'create')) {
        throw new Exception('No permission to create requisition vouchers');
    }

    // Validate required fields
    if (empty($data['destination_location_id'])) {
        throw new Exception('Destination location is required');
    }

    if (empty($data['voucher_date'])) {
        throw new Exception('Voucher date is required');
    }

    $details = $data['details'] ?? [];
    if (empty($details) || !is_array($details)) {
        throw new Exception('At least one item is required');
    }

    $destinationLocationId = (int)$data['destination_location_id'];
    $costCentreId = !empty($data['cost_centre_id']) ? (int)$data['cost_centre_id'] : null;
    $voucherDate = clean($data['voucher_date']);
    $remarks = clean($data['remarks'] ?? '');
    $tempId = $data['tempId'] ?? null;

    // Optional BOM link (migration 0033). Only persisted when the column exists.
    $hasBomCol = rv_hasColumn($conn, 'requisition_vouchers', 'bom_id');
    $bomId = ($hasBomCol && !empty($data['bom_id'])) ? (int)$data['bom_id'] : null;

    // Check user has access to the destination location
    if (!canAccessLocation($destinationLocationId)) {
        throw new Exception('You do not have access to the destination location');
    }

    // Only an APPROVED BOM may be linked (migration 0049).
    if ($bomLinkErr = bomLinkError($conn, $bomId)) {
        throw new Exception($bomLinkErr);
    }

    $conn->beginTransaction();

    try {
        // Generate voucher number
        $voucherNumber = getNextNumber('requisition_vouchers', 'voucher_number', getSetting('rv_prefix', 'RV'));

        $preparedSig = rv_sanitizeSig($data['prepared_by_signature'] ?? null);
        // Nothing drawn on the pad — use the creator's signature on file (0041).
        if ($preparedSig === null) {
            $preparedSig = getUserSignature($conn, getUserId());
        }

        // Insert requisition header (bom_id column included only when present).
        if ($hasBomCol) {
            $stmt = $conn->prepare("
                INSERT INTO requisition_vouchers
                (voucher_number, voucher_date, destination_location_id, cost_centre_id, bom_id, status, remarks, created_by, prepared_by_signature)
                VALUES (?, ?, ?, ?, ?, 'draft', ?, ?, ?)
            ");
            $stmt->execute([
                $voucherNumber,
                dbDate($voucherDate),
                $destinationLocationId,
                $costCentreId,
                $bomId,
                $remarks,
                getUserId(),
                $preparedSig
            ]);
        } else {
            $stmt = $conn->prepare("
                INSERT INTO requisition_vouchers
                (voucher_number, voucher_date, destination_location_id, cost_centre_id, status, remarks, created_by, prepared_by_signature)
                VALUES (?, ?, ?, ?, 'draft', ?, ?, ?)
            ");
            $stmt->execute([
                $voucherNumber,
                dbDate($voucherDate),
                $destinationLocationId,
                $costCentreId,
                $remarks,
                getUserId(),
                $preparedSig
            ]);
        }

        $requisitionId = $conn->lastInsertId();

        // Insert details
        $detailStmt = $conn->prepare("
            INSERT INTO requisition_voucher_details
            (requisition_id, product_id, requested_quantity, remarks)
            VALUES (?, ?, ?, ?)
        ");

        foreach ($details as $item) {
            $productId = (int)$item['product_id'];
            $quantity = floatval($item['quantity']);
            $itemRemarks = clean($item['remarks'] ?? '');

            if ($productId <= 0 || $quantity <= 0) continue;

            $detailStmt->execute([
                $requisitionId,
                $productId,
                $quantity,
                $itemRemarks
            ]);
        }

        $conn->commit();

        logAudit('requisition_voucher', 'create', $requisitionId);

        // Fetch the created requisition with details
        $requisition = getRequisitionVoucherById($conn, $requisitionId);

        echo json_encode([
            'success' => true,
            'message' => 'Requisition voucher created successfully',
            'serverId' => $requisitionId,
            'serverNumber' => $voucherNumber,
            'tempId' => $tempId,
            'requisition_voucher' => $requisition
        ]);

    } catch (PDOException $e) {
        $conn->rollBack();
        throw new Exception('Database error: ' . $e->getMessage());
    }
}

/**
 * Update an existing draft requisition voucher
 */
function updateRequisitionVoucher($conn, $data) {
    if (!hasPermission('requisition_vouchers', 'edit')) {
        throw new Exception('No permission to edit requisition vouchers');
    }

    $requisitionId = $data['id'] ?? $data['requisition_id'] ?? 0;
    if (empty($requisitionId)) {
        throw new Exception('Requisition ID is required');
    }

    // Check if requisition exists and is draft
    $checkStmt = $conn->prepare("SELECT status, destination_location_id FROM requisition_vouchers WHERE id = ?");
    $checkStmt->execute([$requisitionId]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        throw new Exception('Requisition voucher not found');
    }

    if ($existing['status'] !== 'draft') {
        throw new Exception('Only draft requisition vouchers can be edited');
    }

    $details = $data['details'] ?? [];

    $destinationLocationId = !empty($data['destination_location_id']) ? (int)$data['destination_location_id'] : (int)$existing['destination_location_id'];
    $costCentreId = !empty($data['cost_centre_id']) ? (int)$data['cost_centre_id'] : null;
    $voucherDate = !empty($data['voucher_date']) ? clean($data['voucher_date']) : null;
    $remarks = clean($data['remarks'] ?? '');

    // Optional BOM link (migration 0033). Only updated when the column exists
    // and the key is present in the payload.
    $hasBomCol = rv_hasColumn($conn, 'requisition_vouchers', 'bom_id');
    $bomKeyPresent = $hasBomCol && array_key_exists('bom_id', $data);
    $bomId = $bomKeyPresent ? (!empty($data['bom_id']) ? (int)$data['bom_id'] : null) : null;

    // Check user has access to the destination location
    if (!canAccessLocation($destinationLocationId)) {
        throw new Exception('You do not have access to the destination location');
    }

    // Only an APPROVED BOM may be newly linked (migration 0049); the existing
    // link is always kept.
    if ($bomKeyPresent && ($bomLinkErr = bomLinkError($conn, $bomId, $existing['bom_id'] ?? null))) {
        throw new Exception($bomLinkErr);
    }

    $conn->beginTransaction();

    try {
        // Only touch signature column if the key is present in the payload.
        $sigKeyPresent = array_key_exists('prepared_by_signature', $data);
        $preparedSig = $sigKeyPresent ? rv_sanitizeSig($data['prepared_by_signature']) : null;

        // Update requisition header
        $sql = "
            UPDATE requisition_vouchers SET
                destination_location_id = ?,
                cost_centre_id = ?,
                voucher_date = ?,
                remarks = ?,
                " . ($bomKeyPresent ? "bom_id = ?, " : "") . "
                " . ($sigKeyPresent ? "prepared_by_signature = ?, " : "") . "
                updated_at = NOW()
            WHERE id = ?
        ";
        $params = [
            $destinationLocationId,
            $costCentreId,
            $voucherDate ? dbDate($voucherDate) : null,
            $remarks,
        ];
        if ($bomKeyPresent) {
            $params[] = $bomId;
        }
        if ($sigKeyPresent) {
            $params[] = $preparedSig;
        }
        $params[] = $requisitionId;

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);

        // Delete existing details and re-insert
        if (!empty($details) && is_array($details)) {
            $deleteStmt = $conn->prepare("DELETE FROM requisition_voucher_details WHERE requisition_id = ?");
            $deleteStmt->execute([$requisitionId]);

            $detailStmt = $conn->prepare("
                INSERT INTO requisition_voucher_details
                (requisition_id, product_id, requested_quantity, remarks)
                VALUES (?, ?, ?, ?)
            ");

            foreach ($details as $item) {
                $productId = (int)$item['product_id'];
                $quantity = floatval($item['quantity']);
                $itemRemarks = clean($item['remarks'] ?? '');

                if ($productId <= 0 || $quantity <= 0) continue;

                $detailStmt->execute([
                    $requisitionId,
                    $productId,
                    $quantity,
                    $itemRemarks
                ]);
            }
        }

        $conn->commit();

        logAudit('requisition_voucher', 'update', $requisitionId);

        // Fetch the updated requisition with details
        $requisition = getRequisitionVoucherById($conn, $requisitionId);

        echo json_encode([
            'success' => true,
            'message' => 'Requisition voucher updated successfully',
            'serverId' => $requisitionId,
            'requisition_voucher' => $requisition
        ]);

    } catch (PDOException $e) {
        $conn->rollBack();
        throw new Exception('Database error: ' . $e->getMessage());
    }
}

/**
 * Delete a draft requisition voucher
 */
function deleteRequisitionVoucher($conn, $data) {
    if (!hasPermission('requisition_vouchers', 'delete')) {
        throw new Exception('No permission to delete requisition vouchers');
    }

    $requisitionId = $data['id'] ?? $data['requisition_id'] ?? 0;
    if (empty($requisitionId)) {
        throw new Exception('Requisition ID is required');
    }

    // Check if requisition exists and is draft
    $checkStmt = $conn->prepare("SELECT status FROM requisition_vouchers WHERE id = ?");
    $checkStmt->execute([$requisitionId]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        throw new Exception('Requisition voucher not found');
    }

    if ($existing['status'] !== 'draft') {
        throw new Exception('Only draft requisition vouchers can be deleted');
    }

    $conn->beginTransaction();

    try {
        // Delete header (details will cascade via FK)
        $deleteStmt = $conn->prepare("DELETE FROM requisition_vouchers WHERE id = ?");
        $deleteStmt->execute([$requisitionId]);

        $conn->commit();

        logAudit('requisition_voucher', 'delete', $requisitionId);

        echo json_encode([
            'success' => true,
            'message' => 'Requisition voucher deleted successfully'
        ]);

    } catch (PDOException $e) {
        $conn->rollBack();
        throw new Exception('Database error: ' . $e->getMessage());
    }
}

/**
 * Send requisition voucher for approval
 */
function sendForApproval($conn, $data) {
    if (!hasPermission('requisition_vouchers', 'edit')) {
        throw new Exception('No permission to send requisition vouchers for approval');
    }

    $requisitionId = $data['id'] ?? $data['requisition_id'] ?? 0;
    if (empty($requisitionId)) {
        throw new Exception('Requisition ID is required');
    }

    // Check if requisition exists and is draft
    $checkStmt = $conn->prepare("SELECT * FROM requisition_vouchers WHERE id = ?");
    $checkStmt->execute([$requisitionId]);
    $requisition = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$requisition) {
        throw new Exception('Requisition voucher not found');
    }

    if ($requisition['status'] !== 'draft') {
        throw new Exception('Only draft requisition vouchers can be sent for approval');
    }

    $conn->beginTransaction();

    try {
        // Update status to pending_approval
        $stmt = $conn->prepare("
            UPDATE requisition_vouchers SET
                status = 'pending_approval',
                sent_for_approval_by = ?,
                sent_for_approval_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([getUserId(), $requisitionId]);

        $conn->commit();

        logAudit('requisition_voucher', 'send_for_approval', $requisitionId);

        // Notify approvers — uses cost-centre-scoped override when configured,
        // otherwise falls back to the existing can_approve permission set.
        // Also sends a Fabonic-branded email when SMTP is configured.
        $fullRequisition = getRequisitionVoucherById($conn, $requisitionId);
        if ($fullRequisition) {
            notifyRequisitionApprovers($fullRequisition);
        }

        echo json_encode([
            'success' => true,
            'message' => 'Requisition voucher sent for approval'
        ]);

    } catch (PDOException $e) {
        $conn->rollBack();
        throw new Exception('Database error: ' . $e->getMessage());
    }
}

/**
 * Approve a requisition voucher
 */
function approveRequisitionVoucher($conn, $data) {
    if (!hasPermission('requisition_vouchers', 'approve')) {
        throw new Exception('No permission to approve requisition vouchers');
    }

    $requisitionId = $data['id'] ?? $data['requisition_id'] ?? 0;
    if (empty($requisitionId)) {
        throw new Exception('Requisition ID is required');
    }

    // Check if requisition exists and is pending_approval
    $checkStmt = $conn->prepare("SELECT * FROM requisition_vouchers WHERE id = ?");
    $checkStmt->execute([$requisitionId]);
    $requisition = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$requisition) {
        throw new Exception('Requisition voucher not found');
    }

    if ($requisition['status'] !== 'pending_approval') {
        throw new Exception('Only pending approval requisition vouchers can be approved');
    }

    // Cost-centre override: when the requisition's CC has approvers configured,
    // only those users are allowed to approve. If no CC or no approvers set,
    // falls back to the existing can_approve permission check (already passed above).
    if (!canApproveRequisitionForCostCentre(getUserId(), $requisition['cost_centre_id'] ?? null)) {
        throw new Exception('You are not authorised to approve requisitions for this cost centre');
    }

    $approvedSig = rv_sanitizeSig($data['approved_by_signature'] ?? null);
    // Nothing drawn on the pad — use the approver's signature on file (0041).
    if ($approvedSig === null) {
        $approvedSig = getUserSignature($conn, getUserId());
    }

    // Optional: items[] with { detail_id, approved_quantity } posted from the approve modal.
    // When omitted (older clients), we default approved_quantity = requested_quantity per row
    // so downstream code can rely on approved_quantity being non-NULL after approval.
    $postedItems = is_array($data['items'] ?? null) ? $data['items'] : [];
    $postedMap = [];
    foreach ($postedItems as $it) {
        $did = (int)($it['detail_id'] ?? 0);
        if ($did <= 0) continue;
        $postedMap[$did] = $it; // keep full item so we can read approved_quantity + approval_remarks
    }

    // Load existing details to validate against requested_quantity ceilings
    $detailStmt = $conn->prepare("SELECT id, requested_quantity FROM requisition_voucher_details WHERE requisition_id = ?");
    $detailStmt->execute([$requisitionId]);
    $existingDetails = $detailStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($existingDetails)) {
        throw new Exception('Requisition has no line items');
    }

    // Resolve approved_quantity per detail row with validation.
    // Rule: 0 <= approved_quantity <= requested_quantity. Default to requested when omitted.
    $resolved = []; // detail_id => ['qty' => float, 'remarks' => string]
    foreach ($existingDetails as $row) {
        $detailId = (int)$row['id'];
        $requestedQty = floatval($row['requested_quantity']);
        $postedItem = array_key_exists($detailId, $postedMap) ? $postedMap[$detailId] : null;
        $rawPosted = is_array($postedItem) ? ($postedItem['approved_quantity'] ?? null) : null;

        if ($rawPosted === null || $rawPosted === '') {
            $approvedQty = $requestedQty;
        } else {
            if (!is_numeric($rawPosted)) {
                throw new Exception('Invalid approved quantity for line ' . $detailId);
            }
            $approvedQty = floatval($rawPosted);
            if ($approvedQty < 0) {
                throw new Exception('Approved quantity cannot be negative');
            }
            // No upper limit: the approver may increase a line above the requested
            // quantity. 0 still means the line is rejected (nothing to dispatch).
            // Normalise to 3 decimal places (matches column precision)
            $approvedQty = round($approvedQty, 3);
        }

        // Optional per-line approver remark (stored separately from the requester's
        // remark so the original request text is preserved).
        $approvalRemarks = '';
        if (is_array($postedItem) && isset($postedItem['approval_remarks'])) {
            $approvalRemarks = clean((string)$postedItem['approval_remarks']);
        }

        $resolved[$detailId] = ['qty' => $approvedQty, 'remarks' => $approvalRemarks];
    }

    $conn->beginTransaction();

    try {
        // Persist approved_quantity (migration 0023) and approval_remarks (migration 0027)
        // per line — only writing columns that actually exist so older schemas keep working.
        $hasApprovedCol = rv_hasColumn($conn, 'requisition_voucher_details', 'approved_quantity');
        $hasApprovalRemarksCol = rv_hasColumn($conn, 'requisition_voucher_details', 'approval_remarks');

        if ($hasApprovedCol && $hasApprovalRemarksCol) {
            $updDetail = $conn->prepare("UPDATE requisition_voucher_details SET approved_quantity = ?, approval_remarks = ? WHERE id = ? AND requisition_id = ?");
            foreach ($resolved as $detailId => $r) {
                $updDetail->execute([$r['qty'], ($r['remarks'] !== '' ? $r['remarks'] : null), $detailId, $requisitionId]);
            }
        } elseif ($hasApprovedCol) {
            $updDetail = $conn->prepare("UPDATE requisition_voucher_details SET approved_quantity = ? WHERE id = ? AND requisition_id = ?");
            foreach ($resolved as $detailId => $r) {
                $updDetail->execute([$r['qty'], $detailId, $requisitionId]);
            }
        }

        // Update header: status, approver, timestamp, signature
        $stmt = $conn->prepare("
            UPDATE requisition_vouchers SET
                status = 'approved',
                approved_by = ?,
                approved_at = NOW(),
                approved_by_signature = ?
            WHERE id = ?
        ");
        $stmt->execute([getUserId(), $approvedSig, $requisitionId]);

        $conn->commit();

        logAudit('requisition_voucher', 'approve', $requisitionId);

        // Notify the creator + any cost-centre watchers that the requisition
        // has been approved. Sends Fabonic-branded email when SMTP is configured;
        // bell notification is always created regardless of email outcome.
        $fullRequisition = getRequisitionVoucherById($conn, $requisitionId);
        if ($fullRequisition) {
            notifyRequisitionApproved($fullRequisition);
        }

        echo json_encode([
            'success' => true,
            'message' => 'Requisition voucher approved'
        ]);

    } catch (PDOException $e) {
        $conn->rollBack();
        throw new Exception('Database error: ' . $e->getMessage());
    }
}

/**
 * Reject a pending requisition voucher (terminal state).
 *
 * Same authorisation rules as approve: caller needs the 'approve' permission
 * and, when the cost centre has approvers configured, must be one of them.
 * Reject reason is optional. Only 'pending_approval' vouchers can be rejected;
 * nothing is transferred and no line quantities are changed.
 */
function rejectRequisitionVoucher($conn, $data) {
    if (!hasPermission('requisition_vouchers', 'approve')) {
        throw new Exception('No permission to reject requisition vouchers');
    }

    $requisitionId = $data['id'] ?? $data['requisition_id'] ?? 0;
    if (empty($requisitionId)) {
        throw new Exception('Requisition ID is required');
    }

    $checkStmt = $conn->prepare("SELECT * FROM requisition_vouchers WHERE id = ?");
    $checkStmt->execute([$requisitionId]);
    $requisition = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$requisition) {
        throw new Exception('Requisition voucher not found');
    }

    if ($requisition['status'] !== 'pending_approval') {
        throw new Exception('Only pending approval requisition vouchers can be rejected');
    }

    // Mirror approve: enforce cost-centre approver override when configured.
    if (!canApproveRequisitionForCostCentre(getUserId(), $requisition['cost_centre_id'] ?? null)) {
        throw new Exception('You are not authorised to reject requisitions for this cost centre');
    }

    $rejectReason = clean($data['reject_reason'] ?? '');

    $conn->beginTransaction();

    try {
        $stmt = $conn->prepare("
            UPDATE requisition_vouchers SET
                status = 'rejected',
                rejected_by = ?,
                rejected_at = NOW(),
                reject_reason = ?
            WHERE id = ?
        ");
        $stmt->execute([getUserId(), $rejectReason, $requisitionId]);

        $conn->commit();

        logAudit('requisition_voucher', 'reject', $requisitionId);

        // Notify the creator (+ cost-centre watchers) that it was rejected.
        // Bell notification is always created; email is best-effort.
        $fullRequisition = getRequisitionVoucherById($conn, $requisitionId);
        if ($fullRequisition) {
            notifyRequisitionRejected($fullRequisition, $rejectReason);
        }

        echo json_encode([
            'success' => true,
            'message' => 'Requisition voucher rejected'
        ]);

    } catch (PDOException $e) {
        $conn->rollBack();
        throw new Exception('Database error: ' . $e->getMessage());
    }
}

/**
 * Revise the approved quantities (and per-line approval remarks) of an already
 * approved requisition, WITHOUT changing its status or any approval metadata.
 *
 * Allowed only while the requisition is still open (status = 'approved'); once
 * it is closed the quantities are locked. Partial transfers and linked purchases
 * do NOT block editing — but a line's new approved quantity can never drop below
 * what has already been transferred on that line (keeps remaining >= 0).
 *
 * Authorisation mirrors approve: caller needs the 'approve' permission and, when
 * the cost centre has approvers configured, must be one of them. Purely additive:
 * the approve / make_transfer / close paths are untouched.
 */
function reviseApprovalQuantities($conn, $data) {
    if (!hasPermission('requisition_vouchers', 'approve')) {
        throw new Exception('No permission to revise approved quantities');
    }

    $requisitionId = $data['id'] ?? $data['requisition_id'] ?? 0;
    if (empty($requisitionId)) {
        throw new Exception('Requisition ID is required');
    }

    $checkStmt = $conn->prepare("SELECT * FROM requisition_vouchers WHERE id = ?");
    $checkStmt->execute([$requisitionId]);
    $requisition = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$requisition) {
        throw new Exception('Requisition voucher not found');
    }

    // Only an OPEN (approved) requisition may be revised. Closed = locked.
    if ($requisition['status'] !== 'approved') {
        throw new Exception('Only open (approved) requisition vouchers can have their quantities revised');
    }

    // Same cost-centre approver override as approve/reject.
    if (!canApproveRequisitionForCostCentre(getUserId(), $requisition['cost_centre_id'] ?? null)) {
        throw new Exception('You are not authorised to revise requisitions for this cost centre');
    }

    // Posted items[] = { detail_id, approved_quantity, approval_remarks }
    $postedItems = is_array($data['items'] ?? null) ? $data['items'] : [];
    $postedMap = [];
    foreach ($postedItems as $it) {
        $did = (int)($it['detail_id'] ?? 0);
        if ($did <= 0) continue;
        $postedMap[$did] = $it;
    }

    if (empty($postedMap)) {
        throw new Exception('No quantities were submitted');
    }

    // Load existing lines with their already-transferred quantities so we can
    // enforce the per-line floor (approved_quantity >= transferred_quantity).
    $detailStmt = $conn->prepare("
        SELECT rvd.id, rvd.product_id, rvd.requested_quantity, rvd.transferred_quantity, p.name AS product_name
        FROM requisition_voucher_details rvd
        JOIN products p ON rvd.product_id = p.id
        WHERE rvd.requisition_id = ?
    ");
    $detailStmt->execute([$requisitionId]);
    $existingDetails = $detailStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($existingDetails)) {
        throw new Exception('Requisition has no line items');
    }

    // Resolve + validate each line that was posted. Lines not posted are left as-is.
    $resolved = []; // detail_id => ['qty' => float, 'remarks' => string|null]
    foreach ($existingDetails as $row) {
        $detailId = (int)$row['id'];
        if (!array_key_exists($detailId, $postedMap)) continue;

        $postedItem = $postedMap[$detailId];
        $rawPosted = $postedItem['approved_quantity'] ?? null;

        if ($rawPosted === null || $rawPosted === '' || !is_numeric($rawPosted)) {
            throw new Exception('Invalid approved quantity for ' . $row['product_name']);
        }
        $approvedQty = round(floatval($rawPosted), 3);
        if ($approvedQty < 0) {
            throw new Exception('Approved quantity cannot be negative for ' . $row['product_name']);
        }

        // Floor: cannot reduce below what is already transferred on this line.
        $transferred = floatval($row['transferred_quantity']);
        if ($approvedQty < $transferred - 0.0001) {
            throw new Exception(
                'Approved quantity (' . number_format($approvedQty, 3) . ') for ' . $row['product_name'] .
                ' cannot be below the already-transferred quantity (' . number_format($transferred, 3) . ')'
            );
        }

        $approvalRemarks = '';
        if (isset($postedItem['approval_remarks'])) {
            $approvalRemarks = clean((string)$postedItem['approval_remarks']);
        }

        $resolved[$detailId] = ['qty' => $approvedQty, 'remarks' => $approvalRemarks];
    }

    if (empty($resolved)) {
        throw new Exception('No matching line items to revise');
    }

    $conn->beginTransaction();

    try {
        // Only write columns that exist (mirrors approve() so older schemas work).
        $hasApprovedCol = rv_hasColumn($conn, 'requisition_voucher_details', 'approved_quantity');
        $hasApprovalRemarksCol = rv_hasColumn($conn, 'requisition_voucher_details', 'approval_remarks');

        if ($hasApprovedCol && $hasApprovalRemarksCol) {
            $updDetail = $conn->prepare("UPDATE requisition_voucher_details SET approved_quantity = ?, approval_remarks = ? WHERE id = ? AND requisition_id = ?");
            foreach ($resolved as $detailId => $r) {
                $updDetail->execute([$r['qty'], ($r['remarks'] !== '' ? $r['remarks'] : null), $detailId, $requisitionId]);
            }
        } elseif ($hasApprovedCol) {
            $updDetail = $conn->prepare("UPDATE requisition_voucher_details SET approved_quantity = ? WHERE id = ? AND requisition_id = ?");
            foreach ($resolved as $detailId => $r) {
                $updDetail->execute([$r['qty'], $detailId, $requisitionId]);
            }
        } else {
            throw new Exception('This installation does not support per-line approved quantities');
        }

        // Status / approver / signature are intentionally left unchanged. We also
        // do NOT auto-close even if every line is now fully transferred — staying
        // open is the expected state; the approver closes manually.

        $conn->commit();

        logAudit('requisition_voucher', 'revise_approval', $requisitionId);

        // Notify the original requester that approved quantities were revised.
        $fullRequisition = getRequisitionVoucherById($conn, $requisitionId);
        if ($fullRequisition) {
            notifyRequisitionRevised($fullRequisition);
        }

        echo json_encode([
            'success' => true,
            'message' => 'Approved quantities updated'
        ]);

    } catch (PDOException $e) {
        $conn->rollBack();
        throw new Exception('Database error: ' . $e->getMessage());
    }
}

/**
 * Change the destination location of an approved requisition voucher.
 *
 * Allowed only while the requisition is still open (status = 'approved') AND no
 * stock transfer has yet been made against it. Once anything has been dispatched
 * the destination is locked, because the transferred stock already committed to
 * the old destination and moving it would leave the records inconsistent.
 *
 * Status is intentionally left unchanged: no re-approval is required because the
 * approved items and quantities are untouched — only where they will be delivered.
 *
 * Authorisation mirrors approve/reject/revise: caller needs the 'approve'
 * permission and, when the cost centre has approvers configured, must be one of
 * them. The caller must also have access to the new destination location.
 */
function changeRequisitionDestination($conn, $data) {
    if (!hasPermission('requisition_vouchers', 'approve')) {
        throw new Exception('No permission to change the destination location');
    }

    $requisitionId = $data['id'] ?? $data['requisition_id'] ?? 0;
    if (empty($requisitionId)) {
        throw new Exception('Requisition ID is required');
    }

    $newDestinationId = !empty($data['destination_location_id']) ? (int)$data['destination_location_id'] : 0;
    if ($newDestinationId <= 0) {
        throw new Exception('Destination location is required');
    }

    $checkStmt = $conn->prepare("SELECT * FROM requisition_vouchers WHERE id = ?");
    $checkStmt->execute([$requisitionId]);
    $requisition = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$requisition) {
        throw new Exception('Requisition voucher not found');
    }

    // Only an OPEN (approved) requisition may have its destination changed.
    if ($requisition['status'] !== 'approved') {
        throw new Exception('Only open (approved) requisition vouchers can have their destination changed');
    }

    // Same cost-centre approver override as approve/reject/revise.
    if (!canApproveRequisitionForCostCentre(getUserId(), $requisition['cost_centre_id'] ?? null)) {
        throw new Exception('You are not authorised to change the destination for this cost centre');
    }

    // Hard guard: once ANY transfer exists against this requisition the destination
    // is locked, because dispatched stock already committed to the old destination.
    $transferStmt = $conn->prepare("SELECT COUNT(*) FROM stock_transfers WHERE requisition_id = ?");
    $transferStmt->execute([$requisitionId]);
    if ((int)$transferStmt->fetchColumn() > 0) {
        throw new Exception('Cannot change destination after a transfer has been made');
    }

    // Caller must have access to the new destination.
    if (!canAccessLocation($newDestinationId)) {
        throw new Exception('You do not have access to the selected destination location');
    }

    // No-op when the destination is unchanged.
    if ((int)$requisition['destination_location_id'] === $newDestinationId) {
        echo json_encode([
            'success' => true,
            'message' => 'Destination location unchanged'
        ]);
        return;
    }

    $conn->beginTransaction();

    try {
        $stmt = $conn->prepare("
            UPDATE requisition_vouchers SET
                destination_location_id = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$newDestinationId, $requisitionId]);

        $conn->commit();

        logAudit('requisition_voucher', 'change_destination', $requisitionId);

        echo json_encode([
            'success' => true,
            'message' => 'Destination location updated'
        ]);

    } catch (PDOException $e) {
        $conn->rollBack();
        throw new Exception('Database error: ' . $e->getMessage());
    }
}

/**
 * Map an EXISTING requisition voucher to a Bill of Materials, change which BOM
 * it points at, or unlink it entirely.
 *
 * WHY THIS EXISTS: bom_id (migration 0033) could previously only be set on the
 * create/edit form, which is draft-only. A requisition that reached
 * pending_approval or beyond could therefore never be linked to a BOM, even
 * though that is exactly when people notice the omission. This action is the
 * view screen's way in, and it deliberately accepts ANY status (user-confirmed)
 * so an already-fulfilled or closed requisition can still be mapped after the
 * fact.
 *
 * IT WRITES ONE COLUMN. No stock, no GL, no details — the BOM link is reference
 * data that only drives the balance figures described below.
 *
 * NOT INERT, THOUGH: getBomProductBalances() derives "transferred so far" by
 * joining every requisition whose bom_id matches, so linking a requisition that
 * already has transfers immediately CONSUMES that BOM's remaining balance for
 * everyone, and unlinking gives it back. That is the intended behaviour for
 * retro-mapping, but it is why this is permission-gated and audited.
 */
function mapRequisitionBom($conn, $data) {
    // Either flag admits (user-confirmed). `edit` lets the person who raised the
    // requisition correct their own omission; `approve` covers cost-centre
    // approvers, who on some tenants are the only non-Admins with any authority
    // over a requisition once it has left draft.
    if (!hasPermission('requisition_vouchers', 'edit') && !hasPermission('requisition_vouchers', 'approve')) {
        throw new Exception('No permission to map a requisition voucher to a BOM');
    }

    // Pre-0033 tenants have no column to write. Fail loudly rather than
    // silently accepting a mapping that goes nowhere.
    if (!rv_hasColumn($conn, 'requisition_vouchers', 'bom_id')) {
        throw new Exception('BOM linking is not available on this installation');
    }

    $requisitionId = (int)($data['requisition_id'] ?? $data['id'] ?? 0);
    if (empty($requisitionId)) {
        throw new Exception('Requisition ID is required');
    }

    $checkStmt = $conn->prepare("SELECT * FROM requisition_vouchers WHERE id = ?");
    $checkStmt->execute([$requisitionId]);
    $requisition = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$requisition) {
        throw new Exception('Requisition voucher not found');
    }

    // No status gate by design — see the docblock. The caller must still have
    // access to the requisition's own destination location, matching every
    // other mutation in this file.
    if (!canAccessLocation((int)$requisition['destination_location_id'])) {
        throw new Exception('You do not have access to this requisition voucher');
    }

    // Empty / 0 / null all mean UNLINK.
    $newBomId = !empty($data['bom_id']) ? (int)$data['bom_id'] : null;

    if ($newBomId !== null) {
        // The BOM must exist AND live in a location the caller can reach. The
        // dropdown already filters this way; re-checking here stops a
        // hand-crafted payload tagging a requisition to a BOM its author
        // cannot see.
        $bomStmt = $conn->prepare("SELECT id, bom_number, location_id FROM boms WHERE id = ?");
        $bomStmt->execute([$newBomId]);
        $bom = $bomStmt->fetch(PDO::FETCH_ASSOC);

        if (!$bom) {
            throw new Exception('Bill of Materials not found');
        }

        if (!canAccessLocation((int)$bom['location_id'])) {
            throw new Exception('You do not have access to the selected Bill of Materials');
        }

        // Only an APPROVED BOM may be newly linked (migration 0049).
        if ($bomLinkErr = bomLinkError($conn, $newBomId, $requisition['bom_id'] ?? null)) {
            throw new Exception($bomLinkErr);
        }
    }

    // No-op when nothing changes, so an accidental re-save neither bumps
    // updated_at nor writes an audit row. Mirrors change_destination.
    $currentBomId = !empty($requisition['bom_id']) ? (int)$requisition['bom_id'] : null;
    if ($currentBomId === $newBomId) {
        echo json_encode([
            'success' => true,
            'message' => 'BOM link unchanged'
        ]);
        return;
    }

    $conn->beginTransaction();

    try {
        $stmt = $conn->prepare("
            UPDATE requisition_vouchers SET
                bom_id = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$newBomId, $requisitionId]);

        $conn->commit();

        logAudit('requisition_voucher', $newBomId === null ? 'unmap_bom' : 'map_bom', $requisitionId);

        echo json_encode([
            'success' => true,
            'message' => $newBomId === null ? 'BOM link removed' : 'BOM linked'
        ]);

    } catch (PDOException $e) {
        $conn->rollBack();
        throw new Exception('Database error: ' . $e->getMessage());
    }
}

/**
 * Create a stock transfer from an approved requisition voucher
 */
function makeTransfer($conn, $data) {
    if (!hasPermission('stock_transfer', 'create')) {
        throw new Exception('No permission to create stock transfers');
    }

    $requisitionId = (int)($data['requisition_id'] ?? $data['id'] ?? 0);
    $sourceLocationId = (int)($data['source_location_id'] ?? 0);
    $items = $data['items'] ?? [];

    if (empty($requisitionId)) {
        throw new Exception('Requisition ID is required');
    }

    if (empty($sourceLocationId)) {
        throw new Exception('Source location is required');
    }

    if (empty($items) || !is_array($items)) {
        throw new Exception('At least one item is required');
    }

    // Check source location access
    if (!canAccessLocation($sourceLocationId)) {
        throw new Exception('You do not have access to the source location');
    }

    $conn->beginTransaction();

    try {
        // Lock the requisition row for update
        $lockStmt = $conn->prepare("SELECT * FROM requisition_vouchers WHERE id = ? FOR UPDATE");
        $lockStmt->execute([$requisitionId]);
        $requisition = $lockStmt->fetch(PDO::FETCH_ASSOC);

        if (!$requisition) {
            throw new Exception('Requisition voucher not found');
        }

        if ($requisition['status'] !== 'approved') {
            throw new Exception('Only approved requisition vouchers can be transferred');
        }

        $destinationLocationId = (int)$requisition['destination_location_id'];

        // Source and destination must be different
        if ($sourceLocationId === $destinationLocationId) {
            throw new Exception('Source and destination locations must be different');
        }

        // Get all requisition details for validation
        $reqDetailsStmt = $conn->prepare("SELECT * FROM requisition_voucher_details WHERE requisition_id = ?");
        $reqDetailsStmt->execute([$requisitionId]);
        $reqDetails = $reqDetailsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Build a map of requisition details by id
        $reqDetailMap = [];
        foreach ($reqDetails as $rd) {
            $reqDetailMap[$rd['id']] = $rd;
        }

        // Validate each item
        foreach ($items as $item) {
            $detailId = (int)($item['requisition_detail_id'] ?? $item['detail_id'] ?? 0);
            $quantity = floatval($item['quantity'] ?? 0);

            if ($detailId <= 0 || $quantity <= 0) {
                throw new Exception('Invalid item data: detail ID and quantity are required');
            }

            if (!isset($reqDetailMap[$detailId])) {
                throw new Exception('Requisition detail #' . $detailId . ' not found');
            }

            $rd = $reqDetailMap[$detailId];
            // Use approved_quantity when set (post-migration 0023); fall back to requested_quantity
            // for legacy rows so old approved requisitions still dispatch correctly.
            $effectiveCap = (isset($rd['approved_quantity']) && $rd['approved_quantity'] !== null)
                ? floatval($rd['approved_quantity'])
                : floatval($rd['requested_quantity']);
            $remainingQty = $effectiveCap - floatval($rd['transferred_quantity']);

            if ($quantity > $remainingQty + 0.0001) {
                $prodStmt = $conn->prepare("SELECT name FROM products WHERE id = ?");
                $prodStmt->execute([$rd['product_id']]);
                $productName = $prodStmt->fetchColumn();
                throw new Exception('Transfer quantity (' . number_format($quantity, 3) . ') exceeds remaining quantity (' . number_format($remainingQty, 3) . ') for ' . $productName);
            }
        }

        // Generate transfer number
        $transferNumber = getNextNumber('stock_transfers', 'transfer_number', 'TRF');

        // Get currency defaults
        $currencyId = null;
        $exchangeRateToBase = 1;
        $defaultCurrency = getDefaultCurrency();
        if ($defaultCurrency) {
            $currencyId = $defaultCurrency['id'];
        }

        // Insert stock transfer header
        $stmt = $conn->prepare("
            INSERT INTO stock_transfers
            (transfer_number, from_location_id, to_location_id, requisition_id, cost_centre_id,
             currency_id, exchange_rate_to_base, transfer_date, status, remarks, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?)
        ");
        $stmt->execute([
            $transferNumber,
            $sourceLocationId,
            $destinationLocationId,
            $requisitionId,
            $requisition['cost_centre_id'],
            $currencyId,
            $exchangeRateToBase,
            date('Y-m-d'),
            'Transfer from requisition ' . $requisition['voucher_number'],
            getUserId()
        ]);

        $transferId = $conn->lastInsertId();

        // Insert stock transfer details and update requisition transferred quantities
        $transferDetailStmt = $conn->prepare("
            INSERT INTO stock_transfer_details
            (transfer_id, product_id, requisition_detail_id, dispatched_quantity, rate_per_unit)
            VALUES (?, ?, ?, ?, 0)
        ");

        $updateTransferredStmt = $conn->prepare("
            UPDATE requisition_voucher_details
            SET transferred_quantity = transferred_quantity + ?
            WHERE id = ?
        ");

        foreach ($items as $item) {
            $detailId = (int)($item['requisition_detail_id'] ?? $item['detail_id'] ?? 0);
            $productId = (int)$item['product_id'];
            $quantity = floatval($item['quantity']);

            // Insert transfer detail
            $transferDetailStmt->execute([
                $transferId,
                $productId,
                $detailId,
                $quantity
            ]);

            // Update transferred quantity on requisition detail
            $updateTransferredStmt->execute([
                $quantity,
                $detailId
            ]);
        }

        // Check auto-close: if ALL details have transferred_quantity >= effective cap.
        // Effective cap is approved_quantity when set (post-migration 0023), else
        // requested_quantity. The COALESCE form is only safe when the column
        // exists, so we pick the SQL based on the schema at runtime.
        if (rv_hasColumn($conn, 'requisition_voucher_details', 'approved_quantity')) {
            $checkCloseStmt = $conn->prepare("
                SELECT COUNT(*) FROM requisition_voucher_details
                WHERE requisition_id = ? AND transferred_quantity < COALESCE(approved_quantity, requested_quantity)
            ");
        } else {
            $checkCloseStmt = $conn->prepare("
                SELECT COUNT(*) FROM requisition_voucher_details
                WHERE requisition_id = ? AND transferred_quantity < requested_quantity
            ");
        }
        $checkCloseStmt->execute([$requisitionId]);
        $pendingCount = (int)$checkCloseStmt->fetchColumn();

        if ($pendingCount === 0) {
            // All items fully transferred - auto-close the requisition
            $closeStmt = $conn->prepare("
                UPDATE requisition_vouchers SET
                    status = 'closed',
                    closed_by = ?,
                    closed_at = NOW()
                WHERE id = ?
            ");
            $closeStmt->execute([getUserId(), $requisitionId]);

            logAudit('requisition_voucher', 'auto_close', $requisitionId);
        }

        $conn->commit();

        logAudit('stock_transfer', 'create', $transferId);
        logAudit('requisition_voucher', 'make_transfer', $requisitionId);

        echo json_encode([
            'success' => true,
            'message' => 'Stock transfer created from requisition',
            'transfer_id' => $transferId,
            'transfer_number' => $transferNumber
        ]);

    } catch (PDOException $e) {
        $conn->rollBack();
        throw new Exception('Database error: ' . $e->getMessage());
    }
}

/**
 * Manually close a requisition voucher
 */
function closeRequisitionVoucher($conn, $data) {
    if (!hasPermission('requisition_vouchers', 'edit')) {
        throw new Exception('No permission to close requisition vouchers');
    }

    $requisitionId = $data['id'] ?? $data['requisition_id'] ?? 0;
    if (empty($requisitionId)) {
        throw new Exception('Requisition ID is required');
    }

    // Check if requisition exists and is approved
    $checkStmt = $conn->prepare("SELECT status FROM requisition_vouchers WHERE id = ?");
    $checkStmt->execute([$requisitionId]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        throw new Exception('Requisition voucher not found');
    }

    if ($existing['status'] !== 'approved') {
        throw new Exception('Only approved requisition vouchers can be manually closed');
    }

    $closeReason = clean($data['close_reason'] ?? '');

    $conn->beginTransaction();

    try {
        $stmt = $conn->prepare("
            UPDATE requisition_vouchers SET
                status = 'closed',
                closed_by = ?,
                closed_at = NOW(),
                close_reason = ?
            WHERE id = ?
        ");
        $stmt->execute([getUserId(), $closeReason, $requisitionId]);

        $conn->commit();

        logAudit('requisition_voucher', 'close', $requisitionId);

        echo json_encode([
            'success' => true,
            'message' => 'Requisition voucher closed'
        ]);

    } catch (PDOException $e) {
        $conn->rollBack();
        throw new Exception('Database error: ' . $e->getMessage());
    }
}

/**
 * Get requisition voucher by ID with details
 */
function getRequisitionVoucherById($conn, $requisitionId) {
    $stmt = $conn->prepare("
        SELECT
            rv.*,
            l.name as destination_location_name,
            cc.name as cost_centre_name,
            cc.code as cost_centre_code,
            u.name as created_by_name,
            u2.name as sent_for_approval_by_name,
            u3.name as approved_by_name,
            u4.name as closed_by_name
        FROM requisition_vouchers rv
        LEFT JOIN locations l ON rv.destination_location_id = l.id
        LEFT JOIN cost_centres cc ON rv.cost_centre_id = cc.id
        LEFT JOIN users u ON rv.created_by = u.id
        LEFT JOIN users u2 ON rv.sent_for_approval_by = u2.id
        LEFT JOIN users u3 ON rv.approved_by = u3.id
        LEFT JOIN users u4 ON rv.closed_by = u4.id
        WHERE rv.id = ?
    ");
    $stmt->execute([$requisitionId]);
    $requisition = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$requisition) {
        return null;
    }

    // Fetch details
    $detailsStmt = $conn->prepare("
        SELECT
            rvd.*,
            p.name as product_name,
            p.unit,
            pc.name as category_name
        FROM requisition_voucher_details rvd
        JOIN products p ON rvd.product_id = p.id
        LEFT JOIN product_categories pc ON p.category_id = pc.id
        WHERE rvd.requisition_id = ?
        ORDER BY rvd.id
    ");
    $detailsStmt->execute([$requisitionId]);
    $requisition['details'] = $detailsStmt->fetchAll(PDO::FETCH_ASSOC);
    $requisition['synced'] = true;

    return $requisition;
}
