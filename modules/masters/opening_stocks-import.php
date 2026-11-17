<?php
/**
 * Opening Stocks Import Page
 * Upload CSV/Excel files to import opening stocks in bulk
 */

require_once '../../config/database.php';
require_once '../../includes/functions.php';

$pageTitle = __('opening_stocks_title') . ' - ' . __('import');

if (!hasPermission('opening_stocks', 'import')) {
    setAlert(__('no_permission'), 'danger');
    redirect('');
}

// Get user's accessible locations
$locations = getUserLocations();

require_once '../../includes/header.php';
?>

<div class="row mb-3">
    <div class="col-md-6">
        <h2><i class="bi bi-upload"></i> <?php _e('opening_stocks_title'); ?> - <?php _e('import'); ?></h2>
    </div>
    <div class="col-md-6 text-end">
        <a href="<?php echo BASE_URL; ?>modules/masters/opening_stocks.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> <?php _e('back'); ?>
        </a>
        <a href="<?php echo BASE_URL; ?>api/opening_stocks-import.php?action=template" class="btn btn-outline-success">
            <i class="bi bi-download"></i> <?php _e('download_template'); ?>
        </a>
    </div>
</div>

<!-- Step 1: Location, Date & File Upload -->
<div class="card mb-3" id="uploadSection">
    <div class="card-header">
        <strong><i class="bi bi-1-circle"></i> <?php _e('select_location'); ?> & <?php _e('upload_file'); ?></strong>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-8">
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label for="locationSelect" class="form-label required"><?php _e('location'); ?></label>
                        <select id="locationSelect" class="form-select" required>
                            <option value=""><?php _e('select_location'); ?></option>
                            <?= renderTreeOptions($locations, null) ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label for="stockDate" class="form-label required"><?php _e('stock_date'); ?></label>
                        <input type="date" id="stockDate" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>
                <div class="mb-3">
                    <label for="importFile" class="form-label"><?php _e('select_file'); ?></label>
                    <input type="file" class="form-control" id="importFile" accept=".csv,.xlsx">
                    <div class="form-text"><?php _e('supported_formats'); ?>: CSV, XLSX (Excel)</div>
                </div>
                <button type="button" class="btn btn-primary" onclick="uploadFile()" id="uploadBtn">
                    <i class="bi bi-cloud-upload"></i> <?php _e('upload_and_preview'); ?>
                </button>
            </div>
            <div class="col-md-4">
                <div class="alert alert-info mb-0">
                    <strong><?php _e('required_columns'); ?>:</strong>
                    <ul class="mb-0 mt-2">
                        <li><code>product_name</code> - <?php _e('product'); ?></li>
                        <li><code>quantity</code> - <?php _e('quantity'); ?></li>
                        <li><code>rate_per_unit</code> - <?php _e('rate_per_unit'); ?></li>
                    </ul>
                    <strong class="mt-2 d-block"><?php _e('optional_columns'); ?>:</strong>
                    <ul class="mb-0 mt-1">
                        <li><code>remarks</code></li>
                    </ul>
                    <hr class="my-2">
                    <small class="text-muted">
                        <i class="bi bi-info-circle"></i> Products are matched by name. Unmatched products will be skipped.
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Step 2: Preview -->
<div class="card mb-3" id="previewSection" style="display: none;">
    <div class="card-header d-flex justify-content-between align-items-center">
        <strong><i class="bi bi-2-circle"></i> <?php _e('preview_import'); ?></strong>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="resetImport()">
            <i class="bi bi-arrow-counterclockwise"></i> <?php _e('start_over'); ?>
        </button>
    </div>
    <div class="card-body">
        <!-- Summary Cards -->
        <div class="row mb-4" id="summaryCards">
            <div class="col-md-3">
                <div class="card bg-success text-white">
                    <div class="card-body text-center py-2">
                        <h3 id="matchedCount">0</h3>
                        <small>Ready to Import</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-warning text-dark">
                    <div class="card-body text-center py-2">
                        <h3 id="duplicatesCount">0</h3>
                        <small>Duplicates Found</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-secondary text-white">
                    <div class="card-body text-center py-2">
                        <h3 id="unmatchedCount">0</h3>
                        <small>Unmatched (Skipped)</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-danger text-white">
                    <div class="card-body text-center py-2">
                        <h3 id="errorsCount">0</h3>
                        <small><?php _e('errors'); ?></small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Duplicate Notice -->
        <div id="duplicateActionSection" class="mb-3" style="display: none;">
            <div class="alert alert-warning mb-0">
                <strong><i class="bi bi-exclamation-triangle"></i> Duplicate opening stocks found.</strong>
                <p class="mb-0">These products already have opening stock at this location (on the date shown in the preview). They will be <strong>skipped</strong> — edit them individually via the Opening Stocks screen instead.</p>
            </div>
        </div>

        <!-- Unmatched Warning -->
        <div id="unmatchedSection" class="mb-3" style="display: none;">
            <div class="alert alert-secondary">
                <strong><i class="bi bi-question-circle"></i> Unmatched products (will be skipped):</strong>
                <div id="unmatchedContent" class="mt-2" style="max-height: 150px; overflow-y: auto;"></div>
            </div>
        </div>

        <!-- Errors List -->
        <div id="errorsList" class="mb-3" style="display: none;">
            <div class="alert alert-danger">
                <strong><i class="bi bi-exclamation-triangle"></i> <?php _e('rows_with_errors'); ?>:</strong>
                <div id="errorsContent" class="mt-2" style="max-height: 150px; overflow-y: auto;"></div>
            </div>
        </div>

        <!-- Preview Table -->
        <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
            <table class="table table-sm table-hover">
                <thead class="table-light sticky-top">
                    <tr>
                        <th><?php _e('row'); ?></th>
                        <th><?php _e('status'); ?></th>
                        <th><?php _e('product'); ?></th>
                        <th>Matched To</th>
                        <th><?php _e('quantity'); ?></th>
                        <th><?php _e('rate_per_unit'); ?></th>
                        <th class="text-end"><?php _e('total_value'); ?></th>
                        <th>Existing Qty</th>
                    </tr>
                </thead>
                <tbody id="previewTableBody">
                </tbody>
            </table>
        </div>

        <!-- Pagination controls (only shown when previewRows > pageSize) -->
        <nav id="previewPagination" class="mt-2" style="display: none;">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="text-muted small" id="previewPageInfo"></div>
                <ul class="pagination pagination-sm mb-0" id="previewPageList"></ul>
            </div>
        </nav>

        <div class="text-muted mt-2" id="previewNote"></div>

        <!-- Confirm Button -->
        <div class="mt-4 text-center">
            <button type="button" class="btn btn-lg btn-success" onclick="startImport()" id="confirmImportBtn">
                <i class="bi bi-check-circle"></i> <?php _e('confirm_import'); ?>
            </button>
        </div>
    </div>
