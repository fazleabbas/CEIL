<?php
/**
 * Units Sync API - Create, Update, Delete operations
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';
$data = $input['data'] ?? [];

try {
    switch ($action) {
        case 'create':
            if (!hasPermission('units', 'create')) {
                throw new Exception('Permission denied');
            }
            
            $name = trim($data['name'] ?? '');
            if (empty($name)) {
                throw new Exception('Unit name is required');
            }
            
            // Check for duplicate
            $stmt = $conn->prepare("SELECT id FROM units WHERE name = ?");
            $stmt->execute([$name]);
            if ($stmt->fetch()) {
                throw new Exception('Unit already exists');
            }
            
            $is_active = isset($data['is_active']) ? (int)$data['is_active'] : 1;
            
            $stmt = $conn->prepare("INSERT INTO units (name, is_active) VALUES (?, ?)");
            $stmt->execute([$name, $is_active]);
            
            $newId = $conn->lastInsertId();
            
            logAudit('units', 'create', $newId, null, $data);
            
            echo json_encode([
                'success' => true,
                'message' => 'Unit created successfully',
                'id' => $newId
            ]);
            break;
            
        case 'update':
            if (!hasPermission('units', 'edit')) {
                throw new Exception('Permission denied');
            }
            
            $id = (int)($data['id'] ?? 0);
            $name = trim($data['name'] ?? '');
            
            if (!$id || empty($name)) {
                throw new Exception('Invalid data');
            }
            
            // Get old values for audit
            $stmt = $conn->prepare("SELECT * FROM units WHERE id = ?");
            $stmt->execute([$id]);
            $oldData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$oldData) {
                throw new Exception('Unit not found');
            }
            
            // Check for duplicate (excluding current)
            $stmt = $conn->prepare("SELECT id FROM units WHERE name = ? AND id != ?");
            $stmt->execute([$name, $id]);
            if ($stmt->fetch()) {
                throw new Exception('Unit name already exists');
            }
            
            $is_active = isset($data['is_active']) ? (int)$data['is_active'] : 1;
            $oldName = $oldData['name'];
            
            // Update unit
            $stmt = $conn->prepare("UPDATE units SET name = ?, is_active = ? WHERE id = ?");
            $stmt->execute([$name, $is_active, $id]);
            
            // Update products using the old unit name to use the new name
            if ($oldName !== $name) {
                $stmt = $conn->prepare("UPDATE products SET unit = ? WHERE unit = ?");
                $stmt->execute([$name, $oldName]);
            }
            
            logAudit('units', 'update', $id, $oldData, $data);
            
            echo json_encode([
                'success' => true,
                'message' => 'Unit updated successfully'
            ]);
            break;
            
        case 'delete':
            if (!hasPermission('units', 'delete')) {
                throw new Exception('Permission denied');
            }
            
            $id = (int)($data['id'] ?? 0);
            if (!$id) {
                throw new Exception('Invalid ID');
            }
            
            // Get unit details
            $stmt = $conn->prepare("SELECT * FROM units WHERE id = ?");
            $stmt->execute([$id]);
            $unit = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$unit) {
                throw new Exception('Unit not found');
            }
            
            // Check if unit is in use
            $stmt = $conn->prepare("SELECT COUNT(*) FROM products WHERE unit COLLATE utf8mb4_unicode_ci = ?");
            $stmt->execute([$unit['name']]);
            $productCount = $stmt->fetchColumn();
            
            if ($productCount > 0) {
                throw new Exception("Cannot delete: {$productCount} product(s) are using this unit");
            }
            
            $stmt = $conn->prepare("DELETE FROM units WHERE id = ?");
            $stmt->execute([$id]);
            
            logAudit('units', 'delete', $id, $unit, null);
            
            echo json_encode([
                'success' => true,
                'message' => 'Unit deleted successfully'
            ]);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
