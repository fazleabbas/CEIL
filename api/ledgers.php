<?php
/**
 * Ledgers GET API
 * Returns all ledger accounts whose account_type is one of:
 *   cash, bank, expense, purchase, sales, duties
 * (Supplier and customer ledgers are managed via their own master pages.)
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if (!hasPermission('ledgers', 'view')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

try {
    $stmt = $conn->prepare("
        SELECT
            la.id,
            la.name,
            la.account_type,
            la.opening_balance,
            la.current_balance,
            la.is_active,
            la.currency_id,
            c.code AS currency_code,
            c.symbol AS currency_symbol,
            (SELECT COUNT(*) FROM transaction_details td WHERE td.ledger_id = la.id) AS transaction_count
        FROM ledger_accounts la
        LEFT JOIN currencies c ON la.currency_id = c.id
        WHERE la.account_type IN ('cash','bank','expense','purchase','sales','duties')
        ORDER BY la.account_type, la.name
    ");
    $stmt->execute();
    $ledgers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'ledgers' => $ledgers,
        'timestamp' => microtime(true)
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Error $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
