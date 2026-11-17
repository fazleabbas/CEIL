<?php
/**
 * Products Import Page
 * Upload CSV/Excel files to import products in bulk
 */

require_once '../../config/database.php';
require_once '../../includes/functions.php';

$pageTitle = __('import_products');

if (!hasPermission('products', 'import')) {
    setAlert(__('no_permission'), 'danger');
    redirect('');
}

require_once '../../includes/header.php';
?>

<div class="row mb-3">
    <div class="col-md-6">
        <h2><i class="bi bi-upload"></i> <?php _e('import_products'); ?></h2>
    </div>
    <div class="col-md-6 text-end">
        <a href="<?php echo BASE_URL; ?>modules/masters/products.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> <?php _e('back_to_products'); ?>
        </a>
        <a href="<?php echo BASE_URL; ?>api/products-import.php?action=template" class="btn btn-outline-success">
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
                        <li><code>name</code> - <?php _e('product_name'); ?></li>
                        <li><code>category</code> - <?php _e('category_name'); ?></li>
                        <li><code>unit</code> - <?php _e('unit'); ?> (e.g., pcs, kg)</li>
                    </ul>
                    <strong class="mt-2 d-block"><?php _e('optional_columns'); ?>:</strong>
                    <ul class="mb-0 mt-1">
                        <li><code>barcode</code></li>
                        <li><code>default_selling_price</code> or <code>price</code></li>
                        <li><code>description</code></li>
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
        <!-- Summary Cards (clickable filters) -->
        <style>
            #summaryCards .filter-card { cursor: pointer; transition: transform .1s, box-shadow .1s; }
            #summaryCards .filter-card:hover { transform: translateY(-2px); box-shadow: 0 .25rem .75rem rgba(0,0,0,.15); }
            #summaryCards .filter-card.active { outline: 3px solid #0d6efd; outline-offset: 2px; }
        </style>
        <div class="row mb-4" id="summaryCards">
            <div class="col-md-3">
                <div class="card bg-success text-white filter-card" data-filter="new">
                    <div class="card-body text-center">
                        <h3 id="newProductsCount">0</h3>
                        <small><?php _e('new_products'); ?></small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-warning text-dark filter-card" data-filter="update">
                    <div class="card-body text-center">
                        <h3 id="updateProductsCount">0</h3>
                        <small><?php _e('products_to_update'); ?></small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-info text-white filter-card" data-filter="newCategory">
                    <div class="card-body text-center">
                        <h3 id="newCategoriesCount">0</h3>
                        <small><?php _e('new_categories'); ?></small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-danger text-white filter-card" data-filter="errors">
                    <div class="card-body text-center">
                        <h3 id="errorsCount">0</h3>
                        <small><?php _e('errors'); ?></small>
                    </div>
                </div>
            </div>
        </div>

        <!-- New Categories List -->
        <div id="newCategoriesList" class="mb-3" style="display: none;">
            <div class="alert alert-info">
                <strong><i class="bi bi-folder-plus"></i> <?php _e('categories_to_create'); ?>:</strong>
                <span id="newCategoriesNames"></span>
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
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div>
                <label class="me-2 small text-muted"><?php _e('rows'); ?>:</label>
                <select id="pageSizeSelect" class="form-select form-select-sm d-inline-block" style="width:auto;">
                    <option value="25">25</option>
                    <option value="50" selected>50</option>
                    <option value="100">100</option>
                    <option value="0"><?php _e('all'); ?></option>
                </select>
            </div>
            <div class="small text-muted" id="previewNote"></div>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover">
                <thead class="table-light">
                    <tr>
                        <th><?php _e('row'); ?></th>
                        <th><?php _e('status'); ?></th>
                        <th><?php _e('name'); ?></th>
                        <th><?php _e('category'); ?></th>
                        <th><?php _e('unit'); ?></th>
                        <th><?php _e('barcode'); ?></th>
                        <th><?php _e('price'); ?></th>
                    </tr>
                </thead>
                <tbody id="previewTableBody">
                </tbody>
            </table>
        </div>

        <nav class="d-flex justify-content-between align-items-center" id="paginationNav">
            <button type="button" class="btn btn-sm btn-outline-secondary" id="prevPageBtn">
                <i class="bi bi-chevron-left"></i> <?php _e('previous'); ?>
            </button>
            <span class="small text-muted" id="pageInfo"></span>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="nextPageBtn">
                <?php _e('next'); ?> <i class="bi bi-chevron-right"></i>
            </button>
        </nav>

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
            <div class="col-md-3">
                <h4 id="progressProcessed">0</h4>
                <small class="text-muted"><?php _e('processed'); ?></small>
            </div>
            <div class="col-md-3">
                <h4 id="progressCreated" class="text-success">0</h4>
                <small class="text-muted"><?php _e('created'); ?></small>
            </div>
            <div class="col-md-3">
                <h4 id="progressUpdated" class="text-warning">0</h4>
                <small class="text-muted"><?php _e('updated'); ?></small>
            </div>
            <div class="col-md-3">
                <h4 id="progressErrors" class="text-danger">0</h4>
                <small class="text-muted"><?php _e('errors'); ?></small>
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
            <div class="col-md-3">
                <h3 id="finalTotal">0</h3>
                <small class="text-muted"><?php _e('total_processed'); ?></small>
            </div>
            <div class="col-md-3">
                <h3 class="text-success" id="finalCreated">0</h3>
                <small class="text-muted"><?php _e('products_created'); ?></small>
            </div>
            <div class="col-md-3">
                <h3 class="text-warning" id="finalUpdated">0</h3>
                <small class="text-muted"><?php _e('products_updated'); ?></small>
            </div>
            <div class="col-md-3">
                <h3 class="text-info" id="finalCategories">0</h3>
                <small class="text-muted"><?php _e('categories_created'); ?></small>
            </div>
        </div>
        <a href="<?php echo BASE_URL; ?>modules/masters/products.php" class="btn btn-primary">
            <i class="bi bi-box"></i> <?php _e('view_products'); ?>
        </a>
        <button type="button" class="btn btn-outline-secondary" onclick="resetImport()">
            <i class="bi bi-plus-circle"></i> <?php _e('import_more'); ?>
        </button>
    </div>
