<?php
/**
 * Purchase Imports Management
 * Handles import purchases with additional costs (freight, customs, port charges)
 * Calculates landed cost per item using costing factor
 */
require_once '../../config/database.php';
require_once '../../includes/functions.php';

$pageTitle = 'Purchase Imports';

if (!hasPermission('purchase_imports', 'view')) {
    setAlert('You do not have permission to access this page', 'danger');
    redirect('');
}

require_once '../../includes/header.php';
?>

<div class="row mb-3">
    <div class="col-md-6">
        <h2><i class="bi bi-globe2"></i> Purchase Imports</h2>
    </div>
    <div class="col-md-6 text-end">
        <button type="button" class="btn btn-success" id="exportBtn" onclick="exportImports()">
            <i class="bi bi-file-earmark-excel"></i> Export to Excel
        </button>
        <?php if (hasPermission('purchase_imports', 'create')): ?>
        <a class="btn btn-primary" href="purchase_import_form.php" id="createBtn">
            <i class="bi bi-plus-circle"></i> New Purchase Import
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
                       placeholder="Search import number, PO, supplier..." onkeyup="filterImports()">
            </div>
            <div class="col-md-2">
                <button onclick="clearFilters()" class="btn btn-secondary w-100">
                    <i class="bi bi-x-circle"></i> Clear
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Imports Table -->
<div class="card">
    <div class="card-body">
        <div id="loadingSpinner" class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-2">Loading purchase imports...</p>
        </div>

        <div id="importsContainer" style="display:none;">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <span id="importCount" class="text-muted">0 imports</span>
                <span id="totalAmount" class="fw-bold text-primary">Total Landed: 0.00</span>
            </div>

            <div class="table-responsive">
                <table class="table table-hover table-striped">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 120px;">Import #</th>
                            <th style="width: 110px;">PO #</th>
                            <th style="width: 110px;">Date</th>
                            <th>Supplier</th>
                            <th>Location</th>
                            <th class="text-center" style="width: 80px;">Items</th>
                            <th class="text-end" style="width: 130px;">FOB Total</th>
                            <th class="text-end" style="width: 130px;">Landed Total</th>
                            <th class="text-center" style="width: 90px;">Factor</th>
                            <th style="width: 150px;" class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="importsTableBody">
                    </tbody>
                </table>
            </div>
        </div>

        <div id="emptyState" style="display:none;" class="text-center py-5">
            <i class="bi bi-inbox" style="font-size: 3rem; color: #ccc;"></i>
            <p class="text-muted mt-3">No purchase imports found</p>
            <?php if (hasPermission('purchase_imports', 'create')): ?>
            <a class="btn btn-primary mt-2" href="purchase_import_form.php">
                <i class="bi bi-plus-circle"></i> Create First Import
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- View Import Modal -->
<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="bi bi-eye"></i> Import Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
<!-- Legacy import banner. Hidden via Bootstrap's d-none class because
                     the inline style="display:none" would lose the !important
                     fight with d-flex. viewImport() toggles d-none. -->
                <div class="alert alert-warning d-flex align-items-start mb-3 d-none" id="viewLegacyBanner">
                    <i class="bi bi-exclamation-triangle-fill me-2 mt-1"></i>
                    <div>
                        <strong>No accounting entries for this import yet.</strong>
                        It was created before the GL posting feature was added.
                        Click Edit and save to post the supplier liability, VAT,
                        Bucket A costs and Bucket B journal vouchers.
                    </div>
                </div>

                <!-- Header Info -->
                <div class="row mb-3">
                    <div class="col-md-3">
                        <strong>Import Number:</strong><br>
                        <span id="viewImportNumber" class="fs-5 text-primary"></span>
                    </div>
                    <div class="col-md-3">
                        <strong>PO Number:</strong><br>
                        <span id="viewPoNumber" class="text-muted">-</span>
                    </div>
                    <div class="col-md-3">
                        <strong><i class="bi bi-calendar"></i> Date:</strong><br>
                        <span id="viewDate"></span>
                    </div>
                    <div class="col-md-3">
                        <strong><i class="bi bi-currency-exchange"></i> Currency:</strong><br>
                        <span id="viewCurrency"></span>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-3">
                        <strong><i class="bi bi-truck"></i> Supplier:</strong><br>
                        <span id="viewSupplier"></span>
                    </div>
                    <div class="col-md-3">
                        <strong><i class="bi bi-geo-alt"></i> Location:</strong><br>
                        <span id="viewLocation"></span>
                    </div>
                    <div class="col-md-3" id="viewCostCentreRow" style="display:none;">
                        <strong><i class="bi bi-building"></i> Cost Centre:</strong><br>
                        <span id="viewCostCentre"></span>
                    </div>
                </div>
                <div class="row mb-3" id="viewNotesRow" style="display:none;">
                    <div class="col-12">
                        <strong><i class="bi bi-sticky"></i> Notes:</strong><br>
                        <span id="viewNotes" class="text-muted"></span>
                    </div>
                </div>

                <hr>

                <!-- Costing Summary -->
                <h6 class="mb-3"><i class="bi bi-calculator"></i> Import Costing Summary</h6>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <table class="table table-sm table-bordered">
                            <tbody id="viewCostingSummary">
                            </tbody>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <div class="card bg-light">
                            <div class="card-body text-center">
                                <h6 class="text-muted mb-1">Costing Factor</h6>
                                <h3 class="text-primary mb-1" id="viewCostingFactor">1.0000</h3>
                                <small class="text-muted">Total Landed / Total FOB</small>
                            </div>
                        </div>
                    </div>
                </div>

                <hr>

                <!-- Items with Landed Cost -->
                <h6 class="mb-3"><i class="bi bi-box-seam"></i> Items with Landed Cost</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 5%;">#</th>
                                <th>Product</th>
                                <th class="text-center">Unit</th>
                                <th class="text-end">Qty</th>
                                <th class="text-end">FOB Rate</th>
                                <th class="text-end">FOB Amount</th>
                                <th class="text-end">Landed Cost</th>
                                <th class="text-end">Selling Price</th>
                                <th class="text-end">Profit/Unit</th>
                            </tr>
                        </thead>
                        <tbody id="viewItemsBody">
                        </tbody>
                        <tfoot>
                            <tr class="table-active fw-bold">
                                <td colspan="5" class="text-end">Totals:</td>
                                <td class="text-end" id="viewTotalFob"></td>
                                <td class="text-end" id="viewTotalLanded"></td>
                                <td colspan="2"></td>
                            </tr>
                            <tr id="viewVatRow" style="display:none;">
                                <td colspan="5" class="text-end">VAT Amount:</td>
                                <td class="text-end" id="viewVatAmount"></td>
                                <td colspan="3"></td>
                            </tr>
                            <tr class="table-active fw-bold" id="viewPayableRow" style="display:none;">
                                <td colspan="5" class="text-end">Payable to Supplier (FOB + VAT):</td>
                                <td class="text-end" id="viewPayableAmount"></td>
                                <td colspan="3"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <hr>

                <!-- GL Effects: Main purchase_import transaction + each child JV.
                     Lazy-fetched on view-modal open via api/purchase-import-gl.php. -->
                <h6 class="mb-3">
                    <i class="bi bi-calculator-fill"></i> GL Effects
                    <small class="text-muted">— DR/CR posted to the general ledger</small>
                </h6>
                <div id="viewGlContainer">
                    <div id="viewGlLoading" class="text-center py-3" style="display:none;">
                        <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                        <small class="text-muted">Loading GL details…</small>
                    </div>
                    <div id="viewGlEmpty" class="alert alert-warning py-2 mb-0" style="display:none;">
                        <i class="bi bi-exclamation-triangle"></i>
                        No GL transactions posted for this import. Edit and save to post.
                    </div>
                    <div id="viewGlContent" style="display:none;"></div>
                </div>

                <hr>

                <div class="row mt-3">
                    <div class="col-md-6">
                        <small class="text-muted">
                            <i class="bi bi-person"></i> Created by: <span id="viewCreatedBy"></span>
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
                <?php if (hasPermission('purchase_imports', 'edit')): ?>
                <a class="btn btn-warning" id="viewEditBtn" href="#" onclick="editFromView(); return false;">
                    <i class="bi bi-pencil"></i> Edit
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// Global variables
let imports = [];
let filteredImports = [];
let viewModal;
let selectedCurrencySymbol = '<?php echo addslashes(isset($defaultCurrencySymbol) ? $defaultCurrencySymbol : 'TZS'); ?>';
let currentViewImportId = null;

