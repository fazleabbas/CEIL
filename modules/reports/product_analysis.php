<?php
require_once '../../config/database.php';
require_once '../../includes/functions.php';

$pageTitle = __('product_analysis_report');

if (!canViewReport('product_analysis')) {
    setAlert(__('no_permission'), 'danger');
    redirect('');
}

require_once '../../includes/header.php';

// Get filters
$locationFilter = $_GET['location'] ?? '';
$productFilter = $_GET['product'] ?? '';
$supplierFilter = $_GET['supplier'] ?? '';
$customerFilter = $_GET['customer'] ?? '';
$dateFrom = $_GET['date_from'] ?? date('Y-m-01'); // First day of month
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

// Get user locations
$userLocations = getUserLocations();

// Get all active products
$productsStmt = $conn->query("
    SELECT p.id, p.name, p.barcode, pc.name as category_name, p.unit
    FROM products p
    LEFT JOIN product_categories pc ON p.category_id = pc.id
    WHERE p.is_active = 1
    ORDER BY p.name
");
$products = $productsStmt->fetchAll();

// Get all active suppliers
$suppliersStmt = $conn->query("
    SELECT s.id, la.name
    FROM suppliers s
    JOIN ledger_accounts la ON s.ledger_account_id = la.id
    WHERE s.is_active = 1
    ORDER BY la.name
");
$suppliers = $suppliersStmt->fetchAll();

// Get all active customers
$customersStmt = $conn->query("
    SELECT c.id, la.name
    FROM customers c
    JOIN ledger_accounts la ON c.ledger_account_id = la.id
    WHERE c.is_active = 1
    ORDER BY la.name
");
$customers = $customersStmt->fetchAll();

// Get product details if selected
$productDetails = null;
$reportData = null;

if ($productFilter && $locationFilter) {
    $prodDetailStmt = $conn->prepare("
        SELECT p.*, pc.name as category_name
        FROM products p
        LEFT JOIN product_categories pc ON p.category_id = pc.id
        WHERE p.id = ?
    ");
    $prodDetailStmt->execute([$productFilter]);
    $productDetails = $prodDetailStmt->fetch();
    
    if ($productDetails) {
        // Build location condition
        $locationCondition = '';
        $locationParams = [];
        
        if ($locationFilter == 'all') {
            if (!empty($userLocations)) {
                $locationIds = array_column($userLocations, 'id');
                $locInClause = implode(',', array_map('intval', $locationIds));
                $locationCondition = "location_id IN ($locInClause)";
                $locationParams = [];
            }
        } else {
            // Aggregate parent + all descendant locations
            $locationIds = getDescendantLocationIds($locationFilter, $conn);
            $locInClause = implode(',', array_map('intval', $locationIds));
            $locationCondition = "location_id IN ($locInClause)";
            $locationParams = [];
        }
        
        // Build supplier condition for purchases
        $supplierCondition = '';
        $supplierParams = [];
        if ($supplierFilter) {
            $supplierCondition = "AND p.supplier_id = ?";
            $supplierParams = [$supplierFilter];
        }
        
        // Build customer condition for sales
        $customerCondition = '';
        $customerParams = [];
        if ($customerFilter) {
            $customerCondition = "AND s.customer_id = ?";
            $customerParams = [$customerFilter];
        }
        
        // =====================
        // PURCHASE DATA (converted to base currency)
        // =====================
        $purchaseQuery = "
            SELECT
                COUNT(DISTINCT p.id) as num_purchases,
                COALESCE(SUM(pd.quantity), 0) as total_qty,
                COALESCE(SUM(pd.amount * COALESCE(p.exchange_rate_to_base, 1)), 0) as total_amount,
                COALESCE(AVG(pd.rate_per_unit * COALESCE(p.exchange_rate_to_base, 1)), 0) as avg_rate
            FROM purchase_details pd
            JOIN purchases p ON pd.purchase_id = p.id
            WHERE pd.product_id = ?
            AND p.$locationCondition
            AND p.purchase_date BETWEEN ? AND ?
            $supplierCondition
        ";
        $purchaseStmt = $conn->prepare($purchaseQuery);
        $purchaseStmt->execute(array_merge(
            [$productFilter],
            $locationParams,
            [$dateFrom, $dateTo],
            $supplierParams
        ));
        $purchaseData = $purchaseStmt->fetch();

        // Add purchase imports to purchase data
        $importQuery = "
            SELECT
                COUNT(DISTINCT pi.id) as num_imports,
                COALESCE(SUM(pid.quantity), 0) as total_qty,
                COALESCE(SUM(pid.quantity * pid.landed_rate_per_unit * COALESCE(pi.exchange_rate_to_base, 1)), 0) as total_amount
            FROM purchase_import_details pid
            JOIN purchase_imports pi ON pid.import_id = pi.id
            WHERE pid.product_id = ?
            AND pi.$locationCondition
            AND pi.import_date BETWEEN ? AND ?
            $supplierCondition
        ";
        $importStmt = $conn->prepare($importQuery);
        $importStmt->execute(array_merge(
            [$productFilter],
            $locationParams,
            [$dateFrom, $dateTo],
            $supplierParams
        ));
        $importData = $importStmt->fetch();
        $purchaseData['num_purchases'] += $importData['num_imports'];
        $purchaseData['total_qty'] += $importData['total_qty'];
        $purchaseData['total_amount'] += $importData['total_amount'];

        // =====================
        // SALES DATA (converted to base currency)
        // =====================
        $salesQuery = "
            SELECT
                COUNT(DISTINCT s.id) as num_sales,
                COALESCE(SUM(sd.quantity), 0) as total_qty,
                COALESCE(SUM(sd.amount * COALESCE(s.exchange_rate_to_base, 1)), 0) as total_amount,
                COALESCE(AVG(sd.rate_per_unit * COALESCE(s.exchange_rate_to_base, 1)), 0) as avg_rate
            FROM sale_details sd
            JOIN sales s ON sd.sale_id = s.id
            WHERE sd.product_id = ?
            AND s.$locationCondition
            AND s.sale_date BETWEEN ? AND ?
            $customerCondition
        ";
        $salesStmt = $conn->prepare($salesQuery);
        $salesStmt->execute(array_merge(
            [$productFilter],
            $locationParams,
            [$dateFrom, $dateTo],
            $customerParams
        ));
        $salesData = $salesStmt->fetch();
        
        // =====================
        // CALCULATE COGS (FIFO)
        // =====================
        $cogs = 0;
        if ($salesData['total_qty'] > 0) {
            // Get batches available before/during the period
            $batchQuery = "
                SELECT sb.rate_per_unit, sb.quantity_in
                FROM stock_batches sb
                WHERE sb.product_id = ?
                AND sb.$locationCondition
                AND sb.batch_date <= ?
                ORDER BY sb.batch_date ASC, sb.id ASC
            ";
            $batchStmt = $conn->prepare($batchQuery);
            $batchStmt->execute(array_merge([$productFilter], $locationParams, [$dateTo]));
            $batches = $batchStmt->fetchAll();
            
            $remainingQty = $salesData['total_qty'];
            foreach ($batches as $batch) {
                if ($remainingQty <= 0) break;
                $qtyFromBatch = min($remainingQty, $batch['quantity_in']);
                $cogs += $qtyFromBatch * $batch['rate_per_unit'];
                $remainingQty -= $qtyFromBatch;
            }
        }
        
        // Calculate profit
        $totalProfit = $salesData['total_amount'] - $cogs;
        $profitMargin = $salesData['total_amount'] > 0 
            ? ($totalProfit / $salesData['total_amount']) * 100 
            : 0;
        
        // =====================
        // SUPPLIER BREAKDOWN
        // =====================
        $supplierBreakdownQuery = "
            SELECT
                s.id as supplier_id,
                la.name as supplier_name,
                COUNT(DISTINCT p.id) as num_purchases,
                COALESCE(SUM(pd.quantity), 0) as total_qty,
                COALESCE(SUM(pd.amount * COALESCE(p.exchange_rate_to_base, 1)), 0) as total_amount,
                COALESCE(AVG(pd.rate_per_unit * COALESCE(p.exchange_rate_to_base, 1)), 0) as avg_rate
            FROM purchase_details pd
            JOIN purchases p ON pd.purchase_id = p.id
            JOIN suppliers s ON p.supplier_id = s.id
            JOIN ledger_accounts la ON s.ledger_account_id = la.id
            WHERE pd.product_id = ?
            AND p.$locationCondition
            AND p.purchase_date BETWEEN ? AND ?
            $supplierCondition
            GROUP BY s.id, la.name
            ORDER BY total_amount DESC
        ";
        $supplierBreakdownStmt = $conn->prepare($supplierBreakdownQuery);
        $supplierBreakdownStmt->execute(array_merge(
            [$productFilter],
            $locationParams,
            [$dateFrom, $dateTo],
            $supplierParams
        ));
        $supplierBreakdown = $supplierBreakdownStmt->fetchAll();

        // Add purchase import supplier breakdown
        $importSupplierQuery = "
            SELECT
                s.id as supplier_id,
                la.name as supplier_name,
                COUNT(DISTINCT pi.id) as num_purchases,
                COALESCE(SUM(pid.quantity), 0) as total_qty,
                COALESCE(SUM(pid.quantity * pid.landed_rate_per_unit * COALESCE(pi.exchange_rate_to_base, 1)), 0) as total_amount,
                COALESCE(AVG(pid.landed_rate_per_unit * COALESCE(pi.exchange_rate_to_base, 1)), 0) as avg_rate
            FROM purchase_import_details pid
            JOIN purchase_imports pi ON pid.import_id = pi.id
            JOIN suppliers s ON pi.supplier_id = s.id
            JOIN ledger_accounts la ON s.ledger_account_id = la.id
            WHERE pid.product_id = ?
            AND pi.$locationCondition
            AND pi.import_date BETWEEN ? AND ?
            $supplierCondition
            GROUP BY s.id, la.name
            ORDER BY total_amount DESC
        ";
        $importSupplierStmt = $conn->prepare($importSupplierQuery);
        $importSupplierStmt->execute(array_merge(
            [$productFilter],
            $locationParams,
            [$dateFrom, $dateTo],
            $supplierParams
        ));
        $importSupplierBreakdown = $importSupplierStmt->fetchAll();

        // Merge import supplier data into supplier breakdown
        foreach ($importSupplierBreakdown as $impRow) {
            $found = false;
            foreach ($supplierBreakdown as &$supRow) {
                if ($supRow['supplier_id'] == $impRow['supplier_id']) {
                    $supRow['num_purchases'] += $impRow['num_purchases'];
                    $totalQtyBefore = $supRow['total_qty'];
                    $supRow['total_qty'] += $impRow['total_qty'];
                    $supRow['total_amount'] += $impRow['total_amount'];
                    // Recalculate weighted average rate
                    if ($supRow['total_qty'] > 0) {
                        $supRow['avg_rate'] = $supRow['total_amount'] / $supRow['total_qty'];
                    }
                    $found = true;
                    break;
                }
            }
            unset($supRow);
            if (!$found) {
                $supplierBreakdown[] = $impRow;
            }
        }
        // Re-sort by total_amount desc
        usort($supplierBreakdown, function($a, $b) {
            return $b['total_amount'] <=> $a['total_amount'];
        });

        // =====================
        // CUSTOMER BREAKDOWN
        // =====================
        $customerBreakdownQuery = "
            SELECT
                c.id as customer_id,
                la.name as customer_name,
                COUNT(DISTINCT s.id) as num_sales,
                COALESCE(SUM(sd.quantity), 0) as total_qty,
                COALESCE(SUM(sd.amount * COALESCE(s.exchange_rate_to_base, 1)), 0) as total_amount,
                COALESCE(AVG(sd.rate_per_unit * COALESCE(s.exchange_rate_to_base, 1)), 0) as avg_rate
            FROM sale_details sd
            JOIN sales s ON sd.sale_id = s.id
            JOIN customers c ON s.customer_id = c.id
            JOIN ledger_accounts la ON c.ledger_account_id = la.id
            WHERE sd.product_id = ?
            AND s.$locationCondition
            AND s.sale_date BETWEEN ? AND ?
            $customerCondition
            GROUP BY c.id, la.name
            ORDER BY total_amount DESC
        ";
        $customerBreakdownStmt = $conn->prepare($customerBreakdownQuery);
        $customerBreakdownStmt->execute(array_merge(
            [$productFilter],
            $locationParams,
            [$dateFrom, $dateTo],
            $customerParams
        ));
        $customerBreakdown = $customerBreakdownStmt->fetchAll();
        
        // =====================
        // TREND DATA (Purchase vs Sales over time)
        // =====================
        // Determine grouping based on date range
        $daysDiff = (strtotime($dateTo) - strtotime($dateFrom)) / (60 * 60 * 24);
        if ($daysDiff <= 31) {
            $dateFormat = '%Y-%m-%d';
            $phpFormat = 'Y-m-d';
            $groupLabel = 'daily';
        } elseif ($daysDiff <= 90) {
            $dateFormat = '%x-W%v'; // Year-Week
            $phpFormat = 'Y-\WW';
            $groupLabel = 'weekly';
        } else {
            $dateFormat = '%Y-%m';
            $phpFormat = 'Y-m';
            $groupLabel = 'monthly';
        }
        
        // Purchase trend
        $purchaseTrendQuery = "
            SELECT 
                DATE_FORMAT(p.purchase_date, '$dateFormat') as period,
                COALESCE(SUM(pd.quantity), 0) as total_qty
            FROM purchase_details pd
            JOIN purchases p ON pd.purchase_id = p.id
            WHERE pd.product_id = ?
            AND p.$locationCondition
            AND p.purchase_date BETWEEN ? AND ?
            $supplierCondition
            GROUP BY period
            ORDER BY period ASC
        ";
        $purchaseTrendStmt = $conn->prepare($purchaseTrendQuery);
        $purchaseTrendStmt->execute(array_merge(
            [$productFilter],
            $locationParams,
            [$dateFrom, $dateTo],
            $supplierParams
        ));
        $purchaseTrend = $purchaseTrendStmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // Purchase import trend (merge into purchase trend)
        $importTrendQuery = "
            SELECT
                DATE_FORMAT(pi.import_date, '$dateFormat') as period,
                COALESCE(SUM(pid.quantity), 0) as total_qty
            FROM purchase_import_details pid
            JOIN purchase_imports pi ON pid.import_id = pi.id
            WHERE pid.product_id = ?
            AND pi.$locationCondition
            AND pi.import_date BETWEEN ? AND ?
            $supplierCondition
            GROUP BY period
            ORDER BY period ASC
        ";
        $importTrendStmt = $conn->prepare($importTrendQuery);
        $importTrendStmt->execute(array_merge(
            [$productFilter],
            $locationParams,
            [$dateFrom, $dateTo],
            $supplierParams
        ));
        $importTrend = $importTrendStmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // Merge import trend into purchase trend
        foreach ($importTrend as $period => $qty) {
            if (isset($purchaseTrend[$period])) {
                $purchaseTrend[$period] += $qty;
            } else {
                $purchaseTrend[$period] = $qty;
            }
        }
        ksort($purchaseTrend);

        // Sales trend
        $salesTrendQuery = "
            SELECT 
                DATE_FORMAT(s.sale_date, '$dateFormat') as period,
                COALESCE(SUM(sd.quantity), 0) as total_qty
            FROM sale_details sd
            JOIN sales s ON sd.sale_id = s.id
            WHERE sd.product_id = ?
            AND s.$locationCondition
            AND s.sale_date BETWEEN ? AND ?
            $customerCondition
            GROUP BY period
            ORDER BY period ASC
        ";
        $salesTrendStmt = $conn->prepare($salesTrendQuery);
        $salesTrendStmt->execute(array_merge(
            [$productFilter],
            $locationParams,
            [$dateFrom, $dateTo],
            $customerParams
        ));
        $salesTrend = $salesTrendStmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Merge periods for consistent x-axis
        $allPeriods = array_unique(array_merge(array_keys($purchaseTrend), array_keys($salesTrend)));
        sort($allPeriods);
        
        $trendData = [
            'labels' => $allPeriods,
            'purchases' => [],
            'sales' => [],
            'group_label' => $groupLabel
        ];
        
        foreach ($allPeriods as $period) {
            $trendData['purchases'][] = isset($purchaseTrend[$period]) ? floatval($purchaseTrend[$period]) : 0;
            $trendData['sales'][] = isset($salesTrend[$period]) ? floatval($salesTrend[$period]) : 0;
        }
        
        // =====================
        // TRANSACTION DETAILS
        // =====================
        $transactions = [];
        
        // Get purchase transactions
        $purchaseTransQuery = "
            SELECT 
                p.purchase_date as trans_date,
                'purchase' as trans_type,
                p.purchase_number as reference,
                la.name as party_name,
                pd.quantity,
                pd.rate_per_unit,
                pd.amount,
                p.id as trans_id
            FROM purchase_details pd
            JOIN purchases p ON pd.purchase_id = p.id
            JOIN suppliers s ON p.supplier_id = s.id
            JOIN ledger_accounts la ON s.ledger_account_id = la.id
            WHERE pd.product_id = ?
            AND p.$locationCondition
            AND p.purchase_date BETWEEN ? AND ?
            $supplierCondition
            ORDER BY p.purchase_date DESC, p.id DESC
        ";
        $purchaseTransStmt = $conn->prepare($purchaseTransQuery);
        $purchaseTransStmt->execute(array_merge(
            [$productFilter],
            $locationParams,
            [$dateFrom, $dateTo],
            $supplierParams
        ));
        $purchaseTransactions = $purchaseTransStmt->fetchAll();
        
        foreach ($purchaseTransactions as $row) {
            $transactions[] = $row;
        }

        // Get purchase import transactions
        $importTransQuery = "
            SELECT
                pi.import_date as trans_date,
                'purchase_import' as trans_type,
                pi.import_number as reference,
                la.name as party_name,
                pid.quantity,
                pid.landed_rate_per_unit as rate_per_unit,
                (pid.quantity * pid.landed_rate_per_unit) as amount,
                pi.id as trans_id
            FROM purchase_import_details pid
            JOIN purchase_imports pi ON pid.import_id = pi.id
            JOIN suppliers s ON pi.supplier_id = s.id
            JOIN ledger_accounts la ON s.ledger_account_id = la.id
            WHERE pid.product_id = ?
            AND pi.$locationCondition
            AND pi.import_date BETWEEN ? AND ?
            $supplierCondition
            ORDER BY pi.import_date DESC, pi.id DESC
        ";
        $importTransStmt = $conn->prepare($importTransQuery);
        $importTransStmt->execute(array_merge(
            [$productFilter],
            $locationParams,
            [$dateFrom, $dateTo],
            $supplierParams
        ));
        $importTransactions = $importTransStmt->fetchAll();

        foreach ($importTransactions as $row) {
            $transactions[] = $row;
        }

        // Get sales transactions
        $salesTransQuery = "
            SELECT 
                s.sale_date as trans_date,
                'sale' as trans_type,
                s.sale_number as reference,
                la.name as party_name,
                sd.quantity,
                sd.rate_per_unit,
                sd.amount,
                s.id as trans_id
            FROM sale_details sd
            JOIN sales s ON sd.sale_id = s.id
            JOIN customers c ON s.customer_id = c.id
            JOIN ledger_accounts la ON c.ledger_account_id = la.id
            WHERE sd.product_id = ?
            AND s.$locationCondition
            AND s.sale_date BETWEEN ? AND ?
            $customerCondition
            ORDER BY s.sale_date DESC, s.id DESC
        ";
        $salesTransStmt = $conn->prepare($salesTransQuery);
        $salesTransStmt->execute(array_merge(
            [$productFilter],
            $locationParams,
            [$dateFrom, $dateTo],
            $customerParams
        ));
        $salesTransactions = $salesTransStmt->fetchAll();
        
        foreach ($salesTransactions as $row) {
            $transactions[] = $row;
        }
        
        // Sort by date descending
        usort($transactions, function($a, $b) {
            return strtotime($b['trans_date']) - strtotime($a['trans_date']);
        });
        
        $reportData = [
            'purchases' => [
                'count' => $purchaseData['num_purchases'],
                'qty' => $purchaseData['total_qty'],
                'amount' => $purchaseData['total_amount'],
                'avg_rate' => $purchaseData['avg_rate']
            ],
            'sales' => [
                'count' => $salesData['num_sales'],
                'qty' => $salesData['total_qty'],
                'amount' => $salesData['total_amount'],
                'avg_rate' => $salesData['avg_rate']
            ],
            'cogs' => $cogs,
            'profit' => $totalProfit,
            'profit_margin' => $profitMargin,
            'supplier_breakdown' => $supplierBreakdown,
            'customer_breakdown' => $customerBreakdown,
            'trend' => $trendData,
            'transactions' => $transactions
        ];
    }
}
?>

<div class="row mb-3">
    <div class="col-12">
        <h2><i class="bi bi-bar-chart-line"></i> <?php _e('product_analysis_report'); ?></h2>
    </div>
</div>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="" id="filterForm">
            <div class="row g-3">
                <!-- Location -->
                <div class="col-md-2 col-12">
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
                
                <!-- Date From -->
                <div class="col-md-2 col-6">
                    <label class="form-label required"><?php _e('date_from'); ?></label>
                    <input type="date" name="date_from" class="form-control"
                           value="<?php echo $dateFrom; ?>" required>
                </div>
                
                <!-- Date To -->
                <div class="col-md-2 col-6">
                    <label class="form-label required"><?php _e('date_to'); ?></label>
                    <input type="date" name="date_to" class="form-control"
                           value="<?php echo $dateTo; ?>" required>
                </div>
                
                <!-- Product (Select2) -->
                <div class="col-md-3 col-12">
                    <label class="form-label required"><?php _e('product'); ?></label>
                    <select name="product" id="productSelect" class="form-select" required>
                        <option value=""><?php _e('select_product'); ?></option>
                        <?php foreach ($products as $prod): ?>
                        <option value="<?php echo $prod['id']; ?>"
                                <?php echo $productFilter == $prod['id'] ? 'selected' : ''; ?>
                                data-barcode="<?php echo htmlspecialchars($prod['barcode'] ?? ''); ?>"
                                data-category="<?php echo htmlspecialchars($prod['category_name'] ?? ''); ?>">
                            <?php echo htmlspecialchars($prod['name']); ?>
                            <?php if ($prod['barcode']): ?>(<?php echo htmlspecialchars($prod['barcode']); ?>)<?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Generate Button -->
                <div class="col-md-3 col-12 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-search"></i> <?php _e('generate_report'); ?>
                    </button>
                </div>
            </div>
            
            <!-- Second Row: Supplier & Customer Filters -->
            <div class="row g-3 mt-1">
                <!-- Supplier -->
                <div class="col-md-4 col-12">
                    <label class="form-label"><?php _e('supplier'); ?></label>
                    <select name="supplier" id="supplierSelect" class="form-select">
                        <option value=""><?php _e('all_suppliers'); ?></option>
                        <?php foreach ($suppliers as $sup): ?>
                        <option value="<?php echo $sup['id']; ?>"
                                <?php echo $supplierFilter == $sup['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($sup['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Customer -->
                <div class="col-md-4 col-12">
                    <label class="form-label"><?php _e('customer'); ?></label>
                    <select name="customer" id="customerSelect" class="form-select">
                        <option value=""><?php _e('all_customers'); ?></option>
                        <?php foreach ($customers as $cust): ?>
                        <option value="<?php echo $cust['id']; ?>"
                                <?php echo $customerFilter == $cust['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cust['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </form>
    </div>
</div>

<?php if ($locationFilter && $productFilter && $productDetails): ?>
<!-- Product Info Header -->
<div class="card mb-3">
    <div class="card-header bg-primary text-white">
        <div class="d-flex justify-content-between align-items-center flex-wrap">
            <div>
                <h5 class="mb-0">
                    <?php echo htmlspecialchars($productDetails['name']); ?>
                    <?php if ($productDetails['barcode']): ?>
                    <small class="ms-2">(<?php echo htmlspecialchars($productDetails['barcode']); ?>)</small>
                    <?php endif; ?>
                </h5>
                <small>
                    <?php echo htmlspecialchars($productDetails['category_name'] ?? 'N/A'); ?> | 
                    <?php _e('unit'); ?>: <?php echo htmlspecialchars($productDetails['unit'] ?? 'N/A'); ?>
                </small>
            </div>
            <div class="mt-2 mt-md-0">
                <span class="badge bg-light text-dark">
                    <?php echo formatDate($dateFrom); ?> <?php _e('to'); ?> <?php echo formatDate($dateTo); ?>
                </span>
            </div>
        </div>
    </div>
</div>

<?php if ($reportData): ?>
<!-- Summary Cards -->
<div class="row g-3 mb-3">
    <!-- Total Purchases -->
    <div class="col-md-4 col-6">
        <div class="card h-100 border-primary">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">
                    <i class="bi bi-cart-plus text-primary"></i> <?php _e('total_purchases'); ?>
                </h6>
                <h4 class="mb-1"><?php echo formatCurrency($reportData['purchases']['amount']); ?></h4>
                <small class="text-muted">
                    <?php echo formatNumber($reportData['purchases']['qty'], 2); ?> <?php echo $productDetails['unit']; ?> 
                    (<?php echo $reportData['purchases']['count']; ?> <?php _e('num_purchases'); ?>)
                </small>
            </div>
        </div>
    </div>
    
    <!-- Total Sales -->
    <div class="col-md-4 col-6">
        <div class="card h-100 border-success">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">
                    <i class="bi bi-cart-check text-success"></i> <?php _e('total_sales'); ?>
                </h6>
                <h4 class="mb-1"><?php echo formatCurrency($reportData['sales']['amount']); ?></h4>
                <small class="text-muted">
                    <?php echo formatNumber($reportData['sales']['qty'], 2); ?> <?php echo $productDetails['unit']; ?>
                    (<?php echo $reportData['sales']['count']; ?> <?php _e('num_sales'); ?>)
                </small>
            </div>
        </div>
    </div>
    
    <!-- Total Profit -->
    <div class="col-md-4 col-12">
        <div class="card h-100 <?php echo $reportData['profit'] >= 0 ? 'border-success bg-success bg-opacity-10' : 'border-danger bg-danger bg-opacity-10'; ?>">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">
                    <i class="bi bi-graph-up-arrow"></i> <?php _e('total_profit'); ?>
                </h6>
                <h4 class="mb-1 <?php echo $reportData['profit'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                    <?php echo formatCurrency($reportData['profit']); ?>
                </h4>
                <small class="text-muted">
                    <?php _e('profit_margin'); ?>: <?php echo formatNumber($reportData['profit_margin'], 2); ?>%
                </small>
            </div>
        </div>
    </div>
</div>

<!-- Second Row: Averages -->
<div class="row g-3 mb-3">
    <!-- Avg Purchase Cost -->
    <div class="col-md-3 col-6">
        <div class="card h-100">
            <div class="card-body text-center py-3">
                <small class="text-muted d-block mb-1"><?php _e('avg_purchase_cost'); ?></small>
                <h5 class="mb-0 text-primary"><?php echo formatCurrency($reportData['purchases']['avg_rate']); ?></h5>
            </div>
        </div>
    </div>
    
    <!-- Avg Sale Price -->
    <div class="col-md-3 col-6">
        <div class="card h-100">
            <div class="card-body text-center py-3">
                <small class="text-muted d-block mb-1"><?php _e('avg_sale_price'); ?></small>
                <h5 class="mb-0 text-success"><?php echo formatCurrency($reportData['sales']['avg_rate']); ?></h5>
            </div>
        </div>
    </div>
    
    <!-- Qty Purchased -->
    <div class="col-md-3 col-6">
        <div class="card h-100">
            <div class="card-body text-center py-3">
                <small class="text-muted d-block mb-1"><?php _e('qty_purchased'); ?></small>
                <h5 class="mb-0"><?php echo formatNumber($reportData['purchases']['qty'], 2); ?> <small><?php echo $productDetails['unit']; ?></small></h5>
            </div>
        </div>
    </div>
    
    <!-- Qty Sold -->
    <div class="col-md-3 col-6">
        <div class="card h-100">
            <div class="card-body text-center py-3">
                <small class="text-muted d-block mb-1"><?php _e('qty_sold'); ?></small>
                <h5 class="mb-0"><?php echo formatNumber($reportData['sales']['qty'], 2); ?> <small><?php echo $productDetails['unit']; ?></small></h5>
            </div>
        </div>
    </div>
</div>

<!-- COGS Info -->
<div class="row mb-3">
    <div class="col-12">
        <div class="card bg-light">
            <div class="card-body py-2 d-flex justify-content-between align-items-center flex-wrap">
                <span>
                    <i class="bi bi-info-circle"></i>
                    <?php _e('cost_of_goods_sold'); ?> (FIFO): 
                    <strong><?php echo formatCurrency($reportData['cogs']); ?></strong>
                </span>
                <span class="badge bg-secondary">
                    <?php _e('sales_revenue'); ?>: <?php echo formatCurrency($reportData['sales']['amount']); ?> - 
                    <?php _e('cogs'); ?>: <?php echo formatCurrency($reportData['cogs']); ?> = 
                    <?php _e('profit'); ?>: <?php echo formatCurrency($reportData['profit']); ?>
                </span>
            </div>
        </div>
    </div>
</div>

<!-- Supplier & Customer Breakdown Tables -->
<div class="row g-3 mb-3">
    <!-- Supplier Breakdown -->
    <div class="col-lg-6 col-12">
        <div class="card h-100">
            <div class="card-header bg-primary text-white">
                <h6 class="mb-0">
                    <i class="bi bi-truck"></i> <?php _e('supplier_breakdown'); ?>
                </h6>
            </div>
            <div class="card-body p-0">
                <?php if (!empty($reportData['supplier_breakdown'])): ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th><?php _e('supplier'); ?></th>
                                <th class="text-center"><?php _e('num_purchases'); ?></th>
                                <th class="text-end"><?php _e('quantity'); ?></th>
                                <th class="text-end"><?php _e('avg_rate'); ?></th>
                                <th class="text-end"><?php _e('amount'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportData['supplier_breakdown'] as $row): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($row['supplier_name']); ?></strong>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-primary"><?php echo $row['num_purchases']; ?></span>
                                </td>
                                <td class="text-end">
                                    <?php echo formatNumber($row['total_qty'], 2); ?>
                                </td>
                                <td class="text-end">
                                    <?php echo formatCurrency($row['avg_rate']); ?>
                                </td>
                                <td class="text-end">
                                    <strong><?php echo formatCurrency($row['total_amount']); ?></strong>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th><?php _e('total'); ?></th>
                                <th class="text-center"><?php echo $reportData['purchases']['count']; ?></th>
                                <th class="text-end"><?php echo formatNumber($reportData['purchases']['qty'], 2); ?></th>
                                <th class="text-end"><?php echo formatCurrency($reportData['purchases']['avg_rate']); ?></th>
                                <th class="text-end"><?php echo formatCurrency($reportData['purchases']['amount']); ?></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php else: ?>
                <div class="text-center text-muted py-4">
                    <i class="bi bi-inbox fs-1"></i>
                    <p class="mb-0"><?php _e('no_records'); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Customer Breakdown -->
    <div class="col-lg-6 col-12">
        <div class="card h-100">
            <div class="card-header bg-success text-white">
                <h6 class="mb-0">
                    <i class="bi bi-people"></i> <?php _e('customer_breakdown'); ?>
                </h6>
            </div>
            <div class="card-body p-0">
                <?php if (!empty($reportData['customer_breakdown'])): ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th><?php _e('customer'); ?></th>
                                <th class="text-center"><?php _e('num_sales'); ?></th>
                                <th class="text-end"><?php _e('quantity'); ?></th>
                                <th class="text-end"><?php _e('avg_rate'); ?></th>
                                <th class="text-end"><?php _e('amount'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportData['customer_breakdown'] as $row): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($row['customer_name']); ?></strong>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-success"><?php echo $row['num_sales']; ?></span>
                                </td>
                                <td class="text-end">
                                    <?php echo formatNumber($row['total_qty'], 2); ?>
                                </td>
                                <td class="text-end">
                                    <?php echo formatCurrency($row['avg_rate']); ?>
                                </td>
                                <td class="text-end">
                                    <strong><?php echo formatCurrency($row['total_amount']); ?></strong>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th><?php _e('total'); ?></th>
                                <th class="text-center"><?php echo $reportData['sales']['count']; ?></th>
                                <th class="text-end"><?php echo formatNumber($reportData['sales']['qty'], 2); ?></th>
                                <th class="text-end"><?php echo formatCurrency($reportData['sales']['avg_rate']); ?></th>
                                <th class="text-end"><?php echo formatCurrency($reportData['sales']['amount']); ?></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php else: ?>
                <div class="text-center text-muted py-4">
                    <i class="bi bi-inbox fs-1"></i>
                    <p class="mb-0"><?php _e('no_records'); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Charts Section -->
<div class="row g-3 mb-3">
    <!-- Pie Chart: Purchases by Supplier -->
    <div class="col-lg-4 col-md-6 col-12">
        <div class="card h-100">
            <div class="card-header">
                <h6 class="mb-0">
                    <i class="bi bi-pie-chart"></i> <?php _e('purchases_by_supplier'); ?>
                </h6>
            </div>
            <div class="card-body">
                <?php if (!empty($reportData['supplier_breakdown'])): ?>
                <canvas id="supplierPieChart" style="max-height: 250px;"></canvas>
                <?php else: ?>
                <div class="text-center text-muted py-4">
                    <i class="bi bi-pie-chart fs-1"></i>
                    <p class="mb-0"><?php _e('no_records'); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Pie Chart: Sales by Customer -->
    <div class="col-lg-4 col-md-6 col-12">
        <div class="card h-100">
            <div class="card-header">
                <h6 class="mb-0">
                    <i class="bi bi-pie-chart"></i> <?php _e('sales_by_customer'); ?>
                </h6>
            </div>
            <div class="card-body">
                <?php if (!empty($reportData['customer_breakdown'])): ?>
                <canvas id="customerPieChart" style="max-height: 250px;"></canvas>
                <?php else: ?>
                <div class="text-center text-muted py-4">
                    <i class="bi bi-pie-chart fs-1"></i>
                    <p class="mb-0"><?php _e('no_records'); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Trend Chart: Purchase vs Sales -->
    <div class="col-lg-4 col-12">
        <div class="card h-100">
            <div class="card-header">
                <h6 class="mb-0">
                    <i class="bi bi-graph-up"></i> <?php _e('purchase_vs_sales_trend'); ?>
                    <small class="text-muted">(<?php echo ucfirst($reportData['trend']['group_label']); ?>)</small>
                </h6>
            </div>
            <div class="card-body">
                <?php if (!empty($reportData['trend']['labels'])): ?>
                <canvas id="trendChart" style="max-height: 250px;"></canvas>
                <?php else: ?>
                <div class="text-center text-muted py-4">
                    <i class="bi bi-graph-up fs-1"></i>
                    <p class="mb-0"><?php _e('no_records'); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Transaction Details -->
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
        <h6 class="mb-0">
            <i class="bi bi-list-ul"></i> <?php _e('transaction_details'); ?>
        </h6>
        <div class="mt-2 mt-md-0">
            <button type="button" class="btn btn-sm btn-outline-success" onclick="exportToExcel()">
                <i class="bi bi-file-earmark-excel"></i> <?php _e('export_excel'); ?>
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">
                <i class="bi bi-printer"></i> <?php _e('print_report'); ?>
            </button>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (!empty($reportData['transactions'])): ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0" id="transactionsTable">
                <thead class="table-light">
                    <tr>
                        <th><?php _e('date'); ?></th>
                        <th><?php _e('transaction_type'); ?></th>
                        <th><?php _e('reference'); ?></th>
                        <th><?php _e('party'); ?></th>
                        <th class="text-end"><?php _e('quantity'); ?></th>
                        <th class="text-end"><?php _e('rate'); ?></th>
                        <th class="text-end"><?php _e('amount'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reportData['transactions'] as $trans): ?>
                    <tr>
                        <td><?php echo formatDate($trans['trans_date']); ?></td>
                        <td>
                            <?php if ($trans['trans_type'] == 'purchase'): ?>
                            <span class="badge bg-primary">
                                <i class="bi bi-cart-plus"></i> <?php _e('purchase'); ?>
                            </span>
                            <?php elseif ($trans['trans_type'] == 'purchase_import'): ?>
                            <span class="badge bg-info">
                                <i class="bi bi-box-seam"></i> Import
                            </span>
                            <?php else: ?>
                            <span class="badge bg-success">
                                <i class="bi bi-cart-check"></i> <?php _e('sale'); ?>
                            </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($trans['trans_type'] == 'purchase'): ?>
                            <a href="<?php echo BASE_URL; ?>modules/transactions/purchases_view.php?id=<?php echo $trans['trans_id']; ?>"
                               target="_blank" class="text-decoration-none">
                                <?php echo htmlspecialchars($trans['reference']); ?>
                                <i class="bi bi-box-arrow-up-right small"></i>
                            </a>
                            <?php elseif ($trans['trans_type'] == 'purchase_import'): ?>
                            <a href="<?php echo BASE_URL; ?>modules/transactions/purchase_imports.php?view=<?php echo $trans['trans_id']; ?>"
                               target="_blank" class="text-decoration-none">
                                <?php echo htmlspecialchars($trans['reference']); ?>
                                <i class="bi bi-box-arrow-up-right small"></i>
                            </a>
                            <?php else: ?>
                            <a href="<?php echo BASE_URL; ?>modules/transactions/sales_view.php?id=<?php echo $trans['trans_id']; ?>"
                               target="_blank" class="text-decoration-none">
                                <?php echo htmlspecialchars($trans['reference']); ?>
                                <i class="bi bi-box-arrow-up-right small"></i>
                            </a>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($trans['party_name']); ?></td>
                        <td class="text-end"><?php echo formatNumber($trans['quantity'], 2); ?></td>
                        <td class="text-end"><?php echo formatCurrency($trans['rate_per_unit']); ?></td>
                        <td class="text-end"><strong><?php echo formatCurrency($trans['amount']); ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="text-center text-muted py-4">
            <i class="bi bi-inbox fs-1"></i>
            <p class="mb-0"><?php _e('no_transactions_found'); ?></p>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php elseif ($locationFilter && !$productFilter): ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle"></i>
    <?php _e('select_product_required'); ?>
</div>
<?php elseif (!$locationFilter): ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle"></i>
    <?php _e('please_select_location_date'); ?>
</div>
<?php endif; ?>

<!-- Select2 CSS & JS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<script>
$(document).ready(function() {
    // Initialize Select2 for Product
    $('#productSelect').select2({
        theme: 'bootstrap-5',
        placeholder: '<?php echo __('select_product'); ?>',
        allowClear: true,
        width: '100%'
    });
    
    // Initialize Select2 for Supplier
    $('#supplierSelect').select2({
        theme: 'bootstrap-5',
        placeholder: '<?php echo __('all_suppliers'); ?>',
        allowClear: true,
        width: '100%'
    });
    
    // Initialize Select2 for Customer
    $('#customerSelect').select2({
        theme: 'bootstrap-5',
        placeholder: '<?php echo __('all_customers'); ?>',
        allowClear: true,
        width: '100%'
    });
    
    <?php if ($reportData): ?>
    // Chart colors
    const chartColors = [
        '#0d6efd', '#198754', '#dc3545', '#ffc107', '#0dcaf0', 
        '#6f42c1', '#fd7e14', '#20c997', '#6610f2', '#d63384',
        '#17a2b8', '#28a745', '#ffc107', '#dc3545', '#6c757d'
    ];
    
    <?php if (!empty($reportData['supplier_breakdown'])): ?>
    // Supplier Pie Chart
    const supplierCtx = document.getElementById('supplierPieChart').getContext('2d');
    new Chart(supplierCtx, {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode(array_column($reportData['supplier_breakdown'], 'supplier_name')); ?>,
            datasets: [{
                data: <?php echo json_encode(array_map('floatval', array_column($reportData['supplier_breakdown'], 'total_amount'))); ?>,
                backgroundColor: chartColors.slice(0, <?php echo count($reportData['supplier_breakdown']); ?>),
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        boxWidth: 12,
                        padding: 8,
                        font: { size: 10 }
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let value = context.raw;
                            let total = context.dataset.data.reduce((a, b) => a + b, 0);
                            let percentage = ((value / total) * 100).toFixed(1);
                            return context.label + ': <?php echo $defaultCurrencySymbol; ?>' + value.toLocaleString() + ' (' + percentage + '%)';
                        }
                    }
                }
            }
        }
    });
    <?php endif; ?>
    
    <?php if (!empty($reportData['customer_breakdown'])): ?>
    // Customer Pie Chart
    const customerCtx = document.getElementById('customerPieChart').getContext('2d');
    new Chart(customerCtx, {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode(array_column($reportData['customer_breakdown'], 'customer_name')); ?>,
            datasets: [{
                data: <?php echo json_encode(array_map('floatval', array_column($reportData['customer_breakdown'], 'total_amount'))); ?>,
                backgroundColor: chartColors.slice(0, <?php echo count($reportData['customer_breakdown']); ?>),
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        boxWidth: 12,
                        padding: 8,
                        font: { size: 10 }
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let value = context.raw;
                            let total = context.dataset.data.reduce((a, b) => a + b, 0);
                            let percentage = ((value / total) * 100).toFixed(1);
                            return context.label + ': <?php echo $defaultCurrencySymbol; ?>' + value.toLocaleString() + ' (' + percentage + '%)';
                        }
                    }
                }
            }
        }
    });
    <?php endif; ?>
    
    <?php if (!empty($reportData['trend']['labels'])): ?>
    // Trend Line Chart
    const trendCtx = document.getElementById('trendChart').getContext('2d');
    new Chart(trendCtx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($reportData['trend']['labels']); ?>,
            datasets: [
                {
                    label: '<?php echo __('purchases'); ?>',
                    data: <?php echo json_encode($reportData['trend']['purchases']); ?>,
                    borderColor: '#0d6efd',
                    backgroundColor: 'rgba(13, 110, 253, 0.1)',
                    fill: true,
                    tension: 0.3,
                    borderWidth: 2,
                    pointRadius: 3
                },
                {
                    label: '<?php echo __('sales'); ?>',
                    data: <?php echo json_encode($reportData['trend']['sales']); ?>,
                    borderColor: '#198754',
                    backgroundColor: 'rgba(25, 135, 84, 0.1)',
                    fill: true,
                    tension: 0.3,
                    borderWidth: 2,
                    pointRadius: 3
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            interaction: {
                intersect: false,
                mode: 'index'
            },
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        boxWidth: 12,
                        padding: 8,
                        font: { size: 10 }
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ': ' + context.raw.toLocaleString() + ' <?php echo $productDetails['unit'] ?? ''; ?>';
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        font: { size: 10 }
                    }
                },
                x: {
                    ticks: {
                        font: { size: 9 },
                        maxRotation: 45
                    }
                }
            }
        }
    });
    <?php endif; ?>
    <?php endif; ?>
});