</div>

<!-- Step 3: Progress -->
<div class="card mb-3" id="progressSection" style="display: none;">
    <div class="card-header">
        <strong><i class="bi bi-3-circle"></i> <?php _e('importing'); ?>...</strong>
    </div>
    <div class="card-body">
        <div class="progress mb-3" style="height: 30px;">
            <div class="progress-bar progress-bar-striped progress-bar-animated"
                 role="progressbar" id="importProgressBar"
                 style="width: 0%">0%</div>
        </div>
        <div class="row text-center" id="progressStats">
            <div class="col">
                <h4 id="progressProcessed">0</h4>
                <small class="text-muted"><?php _e('processed'); ?></small>
            </div>
            <div class="col">
                <h4 id="progressCreated" class="text-success">0</h4>
                <small class="text-muted"><?php _e('created'); ?></small>
            </div>
            <div class="col">
                <h4 id="progressSkipped" class="text-secondary">0</h4>
                <small class="text-muted">Skipped</small>
            </div>
        </div>
    </div>
</div>

<!-- Step 4: Complete -->
<div class="card mb-3" id="completeSection" style="display: none;">
    <div class="card-header bg-success text-white">
        <strong><i class="bi bi-check-circle"></i> <?php _e('import_complete'); ?></strong>
    </div>
    <div class="card-body text-center">
        <div class="row mb-4">
            <div class="col">
                <h3 id="finalTotal">0</h3>
                <small class="text-muted"><?php _e('total_processed'); ?></small>
            </div>
            <div class="col">
                <h3 class="text-success" id="finalCreated">0</h3>
                <small class="text-muted"><?php _e('created'); ?></small>
            </div>
            <div class="col">
                <h3 class="text-secondary" id="finalSkipped" style="cursor: default;">0</h3>
                <small class="text-muted">Skipped</small>
                <div id="finalSkippedLinkWrap" class="mt-1" style="display: none;">
                    <a href="#" id="finalSkippedLink" onclick="showSkippedDetails(); return false;" class="small text-decoration-underline">
                        <i class="bi bi-list-ul"></i> View skipped products
                    </a>
                </div>
            </div>
        </div>
        <a href="<?php echo BASE_URL; ?>modules/masters/opening_stocks.php" class="btn btn-primary">
            <i class="bi bi-clipboard-data"></i> <?php _e('opening_stocks_title'); ?>
        </a>
        <button type="button" class="btn btn-outline-secondary" onclick="resetImport()">
            <i class="bi bi-plus-circle"></i> <?php _e('import_more'); ?>
        </button>
    </div>
