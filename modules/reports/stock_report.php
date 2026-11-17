<?php
require_once '../../config/database.php';
require_once '../../includes/functions.php';
$pageTitle = __('stock_report_title');
if (!canViewReport('stock_report')) {
    setAlert(__('no_permission'), 'danger');
    redirect('');
}

require_once '../../includes/header.php';

// Get filters
$locationFilter = $_GET['location'] ?? '';
$categoryFilter = $_GET['category'] ?? '';
$productFilter = $_GET['product'] ?? '';
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$today = date('Y-m-d'); // For current stock calculation

// Get user locations
$userLocations = getUserLocations();

// Get categories (include parent_id for tree rendering)
$categoriesStmt = $conn->query("SELECT id, parent_id, name FROM product_categories WHERE is_active = 1 ORDER BY name");
$categories = $categoriesStmt->fetchAll();

// Get products for the filter dropdown (filtered by selected category if any)
$productCatCondition = '';
if ($categoryFilter) {
    $productCatIds = getDescendantCategoryIds($categoryFilter, $conn);
    $productCatInClause = implode(',', array_map('intval', $productCatIds));
    $productCatCondition = "AND p.category_id IN ($productCatInClause)";
}
$productsStmt = $conn->query("
    SELECT p.id, p.name, pc.name as category_name
    FROM products p
    LEFT JOIN product_categories pc ON p.category_id = pc.id
    WHERE p.is_active = 1 $productCatCondition
    ORDER BY p.name
");
$products = $productsStmt->fetchAll();

// Get stock data
$stockData = [];

if ($locationFilter) {
    // Get all descendant location IDs for aggregation
    $locationIds = getDescendantLocationIds($locationFilter, $conn);
    $locInClause = implode(',', array_map('intval', $locationIds));
    $hasDescendants = count($locationIds) > 1;

    // Build category filter for SQL (aggregates parent + all descendant categories)
    $categoryCondition = '';
    $categoryParam = [];
    if ($categoryFilter) {
        $categoryIds = getDescendantCategoryIds($categoryFilter, $conn);
        $catInClause = implode(',', array_map('intval', $categoryIds));
        $categoryCondition = "AND p.category_id IN ($catInClause)";
    }

    // Build product filter for SQL (single product when selected)
    $productCondition = '';
    if ($productFilter) {
        $productCondition = "AND p.id = " . intval($productFilter);
    }

    // Location(s) selected — aggregates parent + all descendants
    $stmt = $conn->prepare("
        SELECT 
            p.id,
            p.name as product_name,
            p.unit,
            pc.name as category_name,
            
            -- OPENING CALCULATION
            -- Opening stock entries within the date range
            COALESCE((
                SELECT SUM(os.quantity) 
                FROM opening_stocks os 
                WHERE os.product_id = p.id 
                  AND os.location_id IN ($locInClause)
                  AND os.stock_date BETWEEN ? AND ?
            ), 0) as opening_in_range,
            
            -- Opening stock entries before the from_date
            COALESCE((
                SELECT SUM(os.quantity) 
                FROM opening_stocks os 
                WHERE os.product_id = p.id 
                  AND os.location_id IN ($locInClause)
                  AND os.stock_date < ?
            ), 0) as opening_before_range,
            
            -- Purchases before the from_date
            COALESCE((
                SELECT SUM(pd.quantity)
                FROM purchase_details pd
                JOIN purchases pu ON pd.purchase_id = pu.id
                WHERE pd.product_id = p.id
                  AND pu.location_id IN ($locInClause)
                  AND pu.purchase_date < ?
            ), 0) + COALESCE((
                SELECT SUM(pid.quantity)
                FROM purchase_import_details pid
                JOIN purchase_imports pi ON pid.import_id = pi.id
                WHERE pid.product_id = p.id
                  AND pi.location_id IN ($locInClause)
                  AND pi.import_date < ?
            ), 0) as purchases_before_range,
            
            -- Sales before the from_date
            COALESCE((
                SELECT SUM(sd.quantity)
                FROM sale_details sd
                JOIN sales s ON sd.sale_id = s.id
                WHERE sd.product_id = p.id
                  AND s.location_id IN ($locInClause)
                  AND s.sale_date < ?
            ), 0) as sales_before_range,
            
            -- PERIOD PURCHASES (within date range)
            COALESCE((
                SELECT SUM(pd.quantity)
                FROM purchase_details pd
                JOIN purchases pu ON pd.purchase_id = pu.id
                WHERE pd.product_id = p.id
                  AND pu.location_id IN ($locInClause)
                  AND pu.purchase_date BETWEEN ? AND ?
            ), 0) + COALESCE((
                SELECT SUM(pid.quantity)
                FROM purchase_import_details pid
                JOIN purchase_imports pi ON pid.import_id = pi.id
                WHERE pid.product_id = p.id
                  AND pi.location_id IN ($locInClause)
                  AND pi.import_date BETWEEN ? AND ?
            ), 0) as period_purchases,
            
            -- PERIOD SALES (within date range)
            COALESCE((
                SELECT SUM(sd.quantity)
                FROM sale_details sd
                JOIN sales s ON sd.sale_id = s.id
                WHERE sd.product_id = p.id
                  AND s.location_id IN ($locInClause)
                  AND s.sale_date BETWEEN ? AND ?
            ), 0) as period_sales,
            
            -- CURRENT STOCK CALCULATION (all transactions till today)
            -- All opening stock entries till today
            COALESCE((
                SELECT SUM(os.quantity) 
                FROM opening_stocks os 
                WHERE os.product_id = p.id 
                  AND os.location_id IN ($locInClause)
                  AND os.stock_date <= ?
            ), 0) as current_opening_entries,
            
            -- All purchases till today
            COALESCE((
                SELECT SUM(pd.quantity)
                FROM purchase_details pd
                JOIN purchases pu ON pd.purchase_id = pu.id
                WHERE pd.product_id = p.id
                  AND pu.location_id IN ($locInClause)
                  AND pu.purchase_date <= ?
            ), 0) + COALESCE((
                SELECT SUM(pid.quantity)
                FROM purchase_import_details pid
                JOIN purchase_imports pi ON pid.import_id = pi.id
                WHERE pid.product_id = p.id
                  AND pi.location_id IN ($locInClause)
                  AND pi.import_date <= ?
            ), 0) as current_purchases,
            
            -- All sales till today
            COALESCE((
                SELECT SUM(sd.quantity)
                FROM sale_details sd
                JOIN sales s ON sd.sale_id = s.id
                WHERE sd.product_id = p.id
                  AND s.location_id IN ($locInClause)
                  AND s.sale_date <= ?
            ), 0) as current_sales,
            
            -- ADJUSTMENTS BEFORE DATE RANGE
            COALESCE((
                SELECT SUM(CASE WHEN sad.adjustment_type = 'increase' THEN sad.quantity ELSE -sad.quantity END)
                FROM stock_adjustment_details sad
                JOIN stock_adjustments sa ON sad.adjustment_id = sa.id
                WHERE sad.product_id = p.id
                  AND sa.location_id IN ($locInClause)
                  AND sa.adjustment_date < ?
            ), 0) as adjustments_before_range,
            
            -- PERIOD ADJUSTMENTS (within date range)
            COALESCE((
                SELECT SUM(CASE WHEN sad.adjustment_type = 'increase' THEN sad.quantity ELSE -sad.quantity END)
                FROM stock_adjustment_details sad
                JOIN stock_adjustments sa ON sad.adjustment_id = sa.id
                WHERE sad.product_id = p.id
                  AND sa.location_id IN ($locInClause)
                  AND sa.adjustment_date BETWEEN ? AND ?
            ), 0) as period_adjustments,
            
            -- ALL ADJUSTMENTS TILL TODAY
            COALESCE((
                SELECT SUM(CASE WHEN sad.adjustment_type = 'increase' THEN sad.quantity ELSE -sad.quantity END)
                FROM stock_adjustment_details sad
                JOIN stock_adjustments sa ON sad.adjustment_id = sa.id
                WHERE sad.product_id = p.id
                  AND sa.location_id IN ($locInClause)
                  AND sa.adjustment_date <= ?
            ), 0) as current_adjustments,
            
            -- TRANSFERS BEFORE DATE RANGE
            -- Transfer In (received) before date range
            COALESCE((
                SELECT SUM(td.received_quantity)
                FROM stock_transfer_details td
                JOIN stock_transfers t ON td.transfer_id = t.id
                WHERE td.product_id = p.id
                  AND t.to_location_id IN ($locInClause)
                  AND t.status = 'received'
                  AND DATE(t.received_at) < ?
            ), 0) as transfer_in_before_range,
            
            -- Transfer Out (dispatched) before date range
            COALESCE((
                SELECT SUM(td.dispatched_quantity)
                FROM stock_transfer_details td
                JOIN stock_transfers t ON td.transfer_id = t.id
                WHERE td.product_id = p.id
                  AND t.from_location_id IN ($locInClause)
                  AND t.status IN ('in_transit', 'received')
                  AND DATE(t.dispatched_at) < ?
            ), 0) as transfer_out_before_range,
            
            -- PERIOD TRANSFERS (within date range)
            -- Transfer In during period
            COALESCE((
                SELECT SUM(td.received_quantity)
                FROM stock_transfer_details td
                JOIN stock_transfers t ON td.transfer_id = t.id
                WHERE td.product_id = p.id
                  AND t.to_location_id IN ($locInClause)
                  AND t.status = 'received'
                  AND DATE(t.received_at) BETWEEN ? AND ?
            ), 0) as period_transfer_in,
            
            -- Transfer Out during period
            COALESCE((
                SELECT SUM(td.dispatched_quantity)
                FROM stock_transfer_details td
                JOIN stock_transfers t ON td.transfer_id = t.id
                WHERE td.product_id = p.id
                  AND t.from_location_id IN ($locInClause)
                  AND t.status IN ('in_transit', 'received')
                  AND DATE(t.dispatched_at) BETWEEN ? AND ?
            ), 0) as period_transfer_out,
            
            -- ALL TRANSFERS TILL TODAY
            -- Transfer In till today
            COALESCE((
                SELECT SUM(td.received_quantity)
                FROM stock_transfer_details td
                JOIN stock_transfers t ON td.transfer_id = t.id
                WHERE td.product_id = p.id
                  AND t.to_location_id IN ($locInClause)
                  AND t.status = 'received'
                  AND DATE(t.received_at) <= ?
            ), 0) as current_transfer_in,
            
            -- Transfer Out till today
            COALESCE((
                SELECT SUM(td.dispatched_quantity)
                FROM stock_transfer_details td
                JOIN stock_transfers t ON td.transfer_id = t.id
                WHERE td.product_id = p.id
                  AND t.from_location_id IN ($locInClause)
                  AND t.status IN ('in_transit', 'received')
                  AND DATE(t.dispatched_at) <= ?
            ), 0) as current_transfer_out,

            -- CONSUMPTION BEFORE DATE RANGE
            COALESCE((
                SELECT SUM(cvd.quantity)
                FROM consumption_voucher_details cvd
                JOIN consumption_vouchers cv ON cvd.voucher_id = cv.id
                WHERE cvd.product_id = p.id
                  AND cv.location_id IN ($locInClause)
                  AND cv.voucher_date < ?
            ), 0) as consumption_before_range,

            -- PERIOD CONSUMPTION (within date range)
            COALESCE((
                SELECT SUM(cvd.quantity)
                FROM consumption_voucher_details cvd
                JOIN consumption_vouchers cv ON cvd.voucher_id = cv.id
                WHERE cvd.product_id = p.id
                  AND cv.location_id IN ($locInClause)
                  AND cv.voucher_date BETWEEN ? AND ?
            ), 0) as period_consumption,

            -- ALL CONSUMPTION TILL TODAY
            COALESCE((
                SELECT SUM(cvd.quantity)
                FROM consumption_voucher_details cvd
                JOIN consumption_vouchers cv ON cvd.voucher_id = cv.id
                WHERE cvd.product_id = p.id
                  AND cv.location_id IN ($locInClause)
                  AND cv.voucher_date <= ?
            ), 0) as current_consumption,

            -- WEIGHTED AVERAGE COST RATE as of dateTo (for closing value)
            COALESCE((
                SELECT SUM(sb.quantity_in * sb.rate_per_unit) / NULLIF(SUM(sb.quantity_in), 0)
                FROM stock_batches sb
                WHERE sb.product_id = p.id
                  AND sb.location_id IN ($locInClause)
                  AND sb.batch_date <= ?
            ), 0) as avg_rate_closing,

            -- WEIGHTED AVERAGE COST RATE as of today (for current value)
            COALESCE((
                SELECT SUM(sb.quantity_in * sb.rate_per_unit) / NULLIF(SUM(sb.quantity_in), 0)
                FROM stock_batches sb
                WHERE sb.product_id = p.id
                  AND sb.location_id IN ($locInClause)
                  AND sb.batch_date <= ?
            ), 0) as avg_rate_current

        FROM products p
        LEFT JOIN product_categories pc ON p.category_id = pc.id
        WHERE p.is_active = 1 $categoryCondition $productCondition
        ORDER BY pc.name, p.name
    ");
    
    // Execute with parameters (location IDs are embedded via $locInClause, only dates remain)
    $params = array_merge([
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
        $today               // avg_rate_current (weighted avg as of today)
    ], $categoryParam);
    
    $stmt->execute($params);
    $stockData = $stmt->fetchAll();
}
?>

<div class="row mb-3">
    <div class="col-md-12">
        <h2><i class="bi bi-graph-up"></i> <?php echo __('stock_report_title'); ?></h2>
    </div>
</div>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label required"><?php echo __('location'); ?></label>
                    <select name="location" class="form-select select2" required>
                        <option value=""><?php echo __('select_location'); ?></option>
                        <?= renderTreeOptions($userLocations, $locationFilter) ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?php echo __('category'); ?></label>
                    <select name="category" class="form-select select2">
                        <option value=""><?php echo __('all_categories'); ?></option>
                        <?php echo renderTreeOptions($categories, $categoryFilter); ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?php echo __('product'); ?></label>
                    <select name="product" class="form-select select2">
                        <option value=""><?php echo __('all_products', 'All Products'); ?></option>
                        <?php foreach ($products as $prod): ?>
                        <option value="<?php echo $prod['id']; ?>"
                                <?php echo $productFilter == $prod['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($prod['name']) . ' (' . htmlspecialchars($prod['category_name']) . ')'; ?>
                        </option>
                        <?php endforeach; ?>
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
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-search"></i> <?php echo __('generate'); ?>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php if (!empty($stockData)): ?>
<!-- Stock Report Table -->
<div class="card">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">
                <?php echo sprintf(__('stock_report_period'), formatDate($dateFrom), formatDate($dateTo)); ?>
            </h5>
            <button id="genExcelBtn" type="button" class="btn btn-success" onclick="generateStockExcel(this)">
                <i class="bi bi-file-earmark-excel"></i> <?php echo __('generate_excel', 'Generate Excel'); ?>
            </button>
        </div>
        
        <style>
            .stock-report-scroll {
                max-height: calc(100vh - 220px);
                overflow-y: auto;
            }
            .stock-report-scroll thead th {
                position: sticky;
                z-index: 2;
            }
            .stock-report-scroll thead tr:first-child th { top: 0; }
            .stock-report-scroll thead tr:nth-child(2) th { top: 38px; }
        </style>
        <div class="table-responsive stock-report-scroll">
            <table class="table table-bordered table-sm" id="stockTable">
                <thead class="table-light">
                    <tr>
                        <th rowspan="2">#</th>
                        <th rowspan="2"><?php echo __('product'); ?></th>
                        <th rowspan="2"><?php echo __('category'); ?></th>
                        <th rowspan="2"><?php echo __('unit'); ?></th>
                        <th rowspan="2"><?php echo __('avg_rate'); ?></th>
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
                    $currentCategory = '';
                    $totalOpening = 0;
                    $totalPurchases = 0;
                    $totalSales = 0;
                    $totalAdjustments = 0;
                    $totalTransferIn = 0;
                    $totalTransferOut = 0;
                    $totalConsumption = 0;
                    $totalClosing = 0;
                    $totalClosingValue = 0;
                    $totalCurrent = 0;
                    $totalCurrentValue = 0;
                    
                    foreach ($stockData as $item):
                        $rowNum++;
                        
                        // OPENING = Opening entries in range + Opening before + Purchases before - Sales before + Adjustments before + Transfer In before - Transfer Out before - Consumption before
                        $opening = $item['opening_in_range']
                                 + $item['opening_before_range']
                                 + $item['purchases_before_range']
                                 - $item['sales_before_range']
                                 + $item['adjustments_before_range']
                                 + $item['transfer_in_before_range']
                                 - $item['transfer_out_before_range']
                                 - $item['consumption_before_range'];

                        // Period movements
                        $periodAdjustment = $item['period_adjustments'];
                        $periodTransferIn = $item['period_transfer_in'];
                        $periodTransferOut = $item['period_transfer_out'];
                        $periodConsumption = $item['period_consumption'];

                        // CLOSING = Opening + Period Purchases - Period Sales + Period Adjustments + Period Transfer In - Period Transfer Out - Period Consumption
                        $closing = $opening + $item['period_purchases'] - $item['period_sales'] + $periodAdjustment + $periodTransferIn - $periodTransferOut - $periodConsumption;

                        // CURRENT STOCK = All transactions till today
                        $currentStock = $item['current_opening_entries']
                                      + $item['current_purchases']
                                      - $item['current_sales']
                                      + $item['current_adjustments']
                                      + $item['current_transfer_in']
                                      - $item['current_transfer_out']
                                      - $item['current_consumption'];

                        // STOCK VALUATION (weighted average cost)
                        $avgRateClosing = (float)$item['avg_rate_closing'];
                        $avgRateCurrent = (float)$item['avg_rate_current'];
                        $closingValue = $closing * $avgRateClosing;
                        $currentValue = $currentStock * $avgRateCurrent;

                        // Add to totals (only show rows with any movement)
                        $hasMovement = $opening != 0 || $item['period_purchases'] != 0 || $item['period_sales'] != 0
                                    || $periodAdjustment != 0 || $periodTransferIn != 0 || $periodTransferOut != 0 || $periodConsumption != 0 || $currentStock != 0;
                        if ($hasMovement) {
                            $totalOpening += $opening;
                            $totalPurchases += $item['period_purchases'];
                            $totalSales += $item['period_sales'];
                            $totalAdjustments += $periodAdjustment;
                            $totalTransferIn += $periodTransferIn;
                            $totalTransferOut += $periodTransferOut;
                            $totalConsumption += $periodConsumption;
                            $totalClosing += $closing;
                            $totalClosingValue += $closingValue;
                            $totalCurrent += $currentStock;
                            $totalCurrentValue += $currentValue;
                            
                            // Category header
                            if ($currentCategory != $item['category_name']):
                                $currentCategory = $item['category_name'];
                    ?>
                    <tr class="table-secondary">
                        <td colspan="16"><strong><?php echo htmlspecialchars($currentCategory); ?></strong></td>
                    </tr>
                    <?php endif; ?>
                    
                    <tr>
                        <td><?php echo $rowNum; ?></td>
                        <td>
                            <strong><?php echo htmlspecialchars($item['product_name']); ?></strong>
                            <a href="product_stock_card.php?location=<?php echo $locationFilter; ?>&product=<?php echo $item['id']; ?>&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>&category=<?php echo $categoryFilter; ?>"
                               class="text-decoration-none ms-2"
                               title="<?php echo __('view_stock_card'); ?>">
                                <i class="bi bi-card-list text-primary"></i>
                            </a>
                            <?php if (canViewReport('stock_value')): ?>
                            <a href="stock_value_by_product_locations.php?product_id=<?php echo $item['id']; ?>"
                               class="text-decoration-none ms-1"
                               target="_blank" rel="noopener"
                               title="<?php echo __('view_stock_by_location', 'View Stock by Location'); ?>">
                                <i class="bi bi-buildings text-primary"></i>
                            </a>
                            <?php endif; ?>
                            <?php if (!empty($hasDescendants)): ?>
                            <a href="product_location_breakdown.php?location=<?php echo $locationFilter; ?>&product=<?php echo $item['id']; ?>&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>&category=<?php echo $categoryFilter; ?>"
                               class="text-decoration-none ms-1"
                               target="_blank" rel="noopener"
                               title="<?php echo __('view_location_breakdown', 'View Location Breakdown'); ?>">
                                <i class="bi bi-geo-alt text-primary"></i>
                            </a>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($item['category_name']); ?></td>
                        <td><?php echo htmlspecialchars($item['unit']); ?></td>
                        <td class="text-end"><?php echo $avgRateClosing > 0 ? formatNumber($avgRateClosing, 2) : '-'; ?></td>
                        <td class="text-end"><?php echo formatNumber($opening, 3); ?></td>
                        <td class="text-end text-success">
                            <?php echo $item['period_purchases'] > 0 ? formatNumber($item['period_purchases'], 3) : '-'; ?>
                        </td>
                        <td class="text-end text-danger">
                            <?php echo $item['period_sales'] > 0 ? formatNumber($item['period_sales'], 3) : '-'; ?>
                        </td>
                        <td class="text-end <?php echo $periodAdjustment > 0 ? 'text-info' : ($periodAdjustment < 0 ? 'text-warning' : ''); ?>">
                            <?php 
                            if ($periodAdjustment > 0) {
                                echo '+' . formatNumber($periodAdjustment, 3);
                            } elseif ($periodAdjustment < 0) {
                                echo formatNumber($periodAdjustment, 3);
                            } else {
                                echo '-';
                            }
                            ?>
                        </td>
                        <td class="text-end text-primary">
                            <?php echo $periodTransferIn > 0 ? formatNumber($periodTransferIn, 3) : '-'; ?>
                        </td>
                        <td class="text-end text-secondary">
                            <?php echo $periodTransferOut > 0 ? formatNumber($periodTransferOut, 3) : '-'; ?>
                        </td>
                        <td class="text-end text-danger">
                            <?php echo $periodConsumption > 0 ? formatNumber($periodConsumption, 3) : '-'; ?>
                        </td>
                        <td class="text-end"><strong><?php echo formatNumber($closing, 3); ?></strong></td>
                        <td class="text-end"><strong><?php echo formatNumber($closingValue, 2); ?></strong></td>
                        <td class="text-end">
                            <strong><?php echo formatNumber($currentStock, 3); ?></strong>
                        </td>
                        <td class="text-end"><strong><?php echo formatNumber($currentValue, 2); ?></strong></td>
                    </tr>
                    <?php 
                        }
                    endforeach; 
                    ?>
                </tbody>
                <tfoot class="table-light">
                    <tr>
                        <th colspan="5" class="text-end"><?php echo __('total'); ?>:</th>
                        <th class="text-end"><?php echo formatNumber($totalOpening, 3); ?></th>
                        <th class="text-end text-success"><?php echo formatNumber($totalPurchases, 3); ?></th>
                        <th class="text-end text-danger"><?php echo formatNumber($totalSales, 3); ?></th>
                        <th class="text-end <?php echo $totalAdjustments > 0 ? 'text-info' : ($totalAdjustments < 0 ? 'text-warning' : ''); ?>">
                            <?php echo $totalAdjustments > 0 ? '+' : ''; ?><?php echo formatNumber($totalAdjustments, 3); ?>
                        </th>
                        <th class="text-end text-primary"><?php echo formatNumber($totalTransferIn, 3); ?></th>
                        <th class="text-end text-secondary"><?php echo formatNumber($totalTransferOut, 3); ?></th>
                        <th class="text-end text-danger"><?php echo formatNumber($totalConsumption, 3); ?></th>
                        <th class="text-end"><?php echo formatNumber($totalClosing, 3); ?></th>
                        <th class="text-end"><?php echo formatNumber($totalClosingValue, 2); ?></th>
                        <th class="text-end"><?php echo formatNumber($totalCurrent, 3); ?></th>
                        <th class="text-end"><?php echo formatNumber($totalCurrentValue, 2); ?></th>
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

<?php elseif ($locationFilter): ?>
<div class="alert alert-info">
    <i class="bi bi-info-circle"></i> <?php echo __('no_products_criteria'); ?>
</div>
<?php else: ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle"></i> <?php echo __('select_location_generate'); ?>
</div>
<?php endif; ?>

<script>
async function generateStockExcel(btn) {
    if (btn.dataset.state === 'ready') {
        const a = document.createElement('a');
        a.href = btn.dataset.url;
        a.download = btn.dataset.filename || 'stock_report.csv';
        document.body.appendChild(a);
        a.click();
        a.remove();
        return;
    }

    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Generating...';

    try {
        const params = new URLSearchParams(window.location.search);
        const resp = await fetch('stock_report_export.php?' + params.toString(), { credentials: 'same-origin' });
        if (!resp.ok) throw new Error('HTTP ' + resp.status);

        const blob = await resp.blob();
        const url = URL.createObjectURL(blob);

        let filename = 'stock_report.csv';
        const cd = resp.headers.get('Content-Disposition') || '';
        const m = cd.match(/filename="?([^";]+)"?/i);
        if (m) filename = m[1];

        btn.dataset.state = 'ready';
        btn.dataset.url = url;
        btn.dataset.filename = filename;
        btn.disabled = false;
        btn.classList.remove('btn-success');
        btn.classList.add('btn-primary');
        btn.innerHTML = '<i class="bi bi-download"></i> Download CSV';
    } catch (e) {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
        alert('Failed to generate Excel: ' + e.message);
    }
}

// Initialize Select2 for the filter dropdowns.
// jQuery + Select2 are loaded in the footer (after this inline script), so we
// defer to DOMContentLoaded, by which time those blocking scripts have run.
document.addEventListener('DOMContentLoaded', function() {
    if (window.jQuery && $.fn.select2) {
        $('.select2').each(function() {
            $(this).select2({
                placeholder: $(this).find('option[value=""]').text(),
                allowClear: true,
                width: '100%'
            });
        });
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>
