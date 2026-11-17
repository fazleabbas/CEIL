<?php
require_once '../../config/database.php';
require_once '../../includes/functions.php';

if (!canViewReport('stock_report_by_type')) {
    http_response_code(403);
    exit('Forbidden');
}

$validTypes = ['site', 'warehouse', 'others'];
$typeFilter = $_GET['location_type'] ?? 'site';
if (!in_array($typeFilter, $validTypes, true)) {
    $typeFilter = 'site';
}
$categoryFilter = $_GET['category'] ?? '';
$productFilter = $_GET['product'] ?? '';
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$today = date('Y-m-d');

// Accessible locations of the selected type (exact-type match, no hierarchy walk)
$locationIds = getUserLocationTypeIds($typeFilter);
if (empty($locationIds)) {
    http_response_code(400);
    exit('No locations of this type');
}

$locInClause = implode(',', array_map('intval', $locationIds));

$categoryCondition = '';
if ($categoryFilter) {
    $categoryIds = getDescendantCategoryIds($categoryFilter, $conn);
    $catInClause = implode(',', array_map('intval', $categoryIds));
    $categoryCondition = "AND p.category_id IN ($catInClause)";
}

$productCondition = '';
if ($productFilter) {
    $productCondition = "AND p.id = " . intval($productFilter);
}

