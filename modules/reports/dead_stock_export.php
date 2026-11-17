<?php
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/dead_stock_query.php';

if (!canViewReport('dead_stock')) {
    http_response_code(403);
    exit('Forbidden');
}

$days = deadStockParseDays($_GET['days'] ?? '');
if ($days === null) {
    http_response_code(400);
    exit(sprintf(__('dead_stock_days_invalid'), DEAD_STOCK_MAX_DAYS));
}

$locationIds = deadStockResolveLocationIds($conn, $_GET['location'] ?? 0);
if (empty($locationIds)) {
    http_response_code(403);
    exit(__('dead_stock_no_location_access'));
}

$categoryFilter = (int)($_GET['category'] ?? 0);
$categoryIds = $categoryFilter > 0 ? getDescendantCategoryIds($categoryFilter, $conn) : [];
$rows = deadStockFetchRows($conn, $locationIds, $days, $categoryIds);

/**
 * Excel runs a cell starting with one of these as a formula, so text from
 * master data is prefixed to keep it as plain text. Numeric columns skip this.
 */
function deadStockCsvText($value) {
    $value = (string)$value;
    return ($value !== '' && strpos("=+-@\t\r", $value[0]) !== false) ? "'" . $value : $value;
}

$filename = 'dead_stock_' . $days . '_days_' . date('Ymd') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');

$out = fopen('php://output', 'w');

// UTF-8 BOM so Excel detects encoding correctly
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, [
    __('location'),
    __('product'),
    __('category'),
    __('unit'),
    __('quantity'),
    __('dead_stock_last_movement'),
    __('dead_stock_movement_type'),
    __('dead_stock_days_idle'),
    __('dead_stock_type_opening'),
    __('rate'),
    __('value'),
]);

$totalQty = 0.0;
$totalValue = 0.0;
foreach ($rows as $row) {
    $totalQty += (float)$row['quantity'];
    $totalValue += (float)$row['stock_value'];
    $moved = !empty($row['last_movement_date']);
    fputcsv($out, [
        deadStockCsvText($row['location_name']),
        deadStockCsvText($row['product_name']),
        deadStockCsvText($row['category_name']),
        deadStockCsvText($row['unit']),
        round((float)$row['quantity'], 3),
        $moved ? $row['last_movement_date'] : '',
        $moved ? deadStockMovementLabel($row['last_movement_type']) : __('dead_stock_never_moved'),
        $moved ? (int)$row['days_idle'] : '',
        (string)$row['opening_date'],
        round((float)$row['rate'], 2),
        round((float)$row['stock_value'], 2),
    ]);
}

fputcsv($out, [__('grand_total'), '', '', '', round($totalQty, 3), '', '', '', '', '', round($totalValue, 2)]);
fclose($out);
