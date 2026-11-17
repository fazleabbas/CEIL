<?php
/**
 * Roles Management Page - CLIENT SIDE
 * This is the HTML page that users see in their browser
 */
require_once '../../config/database.php';
require_once '../../includes/functions.php';

$pageTitle = __('roles_management');

if (!hasPermission('roles', 'view')) {
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
        <h2><i class="bi bi-shield"></i> <?php _e('roles_management'); ?></h2>
    </div>
    <div class="col-md-6 text-end">
        <button class="btn btn-outline-primary" onclick="manualSync()">
            <i class="bi bi-arrow-repeat"></i> <?php _e('sync_now'); ?>
        </button>
        <?php if (hasPermission('roles', 'create')): ?>
        <button class="btn btn-primary" onclick="showCreateModal()">
            <i class="bi bi-plus-circle"></i> <?php _e('add_new_role'); ?>
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- Roles List -->
<div class="card">
    <div class="card-body">
        <div id="loadingSpinner" class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden"><?php _e('loading'); ?></span>
            </div>
            <p class="mt-2"><?php _e('loading_roles'); ?></p>
        </div>
        
        <div id="rolesContainer" style="display:none;">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th><?php _e('sr_no'); ?></th>
                            <th><?php _e('name'); ?></th>
                            <th><?php _e('description'); ?></th>
                            <th><?php _e('users_count'); ?></th>
                            <th><?php _e('status'); ?></th>
                            <th><?php _e('actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="rolesTableBody">
                        <!-- Populated by JavaScript -->
                    </tbody>
                </table>
            </div>
        </div>
        
        <div id="emptyState" style="display:none;" class="text-center py-5">
            <i class="bi bi-shield" style="font-size: 4rem; color: #ccc;"></i>
            <h5 class="mt-3"><?php _e('no_roles_found'); ?></h5>
        </div>
    </div>
</div>

<!-- Create/Edit Modal -->
<div class="modal fade" id="roleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="roleModalTitle"><?php _e('add_new_role'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="roleForm">
                    <input type="hidden" id="roleId">
                    
                    <div class="mb-3">
                        <label for="roleName" class="form-label"><?php _e('role_name'); ?> *</label>
                        <input type="text" class="form-control" id="roleName" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="roleDescription" class="form-label"><?php _e('description'); ?></label>
                        <textarea class="form-control" id="roleDescription" rows="3"></textarea>
                    </div>
                    
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="roleActive" checked>
                        <label class="form-check-label" for="roleActive"><?php _e('active'); ?></label>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                <button type="button" class="btn btn-primary" onclick="saveRole()">
                    <i class="bi bi-check-circle"></i> <?php _e('save_role'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Translations for JavaScript
const LANG = {
    add_new_role: <?php echo json_encode(__('add_new_role')); ?>,
    edit_role: <?php echo json_encode(__('edit_role')); ?>,
    pending: <?php echo json_encode(__('pending')); ?>,
    users_label: <?php echo json_encode(__('users_label')); ?>,
    active: <?php echo json_encode(__('active')); ?>,
    inactive: <?php echo json_encode(__('inactive')); ?>,
    enter_role_name: <?php echo json_encode(__('enter_role_name')); ?>,
    confirm_delete_role: <?php echo json_encode(__('confirm_delete_role')); ?>,
    cannot_delete_system_roles: <?php echo json_encode(__('cannot_delete_system_roles')); ?>,
    edit: <?php echo json_encode(__('edit')); ?>,
    delete: <?php echo json_encode(__('delete')); ?>
};

// Store roles data
let rolesData = [];
let roleModal;

// Initialize
document.addEventListener('DOMContentLoaded', async function() {
    console.log('🎬 Page loaded');
    
    // Initialize modal
    roleModal = new bootstrap.Modal(document.getElementById('roleModal'));
    
    // Initialize sync manager
    await syncManager.init();
    
    // Load roles
    await loadRoles();
    
    // Enter key support in modal
    document.getElementById('roleForm').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            saveRole();
        }
    });
});

// Load roles from sync manager
async function loadRoles() {
    console.log('📥 Loading roles...');
    document.getElementById('loadingSpinner').style.display = 'block';
    document.getElementById('rolesContainer').style.display = 'none';
    
    try {
        rolesData = await syncManager.getRoles();
        console.log(`✅ Loaded ${rolesData.length} roles`);
        displayRoles();
    } catch (error) {
        console.error('❌ Load failed:', error);
        alert('Failed to load roles');
    } finally {
        document.getElementById('loadingSpinner').style.display = 'none';
    }
}

