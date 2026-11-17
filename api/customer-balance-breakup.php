<?php
/**
 * Customer Balance Breakup API
 * Returns opening balance, total debits, total credits and transaction history,
 * all read from the general ledger so journal vouchers are included.
 *
 * `total_sales` / `total_receipts` keep their JSON key names for backwards
 * compatibility but now carry total DEBITS / total CREDITS respectively.
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

if (!hasPermission('customers', 'view')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No permission']);
    exit;
}

$customerId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$customerId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Customer ID required']);
    exit;
}

try {
    // Get customer info with ledger account
    $stmt = $conn->prepare("
        SELECT 
            c.id,
            la.id as ledger_account_id,
            la.name,
            la.opening_balance
        FROM customers c
        INNER JOIN ledger_accounts la ON c.ledger_account_id = la.id
        WHERE c.id = ?
    ");
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$customer) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Customer not found']);
        exit;
    }
    
    // Every movement on this customer is read from the general ledger, so
    // journal vouchers show up alongside sales and receipts. amount_base is
    // already in base currency, so no exchange-rate maths is needed here.
    $ledgerId = intval($customer['ledger_account_id']);

    // Totals: debits raise the receivable, credits reduce it
    $stmt = $conn->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN td.dr_cr = 'DR' THEN td.amount_base ELSE 0 END), 0) AS dr_total,
            COALESCE(SUM(CASE WHEN td.dr_cr = 'CR' THEN td.amount_base ELSE 0 END), 0) AS cr_total
        FROM transaction_details td
        INNER JOIN transactions t ON t.id = td.transaction_id
        WHERE td.ledger_id = ? AND t.status = 'active'
    ");
    $stmt->execute([$ledgerId]);
    $totals = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['dr_total' => 0, 'cr_total' => 0];
    $totalSales = floatval($totals['dr_total']);
    $totalReceipts = floatval($totals['cr_total']);

    // Calculate current balance
    $openingBalance = floatval($customer['opening_balance']);
    $currentBalance = $openingBalance + $totalSales - $totalReceipts;

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
        // Receivable convention: a debit raises the balance, a credit lowers it
        $txn['entry_type'] = ($txn['dr_cr'] === 'DR') ? 'debit' : 'credit';
        if ($txn['entry_type'] === 'debit') {
            $runningBalance += floatval($txn['amount']);
        } else {
            $runningBalance -= floatval($txn['amount']);
        }
        $txn['running_balance'] = $runningBalance;
        $txn['amount'] = floatval($txn['amount']);
    }
    unset($txn);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'customer_id' => $customerId,
            'customer_name' => $customer['name'],
            'opening_balance' => $openingBalance,
            'total_sales' => $totalSales,
            'total_receipts' => $totalReceipts,
            'current_balance' => $currentBalance,
            'transactions' => $transactions
        ]
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