$stmt = $conn->prepare("
    SELECT
        p.id,
        p.name as product_name,
        p.unit,
        p.brand,
        p.description as model,
        pc.name as category_name,
        pcp.name as parent_category_name,

        COALESCE((
            SELECT SUM(os.quantity) FROM opening_stocks os
            WHERE os.product_id = p.id AND os.location_id IN ($locInClause)
              AND os.stock_date BETWEEN ? AND ?
        ), 0) as opening_in_range,

        COALESCE((
            SELECT SUM(os.quantity) FROM opening_stocks os
            WHERE os.product_id = p.id AND os.location_id IN ($locInClause)
              AND os.stock_date < ?
        ), 0) as opening_before_range,

        COALESCE((
            SELECT SUM(pd.quantity) FROM purchase_details pd
            JOIN purchases pu ON pd.purchase_id = pu.id
            WHERE pd.product_id = p.id AND pu.location_id IN ($locInClause)
              AND pu.purchase_date < ?
        ), 0) + COALESCE((
            SELECT SUM(pid.quantity) FROM purchase_import_details pid
            JOIN purchase_imports pi ON pid.import_id = pi.id
            WHERE pid.product_id = p.id AND pi.location_id IN ($locInClause)
              AND pi.import_date < ?
        ), 0) as purchases_before_range,

        COALESCE((
            SELECT SUM(sd.quantity) FROM sale_details sd
            JOIN sales s ON sd.sale_id = s.id
            WHERE sd.product_id = p.id AND s.location_id IN ($locInClause)
              AND s.sale_date < ?
        ), 0) as sales_before_range,

        COALESCE((
            SELECT SUM(pd.quantity) FROM purchase_details pd
            JOIN purchases pu ON pd.purchase_id = pu.id
            WHERE pd.product_id = p.id AND pu.location_id IN ($locInClause)
              AND pu.purchase_date BETWEEN ? AND ?
        ), 0) + COALESCE((
            SELECT SUM(pid.quantity) FROM purchase_import_details pid
            JOIN purchase_imports pi ON pid.import_id = pi.id
            WHERE pid.product_id = p.id AND pi.location_id IN ($locInClause)
              AND pi.import_date BETWEEN ? AND ?
        ), 0) as period_purchases,

        COALESCE((
            SELECT SUM(sd.quantity) FROM sale_details sd
            JOIN sales s ON sd.sale_id = s.id
            WHERE sd.product_id = p.id AND s.location_id IN ($locInClause)
              AND s.sale_date BETWEEN ? AND ?
        ), 0) as period_sales,

        COALESCE((
            SELECT SUM(os.quantity) FROM opening_stocks os
            WHERE os.product_id = p.id AND os.location_id IN ($locInClause)
              AND os.stock_date <= ?
        ), 0) as current_opening_entries,

        COALESCE((
            SELECT SUM(pd.quantity) FROM purchase_details pd
            JOIN purchases pu ON pd.purchase_id = pu.id
            WHERE pd.product_id = p.id AND pu.location_id IN ($locInClause)
              AND pu.purchase_date <= ?
        ), 0) + COALESCE((
            SELECT SUM(pid.quantity) FROM purchase_import_details pid
            JOIN purchase_imports pi ON pid.import_id = pi.id
            WHERE pid.product_id = p.id AND pi.location_id IN ($locInClause)
              AND pi.import_date <= ?
        ), 0) as current_purchases,

        COALESCE((
            SELECT SUM(sd.quantity) FROM sale_details sd
            JOIN sales s ON sd.sale_id = s.id
            WHERE sd.product_id = p.id AND s.location_id IN ($locInClause)
              AND s.sale_date <= ?
        ), 0) as current_sales,

        COALESCE((
            SELECT SUM(CASE WHEN sad.adjustment_type = 'increase' THEN sad.quantity ELSE -sad.quantity END)
            FROM stock_adjustment_details sad
            JOIN stock_adjustments sa ON sad.adjustment_id = sa.id
            WHERE sad.product_id = p.id AND sa.location_id IN ($locInClause)
              AND sa.adjustment_date < ?
        ), 0) as adjustments_before_range,

        COALESCE((
            SELECT SUM(CASE WHEN sad.adjustment_type = 'increase' THEN sad.quantity ELSE -sad.quantity END)
            FROM stock_adjustment_details sad
            JOIN stock_adjustments sa ON sad.adjustment_id = sa.id
            WHERE sad.product_id = p.id AND sa.location_id IN ($locInClause)
              AND sa.adjustment_date BETWEEN ? AND ?
        ), 0) as period_adjustments,

        COALESCE((
            SELECT SUM(CASE WHEN sad.adjustment_type = 'increase' THEN sad.quantity ELSE -sad.quantity END)
            FROM stock_adjustment_details sad
            JOIN stock_adjustments sa ON sad.adjustment_id = sa.id
            WHERE sad.product_id = p.id AND sa.location_id IN ($locInClause)
              AND sa.adjustment_date <= ?
        ), 0) as current_adjustments,

        COALESCE((
            SELECT SUM(td.received_quantity) FROM stock_transfer_details td
            JOIN stock_transfers t ON td.transfer_id = t.id
            WHERE td.product_id = p.id AND t.to_location_id IN ($locInClause)
              AND t.status = 'received' AND DATE(t.received_at) < ?
        ), 0) as transfer_in_before_range,

        COALESCE((
            SELECT SUM(td.dispatched_quantity) FROM stock_transfer_details td
            JOIN stock_transfers t ON td.transfer_id = t.id
            WHERE td.product_id = p.id AND t.from_location_id IN ($locInClause)
              AND t.status IN ('in_transit', 'received') AND DATE(t.dispatched_at) < ?
        ), 0) as transfer_out_before_range,

        COALESCE((
            SELECT SUM(td.received_quantity) FROM stock_transfer_details td
            JOIN stock_transfers t ON td.transfer_id = t.id
            WHERE td.product_id = p.id AND t.to_location_id IN ($locInClause)
              AND t.status = 'received' AND DATE(t.received_at) BETWEEN ? AND ?
        ), 0) as period_transfer_in,

        COALESCE((
            SELECT SUM(td.dispatched_quantity) FROM stock_transfer_details td
            JOIN stock_transfers t ON td.transfer_id = t.id
            WHERE td.product_id = p.id AND t.from_location_id IN ($locInClause)
              AND t.status IN ('in_transit', 'received') AND DATE(t.dispatched_at) BETWEEN ? AND ?
        ), 0) as period_transfer_out,

        COALESCE((
            SELECT SUM(td.received_quantity) FROM stock_transfer_details td
            JOIN stock_transfers t ON td.transfer_id = t.id
            WHERE td.product_id = p.id AND t.to_location_id IN ($locInClause)
              AND t.status = 'received' AND DATE(t.received_at) <= ?
        ), 0) as current_transfer_in,

        COALESCE((
            SELECT SUM(td.dispatched_quantity) FROM stock_transfer_details td
            JOIN stock_transfers t ON td.transfer_id = t.id
            WHERE td.product_id = p.id AND t.from_location_id IN ($locInClause)
              AND t.status IN ('in_transit', 'received') AND DATE(t.dispatched_at) <= ?
        ), 0) as current_transfer_out,

        COALESCE((
            SELECT SUM(cvd.quantity) FROM consumption_voucher_details cvd
            JOIN consumption_vouchers cv ON cvd.voucher_id = cv.id
            WHERE cvd.product_id = p.id AND cv.location_id IN ($locInClause)
              AND cv.voucher_date < ?
        ), 0) as consumption_before_range,

        COALESCE((
            SELECT SUM(cvd.quantity) FROM consumption_voucher_details cvd
            JOIN consumption_vouchers cv ON cvd.voucher_id = cv.id
            WHERE cvd.product_id = p.id AND cv.location_id IN ($locInClause)
              AND cv.voucher_date BETWEEN ? AND ?
        ), 0) as period_consumption,

        COALESCE((
            SELECT SUM(cvd.quantity) FROM consumption_voucher_details cvd
            JOIN consumption_vouchers cv ON cvd.voucher_id = cv.id
            WHERE cvd.product_id = p.id AND cv.location_id IN ($locInClause)
              AND cv.voucher_date <= ?
        ), 0) as current_consumption,

        COALESCE((
            SELECT SUM(sb.quantity_in * sb.rate_per_unit) / NULLIF(SUM(sb.quantity_in), 0)
            FROM stock_batches sb
            WHERE sb.product_id = p.id AND sb.location_id IN ($locInClause)
              AND sb.batch_date <= ?
        ), 0) as avg_rate_closing,

        COALESCE((
            SELECT SUM(sb.quantity_in * sb.rate_per_unit) / NULLIF(SUM(sb.quantity_in), 0)
            FROM stock_batches sb
            WHERE sb.product_id = p.id AND sb.location_id IN ($locInClause)
              AND sb.batch_date <= ?
        ), 0) as avg_rate_current

    FROM products p
    LEFT JOIN product_categories pc ON p.category_id = pc.id
    LEFT JOIN product_categories pcp ON pc.parent_id = pcp.id
    WHERE p.is_active = 1 $categoryCondition $productCondition
    ORDER BY pc.name, p.name
");

$params = [
    $dateFrom, $dateTo,  // opening_in_range
    $dateFrom,           // opening_before_range
    $dateFrom,           // purchases_before_range (regular)
    $dateFrom,           // purchases_before_range (imports)
    $dateFrom,           // sales_before_range
    $dateFrom, $dateTo,  // period_purchases (regular)
    $dateFrom, $dateTo,  // period_purchases (imports)
    $dateFrom, $dateTo,  // period_sales
    $today,              // current_opening_entries
    $today,              // current_purchases (regular)
    $today,              // current_purchases (imports)
    $today,              // current_sales
    $dateFrom,           // adjustments_before_range
    $dateFrom, $dateTo,  // period_adjustments
    $today,              // current_adjustments
    $dateFrom,           // transfer_in_before_range
    $dateFrom,           // transfer_out_before_range
    $dateFrom, $dateTo,  // period_transfer_in
    $dateFrom, $dateTo,  // period_transfer_out
    $today,              // current_transfer_in
    $today,              // current_transfer_out
    $dateFrom,           // consumption_before_range
    $dateFrom, $dateTo,  // period_consumption
    $today,              // current_consumption
    $dateTo,             // avg_rate_closing (weighted avg as of dateTo)
    $today               // avg_rate_current
];

$stmt->execute($params);

$filename = 'stock_report_' . $typeFilter . '_' . str_replace('-', '', $dateFrom) . '_to_' . str_replace('-', '', $dateTo) . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');

$out = fopen('php://output', 'w');

// UTF-8 BOM so Excel detects encoding correctly
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, [
    'Sr. No.', 'ITEM DESCRIPTION', 'UNIT', 'BRAND', 'MODEL',
    'CATEGORY', 'PARENT CATEGORY',
    'CLOSING STOCK', 'TOTAL VALUE OF CLOSING STOCK',
    'CURRENT STOCK', 'RATE', 'TOTAL VALUE'
]);

