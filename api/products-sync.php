<?php
/**
 * Products Sync API - Handles CRUD operations
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
            if (!hasPermission('products', 'create')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'No permission to create']);
                exit;
            }
            
            // Store free text raw (escaped at output) to avoid &#039; entity corruption
            $name = cleanText($input['data']['name']);
            $brand = cleanText($input['data']['brand'] ?? '');
            $categoryId = (int)$input['data']['category_id'];
            $unit = cleanText($input['data']['unit']);
            $barcode = cleanText($input['data']['barcode'] ?? '');
            $defaultSellingPrice = floatval($input['data']['default_selling_price'] ?? 0);
            $description = cleanText($input['data']['description'] ?? '');
            $isActive = $input['data']['is_active'] ?? 1;

            // Validate
            if (empty($name)) {
                throw new Exception('Product name is required');
            }

            if (empty($categoryId)) {
                throw new Exception('Category is required');
            }

            if (empty($unit)) {
                throw new Exception('Unit is required');
            }

            // Check duplicate name
            $check = $conn->prepare("SELECT id FROM products WHERE name = ?");
            $check->execute([$name]);
            if ($check->fetch()) {
                throw new Exception('A product with this name already exists');
            }

            // Check duplicate barcode (if provided)
            if (!empty($barcode)) {
                $check = $conn->prepare("SELECT id FROM products WHERE barcode = ?");
                $check->execute([$barcode]);
                if ($check->fetch()) {
                    throw new Exception('This barcode is already assigned to another product');
                }
            }

            // Insert product
            $stmt = $conn->prepare("
                INSERT INTO products (name, brand, category_id, unit, barcode, default_selling_price, description, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$name, $brand, $categoryId, $unit, $barcode, $defaultSellingPrice, $description, $isActive]);
            
            $newId = $conn->lastInsertId();
            logAudit('products', 'create', $newId);
            
            // Get the created product
            $getStmt = $conn->prepare("
                SELECT p.*, 
                       pc.name as category_name,
                       0 as total_stock,
                       0 as purchase_count,
                       0 as sale_count
                FROM products p
                LEFT JOIN product_categories pc ON p.category_id = pc.id
                WHERE p.id = ?
            ");
            $getStmt->execute([$newId]);
            $product = $getStmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'message' => 'Product created successfully',
                'product' => $product,
                'serverId' => $newId
            ]);
            break;
            
        case 'update':
            if (!hasPermission('products', 'edit')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'No permission to edit']);
                exit;
            }
            
            $id = (int)$input['data']['id'];
            // Store free text raw (escaped at output) to avoid &#039; entity corruption
            $name = cleanText($input['data']['name']);
            $brand = cleanText($input['data']['brand'] ?? '');
            $categoryId = (int)$input['data']['category_id'];
            $unit = cleanText($input['data']['unit']);
            $barcode = cleanText($input['data']['barcode'] ?? '');
            $defaultSellingPrice = floatval($input['data']['default_selling_price'] ?? 0);
            $description = cleanText($input['data']['description'] ?? '');
            $isActive = $input['data']['is_active'] ?? 1;
            
            // Validate
            if (empty($name)) {
                throw new Exception('Product name is required');
            }
            
            if (empty($categoryId)) {
                throw new Exception('Category is required');
            }
            
            if (empty($unit)) {
                throw new Exception('Unit is required');
            }
            
            // Check if product exists
            $checkStmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
            $checkStmt->execute([$id]);
            $oldProduct = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$oldProduct) {
                throw new Exception('Product not found');
            }
            
            // Check for duplicate name
            $dupStmt = $conn->prepare("SELECT id FROM products WHERE name = ? AND id != ?");
            $dupStmt->execute([$name, $id]);
            if ($dupStmt->fetch()) {
                throw new Exception('A product with this name already exists');
            }
            
            // Check for duplicate barcode (if provided)
            if (!empty($barcode)) {
                $dupStmt = $conn->prepare("SELECT id FROM products WHERE barcode = ? AND id != ?");
                $dupStmt->execute([$barcode, $id]);
                if ($dupStmt->fetch()) {
                    throw new Exception('This barcode is already assigned to another product');
                }
            }
            
            // Update product
            $stmt = $conn->prepare("
                UPDATE products
                SET name = ?, brand = ?, category_id = ?, unit = ?, barcode = ?,
                    default_selling_price = ?, description = ?, is_active = ?
                WHERE id = ?
            ");
            $stmt->execute([$name, $brand, $categoryId, $unit, $barcode, $defaultSellingPrice, $description, $isActive, $id]);

            logAudit('products', 'update', $id, $oldProduct, [
                'name' => $name,
                'brand' => $brand,
                'category_id' => $categoryId,
                'unit' => $unit,
                'barcode' => $barcode,
                'default_selling_price' => $defaultSellingPrice,
                'description' => $description,
                'is_active' => $isActive
            ]);
            
            // Get the updated product
            $getStmt = $conn->prepare("
                SELECT p.*,
                       pc.name as category_name,
                       0 as total_stock,
                       (SELECT COUNT(*) FROM purchase_details WHERE product_id = p.id) as purchase_count,
                       (SELECT COUNT(*) FROM sale_details WHERE product_id = p.id) as sale_count
                FROM products p
                LEFT JOIN product_categories pc ON p.category_id = pc.id
                WHERE p.id = ?
            ");
            $getStmt->execute([$id]);
            $product = $getStmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'message' => 'Product updated successfully',
                'product' => $product
            ]);
            break;
            
        case 'delete':
            if (!hasPermission('products', 'delete')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'No permission to delete']);
                exit;
            }
            
            $id = (int)$input['data']['id'];
            
            // Check if product is in use
            $checkStmt = $conn->prepare("
                SELECT 
                    (SELECT COUNT(*) FROM purchase_details WHERE product_id = ?) as purchase_count,
                    (SELECT COUNT(*) FROM sale_details WHERE product_id = ?) as sale_count,
                    (SELECT COUNT(*) FROM stock_batches WHERE product_id = ?) as stock_count
            ");
            $checkStmt->execute([$id, $id, $id]);
            $usage = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            $errors = [];
            if ($usage['purchase_count'] > 0) {
                $errors[] = "{$usage['purchase_count']} purchase(s)";
            }
            if ($usage['sale_count'] > 0) {
                $errors[] = "{$usage['sale_count']} sale(s)";
            }
            if ($usage['stock_count'] > 0) {
                $errors[] = "{$usage['stock_count']} stock batch(es)";
            }
            
            if (!empty($errors)) {
                throw new Exception('Cannot delete this product. It has: ' . implode(', ', $errors));
            }
            
            // Delete product (will also delete product_pricing due to cascade)
            $stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
            $stmt->execute([$id]);
            
            logAudit('products', 'delete', $id);
            
            echo json_encode([
                'success' => true,
                'message' => 'Product deleted successfully'
            ]);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