// Initialize
document.addEventListener('DOMContentLoaded', async function() {
    viewModal = new bootstrap.Modal(document.getElementById('viewModal'));

    const today = new Date().toISOString().split('T')[0];

    // When arriving from a just-saved import (?view=ID&date=YYYY-MM-DD), set the
    // date filter to that import's date so the server returns it and the
    // auto-open view modal can find it. A back-dated import would otherwise fall
    // outside the default (today-only) window. This takes priority over the
    // remembered session range.
    const initParams = new URLSearchParams(window.location.search);
    const focusDate = initParams.get('date');
    if (focusDate && /^\d{4}-\d{2}-\d{2}$/.test(focusDate)) {
        document.getElementById('dateFrom').value = focusDate;
        document.getElementById('dateTo').value = focusDate;
        sessionStorage.setItem('importsFilterDateFrom', focusDate);
        sessionStorage.setItem('importsFilterDateTo', focusDate);
    } else {
        const storedDateFrom = sessionStorage.getItem('importsFilterDateFrom');
        const storedDateTo = sessionStorage.getItem('importsFilterDateTo');
        document.getElementById('dateFrom').value = storedDateFrom || today;
        document.getElementById('dateTo').value = storedDateTo || today;
    }

    await loadAllData();

    // Date range is filtered server-side now, so changing a bound must refetch.
    document.getElementById('dateFrom').addEventListener('change', async function() {
        sessionStorage.setItem('importsFilterDateFrom', this.value);
        await loadImports();
        filterImports();
    });
    document.getElementById('dateTo').addEventListener('change', async function() {
        sessionStorage.setItem('importsFilterDateTo', this.value);
        await loadImports();
        filterImports();
    });
});

