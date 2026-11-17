<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authorized']);
    exit;
}

if (!hasPermission('sales', 'create')) {
    echo json_encode(['success' => false, 'message' => 'No permission']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$locationId = $input['location_id'] ?? 0;
$saleDate = dbDate($input['sale_date'] ?? date('Y-m-d'));
$customerId = $input['customer_id'] ?? 1; // Default to Cash customer
$paymentMode = clean($input['payment_mode'] ?? 'cash');
$items = $input['items'] ?? [];

if (empty($items)) {
    echo json_encode(['success' => false, 'message' => 'No items provided']);
    exit;
}

try {
    $conn->beginTransaction();
    
    // Get sale number
    $saleNumber = getNextNumber('sales', 'sale_number', 'SAL');
    
    // Calculate total
    $totalAmount = 0;
    foreach ($items as $item) {
        $totalAmount += $item['quantity'] * $item['rate'];
    }
    
    // Insert sale
    $stmt = $conn->prepare("
        INSERT INTO sales 
        (sale_number, sale_date, customer_id, location_id, total_amount, payment_mode, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $saleNumber,
        $saleDate,
        $customerId,
        $locationId,
        $totalAmount,
        $paymentMode,
        getUserId()
    ]);
    
    $saleId = $conn->lastInsertId();
    
    // Insert sale details and update stock batches
    $detailStmt = $conn->prepare("
        INSERT INTO sale_details (sale_id, product_id, quantity, rate_per_unit, amount)
        VALUES (?, ?, ?, ?, ?)
    ");
    
    foreach ($items as $item) {
        $amount = $item['quantity'] * $item['rate'];
        
        // Insert detail
        $detailStmt->execute([
            $saleId,
            $item['product_id'],
            $item['quantity'],
            $item['rate'],
            $amount
        ]);
        
        // Update stock batches (FIFO)
        $remainingQty = $item['quantity'];
        
        // Get batches in FIFO order
        $batchStmt = $conn->prepare("
            SELECT id, quantity_remaining 
            FROM stock_batches 
            WHERE location_id = ? AND product_id = ? AND quantity_remaining > 0
            ORDER BY batch_date ASC, id ASC
        ");
        $batchStmt->execute([$locationId, $item['product_id']]);
        $batches = $batchStmt->fetchAll();
        
        foreach ($batches as $batch) {
            if ($remainingQty <= 0) break;
            
            $deductQty = min($remainingQty, $batch['quantity_remaining']);
            $newRemaining = $batch['quantity_remaining'] - $deductQty;
            
            // Update batch
            $updateStmt = $conn->prepare("
                UPDATE stock_batches 
                SET quantity_remaining = ?
                WHERE id = ?
            ");
            $updateStmt->execute([$newRemaining, $batch['id']]);
            
            $remainingQty -= $deductQty;
        }
        
       // Allow negative stock - just log a warning
            error_log("Warning: Insufficient stock for product ID: {$item['product_id']}. Remaining: {$remainingQty}");
    }
    
    $conn->commit();
    
    logAudit('sales', 'create', $saleId);
    
    echo json_encode([
        'success' => true,
        'sale_id' => $saleId,
        'sale_number' => $saleNumber
    ]);
    
} catch (Exception $e) {
    $conn->rollBack();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
