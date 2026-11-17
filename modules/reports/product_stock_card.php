<?php
$pageTitle = 'Product Stock Card';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

if (!canViewReport('stock_report')) {
    setAlert('You do not have permission to access this page', 'danger');
    redirect('');
}

require_once '../../includes/header.php';

// Get filters
$locationFilter = $_GET['location'] ?? '';
$productFilter = $_GET['product'] ?? '';
$categoryFilter = $_GET['category'] ?? '';
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

// Get user locations
$userLocations = getUserLocations();

// Get categories (include parent_id for tree rendering)
$categoriesStmt = $conn->query("SELECT id, parent_id, name FROM product_categories WHERE is_active = 1 ORDER BY name");
$categories = $categoriesStmt->fetchAll();

// Get products based on category filter
$products = [];
if ($locationFilter) {
    $categoryCondition = '';
    $categoryParam = [];
    if ($categoryFilter) {
        $categoryIds = getDescendantCategoryIds($categoryFilter, $conn);
        $catInClause = implode(',', array_map('intval', $categoryIds));
        $categoryCondition = "AND p.category_id IN ($catInClause)";
    }
    
    $prodStmt = $conn->prepare("
        SELECT p.id, p.name, pc.name as category_name, p.unit
        FROM products p
        LEFT JOIN product_categories pc ON p.category_id = pc.id
        WHERE p.is_active = 1 $categoryCondition
        ORDER BY p.name
    ");
    $prodStmt->execute($categoryParam);
    $products = $prodStmt->fetchAll();
}

// Get product details if selected
$productDetails = null;
$stockCardData = [];
$openingBalance = 0;

if ($locationFilter && $productFilter) {
    // Get all descendant location IDs for aggregation
    $locationIds = getDescendantLocationIds($locationFilter, $conn);
    $locInClause = implode(',', array_map('intval', $locationIds));

    // Get product details
    $prodDetailStmt = $conn->prepare("
        SELECT p.*, pc.name as category_name
        FROM products p
        LEFT JOIN product_categories pc ON p.category_id = pc.id
        WHERE p.id = ?
    ");
    $prodDetailStmt->execute([$productFilter]);
    $productDetails = $prodDetailStmt->fetch();

    if ($productDetails) {
        // Calculate opening balance (before from_date)
        // Opening stock entries before from_date
        $openingStmt = $conn->prepare("
            SELECT COALESCE(SUM(quantity), 0) as opening_qty
            FROM opening_stocks
            WHERE product_id = ? AND location_id IN ($locInClause) AND stock_date < ?
        ");
        $openingStmt->execute([$productFilter, $dateFrom]);
        $openingFromEntry = $openingStmt->fetch()['opening_qty'];

        // Purchases before from_date (regular + imports)
        $purchasesBeforeStmt = $conn->prepare("
            SELECT COALESCE(SUM(pd.quantity), 0) as purchase_qty
            FROM purchase_details pd
            JOIN purchases p ON pd.purchase_id = p.id
            WHERE pd.product_id = ? AND p.location_id IN ($locInClause) AND p.purchase_date < ?
        ");
        $purchasesBeforeStmt->execute([$productFilter, $dateFrom]);
        $purchasesBefore = $purchasesBeforeStmt->fetch()['purchase_qty'];

        // Purchase imports before from_date
        $importsBeforeStmt = $conn->prepare("
            SELECT COALESCE(SUM(pid.quantity), 0) as import_qty
            FROM purchase_import_details pid
            JOIN purchase_imports pi ON pid.import_id = pi.id
            WHERE pid.product_id = ? AND pi.location_id IN ($locInClause) AND pi.import_date < ?
        ");
        $importsBeforeStmt->execute([$productFilter, $dateFrom]);
        $purchasesBefore += $importsBeforeStmt->fetch()['import_qty'];

        // Sales before from_date
        $salesBeforeStmt = $conn->prepare("
            SELECT COALESCE(SUM(sd.quantity), 0) as sale_qty
            FROM sale_details sd
            JOIN sales s ON sd.sale_id = s.id
            WHERE sd.product_id = ? AND s.location_id IN ($locInClause) AND s.sale_date < ?
        ");
        $salesBeforeStmt->execute([$productFilter, $dateFrom]);
        $salesBefore = $salesBeforeStmt->fetch()['sale_qty'];

        // Adjustments before from_date
        $adjustmentsBeforeStmt = $conn->prepare("
            SELECT COALESCE(SUM(CASE WHEN sad.adjustment_type = 'increase' THEN sad.quantity ELSE -sad.quantity END), 0) as adj_qty
            FROM stock_adjustment_details sad
            JOIN stock_adjustments sa ON sad.adjustment_id = sa.id
            WHERE sad.product_id = ? AND sa.location_id IN ($locInClause) AND sa.adjustment_date < ?
        ");
        $adjustmentsBeforeStmt->execute([$productFilter, $dateFrom]);
        $adjustmentsBefore = $adjustmentsBeforeStmt->fetch()['adj_qty'];

        // Transfer In before from_date
        $transferInBeforeStmt = $conn->prepare("
            SELECT COALESCE(SUM(td.received_quantity), 0) as transfer_in_qty
            FROM stock_transfer_details td
            JOIN stock_transfers t ON td.transfer_id = t.id
            WHERE td.product_id = ? AND t.to_location_id IN ($locInClause) AND t.status = 'received' AND DATE(t.received_at) < ?
        ");
        $transferInBeforeStmt->execute([$productFilter, $dateFrom]);
        $transferInBefore = $transferInBeforeStmt->fetch()['transfer_in_qty'];

        // Transfer Out before from_date
        $transferOutBeforeStmt = $conn->prepare("
            SELECT COALESCE(SUM(td.dispatched_quantity), 0) as transfer_out_qty
            FROM stock_transfer_details td
            JOIN stock_transfers t ON td.transfer_id = t.id
            WHERE td.product_id = ? AND t.from_location_id IN ($locInClause) AND t.status IN ('in_transit', 'received') AND DATE(t.dispatched_at) < ?
        ");
        $transferOutBeforeStmt->execute([$productFilter, $dateFrom]);
        $transferOutBefore = $transferOutBeforeStmt->fetch()['transfer_out_qty'];

        // Consumption before from_date
        $consumptionBeforeStmt = $conn->prepare("
            SELECT COALESCE(SUM(cvd.quantity), 0) as consumption_qty
            FROM consumption_voucher_details cvd
            JOIN consumption_vouchers cv ON cvd.voucher_id = cv.id
            WHERE cvd.product_id = ? AND cv.location_id IN ($locInClause) AND cv.voucher_date < ?
        ");
        $consumptionBeforeStmt->execute([$productFilter, $dateFrom]);
        $consumptionBefore = $consumptionBeforeStmt->fetch()['consumption_qty'];

        $openingBalance = $openingFromEntry + $purchasesBefore - $salesBefore + $adjustmentsBefore + $transferInBefore - $transferOutBefore - $consumptionBefore;
        
        // Get all transactions in the period
        $transactions = [];
        
        // 1. Get opening stock entries in the period
        $openingInPeriodStmt = $conn->prepare("
            SELECT
                stock_date as trans_date,
                'opening' as trans_type,
                id as trans_id,
                quantity,
                rate_per_unit,
                'Opening Stock' as particulars,
                stock_date as sort_date,
                '00:00:00' as sort_time
            FROM opening_stocks
            WHERE product_id = ? AND location_id IN ($locInClause)
              AND stock_date BETWEEN ? AND ?
            ORDER BY stock_date
        ");
        $openingInPeriodStmt->execute([$productFilter, $dateFrom, $dateTo]);
        $openingInPeriod = $openingInPeriodStmt->fetchAll();
        
        foreach ($openingInPeriod as $row) {
            $transactions[] = $row;
        }
        
        // 2. Get purchases in the period
        $purchasesStmt = $conn->prepare("
            SELECT 
                p.purchase_date as trans_date,
                'purchase' as trans_type,
                p.id as trans_id,
                pd.quantity,
                pd.rate_per_unit,
                CONCAT('Purchase - ', p.purchase_number, ' - ', la.name) as particulars,
                p.purchase_date as sort_date,
                p.created_at as sort_time
            FROM purchase_details pd
            JOIN purchases p ON pd.purchase_id = p.id
            JOIN suppliers s ON p.supplier_id = s.id
            JOIN ledger_accounts la ON s.ledger_account_id = la.id
            WHERE pd.product_id = ? AND p.location_id IN ($locInClause)
              AND p.purchase_date BETWEEN ? AND ?
            ORDER BY p.purchase_date, p.created_at
        ");
        $purchasesStmt->execute([$productFilter, $dateFrom, $dateTo]);
        $purchases = $purchasesStmt->fetchAll();
        
        foreach ($purchases as $row) {
            $transactions[] = $row;
        }

        // 2b. Get purchase imports in the period
        $importPurchasesStmt = $conn->prepare("
            SELECT
                pi.import_date as trans_date,
                'purchase' as trans_type,
                pi.id as trans_id,
                pid.quantity,
                pid.landed_rate_per_unit as rate_per_unit,
                CONCAT('Import - ', pi.import_number, ' - ', la.name) as particulars,
                pi.import_date as sort_date,
                pi.created_at as sort_time
            FROM purchase_import_details pid
            JOIN purchase_imports pi ON pid.import_id = pi.id
            JOIN suppliers s ON pi.supplier_id = s.id
            JOIN ledger_accounts la ON s.ledger_account_id = la.id
            WHERE pid.product_id = ? AND pi.location_id IN ($locInClause)
              AND pi.import_date BETWEEN ? AND ?
            ORDER BY pi.import_date, pi.created_at
        ");
        $importPurchasesStmt->execute([$productFilter, $dateFrom, $dateTo]);
        $importPurchases = $importPurchasesStmt->fetchAll();

        foreach ($importPurchases as $row) {
            $transactions[] = $row;
        }

        // 3. Get sales in the period
        $salesStmt = $conn->prepare("
            SELECT 
                s.sale_date as trans_date,
                'sale' as trans_type,
                s.id as trans_id,
                SUM(sd.quantity) as quantity,
                AVG(sd.rate_per_unit) as rate_per_unit,
                CONCAT('Sales - ', s.sale_number, ' - ', la.name) as particulars,
                s.sale_date as sort_date,
                s.created_at as sort_time
            FROM sale_details sd
            JOIN sales s ON sd.sale_id = s.id
            JOIN customers c ON s.customer_id = c.id
            JOIN ledger_accounts la ON c.ledger_account_id = la.id
            WHERE sd.product_id = ? AND s.location_id IN ($locInClause)
              AND s.sale_date BETWEEN ? AND ?
            GROUP BY s.id, s.sale_number, s.sale_date, la.name, s.created_at
            ORDER BY s.sale_date, s.created_at
        ");
        $salesStmt->execute([$productFilter, $dateFrom, $dateTo]);
        $sales = $salesStmt->fetchAll();
        
        foreach ($sales as $row) {
            $transactions[] = $row;
        }
        
        // 4. Get adjustments in the period
        $adjustmentsStmt = $conn->prepare("
            SELECT 
                sa.adjustment_date as trans_date,
                CASE WHEN sad.adjustment_type = 'increase' THEN 'adjustment_in' ELSE 'adjustment_out' END as trans_type,
                sa.id as trans_id,
                sad.quantity,
                sad.rate_per_unit,
                CONCAT('Adjustment - ', sa.adjustment_number, ' (', UPPER(sad.adjustment_type), ')') as particulars,
                sa.adjustment_date as sort_date,
                sa.created_at as sort_time
            FROM stock_adjustment_details sad
            JOIN stock_adjustments sa ON sad.adjustment_id = sa.id
            WHERE sad.product_id = ? AND sa.location_id IN ($locInClause)
              AND sa.adjustment_date BETWEEN ? AND ?
            ORDER BY sa.adjustment_date, sa.created_at
        ");
        $adjustmentsStmt->execute([$productFilter, $dateFrom, $dateTo]);
        $adjustments = $adjustmentsStmt->fetchAll();
        
        foreach ($adjustments as $row) {
            $transactions[] = $row;
        }

        // 5. Get transfer IN in the period
        $transferInStmt = $conn->prepare("
            SELECT
                DATE(t.received_at) as trans_date,
                'transfer_in' as trans_type,
                t.id as trans_id,
                td.received_quantity as quantity,
                td.rate_per_unit,
                CONCAT('Transfer In - ', t.transfer_number, ' from ', fl.name) as particulars,
                DATE(t.received_at) as sort_date,
                t.received_at as sort_time
            FROM stock_transfer_details td
            JOIN stock_transfers t ON td.transfer_id = t.id
            JOIN locations fl ON t.from_location_id = fl.id
            WHERE td.product_id = ? AND t.to_location_id IN ($locInClause)
              AND t.status = 'received'
              AND DATE(t.received_at) BETWEEN ? AND ?
            ORDER BY t.received_at
        ");
        $transferInStmt->execute([$productFilter, $dateFrom, $dateTo]);
        $transfersIn = $transferInStmt->fetchAll();

        foreach ($transfersIn as $row) {
            $transactions[] = $row;
        }

        // 6. Get transfer OUT in the period
        $transferOutStmt = $conn->prepare("
            SELECT
                DATE(t.dispatched_at) as trans_date,
                'transfer_out' as trans_type,
                t.id as trans_id,
                td.dispatched_quantity as quantity,
                td.rate_per_unit,
                CONCAT('Transfer Out - ', t.transfer_number, ' to ', tl.name) as particulars,
                DATE(t.dispatched_at) as sort_date,
                t.dispatched_at as sort_time
            FROM stock_transfer_details td
            JOIN stock_transfers t ON td.transfer_id = t.id
            JOIN locations tl ON t.to_location_id = tl.id
            WHERE td.product_id = ? AND t.from_location_id IN ($locInClause)
              AND t.status IN ('in_transit', 'received')
              AND DATE(t.dispatched_at) BETWEEN ? AND ?
            ORDER BY t.dispatched_at
        ");
        $transferOutStmt->execute([$productFilter, $dateFrom, $dateTo]);
        $transfersOut = $transferOutStmt->fetchAll();

        foreach ($transfersOut as $row) {
            $transactions[] = $row;
        }

        // 7. Get consumption vouchers in the period
        $consumptionStmt = $conn->prepare("
            SELECT
                cv.voucher_date as trans_date,
                'consumption' as trans_type,
                cv.id as trans_id,
                cvd.quantity,
                cvd.rate_per_unit,
                CONCAT('Consumption - ', cv.voucher_number, COALESCE(CONCAT(' - ', cc.name), '')) as particulars,
                cv.voucher_date as sort_date,
                cv.created_at as sort_time
            FROM consumption_voucher_details cvd
            JOIN consumption_vouchers cv ON cvd.voucher_id = cv.id
            LEFT JOIN cost_centres cc ON cv.cost_centre_id = cc.id
            WHERE cvd.product_id = ? AND cv.location_id IN ($locInClause)
              AND cv.voucher_date BETWEEN ? AND ?
            ORDER BY cv.voucher_date, cv.created_at
        ");
        $consumptionStmt->execute([$productFilter, $dateFrom, $dateTo]);
        $consumptions = $consumptionStmt->fetchAll();

        foreach ($consumptions as $row) {
            $transactions[] = $row;
        }

        // Sort all transactions by date and time
        usort($transactions, function($a, $b) {
            $dateCompare = strcmp($a['sort_date'], $b['sort_date']);
            if ($dateCompare !== 0) {
                return $dateCompare;
            }
            return strcmp($a['sort_time'], $b['sort_time']);
        });
        
        // Build stock card data with running balance
        $runningBalance = $openingBalance;

        $openingRowBalance = 0;
        
        foreach ($transactions as $trans) {
            $purchase = 0;
            $sale = 0;
            $adjustment = 0;
            $transferIn = 0;
            $transferOut = 0;
            $consumption = 0;

            if ($trans['trans_type'] === 'opening') {
                $runningBalance += $trans['quantity'];
                $openingRowBalance += $trans['quantity'];
            } elseif ($trans['trans_type'] === 'purchase') {
                $purchase = $trans['quantity'];
            } elseif ($trans['trans_type'] === 'sale') {
                $sale = $trans['quantity'];
            } elseif ($trans['trans_type'] === 'adjustment_in') {
                $adjustment = $trans['quantity'];
            } elseif ($trans['trans_type'] === 'adjustment_out') {
                $adjustment = -$trans['quantity'];
            } elseif ($trans['trans_type'] === 'transfer_in') {
                $transferIn = $trans['quantity'];
            } elseif ($trans['trans_type'] === 'transfer_out') {
                $transferOut = $trans['quantity'];
            } elseif ($trans['trans_type'] === 'consumption') {
                $consumption = $trans['quantity'];
            }

            $runningBalance += $purchase - $sale + $adjustment + $transferIn - $transferOut - $consumption;

            $stockCardData[] = [
                'date' => $trans['trans_date'],
                'particulars' => $trans['particulars'],
                'trans_type' => $trans['trans_type'],
                'trans_id' => $trans['trans_id'],
                'purchase' => $purchase,
                'sale' => $sale,
                'adjustment' => $adjustment,
                'transfer_in' => $transferIn,
                'transfer_out' => $transferOut,
                'consumption' => $consumption,
                'balance' => $runningBalance,
                'rate' => $trans['rate_per_unit'] ?? 0
            ];
        }
    }
}
?>

<div class="row mb-3">
    <div class="col-md-12">
        <h2>
            <i class="bi bi-card-list"></i> Product Stock Card
            <?php if ($productDetails): ?>
            <small class="text-muted">- <?php echo htmlspecialchars($productDetails['name']); ?></small>
            <?php endif; ?>
        </h2>
    </div>
</div>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label required">Location</label>
                    <select name="location" class="form-select" required onchange="this.form.submit()">
                        <option value="">-- Select Location --</option>
                        <?= renderTreeOptions($userLocations, $locationFilter) ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Category</label>
                    <select name="category" class="form-select" onchange="this.form.submit()">
                        <option value=""><?php echo __('all_categories'); ?></option>
                        <?php echo renderTreeOptions($categories, $categoryFilter); ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label required">Product</label>
                    <select name="product" class="form-select select2" required>
                        <option value="">-- Select Product --</option>
                        <?php foreach ($products as $prod): ?>
                        <option value="<?php echo $prod['id']; ?>" 
                                <?php echo $productFilter == $prod['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($prod['name']) . ' (' . htmlspecialchars($prod['category_name']) . ')'; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Date From</label>
                    <input type="date" name="date_from" class="form-control" 
                           value="<?php echo $dateFrom; ?>" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Date To</label>
                    <input type="date" name="date_to" class="form-control" 
                           value="<?php echo $dateTo; ?>" required>
                </div>
            </div>
            <div class="row mt-3">
                <div class="col-md-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search"></i> Generate
                    </button>
                    <a href="stock_report.php?location=<?php echo $locationFilter; ?>&category=<?php echo $categoryFilter; ?>&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>" 
                       class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Stock Report
                    </a>
                </div>
            </div>
        </form>
    </div>
</div>

<?php if ($productDetails && $locationFilter): ?>
<!-- Product Info -->
<div class="card mb-3">
    <div class="card-body">
        <div class="row">
            <div class="col-md-3">
                <strong>Product:</strong> <?php echo htmlspecialchars($productDetails['name']); ?>
            </div>
            <div class="col-md-2">
                <strong>Category:</strong> <?php echo htmlspecialchars($productDetails['category_name']); ?>
            </div>
            <div class="col-md-2">
                <strong>Unit:</strong> <?php echo htmlspecialchars($productDetails['unit']); ?>
            </div>
            <div class="col-md-2">
                <strong>Opening Balance:</strong> 
                <span class="badge bg-info"><?php echo formatNumber($openingBalance, 3); ?></span>
            </div>
            <div class="col-md-3 text-end">
                <button onclick="exportTableToCSV('stockCardTable', 'stock_card_<?php echo preg_replace('/[^A-Za-z0-9_\-]+/', '_', $productDetails['name']); ?>.csv')"
                        class="btn btn-sm btn-success">
                    <i class="bi bi-file-earmark-excel"></i> Export
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Stock Card Table -->
<div class="card">
    <div class="card-body">
        <?php if (empty($stockCardData) && $openingBalance == 0): ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> No transactions found for this product in the selected period.
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-bordered table-sm table-hover" id="stockCardTable">
                <thead class="table-light">
                    <tr>
                        <th style="width: 4%;">Sr.</th>
                        <th style="width: 8%;">Date</th>
                        <th style="width: 22%;">Particulars</th>
                        <th style="width: 8%;" class="text-end">Purchase</th>
                        <th style="width: 8%;" class="text-end">Sales</th>
                        <th style="width: 8%;" class="text-end">Adj.</th>
                        <th style="width: 8%;" class="text-end">Trf In</th>
                        <th style="width: 8%;" class="text-end">Trf Out</th>
                        <th style="width: 8%;" class="text-end">Consumed</th>
                        <th style="width: 8%;" class="text-end">Balance</th>
                        <th style="width: 10%;" class="text-end">Rate/Unit</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Opening Row -->
                    <?php if ($openingBalance != 0): ?>
                    <tr class="table-info">
                        <td>1</td>
                        <td></td>
                        <td><strong>Opening Balance</strong></td>
                        <td class="text-end">-</td>
                        <td class="text-end">-</td>
                        <td class="text-end">-</td>
                        <td class="text-end">-</td>
                        <td class="text-end">-</td>
                        <td class="text-end">-</td>
                        <td class="text-end"><strong><?php echo formatNumber($openingBalance, 3); ?></strong></td>
                        <td class="text-end">-</td>
                    </tr>
                    <?php endif; ?>
                    
                    <!-- Transaction Rows -->
                    <?php 
                    $srNo = ($openingBalance != 0) ? 2 : 1;
                    $totalPurchases = 0;
                    $totalSales = 0;
                    $totalAdjustments = 0;
                    $totalTransferIn = 0;
                    $totalTransferOut = 0;
                    $totalConsumption = 0;

                    foreach ($stockCardData as $row):
                        $totalPurchases += $row['purchase'];
                        $totalSales += $row['sale'];
                        $totalAdjustments += $row['adjustment'];
                        $totalTransferIn += $row['transfer_in'];
                        $totalTransferOut += $row['transfer_out'];
                        $totalConsumption += $row['consumption'];

                        // Determine link for transaction
                        $transLink = '#';
                        if ($row['trans_type'] === 'purchase') {
                            $transLink = BASE_URL . 'modules/transactions/purchases_view.php?id=' . $row['trans_id'];
                        } elseif ($row['trans_type'] === 'sale') {
                            $transLink = BASE_URL . 'modules/transactions/sales_view.php?id=' . $row['trans_id'];
                        } elseif ($row['trans_type'] === 'adjustment_in' || $row['trans_type'] === 'adjustment_out') {
                            $transLink = BASE_URL . 'modules/transactions/stock_adjustment_view.php?id=' . $row['trans_id'];
                        } elseif ($row['trans_type'] === 'transfer_in' || $row['trans_type'] === 'transfer_out') {
                            $transLink = BASE_URL . 'modules/transactions/stock_transfer_view.php?id=' . $row['trans_id'];
                        } elseif ($row['trans_type'] === 'consumption') {
                            $transLink = BASE_URL . 'modules/transactions/consumption_voucher_view.php?id=' . $row['trans_id'];
                        }
                    ?>
                    <tr>
                        <td><?php echo $srNo++; ?></td>
                        <td><?php echo formatDate($row['date']); ?></td>
                        <td>
                            <?php if ($row['trans_type'] !== 'opening'): ?>
                            <a href="<?php echo $transLink; ?>" target="_blank" class="text-decoration-none">
                                <?php echo htmlspecialchars($row['particulars']); ?>
                                <i class="bi bi-box-arrow-up-right text-muted small ms-1"></i>
                            </a>
                            <?php else: ?>
                                <?php echo htmlspecialchars($row['particulars']); ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-success">
                            <?php echo $row['purchase'] > 0 ? formatNumber($row['purchase'], 3) : '-'; ?>
                        </td>
                        <td class="text-end text-danger">
                            <?php echo $row['sale'] > 0 ? formatNumber($row['sale'], 3) : '-'; ?>
                        </td>
                        <td class="text-end <?php echo $row['adjustment'] > 0 ? 'text-info' : ($row['adjustment'] < 0 ? 'text-warning' : ''); ?>">
                            <?php
                            if ($row['adjustment'] > 0) {
                                echo '+' . formatNumber($row['adjustment'], 3);
                            } elseif ($row['adjustment'] < 0) {
                                echo formatNumber($row['adjustment'], 3);
                            } else {
                                echo '-';
                            }
                            ?>
                        </td>
                        <td class="text-end text-primary">
                            <?php echo $row['transfer_in'] > 0 ? formatNumber($row['transfer_in'], 3) : '-'; ?>
                        </td>
                        <td class="text-end text-secondary">
                            <?php echo $row['transfer_out'] > 0 ? formatNumber($row['transfer_out'], 3) : '-'; ?>
                        </td>
                        <td class="text-end text-danger">
                            <?php echo $row['consumption'] > 0 ? formatNumber($row['consumption'], 3) : '-'; ?>
                        </td>
                        <td class="text-end">
                            <strong><?php echo formatNumber($row['balance'], 3); ?></strong>
                        </td>
                        <td class="text-end">
                            <?php echo $row['rate'] > 0 ? formatCurrency($row['rate'], 2) : '-'; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <!-- Closing Row -->
                    <?php
                     $closingBalance = $openingBalance + $openingRowBalance + $totalPurchases - $totalSales + $totalAdjustments + $totalTransferIn - $totalTransferOut - $totalConsumption;
                    ?>
                    <tr class="table-success">
                        <td><?php echo $srNo; ?></td>
                        <td></td>
                        <td><strong>Closing Balance</strong></td>
                        <td class="text-end">-</td>
                        <td class="text-end">-</td>
                        <td class="text-end">-</td>
                        <td class="text-end">-</td>
                        <td class="text-end">-</td>
                        <td class="text-end">-</td>
                        <td class="text-end"><strong><?php echo formatNumber($closingBalance, 3); ?></strong></td>
                        <td class="text-end">-</td>
                    </tr>
                </tbody>
                <tfoot class="table-light">
                    <tr>
                        <th colspan="3" class="text-end">TOTAL:</th>
                        <th class="text-end text-success"><?php echo formatNumber($totalPurchases, 3); ?></th>
                        <th class="text-end text-danger"><?php echo formatNumber($totalSales, 3); ?></th>
                        <th class="text-end <?php echo $totalAdjustments > 0 ? 'text-info' : ($totalAdjustments < 0 ? 'text-warning' : ''); ?>">
                            <?php echo $totalAdjustments > 0 ? '+' : ''; ?><?php echo formatNumber($totalAdjustments, 3); ?>
                        </th>
                        <th class="text-end text-primary"><?php echo formatNumber($totalTransferIn, 3); ?></th>
                        <th class="text-end text-secondary"><?php echo formatNumber($totalTransferOut, 3); ?></th>
                        <th class="text-end text-danger"><?php echo formatNumber($totalConsumption, 3); ?></th>
                        <th class="text-end">&nbsp;</th>
                        <th></th>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($locationFilter): ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle"></i> Please select a product to view the stock card.
</div>
<?php else: ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle"></i> Please select a location first.
</div>
<?php endif; ?>

<script>
// Initialize Select2 for product dropdown
$(document).ready(function() {
    $('.select2').select2({
        placeholder: '-- Select Product --',
        allowClear: true,
        width: '100%'
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>