// Load list data
async function loadAllData() {
    document.getElementById('loadingSpinner').style.display = 'block';
    document.getElementById('importsContainer').style.display = 'none';
    document.getElementById('emptyState').style.display = 'none';

    try {
        await loadImports();
        filterImports();

        // Auto-open view modal if ?view=ID is in URL
        const urlParams = new URLSearchParams(window.location.search);
        const viewId = urlParams.get('view');
        if (viewId) {
            viewImport(viewId);
        }
    } catch (error) {
        console.error('Failed to load data:', error);
        showToast('Failed to load data: ' + error.message, 'danger');
    } finally {
        document.getElementById('loadingSpinner').style.display = 'none';
    }
}

// Load imports
async function loadImports() {
    try {
        // Pass the UI's date range so the server returns only that window
        // (replaces the old fixed 90-day cap). Empty bounds are omitted,
        // leaving that side open.
        const params = new URLSearchParams({ _: Date.now() });
        const dFrom = document.getElementById('dateFrom').value;
        const dTo = document.getElementById('dateTo').value;
        if (dFrom) params.set('dateFrom', dFrom);
        if (dTo) params.set('dateTo', dTo);
        const response = await fetch(BASE_URL + 'api/purchase-imports.php?' + params.toString(), {
            headers: { 'Cache-Control': 'no-cache' }, cache: 'no-store'
        });
        const result = await response.json();
        if (result.success) {
            imports = result.purchase_imports || [];
        }
    } catch (error) {
        console.error('Failed to load imports:', error);
        imports = [];
    }
    filteredImports = imports;
}

// ==================== DISPLAY ====================

