<?php
/**
 * Product Pricing API
 * Retrieve all active product pricing (location-specific prices)
 */

header('Content-Type: application/json');

require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is logged in
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    $stmt = $conn->prepare("
        SELECT 
            pp.id,
            pp.product_id,
            pp.location_id,
            pp.selling_price,
            pp.currency_id,
            pp.is_active
        FROM product_pricing pp
        WHERE pp.is_active = 1
        ORDER BY pp.product_id, pp.location_id
    ");
    $stmt->execute();
    $pricing = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'pricing' => $pricing,
        'timestamp' => date('c')
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
