<?php
/**
 * Dead Stock popup: the latest document of each movement type for one product
 * at one exact location. Read-only.
 *
 * Request (GET): product, location
 * Response: { success, product, location, rows: [ { type, label, counts_as_movement,
 *             date, document, quantity, party, url } ] }
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/dead_stock_query.php';

function deadStockDetailFail($status, $message) {
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

if (!isLoggedIn()) {
    deadStockDetailFail(401, 'Not logged in');
}
if (!canViewReport('dead_stock')) {
    deadStockDetailFail(403, 'No permission');
}

$productId = (int)($_GET['product'] ?? 0);
$locationId = (int)($_GET['location'] ?? 0);
if ($productId <= 0 || $locationId <= 0) {
    deadStockDetailFail(400, 'Product and location are required');
}
if (!canAccessLocation($locationId)) {
    deadStockDetailFail(403, 'No access to this location');
}

try {
    $stmt = $conn->prepare("SELECT name FROM products WHERE id = ?");
    $stmt->execute([$productId]);
    $productName = $stmt->fetchColumn();

    $stmt = $conn->prepare("SELECT name FROM locations WHERE id = ?");
    $stmt->execute([$locationId]);
    $locationName = $stmt->fetchColumn();

    if ($productName === false || $locationName === false) {
        deadStockDetailFail(404, 'Not found');
    }

    // Every query takes (product, location) and returns the latest document.
    // Quantities are summed because one document can list a product on several lines.
    $types = [
        'purchase' => [
            'counts' => true, 'module' => 'purchases', 'url' => 'modules/transactions/purchases_view.php?id=',
            'sql' => "SELECT h.id, h.purchase_date AS d, h.purchase_number AS document, SUM(x.quantity) AS qty, la.name AS party
                      FROM purchase_details x
                      JOIN purchases h ON h.id = x.purchase_id
                      LEFT JOIN suppliers su ON su.id = h.supplier_id
                      LEFT JOIN ledger_accounts la ON la.id = su.ledger_account_id
                      WHERE x.product_id = ? AND h.location_id = ? AND h.purchase_date <= CURDATE()
                      GROUP BY h.id, h.purchase_date, h.purchase_number, la.name
                      ORDER BY d DESC, h.id DESC LIMIT 1",
        ],
        'import' => [
            'counts' => true, 'module' => 'purchase_imports', 'url' => 'modules/transactions/purchase_imports.php?view=',
            'sql' => "SELECT h.id, h.import_date AS d, h.import_number AS document, SUM(x.quantity) AS qty, la.name AS party
                      FROM purchase_import_details x
                      JOIN purchase_imports h ON h.id = x.import_id
                      LEFT JOIN suppliers su ON su.id = h.supplier_id
                      LEFT JOIN ledger_accounts la ON la.id = su.ledger_account_id
                      WHERE x.product_id = ? AND h.location_id = ? AND h.import_date <= CURDATE()
                      GROUP BY h.id, h.import_date, h.import_number, la.name
                      ORDER BY d DESC, h.id DESC LIMIT 1",
        ],
        'sale' => [
            'counts' => true, 'module' => 'sales', 'url' => 'modules/transactions/sales_view.php?id=',
            'sql' => "SELECT h.id, h.sale_date AS d, h.sale_number AS document, SUM(x.quantity) AS qty,
                             COALESCE(la.name, h.print_name) AS party
                      FROM sale_details x
                      JOIN sales h ON h.id = x.sale_id
                      LEFT JOIN customers cu ON cu.id = h.customer_id
                      LEFT JOIN ledger_accounts la ON la.id = cu.ledger_account_id
                      WHERE x.product_id = ? AND h.location_id = ? AND h.sale_date <= CURDATE()
                      GROUP BY h.id, h.sale_date, h.sale_number, la.name, h.print_name
                      ORDER BY d DESC, h.id DESC LIMIT 1",
        ],
        'consumption' => [
            'counts' => true, 'module' => 'consumption_vouchers', 'url' => 'modules/transactions/consumption_voucher_view.php?id=',
            'sql' => "SELECT h.id, h.voucher_date AS d, h.voucher_number AS document, SUM(x.quantity) AS qty, cc.name AS party
                      FROM consumption_voucher_details x
                      JOIN consumption_vouchers h ON h.id = x.voucher_id
                      LEFT JOIN cost_centres cc ON cc.id = h.cost_centre_id
                      WHERE x.product_id = ? AND h.location_id = ? AND h.voucher_date <= CURDATE()
                      GROUP BY h.id, h.voucher_date, h.voucher_number, cc.name
                      ORDER BY d DESC, h.id DESC LIMIT 1",
        ],
        'transfer_in' => [
            'counts' => true, 'module' => 'stock_transfer', 'url' => 'modules/transactions/transfers_view.php?id=',
            'sql' => "SELECT h.id, DATE(h.received_at) AS d, h.transfer_number AS document, SUM(x.received_quantity) AS qty, fl.name AS party
                      FROM stock_transfer_details x
                      JOIN stock_transfers h ON h.id = x.transfer_id
                      LEFT JOIN locations fl ON fl.id = h.from_location_id
                      WHERE x.product_id = ? AND h.to_location_id = ? AND h.status = 'received' AND DATE(h.received_at) <= CURDATE()
                      GROUP BY h.id, DATE(h.received_at), h.transfer_number, fl.name
                      ORDER BY d DESC, h.id DESC LIMIT 1",
        ],
        'transfer_out' => [
            'counts' => true, 'module' => 'stock_transfer', 'url' => 'modules/transactions/transfers_view.php?id=',
            'sql' => "SELECT h.id, DATE(h.dispatched_at) AS d, h.transfer_number AS document, SUM(x.dispatched_quantity) AS qty, tl.name AS party
                      FROM stock_transfer_details x
                      JOIN stock_transfers h ON h.id = x.transfer_id
                      LEFT JOIN locations tl ON tl.id = h.to_location_id
                      WHERE x.product_id = ? AND h.from_location_id = ? AND h.status IN ('in_transit', 'received') AND DATE(h.dispatched_at) <= CURDATE()
                      GROUP BY h.id, DATE(h.dispatched_at), h.transfer_number, tl.name
                      ORDER BY d DESC, h.id DESC LIMIT 1",
        ],
        'opening' => [
            'counts' => false, 'module' => null, 'url' => null,
            'sql' => "SELECT x.id, x.stock_date AS d, NULL AS document, x.quantity AS qty, NULL AS party
                      FROM opening_stocks x
                      WHERE x.product_id = ? AND x.location_id = ? AND x.stock_date <= CURDATE()
                      ORDER BY d DESC, x.id DESC LIMIT 1",
        ],
        'adjustment' => [
            'counts' => false, 'module' => 'stock_adjustment', 'url' => 'modules/transactions/stock_adjustment_view.php?id=',
            'sql' => "SELECT h.id, h.adjustment_date AS d, h.adjustment_number AS document,
                             SUM(CASE WHEN x.adjustment_type = 'increase' THEN x.quantity ELSE -x.quantity END) AS qty, NULL AS party
                      FROM stock_adjustment_details x
                      JOIN stock_adjustments h ON h.id = x.adjustment_id
                      WHERE x.product_id = ? AND h.location_id = ? AND h.adjustment_date <= CURDATE()
                      GROUP BY h.id, h.adjustment_date, h.adjustment_number
                      ORDER BY d DESC, h.id DESC LIMIT 1",
        ],
        // Any status: a count still in draft is still someone having counted the item.
        'stock_count' => [
            'counts' => false, 'module' => 'stock_taking', 'url' => 'modules/reports/stock_taking_view.php?id=',
            'sql' => "SELECT h.id, h.stock_date AS d, h.stock_taking_number AS document, SUM(x.physical_stock) AS qty, h.status AS party
                      FROM stock_taking_details x
                      JOIN stock_taking h ON h.id = x.taking_id
                      WHERE x.product_id = ? AND h.location_id = ? AND h.stock_date <= CURDATE()
                      GROUP BY h.id, h.stock_date, h.stock_taking_number, h.status
                      ORDER BY d DESC, h.id DESC LIMIT 1",
        ],
    ];

    $rows = [];
    foreach ($types as $type => $config) {
        $stmt = $conn->prepare($config['sql']);
        $stmt->execute([$productId, $locationId]);
        $found = $stmt->fetch(PDO::FETCH_ASSOC);

        $url = null;
        if ($found && $config['url'] && hasPermission($config['module'], 'view')) {
            $url = BASE_URL . $config['url'] . (int)$found['id'];
        }

        $rows[] = [
            'type' => $type,
            'label' => deadStockMovementLabel($type),
            'counts_as_movement' => $config['counts'],
            'date' => $found ? formatDate($found['d']) : null,
            'document' => $found ? $found['document'] : null,
            'quantity' => $found ? formatNumber($found['qty'], 3) : null,
            'party' => !$found ? null : ($type === 'stock_count' ? __($found['party']) : $found['party']),
            'url' => $url,
        ];
    }

    echo json_encode([
        'success' => true,
        'product' => $productName,
        'location' => $locationName,
        'rows' => $rows,
    ]);
} catch (Throwable $e) {
    error_log('dead-stock-detail: ' . $e->getMessage());
    deadStockDetailFail(500, 'Could not load the details');
}
