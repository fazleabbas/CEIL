<?php
/**
 * Products API - GET Endpoint
 * Fetches all active products with pricing for offline caching
 */

require_once '../config/database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

// Check authentication
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

// Check permission
if (!hasPermission('products', 'view')) {
    http_response_code(403);
    echo json_encode(['error' => 'No permission to view products']);
    exit;
}

try {
    // Fetch all active products with category names
    $stmt = $conn->query("
        SELECT 
            p.id,
            p.name,
            p.category_id,
            pc.name as category_name,
            p.unit,
            p.barcode,
            p.default_selling_price,
            p.default_currency_id,
            p.is_active
        FROM products p
        LEFT JOIN product_categories pc ON p.category_id = pc.id
        WHERE p.is_active = 1
        ORDER BY pc.name, p.name
    ");
    
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch location-specific pricing for all products
    $pricingStmt = $conn->query("
        SELECT product_id, location_id, selling_price
        FROM product_pricing
        WHERE is_active = 1
    ");
    
    $pricing = [];
    while ($row = $pricingStmt->fetch(PDO::FETCH_ASSOC)) {
        if (!isset($pricing[$row['product_id']])) {
            $pricing[$row['product_id']] = [];
        }
        $pricing[$row['product_id']][] = [
            'location_id' => $row['location_id'],
            'selling_price' => $row['selling_price']
        ];
    }
    
    // Attach pricing to products
    foreach ($products as &$product) {
        $product['pricing'] = $pricing[$product['id']] ?? [];
    }
    
    echo json_encode($products);
    
} catch (PDOException $e) {
    error_log("Products API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
