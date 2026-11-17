<?php
/**
 * Get Product Stock Info
 * Returns available stock and FIFO cost for a product at a location
 */
header('Content-Type: application/json');

require_once '../config/database.php';
require_once '../includes/functions.php';

try {
    $productId = $_GET['product_id'] ?? '';
    $locationId = $_GET['location_id'] ?? '';

    if (empty($productId) || empty($locationId)) {
        echo json_encode(['success' => false, 'error' => 'Product ID and Location ID are required']);
        exit;
    }

    // Calculate available stock
    // Stock = Opening + Purchases + Adjustment Increases + Transfer In
    //       - Sales - Adjustment Decreases - Transfer Out

    // Get opening stock
    $openingStmt = $conn->prepare("
        SELECT COALESCE(SUM(quantity), 0) as qty
        FROM opening_stocks
        WHERE product_id = ? AND location_id = ?
    ");
    $openingStmt->execute([$productId, $locationId]);
    $openingQty = floatval($openingStmt->fetchColumn());

    // Get purchases
    $purchasesStmt = $conn->prepare("
        SELECT COALESCE(SUM(pd.quantity), 0) as qty
        FROM purchase_details pd
        INNER JOIN purchases p ON pd.purchase_id = p.id
        WHERE pd.product_id = ? AND p.location_id = ?
    ");
    $purchasesStmt->execute([$productId, $locationId]);
    $purchasesQty = floatval($purchasesStmt->fetchColumn());

    // Get imports
    // Imports write stock_batches exactly like purchases do, so they are real
    // available stock. Omitting them made this endpoint report 0 for imported
    // products while the stock report (which counts them) showed the true qty.
    $importsStmt = $conn->prepare("
        SELECT COALESCE(SUM(pid.quantity), 0) as qty
        FROM purchase_import_details pid
        INNER JOIN purchase_imports pi ON pid.import_id = pi.id
        WHERE pid.product_id = ? AND pi.location_id = ?
    ");
    $importsStmt->execute([$productId, $locationId]);
    $importsQty = floatval($importsStmt->fetchColumn());

    // Get sales
    $salesStmt = $conn->prepare("
        SELECT COALESCE(SUM(sd.quantity), 0) as qty
        FROM sale_details sd
        INNER JOIN sales s ON sd.sale_id = s.id
        WHERE sd.product_id = ? AND s.location_id = ?
    ");
    $salesStmt->execute([$productId, $locationId]);
    $salesQty = floatval($salesStmt->fetchColumn());

    // Get adjustment increases
    $adjIncStmt = $conn->prepare("
        SELECT COALESCE(SUM(sad.quantity), 0) as qty
        FROM stock_adjustment_details sad
        INNER JOIN stock_adjustments sa ON sad.adjustment_id = sa.id
        WHERE sad.product_id = ? AND sa.location_id = ? AND sad.adjustment_type = 'increase'
    ");
    $adjIncStmt->execute([$productId, $locationId]);
    $adjIncreaseQty = floatval($adjIncStmt->fetchColumn());

    // Get adjustment decreases
    $adjDecStmt = $conn->prepare("
        SELECT COALESCE(SUM(sad.quantity), 0) as qty
        FROM stock_adjustment_details sad
        INNER JOIN stock_adjustments sa ON sad.adjustment_id = sa.id
        WHERE sad.product_id = ? AND sa.location_id = ? AND sad.adjustment_type = 'decrease'
    ");
    $adjDecStmt->execute([$productId, $locationId]);
    $adjDecreaseQty = floatval($adjDecStmt->fetchColumn());

    // Get transfer in (received transfers TO this location)
    $transferInStmt = $conn->prepare("
        SELECT COALESCE(SUM(td.received_quantity), 0) as qty
        FROM stock_transfer_details td
        INNER JOIN stock_transfers t ON td.transfer_id = t.id
        WHERE td.product_id = ? AND t.to_location_id = ? AND t.status = 'received'
    ");
    $transferInStmt->execute([$productId, $locationId]);
    $transferInQty = floatval($transferInStmt->fetchColumn());

    // Get transfer out (dispatched transfers FROM this location)
    $transferOutStmt = $conn->prepare("
        SELECT COALESCE(SUM(td.dispatched_quantity), 0) as qty
        FROM stock_transfer_details td
        INNER JOIN stock_transfers t ON td.transfer_id = t.id
        WHERE td.product_id = ? AND t.from_location_id = ? AND t.status IN ('in_transit', 'received')
    ");
    $transferOutStmt->execute([$productId, $locationId]);
    $transferOutQty = floatval($transferOutStmt->fetchColumn());

    // Calculate available stock
    $availableStock = $openingQty + $purchasesQty + $importsQty + $adjIncreaseQty + $transferInQty
                    - $salesQty - $adjDecreaseQty - $transferOutQty;

    // Get FIFO cost from stock_batches (oldest batch with remaining quantity)
    $fifoCostStmt = $conn->prepare("
        SELECT rate_per_unit
        FROM stock_batches
        WHERE product_id = ? AND location_id = ? AND quantity_remaining > 0
        ORDER BY batch_date ASC, id ASC
        LIMIT 1
    ");
    $fifoCostStmt->execute([$productId, $locationId]);
    $fifoCost = $fifoCostStmt->fetchColumn();

    // If no batch found, try to get from opening stock or latest purchase
    if ($fifoCost === false) {
        // Try opening stock
        $openingRateStmt = $conn->prepare("
            SELECT rate_per_unit
            FROM opening_stocks
            WHERE product_id = ? AND location_id = ?
            ORDER BY stock_date DESC
            LIMIT 1
        ");
        $openingRateStmt->execute([$productId, $locationId]);
        $fifoCost = $openingRateStmt->fetchColumn();
    }

    if ($fifoCost === false) {
        // Try latest purchase
        $purchaseRateStmt = $conn->prepare("
            SELECT pd.rate_per_unit
            FROM purchase_details pd
            INNER JOIN purchases p ON pd.purchase_id = p.id
            WHERE pd.product_id = ? AND p.location_id = ?
            ORDER BY p.purchase_date DESC, p.id DESC
            LIMIT 1
        ");
        $purchaseRateStmt->execute([$productId, $locationId]);
        $fifoCost = $purchaseRateStmt->fetchColumn();
    }

    // Default to 0 if no cost found
    if ($fifoCost === false) {
        $fifoCost = 0;
    }

    echo json_encode([
        'success' => true,
        'product_id' => $productId,
        'location_id' => $locationId,
        'available_stock' => $availableStock,
        'fifo_cost' => floatval($fifoCost),
        'breakdown' => [
            'opening' => $openingQty,
            'purchases' => $purchasesQty,
            'imports' => $importsQty,
            'sales' => $salesQty,
            'adj_increase' => $adjIncreaseQty,
            'adj_decrease' => $adjDecreaseQty,
            'transfer_in' => $transferInQty,
            'transfer_out' => $transferOutQty
        ]
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
