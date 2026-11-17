<?php
/**
 * Suppliers Import Page
 * Upload CSV/Excel files to import suppliers in bulk
 */

require_once '../../config/database.php';
require_once '../../includes/functions.php';

$pageTitle = __('import_suppliers');

if (!hasPermission('suppliers', 'import')) {
    setAlert(__('no_permission'), 'danger');
    redirect('');
}

require_once '../../includes/header.php';
?>

<div class="row mb-3">
    <div class="col-md-6">
        <h2><i class="bi bi-upload"></i> <?php _e('import_suppliers'); ?></h2>
    </div>
    <div class="col-md-6 text-end">
        <a href="<?php echo BASE_URL; ?>modules/masters/suppliers.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> <?php _e('back_to_suppliers'); ?>
        </a>
        <a href="<?php echo BASE_URL; ?>api/suppliers-import.php?action=template" class="btn btn-outline-success">
            <i class="bi bi-download"></i> <?php _e('download_template'); ?>
        </a>
    </div>
</div>

<!-- Step 1: Upload -->
<div class="card mb-3" id="uploadSection">
    <div class="card-header">
        <strong><i class="bi bi-1-circle"></i> <?php _e('upload_file'); ?></strong>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-8">
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
                        <li><code>name</code> - <?php _e('supplier_name'); ?></li>
                    </ul>
                    <strong class="mt-2 d-block"><?php _e('optional_columns'); ?>:</strong>
                    <ul class="mb-0 mt-1">
                        <li><code>contact_person</code></li>
                        <li><code>phone</code></li>
                        <li><code>email</code></li>
                        <li><code>address</code></li>
                        <li><code>gstin</code></li>
                        <li><code>pan</code></li>
                        <li><code>opening_balance</code></li>
                    </ul>
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
            <div class="col-md-4">
                <div class="card bg-success text-white">
                    <div class="card-body text-center">
                        <h3 id="newSuppliersCount">0</h3>
                        <small><?php _e('new_suppliers'); ?></small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card bg-warning text-dark">
                    <div class="card-body text-center">
                        <h3 id="skippedCount">0</h3>
                        <small><?php _e('skipped_duplicates'); ?></small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card bg-danger text-white">
                    <div class="card-body text-center">
                        <h3 id="errorsCount">0</h3>
                        <small><?php _e('errors'); ?></small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Errors List -->
        <div id="errorsList" class="mb-3" style="display: none;">
            <div class="alert alert-danger">
                <strong><i class="bi bi-exclamation-triangle"></i> <?php _e('rows_with_errors'); ?>:</strong>
                <div id="errorsContent" class="mt-2" style="max-height: 200px; overflow-y: auto;"></div>
            </div>
        </div>

        <!-- Preview Table -->
        <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
            <table class="table table-sm table-hover">
                <thead class="table-light sticky-top">
                    <tr>
                        <th><?php _e('row'); ?></th>
                        <th><?php _e('status'); ?></th>
                        <th><?php _e('name'); ?></th>
                        <th><?php _e('contact_person'); ?></th>
                        <th><?php _e('phone'); ?></th>
                        <th><?php _e('email'); ?></th>
                        <th><?php _e('opening_balance'); ?></th>
                    </tr>
                </thead>
                <tbody id="previewTableBody">
                </tbody>
            </table>
        </div>

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
            <div class="col-md-4">
                <h4 id="progressProcessed">0</h4>
                <small class="text-muted"><?php _e('processed'); ?></small>
            </div>
            <div class="col-md-4">
                <h4 id="progressCreated" class="text-success">0</h4>
                <small class="text-muted"><?php _e('created'); ?></small>
            </div>
            <div class="col-md-4">
                <h4 id="progressSkipped" class="text-warning">0</h4>
                <small class="text-muted"><?php _e('skipped_duplicates'); ?></small>
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
            <div class="col-md-4">
                <h3 id="finalTotal">0</h3>
                <small class="text-muted"><?php _e('total_processed'); ?></small>
            </div>
            <div class="col-md-4">
                <h3 class="text-success" id="finalCreated">0</h3>
                <small class="text-muted"><?php _e('suppliers_created'); ?></small>
            </div>
            <div class="col-md-4">
                <h3 class="text-warning" id="finalSkipped">0</h3>
                <small class="text-muted"><?php _e('skipped_duplicates'); ?></small>
            </div>
        </div>
        <a href="<?php echo BASE_URL; ?>modules/masters/suppliers.php" class="btn btn-primary">
            <i class="bi bi-truck"></i> <?php _e('view_suppliers'); ?>
        </a>
        <button type="button" class="btn btn-outline-secondary" onclick="resetImport()">
            <i class="bi bi-plus-circle"></i> <?php _e('import_more'); ?>
        </button>
    </div>
</div>

<script>
const API_URL = '<?php echo BASE_URL; ?>api/suppliers-import.php';
let currentFileId = null;
let totalRows = 0;
let importStats = { created: 0, skipped: 0, errors: 0 };

