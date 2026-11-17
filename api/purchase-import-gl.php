<?php
/**
 * Purchase Import GL Effects — read-only.
 *
 * Returns the accounting trail for a single purchase import:
 *   - The main 'purchase_import' transaction (DR Purchase / DR VAT /
 *     DR Bucket A ledgers / CR Supplier).
 *   - Every child 'journal' voucher whose source_voucher_id points at this
 *     import (one per Bucket B cost line).
 *
 * Used by the import's view modal "GL Effects" section. Lazy-fetched so the
 * main list query stays lean.
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

// Reuse the import view permission — anyone allowed to see the import is
// allowed to see its GL trail.
if (!hasPermission('purchase_imports', 'view')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

$importId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($importId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing or invalid id']);
    exit;
}

try {
    // Confirm the import exists + the user's location access matches.
    $loc = $conn->prepare("SELECT location_id FROM purchase_imports WHERE id = ?");
    $loc->execute([$importId]);
    $row = $loc->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Import not found']);
        exit;
    }
    if (!canAccessLocation((int)$row['location_id'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No access to this import location']);
        exit;
    }

    // 1) Main 'purchase_import' transaction (zero or one row).
    $mainStmt = $conn->prepare("
        SELECT t.id, t.voucher_number, t.date, t.narration,
               t.voucher_type, t.cost_centre_id,
               cc.name AS cost_centre_name
        FROM transactions t
        LEFT JOIN cost_centres cc ON t.cost_centre_id = cc.id
        WHERE t.voucher_type = 'purchase_import' AND t.voucher_id = ?
        LIMIT 1
    ");
    $mainStmt->execute([$importId]);
    $main = $mainStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    // 2) Child journal vouchers (one per Bucket B cost line).
    $jvStmt = $conn->prepare("
        SELECT t.id, t.voucher_number, t.date, t.narration,
               t.voucher_type, t.voucher_id AS cost_line_id,
               t.cost_centre_id, cc.name AS cost_centre_name
        FROM transactions t
        LEFT JOIN cost_centres cc ON t.cost_centre_id = cc.id
        WHERE t.voucher_type = 'journal'
          AND t.source_voucher_type = 'purchase_import'
          AND t.source_voucher_id = ?
          AND t.status = 'active'
        ORDER BY t.id
    ");
    $jvStmt->execute([$importId]);
    $jvs = $jvStmt->fetchAll(PDO::FETCH_ASSOC);

    // 3) Pull every detail row for the matched transactions in one go.
    $txIds = array_filter(array_merge(
        $main ? [(int)$main['id']] : [],
        array_map(fn($jv) => (int)$jv['id'], $jvs)
    ));

    $detailsByTx = [];
    if (!empty($txIds)) {
        $placeholders = str_repeat('?,', count($txIds) - 1) . '?';
        $detailsStmt = $conn->prepare("
            SELECT td.transaction_id, td.dr_cr,
                   td.ledger_id, la.name AS ledger_name,
                   td.currency_id, cur.code AS currency_code,
                   td.rate_of_exchange, td.amount, td.amount_base
            FROM transaction_details td
            JOIN ledger_accounts la ON td.ledger_id = la.id
            LEFT JOIN currencies cur ON td.currency_id = cur.id
            WHERE td.transaction_id IN ($placeholders)
            ORDER BY td.transaction_id, td.id
        ");
        $detailsStmt->execute($txIds);
        foreach ($detailsStmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $detailsByTx[$d['transaction_id']][] = $d;
        }
    }

    // Attach details + totals so the front-end doesn't need to recompute.
    $attach = function (&$tx) use (&$detailsByTx) {
        $details = $detailsByTx[$tx['id']] ?? [];
        $tx['details'] = $details;
        $dr = 0; $cr = 0;
        foreach ($details as $d) {
            if ($d['dr_cr'] === 'DR') $dr += (float)$d['amount_base'];
            else                      $cr += (float)$d['amount_base'];
        }
        $tx['total_dr_base'] = $dr;
        $tx['total_cr_base'] = $cr;
    };

    if ($main) $attach($main);
    foreach ($jvs as &$jv) $attach($jv);
    unset($jv);

    echo json_encode([
        'success' => true,
        'import_id' => $importId,
        'main'      => $main,
        'journal_vouchers' => $jvs,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ]);
}
