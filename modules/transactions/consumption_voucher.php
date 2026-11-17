<?php
require_once '../../config/database.php';
require_once '../../includes/functions.php';
$pageTitle = __('consumption_voucher');
if (!hasPermission('consumption_vouchers', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('');
}

// Get currencies
$allCurrencies = $conn->query("SELECT * FROM currencies WHERE is_active = 1 ORDER BY is_default DESC, code ASC")->fetchAll();
$defaultCurrency = getDefaultCurrency();

/**
 * Consume $qty of a product at a location via FIFO.
 *
 * Same batch walk and weighted-average costing the module has always used —
 * it additionally returns which batches were drawn from, so the caller can
 * record them and reverse the voucher exactly later.
 *
 * @return array ['rate' => float, 'amount' => float, 'deductions' => [['batch_id','quantity','rate']]]
 */
function cvConsumeFifo($conn, $productId, $locationId, $qty) {
    $remainingQty = $qty;
    $totalCost = 0;
    $deductions = [];

    $batchesStmt = $conn->prepare("
        SELECT id, quantity_remaining, rate_per_unit
        FROM stock_batches
        WHERE product_id = ? AND location_id = ? AND quantity_remaining > 0
        ORDER BY batch_date ASC, id ASC
    ");
    $batchesStmt->execute([$productId, $locationId]);
    $batches = $batchesStmt->fetchAll();

    foreach ($batches as $batch) {
        if ($remainingQty <= 0) break;

        $deductQty = min($remainingQty, $batch['quantity_remaining']);
        $newRemaining = $batch['quantity_remaining'] - $deductQty;

        $updateBatchStmt = $conn->prepare("
            UPDATE stock_batches SET quantity_remaining = ? WHERE id = ?
        ");
        $updateBatchStmt->execute([$newRemaining, $batch['id']]);

        $deductions[] = [
            'batch_id' => $batch['id'],
            'quantity' => $deductQty,
            'rate'     => $batch['rate_per_unit'],
        ];

        $totalCost += $deductQty * $batch['rate_per_unit'];
        $remainingQty -= $deductQty;
    }

    return [
        'rate'       => $qty > 0 ? $totalCost / $qty : 0,
        'amount'     => $totalCost,
        'deductions' => $deductions,
    ];
}

/**
 * Write a voucher's detail lines, deducting stock FIFO and recording the
 * batches consumed. Shared by create and edit so both behave identically.
 *
 * @return float Voucher total
 */
function cvSaveLines($conn, $voucherId, $locationId, $products, $quantities, $itemRemarks) {
    $trackBatches = cvBatchTrackingAvailable($conn);
    $totalAmount = 0;

    foreach ($products as $index => $productId) {
        $productId = (int)$productId;
        $qty = floatval($quantities[$index] ?? 0);
        $itemRemark = clean($itemRemarks[$index] ?? '');

        if ($productId <= 0 || $qty <= 0) continue;

        $fifo = cvConsumeFifo($conn, $productId, $locationId, $qty);

        $detailStmt = $conn->prepare("
            INSERT INTO consumption_voucher_details
            (voucher_id, product_id, quantity, rate_per_unit, amount, remarks)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $detailStmt->execute([$voucherId, $productId, $qty, $fifo['rate'], $fifo['amount'], $itemRemark]);
        $detailId = $conn->lastInsertId();

        if ($trackBatches) {
            $batchLinkStmt = $conn->prepare("
                INSERT INTO consumption_voucher_batches
                (voucher_id, detail_id, batch_id, quantity, rate_per_unit)
                VALUES (?, ?, ?, ?, ?)
            ");
            foreach ($fifo['deductions'] as $deduction) {
                $batchLinkStmt->execute([
                    $voucherId, $detailId, $deduction['batch_id'],
                    $deduction['quantity'], $deduction['rate']
                ]);
            }
        }

        $totalAmount += $fifo['amount'];
    }

    return $totalAmount;
}

/**
 * Validate the submitted lines against available stock.
 *
 * @return array Error messages (empty when everything is available)
 */
function cvCheckStock($conn, $products, $quantities, $locationId) {
    $errors = [];

    foreach ($products as $index => $productId) {
        $productId = (int)$productId;
        $qty = floatval($quantities[$index] ?? 0);
        if ($productId <= 0 || $qty <= 0) continue;

        $stockInfo = getProductStockOnFly($productId, $locationId);
        if ($stockInfo['quantity'] < $qty) {
            $pStmt = $conn->prepare("SELECT name FROM products WHERE id = ?");
            $pStmt->execute([$productId]);
            $pName = $pStmt->fetchColumn();

            $lStmt = $conn->prepare("SELECT name FROM locations WHERE id = ?");
            $lStmt->execute([$locationId]);
            $lName = $lStmt->fetchColumn();

            $errors[] = sprintf(
                __('insufficient_stock_for_consumption'),
                $pName, $lName,
                number_format($stockInfo['quantity'], 3),
                number_format($qty, 3)
            );
        }
    }

    return $errors;
}

// Edit mode (?id=). Editing reverses the voucher's stock effect and re-applies
// it, so it is gated on its own permission.
$editId = (int)($_GET['id'] ?? 0);
if ($editId <= 0 && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $editId = (int)($_POST['voucher_id'] ?? 0);
}
$isEdit = $editId > 0;
$editVoucher = null;

if ($isEdit) {
    if (!hasPermission('consumption_vouchers', 'edit')) {
        setAlert(__('no_permission'), 'danger');
        redirect('modules/transactions/consumption_voucher_list.php');
    }

    $editStmt = $conn->prepare("SELECT * FROM consumption_vouchers WHERE id = ?");
    $editStmt->execute([$editId]);
    $editVoucher = $editStmt->fetch();

    if (!$editVoucher) {
        setAlert(__('consumption_voucher_not_found'), 'danger');
        redirect('modules/transactions/consumption_voucher_list.php');
    }
    if (!canAccessLocation($editVoucher['location_id'])) {
        setAlert(__('no_permission'), 'danger');
        redirect('modules/transactions/consumption_voucher_list.php');
    }

    // Returns lock the voucher (migration 0047). cvCanEdit() is the single
    // decision, shared with the list and the view, so they can never disagree
    // about who sees a pencil. Two cases: a voucher that has been returned
    // against (re-running the edit path would restore the full issued quantity
    // on top of what already came back), and a voucher that IS a return (its
    // batch give-back would have to be re-derived - delete and raise another).
    if (!cvCanEdit($conn, $editVoucher)) {
        setAlert(
            !empty($editVoucher['return_of_voucher_id'])
                ? __('cv_return_not_editable')
                : __('cv_locked_by_return'),
            'danger'
        );
        redirect('modules/transactions/consumption_voucher_view.php?id=' . $editId);
    }
}

$errors = [];

// Handle form submission
$canSubmit = $isEdit
    ? hasPermission('consumption_vouchers', 'edit')
    : hasPermission('consumption_vouchers', 'create');

if ($_SERVER['REQUEST_METHOD'] == 'POST' && $canSubmit) {
    checkCSRF();

    $locationId = (int)$_POST['location_id'];
    $costCentreId = !empty($_POST['cost_centre_id']) ? (int)$_POST['cost_centre_id'] : null;
    $voucherDate = dbDate($_POST['voucher_date']);
    $remarks = clean($_POST['remarks'] ?? '');
    $currencyId = !empty($_POST['currency_id']) ? (int)$_POST['currency_id'] : ($defaultCurrency ? $defaultCurrency['id'] : null);
    $exchangeRateToBase = !empty($_POST['exchange_rate_to_base']) ? floatval($_POST['exchange_rate_to_base']) : 1;

    $products = $_POST['products'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $itemRemarks = $_POST['item_remarks'] ?? [];

    // Project Manager / Engineer sign-off. Both parts optional: a voucher with
    // no name recorded prints exactly as it did before this existed.
    $projectManagerName = clean($_POST['project_manager_name'] ?? '');
    if ($projectManagerName === '') {
        $projectManagerName = null;
    } else {
        // Trim to the column width in PHP rather than letting MySQL do it.
        // This server runs without STRICT_TRANS_TABLES, so an over-long value
        // is silently truncated here -- but on a tenant that IS strict the
        // same POST would throw and roll back the entire voucher save. The
        // form caps the field at 150 too, so only a crafted POST reaches this.
        $projectManagerName = mb_substr($projectManagerName, 0, 150);
    }

    // Signature is whatever was drawn on (or uploaded into) the pad, validated
    // as a base64 PNG/JPEG data URL exactly as requisition_voucher.php does for
    // Prepared By.
    //
    // It deliberately does NOT fall back to getUserSignature() the way that
    // form does. The preparer there IS the logged-in user; the project manager
    // here is a free-text third party, so borrowing the creator's saved
    // signature would stamp a signature onto an authorisation they never gave.
    //
    // Three outcomes, which the edit path below has to tell apart:
    //   a signature to store  -> $projectManagerSig
    //   an explicit removal   -> $pmSigClear   (the field posted back empty,
    //                            which only happens when Clear was pressed:
    //                            the input is otherwise pre-seeded with the
    //                            signature already on the voucher)
    //   leave well alone      -> neither       (field absent, or contents
    //                            unparseable, so nothing is destroyed)
    $projectManagerSig = null;
    $pmSigClear = false;

    if (array_key_exists('project_manager_signature', $_POST)) {
        $pmSigPosted = $_POST['project_manager_signature'];
        if (!is_string($pmSigPosted) || trim($pmSigPosted) === '') {
            $pmSigClear = true;
        } elseif (preg_match('#^data:image/(png|jpeg|jpg);base64,[A-Za-z0-9+/=]+$#', $pmSigPosted)
                  && strlen($pmSigPosted) <= 2 * 1024 * 1024) {
            $projectManagerSig = $pmSigPosted;
        }
    }

    $errors = [];
    $allowNegativeStock = getSetting('allow_negative_stock', '0') === '1';

    if (empty($locationId)) {
        $errors[] = __('please_select_location');
    }

    if (empty($voucherDate)) {
        $errors[] = __('please_select_date');
    }

    // Validate at least one item
    $hasItems = false;
    foreach ($products as $index => $productId) {
        if (!empty($productId) && floatval($quantities[$index] ?? 0) > 0) {
            $hasItems = true;
            break;
        }
    }

    if (!$hasItems) {
        $errors[] = __('please_add_at_least_one_item');
    }

    // Check stock availability if negative stock not allowed.
    // Create checks here, before the transaction, exactly as before. Edit cannot
    // check yet — its own old lines are still holding stock down — so it checks
    // inside the transaction, after those lines have been reversed.
    if (empty($errors) && !$allowNegativeStock && !$isEdit) {
        $errors = array_merge($errors, cvCheckStock($conn, $products, $quantities, $locationId));
    }

    if (empty($errors)) {
        try {
            $conn->beginTransaction();

            if ($isEdit) {
                // Put the stock this voucher consumed back where it came from,
                // then drop the old lines (their batch links cascade away).
                cvRestoreStock($conn, $editId, $editVoucher['location_id']);
                $conn->prepare("DELETE FROM consumption_voucher_details WHERE voucher_id = ?")
                     ->execute([$editId]);

                // Now the stock figures are clean, so the new lines can be checked.
                if (!$allowNegativeStock) {
                    $stockErrors = cvCheckStock($conn, $products, $quantities, $locationId);
                    if (!empty($stockErrors)) {
                        throw new Exception(implode(' ', $stockErrors));
                    }
                }

                // The signature column is touched only when a new signature
                // arrived, or when the pad was explicitly cleared. Anything
                // else leaves the signature already on the voucher alone, so a
                // save that fails to reach the pad can never silently wipe it.
                // The NAME always writes, so it can be corrected on its own.
                $pmSigSet = '';
                $pmParams = [];
                if ($projectManagerSig !== null) {
                    $pmSigSet = ', project_manager_signature = ?';
                    $pmParams = [$projectManagerSig];
                } elseif ($pmSigClear) {
                    $pmSigSet = ', project_manager_signature = NULL';
                }

                $stmt = $conn->prepare("
                    UPDATE consumption_vouchers
                    SET voucher_date = ?, location_id = ?, cost_centre_id = ?,
                        currency_id = ?, exchange_rate_to_base = ?, remarks = ?,
                        project_manager_name = ?{$pmSigSet}
                    WHERE id = ?
                ");
                $stmt->execute(array_merge(
                    [$voucherDate, $locationId, $costCentreId, $currencyId, $exchangeRateToBase, $remarks, $projectManagerName],
                    $pmParams,
                    [$editId]
                ));

                $voucherId = $editId;
            } else {
                // Generate voucher number
                $voucherNumber = getNextNumber('consumption_vouchers', 'voucher_number', getSetting('cv_prefix', 'CV'));

                // Insert voucher header
                $stmt = $conn->prepare("
                    INSERT INTO consumption_vouchers
                    (voucher_number, voucher_date, location_id, cost_centre_id, currency_id, exchange_rate_to_base, remarks,
                     project_manager_name, project_manager_signature, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$voucherNumber, $voucherDate, $locationId, $costCentreId, $currencyId, $exchangeRateToBase, $remarks,
                                $projectManagerName, $projectManagerSig, getUserId()]);

                $voucherId = $conn->lastInsertId();
            }

            // Insert details and deduct stock via FIFO
            $totalAmount = cvSaveLines($conn, $voucherId, $locationId, $products, $quantities, $itemRemarks);

            // Update total amount
            $updateStmt = $conn->prepare("UPDATE consumption_vouchers SET total_amount = ? WHERE id = ?");
            $updateStmt->execute([$totalAmount, $voucherId]);

            $conn->commit();

            logAudit('consumption_voucher', $isEdit ? 'update' : 'create', $voucherId);
            setAlert($isEdit ? __('consumption_voucher_updated') : __('consumption_voucher_created'), 'success');
            redirect('modules/transactions/consumption_voucher_view.php?id=' . $voucherId);

        } catch (PDOException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $errors[] = sprintf(__('database_error'), $e->getMessage());
        } catch (Exception $e) {
            // Stock shortfall on edit — everything above is rolled back, so the
            // voucher is left exactly as it was.
            if ($conn->inTransaction()) $conn->rollBack();
            $errors[] = $e->getMessage();
        }
    }

    if (!empty($errors)) {
        foreach ($errors as $error) {
            setAlert($error, 'danger');
        }
    }
}

require_once '../../includes/header.php';

// Get user's accessible locations
$locations = getUserLocations();

// Get user's accessible cost centres
$costCentres = getUserCostCentres();

// Get all active products for dropdown
$productsStmt = $conn->query("
    SELECT p.id, p.name, p.unit, pc.name as category_name
    FROM products p
    LEFT JOIN product_categories pc ON p.category_id = pc.id
    WHERE p.is_active = 1
    ORDER BY pc.name, p.name
");
$allProducts = $productsStmt->fetchAll();

// Form values. A rejected POST wins so nothing typed is lost, then the voucher
// being edited, then blanks for a new voucher.
$posted = ($_SERVER['REQUEST_METHOD'] == 'POST');

$formLocationId   = $posted ? (int)($_POST['location_id'] ?? 0)      : ($isEdit ? (int)$editVoucher['location_id'] : 0);
$formCostCentreId = $posted ? (int)($_POST['cost_centre_id'] ?? 0)   : ($isEdit ? (int)$editVoucher['cost_centre_id'] : 0);
$formVoucherDate  = $posted ? ($_POST['voucher_date'] ?? '')         : ($isEdit ? $editVoucher['voucher_date'] : date('Y-m-d'));
$formRemarks      = $posted ? ($_POST['remarks'] ?? '')              : ($isEdit ? $editVoucher['remarks'] : '');
$formPmName       = $posted ? ($_POST['project_manager_name'] ?? '') : ($isEdit ? ($editVoucher['project_manager_name'] ?? '') : '');

// Signature already on the voucher, shown so an editor can see there is one
// without having to re-draw it. A rejected POST re-seeds the pad from what was
// just drawn, so a validation error never costs the user their signature.
$formPmSig = $posted
    ? ($_POST['project_manager_signature'] ?? '')
    : ($isEdit ? ($editVoucher['project_manager_signature'] ?? '') : '');
if (!is_string($formPmSig) || !preg_match('#^data:image/(png|jpeg|jpg);base64,[A-Za-z0-9+/=]+$#', $formPmSig)) {
    $formPmSig = '';
}
$formCurrencyId   = $posted ? (int)($_POST['currency_id'] ?? 0)      : ($isEdit ? (int)$editVoucher['currency_id'] : ($defaultCurrency ? (int)$defaultCurrency['id'] : 0));
$formExchangeRate = $posted ? ($_POST['exchange_rate_to_base'] ?? 1) : ($isEdit ? $editVoucher['exchange_rate_to_base'] : 1);

$formRows = [];
if ($posted) {
    foreach (($_POST['products'] ?? []) as $i => $pid) {
        $formRows[] = [
            'product_id' => (int)$pid,
            'quantity'   => $_POST['quantity'][$i] ?? '',
            'remarks'    => $_POST['item_remarks'][$i] ?? '',
        ];
    }
} elseif ($isEdit) {
    $rowsStmt = $conn->prepare("
        SELECT product_id, quantity, remarks
        FROM consumption_voucher_details WHERE voucher_id = ? ORDER BY id
    ");
    $rowsStmt->execute([$editId]);
    foreach ($rowsStmt->fetchAll() as $row) {
        $formRows[] = [
            'product_id' => (int)$row['product_id'],
            'quantity'   => floatval($row['quantity']),
            'remarks'    => $row['remarks'],
        ];
    }
}
if (empty($formRows)) {
    $formRows[] = ['product_id' => 0, 'quantity' => '', 'remarks' => ''];
}

// Non-default currency needs the exchange rate visible from the start
$showExchangeRate = $defaultCurrency && $formCurrencyId && $formCurrencyId != $defaultCurrency['id'];

// What this voucher currently holds per product — the Current stock column adds
// it back, since saving reverses it before re-applying the new lines.
$editOwnQty = [];
if ($isEdit) {
    $ownStmt = $conn->prepare("
        SELECT product_id, SUM(quantity) AS qty
        FROM consumption_voucher_details WHERE voucher_id = ? GROUP BY product_id
    ");
    $ownStmt->execute([$editId]);
    foreach ($ownStmt->fetchAll() as $own) {
        $editOwnQty[(string)$own['product_id']] = floatval($own['qty']);
    }
}
?>

<div class="row justify-content-center">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="bi bi-clipboard-minus"></i>
                    <?php _e($isEdit ? 'edit_consumption_voucher' : 'new_consumption_voucher'); ?>
                    <?php if ($isEdit): ?>
                        <span class="text-muted">— <?php echo htmlspecialchars($editVoucher['voucher_number']); ?></span>
                    <?php endif; ?>
                </h5>
                <div>
                    <a href="consumption_voucher_list.php" class="btn btn-sm btn-secondary">
                        <i class="bi bi-list"></i> <?php _e('list'); ?>
                    </a>
                </div>
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

                <form method="POST" id="consumptionForm">
                    <?php echo csrfField(); ?>
                    <?php if ($isEdit): ?>
                    <input type="hidden" name="voucher_id" value="<?php echo $editId; ?>">
                    <?php endif; ?>

                    <div class="row mb-3">
                        <div class="col-md-3">
                            <label class="form-label required"><?php _e('location'); ?></label>
                            <select name="location_id" id="locationId" class="form-select" required>
                                <option value=""><?php _e('select_location'); ?></option>
                                <?= renderTreeOptions($locations, $formLocationId ?: null) ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><?php _e('cost_centre'); ?></label>
                            <select name="cost_centre_id" id="costCentreId" class="form-select">
                                <option value=""><?php _e('select_cost_centre'); ?></option>
                                <?= renderTreeOptions($costCentres, $formCostCentreId ?: null, null, 'name', 'code') ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label required"><?php _e('voucher_date'); ?></label>
                            <input type="date" name="voucher_date" id="voucherDate" class="form-control"
                                   value="<?php echo htmlspecialchars($formVoucherDate); ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><?php _e('remarks'); ?></label>
                            <input type="text" name="remarks" class="form-control"
                                   value="<?php echo htmlspecialchars($formRemarks ?? ''); ?>"
                                   placeholder="<?php echo __('optional'); ?>">
                        </div>
                    </div>
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <label class="form-label"><?php _e('currency'); ?></label>
                            <select name="currency_id" id="currencyId" class="form-select" onchange="onCurrencyChange()">
                                <?php foreach ($allCurrencies as $cur): ?>
                                <option value="<?php echo $cur['id']; ?>"
                                    data-symbol="<?php echo htmlspecialchars($cur['symbol']); ?>"
                                    data-is-default="<?php echo $cur['is_default']; ?>"
                                    <?php echo $formCurrencyId == $cur['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cur['code'] . ' - ' . $cur['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3" id="exchangeRateGroup" style="display:<?php echo $showExchangeRate ? '' : 'none'; ?>;">
                            <label class="form-label"><?php _e('exchange_rate'); ?></label>
                            <input type="number" class="form-control" id="exchangeRateToBase" name="exchange_rate_to_base"
                                   step="0.00000001" min="0.00000001" value="<?php echo htmlspecialchars($formExchangeRate); ?>">
                            <small class="text-muted" id="exchangeRateHint"></small>
                        </div>
                    </div>

                    <h6 class="text-muted mb-3"><?php _e('consumption_items'); ?></h6>

                    <div class="table-responsive">
                        <table class="table table-bordered table-sm" id="consumptionTable">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 5%;"><?php _e('sr'); ?></th>
                                    <th style="width: 30%;"><?php _e('product'); ?></th>
                                    <th style="width: 12%;"><?php _e('current_stock'); ?></th>
                                    <th style="width: 12%;"><?php _e('quantity'); ?></th>
                                    <th style="width: 15%;"><?php _e('remarks'); ?></th>
                                    <th style="width: 5%;"><?php _e('action'); ?></th>
                                </tr>
                            </thead>
                            <tbody id="consumptionItems">
                                <?php foreach ($formRows as $rowIndex => $formRow): ?>
                                <tr class="item-row">
                                    <td class="row-number"><?php echo $rowIndex + 1; ?></td>
                                    <td>
                                        <select name="products[]" class="form-select form-select-sm product-select" required onchange="updateCurrentStock(this)">
                                            <option value=""><?php _e('select_product'); ?></option>
                                            <?php foreach ($allProducts as $product): ?>
                                            <option value="<?php echo $product['id']; ?>"
                                                <?php echo $formRow['product_id'] == $product['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($product['name']); ?> (<?php echo $product['unit']; ?>)
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="text" class="form-control form-control-sm stock-display text-end" readonly value="-" tabindex="-1">
                                    </td>
                                    <td>
                                        <input type="number" name="quantity[]" step="0.001" min="0.001"
                                               value="<?php echo htmlspecialchars($formRow['quantity']); ?>"
                                               class="form-control form-control-sm qty-input" required>
                                    </td>
                                    <td>
                                        <input type="text" name="item_remarks[]" class="form-control form-control-sm"
                                               value="<?php echo htmlspecialchars($formRow['remarks'] ?? ''); ?>"
                                               placeholder="<?php echo __('optional'); ?>">
                                    </td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-danger" onclick="removeRow(this)">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="6">
                                        <button type="button" class="btn btn-sm btn-primary" onclick="addRow()">
                                            <i class="bi bi-plus"></i> <?php _e('add_row'); ?>
                                        </button>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <!-- Project Manager / Engineer sign-off. Free text plus an
                         optional drawn signature, both printed on the voucher
                         and its delivery note. Left blank, neither printable
                         gains anything, exactly as before this existed. -->
                    <div class="card bg-light border mt-4">
                        <div class="card-body">
                            <h6 class="mb-3"><i class="bi bi-pen"></i> <?php _e('project_manager'); ?>
                                <small class="text-muted">(<?php _e('optional'); ?>)</small></h6>
                            <div class="row">
                                <div class="col-md-5">
                                    <label class="form-label"><?php _e('project_manager_name'); ?></label>
                                    <input type="text" name="project_manager_name" class="form-control"
                                           maxlength="150"
                                           value="<?php echo htmlspecialchars($formPmName ?? ''); ?>"
                                           placeholder="<?php echo __('project_manager_name_hint'); ?>">
                                    <small class="text-muted"><?php _e('project_manager_hint'); ?></small>
                                </div>
                                <div class="col-md-7">
                                    <label class="form-label"><?php _e('signature'); ?></label>
                                    <style>
                                        .cv-signature-wrapper { border: 1px dashed #adb5bd; border-radius: 4px; background: #fff; position: relative; max-width: 420px; }
                                        .cv-signature-canvas { display: block; width: 100%; height: 120px; touch-action: none; cursor: crosshair; }
                                        .cv-signature-placeholder { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: #adb5bd; font-style: italic; pointer-events: none; user-select: none; font-size: 13px; }
                                    </style>
                                    <div class="cv-signature-wrapper">
                                        <canvas id="pmSignature" class="cv-signature-canvas"></canvas>
                                        <span class="cv-signature-placeholder" id="pmSignaturePlaceholder"><?php _e('sign_here'); ?></span>
                                    </div>
                                    <div class="mt-2">
                                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearPmSignature()">
                                            <i class="bi bi-eraser"></i> <?php _e('clear'); ?>
                                        </button>
                                    </div>
                                    <?php
                                    /* Shared upload control: lets the signature be supplied as an image
                                       file instead of drawn. $sigUploadOfferSave is FALSE because this
                                       signature belongs to a free-text third party, not to the logged-in
                                       user, so it must never be offered for saving to their profile. */
                                    $sigUploadCanvasId      = 'pmSignature';
                                    $sigUploadPlaceholderId = 'pmSignaturePlaceholder';
                                    $sigUploadOfferSave     = false;
                                    require __DIR__ . '/../../includes/signature_upload_control.php';
                                    ?>
                                    <input type="hidden" name="project_manager_signature" id="pmSignatureInput"
                                           value="<?php echo htmlspecialchars($formPmSig); ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end mt-4">
                        <a href="consumption_voucher_list.php" class="btn btn-secondary me-2"><?php _e('cancel'); ?></a>
                        <?php if ($canSubmit): ?>
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check-circle"></i>
                            <?php _e($isEdit ? 'update_consumption_voucher' : 'save_consumption_voucher'); ?>
                        </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
const productsData = <?php echo json_encode($allProducts); ?>;
let stockCache = {};

// On edit, the quantities this voucher already consumed are still deducted from
// stock. Saving reverses them first, so the stock genuinely available to this
// voucher is the current figure plus its own consumption — but only at the
// location it was originally booked to, which is where the reversal lands.
const OWN_CONSUMPTION = <?php echo json_encode($editOwnQty ?? new stdClass()); ?>;
const OWN_LOCATION_ID = <?php echo $isEdit ? (int)$editVoucher['location_id'] : 0; ?>;

// Select2 for the dropdowns. Same client-side pattern as the reports (options
// are already rendered server-side) — jQuery + Select2 come from the footer, so
// callers must run after DOMContentLoaded. Returns true when Select2 took over.
function initSelect2(selectElement, allowClear) {
    if (!selectElement || !window.jQuery || !$.fn.select2) return false;
    const $el = $(selectElement);
    $el.select2({
        placeholder: $el.find('option[value=""]').text(),
        allowClear: !!allowClear,
        width: '100%'
    });
    return true;
}

function initProductSelect2(selectElement) {
    return initSelect2(selectElement, false);
}

function addRow() {
    const tbody = document.getElementById('consumptionItems');
    const rowCount = tbody.querySelectorAll('.item-row').length + 1;

    let productOptions = '<option value=""><?php echo __('select_product'); ?></option>';
    productsData.forEach(p => {
        productOptions += `<option value="${p.id}">${escapeHtml(p.name)} (${p.unit})</option>`;
    });

    const newRow = document.createElement('tr');
    newRow.className = 'item-row';
    newRow.innerHTML = `
        <td class="row-number">${rowCount}</td>
        <td>
            <select name="products[]" class="form-select form-select-sm product-select" required onchange="updateCurrentStock(this)">
                ${productOptions}
            </select>
        </td>
        <td>
            <input type="text" class="form-control form-control-sm stock-display text-end" readonly value="-" tabindex="-1">
        </td>
        <td>
            <input type="number" name="quantity[]" step="0.001" min="0.001"
                   class="form-control form-control-sm qty-input" required>
        </td>
        <td>
            <input type="text" name="item_remarks[]" class="form-control form-control-sm" placeholder="<?php echo __('optional'); ?>">
        </td>
        <td class="text-center">
            <button type="button" class="btn btn-sm btn-danger" onclick="removeRow(this)">
                <i class="bi bi-trash"></i>
            </button>
        </td>
    `;

    tbody.appendChild(newRow);
    initProductSelect2(newRow.querySelector('.product-select'));
}

function removeRow(btn) {
    const tbody = document.getElementById('consumptionItems');
    if (tbody.querySelectorAll('.item-row').length > 1) {
        btn.closest('tr').remove();
        renumberRows();
    } else {
        alert('<?php echo __('at_least_one_item_required'); ?>');
    }
}

function renumberRows() {
    document.querySelectorAll('#consumptionItems .item-row').forEach((row, index) => {
        row.querySelector('.row-number').textContent = index + 1;
    });
}

function updateCurrentStock(selectElement) {
    const row = selectElement.closest('tr');
    const stockDisplay = row.querySelector('.stock-display');
    const productId = selectElement.value;
    const locationId = document.getElementById('locationId').value;

    if (!productId || !locationId) {
        stockDisplay.value = '-';
        return;
    }

    const cacheKey = `${productId}_${locationId}`;
    if (stockCache[cacheKey] !== undefined) {
        stockDisplay.value = stockCache[cacheKey];
        return;
    }

    stockDisplay.value = '...';

    // Fetch stock via AJAX using existing stock-check API
    fetch(`<?php echo BASE_URL; ?>api/stock-check.php?product_ids=${productId}&location_id=${locationId}`)
        .then(r => r.json())
        .then(data => {
            if (data.success && data.stock[productId]) {
                let available = parseFloat(data.stock[productId].available_stock);
                if (parseInt(locationId, 10) === OWN_LOCATION_ID && OWN_CONSUMPTION[productId]) {
                    available += parseFloat(OWN_CONSUMPTION[productId]);
                }
                const qty = available.toFixed(3);
                stockCache[cacheKey] = qty;
                stockDisplay.value = qty;
            } else {
                stockDisplay.value = '-';
            }
        })
        .catch(() => {
            stockDisplay.value = '-';
        });
}

// Refresh all stock displays when location changes
function onLocationChange() {
    stockCache = {};
    document.querySelectorAll('.product-select').forEach(select => {
        if (select.value) {
            updateCurrentStock(select);
        } else {
            select.closest('tr').querySelector('.stock-display').value = '-';
        }
    });
}

// Native listener — the fallback path when Select2 fails to load. Select2 fires
// `change` through jQuery, which does not reach addEventListener handlers, so
// the Select2 branch below rebinds this with jQuery instead (never both).
document.getElementById('locationId').addEventListener('change', onLocationChange);

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Currency handling
const defaultCurrencyId = <?php echo $defaultCurrency ? $defaultCurrency['id'] : 'null'; ?>;

function onCurrencyChange() {
    const select = document.getElementById('currencyId');
    const currId = parseInt(select.value);
    const exchangeRateGroup = document.getElementById('exchangeRateGroup');

    if (currId === defaultCurrencyId) {
        exchangeRateGroup.style.display = 'none';
        document.getElementById('exchangeRateToBase').value = '1';
    } else {
        exchangeRateGroup.style.display = '';
        fetchExchangeRate(currId);
    }
}

async function fetchExchangeRate(currencyId) {
    const vDate = document.getElementById('voucherDate').value || new Date().toISOString().split('T')[0];
    try {
        const url = `<?php echo BASE_URL; ?>api/exchange-rates.php?from_currency_id=${currencyId}&to_currency_id=${defaultCurrencyId}&_=${Date.now()}`;
        const response = await fetch(url);
        if (response.ok) {
            const result = await response.json();
            if (result.success && result.exchange_rates && result.exchange_rates.length > 0) {
                const applicable = result.exchange_rates.filter(r => r.effective_date <= vDate);
                if (applicable.length > 0) {
                    document.getElementById('exchangeRateToBase').value = applicable[0].rate;
                    document.getElementById('exchangeRateHint').textContent =
                        `1 ${applicable[0].from_currency_code} = ${applicable[0].rate} ${applicable[0].to_currency_code}`;
                    return;
                }
            }
            const revUrl = `<?php echo BASE_URL; ?>api/exchange-rates.php?from_currency_id=${defaultCurrencyId}&to_currency_id=${currencyId}&_=${Date.now()}`;
            const revResponse = await fetch(revUrl);
            if (revResponse.ok) {
                const revResult = await revResponse.json();
                if (revResult.success && revResult.exchange_rates && revResult.exchange_rates.length > 0) {
                    const applicable = revResult.exchange_rates.filter(r => r.effective_date <= vDate);
                    if (applicable.length > 0 && parseFloat(applicable[0].rate) !== 0) {
                        const reverseRate = (1 / parseFloat(applicable[0].rate)).toFixed(8);
                        document.getElementById('exchangeRateToBase').value = reverseRate;
                        document.getElementById('exchangeRateHint').textContent =
                            `1 ${applicable[0].to_currency_code} = ${reverseRate} ${applicable[0].from_currency_code} (reverse)`;
                        return;
                    }
                }
            }
        }
    } catch (error) {
        console.error('Failed to fetch exchange rate:', error);
    }
    document.getElementById('exchangeRateHint').textContent = 'No rate found - please enter manually';
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('#consumptionItems .product-select').forEach(initProductSelect2);
    if (initSelect2(document.getElementById('locationId'), false)) {
        $('#locationId').on('change', onLocationChange);
    }
    initSelect2(document.getElementById('costCentreId'), true);

    // Prefilled rows (edit, or a form redisplayed after an error) show their stock
    document.querySelectorAll('#consumptionItems .product-select').forEach(select => {
        if (select.value) updateCurrentStock(select);
    });
});
</script>

<script src="<?php echo BASE_URL; ?>assets/js/signature_pad.js"></script>
<script>
// Project Manager signature pad. Mirrors the Prepared By pad on
// requisition_voucher.php; the shared upload control drives the same pad via
// SignaturePad.forCanvas(), so a picked image and a drawn stroke both end up
// in the same hidden input.
let pmSigPad = null;
const existingPmSigDataCV = <?php echo json_encode($formPmSig); ?>;

function clearPmSignature() {
    if (pmSigPad) {
        pmSigPad.clear();
        const ph = document.getElementById('pmSignaturePlaceholder');
        if (ph) ph.style.display = '';
        // Clear the stored value too, otherwise a signature removed on screen
        // would be silently re-posted from the hidden input.
        const input = document.getElementById('pmSignatureInput');
        if (input) input.value = '';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const canvas = document.getElementById('pmSignature');
    if (canvas && window.SignaturePad) {
        pmSigPad = new SignaturePad(canvas, { penColor: '#000', lineWidth: 2 });
        if (existingPmSigDataCV) {
            pmSigPad.fromDataURL(existingPmSigDataCV);
            const ph = document.getElementById('pmSignaturePlaceholder');
            if (ph) ph.style.display = 'none';
        }
        canvas.addEventListener('pointerdown', function() {
            const ph = document.getElementById('pmSignaturePlaceholder');
            if (ph) ph.style.display = 'none';
        });
    }
});

// Capture whatever is on the pad into the hidden input just before submit.
// An empty pad leaves the input alone: on edit it still carries the signature
// already on the voucher, so an ordinary re-save never drops it.
document.getElementById('consumptionForm')?.addEventListener('submit', function() {
    const input = document.getElementById('pmSignatureInput');
    if (input && pmSigPad && !pmSigPad.isEmpty()) {
        input.value = pmSigPad.toDataURL();
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>
