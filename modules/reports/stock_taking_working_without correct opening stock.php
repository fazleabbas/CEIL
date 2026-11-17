<?php
$pageTitle = 'Stock Taking';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

if (!hasPermission('stock_taking', 'view')) {
    setAlert('You do not have permission to access this page', 'danger');
    redirect('');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && hasPermission('stock_taking', 'create')) {
    checkCSRF();
    
    $locationId = (int)$_POST['location_id'];
    $stockDate = dbDate($_POST['stock_date']);
    $calculationMethod = clean($_POST['calculation_method']);
    $actualCash = floatval($_POST['actual_cash_amount'] ?? 0);
    $products = $_POST['products'] ?? [];
    $physicalQty = $_POST['physical_qty'] ?? [];
    
    $errors = [];
    
    if (empty($locationId)) {
        $errors[] = 'Please select a location';
    }
    
    if (empty($stockDate)) {
        $errors[] = 'Please select a date';
    }
    
    if (!in_array($calculationMethod, ['FIFO', 'LIFO', 'AVERAGE'])) {
        $errors[] = 'Invalid calculation method';
    }
    
    // Check for duplicate stock taking
    $checkStmt = $conn->prepare("
        SELECT id FROM stock_taking 
        WHERE location_id = ? AND stock_date = ?
    ");
    $checkStmt->execute([$locationId, $stockDate]);
    if ($checkStmt->fetch()) {
        $errors[] = 'Stock taking already exists for this location and date. Please edit the existing one.';
    }
    
    if (empty($errors)) {
        try {
            $conn->beginTransaction();
            
            // Calculate expected sales amount
            $salesStmt = $conn->prepare("
                SELECT COALESCE(SUM(total_amount), 0) as total_sales
                FROM sales
                WHERE location_id = ? AND sale_date = ?
            ");
            $salesStmt->execute([$locationId, $stockDate]);
            $expectedSales = $salesStmt->fetchColumn();
            
            // Create stock taking header
            $takingNumber = getNextNumber('stock_taking', 'stock_taking_number', getSetting('stock_taking_prefix', 'STK'));
            
            $stmt = $conn->prepare("
                INSERT INTO stock_taking 
                (stock_taking_number, stock_date, location_id, calculation_method, expected_sales_amount, actual_cash_amount, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$takingNumber, $stockDate, $locationId, $calculationMethod, $expectedSales, $actualCash, getUserId()]);
            
            $takingId = $conn->lastInsertId();
            
            // Calculate previous day for opening stock
            $prevDate = date('Y-m-d', strtotime($stockDate . ' -1 day'));
            
            // Insert details
            $detailStmt = $conn->prepare("
                INSERT INTO stock_taking_details 
                (taking_id, product_id, system_opening, system_purchases, system_sales, physical_stock, variance_amount)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            foreach ($products as $index => $productId) {
                $physical = floatval($physicalQty[$index] ?? 0);
                
                // Get opening stock (previous day's closing)
                $openingStmt = $conn->prepare("
                    SELECT COALESCE(SUM(quantity_remaining), 0) as opening
                    FROM stock_batches
                    WHERE product_id = ? AND location_id = ? AND batch_date <= ?
                ");
                $openingStmt->execute([$productId, $locationId, $prevDate]);
                $opening = $openingStmt->fetchColumn();
                
                // Subtract sales from previous day and before to get actual opening
                $prevSalesStmt = $conn->prepare("
                    SELECT COALESCE(SUM(sd.quantity), 0) as prev_sales
                    FROM sale_details sd
                    JOIN sales s ON sd.sale_id = s.id
                    WHERE sd.product_id = ? AND s.location_id = ? AND s.sale_date < ?
                ");
                $prevSalesStmt->execute([$productId, $locationId, $stockDate]);
                $prevSales = $prevSalesStmt->fetchColumn();
                
                $actualOpening = $opening - $prevSales;
                
                // Get purchases for selected date
                $purchaseStmt = $conn->prepare("
                    SELECT COALESCE(SUM(pd.quantity), 0) as purchases
                    FROM purchase_details pd
                    JOIN purchases p ON pd.purchase_id = p.id
                    WHERE pd.product_id = ? AND p.location_id = ? AND p.purchase_date = ?
                ");
                $purchaseStmt->execute([$productId, $locationId, $stockDate]);
                $purchases = $purchaseStmt->fetchColumn();
                
                // Get sales for selected date
                $salesDayStmt = $conn->prepare("
                    SELECT COALESCE(SUM(sd.quantity), 0) as sales
                    FROM sale_details sd
                    JOIN sales s ON sd.sale_id = s.id
                    WHERE sd.product_id = ? AND s.location_id = ? AND s.sale_date = ?
                ");
                $salesDayStmt->execute([$productId, $locationId, $stockDate]);
                $sales = $salesDayStmt->fetchColumn();
                
                $closing = $actualOpening + $purchases - $sales;
                $varianceQty = $physical - $closing;
                
                // Calculate variance amount based on selected method
                $varianceAmount = 0;
                
                if (abs($varianceQty) > 0.01) {
                    $rate = 0;
                    
                    if ($calculationMethod == 'FIFO') {
                        // Get oldest batch rate
                        $rateStmt = $conn->prepare("
                            SELECT rate_per_unit
                            FROM stock_batches
                            WHERE product_id = ? AND location_id = ? 
                            AND batch_date <= ? AND quantity_remaining > 0
                            ORDER BY batch_date ASC, id ASC
                            LIMIT 1
                        ");
                        $rateStmt->execute([$productId, $locationId, $stockDate]);
                        $rate = $rateStmt->fetchColumn() ?: 0;
                        
                    } elseif ($calculationMethod == 'LIFO') {
                        // Get newest batch rate
                        $rateStmt = $conn->prepare("
                            SELECT rate_per_unit
                            FROM stock_batches
                            WHERE product_id = ? AND location_id = ? 
                            AND batch_date <= ? AND quantity_remaining > 0
                            ORDER BY batch_date DESC, id DESC
                            LIMIT 1
                        ");
                        $rateStmt->execute([$productId, $locationId, $stockDate]);
                        $rate = $rateStmt->fetchColumn() ?: 0;
                        
                    } else { // AVERAGE
                        // Calculate weighted average
                        $rateStmt = $conn->prepare("
                            SELECT 
                                COALESCE(
                                    SUM(quantity_remaining * rate_per_unit) / NULLIF(SUM(quantity_remaining), 0),
                                    0
                                ) as avg_rate
                            FROM stock_batches
                            WHERE product_id = ? AND location_id = ? 
                            AND batch_date <= ? AND quantity_remaining > 0
                        ");
                        $rateStmt->execute([$productId, $locationId, $stockDate]);
                        $rate = $rateStmt->fetchColumn() ?: 0;
                    }
                    
                    $varianceAmount = $varianceQty * $rate;
                }
                
                $detailStmt->execute([
                    $takingId,
                    $productId,
                    $actualOpening,
                    $purchases,
                    $sales,
                    $physical,
                    $varianceAmount
                ]);
            }
            
            $conn->commit();
            
            logAudit('stock_taking', 'create', $takingId);
            setAlert('Stock taking saved successfully', 'success');
            redirect('modules/reports/stock_taking_view.php?id=' . $takingId);
            
        } catch (PDOException $e) {
            $conn->rollBack();
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
    
    if (!empty($errors)) {
        foreach ($errors as $error) {
            setAlert($error, 'danger');
        }
    }
}

require_once '../../includes/header.php';

// Get user's accessible locations
$locations = getUserLocations();

// Get selected location, date, and calculation method
$selectedLocation = $_GET['location'] ?? '';
$selectedDate = $_GET['date'] ?? date('Y-m-d');
$selectedMethod = $_GET['method'] ?? 'FIFO';

// Get products for selected location with stock data
$products = [];
$expectedSalesAmount = 0;

if ($selectedLocation && $selectedDate) {
    // Calculate previous day
    $prevDate = date('Y-m-d', strtotime($selectedDate . ' -1 day'));
    
    // Get expected sales amount
    $salesStmt = $conn->prepare("
        SELECT COALESCE(SUM(total_amount), 0) as total_sales
        FROM sales
        WHERE location_id = ? AND sale_date = ?
    ");
    $salesStmt->execute([$selectedLocation, $selectedDate]);
    $expectedSalesAmount = $salesStmt->fetchColumn();
    
    $stmt = $conn->prepare("
        SELECT 
            p.id,
            p.name,
            p.unit,
            pc.name as category_name
        FROM products p
        LEFT JOIN product_categories pc ON p.category_id = pc.id
        WHERE p.is_active = 1
        ORDER BY pc.name, p.name
    ");
    $stmt->execute();
    $allProducts = $stmt->fetchAll();
    
    // For each product, calculate opening, purchases, sales
    foreach ($allProducts as $product) {
        $productId = $product['id'];
        
        // Get opening stock (previous day's closing)
        $openingStmt = $conn->prepare("
            SELECT COALESCE(SUM(quantity_remaining), 0) as opening
            FROM stock_batches
            WHERE product_id = ? AND location_id = ? AND batch_date <= ?
        ");
        $openingStmt->execute([$productId, $selectedLocation, $prevDate]);
        $opening = $openingStmt->fetchColumn();
        
        // Subtract all sales before the selected date
        $prevSalesStmt = $conn->prepare("
            SELECT COALESCE(SUM(sd.quantity), 0) as prev_sales
            FROM sale_details sd
            JOIN sales s ON sd.sale_id = s.id
            WHERE sd.product_id = ? AND s.location_id = ? AND s.sale_date < ?
        ");
        $prevSalesStmt->execute([$productId, $selectedLocation, $selectedDate]);
        $prevSales = $prevSalesStmt->fetchColumn();
        
        $actualOpening = $opening - $prevSales;
        
        // Get purchases for selected date
        $purchaseStmt = $conn->prepare("
            SELECT COALESCE(SUM(pd.quantity), 0) as purchases
            FROM purchase_details pd
            JOIN purchases p ON pd.purchase_id = p.id
            WHERE pd.product_id = ? AND p.location_id = ? AND p.purchase_date = ?
        ");
        $purchaseStmt->execute([$productId, $selectedLocation, $selectedDate]);
        $purchases = $purchaseStmt->fetchColumn();
        
        // Get sales for selected date
        $salesStmt = $conn->prepare("
            SELECT COALESCE(SUM(sd.quantity), 0) as sales
            FROM sale_details sd
            JOIN sales s ON sd.sale_id = s.id
            WHERE sd.product_id = ? AND s.location_id = ? AND s.sale_date = ?
        ");
        $salesStmt->execute([$productId, $selectedLocation, $selectedDate]);
        $sales = $salesStmt->fetchColumn();
        
        $closing = $actualOpening + $purchases - $sales;
        
        $product['opening'] = $actualOpening;
        $product['purchases'] = $purchases;
        $product['sales'] = $sales;
        $product['closing'] = $closing;
        
        $products[] = $product;
    }
}
?>

<div class="row mb-3">
    <div class="col-md-6">
        <h2><i class="bi bi-clipboard-check"></i> Stock Taking</h2>
    </div>
    <div class="col-md-6 text-end">
        <a href="stock_taking_list.php" class="btn btn-secondary">
            <i class="bi bi-list"></i> View All Stock Takings
        </a>
    </div>
</div>

<!-- Location, Calculation Method & Date Selection -->
<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="" class="row g-3">
            <div class="col-md-4">
                <label for="location" class="form-label required">Location</label>
                <select name="location" id="location" class="form-select" required>
                    <option value="">-- Select Location --</option>
                    <?php foreach ($locations as $location): ?>
                    <option value="<?php echo $location['id']; ?>" 
                            <?php echo $selectedLocation == $location['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($location['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label for="method" class="form-label required">Calculation Method</label>
                <select name="method" id="method" class="form-select" required>
                    <option value="FIFO" <?php echo $selectedMethod == 'FIFO' ? 'selected' : ''; ?>>FIFO (First-In-First-Out)</option>
                    <option value="LIFO" <?php echo $selectedMethod == 'LIFO' ? 'selected' : ''; ?>>LIFO (Last-In-First-Out)</option>
                    <option value="AVERAGE" <?php echo $selectedMethod == 'AVERAGE' ? 'selected' : ''; ?>>Average Cost</option>
                </select>
                <small class="text-muted">For variance amount calculation</small>
            </div>
            <div class="col-md-3">
                <label for="date" class="form-label required">Stock Date</label>
                <input type="date" name="date" id="date" class="form-control" 
                       value="<?php echo $selectedDate; ?>" required max="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-arrow-clockwise"></i> Load
                </button>
            </div>
        </form>
    </div>
</div>

<?php if (!empty($products)): ?>
<!-- Stock Taking Entry Form -->
<form method="POST" action="" id="stockTakingForm">
    <?php echo csrfField(); ?>
    <input type="hidden" name="location_id" value="<?php echo $selectedLocation; ?>">
    <input type="hidden" name="stock_date" value="<?php echo $selectedDate; ?>">
    <input type="hidden" name="calculation_method" value="<?php echo $selectedMethod; ?>">
    
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                Enter Physical Stock Count 
                <span class="badge bg-info"><?php echo $selectedMethod; ?> Method</span>
            </h5>
            <div>
                <button type="button" class="btn btn-sm btn-secondary" onclick="clearAll()">
                    <i class="bi bi-x-circle"></i> Clear All
                </button>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0" id="stockTakingTable">
                    <thead class="table-light sticky-top">
                        <tr>
                            <th style="width: 4%;">Sr.</th>
                            <th style="width: 20%;">Product</th>
                            <th style="width: 12%;">Category</th>
                            <th style="width: 8%;">Unit</th>
                            <th style="width: 9%;" class="text-end">Opening</th>
                            <th style="width: 9%;" class="text-end">Purchase</th>
                            <th style="width: 9%;" class="text-end">Sales</th>
                            <th style="width: 9%;" class="text-end">Closing</th>
                            <th style="width: 14%;">Physical Stock</th>
                            <th style="width: 6%;" class="text-end">Variance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $currentCategory = '';
                        $rowNum = 0;
                        foreach ($products as $index => $product): 
                            $rowNum++;
                            
                            // Category separator
                            if ($currentCategory != $product['category_name']):
                                $currentCategory = $product['category_name'];
                        ?>
                        <tr class="table-secondary">
                            <td colspan="10">
                                <strong><i class="bi bi-tag"></i> <?php echo htmlspecialchars($currentCategory ?: 'Uncategorized'); ?></strong>
                            </td>
                        </tr>
                        <?php endif; ?>
                        
                        <tr>
                            <td><?php echo $rowNum; ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($product['name']); ?></strong>
                                <input type="hidden" name="products[]" value="<?php echo $product['id']; ?>">
                            </td>
                            <td><?php echo htmlspecialchars($product['category_name'] ?: '-'); ?></td>
                            <td><?php echo htmlspecialchars($product['unit']); ?></td>
                            <td class="text-end">
                                <span class="badge bg-secondary"><?php echo formatNumber($product['opening'], 2); ?></span>
                            </td>
                            <td class="text-end">
                                <span class="badge bg-success"><?php echo formatNumber($product['purchases'], 2); ?></span>
                            </td>
                            <td class="text-end">
                                <span class="badge bg-danger"><?php echo formatNumber($product['sales'], 2); ?></span>
                            </td>
                            <td class="text-end">
                                <strong class="closing-qty" data-closing="<?php echo $product['closing']; ?>">
                                    <?php echo formatNumber($product['closing'], 2); ?>
                                </strong>
                            </td>
                            <td>
                                <input 
                                    type="number" 
                                    step="0.01"
                                    class="form-control form-control-sm physical-input" 
                                    name="physical_qty[]"
                                    value=""
                                    data-row="<?php echo $index; ?>"
                                    data-closing="<?php echo $product['closing']; ?>"
                                    placeholder="Enter count"
                                    oninput="calculateVariance(<?php echo $index; ?>)"
                                >
                            </td>
                            <td class="text-end">
                                <span class="variance-display" id="variance-<?php echo $index; ?>">-</span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Cash Reconciliation Section -->
    <div class="card mb-3">
        <div class="card-header bg-info text-white">
            <h5 class="mb-0"><i class="bi bi-cash-stack"></i> Cash Reconciliation</h5>
        </div>
        <div class="card-body">
            <div class="row align-items-end">
                <div class="col-md-4">
                    <label class="form-label">Expected Sales Amount (System)</label>
                    <div class="input-group">
                        <span class="input-group-text"><?php echo CURRENCY_SYMBOL; ?></span>
                        <input type="text" class="form-control form-control-lg" 
                               value="<?php echo formatNumber($expectedSalesAmount, 2); ?>" 
                               readonly>
                    </div>
                    <small class="text-muted">Total sales for <?php echo formatDate($selectedDate); ?></small>
                </div>
                <div class="col-md-4">
                    <label class="form-label required">Actual Cash in Hand</label>
                    <div class="input-group">
                        <span class="input-group-text"><?php echo CURRENCY_SYMBOL; ?></span>
                        <input type="number" step="0.01" class="form-control form-control-lg" 
                               name="actual_cash_amount" id="actualCash"
                               placeholder="0.00" required
                               oninput="calculateCashVariance()">
                    </div>
                    <small class="text-muted">Enter actual cash counted</small>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Cash Variance</label>
                    <div class="input-group">
                        <span class="input-group-text"><?php echo CURRENCY_SYMBOL; ?></span>
                        <input type="text" class="form-control form-control-lg fw-bold" 
                               id="cashVariance" value="0.00" readonly>
                    </div>
                    <small class="text-muted" id="varianceHelp">Actual - Expected</small>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Submit Section -->
    <div class="card">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center">
                <div class="alert alert-info mb-0 flex-grow-1 me-3">
                    <i class="bi bi-info-circle"></i>
                    <strong>Instructions:</strong> Count physical stock for each product and enter the quantity. 
                    Enter the actual cash amount you have counted. Variance amounts will be calculated using <strong><?php echo $selectedMethod; ?></strong> method. 
                    Then submit to save the stock taking.
                </div>
                <?php if (hasPermission('stock_taking', 'create')): ?>
                <button type="submit" class="btn btn-success btn-lg">
                    <i class="bi bi-check-circle"></i> Submit Stock Taking
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</form>

<script>
const expectedSales = <?php echo $expectedSalesAmount; ?>;

function calculateVariance(rowIndex) {
    const physicalInput = document.querySelector(`input[data-row="${rowIndex}"]`);
    const closing = parseFloat(physicalInput.dataset.closing) || 0;
    const physical = parseFloat(physicalInput.value) || 0;
    const variance = physical - closing;
    
    const varianceDisplay = document.getElementById(`variance-${rowIndex}`);
    
    if (physicalInput.value === '') {
        varianceDisplay.textContent = '-';
        varianceDisplay.className = 'variance-display';
    } else {
        varianceDisplay.textContent = formatNumber(variance, 2);
        
        if (Math.abs(variance) < 0.01) {
            varianceDisplay.className = 'variance-display badge bg-success';
            varianceDisplay.textContent = '0.00';
        } else if (variance > 0) {
            varianceDisplay.className = 'variance-display badge bg-info';
            varianceDisplay.textContent = '+' + formatNumber(variance, 2);
        } else {
            varianceDisplay.className = 'variance-display badge bg-danger';
        }
    }
}

function calculateCashVariance() {
    const actualCash = parseFloat(document.getElementById('actualCash').value) || 0;
    const variance = actualCash - expectedSales;
    
    const varianceInput = document.getElementById('cashVariance');
    const varianceHelp = document.getElementById('varianceHelp');
    
    varianceInput.value = formatNumber(variance, 2);
    
    if (Math.abs(variance) < 0.01) {
        varianceInput.className = 'form-control form-control-lg fw-bold text-success';
        varianceHelp.textContent = 'Perfect match!';
        varianceHelp.className = 'text-success';
    } else if (variance > 0) {
        varianceInput.className = 'form-control form-control-lg fw-bold text-info';
        varianceHelp.textContent = 'Surplus cash';
        varianceHelp.className = 'text-info';
    } else {
        varianceInput.className = 'form-control form-control-lg fw-bold text-danger';
        varianceHelp.textContent = 'Cash shortage';
        varianceHelp.className = 'text-danger';
    }
}

function clearAll() {
    if (confirm('Are you sure you want to clear all entries?')) {
        document.querySelectorAll('.physical-input').forEach(input => {
            input.value = '';
        });
        document.querySelectorAll('.variance-display').forEach(el => {
            el.textContent = '-';
            el.className = 'variance-display';
        });
        document.getElementById('actualCash').value = '';
        calculateCashVariance();
    }
}

// Confirm before leaving if data entered
let dataEntered = false;
document.querySelectorAll('.physical-input, #actualCash').forEach(input => {
    input.addEventListener('input', function() {
        if (this.value) dataEntered = true;
    });
});

window.addEventListener('beforeunload', function(e) {
    if (dataEntered) {
        e.preventDefault();
        e.returnValue = '';
    }
});

document.getElementById('stockTakingForm')?.addEventListener('submit', function() {
    dataEntered = false;
});
</script>

<?php elseif ($selectedLocation): ?>
<div class="alert alert-info">
    <i class="bi bi-info-circle"></i> No products found. Please add products first in 
    <a href="../masters/products.php">Products Management</a>.
</div>
<?php endif; ?>

<style>
.sticky-top {
    position: sticky;
    top: 0;
    z-index: 10;
}

.table-responsive {
    max-height: 600px;
    overflow-y: auto;
}

.physical-input:focus {
    border-color: #0d6efd;
    box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
}

@media (max-width: 768px) {
    .table-responsive {
        font-size: 0.875rem;
    }
    
    .table th, .table td {
        padding: 0.5rem 0.25rem;
    }
}
</style>

<?php require_once '../../includes/footer.php'; ?>
