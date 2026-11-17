<?php
/**
 * Supplier Balance Breakup API
 * Returns opening balance, total credits, total debits and transaction history,
 * all read from the general ledger so journal vouchers and purchase imports
 * are included.
 *
 * `total_purchases` / `total_payments` keep their JSON key names for backwards
 * compatibility but now carry total CREDITS / total DEBITS respectively.
 */

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

if (!hasPermission('suppliers', 'view')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No permission']);
    exit;
}

$supplierId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$supplierId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Supplier ID required']);
    exit;
}

try {
    // Get supplier info with ledger account
    $stmt = $conn->prepare("
        SELECT 
            s.id,
            la.id as ledger_account_id,
            la.name,
            la.opening_balance
        FROM suppliers s
        INNER JOIN ledger_accounts la ON s.ledger_account_id = la.id
        WHERE s.id = ?
    ");
    $stmt->execute([$supplierId]);
    $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$supplier) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Supplier not found']);
        exit;
    }
    
    // Every movement on this supplier is read from the general ledger, so
    // journal vouchers and purchase imports show up alongside purchases and
    // payments. amount_base is already in base currency, so no exchange-rate
    // maths is needed here.
    $ledgerId = intval($supplier['ledger_account_id']);

    // Totals: credits raise the payable, debits reduce it
    $stmt = $conn->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN td.dr_cr = 'CR' THEN td.amount_base ELSE 0 END), 0) AS cr_total,
            COALESCE(SUM(CASE WHEN td.dr_cr = 'DR' THEN td.amount_base ELSE 0 END), 0) AS dr_total
        FROM transaction_details td
        INNER JOIN transactions t ON t.id = td.transaction_id
        WHERE td.ledger_id = ? AND t.status = 'active'
    ");
    $stmt->execute([$ledgerId]);
    $totals = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['cr_total' => 0, 'dr_total' => 0];
    $totalPurchases = floatval($totals['cr_total']);
    $totalPayments = floatval($totals['dr_total']);

    // Calculate current balance
    $openingBalance = floatval($supplier['opening_balance']);
    $currentBalance = $openingBalance + $totalPurchases - $totalPayments;

    // Get transaction history straight from the GL
    $stmt = $conn->prepare("
        SELECT
            t.voucher_type   AS type,
            t.date           AS transaction_date,
            t.voucher_number AS reference,
            td.amount_base   AS amount,
            td.dr_cr,
            t.narration      AS remarks
        FROM transaction_details td
        INNER JOIN transactions t ON t.id = td.transaction_id
        WHERE td.ledger_id = ? AND t.status = 'active'
        ORDER BY t.date ASC, t.id ASC, td.id ASC
    ");
    $stmt->execute([$ledgerId]);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Add running balance to transactions
    $runningBalance = $openingBalance;
    foreach ($transactions as &$txn) {
        // Payable convention: a credit raises the balance, a debit lowers it.
        // `entry_type` reports the true accounting side (dr_cr); the running
        // balance direction is driven by the payable convention above, so the
        // figures are unchanged by that labelling.
        $txn['entry_type'] = ($txn['dr_cr'] === 'DR') ? 'debit' : 'credit';
        if ($txn['dr_cr'] === 'CR') {
            $runningBalance += floatval($txn['amount']);
        } else {
            $runningBalance -= floatval($txn['amount']);
        }
        $txn['running_balance'] = $runningBalance;
        $txn['amount'] = floatval($txn['amount']);
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'supplier_id' => $supplierId,
            'supplier_name' => $supplier['name'],
            'opening_balance' => $openingBalance,
            'total_purchases' => $totalPurchases,
            'total_payments' => $totalPayments,
            'current_balance' => $currentBalance,
            'transactions' => $transactions
        ]
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
