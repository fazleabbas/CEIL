<?php
/**
 * Customers GET API
 * Retrieve all customers with ledger account and transaction data
 * Balance is calculated on-the-fly from the GENERAL LEDGER:
 *   Opening Balance + SUM(DR) - SUM(CR) over transaction_details
 *
 * The GL is the single source of truth so that journal vouchers (and any other
 * voucher type posted against the customer's ledger) are reflected here exactly
 * as they are in modules/reports/ledger_report.php. Summing sales/receipts
 * directly missed manual journals entirely and ignored the exchange rate.
 * `opening_balance` lives only on ledger_accounts (it is never posted to the
 * GL by customers-sync.php), so adding it to the GL delta cannot double count.
 */

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Mon, 01 Jan 2000 00:00:00 GMT');

require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is logged in
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Check permissions - allow if user can view customers OR create sales
if (!hasPermission('customers', 'view') && !hasPermission('sales', 'create')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

try {
    $stmt = $conn->prepare("
        SELECT c.*,
               la.name,
               la.opening_balance,
               -- Calculate current balance dynamically on-the-fly from the GL.
               -- Receivable convention (unchanged): positive = customer owes us.
               (la.opening_balance + COALESCE(gl.delta, 0)) as current_balance,
               (SELECT COUNT(*) FROM sales WHERE customer_id = c.id) as sale_count,
               (SELECT COUNT(*) FROM receipts WHERE from_account_id = la.id) as receipt_count
        FROM customers c
        INNER JOIN ledger_accounts la ON c.ledger_account_id = la.id
        LEFT JOIN (
            SELECT td.ledger_id,
                   SUM(CASE WHEN td.dr_cr = 'DR' THEN td.amount_base ELSE -td.amount_base END) AS delta
            FROM transaction_details td
            INNER JOIN transactions t ON t.id = td.transaction_id
            WHERE t.status = 'active'
            GROUP BY td.ledger_id
        ) gl ON gl.ledger_id = la.id
        WHERE c.is_active = 1
        ORDER BY c.id ASC
    ");
    $stmt->execute();
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'customers' => $customers,
        'timestamp' => microtime(true),
        'server_time' => date('Y-m-d H:i:s'),
        'note' => 'Balance calculated from the general ledger as: Opening Balance + Debits - Credits'
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