// Export to Excel function
function exportToExcel() {
    const table = document.getElementById('transactionsTable');
    if (!table) {
        alert('No data to export');
        return;
    }
    
    let csv = [];
    const rows = table.querySelectorAll('tr');
    
    // Add report header
    csv.push('<?php echo __('product_analysis_report'); ?>');
    csv.push('<?php echo addslashes($productDetails['name'] ?? ''); ?>');
    csv.push('<?php echo $dateFrom; ?> to <?php echo $dateTo; ?>');
    csv.push('');
    
    for (let i = 0; i < rows.length; i++) {
        const row = [];
        const cols = rows[i].querySelectorAll('td, th');
        
        for (let j = 0; j < cols.length; j++) {
            let text = cols[j].innerText.replace(/"/g, '""');
            row.push('"' + text + '"');
        }
        csv.push(row.join(','));
    }
    
    // Add summary
    csv.push('');
    csv.push('"<?php echo __('total_purchases'); ?>","<?php echo $reportData ? formatCurrency($reportData['purchases']['amount']) : ''; ?>"');
    csv.push('"<?php echo __('total_sales'); ?>","<?php echo $reportData ? formatCurrency($reportData['sales']['amount']) : ''; ?>"');
    csv.push('"<?php echo __('total_profit'); ?>","<?php echo $reportData ? formatCurrency($reportData['profit']) : ''; ?>"');
    
    const csvContent = csv.join('\n');
    const blob = new Blob(['\ufeff' + csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    
    link.setAttribute('href', url);
    link.setAttribute('download', 'product_analysis_<?php echo $productFilter; ?>_<?php echo date('Y-m-d'); ?>.csv');
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>

<!-- Print Styles -->
<style>
@media print {
    .navbar, .btn, .select2, form, .alert-info, .no-print {
        display: none !important;
    }
    .card {
        border: 1px solid #ddd !important;
        break-inside: avoid;
    }
    .card-header {
        background-color: #f8f9fa !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    canvas {
        max-height: 200px !important;
    }
}
</style>

<?php require_once '../../includes/footer.php'; ?>