</div>

<!-- Skipped Products Modal -->
<div class="modal fade" id="skippedModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-list-ul"></i> Skipped Products</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th style="width: 80px;"><?php _e('row'); ?></th>
                                <th><?php _e('product'); ?></th>
                                <th>Reason</th>
                            </tr>
                        </thead>
                        <tbody id="skippedModalBody"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
const API_URL = '<?php echo BASE_URL; ?>api/opening_stocks-import.php';
let currentFileId = null;
let totalRows = 0;
let importStats = { created: 0, skipped: 0 };
let skippedItems = [];

// Client-side pagination state for the preview table
const previewState = {
    rows: [],
    page: 1,
    pageSize: 100
};

// Build one <tr> for a preview row
function renderPreviewRow(row) {
    let statusBadge = '';
    let existingInfo = '-';

    if (row.status === 'matched') {
        statusBadge = '<span class="badge bg-success">New</span>';
    } else if (row.status === 'duplicate') {
        statusBadge = '<span class="badge bg-warning text-dark">Duplicate (skipped)</span>';
        const dateSuffix = row.existing_date ? ` on ${escapeHtml(row.existing_date)}` : '';
        existingInfo = `${formatNumber(row.existing_quantity)} @ ${formatNumber(row.existing_rate)}${dateSuffix}`;
    } else if (row.status === 'unmatched') {
        statusBadge = '<span class="badge bg-secondary">Unmatched</span>';
    }

    const matchedTo = row.matched_name ? escapeHtml(row.matched_name) : '<span class="text-muted">-</span>';

    return `
        <tr class="${row.status === 'unmatched' ? 'table-light text-muted' : ''}">
            <td>${row.row}</td>
            <td>${statusBadge}</td>
            <td>${escapeHtml(row.product_name)}</td>
            <td>${matchedTo}</td>
            <td>${formatNumber(row.quantity, 3)}</td>
            <td>${formatNumber(row.rate_per_unit, 2)}</td>
            <td class="text-end">${formatNumber(row.total_value, 2)}</td>
            <td>${existingInfo}</td>
        </tr>
    `;
}

