<?php
/**
 * Categories Management - Online Only
 * Simple AJAX-based CRUD, no offline sync
 */
require_once '../../config/database.php';
require_once '../../includes/functions.php';

$pageTitle = __('categories_management');

if (!hasPermission('categories', 'view')) {
    setAlert('You do not have permission to access this page', 'danger');
    redirect('');
}

require_once '../../includes/header.php';
?>

<div class="row mb-3">
    <div class="col-md-6">
        <h2><i class="bi bi-tags"></i> <?php _e('categories_management'); ?></h2>
    </div>
    <div class="col-md-6 text-end">
        <?php if (hasPermission('categories', 'create')): ?>
        <a href="<?php echo BASE_URL; ?>modules/masters/categories-import.php" class="btn btn-outline-success">
            <i class="bi bi-upload"></i> <?php _e('import_categories'); ?>
        </a>
        <button class="btn btn-primary" onclick="showCreateModal()">
            <i class="bi bi-plus-circle"></i> <?php _e('add_new_category'); ?>
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
                       placeholder="<?php _e('search_categories'); ?>" 
                       onkeyup="searchCategories()">
            </div>
            <div class="col-md-2">
                <button onclick="clearSearch()" class="btn btn-secondary w-100" id="clearBtn" style="display:none;">
                    <i class="bi bi-x-circle"></i> <?php _e('clear'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Categories Table -->
<div class="card">
    <div class="card-body">
        <div id="loadingSpinner" class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden"><?php _e('loading'); ?></span>
            </div>
            <p class="mt-2"><?php _e('loading_categories'); ?></p>
        </div>
        
        <div id="categoriesContainer" style="display:none;">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th><?php _e('sr_no'); ?></th>
                            <th><?php _e('name'); ?></th>
                            <th><?php _e('description'); ?></th>
                            <th><?php _e('products_count'); ?></th>
                            <th><?php _e('status'); ?></th>
                            <th><?php _e('actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="categoriesTableBody">
                        <!-- Populated by JavaScript -->
                    </tbody>
                </table>
            </div>
        </div>
        
        <div id="emptyState" style="display:none;" class="text-center py-5">
            <i class="bi bi-tags" style="font-size: 4rem; color: #ccc;"></i>
            <h5 class="mt-3"><?php _e('no_categories_found'); ?></h5>
            <p class="text-muted"><?php _e('start_adding_category'); ?></p>
        </div>
    </div>
</div>

<!-- Create/Edit Modal -->
<div class="modal fade" id="categoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="categoryModalTitle"><?php _e('add_new_category'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="categoryForm">
                    <input type="hidden" id="categoryId">
                    
                    <div class="mb-3">
                        <label for="categoryParent" class="form-label"><?php _e('parent_category'); ?></label>
                        <select class="form-select" id="categoryParent">
                            <option value=""><?php _e('none_root_level'); ?></option>
                        </select>
                        <small class="text-muted"><?php _e('parent_category_hint'); ?></small>
                    </div>

                    <div class="mb-3">
                        <label for="categoryName" class="form-label"><?php _e('category_name'); ?> *</label>
                        <input type="text" class="form-control" id="categoryName" required>
                        <small class="text-muted"><?php _e('category_name_hint'); ?></small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="categoryDescription" class="form-label"><?php _e('description'); ?></label>
                        <textarea class="form-control" id="categoryDescription" rows="3"></textarea>
                    </div>
                    
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="categoryActive" checked>
                        <label class="form-check-label" for="categoryActive"><?php _e('active'); ?></label>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                <button type="button" class="btn btn-primary" onclick="saveCategory()">
                    <i class="bi bi-check-circle"></i> <?php _e('save_category'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Translations for JavaScript
const LANG = {
    add_new_category: <?php echo json_encode(__('add_new_category')); ?>,
    edit_category: <?php echo json_encode(__('edit_category')); ?>,
    enter_category_name: <?php echo json_encode(__('enter_category_name')); ?>,
    confirm_delete_category: <?php echo json_encode(__('confirm_delete_category')); ?>,
    category_has_products: <?php echo json_encode(__('category_has_products')); ?>,
    products_label: <?php echo json_encode(__('products_label')); ?>,
    active: <?php echo json_encode(__('active')); ?>,
    inactive: <?php echo json_encode(__('inactive')); ?>,
    edit: <?php echo json_encode(__('edit')); ?>,
    delete: <?php echo json_encode(__('delete')); ?>,
    none_root_level: <?php echo json_encode(__('none_root_level')); ?>
};

// Global variables
let categoriesData = [];
let categoryModal;
let searchQuery = '';

// Tree helper: build nested tree from flat array
function buildTreeJS(items, parentId = null, depth = 0) {
    const branch = [];
    items.forEach(item => {
        const itemParent = item.parent_id ? parseInt(item.parent_id) : null;
        if (itemParent === parentId) {
            const node = { ...item, depth: depth, children: [] };
            node.children = buildTreeJS(items, parseInt(item.id), depth + 1);
            branch.push(node);
        }
    });
    return branch;
}

// Tree helper: flatten nested tree to depth-first sorted array
function flattenTreeJS(tree) {
    const result = [];
    tree.forEach(item => {
        const children = item.children;
        const flat = { ...item };
        delete flat.children;
        result.push(flat);
        if (children && children.length > 0) {
            result.push(...flattenTreeJS(children));
        }
    });
    return result;
}

// Tree helper: get all descendant IDs of a given item
function getDescendantIdsJS(items, parentId) {
    const descendants = [];
    items.forEach(item => {
        const itemParent = item.parent_id ? parseInt(item.parent_id) : null;
        if (itemParent === parentId) {
            descendants.push(parseInt(item.id));
            descendants.push(...getDescendantIdsJS(items, parseInt(item.id)));
        }
    });
    return descendants;
}

// Populate parent category dropdown (tree-indented, excluding self and descendants when editing)
function populateParentDropdown(excludeId = null) {
    const select = document.getElementById('categoryParent');
    select.innerHTML = `<option value="">${LANG.none_root_level}</option>`;

    let excludeIds = [];
    if (excludeId !== null) {
        excludeIds = [excludeId, ...getDescendantIdsJS(categoriesData, excludeId)];
    }

    const tree = buildTreeJS(categoriesData);
    const sorted = flattenTreeJS(tree);

    sorted.forEach(item => {
        if (excludeIds.includes(parseInt(item.id))) return;
        if (!item.is_active && (excludeId === null || parseInt(item.id) !== excludeId)) return;
        const indent = '\u2014 '.repeat(item.depth);
        const option = document.createElement('option');
        option.value = item.id;
        option.textContent = indent + item.name;
        select.appendChild(option);
    });
}

// Initialize
document.addEventListener('DOMContentLoaded', async function() {
    console.log('🎬 Categories page loaded');
    
    // Initialize modal
    categoryModal = new bootstrap.Modal(document.getElementById('categoryModal'));
    
    // Load categories
    await loadCategories();
    
    // Enter key support in modal
    document.getElementById('categoryForm').addEventListener('keypress', function(e) {
        if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') {
            e.preventDefault();
            saveCategory();
        }
    });
});

// Load categories from API
async function loadCategories() {
    console.log('📥 Loading categories...');
    document.getElementById('loadingSpinner').style.display = 'block';
    document.getElementById('categoriesContainer').style.display = 'none';
    document.getElementById('emptyState').style.display = 'none';
    
    try {
        const url = `${BASE_URL}api/categories.php?_=${Date.now()}`;
        
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
        console.log('📨 Server response:', result);
        
        if (!result.success) {
            throw new Error(result.error || 'Failed to load categories');
        }
        
        categoriesData = result.categories;
        console.log(`✅ Loaded ${categoriesData.length} categories`);
        
        displayCategories();
        
    } catch (error) {
        console.error('❌ Load failed:', error);
        showToast('Failed to load categories: ' + error.message, 'danger');
    } finally {
        document.getElementById('loadingSpinner').style.display = 'none';
    }
}

// Display categories in table (tree-sorted with indentation)
function displayCategories() {
    const tbody = document.getElementById('categoriesTableBody');

    // Build tree-sorted list
    const tree = buildTreeJS(categoriesData);
    let sorted = flattenTreeJS(tree);

    // Filter by search query
    if (searchQuery) {
        const query = searchQuery.toLowerCase();
        sorted = sorted.filter(cat =>
            cat.name.toLowerCase().includes(query) ||
            (cat.description && cat.description.toLowerCase().includes(query))
        );
    }

    if (sorted.length === 0) {
        document.getElementById('emptyState').style.display = 'block';
        document.getElementById('categoriesContainer').style.display = 'none';
        return;
    }

    document.getElementById('emptyState').style.display = 'none';
    document.getElementById('categoriesContainer').style.display = 'block';

    tbody.innerHTML = sorted.map((category, index) => {
        const indent = '<span class="text-muted">' + '\u2014 '.repeat(category.depth) + '</span>';
        return `
        <tr>
            <td>${index + 1}</td>
            <td>${indent}<strong>${escapeHtml(category.name)}</strong></td>
            <td>${escapeHtml(category.description || '-')}</td>
            <td><span class="badge bg-info">${category.product_count || 0} ${LANG.products_label}</span></td>
            <td>
                ${category.is_active ?
                    '<span class="badge bg-success">' + LANG.active + '</span>' :
                    '<span class="badge bg-secondary">' + LANG.inactive + '</span>'}
            </td>
            <td>
                <div class="btn-group btn-group-sm">
                    <?php if (hasPermission('categories', 'edit')): ?>
                    <button class="btn btn-outline-primary" onclick="showEditModal(${category.id})" title="${LANG.edit}">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <?php endif; ?>

                    <?php if (hasPermission('categories', 'delete')): ?>
                    <button class="btn btn-outline-danger" onclick="deleteCategory(${category.id})" title="${LANG.delete}">
                        <i class="bi bi-trash"></i>
                    </button>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
    `}).join('');
}

// Show create modal
function showCreateModal() {
    document.getElementById('categoryModalTitle').textContent = LANG.add_new_category;
    document.getElementById('categoryForm').reset();
    document.getElementById('categoryId').value = '';
    document.getElementById('categoryActive').checked = true;
    populateParentDropdown(null);
    document.getElementById('categoryParent').value = '';
    categoryModal.show();
}

// Show edit modal
function showEditModal(idParam) {
    const id = parseInt(idParam);
    const category = categoriesData.find(c => parseInt(c.id) === id);
    if (!category) return;

    document.getElementById('categoryModalTitle').textContent = LANG.edit_category;
    document.getElementById('categoryId').value = category.id;
    document.getElementById('categoryName').value = category.name;
    document.getElementById('categoryDescription').value = category.description || '';
    document.getElementById('categoryActive').checked = category.is_active == 1;
    populateParentDropdown(id);
    document.getElementById('categoryParent').value = category.parent_id || '';

    categoryModal.show();
}

// Save category (create or update)
async function saveCategory() {
    const idValue = document.getElementById('categoryId').value;
    const id = idValue ? parseInt(idValue) : null;
    const name = document.getElementById('categoryName').value.trim();
    const description = document.getElementById('categoryDescription').value.trim();
    const is_active = document.getElementById('categoryActive').checked ? 1 : 0;
    const parentValue = document.getElementById('categoryParent').value;
    const parent_id = parentValue ? parseInt(parentValue) : null;

    if (!name) {
        showToast(LANG.enter_category_name, 'warning');
        return;
    }

    const data = { name, description, is_active, parent_id };
    if (id) {
        data.id = id;
    }
    
    try {
        const url = `${BASE_URL}api/categories-sync.php?_=${Date.now()}`;
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
        console.log('📨 Save response:', result);
        
        if (!result.success) {
            throw new Error(result.error || 'Failed to save');
        }
        
        showToast(result.message, 'success');
        categoryModal.hide();
        
        // Reload to get fresh data
        await loadCategories();
        
    } catch (error) {
        console.error('❌ Save failed:', error);
        showToast(error.message, 'danger');
    }
}

// Delete category
async function deleteCategory(idParam) {
    const id = parseInt(idParam);
    const category = categoriesData.find(c => parseInt(c.id) === id);
    if (!category) return;
    
    let message = LANG.confirm_delete_category.replace('%s', category.name);
    if (category.product_count > 0) {
        message += `\n\n` + LANG.category_has_products.replace('%s', category.product_count);
    }
    
    if (!confirm(message)) {
        return;
    }
    
    try {
        const url = `${BASE_URL}api/categories-sync.php?_=${Date.now()}`;
        
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
        console.log('📨 Delete response:', result);
        
        if (!result.success) {
            throw new Error(result.error || 'Failed to delete');
        }
        
        showToast(result.message, 'success');
        
        // Remove from array
        categoriesData = categoriesData.filter(c => parseInt(c.id) !== id);
        displayCategories();
        
    } catch (error) {
        console.error('❌ Delete failed:', error);
        showToast(error.message, 'danger');
    }
}

// Search categories
function searchCategories() {
    searchQuery = document.getElementById('searchInput').value.trim();
    document.getElementById('clearBtn').style.display = searchQuery ? 'block' : 'none';
    displayCategories();
}

// Clear search
function clearSearch() {
    document.getElementById('searchInput').value = '';
    searchQuery = '';
    document.getElementById('clearBtn').style.display = 'none';
    displayCategories();
}

// Show toast notification
function showToast(message, type = 'info') {
    // Remove existing toasts
    document.querySelectorAll('.category-toast').forEach(t => t.remove());
    
    const toast = document.createElement('div');
    toast.className = `category-toast alert alert-${type} alert-dismissible fade show`;
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
