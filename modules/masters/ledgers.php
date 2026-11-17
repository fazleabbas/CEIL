<?php
/**
 * Ledgers Management - Online Only
 * Manages cash / bank / expense / purchase / sales / duties ledger accounts.
 * Supplier and customer ledgers are managed via their own master pages.
 */
require_once '../../config/database.php';
require_once '../../includes/functions.php';

$pageTitle = __('ledgers_management');

if (!hasPermission('ledgers', 'view')) {
    setAlert('You do not have permission to access this page', 'danger');
    redirect('');
}

// Currencies for the modal dropdown
$currencies = [];
try {
    $stmt = $conn->prepare("SELECT id, code, name, symbol, is_default FROM currencies WHERE is_active = 1 ORDER BY is_default DESC, code ASC");
    $stmt->execute();
    $currencies = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $currencies = [];
}

require_once '../../includes/header.php';
?>

<div class="row mb-3">
    <div class="col-md-6">
        <h2><i class="bi bi-journal-bookmark"></i> <?php _e('ledgers_management'); ?></h2>
    </div>
    <div class="col-md-6 text-end">
        <?php if (hasPermission('ledgers', 'create')): ?>
        <button class="btn btn-primary" onclick="showCreateModal()">
            <i class="bi bi-plus-circle"></i> <?php _e('add_new_ledger'); ?>
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- Filter / Search -->
<div class="card mb-3">
    <div class="card-body">
        <div class="row g-2">
            <div class="col-md-4">
                <select id="typeFilter" class="form-select" onchange="applyFilters()">
                    <option value=""><?php _e('all_account_types'); ?></option>
                    <option value="cash"><?php _e('lt_cash'); ?></option>
                    <option value="bank"><?php _e('lt_bank'); ?></option>
                    <option value="expense"><?php _e('lt_expense'); ?></option>
                    <option value="purchase"><?php _e('lt_purchase'); ?></option>
                    <option value="sales"><?php _e('lt_sales'); ?></option>
                    <option value="duties"><?php _e('lt_duties'); ?></option>
                </select>
            </div>
            <div class="col-md-6">
                <input type="text" id="searchInput" class="form-control"
                       placeholder="<?php _e('search_ledgers'); ?>"
                       onkeyup="applyFilters()">
            </div>
            <div class="col-md-2">
                <button onclick="clearFilters()" class="btn btn-secondary w-100" id="clearBtn" style="display:none;">
                    <i class="bi bi-x-circle"></i> <?php _e('clear'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Ledgers Table -->
<div class="card">
    <div class="card-body">
        <div id="loadingSpinner" class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden"><?php _e('loading'); ?></span>
            </div>
            <p class="mt-2"><?php _e('loading_ledgers'); ?></p>
        </div>

        <div id="ledgersContainer" style="display:none;">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th><?php _e('sr_no'); ?></th>
                            <th><?php _e('name'); ?></th>
                            <th><?php _e('account_type'); ?></th>
                            <th><?php _e('currency'); ?></th>
                            <th class="text-end"><?php _e('opening_balance'); ?></th>
                            <th class="text-end"><?php _e('current_balance'); ?></th>
                            <th><?php _e('status'); ?></th>
                            <th><?php _e('actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="ledgersTableBody"></tbody>
                </table>
            </div>
        </div>

        <div id="emptyState" style="display:none;" class="text-center py-5">
            <i class="bi bi-journal-bookmark" style="font-size: 4rem; color: #ccc;"></i>
            <h5 class="mt-3"><?php _e('no_ledgers_found'); ?></h5>
            <p class="text-muted"><?php _e('start_adding_ledger'); ?></p>
        </div>
    </div>
</div>

<!-- Create/Edit Modal -->
<div class="modal fade" id="ledgerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="ledgerModalTitle"><?php _e('add_new_ledger'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="ledgerForm">
                    <input type="hidden" id="ledgerId">

                    <div class="mb-3">
                        <label for="ledgerName" class="form-label"><?php _e('ledger_name'); ?> *</label>
                        <input type="text" class="form-control" id="ledgerName" required>
                    </div>

                    <div class="mb-3">
                        <label for="ledgerType" class="form-label"><?php _e('account_type'); ?> *</label>
                        <select class="form-select" id="ledgerType" required>
                            <option value=""><?php _e('select_account_type'); ?></option>
                            <option value="cash"><?php _e('lt_cash'); ?></option>
                            <option value="bank"><?php _e('lt_bank'); ?></option>
                            <option value="expense"><?php _e('lt_expense'); ?></option>
                            <option value="purchase"><?php _e('lt_purchase'); ?></option>
                            <option value="sales"><?php _e('lt_sales'); ?></option>
                            <option value="duties"><?php _e('lt_duties'); ?></option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="ledgerCurrency" class="form-label"><?php _e('currency'); ?></label>
                        <select class="form-select" id="ledgerCurrency">
                            <?php foreach ($currencies as $cur): ?>
                            <option value="<?php echo (int)$cur['id']; ?>" <?php echo $cur['is_default'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cur['code'] . ' - ' . $cur['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="ledgerOpening" class="form-label"><?php _e('opening_balance'); ?></label>
                        <input type="number" step="0.01" class="form-control" id="ledgerOpening" value="0.00">
                    </div>

                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="ledgerActive" checked>
                        <label class="form-check-label" for="ledgerActive"><?php _e('active'); ?></label>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                <button type="button" class="btn btn-primary" onclick="saveLedger()">
                    <i class="bi bi-check-circle"></i> <?php _e('save'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
const LANG = {
    add_new_ledger: <?php echo json_encode(__('add_new_ledger')); ?>,
    edit_ledger: <?php echo json_encode(__('edit_ledger')); ?>,
    enter_ledger_name: <?php echo json_encode(__('enter_ledger_name')); ?>,
    select_account_type: <?php echo json_encode(__('select_account_type')); ?>,
    confirm_delete_ledger: <?php echo json_encode(__('confirm_delete_ledger')); ?>,
    active: <?php echo json_encode(__('active')); ?>,
    inactive: <?php echo json_encode(__('inactive')); ?>,
    edit: <?php echo json_encode(__('edit')); ?>,
    delete: <?php echo json_encode(__('delete')); ?>,
    types: {
        cash: <?php echo json_encode(__('lt_cash')); ?>,
        bank: <?php echo json_encode(__('lt_bank')); ?>,
        expense: <?php echo json_encode(__('lt_expense')); ?>,
        purchase: <?php echo json_encode(__('lt_purchase')); ?>,
        sales: <?php echo json_encode(__('lt_sales')); ?>,
        duties: <?php echo json_encode(__('lt_duties')); ?>
    }
};

const TYPE_BADGE_CLASS = {
    cash: 'bg-success',
    bank: 'bg-primary',
    expense: 'bg-warning text-dark',
    purchase: 'bg-info text-dark',
    sales: 'bg-secondary',
    duties: 'bg-dark'
};

let ledgersData = [];
let ledgerModal;
let typeFilterValue = '';
let searchQuery = '';

document.addEventListener('DOMContentLoaded', async function() {
    ledgerModal = new bootstrap.Modal(document.getElementById('ledgerModal'));
    await loadLedgers();

    document.getElementById('ledgerForm').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); saveLedger(); }
    });
});

async function loadLedgers() {
    document.getElementById('loadingSpinner').style.display = 'block';
    document.getElementById('ledgersContainer').style.display = 'none';
    document.getElementById('emptyState').style.display = 'none';

    try {
        const response = await fetch(`${BASE_URL}api/ledgers.php?_=${Date.now()}`, {
            method: 'GET',
            headers: { 'Cache-Control': 'no-cache' },
            cache: 'no-store'
        });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);

        const result = await response.json();
        if (!result.success) throw new Error(result.error || 'Failed to load ledgers');

        ledgersData = result.ledgers || [];
        displayLedgers();

    } catch (error) {
        console.error('Load failed:', error);
        showToast('Failed to load ledgers: ' + error.message, 'danger');
    } finally {
        document.getElementById('loadingSpinner').style.display = 'none';
    }
}

