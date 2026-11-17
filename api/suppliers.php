<?php
/**
 * Suppliers GET API
 * Returns all suppliers with ledger account and transaction data - ALWAYS FRESH, NEVER CACHED
 * Balance is calculated on-the-fly from the GENERAL LEDGER:
 *   Opening Balance + SUM(CR) - SUM(DR) over transaction_details
 *
 * The GL is the single source of truth so that journal vouchers AND purchase
 * imports (which post to the supplier ledger but have no row in `purchases`)
 * are reflected here exactly as they are in modules/reports/ledger_report.php.
 * `opening_balance` lives only on ledger_accounts (it is never posted to the GL
 * by suppliers-sync.php), so adding it to the GL delta cannot double count.
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

// Check permissions - allow if user can view suppliers OR create purchases
if (!hasPermission('suppliers', 'view') && !hasPermission('purchases', 'create')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

try {
    $stmt = $conn->prepare("
        SELECT 
            s.id,
            s.ledger_account_id,
            la.name,
            s.contact_person,
            s.phone,
            s.email,
            s.address,
            s.gstin,
            s.pan,
            la.opening_balance,
            -- Calculate current balance dynamically on-the-fly from the GL.
            -- Payable convention (unchanged): positive = we owe the supplier.
            (la.opening_balance + COALESCE(gl.delta, 0)) as current_balance,
            s.is_active,
            (SELECT COUNT(*) FROM purchases WHERE supplier_id = s.id) as purchase_count,
            (SELECT COUNT(*) FROM payments WHERE to_account_id = la.id) as payment_count
        FROM suppliers s
        INNER JOIN ledger_accounts la ON s.ledger_account_id = la.id
        LEFT JOIN (
            SELECT td.ledger_id,
                   SUM(CASE WHEN td.dr_cr = 'CR' THEN td.amount_base ELSE -td.amount_base END) AS delta
            FROM transaction_details td
            INNER JOIN transactions t ON t.id = td.transaction_id
            WHERE t.status = 'active'
            GROUP BY td.ledger_id
        ) gl ON gl.ledger_id = la.id
        WHERE s.is_active = 1
        ORDER BY la.name ASC
    ");
    $stmt->execute();
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'suppliers' => $suppliers,
        'timestamp' => microtime(true),
        'server_time' => date('Y-m-d H:i:s'),
        'note' => 'Balance calculated from the general ledger as: Opening Balance + Credits - Debits'
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
