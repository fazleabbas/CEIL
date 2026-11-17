<?php
/**
 * Products search API for Select2 AJAX use.
 *
 * Query params:
 *   q           - search term (matches name, barcode)
 *   location_id - required when nonzero=1 (to compute non-zero stock)
 *   date        - required when nonzero=1 (Y-m-d)
 *   category_id - optional, filters to category + descendants
 *   nonzero     - 1 = only products with non-zero stock at location/date
 *   page        - 1-based pagination
 *   ids         - comma-separated IDs to fetch by id (for hydrating preselected
 *                 options in Select2 — bypasses search/filters)
 *
 * Response (Select2 format):
 *   { results: [{id, text}], pagination: { more: bool } }
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['results' => [], 'error' => 'Unauthorized']);
    exit;
}

// requisition_vouchers.VIEW and consumption_vouchers.VIEW are admitted (not just
// .create) because the RV list and the Consumption Voucher list both have a
// product filter — a view-only user could open either page but the dropdown
// would 403 and silently return nothing. This endpoint only ever returns
// product names, so read access is the right bar.
if (!hasPermission('stock_taking', 'view')
    && !hasPermission('requisition_vouchers', 'view')
    && !hasPermission('requisition_vouchers', 'create')
    && !hasPermission('consumption_vouchers', 'view')
    && !hasPermission('boq', 'create')
    && !hasPermission('bom', 'create')) {
    http_response_code(403);
    echo json_encode(['results' => [], 'error' => 'Forbidden']);
    exit;
}

try {
    // Optional cost auto-fill (used by the BOM form): when with_cost=1, each
    // result carries `rate` = weighted-average cost for the product at the
    // given location/date.
    $withCost       = !empty($_GET['with_cost']);
    $costLocationId = (int)($_GET['location_id'] ?? 0);
    $costDate       = trim($_GET['date'] ?? '');

    // ----- Hydration mode: explicit IDs requested -----
    $idsParam = trim($_GET['ids'] ?? '');
    if ($idsParam !== '') {
        $ids = array_filter(array_map('intval', explode(',', $idsParam)));
        if (empty($ids)) {
            echo json_encode(['results' => [], 'pagination' => ['more' => false]]);
            exit;
        }
        list($ph, $params) = buildInClause($ids);
        $stmt = $conn->prepare("
            SELECT p.id, p.name, p.unit, pc.name AS category_name
            FROM products p
            LEFT JOIN product_categories pc ON p.category_id = pc.id
            WHERE p.id IN ($ph)
            ORDER BY p.name ASC
        ");
        $stmt->execute($params);
        $results = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $label = $row['name'];
            if (!empty($row['category_name'])) {
                $label .= ' (' . $row['category_name'] . ')';
            }
            $item = ['id' => (int)$row['id'], 'text' => $label, 'unit' => $row['unit']];
            if ($withCost) {
                $item['rate'] = getProductAvgCost($row['id'], $costLocationId, $costDate, $conn);
            }
            $results[] = $item;
        }
        echo json_encode(['results' => $results, 'pagination' => ['more' => false]]);
        exit;
    }

    // ----- Search mode -----
    $q          = trim($_GET['q'] ?? '');
    $locationId = (int)($_GET['location_id'] ?? 0);
    $date       = trim($_GET['date'] ?? '');
    $categoryId = (int)($_GET['category_id'] ?? 0);
    $nonzero    = !empty($_GET['nonzero']);
    $page       = max(1, (int)($_GET['page'] ?? 1));
    $perPage    = 30;
    $offset     = ($page - 1) * $perPage;

    $where  = ['p.is_active = 1'];
    $params = [];

    if ($q !== '') {
        // Two distinct placeholders — PDO with ATTR_EMULATE_PREPARES=false does
        // not allow reusing the same named param.
        $where[] = '(p.name LIKE :qname OR p.barcode LIKE :qbarcode)';
        $params[':qname']    = '%' . $q . '%';
        $params[':qbarcode'] = '%' . $q . '%';
    }

    if ($categoryId > 0) {
        $catIds = getDescendantCategoryIds($categoryId, $conn);
        if (!empty($catIds)) {
            $catIds = array_map('intval', $catIds);
            $where[] = 'p.category_id IN (' . implode(',', $catIds) . ')';
        } else {
            // Category resolved to nothing → no results
            echo json_encode(['results' => [], 'pagination' => ['more' => false]]);
            exit;
        }
    }

    if ($nonzero && $locationId > 0 && $date !== '') {
        $nonzeroIds = getProductIdsWithNonZeroStock($locationId, $date, $conn);
        if (empty($nonzeroIds)) {
            echo json_encode(['results' => [], 'pagination' => ['more' => false]]);
            exit;
        }
        $nonzeroIds = array_map('intval', $nonzeroIds);
        $where[] = 'p.id IN (' . implode(',', $nonzeroIds) . ')';
    }

    $whereSql = implode(' AND ', $where);

    // Fetch one extra row to detect "more"
    $sql = "
        SELECT p.id, p.name, p.unit, pc.name AS category_name
        FROM products p
        LEFT JOIN product_categories pc ON p.category_id = pc.id
        WHERE $whereSql
        ORDER BY p.name ASC
        LIMIT " . ($perPage + 1) . " OFFSET " . (int)$offset . "
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $more = false;
    if (count($rows) > $perPage) {
        $more = true;
        $rows = array_slice($rows, 0, $perPage);
    }

    $results = [];
    foreach ($rows as $row) {
        $label = $row['name'];
        if (!empty($row['category_name'])) {
            $label .= ' (' . $row['category_name'] . ')';
        }
        $item = ['id' => (int)$row['id'], 'text' => $label, 'unit' => $row['unit']];
        if ($withCost) {
            $item['rate'] = getProductAvgCost($row['id'], $costLocationId, $costDate, $conn);
        }
        $results[] = $item;
    }

    echo json_encode([
        'results'    => $results,
        'pagination' => ['more' => $more],
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'results' => [],
        'error'   => $e->getMessage(),
    ]);
}
