<?php
$pageTitle = 'Sale Details';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

if (!hasPermission('sales', 'view')) {
    setAlert('You do not have permission to access this page', 'danger');
    redirect('');
}

$saleId = $_GET['id'] ?? '';
if (!$saleId) {
    setAlert('Invalid sale ID', 'danger');
    redirect('modules/transactions/sales_list.php');
}

require_once '../../includes/header.php';
?>

<style>
    .detail-card {
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        padding: 20px;
        margin-bottom: 20px;
    }
    .detail-row {
        display: flex;
        justify-content: space-between;
        padding: 10px 0;
        border-bottom: 1px solid #e9ecef;
    }
    .detail-row:last-child {
        border-bottom: none;
    }
    .detail-label {
        font-weight: 600;
        color: #6c757d;
    }
    .detail-value {
        text-align: right;
        font-weight: 500;
    }
    .sync-badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 600;
    }
    .sync-badge.synced {
        background: #d4edda;
        color: #155724;
    }
    .sync-badge.pending {
        background: #fff3cd;
        color: #856404;
    }
    .items-table {
        background: #fff;
    }
    .total-row {
        background: #f8f9fa;
        font-weight: bold;
        font-size: 1.1rem;
    }
    @media print {
        .no-print {
            display: none !important;
        }
        .detail-card {
            box-shadow: none;
            border: 1px solid #dee2e6;
        }
    }
</style>

<?php
/* A printed sale is given to the customer, so it carries the company
   letterhead. Print-only — the on-screen page is unchanged. */
$printLetterheadTitle = __('sale');
require __DIR__ . '/../../includes/print_letterhead.php';
?>

<div class="no-print">
    <div class="row mb-3">
        <div class="col-md-6">
            <a href="sales_list.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back to List
            </a>
        </div>
        <div class="col-md-6 text-end">
            <button class="btn btn-outline-primary" onclick="window.print()">
                <i class="bi bi-printer"></i> Print
            </button>
            
            <?php if (hasPermission('sales', 'delete')): ?>
            <button class="btn btn-outline-danger" onclick="deleteSale()" id="deleteBtn">
                <i class="bi bi-trash"></i> Delete
            </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Loading State -->
<div id="loadingState" class="text-center py-5">
    <div class="spinner-border text-primary" role="status">
        <span class="visually-hidden">Loading...</span>
    </div>
    <p class="mt-2">Loading sale details...</p>
</div>

<!-- Sale Details Container -->
<div id="saleDetailsContainer" style="display: none;">
    <!-- Header Card -->
    <div class="detail-card">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
                <h3 class="mb-1" id="saleNumber">SALE #</h3>
                <p class="text-muted mb-0">
                    <i class="bi bi-calendar"></i> <span id="saleDate"></span>
                </p>
            </div>
            <div id="syncBadgeContainer"></div>
        </div>
        
        <div class="row mt-3">
            <div class="col-md-6">
                <div class="detail-row">
                    <span class="detail-label">Customer:</span>
                    <span class="detail-value" id="customerName"></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Location:</span>
                    <span class="detail-value" id="locationName"></span>
                </div>
                <div class="detail-row" id="costCentreRow" style="display:none;">
                    <span class="detail-label"><?php _e('cost_centre'); ?>:</span>
                    <span class="detail-value" id="costCentreName"></span>
                </div>
            </div>
            <div class="col-md-6">
                <div class="detail-row">
                    <span class="detail-label">Payment Mode:</span>
                    <span class="detail-value" id="paymentMode"></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Created By:</span>
                    <span class="detail-value" id="createdBy"></span>
                </div>
            </div>
        </div>
        
        <div class="detail-row" id="remarksRow" style="display: none;">
            <span class="detail-label">Remarks:</span>
            <span class="detail-value" id="remarks"></span>
        </div>
    </div>
    
    <!-- Items Card -->
    <div class="detail-card">
        <h5 class="mb-3">Sale Items</h5>
        
        <div class="table-responsive">
            <table class="table items-table">
                <thead class="table-light">
                    <tr>
                        <th width="5%">#</th>
                        <th width="35%">Product</th>
                        <th width="15%">Quantity</th>
                        <th width="15%">Rate</th>
                        <th width="15%" class="text-end">Amount</th>
                    </tr>
                </thead>
                <tbody id="itemsTableBody">
                    <!-- Items will be rendered here -->
                </tbody>
                <tfoot>
                    <tr class="total-row">
                        <td colspan="4" class="text-end">TOTAL:</td>
                        <td class="text-end" id="totalAmount"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    
    <!-- Metadata Card -->
    <div class="detail-card no-print">
        <h6 class="text-muted mb-3">Metadata</h6>
        <div class="row">
            <div class="col-md-6">
                <small class="text-muted">
                    <strong>Created:</strong> <span id="createdAt"></span>
                </small>
            </div>
            <div class="col-md-6">
                <small class="text-muted">
                    <strong>Updated:</strong> <span id="updatedAt"></span>
                </small>
            </div>
        </div>
    </div>
