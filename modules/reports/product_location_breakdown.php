<?php
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// -------- DIAGNOSTIC (TEMPORARY) --------
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
$__dbgStart = microtime(true);
$__dbgLog = [];
$__dbgFile = __DIR__ . '/breakdown_debug.log';
$__dbg = function ($msg) use (&$__dbgLog, $__dbgStart, $__dbgFile) {
    $t = number_format((microtime(true) - $__dbgStart) * 1000, 1);
    $line = "[{$t}ms] $msg";
    $__dbgLog[] = $line;
    @file_put_contents($__dbgFile, $line . "\n", FILE_APPEND | LOCK_EX);
    @error_log('[BREAKDOWN] ' . $line);
};
$__dbg('---- REQUEST START ' . date('c') . ' ----');
$__dbg('URL: ' . ($_SERVER['REQUEST_URI'] ?? '?'));
// ----------------------------------------

// Force buffered queries for this connection — production defaulted to unbuffered,
// which prevented preparing a new statement while a previous cursor was still open.
if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
    $conn->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
}

$pageTitle = __('product_location_breakdown_title', 'Product Location Breakdown');
if (!canViewReport('stock_report')) {
    setAlert(__('no_permission', 'You do not have permission to access this page'), 'danger');
    redirect('');
}

$__dbg('before header include');
require_once '../../includes/header.php';
$__dbg('after header include');

// Get filters
$productId = (int)($_GET['product'] ?? 0);
$locationFilter = $_GET['location'] ?? '';
$categoryFilter = $_GET['category'] ?? '';
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$today = date('Y-m-d');

// Get user locations (for filter dropdown)
$userLocations = getUserLocations();

