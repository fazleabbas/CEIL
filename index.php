<?php
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");

require_once 'config/database.php';
require_once 'includes/functions.php';
$pageTitle = __('dashboard');
require_once 'includes/header.php';

// Check for pending approvals (for users with approve permission)
$pendingApprovals = 0;
if (hasPermission('requisition_vouchers', 'approve')) {
    try {
        $paStmt = $conn->prepare("SELECT COUNT(*) FROM requisition_vouchers WHERE status = 'pending_approval'");
        $paStmt->execute();
        $pendingApprovals = (int)$paStmt->fetchColumn();
    } catch (PDOException $e) {
        $pendingApprovals = 0;
    }
}

// Check if user is non-Admin - show simple welcome page
if (getUserData('role_name') != 'Admin') {
?>
<?php if ($pendingApprovals > 0): ?>
<div class="alert alert-warning mb-3">
    <i class="bi bi-clipboard-check me-2"></i>
    <strong><?php echo $pendingApprovals; ?></strong> <?php _e('pending_approvals_message'); ?>
    <a href="<?php echo BASE_URL; ?>modules/transactions/requisition_voucher_list.php?status=pending_approval" class="alert-link ms-2">
        <?php _e('view'); ?> <i class="bi bi-arrow-right"></i>
    </a>
</div>
<?php endif; ?>
<div class="d-flex align-items-center justify-content-center" style="min-height: 60vh;">
    <div class="text-center">
        <i class="bi bi-person-circle text-primary" style="font-size: 5rem;"></i>
        <h1 class="mt-4 mb-3"><?php _e('welcome'); ?>, <?php echo htmlspecialchars(getUserData('name')); ?>!</h1>
        <p class="text-muted"><?php _e('use_menu'); ?></p>
    </div>
</div>
<?php
    require_once 'includes/footer.php';
    exit;
}

// Admin Dashboard - Get dashboard statistics
$stats = [];

// Today's Sales (converted to base currency)
$stmt = $conn->prepare("SELECT COALESCE(SUM(total_amount * COALESCE(exchange_rate_to_base, 1)), 0) as total FROM sales WHERE sale_date = CURDATE()");
$stmt->execute();
$stats['today_sales'] = $stmt->fetch()['total'];

// Today's Purchases (converted to base currency)
$stmt = $conn->prepare("SELECT COALESCE(SUM((total_amount + vat_amount) * COALESCE(exchange_rate_to_base, 1)), 0) as total FROM purchases WHERE purchase_date = CURDATE()");
$stmt->execute();
$stats['today_purchases'] = $stmt->fetch()['total'];

// This Month's Sales (converted to base currency)
$stmt = $conn->prepare("SELECT COALESCE(SUM(total_amount * COALESCE(exchange_rate_to_base, 1)), 0) as total FROM sales WHERE MONTH(sale_date) = MONTH(CURDATE()) AND YEAR(sale_date) = YEAR(CURDATE())");
$stmt->execute();
$stats['month_sales'] = $stmt->fetch()['total'];

