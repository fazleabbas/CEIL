<?php
require_once '../../config/database.php';
require_once '../../includes/functions.php';
$pageTitle = __('consumption_voucher_details');
if (!hasPermission('consumption_vouchers', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('');
}

// Handle Delete
if (isset($_GET['delete']) && hasPermission('consumption_vouchers', 'delete')) {
    $deleteId = (int)$_GET['delete'];

    try {
        $conn->beginTransaction();

        // Get voucher info
        $stmt = $conn->prepare("SELECT voucher_number, location_id FROM consumption_vouchers WHERE id = ?");
        $stmt->execute([$deleteId]);
        $voucher = $stmt->fetch();

        if ($voucher) {
            // A voucher that has been returned against must not be deleted:
            // cvRestoreStock() would put the FULL issued quantity back on top
            // of what the return already gave back, leaving more in stock than
            // ever went out. The list and the view hide the button, so this is
            // the guard against a hand-typed ?delete= (migration 0047).
            if (cvHasReturns($conn, $deleteId)) {
                $conn->rollBack();
                setAlert(__('cv_locked_by_return'), 'danger');
                redirect('modules/transactions/consumption_voucher_view.php?id=' . $deleteId);
            }

            // Reverse stock. For vouchers that recorded the batches they drew
            // from (migration 0040) this restores those exact batches; older
            // vouchers fall back to the original add-a-new-batch behaviour.
            // A RETURN holds negative batch links, so the same call correctly
            // subtracts the stock the return had given back.
            cvRestoreStock($conn, $deleteId, $voucher['location_id']);

            // Delete details
            $stmt = $conn->prepare("DELETE FROM consumption_voucher_details WHERE voucher_id = ?");
            $stmt->execute([$deleteId]);

            // Delete header
            $stmt = $conn->prepare("DELETE FROM consumption_vouchers WHERE id = ?");
            $stmt->execute([$deleteId]);

            $conn->commit();

            logAudit('consumption_voucher', 'delete', $deleteId);
            setAlert(sprintf(__('consumption_voucher_deleted'), $voucher['voucher_number']), 'success');
            redirect('modules/transactions/consumption_voucher_list.php');
        } else {
            setAlert(__('consumption_voucher_not_found'), 'danger');
            redirect('modules/transactions/consumption_voucher_list.php');
        }
    } catch (PDOException $e) {
        $conn->rollBack();
        setAlert(sprintf(__('error_deleting_consumption_voucher'), $e->getMessage()), 'danger');
        redirect('modules/transactions/consumption_voucher_list.php');
    }
}

$id = $_GET['id'] ?? 0;

// Get voucher header
$stmt = $conn->prepare("
    SELECT
        cv.*,
        l.name as location_name,
        cc.name as cost_centre_name,
        cc.code as cost_centre_code,
        u.name as created_by_name
    FROM consumption_vouchers cv
    LEFT JOIN locations l ON cv.location_id = l.id
    LEFT JOIN cost_centres cc ON cv.cost_centre_id = cc.id
    LEFT JOIN users u ON cv.created_by = u.id
    WHERE cv.id = ?
");
$stmt->execute([$id]);
$voucher = $stmt->fetch();

if (!$voucher) {
    setAlert(__('consumption_voucher_not_found'), 'danger');
    redirect('modules/transactions/consumption_voucher_list.php');
}

// Get voucher details
$detailsStmt = $conn->prepare("
    SELECT
        cvd.*,
        p.name as product_name,
        p.unit,
        pc.name as category_name
    FROM consumption_voucher_details cvd
    JOIN products p ON cvd.product_id = p.id
    LEFT JOIN product_categories pc ON p.category_id = pc.id
    WHERE cvd.voucher_id = ?
    ORDER BY pc.name, p.name
");
$detailsStmt->execute([$id]);
$details = $detailsStmt->fetchAll();

require_once '../../includes/header.php';

$totalItems = count($details);

// Preparer's e-signature, if the creator has one saved on their user record.
// getUserSignature() returns null for id 0/NULL and for pre-0041 tenants where
// users.signature does not exist, so the footer simply falls back to a blank
// ruled line.
$cvPreparedSig = getUserSignature($conn, (int)($voucher['created_by'] ?? 0));

// Project Manager / Engineer who authorised the consumption (migration 0046).
// Guarded with ?? so a tenant that has not run the migration simply renders no
// sign-off box rather than a warning.
// Return state (migration 0047). A voucher that has been returned against is
// locked for edit AND delete; a voucher that IS a return is locked for edit but
// stays deletable, because deleting and raising another is how one is corrected.
$cvIsReturn   = !empty($voucher['return_of_voucher_id']);
$cvHasReturns = cvHasReturns($conn, $id);
$cvCanEdit    = hasPermission('consumption_vouchers', 'edit') && cvCanEdit($conn, $voucher, $cvHasReturns);
$cvCanDelete  = hasPermission('consumption_vouchers', 'delete') && cvCanDelete($conn, $voucher, $cvHasReturns);

// Returns raised against this voucher, and the original when this IS a return.
$cvReturns = [];
$cvParent  = null;
if (cvReturnsAvailable($conn)) {
    try {
        if ($cvHasReturns) {
            $rs = $conn->prepare("
                SELECT id, voucher_number, voucher_date, total_amount
                FROM consumption_vouchers
                WHERE return_of_voucher_id = ?
                ORDER BY voucher_date, id
            ");
            $rs->execute([$id]);
            $cvReturns = $rs->fetchAll();
        }
        if ($cvIsReturn) {
            $ps = $conn->prepare("SELECT id, voucher_number FROM consumption_vouchers WHERE id = ?");
            $ps->execute([(int)$voucher['return_of_voucher_id']]);
            $cvParent = $ps->fetch();
        }
    } catch (Throwable $e) {
        // Panels simply do not render.
    }
}

// How much of this voucher is still returnable, so the button only shows when
// there is something left to return.
$cvReturnable = 0;
if (!$cvIsReturn && cvReturnsAvailable($conn)) {
    $returnedMap = cvReturnedQuantities($conn, $id);
    foreach ($details as $d) {
        $already = isset($returnedMap[(int)$d['id']]) ? (float)$returnedMap[(int)$d['id']] : 0.0;
        $cvReturnable += max(0, (float)$d['quantity'] - $already);
    }
}

$cvPmName = trim((string)($voucher['project_manager_name'] ?? ''));
$cvPmSig  = (string)($voucher['project_manager_signature'] ?? '');
if (!preg_match('#^data:image/(png|jpeg|jpg);base64,[A-Za-z0-9+/=]+$#', $cvPmSig)) {
    $cvPmSig = '';
}
?>

<div class="row justify-content-center">
    <div class="col-md-12 col-lg-10">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center no-print">
                <h5 class="mb-0">
                    <i class="bi bi-clipboard-minus"></i> <?php _e('consumption_voucher_details'); ?>
                </h5>
                <div>
                    <button onclick="window.print()" class="btn btn-sm btn-secondary">
                        <i class="bi bi-printer"></i> <?php _e('print'); ?>
                    </button>
                    <?php if ($cvCanEdit): ?>
                    <a href="consumption_voucher.php?id=<?php echo $id; ?>" class="btn btn-sm btn-warning">
                        <i class="bi bi-pencil"></i> <?php _e('edit'); ?>
                    </a>
                    <?php endif; ?>
                    <?php if (!$cvIsReturn && $cvReturnable > 0 && hasPermission('consumption_vouchers', 'create')): ?>
                    <a href="consumption_voucher_return.php?from=<?php echo $id; ?>" class="btn btn-sm btn-outline-warning">
                        <i class="bi bi-arrow-return-left"></i> <?php _e('create_return'); ?>
                    </a>
                    <?php endif; ?>
                    <?php if ($cvCanDelete): ?>
                    <button onclick="confirmDelete(<?php echo $id; ?>)" class="btn btn-sm btn-danger">
                        <i class="bi bi-trash"></i> <?php _e('delete'); ?>
                    </button>
                    <?php endif; ?>
                    <a href="consumption_voucher.php" class="btn btn-sm btn-primary">
                        <i class="bi bi-plus"></i> <?php _e('new'); ?>
                    </a>
                    <a href="consumption_voucher_list.php" class="btn btn-sm btn-secondary">
                        <i class="bi bi-list"></i> <?php _e('list'); ?>
                    </a>
                </div>
            </div>
            <div class="card-body">

                <?php if ($cvIsReturn): ?>
                <!-- This voucher IS a return. no-print: the printed voucher is
                     unchanged, these notices are screen navigation only. -->
                <div class="alert alert-warning no-print">
                    <i class="bi bi-arrow-return-left"></i>
                    <?php echo sprintf(
                        __('cv_is_a_return'),
                        $cvParent
                            ? '<a href="consumption_voucher_view.php?id=' . (int)$cvParent['id'] . '">'
                              . htmlspecialchars($cvParent['voucher_number']) . '</a>'
                            : '-'
                    ); ?>
                </div>
                <?php endif; ?>

                <?php if ($cvHasReturns): ?>
                <div class="alert alert-secondary no-print">
                    <i class="bi bi-lock"></i>
                    <strong><?php _e('cv_locked_by_return'); ?></strong>
                    <div class="mt-2">
                        <?php foreach ($cvReturns as $r): ?>
                        <a href="consumption_voucher_view.php?id=<?php echo (int)$r['id']; ?>"
                           class="badge bg-warning text-dark text-decoration-none me-1">
                            <?php echo htmlspecialchars($r['voucher_number']); ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="card border mb-4 no-print">
                    <div class="card-header py-2">
                        <strong><i class="bi bi-arrow-return-left"></i> <?php _e('cv_returns_panel'); ?></strong>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th><?php _e('voucher_number_label'); ?></th>
                                    <th><?php _e('date_label'); ?></th>
                                    <th class="text-end"><?php _e('amount'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cvReturns as $r): ?>
                                <tr>
                                    <td>
                                        <a href="consumption_voucher_view.php?id=<?php echo (int)$r['id']; ?>">
                                            <?php echo htmlspecialchars($r['voucher_number']); ?>
                                        </a>
                                    </td>
                                    <td><?php echo formatDate($r['voucher_date']); ?></td>
                                    <td class="text-end text-success">
                                        <?php /* Bare call, matching every other amount on this page.
                                                 NOTE this page renders the tenant default currency and
                                                 ignores the voucher currency_id - pre-existing, same class
                                                 as the list fixes, NOT introduced here. */ ?>
                                        <?php echo formatCurrency($r['total_amount']); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Header Info -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <h4 class="text-primary mb-3"><?php echo APP_NAME; ?></h4>
                        <table class="table table-sm table-borderless">
                            <tr>
                                <td width="40%"><strong><?php _e('voucher_number_label'); ?></strong></td>
                                <td><?php echo htmlspecialchars($voucher['voucher_number']); ?></td>
                            </tr>
                            <tr>
                                <td><strong><?php _e('date_label'); ?></strong></td>
                                <td><?php echo formatDate($voucher['voucher_date']); ?></td>
                            </tr>
                            <tr>
                                <td><strong><?php _e('location_label'); ?></strong></td>
                                <td><?php echo htmlspecialchars($voucher['location_name']); ?></td>
                            </tr>
                            <?php if ($voucher['cost_centre_name']): ?>
                            <tr>
                                <td><strong><?php _e('cost_centre_label'); ?></strong></td>
                                <td><?php echo htmlspecialchars($voucher['cost_centre_code'] . ' - ' . $voucher['cost_centre_name']); ?></td>
                            </tr>
                            <?php endif; ?>
                        </table>
                    </div>
                    <div class="col-md-6 text-end">
                        <table class="table table-sm table-borderless">
                            <tr>
                                <td width="40%"><strong><?php _e('created_by_label'); ?></strong></td>
                                <td><?php echo htmlspecialchars($voucher['created_by_name']); ?></td>
                            </tr>
                            <tr>
                                <td><strong><?php _e('created_at_label'); ?></strong></td>
                                <td><?php echo formatDateTime($voucher['created_at']); ?></td>
                            </tr>
                            <?php if ($voucher['remarks']): ?>
                            <tr>
                                <td><strong><?php _e('remarks_label'); ?></strong></td>
                                <td><?php echo htmlspecialchars($voucher['remarks']); ?></td>
                            </tr>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>

                <hr>

                <!-- Summary Cards -->
                <div class="row mb-4 print-summary">
                    <div class="col-md-4 col-6">
                        <div class="card bg-light">
                            <div class="card-body text-center py-2">
                                <small class="text-muted"><?php _e('consumed_items'); ?></small>
                                <h4 class="mb-0 text-danger"><?php echo $totalItems; ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 col-6">
                        <div class="card bg-light">
                            <div class="card-body text-center py-2">
                                <small class="text-muted"><?php _e('total_consumption'); ?></small>
                                <h4 class="mb-0 text-danger"><?php echo formatCurrency($voucher['total_amount']); ?></h4>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Details Table -->
                <h6 class="text-muted mb-3"><?php _e('consumption_items'); ?></h6>
                <div class="table-responsive">
                    <table class="table table-bordered table-sm">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 5%;"><?php _e('sr'); ?></th>
                                <th style="width: 30%;"><?php _e('product'); ?></th>
                                <th style="width: 12%;"><?php _e('category'); ?></th>
                                <th style="width: 8%;"><?php _e('unit'); ?></th>
                                <th style="width: 12%;" class="text-end"><?php _e('quantity'); ?></th>
                                <th style="width: 12%;" class="text-end"><?php _e('rate'); ?></th>
                                <th style="width: 15%;" class="text-end"><?php _e('amount'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $rowNum = 0;
                            $currentCategory = '';
                            foreach ($details as $detail):
                                $rowNum++;

                                if ($currentCategory != $detail['category_name']):
                                    $currentCategory = $detail['category_name'];
                            ?>
                            <tr class="table-secondary">
                                <td colspan="7">
                                    <strong><i class="bi bi-tag"></i> <?php echo htmlspecialchars($currentCategory ?: __('uncategorized')); ?></strong>
                                </td>
                            </tr>
                            <?php endif; ?>

                            <tr class="table-danger" style="--bs-table-bg-opacity: 0.3;">
                                <td><?php echo $rowNum; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($detail['product_name']); ?></strong>
                                    <?php if ($detail['remarks']): ?>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($detail['remarks']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($detail['category_name'] ?: '-'); ?></td>
                                <td><?php echo htmlspecialchars($detail['unit']); ?></td>
                                <td class="text-end"><?php echo formatNumber($detail['quantity'], 3); ?></td>
                                <td class="text-end"><?php echo formatCurrency($detail['rate_per_unit']); ?></td>
                                <td class="text-end">
                                    <strong class="text-danger"><?php echo formatCurrency($detail['amount']); ?></strong>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="6" class="text-end"><?php _e('total_consumed'); ?>:</th>
                                <th class="text-end">
                                    <strong class="text-danger"><?php echo formatCurrency($voucher['total_amount']); ?></strong>
                                </th>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Footer. The preparer's saved signature (Profile -> My
                     Signature, or Masters -> Users) prints above the line when
                     they have one on file; otherwise the blank ruled line is
                     rendered exactly as before. -->
                <div class="row mt-5 pt-4 border-top">
                    <div class="col-6 text-center">
                        <?php if ($cvPreparedSig): ?>
                        <div style="min-width: 150px; display: inline-block;">
                            <img src="<?php echo htmlspecialchars($cvPreparedSig); ?>" alt="<?php _e('signature'); ?>" style="max-height: 70px; max-width: 100%; display: block; margin: 0 auto 4px auto;">
                        </div>
                        <br>
                        <?php endif; ?>
                        <div style="border-top: 1px solid #000; display: inline-block; padding-top: 5px; min-width: 150px;">
                            <?php _e('prepared_by'); ?>
                            <?php if (!empty($voucher['created_by_name'])): ?>
                            <div style="font-size: 12px;"><?php echo htmlspecialchars($voucher['created_by_name']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($cvPmName !== ''): ?>
                    <!-- Project Manager / Engineer sign-off. Rendered only when a
                         name was recorded, so a voucher without one prints with
                         the single Prepared By box exactly as it always has. -->
                    <div class="col-6 text-center">
                        <?php if ($cvPmSig): ?>
                        <div style="min-width: 150px; display: inline-block;">
                            <img src="<?php echo htmlspecialchars($cvPmSig); ?>" alt="<?php _e('signature'); ?>" style="max-height: 70px; max-width: 100%; display: block; margin: 0 auto 4px auto;">
                        </div>
                        <br>
                        <?php endif; ?>
                        <div style="border-top: 1px solid #000; display: inline-block; padding-top: 5px; min-width: 150px;">
                            <?php _e('project_manager'); ?>
                            <div style="font-size: 12px;"><?php echo htmlspecialchars($cvPmName); ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function confirmDelete(id) {
    if (confirm('<?php echo __('confirm_delete_consumption_voucher'); ?>\n\n<?php echo __('confirm_delete_cv_will_reverse'); ?>')) {
        window.location.href = 'consumption_voucher_view.php?delete=' + id;
    }
}
</script>

<style>
@media print {
    @page {
        size: A4;
        margin: 10mm;
    }

    .no-print, .btn, .navbar, .footer, .card-header, nav, .sidebar {
        display: none !important;
    }

    body {
        background: white !important;
        font-size: 9pt !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    .container, .container-fluid, .row, .col-md-12, .col-lg-10 {
        max-width: 100% !important;
        width: 100% !important;
        padding: 0 !important;
        margin: 0 !important;
    }

    .card {
        border: none !important;
        box-shadow: none !important;
    }

    .card-body {
        padding: 0 !important;
    }

    .card-body > .row.mb-4:first-child {
        margin-bottom: 0.5rem !important;
    }

    .card-body > .row.mb-4:first-child h4 {
        font-size: 14pt !important;
        margin-bottom: 0.3rem !important;
    }

    .card-body > .row.mb-4:first-child .table-sm {
        font-size: 8pt !important;
    }

    .card-body > .row.mb-4:first-child .table-sm td {
        padding: 1px 4px !important;
    }

    hr {
        margin: 0.3rem 0 !important;
    }

    .print-summary {
        display: flex !important;
        flex-direction: row !important;
        flex-wrap: nowrap !important;
        justify-content: space-between !important;
        gap: 8px !important;
        margin-bottom: 0.5rem !important;
    }

    .print-summary > div {
        flex: 1 1 50% !important;
        max-width: 50% !important;
        width: auto !important;
        padding: 0 !important;
    }

    .print-summary .card {
        margin: 0 !important;
        border: 1px solid #ddd !important;
    }

    .print-summary .card-body {
        padding: 4px 6px !important;
    }

    .print-summary .card-body small {
        font-size: 6pt !important;
        display: block !important;
    }

    .print-summary .card-body h4 {
        font-size: 9pt !important;
        margin: 0 !important;
    }

    .table-responsive {
        overflow: visible !important;
    }

    .table {
        font-size: 8pt !important;
        page-break-inside: auto !important;
    }

    .table th, .table td {
        padding: 2px 4px !important;
        vertical-align: middle !important;
    }

    .table thead th {
        background-color: #f8f9fa !important;
        font-size: 7pt !important;
    }

    .table-secondary td {
        padding: 1px 4px !important;
        font-size: 7pt !important;
        background-color: #e9ecef !important;
    }

    .table-danger {
        background-color: rgba(220, 53, 69, 0.1) !important;
    }

    .badge {
        font-size: 6pt !important;
        padding: 1px 4px !important;
    }

    h6.text-muted.mb-3 {
        font-size: 8pt !important;
        margin-bottom: 0.3rem !important;
    }

    .row.mt-5.pt-4.border-top {
        margin-top: 1rem !important;
        padding-top: 0.5rem !important;
    }

    .row.mt-5.pt-4.border-top > div {
        font-size: 8pt !important;
    }

    .text-muted.small {
        display: none !important;
    }
}
</style>

<?php require_once '../../includes/footer.php'; ?>
