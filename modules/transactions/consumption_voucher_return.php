<?php
/**
 * Consumption Voucher - Return Form
 *
 * Return material that was issued on a consumption voucher back into stock,
 * as its own dated document. Launched from the original voucher's view page
 * ("Create Return") as ?from=<voucher id>.
 *
 * The return is saved as an ordinary consumption voucher carrying NEGATIVE
 * quantities and flagged with return_of_voucher_id (migration 0047). That sign
 * convention is what lets every report stay untouched: consumption is
 * aggregated as a plain SUM(quantity) in eight report files, so a negative row
 * nets out on its own.
 *
 * Location and cost centre are inherited from the original and are NOT
 * editable - the stock has to go back onto the batches it came from, which
 * live at the original location.
 */
require_once '../../config/database.php';
require_once '../../includes/functions.php';
$pageTitle = __('consumption_voucher_return');

if (!isLoggedIn()) {
    redirect('login.php');
}

// Same permission as raising a consumption voucher (user-confirmed).
if (!hasPermission('consumption_vouchers', 'create')) {
    setAlert(__('no_permission'), 'danger');
    redirect('modules/transactions/consumption_voucher_list.php');
}

if (!cvReturnsAvailable($conn)) {
    setAlert(__('cv_returns_not_available'), 'danger');
    redirect('modules/transactions/consumption_voucher_list.php');
}

$parentId = (int)($_GET['from'] ?? 0);
if ($parentId <= 0 && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $parentId = (int)($_POST['parent_id'] ?? 0);
}
if ($parentId <= 0) {
    setAlert(__('consumption_voucher_not_found'), 'danger');
    redirect('modules/transactions/consumption_voucher_list.php');
}

