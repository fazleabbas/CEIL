<?php
/**
 * Currencies Management - Online Only
 * Simple AJAX-based CRUD, no offline sync
 */
require_once '../../config/database.php';
require_once '../../includes/functions.php';

$pageTitle = __('currencies_management');

if (!hasPermission('currencies', 'view')) {
    setAlert('You do not have permission to access this page', 'danger');
    redirect('');
}

require_once '../../includes/header.php';
?>

<div class="row mb-3">
    <div class="col-md-6">
        <h2><i class="bi bi-currency-exchange"></i> <?php _e('currencies_management'); ?></h2>
    </div>
    <div class="col-md-6 text-end">
        <?php if (hasPermission('currencies', 'create')): ?>
        <button class="btn btn-primary" onclick="showCreateModal()">
            <i class="bi bi-plus-circle"></i> <?php _e('add_new_currency'); ?>
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
                       placeholder="<?php _e('search_currencies'); ?>"
                       onkeyup="searchCurrencies()">
            </div>
            <div class="col-md-2">
                <button onclick="clearSearch()" class="btn btn-secondary w-100" id="clearBtn" style="display:none;">
                    <i class="bi bi-x-circle"></i> <?php _e('clear'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Currencies Table -->
<div class="card">
    <div class="card-body">
        <div id="loadingSpinner" class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden"><?php _e('loading'); ?></span>
            </div>
            <p class="mt-2"><?php _e('loading_currencies'); ?></p>
        </div>

        <div id="currenciesContainer" style="display:none;">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th><?php _e('sr_no'); ?></th>
                            <th><?php _e('currency_code'); ?></th>
                            <th><?php _e('name'); ?></th>
                            <th><?php _e('symbol'); ?></th>
                            <th><?php _e('decimal_places'); ?></th>
                            <th><?php _e('default'); ?></th>
                            <th><?php _e('status'); ?></th>
                            <th><?php _e('actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="currenciesTableBody">
                        <!-- Populated by JavaScript -->
                    </tbody>
                </table>
            </div>
        </div>

        <div id="emptyState" style="display:none;" class="text-center py-5">
            <i class="bi bi-currency-exchange" style="font-size: 4rem; color: #ccc;"></i>
            <h5 class="mt-3"><?php _e('no_currencies_found'); ?></h5>
            <p class="text-muted"><?php _e('start_adding_currency'); ?></p>
        </div>
    </div>
</div>

<!-- Create/Edit Modal -->
<div class="modal fade" id="currencyModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="currencyModalTitle"><?php _e('add_new_currency'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="currencyForm">
                    <input type="hidden" id="currencyId">

                    <div class="mb-3">
                        <label for="currencyCode" class="form-label"><?php _e('currency_code'); ?> *</label>
                        <input type="text" class="form-control" id="currencyCode" maxlength="3" required
                               style="text-transform: uppercase;">
                        <small class="text-muted"><?php _e('currency_code_hint'); ?></small>
                    </div>

                    <div class="mb-3">
                        <label for="currencyName" class="form-label"><?php _e('currency_name'); ?> *</label>
                        <input type="text" class="form-control" id="currencyName" required>
                        <small class="text-muted"><?php _e('currency_name_hint'); ?></small>
                    </div>

                    <div class="mb-3">
                        <label for="currencySymbol" class="form-label"><?php _e('symbol'); ?> *</label>
                        <input type="text" class="form-control" id="currencySymbol" maxlength="10" required>
                        <small class="text-muted"><?php _e('currency_symbol_hint'); ?></small>
                    </div>

                    <div class="mb-3">
                        <label for="currencyDecimalPlaces" class="form-label"><?php _e('decimal_places'); ?></label>
                        <select class="form-select" id="currencyDecimalPlaces">
                            <option value="0">0</option>
                            <option value="2" selected>2</option>
                            <option value="3">3</option>
                        </select>
                    </div>

                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="currencyDefault">
                        <label class="form-check-label" for="currencyDefault"><?php _e('set_as_default'); ?></label>
                    </div>

                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="currencyActive" checked>
                        <label class="form-check-label" for="currencyActive"><?php _e('active'); ?></label>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                <button type="button" class="btn btn-primary" onclick="saveCurrency()">
                    <i class="bi bi-check-circle"></i> <?php _e('save_currency'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Translations for JavaScript
const LANG = {
    add_new_currency: <?php echo json_encode(__('add_new_currency')); ?>,
    edit_currency: <?php echo json_encode(__('edit_currency')); ?>,
    enter_currency_code: <?php echo json_encode(__('enter_currency_code')); ?>,
    enter_currency_name: <?php echo json_encode(__('enter_currency_name')); ?>,
    enter_currency_symbol: <?php echo json_encode(__('enter_currency_symbol')); ?>,
    confirm_delete_currency: <?php echo json_encode(__('confirm_delete_currency')); ?>,
    currency_in_use: <?php echo json_encode(__('currency_in_use')); ?>,
    active: <?php echo json_encode(__('active')); ?>,
    inactive: <?php echo json_encode(__('inactive')); ?>,
    default_label: <?php echo json_encode(__('default_label')); ?>,
    edit: <?php echo json_encode(__('edit')); ?>,
    delete: <?php echo json_encode(__('delete')); ?>,
    set_as_default: <?php echo json_encode(__('set_as_default')); ?>
};

// Global variables
let currenciesData = [];
let currencyModal;
let searchQuery = '';

// Initialize
document.addEventListener('DOMContentLoaded', async function() {
    console.log('Currencies page loaded');

    // Initialize modal
    currencyModal = new bootstrap.Modal(document.getElementById('currencyModal'));

    // Load currencies
    await loadCurrencies();

    // Enter key support in modal
    document.getElementById('currencyForm').addEventListener('keypress', function(e) {
        if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') {
            e.preventDefault();
            saveCurrency();
        }
    });
});

// Load currencies from API
async function loadCurrencies() {
    console.log('Loading currencies...');
    document.getElementById('loadingSpinner').style.display = 'block';
    document.getElementById('currenciesContainer').style.display = 'none';
    document.getElementById('emptyState').style.display = 'none';

    try {
        const url = `${BASE_URL}api/currencies.php?_=${Date.now()}`;

        const response = await fetch(url, {
            method: 'GET',
            headers: {
                'Cache-Control': 'no-cache, no-store, must-revalidate',
                'Pragma': 'no-cache'
            },
            cache: 'no-store'
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const result = await response.json();
        console.log('Server response:', result);

        if (!result.success) {
            throw new Error(result.error || 'Failed to load currencies');
        }

        currenciesData = result.currencies;
        console.log(`Loaded ${currenciesData.length} currencies`);

        displayCurrencies();

    } catch (error) {
        console.error('Load failed:', error);
        showToast('Failed to load currencies: ' + error.message, 'danger');
    } finally {
        document.getElementById('loadingSpinner').style.display = 'none';
    }
}

// Display currencies in table
function displayCurrencies() {
    const tbody = document.getElementById('currenciesTableBody');

    // Filter by search query
    let filtered = currenciesData;
    if (searchQuery) {
        const query = searchQuery.toLowerCase();
        filtered = currenciesData.filter(cur =>
            cur.code.toLowerCase().includes(query) ||
            cur.name.toLowerCase().includes(query) ||
            cur.symbol.toLowerCase().includes(query)
        );
    }

    if (filtered.length === 0) {
        document.getElementById('emptyState').style.display = 'block';
        document.getElementById('currenciesContainer').style.display = 'none';
        return;
    }

    document.getElementById('emptyState').style.display = 'none';
    document.getElementById('currenciesContainer').style.display = 'block';

    tbody.innerHTML = filtered.map((currency, index) => `
        <tr>
            <td>${index + 1}</td>
            <td><strong>${escapeHtml(currency.code)}</strong></td>
            <td>${escapeHtml(currency.name)}</td>
            <td>${escapeHtml(currency.symbol)}</td>
            <td>${currency.decimal_places}</td>
            <td>
                ${currency.is_default == 1 ?
                    '<span class="badge bg-warning text-dark"><i class="bi bi-star-fill"></i> ' + LANG.default_label + '</span>' :
                    '<?php if (hasPermission("currencies", "edit")): ?><button class="btn btn-outline-warning btn-sm" onclick="setDefault(' + currency.id + ')" title="' + LANG.set_as_default + '"><i class="bi bi-star"></i></button><?php endif; ?>'}
            </td>
            <td>
                ${currency.is_active == 1 ?
                    '<span class="badge bg-success">' + LANG.active + '</span>' :
                    '<span class="badge bg-secondary">' + LANG.inactive + '</span>'}
            </td>
            <td>
                <div class="btn-group btn-group-sm">
                    <?php if (hasPermission('currencies', 'edit')): ?>
                    <button class="btn btn-outline-primary" onclick="showEditModal(${currency.id})" title="${LANG.edit}">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <?php endif; ?>

                    <?php if (hasPermission('currencies', 'delete')): ?>
                    <button class="btn btn-outline-danger" onclick="deleteCurrency(${currency.id})" title="${LANG.delete}">
                        <i class="bi bi-trash"></i>
                    </button>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
    `).join('');
}

// Show create modal
function showCreateModal() {
    document.getElementById('currencyModalTitle').textContent = LANG.add_new_currency;
    document.getElementById('currencyForm').reset();
    document.getElementById('currencyId').value = '';
    document.getElementById('currencyActive').checked = true;
    document.getElementById('currencyDefault').checked = false;
    document.getElementById('currencyDecimalPlaces').value = '2';
    currencyModal.show();
}

// Show edit modal
function showEditModal(idParam) {
    const id = parseInt(idParam);
    const currency = currenciesData.find(c => c.id === id);
    if (!currency) return;

    document.getElementById('currencyModalTitle').textContent = LANG.edit_currency;
    document.getElementById('currencyId').value = currency.id;
    document.getElementById('currencyCode').value = currency.code;
    document.getElementById('currencyName').value = currency.name;
    document.getElementById('currencySymbol').value = currency.symbol;
    document.getElementById('currencyDecimalPlaces').value = currency.decimal_places;
    document.getElementById('currencyDefault').checked = currency.is_default == 1;
    document.getElementById('currencyActive').checked = currency.is_active == 1;

    currencyModal.show();
}

// Save currency (create or update)
async function saveCurrency() {
    const idValue = document.getElementById('currencyId').value;
    const id = idValue ? parseInt(idValue) : null;
    const code = document.getElementById('currencyCode').value.trim().toUpperCase();
    const name = document.getElementById('currencyName').value.trim();
    const symbol = document.getElementById('currencySymbol').value.trim();
    const decimal_places = parseInt(document.getElementById('currencyDecimalPlaces').value);
    const is_default = document.getElementById('currencyDefault').checked ? 1 : 0;
    const is_active = document.getElementById('currencyActive').checked ? 1 : 0;

    if (!code || code.length !== 3) {
        showToast(LANG.enter_currency_code, 'warning');
        return;
    }

    if (!name) {
        showToast(LANG.enter_currency_name, 'warning');
        return;
    }

    if (!symbol) {
        showToast(LANG.enter_currency_symbol, 'warning');
        return;
    }

    const data = { code, name, symbol, decimal_places, is_default, is_active };
    if (id) {
        data.id = id;
    }

    try {
        const url = `${BASE_URL}api/currencies-sync.php?_=${Date.now()}`;
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
        console.log('Save response:', result);

        if (!result.success) {
            throw new Error(result.error || 'Failed to save');
        }

        showToast(result.message, 'success');
        currencyModal.hide();

        // Reload to get fresh data
        await loadCurrencies();

    } catch (error) {
        console.error('Save failed:', error);
        showToast(error.message, 'danger');
    }
}

// Set as default currency
async function setDefault(idParam) {
    const id = parseInt(idParam);
    const currency = currenciesData.find(c => c.id === id);
    if (!currency) return;

    try {
        const url = `${BASE_URL}api/currencies-sync.php?_=${Date.now()}`;

        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Cache-Control': 'no-cache'
            },
            cache: 'no-store',
            body: JSON.stringify({
                action: 'set_default',
                data: { id }
            })
        });

        const result = await response.json();
        console.log('Set default response:', result);

        if (!result.success) {
            throw new Error(result.error || 'Failed to set default');
        }

        showToast(result.message, 'success');
        await loadCurrencies();

    } catch (error) {
        console.error('Set default failed:', error);
        showToast(error.message, 'danger');
    }
}

// Delete currency
async function deleteCurrency(idParam) {
    const id = parseInt(idParam);
    const currency = currenciesData.find(c => c.id === id);
    if (!currency) return;

    let message = LANG.confirm_delete_currency.replace('%s', currency.name);

    if (!confirm(message)) {
        return;
    }

    try {
        const url = `${BASE_URL}api/currencies-sync.php?_=${Date.now()}`;

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
        console.log('Delete response:', result);

        if (!result.success) {
            throw new Error(result.error || 'Failed to delete');
        }

        showToast(result.message, 'success');

        // Remove from array
        currenciesData = currenciesData.filter(c => c.id !== id);
        displayCurrencies();

    } catch (error) {
        console.error('Delete failed:', error);
        showToast(error.message, 'danger');
    }
}

// Search currencies
function searchCurrencies() {
    searchQuery = document.getElementById('searchInput').value.trim();
    document.getElementById('clearBtn').style.display = searchQuery ? 'block' : 'none';
    displayCurrencies();
}

// Clear search
function clearSearch() {
    document.getElementById('searchInput').value = '';
    searchQuery = '';
    document.getElementById('clearBtn').style.display = 'none';
    displayCurrencies();
}

// Show toast notification
function showToast(message, type = 'info') {
    // Remove existing toasts
    document.querySelectorAll('.currency-toast').forEach(t => t.remove());

    const toast = document.createElement('div');
    toast.className = `currency-toast alert alert-${type} alert-dismissible fade show`;
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
</script>

<?php require_once '../../includes/footer.php'; ?>