function fmtAmount(v) {
    const n = Number(v || 0);
    return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function displayLedgers() {
    const tbody = document.getElementById('ledgersTableBody');

    let filtered = ledgersData;
    if (typeFilterValue) {
        filtered = filtered.filter(l => l.account_type === typeFilterValue);
    }
    if (searchQuery) {
        const q = searchQuery.toLowerCase();
        filtered = filtered.filter(l => l.name.toLowerCase().includes(q));
    }

    if (filtered.length === 0) {
        document.getElementById('emptyState').style.display = 'block';
        document.getElementById('ledgersContainer').style.display = 'none';
        return;
    }

    document.getElementById('emptyState').style.display = 'none';
    document.getElementById('ledgersContainer').style.display = 'block';

    tbody.innerHTML = filtered.map((l, index) => {
        const badgeClass = TYPE_BADGE_CLASS[l.account_type] || 'bg-secondary';
        const typeLabel = LANG.types[l.account_type] || l.account_type;
        const inUse = (parseInt(l.transaction_count) || 0) > 0;
        return `
            <tr>
                <td>${index + 1}</td>
                <td><strong>${escapeHtml(l.name)}</strong></td>
                <td><span class="badge ${badgeClass}">${escapeHtml(typeLabel)}</span></td>
                <td>${escapeHtml(l.currency_code || '')}</td>
                <td class="text-end">${fmtAmount(l.opening_balance)}</td>
                <td class="text-end">${fmtAmount(l.current_balance)}</td>
                <td>${l.is_active == 1
                    ? '<span class="badge bg-success">' + LANG.active + '</span>'
                    : '<span class="badge bg-secondary">' + LANG.inactive + '</span>'}</td>
                <td>
                    <div class="btn-group btn-group-sm">
                        <?php if (hasPermission('ledgers', 'edit')): ?>
                        <button class="btn btn-outline-primary" onclick="showEditModal(${l.id})" title="${LANG.edit}">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <?php endif; ?>
                        <?php if (hasPermission('ledgers', 'delete')): ?>
                        <button class="btn btn-outline-danger" onclick="deleteLedger(${l.id})" title="${LANG.delete}" ${inUse ? 'disabled' : ''}>
                            <i class="bi bi-trash"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>`;
    }).join('');
}

function showCreateModal() {
    document.getElementById('ledgerModalTitle').textContent = LANG.add_new_ledger;
    document.getElementById('ledgerForm').reset();
    document.getElementById('ledgerId').value = '';
    document.getElementById('ledgerOpening').value = '0.00';
    document.getElementById('ledgerActive').checked = true;
    // Reselect default currency
    const sel = document.getElementById('ledgerCurrency');
    for (const opt of sel.options) {
        if (opt.defaultSelected) { sel.value = opt.value; break; }
    }
    ledgerModal.show();
}

function showEditModal(idParam) {
    const id = parseInt(idParam);
    const l = ledgersData.find(x => parseInt(x.id) === id);
    if (!l) return;

    document.getElementById('ledgerModalTitle').textContent = LANG.edit_ledger;
    document.getElementById('ledgerId').value = l.id;
    document.getElementById('ledgerName').value = l.name;
    document.getElementById('ledgerType').value = l.account_type;
    document.getElementById('ledgerCurrency').value = l.currency_id || '';
    document.getElementById('ledgerOpening').value = parseFloat(l.opening_balance || 0).toFixed(2);
    document.getElementById('ledgerActive').checked = l.is_active == 1;

    ledgerModal.show();
}

async function saveLedger() {
    const idValue = document.getElementById('ledgerId').value;
    const id = idValue ? parseInt(idValue) : null;
    const name = document.getElementById('ledgerName').value.trim();
    const account_type = document.getElementById('ledgerType').value;
    const currency_id = document.getElementById('ledgerCurrency').value || null;
    const opening_balance = parseFloat(document.getElementById('ledgerOpening').value || 0);
    const is_active = document.getElementById('ledgerActive').checked ? 1 : 0;

    if (!name) { showToast(LANG.enter_ledger_name, 'warning'); return; }
    if (!account_type) { showToast(LANG.select_account_type, 'warning'); return; }

    const data = { name, account_type, currency_id, opening_balance, is_active };
    if (id) data.id = id;

    try {
        const response = await fetch(`${BASE_URL}api/ledgers-sync.php?_=${Date.now()}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Cache-Control': 'no-cache' },
            body: JSON.stringify({ action: id ? 'update' : 'create', data })
        });
        const result = await response.json();
        if (!result.success) throw new Error(result.error || 'Failed to save');

        showToast(result.message, 'success');
        ledgerModal.hide();
        await loadLedgers();
    } catch (error) {
        console.error('Save failed:', error);
        showToast(error.message, 'danger');
    }
}

async function deleteLedger(idParam) {
    const id = parseInt(idParam);
    const l = ledgersData.find(x => parseInt(x.id) === id);
    if (!l) return;

    if (!confirm(LANG.confirm_delete_ledger.replace('%s', l.name))) return;

    try {
        const response = await fetch(`${BASE_URL}api/ledgers-sync.php?_=${Date.now()}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Cache-Control': 'no-cache' },
            body: JSON.stringify({ action: 'delete', data: { id } })
        });
        const result = await response.json();
        if (!result.success) throw new Error(result.error || 'Failed to delete');

        showToast(result.message, 'success');
        ledgersData = ledgersData.filter(x => parseInt(x.id) !== id);
        displayLedgers();
    } catch (error) {
        console.error('Delete failed:', error);
        showToast(error.message, 'danger');
    }
}

