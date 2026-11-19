<?php
/**
 * Suppliers Management - Online Only (with IndexedDB Cache for Dropdowns)
 * Full details with ledger account integration
 */
require_once '../../config/database.php';
require_once '../../includes/functions.php';

$pageTitle = __('suppliers_management');

if (!hasPermission('suppliers', 'view')) {
    setAlert(__('error'), 'danger');
    redirect('');
}

$blacklistAvailable = supplierBlacklistAvailable($conn);

require_once '../../includes/header.php';
?>

<div class="row mb-3">
    <div class="col-md-6">
        <h2><i class="bi bi-truck"></i> <?php _e('suppliers_management'); ?></h2>
    </div>
    <div class="col-md-6 text-end">
        <?php if (hasPermission('suppliers', 'import')): ?>
        <a href="<?php echo BASE_URL; ?>modules/masters/suppliers-import.php" class="btn btn-outline-success">
            <i class="bi bi-upload"></i> <?php _e('import_suppliers'); ?>
        </a>
        <?php endif; ?>
        <?php if (hasPermission('suppliers', 'create')): ?>
        <button class="btn btn-primary" onclick="showCreateModal()">
            <i class="bi bi-plus-circle"></i> <?php _e('add_new_supplier'); ?>
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
                       placeholder="<?php _e('search_suppliers'); ?>" 
                       onkeyup="searchSuppliers()">
            </div>
            <div class="col-md-2">
                <button onclick="clearSearch()" class="btn btn-secondary w-100" id="clearBtn" style="display:none;">
                    <i class="bi bi-x-circle"></i> <?php _e('clear'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Suppliers Table -->
<div class="card">
    <div class="card-body">
        <div id="loadingSpinner" class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden"><?php _e('loading'); ?></span>
            </div>
            <p class="mt-2"><?php _e('loading_suppliers'); ?></p>
        </div>
        
        <div id="suppliersContainer" style="display:none;">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th><?php _e('name'); ?></th>
                            <th><?php _e('contact_person'); ?></th>
                            <th><?php _e('phone'); ?></th>
                            <th><?php _e('email'); ?></th>
                            <th><?php _e('balance'); ?></th>
                            <th><?php _e('purchases'); ?></th>
                            <th><?php _e('payments'); ?></th>
                            <th><?php _e('status'); ?></th>
                            <th><?php _e('actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="suppliersTableBody">
                        <!-- Populated by JavaScript -->
                    </tbody>
                </table>
            </div>
        </div>
        
        <div id="emptyState" style="display:none;" class="text-center py-5">
            <i class="bi bi-truck" style="font-size: 4rem; color: #ccc;"></i>
            <h5 class="mt-3"><?php _e('no_suppliers_found'); ?></h5>
            <p class="text-muted"><?php _e('start_adding_supplier'); ?></p>
        </div>
    </div>
</div>

<!-- Create/Edit Modal -->
<div class="modal fade" id="supplierModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="supplierModalTitle"><?php _e('add_new_supplier'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="supplierForm">
                    <input type="hidden" id="supplierId">
                    <input type="hidden" id="supplierLedgerId">
                    
                    <!-- Basic Information -->
                    <h6 class="border-bottom pb-2 mb-3"><?php _e('basic_information'); ?></h6>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="supplierName" class="form-label"><?php _e('supplier_name'); ?> *</label>
                            <input type="text" class="form-control" id="supplierName" required>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="supplierContact" class="form-label"><?php _e('contact_person'); ?></label>
                            <input type="text" class="form-control" id="supplierContact">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="supplierPhone" class="form-label"><?php _e('phone'); ?></label>
                            <input type="text" class="form-control" id="supplierPhone">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="supplierEmail" class="form-label"><?php _e('email'); ?></label>
                            <input type="email" class="form-control" id="supplierEmail">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="supplierAddress" class="form-label"><?php _e('address'); ?></label>
                        <textarea class="form-control" id="supplierAddress" rows="2"></textarea>
                    </div>
                    
                    <!-- Tax Information -->
                    <h6 class="border-bottom pb-2 mb-3 mt-4"><?php _e('tax_information'); ?></h6>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="supplierGSTIN" class="form-label"><?php _e('gstin'); ?></label>
                            <input type="text" class="form-control" id="supplierGSTIN" 
                                   maxlength="15" placeholder="22AAAAA0000A1Z5">
                            <small class="text-muted">15 characters (<?php _e('optional'); ?>)</small>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="supplierPAN" class="form-label"><?php _e('pan'); ?></label>
                            <input type="text" class="form-control" id="supplierPAN" 
                                   maxlength="10" placeholder="ABCDE1234F">
                            <small class="text-muted">10 characters (<?php _e('optional'); ?>)</small>
                        </div>
                    </div>
                    
                    <!-- Financial Information -->
                    <h6 class="border-bottom pb-2 mb-3 mt-4"><?php _e('financial_information'); ?></h6>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="supplierOpeningBalance" class="form-label"><?php _e('opening_balance'); ?></label>
                            <input type="number" step="0.01" class="form-control" 
                                   id="supplierOpeningBalance" value="0">
                            <small class="text-muted"><?php _e('opening_balance_hint'); ?></small>
                        </div>
                        
                        <div class="col-md-6 mb-3 d-flex align-items-end">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" 
                                       id="supplierActive" checked>
                                <label class="form-check-label" for="supplierActive"><?php _e('active'); ?></label>
                            </div>
                        </div>
                    </div>

                    <?php if ($blacklistAvailable): ?>
                    <!-- Blacklist (migration 0054). Edit mode only: setting it needs
                         suppliers.edit, and the create action does not take it. -->
                    <div id="blacklistSection" style="display:none;">
                        <h6 class="border-bottom pb-2 mb-3 mt-4 text-danger"><i class="bi bi-slash-circle"></i> <?php _e('supplier_blacklist'); ?></h6>
                        <div class="form-check mb-2">
                            <input type="checkbox" class="form-check-input" id="supplierBlacklisted"
                                   onchange="toggleBlacklistReason()">
                            <label class="form-check-label" for="supplierBlacklisted"><?php _e('supplier_blacklisted_label'); ?></label>
                        </div>
                        <small class="text-muted d-block mb-2"><?php _e('supplier_blacklist_hint'); ?></small>
                        <div id="blacklistReasonWrap" style="display:none;">
                            <label for="supplierBlacklistReason" class="form-label"><?php _e('supplier_blacklist_reason'); ?> <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="supplierBlacklistReason" rows="2" maxlength="1000"></textarea>
                            <small class="text-muted" id="blacklistSince"></small>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Warning for opening balance edit -->
                    <div class="alert alert-warning" id="balanceWarning" style="display:none;">
                        <i class="bi bi-exclamation-triangle"></i>
                        <strong><?php _e('warning'); ?>:</strong> <?php _e('balance_warning'); ?>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                <button type="button" class="btn btn-primary" onclick="saveSupplier()">
                    <i class="bi bi-check-circle"></i> <?php _e('save_supplier'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Balance Breakup Modal -->
<div class="modal fade" id="balanceBreakupModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-calculator"></i> <?php _e('balance_breakup'); ?>: <span id="breakupSupplierName"></span>
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
                                    <small class="text-muted">(+) <?php _e('credit'); ?></small>
                                    <h5 id="breakupPurchases" class="mb-0 text-success">-</h5>
                                </div>
                                <div class="col-6 col-md-3 mb-2">
                                    <small class="text-muted">(-) <?php _e('debit'); ?></small>
                                    <h5 id="breakupPayments" class="mb-0 text-danger">-</h5>
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
    add_new_supplier: <?php echo json_encode(__('add_new_supplier')); ?>,
    edit_supplier: <?php echo json_encode(__('edit_supplier')); ?>,
    enter_supplier_name: <?php echo json_encode(__('enter_supplier_name')); ?>,
    confirm_delete_supplier: <?php echo json_encode(__('confirm_delete_supplier')); ?>,
    supplier_has_transactions: <?php echo json_encode(__('supplier_has_transactions')); ?>,
    active: <?php echo json_encode(__('active')); ?>,
    inactive: <?php echo json_encode(__('inactive')); ?>,
    edit: <?php echo json_encode(__('edit')); ?>,
    delete: <?php echo json_encode(__('delete')); ?>,
    purchase_s: <?php echo json_encode(__('purchase_s')); ?>,
    payment_s: <?php echo json_encode(__('payment_s')); ?>,
    and: <?php echo json_encode(__('and')); ?>,
    purchase: <?php echo json_encode(__('purchase')); ?>,
    payment: <?php echo json_encode(__('payment')); ?>,
    opening: <?php echo json_encode(__('opening_balance')); ?>,
    blacklisted: <?php echo json_encode(__('supplier_blacklisted')); ?>,
    blacklist_reason_required: <?php echo json_encode(__('supplier_blacklist_reason_required')); ?>,
    blacklisted_since: <?php echo json_encode(__('supplier_blacklisted_since')); ?>
};
const BLACKLIST_AVAILABLE = <?php echo $blacklistAvailable ? 'true' : 'false'; ?>;

const CURRENCY_SYMBOL = <?php echo json_encode($defaultCurrencySymbol); ?>;

// Global variables
let suppliersData = [];
let supplierModal;
let balanceBreakupModal;
let searchQuery = '';
let currentBreakupData = null;

// Initialize
document.addEventListener('DOMContentLoaded', async function() {
    console.log('🎬 Suppliers page loaded');
    
    // Initialize modals
    supplierModal = new bootstrap.Modal(document.getElementById('supplierModal'));
    balanceBreakupModal = new bootstrap.Modal(document.getElementById('balanceBreakupModal'));
    
    // Initialize offline DB if available
    if (window.offlineDB) {
        try {
            await window.offlineDB.init();
            console.log('✅ IndexedDB ready for caching');
        } catch (error) {
            console.log('⚠️ IndexedDB not available, will work online-only');
        }
    }
    
    // Load suppliers
    await loadSuppliers();
    
    // Enter key support in modal
    document.getElementById('supplierForm').addEventListener('keypress', function(e) {
        if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') {
            e.preventDefault();
            saveSupplier();
        }
    });
    
    // Event delegation for edit/delete buttons
    document.getElementById('suppliersTableBody').addEventListener('click', function(e) {
        const btn = e.target.closest('button[data-id]');
        if (!btn) return;
        
        const id = btn.getAttribute('data-id');
        if (btn.getAttribute('data-action') === 'delete') {
            deleteSupplier(id);
        } else {
            showEditModal(id);
        }
    });
    
    // Show warning when opening balance changes (edit mode only)
    document.getElementById('supplierOpeningBalance').addEventListener('input', function() {
        const isEdit = document.getElementById('supplierId').value;
        const warning = document.getElementById('balanceWarning');
        if (isEdit && warning) {
            warning.style.display = 'block';
        }
    });
});

// Load suppliers from API
async function loadSuppliers() {
    console.log('📥 Loading suppliers...');
    document.getElementById('loadingSpinner').style.display = 'block';
    document.getElementById('suppliersContainer').style.display = 'none';
    document.getElementById('emptyState').style.display = 'none';
    
    try {
        const url = `${BASE_URL}api/suppliers.php?_=${Date.now()}`;
        
        const response = await fetch(url, {
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
            suppliersData = result.suppliers || result.data || [];
            
            // Cache in IndexedDB for dropdown use
            if (window.offlineDB && window.offlineDB.db && suppliersData.length > 0) {
                for (const supplier of suppliersData) {
                    await window.offlineDB.save('suppliers', supplier);
                }
                console.log(`✅ Cached ${suppliersData.length} suppliers`);
            }
        } else {
            throw new Error(result.error || 'Failed to load');
        }
        
    } catch (error) {
        console.error('❌ Load failed:', error);
        
        // Try to load from cache
        if (window.offlineDB && window.offlineDB.db) {
            try {
                suppliersData = await window.offlineDB.getAll('suppliers');
                console.log(`📦 Loaded ${suppliersData.length} suppliers from cache`);
            } catch (e) {
                console.error('Cache load failed:', e);
            }
        }
    }
    
    document.getElementById('loadingSpinner').style.display = 'none';
    displaySuppliers();
}

// Display suppliers in table
function displaySuppliers() {
    const tbody = document.getElementById('suppliersTableBody');
    
    // Filter by search
    let filtered = suppliersData;
    if (searchQuery) {
        const q = searchQuery.toLowerCase();
        filtered = suppliersData.filter(s => 
            s.name.toLowerCase().includes(q) ||
            (s.contact_person && s.contact_person.toLowerCase().includes(q)) ||
            (s.phone && s.phone.toLowerCase().includes(q)) ||
            (s.email && s.email.toLowerCase().includes(q))
        );
    }
    
    if (filtered.length === 0) {
        document.getElementById('suppliersContainer').style.display = 'none';
        document.getElementById('emptyState').style.display = 'block';
        return;
    }
    
    document.getElementById('emptyState').style.display = 'none';
    document.getElementById('suppliersContainer').style.display = 'block';
    
    tbody.innerHTML = filtered.map(supplier => `
        <tr>
            <td><strong>${escapeHtml(supplier.name)}</strong></td>
            <td>${escapeHtml(supplier.contact_person || '-')}</td>
            <td>${escapeHtml(supplier.phone || '-')}</td>
            <td>${escapeHtml(supplier.email || '-')}</td>
            <td class="balance-cell" style="cursor:pointer;" onclick="showBalanceBreakup(${supplier.id}, '${escapeHtml(supplier.name).replace(/'/g, "\\'")}')">
                <span class="${supplier.current_balance > 0 ? 'text-danger' : 'text-success'}" title="Click for breakup">
                    ${formatCurrency(supplier.current_balance)} <i class="bi bi-info-circle-fill text-muted" style="font-size:0.75rem;"></i>
                </span>
            </td>
            <td><span class="badge bg-info">${supplier.purchase_count || 0}</span></td>
            <td><span class="badge bg-secondary">${supplier.payment_count || 0}</span></td>
            <td>
                ${supplier.is_active ? 
                    `<span class="badge bg-success">${LANG.active}</span>` : 
                    `<span class="badge bg-secondary">${LANG.inactive}</span>`}
                ${supplier.is_blacklisted == 1 ?
                    `<span class="badge bg-danger" title="${escapeHtml(supplier.blacklist_reason || '')}"><i class="bi bi-slash-circle"></i> ${LANG.blacklisted}</span>` : ''}
            </td>
            <td>
                <div class="btn-group btn-group-sm">
                    <?php if (hasPermission('suppliers', 'edit')): ?>
                    <button type="button" class="btn btn-outline-primary" data-id="${supplier.id}" title="${LANG.edit}">
                        <i class="bi bi-pencil" style="pointer-events:none;"></i>
                    </button>
                    <?php endif; ?>
                    
                    <?php if (hasPermission('suppliers', 'delete')): ?>
                    <button type="button" class="btn btn-outline-danger" data-id="${supplier.id}" data-action="delete" title="${LANG.delete}">
                        <i class="bi bi-trash" style="pointer-events:none;"></i>
                    </button>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
    `).join('');
}

function toggleBlacklistReason() {
    const on = document.getElementById('supplierBlacklisted').checked;
    document.getElementById('blacklistReasonWrap').style.display = on ? 'block' : 'none';
}

// Show create modal
function showCreateModal() {
    document.getElementById('supplierModalTitle').textContent = LANG.add_new_supplier;
    document.getElementById('supplierForm').reset();
    document.getElementById('supplierId').value = '';
    document.getElementById('supplierLedgerId').value = '';
    document.getElementById('supplierActive').checked = true;
    document.getElementById('supplierOpeningBalance').value = '0';
    document.getElementById('balanceWarning').style.display = 'none';
    if (BLACKLIST_AVAILABLE) {
        // Blacklisting is an edit-only action (suppliers.edit).
        document.getElementById('blacklistSection').style.display = 'none';
        document.getElementById('supplierBlacklisted').checked = false;
        document.getElementById('supplierBlacklistReason').value = '';
        toggleBlacklistReason();
    }
    supplierModal.show();
}

// Show edit modal
function showEditModal(idParam) {
    const id = parseInt(idParam);
    const supplier = suppliersData.find(s => parseInt(s.id) === id);
    if (!supplier) return;
    
    document.getElementById('supplierModalTitle').textContent = LANG.edit_supplier;
    document.getElementById('supplierId').value = supplier.id;
    document.getElementById('supplierLedgerId').value = supplier.ledger_account_id;
    document.getElementById('supplierName').value = supplier.name;
    document.getElementById('supplierContact').value = supplier.contact_person || '';
    document.getElementById('supplierPhone').value = supplier.phone || '';
    document.getElementById('supplierEmail').value = supplier.email || '';
    document.getElementById('supplierAddress').value = supplier.address || '';
    document.getElementById('supplierGSTIN').value = supplier.gstin || '';
    document.getElementById('supplierPAN').value = supplier.pan || '';
    document.getElementById('supplierOpeningBalance').value = supplier.opening_balance || 0;
    document.getElementById('supplierActive').checked = supplier.is_active == 1;
    document.getElementById('balanceWarning').style.display = 'none';
    if (BLACKLIST_AVAILABLE) {
        document.getElementById('blacklistSection').style.display = 'block';
        document.getElementById('supplierBlacklisted').checked = supplier.is_blacklisted == 1;
        document.getElementById('supplierBlacklistReason').value = supplier.blacklist_reason || '';
        document.getElementById('blacklistSince').textContent = (supplier.is_blacklisted == 1 && supplier.blacklisted_at)
            ? LANG.blacklisted_since + ' ' + formatDate(supplier.blacklisted_at) : '';
        toggleBlacklistReason();
    }
    
    supplierModal.show();
}

// Save supplier (create or update)
async function saveSupplier() {
    const idValue = document.getElementById('supplierId').value;
    const id = idValue ? parseInt(idValue) : null;
    const name = document.getElementById('supplierName').value.trim();
    const contact_person = document.getElementById('supplierContact').value.trim();
    const phone = document.getElementById('supplierPhone').value.trim();
    const email = document.getElementById('supplierEmail').value.trim();
    const address = document.getElementById('supplierAddress').value.trim();
    const gstin = document.getElementById('supplierGSTIN').value.trim().toUpperCase();
    const pan = document.getElementById('supplierPAN').value.trim().toUpperCase();
    const opening_balance = parseFloat(document.getElementById('supplierOpeningBalance').value) || 0;
    const is_active = document.getElementById('supplierActive').checked ? 1 : 0;
    
    if (!name) {
        showToast(LANG.enter_supplier_name, 'warning');
        return;
    }
    
    const data = { 
        name, contact_person, phone, email, address,
        gstin, pan, opening_balance, is_active
    };
    
    if (id) {
        data.id = id;
        // Sent on edit only; the server ignores it when the key is absent.
        if (BLACKLIST_AVAILABLE) {
            data.is_blacklisted = document.getElementById('supplierBlacklisted').checked ? 1 : 0;
            data.blacklist_reason = document.getElementById('supplierBlacklistReason').value.trim();
            if (data.is_blacklisted && !data.blacklist_reason) {
                showToast(LANG.blacklist_reason_required, 'warning');
                document.getElementById('supplierBlacklistReason').focus();
                return;
            }
        }
    }
    
    try {
        const url = `${BASE_URL}api/suppliers-sync.php?_=${Date.now()}`;
        const action = id ? 'update' : 'create';
        
        const response = await fetch(url, {
            method: 'POST',
            headers: { 
                'Content-Type': 'application/json',
                'Cache-Control': 'no-cache'
            },
            cache: 'no-store',
            body: JSON.stringify({ action, data })
        });
        
        const result = await response.json();
        console.log('📨 Save response:', result);
        
        if (!result.success) {
            throw new Error(result.error || 'Failed to save');
        }
        
        showToast(result.message, 'success');
        supplierModal.hide();
        
        // Reload to get fresh data
        await loadSuppliers();
        
    } catch (error) {
        console.error('❌ Save failed:', error);
        showToast(error.message, 'danger');
    }
}

// Delete supplier
async function deleteSupplier(idParam) {
    const id = parseInt(idParam);
    const supplier = suppliersData.find(s => parseInt(s.id) === id);
    if (!supplier) return;
    
    const warnings = [];
    if (supplier.purchase_count > 0) {
        warnings.push(`${supplier.purchase_count} ${LANG.purchase_s}`);
    }
    if (supplier.payment_count > 0) {
        warnings.push(`${supplier.payment_count} ${LANG.payment_s}`);
    }
    
    let message = LANG.confirm_delete_supplier.replace('%s', supplier.name);
    if (warnings.length > 0) {
        message += '\n\n' + LANG.supplier_has_transactions.replace('%s', warnings.join(` ${LANG.and} `));
    }
    
    if (!confirm(message)) {
        return;
    }
    
    try {
        const url = `${BASE_URL}api/suppliers-sync.php?_=${Date.now()}`;
        
        const response = await fetch(url, {
            method: 'POST',
            headers: { 
                'Content-Type': 'application/json',
                'Cache-Control': 'no-cache'
            },
            cache: 'no-store',
            body: JSON.stringify({ 
                action: 'delete', 
                data: { id } 
            })
        });
        
        const result = await response.json();
        console.log('📨 Delete response:', result);
        
        if (!result.success) {
            throw new Error(result.error || 'Failed to delete');
        }
        
        showToast(result.message, 'success');
        
        // Remove from array and update cache
        suppliersData = suppliersData.filter(s => parseInt(s.id) !== id);
        if (window.offlineDB && window.offlineDB.db) {
            await window.offlineDB.delete('suppliers', id);
        }
        displaySuppliers();
        
    } catch (error) {
        console.error('❌ Delete failed:', error);
        showToast(error.message, 'danger');
    }
}

// Search suppliers
function searchSuppliers() {
    searchQuery = document.getElementById('searchInput').value.trim();
    document.getElementById('clearBtn').style.display = searchQuery ? 'block' : 'none';
    displaySuppliers();
}

// Clear search
function clearSearch() {
    document.getElementById('searchInput').value = '';
    searchQuery = '';
    document.getElementById('clearBtn').style.display = 'none';
    displaySuppliers();
}

// Show toast notification
function showToast(message, type = 'info') {
    // Remove existing toasts
    document.querySelectorAll('.supplier-toast').forEach(t => t.remove());
    
    const toast = document.createElement('div');
    toast.className = `supplier-toast alert alert-${type} alert-dismissible fade show`;
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

// Escape HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Show balance breakup modal
async function showBalanceBreakup(supplierId, supplierName) {
    // Show modal with loading state
    document.getElementById('breakupSupplierName').textContent = supplierName;
    document.getElementById('breakupLoading').style.display = 'block';
    document.getElementById('breakupContent').style.display = 'none';
    balanceBreakupModal.show();
    
    try {
        const response = await fetch(`${BASE_URL}api/supplier-balance-breakup.php?id=${supplierId}&_=${Date.now()}`, {
            cache: 'no-store'
        });
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.error || 'Failed to load');
        }
        
        const data = result.data;
        currentBreakupData = { ...data, type: 'supplier' };
        
        // Update summary
        document.getElementById('breakupOpening').textContent = formatCurrency(data.opening_balance);
        document.getElementById('breakupPurchases').textContent = formatCurrency(data.total_purchases);
        document.getElementById('breakupPayments').textContent = formatCurrency(data.total_payments);
        
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
            
            // Opening balance row. A positive supplier balance means we owe
            // them, which is a CREDIT balance, so it belongs in the Credit
            // column. The figure itself is unchanged.
            if (data.opening_balance !== 0) {
                rows += `
                    <tr class="table-secondary">
                        <td>-</td>
                        <td><em>${LANG.opening}</em></td>
                        <td>-</td>
                        <td class="text-end">${data.opening_balance < 0 ? formatCurrency(Math.abs(data.opening_balance)) : '-'}</td>
                        <td class="text-end">${data.opening_balance > 0 ? formatCurrency(data.opening_balance) : '-'}</td>
                        <td class="text-end">${formatCurrency(data.opening_balance)}</td>
                    </tr>
                `;
            }

            // Transaction rows
            data.transactions.forEach(txn => {
                // Rows come from the general ledger, so the true accounting side
                // decides the column: a purchase CREDITS the supplier, a payment
                // DEBITS them. The running balance still follows the payable
                // convention (credit raises it), so no figure changes here.
                const isDebit = (txn.dr_cr || (txn.entry_type === 'debit' ? 'DR' : 'CR')) === 'DR';
                const typeBadge = voucherBadge(txn.type);

                rows += `
                    <tr>
                        <td>${formatDate(txn.transaction_date)}</td>
                        <td>${typeBadge}</td>
                        <td><small>${escapeHtml(txn.reference)}</small></td>
                        <td class="text-end ${isDebit ? 'text-danger' : ''}">${isDebit ? formatCurrency(txn.amount) : '-'}</td>
                        <td class="text-end ${!isDebit ? 'text-success' : ''}">${!isDebit ? formatCurrency(txn.amount) : '-'}</td>
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
// general ledger, so any voucher type can appear (imports and journals included).
const VOUCHER_TYPES = {
    purchase:        ['Purchase', 'bg-info'],
    purchase_import: ['Import',   'bg-primary'],
    payment:         ['Payment',  'bg-warning text-dark'],
    sales:           ['Sale',     'bg-info'],
    receipt:         ['Receipt',  'bg-success'],
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

    // This statement is sent to the supplier, so it carries the company
    // letterhead — banner if one is uploaded (Masters -> Company Settings),
    // name/address/phone otherwise. The popup is written into a blank window
    // and never loads style.css, hence the CSS injected below too.
    const companyLetterhead = <?php echo json_encode(getPrintLetterheadHtml()); ?>;

    let transactionRows = '';
    
    // Opening balance row. A positive supplier balance means we owe them, which
    // is a CREDIT balance, so it belongs in the Credit column. Figure unchanged.
    if (data.opening_balance !== 0) {
        transactionRows += `
            <tr style="background:#f8f9fa;">
                <td>-</td>
                <td><em>${LANG.opening}</em></td>
                <td>-</td>
                <td style="text-align:right;">${data.opening_balance < 0 ? formatCurrency(Math.abs(data.opening_balance)) : '-'}</td>
                <td style="text-align:right;">${data.opening_balance > 0 ? formatCurrency(data.opening_balance) : '-'}</td>
                <td style="text-align:right;">${formatCurrency(data.opening_balance)}</td>
            </tr>
        `;
    }

    // Transaction rows. Column follows the true accounting side; the running
    // balance is the one already computed server-side.
    data.transactions.forEach(txn => {
        const isDebit = (txn.dr_cr || (txn.entry_type === 'debit' ? 'DR' : 'CR')) === 'DR';

        transactionRows += `
            <tr>
                <td>${formatDate(txn.transaction_date)}</td>
                <td>${voucherLabel(txn.type)}</td>
                <td>${txn.reference}</td>
                <td style="text-align:right;color:${isDebit ? 'red' : 'inherit'};">${isDebit ? formatCurrency(txn.amount) : '-'}</td>
                <td style="text-align:right;color:${!isDebit ? 'green' : 'inherit'};">${!isDebit ? formatCurrency(txn.amount) : '-'}</td>
                <td style="text-align:right;font-weight:bold;">${formatCurrency(txn.running_balance)}</td>
            </tr>
        `;
    });
    
    const html = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Balance Breakup - ${data.supplier_name}</title>
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
            <div class="subtitle"><strong>Supplier:</strong> ${data.supplier_name}</div>
            
            <div class="summary">
                <div class="summary-item">
                    <div class="label">Opening Balance</div>
                    <div class="value">${formatCurrency(data.opening_balance)}</div>
                </div>
                <div class="summary-item">
                    <div class="label">(+) Credit</div>
                    <div class="value text-success">${formatCurrency(data.total_purchases)}</div>
                </div>
                <div class="summary-item">
                    <div class="label">(-) Debit</div>
                    <div class="value text-danger">${formatCurrency(data.total_payments)}</div>
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
