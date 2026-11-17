<?php
/**
 * Exchange Rates GET API
 * Returns exchange rates with currency details - ALWAYS FRESH, NEVER CACHED
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

// Check permissions
if (!hasPermission('exchange_rates', 'view')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

try {
    $where = [];
    $params = [];

    if (!empty($_GET['from_currency_id'])) {
        $where[] = "er.from_currency_id = ?";
        $params[] = (int)$_GET['from_currency_id'];
    }

    if (!empty($_GET['to_currency_id'])) {
        $where[] = "er.to_currency_id = ?";
        $params[] = (int)$_GET['to_currency_id'];
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmt = $conn->prepare("
        SELECT
            er.*,
            fc.code as from_currency_code,
            fc.name as from_currency_name,
            fc.symbol as from_currency_symbol,
            tc.code as to_currency_code,
            tc.name as to_currency_name,
            tc.symbol as to_currency_symbol,
            u.name as created_by_name
        FROM exchange_rates er
        JOIN currencies fc ON er.from_currency_id = fc.id
        JOIN currencies tc ON er.to_currency_id = tc.id
        LEFT JOIN users u ON er.created_by = u.id
        $whereClause
        ORDER BY er.effective_date DESC, fc.code ASC, tc.code ASC
    ");
    $stmt->execute($params);
    $rates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'exchange_rates' => $rates,
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
