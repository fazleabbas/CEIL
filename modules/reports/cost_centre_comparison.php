<?php
require_once '../../config/database.php';
require_once '../../includes/functions.php';
$pageTitle = __('cost_centre_comparison');
if (!canViewReport('cost_centre_comparison')) {
    setAlert(__('no_permission'), 'danger');
    redirect('');
}

require_once '../../includes/header.php';

// Get filters
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$showReport = isset($_GET['date_from']);

$userCostCentres = getUserCostCentres();
$comparisonData = [];

if ($showReport && !empty($userCostCentres)) {
    foreach ($userCostCentres as $cc) {
        $ccId = $cc['id'];

        // Get all descendant cost centre IDs for aggregation
        $ccIds = getDescendantCostCentreIds($ccId, $conn);
        $ccInClause = implode(',', array_map('intval', $ccIds));

        // Revenue (converted to base currency)
        $stmt = $conn->prepare("SELECT COALESCE(SUM(total_amount * COALESCE(exchange_rate_to_base, 1)), 0) FROM sales WHERE cost_centre_id IN ($ccInClause) AND sale_date BETWEEN ? AND ?");
        $stmt->execute([$dateFrom, $dateTo]);
        $revenue = $stmt->fetchColumn();

        // Purchases (converted to base currency)
        $stmt = $conn->prepare("SELECT COALESCE(SUM((total_amount + vat_amount) * COALESCE(exchange_rate_to_base, 1)), 0) FROM purchases WHERE cost_centre_id IN ($ccInClause) AND purchase_date BETWEEN ? AND ?");
        $stmt->execute([$dateFrom, $dateTo]);
        $purchases = $stmt->fetchColumn();

        // Consumption (converted to base currency)
        $stmt = $conn->prepare("SELECT COALESCE(SUM(total_amount * COALESCE(exchange_rate_to_base, 1)), 0) FROM consumption_vouchers WHERE cost_centre_id IN ($ccInClause) AND voucher_date BETWEEN ? AND ?");
        $stmt->execute([$dateFrom, $dateTo]);
        $consumption = $stmt->fetchColumn();

        // Expenses (converted to base currency)
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(p.amount * COALESCE(p.exchange_rate_to_base, 1)), 0)
            FROM payments p
            JOIN ledger_accounts la ON p.to_account_id = la.id
            WHERE la.account_type = 'expense'
            AND p.cost_centre_id IN ($ccInClause)
            AND p.payment_date BETWEEN ? AND ?
        ");
        $stmt->execute([$dateFrom, $dateTo]);
        $expenses = $stmt->fetchColumn();

        // COGS (converted to base currency using sale's exchange rate)
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(sd.cost_per_unit * sd.quantity * COALESCE(s.exchange_rate_to_base, 1)), 0)
            FROM sale_details sd
            JOIN sales s ON sd.sale_id = s.id
            WHERE s.cost_centre_id IN ($ccInClause) AND s.sale_date BETWEEN ? AND ?
        ");
        $stmt->execute([$dateFrom, $dateTo]);
        $cogs = $stmt->fetchColumn();

        $grossProfit = $revenue - $cogs;
        $netPL = $grossProfit - $expenses - $consumption;

        // Budget
        $stmt = $conn->prepare("SELECT budget_amount, status FROM cost_centres WHERE id = ?");
        $stmt->execute([$ccId]);
        $ccInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        $budget = floatval($ccInfo['budget_amount']);
        $totalSpend = $purchases + $expenses + $consumption;
        $budgetUsed = $budget > 0 ? ($totalSpend / $budget) * 100 : 0;

        $comparisonData[] = [
            'id' => $ccId,
            'code' => $cc['code'],
            'name' => $cc['name'],
            'status' => $ccInfo['status'],
            'revenue' => $revenue,
            'cogs' => $cogs,
            'gross_profit' => $grossProfit,
            'consumption' => $consumption,
            'expenses' => $expenses,
            'net_pl' => $netPL,
            'purchases' => $purchases,
            'budget' => $budget,
            'budget_used' => $budgetUsed
        ];
    }

    // Sort by net P&L descending
    usort($comparisonData, function ($a, $b) {
        return $b['net_pl'] <=> $a['net_pl'];
    });
}
?>

<div class="row mb-3">
    <div class="col-12">
        <h2><i class="bi bi-bar-chart-steps"></i> <?php _e('cost_centre_comparison'); ?></h2>
    </div>
</div>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="">
            <div class="row g-3">
                <div class="col-md-4 col-6">
                    <label class="form-label required"><?php _e('date_from'); ?></label>
                    <input type="date" name="date_from" class="form-control"
                           value="<?php echo $dateFrom; ?>" required>
                </div>
                <div class="col-md-4 col-6">
                    <label class="form-label required"><?php _e('date_to'); ?></label>
                    <input type="date" name="date_to" class="form-control"
                           value="<?php echo $dateTo; ?>" required>
                </div>
                <div class="col-md-4 col-12 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-calculator"></i> <?php _e('calculate'); ?>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php if ($showReport && !empty($comparisonData)): ?>