$srNo = 0;
$totalClosingQty = 0.0;
$totalClosingValue = 0.0;
$totalQty = 0.0;
$totalValue = 0.0;

while ($item = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $opening = $item['opening_in_range']
             + $item['opening_before_range']
             + $item['purchases_before_range']
             - $item['sales_before_range']
             + $item['adjustments_before_range']
             + $item['transfer_in_before_range']
             - $item['transfer_out_before_range']
             - $item['consumption_before_range'];

    $closingStock = $opening
                  + $item['period_purchases']
                  - $item['period_sales']
                  + $item['period_adjustments']
                  + $item['period_transfer_in']
                  - $item['period_transfer_out']
                  - $item['period_consumption'];

    $currentStock = $item['current_opening_entries']
                  + $item['current_purchases']
                  - $item['current_sales']
                  + $item['current_adjustments']
                  + $item['current_transfer_in']
                  - $item['current_transfer_out']
                  - $item['current_consumption'];

    $hasMovement = $opening != 0
                || $item['period_purchases'] != 0
                || $item['period_sales'] != 0
                || $item['period_adjustments'] != 0
                || $item['period_transfer_in'] != 0
                || $item['period_transfer_out'] != 0
                || $item['period_consumption'] != 0
                || $currentStock != 0;

    if (!$hasMovement) continue;

    $srNo++;
    $closingRate = (float)$item['avg_rate_closing'];
    $closingValue = $closingStock * $closingRate;
    $rate = (float)$item['avg_rate_current'];
    $rowValue = $currentStock * $rate;
    $totalClosingQty += $closingStock;
    $totalClosingValue += $closingValue;
    $totalQty += $currentStock;
    $totalValue += $rowValue;

    fputcsv($out, [
        $srNo,
        $item['product_name'],
        $item['unit'],
        $item['brand'] ?? '',
        $item['model'] ?? '',
        $item['category_name'] ?? '',
        $item['parent_category_name'] ?? '',
        number_format($closingStock, 3, '.', ''),
        number_format($closingValue, 2, '.', ''),
        number_format($currentStock, 3, '.', ''),
        number_format($rate, 2, '.', ''),
        number_format($rowValue, 2, '.', ''),
    ]);
}

fputcsv($out, [
    'TOTAL', '', '', '', '', '', '',
    number_format($totalClosingQty, 3, '.', ''),
    number_format($totalClosingValue, 2, '.', ''),
    number_format($totalQty, 3, '.', ''),
    '',
    number_format($totalValue, 2, '.', ''),
]);

fclose($out);
exit;
