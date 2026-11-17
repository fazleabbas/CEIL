<?php
/**
 * Cost Centres Import Page
 *
 * Upload a CSV/Excel sheet to create cost centres in bulk, at any depth, via
 * Code + Parent Code columns. Mirrors modules/masters/locations-import.php and
 * talks only to api/cost_centres-import.php.
 *
 * Parent is matched by CODE, not name: cost centre names repeat heavily
 * (78 x "Equipment Container" on this tenant), and the UNIQUE key is the code.
 *
 * The preview lists rows in the order they will actually be written (parents
 * before children), which is why a sheet can list a child above its parent.
 */

require_once '../../config/database.php';
require_once '../../includes/functions.php';

$pageTitle = __('import_cost_centres');

if (!hasPermission('cost_centres', 'create')) {
    setAlert(__('no_permission'), 'danger');
    redirect('');
}

require_once '../../includes/header.php';
?>

<div class="row mb-3">
    <div class="col-md-6">
        <h2><i class="bi bi-upload"></i> <?php _e('import_cost_centres'); ?></h2>
    </div>
    <div class="col-md-6 text-end">
        <a href="<?php echo BASE_URL; ?>modules/masters/cost_centres.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> <?php _e('back_to_cost_centres'); ?>
        </a>
        <a href="<?php echo BASE_URL; ?>api/cost_centres-import.php?action=template" class="btn btn-outline-success">
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
            <div class="col-md-7">
                <div class="mb-3">
                    <label for="importFile" class="form-label"><?php _e('select_file'); ?></label>
                    <input type="file" class="form-control" id="importFile" accept=".csv,.xlsx">
                    <div class="form-text"><?php _e('supported_formats'); ?>: CSV, XLSX (Excel)</div>
                </div>
                <button type="button" class="btn btn-primary" onclick="uploadFile()" id="uploadBtn">
                    <i class="bi bi-cloud-upload"></i> <?php _e('upload_and_preview'); ?>
                </button>
            </div>
            <div class="col-md-5">
                <div class="alert alert-info mb-0">
                    <strong><?php _e('required_columns'); ?>:</strong>
                    <ul class="mb-0 mt-2">
                        <li><code>code</code> - <?php _e('cost_centre_code'); ?></li>
                        <li><code>name</code> - <?php _e('cost_centre_name'); ?></li>
                    </ul>
                    <strong class="mt-2 d-block"><?php _e('optional_columns'); ?>:</strong>
                    <ul class="mb-0 mt-1">
                        <li><code>parent code</code></li>
                        <li><code>description</code></li>
                        <li><code>start date</code>, <code>end date</code></li>
                        <li><code>status</code> - active / completed / on_hold</li>
                        <li><code>budget</code></li>
                        <li><code>active</code> - Yes/No</li>
                    </ul>
                    <hr class="my-2">
                    <small class="text-muted d-block">
                        <i class="bi bi-upc-scan"></i> <?php _e('cc_import_hint_code'); ?>
                    </small>
                    <small class="text-muted d-block mt-1">
                        <i class="bi bi-diagram-3"></i> <?php _e('cc_import_hint_depth'); ?>
                    </small>
                    <small class="text-muted d-block mt-1">
                        <i class="bi bi-shield-check"></i> <?php _e('cc_import_hint_existing'); ?>
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
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card bg-success text-white">
                    <div class="card-body text-center">
                        <h3 id="newCount">0</h3>
                        <small><?php _e('new_cost_centres'); ?></small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card bg-info text-white">
                    <div class="card-body text-center">
                        <h3 id="updateCount">0</h3>
                        <small><?php _e('to_be_updated'); ?></small>
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

        <div id="errorsList" class="mb-3" style="display: none;">
            <div class="alert alert-danger">
                <strong><i class="bi bi-exclamation-triangle"></i> <?php _e('rows_with_errors'); ?>:</strong>
                <div class="small"><?php _e('cc_import_errors_note'); ?></div>
                <div id="errorsContent" class="mt-2" style="max-height: 220px; overflow-y: auto;"></div>
            </div>
        </div>

        <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
            <table class="table table-sm table-hover">
                <thead class="table-light sticky-top">
                    <tr>
                        <th><?php _e('row'); ?></th>
                        <th><?php _e('status'); ?></th>
                        <th><?php _e('cost_centre_code'); ?></th>
                        <th><?php _e('name'); ?></th>
                        <th><?php _e('parent'); ?></th>
                    </tr>
                </thead>
                <tbody id="previewTableBody"></tbody>
            </table>
        </div>

        <div class="text-muted mt-2 small" id="previewNote"></div>

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
                 role="progressbar" id="importProgressBar" style="width: 0%">0%</div>
        </div>
        <div class="row text-center">
            <div class="col-md-4">
                <h4 id="progressProcessed">0</h4>
                <small class="text-muted"><?php _e('processed'); ?></small>
            </div>
            <div class="col-md-4">
                <h4 id="progressCreated" class="text-success">0</h4>
                <small class="text-muted"><?php _e('created'); ?></small>
            </div>
            <div class="col-md-4">
                <h4 id="progressUpdated" class="text-info">0</h4>
                <small class="text-muted"><?php _e('updated'); ?></small>
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
                <small class="text-muted"><?php _e('cost_centres_created'); ?></small>
            </div>
            <div class="col-md-4">
                <h3 class="text-info" id="finalUpdated">0</h3>
                <small class="text-muted"><?php _e('cost_centres_updated'); ?></small>
            </div>
        </div>
        <div id="finalErrors" class="alert alert-warning text-start" style="display:none;"></div>
        <a href="<?php echo BASE_URL; ?>modules/masters/cost_centres.php" class="btn btn-primary">
            <i class="bi bi-building"></i> <?php _e('view_cost_centres'); ?>
        </a>
        <button type="button" class="btn btn-outline-secondary" onclick="resetImport()">
            <i class="bi bi-plus-circle"></i> <?php _e('import_more'); ?>
        </button>
    </div>
