<?php
/**
 * Exchange Rates Management - Online Only
 * Simple AJAX-based CRUD, no offline sync
 */
require_once '../../config/database.php';
require_once '../../includes/functions.php';

$pageTitle = __('exchange_rates_management');

if (!hasPermission('exchange_rates', 'view')) {
    setAlert('You do not have permission to access this page', 'danger');
    redirect('');
}

require_once '../../includes/header.php';
?>

<div class="row mb-3">
    <div class="col-md-6">
        <h2><i class="bi bi-graph-up-arrow"></i> <?php _e('exchange_rates_management'); ?></h2>
    </div>
    <div class="col-md-6 text-end">
        <?php if (hasPermission('exchange_rates', 'create')): ?>
        <button class="btn btn-primary" onclick="showCreateModal()">
            <i class="bi bi-plus-circle"></i> <?php _e('add_new_exchange_rate'); ?>
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- Filter Bar -->
<div class="card mb-3">
    <div class="card-body">
        <div class="row g-2">
            <div class="col-md-4">
                <label class="form-label"><?php _e('from_currency'); ?></label>
                <select id="filterFromCurrency" class="form-select" onchange="loadExchangeRates()">
                    <option value=""><?php _e('all_currencies'); ?></option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?php _e('to_currency'); ?></label>
                <select id="filterToCurrency" class="form-select" onchange="loadExchangeRates()">
                    <option value=""><?php _e('all_currencies'); ?></option>
                </select>
            </div>
            <div class="col-md-4 d-flex align-items-end">
                <button onclick="clearFilters()" class="btn btn-secondary w-100">
                    <i class="bi bi-x-circle"></i> <?php _e('clear'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Exchange Rates Table -->
<div class="card">
    <div class="card-body">
        <div id="loadingSpinner" class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden"><?php _e('loading'); ?></span>
            </div>
            <p class="mt-2"><?php _e('loading_exchange_rates'); ?></p>
        </div>

        <div id="ratesContainer" style="display:none;">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th><?php _e('sr_no'); ?></th>
                            <th><?php _e('from_currency'); ?></th>
                            <th><?php _e('to_currency'); ?></th>
                            <th><?php _e('rate'); ?></th>
                            <th><?php _e('effective_date'); ?></th>
                            <th><?php _e('created_by'); ?></th>
                            <th><?php _e('actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="ratesTableBody">
                        <!-- Populated by JavaScript -->
                    </tbody>
                </table>
            </div>
        </div>

        <div id="emptyState" style="display:none;" class="text-center py-5">
            <i class="bi bi-graph-up-arrow" style="font-size: 4rem; color: #ccc;"></i>
            <h5 class="mt-3"><?php _e('no_exchange_rates_found'); ?></h5>
            <p class="text-muted"><?php _e('start_adding_exchange_rate'); ?></p>
        </div>
    </div>
</div>

<!-- Create/Edit Modal -->
<div class="modal fade" id="rateModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="rateModalTitle"><?php _e('add_new_exchange_rate'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="rateForm">
                    <input type="hidden" id="rateId">

                    <div class="mb-3">
                        <label for="fromCurrencyId" class="form-label"><?php _e('from_currency'); ?> *</label>
                        <select class="form-select" id="fromCurrencyId" required>
                            <option value=""><?php _e('select_currency'); ?></option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="toCurrencyId" class="form-label"><?php _e('to_currency'); ?> *</label>
                        <select class="form-select" id="toCurrencyId" required>
                            <option value=""><?php _e('select_currency'); ?></option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="rateValue" class="form-label"><?php _e('rate'); ?> *</label>
                        <input type="number" class="form-control" id="rateValue" step="0.00000001" min="0.00000001" required>
                        <small class="text-muted" id="rateHint"><?php _e('exchange_rate_hint'); ?></small>
                    </div>

                    <div class="mb-3">
                        <label for="effectiveDate" class="form-label"><?php _e('effective_date'); ?> *</label>
                        <input type="date" class="form-control" id="effectiveDate" required>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                <button type="button" class="btn btn-primary" onclick="saveRate()">
                    <i class="bi bi-check-circle"></i> <?php _e('save_exchange_rate'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Translations for JavaScript
const LANG = {
    add_new_exchange_rate: <?php echo json_encode(__('add_new_exchange_rate')); ?>,
    edit_exchange_rate: <?php echo json_encode(__('edit_exchange_rate')); ?>,
    confirm_delete_exchange_rate: <?php echo json_encode(__('confirm_delete_exchange_rate')); ?>,
    select_from_currency: <?php echo json_encode(__('select_from_currency_msg')); ?>,
    select_to_currency: <?php echo json_encode(__('select_to_currency_msg')); ?>,
    enter_rate: <?php echo json_encode(__('enter_rate_msg')); ?>,
    enter_effective_date: <?php echo json_encode(__('enter_effective_date_msg')); ?>,
    same_currency_error: <?php echo json_encode(__('same_currency_error')); ?>,
    edit: <?php echo json_encode(__('edit')); ?>,
    delete: <?php echo json_encode(__('delete')); ?>,
    rate_hint_template: <?php echo json_encode(__('rate_hint_template')); ?>
};

// Global variables
let ratesData = [];
let currenciesList = [];
let rateModal;

// Initialize
document.addEventListener('DOMContentLoaded', async function() {
    console.log('Exchange rates page loaded');

    // Initialize modal
    rateModal = new bootstrap.Modal(document.getElementById('rateModal'));

    // Set default date to today
    document.getElementById('effectiveDate').value = new Date().toISOString().split('T')[0];

    // Load currencies for dropdowns first, then rates
    await loadCurrencies();
    await loadExchangeRates();

    // Enter key support in modal
    document.getElementById('rateForm').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            saveRate();
        }
    });

    // Update rate hint when currencies change
    document.getElementById('fromCurrencyId').addEventListener('change', updateRateHint);
    document.getElementById('toCurrencyId').addEventListener('change', updateRateHint);
});

