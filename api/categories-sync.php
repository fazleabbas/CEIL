<?php
/**
 * Categories CRUD API
 * Handles create, update, delete operations - NEVER CACHED
 */

// Prevent ANY caching
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: Mon, 01 Jan 2000 00:00:00 GMT');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
header('Vary: *');

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
            if (!hasPermission('categories', 'create')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'No permission to create']);
                exit;
            }
            
            $name = cleanText($input['data']['name']);
            $description = cleanText($input['data']['description'] ?? '');
            $isActive = $input['data']['is_active'] ?? 1;
            $parentId = isset($input['data']['parent_id']) && $input['data']['parent_id'] !== '' ? (int)$input['data']['parent_id'] : null;

            if (empty($name)) {
                throw new Exception('Category name is required');
            }

            // Validate parent exists if specified
            if ($parentId !== null) {
                $parentCheck = $conn->prepare("SELECT id FROM product_categories WHERE id = ?");
                $parentCheck->execute([$parentId]);
                if (!$parentCheck->fetch()) {
                    throw new Exception('Parent category not found');
                }
            }

            // Check for duplicate name
            $check = $conn->prepare("SELECT id FROM product_categories WHERE name = ?");
            $check->execute([$name]);
            if ($check->fetch()) {
                throw new Exception('A category with this name already exists');
            }

            $stmt = $conn->prepare("
                INSERT INTO product_categories (parent_id, name, description, is_active)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$parentId, $name, $description, $isActive]);
            
            $newId = $conn->lastInsertId();
            logAudit('categories', 'create', $newId);
            
            // Get the created category with counts
            $getStmt = $conn->prepare("
                SELECT 
                    pc.*,
                    (SELECT COUNT(*) FROM products WHERE category_id = pc.id) as product_count
                FROM product_categories pc
                WHERE pc.id = ?
            ");
            $getStmt->execute([$newId]);
            $category = $getStmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'message' => 'Category created successfully',
                'category' => $category,
                'timestamp' => microtime(true)
            ]);
            break;
            
        case 'update':
            if (!hasPermission('categories', 'edit')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'No permission to edit']);
                exit;
            }
            
            $id = (int)$input['data']['id'];
            $name = cleanText($input['data']['name']);
            $description = cleanText($input['data']['description'] ?? '');
            $isActive = $input['data']['is_active'] ?? 1;
            $parentId = isset($input['data']['parent_id']) && $input['data']['parent_id'] !== '' ? (int)$input['data']['parent_id'] : null;

            if (empty($name)) {
                throw new Exception('Category name is required');
            }

            // Check if category exists
            $checkStmt = $conn->prepare("SELECT * FROM product_categories WHERE id = ?");
            $checkStmt->execute([$id]);
            $oldCategory = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$oldCategory) {
                throw new Exception('Category not found');
            }

            // Prevent setting self as parent
            if ($parentId !== null && $parentId === $id) {
                throw new Exception('A category cannot be its own parent');
            }

            // Prevent circular reference: parent cannot be a descendant of this category
            if ($parentId !== null) {
                $descendantIds = getDescendantCategoryIds($id, $conn);
                if (in_array($parentId, $descendantIds)) {
                    throw new Exception('Cannot set a child category as parent (circular reference)');
                }
            }

            // Validate parent exists if specified
            if ($parentId !== null) {
                $parentCheck = $conn->prepare("SELECT id FROM product_categories WHERE id = ?");
                $parentCheck->execute([$parentId]);
                if (!$parentCheck->fetch()) {
                    throw new Exception('Parent category not found');
                }
            }

            // Check for duplicate name
            $dupStmt = $conn->prepare("SELECT id FROM product_categories WHERE name = ? AND id != ?");
            $dupStmt->execute([$name, $id]);
            if ($dupStmt->fetch()) {
                throw new Exception('A category with this name already exists');
            }

            $stmt = $conn->prepare("
                UPDATE product_categories
                SET parent_id = ?, name = ?, description = ?, is_active = ?
                WHERE id = ?
            ");
            $stmt->execute([$parentId, $name, $description, $isActive, $id]);

            logAudit('categories', 'update', $id, $oldCategory, [
                'parent_id' => $parentId,
                'name' => $name,
                'description' => $description,
                'is_active' => $isActive
            ]);
            
            // Get updated category with counts
            $getStmt = $conn->prepare("
                SELECT 
                    pc.*,
                    (SELECT COUNT(*) FROM products WHERE category_id = pc.id) as product_count
                FROM product_categories pc
                WHERE pc.id = ?
            ");
            $getStmt->execute([$id]);
            $category = $getStmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'message' => 'Category updated successfully',
                'category' => $category,
                'timestamp' => microtime(true)
            ]);
            break;
            
        case 'delete':
            if (!hasPermission('categories', 'delete')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'No permission to delete']);
                exit;
            }
            
            $id = (int)$input['data']['id'];
            
            // Check if category is in use
            $checkStmt = $conn->prepare("SELECT COUNT(*) as count FROM products WHERE category_id = ?");
            $checkStmt->execute([$id]);
            $count = $checkStmt->fetch()['count'];
            
            if ($count > 0) {
                throw new Exception("Cannot delete this category. It has $count product(s)");
            }
            
            // Delete category
            $stmt = $conn->prepare("DELETE FROM product_categories WHERE id = ?");
            $stmt->execute([$id]);
            
            logAudit('categories', 'delete', $id);
            
            echo json_encode([
                'success' => true,
                'message' => 'Category deleted successfully',
                'timestamp' => microtime(true)
            ]);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => microtime(true)
    ]);
}