</div>

<script>
const API_URL = '<?php echo BASE_URL; ?>api/cost_centres-import.php';
let currentFileId = null;
let planCount = 0;
let importStats = { created: 0, updated: 0, errors: [] };

async function uploadFile() {
    const fileInput = document.getElementById('importFile');
    const file = fileInput.files[0];
    if (!file) {
        alert('<?php echo addslashes(__('please_select_file')); ?>');
        return;
    }

    const uploadBtn = document.getElementById('uploadBtn');
    uploadBtn.disabled = true;
    uploadBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> <?php echo addslashes(__('uploading')); ?>...';

    try {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('action', 'upload');

        const uploadResult = await (await fetch(API_URL, { method: 'POST', body: formData })).json();
        if (!uploadResult.success) throw new Error(uploadResult.error);
        currentFileId = uploadResult.fileId;

        uploadBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> <?php echo addslashes(__('analyzing')); ?>...';

        const previewResult = await (await fetch(`${API_URL}?action=preview&fileId=${encodeURIComponent(currentFileId)}`)).json();
        if (!previewResult.success) throw new Error(previewResult.error);

        showPreview(previewResult);
    } catch (error) {
        alert('<?php echo addslashes(__('error')); ?>: ' + error.message);
    } finally {
        uploadBtn.disabled = false;
        uploadBtn.innerHTML = '<i class="bi bi-cloud-upload"></i> <?php echo addslashes(__('upload_and_preview')); ?>';
    }
}

