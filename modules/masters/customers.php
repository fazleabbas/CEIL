<?php
/**
 * Customers Management Page
 * Online-only with IndexedDB caching for dropdowns
 * Full customer details with ledger integration
 */
require_once '../../config/database.php';
require_once '../../includes/functions.php';

$pageTitle = __('customers_management');

if (!hasPermission('customers', 'view')) {
    setAlert(__('error'), 'danger');
    redirect('');
}

require_once '../../includes/header.php';
?>

<style>
.customer-badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 0.25rem;
    font-size: 0.875rem;
    font-weight: 500;
}

.balance-positive {
    color: #dc3545;
    font-weight: 600;
}

.balance-negative {
    color: #198754;
    font-weight: 600;
}

.balance-zero {
    color: #6c757d;
}

.modal-backdrop.show {
    opacity: 0.5;
}

.form-label.required::after {
    content: " *";
    color: #dc3545;
}

.warning-box {
    background-color: #fff3cd;
    border: 1px solid #ffc107;
    border-radius: 0.375rem;
    padding: 1rem;
    margin-bottom: 1rem;
}

.warning-box i {
    color: #ffc107;
}
</style>

<div class="container-fluid">
    <!-- Header -->
    <div class="row mb-3">
        <div class="col-md-6">
            <h2><i class="bi bi-people"></i> <?php _e('customers_management'); ?></h2>
        </div>
        <div class="col-md-6 text-end">
            <?php if (hasPermission('customers', 'create')): ?>
            <button class="btn btn-primary" onclick="showAddModal()">
                <i class="bi bi-plus-circle"></i> <?php _e('add_new_customer'); ?>
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Search Bar -->
    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-2">
                <div class="col-md-10">
                    <input type="text" id="searchInput" class="form-control" 
                           placeholder="<?php _e('search_customers'); ?>">
                </div>
                <div class="col-md-2">
                    <button class="btn btn-primary w-100" onclick="searchCustomers()">
                        <i class="bi bi-search"></i> <?php _e('search'); ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Customers Table -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><?php _e('all_customers'); ?></h5>
            <span id="customerCount" class="badge bg-primary">0 <?php _e('customers'); ?></span>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="customersTable">
                    <thead class="table-light">
                        <tr>
                            <th><?php _e('sr_no'); ?></th>
                            <th><?php _e('name'); ?></th>
                            <th><?php _e('contact_person'); ?></th>
                            <th><?php _e('phone'); ?></th>
                            <th><?php _e('email'); ?></th>
                            <th class="text-end"><?php _e('balance'); ?></th>
                            <th class="text-center"><?php _e('sales'); ?></th>
                            <th class="text-center"><?php _e('receipts'); ?></th>
                            <th><?php _e('status'); ?></th>
                            <th class="text-center"><?php _e('actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="customersTableBody">
                        <tr>
                            <td colspan="10" class="text-center">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden"><?php _e('loading'); ?></span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit Customer Modal -->
<div class="modal fade" id="customerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle"><?php _e('add_new_customer'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="customerForm" onsubmit="saveCustomer(event)">
                <div class="modal-body">
                    <input type="hidden" id="customerId">
                    <input type="hidden" id="isEditMode" value="0">
                    
                    <!-- Warning for editing opening balance -->
                    <div id="editWarning" class="warning-box" style="display: none;">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <strong><?php _e('warning'); ?>:</strong> <?php _e('balance_warning'); ?>
                    </div>
                    
                    <!-- Basic Information -->
                    <h6 class="border-bottom pb-2 mb-3"><?php _e('basic_information'); ?></h6>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="name" class="form-label required"><?php _e('customer_name'); ?></label>
                            <input type="text" class="form-control" id="name" required>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="contactPerson" class="form-label"><?php _e('contact_person'); ?></label>
                            <input type="text" class="form-control" id="contactPerson">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="phone" class="form-label"><?php _e('phone'); ?></label>
                            <input type="tel" class="form-control" id="phone">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="email" class="form-label"><?php _e('email'); ?></label>
                            <input type="email" class="form-control" id="email">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="address" class="form-label"><?php _e('address'); ?></label>
                        <textarea class="form-control" id="address" rows="2"></textarea>
                    </div>
                    
                    <!-- Tax Information -->
                    <h6 class="border-bottom pb-2 mb-3 mt-4"><?php _e('tax_information'); ?></h6>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="gstin" class="form-label"><?php _e('gstin'); ?></label>
                            <input type="text" class="form-control" id="gstin" 
                                   placeholder="22AAAAA0000A1Z5" maxlength="15">
                            <small class="text-muted">15 characters (<?php _e('optional'); ?>)</small>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="pan" class="form-label"><?php _e('pan'); ?></label>
                            <input type="text" class="form-control" id="pan" 
                                   placeholder="ABCDE1234F" maxlength="10">
                            <small class="text-muted">10 characters (<?php _e('optional'); ?>)</small>
                        </div>
                    </div>
                    
                    <!-- Financial Information -->
                    <h6 class="border-bottom pb-2 mb-3 mt-4"><?php _e('financial_information'); ?></h6>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="openingBalance" class="form-label"><?php _e('opening_balance'); ?></label>
                            <input type="number" step="0.01" class="form-control" 
                                   id="openingBalance" value="0">
                            <small class="text-muted"><?php _e('opening_balance_customer_hint'); ?></small>
                        </div>
                        
                        <div class="col-md-6 mb-3 d-flex align-items-end">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" 
                                       id="isActive" checked>
                                <label class="form-check-label" for="isActive"><?php _e('active'); ?></label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle"></i> <?php _e('cancel'); ?>
                    </button>
                    <button type="submit" class="btn btn-primary" id="saveBtn">
                        <i class="bi bi-check-circle"></i> <?php _e('save_customer'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Balance Breakup Modal -->
<div class="modal fade" id="balanceBreakupModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-calculator"></i> <?php _e('balance_breakup'); ?>: <span id="breakupCustomerName"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- Loading state -->
                <div id="breakupLoading" class="text-center py-4">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="mt-2"><?php _e('loading'); ?>...</p>
                </div>
                
                <!-- Content -->
                <div id="breakupContent" style="display:none;">
                    <!-- Summary Card -->
                    <div class="card mb-3 bg-light">
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-6 col-md-3 mb-2">
                                    <small class="text-muted"><?php _e('opening_balance'); ?></small>
                                    <h5 id="breakupOpening" class="mb-0">-</h5>
                                </div>
                                <div class="col-6 col-md-3 mb-2">
                                    <small class="text-muted">(+) <?php _e('debit'); ?></small>
                                    <h5 id="breakupSales" class="mb-0 text-success">-</h5>
                                </div>
                                <div class="col-6 col-md-3 mb-2">
                                    <small class="text-muted">(-) <?php _e('credit'); ?></small>
                                    <h5 id="breakupReceipts" class="mb-0 text-danger">-</h5>
                                </div>
                                <div class="col-6 col-md-3 mb-2">
                                    <small class="text-muted"><?php _e('current_balance'); ?></small>
                                    <h5 id="breakupCurrent" class="mb-0 fw-bold">-</h5>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Transaction History -->
                    <h6 class="border-bottom pb-2 mb-3">
                        <i class="bi bi-list-ul"></i> <?php _e('transaction_history'); ?>
                    </h6>
                    
                    <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                        <table class="table table-sm table-hover">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th><?php _e('date'); ?></th>
                                    <th><?php _e('type'); ?></th>
                                    <th><?php _e('reference'); ?></th>
                                    <th class="text-end"><?php _e('debit'); ?></th>
                                    <th class="text-end"><?php _e('credit'); ?></th>
                                    <th class="text-end"><?php _e('balance'); ?></th>
                                </tr>
                            </thead>
                            <tbody id="breakupTransactions">
                            </tbody>
                        </table>
                    </div>
                    
                    <div id="noTransactions" class="text-center text-muted py-3" style="display:none;">
                        <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                        <p class="mt-2"><?php _e('no_transactions'); ?></p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-primary" onclick="printBalanceBreakup()">
                    <i class="bi bi-printer"></i> <?php _e('print'); ?>
                </button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php _e('close'); ?></button>
            </div>
        </div>
    </div>
</div>

<script>
// Translations for JavaScript
const LANG = {
    add_new_customer: <?php echo json_encode(__('add_new_customer')); ?>,
    edit_customer: <?php echo json_encode(__('edit_customer')); ?>,
    enter_customer_name: <?php echo json_encode(__('enter_customer_name')); ?>,
    confirm_delete_customer: <?php echo json_encode(__('confirm_delete_customer')); ?>,
    customer_has_transactions: <?php echo json_encode(__('customer_has_transactions')); ?>,
    active: <?php echo json_encode(__('active')); ?>,
    inactive: <?php echo json_encode(__('inactive')); ?>,
    edit: <?php echo json_encode(__('edit')); ?>,
    delete: <?php echo json_encode(__('delete')); ?>,
    sale_s: <?php echo json_encode(__('sale_s')); ?>,
    receipt_s: <?php echo json_encode(__('receipt_s')); ?>,
    and: <?php echo json_encode(__('and')); ?>,
    cannot_delete_default_customer: <?php echo json_encode(__('cannot_delete_default_customer')); ?>,
    action_cannot_undone: <?php echo json_encode(__('action_cannot_undone')); ?>,
    customer_not_found: <?php echo json_encode(__('customer_not_found')); ?>,
    saving: <?php echo json_encode(__('saving')); ?>,
    customers: <?php echo json_encode(__('customers')); ?>,
    sale: <?php echo json_encode(__('sale')); ?>,
    receipt: <?php echo json_encode(__('receipt')); ?>,
    opening: <?php echo json_encode(__('opening_balance')); ?>
};

const CURRENCY_SYMBOL = <?php echo json_encode($defaultCurrencySymbol); ?>;

let customers = [];
let filteredCustomers = [];
let customerModal;
let balanceBreakupModal;
let currentBreakupData = null;

// Load customers on page load
document.addEventListener('DOMContentLoaded', async function() {
    console.log('🎬 Customers page loaded');
    customerModal = new bootstrap.Modal(document.getElementById('customerModal'));
    balanceBreakupModal = new bootstrap.Modal(document.getElementById('balanceBreakupModal'));
    await loadCustomers();
    
    // Search on Enter key
    document.getElementById('searchInput').addEventListener('keyup', function(e) {
        if (e.key === 'Enter') {
            searchCustomers();
        }
    });
});

// Load customers from API
async function loadCustomers() {
    console.log('📥 Loading customers...');
    
    try {
        const response = await fetch(`${BASE_URL}api/customers.php?_=${Date.now()}`, {
            method: 'GET',
            headers: {
                'Cache-Control': 'no-cache, no-store, must-revalidate',
                'Pragma': 'no-cache'
            },
            cache: 'no-store'
        });
        
        if (!response.ok) {
            throw new Error('Network error');
        }
        
        const result = await response.json();
        console.log('📨 Load result:', result);
        
        if (result.success) {
            customers = result.customers || [];
            filteredCustomers = customers;
            displayCustomers();
        } else {
            throw new Error(result.error || 'Failed to load');
        }
        
    } catch (error) {
        console.error('❌ Load failed:', error);
        document.getElementById('customersTableBody').innerHTML = `
            <tr>
                <td colspan="10" class="text-center text-danger">
                    <i class="bi bi-exclamation-triangle"></i> ${error.message}
                </td>
            </tr>
        `;
    }
}

// Display customers in table
function displayCustomers() {
    const tbody = document.getElementById('customersTableBody');
    
    if (filteredCustomers.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="10" class="text-center text-muted py-4">
                    <i class="bi bi-people" style="font-size: 2rem;"></i>
                    <p class="mt-2 mb-0">${LANG.customer_not_found}</p>
                </td>
            </tr>
        `;
        document.getElementById('customerCount').textContent = `0 ${LANG.customers}`;
        return;
    }
    
    tbody.innerHTML = filteredCustomers.map((customer, index) => {
        const balance = parseFloat(customer.current_balance) || 0;
        let balanceClass = 'balance-zero';
        if (balance > 0) balanceClass = 'balance-positive';
        else if (balance < 0) balanceClass = 'balance-negative';
        
        const isDefault = customer.id == 1;
        
        return `
            <tr>
                <td>${index + 1}</td>
                <td>
                    <strong>${escapeHtml(customer.name)}</strong>
                    ${isDefault ? '<span class="badge bg-info ms-1">Default</span>' : ''}
                </td>
                <td>${escapeHtml(customer.contact_person || '-')}</td>
                <td>${escapeHtml(customer.phone || '-')}</td>
                <td>${escapeHtml(customer.email || '-')}</td>
                <td class="text-end ${balanceClass}" style="cursor:pointer;" onclick="showBalanceBreakup(${customer.id}, '${escapeHtml(customer.name).replace(/'/g, "\\'")}')">
                    <span title="Click for breakup">${formatCurrency(balance)} <i class="bi bi-info-circle-fill text-muted" style="font-size:0.75rem;"></i></span>
                </td>
                <td class="text-center">
                    <span class="badge bg-info">${customer.sale_count || 0}</span>
                </td>
                <td class="text-center">
                    <span class="badge bg-secondary">${customer.receipt_count || 0}</span>
                </td>
                <td>
                    ${customer.is_active == 1 ? 
                        `<span class="badge bg-success">${LANG.active}</span>` : 
                        `<span class="badge bg-secondary">${LANG.inactive}</span>`}
                </td>
                <td class="text-center">
                    <div class="btn-group btn-group-sm">
                        <?php if (hasPermission('customers', 'edit')): ?>
                        <button class="btn btn-outline-primary" 
                                onclick="showEditModal(${customer.id})" 
                                title="${LANG.edit}">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <?php endif; ?>
                        
                        <?php if (hasPermission('customers', 'delete')): ?>
                        <button class="btn btn-outline-danger" 
                                onclick="deleteCustomer(${customer.id})" 
                                title="${LANG.delete}"
                                ${isDefault ? 'disabled' : ''}>
                            <i class="bi bi-trash"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        `;
    }).join('');
    
    document.getElementById('customerCount').textContent = `${filteredCustomers.length} ${LANG.customers}`;
}

// Search customers
function searchCustomers() {
    const searchTerm = document.getElementById('searchInput').value.toLowerCase().trim();
    
    if (!searchTerm) {
        filteredCustomers = customers;
    } else {
        filteredCustomers = customers.filter(customer => {
            return (
                (customer.name && customer.name.toLowerCase().includes(searchTerm)) ||
                (customer.contact_person && customer.contact_person.toLowerCase().includes(searchTerm)) ||
                (customer.phone && customer.phone.toLowerCase().includes(searchTerm)) ||
                (customer.email && customer.email.toLowerCase().includes(searchTerm))
            );
        });
    }
    
    displayCustomers();
}

// Show add modal
function showAddModal() {
    document.getElementById('modalTitle').textContent = LANG.add_new_customer;
    document.getElementById('customerForm').reset();
    document.getElementById('customerId').value = '';
    document.getElementById('isEditMode').value = '0';
    document.getElementById('isActive').checked = true;
    document.getElementById('editWarning').style.display = 'none';
    customerModal.show();
    
    setTimeout(() => document.getElementById('name').focus(), 300);
}

// Show edit modal
function showEditModal(id) {
    const customer = customers.find(c => c.id == id);
    if (!customer) {
        showToast(LANG.customer_not_found, 'danger');
        return;
    }
    
    document.getElementById('modalTitle').textContent = LANG.edit_customer;
    document.getElementById('customerId').value = customer.id;
    document.getElementById('isEditMode').value = '1';
    document.getElementById('name').value = customer.name || '';
    document.getElementById('contactPerson').value = customer.contact_person || '';
    document.getElementById('phone').value = customer.phone || '';
    document.getElementById('email').value = customer.email || '';
    document.getElementById('address').value = customer.address || '';
    document.getElementById('gstin').value = customer.gstin || '';
    document.getElementById('pan').value = customer.pan || '';
    document.getElementById('openingBalance').value = customer.opening_balance || 0;
    document.getElementById('isActive').checked = customer.is_active == 1;
    
    document.getElementById('editWarning').style.display = 'block';
    
    customerModal.show();
}

// Save customer (create or update)
async function saveCustomer(event) {
    event.preventDefault();
    
    const saveBtn = document.getElementById('saveBtn');
    const originalText = saveBtn.innerHTML;
    saveBtn.disabled = true;
    saveBtn.innerHTML = `<span class="spinner-border spinner-border-sm"></span> ${LANG.saving}`;
    
    try {
        const idValue = document.getElementById('customerId').value;
        const isEdit = document.getElementById('isEditMode').value === '1';
        
        const customerData = {
            name: document.getElementById('name').value.trim(),
            contact_person: document.getElementById('contactPerson').value.trim(),
            phone: document.getElementById('phone').value.trim(),
            email: document.getElementById('email').value.trim(),
            address: document.getElementById('address').value.trim(),
            gstin: document.getElementById('gstin').value.trim().toUpperCase(),
            pan: document.getElementById('pan').value.trim().toUpperCase(),
            opening_balance: parseFloat(document.getElementById('openingBalance').value) || 0,
            is_active: document.getElementById('isActive').checked ? 1 : 0
        };
        
        if (isEdit && idValue) {
            customerData.id = parseInt(idValue);
        }
        
        console.log('💾 Saving customer:', customerData);
        
        const response = await fetch(`${BASE_URL}api/customers-sync.php`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Cache-Control': 'no-cache'
            },
            body: JSON.stringify({
                action: isEdit ? 'update' : 'create',
                data: customerData
            })
        });
        
        const result = await response.json();
        console.log('📨 Save response:', result);
        
        if (result.success) {
            showToast(result.message, 'success');
            customerModal.hide();
            await loadCustomers();
        } else {
            throw new Error(result.error || 'Failed to save customer');
        }
        
    } catch (error) {
        console.error('❌ Save failed:', error);
        showToast('Error: ' + error.message, 'danger');
    } finally {
        saveBtn.disabled = false;
        saveBtn.innerHTML = originalText;
    }
}

// Delete customer
async function deleteCustomer(id) {
    const customer = customers.find(c => c.id == id);
    if (!customer) return;

    // Protect Cash customer by name, not ID
    if (customer.name && customer.name.toLowerCase() === 'cash') {
        showToast(LANG.cannot_delete_default_customer, 'danger');
        return;
    }
    
    let message = LANG.confirm_delete_customer.replace('%s', customer.name);
    message += '\n\n' + LANG.action_cannot_undone;
    
    if (!confirm(message)) {
        return;
    }
    
    try {
        console.log('🗑️ Deleting customer:', id);
        
        const response = await fetch(`${BASE_URL}api/customers-sync.php`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Cache-Control': 'no-cache'
            },
            body: JSON.stringify({
                action: 'delete',
                data: { id: id }
            })
        });
        
        const result = await response.json();
        console.log('📨 Delete response:', result);
        
        if (result.success) {
            showToast(result.message, 'success');
            await loadCustomers();
        } else {
            throw new Error(result.error || 'Failed to delete customer');
        }
        
    } catch (error) {
        console.error('❌ Delete failed:', error);
        showToast('Error: ' + error.message, 'danger');
    }
}

// Show toast notification
function showToast(message, type = 'info') {
    document.querySelectorAll('.custom-toast').forEach(t => t.remove());
    
    const toast = document.createElement('div');
    toast.className = `alert alert-${type} alert-dismissible fade show custom-toast`;
    toast.style.cssText = `
        position: fixed;
        top: 80px;
        right: 20px;
        z-index: 9999;
        min-width: 300px;
        max-width: 500px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
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

// Escape HTML to prevent XSS
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Show balance breakup modal
async function showBalanceBreakup(customerId, customerName) {
    // Show modal with loading state
    document.getElementById('breakupCustomerName').textContent = customerName;
    document.getElementById('breakupLoading').style.display = 'block';
    document.getElementById('breakupContent').style.display = 'none';
    balanceBreakupModal.show();
    
    try {
        const response = await fetch(`${BASE_URL}api/customer-balance-breakup.php?id=${customerId}&_=${Date.now()}`, {
            cache: 'no-store'
        });
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.error || 'Failed to load');
        }
        
        const data = result.data;
        currentBreakupData = { ...data, type: 'customer' };
        
        // Update summary
        document.getElementById('breakupOpening').textContent = formatCurrency(data.opening_balance);
        document.getElementById('breakupSales').textContent = formatCurrency(data.total_sales);
        document.getElementById('breakupReceipts').textContent = formatCurrency(data.total_receipts);
        
        const currentEl = document.getElementById('breakupCurrent');
        currentEl.textContent = formatCurrency(data.current_balance);
        currentEl.className = `mb-0 fw-bold ${data.current_balance > 0 ? 'text-danger' : 'text-success'}`;
        
        // Build transaction table
        const tbody = document.getElementById('breakupTransactions');
        const noTxn = document.getElementById('noTransactions');
        
        if (data.transactions.length === 0 && data.opening_balance === 0) {
            tbody.innerHTML = '';
            noTxn.style.display = 'block';
        } else {
            noTxn.style.display = 'none';
            
            let rows = '';
            
            // Opening balance row
            if (data.opening_balance !== 0) {
                rows += `
                    <tr class="table-secondary">
                        <td>-</td>
                        <td><em>${LANG.opening}</em></td>
                        <td>-</td>
                        <td class="text-end">${data.opening_balance > 0 ? formatCurrency(data.opening_balance) : '-'}</td>
                        <td class="text-end">${data.opening_balance < 0 ? formatCurrency(Math.abs(data.opening_balance)) : '-'}</td>
                        <td class="text-end">${formatCurrency(data.opening_balance)}</td>
                    </tr>
                `;
            }
            
            // Transaction rows
            data.transactions.forEach(txn => {
                // Rows come from the general ledger, so the true accounting side
                // decides the column. For a receivable a debit is also the side
                // that raises the balance, so this matches the running balance.
                const isDebit = (txn.dr_cr || (txn.entry_type === 'debit' ? 'DR' : 'CR')) === 'DR';
                const typeBadge = voucherBadge(txn.type);

                rows += `
                    <tr>
                        <td>${formatDate(txn.transaction_date)}</td>
                        <td>${typeBadge}</td>
                        <td><small>${escapeHtml(txn.reference)}</small></td>
                        <td class="text-end ${isDebit ? 'text-success' : ''}">${isDebit ? formatCurrency(txn.amount) : '-'}</td>
                        <td class="text-end ${!isDebit ? 'text-danger' : ''}">${!isDebit ? formatCurrency(txn.amount) : '-'}</td>
                        <td class="text-end fw-bold">${formatCurrency(txn.running_balance)}</td>
                    </tr>
                `;
            });
            
            tbody.innerHTML = rows;
        }
        
        // Show content
        document.getElementById('breakupLoading').style.display = 'none';
        document.getElementById('breakupContent').style.display = 'block';
        
    } catch (error) {
        console.error('❌ Balance breakup failed:', error);
        showToast(error.message, 'danger');
        balanceBreakupModal.hide();
    }
}

// Format date helper
function formatDate(dateStr) {
    if (!dateStr) return '-';
    const date = new Date(dateStr);
    return date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

// Voucher type -> [label, bootstrap badge class]. The breakup now reads the
// general ledger, so any voucher type can appear (journals included).
const VOUCHER_TYPES = {
    sales:           ['Sale',     'bg-info'],
    sale:            ['Sale',     'bg-info'],
    receipt:         ['Receipt',  'bg-success'],
    purchase:        ['Purchase', 'bg-info'],
    purchase_import: ['Import',   'bg-primary'],
    payment:         ['Payment',  'bg-warning text-dark'],
    journal:         ['Journal',  'bg-secondary'],
    manual_journal:  ['Journal',  'bg-secondary']
};

function voucherLabel(type) {
    const entry = VOUCHER_TYPES[type];
    return entry ? entry[0] : (type || '-');
}

function voucherBadge(type) {
    const entry = VOUCHER_TYPES[type];
    const cls = entry ? entry[1] : 'bg-secondary';
    return `<span class="badge ${cls}">${escapeHtml(voucherLabel(type))}</span>`;
}

// Print balance breakup
function printBalanceBreakup() {
    if (!currentBreakupData) return;
    
    const data = currentBreakupData;
    const printWindow = window.open('', '_blank');

    // This statement is sent to the customer, so it carries the company
    // letterhead — banner if one is uploaded (Masters -> Company Settings),
    // name/address/phone otherwise. The popup is written into a blank window
    // and never loads style.css, hence the CSS injected below too.
    const companyLetterhead = <?php echo json_encode(getPrintLetterheadHtml()); ?>;

    let transactionRows = '';
    
    // Opening balance row
    if (data.opening_balance !== 0) {
        transactionRows += `
            <tr style="background:#f8f9fa;">
                <td>-</td>
                <td><em>${LANG.opening}</em></td>
                <td>-</td>
                <td style="text-align:right;">${data.opening_balance > 0 ? formatCurrency(data.opening_balance) : '-'}</td>
                <td style="text-align:right;">${data.opening_balance < 0 ? formatCurrency(Math.abs(data.opening_balance)) : '-'}</td>
                <td style="text-align:right;">${formatCurrency(data.opening_balance)}</td>
            </tr>
        `;
    }
    
    // Transaction rows
    data.transactions.forEach(txn => {
        const isDebit = (txn.dr_cr || (txn.entry_type === 'debit' ? 'DR' : 'CR')) === 'DR';

        transactionRows += `
            <tr>
                <td>${formatDate(txn.transaction_date)}</td>
                <td>${voucherLabel(txn.type)}</td>
                <td>${txn.reference}</td>
                <td style="text-align:right;color:${isDebit ? 'green' : 'inherit'};">${isDebit ? formatCurrency(txn.amount) : '-'}</td>
                <td style="text-align:right;color:${!isDebit ? 'red' : 'inherit'};">${!isDebit ? formatCurrency(txn.amount) : '-'}</td>
                <td style="text-align:right;font-weight:bold;">${formatCurrency(txn.running_balance)}</td>
            </tr>
        `;
    });
    
    const html = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Balance Breakup - ${data.customer_name}</title>
            <style>
                body { font-family: Arial, sans-serif; padding: 20px; font-size: 12px; }
                h2 { margin-bottom: 5px; }
                .subtitle { color: #666; margin-bottom: 20px; }
                .summary { display: flex; gap: 30px; margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 5px; }
                .summary-item { text-align: center; }
                .summary-item .label { font-size: 11px; color: #666; }
                .summary-item .value { font-size: 16px; font-weight: bold; }
                .text-success { color: green; }
                .text-danger { color: red; }
                table { width: 100%; border-collapse: collapse; margin-top: 10px; }
                th, td { border: 1px solid #ddd; padding: 8px; font-size: 11px; }
                th { background: #f0f0f0; text-align: left; }
                .print-date { text-align: right; font-size: 10px; color: #666; margin-bottom: 10px; }
                .footer { text-align: center; margin-top: 30px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 10px; color: #999; }
                <?php echo getPrintLetterheadCss(); ?>
                @media print {
                    body { padding: 0; }
                    .no-print { display: none; }
                }
            </style>
        </head>
        <body>
            ${companyLetterhead}
            <div class="print-date">Printed: ${new Date().toLocaleString()}</div>
            <h2>Balance Breakup</h2>
            <div class="subtitle"><strong>Customer:</strong> ${data.customer_name}</div>
            
            <div class="summary">
                <div class="summary-item">
                    <div class="label">Opening Balance</div>
                    <div class="value">${formatCurrency(data.opening_balance)}</div>
                </div>
                <div class="summary-item">
                    <div class="label">(+) Debit</div>
                    <div class="value text-success">${formatCurrency(data.total_sales)}</div>
                </div>
                <div class="summary-item">
                    <div class="label">(-) Credit</div>
                    <div class="value text-danger">${formatCurrency(data.total_receipts)}</div>
                </div>
                <div class="summary-item">
                    <div class="label">Current Balance</div>
                    <div class="value ${data.current_balance > 0 ? 'text-danger' : 'text-success'}">${formatCurrency(data.current_balance)}</div>
                </div>
            </div>
            
            <h3>Transaction History</h3>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Reference</th>
                        <th style="text-align:right;">Debit</th>
                        <th style="text-align:right;">Credit</th>
                        <th style="text-align:right;">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    ${transactionRows || '<tr><td colspan="6" style="text-align:center;">No transactions</td></tr>'}
                </tbody>
            </table>
            
            <div class="footer"><?php echo __('developed_by'); ?> <strong>Fabonic</strong> &middot; www.fabonic.co.tz</div>
            
            <script>window.onload = function() { window.print(); }<\/script>
        </body>
        </html>
    `;
    
    printWindow.document.write(html);
    printWindow.document.close();
}
</script>

<?php require_once '../../includes/footer.php'; ?>