// Cash Balance (sum of all cash accounts) - with detailed breakdown.
// Source of truth is the GL (transaction_details). Cash is DR-normal (asset):
//   - DR increases cash (receipts, sales auto-receipts, opening journal, etc.)
//   - CR decreases cash (payments, transfers out, etc.)
// Reading DR/CR sums per cash ledger auto-includes every feature that posts
// to a cash ledger. amount_base is used so multi-currency vouchers reconcile
// in base currency.
$stmt = $conn->prepare("
    SELECT
        la.id,
        la.name as account_name,
        la.opening_balance,
        COALESCE((SELECT SUM(amount_base) FROM transaction_details
                  WHERE ledger_id = la.id AND dr_cr = 'DR'), 0) as total_dr,
        COALESCE((SELECT SUM(amount_base) FROM transaction_details
                  WHERE ledger_id = la.id AND dr_cr = 'CR'), 0) as total_cr,
        (
            la.opening_balance
          + COALESCE((SELECT SUM(amount_base) FROM transaction_details
                      WHERE ledger_id = la.id AND dr_cr = 'DR'), 0)
          - COALESCE((SELECT SUM(amount_base) FROM transaction_details
                      WHERE ledger_id = la.id AND dr_cr = 'CR'), 0)
        ) as calculated_balance
    FROM ledger_accounts la
    WHERE la.account_type = 'cash' AND la.is_active = 1
    ORDER BY la.name
");
$stmt->execute();
$cashDetails = $stmt->fetchAll();
$stats['cash_balance'] = array_sum(array_column($cashDetails, 'calculated_balance'));

// Outstanding Payables (owed to suppliers) - with detailed breakdown.
// Source of truth is the GL (transaction_details). Summing CR and DR per
// supplier ledger automatically picks up everything that hits that supplier:
//   - Regular purchases (DR Purchase / CR Supplier)
//   - Purchase imports (DR Purchase + DR VAT + DR Bucket A / CR Supplier)
//   - Bucket B journal vouchers (DR ledger / CR Beneficiary Supplier)
//   - Payments to the supplier (DR Supplier / CR Cash/Bank)
//   - Any future feature that posts to the supplier ledger
// All values use amount_base so multi-currency vouchers reconcile in base.
$stmt = $conn->prepare("
    SELECT
        la.id,
        s.id as supplier_id,
        la.name as supplier_name,
        la.opening_balance,
        COALESCE((SELECT SUM(amount_base) FROM transaction_details
                  WHERE ledger_id = la.id AND dr_cr = 'CR'), 0) as total_purchases,
        COALESCE((SELECT SUM(amount_base) FROM transaction_details
                  WHERE ledger_id = la.id AND dr_cr = 'DR'), 0) as total_payments,
        (
            la.opening_balance
          + COALESCE((SELECT SUM(amount_base) FROM transaction_details
                      WHERE ledger_id = la.id AND dr_cr = 'CR'), 0)
          - COALESCE((SELECT SUM(amount_base) FROM transaction_details
                      WHERE ledger_id = la.id AND dr_cr = 'DR'), 0)
        ) as calculated_balance
    FROM ledger_accounts la
    INNER JOIN suppliers s ON s.ledger_account_id = la.id
    WHERE la.account_type = 'supplier' AND la.is_active = 1 AND s.is_active = 1
    HAVING calculated_balance > 0
    ORDER BY calculated_balance DESC
");
$stmt->execute();
$payablesDetails = $stmt->fetchAll();
$stats['payables'] = array_sum(array_column($payablesDetails, 'calculated_balance'));

// Outstanding Receivables (owed by customers) - with detailed breakdown.
// Customer ledgers are DR-normal: a sale debits the customer, a receipt
// credits. Reading DR/CR sums from transaction_details auto-includes every
// feature that posts to a customer ledger.
$stmt = $conn->prepare("
    SELECT
        la.id,
        la.name as customer_name,
        la.opening_balance,
        COALESCE((SELECT SUM(amount_base) FROM transaction_details
                  WHERE ledger_id = la.id AND dr_cr = 'DR'), 0) as total_sales,
        COALESCE((SELECT SUM(amount_base) FROM transaction_details
                  WHERE ledger_id = la.id AND dr_cr = 'CR'), 0) as total_receipts,
        (
            la.opening_balance
          + COALESCE((SELECT SUM(amount_base) FROM transaction_details
                      WHERE ledger_id = la.id AND dr_cr = 'DR'), 0)
          - COALESCE((SELECT SUM(amount_base) FROM transaction_details
                      WHERE ledger_id = la.id AND dr_cr = 'CR'), 0)
        ) as calculated_balance
    FROM ledger_accounts la
    INNER JOIN customers c ON c.ledger_account_id = la.id
    WHERE la.account_type = 'customer' AND la.is_active = 1 AND c.is_active = 1
    HAVING calculated_balance > 0
    ORDER BY calculated_balance DESC
");
$stmt->execute();
$receivablesDetails = $stmt->fetchAll();
$stats['receivables'] = array_sum(array_column($receivablesDetails, 'calculated_balance'));

// Recent Transactions
$recentSales = [];
if (hasPermission('sales', 'view')) {
    $stmt = $conn->prepare("
        SELECT s.id, s.sale_number, s.sale_date,
               (s.total_amount * COALESCE(s.exchange_rate_to_base, 1)) as total_amount,
               la.name as customer_name, l.name as location_name
        FROM sales s
        INNER JOIN customers c ON s.customer_id = c.id
        INNER JOIN ledger_accounts la ON c.ledger_account_id = la.id
        INNER JOIN locations l ON s.location_id = l.id
        ORDER BY s.created_at DESC
        LIMIT 5
    ");
    $stmt->execute();
    $recentSales = $stmt->fetchAll();
}
?>

<?php if ($pendingApprovals > 0): ?>
<div class="alert alert-warning mb-3">
    <i class="bi bi-clipboard-check me-2"></i>
    <strong><?php echo $pendingApprovals; ?></strong> <?php _e('pending_approvals_message'); ?>
    <a href="<?php echo BASE_URL; ?>modules/transactions/requisition_voucher_list.php?status=pending_approval" class="alert-link ms-2">
        <?php _e('view'); ?> <i class="bi bi-arrow-right"></i>
    </a>
</div>
<?php endif; ?>

<div class="row mb-4">
    <div class="col-12">
        <h2>
            <i class="bi bi-speedometer2"></i> <?php _e('dashboard'); ?>
        </h2>
        <p class="text-muted"><?php _e('welcome_back'); ?>, <?php echo getUserData('name'); ?>!</p>
    </div>
</div>

<!-- Key Metrics Cards -->
<div class="row g-3 mb-4">
    <!-- Today's Sales -->
    <div class="col-6 col-lg-3">
        <div class="card h-100 border-primary">
            <div class="card-body text-center py-3">
                <i class="bi bi-graph-up-arrow text-primary" style="font-size: 1.75rem;"></i>
                <h5 class="mt-2 mb-0 text-primary"><?php echo formatCurrency($stats['today_sales']); ?></h5>
                <small class="text-muted"><?php _e('todays_sales'); ?></small>
            </div>
        </div>
    </div>
    
    <!-- Today's Purchases -->
    <div class="col-6 col-lg-3">
        <div class="card h-100 border-danger">
            <div class="card-body text-center py-3">
                <i class="bi bi-graph-down-arrow text-danger" style="font-size: 1.75rem;"></i>
                <h5 class="mt-2 mb-0 text-danger"><?php echo formatCurrency($stats['today_purchases']); ?></h5>
                <small class="text-muted"><?php _e('todays_purchases'); ?></small>
            </div>
        </div>
    </div>
    
    <!-- Cash Balance (Clickable) -->
    <div class="col-6 col-lg-3">
        <div class="card h-100 border-info"
             style="cursor: pointer;" data-bs-toggle="modal" data-bs-target="#cashModal">
            <div class="card-body text-center py-3">
                <i class="bi bi-wallet2 text-info" style="font-size: 1.75rem;"></i>
                <h5 class="mt-2 mb-0 text-info"><?php echo formatCurrency($stats['cash_balance']); ?></h5>
                <small class="text-muted"><?php _e('cash_balance'); ?> <i class="bi bi-box-arrow-up-right"></i></small>
            </div>
        </div>
    </div>

    <!-- Total Stock Value (lazy-loaded, bifurcated by location type) -->
    <div class="col-6 col-lg-3">
        <div class="card h-100 border-success">
            <div class="card-body text-center py-3">
                <i class="bi bi-boxes text-success" style="font-size: 1.75rem;"></i>
                <a href="<?php echo BASE_URL; ?>modules/reports/stock_value_breakdown.php"
                   target="_blank" rel="noopener"
                   class="text-decoration-none d-block"
                   title="<?php _e('total_stock_value'); ?>">
                    <h5 class="mt-2 mb-0 text-success" id="stockValueAmount">
                        <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                    </h5>
                    <small class="text-muted"><?php _e('total_stock_value'); ?></small>
                </a>
                <ul class="list-unstyled mb-0 mt-2 text-start small border-top pt-2" id="stockValueByType">
                    <?php
                    $stockTypeRows = [
                        'site'      => __('location_type_site'),
                        'warehouse' => __('location_type_warehouse'),
                        'others'    => __('location_type_others'),
                        'untyped'   => __('location_type_untyped'),
                    ];
                    foreach ($stockTypeRows as $typeKey => $typeLabel): ?>
                        <li class="d-flex justify-content-between align-items-center py-1">
                            <a href="<?php echo BASE_URL; ?>modules/reports/stock_value_breakdown.php?location_type=<?php echo $typeKey; ?>"
                               target="_blank" rel="noopener"
                               class="text-decoration-none text-muted text-truncate me-2"
                               title="<?php echo htmlspecialchars($typeLabel, ENT_QUOTES); ?>">
                                <?php echo htmlspecialchars($typeLabel); ?>
                            </a>
                            <span class="text-success fw-semibold text-nowrap" data-stock-type="<?php echo $typeKey; ?>">
                                <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- Secondary Metrics -->
<div class="row g-3 mb-4">
    <!-- This Month's Sales -->
    <div class="col-12 col-md-4">
        <div class="card h-100">
            <div class="card-body d-flex align-items-center">
                <div class="me-3">
                    <i class="bi bi-calendar-month text-primary" style="font-size: 2rem;"></i>
                </div>
                <div>
                    <h5 class="mb-0 text-primary"><?php echo formatCurrency($stats['month_sales']); ?></h5>
                    <small class="text-muted"><?php _e('this_months_sales'); ?></small>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Outstanding Payables (Clickable) -->
    <div class="col-6 col-md-4">
        <div class="card h-100 <?php echo $stats['payables'] > 0 ? 'border-warning' : ''; ?>"
             style="cursor: pointer;" data-bs-toggle="modal" data-bs-target="#payablesModal">
            <div class="card-body d-flex align-items-center">
                <div class="me-3">
                    <i class="bi bi-arrow-up-circle text-warning" style="font-size: 2rem;"></i>
                </div>
                <div>
                    <h5 class="mb-0 text-warning"><?php echo formatCurrency($stats['payables']); ?></h5>
                    <small class="text-muted"><?php _e('outstanding_payables'); ?> <i class="bi bi-box-arrow-up-right"></i></small>
                </div>
            </div>
        </div>
    </div>

    <!-- Outstanding Receivables (Clickable) -->
    <div class="col-6 col-md-4">
        <div class="card h-100 <?php echo $stats['receivables'] > 0 ? 'border-success' : ''; ?>"
             style="cursor: pointer;" data-bs-toggle="modal" data-bs-target="#receivablesModal">
            <div class="card-body d-flex align-items-center">
                <div class="me-3">
                    <i class="bi bi-arrow-down-circle text-success" style="font-size: 2rem;"></i>
                </div>
                <div>
                    <h5 class="mb-0 text-success"><?php echo formatCurrency($stats['receivables']); ?></h5>
                    <small class="text-muted"><?php _e('outstanding_receivables'); ?> <i class="bi bi-box-arrow-up-right"></i></small>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Cost Centre Summary -->
<?php
$ccSummaryData = [];
try {
    $ccStmt = $conn->query("SELECT id, code, name, budget_amount, status FROM cost_centres WHERE is_active = 1 ORDER BY name");
    $activeCCs = $ccStmt->fetchAll(PDO::FETCH_ASSOC);
    $currentMonth = date('Y-m-01');
    $today = date('Y-m-d');

    foreach ($activeCCs as $cc) {
        $revStmt = $conn->prepare("SELECT COALESCE(SUM(total_amount * COALESCE(exchange_rate_to_base, 1)), 0) FROM sales WHERE cost_centre_id = ? AND sale_date BETWEEN ? AND ?");
        $revStmt->execute([$cc['id'], $currentMonth, $today]);
        $revenue = $revStmt->fetchColumn();

        $expStmt = $conn->prepare("
            SELECT COALESCE(SUM(p.amount * COALESCE(p.exchange_rate_to_base, 1)), 0) FROM payments p
            JOIN ledger_accounts la ON p.to_account_id = la.id
            WHERE la.account_type = 'expense' AND p.cost_centre_id = ? AND p.payment_date BETWEEN ? AND ?
        ");
        $expStmt->execute([$cc['id'], $currentMonth, $today]);
        $expenses = $expStmt->fetchColumn();

        $purStmt = $conn->prepare("SELECT COALESCE(SUM((total_amount + vat_amount) * COALESCE(exchange_rate_to_base, 1)), 0) FROM purchases WHERE cost_centre_id = ? AND purchase_date BETWEEN ? AND ?");
        $purStmt->execute([$cc['id'], $currentMonth, $today]);
        $purchases = $purStmt->fetchColumn();

        $conStmt = $conn->prepare("SELECT COALESCE(SUM(total_amount * COALESCE(exchange_rate_to_base, 1)), 0) FROM consumption_vouchers WHERE cost_centre_id = ? AND voucher_date BETWEEN ? AND ?");
        $conStmt->execute([$cc['id'], $currentMonth, $today]);
        $consumption = $conStmt->fetchColumn();

        $netPL = $revenue - $expenses - $consumption;
        $ccSummaryData[] = [
            'id' => $cc['id'],
            'code' => $cc['code'],
            'name' => $cc['name'],
            'revenue' => $revenue,
            'expenses' => $expenses,
            'consumption' => $consumption,
            'purchases' => $purchases,
            'net_pl' => $netPL
        ];
    }
    usort($ccSummaryData, function ($a, $b) { return $b['net_pl'] <=> $a['net_pl']; });
    $ccSummaryData = array_slice($ccSummaryData, 0, 5);
} catch (Exception $e) {
    $ccSummaryData = [];
}
?>
<?php if (!empty($ccSummaryData)): ?>
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-building"></i> <?php _e('cost_centre_summary'); ?></h5>
                <a href="modules/reports/cost_centre_comparison.php?date_from=<?php echo $currentMonth; ?>&date_to=<?php echo $today; ?>" class="btn btn-sm btn-outline-primary">
                    <?php _e('view_all'); ?> <i class="bi bi-arrow-right"></i>
                </a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th><?php _e('cost_centre'); ?></th>
                                <th class="text-end"><?php _e('revenue'); ?></th>
                                <th class="text-end"><?php _e('material_consumption'); ?></th>
                                <th class="text-end"><?php _e('direct_expenses'); ?></th>
                                <th class="text-end"><?php _e('net_profit'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ccSummaryData as $ccRow): ?>
                            <tr>
                                <td>
                                    <a href="modules/reports/cost_centre_report.php?cost_centre=<?php echo $ccRow['id']; ?>&date_from=<?php echo $currentMonth; ?>&date_to=<?php echo $today; ?>">
                                        <strong><?php echo htmlspecialchars($ccRow['code']); ?></strong>
                                        - <?php echo htmlspecialchars($ccRow['name']); ?>
                                    </a>
                                </td>
                                <td class="text-end"><?php echo formatCurrency($ccRow['revenue']); ?></td>
                                <td class="text-end text-danger"><?php echo formatCurrency($ccRow['consumption']); ?></td>
                                <td class="text-end text-danger"><?php echo formatCurrency($ccRow['expenses']); ?></td>
                                <td class="text-end fw-bold <?php echo $ccRow['net_pl'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo formatCurrency($ccRow['net_pl']); ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Sales Trend Chart & Negative Stock Alert Row -->
<div class="row g-3 mb-4">
    <!-- Sales Trend Chart -->
    <div class="col-12 col-lg-8">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-bar-chart"></i> <?php _e('sales_trend'); ?></h5>
                <button class="btn btn-sm btn-outline-primary" onclick="loadSalesTrend()" title="<?php _e('refresh'); ?>">
                    <i class="bi bi-arrow-clockwise"></i>
                </button>
            </div>
            <div class="card-body">
                <div id="salesChartLoader" class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden"><?php _e('loading'); ?></span>
                    </div>
                </div>
                <canvas id="salesTrendChart" style="display:none; max-height: 250px;"></canvas>
            </div>
        </div>
    </div>
    
    <!-- Negative Stock Alert -->
    <div class="col-12 col-lg-4">
        <div class="card h-100 border-danger">
            <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-exclamation-octagon"></i> <?php _e('negative_stock_alert'); ?></h5>
                <button class="btn btn-sm btn-outline-light" onclick="loadNegativeStock()" title="<?php _e('refresh'); ?>">
                    <i class="bi bi-arrow-clockwise"></i>
                </button>
            </div>
            <div class="card-body p-0" id="negativeStockContainer">
                <div class="text-center py-4">
                    <div class="spinner-border text-danger" role="status">
                        <span class="visually-hidden"><?php _e('loading'); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Recent Sales -->
<?php if (!empty($recentSales)): ?>
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-clock-history"></i> <?php _e('recent_sales'); ?></h5>
                <a href="<?php echo BASE_URL; ?>modules/transactions/sales_management.php" class="btn btn-sm btn-outline-primary"><?php _e('view_all'); ?></a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th><?php _e('sale_number'); ?></th>
                                <th><?php _e('date'); ?></th>
                                <th><?php _e('customer'); ?></th>
                                <th><?php _e('location'); ?></th>
                                <th class="text-end"><?php _e('amount'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentSales as $sale): ?>
                            <tr style="cursor: pointer;" onclick="window.location='<?php echo BASE_URL; ?>modules/transactions/sales_management.php?view=<?php echo $sale['id']; ?>'">
                                <td><span class="text-primary"><?php echo $sale['sale_number']; ?></span></td>
                                <td><?php echo formatDate($sale['sale_date']); ?></td>
                                <td><?php echo htmlspecialchars($sale['customer_name'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($sale['location_name'] ?? ''); ?></td>
                                <td class="text-end"><?php echo formatCurrency($sale['total_amount']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Low Stock Alert -->
<?php if (hasPermission('reports', 'view')): ?>
<div class="row mb-4">
    <div class="col-12">
        <div class="card border-warning">
            <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-exclamation-triangle"></i> <?php _e('low_stock_alert'); ?></h5>
                <button class="btn btn-sm btn-outline-dark" onclick="loadLowStock()" title="<?php _e('refresh'); ?>">
                    <i class="bi bi-arrow-clockwise"></i>
                </button>
            </div>
            <div class="card-body p-0" id="lowStockContainer">
                <div class="text-center py-4">
                    <div class="spinner-border text-warning" role="status">
                        <span class="visually-hidden"><?php _e('loading'); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Quick Actions -->
<div class="row g-3">
    <?php if (hasPermission('sales', 'create')): ?>
    <div class="col-6 col-md-3">
        <a href="<?php echo BASE_URL; ?>modules/transactions/sales_management.php" class="dashboard-card bg-primary text-decoration-none">
            <div class="icon">
                <i class="bi bi-cart-plus"></i>
            </div>
            <h6 class="mb-0"><?php _e('new_sale'); ?></h6>
        </a>
    </div>
    <?php endif; ?>
    
    <?php if (hasPermission('purchases', 'create')): ?>
    <div class="col-6 col-md-3">
        <a href="<?php echo BASE_URL; ?>modules/transactions/purchases.php" class="dashboard-card bg-success text-decoration-none">
            <div class="icon">
                <i class="bi bi-cart-check"></i>
            </div>
            <h6 class="mb-0"><?php _e('new_purchase'); ?></h6>
        </a>
    </div>
    <?php endif; ?>
    
    <?php if (hasPermission('reports', 'view')): ?>
    <div class="col-6 col-md-3">
        <a href="<?php echo BASE_URL; ?>modules/reports/stock_report.php" class="dashboard-card bg-info text-decoration-none">
            <div class="icon">
                <i class="bi bi-boxes"></i>
            </div>
            <h6 class="mb-0"><?php _e('stock_report'); ?></h6>
        </a>
    </div>
    <?php endif; ?>
    
    <?php if (hasPermission('products', 'view')): ?>
    <div class="col-6 col-md-3">
        <a href="<?php echo BASE_URL; ?>modules/masters/products.php" class="dashboard-card bg-warning text-decoration-none">
            <div class="icon">
                <i class="bi bi-box"></i>
            </div>
            <h6 class="mb-0"><?php _e('products'); ?></h6>
        </a>
    </div>
    <?php endif; ?>
</div>

<!-- Payables Breakdown Modal -->
<div class="modal fade" id="payablesModal" tabindex="-1" aria-labelledby="payablesModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title" id="payablesModalLabel">
                    <i class="bi bi-arrow-up-circle"></i> <?php _e('outstanding_payables'); ?> - <?php _e('breakdown'); ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php if (empty($payablesDetails)): ?>
                    <div class="text-center py-4 text-success">
                        <i class="bi bi-check-circle" style="font-size: 3rem;"></i>
                        <p class="mt-3 mb-0"><?php _e('no_outstanding_payables'); ?></p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-striped">
                            <thead class="table-warning">
                                <tr>
                                    <th><?php _e('supplier'); ?></th>
                                    <th class="text-end"><?php _e('opening_balance'); ?></th>
                                    <th class="text-end">+ <?php _e('purchases'); ?></th>
                                    <th class="text-end">- <?php _e('payments'); ?></th>
                                    <th class="text-end"><?php _e('balance'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payablesDetails as $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['supplier_name']); ?></td>
                                    <td class="text-end"><?php echo formatCurrency($row['opening_balance']); ?></td>
                                    <td class="text-end text-primary">
                                        <?php if ($row['total_purchases'] > 0): ?>
                                            <a href="modules/reports/ledger_report.php?ledger_ids[]=<?php echo (int)$row['id']; ?>"
                                               target="_blank"
                                               class="text-primary text-decoration-none"
                                               title="View full ledger (purchases, imports, journal vouchers, etc.)"
                                               style="border-bottom: 1px dashed currentColor;">
                                                <?php echo formatCurrency($row['total_purchases']); ?>
                                                <i class="bi bi-box-arrow-up-right small"></i>
                                            </a>
                                        <?php else: ?>
                                            <?php echo formatCurrency($row['total_purchases']); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end text-danger">
                                        <?php if ($row['total_payments'] > 0): ?>
                                            <a href="modules/payments/payments.php?to_account_id=<?php echo (int)$row['id']; ?>"
                                               target="_blank"
                                               class="text-danger text-decoration-none"
                                               title="<?php _e('view_all_payments', 'View all payments'); ?>"
                                               style="border-bottom: 1px dashed currentColor;">
                                                <?php echo formatCurrency($row['total_payments']); ?>
                                                <i class="bi bi-box-arrow-up-right small"></i>
                                            </a>
                                        <?php else: ?>
                                            <?php echo formatCurrency($row['total_payments']); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end fw-bold text-warning"><?php echo formatCurrency($row['calculated_balance']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-warning">
                                <tr class="fw-bold">
                                    <td><?php _e('total'); ?></td>
                                    <td class="text-end"><?php echo formatCurrency(array_sum(array_column($payablesDetails, 'opening_balance'))); ?></td>
                                    <td class="text-end text-primary"><?php echo formatCurrency(array_sum(array_column($payablesDetails, 'total_purchases'))); ?></td>
                                    <td class="text-end text-danger"><?php echo formatCurrency(array_sum(array_column($payablesDetails, 'total_payments'))); ?></td>
                                    <td class="text-end text-warning"><?php echo formatCurrency($stats['payables']); ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <div class="alert alert-info mt-3 mb-0">
                        <i class="bi bi-info-circle"></i>
                        <strong><?php _e('formula'); ?>:</strong> <?php _e('opening_balance'); ?> + <?php _e('purchases'); ?> - <?php _e('payments'); ?> = <?php _e('balance'); ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php _e('close'); ?></button>
            </div>
        </div>
    </div>
</div>

<!-- Receivables Breakdown Modal -->
<div class="modal fade" id="receivablesModal" tabindex="-1" aria-labelledby="receivablesModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="receivablesModalLabel">
                    <i class="bi bi-arrow-down-circle"></i> <?php _e('outstanding_receivables'); ?> - <?php _e('breakdown'); ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php if (empty($receivablesDetails)): ?>
                    <div class="text-center py-4 text-success">
                        <i class="bi bi-check-circle" style="font-size: 3rem;"></i>
                        <p class="mt-3 mb-0"><?php _e('no_outstanding_receivables'); ?></p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-striped">
                            <thead class="table-success">
                                <tr>
                                    <th><?php _e('customer'); ?></th>
                                    <th class="text-end"><?php _e('opening_balance'); ?></th>
                                    <th class="text-end">+ <?php _e('sales'); ?></th>
                                    <th class="text-end">- <?php _e('receipts'); ?></th>
                                    <th class="text-end"><?php _e('balance'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($receivablesDetails as $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['customer_name']); ?></td>
                                    <td class="text-end"><?php echo formatCurrency($row['opening_balance']); ?></td>
                                    <td class="text-end text-primary"><?php echo formatCurrency($row['total_sales']); ?></td>
                                    <td class="text-end text-danger"><?php echo formatCurrency($row['total_receipts']); ?></td>
                                    <td class="text-end fw-bold text-success"><?php echo formatCurrency($row['calculated_balance']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-success">
                                <tr class="fw-bold">
                                    <td><?php _e('total'); ?></td>
                                    <td class="text-end"><?php echo formatCurrency(array_sum(array_column($receivablesDetails, 'opening_balance'))); ?></td>
                                    <td class="text-end text-primary"><?php echo formatCurrency(array_sum(array_column($receivablesDetails, 'total_sales'))); ?></td>
                                    <td class="text-end text-danger"><?php echo formatCurrency(array_sum(array_column($receivablesDetails, 'total_receipts'))); ?></td>
                                    <td class="text-end text-success"><?php echo formatCurrency($stats['receivables']); ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <div class="alert alert-info mt-3 mb-0">
                        <i class="bi bi-info-circle"></i>
                        <strong><?php _e('formula'); ?>:</strong> <?php _e('opening_balance'); ?> + <?php _e('sales'); ?> - <?php _e('receipts'); ?> = <?php _e('balance'); ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php _e('close'); ?></button>
            </div>
        </div>
    </div>
</div>

<!-- Cash Balance Breakdown Modal -->
<div class="modal fade" id="cashModal" tabindex="-1" aria-labelledby="cashModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="cashModalLabel">
                    <i class="bi bi-wallet2"></i> <?php _e('cash_balance'); ?> - <?php _e('breakdown'); ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php if (empty($cashDetails)): ?>
                    <div class="text-center py-4 text-muted">
                        <i class="bi bi-wallet2" style="font-size: 3rem;"></i>
                        <p class="mt-3 mb-0"><?php _e('no_cash_accounts', 'No active cash accounts'); ?></p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-striped">
                            <thead class="table-info">
                                <tr>
                                    <th><?php _e('account'); ?></th>
                                    <th class="text-end"><?php _e('opening_balance'); ?></th>
                                    <th class="text-end">+ <?php _e('cash_in', 'Cash In'); ?></th>
                                    <th class="text-end">- <?php _e('cash_out', 'Cash Out'); ?></th>
                                    <th class="text-end"><?php _e('balance'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cashDetails as $row): ?>
                                <tr>
                                    <td>
                                        <a href="modules/reports/ledger_report.php?ledger_ids[]=<?php echo (int)$row['id']; ?>"
                                           target="_blank"
                                           class="text-decoration-none"
                                           title="<?php _e('view_ledger', 'View full ledger'); ?>"
                                           style="border-bottom: 1px dashed currentColor;">
                                            <?php echo htmlspecialchars($row['account_name']); ?>
                                            <i class="bi bi-box-arrow-up-right small"></i>
                                        </a>
                                    </td>
                                    <td class="text-end"><?php echo formatCurrency($row['opening_balance']); ?></td>
                                    <td class="text-end text-success"><?php echo formatCurrency($row['total_dr']); ?></td>
                                    <td class="text-end text-danger"><?php echo formatCurrency($row['total_cr']); ?></td>
                                    <td class="text-end fw-bold text-info"><?php echo formatCurrency($row['calculated_balance']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-info">
                                <tr class="fw-bold">
                                    <td><?php _e('total'); ?></td>
                                    <td class="text-end"><?php echo formatCurrency(array_sum(array_column($cashDetails, 'opening_balance'))); ?></td>
                                    <td class="text-end text-success"><?php echo formatCurrency(array_sum(array_column($cashDetails, 'total_dr'))); ?></td>
                                    <td class="text-end text-danger"><?php echo formatCurrency(array_sum(array_column($cashDetails, 'total_cr'))); ?></td>
                                    <td class="text-end text-info"><?php echo formatCurrency($stats['cash_balance']); ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <div class="alert alert-info mt-3 mb-0">
                        <i class="bi bi-info-circle"></i>
                        <strong><?php _e('formula'); ?>:</strong> <?php _e('opening_balance'); ?> + <?php _e('cash_in', 'Cash In'); ?> - <?php _e('cash_out', 'Cash Out'); ?> = <?php _e('balance'); ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php _e('close'); ?></button>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<script>
let salesChart = null;

document.addEventListener('DOMContentLoaded', function() {
    // Load Sales Trend Chart
    loadSalesTrend();

    // Load Negative Stock Alert
    loadNegativeStock();

    // Load Low Stock Alert
    loadLowStock();

    // Load Total Stock Value
    loadStockValue();
});

function loadStockValue() {
    const target = document.getElementById('stockValueAmount');
    if (!target) return;

    const typeCells = document.querySelectorAll('[data-stock-type]');

    fetch('<?php echo BASE_URL; ?>ajax/dashboard-data.php?action=stock_value')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                target.textContent = data.formatted;
                const byType = data.by_type || {};
                typeCells.forEach(cell => {
                    const key = cell.getAttribute('data-stock-type');
                    cell.textContent = (byType[key] && byType[key].formatted) ? byType[key].formatted : '—';
                });
            } else {
                target.innerHTML = '<small class="text-muted">—</small>';
                typeCells.forEach(cell => { cell.textContent = '—'; });
            }
        })
        .catch(() => {
            target.innerHTML = '<small class="text-muted">—</small>';
            typeCells.forEach(cell => { cell.textContent = '—'; });
        });
}

function loadSalesTrend() {
    document.getElementById('salesChartLoader').style.display = 'block';
    const canvas = document.getElementById('salesTrendChart');
    canvas.style.display = 'none';
    
    // Destroy existing chart if any
    if (salesChart) {
        salesChart.destroy();
        salesChart = null;
    }
    
    fetch('<?php echo BASE_URL; ?>ajax/dashboard-data.php?action=sales_trend')
        .then(response => response.json())
        .then(data => {
            document.getElementById('salesChartLoader').style.display = 'none';
            canvas.style.display = 'block';
            
            if (data.success) {
                salesChart = new Chart(canvas, {
                    type: 'bar',
                    data: {
                        labels: data.labels,
                        datasets: [{
                            label: '<?php _e('sales'); ?>',
                            data: data.data,
                            backgroundColor: 'rgba(13, 110, 253, 0.7)',
                            borderColor: 'rgba(13, 110, 253, 1)',
                            borderWidth: 1,
                            borderRadius: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) {
                                        return '<?php echo $defaultCurrencySymbol; ?>' + value.toLocaleString();
                                    }
                                }
                            }
                        }
                    }
                });
            }
        })
        .catch(err => {
            document.getElementById('salesChartLoader').innerHTML = '<p class="text-muted"><?php _e('error'); ?></p>';
        });
}

function loadNegativeStock() {
    fetch('<?php echo BASE_URL; ?>ajax/dashboard-data.php?action=negative_stock')
        .then(response => response.json())
        .then(data => {
            const container = document.getElementById('negativeStockContainer');
            
            if (data.success && data.items.length > 0) {
                let html = '<div class="list-group list-group-flush">';
                data.items.forEach(item => {
                    html += `
                        <div class="list-group-item d-flex justify-content-between align-items-center py-2">
                            <div>
                                <strong class="d-block">${item.product_name}</strong>
                                <small class="text-muted">${item.location_name}</small>
                            </div>
                            <span class="badge bg-danger">${item.current_stock} ${item.unit}</span>
                        </div>
                    `;
                });
                html += '</div>';
                container.innerHTML = html;
            } else {
                container.innerHTML = `
                    <div class="text-center py-4 text-success">
                        <i class="bi bi-check-circle" style="font-size: 2rem;"></i>
                        <p class="mb-0 mt-2"><?php _e('no_negative_stock'); ?></p>
                    </div>
                `;
            }
        })
        .catch(err => {
            document.getElementById('negativeStockContainer').innerHTML = '<p class="text-muted text-center py-3"><?php _e('error'); ?></p>';
        });
}

function loadLowStock() {
    const container = document.getElementById('lowStockContainer');
    if (!container) return;
    
    container.innerHTML = `
        <div class="text-center py-4">
            <div class="spinner-border text-warning" role="status">
                <span class="visually-hidden"><?php _e('loading'); ?></span>
            </div>
        </div>
    `;
    
    fetch('<?php echo BASE_URL; ?>ajax/dashboard-data.php?action=low_stock')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.items.length > 0) {
                let html = '<div class="table-responsive"><table class="table table-hover mb-0"><thead><tr>';
                html += '<th><?php _e('product'); ?></th>';
                html += '<th><?php _e('location'); ?></th>';
                html += '<th><?php _e('current_stock'); ?></th>';
                html += '</tr></thead><tbody>';
                data.items.forEach(item => {
                    html += `
                        <tr>
                            <td>${item.product_name}</td>
                            <td>${item.location_name}</td>
                            <td><span class="badge bg-warning text-dark">${item.current_stock} ${item.unit}</span></td>
                        </tr>
                    `;
                });
                html += '</tbody></table></div>';
                container.innerHTML = html;
            } else {
                container.innerHTML = `
                    <div class="text-center py-4 text-success">
                        <i class="bi bi-check-circle" style="font-size: 2rem;"></i>
                        <p class="mb-0 mt-2"><?php _e('no_low_stock'); ?></p>
                    </div>
                `;
            }
        })
        .catch(err => {
            container.innerHTML = '<p class="text-muted text-center py-3"><?php _e('error'); ?></p>';
        });
}
</script>

<?php require_once 'includes/footer.php'; ?>