// Load product
$product = null;
if ($productId) {
    $__dbg('query START: product');
    $t0 = microtime(true);
    $prodStmt = $conn->prepare("
        SELECT p.id, p.name, p.unit, pc.name AS category_name
        FROM products p
        LEFT JOIN product_categories pc ON p.category_id = pc.id
        WHERE p.id = ? AND p.is_active = 1
    ");
    $prodStmt->execute([$productId]);
    $product = $prodStmt->fetch();
    $prodStmt->closeCursor();
    $__dbg('query DONE : product (' . number_format((microtime(true) - $t0) * 1000, 1) . 'ms, found=' . ($product ? 'yes' : 'no') . ')');
}

// Build the breakdown data
$breakdownRows = [];
$avgRateClosing = 0.0;
$avgRateCurrent = 0.0;

if ($product && $locationFilter) {
    // Parent + all descendants
    $locationIds = getDescendantLocationIds($locationFilter, $conn);
    $locInClause = implode(',', array_map('intval', $locationIds));

    // Helper: runs SQL and returns [location_id => [metric => total, ...]]
    // Rows must have a location_id column plus any number of numeric columns.
    $fetchByLocation = function ($label, $sql, $params) use ($conn, $__dbg) {
        $__dbg("query START: $label");
        $t0 = microtime(true);
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $stmt->closeCursor();
        $ms = number_format((microtime(true) - $t0) * 1000, 1);
        $__dbg("query DONE : $label (" . count($rows) . " rows, {$ms}ms)");
        $map = [];
        foreach ($rows as $row) {
            $lid = (int)$row['location_id'];
            unset($row['location_id']);
            $cleaned = [];
            foreach ($row as $k => $v) $cleaned[$k] = (float)$v;
            $map[$lid] = $cleaned;
        }
        return $map;
    };

    $__dbg('locationIds count = ' . count($locationIds));
    // Opening stocks — 3 date-window aggregates in one pass
    $opening = $fetchByLocation('opening_stocks', "
        SELECT location_id,
               SUM(CASE WHEN stock_date BETWEEN ? AND ? THEN quantity ELSE 0 END) AS in_range,
               SUM(CASE WHEN stock_date < ? THEN quantity ELSE 0 END) AS before_range,
               SUM(CASE WHEN stock_date <= ? THEN quantity ELSE 0 END) AS current_till
        FROM opening_stocks
        WHERE product_id = ? AND location_id IN ($locInClause)
        GROUP BY location_id
    ", [$dateFrom, $dateTo, $dateFrom, $today, $productId]);

    // Purchases (regular) — before / period / current in one pass
    $purchasesRegular = $fetchByLocation('purchases_regular', "
        SELECT pu.location_id AS location_id,
               SUM(CASE WHEN pu.purchase_date < ? THEN pd.quantity ELSE 0 END) AS before_range,
               SUM(CASE WHEN pu.purchase_date BETWEEN ? AND ? THEN pd.quantity ELSE 0 END) AS period,
               SUM(CASE WHEN pu.purchase_date <= ? THEN pd.quantity ELSE 0 END) AS current_till
        FROM purchase_details pd
        JOIN purchases pu ON pd.purchase_id = pu.id
        WHERE pd.product_id = ? AND pu.location_id IN ($locInClause)
        GROUP BY pu.location_id
    ", [$dateFrom, $dateFrom, $dateTo, $today, $productId]);

    // Purchases (imports)
    $purchasesImports = $fetchByLocation('purchases_imports', "
        SELECT pi.location_id AS location_id,
               SUM(CASE WHEN pi.import_date < ? THEN pid.quantity ELSE 0 END) AS before_range,
               SUM(CASE WHEN pi.import_date BETWEEN ? AND ? THEN pid.quantity ELSE 0 END) AS period,
               SUM(CASE WHEN pi.import_date <= ? THEN pid.quantity ELSE 0 END) AS current_till
        FROM purchase_import_details pid
        JOIN purchase_imports pi ON pid.import_id = pi.id
        WHERE pid.product_id = ? AND pi.location_id IN ($locInClause)
        GROUP BY pi.location_id
    ", [$dateFrom, $dateFrom, $dateTo, $today, $productId]);

    // Sales
    $sales = $fetchByLocation('sales', "
        SELECT s.location_id AS location_id,
               SUM(CASE WHEN s.sale_date < ? THEN sd.quantity ELSE 0 END) AS before_range,
               SUM(CASE WHEN s.sale_date BETWEEN ? AND ? THEN sd.quantity ELSE 0 END) AS period,
               SUM(CASE WHEN s.sale_date <= ? THEN sd.quantity ELSE 0 END) AS current_till
        FROM sale_details sd
        JOIN sales s ON sd.sale_id = s.id
        WHERE sd.product_id = ? AND s.location_id IN ($locInClause)
        GROUP BY s.location_id
    ", [$dateFrom, $dateFrom, $dateTo, $today, $productId]);

    // Adjustments (signed by type)
    $adjustments = $fetchByLocation('adjustments', "
        SELECT sa.location_id AS location_id,
               SUM(CASE WHEN sa.adjustment_date < ? THEN (CASE WHEN sad.adjustment_type = 'increase' THEN sad.quantity ELSE -sad.quantity END) ELSE 0 END) AS before_range,
               SUM(CASE WHEN sa.adjustment_date BETWEEN ? AND ? THEN (CASE WHEN sad.adjustment_type = 'increase' THEN sad.quantity ELSE -sad.quantity END) ELSE 0 END) AS period,
               SUM(CASE WHEN sa.adjustment_date <= ? THEN (CASE WHEN sad.adjustment_type = 'increase' THEN sad.quantity ELSE -sad.quantity END) ELSE 0 END) AS current_till
        FROM stock_adjustment_details sad
        JOIN stock_adjustments sa ON sad.adjustment_id = sa.id
        WHERE sad.product_id = ? AND sa.location_id IN ($locInClause)
        GROUP BY sa.location_id
    ", [$dateFrom, $dateFrom, $dateTo, $today, $productId]);

    // Transfer In (received at these locations)
    $transferIn = $fetchByLocation('transfer_in', "
        SELECT t.to_location_id AS location_id,
               SUM(CASE WHEN DATE(t.received_at) < ? THEN td.received_quantity ELSE 0 END) AS before_range,
               SUM(CASE WHEN DATE(t.received_at) BETWEEN ? AND ? THEN td.received_quantity ELSE 0 END) AS period,
               SUM(CASE WHEN DATE(t.received_at) <= ? THEN td.received_quantity ELSE 0 END) AS current_till
        FROM stock_transfer_details td
        JOIN stock_transfers t ON td.transfer_id = t.id
        WHERE td.product_id = ? AND t.to_location_id IN ($locInClause) AND t.status = 'received'
        GROUP BY t.to_location_id
    ", [$dateFrom, $dateFrom, $dateTo, $today, $productId]);

    // Transfer Out (dispatched from these locations)
    $transferOut = $fetchByLocation('transfer_out', "
        SELECT t.from_location_id AS location_id,
               SUM(CASE WHEN DATE(t.dispatched_at) < ? THEN td.dispatched_quantity ELSE 0 END) AS before_range,
               SUM(CASE WHEN DATE(t.dispatched_at) BETWEEN ? AND ? THEN td.dispatched_quantity ELSE 0 END) AS period,
               SUM(CASE WHEN DATE(t.dispatched_at) <= ? THEN td.dispatched_quantity ELSE 0 END) AS current_till
        FROM stock_transfer_details td
        JOIN stock_transfers t ON td.transfer_id = t.id
        WHERE td.product_id = ? AND t.from_location_id IN ($locInClause) AND t.status IN ('in_transit', 'received')
        GROUP BY t.from_location_id
    ", [$dateFrom, $dateFrom, $dateTo, $today, $productId]);

    // Consumption
    $consumption = $fetchByLocation('consumption', "
        SELECT cv.location_id AS location_id,
               SUM(CASE WHEN cv.voucher_date < ? THEN cvd.quantity ELSE 0 END) AS before_range,
               SUM(CASE WHEN cv.voucher_date BETWEEN ? AND ? THEN cvd.quantity ELSE 0 END) AS period,
               SUM(CASE WHEN cv.voucher_date <= ? THEN cvd.quantity ELSE 0 END) AS current_till
        FROM consumption_voucher_details cvd
        JOIN consumption_vouchers cv ON cvd.voucher_id = cv.id
        WHERE cvd.product_id = ? AND cv.location_id IN ($locInClause)
        GROUP BY cv.location_id
    ", [$dateFrom, $dateFrom, $dateTo, $today, $productId]);

    // Weighted avg cost — product-wide (no per-location cost in this system)
    $__dbg('query START: avg_rate_closing');
    $t0 = microtime(true);
    $avgStmt = $conn->prepare("
        SELECT COALESCE(SUM(quantity_in * rate_per_unit) / NULLIF(SUM(quantity_in), 0), 0) AS rate
        FROM stock_batches
        WHERE product_id = ? AND location_id IN ($locInClause) AND batch_date <= ?
    ");
    $avgStmt->execute([$productId, $dateTo]);
    $avgRateClosing = (float)($avgStmt->fetch()['rate'] ?? 0);
    $avgStmt->closeCursor();
    $__dbg('query DONE : avg_rate_closing (' . number_format((microtime(true) - $t0) * 1000, 1) . 'ms)');
    $__dbg('query START: avg_rate_current');
    $t0 = microtime(true);
    $avgStmt->execute([$productId, $today]);
    $avgRateCurrent = (float)($avgStmt->fetch()['rate'] ?? 0);
    $avgStmt->closeCursor();
    $__dbg('query DONE : avg_rate_current (' . number_format((microtime(true) - $t0) * 1000, 1) . 'ms)');

    // Location details (for names + tree depth)
    $__dbg('query START: locations');
    $t0 = microtime(true);
    $locStmt = $conn->prepare("
        SELECT id, name, parent_id
        FROM locations
        WHERE id IN ($locInClause)
    ");
    $locStmt->execute();
    $locs = $locStmt->fetchAll();
    $locStmt->closeCursor();
    $__dbg('query DONE : locations (' . count($locs) . ' rows, ' . number_format((microtime(true) - $t0) * 1000, 1) . 'ms)');

    // Compute depth for indentation relative to the selected parent
    $byId = [];
    foreach ($locs as $l) { $byId[(int)$l['id']] = $l; }
    $depthCache = [];
    $computeDepth = function ($lid) use (&$depthCache, &$computeDepth, $byId, $locationFilter) {
        if (isset($depthCache[$lid])) return $depthCache[$lid];
        if ((int)$lid === (int)$locationFilter) return $depthCache[$lid] = 0;
        if (!isset($byId[$lid])) return $depthCache[$lid] = 0;
        $pid = (int)($byId[$lid]['parent_id'] ?? 0);
        if (!$pid || !isset($byId[$pid])) return $depthCache[$lid] = 1;
        return $depthCache[$lid] = 1 + $computeDepth($pid);
    };

    // Sort: selected parent first, then by depth, then name
    usort($locs, function ($a, $b) use ($computeDepth, $locationFilter) {
        $aIsRoot = ((int)$a['id'] === (int)$locationFilter) ? 0 : 1;
        $bIsRoot = ((int)$b['id'] === (int)$locationFilter) ? 0 : 1;
        if ($aIsRoot !== $bIsRoot) return $aIsRoot - $bIsRoot;
        $da = $computeDepth((int)$a['id']);
        $db = $computeDepth((int)$b['id']);
        if ($da !== $db) return $da - $db;
        return strcmp($a['name'], $b['name']);
    });

    $__dbg('starting row composition for ' . count($locs) . ' locations');
    // Compose rows from the maps
    $m = function ($map, $lid, $key) { return $map[$lid][$key] ?? 0; };
    foreach ($locs as $loc) {
        $lid = (int)$loc['id'];
        $openInR = $m($opening, $lid, 'in_range');
        $openBef = $m($opening, $lid, 'before_range');
        $purBef  = $m($purchasesRegular, $lid, 'before_range') + $m($purchasesImports, $lid, 'before_range');
        $salBef  = $m($sales, $lid, 'before_range');
        $adjBef  = $m($adjustments, $lid, 'before_range');
        $tInBef  = $m($transferIn, $lid, 'before_range');
        $tOutBef = $m($transferOut, $lid, 'before_range');
        $consBef = $m($consumption, $lid, 'before_range');

        $perPur  = $m($purchasesRegular, $lid, 'period') + $m($purchasesImports, $lid, 'period');
        $perSal  = $m($sales, $lid, 'period');
        $perAdj  = $m($adjustments, $lid, 'period');
        $perTIn  = $m($transferIn, $lid, 'period');
        $perTOut = $m($transferOut, $lid, 'period');
        $perCons = $m($consumption, $lid, 'period');

        $curOpen  = $m($opening, $lid, 'current_till');
        $curPur   = $m($purchasesRegular, $lid, 'current_till') + $m($purchasesImports, $lid, 'current_till');
        $curSal   = $m($sales, $lid, 'current_till');
        $curAdj   = $m($adjustments, $lid, 'current_till');
        $curTIn   = $m($transferIn, $lid, 'current_till');
        $curTOut  = $m($transferOut, $lid, 'current_till');
        $curCons  = $m($consumption, $lid, 'current_till');

        $rowOpening = $openInR + $openBef + $purBef - $salBef + $adjBef + $tInBef - $tOutBef - $consBef;
        $closing = $rowOpening + $perPur - $perSal + $perAdj + $perTIn - $perTOut - $perCons;
        $currentStock = $curOpen + $curPur - $curSal + $curAdj + $curTIn - $curTOut - $curCons;

        $breakdownRows[] = [
            'location_id'    => $lid,
            'location_name'  => $loc['name'],
            'depth'          => $computeDepth($lid),
            'opening'        => $rowOpening,
            'purchases'      => $perPur,
            'sales'          => $perSal,
            'adjustment'     => $perAdj,
            'transfer_in'    => $perTIn,
            'transfer_out'   => $perTOut,
            'consumption'    => $perCons,
            'closing'        => $closing,
            'closing_value'  => $closing * $avgRateClosing,
            'current_stock'  => $currentStock,
            'current_value'  => $currentStock * $avgRateCurrent,
        ];
    }
    $__dbg('row composition done, ' . count($breakdownRows) . ' rows built');
}
$__dbg('---- REACHED RENDER ----');
?>
<!-- BREAKDOWN_DEBUG
<?php echo htmlspecialchars(implode("\n", $__dbgLog)); ?>
-->


<div class="row mb-3">
    <div class="col-md-12 d-flex justify-content-between align-items-center">
        <h2><i class="bi bi-geo-alt"></i> <?php echo __('product_location_breakdown_title', 'Product Location Breakdown'); ?></h2>
        <a href="stock_report.php?location=<?php echo htmlspecialchars($locationFilter); ?>&category=<?php echo htmlspecialchars($categoryFilter); ?>&date_from=<?php echo htmlspecialchars($dateFrom); ?>&date_to=<?php echo htmlspecialchars($dateTo); ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> <?php echo __('stock_report_title'); ?>
        </a>
    </div>
</div>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="">
            <input type="hidden" name="product" value="<?php echo (int)$productId; ?>">
            <input type="hidden" name="category" value="<?php echo htmlspecialchars($categoryFilter); ?>">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label required"><?php echo __('location'); ?></label>
                    <select name="location" class="form-select" required>
                        <option value=""><?php echo __('select_location'); ?></option>
                        <?= renderTreeOptions($userLocations, $locationFilter) ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?php echo __('date_from'); ?></label>
                    <input type="date" name="date_from" class="form-control"
                           value="<?php echo $dateFrom; ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?php echo __('date_to'); ?></label>
                    <input type="date" name="date_to" class="form-control"
                           value="<?php echo $dateTo; ?>" required>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-search"></i> <?php echo __('generate'); ?>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php if ($product && !empty($breakdownRows)): ?>
<!-- Product info -->
<div class="card mb-3">
    <div class="card-body">
        <div class="row">
            <div class="col-md-5">
                <h5 class="mb-1"><?php echo htmlspecialchars($product['name']); ?></h5>
                <div class="text-muted small">
                    <?php echo __('category'); ?>: <?php echo htmlspecialchars($product['category_name'] ?? '-'); ?>
                    &nbsp;|&nbsp; <?php echo __('unit'); ?>: <?php echo htmlspecialchars($product['unit']); ?>
                </div>
            </div>
            <div class="col-md-7 text-md-end">
                <div class="small">
                    <strong><?php echo __('avg_rate'); ?>:</strong> <?php echo formatNumber($avgRateClosing, 2); ?>
                    &nbsp;|&nbsp;
                    <strong><?php echo sprintf(__('stock_report_period'), formatDate($dateFrom), formatDate($dateTo)); ?></strong>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Breakdown Table -->
<div class="card">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">
                <?php echo sprintf(__('location_breakdown_for', 'Location Breakdown for: %s'), htmlspecialchars($product['name'])); ?>
            </h5>
            <button onclick="exportTableToCSV('breakdownTable', 'location_breakdown.csv')" class="btn btn-success">
                <i class="bi bi-file-earmark-excel"></i> <?php echo __('export'); ?>
            </button>
        </div>

        <style>
            .breakdown-scroll {
                max-height: calc(100vh - 320px);
                overflow-y: auto;
            }
            .breakdown-scroll thead th {
                position: sticky;
                z-index: 2;
            }
            .breakdown-scroll thead tr:first-child th { top: 0; }
            .breakdown-scroll thead tr:nth-child(2) th { top: 38px; }
        </style>
        <div class="table-responsive breakdown-scroll">
            <table class="table table-bordered table-sm" id="breakdownTable">
                <thead class="table-light">
                    <tr>
                        <th rowspan="2">#</th>
                        <th rowspan="2"><?php echo __('location'); ?></th>
                        <th rowspan="2"><?php echo __('opening'); ?></th>
                        <th colspan="6" class="text-center"><?php echo __('period_movements'); ?></th>
                        <th rowspan="2"><?php echo __('closing_stock'); ?></th>
                        <th rowspan="2"><?php echo __('total_value'); ?></th>
                        <th rowspan="2"><?php echo __('current_stock'); ?></th>
                        <th rowspan="2"><?php echo __('total_value'); ?></th>
                    </tr>
                    <tr>
                        <th><?php echo __('purchases'); ?></th>
                        <th><?php echo __('sales'); ?></th>
                        <th><?php echo __('adjustment'); ?></th>
                        <th><?php echo __('transfer_in'); ?></th>
                        <th><?php echo __('transfer_out'); ?></th>
                        <th><?php echo __('consumption_voucher'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $rowNum = 0;
                    $tOpening = $tPur = $tSal = $tAdj = $tTIn = $tTOut = $tCons = 0;
                    $tClosing = $tClosingVal = $tCurrent = $tCurrentVal = 0;
                    foreach ($breakdownRows as $r):
                        $rowNum++;
                        $hasAny = ($r['opening'] != 0 || $r['purchases'] != 0 || $r['sales'] != 0
                                || $r['adjustment'] != 0 || $r['transfer_in'] != 0 || $r['transfer_out'] != 0
                                || $r['consumption'] != 0 || $r['current_stock'] != 0);
                        $tOpening    += $r['opening'];
                        $tPur        += $r['purchases'];
                        $tSal        += $r['sales'];
                        $tAdj        += $r['adjustment'];
                        $tTIn        += $r['transfer_in'];
                        $tTOut       += $r['transfer_out'];
                        $tCons       += $r['consumption'];
                        $tClosing    += $r['closing'];
                        $tClosingVal += $r['closing_value'];
                        $tCurrent    += $r['current_stock'];
                        $tCurrentVal += $r['current_value'];
                        $indent = str_repeat('— ', (int)$r['depth']);
                        $rowClass = $hasAny ? '' : 'text-muted';
                    ?>
                    <tr class="<?php echo $rowClass; ?>">
                        <td><?php echo $rowNum; ?></td>
                        <td><?php echo $indent . htmlspecialchars($r['location_name']); ?></td>
                        <td class="text-end"><?php echo formatNumber($r['opening'], 3); ?></td>
                        <td class="text-end text-success"><?php echo $r['purchases'] > 0 ? formatNumber($r['purchases'], 3) : '-'; ?></td>
                        <td class="text-end text-danger"><?php echo $r['sales'] > 0 ? formatNumber($r['sales'], 3) : '-'; ?></td>
                        <td class="text-end <?php echo $r['adjustment'] > 0 ? 'text-info' : ($r['adjustment'] < 0 ? 'text-warning' : ''); ?>">
                            <?php
                                if ($r['adjustment'] > 0)      echo '+' . formatNumber($r['adjustment'], 3);
                                elseif ($r['adjustment'] < 0)  echo formatNumber($r['adjustment'], 3);
                                else                           echo '-';
                            ?>
                        </td>
                        <td class="text-end text-primary"><?php echo $r['transfer_in'] > 0 ? formatNumber($r['transfer_in'], 3) : '-'; ?></td>
                        <td class="text-end text-secondary"><?php echo $r['transfer_out'] > 0 ? formatNumber($r['transfer_out'], 3) : '-'; ?></td>
                        <td class="text-end text-danger"><?php echo $r['consumption'] > 0 ? formatNumber($r['consumption'], 3) : '-'; ?></td>
                        <td class="text-end"><strong><?php echo formatNumber($r['closing'], 3); ?></strong></td>
                        <td class="text-end"><strong><?php echo formatNumber($r['closing_value'], 2); ?></strong></td>
                        <td class="text-end"><strong><?php echo formatNumber($r['current_stock'], 3); ?></strong></td>
                        <td class="text-end"><strong><?php echo formatNumber($r['current_value'], 2); ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light">
                    <tr>
                        <th colspan="2" class="text-end"><?php echo __('total'); ?>:</th>
                        <th class="text-end"><?php echo formatNumber($tOpening, 3); ?></th>
                        <th class="text-end text-success"><?php echo formatNumber($tPur, 3); ?></th>
                        <th class="text-end text-danger"><?php echo formatNumber($tSal, 3); ?></th>
                        <th class="text-end <?php echo $tAdj > 0 ? 'text-info' : ($tAdj < 0 ? 'text-warning' : ''); ?>">
                            <?php echo $tAdj > 0 ? '+' : ''; ?><?php echo formatNumber($tAdj, 3); ?>
                        </th>
                        <th class="text-end text-primary"><?php echo formatNumber($tTIn, 3); ?></th>
                        <th class="text-end text-secondary"><?php echo formatNumber($tTOut, 3); ?></th>
                        <th class="text-end text-danger"><?php echo formatNumber($tCons, 3); ?></th>
                        <th class="text-end"><?php echo formatNumber($tClosing, 3); ?></th>
                        <th class="text-end"><?php echo formatNumber($tClosingVal, 2); ?></th>
                        <th class="text-end"><?php echo formatNumber($tCurrent, 3); ?></th>
                        <th class="text-end"><?php echo formatNumber($tCurrentVal, 2); ?></th>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="mt-3">
            <small class="text-muted">
                <i class="bi bi-info-circle"></i>
                <?php echo sprintf(__('stock_report_note'), formatDate($dateTo), formatDate($today)); ?>
            </small>
        </div>
    </div>
</div>
<?php elseif ($product): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle"></i> <?php echo __('no_location_data', 'No location data found for this product.'); ?>
    </div>
<?php else: ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle"></i> <?php echo __('select_location_generate'); ?>
    </div>
<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>
