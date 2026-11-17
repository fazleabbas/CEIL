<?php
/**
 * Journal Vouchers — read-only listing.
 *
 * Currently the only source of journal vouchers is Bucket B (third-party)
 * cost lines on Purchase Imports. Each row in this list links back to its
 * source import. Future source types (manual JVs, expense vouchers, etc.)
 * will appear here too once they're added.
 *
 * Edits flow through the parent voucher — saving a purchase import rebuilds
 * all of its JVs. No create/edit/delete from this screen.
 */
require_once '../../config/database.php';
require_once '../../includes/functions.php';

$pageTitle = 'Journal Vouchers';

if (!hasPermission('journal_vouchers', 'view')) {
    setAlert('You do not have permission to access this page', 'danger');
    redirect('');
}

// Manual JV capabilities + form data (auto-generated JVs remain read-only).
$canCreateJV = hasPermission('journal_vouchers', 'create');
$canEditJV   = hasPermission('journal_vouchers', 'edit');
$canDeleteJV = hasPermission('journal_vouchers', 'delete');
$canManageJV = $canCreateJV || $canEditJV || $canDeleteJV;

$jvCurrencies = $conn->query(
    "SELECT id, code, symbol, is_default FROM currencies WHERE is_active = 1 ORDER BY is_default DESC, code ASC"
)->fetchAll(PDO::FETCH_ASSOC);
$jvCostCentreOptions = renderTreeOptions(getUserCostCentres(), null, null, 'name', 'code');

require_once '../../includes/header.php';
?>

<div class="row mb-3">
    <div class="col-md-6">
        <h2><i class="bi bi-journal-text"></i> Journal Vouchers</h2>
        <small class="text-muted">Auto-generated from purchase imports (one per third-party cost line). Edit the parent import to change those. Manual journal vouchers can be added directly.</small>
    </div>
    <?php if ($canCreateJV): ?>
    <div class="col-md-6 text-end d-flex align-items-start justify-content-end">
        <button class="btn btn-primary" onclick="openCreateVoucher()">
            <i class="bi bi-plus-circle"></i> New Journal Voucher
        </button>
    </div>
    <?php endif; ?>
</div>

<!-- Search and Filter -->
<div class="card mb-3">
    <div class="card-body">
        <div class="row g-2">
            <div class="col-md-3">
                <label class="form-label small mb-1">From Date</label>
                <input type="date" id="dateFrom" class="form-control">
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">To Date</label>
                <input type="date" id="dateTo" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label small mb-1">Search</label>
                <input type="text" id="searchInput" class="form-control"
                       placeholder="JV#, ledger, beneficiary, source…" onkeyup="filterVouchers()">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button onclick="clearFilters()" class="btn btn-secondary w-100">
                    <i class="bi bi-x-circle"></i> Clear
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Vouchers Table -->
<div class="card">
    <div class="card-body">
        <div id="loadingSpinner" class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-2">Loading journal vouchers...</p>
        </div>

        <div id="vouchersContainer" style="display:none;">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <span id="voucherCount" class="text-muted">0 vouchers</span>
                <span id="totalAmount" class="fw-bold text-primary">Total (base): 0.00</span>
            </div>

            <div class="table-responsive">
                <table class="table table-hover table-striped">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 110px;">JV #</th>
                            <th style="width: 100px;">Date</th>
                            <th>Narration</th>
                            <th>DR Ledger</th>
                            <th>CR Beneficiary</th>
                            <th class="text-end" style="width: 140px;">Amount</th>
                            <th class="text-end" style="width: 100px;">VAT</th>
                            <th style="width: 130px;">Source</th>
                            <th style="width: 80px;" class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="vouchersTableBody">
                    </tbody>
                </table>
            </div>
        </div>

        <div id="emptyState" style="display:none;" class="text-center py-5">
            <i class="bi bi-inbox" style="font-size: 3rem; color: #ccc;"></i>
            <p class="text-muted mt-3">No journal vouchers in the selected period</p>
        </div>
    </div>
