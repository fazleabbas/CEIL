<?php
require_once '../../config/database.php';
require_once '../../includes/functions.php';
$pageTitle = __('boq_list');
if (!hasPermission('boq', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('');
}

// Handle delete action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    checkCSRF();
    $deleteId = (int)$_POST['id'];
    $stmt = $conn->prepare("SELECT * FROM boqs WHERE id = ?");
    $stmt->execute([$deleteId]);
    if ($delBoq = $stmt->fetch()) {
        // boqCanDelete(): draft / rejected only once approval exists (0051).
        if (boqCanDelete($delBoq)) {
            $conn->prepare("DELETE FROM boqs WHERE id = ?")->execute([$deleteId]);
            logAudit('boq', 'delete', $deleteId);
            setAlert(__('boq_deleted'), 'success');
        } else {
            setAlert(__('no_permission'), 'danger');
        }
    }
    redirect('modules/transactions/boq_list.php');
    exit;
}

require_once '../../includes/header.php';

// Filters
$locationFilter = $_GET['location'] ?? '';
$costCentreFilter = $_GET['cost_centre'] ?? '';
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

// Approval workflow (migration 0051). Pre-migration tenants see the old list.
$hasApproval = boqApprovalAvailable($conn);
$statusFilter = $hasApproval ? ($_GET['status'] ?? '') : '';
if (!in_array($statusFilter, ['', 'draft', 'pending_approval', 'approved', 'rejected'], true)) {
    $statusFilter = '';
}

$locations = getUserLocations();
$costCentres = getUserCostCentres();

// Build query
$whereClause = "WHERE b.boq_date BETWEEN ? AND ?";
$params = [$dateFrom, $dateTo];

if ($locationFilter) {
    $whereClause .= " AND b.location_id = ?";
    $params[] = $locationFilter;
} else {
    $locationIds = array_column($locations, 'id');
    if (!empty($locationIds)) {
        $placeholders = implode(',', array_fill(0, count($locationIds), '?'));
        $whereClause .= " AND b.location_id IN ($placeholders)";
        $params = array_merge($params, $locationIds);
    }
}

if ($costCentreFilter) {
    $whereClause .= " AND b.cost_centre_id = ?";
    $params[] = $costCentreFilter;
}

if ($statusFilter !== '') {
    $whereClause .= " AND b.status = ?";
    $params[] = $statusFilter;
}

$statusBadge = function ($s) {
    switch ($s) {
        case 'pending_approval': return 'warning';
        case 'approved': return 'success';
        case 'rejected': return 'danger';
        default: return 'secondary';
    }
};