// Load currencies for dropdowns
async function loadCurrencies() {
    try {
        const url = `${BASE_URL}api/currencies.php?_=${Date.now()}`;
        const response = await fetch(url, {
            method: 'GET',
            headers: { 'Cache-Control': 'no-cache' },
            cache: 'no-store'
        });

        const result = await response.json();
        if (!result.success) throw new Error(result.error);

        currenciesList = result.currencies;

        // Populate filter dropdowns
        const filterFrom = document.getElementById('filterFromCurrency');
        const filterTo = document.getElementById('filterToCurrency');
        const modalFrom = document.getElementById('fromCurrencyId');
        const modalTo = document.getElementById('toCurrencyId');

        const optionsHtml = currenciesList.map(c =>
            `<option value="${c.id}">${escapeHtml(c.code)} - ${escapeHtml(c.name)}</option>`
        ).join('');

        filterFrom.innerHTML = `<option value="">${escapeHtml(<?php echo json_encode(__('all_currencies')); ?>)}</option>` + optionsHtml;
        filterTo.innerHTML = `<option value="">${escapeHtml(<?php echo json_encode(__('all_currencies')); ?>)}</option>` + optionsHtml;
        modalFrom.innerHTML = `<option value="">${escapeHtml(<?php echo json_encode(__('select_currency')); ?>)}</option>` + optionsHtml;
        modalTo.innerHTML = `<option value="">${escapeHtml(<?php echo json_encode(__('select_currency')); ?>)}</option>` + optionsHtml;

    } catch (error) {
        console.error('Failed to load currencies:', error);
        showToast('Failed to load currencies: ' + error.message, 'danger');
    }
}

// Update rate hint text
function updateRateHint() {
    const fromId = document.getElementById('fromCurrencyId').value;
    const toId = document.getElementById('toCurrencyId').value;
    const hint = document.getElementById('rateHint');

    if (fromId && toId) {
        const fromCur = currenciesList.find(c => c.id == fromId);
        const toCur = currenciesList.find(c => c.id == toId);
        if (fromCur && toCur) {
            hint.textContent = LANG.rate_hint_template
                .replace('%from%', fromCur.code)
                .replace('%to%', toCur.code);
            return;
        }
    }
    hint.textContent = <?php echo json_encode(__('exchange_rate_hint')); ?>;
}

// Load exchange rates from API
async function loadExchangeRates() {
    console.log('Loading exchange rates...');
    document.getElementById('loadingSpinner').style.display = 'block';
    document.getElementById('ratesContainer').style.display = 'none';
    document.getElementById('emptyState').style.display = 'none';

    try {
        let url = `${BASE_URL}api/exchange-rates.php?_=${Date.now()}`;

        const fromFilter = document.getElementById('filterFromCurrency').value;
        const toFilter = document.getElementById('filterToCurrency').value;
        if (fromFilter) url += `&from_currency_id=${fromFilter}`;
        if (toFilter) url += `&to_currency_id=${toFilter}`;

        const response = await fetch(url, {
            method: 'GET',
            headers: { 'Cache-Control': 'no-cache' },
            cache: 'no-store'
        });

        if (!response.ok) throw new Error(`HTTP ${response.status}`);

        const result = await response.json();
        if (!result.success) throw new Error(result.error || 'Failed to load exchange rates');

        ratesData = result.exchange_rates;
        console.log(`Loaded ${ratesData.length} exchange rates`);

        displayRates();

    } catch (error) {
        console.error('Load failed:', error);
        showToast('Failed to load exchange rates: ' + error.message, 'danger');
    } finally {
        document.getElementById('loadingSpinner').style.display = 'none';
    }
}

