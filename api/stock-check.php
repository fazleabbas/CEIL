<?php
/**
 * Stock Check API
 * Returns available stock for products at a location
 * Used to validate sales before saving
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

// Only accept GET or POST
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'])) {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Get parameters
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $locationId = $input['location_id'] ?? null;
    $productIds = $input['product_ids'] ?? [];
    $excludeSaleId = $input['exclude_sale_id'] ?? null; // For edit mode
} else {
    $locationId = $_GET['location_id'] ?? null;
    $productIds = isset($_GET['product_ids']) ? explode(',', $_GET['product_ids']) : [];
    $excludeSaleId = $_GET['exclude_sale_id'] ?? null;
}

if (!$locationId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Location ID is required']);
    exit;
}

if (empty($productIds)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Product IDs are required']);
    exit;
}

// Validate location access
if (!canAccessLocation($locationId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'You do not have access to this location']);
    exit;
}

try {
    $today = date('Y-m-d');
    $stockData = [];
    
    foreach ($productIds as $productId) {
        $productId = intval($productId);
        
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

        // Get imports till today
        // Imports write stock_batches exactly like purchases do; leaving them
        // out understated availability for every imported product.
        $importStmt = $conn->prepare("
            SELECT COALESCE(SUM(pid.quantity), 0) as total
            FROM purchase_import_details pid
            JOIN purchase_imports pi ON pid.import_id = pi.id
            WHERE pid.product_id = ? AND pi.location_id = ? AND pi.import_date <= ?
        ");
        $importStmt->execute([$productId, $locationId, $today]);
        $importQty = floatval($importStmt->fetchColumn());
        
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

        // Get transfers IN to this location
        $transferInStmt = $conn->prepare("
            SELECT COALESCE(SUM(std.received_quantity), 0) as total
            FROM stock_transfer_details std
            JOIN stock_transfers st ON std.transfer_id = st.id
            WHERE std.product_id = ? AND st.to_location_id = ? AND st.transfer_date <= ? AND st.status = 'received'
        ");
        $transferInStmt->execute([$productId, $locationId, $today]);
        $transferInQty = floatval($transferInStmt->fetchColumn());

        // Get transfers OUT from this location
        $transferOutStmt = $conn->prepare("
            SELECT COALESCE(SUM(std.dispatched_quantity), 0) as total
            FROM stock_transfer_details std
            JOIN stock_transfers st ON std.transfer_id = st.id
            WHERE std.product_id = ? AND st.from_location_id = ? AND st.transfer_date <= ? AND st.status IN ('in_transit','received')
        ");
        $transferOutStmt->execute([$productId, $locationId, $today]);
        $transferOutQty = floatval($transferOutStmt->fetchColumn());

        // Get consumption voucher decreases (wrapped in try-catch for pre-migration compatibility)
        $consumptionQty = 0;
        try {
            $consumptionStmt = $conn->prepare("
                SELECT COALESCE(SUM(cvd.quantity), 0) as total
                FROM consumption_voucher_details cvd
                JOIN consumption_vouchers cv ON cvd.voucher_id = cv.id
                WHERE cvd.product_id = ? AND cv.location_id = ? AND cv.voucher_date <= ?
            ");
            $consumptionStmt->execute([$productId, $locationId, $today]);
            $consumptionQty = floatval($consumptionStmt->fetchColumn());
        } catch (PDOException $e) {
            // Table may not exist yet if migration hasn't been run
        }

        // Calculate available stock
        $availableStock = $openingQty + $purchaseQty + $importQty - $salesQty + $adjustmentQty + $transferInQty - $transferOutQty - $consumptionQty;

        // Get product name for error messages
        $productStmt = $conn->prepare("SELECT name FROM products WHERE id = ?");
        $productStmt->execute([$productId]);
        $productName = $productStmt->fetchColumn() ?: 'Unknown Product';

        $stockData[$productId] = [
            'product_id' => $productId,
            'product_name' => $productName,
            'available_stock' => $availableStock,
            'opening' => $openingQty,
            'purchases' => $purchaseQty,
            'imports' => $importQty,
            'sales' => $salesQty,
            'adjustments' => $adjustmentQty,
            'transfers_in' => $transferInQty,
            'transfers_out' => $transferOutQty,
            'consumption' => $consumptionQty
        ];
    }
    
    echo json_encode([
        'success' => true,
        'location_id' => intval($locationId),
        'stock' => $stockData
    ]);
    
} catch (PDOException $e) {
    error_log("Stock Check API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
