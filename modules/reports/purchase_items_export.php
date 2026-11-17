<?php
/**
 * Purchases — Line-Item Excel (CSV) Export
 * Streams ONE ROW PER PRODUCT LINE for a single purchase as a UTF-8 BOM CSV
 * that Excel opens natively. Mirrors purchase_import_items_export.php, but
 * purchases have no additional-cost buckets, so it's product lines only.
 *
 * Scope: constrained to the user's accessible locations. Selected by ?id=<purchaseId>.
 */
require_once '../../config/database.php';
require_once '../../includes/functions.php';

if (!isLoggedIn()) {
    http_response_code(401);
    exit('Unauthorized');
}

if (!hasPermission('purchases', 'view')) {
    http_response_code(403);
    exit('Forbidden');
}

$userLocations = getUserLocations();
$locationIds = array_column($userLocations, 'id');

$purchaseId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$purchase = null;
if ($purchaseId > 0 && !empty($locationIds)) {
    $placeholders = str_repeat('?,', count($locationIds) - 1) . '?';
    $stmt = $conn->prepare("
        SELECT
            p.id, p.purchase_number, p.po_number, p.purchase_date,
            p.total_amount, p.vat_amount, p.exchange_rate_to_base,
            la.name as supplier_name,
            l.name as location_name,
            cc.code as cost_centre_code,
            cc.name as cost_centre_name,
            cur.code as currency_code,
            cur.symbol as currency_symbol
        FROM purchases p
        LEFT JOIN suppliers s ON p.supplier_id = s.id
        LEFT JOIN ledger_accounts la ON s.ledger_account_id = la.id
        LEFT JOIN locations l ON p.location_id = l.id
        LEFT JOIN cost_centres cc ON p.cost_centre_id = cc.id
        LEFT JOIN currencies cur ON p.currency_id = cur.id
        WHERE p.id = ? AND p.location_id IN ($placeholders)
    ");
    $stmt->execute(array_merge([$purchaseId], $locationIds));
    $purchase = $stmt->fetch(PDO::FETCH_ASSOC);
}

$details = [];
if ($purchase) {
    $dStmt = $conn->prepare("
        SELECT pd.quantity, pd.rate_per_unit, pd.amount, pd.batch_number,
               p.name as product_name, p.unit, pc.name as category_name
        FROM purchase_details pd
        JOIN products p ON pd.product_id = p.id
        LEFT JOIN product_categories pc ON p.category_id = pc.id
        WHERE pd.purchase_id = ?
        ORDER BY pd.id
    ");
    $dStmt->execute([$purchase['id']]);
    $details = $dStmt->fetchAll(PDO::FETCH_ASSOC);
}

$safeNo = $purchase
    ? preg_replace('/[^A-Za-z0-9_-]/', '', $purchase['purchase_number'])
    : ('id' . $purchaseId);
$filename = 'purchase_items_' . ($safeNo ?: 'export') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

fputcsv($out, [
    'Sr. No.', 'Purchase #', 'PO #', 'Date', 'Supplier', 'Location', 'Cost Centre',
    'Currency', 'Product', 'Category', 'Unit', 'Qty', 'Rate', 'Amount'
]);

// Nothing to export (bad id / no access) — emit just the header so the user
// still gets a well-formed file rather than a raw error.
if ($purchase) {
    $num = fn($v, $d = 2) => number_format((float)$v, $d, '.', '');
    $dateOut  = $purchase['purchase_date'] ? date('d-m-Y', strtotime($purchase['purchase_date'])) : '';
    $currency = $purchase['currency_code'] ?: ($purchase['currency_symbol'] ?: '');
    $costCentre = trim(($purchase['cost_centre_code'] ? $purchase['cost_centre_code'] . ' - ' : '')
                       . ($purchase['cost_centre_name'] ?? ''));

    $srNo = 0;
    $totalAmt = 0.0;
    foreach ($details as $d) {
        $qty  = (float)$d['quantity'];
        $rate = (float)$d['rate_per_unit'];
        // Prefer the stored line amount; fall back to qty*rate if absent.
        $amt  = ($d['amount'] !== null && $d['amount'] !== '') ? (float)$d['amount'] : $qty * $rate;
        $totalAmt += $amt;
        $srNo++;
        fputcsv($out, [
            $srNo,
            $purchase['purchase_number'],
            $purchase['po_number'] ?: '',
            $dateOut,
            $purchase['supplier_name'] ?: 'Unknown',
            $purchase['location_name'] ?: 'Unknown',
            $costCentre,
            $currency,
            $d['product_name'],
            $d['category_name'] ?: '',
            $d['unit'] ?: '',
            $num($qty, 3),
            $num($rate),
            $num($amt),
        ]);
    }

    // Totals row: items subtotal, VAT, and payable (VAT-inclusive).
    $vat = (float)$purchase['vat_amount'];
    fputcsv($out, ['', '', '', '', '', '', '', '', 'TOTAL', '', '', '', '', $num($totalAmt)]);
    if ($vat > 0) {
        fputcsv($out, ['', '', '', '', '', '', '', '', 'VAT', '', '', '', '', $num($vat)]);
        fputcsv($out, ['', '', '', '', '', '', '', '', 'PAYABLE', '', '', '', '', $num($totalAmt + $vat)]);
    }
}

fclose($out);
exit;