</div>

<script>
const API_URL = '<?php echo BASE_URL; ?>api/products-import.php';
let currentFileId = null;
let totalRows = 0;
let importStats = { created: 0, updated: 0, errors: 0, categoriesCreated: 0 };
let allPreviewRows = [];
let filteredRows = [];
let currentFilter = null; // null = show all
let currentPage = 1;
let pageSize = 50;

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
    document.getElementById('newProductsCount').textContent = data.summary.newProducts;
    document.getElementById('updateProductsCount').textContent = data.summary.updateProducts;
    document.getElementById('newCategoriesCount').textContent = data.summary.newCategories;
    document.getElementById('errorsCount').textContent = data.summary.errors;

    totalRows = data.summary.newProducts + data.summary.updateProducts;

    // Show new categories
    if (data.newCategories && data.newCategories.length > 0) {
        document.getElementById('newCategoriesList').style.display = 'block';
        document.getElementById('newCategoriesNames').textContent = data.newCategories.join(', ');
    } else {
        document.getElementById('newCategoriesList').style.display = 'none';
    }

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

    // Store full dataset and reset filter/page state
    allPreviewRows = data.previewRows || [];
    currentFilter = null;
    currentPage = 1;
    document.querySelectorAll('#summaryCards .filter-card').forEach(c => c.classList.remove('active'));
    applyFilterAndRender();

    // Disable confirm if no valid rows
    document.getElementById('confirmImportBtn').disabled = totalRows === 0;

    // Show preview section
    document.getElementById('uploadSection').style.display = 'none';
    document.getElementById('previewSection').style.display = 'block';
}

// Filter + paginate + render
function applyFilterAndRender() {
    if (currentFilter === 'new') {
        filteredRows = allPreviewRows.filter(r => r.status === 'new');
    } else if (currentFilter === 'update') {
        filteredRows = allPreviewRows.filter(r => r.status === 'update');
    } else if (currentFilter === 'newCategory') {
        filteredRows = allPreviewRows.filter(r => r.isNewCategory);
    } else {
        filteredRows = allPreviewRows.slice();
    }
    renderPage();
}

