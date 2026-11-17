<?php
/**
 * Stock Transfers Sync API
 * Handles create/update/delete/dispatch/cancel operations for sync
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
 * Validate a signature data URL.
 * Returns the cleaned string, or null if missing/invalid.
 */
function sanitizeSignatureDataUrl($value) {
    if ($value === null || $value === '' || !is_string($value)) {
        return null;
    }
    // Must look like data:image/(png|jpeg);base64,...
    if (!preg_match('#^data:image/(png|jpeg|jpg);base64,[A-Za-z0-9+/=]+$#', $value)) {
        return null;
    }
    // Cap size at ~2MB to avoid abuse
    if (strlen($value) > 2 * 1024 * 1024) {
        return null;
    }
    return $value;
}

try {
    switch ($action) {
        case 'create':
            if (!hasPermission('stock_transfer', 'create')) {
                throw new Exception('No permission to create transfers');
            }

            // Validate required fields
            if (empty($data['from_location_id']) || empty($data['to_location_id'])) {
                throw new Exception('From and To locations are required');
            }

            $details = $data['details'] ?? $data['items'] ?? [];
            if (empty($details) || !is_array($details)) {
                throw new Exception('Transfer items are required');
            }

            // Check location access
            if (!canAccessLocation($data['from_location_id'])) {
                throw new Exception('You do not have access to the source location');
            }

            $conn->beginTransaction();

            // Generate transfer number
            $transferNumber = getNextNumber('stock_transfers', 'transfer_number', 'TRF');

            // Insert transfer
            $costCentreId = !empty($data['cost_centre_id']) ? (int)$data['cost_centre_id'] : null;

            // Currency support
            $currencyId = !empty($data['currency_id']) ? (int)$data['currency_id'] : null;
            $exchangeRateToBase = !empty($data['exchange_rate_to_base']) ? floatval($data['exchange_rate_to_base']) : 1;
            if (!$currencyId) {
                $defaultCurrency = getDefaultCurrency();
                $currencyId = $defaultCurrency ? $defaultCurrency['id'] : null;
            }

            $preparedSig = sanitizeSignatureDataUrl($data['prepared_by_signature'] ?? null);
            // Nothing drawn on the pad — use the creator's signature on file (0041).
            if ($preparedSig === null) {
                $preparedSig = getUserSignature($conn, getUserId());
            }

            $stmt = $conn->prepare("
                INSERT INTO stock_transfers
                (transfer_number, from_location_id, to_location_id, cost_centre_id, currency_id, exchange_rate_to_base,
                 transfer_date, vehicle_number, driver_name, driver_phone, remarks, status, created_by, prepared_by_signature)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?)
            ");
            $stmt->execute([
                $transferNumber,
                $data['from_location_id'],
                $data['to_location_id'],
                $costCentreId,
                $currencyId,
                $exchangeRateToBase,
                dbDate($data['transfer_date'] ?? date('Y-m-d')),
                clean($data['vehicle_number'] ?? ''),
                clean($data['driver_name'] ?? ''),
                clean($data['driver_phone'] ?? ''),
                clean($data['remarks'] ?? ''),
                getUserId(),
                $preparedSig
            ]);

            $transferId = $conn->lastInsertId();

            // Insert details
            $detailStmt = $conn->prepare("
                INSERT INTO stock_transfer_details
                (transfer_id, product_id, dispatched_quantity, rate_per_unit)
                VALUES (?, ?, ?, ?)
            ");

            foreach ($details as $item) {
                $qty = floatval($item['quantity'] ?? $item['dispatched_quantity'] ?? 0);
                $rate = floatval($item['rate'] ?? $item['rate_per_unit'] ?? 0);

                $detailStmt->execute([
                    $transferId,
                    $item['product_id'],
                    $qty,
                    $rate
                ]);
            }

            $conn->commit();

            logAudit('stock_transfer', 'create', $transferId);

            echo json_encode([
                'success' => true,
                'serverId' => $transferId,
                'serverNumber' => $transferNumber
            ]);
            break;

        case 'create_reverse':
            // Create a REVERSE transfer: return goods that were transferred to a
            // location back to the source. Stored as an ordinary stock_transfers
            // row (so it reuses the list/view/print and the draft -> in_transit ->
            // received lifecycle) but flagged via reverse_of_transfer_id, with the
            // original's from/to swapped and its cost centre kept. Each line copies
            // the original line's requisition_detail_id so that when this reverse is
            // RECEIVED (see the 'receive' action) it can decrement the requisition's
            // transferred_quantity, which restores the BOM balance live. This action
            // itself does NOT touch stock or transferred_quantity.
            if (!hasPermission('stock_transfer', 'create')) {
                throw new Exception('No permission to create transfers');
            }

            $parentId = (int)($data['reverse_of_transfer_id'] ?? $data['parent_id'] ?? 0);
            if ($parentId <= 0) {
                throw new Exception('Original transfer is required');
            }

            $pStmt = $conn->prepare("SELECT * FROM stock_transfers WHERE id = ?");
            $pStmt->execute([$parentId]);
            $parent = $pStmt->fetch(PDO::FETCH_ASSOC);
            if (!$parent) {
                throw new Exception('Original transfer not found');
            }
            if ($parent['status'] !== 'received') {
                throw new Exception('Only received transfers can be reversed');
            }
            if (!empty($parent['reverse_of_transfer_id'])) {
                throw new Exception('A reverse transfer cannot itself be reversed');
            }

            // Goods physically sit at the parent's destination; that becomes the
            // reverse's source, so the user must have access to it.
            if (!canAccessLocation($parent['to_location_id'])) {
                throw new Exception('You do not have access to the returning location');
            }

            $details = $data['details'] ?? $data['items'] ?? [];
            if (empty($details) || !is_array($details)) {
                throw new Exception('Return items are required');
            }

            // Original lines keyed by product (the form forbids a duplicate product
            // per transfer, so product_id uniquely identifies the original line).
            $origStmt = $conn->prepare("SELECT * FROM stock_transfer_details WHERE transfer_id = ?");
            $origStmt->execute([$parentId]);
            $origLines = [];
            foreach ($origStmt->fetchAll(PDO::FETCH_ASSOC) as $ol) {
                $origLines[(int)$ol['product_id']] = $ol;
            }

            // Already-returned per product across prior (non-cancelled) reverses.
            $alreadyReturned = getTransferReturnedQty($parentId, $conn);

            $toInsert = [];
            foreach ($details as $item) {
                $pid = (int)($item['product_id'] ?? 0);
                $qty = floatval($item['quantity'] ?? $item['dispatched_quantity'] ?? 0);
                if ($pid <= 0 || $qty <= 0) {
                    continue; // skip untouched rows (return qty defaults to 0)
                }
                if (!isset($origLines[$pid])) {
                    throw new Exception('Product not part of the original transfer');
                }
                $ol = $origLines[$pid];
                // Only what actually arrived at the destination is returnable.
                $received = ($ol['received_quantity'] !== null)
                    ? floatval($ol['received_quantity'])
                    : floatval($ol['dispatched_quantity']);
                $remaining = $received - (float)($alreadyReturned[$pid] ?? 0);
                if ($qty > $remaining + 0.0001) {
                    $prodStmt = $conn->prepare("SELECT name FROM products WHERE id = ?");
                    $prodStmt->execute([$pid]);
                    $pn = $prodStmt->fetchColumn();
                    throw new Exception('Return quantity (' . number_format($qty, 3) . ') exceeds returnable ('
                        . number_format(max(0, $remaining), 3) . ') for ' . $pn);
                }
                $toInsert[] = [
                    'product_id' => $pid,
                    'requisition_detail_id' => $ol['requisition_detail_id'],
                    'quantity' => $qty,
                    'rate' => floatval($ol['rate_per_unit']),
                ];
            }

            if (empty($toInsert)) {
                throw new Exception('Enter a return quantity for at least one item');
            }

            $conn->beginTransaction();

            $reverseNumber = getNextNumber('stock_transfers', 'transfer_number', getSetting('reverse_transfer_prefix', 'RT'));
            $preparedSig = sanitizeSignatureDataUrl($data['prepared_by_signature'] ?? null);
            // Nothing drawn on the pad — use the creator's signature on file (0041).
            if ($preparedSig === null) {
                $preparedSig = getUserSignature($conn, getUserId());
            }

            $stmt = $conn->prepare("
                INSERT INTO stock_transfers
                (transfer_number, from_location_id, to_location_id, reverse_of_transfer_id, cost_centre_id,
                 currency_id, exchange_rate_to_base, transfer_date, remarks, status, created_by, prepared_by_signature)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?)
            ");
            $stmt->execute([
                $reverseNumber,
                $parent['to_location_id'],   // from = original destination
                $parent['from_location_id'], // to   = original source
                $parentId,
                $parent['cost_centre_id'],
                $parent['currency_id'],
                $parent['exchange_rate_to_base'],
                dbDate($data['transfer_date'] ?? date('Y-m-d')),
                clean($data['remarks'] ?? ('Reverse of ' . $parent['transfer_number'])),
                getUserId(),
                $preparedSig
            ]);
            $reverseId = $conn->lastInsertId();

            $detailStmt = $conn->prepare("
                INSERT INTO stock_transfer_details
                (transfer_id, product_id, requisition_detail_id, dispatched_quantity, rate_per_unit)
                VALUES (?, ?, ?, ?, ?)
            ");
            foreach ($toInsert as $ti) {
                $detailStmt->execute([
                    $reverseId,
                    $ti['product_id'],
                    $ti['requisition_detail_id'],
                    $ti['quantity'],
                    $ti['rate']
                ]);
            }

            $conn->commit();

            logAudit('stock_transfer', 'create_reverse', $reverseId);

            echo json_encode([
                'success' => true,
                'serverId' => $reverseId,
                'serverNumber' => $reverseNumber
            ]);
            break;

        case 'update':
            if (!hasPermission('stock_transfer', 'edit')) {
                throw new Exception('No permission to edit transfers');
            }

            $transferId = $data['id'] ?? $data['transfer_id'] ?? 0;
            if (empty($transferId)) {
                throw new Exception('Transfer ID is required');
            }

            // Check if transfer exists and is draft
            $checkStmt = $conn->prepare("SELECT status, from_location_id, created_by, prepared_by_signature FROM stock_transfers WHERE id = ?");
            $checkStmt->execute([$transferId]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$existing) {
                throw new Exception('Transfer not found');
            }

            if ($existing['status'] !== 'draft') {
                throw new Exception('Only draft transfers can be edited');
            }

            if (!canAccessLocation($existing['from_location_id'])) {
                throw new Exception('You do not have access to this transfer');
            }

            $details = $data['details'] ?? $data['items'] ?? [];

            $conn->beginTransaction();

            // Update transfer
            $costCentreId = !empty($data['cost_centre_id']) ? (int)$data['cost_centre_id'] : null;

            // Currency support
            $currencyId = !empty($data['currency_id']) ? (int)$data['currency_id'] : null;
            $exchangeRateToBase = !empty($data['exchange_rate_to_base']) ? floatval($data['exchange_rate_to_base']) : 1;
            if (!$currencyId) {
                $defaultCurrency = getDefaultCurrency();
                $currencyId = $defaultCurrency ? $defaultCurrency['id'] : null;
            }

            // Build base UPDATE; only include signature column if the key is present in the payload
            $sigKeyPresent = array_key_exists('prepared_by_signature', $data);
            $preparedSig = $sigKeyPresent ? sanitizeSignatureDataUrl($data['prepared_by_signature']) : null;

            // Nothing drawn: fall back to the signature the PREPARER has on
            // file. Keyed on created_by, not the editor — the printed delivery
            // note pairs this image with the creator's name, so another user's
            // signature would be the wrong one.
            //
            // This also stops an edit from WIPING a stored signature. The pad
            // is hidden for users who have a master signature, so the client
            // now always posts null here; without the fallback the UPDATE below
            // would clear the column. When the creator has no master signature
            // the pad is still shown, so an explicit clear-then-save keeps
            // working exactly as before.
            if ($preparedSig === null) {
                $masterSig = getUserSignature($conn, $existing['created_by']);
                if ($masterSig !== null) {
                    $preparedSig   = $masterSig;
                    $sigKeyPresent = true;
                }
            }

            $sql = "
                UPDATE stock_transfers SET
                    from_location_id = ?,
                    to_location_id = ?,
                    cost_centre_id = ?,
                    currency_id = ?,
                    exchange_rate_to_base = ?,
                    transfer_date = ?,
                    vehicle_number = ?,
                    driver_name = ?,
                    driver_phone = ?,
                    remarks = ?,
                    " . ($sigKeyPresent ? "prepared_by_signature = ?, " : "") . "
                    updated_at = NOW()
                WHERE id = ?
            ";
            $params = [
                $data['from_location_id'],
                $data['to_location_id'],
                $costCentreId,
                $currencyId,
                $exchangeRateToBase,
                dbDate($data['transfer_date'] ?? date('Y-m-d')),
                clean($data['vehicle_number'] ?? ''),
                clean($data['driver_name'] ?? ''),
                clean($data['driver_phone'] ?? ''),
                clean($data['remarks'] ?? ''),
            ];
            if ($sigKeyPresent) {
                $params[] = $preparedSig;
            }
            $params[] = $transferId;

            $stmt = $conn->prepare($sql);
            $stmt->execute($params);

            // Delete existing details and re-insert
            $deleteStmt = $conn->prepare("DELETE FROM stock_transfer_details WHERE transfer_id = ?");
            $deleteStmt->execute([$transferId]);

            $detailStmt = $conn->prepare("
                INSERT INTO stock_transfer_details
                (transfer_id, product_id, dispatched_quantity, rate_per_unit)
                VALUES (?, ?, ?, ?)
            ");

            foreach ($details as $item) {
                $qty = floatval($item['quantity'] ?? $item['dispatched_quantity'] ?? 0);
                $rate = floatval($item['rate'] ?? $item['rate_per_unit'] ?? 0);

                $detailStmt->execute([
                    $transferId,
                    $item['product_id'],
                    $qty,
                    $rate
                ]);
            }

            $conn->commit();

            logAudit('stock_transfer', 'update', $transferId);

            echo json_encode([
                'success' => true,
                'serverId' => $transferId
            ]);
            break;

        case 'delete':
            if (!hasPermission('stock_transfer', 'delete')) {
                throw new Exception('No permission to delete transfers');
            }

            $transferId = $data['id'] ?? $data['transfer_id'] ?? 0;
            if (empty($transferId)) {
                throw new Exception('Transfer ID is required');
            }

            // Check if transfer exists and is draft
            $checkStmt = $conn->prepare("SELECT status, from_location_id FROM stock_transfers WHERE id = ?");
            $checkStmt->execute([$transferId]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$existing) {
                throw new Exception('Transfer not found');
            }

            if ($existing['status'] !== 'draft') {
                throw new Exception('Only draft transfers can be deleted');
            }

            if (!canAccessLocation($existing['from_location_id'])) {
                throw new Exception('You do not have access to this transfer');
            }

            $conn->beginTransaction();

            // If linked to a requisition, decrement transferred_quantity
            $reqCheckStmt = $conn->prepare("SELECT requisition_id FROM stock_transfers WHERE id = ?");
            $reqCheckStmt->execute([$transferId]);
            $reqId = $reqCheckStmt->fetchColumn();

            if ($reqId) {
                // Get transfer details with requisition_detail_id
                $reqDetailsStmt = $conn->prepare("
                    SELECT requisition_detail_id, dispatched_quantity
                    FROM stock_transfer_details
                    WHERE transfer_id = ? AND requisition_detail_id IS NOT NULL
                ");
                $reqDetailsStmt->execute([$transferId]);
                $reqDetails = $reqDetailsStmt->fetchAll(PDO::FETCH_ASSOC);

                // Decrement transferred_quantity for each linked requisition detail
                $updateReqDetailStmt = $conn->prepare("
                    UPDATE requisition_voucher_details
                    SET transferred_quantity = GREATEST(0, transferred_quantity - ?)
                    WHERE id = ?
                ");
                foreach ($reqDetails as $rd) {
                    $updateReqDetailStmt->execute([$rd['dispatched_quantity'], $rd['requisition_detail_id']]);
                }

                // If requisition was auto-closed, reopen it to approved
                $reopenStmt = $conn->prepare("
                    UPDATE requisition_vouchers
                    SET status = 'approved', closed_by = NULL, closed_at = NULL, close_reason = NULL
                    WHERE id = ? AND status = 'closed' AND close_reason IS NULL
                ");
                $reopenStmt->execute([$reqId]);
            }

            // Delete details first
            $deleteDetailStmt = $conn->prepare("DELETE FROM stock_transfer_details WHERE transfer_id = ?");
            $deleteDetailStmt->execute([$transferId]);

            // Delete transfer
            $deleteStmt = $conn->prepare("DELETE FROM stock_transfers WHERE id = ?");
            $deleteStmt->execute([$transferId]);

            $conn->commit();

            logAudit('stock_transfer', 'delete', $transferId);

            echo json_encode(['success' => true]);
            break;

        case 'dispatch':
            if (!hasPermission('stock_transfer', 'edit')) {
                throw new Exception('No permission to dispatch transfers');
            }

            $transferId = $data['id'] ?? $data['transfer_id'] ?? 0;
            if (empty($transferId)) {
                throw new Exception('Transfer ID is required');
            }

            // Get transfer
            $checkStmt = $conn->prepare("SELECT * FROM stock_transfers WHERE id = ?");
            $checkStmt->execute([$transferId]);
            $transfer = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$transfer) {
                throw new Exception('Transfer not found');
            }

            if ($transfer['status'] !== 'draft') {
                throw new Exception('Only draft transfers can be dispatched');
            }

            if (!canAccessLocation($transfer['from_location_id'])) {
                throw new Exception('You do not have access to this transfer');
            }

            // Get transfer details
            $detailsStmt = $conn->prepare("SELECT * FROM stock_transfer_details WHERE transfer_id = ?");
            $detailsStmt->execute([$transferId]);
            $details = $detailsStmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($details)) {
                throw new Exception('Transfer has no items');
            }

            // Check stock availability if negative stock not allowed
            $allowNegativeStock = getSetting('allow_negative_stock', '0') === '1';

            $conn->beginTransaction();

            if (!$allowNegativeStock) {
                foreach ($details as $item) {
                    $stockStmt = $conn->prepare("
                        SELECT COALESCE(SUM(quantity_remaining), 0) as available
                        FROM stock_batches
                        WHERE product_id = ? AND location_id = ? AND quantity_remaining > 0
                    ");
                    $stockStmt->execute([$item['product_id'], $transfer['from_location_id']]);
                    $available = floatval($stockStmt->fetchColumn());

                    if ($available < floatval($item['dispatched_quantity'])) {
                        $prodStmt = $conn->prepare("SELECT name FROM products WHERE id = ?");
                        $prodStmt->execute([$item['product_id']]);
                        $productName = $prodStmt->fetchColumn();

                        throw new Exception(__('transfer_insufficient_stock') . ': ' . $productName . ' (' . __('available') . ': ' . formatNumber($available) . ')');
                    }
                }
            }

            // Deduct stock from source location (FIFO)
            //
            // The line's rate_per_unit is DERIVED HERE, from the cost layers this
            // walk actually consumes, and written back to the detail row. It used
            // to come from the client payload and silently default to 0
            // (see the create action) -- the requisition-based transfer screen
            // never sent a rate at all -- and receive/cancel copy that rate onto
            // the destination stock_batches row. The result was received stock
            // valued at nothing: 136 batches, 31,563 units, ~369m of stock.
            // The server has the true cost right here, so it no longer takes the
            // client's word for it.
            $rateStmt = $conn->prepare("UPDATE stock_transfer_details SET rate_per_unit = ? WHERE id = ?");

            foreach ($details as $item) {
                $remainingQty = floatval($item['dispatched_quantity']);
                $consumedQty = 0.0;   // units actually taken from source batches
                $consumedCost = 0.0;  // their total cost

                $batchStmt = $conn->prepare("
                    SELECT id, quantity_remaining, rate_per_unit
                    FROM stock_batches
                    WHERE location_id = ? AND product_id = ? AND quantity_remaining > 0
                    ORDER BY batch_date ASC, id ASC
                ");
                $batchStmt->execute([$transfer['from_location_id'], $item['product_id']]);
                $batches = $batchStmt->fetchAll();

                foreach ($batches as $batch) {
                    if ($remainingQty <= 0) break;

                    $deductQty = min($remainingQty, $batch['quantity_remaining']);
                    $newRemaining = $batch['quantity_remaining'] - $deductQty;

                    $updateBatchStmt = $conn->prepare("UPDATE stock_batches SET quantity_remaining = ? WHERE id = ?");
                    $updateBatchStmt->execute([$newRemaining, $batch['id']]);

                    $consumedQty  += $deductQty;
                    $consumedCost += $deductQty * floatval($batch['rate_per_unit']);
                    $remainingQty -= $deductQty;
                }

                // Weighted average of the layers consumed. A derived 0 means the
                // SOURCE batches themselves carry no cost, so keep whatever the
                // line already had rather than overwriting a good rate with zero.
                if ($consumedQty > 0) {
                    $derivedRate = $consumedCost / $consumedQty;
                    if ($derivedRate > 0) {
                        $rateStmt->execute([round($derivedRate, 2), $item['id']]);
                    }
                }
            }

            // Optional dispatched-by signature
            $dispatchedSig = sanitizeSignatureDataUrl($data['dispatched_by_signature'] ?? null);
            // Nothing drawn on the pad — use the dispatcher's signature on file (0041).
            if ($dispatchedSig === null) {
                $dispatchedSig = getUserSignature($conn, getUserId());
            }

            // Update transfer status
            $updateStmt = $conn->prepare("
                UPDATE stock_transfers SET
                    status = 'in_transit',
                    dispatched_by = ?,
                    dispatched_at = NOW(),
                    dispatched_by_signature = ?
                WHERE id = ?
            ");
            $updateStmt->execute([getUserId(), $dispatchedSig, $transferId]);

            $conn->commit();

            logAudit('stock_transfer', 'dispatch', $transferId);

            echo json_encode(['success' => true, 'message' => __('transfer_dispatched')]);
            break;

        case 'cancel':
            if (!hasPermission('stock_transfer', 'edit')) {
                throw new Exception('No permission to cancel transfers');
            }

            $transferId = $data['id'] ?? $data['transfer_id'] ?? 0;
            if (empty($transferId)) {
                throw new Exception('Transfer ID is required');
            }

            // Get transfer
            $checkStmt = $conn->prepare("SELECT * FROM stock_transfers WHERE id = ?");
            $checkStmt->execute([$transferId]);
            $transfer = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$transfer) {
                throw new Exception('Transfer not found');
            }

            if ($transfer['status'] === 'cancelled' || $transfer['status'] === 'received') {
                throw new Exception('Cannot cancel this transfer');
            }

            $cancelReason = clean($data['cancel_reason'] ?? '');

            $conn->beginTransaction();

            // If in_transit, restore stock to source location
            if ($transfer['status'] === 'in_transit') {
                $detailsStmt = $conn->prepare("SELECT * FROM stock_transfer_details WHERE transfer_id = ?");
                $detailsStmt->execute([$transferId]);
                $details = $detailsStmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($details as $item) {
                    // Create stock batch to restore
                    $batchStmt = $conn->prepare("
                        INSERT INTO stock_batches
                        (product_id, location_id, quantity_in, quantity_remaining, rate_per_unit, batch_date, transaction_type, transaction_id)
                        VALUES (?, ?, ?, ?, ?, CURDATE(), 'transfer_in', ?)
                    ");
                    $batchStmt->execute([
                        $item['product_id'],
                        $transfer['from_location_id'],
                        $item['dispatched_quantity'],
                        $item['dispatched_quantity'],
                        $item['rate_per_unit'],
                        $transferId
                    ]);
                }
            }

            // Update transfer status
            $updateStmt = $conn->prepare("
                UPDATE stock_transfers SET
                    status = 'cancelled',
                    cancelled_by = ?,
                    cancelled_at = NOW(),
                    cancel_reason = ?
                WHERE id = ?
            ");
            $updateStmt->execute([getUserId(), $cancelReason, $transferId]);

            $conn->commit();

            logAudit('stock_transfer', 'cancel', $transferId);

            echo json_encode(['success' => true, 'message' => __('transfer_cancelled')]);
            break;

        case 'receive':
            if (!hasPermission('stock_transfer', 'edit')) {
                throw new Exception('No permission to receive transfers');
            }

            $transferId = $data['id'] ?? $data['transfer_id'] ?? 0;
            if (empty($transferId)) {
                throw new Exception('Transfer ID is required');
            }

            // Get transfer
            $checkStmt = $conn->prepare("SELECT * FROM stock_transfers WHERE id = ?");
            $checkStmt->execute([$transferId]);
            $transfer = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$transfer) {
                throw new Exception('Transfer not found');
            }

            if ($transfer['status'] !== 'in_transit') {
                throw new Exception('Only in-transit transfers can be received');
            }

            // User must have access to TO location
            if (!canAccessLocation($transfer['to_location_id'])) {
                throw new Exception('You do not have access to the destination location');
            }

            // Get transfer details
            $detailsStmt = $conn->prepare("SELECT * FROM stock_transfer_details WHERE transfer_id = ?");
            $detailsStmt->execute([$transferId]);
            $details = $detailsStmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($details)) {
                throw new Exception('Transfer has no items');
            }

            // Build received quantities map from input
            $receivedItems = $data['items'] ?? [];
            $receivedMap = [];
            foreach ($receivedItems as $ri) {
                $detailId = $ri['detail_id'] ?? $ri['id'] ?? 0;
                $receivedMap[$detailId] = floatval($ri['received_quantity'] ?? $ri['dispatched_quantity'] ?? 0);
            }

            $conn->beginTransaction();

            // Reverse-transfer bookkeeping: when a REVERSE (reverse_of_transfer_id
            // set) is received, the returned qty must be given back to the linked
            // requisition's transferred_quantity so the BOM balance + requisition
            // BOM column rise. Forward transfers skip this entirely.
            $isReverse = !empty($transfer['reverse_of_transfer_id']);
            $reqDetailIdsAffected = [];
            $decReqStmt = $conn->prepare("
                UPDATE requisition_voucher_details
                SET transferred_quantity = GREATEST(0, transferred_quantity - ?)
                WHERE id = ?
            ");

            foreach ($details as $item) {
                $detailId = $item['id'];
                $dispatchedQty = floatval($item['dispatched_quantity']);
                $rate = floatval($item['rate_per_unit']);

                // Get received quantity (default to dispatched if not specified)
                $receivedQty = isset($receivedMap[$detailId]) ? $receivedMap[$detailId] : $dispatchedQty;

                // Validate received quantity
                if ($receivedQty < 0) $receivedQty = 0;
                if ($receivedQty > $dispatchedQty) $receivedQty = $dispatchedQty;

                $shortage = $dispatchedQty - $receivedQty;
                $shortageReason = '';
                if ($shortage > 0) {
                    foreach ($receivedItems as $ri) {
                        if (($ri['detail_id'] ?? $ri['id'] ?? 0) == $detailId) {
                            $shortageReason = clean($ri['shortage_reason'] ?? '');
                            break;
                        }
                    }
                }

                // Update detail with received quantity (shortage_quantity is auto-calculated)
                $updateDetailStmt = $conn->prepare("
                    UPDATE stock_transfer_details SET
                        received_quantity = ?,
                        shortage_reason = ?
                    WHERE id = ?
                ");
                $updateDetailStmt->execute([$receivedQty, $shortageReason, $detailId]);

                // Add stock to destination location (create new batch)
                if ($receivedQty > 0) {
                    $batchStmt = $conn->prepare("
                        INSERT INTO stock_batches
                        (product_id, location_id, quantity_in, quantity_remaining, rate_per_unit, batch_date, transaction_type, transaction_id)
                        VALUES (?, ?, ?, ?, ?, CURDATE(), 'transfer_in', ?)
                    ");
                    $batchStmt->execute([
                        $item['product_id'],
                        $transfer['to_location_id'],
                        $receivedQty,
                        $receivedQty,
                        $rate,
                        $transferId
                    ]);
                }

                // Reverse only: hand the returned qty back to the requisition line
                // (only what actually arrived frees BOM balance).
                if ($isReverse && !empty($item['requisition_detail_id']) && $receivedQty > 0) {
                    $decReqStmt->execute([$receivedQty, $item['requisition_detail_id']]);
                    $reqDetailIdsAffected[] = (int)$item['requisition_detail_id'];
                }
            }

            // Reverse only: a requisition that had auto-closed once fully
            // transferred should reopen now that some of it has been returned.
            if ($isReverse && !empty($reqDetailIdsAffected)) {
                $in = implode(',', array_fill(0, count($reqDetailIdsAffected), '?'));
                $reopenStmt = $conn->prepare("
                    UPDATE requisition_vouchers rv
                    INNER JOIN requisition_voucher_details rvd ON rvd.requisition_id = rv.id
                    SET rv.status = 'approved', rv.closed_by = NULL, rv.closed_at = NULL, rv.close_reason = NULL
                    WHERE rvd.id IN ($in) AND rv.status = 'closed' AND rv.close_reason IS NULL
                ");
                $reopenStmt->execute($reqDetailIdsAffected);
            }

            // Optional received-by signature
            $receivedSig = sanitizeSignatureDataUrl($data['received_by_signature'] ?? null);
            // Nothing drawn on the pad — use the receiver's signature on file (0041).
            if ($receivedSig === null) {
                $receivedSig = getUserSignature($conn, getUserId());
            }

            // Update transfer status to received
            $updateStmt = $conn->prepare("
                UPDATE stock_transfers SET
                    status = 'received',
                    received_by = ?,
                    received_at = NOW(),
                    received_by_signature = ?
                WHERE id = ?
            ");
            $updateStmt->execute([getUserId(), $receivedSig, $transferId]);

            $conn->commit();

            logAudit('stock_transfer', 'receive', $transferId);

            echo json_encode(['success' => true, 'message' => __('transfer_received')]);
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
