<?php
/**
 * Purchase Order list — filterable by supplier, location, status and date.
 * POs are commitment documents; stock/GL are only touched by the Purchases
 * raised from them.
 */
require_once '../../config/database.php';
require_once '../../includes/functions.php';
$pageTitle = __('purchase_order_list');
if (!hasPermission('purchase_orders', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('');
}

// Handle delete action (draft only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    checkCSRF();
    $deleteId = (int)$_POST['id'];
    $stmt = $conn->prepare("SELECT id, po_number, status FROM purchase_orders WHERE id = ?");
    $stmt->execute([$deleteId]);
    $order = $stmt->fetch();

    if ($order && $order['status'] === 'draft') {
        if (hasPermission('purchase_orders', 'delete')) {
            $conn->prepare("DELETE FROM purchase_orders WHERE id = ? AND status = 'draft'")->execute([$deleteId]);
            logAudit('purchase_order', 'delete', $deleteId);
            setAlert(__('purchase_order_deleted'), 'success');
        } else {
            setAlert(__('no_permission'), 'danger');
        }
    } else {
        setAlert(__('po_only_draft_delete'), 'danger');
    }
    redirect('modules/transactions/purchase_order_list.php');
    exit;
}

require_once '../../includes/header.php';

// Filters
$supplierFilter = $_GET['supplier'] ?? '';
$locationFilter = $_GET['location'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

$locations = getUserLocations();

// Active suppliers for the filter dropdown.
$suppliers = $conn->query("
    SELECT s.id, la.name AS name
    FROM suppliers s
    LEFT JOIN ledger_accounts la ON s.ledger_account_id = la.id
    WHERE s.is_active = 1
    ORDER BY la.name
")->fetchAll(PDO::FETCH_ASSOC);

// Build query
$whereClause = "WHERE po.po_date BETWEEN ? AND ?";
$params = [$dateFrom, $dateTo];

if ($supplierFilter) {
    $whereClause .= " AND po.supplier_id = ?";
    $params[] = $supplierFilter;
}

if ($locationFilter) {
    $whereClause .= " AND po.location_id = ?";
    $params[] = $locationFilter;
} else {
    $locationIds = array_column($locations, 'id');
    if (!empty($locationIds)) {
        $placeholders = implode(',', array_fill(0, count($locationIds), '?'));
        $whereClause .= " AND po.location_id IN ($placeholders)";
        $params = array_merge($params, $locationIds);
    }
}

if ($statusFilter) {
    $whereClause .= " AND po.status = ?";
    $params[] = $statusFilter;
}

// Base currency, for flagging + converting the foreign-currency rows below.
$poBaseCurrency   = getDefaultCurrency();
$poBaseCurrencyId = $poBaseCurrency ? (int)$poBaseCurrency['id'] : null;

$stmt = $conn->prepare("
    SELECT
        po.*,
        la.name as supplier_name,
        l.name as location_name,
        u.name as created_by_name,
        b.budget_number,
        (SELECT COUNT(*) FROM purchase_order_details WHERE purchase_order_id = po.id) as item_count
    FROM purchase_orders po
    LEFT JOIN budgets b ON po.budget_id = b.id
    LEFT JOIN suppliers s ON po.supplier_id = s.id
    LEFT JOIN ledger_accounts la ON s.ledger_account_id = la.id
    LEFT JOIN locations l ON po.location_id = l.id
    LEFT JOIN users u ON po.created_by = u.id
    $whereClause
    ORDER BY po.id DESC
");
$stmt->execute($params);
$orders = $stmt->fetchAll();

// Edit gate for the whole page in 2 queries (poEditBlockReason() decides).
$poCanEditRight = hasPermission('purchase_orders', 'edit');
$poPurchasedMap = $poCanEditRight ? poPurchasedIds($conn, array_column($orders, 'id')) : [];
?>

<div class="row justify-content-center">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="bi bi-file-earmark-text"></i> <?php _e('purchase_order_list'); ?>
                </h5>
                <div>
                    <?php if (hasPermission('purchase_orders', 'create')): ?>
                    <a href="purchase_order.php" class="btn btn-sm btn-primary">
                        <i class="bi bi-plus"></i> <?php _e('new_purchase_order'); ?>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <!-- Filters -->
                <form method="GET" class="row g-3 mb-4">
                    <div class="col-md-2">
                        <label class="form-label"><?php _e('supplier'); ?></label>
                        <select name="supplier" class="form-select form-select-sm">
                            <option value=""><?php _e('all_suppliers', 'All Suppliers'); ?></option>
                            <?php foreach ($suppliers as $s): ?>
                            <option value="<?php echo (int)$s['id']; ?>" <?php echo ((string)$supplierFilter === (string)$s['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($s['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><?php _e('location'); ?></label>
                        <select name="location" class="form-select form-select-sm">
                            <option value=""><?php _e('all_locations'); ?></option>
                            <?= renderTreeOptions($locations, $locationFilter) ?>
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
                            <option value="cancelled" <?php echo $statusFilter === 'cancelled' ? 'selected' : ''; ?>><?php _e('status_cancelled'); ?></option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><?php _e('from_date'); ?></label>
                        <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($dateFrom); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><?php _e('to_date'); ?></label>
                        <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($dateTo); ?>">
                    </div>
                    <div class="col-md-2 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-sm btn-primary"><?php _e('filter'); ?></button>
                        <a href="purchase_order_list.php" class="btn btn-sm btn-secondary"><?php _e('reset'); ?></a>
                    </div>
                </form>

                <?php if (empty($orders)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-file-earmark-text" style="font-size: 3rem; color: #ccc;"></i>
                    <p class="text-muted mt-3"><?php _e('no_purchase_orders_found'); ?></p>
                    <?php if (hasPermission('purchase_orders', 'create')): ?>
                    <a href="purchase_order.php" class="btn btn-primary">
                        <i class="bi bi-plus"></i> <?php _e('new_purchase_order'); ?>
                    </a>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <p class="text-muted small mb-2"><?php echo sprintf(__('showing_vouchers'), count($orders)); ?></p>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover table-sm">
                        <thead class="table-light">
                            <tr>
                                <th><?php _e('po_number'); ?></th>
                                <th><?php _e('date'); ?></th>
                                <th><?php _e('supplier'); ?></th>
                                <th><?php _e('location'); ?></th>
                                <th class="text-center"><?php _e('items'); ?></th>
                                <th class="text-end"><?php _e('bill_amount'); ?></th>
                                <th class="text-center"><?php _e('status'); ?></th>
                                <th><?php _e('created_by'); ?></th>
                                <th class="text-center"><?php _e('actions'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $o): ?>
                            <tr>
                                <td>
                                    <a href="purchase_order_view.php?id=<?php echo $o['id']; ?>">
                                        <strong><?php echo htmlspecialchars($o['po_number']); ?></strong>
                                    </a>
                                    <?php if (!empty($o['budget_id'])): ?>
                                    <span class="badge bg-info text-dark" title="<?php _e('created_from_budget'); ?>"><i class="bi bi-cash-coin"></i></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo formatDate($o['po_date']); ?></td>
                                <td><?php echo htmlspecialchars($o['supplier_name'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($o['location_name'] ?? '-'); ?></td>
                                <td class="text-center">
                                    <span class="badge bg-secondary"><?php echo $o['item_count']; ?></span>
                                </td>
                                <?php
                                // The PO's OWN currency. This column used to print a bare
                                // formatNumber() with no symbol at all, so a USD PO was
                                // indistinguishable from a TZS one — while
                                // purchase_order_view.php has always shown the currency
                                // (it joins `currencies`). getCurrency() caches per id, so
                                // the working query above needs no change.
                                $poCurrency = getCurrency($o['currency_id']);
                                $poAmount   = (float)$o['total_amount'] + (float)$o['vat_amount'];
                                $poRate     = (float)($o['exchange_rate_to_base'] ?: 1);
                                $poIsForeign = $poCurrency && $poBaseCurrencyId !== null
                                               && (int)$poCurrency['id'] !== $poBaseCurrencyId;

                                // Rate + base equivalent go in a tooltip, matching the
                                // payments/receipts lists.
                                $poTitle = $poIsForeign
                                    ? $poCurrency['code'] . ' @ ' . formatNumber($poRate, 2) . ' = '
                                      . formatCurrency($poAmount * $poRate, 2, $poBaseCurrency)
                                    : '';
                                ?>
                                <td class="text-end"<?php echo $poIsForeign ? ' title="' . htmlspecialchars($poTitle, ENT_QUOTES) . '"' : ''; ?>>
                                    <?php echo formatCurrency($poAmount, 2, $poCurrency); ?>
                                </td>
                                <td class="text-center">
                                    <?php
                                    $statusBadges = [
                                        'draft' => '<span class="badge bg-secondary">' . __('status_draft') . '</span>',
                                        'pending_approval' => '<span class="badge bg-warning text-dark">' . __('status_pending_approval') . '</span>',
                                        'approved' => '<span class="badge bg-success">' . __('status_approved') . '</span>',
                                        'rejected' => '<span class="badge bg-danger">' . __('status_rejected') . '</span>',
                                        'closed' => '<span class="badge bg-dark">' . __('status_closed') . '</span>',
                                        'cancelled' => '<span class="badge bg-light text-dark border">' . __('status_cancelled') . '</span>',
                                    ];
                                    echo $statusBadges[$o['status']] ?? '<span class="badge bg-secondary">' . htmlspecialchars($o['status']) . '</span>';
                                    ?>
                                </td>
                                <td><?php echo htmlspecialchars($o['created_by_name'] ?? '-'); ?></td>
                                <td class="text-center">
                                    <a href="purchase_order_view.php?id=<?php echo $o['id']; ?>" class="btn btn-sm btn-outline-primary" title="<?php _e('view'); ?>">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <?php if ($poCanEditRight):
                                        $poLockReason = poEditBlockReason($o, !empty($poPurchasedMap[(int)$o['id']]));
                                    ?>
                                    <?php if ($poLockReason === null): ?>
                                    <a href="purchase_order.php?id=<?php echo $o['id']; ?>" class="btn btn-sm btn-outline-warning" title="<?php _e('edit'); ?>">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <?php else: ?>
                                    <button type="button" class="btn btn-sm btn-outline-secondary po-edit-locked" style="opacity: .45;"
                                            title="<?php echo htmlspecialchars($poLockReason); ?>"
                                            data-reason="<?php echo htmlspecialchars($poLockReason); ?>"
                                            onclick="alert(this.dataset.reason)">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if ($o['status'] === 'draft' && hasPermission('purchase_orders', 'delete')): ?>
                                    <button onclick="confirmDelete(<?php echo $o['id']; ?>)" class="btn btn-sm btn-outline-danger" title="<?php _e('delete'); ?>">
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
                <p><?php _e('confirm_delete_po'); ?></p>
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
