<?php
require_once '../../config/database.php';
require_once '../../includes/functions.php';
$pageTitle = __('requisition_voucher_list');
if (!hasPermission('requisition_vouchers', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('');
}

// Handle delete action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $deleteId = (int)$_POST['id'];
    // Verify the voucher exists and is in draft status
    $stmt = $conn->prepare("SELECT id, voucher_number, status FROM requisition_vouchers WHERE id = ?");
    $stmt->execute([$deleteId]);
    $voucher = $stmt->fetch();

    if ($voucher && $voucher['status'] === 'draft') {
        if (hasPermission('requisition_vouchers', 'delete')) {
            $conn->prepare("DELETE FROM requisition_vouchers WHERE id = ? AND status = 'draft'")->execute([$deleteId]);
            setAlert(__('requisition_voucher_deleted'), 'success');
        } else {
            setAlert(__('no_permission'), 'danger');
        }
    } else {
        setAlert(__('only_draft_can_be_deleted'), 'danger');
    }
    redirect('modules/transactions/requisition_voucher_list.php');
    exit;
}

require_once '../../includes/header.php';

// Filters
$locationFilter = $_GET['location'] ?? '';
$costCentreFilter = $_GET['cost_centre'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

// Product filter: zero or more product ids, matched against the voucher's LINES.
// intval + array_filter drops anything non-numeric, so these can be embedded or
// bound without further escaping.
$selectedProductIds = [];
if (!empty($_GET['products']) && is_array($_GET['products'])) {
    $selectedProductIds = array_values(array_unique(array_filter(array_map('intval', $_GET['products']))));
}

// Get user's accessible locations and cost centres
$locations = getUserLocations();
$costCentres = getUserCostCentres();

// Build query
$whereClause = "WHERE rv.voucher_date BETWEEN ? AND ?";
$params = [$dateFrom, $dateTo];

if ($locationFilter) {
    $whereClause .= " AND rv.destination_location_id = ?";
    $params[] = $locationFilter;
} else {
    $locationIds = array_column($locations, 'id');
    if (!empty($locationIds)) {
        $placeholders = implode(',', array_fill(0, count($locationIds), '?'));
        $whereClause .= " AND rv.destination_location_id IN ($placeholders)";
        $params = array_merge($params, $locationIds);
    }
}

if ($costCentreFilter) {
    $whereClause .= " AND rv.cost_centre_id = ?";
    $params[] = $costCentreFilter;
}

if ($statusFilter) {
    $whereClause .= " AND rv.status = ?";
    $params[] = $statusFilter;
}

// Keep only vouchers containing at least one of the chosen products.
//
// EXISTS, not a JOIN: joining requisition_voucher_details would return one row
// per matching line, so a voucher containing two of the selected products would
// be listed twice. idx_rvd_product makes this cheap.
if (!empty($selectedProductIds)) {
    list($prodPlaceholders, $prodParams) = buildInClause($selectedProductIds);
    $whereClause .= " AND EXISTS (
        SELECT 1 FROM requisition_voucher_details rvd
        WHERE rvd.requisition_id = rv.id
          AND rvd.product_id IN ($prodPlaceholders)
    )";
    $params = array_merge($params, $prodParams);
}

// Names for the products already chosen, so Select2 shows the chips straight
// away on reload instead of flashing raw ids (or losing them entirely).
$preselectedProductOptions = [];
if (!empty($selectedProductIds)) {
    list($hydPlaceholders, $hydParams) = buildInClause($selectedProductIds);
    $hydStmt = $conn->prepare("SELECT id, name FROM products WHERE id IN ($hydPlaceholders) ORDER BY name");
    $hydStmt->execute($hydParams);
    foreach ($hydStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $preselectedProductOptions[(int)$row['id']] = $row['name'];
    }
}

