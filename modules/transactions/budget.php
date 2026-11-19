<?php
/**
 * Create / edit a Budget — a supplier-allocation & pricing plan for an APPROVED
 * requisition voucher. Each requisition product can be split across one or more
 * suppliers (and a procurement type: purchase or import) with a quantity + rate.
 * Allocated qty per product may not exceed the remaining (approved minus what is
 * already budgeted in this requisition's other active budgets).
 */
require_once '../../config/database.php';
require_once '../../includes/functions.php';

$isEdit = isset($_GET['id']) && !empty($_GET['id']);
$pageTitle = $isEdit ? __('edit_budget') : __('new_budget');

// Creating still needs the 'budget' create permission. The EDIT decision is
// deferred to budgetCanEdit() below, which also admits the budget's own creator
// — it needs the loaded row to know who that is, so it cannot run up here.
if (!$isEdit && !hasPermission('budget', 'create')) {
    setAlert(__('no_permission'), 'danger');
    redirect('');
}

$budget = [
    'id' => '',
    'budget_date' => date('Y-m-d'),
    'requisition_id' => '',
    'location_id' => '',
    'cost_centre_id' => '',
    'remarks' => '',
    'status' => 'draft',
];
$requisitionId = 0;
$existingAllocations = []; // requisition_detail_id => [ {supplier_id,type,qty,rate}, ... ]
$existingExcessRemark = []; // product_id => reason stored for an over-allocated product

if ($isEdit) {
    $id = (int)$_GET['id'];
    $stmt = $conn->prepare("SELECT * FROM budgets WHERE id = ?");
    $stmt->execute([$id]);
    $budget = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$budget) {
        setAlert(__('budget_not_found'), 'danger');
        redirect('modules/transactions/budget_list.php');
    }
    // Editable: drafts and budgets still awaiting approval, or approved budgets
    // that have no purchases/imports/open POs yet. Reported separately from the
    // permission failure so the user learns WHY the door is shut.
    if (!in_array($budget['status'], ['draft', 'pending_approval', 'approved'], true)) {
        setAlert(__('budget_not_editable'), 'warning');
        redirect('modules/transactions/budget_view.php?id=' . $id);
    }
    if ($budget['status'] === 'approved' && budgetHasLinkedPurchases($id, $conn)) {
        setAlert(__('budget_locked_has_purchases'), 'warning');
        redirect('modules/transactions/budget_view.php?id=' . $id);
    }
    // 'budget' edit permission, or the person who raised this budget (pre-approval only).
    if (!budgetCanEdit($budget, $conn)) {
        setAlert(__('no_permission'), 'danger');
        redirect('modules/transactions/budget_view.php?id=' . $id);
    }
    $requisitionId = (int)$budget['requisition_id'];

    $allocStmt = $conn->prepare("SELECT * FROM budget_details WHERE budget_id = ? ORDER BY id");
    $allocStmt->execute([$id]);
    foreach ($allocStmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $existingAllocations[(int)$a['requisition_detail_id']][] = $a;
        // The excess remark is written to EVERY row of an over-allocated product,
        // so any one of them can hydrate the form. First non-empty wins.
        $apid = (int)$a['product_id'];
        if (!isset($existingExcessRemark[$apid]) && trim((string)($a['remarks'] ?? '')) !== '') {
            $existingExcessRemark[$apid] = (string)$a['remarks'];
        }
    }
} else {
    // New budget must be created from an approved requisition.
    $requisitionId = (int)($_GET['from_requisition'] ?? 0);
    if ($requisitionId <= 0) {
        setAlert(__('budget_requires_requisition'), 'danger');
        redirect('modules/transactions/requisition_voucher_list.php');
    }
}

