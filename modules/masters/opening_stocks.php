<?php
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");

require_once '../../config/database.php';
require_once '../../includes/functions.php';

$pageTitle = __('opening_stocks_title');

if (!hasPermission('opening_stocks', 'view')) {
    setAlert(__('error'), 'danger');
    redirect('');
}

// Get currencies
$allCurrencies = $conn->query("SELECT * FROM currencies WHERE is_active = 1 ORDER BY is_default DESC, code ASC")->fetchAll();
$defaultCurrency = getDefaultCurrency();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && hasPermission('opening_stocks', 'create')) {
    checkCSRF();

    $locationId = (int)$_POST['location_id'];
    $stockDate = dbDate($_POST['stock_date']);
    $currencyId = !empty($_POST['currency_id']) ? (int)$_POST['currency_id'] : ($defaultCurrency ? $defaultCurrency['id'] : null);
    $exchangeRateToBase = !empty($_POST['exchange_rate_to_base']) ? floatval($_POST['exchange_rate_to_base']) : 1;

    $products = $_POST['products'] ?? [];
    $quantities = $_POST['quantities'] ?? [];
    $rates = $_POST['rates'] ?? [];
    
    $errors = [];
    $successCount = 0;
    
    // Validation
    if (empty($locationId)) {
        $errors[] = __('please_select_location');
    }
    
    if (empty($stockDate)) {
        $errors[] = __('please_select_date');
    }
    
    if (empty($errors)) {
        try {
            $conn->beginTransaction();
             
            foreach ($products as $index => $productId) {
                $productId = (int)$productId;
                $quantity = floatval($quantities[$index] ?? 0);
                $rate = floatval($rates[$index] ?? 0);
                
                // Quantity 0/empty → delete any existing opening stock (rate alone doesn't represent stock)
                if ($quantity <= 0) {
                    $delOs = $conn->prepare("DELETE FROM opening_stocks WHERE location_id = ? AND product_id = ?");
                    $delOs->execute([$locationId, $productId]);
                    $delBatch = $conn->prepare("DELETE FROM stock_batches WHERE location_id = ? AND product_id = ? AND transaction_type = 'opening'");
                    $delBatch->execute([$locationId, $productId]);
                    if ($delOs->rowCount() > 0) {
                        $successCount++;
                    }
                    continue;
                }

                // Quantity > 0 still requires a rate
                if ($rate <= 0) {
                    $conn->rollBack();
                    $errors[] = __('please_enter_both_qty_rate');
                    break;
                }
                
                // One opening stock per (location, product) — check ignores date.
                $checkStmt = $conn->prepare("
                    SELECT id FROM opening_stocks
                    WHERE location_id = ? AND product_id = ?
                ");
                $checkStmt->execute([$locationId, $productId]);
                $existing = $checkStmt->fetch();

                if ($existing) {
                    $stmt = $conn->prepare("
                        UPDATE opening_stocks
                        SET stock_date = ?, quantity = ?, rate_per_unit = ?, currency_id = ?, exchange_rate_to_base = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$stockDate, $quantity, $rate, $currencyId, $exchangeRateToBase, $existing['id']]);
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO opening_stocks
                        (location_id, product_id, stock_date, quantity, rate_per_unit, currency_id, exchange_rate_to_base, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$locationId, $productId, $stockDate, $quantity, $rate, $currencyId, $exchangeRateToBase, getUserId()]);
                }

                // Corresponding stock_batches 'opening' row — also one per (location, product).
                $batchCheckStmt = $conn->prepare("
                    SELECT id FROM stock_batches
                    WHERE location_id = ? AND product_id = ? AND transaction_type = 'opening'
                ");
                $batchCheckStmt->execute([$locationId, $productId]);
                $existingBatch = $batchCheckStmt->fetch();

                if ($existingBatch) {
                    $stmt = $conn->prepare("
                        UPDATE stock_batches
                        SET batch_date = ?, quantity_in = ?, quantity_remaining = ?, rate_per_unit = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$stockDate, $quantity, $quantity, $rate, $existingBatch['id']]);
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO stock_batches
                        (location_id, product_id, batch_date, transaction_type, quantity_in, quantity_remaining, rate_per_unit)
                        VALUES (?, ?, ?, 'opening', ?, ?, ?)
                    ");
                    $stmt->execute([$locationId, $productId, $stockDate, $quantity, $quantity, $rate]);
                }
                
                $successCount++;
            }
            
            if (empty($errors)) {
                $conn->commit();

                logAudit('opening_stocks', 'bulk_create', null, null, [
                    'location_id' => $locationId,
                    'stock_date' => $stockDate,
                    'products_count' => $successCount
                ]);

                setAlert(sprintf(__('opening_stocks_saved'), $successCount), 'success');
                $filteredProductId = !empty($_POST['filter_product_id']) ? (int)$_POST['filter_product_id'] : 0;
                $redirectUrl = 'modules/masters/opening_stocks.php?location=' . $locationId . '&date=' . $stockDate;
                if ($filteredProductId > 0) {
                    $redirectUrl .= '&product=' . $filteredProductId;
                }
                redirect($redirectUrl);
            }
            
        } catch (PDOException $e) {
            $conn->rollBack();
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
    
    // Display errors
    if (!empty($errors)) {
        foreach ($errors as $error) {
            setAlert($error, 'danger');
        }
    }
}

require_once '../../includes/header.php';

// Get selected location and date
$selectedLocation = $_GET['location'] ?? ($_POST['location_id'] ?? '');
$selectedDate = $_GET['date'] ?? ($_POST['stock_date'] ?? date('Y-m-d'));
$selectedProduct = isset($_GET['product']) && $_GET['product'] !== '' ? (int)$_GET['product'] : 0;

// Get user's accessible locations
$locations = getUserLocations();

// Get all active products for the filter dropdown (id + name only — lightweight)
$allProducts = $conn->query("SELECT id, name FROM products WHERE is_active = 1 ORDER BY name")->fetchAll();

// Resolve currently-selected product name (for repopulating the search input on reload)
$selectedProductName = '';
if ($selectedProduct > 0) {
    foreach ($allProducts as $pRow) {
        if ((int)$pRow['id'] === $selectedProduct) {
            $selectedProductName = $pRow['name'];
            break;
        }
    }
}

// Get products for selected location (optionally filtered by single product)
$products = [];
if ($selectedLocation) {
    $sql = "
        SELECT
            p.id,
            p.name,
            p.unit,
            pc.name as category_name,
            COALESCE(os.quantity, 0) as existing_quantity,
            COALESCE(os.rate_per_unit, 0) as existing_rate
        FROM products p
        LEFT JOIN product_categories pc ON p.category_id = pc.id
        LEFT JOIN opening_stocks os
            ON os.location_id = ? AND os.product_id = p.id AND os.stock_date = ?
        WHERE p.is_active = 1
    ";
    $params = [$selectedLocation, $selectedDate];
    if ($selectedProduct > 0) {
        $sql .= " AND p.id = ?";
        $params[] = $selectedProduct;
    }
    $sql .= " ORDER BY pc.name, p.name";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll();
}
?>

<div class="row mb-3">
    <div class="col-md-6">
        <h2><i class="bi bi-clipboard-data"></i> <?php _e('opening_stocks_title'); ?></h2>
    </div>
    <div class="col-md-6 text-end">
        <?php if (hasPermission('opening_stocks', 'import')): ?>
        <a href="<?php echo BASE_URL; ?>modules/masters/opening_stocks-import.php" class="btn btn-outline-primary">
            <i class="bi bi-upload"></i> <?php _e('import'); ?>
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Location & Date Selection -->
<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="" class="row g-3">
            <div class="col-md-4">
                <label for="location" class="form-label required"><?php _e('location'); ?></label>
                <select name="location" id="location" class="form-select" required>
                    <option value=""><?php _e('select_location'); ?></option>
                    <?= renderTreeOptions($locations, $selectedLocation) ?>
                </select>
            </div>
            <div class="col-md-3">
                <label for="date" class="form-label required"><?php _e('stock_date'); ?></label>
                <input type="date" name="date" id="date" class="form-control"
                       value="<?php echo $selectedDate; ?>" required>
            </div>
            <div class="col-md-3">
                <label for="productSearch" class="form-label">Product <small class="text-muted">(optional — leave empty for all)</small></label>
                <input type="text" list="productList" id="productSearch" class="form-control"
                       placeholder="Type to search..." autocomplete="off"
                       value="<?php echo htmlspecialchars($selectedProductName); ?>">
                <input type="hidden" name="product" id="productHidden" value="<?php echo $selectedProduct ?: ''; ?>">
                <datalist id="productList">
                    <?php foreach ($allProducts as $p): ?>
                    <option value="<?php echo htmlspecialchars($p['name']); ?>" data-id="<?php echo (int)$p['id']; ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-arrow-clockwise"></i> <?php _e('load_products'); ?>
                </button>
            </div>
        </form>
        <script>
        (function() {
            var search = document.getElementById('productSearch');
            var hidden = document.getElementById('productHidden');
            var list = document.getElementById('productList');
            if (!search || !hidden || !list) return;
            var nameToId = {};
            for (var i = 0; i < list.options.length; i++) {
                nameToId[list.options[i].value] = list.options[i].getAttribute('data-id');
            }
            function sync() {
                var v = search.value.trim();
                hidden.value = (v && nameToId[v]) ? nameToId[v] : '';
            }
            search.addEventListener('input', sync);
            search.addEventListener('change', sync);
        })();
        </script>
    </div>
</div>

<?php if (!empty($products)): ?>
<!-- Opening Stocks Entry Form -->
<form method="POST" action="" id="openingStocksForm">
    <?php echo csrfField(); ?>
    <input type="hidden" name="location_id" value="<?php echo $selectedLocation; ?>">
    <input type="hidden" name="stock_date" value="<?php echo $selectedDate; ?>">
    <input type="hidden" name="filter_product_id" value="<?php echo $selectedProduct ?: ''; ?>">

    <div class="card mb-3">
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <label class="form-label"><?php _e('currency'); ?></label>
                    <select name="currency_id" id="currencyId" class="form-select" onchange="onCurrencyChange()">
                        <?php foreach ($allCurrencies as $cur): ?>
                        <option value="<?php echo $cur['id']; ?>"
                            data-symbol="<?php echo htmlspecialchars($cur['symbol']); ?>"
                            data-is-default="<?php echo $cur['is_default']; ?>"
                            <?php echo $cur['is_default'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cur['code'] . ' - ' . $cur['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4" id="exchangeRateGroup" style="display:none;">
                    <label class="form-label"><?php _e('exchange_rate'); ?></label>
                    <input type="number" class="form-control" id="exchangeRateToBase" name="exchange_rate_to_base"
                           step="0.00000001" min="0.00000001" value="1">
                    <small class="text-muted" id="exchangeRateHint"></small>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><?php _e('enter_opening_stocks'); ?></h5>
            <div>
                <button type="button" class="btn btn-sm btn-secondary" onclick="clearAll()">
                    <i class="bi bi-x-circle"></i> <?php _e('clear_all'); ?>
                </button>
                <?php if (hasPermission('opening_stocks', 'create')): ?>
                <button type="submit" class="btn btn-sm btn-success">
                    <i class="bi bi-check-circle"></i> <?php _e('save_all'); ?>
                </button>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0" id="openingStocksTable">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 5%;">#</th>
                            <th style="width: 30%;"><?php _e('product'); ?></th>
                            <th style="width: 15%;"><?php _e('category'); ?></th>
                            <th style="width: 10%;"><?php _e('unit'); ?></th>
                            <th style="width: 15%;"><?php _e('quantity'); ?></th>
                            <th style="width: 15%;"><?php _e('rate_per_unit'); ?></th>
                            <th style="width: 10%;" class="text-end"><?php _e('total_value'); ?></th>
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
                            <td colspan="7">
                                <strong><i class="bi bi-tag"></i> <?php echo htmlspecialchars($currentCategory); ?></strong>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td><?php echo $rowNum; ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($product['name']); ?></strong>
                                <input type="hidden" name="products[]" value="<?php echo $product['id']; ?>">
                            </td>
                            <td><?php echo htmlspecialchars($product['category_name']); ?></td>
                            <td><?php echo htmlspecialchars($product['unit']); ?></td>
                            <td>
                                <input 
                                    type="number" 
                                    step="0.001"
                                    min="0"
                                    class="form-control form-control-sm quantity-input" 
                                    name="quantities[]"
                                    value="<?php echo $product['existing_quantity'] > 0 ? $product['existing_quantity'] : ''; ?>"
                                    data-row="<?php echo $index; ?>"
                                    placeholder="0"
                                >
                            </td>
                            <td>
                                <input 
                                    type="number" 
                                    step="0.01"
                                    min="0"
                                    class="form-control form-control-sm rate-input" 
                                    name="rates[]"
                                    value="<?php echo $product['existing_rate'] > 0 ? $product['existing_rate'] : ''; ?>"
                                    data-row="<?php echo $index; ?>"
                                    placeholder="0.00"
                                >
                            </td>
                            <td class="text-end">
                                <strong class="total-value" id="total-<?php echo $index; ?>">0.00</strong>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <th colspan="6" class="text-end"><?php _e('grand_total'); ?>:</th>
                            <th class="text-end">
                                <strong id="grandTotal"><?php echo formatCurrency(0); ?></strong>
                            </th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <div class="card-footer">
            <div class="d-flex justify-content-between align-items-center">
                <small class="text-muted">
                    <i class="bi bi-info-circle"></i> <?php _e('opening_stocks_hint'); ?>
                </small>
                <?php if (hasPermission('opening_stocks', 'create')): ?>
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-check-circle"></i> <?php _e('save_opening_stocks'); ?>
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</form>

<script>
const LANG = {
    confirm_clear_all: <?php echo json_encode(__('confirm_clear_all')); ?>
};

let dataEntered = false;

// Single delegated listener on the table — replaces thousands of per-input listeners
document.getElementById('openingStocksTable').addEventListener('input', function(e) {
    const target = e.target;
    if (!target.classList.contains('quantity-input') && !target.classList.contains('rate-input')) return;

    const row = target.dataset.row;
    const quantity = parseFloat(document.querySelector(`input[name="quantities[]"][data-row="${row}"]`).value) || 0;
    const rate = parseFloat(document.querySelector(`input[name="rates[]"][data-row="${row}"]`).value) || 0;

    document.getElementById(`total-${row}`).textContent = formatNumber(quantity * rate, 2);
    calculateGrandTotal();
    dataEntered = true;
});

function calculateGrandTotal() {
    let grandTotal = 0;
    document.querySelectorAll('.total-value').forEach(el => {
        const value = parseFloat(el.textContent.replace(/,/g, '')) || 0;
        grandTotal += value;
    });
    document.getElementById('grandTotal').textContent = '<?php echo $defaultCurrencySymbol; ?> ' + formatNumber(grandTotal, 2);
}

function clearAll() {
    if (confirm(LANG.confirm_clear_all)) {
        document.querySelectorAll('.quantity-input, .rate-input').forEach(input => {
            input.value = '';
        });
        document.querySelectorAll('.total-value').forEach(el => {
            el.textContent = '0.00';
        });
        calculateGrandTotal();
    }
}

// Calculate totals on page load for existing values
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.quantity-input').forEach(input => {
        if (input.value) {
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }
    });
});

