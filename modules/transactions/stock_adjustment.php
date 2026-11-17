<?php
require_once '../../config/database.php';
require_once '../../includes/functions.php';
$pageTitle = __('stock_adjustment');
if (!hasPermission('stock_adjustment', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('');
}

// Check if coming from stock taking
$stockTakingId = $_GET['stock_taking_id'] ?? null;
$stockTakingData = null;
$varianceItems = [];

if ($stockTakingId) {
    // Get stock taking header
    $stmt = $conn->prepare("
        SELECT st.*, l.name as location_name
        FROM stock_taking st
        JOIN locations l ON st.location_id = l.id
        WHERE st.id = ?
    ");
    $stmt->execute([$stockTakingId]);
    $stockTakingData = $stmt->fetch();
    
    if ($stockTakingData) {
        // Check if adjustment already created for this stock taking
        $checkStmt = $conn->prepare("SELECT id, adjustment_number FROM stock_adjustments WHERE stock_taking_id = ?");
        $checkStmt->execute([$stockTakingId]);
        $existingAdj = $checkStmt->fetch();
        
        if ($existingAdj) {
            setAlert(sprintf(__('adjustment_already_exists'), $existingAdj['adjustment_number']), 'warning');
            redirect('modules/transactions/stock_adjustment_view.php?id=' . $existingAdj['id']);
        }
        
        // Get variance items (only those with variance)
        $detailsStmt = $conn->prepare("
            SELECT 
                std.*,
                p.name as product_name,
                p.unit,
                pc.name as category_name
            FROM stock_taking_details std
            JOIN products p ON std.product_id = p.id
            LEFT JOIN product_categories pc ON p.category_id = pc.id
            WHERE std.taking_id = ? AND ABS(std.variance_qty) > 0.01
            ORDER BY pc.name, p.name
        ");
        $detailsStmt->execute([$stockTakingId]);
        $varianceItems = $detailsStmt->fetchAll();
    }
}

// Get currencies
$allCurrencies = $conn->query("SELECT * FROM currencies WHERE is_active = 1 ORDER BY is_default DESC, code ASC")->fetchAll();
$defaultCurrency = getDefaultCurrency();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && hasPermission('stock_adjustment', 'create')) {
    checkCSRF();

    $locationId = (int)$_POST['location_id'];
    $adjustmentDate = dbDate($_POST['adjustment_date']);
    $remarks = clean($_POST['remarks'] ?? '');
    $stockTakingIdPost = (int)($_POST['stock_taking_id'] ?? 0) ?: null;
    $currencyId = !empty($_POST['currency_id']) ? (int)$_POST['currency_id'] : ($defaultCurrency ? $defaultCurrency['id'] : null);
    $exchangeRateToBase = !empty($_POST['exchange_rate_to_base']) ? floatval($_POST['exchange_rate_to_base']) : 1;
    
    $products = $_POST['products'] ?? [];
    $adjustmentTypes = $_POST['adjustment_type'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $rates = $_POST['rate'] ?? [];
    $itemRemarks = $_POST['item_remarks'] ?? [];
    
    $errors = [];

    if (empty($locationId)) {
        $errors[] = __('please_select_location');
    }

    if (empty($adjustmentDate)) {
        $errors[] = __('please_select_date');
    }

    // Validate at least one item
    $hasItems = false;
    foreach ($products as $index => $productId) {
        if (!empty($productId) && floatval($quantities[$index] ?? 0) > 0) {
            $hasItems = true;
            break;
        }
    }

    if (!$hasItems) {
        $errors[] = __('please_add_at_least_one_item');
    }
    
    if (empty($errors)) {
        try {
            $conn->beginTransaction();
            
            // Create adjustment header
            $adjustmentNumber = getNextNumber('stock_adjustments', 'adjustment_number', getSetting('adjustment_prefix', 'ADJ'));
            
            $stmt = $conn->prepare("
                INSERT INTO stock_adjustments
                (adjustment_number, location_id, adjustment_date, stock_taking_id, currency_id, exchange_rate_to_base, remarks, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$adjustmentNumber, $locationId, $adjustmentDate, $stockTakingIdPost, $currencyId, $exchangeRateToBase, $remarks, getUserId()]);
            
            $adjustmentId = $conn->lastInsertId();
            $totalAmount = 0;
            
            // Insert details and update stock batches
            $detailStmt = $conn->prepare("
                INSERT INTO stock_adjustment_details 
                (adjustment_id, product_id, adjustment_type, quantity, rate_per_unit, remarks)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            foreach ($products as $index => $productId) {
                $productId = (int)$productId;
                $qty = floatval($quantities[$index] ?? 0);
                $rate = floatval($rates[$index] ?? 0);
                $type = $adjustmentTypes[$index] ?? 'increase';
                $itemRemark = clean($itemRemarks[$index] ?? '');
                
                if ($productId > 0 && $qty > 0) {
                    // Insert detail
                    $detailStmt->execute([
                        $adjustmentId,
                        $productId,
                        $type,
                        $qty,
                        $rate,
                        $itemRemark
                    ]);
                    
                    $detailId = $conn->lastInsertId();
                    $amount = $qty * $rate;
                    
                    if ($type === 'increase') {
                        // Create new batch for increase
                        $batchStmt = $conn->prepare("
                            INSERT INTO stock_batches 
                            (location_id, product_id, batch_date, transaction_type, transaction_id, quantity_in, quantity_remaining, rate_per_unit)
                            VALUES (?, ?, ?, 'adjustment', ?, ?, ?, ?)
                        ");
                        $batchStmt->execute([$locationId, $productId, $adjustmentDate, $detailId, $qty, $qty, $rate]);
                        $totalAmount += $amount;
                    } else {
                        // Decrease: deduct from existing batches (FIFO)
                        $remainingQty = $qty;
                        
                        $batchesStmt = $conn->prepare("
                            SELECT id, quantity_remaining, rate_per_unit
                            FROM stock_batches
                            WHERE product_id = ? AND location_id = ? AND quantity_remaining > 0
                            ORDER BY batch_date ASC, id ASC
                        ");
                        $batchesStmt->execute([$productId, $locationId]);
                        $batches = $batchesStmt->fetchAll();
                        
                        foreach ($batches as $batch) {
                            if ($remainingQty <= 0) break;
                            
                            $deductQty = min($remainingQty, $batch['quantity_remaining']);
                            $newRemaining = $batch['quantity_remaining'] - $deductQty;
                            
                            $updateBatchStmt = $conn->prepare("
                                UPDATE stock_batches SET quantity_remaining = ? WHERE id = ?
                            ");
                            $updateBatchStmt->execute([$newRemaining, $batch['id']]);
                            
                            $remainingQty -= $deductQty;
                        }
                        
                        $totalAmount -= $amount;
                    }
                }
            }
            
            // Update total amount
            $updateStmt = $conn->prepare("UPDATE stock_adjustments SET total_adjustment_amount = ? WHERE id = ?");
            $updateStmt->execute([$totalAmount, $adjustmentId]);
            
            $conn->commit();

            logAudit('stock_adjustment', 'create', $adjustmentId);
            setAlert(__('stock_adjustment_created'), 'success');
            redirect('modules/transactions/stock_adjustment_view.php?id=' . $adjustmentId);

        } catch (PDOException $e) {
            $conn->rollBack();
            $errors[] = sprintf(__('database_error'), $e->getMessage());
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

// Get all products for dropdown
$productsStmt = $conn->query("
    SELECT p.id, p.name, p.unit, pc.name as category_name
    FROM products p
    LEFT JOIN product_categories pc ON p.category_id = pc.id
    WHERE p.is_active = 1
    ORDER BY pc.name, p.name
");
$allProducts = $productsStmt->fetchAll();
?>

<div class="row justify-content-center">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="bi bi-sliders"></i>
                    <?php echo $stockTakingData ? __('create_adjustment_from_stock_taking') : __('stock_adjustment'); ?>
                </h5>
                <div>
                    <a href="stock_adjustment_list.php" class="btn btn-sm btn-secondary">
                        <i class="bi bi-list"></i> <?php _e('list'); ?>
                    </a>
                </div>
            </div>
            <div class="card-body">
                <?php if ($stockTakingData): ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i>
                    <?php _e('creating_adjustment_from'); ?> <strong><?php echo htmlspecialchars($stockTakingData['stock_taking_number']); ?></strong>
                    <?php _e('at'); ?> <strong><?php echo htmlspecialchars($stockTakingData['location_name']); ?></strong>
                    <?php _e('dated'); ?> <strong><?php echo formatDate($stockTakingData['stock_date']); ?></strong>
                </div>
                <?php endif; ?>
                
                <form method="POST" id="adjustmentForm">
                    <?php echo csrfField(); ?>
                    <?php if ($stockTakingIdPost ?? $stockTakingId): ?>
                    <input type="hidden" name="stock_taking_id" value="<?php echo $stockTakingIdPost ?? $stockTakingId; ?>">
                    <?php endif; ?>
                    
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label required"><?php _e('location'); ?></label>
                            <select name="location_id" id="locationId" class="form-select" required <?php echo $stockTakingData ? 'readonly' : ''; ?>>
                                <option value=""><?php _e('select_location'); ?></option>
                                <?= renderTreeOptions($locations, $stockTakingData ? $stockTakingData['location_id'] : null) ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label required"><?php _e('adjustment_date'); ?></label>
                            <input type="date" name="adjustment_date" id="adjustmentDate" class="form-control"
                                   value="<?php echo $stockTakingData ? $stockTakingData['stock_date'] : date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php _e('remarks'); ?></label>
                            <input type="text" name="remarks" class="form-control"
                                   value="<?php echo $stockTakingData ? __('adjustment_from_stock_taking') . ' ' . htmlspecialchars($stockTakingData['stock_taking_number']) : ''; ?>"
                                   placeholder="<?php echo __('reason_for_adjustment'); ?>">
                        </div>
                    </div>
                    <div class="row mb-4">
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
                    
                    <h6 class="text-muted mb-3"><?php _e('adjustment_items'); ?></h6>

                    <div class="table-responsive">
                        <table class="table table-bordered table-sm" id="adjustmentTable">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 5%;"><?php _e('sr'); ?></th>
                                    <th style="width: 25%;"><?php _e('product'); ?></th>
                                    <th style="width: 12%;"><?php _e('type'); ?></th>
                                    <th style="width: 12%;"><?php _e('quantity'); ?></th>
                                    <th style="width: 12%;"><?php _e('rate'); ?></th>
                                    <th style="width: 12%;"><?php _e('amount'); ?></th>
                                    <th style="width: 17%;"><?php _e('remarks'); ?></th>
                                    <th style="width: 5%;"><?php _e('action'); ?></th>
                                </tr>
                            </thead>
                            <tbody id="adjustmentItems">
                                <?php if (!empty($varianceItems)): ?>
                                    <?php foreach ($varianceItems as $index => $item): ?>
                                    <?php 
                                        $type = $item['variance_qty'] > 0 ? 'increase' : 'decrease';
                                        $qty = abs($item['variance_qty']);
                                        $rate = $qty > 0 ? abs($item['variance_amount']) / $qty : 0;
                                    ?>
                                    <tr class="item-row">
                                        <td class="row-number"><?php echo $index + 1; ?></td>
                                        <td>
                                            <select name="products[]" class="form-select form-select-sm product-select" required>
                                                <option value=""><?php _e('select_product'); ?></option>
                                                <?php foreach ($allProducts as $product): ?>
                                                <option value="<?php echo $product['id']; ?>"
                                                    <?php echo $product['id'] == $item['product_id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($product['name']); ?> (<?php echo $product['unit']; ?>)
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <select name="adjustment_type[]" class="form-select form-select-sm type-select">
                                                <option value="increase" <?php echo $type === 'increase' ? 'selected' : ''; ?>><?php _e('increase'); ?></option>
                                                <option value="decrease" <?php echo $type === 'decrease' ? 'selected' : ''; ?>><?php _e('decrease'); ?></option>
                                            </select>
                                        </td>
                                        <td>
                                            <input type="number" name="quantity[]" step="0.001" min="0.001" 
                                                   class="form-control form-control-sm qty-input" 
                                                   value="<?php echo $qty; ?>" required onchange="calculateAmount(this)">
                                        </td>
                                        <td>
                                            <input type="number" name="rate[]" step="0.01" min="0" 
                                                   class="form-control form-control-sm rate-input" 
                                                   value="<?php echo number_format($rate, 2, '.', ''); ?>" required onchange="calculateAmount(this)">
                                        </td>
                                        <td>
                                            <input type="text" class="form-control form-control-sm amount-display" 
                                                   value="<?php echo number_format($qty * $rate, 2); ?>" readonly>
                                        </td>
                                        <td>
                                            <input type="text" name="item_remarks[]" class="form-control form-control-sm"
                                                   value="<?php _e('variance'); ?>: <?php echo $item['variance_qty'] > 0 ? '+' : ''; ?><?php echo number_format($item['variance_qty'], 3); ?>">
                                        </td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-sm btn-danger" onclick="removeRow(this)">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr class="item-row">
                                        <td class="row-number">1</td>
                                        <td>
                                            <select name="products[]" class="form-select form-select-sm product-select" required>
                                                <option value=""><?php _e('select_product'); ?></option>
                                                <?php foreach ($allProducts as $product): ?>
                                                <option value="<?php echo $product['id']; ?>">
                                                    <?php echo htmlspecialchars($product['name']); ?> (<?php echo $product['unit']; ?>)
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <select name="adjustment_type[]" class="form-select form-select-sm type-select">
                                                <option value="increase"><?php _e('increase'); ?></option>
                                                <option value="decrease"><?php _e('decrease'); ?></option>
                                            </select>
                                        </td>
                                        <td>
                                            <input type="number" name="quantity[]" step="0.001" min="0.001" 
                                                   class="form-control form-control-sm qty-input" required onchange="calculateAmount(this)">
                                        </td>
                                        <td>
                                            <input type="number" name="rate[]" step="0.01" min="0" 
                                                   class="form-control form-control-sm rate-input" required onchange="calculateAmount(this)">
                                        </td>
                                        <td>
                                            <input type="text" class="form-control form-control-sm amount-display" value="0.00" readonly>
                                        </td>
                                        <td>
                                            <input type="text" name="item_remarks[]" class="form-control form-control-sm" placeholder="<?php echo __('optional'); ?>">
                                        </td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-sm btn-danger" onclick="removeRow(this)">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="5" class="text-end"><strong><?php _e('total'); ?>:</strong></td>
                                    <td><strong id="totalAmount">0.00</strong></td>
                                    <td colspan="2">
                                        <button type="button" class="btn btn-sm btn-primary" onclick="addRow()">
                                            <i class="bi bi-plus"></i> <?php _e('add_row'); ?>
                                        </button>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    
                    <div class="d-flex justify-content-end mt-4">
                        <a href="stock_adjustment_list.php" class="btn btn-secondary me-2"><?php _e('cancel'); ?></a>
                        <?php if (hasPermission('stock_adjustment', 'create')): ?>
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check-circle"></i> <?php _e('save_adjustment'); ?>
                        </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
const productsData = <?php echo json_encode($allProducts); ?>;

function addRow() {
    const tbody = document.getElementById('adjustmentItems');
    const rowCount = tbody.querySelectorAll('.item-row').length + 1;

    let productOptions = '<option value=""><?php echo __('select_product'); ?></option>';
    productsData.forEach(p => {
        productOptions += `<option value="${p.id}">${escapeHtml(p.name)} (${p.unit})</option>`;
    });

    const newRow = document.createElement('tr');
    newRow.className = 'item-row';
    newRow.innerHTML = `
        <td class="row-number">${rowCount}</td>
        <td>
            <select name="products[]" class="form-select form-select-sm product-select" required>
                ${productOptions}
            </select>
        </td>
        <td>
            <select name="adjustment_type[]" class="form-select form-select-sm type-select">
                <option value="increase"><?php echo __('increase'); ?></option>
                <option value="decrease"><?php echo __('decrease'); ?></option>
            </select>
        </td>
        <td>
            <input type="number" name="quantity[]" step="0.001" min="0.001"
                   class="form-control form-control-sm qty-input" required onchange="calculateAmount(this)">
        </td>
        <td>
            <input type="number" name="rate[]" step="0.01" min="0"
                   class="form-control form-control-sm rate-input" required onchange="calculateAmount(this)">
        </td>
        <td>
            <input type="text" class="form-control form-control-sm amount-display" value="0.00" readonly>
        </td>
        <td>
            <input type="text" name="item_remarks[]" class="form-control form-control-sm" placeholder="<?php echo __('optional'); ?>">
        </td>
        <td class="text-center">
            <button type="button" class="btn btn-sm btn-danger" onclick="removeRow(this)">
                <i class="bi bi-trash"></i>
            </button>
        </td>
    `;

    tbody.appendChild(newRow);
}

function removeRow(btn) {
    const tbody = document.getElementById('adjustmentItems');
    if (tbody.querySelectorAll('.item-row').length > 1) {
        btn.closest('tr').remove();
        renumberRows();
        calculateTotal();
    } else {
        alert('<?php echo __('at_least_one_item_required'); ?>');
    }
}

function renumberRows() {
    document.querySelectorAll('#adjustmentItems .item-row').forEach((row, index) => {
        row.querySelector('.row-number').textContent = index + 1;
    });
}

function calculateAmount(input) {
    const row = input.closest('tr');
    const qty = parseFloat(row.querySelector('.qty-input').value) || 0;
    const rate = parseFloat(row.querySelector('.rate-input').value) || 0;
    const amount = qty * rate;
    row.querySelector('.amount-display').value = formatNumber(amount, 2);
    calculateTotal();
}

function calculateTotal() {
    let total = 0;
    document.querySelectorAll('#adjustmentItems .item-row').forEach(row => {
        const type = row.querySelector('.type-select').value;
        const amount = parseFloat(row.querySelector('.amount-display').value.replace(/,/g, '')) || 0;
        total += (type === 'increase' ? amount : -amount);
    });
    document.getElementById('totalAmount').textContent = formatNumber(total, 2);
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Recalculate on type change
document.addEventListener('change', function(e) {
    if (e.target.classList.contains('type-select')) {
        calculateTotal();
    }
});

// Initial calculation
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.qty-input').forEach(input => calculateAmount(input));
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
    const adjDate = document.getElementById('adjustmentDate').value || new Date().toISOString().split('T')[0];
    try {
        const url = `<?php echo BASE_URL; ?>api/exchange-rates.php?from_currency_id=${currencyId}&to_currency_id=${defaultCurrencyId}&_=${Date.now()}`;
        const response = await fetch(url);
        if (response.ok) {
            const result = await response.json();
            if (result.success && result.exchange_rates && result.exchange_rates.length > 0) {
                const applicable = result.exchange_rates.filter(r => r.effective_date <= adjDate);
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
                    const applicable = revResult.exchange_rates.filter(r => r.effective_date <= adjDate);
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

<?php require_once '../../includes/footer.php'; ?>