function displayImports() {
    const tbody = document.getElementById('importsTableBody');
    const container = document.getElementById('importsContainer');
    const emptyState = document.getElementById('emptyState');

    if (filteredImports.length === 0) {
        container.style.display = 'none';
        emptyState.style.display = 'block';
        return;
    }

    container.style.display = 'block';
    emptyState.style.display = 'none';

    // No combined "Total Landed" figure: imports can be in different currencies,
    // so a single summed total would have no valid currency. Show only the count.
    document.getElementById('importCount').textContent = `${filteredImports.length} import${filteredImports.length !== 1 ? 's' : ''}`;
    document.getElementById('totalAmount').textContent = '';

    tbody.innerHTML = filteredImports.map(imp => {
        const itemCount = imp.details ? imp.details.length : 0;
        // imp.has_gl arrives as 0/1 from MySQL EXISTS. Treat strict-zero as legacy.
        const isLegacy = !parseInt(imp.has_gl || 0);
        const legacyBadge = isLegacy
            ? ' <span class="badge bg-warning text-dark" title="No GL entries posted yet — open and save to post"><i class="bi bi-exclamation-triangle"></i> No GL</span>'
            : '';
        return `
            <tr${isLegacy ? ' class="table-warning-subtle"' : ''}>
                <td><strong>${escapeHtml(imp.import_number)}</strong>${legacyBadge}</td>
                <td>${escapeHtml(imp.po_number || '-')}</td>
                <td>${formatDate(imp.import_date)}</td>
                <td>${escapeHtml(imp.supplier_name || 'Unknown')}</td>
                <td>${escapeHtml(imp.location_name || 'Unknown')}</td>
                <td class="text-center"><span class="badge bg-info">${itemCount}</span></td>
                <td class="text-end">${fmtCurrency(imp.total_fob_amount, imp.currency_code || imp.currency_symbol)}</td>
                <td class="text-end"><strong>${fmtCurrency(imp.total_landed_amount, imp.currency_code || imp.currency_symbol)}</strong></td>
                <td class="text-center"><span class="badge bg-secondary">${parseFloat(imp.costing_factor).toFixed(4)}</span></td>
                <td class="text-center">
                    <button class="btn btn-sm btn-info" onclick="viewImport('${imp.id}')" title="View">
                        <i class="bi bi-eye"></i>
                    </button>
                    ${hasEditPermission() ? `
                        <a class="btn btn-sm btn-warning" href="purchase_import_form.php?id=${imp.id}" title="Edit">
                            <i class="bi bi-pencil"></i>
                        </a>
                    ` : ''}
                    <button class="btn btn-sm btn-success" onclick="exportImportItems('${imp.id}')" title="Export line items to Excel">
                        <i class="bi bi-file-earmark-excel"></i>
                    </button>
                    ${hasDeletePermission() ? `
                        <button class="btn btn-sm btn-danger" onclick="deleteImport('${imp.id}')" title="Delete">
                            <i class="bi bi-trash"></i>
                        </button>
                    ` : ''}
                </td>
            </tr>
        `;
    }).join('');
}

function filterImports() {
    const searchTerm = document.getElementById('searchInput').value.toLowerCase();
    const dateFrom = document.getElementById('dateFrom').value;
    const dateTo = document.getElementById('dateTo').value;

    filteredImports = imports.filter(imp => {
        const matchesSearch = !searchTerm ||
            imp.import_number.toLowerCase().includes(searchTerm) ||
            (imp.po_number && imp.po_number.toLowerCase().includes(searchTerm)) ||
            (imp.supplier_name && imp.supplier_name.toLowerCase().includes(searchTerm)) ||
            (imp.location_name && imp.location_name.toLowerCase().includes(searchTerm));

        const matchesDateFrom = !dateFrom || imp.import_date >= dateFrom;
        const matchesDateTo = !dateTo || imp.import_date <= dateTo;

        return matchesSearch && matchesDateFrom && matchesDateTo;
    });

    displayImports();
}

async function clearFilters() {
    document.getElementById('searchInput').value = '';
    document.getElementById('dateFrom').value = '';
    document.getElementById('dateTo').value = '';
    sessionStorage.removeItem('importsFilterDateFrom');
    sessionStorage.removeItem('importsFilterDateTo');
    // Dates are now server-side filters; clearing them must refetch the full set.
    await loadImports();
    filterImports();
}

// ==================== EXPORT ====================