// Confirm before leaving if data entered (dataEntered is set by the delegated listener above)
window.addEventListener('beforeunload', function(e) {
    if (dataEntered) {
        e.preventDefault();
        e.returnValue = '';
    }
});

document.getElementById('openingStocksForm').addEventListener('submit', function() {
    dataEntered = false;
});

// Currency handling
const defaultCurrencyId = <?php echo $defaultCurrency ? $defaultCurrency['id'] : 'null'; ?>;

function onCurrencyChange() {
    const select = document.getElementById('currencyId');
    const currId = parseInt(select.value);
    const exchangeRateGroup = document.getElementById('exchangeRateGroup');

    if (currId === defaultCurrencyId) {
        exchangeRateGroup.style.display = 'none';
        document.getElementById('exchangeRateToBase').value = '1';
    } else {
        exchangeRateGroup.style.display = '';
        fetchExchangeRate(currId);
    }
}

async function fetchExchangeRate(currencyId) {
    const stockDate = '<?php echo $selectedDate; ?>';
    try {
        const url = `<?php echo BASE_URL; ?>api/exchange-rates.php?from_currency_id=${currencyId}&to_currency_id=${defaultCurrencyId}&_=${Date.now()}`;
        const response = await fetch(url);
        if (response.ok) {
            const result = await response.json();
            if (result.success && result.exchange_rates && result.exchange_rates.length > 0) {
                const applicable = result.exchange_rates.filter(r => r.effective_date <= stockDate);
                if (applicable.length > 0) {
                    document.getElementById('exchangeRateToBase').value = applicable[0].rate;
                    document.getElementById('exchangeRateHint').textContent =
                        `1 ${applicable[0].from_currency_code} = ${applicable[0].rate} ${applicable[0].to_currency_code}`;
                    return;
                }
            }
            const revUrl = `<?php echo BASE_URL; ?>api/exchange-rates.php?from_currency_id=${defaultCurrencyId}&to_currency_id=${currencyId}&_=${Date.now()}`;
            const revResponse = await fetch(revUrl);
            if (revResponse.ok) {
                const revResult = await revResponse.json();
                if (revResult.success && revResult.exchange_rates && revResult.exchange_rates.length > 0) {
                    const applicable = revResult.exchange_rates.filter(r => r.effective_date <= stockDate);
                    if (applicable.length > 0 && parseFloat(applicable[0].rate) !== 0) {
                        const reverseRate = (1 / parseFloat(applicable[0].rate)).toFixed(8);
                        document.getElementById('exchangeRateToBase').value = reverseRate;
                        document.getElementById('exchangeRateHint').textContent =
                            `1 ${applicable[0].to_currency_code} = ${reverseRate} ${applicable[0].from_currency_code} (reverse)`;
                        return;
                    }
                }
            }
        }
    } catch (error) {
        console.error('Failed to fetch exchange rate:', error);
    }
    document.getElementById('exchangeRateHint').textContent = 'No rate found - please enter manually';
}
</script>

<?php elseif ($selectedLocation): ?>
<div class="alert alert-info">
    <i class="bi bi-info-circle"></i> <?php _e('no_products_found_add'); ?> 
    <a href="products.php"><?php _e('products_management'); ?></a>.
</div>
<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>