// Load the requisition + its approved items.
$rvStmt = $conn->prepare("
    SELECT rv.*, l.name AS location_name, cc.name AS cost_centre_name, cc.code AS cost_centre_code
    FROM requisition_vouchers rv
    LEFT JOIN locations l ON rv.destination_location_id = l.id
    LEFT JOIN cost_centres cc ON rv.cost_centre_id = cc.id
    WHERE rv.id = ?
");
$rvStmt->execute([$requisitionId]);
$rv = $rvStmt->fetch(PDO::FETCH_ASSOC);

if (!$rv) {
    setAlert(__('requisition_not_found'), 'danger');
    redirect('modules/transactions/requisition_voucher_list.php');
}
if ($rv['status'] !== 'approved' && !$isEdit) {
    setAlert(__('budget_requisition_must_be_approved'), 'danger');
    redirect('modules/transactions/requisition_voucher_view.php?id=' . $requisitionId);
}

// Requisition lines with the effective (approved) quantity.
$rvdStmt = $conn->prepare("
    SELECT rvd.id, rvd.product_id,
           COALESCE(rvd.approved_quantity, rvd.requested_quantity) AS approved_qty,
           p.name AS product_name, p.unit
    FROM requisition_voucher_details rvd
    JOIN products p ON rvd.product_id = p.id
    WHERE rvd.requisition_id = ?
    ORDER BY rvd.id
");
$rvdStmt->execute([$requisitionId]);
$rvLines = $rvdStmt->fetchAll(PDO::FETCH_ASSOC);

// Already-budgeted per product in OTHER active budgets of this requisition.
$budgetedOther = getRequisitionBudgetedQty($requisitionId, $conn, $isEdit ? (int)$budget['id'] : 0);

// Per-PRODUCT aggregates. The same product can appear on SEVERAL requisition
// lines (8 requisitions on this tenant do), and the over-allocation rule has
// always been per product — summed across every line and every supplier row.
// The form used to render one independent block per line, each subtracting the
// product-wide $budgetedOther again, so for a duplicated product it understated
// remaining and disagreed with the check below. These maps are the single
// grain both sides now use.
$productApproved  = []; // product_id => approved qty summed across its lines
$productLineCount = []; // product_id => how many requisition lines carry it
foreach ($rvLines as $ln) {
    $lpid = (int)$ln['product_id'];
    $productApproved[$lpid]  = ($productApproved[$lpid] ?? 0) + (float)$ln['approved_qty'];
    $productLineCount[$lpid] = ($productLineCount[$lpid] ?? 0) + 1;
}
$productRemaining = []; // product_id => what this budget may take without a reason
foreach ($productApproved as $ppid => $pApproved) {
    $pRem = $pApproved - ($budgetedOther[$ppid] ?? 0);
    $productRemaining[$ppid] = $pRem > 0 ? $pRem : 0;
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

// Blacklisted suppliers (migration 0054) are not offered. One already on THIS
// budget stays in its dropdown, marked, so the row does not silently fall back
// to "Select supplier" and get dropped. The save then refuses it by name.
$blacklisted = getBlacklistedSuppliers($conn);
$onThisBudget = [];
foreach ($existingAllocations as $rows) {
    foreach ($rows as $a) $onThisBudget[(int)$a['supplier_id']] = true;
}
$budgetBlacklisted = array_intersect_key($blacklisted, $onThisBudget);
$suppliers = array_values(array_filter($suppliers, function ($s) use ($blacklisted, $onThisBudget) {
    $sid = (int)$s['id'];
    return !isset($blacklisted[$sid]) || isset($onThisBudget[$sid]);
}));

// Validation / save errors, rendered inline in the card body below. They are
// ALSO passed to setAlert() at the end of the POST block, but that goes nowhere
// on this form — getAlert() has no callers anywhere in the app — so without the
// inline block a failed save looks like a dead Save button.
$errors = [];

// Handle submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();

    $budgetDate = dbDate($_POST['budget_date'] ?? date('Y-m-d'));
    $remarks = clean($_POST['remarks'] ?? '');
    $editId = !empty($_POST['edit_id']) ? (int)$_POST['edit_id'] : null;

    $aSupplier = $_POST['alloc_supplier'] ?? [];
    $aType     = $_POST['alloc_type'] ?? [];
    $aQty      = $_POST['alloc_qty'] ?? [];
    $aRate     = $_POST['alloc_rate'] ?? [];
    $aVatPct   = $_POST['alloc_vat_pct'] ?? [];   // migration 0044
    $aVatAmt   = $_POST['alloc_vat_amt'] ?? [];
    // Keyed by PRODUCT id, not by row: the over-allocation it explains is a
    // property of the product total, not of any single supplier row.
    $aExcessRemark = $_POST['alloc_excess_remark'] ?? [];

    $errors = [];

    // Map requisition_detail_id => [product_id, approved_qty]
    $rdMap = [];
    foreach ($rvLines as $ln) {
        $rdMap[(int)$ln['id']] = [
            'product_id' => (int)$ln['product_id'],
            'approved'   => (float)$ln['approved_qty'],
            'name'       => $ln['product_name'],
        ];
    }

    // Build normalized allocations + validate.
    $allocs = [];
    $perProductAllocated = []; // product_id => qty in this budget (across ALL its rows)
    foreach ($aSupplier as $rdId => $supList) {
        $rdId = (int)$rdId;
        if (!isset($rdMap[$rdId])) continue;
        $productId = $rdMap[$rdId]['product_id'];
        $types = $aType[$rdId] ?? [];
        $qtys  = $aQty[$rdId] ?? [];
        $rates = $aRate[$rdId] ?? [];
        $vatPcts = $aVatPct[$rdId] ?? [];
        $vatAmts = $aVatAmt[$rdId] ?? [];

        foreach ($supList as $k => $sid) {
            $sid = (int)$sid;
            $qty = floatval($qtys[$k] ?? 0);
            $type = ($types[$k] ?? 'purchase') === 'import' ? 'import' : 'purchase';
            $rate = floatval($rates[$k] ?? 0);
            // No supplier means this is not an allocation at all, so the row is
            // skipped whatever else was typed into it — an unused row must never
            // block the save. This covers the blank row too.
            // It is not a silent loss: the form runs a confirm() before
            // submitting when such a row carries a rate or VAT, i.e. when it
            // holds something the user plainly meant to keep.
            if ($sid <= 0) continue;
            if ($qty <= 0) { $errors[] = sprintf(__('budget_alloc_needs_qty'), $rdMap[$rdId]['name']); continue; }
            if ($rate < 0) $rate = 0;

            // VAT (0044). The AMOUNT is authoritative — every total and every
            // downstream prefill reads it. The percent is kept only so the
            // amount can be re-derived if quantity or rate change later; it is
            // NULL when the user typed a flat amount instead.
            $vatPctRaw = trim((string)($vatPcts[$k] ?? ''));
            $vatPct = ($vatPctRaw === '') ? null : floatval($vatPctRaw);
            if ($vatPct !== null && ($vatPct < 0 || $vatPct > 100)) {
                $errors[] = sprintf(__('budget_alloc_bad_vat_pct'), $rdMap[$rdId]['name']);
                continue;
            }
            $vatAmt = floatval($vatAmts[$k] ?? 0);
            if ($vatAmt < 0) $vatAmt = 0;
            // Trust the percent when one is given: the browser already derived
            // the amount from it, and this keeps a stale amount (JS disabled,
            // or a hand-edited POST) from disagreeing with the stored percent.
            if ($vatPct !== null) {
                $vatAmt = round($qty * $rate * $vatPct / 100, 2);
            }

            // The same product + supplier + type MAY appear on several rows
            // (user-requested). Splitting one allocation across rows — different
            // rates, phases, or simply how the user lays the budget out — is
            // legitimate, and the over-allocation check below still counts the
            // product's rows TOGETHER, so nothing can be over-budgeted by
            // splitting. Every consumer of these rows aggregates per product
            // before doing its remaining-quantity arithmetic; see the GROUP BY
            // in getBudgetSupplierRemaining() and the three prefill queries.
            $perProductAllocated[$productId] = ($perProductAllocated[$productId] ?? 0) + $qty;
            $allocs[] = [
                'rd_id' => $rdId,
                'product_id' => $productId,
                'supplier_id' => $sid,
                'type' => $type,
                'qty' => $qty,
                'rate' => $rate,
                'vat_pct' => $vatPct,
                'vat_amt' => $vatAmt,
            ];
        }
    }

    // Over-allocation: allowed, but a product whose total exceeds the remaining
    // (approved - already budgeted elsewhere) MUST carry a written reason. This
    // used to be a flat rejection; the reason is now what stands in for the cap,
    // so an empty one is still refused. The client hides the box until a product
    // goes over, but it is never trusted — a hand-crafted POST lands here too.
    $excessRemarkByProduct = []; // product_id => reason, written to that product's rows
    foreach ($perProductAllocated as $productId => $alloc) {
        $remaining = $productRemaining[$productId] ?? 0;
        if ($alloc <= $remaining + 0.0005) continue;

        $fmt = function ($n) { return rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.'); };
        $pname = '';
        foreach ($rdMap as $rd) { if ($rd['product_id'] === $productId) { $pname = $rd['name']; break; } }

        $reason = clean((string)($aExcessRemark[$productId] ?? ''));
        if ($reason === '') {
            $errors[] = sprintf(__('budget_over_needs_reason'), $pname, $fmt($alloc), $fmt($remaining));
            continue;
        }
        $excessRemarkByProduct[$productId] = $reason;
    }

    if (empty($allocs)) {
        $errors[] = __('budget_needs_one_allocation');
    }

    // Blacklisted suppliers (0054). Checked here, not only in the dropdown, so a
    // hand-made POST is refused too.
    foreach (budgetBlacklistErrors(array_column($allocs, 'supplier_id'), $conn) as $be) {
        $errors[] = $be;
    }

    if (empty($errors)) {
        try {
            $conn->beginTransaction();

            if ($editId) {
                $chk = $conn->prepare("SELECT id, status, created_by, budget_number FROM budgets WHERE id = ?");
                $chk->execute([$editId]);
                $curRow = $chk->fetch(PDO::FETCH_ASSOC);
                $curStatus = $curRow ? $curRow['status'] : null;
                // Editable: drafts / pending approval, or approved budgets with no
                // purchases/imports/open POs yet. Re-checked inside the transaction to
                // guard against a purchase created mid-edit.
                if (!in_array($curStatus, ['draft', 'pending_approval', 'approved'], true)) {
                    throw new Exception(__('budget_not_editable'));
                }
                if ($curStatus === 'approved' && budgetHasLinkedPurchases($editId, $conn)) {
                    throw new Exception(__('budget_locked_has_purchases'));
                }
                // Re-run the permission gate against the row as it stands NOW, so a
                // hand-crafted POST cannot walk past the page-load check above.
                if (!budgetCanEdit($curRow, $conn)) {
                    throw new Exception(__('no_permission'));
                }

                // Editing a budget that is awaiting approval WITHDRAWS it back to
                // draft: the approver must never be able to sign off on figures that
                // changed under them after they were notified. An approved budget's
                // status is still intentionally left unchanged.
                $withdrawnFromApproval = ($curStatus === 'pending_approval');
                if ($withdrawnFromApproval) {
                    $upd = $conn->prepare("
                        UPDATE budgets
                        SET budget_date = ?, remarks = ?,
                            status = 'draft', sent_for_approval_by = NULL, sent_for_approval_at = NULL
                        WHERE id = ?
                    ");
                } else {
                    $upd = $conn->prepare("UPDATE budgets SET budget_date = ?, remarks = ? WHERE id = ?");
                }
                $upd->execute([$budgetDate, $remarks, $editId]);
                $conn->prepare("DELETE FROM budget_details WHERE budget_id = ?")->execute([$editId]);
                $budgetId = $editId;
            } else {
                $budgetNumber = getNextNumber('budgets', 'budget_number', getSetting('budget_prefix', 'BUD'));
                $ins = $conn->prepare("
                    INSERT INTO budgets (budget_number, budget_date, requisition_id, location_id, cost_centre_id, status, remarks, created_by, prepared_by_signature)
                    VALUES (?, ?, ?, ?, ?, 'draft', ?, ?, ?)
                ");
                $ins->execute([
                    $budgetNumber,
                    $budgetDate,
                    $requisitionId,
                    (int)$rv['destination_location_id'],
                    $rv['cost_centre_id'] ? (int)$rv['cost_centre_id'] : null,
                    $remarks,
                    getUserId(),
                    // There is no pad on this form — the column stayed empty
                    // until now. Fill it from the creator's signature on file.
                    getUserSignature($conn, getUserId()),
                ]);
                $budgetId = $conn->lastInsertId();
            }

            // remarks carries the over-allocation reason. It is written to EVERY row
            // of an over-allocated product on purpose: the rows of one product are
            // routinely aggregated (see the GROUP BY in getBudgetSupplierRemaining),
            // so hanging the reason off a single arbitrary row would lose it.
            // NULL for any product that stayed within its remaining quantity.
            $detStmt = $conn->prepare("
                INSERT INTO budget_details (budget_id, requisition_detail_id, product_id, supplier_id, procurement_type, quantity, rate, vat_percent, vat_amount, remarks)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($allocs as $a) {
                $detStmt->execute([$budgetId, $a['rd_id'], $a['product_id'], $a['supplier_id'], $a['type'], $a['qty'], $a['rate'], $a['vat_pct'], $a['vat_amt'],
                    $excessRemarkByProduct[$a['product_id']] ?? null]);
            }

            $conn->commit();
            logAudit('budget', $editId ? 'update' : 'create', $budgetId);

            if (!empty($withdrawnFromApproval)) {
                // Logged as its own action so a withdrawal is greppable rather than
                // hiding inside the ordinary 'update' rows.
                logAudit('budget', 'withdraw_from_approval', $budgetId);

                // The approvers were notified when this budget was sent. Tell them it
                // has been pulled back, so nobody chases a link that no longer has an
                // Approve button on it. Best-effort: a notification failure must not
                // undo the save that already committed above.
                try {
                    notifyApprovers(
                        'budget',
                        'budget_withdrawn',
                        __('budget_withdrawn_notify_title'),
                        sprintf(__('budget_withdrawn_notify_body'), $curRow['budget_number'] ?? ('#' . $budgetId)),
                        BASE_URL . 'modules/transactions/budget_view.php?id=' . $budgetId,
                        $budgetId,
                        'budget'
                    );
                } catch (Exception $ne) {
                    error_log('budget withdraw notify failed: ' . $ne->getMessage());
                }
            }

            setAlert($editId ? __('budget_updated') : __('budget_created'), 'success');
            redirect('modules/transactions/budget_view.php?id=' . $budgetId);

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

// Per-product avg cost (for default rate on new allocation rows).
$avgCostByProduct = [];
foreach ($rvLines as $ln) {
    $pid = (int)$ln['product_id'];
    if (!isset($avgCostByProduct[$pid])) {
        $avgCostByProduct[$pid] = getProductAvgCost($pid, (int)$rv['destination_location_id'], $budget['budget_date'], $conn);
    }
}
?>

<div class="row justify-content-center">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="bi bi-cash-coin"></i>
                    <?php echo $isEdit ? __('edit_budget') : __('new_budget'); ?>
                </h5>
                <div>
                    <a href="budget_list.php" class="btn btn-sm btn-secondary">
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

                <?php if (!empty($budgetBlacklisted) && empty($errors)): ?>
                <div class="alert alert-warning">
                    <i class="bi bi-slash-circle"></i> <strong><?php _e('budget_has_blacklisted_title'); ?></strong>
                    <ul class="mb-0 mt-1">
                        <?php foreach ($budgetBlacklisted as $bl): ?>
                        <li><?php echo htmlspecialchars($bl['name'] ?? '-'); ?>: <?php echo htmlspecialchars((string)($bl['reason'] ?? '')); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="small mt-1"><?php _e('budget_replace_blacklisted_hint'); ?></div>
                </div>
                <?php endif; ?>

                <?php if ($isEdit && ($budget['status'] ?? '') === 'pending_approval'): ?>
                <!-- Saving withdraws this budget from the approval queue. Say so BEFORE
                     they start typing, not in a toast once the status has already moved. -->
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i>
                    <?php _e('budget_edit_pending_reverts_hint'); ?>
                </div>
                <?php endif; ?>

                <form method="POST" id="budgetForm">
                    <?php echo csrfField(); ?>
                    <?php if ($isEdit): ?>
                    <input type="hidden" name="edit_id" value="<?php echo (int)$budget['id']; ?>">
                    <?php endif; ?>

                    <div class="row mb-3">
                        <div class="col-md-3">
                            <label class="form-label"><?php _e('requisition_voucher'); ?></label>
                            <div class="form-control bg-light">
                                <a href="requisition_voucher_view.php?id=<?php echo $requisitionId; ?>" target="_blank">
                                    <?php echo htmlspecialchars($rv['voucher_number']); ?>
                                </a>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><?php _e('location'); ?></label>
                            <div class="form-control bg-light"><?php echo htmlspecialchars($rv['location_name']); ?></div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label required"><?php _e('budget_date'); ?></label>
                            <input type="date" name="budget_date" class="form-control"
                                   value="<?php echo htmlspecialchars($budget['budget_date']); ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><?php _e('remarks'); ?></label>
                            <textarea name="remarks" class="form-control" rows="1"
                                      placeholder="<?php echo __('optional'); ?>"><?php echo htmlspecialchars($budget['remarks'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="text-muted mb-0"><?php _e('budget_allocations'); ?></h6>
                        <span class="badge bg-light text-dark border">
                            <?php _e('goods'); ?>: <span id="budgetGoodsTotal">0.00</span>
                            &nbsp;&middot;&nbsp; <?php _e('vat_amount'); ?>: <span id="budgetVatTotal">0.00</span>
                            &nbsp;&middot;&nbsp; <?php _e('budget_total'); ?>: <span id="budgetTotal" class="fw-bold">0.00</span>
                        </span>
                    </div>
                    <div class="small text-muted mb-3">
                        <i class="bi bi-info-circle"></i> <?php _e('budget_alloc_hint'); ?>
                    </div>

                    <?php
                    // Tracks which product already rendered its excess-reason box, so a
                    // product spanning several requisition lines gets exactly one.
                    $excessBoxRendered = [];
                    // data-remaining is now the PRODUCT total, so a product on two lines
                    // would prefill the asked-for quantity twice and open the form already
                    // over. The remaining is therefore handed out across that product's
                    // lines in order, each taking at most its own approved quantity.
                    $prefillLeft = $productRemaining;
                    $linePrefill = [];
                    foreach ($rvLines as $pl) {
                        $plPid = (int)$pl['product_id'];
                        $take = min((float)$pl['approved_qty'], $prefillLeft[$plPid] ?? 0);
                        if ($take < 0) $take = 0;
                        $linePrefill[(int)$pl['id']] = $take;
                        $prefillLeft[$plPid] = ($prefillLeft[$plPid] ?? 0) - $take;
                    }
                    foreach ($rvLines as $ln):
                        $rdId = (int)$ln['id'];
                        $pid = (int)$ln['product_id'];
                        $approved = (float)$ln['approved_qty'];
                        $already = $budgetedOther[$pid] ?? 0;
                        // Product-level, NOT line-level: for a product on two lines the
                        // old per-line figure subtracted $already twice and disagreed
                        // with the server check.
                        $remaining = $productRemaining[$pid] ?? 0;
                        $sharedProduct = ($productLineCount[$pid] ?? 1) > 1;
                        $rows = $existingAllocations[$rdId] ?? [];
                        $showExcessBox = !isset($excessBoxRendered[$pid]);
                        $excessBoxRendered[$pid] = true;
                    ?>
                    <div class="card mb-3 budget-product" data-rd-id="<?php echo $rdId; ?>" data-product-id="<?php echo $pid; ?>"
                         data-remaining="<?php echo $remaining; ?>" data-prefill="<?php echo $linePrefill[$rdId] ?? 0; ?>"
                         data-avgcost="<?php echo (float)($avgCostByProduct[$pid] ?? 0); ?>">
                        <div class="card-body">
                            <div class="d-flex flex-wrap justify-content-between align-items-center mb-2">
                                <div>
                                    <strong><?php echo htmlspecialchars($ln['product_name']); ?></strong>
                                    <span class="text-muted small">(<?php echo htmlspecialchars($ln['unit']); ?>)</span>
                                    <?php if ($sharedProduct): ?>
                                    <span class="badge bg-secondary ms-1" title="<?php echo htmlspecialchars(__('budget_product_shared_hint')); ?>">
                                        <?php _e('budget_product_shared'); ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                                <div class="small text-muted">
                                    <?php _e('approved'); ?>: <strong><?php echo formatNumber($sharedProduct ? ($productApproved[$pid] ?? 0) : $approved, 3); ?></strong>
                                    &middot; <?php _e('budgeted_elsewhere'); ?>: <?php echo formatNumber($already, 3); ?>
                                    &middot; <?php _e('remaining'); ?>: <strong><?php echo formatNumber($remaining, 3); ?></strong>
                                    &middot; <?php _e('allocated'); ?>: <span class="alloc-sum fw-bold">0</span>
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered mb-1">
                                    <thead class="table-light">
                                        <tr>
                                            <?php /* VAT sits AFTER Amount: Amount is goods only
                                                    (qty x rate), so putting VAT before it would
                                                    invite reading Amount as the all-in figure.
                                                    Matches the Total -> VAT -> Payable order the
                                                    purchase and import forms already use. */ ?>
                                            <th style="width:26%;"><?php _e('supplier'); ?></th>
                                            <th style="width:13%;"><?php _e('procurement_type'); ?></th>
                                            <th style="width:12%;" class="text-end"><?php _e('quantity'); ?></th>
                                            <th style="width:12%;" class="text-end"><?php _e('rate'); ?></th>
                                            <th style="width:14%;" class="text-end"><?php _e('amount'); ?></th>
                                            <th style="width:9%;" class="text-end"><?php _e('vat_percent'); ?></th>
                                            <th style="width:14%;" class="text-end"><?php _e('vat_amount'); ?></th>
                                            <th style="width:4%;"></th>
                                        </tr>
                                    </thead>
                                    <tbody class="alloc-rows"></tbody>
                                </table>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary add-alloc-btn">
                                <i class="bi bi-plus"></i> <?php _e('add_supplier_allocation'); ?>
                            </button>
                        </div>

                        <?php if ($showExcessBox): ?>
                        <?php $priorReason = $existingExcessRemark[$pid] ?? ''; ?>
                        <!-- Revealed by JS the moment this PRODUCT total crosses its remaining
                             quantity. Rendered once per product even when the product spans
                             several requisition lines, because the excess is a property of the
                             product total. Kept in the DOM (not injected) so a reason typed
                             before the qty was lowered is not lost, and so an edit can
                             re-populate it server-side. -->
                        <div class="excess-reason-wrap mt-2" data-product-id="<?php echo $pid; ?>"
                             style="<?php echo $priorReason === '' ? 'display:none;' : ''; ?>">
                            <label class="form-label small mb-1 text-danger fw-bold">
                                <i class="bi bi-exclamation-triangle"></i>
                                <?php _e('budget_excess_reason_label'); ?>
                                <span class="excess-over-badge badge bg-danger ms-1"></span>
                            </label>
                            <textarea name="alloc_excess_remark[<?php echo $pid; ?>]"
                                      class="form-control form-control-sm excess-reason-input"
                                      rows="2" maxlength="500"
                                      placeholder="<?php echo htmlspecialchars(__('budget_excess_reason_placeholder')); ?>"><?php echo htmlspecialchars($priorReason); ?></textarea>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>

                    <div id="budgetOverWarn" class="alert alert-warning mt-3" style="display:none;">
                        <i class="bi bi-exclamation-triangle"></i> <?php _e('budget_over_needs_reason_block'); ?>
                    </div>

                    <div class="d-flex justify-content-end mt-4">
                        <a href="requisition_voucher_view.php?id=<?php echo $requisitionId; ?>" class="btn btn-secondary me-2"><?php _e('cancel'); ?></a>
                        <button type="submit" id="budgetSaveBtn" class="btn btn-success">
                            <i class="bi bi-check-circle"></i> <?php _e('save_budget'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

<script>
const SUPPLIER_OPTIONS = <?php echo json_encode(array_map(function ($s) use ($blacklisted) {
    return ['id' => (int)$s['id'], 'name' => $s['name'], 'blacklisted' => isset($blacklisted[(int)$s['id']])];
}, $suppliers), JSON_UNESCAPED_UNICODE); ?>;

const EXISTING_ALLOCS = <?php
    $byRd = [];
    foreach ($rvLines as $ln) {
        $rid = (int)$ln['id'];
        $byRd[$rid] = array_map(function ($a) {
            return [
                'supplier_id' => (int)$a['supplier_id'],
                'type' => $a['procurement_type'],
                'qty' => (float)$a['quantity'],
                'rate' => (float)$a['rate'],
                // Migration 0044. vat_pct stays null when a flat amount was
                // typed, so reloading the form does not invent a percentage.
                'vat_pct' => isset($a['vat_percent']) && $a['vat_percent'] !== null ? (float)$a['vat_percent'] : null,
                'vat_amt' => (float)($a['vat_amount'] ?? 0),
            ];
        }, $existingAllocations[$rid] ?? []);
    }
    echo json_encode($byRd, JSON_UNESCAPED_UNICODE);
?>;

const T = {
    purchase: '<?php echo addslashes(__('purchase')); ?>',
    import: '<?php echo addslashes(__('import')); ?>',
    select_supplier: '<?php echo addslashes(__('select_supplier')); ?>',
    blacklisted: <?php echo json_encode(__('supplier_blacklisted')); ?>,
    confirm_drop_alloc_rows: '<?php echo addslashes(__('budget_confirm_drop_alloc_rows')); ?>',
    over_by: '<?php echo addslashes(__('budget_over_by')); ?>'
};

function fmt3(n) {
    if (n === null || n === undefined || isNaN(n)) return '0';
    return parseFloat(n).toFixed(3).replace(/\.?0+$/, '');
}
function fmt2(n) {
    if (n === null || n === undefined || isNaN(n)) return '0.00';
    return parseFloat(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function supplierOptionsHtml(selected) {
    let html = '<option value="">' + T.select_supplier + '</option>';
    SUPPLIER_OPTIONS.forEach(s => {
        // A blacklisted supplier is only listed when it is already on this
        // budget, and only in the row that holds it, so it cannot be picked anew.
        if (s.blacklisted && s.id !== selected) return;
        html += '<option value="' + s.id + '"' + (s.id === selected ? ' selected' : '') +
            (s.blacklisted ? ' class="text-danger"' : '') + '>' +
            $('<div>').text(s.name).html() + (s.blacklisted ? ' (' + $('<div>').text(T.blacklisted).html() + ')' : '') + '</option>';
    });
    return html;
}

function allocRowHtml(rdId, data) {
    const sup = data ? data.supplier_id : 0;
    const type = data ? data.type : 'purchase';
    const qty = data && data.qty ? fmt3(data.qty) : '';
    const rate = data && (data.rate || data.rate === 0) ? fmt3(data.rate) : '';
    const vatPct = data && data.vat_pct !== null && data.vat_pct !== undefined ? fmt3(data.vat_pct) : '';
    const vatAmt = data && data.vat_amt ? parseFloat(data.vat_amt).toFixed(2) : '';
    return `
    <tr class="alloc-row">
        <td>
            <select name="alloc_supplier[${rdId}][]" class="form-select form-select-sm alloc-supplier">
                ${supplierOptionsHtml(sup)}
            </select>
        </td>
        <td>
            <select name="alloc_type[${rdId}][]" class="form-select form-select-sm">
                <option value="purchase"${type === 'purchase' ? ' selected' : ''}>${T.purchase}</option>
                <option value="import"${type === 'import' ? ' selected' : ''}>${T.import}</option>
            </select>
        </td>
        <td><input type="number" name="alloc_qty[${rdId}][]" step="0.001" min="0.001" class="form-control form-control-sm text-end alloc-qty" value="${qty}"></td>
        <td><input type="number" name="alloc_rate[${rdId}][]" step="0.01" min="0" class="form-control form-control-sm text-end alloc-rate" value="${rate}"></td>
        <td class="text-end alloc-amount align-middle">0.00</td>
        <td><input type="number" name="alloc_vat_pct[${rdId}][]" step="0.001" min="0" max="100" class="form-control form-control-sm text-end alloc-vat-pct" value="${vatPct}" placeholder="%"></td>
        <td><input type="number" name="alloc_vat_amt[${rdId}][]" step="0.01" min="0" class="form-control form-control-sm text-end alloc-vat-amt" value="${vatAmt}"></td>
        <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger remove-alloc"><i class="bi bi-x"></i></button></td>
    </tr>`;
}

/**
 * Keep a row's VAT % and VAT amount consistent.
 *
 * Whichever field the user last touched wins:
 *   'pct'  -> amount = qty x rate x pct / 100
 *   'amt'  -> pct back-derived from the amount
 *   'base' -> qty or rate changed: re-derive the amount, but ONLY when a % is
 *             set. A hand-typed flat amount is left alone, otherwise editing a
 *             quantity would silently overwrite a figure the user chose.
 */
function syncRowVat(row, source) {
    const qty  = parseFloat(row.querySelector('.alloc-qty').value) || 0;
    const rate = parseFloat(row.querySelector('.alloc-rate').value) || 0;
    const base = qty * rate;
    const pctEl = row.querySelector('.alloc-vat-pct');
    const amtEl = row.querySelector('.alloc-vat-amt');
    if (!pctEl || !amtEl) return;

    if (source === 'pct') {
        const pct = parseFloat(pctEl.value);
        amtEl.value = (!isNaN(pct) && pct >= 0 && base > 0) ? (base * pct / 100).toFixed(2)
                    : (pctEl.value === '' ? amtEl.value : '0.00');
    } else if (source === 'amt') {
        const amt = parseFloat(amtEl.value);
        pctEl.value = (!isNaN(amt) && amt > 0 && base > 0) ? (amt / base * 100).toFixed(3) : '';
    } else if (source === 'base') {
        const pct = parseFloat(pctEl.value);
        if (!isNaN(pct) && pct >= 0) {
            amtEl.value = base > 0 ? (base * pct / 100).toFixed(2) : '0.00';
        }
    }
}

function recalcProduct(block) {
    let sum = 0, total = 0;
    block.querySelectorAll('.alloc-row').forEach(row => {
        const qty = parseFloat(row.querySelector('.alloc-qty').value) || 0;
        const rate = parseFloat(row.querySelector('.alloc-rate').value) || 0;
        sum += qty;
        const amt = qty * rate;
        total += amt;
        row.querySelector('.alloc-amount').textContent = fmt2(amt);
    });
    const remaining = parseFloat(block.dataset.remaining) || 0;
    const sumEl = block.querySelector('.alloc-sum');
    sumEl.textContent = fmt3(sum);
    sumEl.classList.toggle('text-danger', sum > remaining + 0.0005);
    sumEl.classList.toggle('text-success', sum > 0 && sum <= remaining + 0.0005);
    recalcBudgetTotal();
}

function recalcBudgetTotal() {
    // Goods and VAT are tracked separately — the Amount column is goods only,
    // so the badge shows Goods / VAT / Total rather than one ambiguous figure.
    let goods = 0, vat = 0;
    document.querySelectorAll('.budget-product .alloc-row').forEach(row => {
        const qty = parseFloat(row.querySelector('.alloc-qty').value) || 0;
        const rate = parseFloat(row.querySelector('.alloc-rate').value) || 0;
        const vatEl = row.querySelector('.alloc-vat-amt');
        goods += qty * rate;
        vat += vatEl ? (parseFloat(vatEl.value) || 0) : 0;
    });
    document.getElementById('budgetGoodsTotal').textContent = fmt2(goods);
    document.getElementById('budgetVatTotal').textContent = fmt2(vat);
    document.getElementById('budgetTotal').textContent = fmt2(goods + vat);
    updateSaveState();
}

// Allocated quantity per PRODUCT, summed across every block and every supplier
// row. It must be per product, not per block: one product can sit on more than
// one requisition line, and the server has always aggregated it this way. A
// per-block total would disagree with the server for such a product.
function allocatedByProduct() {
    const totals = {};
    document.querySelectorAll('.budget-product').forEach(block => {
        const pid = block.dataset.productId;
        let sum = 0;
        block.querySelectorAll('.alloc-row').forEach(row => {
            sum += parseFloat(row.querySelector('.alloc-qty').value) || 0;
        });
        totals[pid] = (totals[pid] || 0) + sum;
    });
    return totals;
}

// Remaining is identical on every block of a given product (both are rendered
// from the same product-level figure), so the first block wins.
function remainingByProduct() {
    const rem = {};
    document.querySelectorAll('.budget-product').forEach(block => {
        const pid = block.dataset.productId;
        if (!(pid in rem)) rem[pid] = parseFloat(block.dataset.remaining) || 0;
    });
    return rem;
}

// Products going beyond their remaining quantity. Over is ALLOWED now — it just
// has to be explained — so this returns the excess per product rather than a
// bare boolean, and the caller decides what to do about it.
function productsOver() {
    const alloc = allocatedByProduct();
    const rem = remainingByProduct();
    const over = {};
    Object.keys(alloc).forEach(pid => {
        const excess = alloc[pid] - (rem[pid] || 0);
        if (excess > 0.0005) over[pid] = excess;
    });
    return over;
}

// Show the reason box for every over product, hide it for the rest, and block
// Save only while an over product has no reason typed. The excess quantity is
// no longer a hard stop — the written reason is what replaces the old cap.
function updateSaveState() {
    const over = productsOver();
    let missingReason = false;

    document.querySelectorAll('.excess-reason-wrap').forEach(wrap => {
        const pid = wrap.dataset.productId;
        const excess = over[pid];
        const input = wrap.querySelector('.excess-reason-input');
        const badge = wrap.querySelector('.excess-over-badge');
        if (excess) {
            wrap.style.display = '';
            if (badge) badge.textContent = T.over_by.replace('%s', fmt3(excess));
            if (input && input.value.trim() === '') missingReason = true;
        } else {
            // Hidden, but the textarea keeps whatever was typed. It is only sent
            // when the product is genuinely over, because the server ignores a
            // reason for a product that is within its remaining quantity.
            wrap.style.display = 'none';
        }
    });

    const btn = document.getElementById('budgetSaveBtn');
    const warn = document.getElementById('budgetOverWarn');
    if (btn) btn.disabled = missingReason;
    if (warn) warn.style.display = missingReason ? '' : 'none';
}

function addAllocRow(block, data) {
    const rdId = block.dataset.rdId;
    const tbody = block.querySelector('.alloc-rows');
    tbody.insertAdjacentHTML('beforeend', allocRowHtml(rdId, data));
    const row = tbody.lastElementChild;
    // Default rate from avg cost when adding a fresh row. Rate is stored as
    // decimal(15,2), so keep the default at 2 decimals — otherwise the value
    // shown here (3dp) would round on save and look like the rate "changed".
    if (!data) {
        const avg = parseFloat(block.dataset.avgcost) || 0;
        if (avg > 0) row.querySelector('.alloc-rate').value = avg.toFixed(2);
    }
    recalcProduct(block);
}

document.addEventListener('input', function (e) {
    // The excess-reason textarea lives outside .alloc-row, so it has to be handled
    // before the guard below drops out. Typing a reason is what re-enables Save.
    if (e.target.classList.contains('excess-reason-input')) {
        updateSaveState();
        return;
    }

    const row = e.target.closest('.alloc-row');
    const block = e.target.closest('.budget-product');
    if (!block || !row) return;

    if (e.target.classList.contains('alloc-qty') || e.target.classList.contains('alloc-rate')) {
        syncRowVat(row, 'base');      // re-derive VAT when a % is set
        recalcProduct(block);
    } else if (e.target.classList.contains('alloc-vat-pct')) {
        syncRowVat(row, 'pct');
        recalcProduct(block);
    } else if (e.target.classList.contains('alloc-vat-amt')) {
        syncRowVat(row, 'amt');
        recalcProduct(block);
    }
});

document.addEventListener('click', function (e) {
    const addBtn = e.target.closest('.add-alloc-btn');
    if (addBtn) {
        addAllocRow(addBtn.closest('.budget-product'), null);
        return;
    }
    const rm = e.target.closest('.remove-alloc');
    if (rm) {
        const block = rm.closest('.budget-product');
        rm.closest('.alloc-row').remove();
        recalcProduct(block);
    }
});

/**
 * Count rows that will be DISCARDED on save and that hold more than a quantity.
 *
 * A row with no supplier is not an allocation, so the server drops it. A row
 * holding nothing but a quantity is the ordinary unused/left-over row and goes
 * quietly. One that also carries a rate or VAT is something the user typed
 * deliberately, so it is worth asking before throwing it away.
 */
function supplierlessRowsWithData() {
    let n = 0;
    document.querySelectorAll('.alloc-row').forEach(row => {
        const sup = row.querySelector('.alloc-supplier');
        if (sup && sup.value) return; // supplier chosen — the row is kept
        const filled = sel => {
            const el = row.querySelector(sel);
            return el && el.value.trim() !== '' && parseFloat(el.value) > 0;
        };
        if (filled('.alloc-rate') || filled('.alloc-vat-pct') || filled('.alloc-vat-amt')) n++;
    });
    return n;
}

document.getElementById('budgetForm').addEventListener('submit', function (e) {
    // Over-allocation is permitted; an UNEXPLAINED one is not.
    updateSaveState();
    const over = productsOver();
    const unexplained = Object.keys(over).some(pid => {
        const wrap = document.querySelector('.excess-reason-wrap[data-product-id="' + pid + '"]');
        const input = wrap && wrap.querySelector('.excess-reason-input');
        return !input || input.value.trim() === '';
    });
    if (unexplained) {
        e.preventDefault();
        const warn = document.getElementById('budgetOverWarn');
        if (warn) warn.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }

    const dropping = supplierlessRowsWithData();
    if (dropping > 0 && !confirm(T.confirm_drop_alloc_rows.replace('%d', dropping))) {
        e.preventDefault();
    }
});

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.budget-product').forEach(block => {
        const rdId = block.dataset.rdId;
        const rows = EXISTING_ALLOCS[rdId] || [];
        if (rows.length) {
            rows.forEach(r => addAllocRow(block, r));
        } else {
            addAllocRow(block, null);
            // Pre-fill the first (fresh) row's qty so the budget defaults to the
            // amount asked for. This is data-prefill, NOT data-remaining: remaining
            // is the PRODUCT total and would be prefilled once per line for a
            // product that appears on several of them.
            const prefill = parseFloat(block.dataset.prefill) || 0;
            if (prefill > 0) {
                const qtyInput = block.querySelector('.alloc-row .alloc-qty');
                if (qtyInput) { qtyInput.value = fmt3(prefill); recalcProduct(block); }
            }
        }
    });
});
</script>