</div>

<!-- View Voucher Modal -->
<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="bi bi-eye"></i> Journal Voucher Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-md-3">
                        <strong>JV #:</strong><br>
                        <span id="viewVoucherNumber" class="fs-5 text-primary"></span>
                    </div>
                    <div class="col-md-3">
                        <strong><i class="bi bi-calendar"></i> Date:</strong><br>
                        <span id="viewDate"></span>
                    </div>
                    <div class="col-md-3" id="viewSourceRow" style="display:none;">
                        <strong><i class="bi bi-link-45deg"></i> Source:</strong><br>
                        <a id="viewSourceLink" href="#"></a>
                    </div>
                    <div class="col-md-3" id="viewCostCentreRow" style="display:none;">
                        <strong><i class="bi bi-building"></i> Cost Centre:</strong><br>
                        <span id="viewCostCentre"></span>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-12">
                        <strong><i class="bi bi-sticky"></i> Narration:</strong><br>
                        <span id="viewNarration" class="text-muted"></span>
                    </div>
                </div>

                <hr>

                <h6 class="mb-3"><i class="bi bi-list-ul"></i> Details (DR / CR)</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th>DR/CR</th>
                                <th>Ledger</th>
                                <th class="text-center">Currency</th>
                                <th class="text-end">FX Rate</th>
                                <th class="text-end">Amount</th>
                                <th class="text-end">Amount (Base)</th>
                            </tr>
                        </thead>
                        <tbody id="viewDetailsBody"></tbody>
                        <tfoot>
                            <tr class="table-active fw-bold">
                                <td colspan="5" class="text-end">Total DR / CR (base):</td>
                                <td class="text-end" id="viewDetailsTotal"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="row mt-3">
                    <div class="col-md-6">
                        <small class="text-muted">
                            <i class="bi bi-person"></i> Created by: <span id="viewCreatedBy"></span>
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

<?php if ($canManageJV): ?>
<style>
/* Keep the Select2-ised ledger dropdowns the same size as form-select-sm
   so the JV line rows don't grow taller. Scoped to the lines table. */