$stmt = $conn->prepare("
    SELECT
        b.*,
        l.name as location_name,
        cc.name as cost_centre_name,
        cc.code as cost_centre_code,
        u.name as created_by_name,
        (SELECT COUNT(*) FROM boq_details WHERE boq_id = b.id) as item_count
    FROM boqs b
    LEFT JOIN locations l ON b.location_id = l.id
    LEFT JOIN cost_centres cc ON b.cost_centre_id = cc.id
    LEFT JOIN users u ON b.created_by = u.id
    $whereClause
    ORDER BY b.id DESC
");
$stmt->execute($params);
$boqs = $stmt->fetchAll();
?>

<div class="row justify-content-center">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="bi bi-rulers"></i> <?php _e('boq_list'); ?>
                </h5>
                <div>
                    <?php if (hasPermission('boq', 'create')): ?>
                    <a href="boq.php" class="btn btn-sm btn-primary">
                        <i class="bi bi-plus"></i> <?php _e('new_boq'); ?>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <!-- Filters -->
                <form method="GET" class="row g-3 mb-4">
                    <div class="col-md-<?php echo $hasApproval ? 2 : 3; ?>">
                        <label class="form-label"><?php _e('location'); ?></label>
                        <select name="location" class="form-select form-select-sm">
                            <option value=""><?php _e('all_locations'); ?></option>
                            <?= renderTreeOptions($locations, $locationFilter) ?>
                        </select>
                    </div>
                    <div class="col-md-<?php echo $hasApproval ? 2 : 3; ?>">
                        <label class="form-label"><?php _e('cost_centre'); ?></label>
                        <select name="cost_centre" class="form-select form-select-sm">
                            <option value=""><?php _e('all_cost_centres'); ?></option>
                            <?= renderTreeOptions($costCentres, $costCentreFilter, null, 'name', 'code') ?>
                        </select>
                    </div>
                    <?php if ($hasApproval): ?>
                    <div class="col-md-2">
                        <label class="form-label"><?php _e('status'); ?></label>
                        <select name="status" class="form-select form-select-sm">
                            <option value=""><?php _e('all_statuses'); ?></option>
                            <?php foreach (['draft', 'pending_approval', 'approved', 'rejected'] as $st): ?>
                            <option value="<?php echo $st; ?>" <?php echo $statusFilter === $st ? 'selected' : ''; ?>><?php _e('status_' . $st); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="col-md-2">
                        <label class="form-label"><?php _e('from_date'); ?></label>
                        <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo $dateFrom; ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><?php _e('to_date'); ?></label>
                        <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo $dateTo; ?>">
                    </div>
                    <div class="col-md-2 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-sm btn-primary"><?php _e('filter'); ?></button>
                        <a href="boq_list.php" class="btn btn-sm btn-secondary"><?php _e('reset'); ?></a>
                    </div>
                </form>

                <?php if (empty($boqs)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-rulers" style="font-size: 3rem; color: #ccc;"></i>
                    <p class="text-muted mt-3"><?php _e('no_boqs_found'); ?></p>
                    <?php if (hasPermission('boq', 'create')): ?>
                    <a href="boq.php" class="btn btn-primary">
                        <i class="bi bi-plus"></i> <?php _e('new_boq'); ?>
                    </a>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover table-sm">
                        <thead class="table-light">
                            <tr>
                                <th><?php _e('boq_number'); ?></th>
                                <th><?php _e('date'); ?></th>
                                <th><?php _e('location'); ?></th>
                                <th><?php _e('cost_centre'); ?></th>
                                <th class="text-center"><?php _e('items'); ?></th>
                                <?php if ($hasApproval): ?>
                                <th class="text-center"><?php _e('status'); ?></th>
                                <?php endif; ?>
                                <th><?php _e('created_by'); ?></th>
                                <th class="text-center"><?php _e('actions'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($boqs as $b): ?>
                            <tr>
                                <td>
                                    <a href="boq_view.php?id=<?php echo $b['id']; ?>">
                                        <strong><?php echo htmlspecialchars($b['boq_number']); ?></strong>
                                    </a>
                                </td>
                                <td><?php echo formatDate($b['boq_date']); ?></td>
                                <td><?php echo htmlspecialchars($b['location_name']); ?></td>
                                <td>
                                    <?php if ($b['cost_centre_name']): ?>
                                    <small><?php echo htmlspecialchars($b['cost_centre_code']); ?></small> -
                                    <?php echo htmlspecialchars($b['cost_centre_name']); ?>
                                    <?php else: ?>
                                    <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-secondary"><?php echo $b['item_count']; ?></span>
                                </td>
                                <?php if ($hasApproval): ?>
                                <td class="text-center"><span class="badge bg-<?php echo $statusBadge($b['status']); ?>"><?php _e('status_' . $b['status']); ?></span></td>
                                <?php endif; ?>
                                <td><?php echo htmlspecialchars($b['created_by_name']); ?></td>
                                <td class="text-center">
                                    <a href="boq_view.php?id=<?php echo $b['id']; ?>" class="btn btn-sm btn-outline-primary" title="<?php _e('view'); ?>">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <?php if (boqCanEdit($b)): ?>
                                    <a href="boq.php?id=<?php echo $b['id']; ?>" class="btn btn-sm btn-outline-warning" title="<?php _e('edit'); ?>">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <?php endif; ?>
                                    <?php if (boqCanDelete($b)): ?>
                                    <button onclick="confirmDelete(<?php echo $b['id']; ?>)" class="btn btn-sm btn-outline-danger" title="<?php _e('delete'); ?>">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-trash"></i> <?php _e('confirm_delete'); ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p><?php _e('confirm_delete_boq'); ?></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> <?php _e('cancel'); ?>
                </button>
                <form id="deleteForm" method="POST" class="d-inline">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" id="deleteId">
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash"></i> <?php _e('delete'); ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
let deleteModal;
document.addEventListener('DOMContentLoaded', function() {
    deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
});
function confirmDelete(id) {
    document.getElementById('deleteId').value = id;
    deleteModal.show();
}
</script>

<?php require_once '../../includes/footer.php'; ?>