// Load the original voucher
$parentStmt = $conn->prepare("
    SELECT cv.*, l.name AS location_name, cc.name AS cost_centre_name, cc.code AS cost_centre_code
    FROM consumption_vouchers cv
    LEFT JOIN locations l ON cv.location_id = l.id
    LEFT JOIN cost_centres cc ON cv.cost_centre_id = cc.id
    WHERE cv.id = ?
");
$parentStmt->execute([$parentId]);
$parent = $parentStmt->fetch();

if (!$parent) {
    setAlert(__('consumption_voucher_not_found'), 'danger');
    redirect('modules/transactions/consumption_voucher_list.php');
}

// A return cannot itself be returned - that would be a re-issue, which is an
// ordinary consumption voucher.
if (!empty($parent['return_of_voucher_id'])) {
    setAlert(__('cv_cannot_return_a_return'), 'danger');
    redirect('modules/transactions/consumption_voucher_view.php?id=' . $parentId);
}

if (!canAccessLocation($parent['location_id'])) {
    setAlert(__('no_permission'), 'danger');
    redirect('modules/transactions/consumption_voucher_list.php');
}

/**
 * Lines of the original voucher with how much of each is still returnable.
 *
 * @param PDO $conn     Database connection
 * @param int $parentId Original consumption voucher ID
 * @return array Detail rows with 'returned' and 'returnable' added
 */
function cvrLoadParentLines($conn, $parentId) {
    $stmt = $conn->prepare("
        SELECT cvd.*, p.name AS product_name, p.unit
        FROM consumption_voucher_details cvd
        LEFT JOIN products p ON cvd.product_id = p.id
        WHERE cvd.voucher_id = ?
        ORDER BY cvd.id
    ");
    $stmt->execute([$parentId]);
    $lines = $stmt->fetchAll();

    $returned = cvReturnedQuantities($conn, $parentId);
    foreach ($lines as &$line) {
        $already = isset($returned[(int)$line['id']]) ? (float)$returned[(int)$line['id']] : 0.0;
        $line['returned']   = $already;
        $line['returnable'] = max(0, (float)$line['quantity'] - $already);
    }
    unset($line);

    return $lines;
}

$parentLines = cvrLoadParentLines($conn, $parentId);

$totalReturnable = 0;
foreach ($parentLines as $line) {
    $totalReturnable += $line['returnable'];
}

$errors = [];
$posted = ($_SERVER['REQUEST_METHOD'] == 'POST');

if ($posted) {
    checkCSRF();

    $returnDate = dbDate($_POST['voucher_date'] ?? '');
    $remarks    = clean($_POST['remarks'] ?? '');
    $qtyIn      = $_POST['return_qty'] ?? [];

    if (empty($returnDate)) {
        $errors[] = __('please_select_date');
    }

    // Collect the lines actually being returned, validated against the ORIGINAL
    // rows rather than anything the browser sent back, so a hand-crafted POST
    // cannot return a line that belongs to another voucher.
    $toReturn = [];
    foreach ($parentLines as $line) {
        $detailId = (int)$line['id'];
        $qty = isset($qtyIn[$detailId]) ? floatval($qtyIn[$detailId]) : 0;

        if ($qty <= 0) {
            continue;
        }
        if ($qty > $line['returnable'] + 0.0000001) {
            $errors[] = sprintf(
                __('cv_return_exceeds_returnable'),
                $line['product_name'],
                formatNumber($line['returnable'], 3)
            );
            continue;
        }
        $toReturn[$detailId] = ['qty' => $qty, 'line' => $line];
    }

    if (empty($toReturn) && empty($errors)) {
        $errors[] = __('cv_return_nothing_entered');
    }

    if (empty($errors)) {
        try {
            $conn->beginTransaction();

            // Re-check inside the transaction. Two people returning against the
            // same voucher at once would otherwise be able to overshoot between
            // page load and POST.
            $freshReturned = cvReturnedQuantities($conn, $parentId);
            foreach ($toReturn as $detailId => $item) {
                $already = isset($freshReturned[$detailId]) ? (float)$freshReturned[$detailId] : 0.0;
                $stillReturnable = (float)$item['line']['quantity'] - $already;
                if ($item['qty'] > $stillReturnable + 0.0000001) {
                    throw new Exception(sprintf(
                        __('cv_return_exceeds_returnable'),
                        $item['line']['product_name'],
                        formatNumber(max(0, $stillReturnable), 3)
                    ));
                }
            }

            $returnNumber = getNextNumber('consumption_vouchers', 'voucher_number', getSetting('cvr_prefix', 'CVR'));

            $headerStmt = $conn->prepare("
                INSERT INTO consumption_vouchers
                (voucher_number, voucher_date, location_id, cost_centre_id, currency_id, exchange_rate_to_base,
                 remarks, return_of_voucher_id, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $headerStmt->execute([
                $returnNumber,
                $returnDate,
                $parent['location_id'],
                $parent['cost_centre_id'],
                $parent['currency_id'],
                $parent['exchange_rate_to_base'],
                $remarks,
                $parentId,
                getUserId()
            ]);
            $returnId = $conn->lastInsertId();

            $totalAmount = cvrApplyReturn($conn, $returnId, $parentId, $parent['location_id'], $toReturn);

            $conn->prepare("UPDATE consumption_vouchers SET total_amount = ? WHERE id = ?")
                 ->execute([$totalAmount, $returnId]);

            $conn->commit();

            logAudit('consumption_voucher', 'return', $returnId);
            setAlert(sprintf(__('cv_return_created'), $returnNumber), 'success');
            redirect('modules/transactions/consumption_voucher_view.php?id=' . $returnId);

        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $errors[] = $e->getMessage();
        }
    }
}

/**
 * Write the return's detail rows, give the stock back and record the batch links.
 *
 * Stock is returned to the EXACT batches it was drawn from, walked in reverse
 * order of consumption (most recently drawn layer first), so the consumption
 * that remains still sits on the earliest layers. Each give-back is recorded as
 * a batch link with a NEGATIVE quantity - which is what lets cvRestoreStock()
 * reverse a deleted return with no special case.
 *
 * Vouchers with no recorded batches (raised before migration 0040, or on a
 * tenant without that table) fall back to adding a new batch at the rate the
 * original line recorded - the same fallback cvRestoreStock() already uses.
 *
 * @param PDO   $conn       Database connection
 * @param int   $returnId   The return voucher being written
 * @param int   $parentId   Original consumption voucher ID
 * @param int   $locationId Location the stock goes back to
 * @param array $toReturn   Map of original detail_id => ['qty','line']
 * @return float Total amount of the return, NEGATIVE
 */
function cvrApplyReturn($conn, $returnId, $parentId, $locationId, array $toReturn) {
    $trackBatches   = cvBatchTrackingAvailable($conn);
    $batchesByLine  = $trackBatches ? cvReturnableBatches($conn, $parentId) : [];
    $totalAmount    = 0;

    $detailStmt = $conn->prepare("
        INSERT INTO consumption_voucher_details
        (voucher_id, product_id, quantity, rate_per_unit, amount, remarks, return_of_detail_id)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $linkStmt = $conn->prepare("
        INSERT INTO consumption_voucher_batches
        (voucher_id, detail_id, batch_id, quantity, rate_per_unit)
        VALUES (?, ?, ?, ?, ?)
    ");
    $giveBackStmt = $conn->prepare("
        UPDATE stock_batches SET quantity_remaining = quantity_remaining + ? WHERE id = ?
    ");
    $newBatchStmt = $conn->prepare("
        INSERT INTO stock_batches
        (location_id, product_id, batch_date, transaction_type, transaction_id, quantity_in, quantity_remaining, rate_per_unit)
        VALUES (?, ?, CURDATE(), 'consumption', NULL, ?, ?, ?)
    ");

    foreach ($toReturn as $detailId => $item) {
        $qty  = $item['qty'];
        $line = $item['line'];

        $remaining  = $qty;
        $lineCost   = 0;
        $giveBacks  = [];

        foreach (($batchesByLine[$detailId] ?? []) as $slot) {
            if ($remaining <= 0.0000001) {
                break;
            }
            $give = min($remaining, $slot['available']);
            if ($give <= 0) {
                continue;
            }
            $giveBacks[] = ['batch_id' => $slot['batch_id'], 'qty' => $give, 'rate' => $slot['rate']];
            $lineCost   += $give * $slot['rate'];
            $remaining  -= $give;
        }

        // Nothing recorded to give back against (pre-0040 voucher, or the batch
        // rows have since gone): put the balance back as a new batch at the
        // rate the original line recorded.
        $newBatchQty = 0;
        if ($remaining > 0.0000001) {
            $newBatchQty = $remaining;
            $lineCost   += $newBatchQty * (float)$line['rate_per_unit'];
        }

        $rate   = $qty > 0 ? ($lineCost / $qty) : (float)$line['rate_per_unit'];
        $amount = $lineCost;

        // Quantities and amounts are stored NEGATIVE so that every report's
        // SUM(quantity) nets the return off against the original consumption.
        $detailStmt->execute([
            $returnId,
            (int)$line['product_id'],
            -$qty,
            $rate,
            -$amount,
            '',
            $detailId
        ]);
        $returnDetailId = $conn->lastInsertId();

        foreach ($giveBacks as $give) {
            $giveBackStmt->execute([$give['qty'], $give['batch_id']]);
            if ($trackBatches) {
                $linkStmt->execute([$returnId, $returnDetailId, $give['batch_id'], -$give['qty'], $give['rate']]);
            }
        }

        if ($newBatchQty > 0) {
            $newBatchStmt->execute([
                $locationId,
                (int)$line['product_id'],
                $newBatchQty,
                $newBatchQty,
                (float)$line['rate_per_unit']
            ]);
        }

        $totalAmount += $amount;
    }

    return -$totalAmount;
}

require_once '../../includes/header.php';

$formDate    = $posted ? ($_POST['voucher_date'] ?? '') : date('Y-m-d');
$formRemarks = $posted ? ($_POST['remarks'] ?? '') : '';
$formQty     = $posted ? ($_POST['return_qty'] ?? []) : [];
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="bi bi-arrow-return-left"></i>
                        <?php _e('consumption_voucher_return'); ?>
                    </h5>
                    <a href="consumption_voucher_view.php?id=<?php echo $parentId; ?>" class="btn btn-sm btn-secondary">
                        <i class="bi bi-arrow-left"></i> <?php _e('back'); ?>
                    </a>
                </div>
                <div class="card-body">

                    <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>

                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i>
                        <?php echo sprintf(
                            __('cv_return_against'),
                            htmlspecialchars($parent['voucher_number'])
                        ); ?>
                        <?php _e('cv_return_location_fixed'); ?>
                    </div>

                    <?php if ($totalReturnable <= 0): ?>
                    <div class="alert alert-warning mb-0">
                        <i class="bi bi-exclamation-triangle"></i>
                        <?php _e('cv_return_fully_returned'); ?>
                    </div>
                    <?php else: ?>

                    <form method="POST" id="cvReturnForm">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRF(); ?>">
                        <input type="hidden" name="parent_id" value="<?php echo $parentId; ?>">

                        <div class="row mb-4">
                            <div class="col-md-3">
                                <label class="form-label"><?php _e('location'); ?></label>
                                <input type="text" class="form-control" readonly
                                       value="<?php echo htmlspecialchars($parent['location_name'] ?? ''); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label"><?php _e('cost_centre'); ?></label>
                                <input type="text" class="form-control" readonly
                                       value="<?php echo htmlspecialchars(trim(($parent['cost_centre_code'] ?? '') . ' ' . ($parent['cost_centre_name'] ?? '')) ?: '-'); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label required"><?php _e('return_date'); ?></label>
                                <input type="date" name="voucher_date" class="form-control"
                                       value="<?php echo htmlspecialchars($formDate); ?>" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label"><?php _e('remarks'); ?></label>
                                <input type="text" name="remarks" class="form-control"
                                       value="<?php echo htmlspecialchars($formRemarks); ?>"
                                       placeholder="<?php echo __('optional'); ?>">
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-bordered table-sm align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width:40px;">#</th>
                                        <th><?php _e('product'); ?></th>
                                        <th><?php _e('unit'); ?></th>
                                        <th class="text-end"><?php _e('issued_quantity'); ?></th>
                                        <th class="text-end"><?php _e('already_returned'); ?></th>
                                        <th class="text-end"><?php _e('returnable'); ?></th>
                                        <th class="text-end" style="width:160px;"><?php _e('return_quantity'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $i = 0; foreach ($parentLines as $line): $i++; ?>
                                    <tr<?php echo $line['returnable'] <= 0 ? ' class="table-secondary"' : ''; ?>>
                                        <td><?php echo $i; ?></td>
                                        <td><?php echo htmlspecialchars($line['product_name'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($line['unit'] ?? ''); ?></td>
                                        <td class="text-end"><?php echo formatNumber($line['quantity'], 3); ?></td>
                                        <td class="text-end"><?php echo formatNumber($line['returned'], 3); ?></td>
                                        <td class="text-end"><strong><?php echo formatNumber($line['returnable'], 3); ?></strong></td>
                                        <td>
                                            <?php if ($line['returnable'] > 0): ?>
                                            <input type="number" class="form-control form-control-sm text-end return-qty"
                                                   name="return_qty[<?php echo (int)$line['id']; ?>]"
                                                   step="0.001" min="0"
                                                   max="<?php echo htmlspecialchars($line['returnable']); ?>"
                                                   data-max="<?php echo htmlspecialchars($line['returnable']); ?>"
                                                   value="<?php echo htmlspecialchars($formQty[(int)$line['id']] ?? ''); ?>"
                                                   placeholder="0">
                                            <?php else: ?>
                                            <span class="text-muted small"><?php _e('fully_returned'); ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="d-flex justify-content-end mt-4">
                            <a href="consumption_voucher_view.php?id=<?php echo $parentId; ?>" class="btn btn-secondary me-2"><?php _e('cancel'); ?></a>
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-check-circle"></i> <?php _e('save_return'); ?>
                            </button>
                        </div>
                    </form>

                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Client-side mirror of the server cap, so an over-return is caught before a
// round trip. The server re-checks inside the transaction regardless.
document.getElementById('cvReturnForm')?.addEventListener('submit', function (e) {
    var total = 0;
    var bad = null;

    document.querySelectorAll('.return-qty').forEach(function (input) {
        var val = parseFloat(input.value) || 0;
        var max = parseFloat(input.getAttribute('data-max')) || 0;
        if (val < 0) { bad = input; }
        if (val > max + 0.0000001) { bad = input; }
        total += val;
    });

    if (bad) {
        e.preventDefault();
        bad.focus();
        alert(<?php echo json_encode(__('cv_return_check_quantities')); ?>);
        return false;
    }
    if (total <= 0) {
        e.preventDefault();
        alert(<?php echo json_encode(__('cv_return_nothing_entered')); ?>);
        return false;
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>
