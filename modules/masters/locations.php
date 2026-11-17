<?php
/**
 * Locations Management - Online Only
 * Simple AJAX-based CRUD, no offline sync
 */
require_once '../../config/database.php';
require_once '../../includes/functions.php';

$pageTitle = __('locations_management');

if (!hasPermission('locations', 'view')) {
    setAlert('You do not have permission to access this page', 'danger');
    redirect('');
}

require_once '../../includes/header.php';
?>

<div class="row mb-3">
    <div class="col-md-6">
        <h2><i class="bi bi-geo-alt"></i> <?php _e('locations_management'); ?></h2>
    </div>
    <div class="col-md-6 text-end">
        <?php if (hasPermission('locations', 'import')): ?>
        <a href="<?php echo BASE_URL; ?>modules/masters/locations-import.php" class="btn btn-outline-success">
            <i class="bi bi-upload"></i> <?php _e('import_locations'); ?>
        </a>
        <?php endif; ?>
        <?php if (hasPermission('locations', 'create')): ?>
        <button class="btn btn-primary" onclick="showCreateModal()">
            <i class="bi bi-plus-circle"></i> <?php _e('add_new_location'); ?>
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
                       placeholder="<?php _e('search_locations'); ?>" 
                       onkeyup="searchLocations()">
            </div>
            <div class="col-md-2">
                <button onclick="clearSearch()" class="btn btn-secondary w-100" id="clearBtn" style="display:none;">
                    <i class="bi bi-x-circle"></i> <?php _e('clear'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Locations Table -->
<div class="card">
    <div class="card-body">
        <div id="loadingSpinner" class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden"><?php _e('loading'); ?></span>
            </div>
            <p class="mt-2"><?php _e('loading_locations'); ?></p>
        </div>
        
        <div id="locationsContainer" style="display:none;">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th><?php _e('sr_no'); ?></th>
                            <th><?php _e('name'); ?></th>
                            <th><?php _e('address'); ?></th>
                            <th><?php _e('phone'); ?></th>
                            <th><?php _e('location_type'); ?></th>
                            <th><?php _e('products_count'); ?></th>
                            <th><?php _e('users_count'); ?></th>
                            <th><?php _e('status'); ?></th>
                            <th><?php _e('actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="locationsTableBody">
                        <!-- Populated by JavaScript -->
                    </tbody>
                </table>
            </div>
        </div>
        
        <div id="emptyState" style="display:none;" class="text-center py-5">
            <i class="bi bi-geo-alt" style="font-size: 4rem; color: #ccc;"></i>
            <h5 class="mt-3"><?php _e('no_locations_found'); ?></h5>
            <p class="text-muted"><?php _e('start_adding_location'); ?></p>
        </div>
    </div>
</div>

<!-- Create/Edit Modal -->
<div class="modal fade" id="locationModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="locationModalTitle"><?php _e('add_new_location'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="locationForm">
                    <input type="hidden" id="locationId">

                    <div class="mb-3">
                        <label for="locationParent" class="form-label"><?php _e('parent_location'); ?></label>
                        <select class="form-select" id="locationParent">
                            <option value=""><?php _e('none_root_level'); ?></option>
                        </select>
                        <small class="text-muted"><?php _e('parent_location_hint'); ?></small>
                    </div>

                    <div class="mb-3">
                        <label for="locationName" class="form-label"><?php _e('location_name'); ?> *</label>
                        <input type="text" class="form-control" id="locationName" required>
                        <small class="text-muted"><?php _e('location_name_hint'); ?></small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="locationAddress" class="form-label"><?php _e('address'); ?></label>
                        <textarea class="form-control" id="locationAddress" rows="3"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label for="locationPhone" class="form-label"><?php _e('phone'); ?></label>
                        <input type="text" class="form-control" id="locationPhone">
                    </div>

                    <div class="mb-3">
                        <label for="locationType" class="form-label"><?php _e('location_type'); ?></label>
                        <select class="form-select" id="locationType">
                            <option value=""><?php _e('location_type_none'); ?></option>
                            <option value="site"><?php _e('location_type_site'); ?></option>
                            <option value="warehouse"><?php _e('location_type_warehouse'); ?></option>
                            <option value="others"><?php _e('location_type_others'); ?></option>
                        </select>
                    </div>

                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="locationActive" checked>
                        <label class="form-check-label" for="locationActive"><?php _e('active'); ?></label>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                <button type="button" class="btn btn-primary" onclick="saveLocation()">
                    <i class="bi bi-check-circle"></i> <?php _e('save_location'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Translations for JavaScript
const LANG = {
    add_new_location: <?php echo json_encode(__('add_new_location')); ?>,
    edit_location: <?php echo json_encode(__('edit_location')); ?>,
    enter_location_name: <?php echo json_encode(__('enter_location_name')); ?>,
    confirm_delete_location: <?php echo json_encode(__('confirm_delete_location')); ?>,
    location_has_assigned: <?php echo json_encode(__('location_has_assigned')); ?>,
    products_label: <?php echo json_encode(__('products_label')); ?>,
    users_label: <?php echo json_encode(__('users_label')); ?>,
    active: <?php echo json_encode(__('active')); ?>,
    inactive: <?php echo json_encode(__('inactive')); ?>,
    edit: <?php echo json_encode(__('edit')); ?>,
    delete: <?php echo json_encode(__('delete')); ?>,
    user_s: <?php echo json_encode(__('user_s')); ?>,
    product_s: <?php echo json_encode(__('product_s')); ?>,
    and: <?php echo json_encode(__('and')); ?>,
    none_root_level: <?php echo json_encode(__('none_root_level')); ?>,
    location_type_site: <?php echo json_encode(__('location_type_site')); ?>,
    location_type_warehouse: <?php echo json_encode(__('location_type_warehouse')); ?>,
    location_type_others: <?php echo json_encode(__('location_type_others')); ?>
};

// Map a stored location_type value to its translated label
function locationTypeLabel(type) {
    switch (type) {
        case 'site': return LANG.location_type_site;
        case 'warehouse': return LANG.location_type_warehouse;
        case 'others': return LANG.location_type_others;
        default: return '-';
    }
}

// Global variables
let locationsData = [];
let locationModal;
let searchQuery = '';
const canEdit = <?= hasPermission('locations', 'edit') ? 'true' : 'false' ?>;
const canDelete = <?= hasPermission('locations', 'delete') ? 'true' : 'false' ?>;

// Initialize
document.addEventListener('DOMContentLoaded', async function() {
    console.log('🎬 Locations page loaded');
    
    // Initialize modal
    locationModal = new bootstrap.Modal(document.getElementById('locationModal'));
    
    // Load locations
    await loadLocations();
    
    // Enter key support in modal
    document.getElementById('locationForm').addEventListener('keypress', function(e) {
        if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') {
            e.preventDefault();
            saveLocation();
        }
    });
});

// Load locations from API
async function loadLocations() {
    console.log('📥 Loading locations...');
    document.getElementById('loadingSpinner').style.display = 'block';
    document.getElementById('locationsContainer').style.display = 'none';
    document.getElementById('emptyState').style.display = 'none';
    
    try {
        const url = `${BASE_URL}api/locations.php?_=${Date.now()}`;
        
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
            throw new Error(result.error || 'Failed to load locations');
        }
        
        locationsData = result.locations;
        console.log(`✅ Loaded ${locationsData.length} locations`);
        
        displayLocations();
        
    } catch (error) {
        console.error('❌ Load failed:', error);
        showToast('Failed to load locations: ' + error.message, 'danger');
    } finally {
        document.getElementById('loadingSpinner').style.display = 'none';
    }
}

// Build tree from flat array
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

// Flatten tree to sorted array (depth-first)
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

// Get all descendant IDs of a given item
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

// Populate parent dropdown options (tree-indented, excluding self and descendants)
function populateParentDropdown(excludeId = null) {
    const select = document.getElementById('locationParent');
    select.innerHTML = `<option value="">${LANG.none_root_level}</option>`;

    // Exclude the item itself and its descendants
    let excludeIds = [];
    if (excludeId !== null) {
        excludeIds = [excludeId, ...getDescendantIdsJS(locationsData, excludeId)];
    }

    const tree = buildTreeJS(locationsData);
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

// Display locations in table
function displayLocations() {
    const tbody = document.getElementById('locationsTableBody');

    // Build tree-sorted list
    const tree = buildTreeJS(locationsData);
    let sorted = flattenTreeJS(tree);

    // Filter by search query
    if (searchQuery) {
        const query = searchQuery.toLowerCase();
        sorted = sorted.filter(loc =>
            loc.name.toLowerCase().includes(query) ||
            (loc.address && loc.address.toLowerCase().includes(query)) ||
            (loc.phone && loc.phone.toLowerCase().includes(query))
        );
    }

    if (sorted.length === 0) {
        document.getElementById('emptyState').style.display = 'block';
        document.getElementById('locationsContainer').style.display = 'none';
        return;
    }

    document.getElementById('emptyState').style.display = 'none';
    document.getElementById('locationsContainer').style.display = 'block';

    tbody.innerHTML = sorted.map((location, index) => {
        const indent = '<span class="text-muted">' + '\u2014 '.repeat(location.depth) + '</span>';
        return `
        <tr>
            <td>${index + 1}</td>
            <td>${indent}<strong>${escapeHtml(location.name)}</strong></td>
            <td>${escapeHtml(location.address || '-')}</td>
            <td>${escapeHtml(location.phone || '-')}</td>
            <td>${escapeHtml(locationTypeLabel(location.location_type))}</td>
            <td><span class="badge bg-info">${location.product_count || 0} ${LANG.products_label}</span></td>
            <td><span class="badge bg-secondary">${location.user_count || 0} ${LANG.users_label}</span></td>
            <td>
                ${location.is_active ?
                    '<span class="badge bg-success">' + LANG.active + '</span>' :
                    '<span class="badge bg-secondary">' + LANG.inactive + '</span>'}
            </td>
            <td>
                <div class="btn-group btn-group-sm">
                    ${canEdit ? `<button class="btn btn-outline-primary" onclick="showEditModal(${location.id})" title="${LANG.edit}">
                        <i class="bi bi-pencil"></i>
                    </button>` : ''}
                    ${canDelete ? `<button class="btn btn-outline-danger" onclick="deleteLocation(${location.id})" title="${LANG.delete}">
                        <i class="bi bi-trash"></i>
                    </button>` : ''}
                </div>
            </td>
        </tr>
    `}).join('');
}

// Show create modal
function showCreateModal() {
    document.getElementById('locationModalTitle').textContent = LANG.add_new_location;
    document.getElementById('locationForm').reset();
    document.getElementById('locationId').value = '';
    document.getElementById('locationActive').checked = true;
    populateParentDropdown(null);
    document.getElementById('locationParent').value = '';
    locationModal.show();
}

// Show edit modal
function showEditModal(idParam) {
    const id = parseInt(idParam);
    const location = locationsData.find(l => parseInt(l.id) === id);
    if (!location) return;

    document.getElementById('locationModalTitle').textContent = LANG.edit_location;
    document.getElementById('locationId').value = location.id;
    document.getElementById('locationName').value = location.name;
    document.getElementById('locationAddress').value = location.address || '';
    document.getElementById('locationPhone').value = location.phone || '';
    document.getElementById('locationType').value = location.location_type || '';
    document.getElementById('locationActive').checked = location.is_active == 1;
    populateParentDropdown(id);
    document.getElementById('locationParent').value = location.parent_id || '';

    locationModal.show();
}

// Save location (create or update)
async function saveLocation() {
    const idValue = document.getElementById('locationId').value;
    const id = idValue ? parseInt(idValue) : null;
    const name = document.getElementById('locationName').value.trim();
    const address = document.getElementById('locationAddress').value.trim();
    const phone = document.getElementById('locationPhone').value.trim();
    const location_type = document.getElementById('locationType').value;
    const is_active = document.getElementById('locationActive').checked ? 1 : 0;
    const parentValue = document.getElementById('locationParent').value;
    const parent_id = parentValue ? parseInt(parentValue) : null;

    if (!name) {
        showToast(LANG.enter_location_name, 'warning');
        return;
    }

    const data = { name, address, phone, location_type, is_active, parent_id };
    if (id) {
        data.id = id;
    }
    
    try {
        const url = `${BASE_URL}api/locations-sync.php?_=${Date.now()}`;
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
        locationModal.hide();
        
        // Reload to get fresh data
        await loadLocations();
        
    } catch (error) {
        console.error('❌ Save failed:', error);
        showToast(error.message, 'danger');
    }
}

// Delete location
async function deleteLocation(idParam) {
    const id = parseInt(idParam);
    const location = locationsData.find(l => parseInt(l.id) === id);
    if (!location) return;
    
    // Build warning message
    const warnings = [];
    if (location.user_count > 0) {
        warnings.push(`${location.user_count} ${LANG.user_s}`);
    }
    if (location.product_count > 0) {
        warnings.push(`${location.product_count} ${LANG.product_s}`);
    }
    
    let message = LANG.confirm_delete_location.replace('%s', location.name);
    if (warnings.length > 0) {
        message += `\n\n` + LANG.location_has_assigned.replace('%s', warnings.join(` ${LANG.and} `));
    }
    
    if (!confirm(message)) {
        return;
    }
    
    try {
        const url = `${BASE_URL}api/locations-sync.php?_=${Date.now()}`;
        
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
        locationsData = locationsData.filter(l => l.id !== id);
        displayLocations();
        
    } catch (error) {
        console.error('❌ Delete failed:', error);
        showToast(error.message, 'danger');
    }
}

// Search locations
function searchLocations() {
    searchQuery = document.getElementById('searchInput').value.trim();
    document.getElementById('clearBtn').style.display = searchQuery ? 'block' : 'none';
    displayLocations();
}

// Clear search
function clearSearch() {
    document.getElementById('searchInput').value = '';
    searchQuery = '';
    document.getElementById('clearBtn').style.display = 'none';
    displayLocations();
}

// Show toast notification
function showToast(message, type = 'info') {
    // Remove existing toasts
    document.querySelectorAll('.location-toast').forEach(t => t.remove());
    
    const toast = document.createElement('div');
    toast.className = `location-toast alert alert-${type} alert-dismissible fade show`;
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
