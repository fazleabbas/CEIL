<?php
/**
 * Purchases GET API
 * Retrieve all purchases with embedded details for offline sync
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
if (!hasPermission('purchases', 'view')) {
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
            'purchases' => [],
            'timestamp' => date('c')
        ]);
        exit;
    }
    
    // Optional date-range filter (passed by the purchases page; absent = no cap)
    $dateFrom = isset($_GET['date_from']) && $_GET['date_from'] !== '' ? $_GET['date_from'] : null;
    $dateTo   = isset($_GET['date_to'])   && $_GET['date_to']   !== '' ? $_GET['date_to']   : null;

    $placeholders = str_repeat('?,', count($locationIds) - 1) . '?';

    $sql = "
        SELECT
            p.*,
            la.name as supplier_name,
            l.name as location_name,
            cc.name as cost_centre_name,
            cc.code as cost_centre_code,
            -- p.* carries currency_id + exchange_rate_to_base but nothing the
            -- browser can render, so the purchases list showed every row with
            -- the tenant DEFAULT symbol. ADDITIVE: existing keys are untouched,
            -- so the offline cache (assets/js/sync-manager.js) keeps working and
            -- rows cached before this change simply lack these three keys.
            cur.code as currency_code,
            cur.symbol as currency_symbol,
            cur.decimal_places as currency_decimals,
            u.name as created_by_name
        FROM purchases p
        LEFT JOIN suppliers s ON p.supplier_id = s.id
        LEFT JOIN ledger_accounts la ON s.ledger_account_id = la.id
        LEFT JOIN locations l ON p.location_id = l.id
        LEFT JOIN cost_centres cc ON p.cost_centre_id = cc.id
        LEFT JOIN currencies cur ON p.currency_id = cur.id
        LEFT JOIN users u ON p.created_by = u.id
        WHERE p.location_id IN ($placeholders)
    ";

    $params = $locationIds;

    if ($dateFrom !== null) {
        $sql .= " AND p.purchase_date >= ?";
        $params[] = $dateFrom;
    }
    if ($dateTo !== null) {
        $sql .= " AND p.purchase_date <= ?";
        $params[] = $dateTo;
    }

    $sql .= " ORDER BY p.purchase_date DESC, p.id DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $purchases = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch details for each purchase and embed them
    foreach ($purchases as &$purchase) {
        $detailsStmt = $conn->prepare("
            SELECT 
                pd.*,
                p.name as product_name,
                p.unit,
                pc.name as category_name
            FROM purchase_details pd
            JOIN products p ON pd.product_id = p.id
            LEFT JOIN product_categories pc ON p.category_id = pc.id
            WHERE pd.purchase_id = ?
            ORDER BY pd.id
        ");
        $detailsStmt->execute([$purchase['id']]);
        $purchase['details'] = $detailsStmt->fetchAll(PDO::FETCH_ASSOC);
        $purchase['synced'] = true; // Mark as synced from server
    }
    
    echo json_encode([
        'success' => true,
        'purchases' => $purchases,
        'timestamp' => date('c')
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
