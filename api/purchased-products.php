<?php
/**
 * Purchased Products GET API
 * Distinct products that appear on ANY purchase at the user's locations,
 * regardless of date. Feeds the product filter on the purchases list, so the
 * dropdown does not depend on the selected date range. Read-only.
 */

header('Content-Type: application/json');

require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if (!hasPermission('purchases', 'view')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

try {
    // Same location scoping as api/purchases.php
    $locationIds = array_column(getUserLocations(), 'id');
    if (empty($locationIds)) {
        echo json_encode(['success' => true, 'products' => []]);
        exit;
    }
    $placeholders = str_repeat('?,', count($locationIds) - 1) . '?';

    // Inactive products are included on purpose: old purchases stay findable.
    $stmt = $conn->prepare("
        SELECT pr.id, pr.name
        FROM products pr
        WHERE EXISTS (
            SELECT 1
            FROM purchase_details pd
            JOIN purchases p ON p.id = pd.purchase_id
            WHERE pd.product_id = pr.id
              AND p.location_id IN ($placeholders)
        )
        ORDER BY pr.name
    ");
    $stmt->execute($locationIds);

    echo json_encode([
        'success'  => true,
        'products' => $stmt->fetchAll(PDO::FETCH_ASSOC),
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
