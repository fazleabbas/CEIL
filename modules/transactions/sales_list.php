<?php
/**
 * Sales Management - With Offline Support
 * Users can view sales offline
 * Create via sales.php, Delete only when online
 * Edit button opens view modal (view-only for now)
 */
$pageTitle = 'Sales Management';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

if (!hasPermission('sales', 'view')) {
    setAlert('You do not have permission to access this page', 'danger');
    redirect('');
}

require_once '../../includes/header.php';
?>

<!-- Online Status Indicator -->
<div id="onlineStatus" class="badge bg-secondary position-fixed" style="top: 10px; right: 10px; z-index: 1050;">
    <i class="bi bi-wifi-off"></i> Checking...
</div>

<div class="row mb-3">
    <div class="col-md-6">
        <h2><i class="bi bi-receipt"></i> Sales Management</h2>
    </div>
    <div class="col-md-6 text-end">
        <?php if (hasPermission('sales', 'create')): ?>
        <a href="sales.php" class="btn btn-primary" id="addSaleBtn">
            <i class="bi bi-plus-circle"></i> New Sale
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Search and Filter -->
<div class="card mb-3">
    <div class="card-body">
        <div class="row g-2">
            <div class="col-md-3">
                <input type="date" id="dateFrom" class="form-control" placeholder="From Date">
            </div>
            <div class="col-md-3">
                <input type="date" id="dateTo" class="form-control" placeholder="To Date">
            </div>
            <div class="col-md-4">
                <input type="text" id="searchInput" class="form-control" 
                       placeholder="Search by number, customer..." onkeyup="filterSales()">
            </div>
            <div class="col-md-2">
                <button onclick="clearFilters()" class="btn btn-secondary w-100">
                    <i class="bi bi-x-circle"></i> Clear
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
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-2">Loading sales...</p>
        </div>
        
        <div id="salesContainer" style="display:none;">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <span id="saleCount" class="text-muted">0 sales</span>
                <span id="totalAmount" class="fw-bold text-primary text-end">Total: <?php echo htmlspecialchars($defaultCurrencySymbol); ?> 0.00</span>
            </div>
            
            <div class="table-responsive">
                <table class="table table-hover table-striped">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 120px;">Number</th>
                            <th style="width: 110px;">Date</th>
                            <th>Customer</th>
                            <th>Location</th>
                            <th style="width: 80px;" class="text-center">Items</th>
                            <th style="width: 140px;" class="text-end">Amount</th>
                            <th style="width: 100px;" class="text-center">Payment</th>
                            <th style="width: 100px;" class="text-center">Status</th>
                            <th style="width: 150px;" class="text-center no-export">Actions</th>
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
            <p class="text-muted mt-3">No sales found</p>
            <?php if (hasPermission('sales', 'create')): ?>
            <a href="sales.php" class="btn btn-primary mt-2">
                <i class="bi bi-plus-circle"></i> Create First Sale
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- View Sale Modal -->
<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">
                    <i class="bi bi-receipt"></i> Sale Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Sale Number:</strong><br>
                        <span id="viewSaleNumber" class="fs-5 text-primary"></span>
                    </div>
                    <div class="col-md-6 text-md-end">
                        <strong>Status:</strong><br>
                        <span id="viewStatus"></span>
                    </div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-3">
                        <strong><i class="bi bi-calendar"></i> Date:</strong><br>
                        <span id="viewDate"></span>
                    </div>
                    <div class="col-md-3">
                        <strong><i class="bi bi-person"></i> Customer:</strong><br>
                        <span id="viewCustomer"></span>
                    </div>
                    <div class="col-md-3">
                        <strong><i class="bi bi-geo-alt"></i> Location:</strong><br>
                        <span id="viewLocation"></span>
                    </div>
                    <div class="col-md-3">
                        <strong><i class="bi bi-credit-card"></i> Payment:</strong><br>
                        <span id="viewPayment"></span>
                    </div>
                </div>
                
                <div class="row mb-3" id="viewRemarksRow" style="display:none;">
                    <div class="col-12">
                        <strong><i class="bi bi-sticky"></i> Remarks:</strong><br>
                        <span id="viewRemarks" class="text-muted"></span>
                    </div>
                </div>
                
                <hr>
                
                <h6 class="mb-3"><i class="bi bi-cart"></i> Sale Items</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 5%;">#</th>
                                <th style="width: 40%;">Product</th>
                                <th style="width: 10%;" class="text-center">Unit</th>
                                <th style="width: 15%;" class="text-end">Quantity</th>
                                <th style="width: 15%;" class="text-end">Rate</th>
                                <th style="width: 15%;" class="text-end">Amount</th>
                            </tr>
                        </thead>
                        <tbody id="viewItemsBody">
                        </tbody>
                        <tfoot>
                            <tr class="table-active fw-bold">
                                <td colspan="5" class="text-end">Total Amount:</td>
                                <td class="text-end" id="viewTotalAmount"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                
                <div class="row mt-3">
                    <div class="col-md-6">
                        <small class="text-muted">
                            <i class="bi bi-person-badge"></i> Created by: <span id="viewCreatedBy"></span>
                        </small>
                    </div>
                    <div class="col-md-6 text-md-end">
                        <small class="text-muted">
                            <i class="bi bi-clock"></i> Created: <span id="viewCreatedAt"></span>
                        </small>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> Close
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
.payment-badge {
    font-size: 0.85rem;
}
</style>

