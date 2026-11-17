<?php
/**
 * Locations GET API
 * Returns all locations with usage counts - ALWAYS FRESH, NEVER CACHED
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
    // Admins see all locations; non-admins are scoped to their assigned locations
    // (this API also feeds transaction dropdowns, so it must work for non-admins).
    if (getUserData('role_name') == 'Admin') {
        $stmt = $conn->prepare("
            SELECT
                l.*,
                (SELECT COUNT(*) FROM user_locations WHERE location_id = l.id) as user_count
            FROM locations l
            ORDER BY l.name ASC
        ");
        $stmt->execute();
    } else {
        $stmt = $conn->prepare("
            SELECT
                l.*,
                (SELECT COUNT(*) FROM user_locations WHERE location_id = l.id) as user_count
            FROM locations l
            INNER JOIN user_locations ul ON ul.location_id = l.id
            WHERE ul.user_id = ?
            ORDER BY l.name ASC
        ");
        $stmt->execute([getUserId()]);
    }
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Bulk-compute product counts (products with stock > 0) for all locations in one query
    $productCounts = getProductCountsPerLocation();
    foreach ($locations as &$location) {
        $location['product_count'] = $productCounts[(int)$location['id']] ?? 0;
    }
    unset($location);
    
    echo json_encode([
        'success' => true,
        'locations' => $locations,
        'timestamp' => microtime(true),
        'server_time' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
