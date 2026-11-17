<?php
/**
 * Sales Sync API - POST Endpoint
 * Handles create and delete operations with stock management
 */

require_once '../config/database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

// Check authentication
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

$action = $input['action'] ?? '';
$data = $input['data'] ?? [];

try {
    switch ($action) {
        case 'create':
            handleCreate($data, $conn);
            break;
            
        case 'update':
            handleUpdate($data, $conn);
            break;
            
        case 'delete':
            handleDelete($data, $conn);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
            exit;
    }
} catch (Exception $e) {
    error_log("Sales Sync Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * Get available stock for a product at a location
 * @param int $productId
 * @param int $locationId
 * @param PDO $conn
 * @param int|null $excludeSaleId - Exclude this sale's quantities (for edit mode)
 * @return float Available stock quantity
 */
function getAvailableStock($productId, $locationId, $conn, $excludeSaleId = null) {
    $today = date('Y-m-d');
    
    // Get opening stocks till today
    $openingStmt = $conn->prepare("
        SELECT COALESCE(SUM(quantity), 0) as total
        FROM opening_stocks
        WHERE product_id = ? AND location_id = ? AND stock_date <= ?
    ");
    $openingStmt->execute([$productId, $locationId, $today]);
    $openingQty = floatval($openingStmt->fetchColumn());
    
    // Get purchases till today
    $purchaseStmt = $conn->prepare("
        SELECT COALESCE(SUM(pd.quantity), 0) as total
        FROM purchase_details pd
        JOIN purchases p ON pd.purchase_id = p.id
        WHERE pd.product_id = ? AND p.location_id = ? AND p.purchase_date <= ?
    ");
    $purchaseStmt->execute([$productId, $locationId, $today]);
    $purchaseQty = floatval($purchaseStmt->fetchColumn());
    
    // Get sales till today (excluding the sale being edited if provided)
    $salesQuery = "
        SELECT COALESCE(SUM(sd.quantity), 0) as total
        FROM sale_details sd
        JOIN sales s ON sd.sale_id = s.id
        WHERE sd.product_id = ? AND s.location_id = ? AND s.sale_date <= ?
    ";
    $salesParams = [$productId, $locationId, $today];
    
    if ($excludeSaleId) {
        $salesQuery .= " AND s.id != ?";
        $salesParams[] = intval($excludeSaleId);
    }
    
    $salesStmt = $conn->prepare($salesQuery);
    $salesStmt->execute($salesParams);
    $salesQty = floatval($salesStmt->fetchColumn());
    
    // Get adjustments till today
    $adjustmentStmt = $conn->prepare("
        SELECT COALESCE(SUM(CASE WHEN sad.adjustment_type = 'increase' THEN sad.quantity ELSE -sad.quantity END), 0) as total
        FROM stock_adjustment_details sad
        JOIN stock_adjustments sa ON sad.adjustment_id = sa.id
        WHERE sad.product_id = ? AND sa.location_id = ? AND sa.adjustment_date <= ?
    ");
    $adjustmentStmt->execute([$productId, $locationId, $today]);
    $adjustmentQty = floatval($adjustmentStmt->fetchColumn());
    
    return $openingQty + $purchaseQty - $salesQty + $adjustmentQty;
}

/**
 * Validate stock availability for sale items
 * @param array $details - Sale items
 * @param int $locationId
 * @param PDO $conn
 * @param int|null $excludeSaleId - For edit mode
 * @return array - Array of error messages (empty if all valid)
 */
function validateStockAvailability($details, $locationId, $conn, $excludeSaleId = null) {
    $stockErrors = [];
    
    foreach ($details as $index => $item) {
        $productId = $item['product_id'];
        $requestedQty = floatval($item['quantity']);
        
        $availableStock = getAvailableStock($productId, $locationId, $conn, $excludeSaleId);
        
        if ($requestedQty > $availableStock) {
            // Get product name for error message
            $productStmt = $conn->prepare("SELECT name FROM products WHERE id = ?");
            $productStmt->execute([$productId]);
            $productName = $productStmt->fetchColumn() ?: 'Unknown Product';
            
            $stockErrors[] = "{$productName}: Requested {$requestedQty}, Available " . number_format($availableStock, 3);
        }
    }
    
    return $stockErrors;
}

/**
 * Handle CREATE sale
 */
function handleCreate($data, $conn) {
    // Check permission
    if (!hasPermission('sales', 'create')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No permission to create sales']);
        exit;
    }
    
    // Validate required fields
    $errors = [];
    
    if (empty($data['sale_date'])) {
        $errors[] = 'Sale date is required';
    }
    
    if (empty($data['location_id'])) {
        $errors[] = 'Location is required';
    }
    
    if (empty($data['customer_id'])) {
        $errors[] = 'Customer is required';
    }
    
    if (empty($data['details']) || !is_array($data['details'])) {
        $errors[] = 'At least one item is required';
    }
    
    // Validate date not in future
    if (!empty($data['sale_date'])) {
        $saleDate = strtotime($data['sale_date']);
        $today = strtotime(date('Y-m-d'));
        if ($saleDate > $today) {
            $errors[] = 'Sale date cannot be in the future';
        }
    }
    
    // Validate location access
    if (!empty($data['location_id']) && !canAccessLocation($data['location_id'])) {
        $errors[] = 'You do not have access to this location';
    }
    
    // Validate items
    if (!empty($data['details'])) {
        foreach ($data['details'] as $index => $item) {
            if (empty($item['product_id'])) {
                $errors[] = "Item " . ($index + 1) . ": Product is required";
            }
            if (empty($item['quantity']) || $item['quantity'] <= 0) {
                $errors[] = "Item " . ($index + 1) . ": Valid quantity is required";
            }
            if (!isset($item['rate_per_unit']) || $item['rate_per_unit'] < 0) {
                $errors[] = "Item " . ($index + 1) . ": Valid rate is required";
            }
        }
    }
    
    if (!empty($errors)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => implode(', ', $errors)]);
        exit;
    }
    
    // Validate stock availability
    $stockErrors = validateStockAvailability($data['details'], $data['location_id'], $conn);
    if (!empty($stockErrors)) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'error' => 'Insufficient stock: ' . implode('; ', $stockErrors),
            'stock_errors' => $stockErrors
        ]);
        exit;
    }
    
    $conn->beginTransaction();
    
    try {
        // Generate sale number
        $saleNumber = getNextNumber('sales', 'sale_number', getSetting('sale_prefix', 'SAL'));
        
        // Calculate total
        $totalAmount = 0;
        foreach ($data['details'] as $item) {
            $totalAmount += floatval($item['quantity']) * floatval($item['rate_per_unit']);
        }
        
        // Insert sale
        $paymentMode = $data['payment_mode'] ?? 'cash';
        $toAccountId = ($paymentMode !== 'credit' && !empty($data['to_account_id'])) ? $data['to_account_id'] : null;

        $costCentreId = !empty($data['cost_centre_id']) ? (int)$data['cost_centre_id'] : null;

        // Currency support
        $currencyId = !empty($data['currency_id']) ? (int)$data['currency_id'] : null;
        $exchangeRateToBase = !empty($data['exchange_rate_to_base']) ? floatval($data['exchange_rate_to_base']) : 1;
        if (!$currencyId) {
            $defaultCurrency = getDefaultCurrency();
            $currencyId = $defaultCurrency ? $defaultCurrency['id'] : null;
        }

        $stmt = $conn->prepare("
            INSERT INTO sales
            (sale_number, sale_date, customer_id, print_name, location_id, cost_centre_id, currency_id, exchange_rate_to_base, total_amount, payment_mode, to_account_id, remarks, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $saleNumber,
            $data['sale_date'],
            $data['customer_id'],
            $data['print_name'] ?? null,
            $data['location_id'],
            $costCentreId,
            $currencyId,
            $exchangeRateToBase,
            $totalAmount,
            $paymentMode,
            $toAccountId,
            $data['notes'] ?? null,
            getUserId()
        ]);
        
        $saleId = $conn->lastInsertId();
        
        // Insert details and update stock
        $detailStmt = $conn->prepare("
            INSERT INTO sale_details (sale_id, product_id, quantity, rate_per_unit, amount, cost_per_unit)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        foreach ($data['details'] as $item) {
            $quantity = floatval($item['quantity']);
            $rate = floatval($item['rate_per_unit']);
            $amount = $quantity * $rate;
            
            // Insert detail
            $detailStmt->execute([
                $saleId,
                $item['product_id'],
                $quantity,
                $rate,
                $amount,
                null  // cost_per_unit will be calculated by FIFO
            ]);
            
            // Update stock batches using FIFO
            $remainingQty = $quantity;
            $totalCost = 0;
            
            // Get batches in FIFO order
            $batchStmt = $conn->prepare("
                SELECT id, quantity_remaining, rate_per_unit
                FROM stock_batches
                WHERE location_id = ? AND product_id = ? AND quantity_remaining > 0
                ORDER BY batch_date ASC, id ASC
            ");
            $batchStmt->execute([$data['location_id'], $item['product_id']]);
            $batches = $batchStmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($batches as $batch) {
                if ($remainingQty <= 0) break;
                
                $deductQty = min($remainingQty, floatval($batch['quantity_remaining']));
                $newRemaining = floatval($batch['quantity_remaining']) - $deductQty;
                
                // Update batch
                $updateBatchStmt = $conn->prepare("
                    UPDATE stock_batches
                    SET quantity_remaining = ?
                    WHERE id = ?
                ");
                $updateBatchStmt->execute([$newRemaining, $batch['id']]);
                
                // Calculate cost
                $totalCost += $deductQty * floatval($batch['rate_per_unit']);
                
                $remainingQty -= $deductQty;
            }
            
            // If still remaining quantity (insufficient stock), allow it but log warning
            if ($remainingQty > 0) {
                error_log("Warning: Insufficient stock for product ID {$item['product_id']}. Remaining unsold: {$remainingQty}");
            }
            
            // Update cost_per_unit in sale_details (for profit calculation)
            if ($quantity > 0 && $totalCost > 0) {
                $avgCost = $totalCost / ($quantity - $remainingQty);
                $conn->prepare("UPDATE sale_details SET cost_per_unit = ? WHERE sale_id = ? AND product_id = ?")
                    ->execute([$avgCost, $saleId, $item['product_id']]);
            }
        }
        
        // Create receipt if payment mode is not credit and to_account_id is provided
        $receiptId = null;
        $receiptNumber = null;
        if (!empty($data['to_account_id']) && ($data['payment_mode'] ?? 'cash') !== 'credit') {
            // Get customer's ledger account ID
            $customerStmt = $conn->prepare("SELECT ledger_account_id FROM customers WHERE id = ?");
            $customerStmt->execute([$data['customer_id']]);
            $customerLedgerId = $customerStmt->fetchColumn();

            if ($customerLedgerId) {
                // Generate receipt number
                $receiptNumber = getNextNumber('receipts', 'receipt_number', getSetting('receipt_prefix', 'REC'));

                // Map payment mode (sales uses 'card', receipts may need mapping)
                $receiptPaymentMode = $data['payment_mode'];
                if ($receiptPaymentMode === 'card') {
                    $receiptPaymentMode = 'card'; // Already correct
                }

                // Insert receipt
                $receiptStmt = $conn->prepare("
                    INSERT INTO receipts
                    (receipt_number, receipt_date, from_account_id, to_account_id, location_id, amount,
                     payment_mode, reference_number, remarks, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $receiptStmt->execute([
                    $receiptNumber,
                    $data['sale_date'],
                    $customerLedgerId,       // From: Customer's ledger account
                    $data['to_account_id'],  // To: Cash/Bank account
                    $data['location_id'],
                    $totalAmount,
                    $receiptPaymentMode,
                    'SALE-' . $saleNumber,   // Reference to sale
                    'Auto-created from Sale ' . $saleNumber,
                    getUserId()
                ]);
                $receiptId = $conn->lastInsertId();

                // Update ledger balances
                // Decrease customer balance (they owe us less)
                $conn->prepare("UPDATE ledger_accounts SET current_balance = current_balance - ? WHERE id = ?")
                    ->execute([$totalAmount, $customerLedgerId]);

                // Increase cash/bank balance (we received money)
                $conn->prepare("UPDATE ledger_accounts SET current_balance = current_balance + ? WHERE id = ?")
                    ->execute([$totalAmount, $data['to_account_id']]);

                // Post the auto-receipt's GL effect (DR cash/bank / CR customer)
                // so the dashboard receivables widget reconciles. The sale's
                // own GL (DR Customer / CR Sales) is posted below; together
                // they net to DR Cash / CR Sales for cash sales.
                createReceiptTransaction($conn, $receiptId);

                logAudit('receipts', 'create', $receiptId, null, [
                    'receipt_number' => $receiptNumber,
                    'amount' => $totalAmount,
                    'created_via' => 'sales_pos',
                    'sale_id' => $saleId
                ]);
            }
        }

        // Post the sale's GL effect (DR Customer / CR Sales) — always the
        // same shape regardless of payment_mode. See createSalesTransaction
        // for the rationale on splitting cash sales into sale + receipt.
        createSalesTransaction($conn, $saleId);

        $conn->commit();

        // Log audit
        logAudit('sales', 'create', $saleId, null, [
            'sale_number' => $saleNumber,
            'total_amount' => $totalAmount,
            'items_count' => count($data['details']),
            'receipt_created' => $receiptId ? true : false
        ]);

        // Return success with server-assigned values
        echo json_encode([
            'success' => true,
            'serverId' => $saleId,
            'serverNumber' => $saleNumber,
            'receiptId' => $receiptId,
            'receiptNumber' => $receiptNumber,
            'message' => 'Sale created successfully' . ($receiptId ? ' with receipt' : '')
        ]);

    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
}

/**
 * Handle UPDATE sale
 */
function handleUpdate($data, $conn) {
    // Check permission
    if (!hasPermission('sales', 'edit')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No permission to edit sales']);
        exit;
    }
    
    // Validate required fields
    $errors = [];
    
    if (empty($data['id'])) {
        $errors[] = 'Sale ID is required';
    }
    
    if (empty($data['sale_date'])) {
        $errors[] = 'Sale date is required';
    }
    
    if (empty($data['location_id'])) {
        $errors[] = 'Location is required';
    }
    
    if (empty($data['customer_id'])) {
        $errors[] = 'Customer is required';
    }
    
    if (empty($data['details']) || !is_array($data['details'])) {
        $errors[] = 'At least one item is required';
    }
    
    // Validate date not in future
    if (!empty($data['sale_date'])) {
        $saleDate = strtotime($data['sale_date']);
        $today = strtotime(date('Y-m-d'));
        if ($saleDate > $today) {
            $errors[] = 'Sale date cannot be in the future';
        }
    }
    
    // Validate location access
    if (!empty($data['location_id']) && !canAccessLocation($data['location_id'])) {
        $errors[] = 'You do not have access to this location';
    }
    
    // Validate items
    if (!empty($data['details'])) {
        foreach ($data['details'] as $index => $item) {
            if (empty($item['product_id'])) {
                $errors[] = "Item " . ($index + 1) . ": Product is required";
            }
            if (empty($item['quantity']) || $item['quantity'] <= 0) {
                $errors[] = "Item " . ($index + 1) . ": Valid quantity is required";
            }
            if (!isset($item['rate_per_unit']) || $item['rate_per_unit'] < 0) {
                $errors[] = "Item " . ($index + 1) . ": Valid rate is required";
            }
        }
    }
    
    if (!empty($errors)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => implode(', ', $errors)]);
        exit;
    }
    
    $saleId = (int)$data['id'];
    
    // Validate stock availability (excluding current sale's quantities)
    $stockErrors = validateStockAvailability($data['details'], $data['location_id'], $conn, $saleId);
    if (!empty($stockErrors)) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'error' => 'Insufficient stock: ' . implode('; ', $stockErrors),
            'stock_errors' => $stockErrors
        ]);
        exit;
    }
    
    // Check if sale exists
    $stmt = $conn->prepare("SELECT * FROM sales WHERE id = ?");
    $stmt->execute([$saleId]);
    $existingSale = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$existingSale) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Sale not found']);
        exit;
    }
    
    try {
        $conn->beginTransaction();
        
        // Step 1: Reverse old stock changes (add stock back from old sale)
        $oldDetailsStmt = $conn->prepare("
            SELECT sd.*, s.location_id
            FROM sale_details sd
            JOIN sales s ON sd.sale_id = s.id
            WHERE sd.sale_id = ?
        ");
        $oldDetailsStmt->execute([$saleId]);
        $oldDetails = $oldDetailsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($oldDetails as $detail) {
            $quantity = floatval($detail['quantity']);
            $locationId = $detail['location_id'];
            $productId = $detail['product_id'];
            
            // Add stock back to oldest batch
            $batchStmt = $conn->prepare("
                SELECT id, quantity_remaining
                FROM stock_batches
                WHERE location_id = ? AND product_id = ?
                ORDER BY batch_date ASC, id ASC
                LIMIT 1
            ");
            $batchStmt->execute([$locationId, $productId]);
            $batch = $batchStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($batch) {
                $newRemaining = floatval($batch['quantity_remaining']) + $quantity;
                $conn->prepare("UPDATE stock_batches SET quantity_remaining = ? WHERE id = ?")
                    ->execute([$newRemaining, $batch['id']]);
            }
        }
        
        // Step 2: Delete old sale details
        $conn->prepare("DELETE FROM sale_details WHERE sale_id = ?")->execute([$saleId]);
        
        // Step 3: Calculate new total
        $totalAmount = 0;
        foreach ($data['details'] as $item) {
            $totalAmount += floatval($item['quantity']) * floatval($item['rate_per_unit']);
        }
        
        // Step 4: Update sale header
        $paymentMode = $data['payment_mode'] ?? 'cash';
        $toAccountId = ($paymentMode !== 'credit' && !empty($data['to_account_id'])) ? $data['to_account_id'] : null;

        // Currency support
        $currencyId = !empty($data['currency_id']) ? (int)$data['currency_id'] : null;
        $exchangeRateToBase = !empty($data['exchange_rate_to_base']) ? floatval($data['exchange_rate_to_base']) : 1;
        if (!$currencyId) {
            $defaultCurrency = getDefaultCurrency();
            $currencyId = $defaultCurrency ? $defaultCurrency['id'] : null;
        }

        $stmt = $conn->prepare("
            UPDATE sales
            SET sale_date = ?, customer_id = ?, print_name = ?, location_id = ?,
                currency_id = ?, exchange_rate_to_base = ?,
                total_amount = ?, payment_mode = ?, to_account_id = ?, remarks = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $data['sale_date'],
            $data['customer_id'],
            $data['print_name'] ?? null,
            $data['location_id'],
            $currencyId,
            $exchangeRateToBase,
            $totalAmount,
            $paymentMode,
            $toAccountId,
            $data['notes'] ?? null,
            $saleId
        ]);
        
        // Step 5: Insert new details and deduct stock using FIFO
        $detailStmt = $conn->prepare("
            INSERT INTO sale_details (sale_id, product_id, quantity, rate_per_unit, amount, cost_per_unit)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        foreach ($data['details'] as $item) {
            $quantity = floatval($item['quantity']);
            $rate = floatval($item['rate_per_unit']);
            $amount = $quantity * $rate;
            
            // Insert detail
            $detailStmt->execute([
                $saleId,
                $item['product_id'],
                $quantity,
                $rate,
                $amount,
                null
            ]);
            
            // Deduct stock using FIFO
            $remainingQty = $quantity;
            $totalCost = 0;
            
            $batchStmt = $conn->prepare("
                SELECT id, quantity_remaining, rate_per_unit
                FROM stock_batches
                WHERE location_id = ? AND product_id = ? AND quantity_remaining > 0
                ORDER BY batch_date ASC, id ASC
            ");
            $batchStmt->execute([$data['location_id'], $item['product_id']]);
            $batches = $batchStmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($batches as $batch) {
                if ($remainingQty <= 0) break;
                
                $deductQty = min($remainingQty, floatval($batch['quantity_remaining']));
                $newRemaining = floatval($batch['quantity_remaining']) - $deductQty;
                
                $conn->prepare("UPDATE stock_batches SET quantity_remaining = ? WHERE id = ?")
                    ->execute([$newRemaining, $batch['id']]);
                
                $totalCost += $deductQty * floatval($batch['rate_per_unit']);
                $remainingQty -= $deductQty;
            }
            
            // Update cost_per_unit
            if ($quantity > 0 && $totalCost > 0) {
                $soldQty = $quantity - $remainingQty;
                if ($soldQty > 0) {
                    $avgCost = $totalCost / $soldQty;
                    $conn->prepare("UPDATE sale_details SET cost_per_unit = ? WHERE sale_id = ? AND product_id = ?")
                        ->execute([$avgCost, $saleId, $item['product_id']]);
                }
            }
        }

        // Rebuild the sale's GL — drop the old 'sales' transaction (cascades
        // to its details) and post a fresh one reflecting the new totals.
        // The auto-receipt's GL (if any) is untouched here, matching the
        // existing behaviour of not regenerating the receipt on sale update.
        deleteVoucherTransaction($conn, 'sales', $saleId);
        createSalesTransaction($conn, $saleId);

        $conn->commit();
        
    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw new Exception('Database error: ' . $e->getMessage());
    }
    
    // Log audit (outside transaction)
    logAudit('sales', 'update', $saleId);
    
    // Fetch updated sale with details
    $sale = getSaleById($conn, $saleId);
    
    echo json_encode([
        'success' => true,
        'message' => 'Sale updated successfully',
        'sale' => $sale
    ]);
}

/**
 * Get sale by ID with details
 */
function getSaleById($conn, $saleId) {
    $stmt = $conn->prepare("
        SELECT s.*, 
               s.print_name,
               c.id as customer_id,
               la.name as customer_name,
               l.name as location_name,
               u.name as created_by_name
        FROM sales s
        LEFT JOIN customers c ON s.customer_id = c.id
        LEFT JOIN ledger_accounts la ON c.ledger_account_id = la.id
        LEFT JOIN locations l ON s.location_id = l.id
        LEFT JOIN users u ON s.created_by = u.id
        WHERE s.id = ?
    ");
    $stmt->execute([$saleId]);
    $sale = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$sale) {
        return null;
    }
    
    // Get sale details
    $detailStmt = $conn->prepare("
        SELECT sd.*, p.name as product_name, p.unit
        FROM sale_details sd
        LEFT JOIN products p ON sd.product_id = p.id
        WHERE sd.sale_id = ?
    ");
    $detailStmt->execute([$saleId]);
    $sale['details'] = $detailStmt->fetchAll(PDO::FETCH_ASSOC);
    
    return $sale;
}

/**
 * Handle DELETE sale
 */
function handleDelete($data, $conn) {
    // Check permission
    if (!hasPermission('sales', 'delete')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No permission to delete sales']);
        exit;
    }
    
    if (empty($data['id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Sale ID is required']);
        exit;
    }
    
    $saleId = $data['id'];
    
    // Handle temp IDs (shouldn't exist on server, but just in case)
    if (is_string($saleId) && strpos($saleId, 'TEMP-') === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Cannot delete unsaved sale']);
        exit;
    }
    
    $conn->beginTransaction();

    try {
        // Cascade-cleanup of the sale's GL transaction (cascades to details).
        // The auto-receipt (if cash sale) is intentionally NOT deleted here —
        // that mirrors the existing behaviour where deleting a sale leaves
        // its auto-generated receipt voucher in the receipts table.
        deleteVoucherTransaction($conn, 'sales', $saleId);

        // Get sale details to reverse stock
        $stmt = $conn->prepare("
            SELECT sd.*, s.location_id
            FROM sale_details sd
            JOIN sales s ON sd.sale_id = s.id
            WHERE sd.sale_id = ?
        ");
        $stmt->execute([$saleId]);
        $details = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($details)) {
            $conn->rollBack();
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Sale not found']);
            exit;
        }
        
        // Reverse stock batches for each item
        foreach ($details as $detail) {
            $quantity = floatval($detail['quantity']);
            $locationId = $detail['location_id'];
            $productId = $detail['product_id'];
            
            // Add stock back using FIFO (oldest batches first)
            $remainingQty = $quantity;
            
            $batchStmt = $conn->prepare("
                SELECT id, quantity_remaining, rate_per_unit
                FROM stock_batches
                WHERE location_id = ? AND product_id = ?
                ORDER BY batch_date ASC, id ASC
            ");
            $batchStmt->execute([$locationId, $productId]);
            $batches = $batchStmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($batches as $batch) {
                if ($remainingQty <= 0) break;
                
                // Add quantity back
                $addQty = $remainingQty;  // Add all remaining
                $newRemaining = floatval($batch['quantity_remaining']) + $addQty;
                
                $updateBatchStmt = $conn->prepare("
                    UPDATE stock_batches
                    SET quantity_remaining = ?
                    WHERE id = ?
                ");
                $updateBatchStmt->execute([$newRemaining, $batch['id']]);
                
                $remainingQty -= $addQty;
                break;  // Only update first batch to keep it simple
            }
        }
        
        // Delete sale details
        $conn->prepare("DELETE FROM sale_details WHERE sale_id = ?")->execute([$saleId]);
        
        // Delete sale
        $conn->prepare("DELETE FROM sales WHERE id = ?")->execute([$saleId]);
        
        $conn->commit();
        
        // Log audit
        logAudit('sales', 'delete', $saleId);
        
        echo json_encode([
            'success' => true,
            'message' => 'Sale deleted successfully'
        ]);
        
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
}