// Download the current list (respecting date range + search) as an Excel-
// friendly CSV. The export endpoint re-applies the same location scope and
// filters server-side. BASE_URL points at the app root; the endpoint lives
// under modules/reports/.
function exportImports() {
    const params = new URLSearchParams();
    const dFrom = document.getElementById('dateFrom').value;
    const dTo = document.getElementById('dateTo').value;
    const search = document.getElementById('searchInput').value.trim();
    if (dFrom) params.set('dateFrom', dFrom);
    if (dTo) params.set('dateTo', dTo);
    if (search) params.set('search', search);
    window.location.href = BASE_URL + 'modules/reports/purchase_imports_export.php?' + params.toString();
}

// Download the line-item breakdown (one row per product line + one per
// additional-cost line) for a SINGLE import.
function exportImportItems(id) {
    window.location.href = BASE_URL + 'modules/reports/purchase_import_items_export.php?id=' + encodeURIComponent(id);
}

// ==================== VIEW ====================

function viewImport(id) {
    const imp = imports.find(p => p.id == id);
    if (!imp) { showToast('Import not found', 'danger'); return; }

    currentViewImportId = id;

    // Header-currency label for this import. FOB / VAT / Landed / item rates /
    // Bucket A amounts are all stored in the import's own currency, so they
    // must be labelled with it — not the page-default symbol. Prefer the ISO
    // code (USD) over the editable `symbol` field, which can be wrong/duplicated
    // (the same reason the entry form uses resolveCurrencyLabel()).
    const cur = imp.currency_code || imp.currency_symbol || selectedCurrencySymbol;
    const fc = (a) => fmtCurrency(a, cur);

    // Legacy banner: show when imp.has_gl is 0/false (no posted GL transaction).
    // Toggle d-none rather than style.display — the banner uses d-flex, whose
    // `display: flex !important` would override an inline display:none.
    const hasGl = parseInt(imp.has_gl || 0);
    document.getElementById('viewLegacyBanner').classList.toggle('d-none', !!hasGl);

    document.getElementById('viewImportNumber').textContent = imp.import_number;
    document.getElementById('viewPoNumber').textContent = imp.po_number || '-';
    document.getElementById('viewDate').textContent = formatDate(imp.import_date);
    document.getElementById('viewSupplier').textContent = imp.supplier_name || 'Unknown';
    document.getElementById('viewLocation').textContent = imp.location_name || 'Unknown';
    document.getElementById('viewCurrency').textContent = (imp.currency_code || '') + (imp.exchange_rate_to_base && imp.exchange_rate_to_base != 1 ? ' (Rate: ' + imp.exchange_rate_to_base + ')' : '');

    // Cost Centre
    const ccRow = document.getElementById('viewCostCentreRow');
    if (imp.cost_centre_id && imp.cost_centre_name) {
        document.getElementById('viewCostCentre').textContent = (imp.cost_centre_code || '') + ' - ' + imp.cost_centre_name;
        ccRow.style.display = '';
    } else {
        ccRow.style.display = 'none';
    }

    // Notes
    if (imp.notes && imp.notes.trim()) {
        document.getElementById('viewNotesRow').style.display = '';
        document.getElementById('viewNotes').textContent = imp.notes;
    } else {
        document.getElementById('viewNotesRow').style.display = 'none';
    }

    // Costing summary table — show Bucket A (supplier-borne) and the legacy
    // /Bucket B section as separate groups so the user can see where each
    // cost came from.
    const costingSummary = document.getElementById('viewCostingSummary');
    let summaryHtml = `<tr><td>Total FOB Price</td><td class="text-end fw-bold">${fc(imp.total_fob_amount)}</td></tr>`;
    if (imp.costs_a && imp.costs_a.length > 0) {
        summaryHtml += `<tr class="table-light"><td colspan="2"><em>Supplier-borne costs:</em></td></tr>`;
        imp.costs_a.forEach(cost => {
            const label = escapeHtml(cost.ledger_name || cost.description || 'Supplier-borne cost');
            summaryHtml += `<tr><td class="ps-4">+ ${label}</td><td class="text-end">${fc(cost.amount)}</td></tr>`;
        });
    }
    if (imp.costs && imp.costs.length > 0) {
        summaryHtml += `<tr class="table-light"><td colspan="2"><em>Third-party costs (separate beneficiaries):</em></td></tr>`;
        imp.costs.forEach(cost => {
            const ledgerLabel = cost.ledger_name || cost.description || 'Third-party cost';
            const benefLabel  = cost.beneficiary_name ? ` → ${escapeHtml(cost.beneficiary_name)}` : '';
            const curr = cost.currency_code || '';
            // Show line amount + (if applicable) base-currency equivalent so
            // the breakdown reconciles with Total Landed.
            let amountCell = `${curr} ${formatNumber(cost.amount, 2)}`;
            const fx = parseFloat(cost.exchange_rate_to_base || 0);
            if (fx > 0 && fx !== 1) {
                amountCell += ` <small class="text-muted">× ${fx.toFixed(4)}</small>`;
            }
            summaryHtml += `<tr><td class="ps-4">+ ${escapeHtml(ledgerLabel)}${benefLabel}</td><td class="text-end">${amountCell}</td></tr>`;
            if (parseFloat(cost.vat_amount || 0) > 0) {
                summaryHtml += `<tr><td class="ps-5 text-muted small">↳ VAT (${parseFloat(cost.vat_percent || 0).toFixed(2)}%)</td><td class="text-end text-muted small">${curr} ${formatNumber(cost.vat_amount, 2)}</td></tr>`;
            }
        });
    }
    summaryHtml += `<tr class="table-success fw-bold"><td>Total Landed Value</td><td class="text-end">${fc(imp.total_landed_amount)}</td></tr>`;
    // Bucket C ("Other Costs — not added to costing"). Shown BELOW the landed
    // total because they post journal vouchers but are deliberately excluded
    // from landed cost / inventory value.
    if (imp.costs_c && imp.costs_c.length > 0) {
        summaryHtml += `<tr class="table-light"><td colspan="2"><em>Other costs (not added to costing):</em></td></tr>`;
        imp.costs_c.forEach(cost => {
            const ledgerLabel = cost.ledger_name || cost.description || 'Other cost';
            const benefLabel  = cost.beneficiary_name ? ` → ${escapeHtml(cost.beneficiary_name)}` : '';
            const curr = cost.currency_code || '';
            let amountCell = `${curr} ${formatNumber(cost.amount, 2)}`;
            const fx = parseFloat(cost.exchange_rate_to_base || 0);
            if (fx > 0 && fx !== 1) {
                amountCell += ` <small class="text-muted">× ${fx.toFixed(4)}</small>`;
            }
            summaryHtml += `<tr class="text-muted"><td class="ps-4">${escapeHtml(ledgerLabel)}${benefLabel}</td><td class="text-end">${amountCell}</td></tr>`;
            if (parseFloat(cost.vat_amount || 0) > 0) {
                summaryHtml += `<tr><td class="ps-5 text-muted small">↳ VAT (${parseFloat(cost.vat_percent || 0).toFixed(2)}%)</td><td class="text-end text-muted small">${curr} ${formatNumber(cost.vat_amount, 2)}</td></tr>`;
            }
        });
    }
    costingSummary.innerHTML = summaryHtml;

    // Costing factor
    document.getElementById('viewCostingFactor').textContent = parseFloat(imp.costing_factor).toFixed(4);

    // Items
    const itemsBody = document.getElementById('viewItemsBody');
    if (imp.details && imp.details.length > 0) {
        let totalFob = 0, totalLanded = 0;
        itemsBody.innerHTML = imp.details.map((item, index) => {
            const fobAmount = parseFloat(item.quantity) * parseFloat(item.fob_rate_per_unit);
            const landedAmount = parseFloat(item.quantity) * parseFloat(item.landed_rate_per_unit);
            const profit = item.selling_price ? parseFloat(item.selling_price) - parseFloat(item.landed_rate_per_unit) : null;
            totalFob += fobAmount;
            totalLanded += landedAmount;

            return `
                <tr>
                    <td class="text-center">${index + 1}</td>
                    <td>${escapeHtml(item.product_name)}</td>
                    <td class="text-center">${escapeHtml(item.unit || '')}</td>
                    <td class="text-end">${formatNumber(item.quantity, 3)}</td>
                    <td class="text-end">${fc(item.fob_rate_per_unit)}</td>
                    <td class="text-end">${fc(fobAmount)}</td>
                    <td class="text-end fw-bold text-success">${fc(item.landed_rate_per_unit)}</td>
                    <td class="text-end">${item.selling_price ? fc(item.selling_price) : '-'}</td>
                    <td class="text-end ${profit !== null ? (profit >= 0 ? 'text-success' : 'text-danger') : ''}">
                        ${profit !== null ? fc(profit) : '-'}
                    </td>
                </tr>
            `;
        }).join('');

        document.getElementById('viewTotalFob').textContent = fc(totalFob);
        document.getElementById('viewTotalLanded').textContent = fc(totalLanded);
    } else {
        itemsBody.innerHTML = '<tr><td colspan="9" class="text-center text-muted">No items</td></tr>';
    }

    // VAT + Payable rows: shown only when VAT > 0
    const vatVal = parseFloat(imp.vat_amount || 0);
    const vatRow = document.getElementById('viewVatRow');
    const payableRow = document.getElementById('viewPayableRow');
    if (vatVal > 0) {
        document.getElementById('viewVatAmount').textContent = fc(vatVal);
        document.getElementById('viewPayableAmount').textContent = fc(parseFloat(imp.total_fob_amount || 0) + vatVal);
        vatRow.style.display = '';
        payableRow.style.display = '';
    } else {
        vatRow.style.display = 'none';
        payableRow.style.display = 'none';
    }

    document.getElementById('viewCreatedBy').textContent = imp.created_by_name || 'Unknown';
    document.getElementById('viewCreatedAt').textContent = formatDateTime(imp.created_at);

    // Lazy-fetch GL effects for this import. We don't block opening the modal
    // on the request; the GL section shows a small spinner while it loads.
    loadGlEffectsFor(id);

    viewModal.show();
}