// Render the current page of the preview table + pagination controls
function renderPreviewPage() {
    const total = previewState.rows.length;
    const pageSize = previewState.pageSize;
    const totalPages = Math.max(1, Math.ceil(total / pageSize));

    // Clamp current page
    if (previewState.page < 1) previewState.page = 1;
    if (previewState.page > totalPages) previewState.page = totalPages;

    const start = (previewState.page - 1) * pageSize;
    const end = Math.min(start + pageSize, total);
    const slice = previewState.rows.slice(start, end);

    document.getElementById('previewTableBody').innerHTML = slice.map(renderPreviewRow).join('');

    // Pagination UI — hide entirely if everything fits on one page
    const nav = document.getElementById('previewPagination');
    if (totalPages <= 1) {
        nav.style.display = 'none';
        return;
    }
    nav.style.display = 'block';
    document.getElementById('previewPageInfo').textContent =
        `Showing ${(start + 1).toLocaleString()}–${end.toLocaleString()} of ${total.toLocaleString()} rows`;

    // Build a compact page list: « ‹ 1 … (cur-2 cur-1) cur (cur+1 cur+2) … last › »
    const cur = previewState.page;
    const pages = new Set([1, totalPages, cur - 2, cur - 1, cur, cur + 1, cur + 2]);
    const visible = [...pages].filter(p => p >= 1 && p <= totalPages).sort((a, b) => a - b);

    let html = '';
    const item = (label, page, opts = {}) => {
        const cls = ['page-item'];
        if (opts.disabled) cls.push('disabled');
        if (opts.active) cls.push('active');
        const onclick = opts.disabled ? '' : `onclick="goToPreviewPage(${page}); return false;"`;
        return `<li class="${cls.join(' ')}"><a class="page-link" href="#" ${onclick}>${label}</a></li>`;
    };

    html += item('«', 1, { disabled: cur === 1 });
    html += item('‹', cur - 1, { disabled: cur === 1 });
    let prev = 0;
    for (const p of visible) {
        if (p - prev > 1) {
            html += '<li class="page-item disabled"><span class="page-link">…</span></li>';
        }
        html += item(p, p, { active: p === cur });
        prev = p;
    }
    html += item('›', cur + 1, { disabled: cur === totalPages });
    html += item('»', totalPages, { disabled: cur === totalPages });

    document.getElementById('previewPageList').innerHTML = html;
}

function goToPreviewPage(page) {
    previewState.page = page;
    renderPreviewPage();
    // Keep the table top in view when paging
    document.querySelector('.table-responsive').scrollTop = 0;
}

// Parse a fetch Response as JSON, but if it fails, throw an Error containing
// the raw response body so the user can see exactly what the server returned
// (PHP fatal error page, HTML, empty body, etc.).
async function parseJsonResponse(response, label) {
    const text = await response.text();
    if (!text) {
        throw new Error(`${label}: empty response from server (HTTP ${response.status}). ` +
            `Check PHP error log — likely a fatal error before json_encode ran.`);
    }
    try {
        const data = JSON.parse(text);
        // Surface stray PHP output captured by the API even on success
        if (data && data.stray_output) {
            console.warn(`${label} stray PHP output:`, data.stray_output);
        }
        return data;
    } catch (e) {
        // Show first 500 chars of the raw body so we can see HTML/warnings
        const snippet = text.substring(0, 500);
        throw new Error(`${label}: server returned non-JSON (HTTP ${response.status}):\n${snippet}`);
    }
}

