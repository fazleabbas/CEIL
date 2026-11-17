<?php
require_once '../../config/database.php';
require_once '../../includes/functions.php';
$pageTitle = __('profit_loss_report');
if (!canViewReport('profit_loss')) {
    setAlert(__('no_permission'), 'danger');
    redirect('');
}

require_once '../../includes/header.php';

// Get filters
$locationFilter = $_GET['location'] ?? '';
$costCentreFilter = $_GET['cost_centre'] ?? '';
$dateFrom = $_GET['date_from'] ?? date('Y-m-01'); // First day of month
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$costingMethod = $_GET['costing_method'] ?? 'FIFO';

// Get user locations and cost centres
$userLocations = getUserLocations();
$userCostCentres = getUserCostCentres();

$plData = null;
$topProducts = [];

if ($locationFilter) {
    // Build location condition
    $locationCondition = '';
    $locationParams = [];

    if ($locationFilter == 'all') {
        // Admin viewing all locations
        if (!empty($userLocations)) {
            $locationIds = array_column($userLocations, 'id');
            $locInClause = implode(',', array_map('intval', $locationIds));
            $locationCondition = "location_id IN ($locInClause)";
            $locationParams = [];
        }
    } else {
        // Specific location selected — aggregate parent + all descendants
        $locationIds = getDescendantLocationIds($locationFilter, $conn);
        $locInClause = implode(',', array_map('intval', $locationIds));
        $locationCondition = "location_id IN ($locInClause)";
        $locationParams = [];
    }

    // Build cost centre condition — aggregate parent + all descendants
    $ccCondition = '';
    $ccParams = [];
    if ($costCentreFilter && $costCentreFilter !== 'all') {
        $ccIds = getDescendantCostCentreIds($costCentreFilter, $conn);
        $ccInClause = implode(',', array_map('intval', $ccIds));
        $ccCondition = " AND cost_centre_id IN ($ccInClause)";
    }

    // Sales revenue (converted to base currency)
    $salesQuery = "
        SELECT
            COALESCE(SUM(total_amount * COALESCE(exchange_rate_to_base, 1)), 0) as total_sales
        FROM sales
        WHERE $locationCondition $ccCondition
        AND sale_date BETWEEN ? AND ?
    ";
    $salesStmt = $conn->prepare($salesQuery);
    $salesStmt->execute(array_merge($locationParams, $ccParams, [$dateFrom, $dateTo]));
    $totalSales = $salesStmt->fetchColumn();
    
    // Cost of Goods Sold (COGS) based on costing method
    $cogs = 0;
    
    // Get all sales in period
    $salesDetailsQuery = "
        SELECT
            sd.product_id,
            sd.quantity,
            sd.rate_per_unit as selling_price,
            s.sale_date,
            s.location_id
        FROM sale_details sd
        JOIN sales s ON sd.sale_id = s.id
        WHERE s.$locationCondition $ccCondition
        AND s.sale_date BETWEEN ? AND ?
        ORDER BY s.sale_date ASC, sd.id ASC
    ";
    $salesDetailsStmt = $conn->prepare($salesDetailsQuery);
    $salesDetailsStmt->execute(array_merge($locationParams, $ccParams, [$dateFrom, $dateTo]));
    $salesDetails = $salesDetailsStmt->fetchAll();
    
    foreach ($salesDetails as $sale) {
        $productId = $sale['product_id'];
        $qtySold = $sale['quantity'];
        $saleDate = $sale['sale_date'];
        $saleLocationId = $sale['location_id'];
        
        if (strtoupper($costingMethod) == 'FIFO') {
            // FIFO: Use oldest stock first
            $batchStmt = $conn->prepare("
                SELECT rate_per_unit, quantity_in
                FROM stock_batches
                WHERE location_id = ? 
                AND product_id = ?
                AND batch_date <= ?
                ORDER BY batch_date ASC, id ASC
            ");
            $batchStmt->execute([$saleLocationId, $productId, $saleDate]);
            $batches = $batchStmt->fetchAll();
            
            $remainingQty = $qtySold;
            foreach ($batches as $batch) {
                if ($remainingQty <= 0) break;
                $qtyFromBatch = min($remainingQty, $batch['quantity_in']);
                $cogs += $qtyFromBatch * $batch['rate_per_unit'];
                $remainingQty -= $qtyFromBatch;
            }
            
        } elseif (strtoupper($costingMethod) == 'LIFO') {
            // LIFO: Use newest stock first
            $batchStmt = $conn->prepare("
                SELECT rate_per_unit, quantity_in
                FROM stock_batches
                WHERE location_id = ? 
                AND product_id = ?
                AND batch_date <= ?
                ORDER BY batch_date DESC, id DESC
            ");
            $batchStmt->execute([$saleLocationId, $productId, $saleDate]);
            $batches = $batchStmt->fetchAll();
            
            $remainingQty = $qtySold;
            foreach ($batches as $batch) {
                if ($remainingQty <= 0) break;
                $qtyFromBatch = min($remainingQty, $batch['quantity_in']);
                $cogs += $qtyFromBatch * $batch['rate_per_unit'];
                $remainingQty -= $qtyFromBatch;
            }
            
        } else { // AVERAGE
            // Weighted Average
            $avgStmt = $conn->prepare("
                SELECT 
                    COALESCE(
                        SUM(quantity_in * rate_per_unit) / NULLIF(SUM(quantity_in), 0),
                        0
                    ) as avg_rate
                FROM stock_batches
                WHERE location_id = ? 
                AND product_id = ?
                AND batch_date <= ?
            ");
            $avgStmt->execute([$saleLocationId, $productId, $saleDate]);
            $avgRate = $avgStmt->fetchColumn();
            
            $cogs += $qtySold * $avgRate;
        }
    }
    
    // Gross Profit
    $grossProfit = $totalSales - $cogs;
    $grossMargin = $totalSales > 0 ? ($grossProfit / $totalSales) * 100 : 0;
    
    // Operating Expenses (Payments for expenses in period)
    // Filter by location: specific location shows only that location's expenses
    // "All locations" shows expenses with NULL location (company-wide) + all location-specific expenses
    $expenseParams = [$dateFrom, $dateTo];
    $expenseLocationCondition = '';

    if ($locationFilter == 'all') {
        // Admin viewing all locations: include NULL (company-wide) + all user locations
        if (!empty($locationIds)) {
            $expenseLocInClause = implode(',', array_map('intval', $locationIds));
            $expenseLocationCondition = "AND (p.location_id IS NULL OR p.location_id IN ($expenseLocInClause))";
        } else {
            $expenseLocationCondition = "AND p.location_id IS NULL";
        }
    } else {
        // Specific location selected: show expenses for location + descendants + company-wide (NULL)
        $expenseLocationCondition = "AND (p.location_id IN ($locInClause) OR p.location_id IS NULL)";
    }

    // Cost centre condition for expenses — aggregate descendants
    $expenseCcCondition = '';
    if ($costCentreFilter && $costCentreFilter !== 'all') {
        $expenseCcCondition = "AND p.cost_centre_id IN ($ccInClause)";
    }

    $expensesStmt = $conn->prepare("
        SELECT
            COALESCE(SUM(p.amount * COALESCE(p.exchange_rate_to_base, 1)), 0) as total_expenses
        FROM payments p
        JOIN ledger_accounts la ON p.to_account_id = la.id
        WHERE la.account_type = 'expense'
        AND p.payment_date BETWEEN ? AND ?
        $expenseLocationCondition
        $expenseCcCondition
    ");
    $expensesStmt->execute($expenseParams);
    $totalExpenses = $expensesStmt->fetchColumn();
    
    // Net Profit
    $netProfit = $grossProfit - $totalExpenses;
    $netMargin = $totalSales > 0 ? ($netProfit / $totalSales) * 100 : 0;
    
    $plData = [
        'sales' => $totalSales,
        'cogs' => $cogs,
        'gross_profit' => $grossProfit,
        'gross_margin' => $grossMargin,
        'expenses' => $totalExpenses,
        'net_profit' => $netProfit,
        'net_margin' => $netMargin
    ];
    
    // Get product-wise profitability (converted to base currency)
    $productQuery = "
        SELECT
            p.id,
            p.name as product_name,
            pc.name as category_name,
            COALESCE(SUM(sd.quantity), 0) as qty_sold,
            COALESCE(SUM(sd.amount * COALESCE(s.exchange_rate_to_base, 1)), 0) as sales_amount
        FROM products p
        LEFT JOIN product_categories pc ON p.category_id = pc.id
        LEFT JOIN sale_details sd ON p.id = sd.product_id
        LEFT JOIN sales s ON sd.sale_id = s.id
            AND s.$locationCondition $ccCondition
            AND s.sale_date BETWEEN ? AND ?
        WHERE p.is_active = 1
        GROUP BY p.id, p.name, pc.name
        HAVING qty_sold > 0
        ORDER BY sales_amount DESC
        LIMIT 20
    ";
    $productStmt = $conn->prepare($productQuery);
    $productStmt->execute(array_merge($locationParams, $ccParams, [$dateFrom, $dateTo]));
    $topProducts = $productStmt->fetchAll();
}
?>

<div class="row mb-3">
    <div class="col-12">
        <h2><i class="bi bi-graph-up-arrow"></i> <?php _e('profit_loss_report'); ?></h2>
    </div>
</div>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="">
            <div class="row g-3">
                <div class="col-md-3 col-12">
                    <label class="form-label required"><?php _e('location'); ?></label>
                    <select name="location" class="form-select" required>
                        <option value=""><?php _e('select_location'); ?></option>
                        <?php if (getUserData('role_name') == 'Admin'): ?>
                        <option value="all" <?php echo $locationFilter == 'all' ? 'selected' : ''; ?>>
                            <?php _e('all_locations'); ?>
                        </option>
                        <?php endif; ?>
                        <?= renderTreeOptions($userLocations, $locationFilter) ?>
                    </select>
                </div>
                <?php if (!empty($userCostCentres)): ?>
                <div class="col-md-2 col-12">
                    <label class="form-label"><?php _e('cost_centre'); ?></label>
                    <select name="cost_centre" class="form-select">
                        <option value=""><?php _e('all_cost_centres'); ?></option>
                        <?= renderTreeOptions($userCostCentres, $costCentreFilter, null, 'name', 'code') ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-md-2 col-6">
                    <label class="form-label required"><?php _e('date_from'); ?></label>
                    <input type="date" name="date_from" class="form-control"
                           value="<?php echo $dateFrom; ?>" required>
                </div>
                <div class="col-md-2 col-6">
                    <label class="form-label required"><?php _e('date_to'); ?></label>
                    <input type="date" name="date_to" class="form-control"
                           value="<?php echo $dateTo; ?>" required>
                </div>
                <div class="col-md-2 col-12">
                    <label class="form-label"><?php _e('costing_method'); ?></label>
                    <select name="costing_method" class="form-select">
                        <option value="FIFO" <?php echo strtoupper($costingMethod) == 'FIFO' ? 'selected' : ''; ?>>
                            <?php _e('fifo_full'); ?>
                        </option>
                        <option value="LIFO" <?php echo strtoupper($costingMethod) == 'LIFO' ? 'selected' : ''; ?>>
                            <?php _e('lifo_full'); ?>
                        </option>
                        <option value="AVERAGE" <?php echo strtoupper($costingMethod) == 'AVERAGE' ? 'selected' : ''; ?>>
                            <?php _e('weighted_average'); ?>
                        </option>
                    </select>
                </div>
                <div class="col-md-2 col-12 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-calculator"></i> <?php _e('calculate'); ?>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php if ($plData): ?>
<!-- P&L Statement -->
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
        <h5 class="mb-0">
            <?php _e('profit_loss_statement'); ?>
            <br class="d-md-none">
            <small class="text-muted"><?php echo formatDate($dateFrom); ?> <?php _e('to'); ?> <?php echo formatDate($dateTo); ?></small>
        </h5>
        <span class="badge bg-info mt-2 mt-md-0">
            <?php _e('costing_label'); ?> <?php echo strtoupper($costingMethod); ?>
        </span>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered mb-0">
                <tbody>
                    <tr>
                        <td colspan="2" class="table-light"><strong><?php _e('revenue'); ?></strong></td>
                    </tr>
                    <tr>
                        <td><?php _e('sales_revenue'); ?></td>
                        <td class="text-end"><strong><?php echo formatCurrency($plData['sales']); ?></strong></td>
                    </tr>

                    <tr>
                        <td colspan="2" class="table-light"><strong><?php _e('cost_of_goods_sold'); ?></strong></td>
                    </tr>
                    <tr>
                        <td><?php echo sprintf(__('cost_of_goods_sold_method'), strtoupper($costingMethod)); ?></td>
                        <td class="text-end text-danger">
                            <strong><?php echo formatCurrency($plData['cogs']); ?></strong>
                        </td>
                    </tr>

                    <tr class="table-info">
                        <td><strong><?php _e('gross_profit'); ?></strong></td>
                        <td class="text-end">
                            <strong class="<?php echo $plData['gross_profit'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                <?php echo formatCurrency($plData['gross_profit']); ?>
                            </strong>
                            <br>
                            <small><?php echo sprintf(__('margin'), formatNumber($plData['gross_margin'], 2)); ?></small>
                        </td>
                    </tr>

                    <tr>
                        <td colspan="2" class="table-light"><strong><?php _e('operating_expenses'); ?></strong></td>
                    </tr>
                    <tr>
                        <td><?php _e('total_expenses'); ?></td>
                        <td class="text-end text-danger">
                            <strong><?php echo formatCurrency($plData['expenses']); ?></strong>
                        </td>
                    </tr>

                    <tr class="table-success">
                        <td><strong><?php _e('net_profit'); ?></strong></td>
                        <td class="text-end">
                            <strong class="fs-5 <?php echo $plData['net_profit'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                <?php echo formatCurrency($plData['net_profit']); ?>
                            </strong>
                            <br>
                            <small><?php echo sprintf(__('margin'), formatNumber($plData['net_margin'], 2)); ?></small>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <!-- Quick Stats -->
        <div class="row mt-4 g-3">
            <div class="col-md-3 col-6">
                <div class="card bg-light text-center">
                    <div class="card-body p-3">
                        <h6 class="text-muted small mb-2"><?php _e('total_sales'); ?></h6>
                        <h5 class="mb-0"><?php echo formatCurrency($plData['sales']); ?></h5>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="card bg-light text-center">
                    <div class="card-body p-3">
                        <h6 class="text-muted small mb-2"><?php _e('cogs'); ?></h6>
                        <h5 class="text-danger mb-0"><?php echo formatCurrency($plData['cogs']); ?></h5>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="card bg-light text-center">
                    <div class="card-body p-3">
                        <h6 class="text-muted small mb-2"><?php _e('gross_profit_label'); ?></h6>
                        <h5 class="mb-0 <?php echo $plData['gross_profit'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                            <?php echo formatCurrency($plData['gross_profit']); ?>
                        </h5>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="card bg-light text-center">
                    <div class="card-body p-3">
                        <h6 class="text-muted small mb-2"><?php _e('net_profit_label'); ?></h6>
                        <h5 class="mb-0 <?php echo $plData['net_profit'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                            <?php echo formatCurrency($plData['net_profit']); ?>
                        </h5>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Top Products by Revenue -->
<?php if (!empty($topProducts)): ?>
<div class="card">
    <div class="card-header">
        <h5 class="mb-0"><?php _e('top_products_by_revenue'); ?></h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-hover">
                <thead>
                    <tr>
                        <th>#</th>
                        <th><?php _e('product'); ?></th>
                        <th class="d-none d-md-table-cell"><?php _e('category'); ?></th>
                        <th class="text-end"><?php _e('qty_sold'); ?></th>
                        <th class="text-end"><?php _e('sales_amount'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $rank = 0;
                    foreach ($topProducts as $product): 
                        $rank++;
                    ?>
                    <tr>
                        <td><?php echo $rank; ?></td>
                        <td>
                            <strong><?php echo htmlspecialchars($product['product_name']); ?></strong>
                            <br class="d-md-none">
                            <small class="text-muted d-md-none"><?php echo htmlspecialchars($product['category_name']); ?></small>
                        </td>
                        <td class="d-none d-md-table-cell"><?php echo htmlspecialchars($product['category_name']); ?></td>
                        <td class="text-end"><?php echo formatNumber($product['qty_sold'], 2); ?></td>
                        <td class="text-end"><strong><?php echo formatCurrency($product['sales_amount']); ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php else: ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle"></i>
    <?php _e('please_select_location_date'); ?>
</div>
<?php endif; ?>

<!-- Costing Method Info -->
<div class="card mt-3">
    <div class="card-header">
        <h6 class="mb-0"><i class="bi bi-info-circle"></i> <?php _e('costing_methods_explained'); ?></h6>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4 col-12">
                <h6><?php _e('fifo_full'); ?></h6>
                <p class="small mb-0">
                    <?php _e('fifo_description'); ?>
                </p>
            </div>
            <div class="col-md-4 col-12">
                <h6><?php _e('lifo_full'); ?></h6>
                <p class="small mb-0">
                    <?php _e('lifo_description'); ?>
                </p>
            </div>
            <div class="col-md-4 col-12">
                <h6><?php _e('weighted_average'); ?></h6>
                <p class="small mb-0">
                    <?php _e('weighted_average_description'); ?>
                </p>
            </div>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