// ==================== GL EFFECTS (view modal) ====================

async function loadGlEffectsFor(importId) {
    const loading = document.getElementById('viewGlLoading');
    const empty   = document.getElementById('viewGlEmpty');
    const content = document.getElementById('viewGlContent');
    loading.style.display = '';
    empty.style.display = 'none';
    content.style.display = 'none';
    content.innerHTML = '';

    try {
        const url = `${BASE_URL}api/purchase-import-gl.php?id=${encodeURIComponent(importId)}&_=${Date.now()}`;
        const resp = await fetch(url, { headers: { 'Cache-Control': 'no-cache' }, cache: 'no-store' });
        const r = await resp.json();
        if (!r.success) throw new Error(r.error || 'Failed to load GL');

        // Guard against late responses arriving after the user opened a
        // different import — only render if it still matches the current view.
        if (currentViewImportId != importId) return;

        const hasMain = !!r.main;
        const jvs = r.journal_vouchers || [];
        if (!hasMain && jvs.length === 0) {
            loading.style.display = 'none';
            empty.style.display = '';
            return;
        }

        let html = '';
        if (hasMain) {
            html += renderGlTransaction(r.main, 'Main Transaction', 'primary');
        }
        if (jvs.length > 0) {
            jvs.forEach(jv => {
                html += renderGlTransaction(jv, 'Journal Voucher', 'success');
            });
        }
        content.innerHTML = html;
        loading.style.display = 'none';
        content.style.display = '';
    } catch (e) {
        console.error('GL fetch failed:', e);
        loading.style.display = 'none';
        empty.style.display = '';
        empty.textContent = 'Could not load GL details: ' + e.message;
    }
}