// Upload file and get preview
async function uploadFile() {
    const locationId = document.getElementById('locationSelect').value;
    const stockDate = document.getElementById('stockDate').value;
    const fileInput = document.getElementById('importFile');
    const file = fileInput.files[0];

    if (!locationId) {
        alert('<?php _e('please_select_location'); ?>');
        return;
    }
    if (!stockDate) {
        alert('<?php _e('please_select_date'); ?>');
        return;
    }
    if (!file) {
        alert('<?php _e('please_select_file'); ?>');
        return;
    }

    const uploadBtn = document.getElementById('uploadBtn');
    uploadBtn.disabled = true;
    uploadBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> <?php _e('uploading'); ?>...';

    try {
        // Step 1: Upload file
        const formData = new FormData();
        formData.append('file', file);
        formData.append('action', 'upload');

        const uploadResponse = await fetch(API_URL, {
            method: 'POST',
            body: formData
        });
        const uploadResult = await parseJsonResponse(uploadResponse, 'Upload');

        if (!uploadResult.success) {
            throw new Error(uploadResult.error + (uploadResult.where ? ' @ ' + uploadResult.where : ''));
        }

        currentFileId = uploadResult.fileId;

        // Step 2: Get preview
        uploadBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> <?php _e('analyzing'); ?>...';

        const previewUrl = `${API_URL}?action=preview&fileId=${currentFileId}&location_id=${locationId}&stock_date=${stockDate}`;
        const previewResponse = await fetch(previewUrl);
        const previewResult = await parseJsonResponse(previewResponse, 'Preview');

        if (!previewResult.success) {
            throw new Error(previewResult.error + (previewResult.where ? ' @ ' + previewResult.where : ''));
        }

        // Show preview
        showPreview(previewResult);

    } catch (error) {
        alert('<?php _e('error'); ?>: ' + error.message);
    } finally {
        uploadBtn.disabled = false;
        uploadBtn.innerHTML = '<i class="bi bi-cloud-upload"></i> <?php _e('upload_and_preview'); ?>';
    }
}

// Show preview results
function showPreview(data) {
    // Update summary cards
    document.getElementById('matchedCount').textContent = data.summary.matched;
    document.getElementById('duplicatesCount').textContent = data.summary.duplicates;
    document.getElementById('unmatchedCount').textContent = data.summary.unmatched;
    document.getElementById('errorsCount').textContent = data.summary.errors;

    totalRows = data.summary.totalRows;

    // Show duplicate action if there are duplicates
    if (data.summary.duplicates > 0) {
        document.getElementById('duplicateActionSection').style.display = 'block';
    } else {
        document.getElementById('duplicateActionSection').style.display = 'none';
    }

    // Show unmatched products
    const unmatchedRows = data.previewRows.filter(r => r.status === 'unmatched');
    if (unmatchedRows.length > 0) {
        document.getElementById('unmatchedSection').style.display = 'block';
        let html = '<ul class="mb-0">';
        unmatchedRows.forEach(r => {
            html += `<li>Row ${r.row}: <strong>${escapeHtml(r.product_name)}</strong></li>`;
        });
        html += '</ul>';
        document.getElementById('unmatchedContent').innerHTML = html;
    } else {
        document.getElementById('unmatchedSection').style.display = 'none';
    }

    // Show errors
    if (data.errors && data.errors.length > 0) {
        document.getElementById('errorsList').style.display = 'block';
        let errorsHtml = '<ul class="mb-0">';
        data.errors.forEach(err => {
            errorsHtml += `<li><strong>Row ${err.row}</strong> (${escapeHtml(err.product_name || '?')}): ${err.errors.join(', ')}</li>`;
        });
        errorsHtml += '</ul>';
        document.getElementById('errorsContent').innerHTML = errorsHtml;
    } else {
        document.getElementById('errorsList').style.display = 'none';
    }

    // Stash rows for client-side pagination and render the first page
    previewState.rows = data.previewRows || [];
    previewState.page = 1;
    renderPreviewPage();

    // Preview note — flag if file exceeded the server-side parser cap
    const note = document.getElementById('previewNote');
    if (data.summary.totalRows >= 20000) {
        note.textContent = `Note: file has ${data.summary.totalRows.toLocaleString()} rows; ` +
            `only the first 20,000 are previewed and will be imported.`;
    } else {
        note.textContent = '';
    }

    // Only matched (new) rows actually get imported — duplicates are skipped.
    document.getElementById('confirmImportBtn').disabled = data.summary.matched === 0;

    // Show preview section, hide upload
    document.getElementById('uploadSection').style.display = 'none';
    document.getElementById('previewSection').style.display = 'block';
}

