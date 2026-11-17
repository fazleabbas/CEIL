<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Only the Admin dashboard calls this. Non-Admins get the welcome page in
// index.php, and these figures must not be reachable by typing the URL.
if (getUserData('role_name') !== 'Admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'sales_trend':
        getSalesTrend();
        break;
    case 'negative_stock':
        getNegativeStock();
        break;
    case 'low_stock':
        getLowStock();
        break;
    case 'stock_value':
        getStockValue();
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}

function getStockValue() {
    global $conn;

    try {
        // Current stock value, attributed PER LOCATION then grouped by the
        // location's location_type so the dashboard tile can bifurcate the total
        // into Site / Warehouse / Others / Untyped buckets.
        //
        // Per-(product, location) net qty via UNION ALL of all 8 movement types,
        // multiplied by that LOCATION'S OWN weighted avg_rate from stock_batches.
        // Same active-product filter as the breakdown report, so the sum of the
        // buckets reconciles to the overall total. Locations with no type set
        // (NULL) fall into the 'untyped' bucket.
        $sql = "
            SELECT
                COALESCE(loc.location_type, 'untyped') AS type_key,
                COALESCE(SUM(net.net_qty * COALESCE(NULLIF(sbl.avg_rate, 0), sbp.avg_rate, 0)), 0) AS type_value
            FROM (
                SELECT location_id, product_id, SUM(signed_qty) AS net_qty
                FROM (
                    SELECT location_id, product_id, quantity AS signed_qty
                      FROM opening_stocks
                      WHERE stock_date <= CURDATE()
                    UNION ALL
                    SELECT pu.location_id, pd.product_id, pd.quantity
                      FROM purchase_details pd
                      JOIN purchases pu ON pd.purchase_id = pu.id
                      WHERE pu.purchase_date <= CURDATE()
                    UNION ALL
                    SELECT pi.location_id, pid.product_id, pid.quantity
                      FROM purchase_import_details pid
                      JOIN purchase_imports pi ON pid.import_id = pi.id
                      WHERE pi.import_date <= CURDATE()
                    UNION ALL
                    SELECT s.location_id, sd.product_id, -sd.quantity
                      FROM sale_details sd
                      JOIN sales s ON sd.sale_id = s.id
                      WHERE s.sale_date <= CURDATE()
                    UNION ALL
                    SELECT t.to_location_id AS location_id, td.product_id, td.received_quantity
                      FROM stock_transfer_details td
                      JOIN stock_transfers t ON td.transfer_id = t.id
                      WHERE t.status = 'received' AND DATE(t.received_at) <= CURDATE()
                    UNION ALL
                    SELECT t.from_location_id AS location_id, td.product_id, -td.dispatched_quantity
                      FROM stock_transfer_details td
                      JOIN stock_transfers t ON td.transfer_id = t.id
                      WHERE t.status IN ('in_transit', 'received') AND DATE(t.dispatched_at) <= CURDATE()
                    UNION ALL
                    SELECT sa.location_id, sad.product_id,
                           CASE WHEN sad.adjustment_type = 'increase' THEN sad.quantity ELSE -sad.quantity END
                      FROM stock_adjustment_details sad
                      JOIN stock_adjustments sa ON sad.adjustment_id = sa.id
                      WHERE sa.adjustment_date <= CURDATE()
                    UNION ALL
                    SELECT cv.location_id, cvd.product_id, -cvd.quantity
                      FROM consumption_voucher_details cvd
                      JOIN consumption_vouchers cv ON cvd.voucher_id = cv.id
                      WHERE cv.voucher_date <= CURDATE()
                ) movements
                GROUP BY location_id, product_id
            ) net
            JOIN products p ON p.id = net.product_id AND p.is_active = 1
            JOIN locations loc ON loc.id = net.location_id
            -- Each location valued at its OWN batches' cost. Grouping by
            -- product alone blended every location's rate into one figure.
            -- sbp is the product-wide fallback, reached when a location has
            -- no batch rows OR its batches carry rate 0 -- 0 means no cost
            -- was recorded there, never that the stock is free.
            LEFT JOIN (
                SELECT product_id, location_id,
                       SUM(quantity_in * rate_per_unit) / NULLIF(SUM(quantity_in), 0) AS avg_rate
                FROM stock_batches
                WHERE batch_date <= CURDATE()
                GROUP BY product_id, location_id
            ) sbl ON sbl.product_id = net.product_id AND sbl.location_id = net.location_id
            LEFT JOIN (
                SELECT product_id,
                       SUM(quantity_in * rate_per_unit) / NULLIF(SUM(quantity_in), 0) AS avg_rate
                FROM stock_batches
                WHERE batch_date <= CURDATE()
                GROUP BY product_id
            ) sbp ON sbp.product_id = net.product_id
            GROUP BY COALESCE(loc.location_type, 'untyped')
        ";

        $stmt = $conn->prepare($sql);
        $stmt->execute();

        // Initialise all four buckets so the tile always renders every row.
        $buckets = ['site' => 0.0, 'warehouse' => 0.0, 'others' => 0.0, 'untyped' => 0.0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = $row['type_key'];
            if (!array_key_exists($key, $buckets)) {
                $key = 'untyped';
            }
            $buckets[$key] += (float)$row['type_value'];
        }

        $totalValue = array_sum($buckets);

        $byType = [];
        foreach ($buckets as $key => $value) {
            $byType[$key] = [
                'value' => $value,
                'formatted' => formatCurrency($value),
            ];
        }

        echo json_encode([
            'success' => true,
            'total_value' => $totalValue,
            'formatted' => formatCurrency($totalValue),
            'by_type' => $byType
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function getSalesTrend() {
    global $conn;
    
    try {
        $stmt = $conn->prepare("
            SELECT 
                DATE(sale_date) as date,
                COALESCE(SUM(total_amount), 0) as total
            FROM sales
            WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
            GROUP BY DATE(sale_date)
            ORDER BY date ASC
        ");
        $stmt->execute();
        $results = $stmt->fetchAll();
        
        $labels = [];
        $data = [];
        
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $labels[] = date('D', strtotime($date));
            
            $found = false;
            foreach ($results as $row) {
                if ($row['date'] === $date) {
                    $data[] = (float)$row['total'];
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $data[] = 0;
            }
        }
        
        echo json_encode([
            'success' => true,
            'labels' => $labels,
            'data' => $data
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function getNegativeStock() {
    try {
        echo json_encode([
            'success' => true,
            'items' => getNegativeStockItems(10)
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function getLowStock() {
    try {
        echo json_encode([
            'success' => true,
            'items' => getLowStockItems(5, 10)
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
