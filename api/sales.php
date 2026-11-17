<?php
/**
 * Sales API - GET Endpoint
 * Fetches sales for offline caching
 */

require_once '../config/database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

// Check authentication
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

// Check permission
if (!hasPermission('sales', 'view')) {
    http_response_code(403);
    echo json_encode(['error' => 'No permission to view sales']);
    exit;
}

try {
    // Get user's accessible locations
    $userLocations = getUserLocations();
    $locationIds = array_column($userLocations, 'id');
    
    if (empty($locationIds)) {
        echo json_encode([]);
        exit;
    }
    
    // Fetch sales from last 90 days for user's locations
    $dateLimit = date('Y-m-d', strtotime('-90 days'));
    
    $placeholders = str_repeat('?,', count($locationIds) - 1) . '?';
    
    $stmt = $conn->prepare("
        SELECT
            s.id,
            s.sale_number,
            s.sale_date,
            s.customer_id,
            s.print_name,
            la.name as customer_name,
            s.location_id,
            l.name as location_name,
            s.total_amount,
            s.payment_mode,
            s.to_account_id,
            s.remarks,
            s.cost_centre_id,
            cc.name as cost_centre_name,
            cc.code as cost_centre_code,
            s.currency_id,
            s.exchange_rate_to_base,
            -- currency_id alone told the browser nothing, so the sales list
            -- rendered every row with the tenant DEFAULT symbol. Ship the
            -- symbol/code with the row. ADDITIVE: existing keys are untouched,
            -- so the offline cache (assets/js/sync-manager.js) keeps working and
            -- rows cached before this change simply lack these three keys.
            cur.code as currency_code,
            cur.symbol as currency_symbol,
            cur.decimal_places as currency_decimals,
            s.created_by,
            u.name as created_by_name,
            s.created_at,
            s.updated_at
        FROM sales s
        LEFT JOIN customers c ON s.customer_id = c.id
        LEFT JOIN ledger_accounts la ON c.ledger_account_id = la.id
        LEFT JOIN locations l ON s.location_id = l.id
        LEFT JOIN cost_centres cc ON s.cost_centre_id = cc.id
        LEFT JOIN currencies cur ON s.currency_id = cur.id
        LEFT JOIN users u ON s.created_by = u.id
        WHERE s.location_id IN ($placeholders)
        AND s.sale_date >= ?
        ORDER BY s.sale_date DESC, s.id DESC
    ");
    
    $params = array_merge($locationIds, [$dateLimit]);
    $stmt->execute($params);
    $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch details for each sale
    foreach ($sales as &$sale) {
        $detailsStmt = $conn->prepare("
            SELECT 
                sd.id,
                sd.sale_id,
                sd.product_id,
                p.name as product_name,
                pc.name as category_name,
                p.unit,
                sd.quantity,
                sd.rate_per_unit,
                sd.amount,
                sd.cost_per_unit
            FROM sale_details sd
            JOIN products p ON sd.product_id = p.id
            LEFT JOIN product_categories pc ON p.category_id = pc.id
            WHERE sd.sale_id = ?
            ORDER BY sd.id
        ");
        $detailsStmt->execute([$sale['id']]);
        $sale['details'] = $detailsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Add synced flag (always true for server data)
        $sale['synced'] = true;
    }
    
    echo json_encode($sales);
    
} catch (PDOException $e) {
    error_log("Sales API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
