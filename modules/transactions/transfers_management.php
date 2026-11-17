<?php
/**
 * Stock Transfers Management
 * List, view, and manage stock transfers between locations
 */
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");

require_once '../../config/database.php';
require_once '../../includes/functions.php';

$pageTitle = __('transfers_management');

if (!hasPermission('stock_transfer', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('');
}

// Get user's accessible locations
$userLocations = getUserLocations();
$locationIds = array_column($userLocations, 'id');

// Handle case when user has no locations assigned
if (empty($locationIds)) {
    // User has no locations - show empty results
    $transfers = [];
    $allLocations = [];
    require_once '../../includes/header.php';
    goto render_page;
}

// Build location filter for SQL
$locationPlaceholders = implode(',', array_fill(0, count($locationIds), '?'));

// Get filters
$dateFrom = $_GET['date_from'] ?? date('Y-m-01'); // First day of current month
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$statusFilter = $_GET['status'] ?? '';
$locationFilter = $_GET['location_id'] ?? '';
$search = $_GET['search'] ?? '';

// Reverse-transfer columns only exist after migration 0039 — guard so the list
// still works on tenants that have not run it yet.
$hasReverseCol = false;
try {
    $colStmt = $conn->query("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_transfers'
          AND COLUMN_NAME = 'reverse_of_transfer_id'
    ");
    $hasReverseCol = ((int)$colStmt->fetchColumn() > 0);
} catch (PDOException $e) {
    $hasReverseCol = false;
}
$revSelect = $hasReverseCol
    ? ", rt.transfer_number as reverse_of_number,
        (SELECT COUNT(*) FROM stock_transfers r2 WHERE r2.reverse_of_transfer_id = t.id AND r2.status <> 'cancelled') as reverse_count"
    : ", NULL as reverse_of_number, 0 as reverse_count";
$revJoin = $hasReverseCol
    ? "LEFT JOIN stock_transfers rt ON t.reverse_of_transfer_id = rt.id"
    : "";

// Build query
$sql = "
    SELECT
        t.*,
        fl.name as from_location_name,
        tl.name as to_location_name,
        u.name as created_by_name,
        du.name as dispatched_by_name,
        ru.name as received_by_name,
        (SELECT COUNT(*) FROM stock_transfer_details WHERE transfer_id = t.id) as item_count,
        (SELECT SUM(dispatched_quantity) FROM stock_transfer_details WHERE transfer_id = t.id) as total_quantity,
        t.requisition_id,
        rv.voucher_number as requisition_number
        $revSelect
    FROM stock_transfers t
    LEFT JOIN locations fl ON t.from_location_id = fl.id
    LEFT JOIN locations tl ON t.to_location_id = tl.id
    LEFT JOIN users u ON t.created_by = u.id
    LEFT JOIN users du ON t.dispatched_by = du.id
    LEFT JOIN users ru ON t.received_by = ru.id
    LEFT JOIN requisition_vouchers rv ON t.requisition_id = rv.id
    $revJoin
    WHERE (t.from_location_id IN ($locationPlaceholders) OR t.to_location_id IN ($locationPlaceholders))
";

$params = array_merge($locationIds, $locationIds);

// Apply filters
if ($dateFrom) {
    $sql .= " AND t.transfer_date >= ?";
    $params[] = $dateFrom;
}
if ($dateTo) {
    $sql .= " AND t.transfer_date <= ?";
    $params[] = $dateTo;
}
if ($statusFilter) {
    $sql .= " AND t.status = ?";
    $params[] = $statusFilter;
}
if ($locationFilter) {
    $sql .= " AND (t.from_location_id = ? OR t.to_location_id = ?)";
    $params[] = $locationFilter;
    $params[] = $locationFilter;
}
if ($search) {
    $sql .= " AND (t.transfer_number LIKE ? OR fl.name LIKE ? OR tl.name LIKE ? OR t.vehicle_number LIKE ? OR t.driver_name LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$sql .= " ORDER BY t.transfer_date DESC, t.id DESC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$transfers = $stmt->fetchAll();

// Get all locations for filter dropdown
$allLocations = getUserLocations();

require_once '../../includes/header.php';

render_page:
?>

<div class="row mb-3">
    <div class="col-md-6">
        <h2><i class="bi bi-box-arrow-right"></i> <?php _e('transfers_management'); ?></h2>
    </div>
    <div class="col-md-6 text-end">
        <?php if (hasPermission('stock_transfer', 'create')): ?>
        <a href="transfers.php" class="btn btn-primary">
            <i class="bi bi-plus-circle"></i> <?php _e('add_new_transfer'); ?>
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2">
            <div class="col-md-2">
                <label class="form-label small"><?php _e('from_date'); ?></label>
                <input type="date" name="date_from" class="form-control"
                       value="<?php echo htmlspecialchars($dateFrom); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small"><?php _e('to_date'); ?></label>
                <input type="date" name="date_to" class="form-control"
                       value="<?php echo htmlspecialchars($dateTo); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small"><?php _e('status'); ?></label>
                <select name="status" class="form-select">
                    <option value=""><?php _e('all_statuses'); ?></option>
                    <option value="draft" <?php echo $statusFilter === 'draft' ? 'selected' : ''; ?>><?php _e('status_draft'); ?></option>
                    <option value="in_transit" <?php echo $statusFilter === 'in_transit' ? 'selected' : ''; ?>><?php _e('status_in_transit'); ?></option>
                    <option value="received" <?php echo $statusFilter === 'received' ? 'selected' : ''; ?>><?php _e('status_received'); ?></option>
                    <option value="cancelled" <?php echo $statusFilter === 'cancelled' ? 'selected' : ''; ?>><?php _e('status_cancelled'); ?></option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small"><?php _e('location'); ?></label>
                <select name="location_id" class="form-select">
                    <option value=""><?php _e('all_locations'); ?></option>
                    <?= renderTreeOptions($allLocations, $locationFilter) ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small"><?php _e('search'); ?></label>
                <input type="text" name="search" class="form-control"
                       placeholder="<?php _e('search_transfers'); ?>"
                       value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small">&nbsp;</label>
                <div class="d-flex gap-1">
                    <button type="submit" class="btn btn-primary flex-grow-1">
                        <i class="bi bi-search"></i>
                    </button>
                    <a href="transfers_management.php" class="btn btn-secondary">
                        <i class="bi bi-x-circle"></i>
                    </a>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Transfers Table -->
<div class="card">
    <div class="card-body">
        <?php if (empty($transfers)): ?>
        <div class="text-center py-5">
            <i class="bi bi-inbox" style="font-size: 3rem; color: #ccc;"></i>
            <p class="text-muted mt-3"><?php _e('no_transfers_found'); ?></p>
            <?php if (hasPermission('stock_transfer', 'create')): ?>
            <a href="transfers.php" class="btn btn-primary mt-2">
                <i class="bi bi-plus-circle"></i> <?php _e('add_new_transfer'); ?>
            </a>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <span class="text-muted"><?php echo count($transfers); ?> <?php _e('transfers_management'); ?></span>
        </div>

        <div class="table-responsive">
            <table class="table table-hover table-striped">
                <thead class="table-light">
                    <tr>
                        <th style="width: 120px;"><?php _e('transfer_number'); ?></th>
                        <th style="width: 100px;"><?php _e('date'); ?></th>
                        <th><?php _e('from_location'); ?></th>
                        <th><?php _e('to_location'); ?></th>
                        <th class="text-center" style="width: 80px;"><?php _e('items'); ?></th>
                        <th class="text-center" style="width: 120px;"><?php _e('status'); ?></th>
                        <th style="width: 120px;"><?php _e('vehicle_number'); ?></th>
                        <th style="width: 180px;" class="text-center"><?php _e('actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transfers as $transfer): ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($transfer['transfer_number']); ?></strong>
                            <?php if (!empty($transfer['reverse_of_transfer_id'])): ?>
                            <br><a href="transfers_view.php?id=<?php echo $transfer['reverse_of_transfer_id']; ?>" class="badge bg-dark text-decoration-none" title="<?php _e('reverse_of'); ?>">
                                <i class="bi bi-arrow-counterclockwise"></i> <?php echo htmlspecialchars($transfer['reverse_of_number'] ?? ''); ?>
                            </a>
                            <?php elseif (!empty($transfer['reverse_count'])): ?>
                            <br><span class="badge bg-secondary" title="<?php _e('has_reverse_transfers'); ?>">
                                <i class="bi bi-arrow-counterclockwise"></i> <?php echo (int)$transfer['reverse_count']; ?> <?php _e('reversed'); ?>
                            </span>
                            <?php endif; ?>
                            <?php if (!empty($transfer['requisition_id'])): ?>
                            <br><a href="<?php echo BASE_URL; ?>modules/transactions/requisition_voucher_view.php?id=<?php echo $transfer['requisition_id']; ?>" class="badge bg-info text-decoration-none" title="<?php _e('from_requisition'); ?>">
                                <i class="bi bi-clipboard-check"></i> <?php echo htmlspecialchars($transfer['requisition_number']); ?>
                            </a>
                            <?php endif; ?>
                        </td>
                        <td><?php echo formatDate($transfer['transfer_date']); ?></td>
                        <td>
                            <i class="bi bi-geo-alt text-danger"></i>
                            <?php echo htmlspecialchars($transfer['from_location_name']); ?>
                        </td>
                        <td>
                            <i class="bi bi-geo-alt-fill text-success"></i>
                            <?php echo htmlspecialchars($transfer['to_location_name']); ?>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-info"><?php echo $transfer['item_count']; ?></span>
                        </td>
                        <td class="text-center">
                            <?php
                            $statusClass = [
                                'draft' => 'bg-secondary',
                                'in_transit' => 'bg-warning text-dark',
                                'received' => 'bg-success',
                                'cancelled' => 'bg-danger'
                            ];
                            $statusIcon = [
                                'draft' => 'bi-file-earmark',
                                'in_transit' => 'bi-truck',
                                'received' => 'bi-check-circle',
                                'cancelled' => 'bi-x-circle'
                            ];
                            $status = $transfer['status'];
                            ?>
                            <span class="badge <?php echo $statusClass[$status] ?? 'bg-secondary'; ?>">
                                <i class="bi <?php echo $statusIcon[$status] ?? ''; ?>"></i>
                                <?php _e('status_' . $status); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($transfer['vehicle_number'] ?? '-'); ?></td>
                        <td class="text-center">
                            <!-- View -->
                            <a href="transfers_view.php?id=<?php echo $transfer['id']; ?>"
                               class="btn btn-sm btn-info" title="<?php _e('view'); ?>">
                                <i class="bi bi-eye"></i>
                            </a>

                            <?php if ($transfer['status'] === 'draft'): ?>
                                <?php if (hasPermission('stock_transfer', 'edit') && canAccessLocation($transfer['from_location_id']) && empty($transfer['reverse_of_transfer_id'])): ?>
                                <!-- Edit (only draft, forward transfers only) -->
                                <a href="transfers.php?id=<?php echo $transfer['id']; ?>"
                                   class="btn btn-sm btn-warning" title="<?php _e('edit'); ?>">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <?php endif; ?>

                                <?php if (hasPermission('stock_transfer', 'create') && canAccessLocation($transfer['from_location_id'])): ?>
                                <!-- Dispatch (only draft) -->
                                <button type="button" class="btn btn-sm btn-primary"
                                        onclick="dispatchTransfer(<?php echo $transfer['id']; ?>)"
                                        title="<?php _e('dispatch_transfer'); ?>">
                                    <i class="bi bi-truck"></i>
                                </button>
                                <?php endif; ?>

                                <?php if (hasPermission('stock_transfer', 'delete')): ?>
                                <!-- Delete (only draft) -->
                                <button type="button" class="btn btn-sm btn-danger"
                                        onclick="deleteTransfer(<?php echo $transfer['id']; ?>)"
                                        title="<?php _e('delete'); ?>">
                                    <i class="bi bi-trash"></i>
                                </button>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if ($transfer['status'] === 'in_transit'): ?>
                                <?php if (hasPermission('stock_transfer', 'edit') && canAccessLocation($transfer['to_location_id'])): ?>
                                <!-- Receive (only in_transit, user must have access to TO location) -->
                                <a href="transfers_receive.php?id=<?php echo $transfer['id']; ?>"
                                   class="btn btn-sm btn-success" title="<?php _e('receive_transfer'); ?>">
                                    <i class="bi bi-box-arrow-in-down"></i>
                                </a>
                                <?php endif; ?>

                                <?php if (hasPermission('stock_transfer', 'delete')): ?>
                                <!-- Cancel (only in_transit) -->
                                <button type="button" class="btn btn-sm btn-danger"
                                        onclick="cancelTransfer(<?php echo $transfer['id']; ?>)"
                                        title="<?php _e('cancel_transfer'); ?>">
                                    <i class="bi bi-x-circle"></i>
                                </button>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if ($transfer['status'] === 'received' || $transfer['status'] === 'in_transit'): ?>
                                <!-- Print DN -->
                                <a href="transfers_view.php?id=<?php echo $transfer['id']; ?>&print=1"
                                   class="btn btn-sm btn-secondary" target="_blank"
                                   title="<?php _e('print_delivery_note'); ?>">
                                    <i class="bi bi-printer"></i>
                                </a>
                            <?php endif; ?>

                            <?php if ($transfer['status'] === 'received' && empty($transfer['reverse_of_transfer_id'])
                                      && hasPermission('stock_transfer', 'create') && canAccessLocation($transfer['to_location_id'])): ?>
                                <!-- Reverse Transfer (return received goods to source) -->
                                <a href="transfer_reverse.php?from=<?php echo $transfer['id']; ?>"
                                   class="btn btn-sm btn-dark" title="<?php _e('reverse_transfer'); ?>">
                                    <i class="bi bi-arrow-counterclockwise"></i>
                                </a>
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

<!-- Dispatch Confirmation Modal -->
<div class="modal fade" id="dispatchModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-truck"></i> <?php _e('dispatch_transfer'); ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p><?php _e('confirm_dispatch'); ?></p>
                <input type="hidden" id="dispatchTransferId">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> <?php _e('cancel'); ?>
                </button>
                <button type="button" class="btn btn-primary" onclick="confirmDispatch()">
                    <i class="bi bi-truck"></i> <?php _e('dispatch_transfer'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Cancel Confirmation Modal -->
<div class="modal fade" id="cancelModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-x-circle"></i> <?php _e('cancel_transfer'); ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p><?php _e('confirm_cancel_transfer'); ?></p>
                <div class="mb-3">
                    <label class="form-label"><?php _e('cancel_reason'); ?></label>
                    <textarea id="cancelReason" class="form-control" rows="3" required></textarea>
                </div>
                <input type="hidden" id="cancelTransferId">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-arrow-left"></i> <?php _e('cancel'); ?>
                </button>
                <button type="button" class="btn btn-danger" onclick="confirmCancel()">
                    <i class="bi bi-x-circle"></i> <?php _e('cancel_transfer'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let dispatchModal, cancelModal;

document.addEventListener('DOMContentLoaded', function() {
    dispatchModal = new bootstrap.Modal(document.getElementById('dispatchModal'));
    cancelModal = new bootstrap.Modal(document.getElementById('cancelModal'));
});

function dispatchTransfer(id) {
    document.getElementById('dispatchTransferId').value = id;
    dispatchModal.show();
}

function confirmDispatch() {
    const id = document.getElementById('dispatchTransferId').value;

    fetch(BASE_URL + 'api/transfers-sync.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            action: 'dispatch',
            transfer_id: id
        })
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            location.reload();
        } else {
            alert(result.error || 'Failed to dispatch transfer');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred');
    });
}

function cancelTransfer(id) {
    document.getElementById('cancelTransferId').value = id;
    document.getElementById('cancelReason').value = '';
    cancelModal.show();
}

function confirmCancel() {
    const id = document.getElementById('cancelTransferId').value;
    const reason = document.getElementById('cancelReason').value.trim();

    if (!reason) {
        alert('Please enter a reason for cancellation');
        return;
    }

    fetch(BASE_URL + 'api/transfers-sync.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            action: 'cancel',
            transfer_id: id,
            cancel_reason: reason
        })
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            location.reload();
        } else {
            alert(result.error || 'Failed to cancel transfer');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred');
    });
}

function deleteTransfer(id) {
    if (!confirm('Are you sure you want to delete this transfer?')) {
        return;
    }

    fetch(BASE_URL + 'api/transfers-sync.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            action: 'delete',
            transfer_id: id
        })
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            location.reload();
        } else {
            alert(result.error || 'Failed to delete transfer');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred');
    });
}
</script>

<?php require_once '../../includes/footer.php'; ?>
