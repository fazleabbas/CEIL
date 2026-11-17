<?php
/**
 * Products GET API
 * Retrieve all products with stock and transaction counts
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

// Check permissions
if (!hasPermission('products', 'view')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

try {
    $stmt = $conn->prepare("
        SELECT p.*,
               pc.name as category_name,
               0 as total_stock,
               (SELECT COUNT(*) FROM purchase_details WHERE product_id = p.id) as purchase_count,
               (SELECT COUNT(*) FROM sale_details WHERE product_id = p.id) as sale_count
        FROM products p
        LEFT JOIN product_categories pc ON p.category_id = pc.id
        WHERE p.is_active = 1
        ORDER BY p.name ASC
    ");
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'products' => $products,
        'timestamp' => date('c')
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
