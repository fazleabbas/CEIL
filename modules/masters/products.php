<?php
/**
 * Products Management Page - WITH OFFLINE SUPPORT
 */
// Set custom JS file to include sync-manager.js
$customJS = 'assets/js/sync-manager.js';

require_once '../../config/database.php';
require_once '../../includes/functions.php';

$pageTitle = __('products_management');

if (!hasPermission('products', 'view')) {
    setAlert('You do not have permission to access this page', 'danger');
    redirect('');
}

require_once '../../includes/header.php';
?>

<!-- Online/Offline Status Badge -->
<div class="position-fixed" style="top: 70px; right: 20px; z-index: 1000;">
    <span id="onlineStatus" class="badge bg-secondary">
        <i class="bi bi-wifi"></i> <?php _e('checking_connection'); ?>
    </span>
</div>

<div class="row mb-3">
    <div class="col-md-6">
        <h2><i class="bi bi-box"></i> <?php _e('products_management'); ?></h2>
    </div>
    <div class="col-md-6 text-end">
        <button class="btn btn-outline-primary" onclick="manualSync()">
            <i class="bi bi-arrow-repeat"></i> <?php _e('sync_now'); ?>
        </button>
        <?php if (hasPermission('products', 'import')): ?>
        <a href="<?php echo BASE_URL; ?>modules/masters/products-import.php" class="btn btn-outline-success">
            <i class="bi bi-upload"></i> <?php _e('import_products'); ?>
        </a>
        <?php endif; ?>
        <?php if (hasPermission('products', 'create')): ?>
        <button class="btn btn-primary" onclick="showCreateModal()">
            <i class="bi bi-plus-circle"></i> <?php _e('add_new_product'); ?>
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- Search & Filter -->
<div class="card mb-3">
    <div class="card-body">
        <div class="row g-2">
            <div class="col-md-5">
                <input type="text" id="searchInput" class="form-control" 
                       placeholder="<?php _e('search_products'); ?>" 
                       onkeyup="searchProducts()">
            </div>
            <div class="col-md-3">
                <select id="categoryFilter" class="form-select" onchange="filterProducts()">
                    <option value=""><?php _e('all_categories'); ?></option>
                </select>
            </div>
            <div class="col-md-2">
                <button onclick="clearSearch()" class="btn btn-secondary w-100" id="clearBtn" style="display:none;">
                    <i class="bi bi-x-circle"></i> <?php _e('clear'); ?>
                </button>
            </div>
            <div class="col-md-2">
                <button onclick="exportToCSV()" class="btn btn-success w-100" id="exportBtn">
                    <i class="bi bi-file-earmark-excel"></i> <?php _e('export'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Products Table -->
<div class="card">
    <div class="card-body">
        <div id="loadingSpinner" class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden"><?php _e('loading'); ?></span>
            </div>
            <p class="mt-2"><?php _e('loading_products'); ?></p>
        </div>
        
        <div id="productsContainer" style="display:none;">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th style="width: 70px;"><?php _e('sr_no'); ?></th>
                            <th><?php _e('name'); ?></th>
                            <th><?php _e('brand'); ?></th>
                            <th><?php _e('description'); ?></th>
                            <th><?php _e('category'); ?></th>
                            <th><?php _e('unit'); ?></th>
                            <th><?php _e('barcode'); ?></th>
                            <th><?php _e('default_price'); ?></th>
                            <th><?php _e('status'); ?></th>
                            <th><?php _e('actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="productsTableBody">
                        <!-- Populated by JavaScript -->
                    </tbody>
                </table>
            </div>
        </div>
        
        <div id="emptyState" style="display:none;" class="text-center py-5">
            <i class="bi bi-box" style="font-size: 4rem; color: #ccc;"></i>
            <h5 class="mt-3"><?php _e('no_products_found'); ?></h5>
            <p class="text-muted"><?php _e('start_adding_product'); ?></p>
        </div>
    </div>
</div>

<!-- Create/Edit Modal -->
<div class="modal fade" id="productModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="productModalTitle"><?php _e('add_new_product'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="productForm">
                    <input type="hidden" id="productId">
                    
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label for="productName" class="form-label"><?php _e('product_name'); ?> *</label>
                            <input type="text" class="form-control" id="productName" required>
                        </div>

                        <div class="col-md-4 mb-3">
                            <label for="productBarcode" class="form-label"><?php _e('barcode'); ?></label>
                            <input type="text" class="form-control" id="productBarcode">
                            <small class="text-muted"><?php _e('optional'); ?></small>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="productBrand" class="form-label"><?php _e('brand'); ?></label>
                            <input type="text" class="form-control" id="productBrand" placeholder="<?php _e('enter_brand'); ?>">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="productCategory" class="form-label"><?php _e('category'); ?> *</label>
                            <select class="form-select" id="productCategory" required>
                                <option value=""><?php _e('select_category'); ?></option>
                            </select>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="productUnit" class="form-label"><?php _e('unit'); ?> *</label>
                            <select class="form-select" id="productUnit" required>
                                <option value=""><?php _e('select_unit'); ?></option>
                                <!-- Populated dynamically from database -->
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="productPrice" class="form-label"><?php _e('default_selling_price'); ?> *</label>
                            <div class="input-group">
                                <span class="input-group-text">TZS.</span>
                                <input type="number" step="0.01" min="0" class="form-control" id="productPrice" required>
                            </div>
                            <small class="text-muted"><?php _e('used_when_no_location_price'); ?></small>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="productDescription" class="form-label"><?php _e('description'); ?></label>
                        <textarea class="form-control" id="productDescription" rows="2"></textarea>
                    </div>
                    
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="productActive" checked>
                        <label class="form-check-label" for="productActive"><?php _e('active'); ?></label>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                <button type="button" class="btn btn-primary" onclick="saveProduct()">
                    <i class="bi bi-check-circle"></i> <?php _e('save_product'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Translations for JavaScript
const LANG = {
    add_new_product: <?php echo json_encode(__('add_new_product')); ?>,
    edit_product: <?php echo json_encode(__('edit_product')); ?>,
    fill_required_fields: <?php echo json_encode(__('fill_required_fields')); ?>,
    confirm_delete_product: <?php echo json_encode(__('confirm_delete_product')); ?>,
    product_has_transactions: <?php echo json_encode(__('product_has_transactions')); ?>,
    pending: <?php echo json_encode(__('pending')); ?>,
    active: <?php echo json_encode(__('active')); ?>,
    inactive: <?php echo json_encode(__('inactive')); ?>,
    edit: <?php echo json_encode(__('edit')); ?>,
    delete: <?php echo json_encode(__('delete')); ?>,
    set_location_prices: <?php echo json_encode(__('set_location_prices')); ?>,
    delete_only_online: <?php echo json_encode(__('delete_only_online')); ?>,
    export_only_online: <?php echo json_encode(__('export_only_online')); ?>,
    location_pricing_only_online: <?php echo json_encode(__('location_pricing_only_online')); ?>,
    no_data_export: <?php echo json_encode(__('no_data_export')); ?>,
    select_category: <?php echo json_encode(__('select_category')); ?>,
    all_categories: <?php echo json_encode(__('all_categories')); ?>,
    select_unit: <?php echo json_encode(__('select_unit')); ?>
};

// Store data
let productsData = [];
let categoriesData = [];
let unitsData = [];
let productModal;
let currentSearch = '';
let currentCategory = '';

// Initialize
document.addEventListener('DOMContentLoaded', async function() {
    console.log('🎬 Products page loaded');
    
    // Initialize modal
    productModal = new bootstrap.Modal(document.getElementById('productModal'));
    
    // Initialize sync manager
    await syncManager.init();
    
    // Load data
    await loadCategories();
    await loadUnits();
    await loadProducts();
    
    // Update UI based on online status
    updateUIForOnlineStatus();
    
    // Enter key support in modal
    document.getElementById('productForm').addEventListener('keypress', function(e) {
        if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') {
            e.preventDefault();
            saveProduct();
        }
    });
    
    // Listen for online/offline events
    window.addEventListener('online', updateUIForOnlineStatus);
    window.addEventListener('offline', updateUIForOnlineStatus);
});

// Category tree helpers
function buildCategoryTree(items, parentId = null, depth = 0) {
    const branch = [];
    items.forEach(item => {
        const itemParent = item.parent_id ? parseInt(item.parent_id) : null;
        if (itemParent === parentId) {
            const node = { ...item, depth: depth, children: [] };
            node.children = buildCategoryTree(items, parseInt(item.id), depth + 1);
            branch.push(node);
        }
    });
    return branch;
}

function flattenCategoryTree(tree) {
    const result = [];
    tree.forEach(item => {
        const children = item.children;
        const flat = { ...item };
        delete flat.children;
        result.push(flat);
        if (children && children.length > 0) {
            result.push(...flattenCategoryTree(children));
        }
    });
    return result;
}

// Load categories
async function loadCategories() {
    console.log('📥 Loading categories...');

    try {
        categoriesData = await syncManager.getCategories();
        console.log(`✅ Loaded ${categoriesData.length} categories`);

        // Populate category filter and select (tree-indented)
        const categorySelect = document.getElementById('productCategory');
        const categoryFilter = document.getElementById('categoryFilter');

        categorySelect.innerHTML = '<option value="">' + LANG.select_category + '</option>';
        categoryFilter.innerHTML = '<option value="">' + LANG.all_categories + '</option>';

        const tree = buildCategoryTree(categoriesData);
        const sorted = flattenCategoryTree(tree);

        sorted.forEach(cat => {
            const indent = '\u2014 '.repeat(cat.depth);
            categorySelect.innerHTML += `<option value="${cat.id}">${escapeHtml(indent + cat.name)}</option>`;
            categoryFilter.innerHTML += `<option value="${cat.id}">${escapeHtml(indent + cat.name)}</option>`;
        });
        
    } catch (error) {
        console.error('❌ Failed to load categories:', error);
    }
}

// Load units from database
async function loadUnits() {
    console.log('📥 Loading units...');
    
    try {
        const response = await fetch(`${BASE_URL}api/units.php?_=${Date.now()}`, {
            method: 'GET',
            headers: { 'Cache-Control': 'no-cache' },
            cache: 'no-store'
        });
        
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        
        const result = await response.json();
        if (!result.success) throw new Error(result.error || 'Failed to load units');
        
        unitsData = result.units.filter(u => u.is_active == 1);
        console.log(`✅ Loaded ${unitsData.length} active units`);
        
        // Populate unit select
        const unitSelect = document.getElementById('productUnit');
        unitSelect.innerHTML = '<option value="">-- Select Unit --</option>';
        
        unitsData.forEach(unit => {
            unitSelect.innerHTML += `<option value="${escapeHtml(unit.name)}">${escapeHtml(unit.name)}</option>`;
        });
        
    } catch (error) {
        console.error('❌ Failed to load units:', error);
        // Fallback to hardcoded units if API fails
        const unitSelect = document.getElementById('productUnit');
        unitSelect.innerHTML = `
            <option value="">${LANG.select_unit}</option>
            <option value="Piece">Piece</option>
            <option value="Kg">Kg</option>
            <option value="Gram">Gram</option>
            <option value="Liter">Liter</option>
            <option value="Box">Box</option>
        `;
    }
}

// Load products
async function loadProducts() {
    console.log('📥 Loading products...');
    document.getElementById('loadingSpinner').style.display = 'block';
    document.getElementById('productsContainer').style.display = 'none';
    document.getElementById('emptyState').style.display = 'none';
    
    try {
        productsData = await syncManager.getProducts();
        console.log(`✅ Loaded ${productsData.length} products`);
        displayProducts();
    } catch (error) {
        console.error('❌ Load failed:', error);
        syncManager.toast('Failed to load products', 'danger');
    } finally {
        document.getElementById('loadingSpinner').style.display = 'none';
    }
}

// Display products in table
function displayProducts() {
    const tbody = document.getElementById('productsTableBody');
    
    // Filter products
    let filtered = productsData.filter(p => {
        const matchSearch = !currentSearch ||
            p.name.toLowerCase().includes(currentSearch.toLowerCase()) ||
            (p.brand && p.brand.toLowerCase().includes(currentSearch.toLowerCase())) ||
            (p.barcode && p.barcode.toLowerCase().includes(currentSearch.toLowerCase()));
        const matchCategory = !currentCategory || p.category_id == currentCategory;
        return matchSearch && matchCategory;
    });
    
    if (filtered.length === 0) {
        document.getElementById('emptyState').style.display = 'block';
        document.getElementById('productsContainer').style.display = 'none';
        return;
    }
    
    document.getElementById('emptyState').style.display = 'none';
    document.getElementById('productsContainer').style.display = 'block';
    
    tbody.innerHTML = filtered.map((product, index) => {
        const isTemp = String(product.id).startsWith('temp_');
        const isOnline = syncManager.isOnline;
        const description = product.description || '';

        return `
        <tr>
            <td>${index + 1}</td>
            <td>
                <strong>${escapeHtml(product.name)}</strong>
                ${isTemp ? '<span class="badge bg-warning text-dark ms-1">' + LANG.pending + '</span>' : ''}
            </td>
            <td>${escapeHtml(product.brand || '-')}</td>
            <td class="text-truncate" style="max-width: 240px;" title="${escapeHtml(description)}">${escapeHtml(description || '-')}</td>
            <td>${escapeHtml(product.category_name || '-')}</td>
            <td>${escapeHtml(product.unit)}</td>
            <td>${escapeHtml(product.barcode || '-')}</td>
            <td>
                <strong>TZS. ${parseFloat(product.default_selling_price || 0).toFixed(2)}</strong>
                <br>
                <button class="btn btn-sm btn-outline-info mt-1 location-price-btn" 
                        onclick="setLocationPrices(${product.id})"
                        ${!isOnline ? 'disabled' : ''}
                        title="${isOnline ? LANG.set_location_prices : LANG.location_pricing_only_online}">
                    <i class="bi bi-tag"></i> ${LANG.set_location_prices}
                </button>
            </td>
            <td>
                ${product.is_active ? 
                    '<span class="badge bg-success">' + LANG.active + '</span>' : 
                    '<span class="badge bg-secondary">' + LANG.inactive + '</span>'}
            </td>
            <td>
                <div class="btn-group btn-group-sm">
                    <?php if (hasPermission('products', 'edit')): ?>
                    <button class="btn btn-outline-primary" onclick="showEditModal(${product.id})" title="${LANG.edit}">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <?php endif; ?>
                    
                    <?php if (hasPermission('products', 'delete')): ?>
                    <button class="btn btn-outline-danger delete-btn" 
                            onclick="deleteProduct(${product.id})" 
                            title="${LANG.delete}">
                        <i class="bi bi-trash"></i>
                    </button>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        `;
    }).join('');
}

// Show create modal
function showCreateModal() {
    document.getElementById('productModalTitle').textContent = LANG.add_new_product;
    document.getElementById('productForm').reset();
    document.getElementById('productId').value = '';
    document.getElementById('productActive').checked = true;
    productModal.show();
}

// Show edit modal
function showEditModal(idParam) {
    const id = String(idParam); // Keep as string for temp IDs
    const product = productsData.find(p => p.id == id);
    if (!product) return;
    
    document.getElementById('productModalTitle').textContent = LANG.edit_product;
    document.getElementById('productId').value = product.id;
    document.getElementById('productName').value = product.name;
    document.getElementById('productBrand').value = product.brand || '';
    document.getElementById('productCategory').value = product.category_id;
    document.getElementById('productUnit').value = product.unit;
    document.getElementById('productBarcode').value = product.barcode || '';
    document.getElementById('productPrice').value = product.default_selling_price;
    document.getElementById('productDescription').value = product.description || '';
    document.getElementById('productActive').checked = product.is_active == 1;
    
    productModal.show();
}

// Save product (create or update)
async function saveProduct() {
    const idValue = document.getElementById('productId').value;
    const id = idValue || null;
    const name = document.getElementById('productName').value.trim();
    const brand = document.getElementById('productBrand').value.trim();
    const categoryId = parseInt(document.getElementById('productCategory').value);
    const unit = document.getElementById('productUnit').value;
    const barcode = document.getElementById('productBarcode').value.trim();
    const price = parseFloat(document.getElementById('productPrice').value);
    const description = document.getElementById('productDescription').value.trim();
    const is_active = document.getElementById('productActive').checked ? 1 : 0;
    
    if (!name || !categoryId || !unit) {
        syncManager.toast(LANG.fill_required_fields, 'warning');
        return;
    }
    
    const data = {
        name,
        brand,
        category_id: categoryId,
        unit,
        barcode,
        default_selling_price: price,
        description,
        is_active
    };
    
    try {
        let result;
        
        if (id) {
            // Update
            data.id = id;
            result = await syncManager.updateProduct(data);
            
            // Update in array
            const index = productsData.findIndex(p => p.id == id);
            if (index !== -1) {
                productsData[index] = result;
            }
        } else {
            // Create
            result = await syncManager.createProduct(data);
            
            // Add to array
            productsData.push(result);
        }
        
        // Update display
        displayProducts();
        
        // Close modal
        productModal.hide();
        
    } catch (error) {
        console.error('Save failed:', error);
    }
}

// Delete product (ONLINE ONLY)
async function deleteProduct(idParam) {
    const id = String(idParam);
    const product = productsData.find(p => p.id == id);
    if (!product) return;
    
    // Check if online
    if (!syncManager.isOnline) {
        syncManager.toast(LANG.delete_only_online, 'warning');
        return;
    }
    
    let message = LANG.confirm_delete_product.replace('%s', product.name);
    
    if (product.purchase_count > 0 || product.sale_count > 0) {
        message += '\n\n' + LANG.product_has_transactions;
    }
    
    if (!confirm(message)) {
        return;
    }
    
    try {
        await syncManager.deleteProduct(id);
        
        // Remove from array
        productsData = productsData.filter(p => p.id != id);
        
        // Update display
        displayProducts();
        
    } catch (error) {
        console.error('Delete failed:', error);
    }
}

// Search products
function searchProducts() {
    currentSearch = document.getElementById('searchInput').value.trim();
    document.getElementById('clearBtn').style.display = currentSearch || currentCategory ? 'block' : 'none';
    displayProducts();
}

// Filter by category
function filterProducts() {
    currentCategory = document.getElementById('categoryFilter').value;
    document.getElementById('clearBtn').style.display = currentSearch || currentCategory ? 'block' : 'none';
    displayProducts();
}

// Clear search/filter
function clearSearch() {
    document.getElementById('searchInput').value = '';
    document.getElementById('categoryFilter').value = '';
    currentSearch = '';
    currentCategory = '';
    document.getElementById('clearBtn').style.display = 'none';
    displayProducts();
}

// Manual sync
async function manualSync() {
    console.log('🔄 Manual sync requested');
    await syncManager.sync();
    await loadCategories();
    await loadProducts();
}

// Export to CSV (ONLINE ONLY)
function exportToCSV() {
    if (!syncManager.isOnline) {
        syncManager.toast(LANG.export_only_online, 'warning');
        return;
    }
    
    if (productsData.length === 0) {
        syncManager.toast(LANG.no_data_export, 'warning');
        return;
    }
    
    const headers = ['ID', 'Name', 'Brand', 'Category', 'Unit', 'Barcode', 'Price', 'Status'];
    const rows = productsData.map(p => [
        p.id,
        p.name,
        p.brand || '',
        p.category_name || '',
        p.unit,
        p.barcode || '',
        p.default_selling_price,
        p.is_active ? 'Active' : 'Inactive'
    ]);
    
    const csv = [
        headers.join(','),
        ...rows.map(r => r.map(f => `"${f}"`).join(','))
    ].join('\n');
    
    const blob = new Blob([csv], { type: 'text/csv' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `products_${new Date().toISOString().split('T')[0]}.csv`;
    link.click();
}

// Update UI based on online/offline status
function updateUIForOnlineStatus() {
    const isOnline = navigator.onLine;
    
    // Delete buttons
    const deleteButtons = document.querySelectorAll('.delete-btn');
    deleteButtons.forEach(btn => {
        if (isOnline) {
            btn.disabled = false;
            btn.title = LANG.delete;
        } else {
            btn.disabled = true;
            btn.title = LANG.delete_only_online;
        }
    });
    
    // Location price buttons
    const locationPriceButtons = document.querySelectorAll('.location-price-btn');
    locationPriceButtons.forEach(btn => {
        if (isOnline) {
            btn.disabled = false;
            btn.title = LANG.set_location_prices;
        } else {
            btn.disabled = true;
            btn.title = LANG.location_pricing_only_online;
        }
    });
    
    // Export button
    const exportBtn = document.getElementById('exportBtn');
    if (exportBtn) {
        if (isOnline) {
            exportBtn.disabled = false;
        } else {
            exportBtn.disabled = true;
            exportBtn.title = LANG.export_only_online;
        }
    }
}

// Set location prices (redirect to pricing page)
function setLocationPrices(productId) {
    if (!syncManager.isOnline) {
        syncManager.toast(LANG.location_pricing_only_online, 'warning');
        return;
    }
    
    // Redirect to product pricing page
    window.location.href = `${BASE_URL}modules/masters/product_pricing.php?product_id=${productId}`;
}

// Escape HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

<?php require_once '../../includes/footer.php'; ?>