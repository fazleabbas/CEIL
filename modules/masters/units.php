<?php
/**
 * Units Management - Online Only
 * Simple AJAX-based CRUD for measurement units
 */
require_once '../../config/database.php';
require_once '../../includes/functions.php';

$pageTitle = __('units_management');

if (!hasPermission('units', 'view')) {
    setAlert('You do not have permission to access this page', 'danger');
    redirect('');
}

require_once '../../includes/header.php';
?>

<div class="row mb-3">
    <div class="col-md-6">
        <h2><i class="bi bi-rulers"></i> <?php _e('units_management'); ?></h2>
    </div>
    <div class="col-md-6 text-end">
        <?php if (hasPermission('units', 'create')): ?>
        <button class="btn btn-primary" onclick="showCreateModal()">
            <i class="bi bi-plus-circle"></i> <?php _e('add_new_unit'); ?>
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
                       placeholder="<?php _e('search_units'); ?>" 
                       onkeyup="searchUnits()">
            </div>
            <div class="col-md-2">
                <button onclick="clearSearch()" class="btn btn-secondary w-100" id="clearBtn" style="display:none;">
                    <i class="bi bi-x-circle"></i> <?php _e('clear'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Units Table -->
<div class="card">
    <div class="card-body">
        <div id="loadingSpinner" class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden"><?php _e('loading'); ?></span>
            </div>
            <p class="mt-2"><?php _e('loading_units'); ?></p>
        </div>
        
        <div id="unitsContainer" style="display:none;">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th><?php _e('sr_no'); ?></th>
                            <th><?php _e('name'); ?></th>
                            <th><?php _e('products_count'); ?></th>
                            <th><?php _e('status'); ?></th>
                            <th><?php _e('actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="unitsTableBody">
                        <!-- Populated by JavaScript -->
                    </tbody>
                </table>
            </div>
        </div>
        
        <div id="emptyState" style="display:none;" class="text-center py-5">
            <i class="bi bi-rulers" style="font-size: 4rem; color: #ccc;"></i>
            <h5 class="mt-3"><?php _e('no_units_found'); ?></h5>
            <p class="text-muted"><?php _e('start_adding_unit'); ?></p>
        </div>
    </div>
</div>

<!-- Create/Edit Modal -->
<div class="modal fade" id="unitModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="unitModalTitle"><?php _e('add_new_unit'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="unitForm">
                    <input type="hidden" id="unitId">
                    
                    <div class="mb-3">
                        <label for="unitName" class="form-label"><?php _e('unit_name'); ?> *</label>
                        <input type="text" class="form-control" id="unitName" required>
                        <small class="text-muted"><?php _e('unit_name_hint'); ?></small>
                    </div>
                    
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="unitActive" checked>
                        <label class="form-check-label" for="unitActive"><?php _e('active'); ?></label>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                <button type="button" class="btn btn-primary" onclick="saveUnit()">
                    <i class="bi bi-check-circle"></i> <?php _e('save_unit'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Translations for JavaScript
const LANG = {
    add_new_unit: <?php echo json_encode(__('add_new_unit')); ?>,
    edit_unit: <?php echo json_encode(__('edit_unit')); ?>,
    enter_unit_name: <?php echo json_encode(__('enter_unit_name')); ?>,
    confirm_delete_unit: <?php echo json_encode(__('confirm_delete_unit')); ?>,
    unit_has_products: <?php echo json_encode(__('unit_has_products')); ?>,
    products_label: <?php echo json_encode(__('products_label')); ?>,
    active: <?php echo json_encode(__('active')); ?>,
    inactive: <?php echo json_encode(__('inactive')); ?>,
    edit: <?php echo json_encode(__('edit')); ?>,
    delete: <?php echo json_encode(__('delete')); ?>
};

let unitsData = [];
let unitModal;
let searchQuery = '';

document.addEventListener('DOMContentLoaded', async function() {
    unitModal = new bootstrap.Modal(document.getElementById('unitModal'));
    await loadUnits();
    
    document.getElementById('unitForm').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            saveUnit();
        }
    });
});

async function loadUnits() {
    document.getElementById('loadingSpinner').style.display = 'block';
    document.getElementById('unitsContainer').style.display = 'none';
    document.getElementById('emptyState').style.display = 'none';
    
    try {
        const response = await fetch(`${BASE_URL}api/units.php?_=${Date.now()}`, {
            method: 'GET',
            headers: { 'Cache-Control': 'no-cache' },
            cache: 'no-store'
        });
        
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        
        const result = await response.json();
        if (!result.success) throw new Error(result.error || 'Failed to load units');
        
        unitsData = result.units;
        displayUnits();
        
    } catch (error) {
        console.error('Load failed:', error);
        showToast('Failed to load units: ' + error.message, 'danger');
    } finally {
        document.getElementById('loadingSpinner').style.display = 'none';
    }
}