// Display roles in table
function displayRoles() {
    const tbody = document.getElementById('rolesTableBody');
    
    if (rolesData.length === 0) {
        document.getElementById('emptyState').style.display = 'block';
        document.getElementById('rolesContainer').style.display = 'none';
        return;
    }
    
    document.getElementById('emptyState').style.display = 'none';
    document.getElementById('rolesContainer').style.display = 'block';
    
    tbody.innerHTML = rolesData.map((role, index) => `
        <tr>
            <td>${index + 1}</td>
            <td>
                <strong>${escapeHtml(role.name)}</strong>
                ${String(role.id).startsWith('temp_') ? '<span class="badge bg-warning text-dark ms-1">' + LANG.pending + '</span>' : ''}
            </td>
            <td>${escapeHtml(role.description || '')}</td>
            <td><span class="badge bg-info">${role.user_count || 0} ${LANG.users_label}</span></td>
            <td>
                ${role.is_active ? 
                    '<span class="badge bg-success">' + LANG.active + '</span>' : 
                    '<span class="badge bg-secondary">' + LANG.inactive + '</span>'}
            </td>
            <td>
                <div class="btn-group btn-group-sm">
                    <?php if (hasPermission('roles', 'edit')): ?>
                    <button class="btn btn-outline-primary" onclick="showEditModal(${role.id})" title="${LANG.edit}">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <?php endif; ?>
                    
                    <?php if (hasPermission('roles', 'delete')): ?>
                    <button class="btn btn-outline-danger" onclick="deleteRole(${role.id})" 
                            ${role.id <= 2 ? 'disabled title="' + LANG.cannot_delete_system_roles + '"' : 'title="' + LANG.delete + '"'}>
                        <i class="bi bi-trash"></i>
                    </button>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
    `).join('');
}

// Show create modal
function showCreateModal() {
    document.getElementById('roleModalTitle').textContent = LANG.add_new_role;
    document.getElementById('roleForm').reset();
    document.getElementById('roleId').value = '';
    document.getElementById('roleActive').checked = true;
    roleModal.show();
}

// Show edit modal
function showEditModal(idParam) {
    const id = parseInt(idParam);  // ← Convert to number!
    const role = rolesData.find(r => r.id == id);
    if (!role) return;
    
    document.getElementById('roleModalTitle').textContent = LANG.edit_role;
    document.getElementById('roleId').value = role.id;
    document.getElementById('roleName').value = role.name;
    document.getElementById('roleDescription').value = role.description || '';
    document.getElementById('roleActive').checked = role.is_active == 1;
    
    roleModal.show();
}

// Save role (create or update)
async function saveRole() {
    const idValue = document.getElementById('roleId').value;
    const id = idValue ? parseInt(idValue) : null;  // ← Convert to number!
    const name = document.getElementById('roleName').value.trim();
    const description = document.getElementById('roleDescription').value.trim();
    const is_active = document.getElementById('roleActive').checked ? 1 : 0;
    
    if (!name) {
        alert(LANG.enter_role_name);
        return;
    }
    
    const data = { name, description, is_active };
    
    try {
        let result;
        
        if (id) {
            // Update
            data.id = id;  // ← Now it's a NUMBER
            result = await syncManager.updateRole(data);
            
            // Update in array
            const index = rolesData.findIndex(r => r.id == id);
            if (index !== -1) {
                rolesData[index] = result;
            }
        } else {
            // Create
            result = await syncManager.createRole(data);
            
            // Add to array
            rolesData.push(result);
        }
        
        // Update display
        displayRoles();
        
        // Close modal
        roleModal.hide();
        
    } catch (error) {
        console.error('Save failed:', error);
    }
}

// Delete role
async function deleteRole(idParam) {
    const id = parseInt(idParam);  // ← Convert to number!
    const role = rolesData.find(r => r.id == id);
    if (!role) return;
    
    if (!confirm(LANG.confirm_delete_role.replace('%s', role.name))) {
        return;
    }
    
    try {
        await syncManager.deleteRole(id);
        
        // Remove from array immediately
        rolesData = rolesData.filter(r => r.id != id);
        
        // Update display
        displayRoles();
        
    } catch (error) {
        console.error('Delete failed:', error);
    }
}

// Manual sync
async function manualSync() {
    console.log('🔄 Manual sync requested');
    const success = await syncManager.sync();
    if (success) {
        await loadRoles();
    }
}

// Escape HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

<?php require_once '../../includes/footer.php'; ?>
