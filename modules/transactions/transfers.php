<?php
/**
 * Stock Transfer Form
 * Create and edit stock transfer drafts
 */
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");

require_once '../../config/database.php';
require_once '../../includes/functions.php';

$isEdit = isset($_GET['id']) && !empty($_GET['id']);
$pageTitle = $isEdit ? __('edit_transfer') : __('add_new_transfer');

// Check permissions
$requiredPermission = $isEdit ? 'edit' : 'create';
if (!hasPermission('stock_transfer', $requiredPermission)) {
    setAlert(__('no_permission'), 'danger');
    redirect('modules/transactions/transfers_management.php');
}

// Get user's accessible locations
$userLocations = getUserLocations();

// Initialize transfer data
$transfer = [
    'id' => '',
    'transfer_date' => date('Y-m-d'),
    'from_location_id' => '',
    'to_location_id' => '',
    'vehicle_number' => '',
    'driver_name' => '',
    'driver_phone' => '',
    'remarks' => '',
    'status' => 'draft'
];
$transferDetails = [];

// If editing, load existing transfer
if ($isEdit) {
    $id = (int)$_GET['id'];
    $stmt = $conn->prepare("
        SELECT t.*, fl.name as from_location_name, tl.name as to_location_name
        FROM stock_transfers t
        LEFT JOIN locations fl ON t.from_location_id = fl.id
        LEFT JOIN locations tl ON t.to_location_id = tl.id
        WHERE t.id = ?
    ");
    $stmt->execute([$id]);
    $transfer = $stmt->fetch();

    if (!$transfer) {
        setAlert(__('transfer_not_found'), 'danger');
        redirect('modules/transactions/transfers_management.php');
    }

    // Can only edit draft transfers
    if ($transfer['status'] !== 'draft') {
        setAlert(__('cannot_edit_dispatched'), 'warning');
        redirect('modules/transactions/transfers_management.php');
    }

    // A reverse transfer is not editable through this (forward) form — editing
    // here would drop the requisition links it carries. Manage it from the list
    // (dispatch or delete + recreate from the source transfer instead).
    if (!empty($transfer['reverse_of_transfer_id'])) {
        setAlert(__('reverse_not_editable'), 'warning');
        redirect('modules/transactions/transfers_management.php');
    }

    // User must have access to FROM location
    if (!canAccessLocation($transfer['from_location_id'])) {
        setAlert(__('no_permission'), 'danger');
        redirect('modules/transactions/transfers_management.php');
    }

    // Load transfer details
    $detailsStmt = $conn->prepare("
        SELECT td.*, p.name as product_name, p.unit
        FROM stock_transfer_details td
        LEFT JOIN products p ON td.product_id = p.id
        WHERE td.transfer_id = ?
        ORDER BY td.id
    ");
    $detailsStmt->execute([$id]);
    $transferDetails = $detailsStmt->fetchAll();
}

// Get all locations for dropdown
$locationsStmt = $conn->query("SELECT id, parent_id, name FROM locations WHERE is_active = 1 ORDER BY name");
$allLocations = $locationsStmt->fetchAll();

// Get products
$productsStmt = $conn->query("
    SELECT p.id, p.name, p.unit, pc.name as category_name
    FROM products p
    LEFT JOIN product_categories pc ON p.category_id = pc.id
    WHERE p.is_active = 1
    ORDER BY p.name
");
$products = $productsStmt->fetchAll();

require_once '../../includes/header.php';
?>

<div class="row mb-3">
    <div class="col-md-6">
        <h2>
            <i class="bi bi-box-arrow-right"></i>
            <?php echo $isEdit ? __('edit_transfer') : __('add_new_transfer'); ?>
        </h2>
    </div>
    <div class="col-md-6 text-end">
        <a href="transfers_management.php" class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i> <?php _e('cancel'); ?>
        </a>
    </div>
</div>

<form id="transferForm" method="POST">
    <input type="hidden" id="transferId" name="id" value="<?php echo htmlspecialchars($transfer['id']); ?>">

    <div class="card mb-3">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-info-circle"></i> <?php _e('transfer_details'); ?></h5>
        </div>
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-md-3">
                    <label class="form-label"><?php _e('transfer_date'); ?> <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" id="transferDate" name="transfer_date"
                           value="<?php echo htmlspecialchars($transfer['transfer_date']); ?>"
                           max="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label"><?php _e('from_location'); ?> <span class="text-danger">*</span></label>
                    <select class="form-select" id="fromLocationId" name="from_location_id" required onchange="onFromLocationChange()">
                        <option value=""><?php _e('select_from_location'); ?></option>
                        <?= renderTreeOptions($userLocations, $transfer['from_location_id']) ?>
                    </select>
                    <small class="text-muted"><?php _e('from_location'); ?> - <?php echo count($userLocations); ?> location(s) available</small>
                </div>
                <div class="col-md-4">
                    <label class="form-label"><?php _e('to_location'); ?> <span class="text-danger">*</span></label>
                    <select class="form-select" id="toLocationId" name="to_location_id" required>
                        <option value=""><?php _e('select_to_location'); ?></option>
                        <?= renderTreeOptions($allLocations, $transfer['to_location_id']) ?>
                    </select>
                </div>
            </div>

            <div class="row mb-3">
                <div class="col-md-3">
                    <label class="form-label"><?php _e('cost_centre'); ?></label>
                    <select class="form-select" id="costCentreId" name="cost_centre_id">
                        <option value=""><?php _e('select_cost_centre'); ?></option>
                        <?= renderTreeOptions(getUserCostCentres(), isset($transfer['cost_centre_id']) ? $transfer['cost_centre_id'] : null, null, 'name', 'code') ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?php _e('currency'); ?></label>
                    <select class="form-select" id="currencyId" name="currency_id" onchange="onCurrencyChange()">
                    </select>
                </div>
            </div>

            <div class="row mb-3">
                <div class="col-md-3" id="exchangeRateGroup" style="display:none;">
                    <label class="form-label"><?php _e('exchange_rate'); ?></label>
                    <input type="number" class="form-control" id="exchangeRateToBase" step="0.00000001" min="0.00000001" value="1">
                    <small class="text-muted" id="exchangeRateHint"></small>
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?php _e('vehicle_number'); ?></label>
                    <input type="text" class="form-control" id="vehicleNumber" name="vehicle_number"
                           value="<?php echo htmlspecialchars($transfer['vehicle_number']); ?>"
                           placeholder="e.g., T 123 ABC" maxlength="50">
                </div>
                <div class="col-md-2">
                    <label class="form-label"><?php _e('driver_name'); ?></label>
                    <input type="text" class="form-control" id="driverName" name="driver_name"
                           value="<?php echo htmlspecialchars($transfer['driver_name']); ?>"
                           placeholder="e.g., John Doe" maxlength="100">
                </div>
                <div class="col-md-2">
                    <label class="form-label"><?php _e('driver_phone'); ?></label>
                    <input type="tel" class="form-control" id="driverPhone" name="driver_phone"
                           value="<?php echo htmlspecialchars($transfer['driver_phone']); ?>"
                           placeholder="e.g., 0712345678" maxlength="20">
                </div>
                <div class="col-md-2">
                    <label class="form-label"><?php _e('remarks'); ?></label>
                    <input type="text" class="form-control" id="remarks" name="remarks"
                           value="<?php echo htmlspecialchars($transfer['remarks']); ?>"
                           placeholder="<?php _e('optional'); ?>">
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-box-seam"></i> <?php _e('transfer_items'); ?></h5>
            <span id="stockInfo" class="badge bg-info" style="display:none;"></span>
        </div>
        <div class="card-body">
            <!-- Add Item Row -->
            <div class="row g-2 mb-3">
                <div class="col-md-5">
                    <select class="form-select" id="productSelect">
                        <option value=""><?php _e('select_product_to_add'); ?></option>
                        <?php foreach ($products as $product): ?>
                        <option value="<?php echo $product['id']; ?>"
                                data-unit="<?php echo htmlspecialchars($product['unit']); ?>"
                                data-name="<?php echo htmlspecialchars($product['name']); ?>">
                            <?php echo htmlspecialchars($product['name']); ?>
                            (<?php echo htmlspecialchars($product['category_name'] ?? 'No Category'); ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <input type="number" class="form-control" id="itemQuantity"
                           placeholder="<?php _e('quantity'); ?>" min="0.001" step="0.001">
                </div>
                <div class="col-md-2">
                    <input type="number" class="form-control" id="itemRate"
                           placeholder="<?php _e('rate_per_unit'); ?>" min="0" step="0.01" readonly>
                    <small class="text-muted">FIFO Cost</small>
                </div>
                <div class="col-md-2">
                    <span id="availableStock" class="form-control-plaintext text-muted">-</span>
                </div>
                <div class="col-md-1">
                    <button type="button" class="btn btn-success w-100" onclick="addItem()">
                        <i class="bi bi-plus"></i>
                    </button>
                </div>
            </div>

            <!-- Items Table -->
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th><?php _e('product'); ?></th>
                            <th class="text-center"><?php _e('unit'); ?></th>
                            <th class="text-end"><?php _e('quantity'); ?></th>
                            <th class="text-end"><?php _e('rate_per_unit'); ?></th>
                            <th class="text-end"><?php _e('amount'); ?></th>
                            <th class="text-center"><?php _e('action'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="itemsTableBody">
                        <tr id="noItemsRow">
                            <td colspan="7" class="text-center text-muted"><?php _e('no_items_added'); ?></td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="table-active">
                            <th colspan="3" class="text-end"><?php _e('total'); ?>:</th>
                            <th class="text-end" id="totalQuantity">0.000</th>
                            <th></th>
                            <th class="text-end" id="totalAmount"><?php echo $defaultCurrencySymbol; ?> 0.00</th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- E-Signatures -->
    <div class="card mb-3">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-pen"></i> E-Signatures <small class="text-muted">(optional)</small></h5>
        </div>
        <div class="card-body">
            <style>
                .signature-wrapper { border: 1px dashed #adb5bd; border-radius: 4px; background: #fafafa; position: relative; }
                .signature-canvas { display: block; width: 100%; height: 140px; touch-action: none; cursor: crosshair; }
                .signature-placeholder { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: #adb5bd; font-style: italic; pointer-events: none; user-select: none; font-size: 13px; }
            </style>
            <?php $myMasterSig = getUserSignature($conn, getUserId()); ?>
            <?php if ($myMasterSig): ?>
            <div class="border rounded p-2 bg-light">
                <img src="<?php echo htmlspecialchars($myMasterSig); ?>" alt="<?php _e('signature'); ?>" style="max-height: 90px; max-width: 100%;">
                <div class="text-muted small mt-1"><i class="bi bi-info-circle"></i> <?php _e('signature_from_profile'); ?></div>
            </div>
            <?php else: ?>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-bold"><?php _e('prepared_by'); ?></label>
                    <div class="signature-wrapper">
                        <canvas id="preparedBySignature" class="signature-canvas"></canvas>
                        <span class="signature-placeholder" id="preparedBySignaturePlaceholder">Sign here</span>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-secondary mt-1" onclick="clearSignaturePad('prepared')">
                        <i class="bi bi-x-circle"></i> <?php _e('clear'); ?>
                    </button>
                    <?php
                    /* Not everyone has a touchscreen. Offer an image upload as an alternative
                       to drawing; it is loaded INTO the pad above, so the submit code is
                       unchanged. Only reachable in this else-branch, i.e. only for signers
                       with no signature on file. */
                    $sigUploadCanvasId      = 'preparedBySignature';
                    $sigUploadPlaceholderId = 'preparedBySignaturePlaceholder';
                    require __DIR__ . '/../../includes/signature_upload_control.php';
                    ?>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-bold"><?php _e('dispatched_by'); ?></label>
                    <small class="text-muted d-block mb-1">(captured only when "Save &amp; Dispatch" is used)</small>
                    <div class="signature-wrapper">
                        <canvas id="dispatchedBySignature" class="signature-canvas"></canvas>
                        <span class="signature-placeholder" id="dispatchedBySignaturePlaceholder">Sign here</span>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-secondary mt-1" onclick="clearSignaturePad('dispatched')">
                        <i class="bi bi-x-circle"></i> <?php _e('clear'); ?>
                    </button>
                    <?php
                    /* Not everyone has a touchscreen. Offer an image upload as an alternative
                       to drawing; it is loaded INTO the pad above, so the submit code is
                       unchanged. Only reachable in this else-branch, i.e. only for signers
                       with no signature on file. */
                    $sigUploadCanvasId      = 'dispatchedBySignature';
                    $sigUploadPlaceholderId = 'dispatchedBySignaturePlaceholder';
                    /* Both pads on this page belong to the SAME user, so the
                       "save to my profile" checkbox is offered once, on the
                       prepared-by pad above, rather than twice. */
                    $sigUploadOfferSave     = false;
                    require __DIR__ . '/../../includes/signature_upload_control.php';
                    ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="card">
        <div class="card-body">
            <div class="d-flex justify-content-between">
                <a href="transfers_management.php" class="btn btn-secondary">
                    <i class="bi bi-x-circle"></i> <?php _e('cancel'); ?>
                </a>
                <div>
                    <button type="button" class="btn btn-primary" onclick="saveTransfer(false)" id="saveBtn">
                        <i class="bi bi-check-circle"></i> <?php _e('save_transfer'); ?>
                    </button>
                    <?php if (hasPermission('stock_transfer', 'create')): ?>
                    <button type="button" class="btn btn-success" onclick="saveTransfer(true)" id="saveDispatchBtn">
                        <i class="bi bi-truck"></i> <?php _e('save_transfer'); ?> &amp; <?php _e('dispatch_transfer'); ?>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</form>

<script src="<?php echo BASE_URL; ?>assets/js/signature_pad.js"></script>

<script>
const CURRENCY_SYMBOL = '<?php echo $defaultCurrencySymbol; ?>';
const isEdit = <?php echo $isEdit ? 'true' : 'false'; ?>;
const allowNegativeStock = <?php echo getSetting('allow_negative_stock', '0') === '1' ? 'true' : 'false'; ?>;

// Existing signatures (when editing)
const existingPreparedSig = <?php echo json_encode($transfer['prepared_by_signature'] ?? null); ?>;
const existingDispatchedSig = <?php echo json_encode($transfer['dispatched_by_signature'] ?? null); ?>;

// Signature pad instances
let preparedSigPad = null;
let dispatchedSigPad = null;

function initSignaturePads() {
    const preparedCanvas = document.getElementById('preparedBySignature');
    const dispatchedCanvas = document.getElementById('dispatchedBySignature');
    if (preparedCanvas && window.SignaturePad) {
        preparedSigPad = new SignaturePad(preparedCanvas, { penColor: '#000', lineWidth: 2 });
        if (existingPreparedSig) {
            preparedSigPad.fromDataURL(existingPreparedSig);
            document.getElementById('preparedBySignaturePlaceholder').style.display = 'none';
        }
        preparedCanvas.addEventListener('pointerdown', function() {
            document.getElementById('preparedBySignaturePlaceholder').style.display = 'none';
        });
    }
    if (dispatchedCanvas && window.SignaturePad) {
        dispatchedSigPad = new SignaturePad(dispatchedCanvas, { penColor: '#000', lineWidth: 2 });
        if (existingDispatchedSig) {
            dispatchedSigPad.fromDataURL(existingDispatchedSig);
            document.getElementById('dispatchedBySignaturePlaceholder').style.display = 'none';
        }
        dispatchedCanvas.addEventListener('pointerdown', function() {
            document.getElementById('dispatchedBySignaturePlaceholder').style.display = 'none';
        });
    }
}

function clearSignaturePad(which) {
    if (which === 'prepared' && preparedSigPad) {
        preparedSigPad.clear();
        document.getElementById('preparedBySignaturePlaceholder').style.display = '';
    } else if (which === 'dispatched' && dispatchedSigPad) {
        dispatchedSigPad.clear();
        document.getElementById('dispatchedBySignaturePlaceholder').style.display = '';
    }
}

// Items array
let itemsArray = <?php echo json_encode(array_map(function($d) {
    return [
        'product_id' => $d['product_id'],
        'product_name' => $d['product_name'],
        'unit' => $d['unit'],
        'quantity' => floatval($d['dispatched_quantity'] ?? 0),
        'rate_per_unit' => floatval($d['rate_per_unit'] ?? 0)
    ];
}, $transferDetails)); ?>;

// Currency
let currencies = [];
let defaultCurrencyId = null;
let selectedCurrencySymbol = '<?php echo $defaultCurrencySymbol; ?>';

// Stock cache
let stockCache = {};

document.addEventListener('DOMContentLoaded', function() {
    loadCurrencies();
    updateItemsTable();
    initSignaturePads();

    // Make product dropdown searchable (type-to-filter)
    $('#productSelect').select2({
        width: '100%',
        placeholder: '<?php echo addslashes(__('select_product_to_add')); ?>'
    });

    // Product select change - fetch FIFO cost and available stock
    $('#productSelect').on('change', function() {
        const productId = this.value;
        const locationId = document.getElementById('fromLocationId').value;

        if (!productId) {
            document.getElementById('itemRate').value = '';
            document.getElementById('availableStock').textContent = '-';
            return;
        }

        if (!locationId) {
            alert('<?php _e('please_select_location'); ?>');
            $(this).val('').trigger('change');
            return;
        }

        fetchProductStock(productId, locationId);
    });

    // Auto-select if only one from location
    const fromSelect = document.getElementById('fromLocationId');
    if (fromSelect.options.length === 2 && !fromSelect.value) {
        fromSelect.selectedIndex = 1;
        onFromLocationChange();
    }
});

// Load currencies
async function loadCurrencies() {
    try {
        const response = await fetch(BASE_URL + 'api/currencies.php?_=' + Date.now(), {
            method: 'GET',
            headers: { 'Cache-Control': 'no-cache' },
            cache: 'no-store'
        });
        if (response.ok) {
            const result = await response.json();
            if (result.success && result.currencies) {
                currencies = result.currencies;
            }
        }
    } catch (error) {
        console.error('Failed to load currencies:', error);
    }

    const currencySelect = document.getElementById('currencyId');
    currencySelect.innerHTML = '';
    currencies.forEach(c => {
        const opt = document.createElement('option');
        opt.value = c.id;
        opt.textContent = c.code + ' - ' + c.name;
        opt.dataset.symbol = c.symbol;
        opt.dataset.isDefault = c.is_default;
        if (c.is_default == 1) {
            opt.selected = true;
            defaultCurrencyId = parseInt(c.id);
            selectedCurrencySymbol = c.symbol;
        }
        currencySelect.appendChild(opt);
    });

    <?php if ($isEdit && isset($transfer['currency_id'])): ?>
    currencySelect.value = '<?php echo $transfer['currency_id']; ?>';
    const curOpt = currencySelect.options[currencySelect.selectedIndex];
    if (curOpt) selectedCurrencySymbol = curOpt.dataset.symbol || selectedCurrencySymbol;
    if (parseInt('<?php echo $transfer['currency_id'] ?? 0; ?>') !== defaultCurrencyId) {
        document.getElementById('exchangeRateGroup').style.display = '';
        document.getElementById('exchangeRateToBase').value = '<?php echo $transfer['exchange_rate_to_base'] ?? '1'; ?>';
    }
    <?php endif; ?>
}

function onCurrencyChange() {
    const select = document.getElementById('currencyId');
    const currId = parseInt(select.value);
    const opt = select.options[select.selectedIndex];
    selectedCurrencySymbol = opt ? opt.dataset.symbol : CURRENCY_SYMBOL;

    const exchangeRateGroup = document.getElementById('exchangeRateGroup');
    if (currId === defaultCurrencyId) {
        exchangeRateGroup.style.display = 'none';
        document.getElementById('exchangeRateToBase').value = '1';
    } else {
        exchangeRateGroup.style.display = '';
        fetchExchangeRate(currId);
    }
    updateItemsTable();
}

async function fetchExchangeRate(currencyId) {
    const transferDate = document.getElementById('transferDate').value || new Date().toISOString().split('T')[0];
    try {
        const url = `${BASE_URL}api/exchange-rates.php?from_currency_id=${currencyId}&to_currency_id=${defaultCurrencyId}&_=${Date.now()}`;
        const response = await fetch(url);
        if (response.ok) {
            const result = await response.json();
            if (result.success && result.exchange_rates && result.exchange_rates.length > 0) {
                const applicable = result.exchange_rates.filter(r => r.effective_date <= transferDate);
                if (applicable.length > 0) {
                    document.getElementById('exchangeRateToBase').value = applicable[0].rate;
                    const fromCur = currencies.find(c => c.id == currencyId);
                    const toCur = currencies.find(c => c.id == defaultCurrencyId);
                    document.getElementById('exchangeRateHint').textContent =
                        `1 ${fromCur ? fromCur.code : ''} = ${applicable[0].rate} ${toCur ? toCur.code : ''}`;
                    return;
                }
            }
            // Try reverse
            const revUrl = `${BASE_URL}api/exchange-rates.php?from_currency_id=${defaultCurrencyId}&to_currency_id=${currencyId}&_=${Date.now()}`;
            const revResponse = await fetch(revUrl);
            if (revResponse.ok) {
                const revResult = await revResponse.json();
                if (revResult.success && revResult.exchange_rates && revResult.exchange_rates.length > 0) {
                    const applicable = revResult.exchange_rates.filter(r => r.effective_date <= transferDate);
                    if (applicable.length > 0 && parseFloat(applicable[0].rate) !== 0) {
                        const reverseRate = (1 / parseFloat(applicable[0].rate)).toFixed(8);
                        document.getElementById('exchangeRateToBase').value = reverseRate;
                        const fromCur = currencies.find(c => c.id == currencyId);
                        const toCur = currencies.find(c => c.id == defaultCurrencyId);
                        document.getElementById('exchangeRateHint').textContent =
                            `1 ${fromCur ? fromCur.code : ''} = ${reverseRate} ${toCur ? toCur.code : ''} (reverse)`;
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

function onFromLocationChange() {
    // Clear items if location changed and items exist
    const fromLocationId = document.getElementById('fromLocationId').value;
    const toLocationId = document.getElementById('toLocationId').value;

    // Validate same location
    if (fromLocationId && toLocationId && fromLocationId === toLocationId) {
        alert('<?php _e('same_location_error'); ?>');
        document.getElementById('toLocationId').value = '';
    }

    // Clear stock cache when location changes
    stockCache = {};
    document.getElementById('itemRate').value = '';
    document.getElementById('availableStock').textContent = '-';
    $('#productSelect').val('').trigger('change');
}

async function fetchProductStock(productId, locationId) {
    const cacheKey = `${productId}_${locationId}`;

    if (stockCache[cacheKey]) {
        applyStockInfo(stockCache[cacheKey]);
        return;
    }

    try {
        const response = await fetch(`${BASE_URL}ajax/get_product_stock.php?product_id=${productId}&location_id=${locationId}`);
        const result = await response.json();

        if (result.success) {
            stockCache[cacheKey] = result;
            applyStockInfo(result);
        } else {
            document.getElementById('itemRate').value = '0';
            document.getElementById('availableStock').textContent = 'N/A';
        }
    } catch (error) {
        console.error('Error fetching stock:', error);
        document.getElementById('itemRate').value = '0';
        document.getElementById('availableStock').textContent = 'Error';
    }
}

function applyStockInfo(data) {
    document.getElementById('itemRate').value = parseFloat(data.fifo_cost || 0).toFixed(2);
    const available = parseFloat(data.available_stock || 0);
    document.getElementById('availableStock').innerHTML =
        `<span class="${available <= 0 ? 'text-danger' : 'text-success'}">
            Available: ${available.toFixed(3)}
        </span>`;
}

function addItem() {
    const productSelect = document.getElementById('productSelect');
    const productId = productSelect.value;
    const quantity = parseFloat(document.getElementById('itemQuantity').value);
    const rate = parseFloat(document.getElementById('itemRate').value) || 0;
    const fromLocationId = document.getElementById('fromLocationId').value;

    if (!fromLocationId) {
        alert('<?php _e('please_select_location'); ?>');
        return;
    }

    if (!productId) {
        alert('Please select a product');
        return;
    }

    if (!quantity || quantity <= 0) {
        alert('Please enter a valid quantity');
        return;
    }

    // Check if product already added
    const existingIndex = itemsArray.findIndex(item => item.product_id == productId);
    if (existingIndex !== -1) {
        alert('This product is already added. Remove it first to change quantity.');
        return;
    }

    // Check stock availability (if not allowing negative)
    if (!allowNegativeStock) {
        const cacheKey = `${productId}_${fromLocationId}`;
        const stockInfo = stockCache[cacheKey];
        if (stockInfo) {
            const available = parseFloat(stockInfo.available_stock || 0);

            // Calculate already allocated quantity for this product
            const allocatedQty = itemsArray
                .filter(item => item.product_id == productId)
                .reduce((sum, item) => sum + item.quantity, 0);

            if (quantity > (available - allocatedQty)) {
                alert(`Insufficient stock. Available: ${(available - allocatedQty).toFixed(3)}, Requested: ${quantity.toFixed(3)}`);
                return;
            }
        }
    }

    // Get product details
    const option = productSelect.options[productSelect.selectedIndex];
    const productName = option.dataset.name;
    const unit = option.dataset.unit;

    // Add to array
    itemsArray.push({
        product_id: productId,
        product_name: productName,
        unit: unit,
        quantity: quantity,
        rate: rate
    });

    // Clear inputs
    $('#productSelect').val('').trigger('change');
    document.getElementById('itemQuantity').value = '';
    document.getElementById('itemRate').value = '';
    document.getElementById('availableStock').textContent = '-';

    updateItemsTable();
}

function removeItem(index) {
    if (confirm('Remove this item?')) {
        itemsArray.splice(index, 1);
        updateItemsTable();
    }
}

function updateItemsTable() {
    const tbody = document.getElementById('itemsTableBody');

    if (itemsArray.length === 0) {
        tbody.innerHTML = '<tr id="noItemsRow"><td colspan="7" class="text-center text-muted"><?php _e('no_items_added'); ?></td></tr>';
        document.getElementById('totalQuantity').textContent = '0.000';
        document.getElementById('totalAmount').textContent = selectedCurrencySymbol + ' 0.00';
        return;
    }

    let totalQty = 0;
    let totalAmt = 0;

    tbody.innerHTML = itemsArray.map((item, index) => {
        const rate = item.rate_per_unit ?? item.rate;
        const amount = item.quantity * rate;
        totalQty += item.quantity;
        totalAmt += amount;

        return `
            <tr>
                <td>${index + 1}</td>
                <td>${escapeHtml(item.product_name)}</td>
                <td class="text-center">${escapeHtml(item.unit)}</td>
                <td class="text-end">${item.quantity.toFixed(3)}</td>
                <td class="text-end">${selectedCurrencySymbol} ${rate.toFixed(2)}</td>
                <td class="text-end">${selectedCurrencySymbol} ${amount.toFixed(2)}</td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-danger" onclick="removeItem(${index})">
                        <i class="bi bi-trash"></i>
                    </button>
                </td>
            </tr>
        `;
    }).join('');

    document.getElementById('totalQuantity').textContent = totalQty.toFixed(3);
    document.getElementById('totalAmount').textContent = selectedCurrencySymbol + ' ' + totalAmt.toFixed(2);
}

async function saveTransfer(dispatchAfterSave = false) {
    // Validate form
    const transferDate = document.getElementById('transferDate').value;
    const fromLocationId = document.getElementById('fromLocationId').value;
    const toLocationId = document.getElementById('toLocationId').value;

    if (!transferDate) {
        alert('<?php _e('please_select_date'); ?>');
        return;
    }

    if (!fromLocationId) {
        alert('<?php _e('select_from_location'); ?>');
        return;
    }

    if (!toLocationId) {
        alert('<?php _e('select_to_location'); ?>');
        return;
    }

    if (fromLocationId === toLocationId) {
        alert('<?php _e('same_location_error'); ?>');
        return;
    }

    if (itemsArray.length === 0) {
        alert('Please add at least one item');
        return;
    }

    // Prepare data for sync API
    const transferId = document.getElementById('transferId').value || null;
    const costCentreId = document.getElementById('costCentreId').value;
    const currencyId = parseInt(document.getElementById('currencyId').value) || defaultCurrencyId;
    const exchangeRateToBase = parseFloat(document.getElementById('exchangeRateToBase').value) || 1;
    // Capture Prepared By signature (null when empty)
    const preparedSig = (preparedSigPad && !preparedSigPad.isEmpty()) ? preparedSigPad.toDataURL() : null;

    const data = {
        action: transferId ? 'update' : 'create',
        id: transferId,
        transfer_date: transferDate,
        from_location_id: parseInt(fromLocationId),
        to_location_id: parseInt(toLocationId),
        cost_centre_id: costCentreId ? parseInt(costCentreId) : null,
        currency_id: currencyId,
        exchange_rate_to_base: exchangeRateToBase,
        vehicle_number: document.getElementById('vehicleNumber').value,
        driver_name: document.getElementById('driverName').value,
        driver_phone: document.getElementById('driverPhone').value,
        remarks: document.getElementById('remarks').value,
        prepared_by_signature: preparedSig,
        items: itemsArray
    };

    // Disable buttons
    const saveBtn = document.getElementById('saveBtn');
    const saveDispatchBtn = document.getElementById('saveDispatchBtn');
    saveBtn.disabled = true;
    if (saveDispatchBtn) saveDispatchBtn.disabled = true;
    saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span><?php _e('saving'); ?>';

    try {
        const response = await fetch(BASE_URL + 'api/transfers-sync.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(data)
        });

        const result = await response.json();

        if (result.success) {
            // If dispatch after save, dispatch the transfer
            if (dispatchAfterSave && result.serverId) {
                const dispatchedSig = (dispatchedSigPad && !dispatchedSigPad.isEmpty()) ? dispatchedSigPad.toDataURL() : null;
                const dispatchResponse = await fetch(BASE_URL + 'api/transfers-sync.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        action: 'dispatch',
                        transfer_id: result.serverId,
                        dispatched_by_signature: dispatchedSig
                    })
                });
                const dispatchResult = await dispatchResponse.json();
                if (!dispatchResult.success) {
                    alert(dispatchResult.error || 'Saved but failed to dispatch');
                }
            }
            // Redirect to management page
            window.location.href = 'transfers_management.php';
        } else {
            alert(result.message || result.error || 'Failed to save transfer');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('An error occurred while saving');
    } finally {
        saveBtn.disabled = false;
        if (saveDispatchBtn) saveDispatchBtn.disabled = false;
        saveBtn.innerHTML = '<i class="bi bi-check-circle"></i> <?php _e('save_transfer'); ?>';
    }
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

<?php require_once '../../includes/footer.php'; ?>
