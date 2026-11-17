<?php
/**
 * Locations CRUD API
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
require_once '../includes/subscription.php';

// Check if user is logged in
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

/**
 * Normalize a submitted location_type to a valid enum value, or NULL when blank/invalid.
 * Optional field — blank means "not set".
 */
function normalizeLocationType($value) {
    $allowed = ['site', 'warehouse', 'others'];
    $value = strtolower(trim((string)($value ?? '')));
    return in_array($value, $allowed, true) ? $value : null;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['action'])) {
        throw new Exception('Action not specified');
    }
    
    switch ($input['action']) {
        case 'create':
            if (!hasPermission('locations', 'create')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'No permission to create']);
                exit;
            }
            
            // Check subscription limit for locations
            if (!canAddLocation($conn)) {
                $status = getSubscriptionStatus($conn);
                http_response_code(403);
                echo json_encode([
                    'success' => false, 
                    'error' => "Location limit reached ({$status['current_locations']}/{$status['max_locations']}). Please upgrade your subscription.",
                    'subscription_limit' => true
                ]);
                exit;
            }
            
            $name = cleanText($input['data']['name']);
            $address = cleanText($input['data']['address'] ?? '');
            $phone = cleanText($input['data']['phone'] ?? '');
            $locationType = normalizeLocationType($input['data']['location_type'] ?? null);
            $isActive = $input['data']['is_active'] ?? 1;
            $parentId = isset($input['data']['parent_id']) && $input['data']['parent_id'] !== '' ? (int)$input['data']['parent_id'] : null;

            if (empty($name)) {
                throw new Exception('Location name is required');
            }

            // Validate parent exists if specified
            if ($parentId !== null) {
                $parentCheck = $conn->prepare("SELECT id FROM locations WHERE id = ?");
                $parentCheck->execute([$parentId]);
                if (!$parentCheck->fetch()) {
                    throw new Exception('Parent location not found');
                }
            }

            // Check for duplicate name
            $check = $conn->prepare("SELECT id FROM locations WHERE name = ?");
            $check->execute([$name]);
            if ($check->fetch()) {
                throw new Exception('A location with this name already exists');
            }

            $stmt = $conn->prepare("
                INSERT INTO locations (parent_id, name, address, phone, location_type, is_active)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$parentId, $name, $address, $phone, $locationType, $isActive]);
            
            $newId = $conn->lastInsertId();
            logAudit('locations', 'create', $newId);
            
            // Get the created location with counts
            $getStmt = $conn->prepare("
                SELECT 
                    l.*,
                    (SELECT COUNT(*) FROM user_locations WHERE location_id = l.id) as user_count
                FROM locations l
                WHERE l.id = ?
            ");
            $getStmt->execute([$newId]);
            $location = $getStmt->fetch(PDO::FETCH_ASSOC);

            if ($location) {
                $productCounts = getProductCountsPerLocation();
                $location['product_count'] = $productCounts[(int)$location['id']] ?? 0;
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Location created successfully',
                'location' => $location,
                'timestamp' => microtime(true)
            ]);
            break;
            
        case 'update':
            if (!hasPermission('locations', 'edit')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'No permission to edit']);
                exit;
            }
            
            $id = (int)$input['data']['id'];
            $name = cleanText($input['data']['name']);
            $address = cleanText($input['data']['address'] ?? '');
            $phone = cleanText($input['data']['phone'] ?? '');
            $locationType = normalizeLocationType($input['data']['location_type'] ?? null);
            $isActive = $input['data']['is_active'] ?? 1;
            $parentId = isset($input['data']['parent_id']) && $input['data']['parent_id'] !== '' ? (int)$input['data']['parent_id'] : null;

            if (empty($name)) {
                throw new Exception('Location name is required');
            }

            // Check if location exists
            $checkStmt = $conn->prepare("SELECT * FROM locations WHERE id = ?");
            $checkStmt->execute([$id]);
            $oldLocation = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$oldLocation) {
                throw new Exception('Location not found');
            }

            // Prevent setting self as parent
            if ($parentId !== null && $parentId === $id) {
                throw new Exception('A location cannot be its own parent');
            }

            // Prevent circular reference: parent cannot be a descendant of this location
            if ($parentId !== null) {
                $descendantIds = getDescendantLocationIds($id, $conn);
                if (in_array($parentId, $descendantIds)) {
                    throw new Exception('Cannot set a child location as parent (circular reference)');
                }
            }

            // Check for duplicate name
            $dupStmt = $conn->prepare("SELECT id FROM locations WHERE name = ? AND id != ?");
            $dupStmt->execute([$name, $id]);
            if ($dupStmt->fetch()) {
                throw new Exception('A location with this name already exists');
            }

            $stmt = $conn->prepare("
                UPDATE locations
                SET parent_id = ?, name = ?, address = ?, phone = ?, location_type = ?, is_active = ?
                WHERE id = ?
            ");
            $stmt->execute([$parentId, $name, $address, $phone, $locationType, $isActive, $id]);

            logAudit('locations', 'update', $id, $oldLocation, [
                'parent_id' => $parentId,
                'name' => $name,
                'address' => $address,
                'phone' => $phone,
                'location_type' => $locationType,
                'is_active' => $isActive
            ]);
            
            // Get updated location with counts
            $getStmt = $conn->prepare("
                SELECT 
                    l.*,
                    (SELECT COUNT(*) FROM user_locations WHERE location_id = l.id) as user_count
                FROM locations l
                WHERE l.id = ?
            ");
            $getStmt->execute([$id]);
            $location = $getStmt->fetch(PDO::FETCH_ASSOC);

            if ($location) {
                $productCounts = getProductCountsPerLocation();
                $location['product_count'] = $productCounts[(int)$location['id']] ?? 0;
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Location updated successfully',
                'location' => $location,
                'timestamp' => microtime(true)
            ]);
            break;
            
        case 'delete':
            if (!hasPermission('locations', 'delete')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'No permission to delete']);
                exit;
            }
            
            $id = (int)$input['data']['id'];
            
            // Check if location is in use
            $checks = [
                'opening_stocks' => $conn->prepare("SELECT COUNT(*) as count FROM opening_stocks WHERE location_id = ?"),
                'purchases' => $conn->prepare("SELECT COUNT(*) as count FROM purchases WHERE location_id = ?"),
                'sales' => $conn->prepare("SELECT COUNT(*) as count FROM sales WHERE location_id = ?"),
                'stock_batches' => $conn->prepare("SELECT COUNT(*) as count FROM stock_batches WHERE location_id = ? AND quantity_remaining > 0")
            ];
            
            $inUse = [];
            foreach ($checks as $table => $stmt) {
                $stmt->execute([$id]);
                $count = $stmt->fetch()['count'];
                if ($count > 0) {
                    $inUse[] = "$count " . str_replace('_', ' ', $table);
                }
            }
            
            if (!empty($inUse)) {
                throw new Exception("Cannot delete this location. It has: " . implode(', ', $inUse));
            }
            
            // Also delete user_locations assignments
            $conn->prepare("DELETE FROM user_locations WHERE location_id = ?")->execute([$id]);
            
            // Delete location
            $stmt = $conn->prepare("DELETE FROM locations WHERE id = ?");
            $stmt->execute([$id]);
            
            logAudit('locations', 'delete', $id);
            
            echo json_encode([
                'success' => true,
                'message' => 'Location deleted successfully',
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
