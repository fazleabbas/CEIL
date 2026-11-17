<?php
/**
 * Consumption Vouchers Sync API - Handles create and delete operations
 * Manages consumption_vouchers, consumption_voucher_details, and stock_batches
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is logged in
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['action'])) {
        throw new Exception('Action not specified');
    }

    switch ($input['action']) {
        case 'create':
            createConsumptionVoucher($conn, $input['data']);
            break;

        case 'delete':
            deleteConsumptionVoucher($conn, $input['data']);
            break;

        default:
            throw new Exception('Invalid action');
    }

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Create new consumption voucher with items and FIFO stock deduction
 */
function createConsumptionVoucher($conn, $data) {
    if (!hasPermission('consumption_vouchers', 'create')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No permission to create consumption vouchers']);
        exit;
    }

    // Validate input
    $errors = [];

    if (empty($data['voucher_date'])) {
        $errors['voucher_date'] = 'Voucher date is required';
    }

    if (empty($data['location_id'])) {
        $errors['location_id'] = 'Location is required';
    }

    if (empty($data['details']) || !is_array($data['details']) || count($data['details']) === 0) {
        $errors['details'] = 'At least one item is required';
    }

    if (!empty($errors)) {
        echo json_encode([
            'success' => false,
            'error' => 'Validation failed',
            'details' => $errors
        ]);
        exit;
    }

    $voucherDate = clean($data['voucher_date']);
    $locationId = (int)$data['location_id'];
    $costCentreId = !empty($data['cost_centre_id']) ? (int)$data['cost_centre_id'] : null;
    $remarks = clean($data['remarks'] ?? '');
    $items = $data['details'];
    $tempId = $data['tempId'] ?? null;

    // Check user has access to this location
    if (!canAccessLocation($locationId)) {
        throw new Exception('You do not have access to this location');
    }

    $allowNegativeStock = getSetting('allow_negative_stock', '0') === '1';

    try {
        $conn->beginTransaction();

        // Generate voucher number
        $voucherNumber = getNextNumber('consumption_vouchers', 'voucher_number', getSetting('cv_prefix', 'CV'));

        // Insert voucher header
        $stmt = $conn->prepare("
            INSERT INTO consumption_vouchers
            (voucher_number, voucher_date, location_id, cost_centre_id, remarks, created_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $voucherNumber,
            $voucherDate,
            $locationId,
            $costCentreId,
            $remarks,
            getUserId()
        ]);

        $voucherId = $conn->lastInsertId();
        $totalAmount = 0;

        // Insert details and deduct stock via FIFO
        foreach ($items as $item) {
            $productId = (int)$item['product_id'];
            $quantity = floatval($item['quantity']);
            $itemRemark = clean($item['remarks'] ?? '');

            if ($productId <= 0 || $quantity <= 0) continue;

            // Check stock availability
            if (!$allowNegativeStock) {
                $stockInfo = getProductStockOnFly($productId, $locationId);
                if ($stockInfo['quantity'] < $quantity) {
                    $pStmt = $conn->prepare("SELECT name FROM products WHERE id = ?");
                    $pStmt->execute([$productId]);
                    $pName = $pStmt->fetchColumn();
                    $lStmt = $conn->prepare("SELECT name FROM locations WHERE id = ?");
                    $lStmt->execute([$locationId]);
                    $lName = $lStmt->fetchColumn();
                    throw new Exception(sprintf('Insufficient stock for %s at %s. Available: %s, Required: %s',
                        $pName, $lName, number_format($stockInfo['quantity'], 3), number_format($quantity, 3)));
                }
            }

            // FIFO deduction
            $remainingQty = $quantity;
            $totalCost = 0;

            $batchesStmt = $conn->prepare("
                SELECT id, quantity_remaining, rate_per_unit
                FROM stock_batches
                WHERE product_id = ? AND location_id = ? AND quantity_remaining > 0
                ORDER BY batch_date ASC, id ASC
            ");
            $batchesStmt->execute([$productId, $locationId]);
            $batches = $batchesStmt->fetchAll();

            foreach ($batches as $batch) {
                if ($remainingQty <= 0) break;

                $deductQty = min($remainingQty, $batch['quantity_remaining']);
                $newRemaining = $batch['quantity_remaining'] - $deductQty;

                $updateBatchStmt = $conn->prepare("
                    UPDATE stock_batches SET quantity_remaining = ? WHERE id = ?
                ");
                $updateBatchStmt->execute([$newRemaining, $batch['id']]);

                $totalCost += $deductQty * $batch['rate_per_unit'];
                $remainingQty -= $deductQty;
            }

            $ratePerUnit = $quantity > 0 ? $totalCost / $quantity : 0;
            $amount = $totalCost;

            // Insert detail
            $detailStmt = $conn->prepare("
                INSERT INTO consumption_voucher_details
                (voucher_id, product_id, quantity, rate_per_unit, amount, remarks)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $detailStmt->execute([$voucherId, $productId, $quantity, $ratePerUnit, $amount, $itemRemark]);

            $totalAmount += $amount;
        }

        // Update total amount
        $updateStmt = $conn->prepare("UPDATE consumption_vouchers SET total_amount = ? WHERE id = ?");
        $updateStmt->execute([$totalAmount, $voucherId]);

        $conn->commit();

        logAudit('consumption_voucher', 'create', $voucherId);

        // Fetch the created voucher with details
        $voucher = getConsumptionVoucherById($conn, $voucherId);

        echo json_encode([
            'success' => true,
            'message' => 'Consumption voucher created successfully',
            'voucher' => $voucher,
            'serverId' => $voucherId,
            'serverNumber' => $voucherNumber,
            'tempId' => $tempId
        ]);

    } catch (PDOException $e) {
        $conn->rollBack();
        throw new Exception('Database error: ' . $e->getMessage());
    }
}

/**
 * Delete consumption voucher and restore stock
 */
function deleteConsumptionVoucher($conn, $data) {
    if (!hasPermission('consumption_vouchers', 'delete')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No permission to delete consumption vouchers']);
        exit;
    }

    $voucherId = (int)$data['id'];

    // Check if voucher exists
    $stmt = $conn->prepare("SELECT * FROM consumption_vouchers WHERE id = ?");
    $stmt->execute([$voucherId]);
    $voucher = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$voucher) {
        throw new Exception('Consumption voucher not found');
    }

    try {
        $conn->beginTransaction();

        // Get details for stock restoration
        $detailsStmt = $conn->prepare("SELECT * FROM consumption_voucher_details WHERE voucher_id = ?");
        $detailsStmt->execute([$voucherId]);
        $details = $detailsStmt->fetchAll();

        // Restore stock by creating new batch rows
        foreach ($details as $detail) {
            $restoreBatchStmt = $conn->prepare("
                INSERT INTO stock_batches
                (location_id, product_id, batch_date, transaction_type, transaction_id, quantity_in, quantity_remaining, rate_per_unit)
                VALUES (?, ?, CURDATE(), 'consumption', NULL, ?, ?, ?)
            ");
            $restoreBatchStmt->execute([
                $voucher['location_id'],
                $detail['product_id'],
                $detail['quantity'],
                $detail['quantity'],
                $detail['rate_per_unit']
            ]);
        }

        // Delete details
        $stmt = $conn->prepare("DELETE FROM consumption_voucher_details WHERE voucher_id = ?");
        $stmt->execute([$voucherId]);

        // Delete header
        $stmt = $conn->prepare("DELETE FROM consumption_vouchers WHERE id = ?");
        $stmt->execute([$voucherId]);

        $conn->commit();

        logAudit('consumption_voucher', 'delete', $voucherId);

        echo json_encode([
            'success' => true,
            'message' => 'Consumption voucher deleted successfully'
        ]);

    } catch (PDOException $e) {
        $conn->rollBack();
        throw new Exception('Database error: ' . $e->getMessage());
    }
}

/**
 * Get consumption voucher by ID with details
 */
function getConsumptionVoucherById($conn, $voucherId) {
    $stmt = $conn->prepare("
        SELECT
            cv.*,
            l.name as location_name,
            cc.name as cost_centre_name,
            cc.code as cost_centre_code,
            u.name as created_by_name
        FROM consumption_vouchers cv
        LEFT JOIN locations l ON cv.location_id = l.id
        LEFT JOIN cost_centres cc ON cv.cost_centre_id = cc.id
        LEFT JOIN users u ON cv.created_by = u.id
        WHERE cv.id = ?
    ");
    $stmt->execute([$voucherId]);
    $voucher = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$voucher) {
        return null;
    }

    // Fetch details
    $detailsStmt = $conn->prepare("
        SELECT
            cvd.*,
            p.name as product_name,
            p.unit,
            pc.name as category_name
        FROM consumption_voucher_details cvd
        JOIN products p ON cvd.product_id = p.id
        LEFT JOIN product_categories pc ON p.category_id = pc.id
        WHERE cvd.voucher_id = ?
        ORDER BY cvd.id
    ");
    $detailsStmt->execute([$voucherId]);
    $voucher['details'] = $detailsStmt->fetchAll(PDO::FETCH_ASSOC);
    $voucher['synced'] = true;

    return $voucher;
}
