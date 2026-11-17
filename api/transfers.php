<?php
/**
 * Stock Transfers API - Read only
 * Returns transfers for offline caching
 */
require_once '../config/database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not authorized']);
    exit;
}

if (!hasPermission('stock_transfer', 'view')) {
    echo json_encode(['success' => false, 'error' => 'No permission']);
    exit;
}

try {
    // Get transfers accessible to this user
    $userLocations = getUserLocations();
    $locationIds = array_column($userLocations, 'id');

    if (empty($locationIds)) {
        echo json_encode(['success' => true, 'transfers' => []]);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($locationIds), '?'));

    $stmt = $conn->prepare("
        SELECT
            t.id,
            t.transfer_number,
            t.transfer_date,
            t.from_location_id,
            fl.name as from_location_name,
            t.to_location_id,
            tl.name as to_location_name,
            t.vehicle_number,
            t.driver_name,
            t.driver_phone,
            t.remarks,
            t.status,
            t.total_quantity,
            t.total_amount,
            t.currency_id,
            t.exchange_rate_to_base,
            t.dispatched_at,
            t.received_at,
            t.created_at,
            t.created_by,
            u.full_name as created_by_name
        FROM stock_transfers t
        LEFT JOIN locations fl ON t.from_location_id = fl.id
        LEFT JOIN locations tl ON t.to_location_id = tl.id
        LEFT JOIN users u ON t.created_by = u.id
        WHERE t.from_location_id IN ($placeholders)
           OR t.to_location_id IN ($placeholders)
        ORDER BY t.created_at DESC
        LIMIT 500
    ");

    // Duplicate location IDs for both conditions
    $params = array_merge($locationIds, $locationIds);
    $stmt->execute($params);
    $transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch details for each transfer
    $detailStmt = $conn->prepare("
        SELECT
            td.id,
            td.transfer_id,
            td.product_id,
            p.name as product_name,
            p.unit,
            td.quantity,
            td.rate_per_unit,
            td.amount,
            td.dispatched_quantity,
            td.received_quantity,
            td.shortage_quantity,
            td.shortage_amount
        FROM stock_transfer_details td
        INNER JOIN products p ON td.product_id = p.id
        WHERE td.transfer_id = ?
        ORDER BY td.id
    ");

    foreach ($transfers as &$transfer) {
        $detailStmt->execute([$transfer['id']]);
        $transfer['details'] = $detailStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode([
        'success' => true,
        'transfers' => $transfers
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