function applyFilters() {
    typeFilterValue = document.getElementById('typeFilter').value;
    searchQuery = document.getElementById('searchInput').value.trim();
    document.getElementById('clearBtn').style.display = (typeFilterValue || searchQuery) ? 'block' : 'none';
    displayLedgers();
}

function clearFilters() {
    document.getElementById('typeFilter').value = '';
    document.getElementById('searchInput').value = '';
    typeFilterValue = '';
    searchQuery = '';
    document.getElementById('clearBtn').style.display = 'none';
    displayLedgers();
}

function showToast(message, type = 'info') {
    document.querySelectorAll('.ledger-toast').forEach(t => t.remove());
    const toast = document.createElement('div');
    toast.className = `ledger-toast alert alert-${type} alert-dismissible fade show`;
    toast.style.cssText = `position: fixed; top: 80px; right: 20px; z-index: 9999; min-width: 300px; box-shadow: 0 4px 6px rgba(0,0,0,0.2);`;
    toast.innerHTML = `${message}<button type="button" class="btn-close" data-bs-dismiss="alert"></button>`;
    document.body.appendChild(toast);
    setTimeout(() => { toast.classList.remove('show'); setTimeout(() => toast.remove(), 150); }, 5000);
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text == null ? '' : text;
    return div.innerHTML;
}
</script>

<?php require_once '../../includes/footer.php'; ?>
