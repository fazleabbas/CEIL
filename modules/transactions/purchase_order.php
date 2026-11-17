<?php
/**
 * Create / edit a Purchase Order (PO) — a commitment document sent to a
 * supplier. A PO never touches stock or the GL; only the Purchase(s) raised
 * from it do. Direct POs are saved as drafts and go through approval
 * (draft -> pending_approval -> approved) before they can be converted.
 *
 * Only draft POs may be edited here; lifecycle actions (send for approval,
 * approve, reject, cancel) live on purchase_order_view.php via
 * api/purchase-orders-sync.php.
 */
require_once '../../config/database.php';
require_once '../../includes/functions.php';

$isEdit = isset($_GET['id']) && !empty($_GET['id']);
$pageTitle = $isEdit ? __('edit_purchase_order') : __('new_purchase_order');

if (!hasPermission('purchase_orders', $isEdit ? 'edit' : 'create')) {
    setAlert(__('no_permission'), 'danger');
    redirect('');
}

$po = [
    'id' => '',
    'po_date' => date('Y-m-d'),
    'expected_date' => '',
    'supplier_id' => '',
    'location_id' => '',
    'cost_centre_id' => '',
    'currency_id' => '',
    'exchange_rate_to_base' => 1,
    'vat_amount' => 0,
    'remarks' => '',
    // A NEW PO starts from the tenant's standard terms (Company Settings);
    // the user edits them per PO. An edited PO loads its own stored values
    // below and never re-reads the defaults.
    'payment_terms' => (string)getSetting('po_default_payment_terms', ''),
    'delivery_terms' => (string)getSetting('po_default_delivery_terms', ''),
    'status' => 'draft',
];
$poDetails = [];

