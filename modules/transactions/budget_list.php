<?php
require_once '../../config/database.php';
require_once '../../includes/functions.php';
$pageTitle = __('budget_list');

if (!hasPermission('budget', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('');
}

// Delete (draft only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    checkCSRF();
    $deleteId = (int)$_POST['id'];
    $stmt = $conn->prepare("SELECT status FROM budgets WHERE id = ?");
    $stmt->execute([$deleteId]);
    $st = $stmt->fetchColumn();
    if ($st !== false) {
        if (!hasPermission('budget', 'delete')) {
            setAlert(__('no_permission'), 'danger');
        } elseif ($st !== 'draft') {
            setAlert(__('budget_only_draft_delete'), 'warning');
        } else {
            $conn->prepare("DELETE FROM budgets WHERE id = ?")->execute([$deleteId]);
            logAudit('budget', 'delete', $deleteId);
            setAlert(__('budget_deleted'), 'success');
        }
    }
    redirect('modules/transactions/budget_list.php');
    exit;
}

require_once '../../includes/header.php';

$statusFilter = $_GET['status'] ?? '';
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

$where = "WHERE b.budget_date BETWEEN ? AND ?";
$params = [$dateFrom, $dateTo];

// Restrict to budgets in the user's accessible locations.
$locationIds = array_column(getUserLocations(), 'id');
if (!empty($locationIds)) {
    $ph = implode(',', array_fill(0, count($locationIds), '?'));
    $where .= " AND b.location_id IN ($ph)";
    $params = array_merge($params, $locationIds);
}
if ($statusFilter !== '') {
    $where .= " AND b.status = ?";
    $params[] = $statusFilter;
}