#editLinesBody .select2-container--default .select2-selection--single {
    height: calc(1.5em + 0.5rem + 2px);
    border: 1px solid #dee2e6;
    border-radius: 0.25rem;
}
#editLinesBody .select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: calc(1.5em + 0.5rem);
    padding-left: 0.5rem;
    font-size: 0.875rem;
}
#editLinesBody .select2-container--default .select2-selection--single .select2-selection__arrow,
#editLinesBody .select2-container--default .select2-selection--single .select2-selection__clear {
    height: calc(1.5em + 0.5rem);
}
</style>
<!-- Create / Edit Voucher Modal -->
<div class="modal fade" id="editModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-pencil-square"></i> <span id="editModalTitle">New Journal Voucher</span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editVoucherId">
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label class="form-label small mb-1">Date <span class="text-danger">*</span></label>
                        <input type="date" id="editDate" class="form-control">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-1">Currency</label>
                        <select id="editCurrency" class="form-select" onchange="onEditCurrencyChange()">
                            <?php foreach ($jvCurrencies as $c): ?>
                            <option value="<?php echo (int)$c['id']; ?>"
                                    data-symbol="<?php echo htmlspecialchars($c['symbol']); ?>"
                                    data-default="<?php echo (int)$c['is_default']; ?>"
                                    <?php echo $c['is_default'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['code']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1">FX Rate → Base</label>
                        <input type="number" id="editFxRate" class="form-control" value="1" min="0.00000001" step="0.00000001" onchange="recalcBalance()" onkeyup="recalcBalance()">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small mb-1">Cost Centre <span class="text-muted">(optional)</span></label>
                        <select id="editCostCentre" class="form-select">
                            <option value="">— None —</option>
                            <?php echo $jvCostCentreOptions; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-1">Narration</label>
                        <input type="text" id="editNarration" class="form-control" placeholder="Describe the journal entry…">
                    </div>
                </div>

                <h6 class="mb-2"><i class="bi bi-list-ul"></i> Lines (DR / CR)</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 110px;">DR / CR</th>
                                <th>Ledger</th>
                                <th class="text-end" style="width: 180px;">Amount</th>
                                <th class="text-end" style="width: 180px;">Amount (Base)</th>
                                <th style="width: 50px;"></th>
                            </tr>
                        </thead>
                        <tbody id="editLinesBody"></tbody>
                        <tfoot>
                            <tr class="table-active fw-bold">
                                <td class="text-end" colspan="2">Totals (base):</td>
                                <td class="text-end">DR <span id="editTotalDr">0.00</span></td>
                                <td class="text-end">CR <span id="editTotalCr">0.00</span></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="addLine()">
                        <i class="bi bi-plus-circle"></i> Add Line
                    </button>
                    <span id="editBalanceStatus" class="badge bg-secondary">No lines</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> Cancel
                </button>
                <button type="button" class="btn btn-primary" id="editSaveBtn" onclick="saveVoucher()" disabled>
                    <i class="bi bi-check-circle"></i> Save Voucher
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
let vouchers = [];
let filteredVouchers = [];
let viewModal;
const canCreateJV = <?php echo $canCreateJV ? 'true' : 'false'; ?>;
const canEditJV   = <?php echo $canEditJV ? 'true' : 'false'; ?>;
const canDeleteJV = <?php echo $canDeleteJV ? 'true' : 'false'; ?>;
const canManageJV = canCreateJV || canEditJV || canDeleteJV;
let editModal = null;
let ledgerOptions = [];      // [{id, name, account_type}] for DR/CR dropdowns
const defaultSymbol = '<?php echo addslashes(isset($defaultCurrencySymbol) ? $defaultCurrencySymbol : 'TZS'); ?>';

document.addEventListener('DOMContentLoaded', async function() {
    viewModal = new bootstrap.Modal(document.getElementById('viewModal'));
    if (canManageJV) {
        editModal = new bootstrap.Modal(document.getElementById('editModal'));
        ensureLedgers();   // warm the ledger list for the DR/CR dropdowns
    }

    const today = new Date().toISOString().split('T')[0];
    const stored = {
        from: sessionStorage.getItem('jvFilterDateFrom'),
        to:   sessionStorage.getItem('jvFilterDateTo'),
    };
    document.getElementById('dateFrom').value = stored.from || today;
    document.getElementById('dateTo').value   = stored.to   || today;

    document.getElementById('dateFrom').addEventListener('change', function() {
        sessionStorage.setItem('jvFilterDateFrom', this.value);
        loadVouchers();
    });
    document.getElementById('dateTo').addEventListener('change', function() {
        sessionStorage.setItem('jvFilterDateTo', this.value);
        loadVouchers();
    });

    await loadVouchers();
});

async function loadVouchers() {
    document.getElementById('loadingSpinner').style.display = 'block';
    document.getElementById('vouchersContainer').style.display = 'none';
    document.getElementById('emptyState').style.display = 'none';

    const dateFrom = document.getElementById('dateFrom').value;
    const dateTo   = document.getElementById('dateTo').value;
    try {
        const url = `${BASE_URL}api/journal-vouchers.php?date_from=${dateFrom}&date_to=${dateTo}&_=${Date.now()}`;
        const resp = await fetch(url, { headers: { 'Cache-Control': 'no-cache' }, cache: 'no-store' });
        const r = await resp.json();
        vouchers = r.success ? (r.journal_vouchers || []) : [];
    } catch (e) {
        console.error('Failed to load journal vouchers:', e);
        vouchers = [];
        showToast('Failed to load: ' + e.message, 'danger');
    }
    filterVouchers();
    document.getElementById('loadingSpinner').style.display = 'none';
}

function filterVouchers() {
    const q = document.getElementById('searchInput').value.toLowerCase();
    filteredVouchers = vouchers.filter(v => {
        if (!q) return true;
        const drLedger = drLedgerOf(v).toLowerCase();
        const crLedger = crLedgerOf(v).toLowerCase();
        return (v.voucher_number || '').toLowerCase().includes(q)
            || (v.narration || '').toLowerCase().includes(q)
            || (v.source_voucher_number || '').toLowerCase().includes(q)
            || drLedger.includes(q)
            || crLedger.includes(q);
    });
    displayVouchers();
}

function clearFilters() {
    document.getElementById('searchInput').value = '';
    document.getElementById('dateFrom').value = '';
    document.getElementById('dateTo').value = '';
    sessionStorage.removeItem('jvFilterDateFrom');
    sessionStorage.removeItem('jvFilterDateTo');
    loadVouchers();
}

// Return the first DR ledger and CR ledger for the list table. JVs from
// purchase imports have at most: DR <expense> [DR VAT] CR <beneficiary>.
// Showing the primary DR (non-VAT) and the CR is enough for at-a-glance.
function drLedgerOf(v) {
    if (!v.details) return '';
    const drs = v.details.filter(d => d.dr_cr === 'DR');
    if (drs.length === 0) return '';
    // Skip the VAT line if there are multiple DRs and one is a VAT ledger.
    const nonVat = drs.find(d => !/^vat$/i.test(d.ledger_name || ''));
    return (nonVat || drs[0]).ledger_name || '';
}
function crLedgerOf(v) {
    if (!v.details) return '';
    const cr = v.details.find(d => d.dr_cr === 'CR');
    return cr ? (cr.ledger_name || '') : '';
}
function vatAmountOf(v) {
    if (!v.details) return 0;
    const vatLine = v.details.find(d => d.dr_cr === 'DR' && /^vat$/i.test(d.ledger_name || ''));
    return vatLine ? parseFloat(vatLine.amount_base || 0) : 0;
}
function primaryDrAmountOf(v) {
    if (!v.details) return 0;
    const drs = v.details.filter(d => d.dr_cr === 'DR');
    const nonVat = drs.find(d => !/^vat$/i.test(d.ledger_name || ''));
    return parseFloat((nonVat || drs[0] || {amount_base: 0}).amount_base);
}

function displayVouchers() {
    const tbody = document.getElementById('vouchersTableBody');
    const container = document.getElementById('vouchersContainer');
    const emptyState = document.getElementById('emptyState');

    if (filteredVouchers.length === 0) {
        container.style.display = 'none';
        emptyState.style.display = 'block';
        return;
    }

    container.style.display = 'block';
    emptyState.style.display = 'none';

    const totalBase = filteredVouchers.reduce((s, v) => s + parseFloat(v.total_cr_base || 0), 0);
    document.getElementById('voucherCount').textContent =
        `${filteredVouchers.length} voucher${filteredVouchers.length !== 1 ? 's' : ''}`;
    document.getElementById('totalAmount').textContent =
        `Total (base): ${defaultSymbol} ${fmtNum(totalBase)}`;

    tbody.innerHTML = filteredVouchers.map(v => {
        const sourceCell = v.source_voucher_number
            ? `<a href="${BASE_URL}modules/transactions/purchase_imports.php?view=${v.source_voucher_id}" title="Open source import">${escapeHtml(v.source_voucher_number)}</a>`
            : '<span class="text-muted">—</span>';
        const vat = vatAmountOf(v);
        const drAmt = primaryDrAmountOf(v);
        const isManual = v.voucher_type === 'manual_journal';
        let actions = `
                    <button class="btn btn-sm btn-info" onclick="viewVoucher(${v.id})" title="View">
                        <i class="bi bi-eye"></i>
                    </button>`;
        if (isManual && canEditJV) {
            actions += `
                    <button class="btn btn-sm btn-warning" onclick="openEditVoucher(${v.id})" title="Edit">
                        <i class="bi bi-pencil"></i>
                    </button>`;
        }
        if (isManual && canDeleteJV) {
            actions += `
                    <button class="btn btn-sm btn-danger" onclick="deleteVoucher(${v.id})" title="Delete">
                        <i class="bi bi-trash"></i>
                    </button>`;
        }
        const sourceLabel = isManual
            ? '<span class="badge bg-light text-dark border">Manual</span>'
            : sourceCell;
        return `
            <tr>
                <td><strong>${escapeHtml(v.voucher_number || '')}</strong></td>
                <td>${formatDate(v.date)}</td>
                <td><small>${escapeHtml(v.narration || '')}</small></td>
                <td>${escapeHtml(drLedgerOf(v))}</td>
                <td>${escapeHtml(crLedgerOf(v))}</td>
                <td class="text-end">${defaultSymbol} ${fmtNum(drAmt)}</td>
                <td class="text-end">${vat > 0 ? defaultSymbol + ' ' + fmtNum(vat) : '<span class="text-muted">—</span>'}</td>
                <td>${sourceLabel}</td>
                <td class="text-center text-nowrap">${actions}</td>
            </tr>
        `;
    }).join('');
}

function viewVoucher(id) {
    const v = vouchers.find(x => x.id == id);
    if (!v) { showToast('Voucher not found', 'danger'); return; }

    document.getElementById('viewVoucherNumber').textContent = v.voucher_number || '';
    document.getElementById('viewDate').textContent = formatDate(v.date);
    document.getElementById('viewNarration').textContent = v.narration || '';

    // Source link
    const srcRow = document.getElementById('viewSourceRow');
    const srcLink = document.getElementById('viewSourceLink');
    if (v.source_voucher_number && v.source_voucher_type === 'purchase_import') {
        srcLink.textContent = v.source_voucher_number;
        srcLink.href = `${BASE_URL}modules/transactions/purchase_imports.php?view=${v.source_voucher_id}`;
        srcRow.style.display = '';
    } else {
        srcRow.style.display = 'none';
    }

    // Cost centre
    const ccRow = document.getElementById('viewCostCentreRow');
    if (v.cost_centre_name) {
        document.getElementById('viewCostCentre').textContent =
            (v.cost_centre_code ? v.cost_centre_code + ' - ' : '') + v.cost_centre_name;
        ccRow.style.display = '';
    } else {
        ccRow.style.display = 'none';
    }

    // Details body
    const tbody = document.getElementById('viewDetailsBody');
    if (v.details && v.details.length > 0) {
        tbody.innerHTML = v.details.map(d => {
            const curr = d.currency_code || '';
            const fx = parseFloat(d.rate_of_exchange || 1);
            return `
                <tr>
                    <td><span class="badge ${d.dr_cr === 'DR' ? 'bg-primary' : 'bg-success'}">${d.dr_cr}</span></td>
                    <td>${escapeHtml(d.ledger_name || '')}</td>
                    <td class="text-center">${escapeHtml(curr)}</td>
                    <td class="text-end">${fmtNum(fx, 4)}</td>
                    <td class="text-end">${curr} ${fmtNum(d.amount)}</td>
                    <td class="text-end">${fmtNum(d.amount_base)}</td>
                </tr>
            `;
        }).join('');
    } else {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No details</td></tr>';
    }
    document.getElementById('viewDetailsTotal').textContent = fmtNum(v.total_cr_base || 0);

    document.getElementById('viewCreatedBy').textContent = v.created_by_name || 'System';

    viewModal.show();
}

// ==================== CREATE / EDIT / DELETE ====================

async function ensureLedgers() {
    if (ledgerOptions.length > 0) return;
    try {
        const resp = await fetch(`${BASE_URL}api/journal-vouchers-sync.php?action=ledgers&_=${Date.now()}`,
            { cache: 'no-store' });
        const r = await resp.json();
        ledgerOptions = r.success ? (r.ledgers || []) : [];
    } catch (e) {
        console.error('Failed to load ledgers:', e);
        ledgerOptions = [];
        showToast('Failed to load ledger accounts: ' + e.message, 'danger');
    }
}

// Headings for the <optgroup>s. The API already returns the ledgers grouped in
// the order we want (cash/bank first, suppliers last), so we just start a new
// group whenever account_type changes.
const LEDGER_TYPE_LABELS = {
    cash:     'Cash',
    bank:     'Bank',
    customer: 'Customers',
    expense:  'Expenses',
    sales:    'Sales',
    purchase: 'Purchase',
    duties:   'Duties & Taxes',
    supplier: 'Suppliers',
};

function ledgerSelectHtml(selectedId) {
    let opts = '<option value="">— Select ledger —</option>';
    let currentType = null;
    ledgerOptions.forEach(l => {
        const type = l.account_type || '';
        if (type !== currentType) {
            if (currentType !== null) opts += '<\/optgroup>';
            opts += `<optgroup label="${escapeHtml(LEDGER_TYPE_LABELS[type] || type || 'Other')}">`;
            currentType = type;
        }
        const sel = (String(l.id) === String(selectedId)) ? ' selected' : '';
        opts += `<option value="${l.id}"${sel}>${escapeHtml(l.name)} <\/option>`;
    });
    if (currentType !== null) opts += '<\/optgroup>';
    return `<select class="form-select form-select-sm jv-line-ledger">${opts}</select>`;
}

// Make a line's ledger dropdown searchable. dropdownParent must be the modal,
// otherwise Bootstrap's focus trap swallows keystrokes in the search box.
// Falls back to the plain <select> if Select2 didn't load.
function initLineLedgerSelect(sel) {
    if (!sel || typeof jQuery === 'undefined' || !jQuery.fn || !jQuery.fn.select2) return;
    jQuery(sel).select2({
        placeholder: '— Select ledger —',
        allowClear: true,
        width: '100%',
        dropdownParent: jQuery('#editModal')
    });
}

function editSymbol() {
    const sel = document.getElementById('editCurrency');
    if (!sel || !sel.selectedOptions.length) return defaultSymbol;
    return sel.selectedOptions[0].getAttribute('data-symbol') || defaultSymbol;
}

function onEditCurrencyChange() {
    const sel = document.getElementById('editCurrency');
    const opt = sel.selectedOptions[0];
    // Base currency always posts at 1:1 — lock the rate. Foreign currency:
    // let the user enter the day's rate (defaults to whatever was there).
    if (opt && opt.getAttribute('data-default') === '1') {
        document.getElementById('editFxRate').value = '1';
    }
    recalcBalance();
}

function addLine(drcr = 'DR', ledgerId = '', amount = '') {
    const tbody = document.getElementById('editLinesBody');
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td>
            <select class="form-select form-select-sm jv-line-drcr" onchange="recalcBalance()">
                <option value="DR"${drcr === 'DR' ? ' selected' : ''}>DR</option>
                <option value="CR"${drcr === 'CR' ? ' selected' : ''}>CR</option>
            </select>
        </td>
        <td>${ledgerSelectHtml(ledgerId)}</td>
        <td><input type="number" class="form-control form-control-sm text-end jv-line-amount"
                   min="0" step="0.01" value="${amount}" oninput="recalcBalance()" placeholder="0.00"></td>
        <td class="text-end jv-line-base">0.00</td>
        <td class="text-center">
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeLine(this)" title="Remove line">
                <i class="bi bi-x"></i>
            </button>
        </td>`;
    tbody.appendChild(tr);
    initLineLedgerSelect(tr.querySelector('.jv-line-ledger'));
    recalcBalance();
}

function removeLine(btn) {
    btn.closest('tr').remove();
    recalcBalance();
}

function gatherLines() {
    const rows = document.querySelectorAll('#editLinesBody tr');
    const fx = parseFloat(document.getElementById('editFxRate').value) || 0;
    const lines = [];
    rows.forEach(tr => {
        lines.push({
            ledger_id: tr.querySelector('.jv-line-ledger').value,
            dr_cr:     tr.querySelector('.jv-line-drcr').value,
            amount:    parseFloat(tr.querySelector('.jv-line-amount').value) || 0,
            _fx:       fx,
            _row:      tr,
        });
    });
    return lines;
}

function recalcBalance() {
    const fx = parseFloat(document.getElementById('editFxRate').value) || 0;
    const sym = editSymbol();
    let totalDr = 0, totalCr = 0, validRows = 0;
    document.querySelectorAll('#editLinesBody tr').forEach(tr => {
        const amount = parseFloat(tr.querySelector('.jv-line-amount').value) || 0;
        const drcr   = tr.querySelector('.jv-line-drcr').value;
        const base   = Math.round(amount * fx * 100) / 100;
        tr.querySelector('.jv-line-base').textContent = fmtNum(base);
        if (amount > 0) validRows++;
        if (drcr === 'DR') totalDr += base; else totalCr += base;
    });
    document.getElementById('editTotalDr').textContent = fmtNum(totalDr);
    document.getElementById('editTotalCr').textContent = fmtNum(totalCr);

    const diff = Math.round((totalDr - totalCr) * 100) / 100;
    const status = document.getElementById('editBalanceStatus');
    const saveBtn = document.getElementById('editSaveBtn');
    const enoughLines = document.querySelectorAll('#editLinesBody tr').length >= 2 && validRows >= 2;

    if (!enoughLines) {
        status.className = 'badge bg-secondary';
        status.textContent = 'Add at least one DR and one CR line';
        saveBtn.disabled = true;
    } else if (Math.abs(diff) > 0.01) {
        status.className = 'badge bg-danger';
        status.textContent = `Out of balance by ${sym} ${fmtNum(Math.abs(diff))}`;
        saveBtn.disabled = true;
    } else {
        status.className = 'badge bg-success';
        status.textContent = `Balanced — ${sym} ${fmtNum(totalDr)}`;
        saveBtn.disabled = false;
    }
}

async function openCreateVoucher() {
    if (!canCreateJV) return;
    await ensureLedgers();
    document.getElementById('editModalTitle').textContent = 'New Journal Voucher';
    document.getElementById('editVoucherId').value = '';
    document.getElementById('editDate').value = new Date().toISOString().split('T')[0];
    document.getElementById('editNarration').value = '';
    document.getElementById('editCostCentre').value = '';
    const curSel = document.getElementById('editCurrency');
    if (curSel.options.length) curSel.selectedIndex = 0;  // default currency is first
    document.getElementById('editFxRate').value = '1';
    document.getElementById('editLinesBody').innerHTML = '';
    addLine('DR');
    addLine('CR');
    recalcBalance();
    editModal.show();
}

async function openEditVoucher(id) {
    if (!canEditJV) return;
    const v = vouchers.find(x => x.id == id);
    if (!v) { showToast('Voucher not found', 'danger'); return; }
    if (v.voucher_type !== 'manual_journal') {
        showToast('Auto-generated vouchers are edited via their source document', 'warning');
        return;
    }
    await ensureLedgers();

    document.getElementById('editModalTitle').textContent = 'Edit ' + (v.voucher_number || 'Journal Voucher');
    document.getElementById('editVoucherId').value = v.id;
    document.getElementById('editDate').value = (v.date || '').split(' ')[0];
    document.getElementById('editNarration').value = v.narration || '';

    // Currency + FX come from the (single) currency shared by all detail lines.
    const first = (v.details && v.details[0]) ? v.details[0] : null;
    const curSel = document.getElementById('editCurrency');
    if (first && first.currency_id) {
        curSel.value = String(first.currency_id);
        if (curSel.value !== String(first.currency_id)) curSel.selectedIndex = 0; // fallback
    } else if (curSel.options.length) {
        curSel.selectedIndex = 0;
    }
    document.getElementById('editFxRate').value = first ? (parseFloat(first.rate_of_exchange) || 1) : 1;

    // Cost centre round-trips via cost_centre_id from the GET API.
    document.getElementById('editCostCentre').value = v.cost_centre_id ? String(v.cost_centre_id) : '';

    const tbody = document.getElementById('editLinesBody');
    tbody.innerHTML = '';
    (v.details || []).forEach(d => addLine(d.dr_cr, d.ledger_id, parseFloat(d.amount) || ''));
    if (!tbody.children.length) { addLine('DR'); addLine('CR'); }
    recalcBalance();
    editModal.show();
}

async function saveVoucher() {
    const id = document.getElementById('editVoucherId').value;
    const lines = gatherLines()
        .filter(l => l.ledger_id && l.amount > 0)
        .map(l => ({ ledger_id: l.ledger_id, dr_cr: l.dr_cr, amount: l.amount }));

    if (lines.length < 2) {
        showToast('Add at least one DR and one CR line', 'danger');
        return;
    }
    const data = {
        id: id || undefined,
        date: document.getElementById('editDate').value,
        narration: document.getElementById('editNarration').value,
        currency_id: document.getElementById('editCurrency').value,
        exchange_rate: parseFloat(document.getElementById('editFxRate').value) || 1,
        cost_centre_id: document.getElementById('editCostCentre').value || null,
        lines: lines,
    };

    const saveBtn = document.getElementById('editSaveBtn');
    saveBtn.disabled = true;
    try {
        const resp = await fetch(`${BASE_URL}api/journal-vouchers-sync.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: id ? 'update' : 'create', data }),
        });
        const r = await resp.json();
        if (!r.success) throw new Error(r.error || 'Save failed');
        editModal.hide();
        showToast(r.message || 'Saved', 'success');
        await loadVouchers();
    } catch (e) {
        showToast(e.message, 'danger');
    } finally {
        saveBtn.disabled = false;
    }
}