// Upload file
async function uploadFile() {
    const fileInput = document.getElementById('importFile');
    const file = fileInput.files[0];

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

        const uploadResult = await uploadResponse.json();

        if (!uploadResult.success) {
            throw new Error(uploadResult.error);
        }

        currentFileId = uploadResult.fileId;

        // Step 2: Get preview
        uploadBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> <?php _e('analyzing'); ?>...';

        const previewResponse = await fetch(`${API_URL}?action=preview&fileId=${currentFileId}`);
        const previewResult = await previewResponse.json();

        if (!previewResult.success) {
            throw new Error(previewResult.error);
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

// Show preview
function showPreview(data) {
    // Update summary cards
    document.getElementById('newSuppliersCount').textContent = data.summary.newSuppliers;
    document.getElementById('skippedCount').textContent = data.summary.skippedDuplicates;
    document.getElementById('errorsCount').textContent = data.summary.errors;

    totalRows = data.summary.newSuppliers + data.summary.skippedDuplicates;

    // Show errors
    if (data.errors && data.errors.length > 0) {
        document.getElementById('errorsList').style.display = 'block';
        let errorsHtml = '<ul class="mb-0">';
        data.errors.forEach(err => {
            errorsHtml += `<li><strong><?php _e('row'); ?> ${err.row}</strong> (${escapeHtml(err.name || '?')}): ${err.errors.join(', ')}</li>`;
        });
        errorsHtml += '</ul>';
        document.getElementById('errorsContent').innerHTML = errorsHtml;
    } else {
        document.getElementById('errorsList').style.display = 'none';
    }

    // Build preview table
    const tbody = document.getElementById('previewTableBody');
    tbody.innerHTML = '';

    data.previewRows.forEach(row => {
        let statusBadge = '';
        if (row.status === 'new') {
            statusBadge = '<span class="badge bg-success"><?php _e('new'); ?></span>';
        } else {
            statusBadge = '<span class="badge bg-warning text-dark"><?php _e('duplicate'); ?></span>';
        }

        tbody.innerHTML += `
            <tr class="${row.status === 'duplicate' ? 'table-warning' : ''}">
                <td>${row.row}</td>
                <td>${statusBadge}</td>
                <td>${escapeHtml(row.name)}</td>
                <td>${escapeHtml(row.contact_person || '-')}</td>
                <td>${escapeHtml(row.phone || '-')}</td>
                <td>${escapeHtml(row.email || '-')}</td>
                <td>${formatNumber(row.opening_balance)}</td>
            </tr>
        `;
    });

    // Preview note
    if (data.summary.totalRows > 100) {
        document.getElementById('previewNote').textContent =
            `<?php _e('showing_first_100'); ?> ${data.summary.totalRows} <?php _e('rows'); ?>`;
    }

    // Disable confirm if no valid new rows
    document.getElementById('confirmImportBtn').disabled = data.summary.newSuppliers === 0;

    // Show preview section
    document.getElementById('uploadSection').style.display = 'none';
    document.getElementById('previewSection').style.display = 'block';
}

// Start import
async function startImport() {
    if (!currentFileId || totalRows === 0) return;

    document.getElementById('previewSection').style.display = 'none';
    document.getElementById('progressSection').style.display = 'block';

    importStats = { created: 0, skipped: 0, errors: 0 };
    let offset = 0;
    const limit = 100;

    try {
        while (true) {
            const response = await fetch(`${API_URL}?action=process&fileId=${currentFileId}&offset=${offset}&limit=${limit}`);
            const result = await response.json();

            if (!result.success) {
                throw new Error(result.error);
            }

            // Update stats
            importStats.created += result.created;
            importStats.skipped += result.skipped;
            importStats.errors += result.errors.length;

            // Update progress UI
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

// Show complete
function showComplete() {
    document.getElementById('progressSection').style.display = 'none';
    document.getElementById('completeSection').style.display = 'block';

    document.getElementById('finalTotal').textContent = importStats.created + importStats.skipped;
    document.getElementById('finalCreated').textContent = importStats.created;
    document.getElementById('finalSkipped').textContent = importStats.skipped;
}

// Reset import
function resetImport() {
    // Cleanup if file was uploaded
    if (currentFileId) {
        fetch(`${API_URL}?action=cleanup&fileId=${currentFileId}`);
        currentFileId = null;
    }

    // Reset UI
    document.getElementById('importFile').value = '';
    document.getElementById('uploadSection').style.display = 'block';
    document.getElementById('previewSection').style.display = 'none';
    document.getElementById('progressSection').style.display = 'none';
    document.getElementById('completeSection').style.display = 'none';

    // Reset progress bar
    document.getElementById('importProgressBar').style.width = '0%';
    document.getElementById('importProgressBar').textContent = '0%';
}

// Utility functions
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatNumber(num) {
    return new Intl.NumberFormat().format(num || 0);
}
</script>

<?php require_once '../../includes/footer.php'; ?>