// Display rates in table
function displayRates() {
    const tbody = document.getElementById('ratesTableBody');

    if (ratesData.length === 0) {
        document.getElementById('emptyState').style.display = 'block';
        document.getElementById('ratesContainer').style.display = 'none';
        return;
    }

    document.getElementById('emptyState').style.display = 'none';
    document.getElementById('ratesContainer').style.display = 'block';

    tbody.innerHTML = ratesData.map((rate, index) => `
        <tr>
            <td>${index + 1}</td>
            <td><strong>${escapeHtml(rate.from_currency_code)}</strong></td>
            <td><strong>${escapeHtml(rate.to_currency_code)}</strong></td>
            <td>${parseFloat(rate.rate).toFixed(8)}</td>
            <td>${escapeHtml(rate.effective_date)}</td>
            <td>${escapeHtml(rate.created_by_name || '-')}</td>
            <td>
                <div class="btn-group btn-group-sm">
                    <?php if (hasPermission('exchange_rates', 'edit')): ?>
                    <button class="btn btn-outline-primary" onclick="showEditModal(${rate.id})" title="${LANG.edit}">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <?php endif; ?>

                    <?php if (hasPermission('exchange_rates', 'delete')): ?>
                    <button class="btn btn-outline-danger" onclick="deleteRate(${rate.id})" title="${LANG.delete}">
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
    document.getElementById('rateModalTitle').textContent = LANG.add_new_exchange_rate;
    document.getElementById('rateForm').reset();
    document.getElementById('rateId').value = '';
    document.getElementById('effectiveDate').value = new Date().toISOString().split('T')[0];
    updateRateHint();
    rateModal.show();
}

// Show edit modal
function showEditModal(idParam) {
    const id = parseInt(idParam);
    const rate = ratesData.find(r => r.id === id);
    if (!rate) return;

    document.getElementById('rateModalTitle').textContent = LANG.edit_exchange_rate;
    document.getElementById('rateId').value = rate.id;
    document.getElementById('fromCurrencyId').value = rate.from_currency_id;
    document.getElementById('toCurrencyId').value = rate.to_currency_id;
    document.getElementById('rateValue').value = rate.rate;
    document.getElementById('effectiveDate').value = rate.effective_date;
    updateRateHint();

    rateModal.show();
}

// Save rate (create or update)
async function saveRate() {
    const idValue = document.getElementById('rateId').value;
    const id = idValue ? parseInt(idValue) : null;
    const from_currency_id = parseInt(document.getElementById('fromCurrencyId').value);
    const to_currency_id = parseInt(document.getElementById('toCurrencyId').value);
    const rate = parseFloat(document.getElementById('rateValue').value);
    const effective_date = document.getElementById('effectiveDate').value;

    if (!from_currency_id) {
        showToast(LANG.select_from_currency, 'warning');
        return;
    }
    if (!to_currency_id) {
        showToast(LANG.select_to_currency, 'warning');
        return;
    }
    if (from_currency_id === to_currency_id) {
        showToast(LANG.same_currency_error, 'warning');
        return;
    }
    if (!rate || rate <= 0) {
        showToast(LANG.enter_rate, 'warning');
        return;
    }
    if (!effective_date) {
        showToast(LANG.enter_effective_date, 'warning');
        return;
    }

    const data = { from_currency_id, to_currency_id, rate, effective_date };
    if (id) {
        data.id = id;
    }

    try {
        const url = `${BASE_URL}api/exchange-rates-sync.php?_=${Date.now()}`;
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
        rateModal.hide();

        await loadExchangeRates();

    } catch (error) {
        console.error('Save failed:', error);
        showToast(error.message, 'danger');
    }
}

// Delete rate
async function deleteRate(idParam) {
    const id = parseInt(idParam);
    const rate = ratesData.find(r => r.id === id);
    if (!rate) return;

    if (!confirm(LANG.confirm_delete_exchange_rate)) {
        return;
    }

    try {
        const url = `${BASE_URL}api/exchange-rates-sync.php?_=${Date.now()}`;

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
        ratesData = ratesData.filter(r => r.id !== id);
        displayRates();

    } catch (error) {
        console.error('Delete failed:', error);
        showToast(error.message, 'danger');
    }
}

// Clear filters
function clearFilters() {
    document.getElementById('filterFromCurrency').value = '';
    document.getElementById('filterToCurrency').value = '';
    loadExchangeRates();
}

// Show toast notification
function showToast(message, type = 'info') {
    document.querySelectorAll('.rate-toast').forEach(t => t.remove());

    const toast = document.createElement('div');
    toast.className = `rate-toast alert alert-${type} alert-dismissible fade show`;
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
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

<?php require_once '../../includes/footer.php'; ?>
