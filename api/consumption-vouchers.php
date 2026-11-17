<?php
/**
 * Consumption Vouchers GET API
 * Retrieve all consumption vouchers with embedded details for offline sync
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
if (!hasPermission('consumption_vouchers', 'view')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

try {
    // Get user's accessible locations
    $userLocations = getUserLocations();
    $locationIds = array_column($userLocations, 'id');

    if (empty($locationIds)) {
        echo json_encode([
            'success' => true,
            'consumption_vouchers' => [],
            'timestamp' => date('c')
        ]);
        exit;
    }

    // Fetch last 90 days of consumption vouchers for user's locations
    $placeholders = str_repeat('?,', count($locationIds) - 1) . '?';
    $dateLimit = date('Y-m-d', strtotime('-90 days'));

    $stmt = $conn->prepare("
        SELECT
            cv.*,
            l.name as location_name,
            cc.name as cost_centre_name,
            cc.code as cost_centre_code,
            u.name as created_by_name
        FROM consumption_vouchers cv
        LEFT JOIN locations l ON cv.location_id = l.id
        LEFT JOIN cost_centres cc ON cv.cost_centre_id = cc.id
        LEFT JOIN users u ON cv.created_by = u.id
        WHERE cv.location_id IN ($placeholders)
          AND cv.voucher_date >= ?
        ORDER BY cv.voucher_date DESC, cv.id DESC
    ");

    $params = array_merge($locationIds, [$dateLimit]);
    $stmt->execute($params);
    $vouchers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch details for each voucher and embed them
    foreach ($vouchers as &$voucher) {
        $detailsStmt = $conn->prepare("
            SELECT
                cvd.*,
                p.name as product_name,
                p.unit,
                pc.name as category_name
            FROM consumption_voucher_details cvd
            JOIN products p ON cvd.product_id = p.id
            LEFT JOIN product_categories pc ON p.category_id = pc.id
            WHERE cvd.voucher_id = ?
            ORDER BY cvd.id
        ");
        $detailsStmt->execute([$voucher['id']]);
        $voucher['details'] = $detailsStmt->fetchAll(PDO::FETCH_ASSOC);
        $voucher['synced'] = true;
    }

    echo json_encode([
        'success' => true,
        'consumption_vouchers' => $vouchers,
        'timestamp' => date('c')
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