function showPreview(data) {
    document.getElementById('newCount').textContent = data.summary.newCostCentres;
    document.getElementById('updateCount').textContent = data.summary.updCostCentres;
    document.getElementById('errorsCount').textContent = data.summary.errors;

    planCount = data.planCount;

    if (data.errors && data.errors.length) {
        document.getElementById('errorsList').style.display = 'block';
        let html = '<ul class="mb-0">';
        data.errors.forEach(err => {
            html += '<li><strong><?php echo addslashes(__('row')); ?> ' + err.row + '</strong>' +
                    (err.value ? ' (' + escapeHtml(err.value) + ')' : '') + ': ' + escapeHtml(err.error) + '</li>';
        });
        document.getElementById('errorsContent').innerHTML = html + '</ul>';
    } else {
        document.getElementById('errorsList').style.display = 'none';
    }

    const tbody = document.getElementById('previewTableBody');
    tbody.innerHTML = '';
    data.previewRows.forEach(row => {
        const isNew = row.action === 'new';
        const badge = isNew
            ? '<span class="badge bg-success"><?php echo addslashes(__('new')); ?></span>'
            : '<span class="badge bg-info"><?php echo addslashes(__('update')); ?></span>';

        // Flag a parent this very sheet creates — it explains why a row can sit
        // above its own parent and still import.
        let parentDisplay = row.parent ? escapeHtml(row.parent) : '<span class="text-muted">—</span>';
        if (row.parentFrom === 'sheet') {
            parentDisplay += ' <span class="badge bg-secondary"><?php echo addslashes(__('from_this_sheet')); ?></span>';
        }

        tbody.innerHTML += '<tr class="' + (isNew ? '' : 'table-info') + '">' +
            '<td>' + row.row + '</td>' +
            '<td>' + badge + '</td>' +
            '<td><code>' + escapeHtml(row.code) + '</code></td>' +
            '<td>' + escapeHtml(row.name) + '</td>' +
            '<td>' + parentDisplay + '</td>' +
            '</tr>';
    });

    let noteText = '<?php echo addslashes(__('cc_import_preview_order')); ?>';
    if (planCount > 100) {
        noteText += ' <?php echo addslashes(__('showing_first_100')); ?> ' + planCount + ' <?php echo addslashes(__('rows')); ?>.';
    }
    document.getElementById('previewNote').textContent = noteText;

    document.getElementById('confirmImportBtn').disabled = (planCount === 0);

    document.getElementById('uploadSection').style.display = 'none';
    document.getElementById('previewSection').style.display = 'block';
}

async function startImport() {
    if (!currentFileId || planCount === 0) return;

    document.getElementById('previewSection').style.display = 'none';
    document.getElementById('progressSection').style.display = 'block';

    importStats = { created: 0, updated: 0, errors: [] };
    let offset = 0;
    const limit = 100;

    try {
        while (true) {
            const result = await (await fetch(
                `${API_URL}?action=process&fileId=${encodeURIComponent(currentFileId)}&offset=${offset}&limit=${limit}`
            )).json();
            if (!result.success) throw new Error(result.error);

            importStats.created += result.created;
            importStats.updated += result.updated;
            if (result.errors && result.errors.length) importStats.errors.push(...result.errors);

            const percent = result.total ? Math.round((result.processed / result.total) * 100) : 100;
            document.getElementById('importProgressBar').style.width = percent + '%';
            document.getElementById('importProgressBar').textContent = percent + '%';
            document.getElementById('progressProcessed').textContent = result.processed;
            document.getElementById('progressCreated').textContent = importStats.created;
            document.getElementById('progressUpdated').textContent = importStats.updated;

            if (!result.hasMore) break;
            offset = result.processed;
        }

        await fetch(`${API_URL}?action=cleanup&fileId=${encodeURIComponent(currentFileId)}`);
        showComplete();
    } catch (error) {
        alert('<?php echo addslashes(__('import_error')); ?>: ' + error.message);
        resetImport();
    }
}

function showComplete() {
    document.getElementById('progressSection').style.display = 'none';
    document.getElementById('completeSection').style.display = 'block';

    document.getElementById('finalTotal').textContent = importStats.created + importStats.updated;
    document.getElementById('finalCreated').textContent = importStats.created;
    document.getElementById('finalUpdated').textContent = importStats.updated;

    const box = document.getElementById('finalErrors');
    if (importStats.errors.length) {
        let html = '<strong><?php echo addslashes(__('rows_with_errors')); ?>:</strong><ul class="mb-0 mt-1">';
        importStats.errors.forEach(e => {
            html += '<li><?php echo addslashes(__('row')); ?> ' + e.row + ': ' + escapeHtml(e.error) + '</li>';
        });
        box.innerHTML = html + '</ul>';
        box.style.display = 'block';
    } else {
        box.style.display = 'none';
    }
}

function resetImport() {
    if (currentFileId) {
        fetch(`${API_URL}?action=cleanup&fileId=${encodeURIComponent(currentFileId)}`);
        currentFileId = null;
    }
    document.getElementById('importFile').value = '';
    document.getElementById('uploadSection').style.display = 'block';
    document.getElementById('previewSection').style.display = 'none';
    document.getElementById('progressSection').style.display = 'none';
    document.getElementById('completeSection').style.display = 'none';
    document.getElementById('importProgressBar').style.width = '0%';
    document.getElementById('importProgressBar').textContent = '0%';
}

function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    const div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML;
}
</script>

<?php require_once '../../includes/footer.php'; ?>
