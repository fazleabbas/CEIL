<?php
/**
 * Cost Centres Management - Online Only
 * Simple AJAX-based CRUD, no offline sync
 */
require_once '../../config/database.php';
require_once '../../includes/functions.php';

$pageTitle = __('cost_centres_management');

if (!hasPermission('cost_centres', 'view')) {
    setAlert('You do not have permission to access this page', 'danger');
    redirect('');
}

require_once '../../includes/header.php';
?>

<div class="row mb-3">
    <div class="col-md-6">
        <h2><i class="bi bi-building"></i> <?php _e('cost_centres_management'); ?></h2>
    </div>
    <div class="col-md-6 text-end">
        <?php if (hasPermission('cost_centres', 'create')): ?>
        <a href="<?php echo BASE_URL; ?>modules/masters/cost_centres-import.php" class="btn btn-outline-success">
            <i class="bi bi-upload"></i> <?php _e('import_cost_centres'); ?>
        </a>
        <button class="btn btn-primary" onclick="showCreateModal()">
            <i class="bi bi-plus-circle"></i> <?php _e('add_new_cost_centre'); ?>
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
                       placeholder="<?php _e('search_cost_centres'); ?>"
                       onkeyup="searchCostCentres()">
            </div>
            <div class="col-md-2">
                <button onclick="clearSearch()" class="btn btn-secondary w-100" id="clearBtn" style="display:none;">
                    <i class="bi bi-x-circle"></i> <?php _e('clear'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Cost Centres Table -->
<div class="card">
    <div class="card-body">
        <div id="loadingSpinner" class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden"><?php _e('loading'); ?></span>
            </div>
            <p class="mt-2"><?php _e('loading_cost_centres'); ?></p>
        </div>

        <div id="costCentresContainer" style="display:none;">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th><?php _e('sr_no'); ?></th>
                            <th><?php _e('cost_centre_code'); ?></th>
                            <th><?php _e('name'); ?></th>
                            <th class="d-none d-md-table-cell"><?php _e('description'); ?></th>
                            <th><?php _e('status'); ?></th>
                            <th class="text-end"><?php _e('budget_amount'); ?></th>
                            <th><?php _e('users_count'); ?></th>
                            <th><?php _e('actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="costCentresTableBody">
                    </tbody>
                </table>
            </div>
        </div>

        <div id="emptyState" style="display:none;" class="text-center py-5">
            <i class="bi bi-building" style="font-size: 4rem; color: #ccc;"></i>
            <h5 class="mt-3"><?php _e('no_cost_centres_found'); ?></h5>
            <p class="text-muted"><?php _e('start_adding_cost_centre'); ?></p>
        </div>
    </div>
</div>

<!-- Create/Edit Modal -->
<div class="modal fade" id="costCentreModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="costCentreModalTitle"><?php _e('add_new_cost_centre'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="costCentreForm">
                    <input type="hidden" id="costCentreId">

                    <ul class="nav nav-tabs mb-3" id="ccTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="cc-tab-details" data-bs-toggle="tab" data-bs-target="#ccTabDetails" type="button" role="tab">
                                <i class="bi bi-info-circle"></i> Details
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="cc-tab-notif" data-bs-toggle="tab" data-bs-target="#ccTabNotif" type="button" role="tab">
                                <i class="bi bi-bell"></i> Notifications
                            </button>
                        </li>
                    </ul>

                    <div class="tab-content">
                        <!-- Details tab (original fields, untouched) -->
                        <div class="tab-pane fade show active" id="ccTabDetails" role="tabpanel">
                            <div class="mb-3">
                                <label for="costCentreParent" class="form-label"><?php _e('parent_cost_centre'); ?></label>
                                <select class="form-select" id="costCentreParent">
                                    <option value=""><?php _e('none_root_level'); ?></option>
                                </select>
                                <small class="text-muted"><?php _e('parent_cost_centre_hint'); ?></small>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="costCentreCode" class="form-label"><?php _e('cost_centre_code'); ?> *</label>
                                    <input type="text" class="form-control" id="costCentreCode" required>
                                    <small class="text-muted"><?php _e('cost_centre_code_hint'); ?></small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="costCentreName" class="form-label"><?php _e('cost_centre_name'); ?> *</label>
                                    <input type="text" class="form-control" id="costCentreName" required>
                                    <small class="text-muted"><?php _e('cost_centre_name_hint'); ?></small>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="costCentreDescription" class="form-label"><?php _e('description'); ?></label>
                                <textarea class="form-control" id="costCentreDescription" rows="2"></textarea>
                            </div>

                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="costCentreStartDate" class="form-label"><?php _e('start_date'); ?></label>
                                    <input type="date" class="form-control" id="costCentreStartDate">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="costCentreEndDate" class="form-label"><?php _e('end_date'); ?></label>
                                    <input type="date" class="form-control" id="costCentreEndDate">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="costCentreStatus" class="form-label"><?php _e('status'); ?></label>
                                    <select class="form-select" id="costCentreStatus">
                                        <option value="active"><?php _e('active'); ?></option>
                                        <option value="completed"><?php _e('completed'); ?></option>
                                        <option value="on_hold"><?php _e('on_hold'); ?></option>
                                    </select>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="costCentreBudget" class="form-label"><?php _e('budget_amount'); ?></label>
                                    <input type="number" class="form-control" id="costCentreBudget" step="0.01" min="0" value="0">
                                    <small class="text-muted"><?php _e('budget_amount_hint'); ?></small>
                                </div>
                                <div class="col-md-6 mb-3 d-flex align-items-end">
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input" id="costCentreActive" checked>
                                        <label class="form-check-label" for="costCentreActive"><?php _e('active'); ?></label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Notifications tab (new) -->
                        <div class="tab-pane fade" id="ccTabNotif" role="tabpanel">
                            <div class="alert alert-info py-2 small mb-3">
                                <i class="bi bi-info-circle"></i>
                                These lists override the default approval routing <strong>for requisition vouchers using this cost centre</strong>.
                                If left empty, the system falls back to the existing permission-based behavior.
                            </div>

                            <div class="mb-3">
                                <label for="costCentreApprovers" class="form-label">
                                    Approvers — users who can approve &amp; receive approval-request notifications
                                </label>
                                <select class="form-select" id="costCentreApprovers" multiple style="width:100%;"></select>
                                <small class="text-muted">
                                    These users will get a bell notification + email whenever a requisition for this cost centre is sent for approval, and they are the only ones permitted to approve it.
                                </small>
                            </div>

                            <div class="mb-3">
                                <label for="costCentreWatchers" class="form-label">
                                    Watchers — users to be informed once approval is done
                                </label>
                                <select class="form-select" id="costCentreWatchers" multiple style="width:100%;"></select>
                                <small class="text-muted">
                                    These users get a bell + email when the requisition is approved. Informational only — they cannot approve.
                                </small>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                <button type="button" class="btn btn-primary" onclick="saveCostCentre()">
                    <i class="bi bi-check-circle"></i> <?php _e('save_cost_centre'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Translations for JavaScript
const LANG = {
    add_new_cost_centre: <?php echo json_encode(__('add_new_cost_centre')); ?>,
    edit_cost_centre: <?php echo json_encode(__('edit_cost_centre')); ?>,
    enter_cost_centre_name: <?php echo json_encode(__('enter_cost_centre_name')); ?>,
    enter_cost_centre_code: <?php echo json_encode(__('enter_cost_centre_code')); ?>,
    confirm_delete_cost_centre: <?php echo json_encode(__('confirm_delete_cost_centre')); ?>,
    cost_centre_has_transactions: <?php echo json_encode(__('cost_centre_has_transactions')); ?>,
    active: <?php echo json_encode(__('active')); ?>,
    inactive: <?php echo json_encode(__('inactive')); ?>,
    completed: <?php echo json_encode(__('completed')); ?>,
    on_hold: <?php echo json_encode(__('on_hold')); ?>,
    users_label: <?php echo json_encode(__('users_label')); ?>,
    edit: <?php echo json_encode(__('edit')); ?>,
    delete: <?php echo json_encode(__('delete')); ?>,
    none_root_level: <?php echo json_encode(__('none_root_level')); ?>
};

// Global variables
let costCentresData = [];
let costCentreModal;
let searchQuery = '';
let allUsersCache = null;          // cached list of active users for the notification selects
let usersLoadPromise = null;       // in-flight fetch for users (de-dupe)
const canEdit = <?= hasPermission('cost_centres', 'edit') ? 'true' : 'false' ?>;
const canDelete = <?= hasPermission('cost_centres', 'delete') ? 'true' : 'false' ?>;

// Initialize
document.addEventListener('DOMContentLoaded', async function() {
    costCentreModal = new bootstrap.Modal(document.getElementById('costCentreModal'));
    await loadCostCentres();

    document.getElementById('costCentreForm').addEventListener('keypress', function(e) {
        if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') {
            e.preventDefault();
            saveCostCentre();
        }
    });

    // Re-initialise Select2 on the notification selects when the modal opens
    // (Select2 needs the elements visible to size correctly).
    document.getElementById('costCentreModal').addEventListener('shown.bs.modal', function() {
        ['#costCentreApprovers', '#costCentreWatchers'].forEach(sel => {
            if ($(sel).hasClass('select2-hidden-accessible')) {
                $(sel).select2('destroy');
            }
            $(sel).select2({
                placeholder: 'Select users…',
                width: '100%',
                dropdownParent: $('#costCentreModal'),
                allowClear: true
            });
        });
    });
});

// Fetch active users once and reuse. Returns Promise<[{id,name,email}]>.
function loadActiveUsers() {
    if (allUsersCache) return Promise.resolve(allUsersCache);
    if (usersLoadPromise) return usersLoadPromise;

    usersLoadPromise = fetch(`${BASE_URL}api/cost_centres.php?action=users&_=${Date.now()}`, {
        headers: { 'Cache-Control': 'no-cache' }, cache: 'no-store'
    })
    .then(r => r.json())
    .then(j => {
        if (!j.success) throw new Error(j.error || 'Failed to load users');
        allUsersCache = j.users || [];
        return allUsersCache;
    })
    .catch(err => {
        usersLoadPromise = null;
        allUsersCache = null;
        throw err;
    });
    return usersLoadPromise;
}

// Populate one of the multi-select dropdowns with users and pre-select ids.
async function populateUserSelect(selectId, selectedIds) {
    const el = document.getElementById(selectId);
    if (!el) return;
    el.innerHTML = '';
    try {
        const users = await loadActiveUsers();
        users.forEach(u => {
            const opt = document.createElement('option');
            opt.value = u.id;
            opt.textContent = u.email ? `${u.name} (${u.email})` : u.name;
            if ((selectedIds || []).map(String).includes(String(u.id))) {
                opt.selected = true;
            }
            el.appendChild(opt);
        });
        // Refresh the Select2 widget so it re-renders selections.
        if ($(el).hasClass('select2-hidden-accessible')) {
            $(el).trigger('change');
        }
    } catch (e) {
        showToast('Failed to load users: ' + e.message, 'danger');
    }
}

// Load cost centres from API
async function loadCostCentres() {
    document.getElementById('loadingSpinner').style.display = 'block';
    document.getElementById('costCentresContainer').style.display = 'none';
    document.getElementById('emptyState').style.display = 'none';

    try {
        const url = `${BASE_URL}api/cost_centres.php?_=${Date.now()}`;
        const response = await fetch(url, {
            method: 'GET',
            headers: { 'Cache-Control': 'no-cache, no-store, must-revalidate', 'Pragma': 'no-cache' },
            cache: 'no-store'
        });

        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const result = await response.json();
        if (!result.success) throw new Error(result.error || 'Failed to load');

        costCentresData = result.cost_centres;
        displayCostCentres();
    } catch (error) {
        showToast('Failed to load cost centres: ' + error.message, 'danger');
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

function populateParentDropdown(excludeId = null) {
    const select = document.getElementById('costCentreParent');
    select.innerHTML = `<option value="">${LANG.none_root_level}</option>`;

    let excludeIds = [];
    if (excludeId !== null) {
        excludeIds = [excludeId, ...getDescendantIdsJS(costCentresData, excludeId)];
    }

    const tree = buildTreeJS(costCentresData);
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

// Display cost centres in table
function displayCostCentres() {
    const tbody = document.getElementById('costCentresTableBody');

    // Build tree-sorted list
    const tree = buildTreeJS(costCentresData);
    let sorted = flattenTreeJS(tree);

    if (searchQuery) {
        const q = searchQuery.toLowerCase();
        sorted = sorted.filter(cc =>
            cc.name.toLowerCase().includes(q) ||
            cc.code.toLowerCase().includes(q) ||
            (cc.description && cc.description.toLowerCase().includes(q))
        );
    }

    if (sorted.length === 0) {
        document.getElementById('emptyState').style.display = 'block';
        document.getElementById('costCentresContainer').style.display = 'none';
        return;
    }

    document.getElementById('emptyState').style.display = 'none';
    document.getElementById('costCentresContainer').style.display = 'block';

    tbody.innerHTML = sorted.map((cc, index) => {
        const indent = '<span class="text-muted">' + '\u2014 '.repeat(cc.depth) + '</span>';
        let statusBadge = '';
        if (cc.status === 'active') statusBadge = `<span class="badge bg-success">${LANG.active}</span>`;
        else if (cc.status === 'completed') statusBadge = `<span class="badge bg-primary">${LANG.completed}</span>`;
        else if (cc.status === 'on_hold') statusBadge = `<span class="badge bg-warning text-dark">${LANG.on_hold}</span>`;

        if (!cc.is_active || cc.is_active == 0) {
            statusBadge = `<span class="badge bg-secondary">${LANG.inactive}</span>`;
        }

        const budget = parseFloat(cc.budget_amount || 0);
        const budgetDisplay = budget > 0 ? formatNumber(budget) : '-';

        return `<tr>
            <td>${index + 1}</td>
            <td><strong>${escapeHtml(cc.code)}</strong></td>
            <td>${indent}${escapeHtml(cc.name)}</td>
            <td class="d-none d-md-table-cell">${escapeHtml(cc.description || '-')}</td>
            <td>${statusBadge}</td>
            <td class="text-end">${budgetDisplay}</td>
            <td><span class="badge bg-secondary">${cc.user_count || 0} ${LANG.users_label}</span></td>
            <td>
                <div class="btn-group btn-group-sm">
                    ${canEdit ? `<button class="btn btn-outline-primary" onclick="showEditModal(${cc.id})" title="${LANG.edit}">
                        <i class="bi bi-pencil"></i>
                    </button>` : ''}
                    ${canDelete ? `<button class="btn btn-outline-danger" onclick="deleteCostCentre(${cc.id})" title="${LANG.delete}">
                        <i class="bi bi-trash"></i>
                    </button>` : ''}
                </div>
            </td>
        </tr>`;
    }).join('');
}

// Show create modal
function showCreateModal() {
    document.getElementById('costCentreModalTitle').textContent = LANG.add_new_cost_centre;
    document.getElementById('costCentreForm').reset();
    document.getElementById('costCentreId').value = '';
    document.getElementById('costCentreActive').checked = true;
    document.getElementById('costCentreStatus').value = 'active';
    document.getElementById('costCentreBudget').value = '0';
    populateParentDropdown(null);
    document.getElementById('costCentreParent').value = '';

    // Reset to Details tab on open
    const detailsTab = document.getElementById('cc-tab-details');
    if (detailsTab && window.bootstrap) bootstrap.Tab.getOrCreateInstance(detailsTab).show();

    // Populate empty user lists (fire & forget — modal can still open immediately).
    populateUserSelect('costCentreApprovers', []);
    populateUserSelect('costCentreWatchers', []);

    costCentreModal.show();
}

// Show edit modal
function showEditModal(idParam) {
    const id = parseInt(idParam);
    const cc = costCentresData.find(c => parseInt(c.id) === id);
    if (!cc) return;

    document.getElementById('costCentreModalTitle').textContent = LANG.edit_cost_centre;
    document.getElementById('costCentreId').value = cc.id;
    document.getElementById('costCentreCode').value = cc.code;
    document.getElementById('costCentreName').value = cc.name;
    document.getElementById('costCentreDescription').value = cc.description || '';
    document.getElementById('costCentreStartDate').value = cc.start_date || '';
    document.getElementById('costCentreEndDate').value = cc.end_date || '';
    document.getElementById('costCentreStatus').value = cc.status || 'active';
    document.getElementById('costCentreBudget').value = cc.budget_amount || 0;
    document.getElementById('costCentreActive').checked = cc.is_active == 1;
    populateParentDropdown(id);
    document.getElementById('costCentreParent').value = cc.parent_id || '';

    // Reset to Details tab on open
    const detailsTab = document.getElementById('cc-tab-details');
    if (detailsTab && window.bootstrap) bootstrap.Tab.getOrCreateInstance(detailsTab).show();

    // Pre-select existing approvers/watchers (server returns approver_ids / watcher_ids).
    const approverIds = Array.isArray(cc.approver_ids) ? cc.approver_ids : [];
    const watcherIds  = Array.isArray(cc.watcher_ids)  ? cc.watcher_ids  : [];
    populateUserSelect('costCentreApprovers', approverIds);
    populateUserSelect('costCentreWatchers',  watcherIds);

    costCentreModal.show();
}

// Save cost centre
async function saveCostCentre() {
    const idValue = document.getElementById('costCentreId').value;
    const id = idValue ? parseInt(idValue) : null;
    const code = document.getElementById('costCentreCode').value.trim();
    const name = document.getElementById('costCentreName').value.trim();
    const description = document.getElementById('costCentreDescription').value.trim();
    const start_date = document.getElementById('costCentreStartDate').value;
    const end_date = document.getElementById('costCentreEndDate').value;
    const status = document.getElementById('costCentreStatus').value;
    const budget_amount = parseFloat(document.getElementById('costCentreBudget').value) || 0;
    const is_active = document.getElementById('costCentreActive').checked ? 1 : 0;

    const parentValue = document.getElementById('costCentreParent').value;
    const parent_id = parentValue ? parseInt(parentValue) : null;

    // Collect selected approvers / watchers (jQuery returns array of strings from Select2).
    const approver_ids = ($('#costCentreApprovers').val() || []).map(v => parseInt(v, 10)).filter(v => v > 0);
    const watcher_ids  = ($('#costCentreWatchers').val()  || []).map(v => parseInt(v, 10)).filter(v => v > 0);

    if (!code) { showToast(LANG.enter_cost_centre_code, 'warning'); return; }
    if (!name) { showToast(LANG.enter_cost_centre_name, 'warning'); return; }

    const data = { code, name, description, start_date, end_date, status, budget_amount, is_active, parent_id, approver_ids, watcher_ids };
    if (id) data.id = id;

    try {
        const url = `${BASE_URL}api/cost_centres-sync.php?_=${Date.now()}`;
        const action = id ? 'update' : 'create';

        const response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Cache-Control': 'no-cache' },
            cache: 'no-store',
            body: JSON.stringify({ action, data })
        });

        const result = await response.json();
        if (!result.success) throw new Error(result.error || 'Failed to save');

        showToast(result.message, 'success');
        costCentreModal.hide();
        await loadCostCentres();
    } catch (error) {
        showToast(error.message, 'danger');
    }
}

// Delete cost centre
async function deleteCostCentre(idParam) {
    const id = parseInt(idParam);
    const cc = costCentresData.find(c => parseInt(c.id) === id);
    if (!cc) return;

    const message = LANG.confirm_delete_cost_centre.replace('%s', cc.name);
    if (!confirm(message)) return;

    try {
        const url = `${BASE_URL}api/cost_centres-sync.php?_=${Date.now()}`;
        const response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Cache-Control': 'no-cache' },
            cache: 'no-store',
            body: JSON.stringify({ action: 'delete', data: { id } })
        });

        const result = await response.json();
        if (!result.success) throw new Error(result.error || 'Failed to delete');

        showToast(result.message, 'success');
        costCentresData = costCentresData.filter(c => parseInt(c.id) !== id);
        displayCostCentres();
    } catch (error) {
        showToast(error.message, 'danger');
    }
}

// Search
function searchCostCentres() {
    searchQuery = document.getElementById('searchInput').value.trim();
    document.getElementById('clearBtn').style.display = searchQuery ? 'block' : 'none';
    displayCostCentres();
}

function clearSearch() {
    document.getElementById('searchInput').value = '';
    searchQuery = '';
    document.getElementById('clearBtn').style.display = 'none';
    displayCostCentres();
}

// Toast notification
function showToast(message, type = 'info') {
    document.querySelectorAll('.cc-toast').forEach(t => t.remove());
    const toast = document.createElement('div');
    toast.className = `cc-toast alert alert-${type} alert-dismissible fade show`;
    toast.style.cssText = 'position:fixed;top:80px;right:20px;z-index:9999;min-width:300px;box-shadow:0 4px 6px rgba(0,0,0,0.2);';
    toast.innerHTML = `${message}<button type="button" class="btn-close" data-bs-dismiss="alert"></button>`;
    document.body.appendChild(toast);
    setTimeout(() => { toast.classList.remove('show'); setTimeout(() => toast.remove(), 150); }, 5000);
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatNumber(num) {
    return parseFloat(num).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
}
</script>

<?php require_once '../../includes/footer.php'; ?>
