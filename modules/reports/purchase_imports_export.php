<?php
/**
 * Purchase Imports — Excel (CSV) Export
 * Streams the purchase imports LIST (one row per import) as a UTF-8 CSV that
 * Excel opens natively. Mirrors the stock_report_export.php pattern.
 *
 * Respects the same scope + filters as the list page / api/purchase-imports.php:
 *   - user's accessible locations
 *   - dateFrom / dateTo (YYYY-MM-DD, either optional)
 *   - search (import #, PO #, supplier, location)
 */
require_once '../../config/database.php';
require_once '../../includes/functions.php';

if (!isLoggedIn()) {
    http_response_code(401);
    exit('Unauthorized');
}

if (!hasPermission('purchase_imports', 'view')) {
    http_response_code(403);
    exit('Forbidden');
}

// Scope to the user's accessible locations (same as the GET API).
$userLocations = getUserLocations();
$locationIds = array_column($userLocations, 'id');

$dateFrom = isset($_GET['dateFrom']) ? trim($_GET['dateFrom']) : '';
$dateTo   = isset($_GET['dateTo'])   ? trim($_GET['dateTo'])   : '';
$search   = isset($_GET['search'])   ? trim($_GET['search'])   : '';
$validDate = fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;

$rows = [];
if (!empty($locationIds)) {
    $placeholders = str_repeat('?,', count($locationIds) - 1) . '?';

    $dateConditions = '';
    $params = $locationIds;
    if ($validDate($dateFrom)) {
        $dateConditions .= ' AND pi.import_date >= ?';
        $params[] = $dateFrom;
    }
    if ($validDate($dateTo)) {
        $dateConditions .= ' AND pi.import_date <= ?';
        $params[] = $dateTo;
    }

    // Search across the same fields the page's search box matches.
    $searchCondition = '';
    if ($search !== '') {
        $searchCondition = ' AND (pi.import_number LIKE ? OR pi.po_number LIKE ?
                                  OR la.name LIKE ? OR l.name LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
    }

    $stmt = $conn->prepare("
        SELECT
            pi.import_number,
            pi.po_number,
            pi.import_date,
            pi.total_fob_amount,
            pi.total_landed_amount,
            pi.costing_factor,
            la.name as supplier_name,
            l.name as location_name,
            cur.code as currency_code,
            cur.symbol as currency_symbol,
            (SELECT COUNT(*) FROM purchase_import_details pid
                WHERE pid.import_id = pi.id) as item_count
        FROM purchase_imports pi
        LEFT JOIN suppliers s ON pi.supplier_id = s.id
        LEFT JOIN ledger_accounts la ON s.ledger_account_id = la.id
        LEFT JOIN locations l ON pi.location_id = l.id
        LEFT JOIN currencies cur ON pi.currency_id = cur.id
        WHERE pi.location_id IN ($placeholders)
          $dateConditions
          $searchCondition
        ORDER BY pi.import_date DESC, pi.id DESC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$fnFrom = $validDate($dateFrom) ? str_replace('-', '', $dateFrom) : 'all';
$fnTo   = $validDate($dateTo)   ? str_replace('-', '', $dateTo)   : 'all';
$filename = 'purchase_imports_' . $fnFrom . '_to_' . $fnTo . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');

$out = fopen('php://output', 'w');

// UTF-8 BOM so Excel detects encoding correctly
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, [
    'Sr. No.', 'Import #', 'PO #', 'Date', 'Supplier', 'Location',
    'Items', 'Currency', 'FOB Total', 'Landed Total', 'Factor'
]);

$srNo = 0;
foreach ($rows as $r) {
    $srNo++;
    // dd-mm-yyyy to match the on-screen list.
    $dateOut = $r['import_date'] ? date('d-m-Y', strtotime($r['import_date'])) : '';
    $currency = $r['currency_code'] ?: ($r['currency_symbol'] ?: '');

    fputcsv($out, [
        $srNo,
        $r['import_number'],
        $r['po_number'] ?: '',
        $dateOut,
        $r['supplier_name'] ?: 'Unknown',
        $r['location_name'] ?: 'Unknown',
        (int)$r['item_count'],
        $currency,
        number_format((float)$r['total_fob_amount'], 2, '.', ''),
        number_format((float)$r['total_landed_amount'], 2, '.', ''),
        number_format((float)$r['costing_factor'], 4, '.', ''),
    ]);
}

fclose($out);
exit;
