<?php
/**
 * Requisition Vouchers GET API
 * Retrieve all requisition vouchers with embedded details and linked transfers for offline sync
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
if (!hasPermission('requisition_vouchers', 'view')) {
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
            'requisition_vouchers' => [],
            'timestamp' => date('c')
        ]);
        exit;
    }

    // Fetch last 90 days of requisition vouchers for user's locations (by destination)
    $placeholders = str_repeat('?,', count($locationIds) - 1) . '?';
    $dateLimit = date('Y-m-d', strtotime('-90 days'));

    $stmt = $conn->prepare("
        SELECT
            rv.*,
            l.name as destination_location_name,
            cc.name as cost_centre_name,
            cc.code as cost_centre_code,
            u.name as created_by_name,
            ua.name as approved_by_name
        FROM requisition_vouchers rv
        LEFT JOIN locations l ON rv.destination_location_id = l.id
        LEFT JOIN cost_centres cc ON rv.cost_centre_id = cc.id
        LEFT JOIN users u ON rv.created_by = u.id
        LEFT JOIN users ua ON rv.approved_by = ua.id
        WHERE rv.destination_location_id IN ($placeholders)
          AND rv.voucher_date >= ?
        ORDER BY rv.voucher_date DESC, rv.id DESC
    ");

    $params = array_merge($locationIds, [$dateLimit]);
    $stmt->execute($params);
    $vouchers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch details and linked transfers for each voucher and embed them
    foreach ($vouchers as &$voucher) {
        // Fetch requisition details
        $detailsStmt = $conn->prepare("
            SELECT
                rvd.*,
                p.name as product_name,
                p.unit,
                pc.name as category_name
            FROM requisition_voucher_details rvd
            JOIN products p ON rvd.product_id = p.id
            LEFT JOIN product_categories pc ON p.category_id = pc.id
            WHERE rvd.requisition_id = ?
            ORDER BY rvd.id
        ");
        $detailsStmt->execute([$voucher['id']]);
        $voucher['details'] = $detailsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch linked stock transfers
        $transfersStmt = $conn->prepare("
            SELECT
                st.id,
                st.transfer_number,
                st.transfer_date,
                st.status,
                fl.name as from_location_name,
                tl.name as to_location_name
            FROM stock_transfers st
            LEFT JOIN locations fl ON st.from_location_id = fl.id
            LEFT JOIN locations tl ON st.to_location_id = tl.id
            WHERE st.requisition_id = ?
            ORDER BY st.transfer_date DESC, st.id DESC
        ");
        $transfersStmt->execute([$voucher['id']]);
        $voucher['transfers'] = $transfersStmt->fetchAll(PDO::FETCH_ASSOC);

        $voucher['synced'] = true;
    }

    echo json_encode([
        'success' => true,
        'requisition_vouchers' => $vouchers,
        'timestamp' => date('c')
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
