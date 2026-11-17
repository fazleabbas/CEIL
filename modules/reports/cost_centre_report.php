<?php
require_once '../../config/database.php';
require_once '../../includes/functions.php';
$pageTitle = __('cost_centre_report');
if (!canViewReport('cost_centre_report')) {
    setAlert(__('no_permission'), 'danger');
    redirect('');
}

require_once '../../includes/header.php';

// Get filters
$costCentreFilter = $_GET['cost_centre'] ?? '';
$locationFilter = $_GET['location'] ?? '';
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$costingMethod = $_GET['costing_method'] ?? 'FIFO';

// Get user's cost centres and locations
$userCostCentres = getUserCostCentres();
$userLocations = getUserLocations();

$plData = null;
$costCentreInfo = null;

if ($costCentreFilter) {
    // Get cost centre info
    $ccStmt = $conn->prepare("SELECT * FROM cost_centres WHERE id = ?");
    $ccStmt->execute([$costCentreFilter]);
    $costCentreInfo = $ccStmt->fetch(PDO::FETCH_ASSOC);

    if ($costCentreInfo) {
        // Build base conditions — aggregate parent + all descendant cost centres
        $ccIds = getDescendantCostCentreIds($costCentreFilter, $conn);
        $ccInClause = implode(',', array_map('intval', $ccIds));
        $ccCondition = "cost_centre_id IN ($ccInClause)";
        $ccParams = [];

        // Optional location filter — aggregate parent + all descendant locations
        $locCondition = '';
        $locParams = [];
        if ($locationFilter && $locationFilter !== 'all') {
            $locIds = getDescendantLocationIds($locationFilter, $conn);
            $locInClause = implode(',', array_map('intval', $locIds));
            $locCondition = " AND location_id IN ($locInClause)";
        } elseif ($locationFilter === 'all' || !$locationFilter) {
            // No additional location filter
        }

        // 1. Sales Revenue (converted to base currency)
        $salesStmt = $conn->prepare("
            SELECT COALESCE(SUM(total_amount * COALESCE(exchange_rate_to_base, 1)), 0) as total_sales
            FROM sales
            WHERE $ccCondition $locCondition
            AND sale_date BETWEEN ? AND ?
        ");
        $salesStmt->execute(array_merge($ccParams, $locParams, [$dateFrom, $dateTo]));
        $totalSales = $salesStmt->fetchColumn();

        // 2. COGS calculation
        $cogs = 0;
        $salesDetailsStmt = $conn->prepare("
            SELECT
                sd.product_id,
                sd.quantity,
                sd.rate_per_unit as selling_price,
                s.sale_date,
                s.location_id
            FROM sale_details sd
            JOIN sales s ON sd.sale_id = s.id
            WHERE s.$ccCondition $locCondition
            AND s.sale_date BETWEEN ? AND ?
            ORDER BY s.sale_date ASC, sd.id ASC
        ");
        $salesDetailsStmt->execute(array_merge($ccParams, $locParams, [$dateFrom, $dateTo]));
        $salesDetails = $salesDetailsStmt->fetchAll();

        foreach ($salesDetails as $sale) {
            $productId = $sale['product_id'];
            $qtySold = $sale['quantity'];
            $saleDate = $sale['sale_date'];
            $saleLocationId = $sale['location_id'];

            if (strtoupper($costingMethod) == 'FIFO') {
                $batchStmt = $conn->prepare("
                    SELECT rate_per_unit, quantity_in
                    FROM stock_batches
                    WHERE location_id = ? AND product_id = ? AND batch_date <= ?
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
                $batchStmt = $conn->prepare("
                    SELECT rate_per_unit, quantity_in
                    FROM stock_batches
                    WHERE location_id = ? AND product_id = ? AND batch_date <= ?
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
            } else {
                $avgStmt = $conn->prepare("
                    SELECT COALESCE(SUM(quantity_in * rate_per_unit) / NULLIF(SUM(quantity_in), 0), 0) as avg_rate
                    FROM stock_batches
                    WHERE location_id = ? AND product_id = ? AND batch_date <= ?
                ");
                $avgStmt->execute([$saleLocationId, $productId, $saleDate]);
                $avgRate = $avgStmt->fetchColumn();
                $cogs += $qtySold * $avgRate;
            }
        }

        // 3. Gross Profit
        $grossProfit = $totalSales - $cogs;
        $grossMargin = $totalSales > 0 ? ($grossProfit / $totalSales) * 100 : 0;

        // 4. Material Purchases (Bill Amount = goods + VAT, converted to base currency)
        $purchasesStmt = $conn->prepare("
            SELECT COALESCE(SUM((total_amount + vat_amount) * COALESCE(exchange_rate_to_base, 1)), 0) as total_purchases
            FROM purchases
            WHERE $ccCondition $locCondition
            AND purchase_date BETWEEN ? AND ?
        ");
        $purchasesStmt->execute(array_merge($ccParams, $locParams, [$dateFrom, $dateTo]));
        $totalPurchases = $purchasesStmt->fetchColumn();

        // 4b. Material Consumption (converted to base currency)
        $consumptionStmt = $conn->prepare("
            SELECT COALESCE(SUM(total_amount * COALESCE(exchange_rate_to_base, 1)), 0) as total_consumption
            FROM consumption_vouchers
            WHERE $ccCondition $locCondition
            AND voucher_date BETWEEN ? AND ?
        ");
        $consumptionStmt->execute(array_merge($ccParams, $locParams, [$dateFrom, $dateTo]));
        $totalConsumption = $consumptionStmt->fetchColumn();

        // 5. Direct Expenses (converted to base currency)
        $expenseStmt = $conn->prepare("
            SELECT COALESCE(SUM(p.amount * COALESCE(p.exchange_rate_to_base, 1)), 0) as total_expenses
            FROM payments p
            JOIN ledger_accounts la ON p.to_account_id = la.id
            WHERE la.account_type = 'expense'
            AND p.$ccCondition
            AND p.payment_date BETWEEN ? AND ?
        ");
        $expenseStmt->execute(array_merge($ccParams, [$dateFrom, $dateTo]));
        $totalExpenses = $expenseStmt->fetchColumn();

        // 6. Net Profit
        $netProfit = $grossProfit - $totalExpenses - $totalConsumption;
        $netMargin = $totalSales > 0 ? ($netProfit / $totalSales) * 100 : 0;

        // 7. Budget vs Actual
        $budgetAmount = floatval($costCentreInfo['budget_amount']);
        $totalActualSpend = $totalPurchases + $totalExpenses + $totalConsumption;
        $budgetVariance = $budgetAmount - $totalActualSpend;
        $budgetUsedPct = $budgetAmount > 0 ? ($totalActualSpend / $budgetAmount) * 100 : 0;

        // 8. Transaction counts
        $salesCountStmt = $conn->prepare("SELECT COUNT(*) FROM sales WHERE $ccCondition AND sale_date BETWEEN ? AND ?");
        $salesCountStmt->execute(array_merge($ccParams, [$dateFrom, $dateTo]));
        $salesCount = $salesCountStmt->fetchColumn();

        $purchasesCountStmt = $conn->prepare("SELECT COUNT(*) FROM purchases WHERE $ccCondition AND purchase_date BETWEEN ? AND ?");
        $purchasesCountStmt->execute(array_merge($ccParams, [$dateFrom, $dateTo]));
        $purchasesCount = $purchasesCountStmt->fetchColumn();

        $paymentsCountStmt = $conn->prepare("SELECT COUNT(*) FROM payments WHERE $ccCondition AND payment_date BETWEEN ? AND ?");
        $paymentsCountStmt->execute(array_merge($ccParams, [$dateFrom, $dateTo]));
        $paymentsCount = $paymentsCountStmt->fetchColumn();

        $consumptionCountStmt = $conn->prepare("SELECT COUNT(*) FROM consumption_vouchers WHERE $ccCondition AND voucher_date BETWEEN ? AND ?");
        $consumptionCountStmt->execute(array_merge($ccParams, [$dateFrom, $dateTo]));
        $consumptionCount = $consumptionCountStmt->fetchColumn();

        $plData = [
            'sales' => $totalSales,
            'cogs' => $cogs,
            'gross_profit' => $grossProfit,
            'gross_margin' => $grossMargin,
            'purchases' => $totalPurchases,
            'consumption' => $totalConsumption,
            'expenses' => $totalExpenses,
            'net_profit' => $netProfit,
            'net_margin' => $netMargin,
            'budget' => $budgetAmount,
            'actual_spend' => $totalActualSpend,
            'variance' => $budgetVariance,
            'budget_used_pct' => $budgetUsedPct,
            'sales_count' => $salesCount,
            'purchases_count' => $purchasesCount,
            'consumption_count' => $consumptionCount,
            'payments_count' => $paymentsCount
        ];
    }
}
?>

<div class="row mb-3">
    <div class="col-12">
        <h2><i class="bi bi-building"></i> <?php _e('cost_centre_report'); ?></h2>
    </div>
</div>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="">
            <div class="row g-3">
                <div class="col-md-3 col-12">
                    <label class="form-label required"><?php _e('cost_centre'); ?></label>
                    <select name="cost_centre" class="form-select" required>
                        <option value=""><?php _e('select_cost_centre'); ?></option>
                        <?= renderTreeOptions($userCostCentres, $costCentreFilter, null, 'name', 'code') ?>
                    </select>
                </div>
                <div class="col-md-2 col-12">
                    <label class="form-label"><?php _e('location'); ?></label>
                    <select name="location" class="form-select">
                        <option value=""><?php _e('all_locations'); ?></option>
                        <?= renderTreeOptions($userLocations, $locationFilter) ?>
                    </select>
                </div>
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
                <div class="col-md-1 col-6">
                    <label class="form-label"><?php _e('costing_method'); ?></label>
                    <select name="costing_method" class="form-select">
                        <option value="FIFO" <?php echo strtoupper($costingMethod) == 'FIFO' ? 'selected' : ''; ?>>FIFO</option>
                        <option value="LIFO" <?php echo strtoupper($costingMethod) == 'LIFO' ? 'selected' : ''; ?>>LIFO</option>
                        <option value="AVERAGE" <?php echo strtoupper($costingMethod) == 'AVERAGE' ? 'selected' : ''; ?>>AVG</option>
                    </select>
                </div>
                <div class="col-md-2 col-6 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-calculator"></i> <?php _e('calculate'); ?>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php if (!$costCentreFilter): ?>
<div class="card">
    <div class="card-body text-center py-5">
        <i class="bi bi-building display-1 text-muted"></i>
        <h5 class="mt-3"><?php _e('please_select_cost_centre'); ?></h5>
    </div>
</div>
<?php elseif ($plData): ?>

<!-- Cost Centre Info Header -->
<div class="card mb-3">
    <div class="card-body">
        <div class="row align-items-center">
            <div class="col-md-6">
                <h4 class="mb-1"><?php echo htmlspecialchars($costCentreInfo['code'] . ' - ' . $costCentreInfo['name']); ?></h4>
                <?php if ($costCentreInfo['description']): ?>
                <p class="text-muted mb-0"><?php echo htmlspecialchars($costCentreInfo['description']); ?></p>
                <?php endif; ?>
            </div>
            <div class="col-md-6 text-md-end">
                <span class="badge bg-<?php echo $costCentreInfo['status'] == 'active' ? 'success' : ($costCentreInfo['status'] == 'on_hold' ? 'warning' : 'secondary'); ?> me-2">
                    <?php _e($costCentreInfo['status'] == 'on_hold' ? 'on_hold' : $costCentreInfo['status']); ?>
                </span>
                <?php if ($costCentreInfo['start_date']): ?>
                <small class="text-muted">
                    <?php echo formatDate($costCentreInfo['start_date']); ?>
                    <?php if ($costCentreInfo['end_date']): ?>
                    - <?php echo formatDate($costCentreInfo['end_date']); ?>
                    <?php endif; ?>
                </small>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- P&L Statement -->
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
        <h5 class="mb-0">
            <?php _e('cost_centre_pl_statement'); ?>
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
                        <td><?php _e('material_purchases'); ?></td>
                        <td class="text-end"><?php echo formatCurrency($plData['purchases']); ?></td>
                    </tr>
                    <tr>
                        <td><?php _e('material_consumption'); ?></td>
                        <td class="text-end"><?php echo formatCurrency($plData['consumption']); ?></td>
                    </tr>
                    <tr>
                        <td><?php _e('direct_expenses'); ?></td>
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
                        <small class="text-muted"><?php echo $plData['sales_count']; ?> <?php _e('transactions_count'); ?></small>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="card bg-light text-center">
                    <div class="card-body p-3">
                        <h6 class="text-muted small mb-2"><?php _e('material_purchases'); ?></h6>
                        <h5 class="text-danger mb-0"><?php echo formatCurrency($plData['purchases']); ?></h5>
                        <small class="text-muted"><?php echo $plData['purchases_count']; ?> <?php _e('transactions_count'); ?></small>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="card bg-light text-center">
                    <div class="card-body p-3">
                        <h6 class="text-muted small mb-2"><?php _e('material_consumption'); ?></h6>
                        <h5 class="text-danger mb-0"><?php echo formatCurrency($plData['consumption']); ?></h5>
                        <small class="text-muted"><?php echo $plData['consumption_count']; ?> <?php _e('transactions_count'); ?></small>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="card bg-light text-center">
                    <div class="card-body p-3">
                        <h6 class="text-muted small mb-2"><?php _e('direct_expenses'); ?></h6>
                        <h5 class="text-danger mb-0"><?php echo formatCurrency($plData['expenses']); ?></h5>
                        <small class="text-muted"><?php echo $plData['payments_count']; ?> <?php _e('transactions_count'); ?></small>
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

<!-- Budget vs Actual -->
<?php if ($plData['budget'] > 0): ?>
<div class="card mb-3">
    <div class="card-header">
        <h5 class="mb-0"><i class="bi bi-pie-chart"></i> <?php _e('budget_vs_actual'); ?></h5>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3 col-6">
                <div class="card bg-light text-center">
                    <div class="card-body p-3">
                        <h6 class="text-muted small mb-2"><?php _e('budget'); ?></h6>
                        <h5 class="mb-0"><?php echo formatCurrency($plData['budget']); ?></h5>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="card bg-light text-center">
                    <div class="card-body p-3">
                        <h6 class="text-muted small mb-2"><?php _e('total_cost'); ?></h6>
                        <h5 class="mb-0"><?php echo formatCurrency($plData['actual_spend']); ?></h5>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="card bg-light text-center">
                    <div class="card-body p-3">
                        <h6 class="text-muted small mb-2"><?php _e('variance'); ?></h6>
                        <h5 class="mb-0 <?php echo $plData['variance'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                            <?php echo formatCurrency(abs($plData['variance'])); ?>
                            <?php echo $plData['variance'] >= 0 ? __('under_budget') : __('over_budget'); ?>
                        </h5>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="card bg-light text-center">
                    <div class="card-body p-3">
                        <h6 class="text-muted small mb-2"><?php _e('budget_used'); ?></h6>
                        <h5 class="mb-0 <?php echo $plData['budget_used_pct'] > 100 ? 'text-danger' : ($plData['budget_used_pct'] > 80 ? 'text-warning' : 'text-success'); ?>">
                            <?php echo formatNumber($plData['budget_used_pct'], 1); ?>%
                        </h5>
                    </div>
                </div>
            </div>
        </div>
        <div class="mt-3">
            <div class="progress" style="height: 25px;">
                <?php
                $pct = min($plData['budget_used_pct'], 100);
                $barClass = $plData['budget_used_pct'] > 100 ? 'bg-danger' : ($plData['budget_used_pct'] > 80 ? 'bg-warning' : 'bg-success');
                ?>
                <div class="progress-bar <?php echo $barClass; ?>" role="progressbar"
                     style="width: <?php echo $pct; ?>%"
                     aria-valuenow="<?php echo $pct; ?>" aria-valuemin="0" aria-valuemax="100">
                    <?php echo formatNumber($plData['budget_used_pct'], 1); ?>%
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php elseif ($costCentreFilter && !$costCentreInfo): ?>
<div class="alert alert-warning">
    <?php _e('no_cost_centres_found'); ?>
</div>
<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>
