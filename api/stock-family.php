<?php
/**
 * Stock Family API
 *
 * Given a destination location and a set of product IDs, returns the aggregated
 * available quantity for each product summed across all WAREHOUSE-type locations
 * that hold stock (requisitions are fulfilled from warehouses). The destination's
 * family is still resolved so the per-location breakdown can flag which rows
 * belong to the destination / its family.
 *
 * Uses a single aggregated UNION ALL query (via _stockMovementsUnionSql()) for
 * all (product, location) pairs to avoid N x M queries.
 *
 * Request (GET or POST JSON):
 *   location_id   - destination location ID (required)
 *   product_ids   - array (POST) or comma-separated string (GET) (required)
 *
 * Response:
 *   {
 *     success: true,
 *     location_id: int,
 *     family_location_ids: int[],
 *     stock: {
 *       <productId>: {
 *         product_id: int,
 *         total_qty: float,
 *         breakdown: [
 *           { location_id, location_name, qty, is_destination, is_family }, ...
 *         ]   // non-zero rows only, destination / family rows flagged
 *       }, ...
 *     }
 *   }
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Allow both requisition creators (create page) and approvers (approve modal)
// to read availability. Additive — does not narrow any existing access.
if (!hasPermission('requisition_vouchers', 'create') && !hasPermission('requisition_vouchers', 'approve')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $locationId = isset($input['location_id']) ? (int)$input['location_id'] : 0;
    $productIds = isset($input['product_ids']) && is_array($input['product_ids']) ? $input['product_ids'] : [];
} else {
    $locationId = isset($_GET['location_id']) ? (int)$_GET['location_id'] : 0;
    $productIds = isset($_GET['product_ids']) ? explode(',', $_GET['product_ids']) : [];
}

if ($locationId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'location_id is required']);
    exit;
}

$productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), function ($v) {
    return $v > 0;
})));

if (empty($productIds)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'product_ids is required']);
    exit;
}

try {
    // The destination's family is still resolved — not to limit the total, but
    // so the breakdown popup can flag which locations belong to the family (and
    // the destination itself). total_qty below is a SYSTEM-WIDE grand total.
    $familyIds = getLocationFamilyIds($locationId, $conn);
    if (empty($familyIds)) {
        $familyIds = [$locationId];
    }
    $familySet = array_fill_keys(array_map('intval', $familyIds), true);

    $prodIn = implode(',', array_map('intval', $productIds));
    $asOfDate = date('Y-m-d');

    // Single aggregated query: net stock per (product, location). Availability is
    // restricted to WAREHOUSE-type locations only — requisitions are fulfilled from
    // warehouses, so both the headline available qty (total_qty) and the
    // per-location breakdown must reflect warehouse stock exclusively. Reuses the
    // shared movement-source UNION.
    $sql = "
        SELECT m.location_id, m.product_id, SUM(m.qty) AS net_qty
        FROM (" . _stockMovementsUnionSql() . ") m
        JOIN locations loc ON loc.id = m.location_id AND loc.location_type = 'warehouse'
        WHERE m.product_id IN ($prodIn)
        GROUP BY m.location_id, m.product_id
        HAVING SUM(m.qty) <> 0
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':d1' => $asOfDate, ':d2' => $asOfDate, ':d3' => $asOfDate, ':d4' => $asOfDate,
        ':d5' => $asOfDate, ':d6' => $asOfDate, ':d7' => $asOfDate, ':d8' => $asOfDate,
        ':d9' => $asOfDate,
    ]);

    // Pre-seed stock map so every requested product appears in the response,
    // even if it has zero stock anywhere.
    $stockMap = [];
    foreach ($productIds as $pid) {
        $stockMap[$pid] = ['product_id' => $pid, 'total_qty' => 0.0, 'breakdown' => []];
    }

    // Fetch all location names once (small reference table) so every row in the
    // breakdown popup can be labelled, regardless of family.
    $locNames = [];
    foreach ($conn->query("SELECT id, name FROM locations")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $locNames[(int)$row['id']] = $row['name'];
    }

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int)$row['product_id'];
        $lid = (int)$row['location_id'];
        $qty = (float)$row['net_qty'];

        if (!isset($stockMap[$pid])) continue;

        $stockMap[$pid]['total_qty'] += $qty;
        $stockMap[$pid]['breakdown'][] = [
            'location_id'    => $lid,
            'location_name'  => $locNames[$lid] ?? ('#' . $lid),
            'qty'            => $qty,
            'is_destination' => ($lid === $locationId),
            'is_family'      => isset($familySet[$lid]),
        ];
    }

    // Sort each product's breakdown: destination first, then family, then by name.
    foreach ($stockMap as &$entry) {
        usort($entry['breakdown'], function ($a, $b) {
            if ($a['is_destination'] !== $b['is_destination']) {
                return $a['is_destination'] ? -1 : 1;
            }
            if ($a['is_family'] !== $b['is_family']) {
                return $a['is_family'] ? -1 : 1;
            }
            return strcasecmp($a['location_name'], $b['location_name']);
        });
    }
    unset($entry);

    echo json_encode([
        'success'             => true,
        'location_id'         => $locationId,
        'family_location_ids' => array_map('intval', $familyIds),
        'stock'               => $stockMap,
    ]);

} catch (PDOException $e) {
    error_log('Stock Family API Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
