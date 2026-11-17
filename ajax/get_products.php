<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

require_once '../config/database.php';
require_once '../includes/functions.php';

try {
    $search = $_GET['search'] ?? '';
    $locationId = $_GET['location'] ?? '';
    
    if (empty($search)) {
        echo json_encode(['success' => false, 'message' => 'Search term is required']);
        exit;
    }
    
    if (empty($locationId)) {
        echo json_encode(['success' => false, 'message' => 'Location is required']);
        exit;
    }
    
    // Search products
    $searchTerm = '%' . $search . '%';
    
    $sql = "
        SELECT 
            p.id,
            p.name,
            p.unit,
            p.barcode,
            p.default_selling_price,
            COALESCE(pp.selling_price, p.default_selling_price) as selling_price,
            c.name as category_name
        FROM products p
        LEFT JOIN product_categories c ON p.category_id = c.id
        LEFT JOIN product_pricing pp ON p.id = pp.product_id 
            AND pp.location_id = ? 
            AND pp.is_active = 1
        WHERE p.is_active = 1 
        AND (
            p.name LIKE ? 
            OR p.barcode LIKE ?
            OR c.name LIKE ?
        )
        ORDER BY p.name
        LIMIT 20
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$locationId, $searchTerm, $searchTerm, $searchTerm]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'products' => $products
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}
?>
