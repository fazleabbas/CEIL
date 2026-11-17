<?php
/**
 * Roles Sync API - CRUD operations
 * NEVER CACHED
 */

// Prevent ANY caching - multiple methods for maximum compatibility
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: Mon, 01 Jan 2000 00:00:00 GMT');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');

// Prevent proxy caching
header('Vary: *');

// CORS headers
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
            if (!hasPermission('roles', 'create')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'No permission to create']);
                exit;
            }
            
            $name = clean($input['data']['name']);
            $description = clean($input['data']['description'] ?? '');
            $isActive = $input['data']['is_active'] ?? 1;
            
            if (empty($name)) {
                throw new Exception('Role name is required');
            }
            
            $check = $conn->prepare("SELECT id FROM roles WHERE name = ?");
            $check->execute([$name]);
            if ($check->fetch()) {
                throw new Exception('A role with this name already exists');
            }
            
            $stmt = $conn->prepare("INSERT INTO roles (name, description, is_active) VALUES (?, ?, ?)");
            $stmt->execute([$name, $description, $isActive]);
            
            $newId = $conn->lastInsertId();
            logAudit('roles', 'create', $newId);
            
            $getStmt = $conn->prepare("
                SELECT r.*,
                       (SELECT COUNT(*) FROM users WHERE role_id = r.id) as user_count
                FROM roles r
                WHERE r.id = ?
            ");
            $getStmt->execute([$newId]);
            $role = $getStmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'message' => 'Role created successfully',
                'role' => $role,
                'serverId' => $newId,
                'timestamp' => microtime(true)
            ]);
            break;
            
        case 'update':
            if (!hasPermission('roles', 'edit')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'No permission to edit']);
                exit;
            }
            
            $id = (int)$input['data']['id'];
            $name = clean($input['data']['name']);
            $description = clean($input['data']['description'] ?? '');
            $isActive = $input['data']['is_active'] ?? 1;
            
            if (empty($name)) {
                throw new Exception('Role name is required');
            }
            
            $checkStmt = $conn->prepare("SELECT * FROM roles WHERE id = ?");
            $checkStmt->execute([$id]);
            $oldRole = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$oldRole) {
                throw new Exception('Role not found');
            }
            
            if ($id == 1 && $isActive == 0) {
                throw new Exception('Cannot deactivate the Admin role');
            }
            
            $dupStmt = $conn->prepare("SELECT id FROM roles WHERE name = ? AND id != ?");
            $dupStmt->execute([$name, $id]);
            if ($dupStmt->fetch()) {
                throw new Exception('A role with this name already exists');
            }
            
            $stmt = $conn->prepare("
                UPDATE roles 
                SET name = ?, description = ?, is_active = ?
                WHERE id = ?
            ");
            $stmt->execute([$name, $description, $isActive, $id]);
            
            logAudit('roles', 'update', $id, $oldRole, [
                'name' => $name,
                'description' => $description,
                'is_active' => $isActive
            ]);
            
            $getStmt = $conn->prepare("
                SELECT r.*,
                       (SELECT COUNT(*) FROM users WHERE role_id = r.id) as user_count
                FROM roles r
                WHERE r.id = ?
            ");
            $getStmt->execute([$id]);
            $role = $getStmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'message' => 'Role updated successfully',
                'role' => $role,
                'timestamp' => microtime(true)
            ]);
            break;
            
        case 'delete':
            if (!hasPermission('roles', 'delete')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'No permission to delete']);
                exit;
            }
            
            $id = (int)$input['data']['id'];
            
            if ($id == 1 || $id == 2) {
                throw new Exception('Cannot delete default system roles (Admin and User)');
            }
            
            $checkStmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE role_id = ?");
            $checkStmt->execute([$id]);
            $count = $checkStmt->fetch()['count'];
            
            if ($count > 0) {
                throw new Exception("Cannot delete this role. $count user(s) are assigned to it");
            }
            
            $stmt = $conn->prepare("DELETE FROM roles WHERE id = ?");
            $stmt->execute([$id]);
            
            logAudit('roles', 'delete', $id);
            
            echo json_encode([
                'success' => true,
                'message' => 'Role deleted successfully',
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