<!-- Comparison Table -->
<div class="card">
    <div class="card-header">
        <h5 class="mb-0">
            <?php _e('cost_centre_comparison'); ?>
            <small class="text-muted ms-2"><?php echo formatDate($dateFrom); ?> - <?php echo formatDate($dateTo); ?></small>
        </h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th><?php _e('cost_centre'); ?></th>
                        <th><?php _e('status'); ?></th>
                        <th class="text-end"><?php _e('revenue'); ?></th>
                        <th class="text-end"><?php _e('cogs'); ?></th>
                        <th class="text-end"><?php _e('gross_profit'); ?></th>
                        <th class="text-end"><?php _e('material_consumption'); ?></th>
                        <th class="text-end"><?php _e('direct_expenses'); ?></th>
                        <th class="text-end"><?php _e('net_profit'); ?></th>
                        <th class="text-end"><?php _e('budget'); ?></th>
                        <th class="text-end"><?php _e('budget_used'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $totalRevenue = 0;
                    $totalCogs = 0;
                    $totalGP = 0;
                    $totalConsumption = 0;
                    $totalExp = 0;
                    $totalNet = 0;
                    $totalBudget = 0;
                    foreach ($comparisonData as $row):
                        $totalRevenue += $row['revenue'];
                        $totalCogs += $row['cogs'];
                        $totalGP += $row['gross_profit'];
                        $totalConsumption += $row['consumption'];
                        $totalExp += $row['expenses'];
                        $totalNet += $row['net_pl'];
                        $totalBudget += $row['budget'];
                    ?>
                    <tr>
                        <td>
                            <?php if (canViewReport('cost_centre_report')): ?>
                            <a href="cost_centre_report.php?cost_centre=<?php echo $row['id']; ?>&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>">
                                <strong><?php echo htmlspecialchars($row['code']); ?></strong>
                                - <?php echo htmlspecialchars($row['name']); ?>
                            </a>
                            <?php else: ?>
                            <strong><?php echo htmlspecialchars($row['code']); ?></strong>
                            - <?php echo htmlspecialchars($row['name']); ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-<?php echo $row['status'] == 'active' ? 'success' : ($row['status'] == 'on_hold' ? 'warning' : 'secondary'); ?>">
                                <?php _e($row['status'] == 'on_hold' ? 'on_hold' : $row['status']); ?>
                            </span>
                        </td>
                        <td class="text-end"><?php echo formatCurrency($row['revenue']); ?></td>
                        <td class="text-end text-danger"><?php echo formatCurrency($row['cogs']); ?></td>
                        <td class="text-end <?php echo $row['gross_profit'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                            <?php echo formatCurrency($row['gross_profit']); ?>
                        </td>
                        <td class="text-end text-danger"><?php echo formatCurrency($row['consumption']); ?></td>
                        <td class="text-end text-danger"><?php echo formatCurrency($row['expenses']); ?></td>
                        <td class="text-end fw-bold <?php echo $row['net_pl'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                            <?php echo formatCurrency($row['net_pl']); ?>
                        </td>
                        <td class="text-end"><?php echo $row['budget'] > 0 ? formatCurrency($row['budget']) : '-'; ?></td>
                        <td class="text-end">
                            <?php if ($row['budget'] > 0): ?>
                            <span class="<?php echo $row['budget_used'] > 100 ? 'text-danger fw-bold' : ($row['budget_used'] > 80 ? 'text-warning' : 'text-success'); ?>">
                                <?php echo formatNumber($row['budget_used'], 1); ?>%
                            </span>
                            <?php else: ?>
                            -
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light">
                    <tr class="fw-bold">
                        <td colspan="2"><?php _e('total'); ?></td>
                        <td class="text-end"><?php echo formatCurrency($totalRevenue); ?></td>
                        <td class="text-end text-danger"><?php echo formatCurrency($totalCogs); ?></td>
                        <td class="text-end <?php echo $totalGP >= 0 ? 'text-success' : 'text-danger'; ?>">
                            <?php echo formatCurrency($totalGP); ?>
                        </td>
                        <td class="text-end text-danger"><?php echo formatCurrency($totalConsumption); ?></td>
                        <td class="text-end text-danger"><?php echo formatCurrency($totalExp); ?></td>
                        <td class="text-end <?php echo $totalNet >= 0 ? 'text-success' : 'text-danger'; ?>">
                            <?php echo formatCurrency($totalNet); ?>
                        </td>
                        <td class="text-end"><?php echo $totalBudget > 0 ? formatCurrency($totalBudget) : '-'; ?></td>
                        <td class="text-end">
                            <?php if ($totalBudget > 0): ?>
                            <?php $totalBudgetUsed = (array_sum(array_column($comparisonData, 'purchases')) + $totalConsumption + $totalExp) / $totalBudget * 100; ?>
                            <?php echo formatNumber($totalBudgetUsed, 1); ?>%
                            <?php else: ?>-<?php endif; ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<?php elseif ($showReport && empty($comparisonData)): ?>
<div class="card">
    <div class="card-body text-center py-5">
        <i class="bi bi-building display-1 text-muted"></i>
        <h5 class="mt-3"><?php _e('no_cost_centres_found'); ?></h5>
    </div>
</div>
<?php elseif (!$showReport): ?>
<div class="card">
    <div class="card-body text-center py-5">
        <i class="bi bi-bar-chart-steps display-1 text-muted"></i>
        <h5 class="mt-3"><?php _e('please_select_cost_centre'); ?></h5>
        <p class="text-muted"><?php _e('select_date_range_to_compare'); ?></p>
    </div>
</div>
<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>