$stmt = $conn->prepare("
    SELECT
        rv.*,
        l.name as location_name,
        cc.name as cost_centre_name,
        cc.code as cost_centre_code,
        u.name as created_by_name,
        (SELECT COUNT(*) FROM requisition_voucher_details WHERE requisition_id = rv.id) as item_count
    FROM requisition_vouchers rv
    LEFT JOIN locations l ON rv.destination_location_id = l.id
    LEFT JOIN cost_centres cc ON rv.cost_centre_id = cc.id
    LEFT JOIN users u ON rv.created_by = u.id
    $whereClause
    ORDER BY rv.id DESC
");
$stmt->execute($params);
$vouchers = $stmt->fetchAll();

// An APPROVED requisition keeps its pencil until something is raised against it.
// Resolved for the whole page in one batch (three queries, not three per row) and
// through the SAME helper the form uses, so this list can never show an edit the
// form would then refuse with a redirect.
$rvApprovedIds = [];
foreach ($vouchers as $v) {
    if (($v['status'] ?? '') === 'approved') {
        $rvApprovedIds[] = $v['id'];
    }
}
$rvEditBlockers = requisitionProcurementBlockers($rvApprovedIds, $conn);
?>

<div class="row justify-content-center">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="bi bi-clipboard-check"></i> <?php _e('requisition_voucher_list'); ?>
                </h5>
                <div>
                    <?php if (hasPermission('requisition_vouchers', 'create')): ?>
                    <a href="requisition_voucher.php" class="btn btn-sm btn-primary">
                        <i class="bi bi-plus"></i> <?php _e('new_requisition_voucher'); ?>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <!-- Filters -->
                <form method="GET" class="row g-3 mb-4" id="rvFilterForm">
                    <div class="col-md-2">
                        <label class="form-label"><?php _e('destination_location'); ?></label>
                        <select name="location" class="form-select form-select-sm">
                            <option value=""><?php _e('all_locations'); ?></option>
                            <?= renderTreeOptions($locations, $locationFilter) ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><?php _e('cost_centre'); ?></label>
                        <select name="cost_centre" class="form-select form-select-sm">
                            <option value=""><?php _e('all_cost_centres'); ?></option>
                            <?= renderTreeOptions($costCentres, $costCentreFilter, null, 'name', 'code') ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><?php _e('status'); ?></label>
                        <select name="status" class="form-select form-select-sm">
                            <option value=""><?php _e('all_statuses'); ?></option>
                            <option value="draft" <?php echo $statusFilter === 'draft' ? 'selected' : ''; ?>><?php _e('status_draft'); ?></option>
                            <option value="pending_approval" <?php echo $statusFilter === 'pending_approval' ? 'selected' : ''; ?>><?php _e('status_pending_approval'); ?></option>
                            <option value="approved" <?php echo $statusFilter === 'approved' ? 'selected' : ''; ?>><?php _e('status_approved'); ?></option>
                            <option value="rejected" <?php echo $statusFilter === 'rejected' ? 'selected' : ''; ?>><?php _e('status_rejected'); ?></option>
                            <option value="closed" <?php echo $statusFilter === 'closed' ? 'selected' : ''; ?>><?php _e('status_closed'); ?></option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><?php _e('from_date'); ?></label>
                        <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo $dateFrom; ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><?php _e('to_date'); ?></label>
                        <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo $dateTo; ?>">
                    </div>

                    <?php /* Second row: the first six filters already fill 12 columns.
                             Preselected products are rendered server-side as selected
                             <option>s so Select2 shows the chips immediately on reload
                             — no hydration round trip. */ ?>
                    <div class="col-md-8">
                        <label class="form-label" for="rvFilterProducts"><?php _e('products'); ?></label>
                        <select name="products[]" id="rvFilterProducts" class="form-select form-select-sm" multiple>
                            <?php foreach ($preselectedProductOptions as $pid => $pname): ?>
                            <option value="<?php echo (int)$pid; ?>" selected><?php echo htmlspecialchars($pname); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted"><?php _e('rv_product_filter_hint'); ?></small>
                    </div>

                    <div class="col-md-4 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-sm btn-primary"><?php _e('filter'); ?></button>
                        <a href="requisition_voucher_list.php" class="btn btn-sm btn-secondary"><?php _e('reset'); ?></a>
                    </div>
                </form>

                <?php if (empty($vouchers)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-clipboard-check" style="font-size: 3rem; color: #ccc;"></i>
                    <p class="text-muted mt-3"><?php _e('no_requisition_vouchers_found'); ?></p>
                    <?php if (hasPermission('requisition_vouchers', 'create')): ?>
                    <a href="requisition_voucher.php" class="btn btn-primary">
                        <i class="bi bi-plus"></i> <?php _e('new_requisition_voucher'); ?>
                    </a>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <p class="text-muted small mb-2"><?php echo sprintf(__('showing_vouchers'), count($vouchers)); ?></p>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover table-sm">
                        <thead class="table-light">
                            <tr>
                                <th><?php _e('voucher_number'); ?></th>
                                <th><?php _e('date'); ?></th>
                                <th><?php _e('destination'); ?></th>
                                <th><?php _e('cost_centre'); ?></th>
                                <th class="text-center"><?php _e('items'); ?></th>
                                <th class="text-center"><?php _e('status'); ?></th>
                                <th><?php _e('created_by'); ?></th>
                                <th class="text-center"><?php _e('actions'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($vouchers as $v): ?>
                            <tr>
                                <td>
                                    <a href="requisition_voucher_view.php?id=<?php echo $v['id']; ?>">
                                        <strong><?php echo htmlspecialchars($v['voucher_number']); ?></strong>
                                    </a>
                                </td>
                                <td><?php echo formatDate($v['voucher_date']); ?></td>
                                <td><?php echo htmlspecialchars($v['location_name']); ?></td>
                                <td>
                                    <?php if ($v['cost_centre_name']): ?>
                                    <small><?php echo htmlspecialchars($v['cost_centre_code']); ?></small> -
                                    <?php echo htmlspecialchars($v['cost_centre_name']); ?>
                                    <?php else: ?>
                                    <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-secondary"><?php echo $v['item_count']; ?></span>
                                </td>
                                <td class="text-center">
                                    <?php
                                    $statusBadges = [
                                        'draft' => '<span class="badge bg-secondary">' . __('status_draft') . '</span>',
                                        'pending_approval' => '<span class="badge bg-warning text-dark">' . __('status_pending_approval') . '</span>',
                                        'approved' => '<span class="badge bg-success">' . __('status_approved') . '</span>',
                                        'rejected' => '<span class="badge bg-danger">' . __('status_rejected') . '</span>',
                                        'closed' => '<span class="badge bg-dark">' . __('status_closed') . '</span>',
                                    ];
                                    echo $statusBadges[$v['status']] ?? '<span class="badge bg-secondary">' . htmlspecialchars($v['status']) . '</span>';
                                    ?>
                                </td>
                                <td><?php echo htmlspecialchars($v['created_by_name']); ?></td>
                                <td class="text-center">
                                    <a href="requisition_voucher_view.php?id=<?php echo $v['id']; ?>" class="btn btn-sm btn-outline-primary" title="<?php _e('view'); ?>">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <?php if (requisitionCanEdit($v, $conn, $v['status'] === 'approved' ? ($rvEditBlockers[$v['id']] ?? []) : null)): ?>
                                    <a href="requisition_voucher.php?id=<?php echo $v['id']; ?>" class="btn btn-sm btn-outline-warning" title="<?php _e('edit'); ?>">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <?php endif; ?>
                                    <a href="requisition_voucher_dn.php?id=<?php echo $v['id']; ?>" class="btn btn-sm btn-outline-secondary" target="_blank" title="<?php _e('print_delivery_note'); ?>">
                                        <i class="bi bi-printer"></i>
                                    </a>
                                    <?php if ($v['status'] === 'draft' && hasPermission('requisition_vouchers', 'delete')): ?>
                                    <button onclick="confirmDelete(<?php echo $v['id']; ?>, '<?php echo htmlspecialchars($v['voucher_number']); ?>')" class="btn btn-sm btn-outline-danger" title="<?php _e('delete'); ?>">
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
                <p><?php _e('confirm_delete_voucher'); ?></p>
                <p class="text-danger small"><?php _e('confirm_delete_voucher_warning'); ?></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> <?php _e('cancel'); ?>
                </button>
                <form id="deleteForm" method="POST" class="d-inline">
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

    // Product filter. jQuery + Select2 are loaded by includes/footer.php, which
    // comes AFTER this inline block, so initialise on DOMContentLoaded (by then
    // those blocking scripts have run) and guard in case the CDN is unreachable
    // — without the guard the whole filter form would break, with it the field
    // degrades to a plain multi-select.
    if (window.jQuery && $.fn.select2) {
        const $products = $('#rvFilterProducts');

        // Searched server-side rather than dumping ~3,900 <option>s into the page.
        $products.select2({
            width: '100%',
            placeholder: <?php echo json_encode(__('rv_product_filter_placeholder')); ?>,
            allowClear: true,
            minimumInputLength: 0,
            ajax: {
                url: BASE_URL + 'api/products-search.php',
                dataType: 'json',
                delay: 250,
                data: params => ({ q: params.term || '', page: params.page || 1 }),
                processResults: (data, params) => {
                    params.page = params.page || 1;
                    return {
                        results: data.results || [],
                        pagination: data.pagination || { more: false }
                    };
                },
                cache: true
            }
        });

        // Keep the URL clean: an untouched multi-select would still submit
        // products[]= and leave a stray empty param on every filter.
        $('#rvFilterForm').on('submit', function () {
            const picks = $products.val();
            if (!picks || picks.length === 0) {
                $products.prop('disabled', true);
            }
        });
    }
});

function confirmDelete(id, number) {
    document.getElementById('deleteId').value = id;
    deleteModal.show();
}
</script>

<?php require_once '../../includes/footer.php'; ?>