$stmt = $conn->prepare("
    SELECT b.*,
        rv.voucher_number,
        l.name AS location_name,
        u.name AS created_by_name,
        (SELECT COUNT(*) FROM budget_details WHERE budget_id = b.id) AS item_count,
        -- VAT-inclusive, so this agrees with the Budget Total on the view page.
        -- vat_amount defaults to 0.00 (migration 0044), so every budget created
        -- before per-line VAT still shows exactly the figure it always did.
        (SELECT COALESCE(SUM(quantity * rate + vat_amount), 0) FROM budget_details WHERE budget_id = b.id) AS total_value,
        (
            (SELECT COUNT(*) FROM purchases WHERE budget_id = b.id)
          + (SELECT COUNT(*) FROM purchase_imports WHERE budget_id = b.id)
        ) AS purchase_count
    FROM budgets b
    LEFT JOIN requisition_vouchers rv ON b.requisition_id = rv.id
    LEFT JOIN locations l ON b.location_id = l.id
    LEFT JOIN users u ON b.created_by = u.id
    $where
    ORDER BY b.id DESC
");
$stmt->execute($params);
$budgets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Purchase status applies to approved budgets only: nothing can be purchased
// against any other status. The filter runs after the batch lookup because the
// state is computed live, not stored on the budget.
$approvedIds = [];
foreach ($budgets as $b) {
    if ($b['status'] === 'approved') {
        $approvedIds[] = (int)$b['id'];
    }
}
$purchaseStates = getBudgetPurchaseStates($approvedIds, $conn);

$purchaseStateFilter = $_GET['purchase_state'] ?? '';
if (in_array($purchaseStateFilter, ['open', 'closed'], true)) {
    $budgets = array_values(array_filter($budgets, function ($b) use ($purchaseStates, $purchaseStateFilter) {
        return ($purchaseStates[(int)$b['id']] ?? null) === $purchaseStateFilter;
    }));
} else {
    $purchaseStateFilter = '';
}

$statusBadge = function ($s) {
    switch ($s) {
        case 'draft': return 'secondary';
        case 'pending_approval': return 'warning';
        case 'approved': return 'success';
        case 'rejected': return 'danger';
        default: return 'secondary';
    }
};
?>

<div class="row justify-content-center">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-cash-coin"></i> <?php _e('budget_list'); ?></h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3 mb-4">
                    <div class="col-md-3">
                        <label class="form-label"><?php _e('status'); ?></label>
                        <select name="status" class="form-select form-select-sm">
                            <option value=""><?php _e('all'); ?></option>
                            <?php foreach (['draft','pending_approval','approved','rejected'] as $s): ?>
                            <option value="<?php echo $s; ?>" <?php echo $statusFilter === $s ? 'selected' : ''; ?>><?php _e('status_' . $s); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><?php _e('budget_purchase_status'); ?></label>
                        <select name="purchase_state" class="form-select form-select-sm">
                            <option value=""><?php _e('all'); ?></option>
                            <option value="open" <?php echo $purchaseStateFilter === 'open' ? 'selected' : ''; ?>><?php _e('budget_purchase_open'); ?></option>
                            <option value="closed" <?php echo $purchaseStateFilter === 'closed' ? 'selected' : ''; ?>><?php _e('budget_purchase_closed'); ?></option>
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
                    <div class="col-md-3 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-sm btn-primary"><?php _e('filter'); ?></button>
                        <a href="budget_list.php" class="btn btn-sm btn-secondary"><?php _e('reset'); ?></a>
                    </div>
                </form>

                <?php if (empty($budgets)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-cash-coin" style="font-size: 3rem; color: #ccc;"></i>
                    <p class="text-muted mt-3"><?php _e('no_budgets_found'); ?></p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover table-sm">
                        <thead class="table-light">
                            <tr>
                                <th><?php _e('budget_number'); ?></th>
                                <th><?php _e('date'); ?></th>
                                <th><?php _e('requisition_voucher'); ?></th>
                                <th><?php _e('location'); ?></th>
                                <th class="text-center"><?php _e('items'); ?></th>
                                <th class="text-end"><?php _e('total_value'); ?></th>
                                <th><?php _e('status'); ?></th>
                                <th class="text-nowrap">
                                    <?php _e('budget_purchase_status'); ?>
                                    <button type="button" class="btn btn-link btn-sm p-0 text-reset align-baseline"
                                            data-bs-toggle="modal" data-bs-target="#purchaseStatusRulesModal"
                                            title="<?php _e('budget_purchase_rules_title'); ?>" aria-label="<?php _e('budget_purchase_rules_title'); ?>">
                                        <i class="bi bi-info-circle"></i>
                                    </button>
                                </th>
                                <th><?php _e('created_by'); ?></th>
                                <th class="text-center"><?php _e('actions'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($budgets as $b): ?>
                            <tr>
                                <td><a href="budget_view.php?id=<?php echo $b['id']; ?>"><strong><?php echo htmlspecialchars($b['budget_number']); ?></strong></a></td>
                                <td><?php echo formatDate($b['budget_date']); ?></td>
                                <td>
                                    <?php if ($b['voucher_number']): ?>
                                    <a href="requisition_voucher_view.php?id=<?php echo (int)$b['requisition_id']; ?>"><?php echo htmlspecialchars($b['voucher_number']); ?></a>
                                    <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($b['location_name']); ?></td>
                                <td class="text-center"><span class="badge bg-secondary"><?php echo $b['item_count']; ?></span></td>
                                <td class="text-end"><?php echo formatNumber($b['total_value'], 2); ?></td>
                                <td><span class="badge bg-<?php echo $statusBadge($b['status']); ?>"><?php _e('status_' . $b['status']); ?></span></td>
                                <td>
                                    <?php $purchaseState = $purchaseStates[(int)$b['id']] ?? null; ?>
                                    <?php if ($purchaseState === 'closed'): ?>
                                    <span class="badge bg-success"><?php _e('budget_purchase_closed'); ?></span>
                                    <?php elseif ($purchaseState === 'open'): ?>
                                    <span class="badge bg-primary"><?php _e('budget_purchase_open'); ?></span>
                                    <?php else: ?>
                                    <span class="text-muted">&ndash;</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($b['created_by_name'] ?? '-'); ?></td>
                                <td class="text-center">
                                    <a href="budget_view.php?id=<?php echo $b['id']; ?>" class="btn btn-sm btn-outline-primary" title="<?php _e('view'); ?>"><i class="bi bi-eye"></i></a>
                                    <?php
                                    // Same gate the form and the view page use, so all three
                                    // agree about who sees a pencil. It admits the budget's own
                                    // creator while the budget is still pre-approval, and for an
                                    // approved budget it consults budgetHasLinkedPurchases(),
                                    // which ALSO counts open purchase orders — the purchase_count
                                    // sub-select on this page's query does not, so the old check
                                    // could show a pencil the form would then refuse.
                                    $budgetEditable = budgetCanEdit($b, $conn);
                                    ?>
                                    <?php if ($budgetEditable): ?>
                                    <a href="budget.php?id=<?php echo $b['id']; ?>" class="btn btn-sm btn-outline-warning" title="<?php _e('edit'); ?>"><i class="bi bi-pencil"></i></a>
                                    <?php endif; ?>
                                    <?php if ($b['status'] === 'draft' && hasPermission('budget', 'delete')): ?>
                                    <button onclick="confirmDelete(<?php echo $b['id']; ?>)" class="btn btn-sm btn-outline-danger" title="<?php _e('delete'); ?>"><i class="bi bi-trash"></i></button>
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

<div class="modal fade" id="purchaseStatusRulesModal" tabindex="-1" aria-labelledby="purchaseStatusRulesTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="purchaseStatusRulesTitle"><i class="bi bi-info-circle"></i> <?php _e('budget_purchase_rules_title'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php _e('close'); ?>"></button>
            </div>
            <div class="modal-body">
                <ul class="mb-0">
                    <li class="mb-2"><span class="badge bg-success"><?php _e('budget_purchase_closed'); ?></span> <?php _e('budget_purchase_rule_closed'); ?></li>
                    <li class="mb-2"><span class="badge bg-primary"><?php _e('budget_purchase_open'); ?></span> <?php _e('budget_purchase_rule_open'); ?></li>
                    <li class="mb-2"><?php _e('budget_purchase_rule_automatic'); ?></li>
                    <li class="mb-2"><?php _e('budget_purchase_rule_matching'); ?></li>
                    <li class="mb-2"><?php _e('budget_purchase_rule_po'); ?></li>
                    <li class="mb-2"><?php _e('budget_purchase_rule_not_approved'); ?></li>
                    <li><?php _e('budget_purchase_rule_no_manual_close'); ?></li>
                </ul>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php _e('close'); ?></button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-trash"></i> <?php _e('confirm_delete'); ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body"><p><?php _e('confirm_delete_budget'); ?></p></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                <form id="deleteForm" method="POST" class="d-inline">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" id="deleteId">
                    <button type="submit" class="btn btn-danger"><i class="bi bi-trash"></i> <?php _e('delete'); ?></button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
let deleteModal;
document.addEventListener('DOMContentLoaded', function () {
    deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
});
function confirmDelete(id) {
    document.getElementById('deleteId').value = id;
    deleteModal.show();
}
</script>

<?php require_once '../../includes/footer.php'; ?>
