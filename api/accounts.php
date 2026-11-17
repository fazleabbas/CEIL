<?php
/**
 * Accounts GET API
 * Returns cash/bank accounts for receipts - ALWAYS FRESH, NEVER CACHED
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
    // Get cash and bank accounts
    $stmt = $conn->prepare("
        SELECT
            id,
            name,
            account_type,
            current_balance,
            is_active
        FROM ledger_accounts
        WHERE account_type IN ('cash', 'bank') AND is_active = 1
        ORDER BY account_type, name ASC
    ");
    $stmt->execute();
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'accounts' => $accounts,
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