function renderPage() {
    const tbody = document.getElementById('previewTableBody');
    tbody.innerHTML = '';

    const total = filteredRows.length;
    const size = pageSize === 0 ? (total || 1) : pageSize;
    const totalPages = Math.max(1, Math.ceil(total / size));
    if (currentPage > totalPages) currentPage = totalPages;
    const start = (currentPage - 1) * size;
    const end = Math.min(start + size, total);
    const slice = filteredRows.slice(start, end);

    let html = '';
    slice.forEach(row => {
        let statusBadge = row.status === 'new'
            ? '<span class="badge bg-success"><?php _e('new'); ?></span>'
            : '<span class="badge bg-warning text-dark"><?php _e('update'); ?></span>';
        if (row.isNewCategory) {
            statusBadge += ' <span class="badge bg-info"><?php _e('new_category'); ?></span>';
        }
        html += `<tr>
            <td>${row.row}</td>
            <td>${statusBadge}</td>
            <td>${escapeHtml(row.name)}</td>
            <td>${escapeHtml(row.category)}</td>
            <td>${escapeHtml(row.unit)}</td>
            <td>${escapeHtml(row.barcode || '-')}</td>
            <td>${formatNumber(row.price)}</td>
        </tr>`;
    });
    tbody.innerHTML = html;

    // Pagination info + controls
    const note = total === 0
        ? '<?php _e('no_rows_to_show'); ?>'
        : `<?php _e('showing'); ?> ${start + 1}-${end} <?php _e('of'); ?> ${total}`;
    document.getElementById('previewNote').textContent = note;
    document.getElementById('pageInfo').textContent =
        `<?php _e('page'); ?> ${currentPage} / ${totalPages}`;
    document.getElementById('prevPageBtn').disabled = currentPage <= 1;
    document.getElementById('nextPageBtn').disabled = currentPage >= totalPages;
    document.getElementById('paginationNav').style.display = (pageSize === 0 || totalPages <= 1) ? 'none' : 'flex';
}

// Wire up card clicks + pagination controls (after DOM ready)
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('#summaryCards .filter-card').forEach(card => {
        card.addEventListener('click', function () {
            const filter = this.dataset.filter;
            // Errors card: just scroll to error list (separate panel)
            if (filter === 'errors') {
                const el = document.getElementById('errorsList');
                if (el && el.style.display !== 'none') el.scrollIntoView({behavior: 'smooth', block: 'start'});
                return;
            }
            // Toggle: click active card to clear
            if (currentFilter === filter) {
                currentFilter = null;
                this.classList.remove('active');
            } else {
                currentFilter = filter;
                document.querySelectorAll('#summaryCards .filter-card').forEach(c => c.classList.remove('active'));
                this.classList.add('active');
            }
            currentPage = 1;
            applyFilterAndRender();
        });
    });

    document.getElementById('pageSizeSelect').addEventListener('change', function () {
        pageSize = parseInt(this.value, 10);
        currentPage = 1;
        renderPage();
    });
    document.getElementById('prevPageBtn').addEventListener('click', function () {
        if (currentPage > 1) { currentPage--; renderPage(); }
    });
    document.getElementById('nextPageBtn').addEventListener('click', function () {
        currentPage++;
        renderPage();
    });
});

// Start import
async function startImport() {
    if (!currentFileId || totalRows === 0) return;
    
    document.getElementById('previewSection').style.display = 'none';
    document.getElementById('progressSection').style.display = 'block';
    
    importStats = { created: 0, updated: 0, errors: 0, categoriesCreated: 0 };
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
            importStats.updated += result.updated;
            importStats.errors += result.errors.length;
            importStats.categoriesCreated += result.categoriesCreated;
            
            // Update progress UI
            const percent = Math.round((result.processed / result.total) * 100);
            document.getElementById('importProgressBar').style.width = percent + '%';
            document.getElementById('importProgressBar').textContent = percent + '%';
            
            document.getElementById('progressProcessed').textContent = result.processed;
            document.getElementById('progressCreated').textContent = importStats.created;
            document.getElementById('progressUpdated').textContent = importStats.updated;
            document.getElementById('progressErrors').textContent = importStats.errors;
            
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
    
    document.getElementById('finalTotal').textContent = importStats.created + importStats.updated;
    document.getElementById('finalCreated').textContent = importStats.created;
    document.getElementById('finalUpdated').textContent = importStats.updated;
    document.getElementById('finalCategories').textContent = importStats.categoriesCreated;
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
