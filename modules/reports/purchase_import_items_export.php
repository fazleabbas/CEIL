<?php
/**
 * Purchase Imports — Line-Item Excel (CSV) Export
 * Streams ONE ROW PER LINE: each product line AND each additional-cost line
 * (Bucket A supplier-borne / B third-party / C other-not-costed) for every
 * import in the current view. A "Row Type" column distinguishes them.
 *
 * Same scope + filters as the list page / api/purchase-imports.php:
 *   - user's accessible locations
 *   - dateFrom / dateTo (YYYY-MM-DD, either optional)
 *   - search (import #, PO #, supplier, location)
 *
 * Amounts note: product FOB/landed values are in the import's HEADER currency;
 * cost lines carry their own line currency + FX-to-base. No cross-import grand
 * total is emitted because imports can be in different currencies.
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

$userLocations = getUserLocations();
$locationIds = array_column($userLocations, 'id');

$importId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$dateFrom = isset($_GET['dateFrom']) ? trim($_GET['dateFrom']) : '';
$dateTo   = isset($_GET['dateTo'])   ? trim($_GET['dateTo'])   : '';
$search   = isset($_GET['search'])   ? trim($_GET['search'])   : '';
$validDate = fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;

$imports = [];
if (!empty($locationIds)) {
    $placeholders = str_repeat('?,', count($locationIds) - 1) . '?';

    // A single import (per-row button) takes precedence over the range/search
    // filters. Still constrained to the user's accessible locations.
    $extraConditions = '';
    $params = $locationIds;
    if ($importId > 0) {
        $extraConditions = ' AND pi.id = ?';
        $params[] = $importId;
    } else {
        if ($validDate($dateFrom)) { $extraConditions .= ' AND pi.import_date >= ?'; $params[] = $dateFrom; }
        if ($validDate($dateTo))   { $extraConditions .= ' AND pi.import_date <= ?'; $params[] = $dateTo; }
        if ($search !== '') {
            $extraConditions .= ' AND (pi.import_number LIKE ? OR pi.po_number LIKE ?
                                       OR la.name LIKE ? OR l.name LIKE ?)';
            $like = '%' . $search . '%';
            $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        }
    }

    $stmt = $conn->prepare("
        SELECT
            pi.id, pi.import_number, pi.po_number, pi.import_date,
            pi.exchange_rate_to_base,
            la.name as supplier_name,
            l.name as location_name,
            cur.code as currency_code,
            cur.symbol as currency_symbol
        FROM purchase_imports pi
        LEFT JOIN suppliers s ON pi.supplier_id = s.id
        LEFT JOIN ledger_accounts la ON s.ledger_account_id = la.id
        LEFT JOIN locations l ON pi.location_id = l.id
        LEFT JOIN currencies cur ON pi.currency_id = cur.id
        WHERE pi.location_id IN ($placeholders)
          $extraConditions
        ORDER BY pi.import_date DESC, pi.id DESC
    ");
    $stmt->execute($params);
    $imports = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Prepared child queries reused per import.
$detailsStmt = $conn->prepare("
    SELECT pid.quantity, pid.fob_rate_per_unit, pid.landed_rate_per_unit, pid.selling_price,
           p.name as product_name, p.unit, pc.name as category_name
    FROM purchase_import_details pid
    JOIN products p ON pid.product_id = p.id
    LEFT JOIN product_categories pc ON p.category_id = pc.id
    WHERE pid.import_id = ?
    ORDER BY pid.id
");
$costsStmt = $conn->prepare("
    SELECT pic.bucket, pic.description, pic.amount, pic.exchange_rate_to_base,
           pic.vat_percent, pic.vat_amount,
           la.name AS ledger_name,
           bla.name AS beneficiary_name,
           cur.code AS currency_code
    FROM purchase_import_costs pic
    LEFT JOIN ledger_accounts la ON pic.ledger_id = la.id
    LEFT JOIN suppliers bs ON pic.beneficiary_supplier_id = bs.id
    LEFT JOIN ledger_accounts bla ON bs.ledger_account_id = bla.id
    LEFT JOIN currencies cur ON pic.currency_id = cur.id
    WHERE pic.import_id = ?
    ORDER BY FIELD(pic.bucket,'A','B','C'), pic.id
");

if ($importId > 0) {
    $singleNo = !empty($imports) ? preg_replace('/[^A-Za-z0-9_-]/', '', $imports[0]['import_number']) : ('id' . $importId);
    $filename = 'purchase_import_items_' . $singleNo . '.csv';
} else {
    $filenameFrom = $validDate($dateFrom) ? str_replace('-', '', $dateFrom) : 'all';
    $filenameTo   = $validDate($dateTo)   ? str_replace('-', '', $dateTo)   : 'all';
    $filename = 'purchase_import_items_' . $filenameFrom . '_to_' . $filenameTo . '.csv';
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

fputcsv($out, [
    'Sr. No.', 'Import #', 'PO #', 'Date', 'Supplier', 'Location', 'Header Currency',
    'Row Type', 'Item / Cost', 'Beneficiary', 'Category', 'Unit',
    'Qty', 'FOB Rate', 'FOB Amount', 'Landed Rate', 'Landed Amount',
    'Selling Price', 'Profit/Unit',
    'Line Currency', 'FX to Base', 'Line Amount', 'VAT %', 'VAT Amount'
]);

$num = fn($v, $d = 2) => number_format((float)$v, $d, '.', '');
$bucketLabel = [
    'A' => 'Cost — Supplier-borne (A)',
    'B' => 'Cost — Third-party (B)',
    'C' => 'Cost — Other, not costed (C)',
];

$srNo = 0;
foreach ($imports as $imp) {
    $dateOut  = $imp['import_date'] ? date('d-m-Y', strtotime($imp['import_date'])) : '';
    $hdrCur   = $imp['currency_code'] ?: ($imp['currency_symbol'] ?: '');
    $hdrFx    = (float)$imp['exchange_rate_to_base'];
    // Shared leading columns for every row of this import.
    $lead = [
        $imp['import_number'],
        $imp['po_number'] ?: '',
        $dateOut,
        $imp['supplier_name'] ?: 'Unknown',
        $imp['location_name'] ?: 'Unknown',
        $hdrCur,
    ];

    // Product line items (FOB/landed are in header currency).
    $detailsStmt->execute([$imp['id']]);
    foreach ($detailsStmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $qty        = (float)$d['quantity'];
        $fobRate    = (float)$d['fob_rate_per_unit'];
        $landedRate = (float)$d['landed_rate_per_unit'];
        $hasSelling = $d['selling_price'] !== null && $d['selling_price'] !== '';
        $selling    = $hasSelling ? (float)$d['selling_price'] : null;
        $profit     = $hasSelling ? ($selling - $landedRate) : null;

        $srNo++;
        fputcsv($out, array_merge([$srNo], $lead, [
            'Product',
            $d['product_name'],
            '',                       // Beneficiary
            $d['category_name'] ?: '',
            $d['unit'] ?: '',
            $num($qty, 3),
            $num($fobRate),
            $num($qty * $fobRate),
            $num($landedRate),
            $num($qty * $landedRate),
            $hasSelling ? $num($selling) : '',
            $profit !== null ? $num($profit) : '',
            '', '', '', '', '',       // Line Currency / FX / Amount / VAT% / VAT Amount
        ]));
    }

    // Additional-cost lines (Bucket A/B/C).
    $costsStmt->execute([$imp['id']]);
    foreach ($costsStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $bucket = $c['bucket'] ?? 'B';
        $label  = $c['ledger_name'] ?: ($c['description'] ?: 'Cost');
        // Bucket A carries no line currency of its own — it's in header currency.
        if ($bucket === 'A') {
            $lineCur = $hdrCur;
            $lineFx  = $hdrFx;
        } else {
            $lineCur = $c['currency_code'] ?: '';
            $lineFx  = (float)$c['exchange_rate_to_base'];
        }
        $vatPct = ($c['vat_percent'] !== null && $c['vat_percent'] !== '') ? $num($c['vat_percent']) : '';

        $srNo++;
        fputcsv($out, array_merge([$srNo], $lead, [
            $bucketLabel[$bucket] ?? ('Cost — ' . $bucket),
            $label,
            $c['beneficiary_name'] ?: '',
            '', '',                   // Category / Unit
            '', '', '', '', '',       // Qty / FOB Rate / FOB Amount / Landed Rate / Landed Amount
            '', '',                   // Selling Price / Profit
            $lineCur,
            $lineFx > 0 ? $num($lineFx, 4) : '',
            $num($c['amount']),
            $vatPct,
            $num($c['vat_amount']),
        ]));
    }
}

fclose($out);
exit;