async function deleteVoucher(id) {
    if (!canDeleteJV) return;
    const v = vouchers.find(x => x.id == id);
    if (!v) { showToast('Voucher not found', 'danger'); return; }
    if (!confirm(`Delete journal voucher ${v.voucher_number || ''}? This cannot be undone.`)) return;
    try {
        const resp = await fetch(`${BASE_URL}api/journal-vouchers-sync.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete', data: { id } }),
        });
        const r = await resp.json();
        if (!r.success) throw new Error(r.error || 'Delete failed');
        showToast(r.message || 'Deleted', 'success');
        await loadVouchers();
    } catch (e) {
        showToast(e.message, 'danger');
    }
}

// ==================== UTILITIES ====================

function fmtNum(n, decimals = 2) {
    const x = parseFloat(n);
    if (isNaN(x)) return '0.00';
    return x.toFixed(decimals).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
}

function formatDate(dateString) {
    if (!dateString) return '';
    const d = new Date(dateString);
    const dd = String(d.getDate()).padStart(2, '0');
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const yyyy = d.getFullYear();
    return `${dd}-${mm}-${yyyy}`;
}

function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    const div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML;
}

function showToast(message, type = 'info') {
    document.querySelectorAll('.jv-toast').forEach(t => t.remove());
    const toast = document.createElement('div');
    toast.className = `jv-toast alert alert-${type} alert-dismissible fade show`;
    toast.style.cssText = 'position:fixed;top:80px;right:20px;z-index:9999;min-width:300px;box-shadow:0 4px 6px rgba(0,0,0,0.2);';
    toast.innerHTML = `${message}<button type="button" class="btn-close" data-bs-dismiss="alert"></button>`;
    document.body.appendChild(toast);
    setTimeout(() => { toast.classList.remove('show'); setTimeout(() => toast.remove(), 150); }, 5000);
}
</script>

<?php require_once '../../includes/footer.php'; ?>