<script>
// Global variables
let sales = [];
let filteredSales = [];
let viewModal;
let customers = [];
let locations = [];

// Initialize
document.addEventListener('DOMContentLoaded', async function() {
    console.log('🎬 Sales page loaded');
    
    // Initialize modal
    viewModal = new bootstrap.Modal(document.getElementById('viewModal'));
    
    // Initialize sync manager and offline DB
    await syncManager.init();
    
    // Load data
    await loadAllData();
    
    // Date filters
    document.getElementById('dateFrom').addEventListener('change', filterSales);
    document.getElementById('dateTo').addEventListener('change', filterSales);
});

// Load all required data
async function loadAllData() {
    console.log('📥 Loading all data...');
    document.getElementById('loadingSpinner').style.display = 'block';
    document.getElementById('salesContainer').style.display = 'none';
    document.getElementById('emptyState').style.display = 'none';
    
    try {
        // Load sales, customers, locations
        await Promise.all([
            loadSales(),
            loadCustomers(),
            loadLocations()
        ]);
        
        console.log('✅ All data loaded');
        displaySales();
        
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

// Load customers (from cache)
async function loadCustomers() {
    console.log('👥 Loading customers...');
    customers = await offlineDB.getAll('customers');
    console.log(`✅ Loaded ${customers.length} customers`);
}

// Load locations (from cache)
async function loadLocations() {
    console.log('📍 Loading locations...');
    locations = await syncManager.getLocations();
    console.log(`✅ Loaded ${locations.length} locations`);
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
    
    // Calculate totals.
    //
    // Grouped by currency: a plain sum over a filtered set that mixes currencies
    // adds USD to TZS and then labels the result with the default symbol. Each
    // row is converted with its OWN exchange_rate_to_base and the base-currency
    // figure is what the header shows.
    // For an all-base-currency set every rate is 1, so this produces the
    // identical number the old reduce() did — nothing changes on a
    // single-currency tenant.
    let totalAmount = 0;
    const byCurrency = new Map();   // currency_id -> { symbol, decimals, sum }

    filteredSales.forEach(s => {
        const amount = parseFloat(s.total_amount || 0);
        const rate = parseFloat(s.exchange_rate_to_base) || 1;
        totalAmount += amount * rate;

        const key = s.currency_id != null ? String(s.currency_id) : 'default';
        if (!byCurrency.has(key)) {
            byCurrency.set(key, { symbol: rowCurrencySymbol(s), decimals: rowCurrencyDecimals(s), sum: 0 });
        }
        byCurrency.get(key).sum += amount;
    });

    const hasForeign = filteredSales.some(rowIsForeignCurrency);

    document.getElementById('saleCount').textContent = `${filteredSales.length} sale${filteredSales.length !== 1 ? 's' : ''}`;

    const baseTotal = formatCurrency(totalAmount, window.DEFAULT_CURRENCY_DECIMALS, window.DEFAULT_CURRENCY_SYMBOL);
    const totalEl = document.getElementById('totalAmount');

    if (hasForeign) {
        // Say so rather than presenting a converted figure as if it were native.
        const parts = [...byCurrency.values()]
            .map(c => escapeHtml(formatCurrency(c.sum, c.decimals, c.symbol)))
            .join(' &middot; ');
        totalEl.innerHTML = `Total: ${escapeHtml(baseTotal)}`
            + `<br><small class="text-muted fw-normal">`
            + `${escapeHtml(CONVERTED_TO_BASE)}: ${parts}</small>`;
    } else {
        totalEl.textContent = `Total: ${baseTotal}`;
    }
    
    tbody.innerHTML = filteredSales.map(sale => {
        const isSynced = sale.synced !== false && !sale.id.toString().startsWith('TEMP_');
        const rowClass = !isSynced ? 'sale-row-pending' : '';
        const statusBadge = isSynced 
            ? '<span class="badge badge-synced"><i class="bi bi-check-circle"></i> Synced</span>'
            : '<span class="badge badge-pending"><i class="bi bi-clock"></i> Pending</span>';
        
        const itemCount = sale.details ? sale.details.length : 0;
        
        // Payment mode badge
        const paymentMode = (sale.payment_mode || 'cash').toUpperCase();
        const paymentBadgeColor = paymentMode === 'CASH' ? 'success' : 
                                  paymentMode === 'CARD' ? 'primary' : 
                                  paymentMode === 'UPI' ? 'info' : 'secondary';
        
        return `
            <tr class="${rowClass}">
                <td>
                    <strong>${escapeHtml(sale.sale_number)}</strong>
                </td>
                <td>${formatDate(sale.sale_date)}</td>
                <td>${escapeHtml(sale.customer_name || 'Unknown')}</td>
                <td>${escapeHtml(sale.location_name || 'Unknown')}</td>
                <td class="text-center">
                    <span class="badge bg-info">${itemCount}</span>
                </td>
                <td class="text-end"${rowFxTitle(sale, sale.total_amount) ? ` title="${escapeHtml(rowFxTitle(sale, sale.total_amount))}"` : ''}>
                    <strong>${formatRowCurrency(sale, sale.total_amount)}</strong>
                </td>
                <td class="text-center">
                    <span class="badge bg-${paymentBadgeColor} payment-badge">${paymentMode}</span>
                </td>
                <td class="text-center">${statusBadge}</td>
                <td class="text-center">
                    <button class="btn btn-sm btn-info" onclick="viewSale('${sale.id}')" title="View">
                        <i class="bi bi-eye"></i>
                    </button>
                    ${hasEditPermission() && isSynced && syncManager.isOnline ? `
                        <button class="btn btn-sm btn-warning" onclick="viewSale('${sale.id}')" 
                                title="View Details (Edit not available yet)" id="editBtn_${sale.id}">
                            <i class="bi bi-pencil"></i>
                        </button>
                    ` : ''}
                    ${hasDeletePermission() && isSynced && syncManager.isOnline ? `
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
    
    filteredSales = sales.filter(sale => {
        // Search filter
        const matchesSearch = !searchTerm || 
            sale.sale_number.toLowerCase().includes(searchTerm) ||
            (sale.customer_name && sale.customer_name.toLowerCase().includes(searchTerm)) ||
            (sale.location_name && sale.location_name.toLowerCase().includes(searchTerm));
        
        // Date filters
        const saleDate = sale.sale_date;
        const matchesDateFrom = !dateFrom || saleDate >= dateFrom;
        const matchesDateTo = !dateTo || saleDate <= dateTo;
        
        return matchesSearch && matchesDateFrom && matchesDateTo;
    });
    
    displaySales();
}

// Clear filters
function clearFilters() {
    document.getElementById('searchInput').value = '';
    document.getElementById('dateFrom').value = '';
    document.getElementById('dateTo').value = '';
    filterSales();
}

// View sale in modal
function viewSale(id) {
    const sale = sales.find(s => s.id == id);
    if (!sale) {
        showToast('Sale not found', 'danger');
        return;
    }
    
    // Populate view modal
    document.getElementById('viewSaleNumber').textContent = sale.sale_number;
    document.getElementById('viewDate').textContent = formatDate(sale.sale_date);
    document.getElementById('viewCustomer').textContent = sale.customer_name || 'Unknown';
    document.getElementById('viewLocation').textContent = sale.location_name || 'Unknown';
    document.getElementById('viewPayment').textContent = (sale.payment_mode || 'cash').toUpperCase();
    
    // Status badge
    const isSynced = sale.synced !== false && !sale.id.toString().startsWith('TEMP_');
    const statusBadge = isSynced 
        ? '<span class="badge badge-synced"><i class="bi bi-check-circle"></i> Synced</span>'
        : '<span class="badge badge-pending"><i class="bi bi-clock"></i> Pending Sync</span>';
    document.getElementById('viewStatus').innerHTML = statusBadge;
    
    // Remarks
    if (sale.remarks && sale.remarks.trim()) {
        document.getElementById('viewRemarksRow').style.display = '';
        document.getElementById('viewRemarks').textContent = sale.remarks;
    } else {
        document.getElementById('viewRemarksRow').style.display = 'none';
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
                    <td class="text-end">${formatRowCurrency(sale, item.rate_per_unit)}</td>
                    <td class="text-end">${formatRowCurrency(sale, amount)}</td>
                </tr>
            `;
        }).join('');
    } else {
        itemsBody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No items</td></tr>';
    }
    
    // Same currency as the row it was opened from — the modal used to print the
    // tenant default here too.
    document.getElementById('viewTotalAmount').textContent = formatRowCurrency(sale, sale.total_amount);
    
    // Created by
    document.getElementById('viewCreatedBy').textContent = sale.created_by_name || 'Unknown';
    document.getElementById('viewCreatedAt').textContent = formatDateTime(sale.created_at);
    
    // Show modal
    viewModal.show();
}

// Delete sale (online only)
async function deleteSale(id) {
    if (!syncManager.isOnline) {
        showToast('Deleting sales is only available when online', 'warning');
        return;
    }
    
    if (!confirm('Are you sure you want to delete this sale? This action cannot be undone.')) {
        return;
    }
    
    try {
        await syncManager.deleteSale(id);
        await loadSales();
        displaySales();
    } catch (error) {
        console.error('❌ Delete failed:', error);
        showToast('Failed to delete sale: ' + error.message, 'danger');
    }
}

// Permission checks
function hasEditPermission() {
    return <?php echo hasPermission('sales', 'edit') ? 'true' : 'false'; ?>;
}

function hasDeletePermission() {
    return <?php echo hasPermission('sales', 'delete') ? 'true' : 'false'; ?>;
}

const CONVERTED_TO_BASE = <?php echo json_encode(__('converted_to_base')); ?>;

// Utility functions
//
// NOTE: there used to be a local formatCurrency() here that hard-coded
// window.DEFAULT_CURRENCY_SYMBOL. It was already DEAD CODE — includes/footer.php
// loads assets/js/main.js AFTER this inline block, and main.js declares a global
// formatCurrency(amount, decimals, symbol) that overwrote it. It was removed
// rather than fixed, so there is one implementation to reason about. main.js
// already accepts a symbol override; the call sites here now pass one.

/**
 * The symbol to print for a row, from the currency joined in api/sales.php.
 *
 * Falls back to the tenant default — which is exactly the old behaviour — for
 * rows cached in IndexedDB BEFORE that join was added, and for offline rows
 * created locally that have no currency joined yet. Those correct themselves on
 * the next sync; until then they render as they always did rather than blank.
 */
function rowCurrencySymbol(row) {
    return (row && row.currency_symbol) || window.DEFAULT_CURRENCY_SYMBOL || 'TZS';
}

function rowCurrencyDecimals(row) {
    if (row && row.currency_decimals !== undefined && row.currency_decimals !== null) {
        return parseInt(row.currency_decimals, 10);
    }
    return window.DEFAULT_CURRENCY_DECIMALS !== undefined ? window.DEFAULT_CURRENCY_DECIMALS : 2;
}

/** Is this row in a currency other than the tenant's base currency? */
function rowIsForeignCurrency(row) {
    if (!row || !row.currency_id || window.DEFAULT_CURRENCY_ID === undefined) return false;
    return Number(row.currency_id) !== Number(window.DEFAULT_CURRENCY_ID);
}

/** Row amount in the row's own currency. */
function formatRowCurrency(row, amount) {
    return formatCurrency(amount, rowCurrencyDecimals(row), rowCurrencySymbol(row));
}

/**
 * "USD @ 2,450.60 = TZS. 339,413,001.20" for the amount cell's tooltip, or ''
 * for a base-currency row. Kept out of the visible cell text to match the
 * payments/receipts lists.
 */
function rowFxTitle(row, amount) {
    if (!rowIsForeignCurrency(row)) return '';
    const rate = parseFloat(row.exchange_rate_to_base) || 1;
    const base = formatCurrency(parseFloat(amount || 0) * rate,
                                window.DEFAULT_CURRENCY_DECIMALS,
                                window.DEFAULT_CURRENCY_SYMBOL);
    return (row.currency_code || rowCurrencySymbol(row)) + ' @ ' + formatNumber(rate, 2) + ' = ' + base;
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
</script>

<?php require_once '../../includes/footer.php'; ?>