function renderGlTransaction(tx, kindLabel, badgeColor) {
    const details = tx.details || [];
    const rows = details.map(d => {
        const curr = d.currency_code || '';
        const fx   = parseFloat(d.rate_of_exchange || 1);
        return `
            <tr>
                <td><span class="badge ${d.dr_cr === 'DR' ? 'bg-primary' : 'bg-success'}">${d.dr_cr}</span></td>
                <td>${escapeHtml(d.ledger_name || '')}</td>
                <td class="text-center"><small>${escapeHtml(curr)}</small></td>
                <td class="text-end"><small>${fx.toFixed(4)}</small></td>
                <td class="text-end">${escapeHtml(curr)} ${formatNumber(d.amount, 2)}</td>
                <td class="text-end">${formatNumber(d.amount_base, 2)}</td>
            </tr>
        `;
    }).join('');

    const totalDr = parseFloat(tx.total_dr_base || 0);
    const totalCr = parseFloat(tx.total_cr_base || 0);
    const balanced = Math.abs(totalDr - totalCr) < 0.01;

    return `
        <div class="card mb-3">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <div>
                    <span class="badge bg-${badgeColor} me-2">${kindLabel}</span>
                    <strong>${escapeHtml(tx.voucher_number || '')}</strong>
                    <small class="text-muted ms-2">${formatDate(tx.date)}</small>
                </div>
                <small class="text-muted">${escapeHtml(tx.narration || '')}</small>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 60px;">DR/CR</th>
                                <th>Ledger</th>
                                <th class="text-center" style="width: 70px;">Curr</th>
                                <th class="text-end" style="width: 80px;">FX</th>
                                <th class="text-end" style="width: 140px;">Amount</th>
                                <th class="text-end" style="width: 140px;">Amount (Base)</th>
                            </tr>
                        </thead>
                        <tbody>${rows || '<tr><td colspan="6" class="text-center text-muted">No details</td></tr>'}</tbody>
                        <tfoot>
                            <tr class="${balanced ? 'table-light' : 'table-danger'}">
                                <td colspan="4" class="text-end fw-bold">Totals (base):</td>
                                <td class="text-end">DR ${formatNumber(totalDr, 2)}</td>
                                <td class="text-end">CR ${formatNumber(totalCr, 2)}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    `;
}