// Start chunked import
async function startImport() {
    if (!currentFileId) return;

    const locationId = document.getElementById('locationSelect').value;
    const stockDate = document.getElementById('stockDate').value;

    document.getElementById('previewSection').style.display = 'none';
    document.getElementById('progressSection').style.display = 'block';

    importStats = { created: 0, skipped: 0 };
    skippedItems = [];
    let offset = 0;
    const limit = 100;

    try {
        while (true) {
            const url = `${API_URL}?action=process&fileId=${currentFileId}&location_id=${locationId}&stock_date=${stockDate}&offset=${offset}&limit=${limit}`;
            const response = await fetch(url);
            const result = await parseJsonResponse(response, `Process (offset=${offset})`);

            if (!result.success) {
                throw new Error(result.error + (result.where ? ' @ ' + result.where : ''));
            }

            importStats.created += result.created;
            importStats.skipped += result.skipped;
            if (Array.isArray(result.skippedItems) && result.skippedItems.length > 0) {
                skippedItems = skippedItems.concat(result.skippedItems);
            }

            const percent = Math.round((result.processed / result.total) * 100);
            document.getElementById('importProgressBar').style.width = percent + '%';
            document.getElementById('importProgressBar').textContent = percent + '%';

            document.getElementById('progressProcessed').textContent = result.processed;
            document.getElementById('progressCreated').textContent = importStats.created;
            document.getElementById('progressSkipped').textContent = importStats.skipped;

            if (result.done) {
                break;
            }

            offset = result.processed;
        }

        // Cleanup temp file
        await fetch(`${API_URL}?action=cleanup&fileId=${currentFileId}`);

        // Show complete
        showComplete();

    } catch (error) {
        alert('<?php _e('import_error'); ?>: ' + error.message);
        resetImport();
    }
}

// Show completion summary
function showComplete() {
    document.getElementById('progressSection').style.display = 'none';
    document.getElementById('completeSection').style.display = 'block';

    document.getElementById('finalTotal').textContent = importStats.created + importStats.skipped;
    document.getElementById('finalCreated').textContent = importStats.created;
    document.getElementById('finalSkipped').textContent = importStats.skipped;

    // Show "View skipped products" link only if there are skipped items with details
    const linkWrap = document.getElementById('finalSkippedLinkWrap');
    if (importStats.skipped > 0 && skippedItems.length > 0) {
        linkWrap.style.display = 'block';
    } else {
        linkWrap.style.display = 'none';
    }
}

// Show the skipped products modal
function showSkippedDetails() {
    const body = document.getElementById('skippedModalBody');
    if (!skippedItems.length) {
        body.innerHTML = '<tr><td colspan="3" class="text-center text-muted">No details available.</td></tr>';
    } else {
        body.innerHTML = skippedItems.map(item => `
            <tr>
                <td>${item.row ?? '-'}</td>
                <td>${escapeHtml(item.product_name || '')}</td>
                <td><span class="text-muted">${escapeHtml(item.reason || '')}</span></td>
            </tr>
        `).join('');
    }
    const modal = new bootstrap.Modal(document.getElementById('skippedModal'));
    modal.show();
}

// Reset to initial state
function resetImport() {
    if (currentFileId) {
        fetch(`${API_URL}?action=cleanup&fileId=${currentFileId}`);
        currentFileId = null;
    }

    document.getElementById('importFile').value = '';
    document.getElementById('uploadSection').style.display = 'block';
    document.getElementById('previewSection').style.display = 'none';
    document.getElementById('progressSection').style.display = 'none';
    document.getElementById('completeSection').style.display = 'none';

    document.getElementById('importProgressBar').style.width = '0%';
    document.getElementById('importProgressBar').textContent = '0%';

    // Reset preview pagination state
    previewState.rows = [];
    previewState.page = 1;
    document.getElementById('previewTableBody').innerHTML = '';
    document.getElementById('previewPagination').style.display = 'none';
}

// Utility: escape HTML
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Utility: format number
function formatNumber(num, decimals = 2) {
    return new Intl.NumberFormat(undefined, {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
    }).format(num || 0);
}
</script>

<?php require_once '../../includes/footer.php'; ?>