if ($isEdit) {
    $id = (int)$_GET['id'];
    $stmt = $conn->prepare("SELECT * FROM purchase_orders WHERE id = ?");
    $stmt->execute([$id]);
    $po = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$po) {
        setAlert(__('po_not_found'), 'danger');
        redirect('modules/transactions/purchase_order_list.php');
    }
    if ($po['status'] !== 'draft') {
        setAlert(__('po_only_draft_edit'), 'warning');
        redirect('modules/transactions/purchase_order_view.php?id=' . $id);
    }

    $detStmt = $conn->prepare("
        SELECT pod.*, p.name AS product_name, p.unit
        FROM purchase_order_details pod
        JOIN products p ON pod.product_id = p.id
        WHERE pod.purchase_order_id = ?
        ORDER BY pod.id
    ");
    $detStmt->execute([$id]);
    $poDetails = $detStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Budget mode (?from_budget=<id>&supplier=<sid>, create only): the PO is raised
// from an APPROVED budget for one supplier. Header fields come from the budget,
// the supplier is locked, items prefill at the remaining-to-procure quantity
// (allocation minus purchases minus other open POs of this budget) and the
// budgeted rate. On save the PO goes straight to 'approved' — the budget's own
// approval covers it — and per-product quantities may not exceed the remaining.
$fromBudget = null;
$fromBudgetRemaining = []; // product_id => ['remaining' => float, 'rate' => float, 'name' => string]
$fromBudgetId = 0;
$fromBudgetSupplier = 0;
if (!$isEdit) {
    $fromBudgetId = (int)($_GET['from_budget'] ?? $_POST['from_budget'] ?? 0);
    $fromBudgetSupplier = (int)($_GET['supplier'] ?? $_POST['from_budget_supplier'] ?? 0);
    if ($fromBudgetId > 0 && $fromBudgetSupplier > 0) {
        $bStmt = $conn->prepare("SELECT * FROM budgets WHERE id = ? AND status = 'approved'");
        $bStmt->execute([$fromBudgetId]);
        $fromBudget = $bStmt->fetch(PDO::FETCH_ASSOC);
        if (!$fromBudget) {
            setAlert(__('budget_not_found'), 'danger');
            redirect('modules/transactions/budget_list.php');
        }

        // This supplier's 'purchase'-type allocations.
        // GROUPED BY PRODUCT — the same product may occupy several rows of this
        // budget for this supplier, while $createdMap / $poOutMap below are per
        // product. Ungrouped, the purchased and on-PO quantities would be
        // subtracted from each row separately and the per-product cap enforced
        // on POST would be wrong. Rate is quantity-weighted; VAT summed.
        $aStmt = $conn->prepare("
            SELECT bd.product_id,
                   SUM(bd.quantity) AS quantity,
                   ROUND(SUM(bd.quantity * bd.rate) / NULLIF(SUM(bd.quantity), 0), 2) AS rate,
                   SUM(bd.vat_amount) AS vat_amount,
                   p.name AS product_name, p.unit
            FROM budget_details bd
            JOIN products p ON bd.product_id = p.id
            WHERE bd.budget_id = ? AND bd.supplier_id = ? AND bd.procurement_type = 'purchase' AND p.is_active = 1
            GROUP BY bd.product_id, p.name, p.unit
            ORDER BY p.name
        ");
        $aStmt->execute([$fromBudgetId, $fromBudgetSupplier]);
        $allocs = $aStmt->fetchAll(PDO::FETCH_ASSOC);

        // Already purchased from this budget for the supplier.
        $createdStmt = $conn->prepare("
            SELECT pd.product_id, COALESCE(SUM(pd.quantity), 0) AS qty
            FROM purchase_details pd
            JOIN purchases pu ON pd.purchase_id = pu.id
            WHERE pu.budget_id = ? AND pu.supplier_id = ?
            GROUP BY pd.product_id
        ");
        $createdStmt->execute([$fromBudgetId, $fromBudgetSupplier]);
        $createdMap = [];
        foreach ($createdStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $createdMap[(int)$r['product_id']] = (float)$r['qty'];
        }

        // Outstanding on OTHER open POs of this budget for the supplier.
        $poOutMap = getBudgetPoOutstanding($fromBudgetId, $conn, $fromBudgetSupplier);

        foreach ($allocs as $a) {
            $pid = (int)$a['product_id'];
            $rem = (float)$a['quantity'] - ($createdMap[$pid] ?? 0) - ($poOutMap[$pid] ?? 0);
            if ($rem < 0) $rem = 0;
            $fromBudgetRemaining[$pid] = [
                'remaining' => $rem,
                'rate'      => (float)$a['rate'],
                'name'      => $a['product_name'],
                'unit'      => $a['unit'],
                // Budget VAT for the quantity this PO will cover (migration 0044).
                'vat'       => budgetLineVatForQty($a['vat_amount'] ?? 0, $a['quantity'], $rem),
            ];
        }

        // Preset header fields + prefill line items (remaining > 0 only).
        $po['supplier_id'] = $fromBudgetSupplier;
        $po['location_id'] = (int)$fromBudget['location_id'];
        $po['cost_centre_id'] = $fromBudget['cost_centre_id'] ? (int)$fromBudget['cost_centre_id'] : '';
        // Sum the budget's per-line VAT into the PO's header VAT box — purchase
        // orders have no per-line VAT column. Renders straight into the existing
        // vat_amount input, so no JS change is needed here.
        $fromBudgetVat = 0;
        foreach ($fromBudgetRemaining as $r) {
            if ($r['remaining'] <= 0.0005) continue;
            $fromBudgetVat += $r['vat'];
        }
        if ($fromBudgetVat > 0) {
            $po['vat_amount'] = round($fromBudgetVat, 2);
        }

        foreach ($fromBudgetRemaining as $pid => $r) {
            if ($r['remaining'] <= 0.0005) continue;
            $poDetails[] = [
                'product_id'    => $pid,
                'product_name'  => $r['name'],
                'unit'          => $r['unit'],
                'quantity'      => $r['remaining'],
                'rate_per_unit' => $r['rate'],
                'remarks'       => '',
            ];
        }
        if (empty($poDetails)) {
            setAlert(__('po_budget_nothing_remaining'), 'warning');
            redirect('modules/transactions/budget_view.php?id=' . $fromBudgetId);
        }
    } else {
        $fromBudgetId = 0;
        $fromBudgetSupplier = 0;
    }
}

// Active suppliers (name lives on the linked ledger account).
$supStmt = $conn->query("
    SELECT s.id, la.name AS name
    FROM suppliers s
    LEFT JOIN ledger_accounts la ON s.ledger_account_id = la.id
    WHERE s.is_active = 1
    ORDER BY la.name
");
$suppliers = $supStmt->fetchAll(PDO::FETCH_ASSOC);

// Active currencies; the default one is preselected for new POs.
$currencies = $conn->query("SELECT id, code, name, is_default FROM currencies WHERE is_active = 1 ORDER BY is_default DESC, code")->fetchAll(PDO::FETCH_ASSOC);
$defaultCurrencyId = 0;
foreach ($currencies as $c) {
    if ((int)$c['is_default'] === 1) { $defaultCurrencyId = (int)$c['id']; break; }
}

// Handle submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();

    $poDate = dbDate($_POST['po_date'] ?? date('Y-m-d'));
    $expectedDate = !empty($_POST['expected_date']) ? dbDate($_POST['expected_date']) : null;
    $supplierId = (int)($_POST['supplier_id'] ?? 0);
    $locationId = (int)($_POST['location_id'] ?? 0);
    $costCentreId = !empty($_POST['cost_centre_id']) ? (int)$_POST['cost_centre_id'] : null;
    $currencyId = !empty($_POST['currency_id']) ? (int)$_POST['currency_id'] : ($defaultCurrencyId ?: null);
    $exchangeRate = floatval($_POST['exchange_rate_to_base'] ?? 1);
    if ($exchangeRate <= 0) $exchangeRate = 1;
    $vatAmount = round(floatval($_POST['vat_amount'] ?? 0), 2);
    if ($vatAmount < 0) $vatAmount = 0;
    $remarks = clean($_POST['remarks'] ?? '');
    // Stored raw (cleanText, not clean) and escaped on output, so terms like
    // "50% advance & balance on delivery" are not saved HTML-encoded.
    $paymentTerms = cleanText($_POST['payment_terms'] ?? '');
    $deliveryTerms = cleanText($_POST['delivery_terms'] ?? '');
    $paymentTerms = $paymentTerms !== '' ? $paymentTerms : null;
    $deliveryTerms = $deliveryTerms !== '' ? $deliveryTerms : null;
    $editId = !empty($_POST['edit_id']) ? (int)$_POST['edit_id'] : null;

    $products = $_POST['products'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $rates = $_POST['rate'] ?? [];
    $itemRemarks = $_POST['item_remarks'] ?? [];

    $errors = [];

    // Budget mode: the supplier is fixed by the budget allocation group.
    if ($fromBudget) {
        $supplierId = $fromBudgetSupplier;
    }

    if ($supplierId <= 0) $errors[] = __('please_select_supplier', 'Please select a supplier');
    if ($locationId <= 0) {
        $errors[] = __('please_select_location');
    } elseif (!canAccessLocation($locationId)) {
        $errors[] = __('no_location_access', 'You do not have access to the selected location');
    }
    if (empty($poDate)) $errors[] = __('please_select_date');

    // Normalize line items; total is computed server-side from the lines.
    $lines = [];
    $totalAmount = 0;
    foreach ($products as $index => $productId) {
        $productId = (int)$productId;
        $qty = floatval($quantities[$index] ?? 0);
        $rate = floatval($rates[$index] ?? 0);
        if ($productId <= 0 || $qty <= 0) continue;
        if ($rate < 0) $rate = 0;
        $qty = round($qty, 3);
        $rate = round($rate, 2);
        $lines[] = [
            'product_id' => $productId,
            'quantity' => $qty,
            'rate' => $rate,
            'remarks' => clean($itemRemarks[$index] ?? ''),
        ];
        $totalAmount += $qty * $rate;
    }
    $totalAmount = round($totalAmount, 2);

    if (empty($lines)) $errors[] = __('please_add_at_least_one_item');

    // Budget mode: every line must be a budgeted product for this supplier and
    // the per-product total may not exceed the remaining-to-procure quantity.
    if ($fromBudget && empty($errors)) {
        $perProduct = [];
        foreach ($lines as $ln) {
            $perProduct[$ln['product_id']] = ($perProduct[$ln['product_id']] ?? 0) + $ln['quantity'];
        }
        $fmtQty = function ($n) { return rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.'); };
        foreach ($perProduct as $pid => $qty) {
            if (!isset($fromBudgetRemaining[$pid])) {
                $pn = $conn->prepare("SELECT name FROM products WHERE id = ?");
                $pn->execute([$pid]);
                $errors[] = sprintf(__('po_budget_product_not_allocated'), $pn->fetchColumn() ?: ('#' . $pid));
                continue;
            }
            $cap = $fromBudgetRemaining[$pid]['remaining'];
            if ($qty > $cap + 0.0005) {
                $errors[] = sprintf(__('po_budget_over_remaining'), $fromBudgetRemaining[$pid]['name'], $fmtQty($qty), $fmtQty(max($cap, 0)));
            }
        }
    }

    if (empty($errors)) {
        try {
            $conn->beginTransaction();

            if ($editId) {
                // Re-check draft status inside the transaction.
                $chk = $conn->prepare("SELECT status FROM purchase_orders WHERE id = ?");
                $chk->execute([$editId]);
                if ($chk->fetchColumn() !== 'draft') {
                    throw new Exception(__('po_only_draft_edit'));
                }
                $upd = $conn->prepare("
                    UPDATE purchase_orders SET
                        po_date = ?, expected_date = ?, supplier_id = ?, location_id = ?,
                        cost_centre_id = ?, currency_id = ?, exchange_rate_to_base = ?,
                        total_amount = ?, vat_amount = ?, remarks = ?,
                        payment_terms = ?, delivery_terms = ?
                    WHERE id = ?
                ");
                $upd->execute([
                    $poDate, $expectedDate, $supplierId, $locationId,
                    $costCentreId, $currencyId, $exchangeRate,
                    $totalAmount, $vatAmount, $remarks,
                    $paymentTerms, $deliveryTerms, $editId,
                ]);
                $conn->prepare("DELETE FROM purchase_order_details WHERE purchase_order_id = ?")->execute([$editId]);
                $poId = $editId;
            } elseif ($fromBudget) {
                // Budget-raised PO: born approved — the budget's approval
                // covers it. Links back via budget_id.
                $poNumber = getNextNumber('purchase_orders', 'po_number', getSetting('po_prefix', 'PO'));

                // WHO gets credited as approver.
                //
                // This used to stamp the CREATOR into approved_by (and their
                // signature into approved_by_signature), which made the document
                // assert that they approved it — even though raising a budget PO
                // needs no `approve` permission and they typically have none.
                // The authority actually comes from whoever approved the BUDGET,
                // so that is who is recorded. The creator still owns
                // prepared_by_signature, which is true.
                //
                // Falls back to NULL rather than to the creator: a budget in
                // 'approved' status should always have an approver, but if the
                // data is odd it is better to name nobody than the wrong person.
                $budgetApproverId  = !empty($fromBudget['approved_by']) ? (int)$fromBudget['approved_by'] : null;
                $budgetApproverSig = $budgetApproverId ? getUserSignature($conn, $budgetApproverId) : null;
                $creatorSig        = getUserSignature($conn, getUserId());

                $ins = $conn->prepare("
                    INSERT INTO purchase_orders
                    (po_number, po_date, expected_date, supplier_id, location_id, cost_centre_id,
                     currency_id, exchange_rate_to_base, total_amount, vat_amount, status, budget_id,
                     remarks, payment_terms, delivery_terms,
                     created_by, prepared_by_signature, approved_by, approved_at, approved_by_signature)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved', ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
                ");
                $ins->execute([
                    $poNumber, $poDate, $expectedDate, $supplierId, $locationId, $costCentreId,
                    $currencyId, $exchangeRate, $totalAmount, $vatAmount, $fromBudgetId,
                    $remarks, $paymentTerms, $deliveryTerms, getUserId(), $creatorSig, $budgetApproverId, $budgetApproverSig,
                ]);
                $poId = $conn->lastInsertId();
            } else {
                $poNumber = getNextNumber('purchase_orders', 'po_number', getSetting('po_prefix', 'PO'));
                $ins = $conn->prepare("
                    INSERT INTO purchase_orders
                    (po_number, po_date, expected_date, supplier_id, location_id, cost_centre_id,
                     currency_id, exchange_rate_to_base, total_amount, vat_amount, status, remarks,
                     payment_terms, delivery_terms, created_by, prepared_by_signature)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?)
                ");
                $ins->execute([
                    $poNumber, $poDate, $expectedDate, $supplierId, $locationId, $costCentreId,
                    $currencyId, $exchangeRate, $totalAmount, $vatAmount, $remarks,
                    $paymentTerms, $deliveryTerms, getUserId(),
                    // No pad on this form — fill from the creator's signature on file.
                    getUserSignature($conn, getUserId()),
                ]);
                $poId = $conn->lastInsertId();
            }

            $detStmt = $conn->prepare("
                INSERT INTO purchase_order_details
                (purchase_order_id, product_id, quantity, rate_per_unit, remarks)
                VALUES (?, ?, ?, ?, ?)
            ");
            foreach ($lines as $ln) {
                $detStmt->execute([$poId, $ln['product_id'], $ln['quantity'], $ln['rate'], $ln['remarks'] !== '' ? $ln['remarks'] : null]);
            }

            $conn->commit();
            logAudit('purchase_order', $editId ? 'update' : 'create', $poId);
            setAlert($editId ? __('purchase_order_updated') : __('purchase_order_created'), 'success');
            redirect('modules/transactions/purchase_order_view.php?id=' . $poId);

        } catch (PDOException $e) {
            $conn->rollBack();
            $errors[] = sprintf(__('database_error'), $e->getMessage());
        } catch (Exception $e) {
            $conn->rollBack();
            $errors[] = $e->getMessage();
        }
    }

    foreach ($errors as $e) setAlert($e, 'danger');
}

require_once '../../includes/header.php';

$locations = getUserLocations();
$costCentres = getUserCostCentres();
?>

<div class="row justify-content-center">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="bi bi-file-earmark-text"></i>
                    <?php echo $isEdit ? __('edit_purchase_order') : __('new_purchase_order'); ?>
                </h5>
                <div>
                    <a href="purchase_order_list.php" class="btn btn-sm btn-secondary">
                        <i class="bi bi-list"></i> <?php _e('list'); ?>
                    </a>
                </div>
            </div>
            <div class="card-body">
                <?php if ($fromBudget): ?>
                <div class="alert alert-info">
                    <i class="bi bi-cash-coin"></i>
                    <?php _e('po_from_budget_hint'); ?>
                    <a href="budget_view.php?id=<?php echo $fromBudgetId; ?>" class="fw-bold">
                        <?php echo htmlspecialchars($fromBudget['budget_number']); ?>
                    </a>
                </div>
                <?php endif; ?>
                <form method="POST" id="poForm">
                    <?php echo csrfField(); ?>
                    <?php if ($isEdit): ?>
                    <input type="hidden" name="edit_id" value="<?php echo (int)$po['id']; ?>">
                    <?php endif; ?>
                    <?php if ($fromBudget): ?>
                    <input type="hidden" name="from_budget" value="<?php echo $fromBudgetId; ?>">
                    <input type="hidden" name="from_budget_supplier" value="<?php echo $fromBudgetSupplier; ?>">
                    <?php endif; ?>

                    <div class="row mb-3">
                        <div class="col-md-3">
                            <label class="form-label required">
                                <?php _e('supplier'); ?>
                                <?php if ($fromBudget): ?>
                                <span class="badge bg-secondary ms-1"><i class="bi bi-lock-fill"></i></span>
                                <?php endif; ?>
                            </label>
                            <select name="supplier_id" id="supplierId" class="form-select" <?php echo $fromBudget ? 'disabled' : 'required'; ?>>
                                <option value=""><?php _e('select_supplier'); ?></option>
                                <?php foreach ($suppliers as $s): ?>
                                <option value="<?php echo (int)$s['id']; ?>" <?php echo ((string)$po['supplier_id'] === (string)$s['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($s['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label required"><?php _e('location'); ?></label>
                            <select name="location_id" class="form-select" required>
                                <option value=""><?php _e('select_location'); ?></option>
                                <?= renderTreeOptions($locations, $po['location_id']) ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><?php _e('cost_centre'); ?></label>
                            <select name="cost_centre_id" class="form-select">
                                <option value=""><?php _e('select_cost_centre'); ?></option>
                                <?= renderTreeOptions($costCentres, $po['cost_centre_id'] ?? null, null, 'name', 'code') ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label required"><?php _e('po_date'); ?></label>
                            <input type="date" name="po_date" class="form-control"
                                   value="<?php echo htmlspecialchars($po['po_date']); ?>" required>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-3">
                            <label class="form-label"><?php _e('expected_delivery_date'); ?></label>
                            <input type="date" name="expected_date" class="form-control"
                                   value="<?php echo htmlspecialchars($po['expected_date'] ?? ''); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><?php _e('currency'); ?></label>
                            <select name="currency_id" id="currencyId" class="form-select">
                                <?php foreach ($currencies as $c):
                                    $selected = $po['currency_id']
                                        ? ((string)$po['currency_id'] === (string)$c['id'])
                                        : ((int)$c['is_default'] === 1);
                                ?>
                                <option value="<?php echo (int)$c['id']; ?>" <?php echo $selected ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($c['code'] . ' - ' . $c['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3" id="exchangeRateGroup" style="display:none;">
                            <label class="form-label"><?php _e('exchange_rate_to_base'); ?></label>
                            <input type="number" name="exchange_rate_to_base" id="exchangeRateToBase" class="form-control"
                                   step="0.00000001" min="0.00000001"
                                   value="<?php echo htmlspecialchars($po['exchange_rate_to_base'] ?: 1); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><?php _e('remarks'); ?></label>
                            <textarea name="remarks" class="form-control" rows="1"
                                      placeholder="<?php echo __('optional'); ?>"><?php echo htmlspecialchars($po['remarks'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <div class="row mb-4">
                        <div class="col-md-6">
                            <label class="form-label"><?php _e('payment_terms'); ?></label>
                            <textarea name="payment_terms" class="form-control" rows="3"
                                      placeholder="<?php echo __('optional'); ?>"><?php echo htmlspecialchars($po['payment_terms'] ?? ''); ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php _e('delivery_terms'); ?></label>
                            <textarea name="delivery_terms" class="form-control" rows="3"
                                      placeholder="<?php echo __('optional'); ?>"><?php echo htmlspecialchars($po['delivery_terms'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <h6 class="text-muted mb-3"><?php _e('po_items'); ?></h6>

                    <div class="table-responsive">
                        <table class="table table-bordered table-sm" id="poTable">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 5%;"><?php _e('sr'); ?></th>
                                    <th style="width: 35%;"><?php _e('product'); ?></th>
                                    <th style="width: 12%;" class="text-end"><?php _e('quantity'); ?></th>
                                    <th style="width: 14%;" class="text-end"><?php _e('rate'); ?></th>
                                    <th style="width: 14%;" class="text-end"><?php _e('amount'); ?></th>
                                    <th style="width: 15%;"><?php _e('remarks'); ?></th>
                                    <th style="width: 5%;"><?php _e('action'); ?></th>
                                </tr>
                            </thead>
                            <tbody id="poItems">
                                <?php if (!empty($poDetails)): ?>
                                    <?php foreach ($poDetails as $index => $detail): ?>
                                <tr class="item-row">
                                    <td class="row-number"><?php echo $index + 1; ?></td>
                                    <td>
                                        <select name="products[]" class="form-select form-select-sm product-select" required>
                                            <option value="<?php echo (int)$detail['product_id']; ?>" selected>
                                                <?php echo htmlspecialchars($detail['product_name']); ?> (<?php echo htmlspecialchars($detail['unit']); ?>)
                                            </option>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="number" name="quantity[]" step="0.001" min="0.001"
                                               class="form-control form-control-sm text-end qty-input"
                                               value="<?php echo htmlspecialchars($detail['quantity']); ?>" required>
                                    </td>
                                    <td>
                                        <input type="number" name="rate[]" step="0.01" min="0"
                                               class="form-control form-control-sm text-end rate-input"
                                               value="<?php echo htmlspecialchars($detail['rate_per_unit']); ?>">
                                    </td>
                                    <td class="text-end line-amount align-middle">0.00</td>
                                    <td>
                                        <input type="text" name="item_remarks[]" class="form-control form-control-sm"
                                               value="<?php echo htmlspecialchars($detail['remarks'] ?? ''); ?>"
                                               placeholder="<?php echo __('optional'); ?>">
                                    </td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-danger" onclick="removeRow(this)">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                <tr class="item-row">
                                    <td class="row-number">1</td>
                                    <td>
                                        <select name="products[]" class="form-select form-select-sm product-select" required>
                                            <option value=""></option>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="number" name="quantity[]" step="0.001" min="0.001"
                                               class="form-control form-control-sm text-end qty-input" required>
                                    </td>
                                    <td>
                                        <input type="number" name="rate[]" step="0.01" min="0"
                                               class="form-control form-control-sm text-end rate-input">
                                    </td>
                                    <td class="text-end line-amount align-middle">0.00</td>
                                    <td>
                                        <input type="text" name="item_remarks[]" class="form-control form-control-sm" placeholder="<?php echo __('optional'); ?>">
                                    </td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-danger" onclick="removeRow(this)">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="7">
                                        <button type="button" class="btn btn-sm btn-primary" onclick="addRow()">
                                            <i class="bi bi-plus"></i> <?php _e('add_row'); ?>
                                        </button>
                                    </td>
                                </tr>
                                <tr>
                                    <th colspan="4" class="text-end"><?php _e('total_amount'); ?>:</th>
                                    <th class="text-end" id="poTotalAmount">0.00</th>
                                    <th colspan="2"></th>
                                </tr>
                                <tr>
                                    <th colspan="4" class="text-end"><?php _e('vat_amount'); ?>:</th>
                                    <th>
                                        <div class="d-flex align-items-center justify-content-end gap-2">
                                            <div class="form-check form-switch mb-0">
                                                <input type="checkbox" id="vatPctToggle" class="form-check-input" onchange="onVatToggleChange()">
                                                <label for="vatPctToggle" class="form-check-label small">%</label>
                                            </div>
                                            <input type="number" id="vatPct" class="form-control form-control-sm text-end"
                                                   style="width: 80px; display: none;" step="0.01" min="0" max="100"
                                                   oninput="onVatPctInput()">
                                            <input type="number" name="vat_amount" id="vatAmount"
                                                   class="form-control form-control-sm text-end" style="width: 130px;"
                                                   step="0.01" min="0" value="<?php echo htmlspecialchars($po['vat_amount'] ?: 0); ?>"
                                                   oninput="recalcTotals()">
                                        </div>
                                    </th>
                                    <th colspan="2"></th>
                                </tr>
                                <tr>
                                    <th colspan="4" class="text-end"><?php _e('payable_amount'); ?>:</th>
                                    <th class="text-end" id="poPayableAmount">0.00</th>
                                    <th colspan="2"></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <div class="d-flex justify-content-end mt-4">
                        <a href="purchase_order_list.php" class="btn btn-secondary me-2"><?php _e('cancel'); ?></a>
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check-circle"></i> <?php _e('save_purchase_order'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

<script>
const PRODUCT_SEARCH_URL = BASE_URL + 'api/products-search.php';
const PRODUCT_PLACEHOLDER = '<?php echo addslashes(__('select_product')); ?>';
const DEFAULT_CURRENCY_ID = <?php echo (int)$defaultCurrencyId; ?>;
// Budget mode: per-product remaining caps (product_id -> qty). Empty object
// outside budget mode. Mirrors the server-side validation, which is the
// authoritative check.
const FROM_BUDGET_CAPS = <?php
    $caps = [];
    foreach ($fromBudgetRemaining as $pid => $r) { $caps[$pid] = $r['remaining']; }
    echo json_encode((object)$caps);
?>;
const OVER_BUDGET_MSG = '<?php echo addslashes(__('po_budget_over_remaining')); ?>';

function initProductSelect2($el) {
    $el.select2({
        width: '100%',
        placeholder: PRODUCT_PLACEHOLDER,
        allowClear: false,
        minimumInputLength: 1,
        ajax: {
            url: PRODUCT_SEARCH_URL,
            dataType: 'json',
            delay: 250,
            data: function (params) {
                return { q: params.term || '', page: params.page || 1 };
            },
            processResults: function (data, params) {
                params.page = params.page || 1;
                return {
                    results: data.results || [],
                    pagination: { more: !!(data.pagination && data.pagination.more) }
                };
            },
            cache: true
        }
    });
}

function renumberRows() {
    document.querySelectorAll('#poItems .item-row').forEach((row, i) => {
        row.querySelector('.row-number').textContent = i + 1;
    });
}

function addRow() {
    const tbody = document.getElementById('poItems');
    const tr = document.createElement('tr');
    tr.className = 'item-row';
    tr.innerHTML = `
        <td class="row-number"></td>
        <td>
            <select name="products[]" class="form-select form-select-sm product-select" required>
                <option value=""></option>
            </select>
        </td>
        <td><input type="number" name="quantity[]" step="0.001" min="0.001" class="form-control form-control-sm text-end qty-input" required></td>
        <td><input type="number" name="rate[]" step="0.01" min="0" class="form-control form-control-sm text-end rate-input"></td>
        <td class="text-end line-amount align-middle">0.00</td>
        <td><input type="text" name="item_remarks[]" class="form-control form-control-sm" placeholder="<?php echo addslashes(__('optional')); ?>"></td>
        <td class="text-center">
            <button type="button" class="btn btn-sm btn-danger" onclick="removeRow(this)">
                <i class="bi bi-trash"></i>
            </button>
        </td>`;
    tbody.appendChild(tr);
    initProductSelect2($(tr).find('.product-select'));
    renumberRows();
}

function removeRow(btn) {
    const rows = document.querySelectorAll('#poItems .item-row');
    if (rows.length <= 1) {
        // Keep at least one row; just clear it.
        const row = btn.closest('tr');
        $(row).find('.product-select').val(null).trigger('change');
        row.querySelector('.qty-input').value = '';
        row.querySelector('.rate-input').value = '';
        row.querySelector('.line-amount').textContent = '0.00';
        recalcTotals();
        return;
    }
    btn.closest('tr').remove();
    renumberRows();
    recalcTotals();
}

function fmt2(n) {
    if (n === null || n === undefined || isNaN(n)) return '0.00';
    return parseFloat(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function goodsTotal() {
    let total = 0;
    document.querySelectorAll('#poItems .item-row').forEach(row => {
        const qty = parseFloat(row.querySelector('.qty-input').value) || 0;
        const rate = parseFloat(row.querySelector('.rate-input').value) || 0;
        const amt = qty * rate;
        row.querySelector('.line-amount').textContent = fmt2(amt);
        total += amt;
    });
    return total;
}

function recalcTotals() {
    const total = goodsTotal();
    const vat = parseFloat(document.getElementById('vatAmount').value) || 0;
    document.getElementById('poTotalAmount').textContent = fmt2(total);
    document.getElementById('poPayableAmount').textContent = fmt2(total + vat);
}

// VAT % toggle: when on, the flat amount is derived from the percentage.
function onVatToggleChange() {
    const on = document.getElementById('vatPctToggle').checked;
    const pctInput = document.getElementById('vatPct');
    const amtInput = document.getElementById('vatAmount');
    pctInput.style.display = on ? '' : 'none';
    amtInput.readOnly = on;
    if (on) onVatPctInput();
}

function onVatPctInput() {
    const pct = parseFloat(document.getElementById('vatPct').value);
    const amt = isNaN(pct) ? 0 : goodsTotal() * pct / 100;
    document.getElementById('vatAmount').value = amt.toFixed(2);
    recalcTotals();
}

// Exchange rate: hidden for the default currency (rate forced to 1);
// otherwise shown and auto-fetched from the exchange-rates API (best effort).
function onCurrencyChange(fetchRate) {
    const currencyId = parseInt(document.getElementById('currencyId').value || '0', 10);
    const group = document.getElementById('exchangeRateGroup');
    const rateInput = document.getElementById('exchangeRateToBase');
    if (!currencyId || currencyId === DEFAULT_CURRENCY_ID) {
        group.style.display = 'none';
        rateInput.value = '1';
        return;
    }
    group.style.display = '';
    if (!fetchRate) return;
    const poDate = document.querySelector('input[name="po_date"]').value || new Date().toISOString().split('T')[0];
    const url = `${BASE_URL}api/exchange-rates.php?from_currency_id=${currencyId}&to_currency_id=${DEFAULT_CURRENCY_ID}&_=${Date.now()}`;
    fetch(url)
        .then(r => r.json())
        .then(data => {
            const rates = (data && data.success && data.exchange_rates) ? data.exchange_rates : [];
            // Rates come sorted by effective_date DESC; take the latest on or before the PO date.
            const applicable = rates.filter(r => r.effective_date <= poDate);
            if (applicable.length > 0 && applicable[0].rate) {
                rateInput.value = applicable[0].rate;
            }
        })
        .catch(() => {});
}

document.addEventListener('input', function (e) {
    if (e.target.classList.contains('qty-input') || e.target.classList.contains('rate-input')) {
        if (document.getElementById('vatPctToggle').checked) {
            onVatPctInput();
        } else {
            recalcTotals();
        }
    }
});

// Budget mode: block submit when any product's total quantity exceeds its
// remaining cap (server re-validates authoritatively on save).
document.getElementById('poForm').addEventListener('submit', function (e) {
    const caps = FROM_BUDGET_CAPS;
    if (!caps || Object.keys(caps).length === 0) return;
    const perProduct = {};
    document.querySelectorAll('#poItems .item-row').forEach(row => {
        const sel = row.querySelector('.product-select');
        const pid = sel ? parseInt(sel.value, 10) : 0;
        if (!pid) return;
        perProduct[pid] = (perProduct[pid] || 0) + (parseFloat(row.querySelector('.qty-input').value) || 0);
    });
    for (const pid in perProduct) {
        const cap = (caps[pid] !== undefined) ? parseFloat(caps[pid]) : 0;
        if (perProduct[pid] > cap + 0.0005) {
            e.preventDefault();
            const row = Array.from(document.querySelectorAll('#poItems .product-select')).find(s => parseInt(s.value, 10) === parseInt(pid, 10));
            const name = row && row.selectedOptions.length ? row.selectedOptions[0].textContent.trim() : ('#' + pid);
            alert(OVER_BUDGET_MSG.replace('%s', name).replace('%s', perProduct[pid]).replace('%s', cap));
            return;
        }
    }
});

document.addEventListener('DOMContentLoaded', function () {
    $('#poItems .product-select').each(function () {
        initProductSelect2($(this));
    });
    $('#supplierId').select2({ width: '100%' });
    document.getElementById('currencyId').addEventListener('change', function () { onCurrencyChange(true); });
    onCurrencyChange(false);
    recalcTotals();
});
</script>