// ==================== EDIT ====================

// "Edit" button inside the view modal — navigate to the dedicated form page
// for the currently-viewed import. Row-level Edit buttons are plain links.
function editFromView() {
    if (currentViewImportId) {
        window.location.href = 'purchase_import_form.php?id=' + encodeURIComponent(currentViewImportId);
    }
}

// ==================== DELETE ====================

async function deleteImport(id) {
    if (!confirm('Are you sure you want to delete this purchase import? This will also remove the associated stock entries.')) {
        return;
    }

    try {
        const response = await fetch(BASE_URL + 'api/purchase-imports-sync.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete', data: { id: parseInt(id) } })
        });
        const result = await response.json();
        if (result.success) {
            showToast(result.message, 'success');
            await loadImports();
            filterImports();
        } else {
            showToast('Error: ' + result.error, 'danger');
        }
    } catch (error) {
        console.error('Delete failed:', error);
        showToast('Failed to delete: ' + error.message, 'danger');
    }
}

// ==================== PERMISSIONS ====================

function hasEditPermission() {
    return <?php echo hasPermission('purchase_imports', 'edit') ? 'true' : 'false'; ?>;
}
function hasDeletePermission() {
    return <?php echo hasPermission('purchase_imports', 'delete') ? 'true' : 'false'; ?>;
}

// ==================== UTILITIES ====================

function fmtCurrency(amount, symbol) {
    const n = parseFloat(amount);
    const s = symbol || selectedCurrencySymbol || 'TZS';
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
    document.querySelectorAll('.import-toast').forEach(t => t.remove());
    const toast = document.createElement('div');
    toast.className = `import-toast alert alert-${type} alert-dismissible fade show`;
    toast.style.cssText = 'position:fixed;top:80px;right:20px;z-index:9999;min-width:300px;box-shadow:0 4px 6px rgba(0,0,0,0.2);';
    toast.innerHTML = `${message}<button type="button" class="btn-close" data-bs-dismiss="alert"></button>`;
    document.body.appendChild(toast);
    setTimeout(() => { toast.classList.remove('show'); setTimeout(() => toast.remove(), 150); }, 5000);
}
</script>

<?php require_once '../../includes/footer.php'; ?>
