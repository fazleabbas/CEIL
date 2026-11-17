<?php
/**
 * Sales Management - With Offline Support
 * Users can create sales offline, view existing sales
 * Edit/Delete only available online
 * Version 2: With inline editing of quantities and rates
 */
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");

require_once '../../config/database.php';
require_once '../../includes/functions.php';

$pageTitle = __('sales_management');

if (!hasPermission('sales', 'view')) {
    setAlert('You do not have permission to access this page', 'danger');
    redirect('');
}

// Get default cash customer
$cashCustomerStmt = $conn->query("
    SELECT c.id, la.name 
    FROM customers c 
    INNER JOIN ledger_accounts la ON c.ledger_account_id = la.id 
    WHERE la.name LIKE '%Cash%' AND c.is_active = 1 
    ORDER BY c.id ASC 
    LIMIT 1
");
$cashCustomer = $cashCustomerStmt->fetch();
$defaultCustomerId = $cashCustomer ? $cashCustomer['id'] : '';
$defaultCustomerName = $cashCustomer ? $cashCustomer['name'] : 'Cash Customer';

require_once '../../includes/header.php';

$currentUserId = getUserId();
?>

<!-- Online Status Indicator -->
<div id="onlineStatus" class="badge bg-secondary position-fixed" style="top: 10px; right: 10px; z-index: 1050;">
    <i class="bi bi-wifi-off"></i> <?php _e('checking_connection'); ?>
</div>

<div class="row mb-3">
    <div class="col-md-6">
        <h2><i class="bi bi-cart-dash"></i> <?php _e('sales_management'); ?></h2>
    </div>
    <div class="col-md-6 text-end">
        <?php if (hasPermission('sales', 'create')): ?>
        <button class="btn btn-primary" onclick="showCreateModal()" id="createBtn">
            <i class="bi bi-plus-circle"></i> <?php _e('add_new_sale'); ?>
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- Search and Filter -->
<div class="card mb-3">
    <div class="card-body">
        <div class="row g-2">
            <div class="col-md-2">
                <input type="date" id="dateFrom" class="form-control" placeholder="<?php _e('from_date'); ?>">
            </div>
            <div class="col-md-2">
                <input type="date" id="dateTo" class="form-control" placeholder="<?php _e('to_date'); ?>">
            </div>
            <div class="col-md-2">
                <select id="paymentModeFilter" class="form-select" onchange="filterSales()">
                    <option value=""><?php _e('all_payment_modes'); ?></option>
                    <option value="cash"><?php _e('cash'); ?></option>
                    <option value="card"><?php _e('card'); ?></option>
                    <option value="credit"><?php _e('credit'); ?></option>
                </select>
            </div>
            <div class="col-md-4">
                <input type="text" id="searchInput" class="form-control"
                       placeholder="<?php _e('search_sales'); ?>" onkeyup="filterSales()">
            </div>
            <div class="col-md-2">
                <button onclick="clearFilters()" class="btn btn-secondary w-100">
                    <i class="bi bi-x-circle"></i> <?php _e('clear'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Sales Table -->
<div class="card">
    <div class="card-body">
        <div id="loadingSpinner" class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden"><?php _e('loading'); ?></span>
            </div>
            <p class="mt-2"><?php _e('loading_sales'); ?></p>
        </div>
        
        <div id="salesContainer" style="display:none;">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <span id="saleCount" class="text-muted">0 sales</span>
                <span id="totalAmount" class="fw-bold text-primary">Total: TZS 0.00</span>
            </div>
            
            <div class="table-responsive">
                <table class="table table-hover table-striped">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 120px;"><?php _e('number'); ?></th>
                            <th style="width: 110px;"><?php _e('date'); ?></th>
                            <th><?php _e('customer'); ?></th>
                            <th><?php _e('print_name'); ?></th>
                            <th><?php _e('location'); ?></th>
                            <th><?php _e('cost_centre'); ?></th>
                            <th style="width: 80px;" class="text-center"><?php _e('items'); ?></th>
                            <th style="width: 140px;" class="text-end"><?php _e('amount'); ?></th>
                            <th style="width: 90px;" class="text-center"><?php _e('payment_mode'); ?></th>
                            <th style="width: 100px;" class="text-center"><?php _e('status'); ?></th>
                            <th style="width: 150px;" class="text-center no-export"><?php _e('actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="salesTableBody">
                        <!-- Sales will be loaded here -->
                    </tbody>
                </table>
            </div>
        </div>
        
        <div id="emptyState" style="display:none;" class="text-center py-5">
            <i class="bi bi-inbox" style="font-size: 3rem; color: #ccc;"></i>
            <p class="text-muted mt-3"><?php _e('no_sales_found'); ?></p>
            <?php if (hasPermission('sales', 'create')): ?>
            <button class="btn btn-primary mt-2" onclick="showCreateModal()">
                <i class="bi bi-plus-circle"></i> <?php _e('create_first_sale'); ?>
            </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- View Sale Modal -->
<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">
                    <i class="bi bi-eye"></i> <?php _e('sale_details'); ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong><?php _e('sale_number'); ?>:</strong><br>
                        <span id="viewSaleNumber" class="fs-5 text-primary"></span>
                    </div>
                    <div class="col-md-6 text-md-end">
                        <strong><?php _e('status'); ?>:</strong><br>
                        <span id="viewStatus"></span>
                    </div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-3">
                        <strong><i class="bi bi-calendar"></i> <?php _e('date'); ?>:</strong><br>
                        <span id="viewDate"></span>
                    </div>
                    <div class="col-md-3">
                        <strong><i class="bi bi-person"></i> <?php _e('customer'); ?>:</strong><br>
                        <span id="viewCustomer"></span>
                    </div>
                    <div class="col-md-3">
                        <strong><i class="bi bi-geo-alt"></i> <?php _e('location'); ?>:</strong><br>
                        <span id="viewLocation"></span>
                    </div>
                    <div class="col-md-3" id="viewCostCentreCol">
                        <strong><i class="bi bi-building"></i> <?php _e('cost_centre'); ?>:</strong><br>
                        <span id="viewCostCentre"></span>
                    </div>
                </div>
                
                <div class="row mb-3" id="viewNotesRow" style="display:none;">
                    <div class="col-12">
                        <strong><i class="bi bi-sticky"></i> <?php _e('notes'); ?>:</strong><br>
                        <span id="viewNotes" class="text-muted"></span>
                    </div>
                </div>
                
                <hr>
                
                <h6 class="mb-3"><i class="bi bi-box-seam"></i> <?php _e('sale_items'); ?></h6>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 5%;">#</th>
                                <th><?php _e('product'); ?></th>
                                <th class="text-center"><?php _e('unit'); ?></th>
                                <th class="text-end"><?php _e('quantity'); ?></th>
                                <th class="text-end"><?php _e('rate'); ?></th>
                                <th class="text-end"><?php _e('amount'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="viewItemsBody">
                        </tbody>
                        <tfoot>
                            <tr class="table-active fw-bold">
                                <td colspan="5" class="text-end"><?php _e('total_amount'); ?>:</td>
                                <td class="text-end" id="viewTotalAmount"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                
                <div class="row mt-3">
                    <div class="col-md-6">
                        <small class="text-muted">
                            <i class="bi bi-person"></i> <?php _e('created_by'); ?>: <span id="viewCreatedBy"></span>
                        </small>
                    </div>
                    <div class="col-md-6 text-md-end">
                        <small class="text-muted">
                            <i class="bi bi-clock"></i> <?php _e('created'); ?>: <span id="viewCreatedAt"></span>
                        </small>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> <?php _e('close'); ?>
                </button>
                <button type="button" class="btn btn-info" onclick="printSale()">
                    <i class="bi bi-printer"></i> <?php _e('print'); ?>
                </button>
                <?php if (hasPermission('sales', 'edit')): ?>
                <button type="button" class="btn btn-warning" id="viewEditBtn" onclick="editFromView()">
                    <i class="bi bi-pencil"></i> <?php _e('edit_sale'); ?>
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Create/Edit Sale Modal -->
<div class="modal fade" id="saleModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle"><?php _e('add_new_sale'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="saleForm">
                    <input type="hidden" id="saleId" name="id">
                    
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <label class="form-label"><?php _e('sale_date'); ?> <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="saleDate" required
                                   max="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><?php _e('customer'); ?></label>
                            <select class="form-select" id="customerId" onchange="onCustomerChange(this)">
                                <option value=""><?php _e('select_customer'); ?></option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><?php _e('customer_name'); ?> <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="printName" required
                                   placeholder="<?php _e('customer_name_placeholder'); ?>" maxlength="250">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><?php _e('location'); ?> <span class="text-danger">*</span></label>
                            <select class="form-select" id="locationId" required>
                                <option value=""><?php _e('select_location'); ?></option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label"><?php _e('payment_mode'); ?></label>
                            <div class="btn-group w-100" role="group" id="paymentModeGroup">
                                <input type="radio" class="btn-check" name="paymentMode" id="pmCash" value="cash" checked>
                                <label class="btn btn-outline-primary" for="pmCash"><?php _e('cash'); ?></label>

                                <input type="radio" class="btn-check" name="paymentMode" id="pmCard" value="card">
                                <label class="btn btn-outline-primary" for="pmCard"><?php _e('card'); ?></label>

                                <input type="radio" class="btn-check" name="paymentMode" id="pmCredit" value="credit">
                                <label class="btn btn-outline-primary" for="pmCredit"><?php _e('credit'); ?></label>
                            </div>
                        </div>
                        <div class="col-md-4" id="toAccountWrapper">
                            <label class="form-label">Money Account</label>
                            <select class="form-select" id="toAccountId">
                                <option value="">-- Select Account --</option>
                            </select>
                            <small class="text-muted" id="toAccountHint">Where the payment lands</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php _e('cost_centre'); ?></label>
                            <select class="form-select" id="costCentreId">
                                <option value=""><?php _e('select_cost_centre'); ?></option>
                            </select>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-3">
                            <label class="form-label"><?php _e('currency'); ?></label>
                            <select class="form-select" id="currencyId" onchange="onCurrencyChange()">
                            </select>
                        </div>
                        <div class="col-md-3" id="exchangeRateGroup" style="display:none;">
                            <label class="form-label"><?php _e('exchange_rate'); ?></label>
                            <input type="number" class="form-control" id="exchangeRateToBase" step="0.00000001" min="0.00000001" value="1">
                            <small class="text-muted" id="exchangeRateHint"></small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php _e('notes'); ?></label>
                            <textarea class="form-control" id="notes" rows="2"></textarea>
                        </div>
                    </div>

                    <hr>
                    
                    <h6 class="mb-3"><?php _e('sale_items'); ?> <span class="text-danger">*</span></h6>
                    
                    <div class="mb-3">
                        <div class="row g-2">
                            <div class="col-md-5">
                                <select class="form-select" id="productSelect">
                                    <option value=""><?php _e('select_product_to_add'); ?></option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <input type="number" class="form-control" id="itemQuantity" 
                                       placeholder="<?php _e('quantity'); ?>" min="0.001" step="0.001">
                            </div>
                            <div class="col-md-3">
                                <input type="number" class="form-control" id="itemRate" 
                                       placeholder="<?php _e('rate_per_unit'); ?>" min="0" step="0.01">
                            </div>
                            <div class="col-md-1">
                                <button type="button" class="btn btn-success w-100" onclick="addItem()">
                                    <i class="bi bi-plus"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered">
                            <thead class="table-light">
                                <tr>
                                    <th><?php _e('product'); ?></th>
                                    <th class="text-center"><?php _e('unit'); ?></th>
                                    <th class="text-end"><?php _e('quantity'); ?></th>
                                    <th class="text-end"><?php _e('rate'); ?></th>
                                    <th class="text-end"><?php _e('amount'); ?></th>
                                    <th class="text-center"><?php _e('action'); ?></th>
                                </tr>
                            </thead>
                            <tbody id="itemsTableBody">
                                <tr id="noItemsRow">
                                    <td colspan="6" class="text-center text-muted"><?php _e('no_items_added'); ?></td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr class="table-active">
                                    <th colspan="4" class="text-end"><?php _e('total_amount'); ?>:</th>
                                    <th class="text-end" id="modalTotalAmount">TZS 0.00</th>
                                    <th></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> <?php _e('cancel'); ?>
                </button>
                <button type="button" class="btn btn-primary" onclick="saveSale()" id="saveBtn">
                    <i class="bi bi-check-circle"></i> <?php _e('save_sale'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.badge-synced {
    background-color: #198754;
}
.badge-pending {
    background-color: #ffc107;
    color: #000;
}
.sale-row-pending {
    background-color: #fff3cd;
}
/* Inline editing styles */
.editable-cell {
    cursor: pointer;
    position: relative;
}
.editable-cell:hover {
    background-color: #f8f9fa;
}
.editable-cell input {
    width: 100%;
    border: 2px solid #0d6efd;
    padding: 4px 8px;
    text-align: right;
}
.edit-mode {
    background-color: #e7f3ff !important;
}
</style>

<script>
// Translations for JavaScript
const LANG = {
    add_new_sale: <?php echo json_encode(__('add_new_sale')); ?>,
    edit_sale: <?php echo json_encode(__('edit_sale')); ?>,
    synced: <?php echo json_encode(__('synced')); ?>,
    pending_sync: <?php echo json_encode(__('pending_sync')); ?>,
    no_items: <?php echo json_encode(__('no_items')); ?>,
    items: <?php echo json_encode(__('items')); ?>,
    sales: <?php echo json_encode(__('sales')); ?>,
    total: <?php echo json_encode(__('total')); ?>,
    edit: <?php echo json_encode(__('edit')); ?>,
    delete: <?php echo json_encode(__('delete')); ?>,
    view: <?php echo json_encode(__('view')); ?>,
    print: <?php echo json_encode(__('print')); ?>,
    confirm_delete_sale: <?php echo json_encode(__('confirm_delete_sale')); ?>,
    edit_only_online: <?php echo json_encode(__('edit_only_online')); ?>,
    delete_only_online: <?php echo json_encode(__('delete_only_online')); ?>,
    editing_only_online: <?php echo json_encode(__('editing_only_online')); ?>,
    sale_not_found: <?php echo json_encode(__('sale_not_found')); ?>,
    failed_save_sale: <?php echo json_encode(__('failed_save_sale')); ?>,
    failed_delete_sale: <?php echo json_encode(__('failed_delete_sale')); ?>,
    failed_load_data: <?php echo json_encode(__('failed_load_data')); ?>,
    saving: <?php echo json_encode(__('saving')); ?>,
    save_sale: <?php echo json_encode(__('save_sale')); ?>,
    sales_receipt: <?php echo json_encode(__('sales_receipt')); ?>,
    item: <?php echo json_encode(__('item')); ?>,
    qty: <?php echo json_encode(__('qty')); ?>,
    thank_you: <?php echo json_encode(__('thank_you')); ?>,
    cash: <?php echo json_encode(__('cash')); ?>,
    card: <?php echo json_encode(__('card')); ?>,
    credit: <?php echo json_encode(__('credit')); ?>
};

const isAdmin = true;

let viewModal;
let currentViewSaleId = null;
// Global variables
let sales = [];
let filteredSales = [];
let saleModal;
let customers = [];
let locations = [];
let products = [];
let productPricing = []; // Location-specific pricing
let costCentres = [];
let currencies = [];
let cashBankAccounts = []; // For the Money Account dropdown on cash/card sales
let defaultCurrencyId = null;
let selectedCurrencySymbol = 'TZS';
let itemsArray = [];
let isEditMode = false;
let editingSaleId = null;
let currentPaymentMode = 'cash';

// Default customer
const defaultCustomerId = <?php echo $defaultCustomerId ? $defaultCustomerId : 'null'; ?>;
const defaultCustomerName = '<?php echo addslashes($defaultCustomerName); ?>';

// Initialize
document.addEventListener('DOMContentLoaded', async function() {
    console.log('🎬 Sales management page loaded');
    
    // Initialize modals
    saleModal = new bootstrap.Modal(document.getElementById('saleModal'));
    viewModal = new bootstrap.Modal(document.getElementById('viewModal')); 
    
    // Initialize sync manager and offline DB
    await syncManager.init();
    
    // Set date filter (use stored dates or default to today)
    const today = new Date().toISOString().split('T')[0];
    const storedDateFrom = sessionStorage.getItem('salesFilterDateFrom');
    const storedDateTo = sessionStorage.getItem('salesFilterDateTo');
    document.getElementById('dateFrom').value = storedDateFrom || today;
    document.getElementById('dateTo').value = storedDateTo || today;
    
    // Load data
    await loadAllData();
    
    // Check if we should auto-open a sale view (from dashboard link)
    const urlParams = new URLSearchParams(window.location.search);
    const viewId = urlParams.get('view');
    if (viewId) {
        viewSale(viewId);
        // Clean URL without reload
        window.history.replaceState({}, document.title, window.location.pathname);
    }
    
    // Set default date
    document.getElementById('saleDate').valueAsDate = new Date();
    
    // Date filters - save to sessionStorage on change
    document.getElementById('dateFrom').addEventListener('change', function() {
        sessionStorage.setItem('salesFilterDateFrom', this.value);
        filterSales();
    });
    document.getElementById('dateTo').addEventListener('change', function() {
        sessionStorage.setItem('salesFilterDateTo', this.value);
        filterSales();
    });

    // Payment mode change listener — keep state + refresh the Money Account
    // dropdown (auto-pick default cash/bank, or hide on credit).
    document.querySelectorAll('input[name="paymentMode"]').forEach(radio => {
        radio.addEventListener('change', function() {
            currentPaymentMode = this.value;
            // Clear so applyPaymentModeToAccount re-applies the default.
            const sel = document.getElementById('toAccountId');
            if (sel) sel.value = '';
            applyPaymentModeToAccount();
        });
    });
});

// Load all required data
async function loadAllData() {
    console.log('📥 Loading all data...');
    document.getElementById('loadingSpinner').style.display = 'block';
    document.getElementById('salesContainer').style.display = 'none';
    document.getElementById('emptyState').style.display = 'none';
    
    try {
        // Load sales, customers, locations, products, pricing, and currencies
        await Promise.all([
            loadSales(),
            loadCustomers(),
            loadLocations(),
            loadProducts(),
            loadProductPricing(),
            loadCostCentres(),
            loadCurrencies(),
            loadAccounts()
        ]);
        
        console.log('✅ All data loaded');
        filterSales();
        
    } catch (error) {
        console.error('❌ Failed to load data:', error);
        showToast('Failed to load data: ' + error.message, 'danger');
    } finally {
        document.getElementById('loadingSpinner').style.display = 'none';
    }
}

// Load sales
async function loadSales() {
    console.log('📦 Loading sales...');
    sales = await syncManager.getSales();
    filteredSales = sales;
    console.log(`✅ Loaded ${sales.length} sales`);
}

// Load customers - with fallback to server if not in cache
async function loadCustomers() {
    console.log('👥 Loading customers...');
    
    // First try to get from IndexedDB
    customers = await offlineDB.getAll('customers');
    
    // If empty and we're online, fetch from server
    if (customers.length === 0 && syncManager.isOnline) {
        console.log('📡 No customers in cache, fetching from server...');
        try {
            const response = await fetch(BASE_URL + 'api/customers.php?_=' + Date.now(), {
                method: 'GET',
                headers: {
                    'Cache-Control': 'no-cache, no-store, must-revalidate',
                    'Pragma': 'no-cache'
                },
                cache: 'no-store'
            });
            
            if (response.ok) {
                const result = await response.json();
                if (result.success && result.customers) {
                    customers = result.customers;
                    // Cache them for future use
                    await offlineDB.replaceAll('customers', customers);
                    console.log(`✅ Fetched and cached ${customers.length} customers from server`);
                }
            }
        } catch (error) {
            console.error('❌ Failed to fetch customers from server:', error);
            showToast('Failed to load customers. Please try again.', 'warning');
        }
    }
    
    // Filter active customers
    customers = customers.filter(c => c.is_active == 1);
    
    // Populate customer dropdown — batch concat then ONE innerHTML write.
    // The += pattern is O(n²) because each assignment re-parses the entire
    // <select>; with thousands of customers/products it freezes the page.
    const customerSelect = document.getElementById('customerId');
    const customerOpts = ['<option value="">-- Select Customer --</option>'];
    customers.forEach(customer => {
        customerOpts.push(`<option value="${customer.id}">${escapeHtml(customer.name)}</option>`);
    });
    customerSelect.innerHTML = customerOpts.join('');

    console.log(`✅ Loaded ${customers.length} active customers`);
}

// Auto-fill customer name when customer is selected
function onCustomerChange(selectElement) {
    const selectedCustomer = customers.find(c => c.id == selectElement.value);
    if (selectedCustomer) {
        document.getElementById('printName').value = selectedCustomer.name;
    }
}

// Load locations
async function loadLocations() {
    console.log('📍 Loading locations...');
    locations = await syncManager.getLocations();
    
    // Populate location dropdown (same batch-concat pattern as customers).
    const locationSelect = document.getElementById('locationId');
    const locationOpts = ['<option value="">-- Select Location --</option>'];
    locations.forEach(location => {
        locationOpts.push(`<option value="${location.id}">${escapeHtml(location.name)}</option>`);
    });
    locationSelect.innerHTML = locationOpts.join('');
    
    // Auto-select if only one location
    if (locations.length === 1) {
        locationSelect.value = locations[0].id;
    }
    
    console.log(`✅ Loaded ${locations.length} locations`);
}

// Load cost centres
async function loadCostCentres() {
    console.log('🏢 Loading cost centres...');
    costCentres = await offlineDB.getAll('cost_centres') || [];

    // If empty and online, trigger sync
    if (costCentres.length === 0 && syncManager.isOnline) {
        try {
            await syncManager.fetchCostCentresFromServer();
            costCentres = await offlineDB.getAll('cost_centres') || [];
        } catch (error) {
            console.error('Failed to fetch cost centres:', error);
        }
    }

    // Filter active cost centres
    costCentres = costCentres.filter(cc => cc.is_active == 1);

    // Populate cost centre dropdown (same batch-concat pattern).
    const costCentreSelect = document.getElementById('costCentreId');
    const costCentreOpts = ['<option value=""><?php _e('select_cost_centre'); ?></option>'];
    costCentres.forEach(cc => {
        const label = cc.code ? `${escapeHtml(cc.code)} - ${escapeHtml(cc.name)}` : escapeHtml(cc.name);
        costCentreOpts.push(`<option value="${cc.id}">${label}</option>`);
    });
    costCentreSelect.innerHTML = costCentreOpts.join('');

    console.log(`✅ Loaded ${costCentres.length} cost centres`);
}

// Load cash + bank accounts (for the "Money Account" dropdown on cash/card sales).
// Hits api/accounts.php which already filters to account_type IN ('cash','bank').
async function loadAccounts() {
    console.log('💰 Loading cash/bank accounts...');
    cashBankAccounts = [];
    try {
        const response = await fetch(BASE_URL + 'api/accounts.php?_=' + Date.now(), {
            method: 'GET',
            headers: { 'Cache-Control': 'no-cache' },
            cache: 'no-store'
        });
        if (response.ok) {
            const result = await response.json();
            if (result.success && Array.isArray(result.accounts)) {
                cashBankAccounts = result.accounts;
            }
        }
    } catch (error) {
        console.error('Failed to load cash/bank accounts:', error);
    }

    // Populate the dropdown — batch concat pattern.
    const sel = document.getElementById('toAccountId');
    const opts = ['<option value="">-- Select Account --</option>'];
    cashBankAccounts.forEach(a => {
        opts.push(`<option value="${a.id}" data-type="${a.account_type}">${escapeHtml(a.name)} (${a.account_type})</option>`);
    });
    sel.innerHTML = opts.join('');

    // Apply the current payment-mode-aware behaviour now that the list is loaded.
    applyPaymentModeToAccount();

    console.log(`✅ Loaded ${cashBankAccounts.length} cash/bank accounts`);
}

// Show / hide / auto-select the "Money Account" dropdown based on payment mode.
//   cash   → show, auto-pick first cash account
//   card   → show, auto-pick first bank account (fallback to first cash)
//   credit → hide + clear value (no receivable settlement)
function applyPaymentModeToAccount() {
    const wrapper = document.getElementById('toAccountWrapper');
    const sel = document.getElementById('toAccountId');
    if (!wrapper || !sel) return;

    if (currentPaymentMode === 'credit') {
        wrapper.style.display = 'none';
        sel.value = '';
        return;
    }
    wrapper.style.display = '';

    // Respect an already-chosen value (e.g. edit mode hydrated it).
    if (sel.value) return;

    if (currentPaymentMode === 'cash') {
        const cash = cashBankAccounts.find(a => a.account_type === 'cash');
        if (cash) sel.value = cash.id;
    } else if (currentPaymentMode === 'card') {
        const bank = cashBankAccounts.find(a => a.account_type === 'bank')
                  || cashBankAccounts.find(a => a.account_type === 'cash');
        if (bank) sel.value = bank.id;
    }
}

// Load currencies
async function loadCurrencies() {
    console.log('Loading currencies...');
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

    // Populate currency dropdown
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
    console.log(`Loaded ${currencies.length} currencies, default: ${defaultCurrencyId}`);
}

// Handle currency change
function onCurrencyChange() {
    const select = document.getElementById('currencyId');
    const currId = parseInt(select.value);
    const opt = select.options[select.selectedIndex];
    selectedCurrencySymbol = opt ? opt.dataset.symbol : 'TZS';

    const exchangeRateGroup = document.getElementById('exchangeRateGroup');
    if (currId === defaultCurrencyId) {
        exchangeRateGroup.style.display = 'none';
        document.getElementById('exchangeRateToBase').value = '1';
    } else {
        exchangeRateGroup.style.display = '';
        fetchExchangeRate(currId);
    }
    // Refresh items table to show new currency symbol
    updateItemsTable();
}

// Fetch exchange rate from server
async function fetchExchangeRate(currencyId) {
    const saleDate = document.getElementById('saleDate').value || new Date().toISOString().split('T')[0];
    try {
        const url = `${BASE_URL}api/exchange-rates.php?from_currency_id=${currencyId}&to_currency_id=${defaultCurrencyId}&_=${Date.now()}`;
        const response = await fetch(url, {
            method: 'GET',
            headers: { 'Cache-Control': 'no-cache' },
            cache: 'no-store'
        });
        if (response.ok) {
            const result = await response.json();
            if (result.success && result.exchange_rates && result.exchange_rates.length > 0) {
                const applicable = result.exchange_rates.filter(r => r.effective_date <= saleDate);
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
            const revResponse = await fetch(revUrl, {
                method: 'GET',
                headers: { 'Cache-Control': 'no-cache' },
                cache: 'no-store'
            });
            if (revResponse.ok) {
                const revResult = await revResponse.json();
                if (revResult.success && revResult.exchange_rates && revResult.exchange_rates.length > 0) {
                    const applicable = revResult.exchange_rates.filter(r => r.effective_date <= saleDate);
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

// Load products
async function loadProducts() {
    console.log('📦 Loading products...');
    products = await syncManager.getProducts();
    
    // Populate product dropdown — THE critical one (you had 3104 products).
    // The old += pattern made this O(n²) and froze the main thread for tens
    // of seconds, blocking even console.log from echoing.
    const productSelect = document.getElementById('productSelect');
    const productOpts = ['<option value="">-- Select Product to Add --</option>'];
    products.forEach(product => {
        productOpts.push(`
            <option value="${product.id}" data-unit="${product.unit}" data-default-price="${product.default_selling_price || 0}">
                ${escapeHtml(product.name)} (${escapeHtml(product.category_name || 'No Category')})
            </option>
        `);
    });
    productSelect.innerHTML = productOpts.join('');
    
    // Auto-fill rate when product is selected
    productSelect.addEventListener('change', function() {
        if (!this.value) {
            document.getElementById('itemRate').value = '';
            return;
        }
        const price = getProductPrice(this.value);
        document.getElementById('itemRate').value = price;
    });
    
    // Update price when location changes
    document.getElementById('locationId').addEventListener('change', function() {
        const productSelect = document.getElementById('productSelect');
        if (productSelect.value) {
            const price = getProductPrice(productSelect.value);
            document.getElementById('itemRate').value = price;
        }
    });
    
    console.log(`✅ Loaded ${products.length} products`);
}

// Load product pricing (location-specific prices)
async function loadProductPricing() {
    console.log('💰 Loading product pricing...');
    
    // Try to get from IndexedDB first
    productPricing = await offlineDB.getAll('product_pricing') || [];
    
    // If empty and online, fetch from server
    if (productPricing.length === 0 && syncManager.isOnline) {
        console.log('📡 No pricing in cache, fetching from server...');
        try {
            const response = await fetch(BASE_URL + 'api/product-pricing.php?_=' + Date.now(), {
                method: 'GET',
                headers: {
                    'Cache-Control': 'no-cache, no-store, must-revalidate',
                    'Pragma': 'no-cache'
                },
                cache: 'no-store'
            });
            
            if (response.ok) {
                const result = await response.json();
                if (result.success && result.pricing) {
                    productPricing = result.pricing;
                    // Cache for offline use
                    await offlineDB.replaceAll('product_pricing', productPricing);
                    console.log(`✅ Fetched and cached ${productPricing.length} pricing records`);
                }
            }
        } catch (error) {
            console.error('❌ Failed to fetch product pricing:', error);
        }
    }
    
    console.log(`✅ Loaded ${productPricing.length} pricing records`);
}

// Check stock availability for items
async function checkStockAvailability(locationId, items, excludeSaleId = null) {
    try {
        const productIds = items.map(item => item.product_id);
        
        const response = await fetch('<?php echo BASE_URL; ?>api/stock-check.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                location_id: parseInt(locationId),
                product_ids: productIds,
                exclude_sale_id: excludeSaleId
            })
        });
        
        const result = await response.json();
        
        if (!result.success) {
            return { valid: false, errors: [result.error || 'Failed to check stock'] };
        }
        
        const errors = [];
        for (const item of items) {
            const stockInfo = result.stock[item.product_id];
            if (stockInfo) {
                const requestedQty = parseFloat(item.quantity);
                const availableQty = parseFloat(stockInfo.available_stock);
                
                if (requestedQty > availableQty) {
                    errors.push(`${item.product_name}: Requested ${requestedQty.toFixed(3)}, Available ${availableQty.toFixed(3)}`);
                }
            }
        }
        
        return { valid: errors.length === 0, errors: errors };
        
    } catch (error) {
        console.error('Stock check failed:', error);
        // If stock check fails, let the server-side validation handle it
        return { valid: true, errors: [] };
    }
}

// Get product price based on location (location price > default price)
function getProductPrice(productId) {
    const locationId = document.getElementById('locationId').value;
    
    // Look for location-specific price
    if (locationId) {
        const locationPrice = productPricing.find(
            pp => pp.product_id == productId && pp.location_id == locationId && pp.is_active == 1
        );
        if (locationPrice && locationPrice.selling_price) {
            console.log(`💰 Using location price for product ${productId}: ${locationPrice.selling_price}`);
            return parseFloat(locationPrice.selling_price);
        }
    }
    
    // Fallback to default price
    const product = products.find(p => p.id == productId);
    const defaultPrice = product ? parseFloat(product.default_selling_price || 0) : 0;
    console.log(`💰 Using default price for product ${productId}: ${defaultPrice}`);
    return defaultPrice;
}

// Display sales in table
function displaySales() {
    const tbody = document.getElementById('salesTableBody');
    const container = document.getElementById('salesContainer');
    const emptyState = document.getElementById('emptyState');
    
    if (filteredSales.length === 0) {
        container.style.display = 'none';
        emptyState.style.display = 'block';
        return;
    }
    
    container.style.display = 'block';
    emptyState.style.display = 'none';
    
    // Calculate totals
    const totalAmount = filteredSales.reduce((sum, s) => sum + parseFloat(s.total_amount || 0), 0);
    document.getElementById('saleCount').textContent = `${filteredSales.length} sale${filteredSales.length !== 1 ? 's' : ''}`;
    if (isAdmin) {
        document.getElementById('totalAmount').textContent = `Total: ${formatCurrency(totalAmount)}`;
    }
    
    tbody.innerHTML = filteredSales.map(sale => {
        const isSynced = sale.synced !== false && !sale.id.toString().startsWith('temp_');
        const rowClass = !isSynced ? 'sale-row-pending' : '';
        const statusBadge = isSynced
            ? '<span class="badge badge-synced"><i class="bi bi-check-circle"></i> ' + LANG.synced + '</span>'
            : '<span class="badge badge-pending"><i class="bi bi-clock"></i> ' + LANG.pending_sync + '</span>';

        const itemCount = sale.details ? sale.details.length : 0;

        // Payment mode badge
        const paymentMode = sale.payment_mode || 'cash';
        const paymentBadgeClass = paymentMode === 'cash' ? 'bg-success' : (paymentMode === 'card' ? 'bg-primary' : 'bg-warning text-dark');
        const paymentLabel = LANG[paymentMode] || paymentMode;
        const paymentBadge = `<span class="badge ${paymentBadgeClass}">${paymentLabel}</span>`;

        return `
            <tr class="${rowClass}">
                <td>
                    <strong>${escapeHtml(sale.sale_number)}</strong>
                </td>
                <td>${formatDate(sale.sale_date)}</td>
                <td>${escapeHtml(sale.customer_name || '')}</td>
                <td>${escapeHtml(sale.print_name || '')}</td>
                <td>${escapeHtml(sale.location_name || 'Unknown')}</td>
                <td>${sale.cost_centre_name ? escapeHtml(sale.cost_centre_code ? sale.cost_centre_code + ' - ' + sale.cost_centre_name : sale.cost_centre_name) : '<span class="text-muted">-</span>'}</td>
                <td class="text-center">
                    <span class="badge bg-info">${itemCount}</span>
                </td>
                ${isAdmin ? `
                <td class="text-end">
                    <strong>${formatCurrency(sale.total_amount)}</strong>
                </td>
                ` : ''}
                <td class="text-center">${paymentBadge}</td>
                <td class="text-center">${statusBadge}</td>
                <td class="text-center">
                    <button class="btn btn-sm btn-info" onclick="viewSale('${sale.id}')" title="View">
                        <i class="bi bi-eye"></i>
                    </button>
                    <button class="btn btn-sm btn-secondary" onclick="printSaleById('${sale.id}')" title="Print">
                        <i class="bi bi-printer"></i>
                    </button>
                    ${canEditSale(sale) && isSynced ? `
                        <button class="btn btn-sm btn-warning" onclick="editSale('${sale.id}')" 
                                title="Edit" id="editBtn_${sale.id}">
                            <i class="bi bi-pencil"></i>
                        </button>
                    ` : ''}
                    ${hasDeletePermission() && isSynced ? `
                        <button class="btn btn-sm btn-danger" onclick="deleteSale('${sale.id}')" 
                                title="Delete" id="deleteBtn_${sale.id}">
                            <i class="bi bi-trash"></i>
                        </button>
                    ` : ''}
                </td>
            </tr>
        `;
    }).join('');
}

// Filter sales
function filterSales() {
    const searchTerm = document.getElementById('searchInput').value.toLowerCase();
    const dateFrom = document.getElementById('dateFrom').value;
    const dateTo = document.getElementById('dateTo').value;
    const paymentModeFilter = document.getElementById('paymentModeFilter').value;

    filteredSales = sales.filter(sale => {
        // Search filter
        const matchesSearch = !searchTerm ||
            sale.sale_number.toLowerCase().includes(searchTerm) ||
            (sale.customer_name && sale.customer_name.toLowerCase().includes(searchTerm)) ||
            (sale.print_name && sale.print_name.toLowerCase().includes(searchTerm)) ||
            (sale.location_name && sale.location_name.toLowerCase().includes(searchTerm)) ||
            (sale.remarks && sale.remarks.toLowerCase().includes(searchTerm));

        // Date filters
        const saleDate = sale.sale_date;
        const matchesDateFrom = !dateFrom || saleDate >= dateFrom;
        const matchesDateTo = !dateTo || saleDate <= dateTo;

        // Payment mode filter
        const matchesPaymentMode = !paymentModeFilter || (sale.payment_mode || 'cash') === paymentModeFilter;

        return matchesSearch && matchesDateFrom && matchesDateTo && matchesPaymentMode;
    });

    displaySales();
}

// Clear filters
function clearFilters() {
    document.getElementById('searchInput').value = '';
    document.getElementById('dateFrom').value = '';
    document.getElementById('dateTo').value = '';
    document.getElementById('paymentModeFilter').value = '';
    sessionStorage.removeItem('salesFilterDateFrom');
    sessionStorage.removeItem('salesFilterDateTo');
    filterSales();
}

// Show create modal
function showCreateModal() {
    isEditMode = false;
    editingSaleId = null;

    document.getElementById('modalTitle').textContent = LANG.add_new_sale;
    document.getElementById('saleForm').reset();
    document.getElementById('saleId').value = '';
    document.getElementById('saleDate').valueAsDate = new Date();

    // Set default customer (Cash Customer)
    if (defaultCustomerId) {
        document.getElementById('customerId').value = defaultCustomerId;
    }

    // Auto-select if only one location
    if (locations.length === 1) {
        document.getElementById('locationId').value = locations[0].id;
    }

    // Reset payment mode to cash + auto-select default cash ledger.
    document.getElementById('pmCash').checked = true;
    currentPaymentMode = 'cash';
    const toAccountSelNew = document.getElementById('toAccountId');
    if (toAccountSelNew) toAccountSelNew.value = '';
    applyPaymentModeToAccount();

    // Reset cost centre
    document.getElementById('costCentreId').value = '';

    // Reset currency to default
    if (defaultCurrencyId) {
        document.getElementById('currencyId').value = defaultCurrencyId;
    }
    document.getElementById('exchangeRateToBase').value = '1';
    document.getElementById('exchangeRateGroup').style.display = 'none';
    const defCur = currencies.find(c => c.is_default == 1);
    selectedCurrencySymbol = defCur ? defCur.symbol : 'TZS';

    itemsArray = [];
    updateItemsTable();

    saleModal.show();
}

// Add item to sale
function addItem() {
    const productSelect = document.getElementById('productSelect');
    const productId = productSelect.value;
    const quantity = parseFloat(document.getElementById('itemQuantity').value);
    // For non-admin, rate is always 0
    const rateInput = document.getElementById('itemRate');
    const rate = isAdmin ? parseFloat(rateInput?.value || 0) : 0;
    
    if (!productId) {
        showToast('Please select a product', 'warning');
        return;
    }
    
    if (!quantity || quantity <= 0) {
        showToast('Please enter a valid quantity', 'warning');
        return;
    }
    
    if (isAdmin && rate < 0) {
        showToast('Rate cannot be negative', 'warning');
        return;
    }
    
    // Check if product already exists
    const existingIndex = itemsArray.findIndex(item => item.product_id == productId);
    if (existingIndex !== -1) {
        showToast('This product is already added. Use the edit icon to change quantity or rate.', 'warning');
        return;
    }
    
    // Get product details
    const product = products.find(p => p.id == productId);
    if (!product) {
        showToast('Product not found', 'danger');
        return;
    }
    
    // Add item
    itemsArray.push({
        product_id: productId,
        product_name: product.name,
        unit: product.unit,
        quantity: quantity,
        rate_per_unit: rate
    });
    
    // Clear inputs
    document.getElementById('productSelect').value = '';
    document.getElementById('itemQuantity').value = '';
    if (isAdmin) {
        document.getElementById('itemRate').value = '';
    }
    
    updateItemsTable();
}

// Remove item
function removeItem(index) {
    if (confirm('Remove this item?')) {
        itemsArray.splice(index, 1);
        updateItemsTable();
    }
}

// Edit item quantity inline
function editQuantity(index, cell) {
    const currentQty = itemsArray[index].quantity;
    const input = document.createElement('input');
    input.type = 'number';
    input.value = currentQty;
    input.step = '0.001';
    input.min = '0.001';
    input.className = 'form-control form-control-sm';
    
    // Replace cell content with input
    const originalContent = cell.innerHTML;
    cell.innerHTML = '';
    cell.appendChild(input);
    cell.classList.add('edit-mode');
    input.focus();
    input.select();
    
    // Save on blur or enter
    const saveEdit = () => {
        const newQty = parseFloat(input.value);
        if (newQty && newQty > 0) {
            itemsArray[index].quantity = newQty;
            updateItemsTable();
        } else {
            cell.innerHTML = originalContent;
            cell.classList.remove('edit-mode');
            showToast('Invalid quantity', 'warning');
        }
    };
    
    input.addEventListener('blur', saveEdit);
    input.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            saveEdit();
        } else if (e.key === 'Escape') {
            cell.innerHTML = originalContent;
            cell.classList.remove('edit-mode');
        }
    });
}

// Edit item rate inline
function editRate(index, cell) {
    const currentRate = itemsArray[index].rate_per_unit;
    const input = document.createElement('input');
    input.type = 'number';
    input.value = currentRate;
    input.step = '0.01';
    input.min = '0';
    input.className = 'form-control form-control-sm';
    
    // Replace cell content with input
    const originalContent = cell.innerHTML;
    cell.innerHTML = '';
    cell.appendChild(input);
    cell.classList.add('edit-mode');
    input.focus();
    input.select();
    
    // Save on blur or enter
    const saveEdit = () => {
        const newRate = parseFloat(input.value);
        if (newRate >= 0) {
            itemsArray[index].rate_per_unit = newRate;
            updateItemsTable();
        } else {
            cell.innerHTML = originalContent;
            cell.classList.remove('edit-mode');
            showToast('Invalid rate', 'warning');
        }
    };
    
    input.addEventListener('blur', saveEdit);
    input.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            saveEdit();
        } else if (e.key === 'Escape') {
            cell.innerHTML = originalContent;
            cell.classList.remove('edit-mode');
        }
    });
}

// Update items table
function updateItemsTable() {
    const tbody = document.getElementById('itemsTableBody');
    const colSpan = isAdmin ? 6 : 4;
    
    if (itemsArray.length === 0) {
        tbody.innerHTML = '<tr id="noItemsRow"><td colspan="' + colSpan + '" class="text-center text-muted">No items added yet</td></tr>';
        if (isAdmin) {
            document.getElementById('modalTotalAmount').textContent = 'TZS 0.00';
        }
        return;
    }
    
    let totalAmount = 0;
    
    tbody.innerHTML = itemsArray.map((item, index) => {
            const amount = item.quantity * item.rate_per_unit;
            totalAmount += amount;
            
            return `
                <tr>
                    <td>${escapeHtml(item.product_name)}</td>
                    <td class="text-center">${escapeHtml(item.unit)}</td>
                    <td class="text-end editable-cell" onclick="editQuantity(${index}, this)" title="Click to edit">
                        ${formatNumber(item.quantity, 3)}
                        <i class="bi bi-pencil-square ms-1 text-muted" style="font-size: 0.8rem;"></i>
                    </td>
                    ${isAdmin ? `
                    <td class="text-end editable-cell" onclick="editRate(${index}, this)" title="Click to edit">
                        ${formatCurrency(item.rate_per_unit)}
                        <i class="bi bi-pencil-square ms-1 text-muted" style="font-size: 0.8rem;"></i>
                    </td>
                    <td class="text-end">${formatCurrency(amount)}</td>
                    ` : ''}
                    <td class="text-center">
                        <button type="button" class="btn btn-sm btn-danger" onclick="removeItem(${index})" title="Remove">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                </tr>
            `;
        }).join('');
    
    if (isAdmin) {
        document.getElementById('modalTotalAmount').textContent = formatCurrency(totalAmount);
    }
}

// Save sale
async function saveSale() {
    // Validate form
    const saleDate = document.getElementById('saleDate').value;
    const customerId = document.getElementById('customerId').value || defaultCustomerId;
    const printName = document.getElementById('printName').value.trim();
    const locationId = document.getElementById('locationId').value;
    const notes = document.getElementById('notes').value;
    
    if (!saleDate) {
        showToast('Please select sale date', 'warning');
        return;
    }
    
    if (!printName) {
        showToast('Please enter customer name', 'warning');
        return;
    }
    
    if (!locationId) {
        showToast('Please select location', 'warning');
        return;
    }
    
    if (itemsArray.length === 0) {
        showToast('Please add at least one item', 'warning');
        return;
    }

    // Validate stock availability (only when online)
    if (syncManager.isOnline) {
        const stockCheckResult = await checkStockAvailability(locationId, itemsArray, isEditMode ? editingSaleId : null);
        if (!stockCheckResult.valid) {
            showToast('Insufficient stock: ' + stockCheckResult.errors.join('; '), 'danger');
            return;
        }
    }
    
    // Prepare data
    const costCentreId = document.getElementById('costCentreId').value;
    const currencyId = parseInt(document.getElementById('currencyId').value) || defaultCurrencyId;
    const exchangeRateToBase = parseFloat(document.getElementById('exchangeRateToBase').value) || 1;
    const data = {
        sale_date: saleDate,
        customer_id: parseInt(customerId),
        print_name: printName,
        location_id: parseInt(locationId),
        cost_centre_id: costCentreId ? parseInt(costCentreId) : null,
        currency_id: currencyId,
        exchange_rate_to_base: exchangeRateToBase,
        notes: notes,
        payment_mode: currentPaymentMode,
        // Money account: required for cash/card so the server auto-creates a
        // receipt voucher and posts DR Cash / CR Customer. Null for credit.
        to_account_id: (currentPaymentMode !== 'credit' && document.getElementById('toAccountId').value)
            ? parseInt(document.getElementById('toAccountId').value)
            : null,
        details: itemsArray
    };
    
    // Disable save button
    const saveBtn = document.getElementById('saveBtn');
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>' + LANG.saving;
    
    try {
        if (isEditMode && editingSaleId) {
            // Update sale (online only)
            data.id = editingSaleId;
            await syncManager.updateSale(data);
        } else {
            // Create new sale (works offline)
            await syncManager.createSale(data);
        }
        
        // Reload sales
        await loadSales();
        filterSales();
        
        // Close modal
        saleModal.hide();
        
    } catch (error) {
        console.error('❌ Save failed:', error);
        showToast(LANG.failed_save_sale + ': ' + error.message, 'danger');
    } finally {
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<i class="bi bi-check-circle"></i> ' + LANG.save_sale;
    }
}

// View sale in modal
function viewSale(id) {
    const sale = sales.find(s => s.id == id);
    if (!sale) {
        showToast(LANG.sale_not_found, 'danger');
        return;
    }
    
    currentViewSaleId = id;
    
    // Populate view modal
    document.getElementById('viewSaleNumber').textContent = sale.sale_number;
    document.getElementById('viewDate').textContent = formatDate(sale.sale_date);
    document.getElementById('viewCustomer').textContent = sale.print_name || '';
    document.getElementById('viewLocation').textContent = sale.location_name || 'Unknown';

    // Cost centre
    const costCentreCol = document.getElementById('viewCostCentreCol');
    if (sale.cost_centre_name) {
        const ccLabel = sale.cost_centre_code ? `${sale.cost_centre_code} - ${sale.cost_centre_name}` : sale.cost_centre_name;
        document.getElementById('viewCostCentre').textContent = ccLabel;
        costCentreCol.style.display = '';
    } else {
        costCentreCol.style.display = 'none';
    }
    
    // Status badge
    const isSynced = sale.synced !== false && !sale.id.toString().startsWith('temp_');
    const statusBadge = isSynced 
        ? '<span class="badge badge-synced"><i class="bi bi-check-circle"></i> ' + LANG.synced + '</span>'
        : '<span class="badge badge-pending"><i class="bi bi-clock"></i> ' + LANG.pending_sync + '</span>';
    document.getElementById('viewStatus').innerHTML = statusBadge;
    
    // Notes
    if (sale.remarks && sale.remarks.trim()) {
        document.getElementById('viewNotesRow').style.display = '';
        document.getElementById('viewNotes').textContent = sale.remarks;
    } else {
        document.getElementById('viewNotesRow').style.display = 'none';
    }
    
    // Items table
    const itemsBody = document.getElementById('viewItemsBody');
    if (sale.details && sale.details.length > 0) {
        itemsBody.innerHTML = sale.details.map((item, index) => {
            const amount = parseFloat(item.quantity) * parseFloat(item.rate_per_unit);
            return `
                <tr>
                    <td class="text-center">${index + 1}</td>
                    <td>${escapeHtml(item.product_name)}</td>
                    <td class="text-center">${escapeHtml(item.unit || '')}</td>
                    <td class="text-end">${formatNumber(item.quantity, 3)}</td>
                    ${isAdmin ? `
                    <td class="text-end">${formatCurrency(item.rate_per_unit)}</td>
                    <td class="text-end">${formatCurrency(amount)}</td>
                    ` : ''}
                </tr>
            `;
        }).join('');
    } else {
        itemsBody.innerHTML = '<tr><td colspan="' + (isAdmin ? '6' : '4') + '" class="text-center text-muted">No items</td></tr>';
    }
    
    if (isAdmin) {
        document.getElementById('viewTotalAmount').textContent = formatCurrency(sale.total_amount);
    }
    
    // Created by
    document.getElementById('viewCreatedBy').textContent = sale.created_by_name || 'Unknown';
    document.getElementById('viewCreatedAt').textContent = formatDateTime(sale.created_at);
    
    // Edit button - only show for synced sales and if user can edit
    const editBtn = document.getElementById('viewEditBtn');
    if (editBtn) {
        if (isSynced && canEditSale(sale)) {
            editBtn.style.display = '';
            editBtn.disabled = !syncManager.isOnline;
            if (!syncManager.isOnline) {
                editBtn.title = 'Edit is only available when online';
            }
        } else {
            editBtn.style.display = 'none';
        }
    }
    
    // Show modal
    viewModal.show();
}

// Edit sale (online only)
async function editSale(id) {
    if (!syncManager.isOnline) {
        showToast(LANG.editing_only_online, 'warning');
        return;
    }
    
    const sale = sales.find(s => s.id == id);
    if (!sale) {
        showToast(LANG.sale_not_found, 'danger');
        return;
    }
    
    console.log('Editing sale:', sale);
    console.log('Remarks value:', sale.remarks);
    
    isEditMode = true;
    editingSaleId = id;
    
    document.getElementById('modalTitle').textContent = LANG.edit_sale;
    document.getElementById('saleId').value = id;
    document.getElementById('saleDate').value = sale.sale_date;
    document.getElementById('customerId').value = sale.customer_id;
    document.getElementById('printName').value = sale.print_name || '';
    document.getElementById('locationId').value = sale.location_id;
    document.getElementById('costCentreId').value = sale.cost_centre_id || '';
    document.getElementById('notes').value = sale.remarks || '';

    // Set currency
    const saleCurrencyId = sale.currency_id || defaultCurrencyId;
    document.getElementById('currencyId').value = saleCurrencyId;
    const curOpt = document.getElementById('currencyId').options[document.getElementById('currencyId').selectedIndex];
    selectedCurrencySymbol = curOpt ? curOpt.dataset.symbol : 'TZS';
    if (parseInt(saleCurrencyId) !== defaultCurrencyId) {
        document.getElementById('exchangeRateGroup').style.display = '';
        document.getElementById('exchangeRateToBase').value = sale.exchange_rate_to_base || '1';
    } else {
        document.getElementById('exchangeRateGroup').style.display = 'none';
        document.getElementById('exchangeRateToBase').value = '1';
    }

    // Set payment mode
    const paymentMode = sale.payment_mode || 'cash';
    currentPaymentMode = paymentMode;
    document.getElementById('pm' + paymentMode.charAt(0).toUpperCase() + paymentMode.slice(1)).checked = true;

    // Rehydrate Money Account: pre-fill the saved to_account_id BEFORE the
    // mode handler runs, so it doesn't overwrite with the default account.
    const toAccountSel = document.getElementById('toAccountId');
    if (toAccountSel) {
        toAccountSel.value = sale.to_account_id || '';
    }
    applyPaymentModeToAccount();

    // Load items
    itemsArray = sale.details ? sale.details.map(detail => ({
        product_id: detail.product_id,
        product_name: detail.product_name,
        unit: detail.unit,
        quantity: parseFloat(detail.quantity),
        rate_per_unit: parseFloat(detail.rate_per_unit)
    })) : [];
    
    updateItemsTable();
    saleModal.show();
}

// Delete sale (online only)
async function deleteSale(id) {
    if (!syncManager.isOnline) {
        showToast(LANG.delete_only_online, 'warning');
        return;
    }
    
    if (!confirm(LANG.confirm_delete_sale)) {
        return;
    }
    
    try {
        await syncManager.deleteSale(id);
        await loadSales();
        filterSales();
    } catch (error) {
        console.error('❌ Delete failed:', error);
        showToast(LANG.failed_delete_sale + ': ' + error.message, 'danger');
    }
}

// Edit from view modal
function editFromView() {
    viewModal.hide();
    setTimeout(() => {
        editSale(currentViewSaleId);
    }, 300);
}

// Permission checks
const currentUserId = <?php echo json_encode($currentUserId); ?>;

function hasEditPermission() {
    return <?php echo hasPermission('sales', 'edit') ? 'true' : 'false'; ?>;
}

// Check if user can edit a specific sale
function canEditSale(sale) {
    return hasEditPermission();
}

function hasDeletePermission() {
    return <?php echo hasPermission('sales', 'delete') ? 'true' : 'false'; ?>;
}

// Utility functions
function formatCurrency(amount, symbol) {
    const s = symbol || selectedCurrencySymbol || 'TZS';
    const n = parseFloat(amount);
    if (isNaN(n)) return s + ' 0.00';
    return s + ' ' + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
}

function formatNumber(num, decimals = 2) {
    const n = parseFloat(num);
    if (isNaN(n)) return '0.00';
    return n.toFixed(decimals).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
}

function formatDate(dateString) {
    if (!dateString) return '';
    const date = new Date(dateString);
    const day = String(date.getDate()).padStart(2, '0');
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const year = date.getFullYear();
    return `${day}-${month}-${year}`;
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function showToast(message, type = 'info') {
    document.querySelectorAll('.sale-toast').forEach(t => t.remove());
    
    const toast = document.createElement('div');
    toast.className = `sale-toast alert alert-${type} alert-dismissible fade show`;
    toast.style.cssText = `
        position: fixed;
        top: 80px;
        right: 20px;
        z-index: 9999;
        min-width: 300px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.2);
    `;
    toast.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 150);
    }, 5000);
}

function formatDateTime(dateTimeString) {
    if (!dateTimeString) return '';
    const date = new Date(dateTimeString);
    const day = String(date.getDate()).padStart(2, '0');
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const year = date.getFullYear();
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    return `${day}-${month}-${year} ${hours}:${minutes}`;
}

function printSaleById(id) {
    const sale = sales.find(s => s.id == id);
    if (!sale) {
        showToast(LANG.sale_not_found, 'danger');
        return;
    }
    printSaleReceipt(sale);
}

function printSale() {
    const sale = sales.find(s => s.id == currentViewSaleId);
    if (!sale) {
        showToast(LANG.sale_not_found, 'danger');
        return;
    }
    printSaleReceipt(sale);
}

function printSaleReceipt(sale) {
    const appName = <?php echo json_encode(APP_NAME); ?>;

    // This receipt is handed to the customer, so it should identify the company
    // rather than the software. Banner if one is uploaded (Masters -> Company
    // Settings), otherwise the company name/address/phone text; APP_NAME stays
    // as the last resort for a tenant with no branding configured at all.
    const companyLetterhead = <?php echo json_encode(getPrintLetterheadHtml()); ?>;

    let itemsHtml = '';
    
    if (sale.details && sale.details.length > 0) {
        sale.details.forEach((item, index) => {
            itemsHtml += `
                <tr>
                    <td>${escapeHtml(item.product_name)}</td>
                    <td class="text-right">${formatNumber(item.quantity, 3)} ${escapeHtml(item.unit || '')}</td>
                </tr>
            `;
        });
    }
    
    const printContent = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Receipt - ${escapeHtml(sale.sale_number)}</title>
            <style>
                @media print {
                    @page {
                        size: 80mm auto;
                        margin: 0;
                    }
                    body { margin: 0; padding: 2mm; }
                }
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }
                body {
                    font-family: 'Courier New', monospace;
                    font-size: 11px;
                    line-height: 1.3;
                    color: #000;
                    width: 80mm;
                    max-width: 80mm;
                }
                .header {
                    text-align: center;
                    border-bottom: 1px dashed #000;
                    padding-bottom: 5px;
                    margin-bottom: 5px;
                }
                .header h1 {
                    font-size: 14px;
                    font-weight: bold;
                    margin-bottom: 3px;
                }
                .header .subtitle {
                    font-size: 10px;
                }
                .info {
                    border-bottom: 1px dashed #000;
                    padding-bottom: 5px;
                    margin-bottom: 5px;
                }
                .info-line {
                    display: flex;
                    justify-content: space-between;
                    font-size: 10px;
                }
                table {
                    width: 100%;
                    border-collapse: collapse;
                }
                th, td {
                    padding: 2px 0;
                    text-align: left;
                    font-size: 10px;
                }
                th {
                    border-bottom: 1px solid #000;
                    font-weight: bold;
                }
                .text-right {
                    text-align: right;
                }
                .divider {
                    border-top: 1px dashed #000;
                    margin: 5px 0;
                }
                .footer {
                    text-align: center;
                    font-size: 9px;
                    margin-top: 8px;
                }
                <?php echo getPrintLetterheadCss('26mm'); ?>
                /* .header already draws the dashed rule and the roll is only
                   ~72mm of printable width, so the letterhead sheds its own
                   border and drops to receipt-sized type. */
                .header .app-lh { border-bottom: 0; padding-bottom: 0; margin-bottom: 3px; }
                .header .app-lh-name { font-size: 14px; }
                .header .app-lh-line { font-size: 9px; }
            </style>
        </head>
        <body>
            <div class="header">
                ${companyLetterhead || `<h1>${escapeHtml(appName)}</h1>`}
                <div class="subtitle">SALES RECEIPT</div>
            </div>
            
            <div class="info">
                <div class="info-line">
                    <span>No: ${escapeHtml(sale.sale_number)}</span>
                    <span>${formatDate(sale.sale_date)}</span>
                </div>
                <div class="info-line">
                    <span>${escapeHtml(sale.print_name || 'Cash')}</span>
                    <span>${escapeHtml(sale.location_name || '')}</span>
                </div>
                ${sale.remarks ? `<div style="font-size:9px;">${escapeHtml(sale.remarks)}</div>` : ''}
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th class="text-right">Qty</th>
                    </tr>
                </thead>
                <tbody>
                    ${itemsHtml}
                </tbody>
            </table>
            
            <div class="divider"></div>
            
            <div class="footer">
                <div>${formatDateTime(new Date().toISOString())}</div>
                <div>Thank you!</div>
                <div style="margin-top:8px; font-size:8px; color:#888;"><?php echo __('developed_by'); ?> Fabonic &middot; www.fabonic.co.tz</div>
            </div>
        </body>
        </html>
    `;
    
    const printWindow = window.open('', '_blank', 'width=300,height=400');
    printWindow.document.write(printContent);
    printWindow.document.close();
    printWindow.focus();
    
    setTimeout(() => {
        printWindow.print();
    }, 250);
}
</script>

<?php require_once '../../includes/footer.php'; ?>