function displayUnits() {
    const tbody = document.getElementById('unitsTableBody');
    
    let filtered = unitsData;
    if (searchQuery) {
        const query = searchQuery.toLowerCase();
        filtered = unitsData.filter(u => u.name.toLowerCase().includes(query));
    }
    
    if (filtered.length === 0) {
        document.getElementById('emptyState').style.display = 'block';
        document.getElementById('unitsContainer').style.display = 'none';
        return;
    }
    
    document.getElementById('emptyState').style.display = 'none';
    document.getElementById('unitsContainer').style.display = 'block';
    
    tbody.innerHTML = filtered.map((unit, index) => `
        <tr>
            <td>${index + 1}</td>
            <td><strong>${escapeHtml(unit.name)}</strong></td>
            <td><span class="badge bg-info">${unit.product_count || 0} ${LANG.products_label}</span></td>
            <td>
                ${unit.is_active == 1 ? 
                    '<span class="badge bg-success">' + LANG.active + '</span>' : 
                    '<span class="badge bg-secondary">' + LANG.inactive + '</span>'}
            </td>
            <td>
                <div class="btn-group btn-group-sm">
                    <?php if (hasPermission('units', 'edit')): ?>
                    <button class="btn btn-outline-primary" onclick="showEditModal(${unit.id})" title="${LANG.edit}">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <?php endif; ?>
                    
                    <?php if (hasPermission('units', 'delete')): ?>
                    <button class="btn btn-outline-danger" onclick="deleteUnit(${unit.id})" title="${LANG.delete}">
                        <i class="bi bi-trash"></i>
                    </button>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
    `).join('');
}

function showCreateModal() {
    document.getElementById('unitModalTitle').textContent = LANG.add_new_unit;
    document.getElementById('unitForm').reset();
    document.getElementById('unitId').value = '';
    document.getElementById('unitActive').checked = true;
    unitModal.show();
}

function showEditModal(idParam) {
    const id = parseInt(idParam);
    const unit = unitsData.find(u => parseInt(u.id) === id);
    if (!unit) return;
    
    document.getElementById('unitModalTitle').textContent = LANG.edit_unit;
    document.getElementById('unitId').value = unit.id;
    document.getElementById('unitName').value = unit.name;
    document.getElementById('unitActive').checked = unit.is_active == 1;
    
    unitModal.show();
}

async function saveUnit() {
    const idValue = document.getElementById('unitId').value;
    const id = idValue ? parseInt(idValue) : null;
    const name = document.getElementById('unitName').value.trim();
    const is_active = document.getElementById('unitActive').checked ? 1 : 0;
    
    if (!name) {
        showToast(LANG.enter_unit_name, 'warning');
        return;
    }
    
    const data = { name, is_active };
    if (id) data.id = id;
    
    try {
        const response = await fetch(`${BASE_URL}api/units-sync.php?_=${Date.now()}`, {
            method: 'POST',
            headers: { 
                'Content-Type': 'application/json',
                'Cache-Control': 'no-cache'
            },
            body: JSON.stringify({ action: id ? 'update' : 'create', data })
        });
        
        const result = await response.json();
        if (!result.success) throw new Error(result.error || 'Failed to save');
        
        showToast(result.message, 'success');
        unitModal.hide();
        await loadUnits();
        
    } catch (error) {
        console.error('Save failed:', error);
        showToast(error.message, 'danger');
    }
}

async function deleteUnit(idParam) {
    const id = parseInt(idParam);
    const unit = unitsData.find(u => parseInt(u.id) === id);
    if (!unit) return;
    
    let message = LANG.confirm_delete_unit.replace('%s', unit.name);
    if (unit.product_count > 0) {
        message += `\n\n` + LANG.unit_has_products.replace('%s', unit.product_count);
    }
    
    if (!confirm(message)) return;
    
    try {
        const response = await fetch(`${BASE_URL}api/units-sync.php?_=${Date.now()}`, {
            method: 'POST',
            headers: { 
                'Content-Type': 'application/json',
                'Cache-Control': 'no-cache'
            },
            body: JSON.stringify({ action: 'delete', data: { id } })
        });
        
        const result = await response.json();
        if (!result.success) throw new Error(result.error || 'Failed to delete');
        
        showToast(result.message, 'success');
        unitsData = unitsData.filter(u => parseInt(u.id) !== id);
        displayUnits();
        
    } catch (error) {
        console.error('Delete failed:', error);
        showToast(error.message, 'danger');
    }
}

function searchUnits() {
    searchQuery = document.getElementById('searchInput').value.trim();
    document.getElementById('clearBtn').style.display = searchQuery ? 'block' : 'none';
    displayUnits();
}

function clearSearch() {
    document.getElementById('searchInput').value = '';
    searchQuery = '';
    document.getElementById('clearBtn').style.display = 'none';
    displayUnits();
}

function showToast(message, type = 'info') {
    document.querySelectorAll('.unit-toast').forEach(t => t.remove());
    
    const toast = document.createElement('div');
    toast.className = `unit-toast alert alert-${type} alert-dismissible fade show`;
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

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

<?php require_once '../../includes/footer.php'; ?>