</div>

<!-- Error State -->
<div id="errorState" class="alert alert-danger" style="display: none;">
    <h5><i class="bi bi-exclamation-triangle"></i> Error</h5>
    <p id="errorMessage"></p>
    <a href="sales_list.php" class="btn btn-secondary">Back to List</a>
</div>

<script src="<?php echo BASE_URL; ?>assets/js/db.js"></script>
<script>
    console.log('=== SALES VIEW PAGE LOADED - VERSION 2026-01-02 ===');
    
    // Define JavaScript constants from PHP
    const BASE_URL = '<?php echo BASE_URL; ?>';
    const CURRENCY_SYMBOL = <?php echo json_encode($defaultCurrencySymbol); ?>;
    const SALE_ID = <?php echo json_encode($saleId); ?>;
    
    let saleData = null;
    let isOnline = navigator.onLine;
    
    // Initialize
    document.addEventListener('DOMContentLoaded', async function() {
        console.log('Loading sale:', SALE_ID);
        
        // Initialize database
        await initDatabase();
        
        // Load sale
        await loadSale();
    });
    
    // Initialize database
    async function initDatabase() {
        try {
            await openDatabase();
            console.log('Database initialized');
        } catch (error) {
            console.error('Database initialization error:', error);
        }
    }
    
    // Load sale from IndexedDB
    async function loadSale() {
        try {
            // First try IndexedDB
            saleData = await getFromStore('sales', SALE_ID);
            
            if (saleData) {
                console.log('Sale loaded from IndexedDB:', saleData);
                renderSale();
                return;
            }
            
            // If not in IndexedDB and online, try server
            if (isOnline) {
                console.log('Sale not in IndexedDB, fetching from server...');
                await fetchSaleFromServer();
            } else {
                showError('Sale not found and you are offline');
            }
            
        } catch (error) {
            console.error('Error loading sale:', error);
            showError('Failed to load sale: ' + error.message);
        }
    }
    
    // Fetch sale from server
    async function fetchSaleFromServer() {
        try {
            const response = await fetch(BASE_URL + 'api/sales.php');
            if (!response.ok) throw new Error('Failed to fetch sales');
            
            const sales = await response.json();
            saleData = sales.find(s => s.id == SALE_ID);
            
            if (saleData) {
                // Save to IndexedDB
                await addToStore('sales', saleData);
                console.log('Sale fetched from server and cached');
                renderSale();
            } else {
                showError('Sale not found on server');
            }
            
        } catch (error) {
            console.error('Error fetching sale from server:', error);
            showError('Failed to fetch sale from server');
        }
    }
    
    // Render sale details
    function renderSale() {
        if (!saleData) return;
        
        // Hide loading, show content
        document.getElementById('loadingState').style.display = 'none';
        document.getElementById('saleDetailsContainer').style.display = 'block';
        
        // Header
        document.getElementById('saleNumber').textContent = saleData.sale_number || 'SALE #' + saleData.id;
        document.getElementById('saleDate').textContent = formatDate(saleData.sale_date);
        
        // Sync badge
        const syncBadge = document.createElement('span');
        syncBadge.className = 'sync-badge ' + (saleData.synced ? 'synced' : 'pending');
        syncBadge.innerHTML = saleData.synced 
            ? '<i class="bi bi-check-circle"></i> Synced'
            : '<i class="bi bi-clock-history"></i> Pending Sync';
        document.getElementById('syncBadgeContainer').appendChild(syncBadge);
        
        // Details
        document.getElementById('customerName').textContent = saleData.customer_name || 'N/A';
        document.getElementById('locationName').textContent = saleData.location_name || 'N/A';

        // Cost Centre
        if (saleData.cost_centre_id && saleData.cost_centre_name) {
            document.getElementById('costCentreName').textContent = (saleData.cost_centre_code || '') + ' - ' + saleData.cost_centre_name;
            document.getElementById('costCentreRow').style.display = '';
        }

        // Payment mode badge
        const paymentBadges = {
            'cash': '<span class="badge bg-success">Cash</span>',
            'card': '<span class="badge bg-primary">Card</span>',
            'credit': '<span class="badge bg-warning text-dark">Credit</span>'
        };
        document.getElementById('paymentMode').innerHTML = paymentBadges[saleData.payment_mode] || saleData.payment_mode;
        
        document.getElementById('createdBy').textContent = saleData.created_by_name || 'N/A';
        
        // Remarks
        if (saleData.remarks) {
            document.getElementById('remarksRow').style.display = 'flex';
            document.getElementById('remarks').textContent = saleData.remarks;
        }
        
        // Items
        renderItems();
        
        // Metadata
        document.getElementById('createdAt').textContent = formatDateTime(saleData.created_at);
        document.getElementById('updatedAt').textContent = formatDateTime(saleData.updated_at || saleData.created_at);
        
        // Update page title
        document.title = saleData.sale_number + ' - Sale Details';
    }
    
    // Render items table
    function renderItems() {
        const tbody = document.getElementById('itemsTableBody');
        tbody.innerHTML = '';
        
        if (!saleData.details || saleData.details.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">No items</td></tr>';
            return;
        }
        
        let totalAmount = 0;
        
        saleData.details.forEach((item, index) => {
            const row = document.createElement('tr');
            
            const quantity = parseFloat(item.quantity);
            const rate = parseFloat(item.rate_per_unit);
            const amount = parseFloat(item.amount);
            totalAmount += amount;
            
            row.innerHTML = `
                <td>${index + 1}</td>
                <td>
                    <strong>${escapeHtml(item.product_name)}</strong>
                    <br>
                    <small class="text-muted">${escapeHtml(item.category_name || '')} - ${escapeHtml(item.unit)}</small>
                </td>
                <td>${quantity.toFixed(3)}</td>
                <td>${CURRENCY_SYMBOL} ${rate.toFixed(2)}</td>
                <td class="text-end"><strong>${CURRENCY_SYMBOL} ${amount.toFixed(2)}</strong></td>
            `;
            
            tbody.appendChild(row);
        });
        
        // Update total
        document.getElementById('totalAmount').textContent = CURRENCY_SYMBOL + ' ' + totalAmount.toFixed(2);
    }
    
    // Delete sale
    async function deleteSale() {
        if (!confirm('Are you sure you want to delete this sale?')) {
            return;
        }
        
        const btn = document.getElementById('deleteBtn');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Deleting...';
        
        try {
            // If synced and online, delete from server
            if (saleData.synced && isOnline) {
                const response = await fetch(BASE_URL + 'api/sales-sync.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'delete',
                        data: { id: SALE_ID }
                    })
                });
                
                if (!response.ok) throw new Error('Failed to delete from server');
                
                const result = await response.json();
                if (!result.success) throw new Error(result.error);
            }
            
            // Delete from IndexedDB
            await deleteFromStore('sales', SALE_ID);
            
            alert('Sale deleted successfully');
            window.location.href = 'sales_list.php';
            
        } catch (error) {
            console.error('Delete error:', error);
            alert('Error deleting sale: ' + error.message);
            
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-trash"></i> Delete';
        }
    }
    
    // Show error
    function showError(message) {
        document.getElementById('loadingState').style.display = 'none';
        document.getElementById('saleDetailsContainer').style.display = 'none';
        document.getElementById('errorState').style.display = 'block';
        document.getElementById('errorMessage').textContent = message;
    }
    
    // Utility functions
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    function formatDate(dateStr) {
        const date = new Date(dateStr);
        return date.toLocaleDateString('en-GB', {
            day: '2-digit',
            month: 'short',
            year: 'numeric'
        });
    }
    
    function formatDateTime(dateStr) {
        if (!dateStr) return 'N/A';
        const date = new Date(dateStr);
        return date.toLocaleString('en-GB', {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }
</script>

<?php require_once '../../includes/footer.php'; ?>
