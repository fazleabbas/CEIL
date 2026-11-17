<?php
/**
 * Journal Vouchers GET API
 * Returns all transactions with voucher_type IN ('journal','manual_journal')
 * (joined with their DR/CR details and a friendly source-voucher number for
 * child JVs).
 *
 * Auto JVs (voucher_type='journal') are read-only — created/updated/deleted
 * via their parent voucher (e.g. saving a purchase import rebuilds all Bucket
 * B journal vouchers). Manual JVs (voucher_type='manual_journal') are managed
 * directly via api/journal-vouchers-sync.php.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Mon, 01 Jan 2000 00:00:00 GMT');

require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if (!hasPermission('journal_vouchers', 'view')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

try {
    // Default window: last 90 days unless caller specifies date_from/date_to.
    $dateFrom = isset($_GET['date_from']) && $_GET['date_from'] !== ''
        ? $_GET['date_from'] : date('Y-m-d', strtotime('-90 days'));
    $dateTo = isset($_GET['date_to']) && $_GET['date_to'] !== ''
        ? $_GET['date_to'] : date('Y-m-d');

    // Pull the journal voucher headers. We left-join the source voucher's
    // number from a UNION over the source-type tables, so a single query
    // gives us a clickable "Source: IMP000007"-style label per row. Today
    // only purchase_import is a source type; future source types can be
    // added to the CASE as needed.
    $stmt = $conn->prepare("
        SELECT
            t.id,
            t.voucher_number,
            t.date,
            t.narration,
            t.voucher_type,
            t.source_voucher_type,
            t.source_voucher_id,
            CASE
                WHEN t.source_voucher_type = 'purchase_import'
                THEN (SELECT pi.import_number FROM purchase_imports pi WHERE pi.id = t.source_voucher_id)
                ELSE NULL
            END AS source_voucher_number,
            t.cost_centre_id,
            cc.code AS cost_centre_code,
            cc.name AS cost_centre_name,
            u.name  AS created_by_name
        FROM transactions t
        LEFT JOIN cost_centres cc ON t.cost_centre_id = cc.id
        LEFT JOIN users u ON t.created_by = u.id
        WHERE t.voucher_type IN ('journal', 'manual_journal')
          AND t.status = 'active'
          AND t.date BETWEEN ? AND ?
        ORDER BY t.date DESC, t.id DESC
    ");
    $stmt->execute([$dateFrom, $dateTo]);
    $vouchers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($vouchers)) {
        echo json_encode([
            'success' => true,
            'journal_vouchers' => [],
            'date_from' => $dateFrom,
            'date_to'   => $dateTo,
        ]);
        exit;
    }

    // Fetch all details for the matched JVs in one query, then group in PHP.
    // Avoids the N+1 problem when the result set is large.
    $ids = array_column($vouchers, 'id');
    $placeholders = str_repeat('?,', count($ids) - 1) . '?';
    $detailsStmt = $conn->prepare("
        SELECT td.transaction_id, td.dr_cr, td.ledger_id, la.name AS ledger_name,
               td.currency_id, cur.code AS currency_code,
               td.rate_of_exchange, td.amount, td.amount_base
        FROM transaction_details td
        JOIN ledger_accounts la ON td.ledger_id = la.id
        LEFT JOIN currencies cur ON td.currency_id = cur.id
        WHERE td.transaction_id IN ($placeholders)
        ORDER BY td.transaction_id, td.id
    ");
    $detailsStmt->execute($ids);
    $detailsByTx = [];
    foreach ($detailsStmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $detailsByTx[$d['transaction_id']][] = $d;
    }

    foreach ($vouchers as &$v) {
        $details = $detailsByTx[$v['id']] ?? [];
        $v['details'] = $details;

        // Summary helpers for the list table (single amount column without
        // having to render the full DR/CR breakdown).
        $totalDr = 0;
        $totalCr = 0;
        $currencyCode = null;
        foreach ($details as $d) {
            if ($d['dr_cr'] === 'DR') $totalDr += (float)$d['amount_base'];
            else                      $totalCr += (float)$d['amount_base'];
            // For a JV all details share the same currency, so we can pick
            // any DR/CR line's currency for the list display.
            if ($currencyCode === null) $currencyCode = $d['currency_code'];
        }
        $v['total_dr_base']  = $totalDr;
        $v['total_cr_base']  = $totalCr;
        $v['currency_code']  = $currencyCode;
    }
    unset($v);

    echo json_encode([
        'success' => true,
        'journal_vouchers' => $vouchers,
        'date_from' => $dateFrom,
        'date_to'   => $dateTo,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ]);
}
