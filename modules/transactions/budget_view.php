<?php
/**
 * View a Budget. Status-dependent: draft (send/edit/delete),
 * pending_approval (approve-with-edit / reject), approved (assisted purchase /
 * import generation — see PROCUREMENT section), rejected (read-only).
 */
require_once '../../config/database.php';
require_once '../../includes/functions.php';

$pageTitle = __('budget_details');

if (!hasPermission('budget', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('');
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    setAlert(__('budget_not_found'), 'danger');
    redirect('modules/transactions/budget_list.php');
}

$stmt = $conn->prepare("
    SELECT b.*,
        rv.voucher_number,
        l.name AS location_name,
        cc.name AS cost_centre_name, cc.code AS cost_centre_code,
        cu.name AS created_by_name,
        au.name AS approved_by_name,
        ru.name AS rejected_by_name
    FROM budgets b
    LEFT JOIN requisition_vouchers rv ON b.requisition_id = rv.id
    LEFT JOIN locations l ON b.location_id = l.id
    LEFT JOIN cost_centres cc ON b.cost_centre_id = cc.id
    LEFT JOIN users cu ON b.created_by = cu.id
    LEFT JOIN users au ON b.approved_by = au.id
    LEFT JOIN users ru ON b.rejected_by = ru.id
    WHERE b.id = ?
");
$stmt->execute([$id]);
$budget = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$budget) {
    setAlert(__('budget_not_found'), 'danger');
    redirect('modules/transactions/budget_list.php');
}
$status = $budget['status'];

$detStmt = $conn->prepare("
    SELECT bd.*, p.name AS product_name, p.unit, la.name AS supplier_name
    FROM budget_details bd
    JOIN products p ON bd.product_id = p.id
    LEFT JOIN suppliers s ON bd.supplier_id = s.id
    LEFT JOIN ledger_accounts la ON s.ledger_account_id = la.id
    WHERE bd.budget_id = ?
    ORDER BY p.name, la.name
");
$detStmt->execute([$id]);
$details = $detStmt->fetchAll(PDO::FETCH_ASSOC);

// Blacklisted suppliers on this budget (migration 0054), keyed by supplier id.
$budgetBlacklisted = getBlacklistedSuppliers($conn, array_column($details, 'supplier_id'));

// Goods and VAT are tracked apart: the Amount column is goods only (qty x rate),
// VAT (migration 0044) is its own column, and the footer shows Goods / VAT / Total.
// On a budget written before 0044 every vat_amount is 0.00, so $grandTotal is
// identical to what this page has always shown.
$goodsTotal = 0;
$vatTotal   = 0;
foreach ($details as $d) {
    $goodsTotal += (float)$d['quantity'] * (float)$d['rate'];
    $vatTotal   += (float)($d['vat_amount'] ?? 0);
}
$grandTotal = $goodsTotal + $vatTotal;

// Supplier Summary: the same figures as the lines table, grouped by supplier +
// procurement type (the grouping the procurement cards use). Display only —
// the rows sum to exactly $goodsTotal / $vatTotal / $grandTotal above.
$supplierSummary = [];
foreach ($details as $d) {
    $sk = $d['supplier_id'] . '|' . $d['procurement_type'];
    if (!isset($supplierSummary[$sk])) {
        $supplierSummary[$sk] = [
            'supplier_name' => $d['supplier_name'],
            'type'          => $d['procurement_type'],
            'goods'         => 0.0,
            'vat'           => 0.0,
        ];
    }
    $supplierSummary[$sk]['goods'] += (float)$d['quantity'] * (float)$d['rate'];
    $supplierSummary[$sk]['vat']   += (float)($d['vat_amount'] ?? 0);
}
uasort($supplierSummary, function ($a, $b) {
    $c = strcasecmp((string)$a['supplier_name'], (string)$b['supplier_name']);
    return $c !== 0 ? $c : strcmp((string)$a['type'], (string)$b['type']);
});

// Approved budgets: build the assisted-procurement groups (supplier + type) with
// remaining-to-create per allocation, plus the list of linked purchases/imports.
$procGroups = [];
$linkedPurchases = [];
$linkedImports = [];
$linkedPos = [];
if ($status === 'approved') {
    $pcStmt = $conn->prepare("
        SELECT pu.supplier_id, pd.product_id, SUM(pd.quantity) AS qty
        FROM purchases pu JOIN purchase_details pd ON pd.purchase_id = pu.id
        WHERE pu.budget_id = ? GROUP BY pu.supplier_id, pd.product_id
    ");
    $pcStmt->execute([$id]);
    $purchaseCreated = [];
    foreach ($pcStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $purchaseCreated[$r['supplier_id'] . '|' . $r['product_id']] = (float)$r['qty'];
    }

    $icStmt = $conn->prepare("
        SELECT pim.supplier_id, pid.product_id, SUM(pid.quantity) AS qty
        FROM purchase_imports pim JOIN purchase_import_details pid ON pid.import_id = pim.id
        WHERE pim.budget_id = ? GROUP BY pim.supplier_id, pid.product_id
    ");
    $icStmt->execute([$id]);
    $importCreated = [];
    foreach ($icStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $importCreated[$r['supplier_id'] . '|' . $r['product_id']] = (float)$r['qty'];
    }

    // Quantities sitting on OPEN purchase orders raised from this budget —
    // they reduce remaining-to-create so a line can't be procured twice
    // (once via PO, again directly). Empty pre-migration-0038.
    $poOutstanding = getBudgetPoOutstanding($id, $conn);

    foreach ($details as $d) {
        $key = $d['supplier_id'] . '|' . $d['procurement_type'];
        if (!isset($procGroups[$key])) {
            $procGroups[$key] = [
                'supplier_id'     => (int)$d['supplier_id'],
                'supplier_name'   => $d['supplier_name'],
                'type'            => $d['procurement_type'],
                'rows'            => [],
                'remaining_total' => 0,
                // VAT that would carry onto the document raised from this group,
                // pro-rated by the quantity still to be procured. Shown on the
                // card so it is visible BEFORE clicking Create Purchase.
                'vat_remaining'   => 0,
            ];
        }
        $map = $d['procurement_type'] === 'import' ? $importCreated : $purchaseCreated;
        $created = $map[$d['supplier_id'] . '|' . $d['product_id']] ?? 0;
        $onPo = $d['procurement_type'] === 'purchase'
            ? ($poOutstanding[$d['supplier_id'] . '|' . $d['product_id']] ?? 0)
            : 0;
        $rem = (float)$d['quantity'] - $created - $onPo;
        if ($rem < 0) $rem = 0;
        $lineVatRem = budgetLineVatForQty($d['vat_amount'] ?? 0, $d['quantity'], $rem);
        $procGroups[$key]['rows'][] = [
            'product_name' => $d['product_name'],
            'qty'          => (float)$d['quantity'],
            'created'      => $created,
            'on_po'        => $onPo,
            'remaining'    => $rem,
            'vat_remaining' => $lineVatRem,
        ];
        $procGroups[$key]['remaining_total'] += $rem;
        $procGroups[$key]['vat_remaining']   += $lineVatRem;
    }

    // Card VALUES (Budgeted / Procured / On PO / Remaining), at the BUDGET rate
    // incl. pro-rated VAT, so they always reconcile to the budget figures.
    // Done per supplier+type+product, NOT per row: the quantity rows above apply
    // a product's whole procured qty to EACH of its rows, which would count the
    // value twice when a product sits on two rows. Here procured and on-PO
    // quantities are handed out across the product's rows in order, each taking
    // at most its own quantity (any excess lands on the last row). The quantity
    // logic above is left exactly as it was.
    $valRows = [];
    foreach ($details as $d) {
        $valRows[$d['supplier_id'] . '|' . $d['procurement_type']][(int)$d['product_id']][] = $d;
    }
    foreach ($valRows as $key => $products) {
        if (!isset($procGroups[$key])) continue;
        $bud = 0.0; $proc = 0.0; $po = 0.0;
        foreach ($products as $pid => $rows) {
            $sid = $rows[0]['supplier_id'];
            $isImp = $rows[0]['procurement_type'] === 'import';
            $procLeft = (float)(($isImp ? $importCreated : $purchaseCreated)[$sid . '|' . $pid] ?? 0);
            $poLeft   = $isImp ? 0.0 : (float)($poOutstanding[$sid . '|' . $pid] ?? 0);
            $last = count($rows) - 1;
            foreach ($rows as $ri => $d) {
                $q    = (float)$d['quantity'];
                $rate = (float)$d['rate'];
                $vat  = (float)($d['vat_amount'] ?? 0);
                $bud += $q * $rate + $vat;
                // Procured fills the row first, then open-PO quantity takes what is left.
                $tp = ($ri === $last) ? $procLeft : min($procLeft, $q);
                $procLeft -= $tp;
                $roomForPo = max(0, $q - $tp);
                $to = ($ri === $last) ? $poLeft : min($poLeft, $roomForPo);
                $poLeft -= $to;
                $proc += $tp * $rate + budgetLineVatForQty($vat, $q, $tp);
                $po   += $to * $rate + budgetLineVatForQty($vat, $q, min($to, $roomForPo));
            }
        }
        $procGroups[$key]['val_budgeted']  = $bud;
        $procGroups[$key]['val_procured']  = $proc;
        $procGroups[$key]['val_on_po']     = $po;
        $procGroups[$key]['val_remaining'] = max(0, $bud - $proc - $po);
    }

    $lp = $conn->prepare("SELECT id, purchase_number FROM purchases WHERE budget_id = ? ORDER BY id");
    $lp->execute([$id]);
    $linkedPurchases = $lp->fetchAll(PDO::FETCH_ASSOC);
    $li = $conn->prepare("SELECT id, import_number FROM purchase_imports WHERE budget_id = ? ORDER BY id");
    $li->execute([$id]);
    $linkedImports = $li->fetchAll(PDO::FETCH_ASSOC);

    // Purchase orders raised from this budget (migration 0038; empty before).
    try {
        $lpo = $conn->prepare("SELECT id, po_number, status FROM purchase_orders WHERE budget_id = ? ORDER BY id");
        $lpo->execute([$id]);
        $linkedPos = $lpo->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $linkedPos = [];
    }
}

$canApprove = ($status === 'pending_approval') && hasPermission('budget', 'approve');
// Shared gate: 'budget' edit permission, or the creator of this budget while it
// is still pre-approval. budgetCanEdit() also refuses an approved budget that
// already has purchases / imports / open POs against it.
$canEditBudget = budgetCanEdit($budget, $conn);
$canSendBudget = budgetCanSendForApproval($budget);
$canCreatePurchase = hasPermission('purchases', 'create');
$canCreateImport = hasPermission('purchase_imports', 'create');
$canCreatePo = hasPermission('purchase_orders', 'create');

// Per-product cap for the approve modal: the requisition's approved quantity
// minus what OTHER budgets of that requisition already took. The approver may
// reduce a line but the per-product total may not exceed this cap.
$approveCap = [];
if ($canApprove) {
    $rid = (int)$budget['requisition_id'];
    $apStmt = $conn->prepare("
        SELECT product_id, SUM(COALESCE(approved_quantity, requested_quantity)) AS approved
        FROM requisition_voucher_details
        WHERE requisition_id = ?
        GROUP BY product_id
    ");
    $apStmt->execute([$rid]);
    $budgetedOther = getRequisitionBudgetedQty($rid, $conn, $id);
    foreach ($apStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pid = (int)$r['product_id'];
        $cap = (float)$r['approved'] - ($budgetedOther[$pid] ?? 0);
        $approveCap[$pid] = $cap > 0 ? $cap : 0;
    }
}

// Reasons already recorded against an over-allocated product ($details is loaded
// above). Shown in the approve modal so the approver reads WHY before signing,
// and pre-filled so leaving the excess untouched does not mean retyping the
// reason the creator already gave. Written to every row of a product, so the
// first non-empty one is the reason.
$excessReasonByProduct = [];
foreach ($details as $d) {
    $dpid = (int)$d['product_id'];
    if (!isset($excessReasonByProduct[$dpid]) && trim((string)($d['remarks'] ?? '')) !== '') {
        $excessReasonByProduct[$dpid] = (string)$d['remarks'];
    }
}

require_once '../../includes/header.php';

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
    <div class="col-md-12 col-lg-11">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center no-print">
                <h5 class="mb-0"><i class="bi bi-cash-coin"></i> <?php echo htmlspecialchars($budget['budget_number']); ?></h5>
                <div>
                    <a href="budget_list.php" class="btn btn-sm btn-secondary"><i class="bi bi-list"></i> <?php _e('list'); ?></a>
                    <button onclick="window.print()" class="btn btn-sm btn-secondary"><i class="bi bi-printer"></i> <?php _e('print'); ?></button>
                </div>
            </div>
            <div class="card-body">
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="text-muted small"><?php _e('budget_number'); ?></div>
                        <div class="fw-bold"><?php echo htmlspecialchars($budget['budget_number']); ?></div>
                    </div>
                    <div class="col-md-2">
                        <div class="text-muted small"><?php _e('date'); ?></div>
                        <div class="fw-bold"><?php echo formatDate($budget['budget_date']); ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small"><?php _e('requisition_voucher'); ?></div>
                        <div class="fw-bold">
                            <a href="requisition_voucher_view.php?id=<?php echo (int)$budget['requisition_id']; ?>"><?php echo htmlspecialchars($budget['voucher_number'] ?? '-'); ?></a>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="text-muted small"><?php _e('location'); ?></div>
                        <div class="fw-bold"><?php echo htmlspecialchars($budget['location_name']); ?></div>
                    </div>
                    <div class="col-md-2">
                        <div class="text-muted small"><?php _e('status'); ?></div>
                        <div><span class="badge bg-<?php echo $statusBadge($status); ?>"><?php _e('status_' . $status); ?></span></div>
                    </div>
                </div>

                <?php if (!empty($budget['remarks'])): ?>
                <div class="mb-3"><div class="text-muted small"><?php _e('remarks'); ?></div><div><?php echo nl2br(htmlspecialchars($budget['remarks'])); ?></div></div>
                <?php endif; ?>

                <?php if ($status === 'rejected' && !empty($budget['reject_reason'])): ?>
                <div class="alert alert-danger">
                    <strong><?php _e('reject_reason'); ?>:</strong> <?php echo htmlspecialchars($budget['reject_reason']); ?>
                    <?php if ($budget['rejected_by_name']): ?><div class="small mt-1"><?php echo htmlspecialchars($budget['rejected_by_name']); ?> &middot; <?php echo formatDateTime($budget['rejected_at']); ?></div><?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($status === 'approved' && !empty($budget['approve_remark'])): ?>
                <div class="alert alert-success">
                    <strong><?php _e('remarks'); ?>:</strong> <?php echo nl2br(htmlspecialchars($budget['approve_remark'])); ?>
                    <?php if ($budget['approved_by_name']): ?><div class="small mt-1"><?php echo htmlspecialchars($budget['approved_by_name']); ?> &middot; <?php echo formatDateTime($budget['approved_at']); ?></div><?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($budgetBlacklisted) && $status !== 'rejected'): ?>
                <div class="alert alert-<?php echo $status === 'approved' ? 'warning' : 'danger'; ?> no-print">
                    <i class="bi bi-slash-circle"></i> <strong><?php _e('budget_has_blacklisted_title'); ?></strong>
                    <ul class="mb-0 mt-1">
                        <?php foreach ($budgetBlacklisted as $bl): ?>
                        <li><?php echo htmlspecialchars($bl['name'] ?? '-'); ?>: <?php echo htmlspecialchars((string)($bl['reason'] ?? '')); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="small mt-1"><?php
                        echo $status === 'approved' ? __('budget_blacklisted_approved_hint') : __('budget_replace_blacklisted_hint');
                    ?></div>
                </div>
                <?php endif; ?>

                <h6 class="text-muted mb-3"><?php _e('budget_allocations'); ?></h6>
                <div class="table-responsive">
                    <table class="table table-bordered table-sm align-middle">
                        <thead class="table-light">
                            <tr>
                                <th style="width:4%;"><?php _e('sr'); ?></th>
                                <th><?php _e('product'); ?></th>
                                <th style="width:8%;"><?php _e('unit'); ?></th>
                                <th><?php _e('supplier'); ?></th>
                                <th style="width:10%;"><?php _e('procurement_type'); ?></th>
                                <th style="width:11%;" class="text-end"><?php _e('quantity'); ?></th>
                                <th style="width:11%;" class="text-end"><?php _e('rate'); ?></th>
                                <th style="width:12%;" class="text-end"><?php _e('amount'); ?></th>
                                <?php /* VAT after Amount — Amount is goods only. */ ?>
                                <th style="width:8%;" class="text-end"><?php _e('vat_percent'); ?></th>
                                <th style="width:12%;" class="text-end"><?php _e('vat_amount'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // The reason is stored on every row of an over-allocated product,
                            // so print it against the first of them only.
                            $reasonShown = [];
                            foreach ($details as $i => $d):
                                $amt = (float)$d['quantity'] * (float)$d['rate'];
                                $rpid = (int)$d['product_id'];
                                $showReason = trim((string)($d['remarks'] ?? '')) !== '' && !isset($reasonShown[$rpid]);
                                if ($showReason) $reasonShown[$rpid] = true;
                            ?>
                            <tr>
                                <td><?php echo $i + 1; ?></td>
                                <td>
                                    <?php echo htmlspecialchars($d['product_name']); ?>
                                    <?php if ($showReason): ?>
                                    <?php /* Only an over-allocated product carries a remark, so its
                                            presence IS the flag that this line exceeded the
                                            requisition quantity. Printed with the document: the
                                            justification has to travel with the figures it explains. */ ?>
                                    <br><small class="text-danger">
                                        <i class="bi bi-exclamation-triangle"></i>
                                        <strong><?php _e('budget_excess_reason_label'); ?>:</strong>
                                        <?php echo htmlspecialchars($d['remarks']); ?>
                                    </small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($d['unit']); ?></td>
                                <td><?php echo htmlspecialchars($d['supplier_name'] ?? '-'); ?><?php if (isset($budgetBlacklisted[(int)$d['supplier_id']])): ?> <span class="badge bg-danger no-print"><i class="bi bi-slash-circle"></i> <?php _e('supplier_blacklisted'); ?></span><?php endif; ?></td>
                                <td><span class="badge bg-<?php echo $d['procurement_type'] === 'import' ? 'info' : 'secondary'; ?>"><?php _e($d['procurement_type']); ?></span></td>
                                <td class="text-end"><?php echo formatNumber($d['quantity'], 3); ?></td>
                                <td class="text-end"><?php echo formatNumber($d['rate'], 2); ?></td>
                                <td class="text-end"><?php echo formatNumber($amt, 2); ?></td>
                                <td class="text-end"><?php echo ($d['vat_percent'] !== null && $d['vat_percent'] !== '') ? formatNumber($d['vat_percent'], 2) : '-'; ?></td>
                                <td class="text-end"><?php echo formatNumber($d['vat_amount'] ?? 0, 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <?php /* Goods / VAT / Total, matching the Total -> VAT -> Payable
                                    order the purchase and import forms use. */ ?>
                            <tr>
                                <th colspan="7" class="text-end"><?php _e('goods'); ?></th>
                                <th class="text-end"><?php echo formatNumber($goodsTotal, 2); ?></th>
                                <th colspan="2"></th>
                            </tr>
                            <tr>
                                <th colspan="7" class="text-end"><?php _e('vat_amount'); ?></th>
                                <th></th>
                                <th></th>
                                <th class="text-end"><?php echo formatNumber($vatTotal, 2); ?></th>
                            </tr>
                            <tr class="table-active">
                                <th colspan="7" class="text-end"><?php _e('budget_total'); ?></th>
                                <th colspan="3" class="text-end"><?php echo formatNumber($grandTotal, 2); ?></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <?php if (!empty($supplierSummary)): ?>
                <!-- Supplier Summary: the lines above grouped by supplier + type. Printed. -->
                <div class="mt-3 budget-supplier-summary" style="page-break-inside: avoid;">
                    <h6 class="mb-2"><i class="bi bi-people"></i> <?php _e('budget_supplier_summary'); ?></h6>
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:4%;"><?php _e('sr'); ?></th>
                                    <th><?php _e('supplier'); ?></th>
                                    <th style="width:10%;"><?php _e('procurement_type'); ?></th>
                                    <th style="width:16%;" class="text-end"><?php _e('goods'); ?></th>
                                    <th style="width:16%;" class="text-end"><?php _e('vat_amount'); ?></th>
                                    <th style="width:16%;" class="text-end"><?php _e('total'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $ssi = 0; foreach ($supplierSummary as $ss): $ssi++; ?>
                                <tr>
                                    <td><?php echo $ssi; ?></td>
                                    <td><?php echo htmlspecialchars($ss['supplier_name'] ?? '-'); ?></td>
                                    <td><span class="badge bg-<?php echo $ss['type'] === 'import' ? 'info' : 'secondary'; ?>"><?php _e($ss['type']); ?></span></td>
                                    <td class="text-end"><?php echo formatNumber($ss['goods'], 2); ?></td>
                                    <td class="text-end"><?php echo formatNumber($ss['vat'], 2); ?></td>
                                    <td class="text-end fw-semibold"><?php echo formatNumber($ss['goods'] + $ss['vat'], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr class="table-active">
                                    <th colspan="3" class="text-end"><?php _e('budget_total'); ?></th>
                                    <th class="text-end"><?php echo formatNumber($goodsTotal, 2); ?></th>
                                    <th class="text-end"><?php echo formatNumber($vatTotal, 2); ?></th>
                                    <th class="text-end"><?php echo formatNumber($grandTotal, 2); ?></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($status === 'approved'): ?>
                <!-- Assisted procurement: one Create Purchase/Import per supplier+type group -->
                <div class="mt-4 no-print">
                    <h6 class="text-muted mb-3"><i class="bi bi-bag-plus"></i> <?php _e('budget_procurement'); ?></h6>
                    <div class="row g-3">
                        <?php foreach ($procGroups as $g):
                            $isImport = $g['type'] === 'import';
                            $canCreate = $isImport ? $canCreateImport : $canCreatePurchase;
                            $done = $g['remaining_total'] <= 0.0005;
                            // Approved before the supplier was blacklisted (0054):
                            // procurement is still allowed, after a warning.
                            $gBlack = $budgetBlacklisted[(int)$g['supplier_id']] ?? null;
                            $gConfirm = $gBlack
                                ? ' onclick="return confirm(' . htmlspecialchars(json_encode(sprintf(__('budget_blacklisted_procure_confirm'), $g['supplier_name'] ?? '-')), ENT_QUOTES) . ')"'
                                : '';
                        ?>
                        <div class="col-md-6">
                            <div class="card h-100">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <div>
                                            <strong><?php echo htmlspecialchars($g['supplier_name'] ?? '-'); ?></strong>
                                            <span class="badge bg-<?php echo $isImport ? 'info' : 'secondary'; ?> ms-1"><?php _e($g['type']); ?></span>
                                            <?php if ($gBlack): ?><span class="badge bg-danger ms-1" title="<?php echo htmlspecialchars((string)($gBlack['reason'] ?? '')); ?>"><i class="bi bi-slash-circle"></i> <?php _e('supplier_blacklisted'); ?></span><?php endif; ?>
                                        </div>
                                        <?php if (!$done): ?>
                                        <div class="d-flex gap-1">
                                            <?php if (!$isImport && $canCreatePo): ?>
                                            <a class="btn btn-sm btn-outline-primary"
                                               href="purchase_order.php?from_budget=<?php echo $id; ?>&supplier=<?php echo $g['supplier_id']; ?>"<?php echo $gConfirm; ?>>
                                                <i class="bi bi-file-earmark-text"></i> <?php _e('create_po'); ?>
                                            </a>
                                            <?php endif; ?>
                                            <?php if ($canCreate): ?>
                                            <a class="btn btn-sm btn-primary"
                                               href="<?php echo $isImport ? 'purchase_import_form.php' : 'purchases.php'; ?>?from_budget=<?php echo $id; ?>&supplier=<?php echo $g['supplier_id']; ?>"<?php echo $gConfirm; ?>>
                                                <i class="bi bi-plus"></i> <?php echo $isImport ? __('create_purchase_import') : __('create_purchase'); ?>
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                        <?php else: ?>
                                        <span class="badge bg-success"><i class="bi bi-check-circle"></i> <?php _e('fully_procured'); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <table class="table table-sm mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th><?php _e('product'); ?></th>
                                                <th class="text-end"><?php _e('quantity'); ?></th>
                                                <th class="text-end"><?php _e('procured'); ?></th>
                                                <?php if (!$isImport): ?>
                                                <th class="text-end"><?php _e('on_po'); ?></th>
                                                <?php endif; ?>
                                                <th class="text-end"><?php _e('remaining'); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($g['rows'] as $r): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($r['product_name']); ?></td>
                                                <td class="text-end"><?php echo formatNumber($r['qty'], 3); ?></td>
                                                <td class="text-end"><?php echo formatNumber($r['created'], 3); ?></td>
                                                <?php if (!$isImport): ?>
                                                <td class="text-end"><?php echo formatNumber($r['on_po'], 3); ?></td>
                                                <?php endif; ?>
                                                <td class="text-end <?php echo $r['remaining'] > 0 ? 'text-warning' : 'text-success'; ?>"><?php echo formatNumber($r['remaining'], 3); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <?php /* What will actually land in the VAT box of the
                                                document raised from this group — pro-rated to the
                                                quantity still outstanding. Shown only when there
                                                is VAT, so single-rate budgets look unchanged. */ ?>
                                        <?php if (!$done && $g['vat_remaining'] > 0): ?>
                                        <tfoot class="table-light">
                                            <tr>
                                                <th colspan="<?php echo $isImport ? 3 : 4; ?>" class="text-end small"><?php _e('vat_to_carry'); ?></th>
                                                <th class="text-end small"><?php echo formatNumber($g['vat_remaining'], 2); ?></th>
                                            </tr>
                                        </tfoot>
                                        <?php endif; ?>
                                    </table>
                                    <?php /* Value of this group at the BUDGET rate, incl. VAT.
                                            Added below the quantity table, which is unchanged. */ ?>
                                    <?php if (isset($g['val_budgeted'])): ?>
                                    <table class="table table-sm table-bordered mb-0 mt-2 small">
                                        <thead class="table-light">
                                            <tr>
                                                <th class="text-end"><?php _e('budget_val_budgeted'); ?></th>
                                                <th class="text-end"><?php _e('procured'); ?></th>
                                                <?php if (!$isImport): ?>
                                                <th class="text-end"><?php _e('on_po'); ?></th>
                                                <?php endif; ?>
                                                <th class="text-end"><?php _e('remaining'); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td class="text-end"><?php echo formatNumber($g['val_budgeted'], 2); ?></td>
                                                <td class="text-end"><?php echo formatNumber($g['val_procured'], 2); ?></td>
                                                <?php if (!$isImport): ?>
                                                <td class="text-end"><?php echo formatNumber($g['val_on_po'], 2); ?></td>
                                                <?php endif; ?>
                                                <td class="text-end fw-semibold <?php echo $g['val_remaining'] > 0.005 ? 'text-warning' : 'text-success'; ?>"><?php echo formatNumber($g['val_remaining'], 2); ?></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    <div class="text-muted small mt-1"><i class="bi bi-info-circle"></i> <?php _e('budget_val_hint'); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if (!empty($linkedPurchases) || !empty($linkedImports) || !empty($linkedPos)): ?>
                    <div class="alert alert-success mt-3">
                        <div class="mb-1"><i class="bi bi-bag-check"></i> <strong><?php _e('budget_linked_docs'); ?>:</strong></div>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ($linkedPos as $lpo): ?>
                            <a href="purchase_order_view.php?id=<?php echo (int)$lpo['id']; ?>" class="badge bg-light text-dark border text-decoration-none">
                                <i class="bi bi-file-earmark-text"></i> <?php echo htmlspecialchars($lpo['po_number']); ?>
                                <span class="text-muted">(<?php _e('status_' . $lpo['status']); ?>)</span>
                            </a>
                            <?php endforeach; ?>
                            <?php foreach ($linkedPurchases as $lp): ?>
                            <a href="purchases_view.php?id=<?php echo (int)$lp['id']; ?>" class="badge bg-light text-dark border text-decoration-none">
                                <i class="bi bi-cart"></i> <?php echo htmlspecialchars($lp['purchase_number']); ?>
                            </a>
                            <?php endforeach; ?>
                            <?php foreach ($linkedImports as $limp): ?>
                            <a href="purchase_imports.php?view=<?php echo (int)$limp['id']; ?>" class="badge bg-light text-dark border text-decoration-none">
                                <i class="bi bi-globe2"></i> <?php echo htmlspecialchars($limp['import_number']); ?>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Status actions -->
                <?php if ($status === 'draft'): ?>
                <div class="mt-4 no-print">
                    <?php if ($canSendBudget): ?>
                    <button type="button" class="btn btn-warning" onclick="sendForApproval(<?php echo $id; ?>)"><i class="bi bi-send"></i> <?php _e('send_for_approval'); ?></button>
                    <?php endif; ?>
                    <?php if ($canEditBudget): ?>
                    <a href="budget.php?id=<?php echo $id; ?>" class="btn btn-primary"><i class="bi bi-pencil"></i> <?php _e('edit'); ?></a>
                    <?php endif; ?>
                    <?php if (hasPermission('budget', 'delete')): ?>
                    <button type="button" class="btn btn-danger" onclick="deleteBudget(<?php echo $id; ?>)"><i class="bi bi-trash"></i> <?php _e('delete'); ?></button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($status === 'pending_approval'): ?>
                <div class="mt-4 no-print">
                    <?php if ($canApprove): ?>
                    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#approveBudgetModal"><i class="bi bi-check-circle"></i> <?php _e('approve'); ?></button>
                    <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#rejectBudgetModal"><i class="bi bi-x-circle"></i> <?php _e('reject'); ?></button>
                    <?php endif; ?>
                    <?php if ($canEditBudget): ?>
                    <a href="budget.php?id=<?php echo $id; ?>" class="btn btn-primary"
                       title="<?php echo htmlspecialchars(__('budget_edit_pending_reverts_hint')); ?>"><i class="bi bi-pencil"></i> <?php _e('edit'); ?></a>
                    <?php endif; ?>
                    <?php if (!$canApprove && !$canEditBudget): ?>
                    <div class="alert alert-info mb-0"><i class="bi bi-hourglass-split"></i> <?php _e('status_pending_approval'); ?></div>
                    <?php endif; ?>
                    <?php if ($canEditBudget): ?>
                    <div class="small text-muted mt-2"><i class="bi bi-info-circle"></i> <?php _e('budget_edit_pending_reverts_hint'); ?></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($status === 'approved' && $canEditBudget): ?>
                <!-- An approved budget stays editable until procurement starts against it.
                     budget_list.php already offered this; the view page did not, so the
                     same budget looked editable from one page and locked from the other.
                     Editing here does NOT change the status — it stays approved. -->
                <div class="mt-4 no-print">
                    <a href="budget.php?id=<?php echo $id; ?>" class="btn btn-primary"><i class="bi bi-pencil"></i> <?php _e('edit'); ?></a>
                    <div class="small text-muted mt-2"><i class="bi bi-info-circle"></i> <?php _e('budget_edit_approved_hint'); ?></div>
                </div>
                <?php endif; ?>

                <div class="text-muted small mt-3">
                    <?php _e('created_by'); ?>: <?php echo htmlspecialchars($budget['created_by_name'] ?? '-'); ?> &middot; <?php echo formatDateTime($budget['created_at']); ?>
                    <?php if ($budget['approved_by_name']): ?>
                    &nbsp;|&nbsp; <?php _e('approved_by'); ?>: <?php echo htmlspecialchars($budget['approved_by_name']); ?> &middot; <?php echo formatDateTime($budget['approved_at']); ?>
                    <?php endif; ?>
                </div>

                <!-- Footer / Signatures (hide empty boxes) — same pattern as the RV view -->
                <?php
                $bHasPreparedSig = !empty($budget['prepared_by_signature']);
                $bHasApprovedSig = !empty($budget['approved_by_signature']);
                ?>
                <?php if ($bHasPreparedSig || $bHasApprovedSig): ?>
                <style>
                    .doc-sig-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 40px; padding-top: 16px; border-top: 1px solid #ddd; }
                    .doc-sig-box { text-align: center; padding: 10px 6px; }
                    .doc-sig-box img { max-height: 80px; max-width: 100%; display: block; margin: 0 auto 4px auto; }
                    .doc-sig-box .doc-sig-role { font-weight: 600; border-top: 1px solid #333; padding-top: 6px; }
                    .doc-sig-box .doc-sig-name { font-size: 13px; }
                    .doc-sig-box .doc-sig-date { font-size: 11px; color: #666; }
                </style>
                <div class="doc-sig-grid">
                    <?php if ($bHasPreparedSig): ?>
                    <div class="doc-sig-box">
                        <img src="<?php echo htmlspecialchars($budget['prepared_by_signature']); ?>" alt="Prepared By Signature">
                        <div class="doc-sig-role"><?php _e('prepared_by'); ?></div>
                        <div class="doc-sig-name"><?php echo htmlspecialchars($budget['created_by_name'] ?: '-'); ?></div>
                        <div class="doc-sig-date"><?php echo formatDateTime($budget['created_at']); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($bHasApprovedSig): ?>
                    <div class="doc-sig-box">
                        <img src="<?php echo htmlspecialchars($budget['approved_by_signature']); ?>" alt="Approved By Signature">
                        <div class="doc-sig-role"><?php _e('approved_by'); ?></div>
                        <div class="doc-sig-name"><?php echo htmlspecialchars($budget['approved_by_name'] ?: '-'); ?></div>
                        <div class="doc-sig-date"><?php echo $budget['approved_at'] ? formatDateTime($budget['approved_at']) : ''; ?></div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($canApprove): ?>
<!-- Approve modal -->
<div class="modal fade" id="approveBudgetModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-check-circle"></i> <?php _e('approve_budget'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-2"><?php _e('budget_approve_hint'); ?></p>
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-bordered align-middle">
                        <thead class="table-light">
                            <tr>
                                <th style="width:4%;"><?php _e('sr'); ?></th>
                                <th><?php _e('product'); ?></th>
                                <th><?php _e('supplier'); ?></th>
                                <th style="width:10%;"><?php _e('procurement_type'); ?></th>
                                <th style="width:12%;" class="text-end"><?php _e('quantity'); ?></th>
                                <th style="width:12%;" class="text-end"><?php _e('rate'); ?></th>
                                <th style="width:12%;" class="text-end"><?php _e('amount'); ?></th>
                                <?php /* The approver can change quantity and rate, so they must be
                                        able to change VAT too — otherwise cutting a rate would
                                        leave the stored VAT overstated. */ ?>
                                <th style="width:9%;" class="text-end"><?php _e('vat_percent'); ?></th>
                                <th style="width:12%;" class="text-end"><?php _e('vat_amount'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($details as $i => $d): $amt = (float)$d['quantity'] * (float)$d['rate']; ?>
                            <tr>
                                <td><?php echo $i + 1; ?></td>
                                <td><?php echo htmlspecialchars($d['product_name']); ?></td>
                                <td><?php echo htmlspecialchars($d['supplier_name'] ?? '-'); ?></td>
                                <td><?php _e($d['procurement_type']); ?></td>
                                <td class="text-end">
                                    <input type="number" class="form-control form-control-sm text-end approve-qty" data-detail-id="<?php echo (int)$d['id']; ?>"
                                           data-product-id="<?php echo (int)$d['product_id']; ?>"
                                           value="<?php echo (float)$d['quantity']; ?>" min="0.001" step="0.001">
                                </td>
                                <td class="text-end">
                                    <input type="number" class="form-control form-control-sm text-end approve-rate" data-detail-id="<?php echo (int)$d['id']; ?>"
                                           value="<?php echo (float)$d['rate']; ?>" min="0" step="0.01">
                                </td>
                                <td class="text-end align-middle approve-amount" data-detail-id="<?php echo (int)$d['id']; ?>"><?php echo formatNumber($amt, 2); ?></td>
                                <td class="text-end">
                                    <input type="number" class="form-control form-control-sm text-end approve-vat-pct" data-detail-id="<?php echo (int)$d['id']; ?>"
                                           value="<?php echo ($d['vat_percent'] !== null && $d['vat_percent'] !== '') ? (float)$d['vat_percent'] : ''; ?>"
                                           min="0" max="100" step="0.001" placeholder="%">
                                </td>
                                <td class="text-end">
                                    <input type="number" class="form-control form-control-sm text-end approve-vat-amt" data-detail-id="<?php echo (int)$d['id']; ?>"
                                           value="<?php echo (float)($d['vat_amount'] ?? 0); ?>" min="0" step="0.01">
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="6" class="text-end"><?php _e('goods'); ?></th>
                                <th class="text-end" id="approveGoodsTotal"><?php echo formatNumber($goodsTotal, 2); ?></th>
                                <th colspan="2"></th>
                            </tr>
                            <tr>
                                <th colspan="6" class="text-end"><?php _e('vat_amount'); ?></th>
                                <th></th>
                                <th></th>
                                <th class="text-end" id="approveVatTotal"><?php echo formatNumber($vatTotal, 2); ?></th>
                            </tr>
                            <tr class="table-active">
                                <th colspan="6" class="text-end"><?php _e('budget_total'); ?></th>
                                <th colspan="3" class="text-end" id="approveBudgetTotal"><?php echo formatNumber($grandTotal, 2); ?></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php
                // Per-supplier subtotals, recomputed live by recalcApprove() from the
                // inputs above. $approveGroupOf maps each detail row to its group index.
                $approveGroupIdx = [];
                $approveGroupOf  = [];
                foreach ($supplierSummary as $sk => $ss) { $approveGroupIdx[$sk] = count($approveGroupIdx); }
                foreach ($details as $d) {
                    $approveGroupOf[(int)$d['id']] = $approveGroupIdx[$d['supplier_id'] . '|' . $d['procurement_type']];
                }
                ?>
                <h6 class="mb-2"><i class="bi bi-people"></i> <?php _e('budget_supplier_summary'); ?></h6>
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-bordered align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th><?php _e('supplier'); ?></th>
                                <th style="width:10%;"><?php _e('procurement_type'); ?></th>
                                <th style="width:16%;" class="text-end"><?php _e('goods'); ?></th>
                                <th style="width:16%;" class="text-end"><?php _e('vat_amount'); ?></th>
                                <th style="width:16%;" class="text-end"><?php _e('total'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($supplierSummary as $sk => $ss): $gi = $approveGroupIdx[$sk]; ?>
                            <tr>
                                <td><?php echo htmlspecialchars($ss['supplier_name'] ?? '-'); ?></td>
                                <td><?php _e($ss['type']); ?></td>
                                <td class="text-end" id="approveSupGoods<?php echo $gi; ?>"><?php echo formatNumber($ss['goods'], 2); ?></td>
                                <td class="text-end" id="approveSupVat<?php echo $gi; ?>"><?php echo formatNumber($ss['vat'], 2); ?></td>
                                <td class="text-end fw-semibold" id="approveSupTotal<?php echo $gi; ?>"><?php echo formatNumber($ss['goods'] + $ss['vat'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <label class="form-label fw-bold">Signature <small class="text-muted">(optional)</small></label>
                <?php $myMasterSig = getUserSignature($conn, getUserId()); ?>
                <?php if ($myMasterSig): ?>
                <div class="border rounded p-2 bg-light">
                    <img src="<?php echo htmlspecialchars($myMasterSig); ?>" alt="<?php _e('signature'); ?>" style="max-height:90px;max-width:100%;">
                    <div class="text-muted small mt-1"><i class="bi bi-info-circle"></i> <?php _e('signature_from_profile'); ?></div>
                </div>
                <?php else: ?>
                <div style="border:1px dashed #adb5bd;border-radius:4px;background:#fafafa;position:relative;">
                    <canvas id="budgetApproveSig" style="display:block;width:100%;height:160px;touch-action:none;cursor:crosshair;"></canvas>
                    <span id="budgetApproveSigPh" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);color:#adb5bd;font-style:italic;pointer-events:none;font-size:13px;">Sign here</span>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary mt-1" onclick="clearBudgetSig()"><i class="bi bi-x-circle"></i> <?php _e('clear'); ?></button>
                <?php
                /* Not everyone has a touchscreen. Offer an image upload as an alternative
                   to drawing; it is loaded INTO the pad above, so the submit code below is
                   unchanged. Only reachable in this else-branch, i.e. only for signers with
                   no signature on file. */
                $sigUploadCanvasId      = 'budgetApproveSig';
                $sigUploadPlaceholderId = 'budgetApproveSigPh';
                require __DIR__ . '/../../includes/signature_upload_control.php';
                ?>
                <?php endif; ?>
                <div class="mt-3">
                    <label class="form-label fw-bold"><?php _e('remarks'); ?> <small class="text-muted">(<?php _e('optional'); ?>)</small></label>
                    <textarea id="budgetApproveRemark" class="form-control" rows="2"></textarea>
                </div>
                <?php
                // One reason box per distinct product in this budget, revealed by
                // refreshApproveExcess() only when that product goes over. Rendered for
                // every product (not injected on demand) so a pre-existing reason from
                // the creator is present and pre-filled the moment the modal opens.
                $approveProductsSeen = [];
                ?>
                <div id="approveExcessWrap" class="mt-2">
                    <?php foreach ($details as $d):
                        $apid = (int)$d['product_id'];
                        if (isset($approveProductsSeen[$apid])) continue;
                        $approveProductsSeen[$apid] = true;
                    ?>
                    <div class="approve-excess-wrap mb-2" data-product-id="<?php echo $apid; ?>" style="display:none;">
                        <label class="form-label small mb-1 text-danger fw-bold">
                            <i class="bi bi-exclamation-triangle"></i>
                            <?php echo htmlspecialchars($d['product_name']); ?> &mdash;
                            <?php _e('budget_excess_reason_label'); ?>
                            <span class="approve-excess-badge badge bg-danger ms-1"></span>
                        </label>
                        <textarea class="form-control form-control-sm approve-excess-reason"
                                  data-product-id="<?php echo $apid; ?>" rows="2" maxlength="500"
                                  placeholder="<?php echo htmlspecialchars(__('budget_excess_reason_placeholder')); ?>"><?php echo htmlspecialchars($excessReasonByProduct[$apid] ?? ''); ?></textarea>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div id="approveBudgetError" class="alert alert-danger mt-2" style="display:none;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                <button type="button" class="btn btn-success" id="approveBudgetBtn" onclick="submitApprove(<?php echo $id; ?>)"><i class="bi bi-check-circle"></i> <?php _e('approve'); ?></button>
            </div>
        </div>
    </div>
</div>

<!-- Reject modal -->
<div class="modal fade" id="rejectBudgetModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-x-circle text-danger"></i> <?php _e('reject_budget'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p><?php _e('confirm_reject_budget'); ?></p>
                <label class="form-label"><?php _e('reject_reason'); ?> <small class="text-muted">(<?php _e('optional'); ?>)</small></label>
                <textarea id="budgetRejectReason" class="form-control" rows="3"></textarea>
                <div id="rejectBudgetError" class="alert alert-danger mt-2" style="display:none;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                <button type="button" class="btn btn-danger" id="rejectBudgetBtn" onclick="submitReject(<?php echo $id; ?>)"><i class="bi bi-x-circle"></i> <?php _e('reject'); ?></button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="<?php echo BASE_URL; ?>assets/js/signature_pad.js"></script>
<script>
const BUDGET_API = '<?php echo BASE_URL; ?>api/budgets-sync.php';

function postBudget(payload, onOk, errBoxId, btnId, btnHtml) {
    const errBox = errBoxId ? document.getElementById(errBoxId) : null;
    const btn = btnId ? document.getElementById(btnId) : null;
    if (errBox) errBox.style.display = 'none';
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>...'; }
    fetch(BUDGET_API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
        .then(r => r.json())
        .then(data => {
            if (data.success) { onOk(data); }
            else {
                if (errBox) { errBox.textContent = data.error || data.message || 'Error'; errBox.style.display = ''; }
                else { alert(data.error || data.message || 'Error'); }
                if (btn) { btn.disabled = false; btn.innerHTML = btnHtml; }
            }
        })
        .catch(e => {
            if (errBox) { errBox.textContent = 'Network error: ' + e.message; errBox.style.display = ''; }
            else { alert('Network error: ' + e.message); }
            if (btn) { btn.disabled = false; btn.innerHTML = btnHtml; }
        });
}

function sendForApproval(id) {
    if (!confirm('<?php echo addslashes(__('confirm_send_for_approval')); ?>')) return;
    postBudget({ action: 'send_for_approval', id: id }, () => location.reload());
}
function deleteBudget(id) {
    if (!confirm('<?php echo addslashes(__('confirm_delete_budget')); ?>')) return;
    postBudget({ action: 'delete', id: id }, () => { location.href = 'budget_list.php'; });
}

<?php if ($canApprove): ?>
const APPROVE_CAP = <?php echo json_encode((object)$approveCap); ?>;
const EXCESS_REASONS = <?php echo json_encode((object)$excessReasonByProduct); ?>;
const APPROVE_GROUP_OF = <?php echo json_encode((object)$approveGroupOf); ?>;
let budgetSigPad = null;
function clearBudgetSig() { if (budgetSigPad) { budgetSigPad.clear(); document.getElementById('budgetApproveSigPh').style.display = ''; } }

// Recompute each row's Amount (qty x rate) and the total as the approver edits.
function fmtBudgetAmt(n) {
    if (n === null || n === undefined || isNaN(n)) return '0.00';
    return parseFloat(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
// Quantities are decimal(15,3); trailing zeros trimmed, matching the budget form.
function fmtBudgetQty(n) {
    if (n === null || n === undefined || isNaN(n)) return '0';
    return parseFloat(n).toFixed(3).replace(/\.?0+$/, '');
}
const BUDGET_T = {
    over_by: '<?php echo addslashes(__('budget_over_by')); ?>'
};
/**
 * Keep one approve-row's VAT % and VAT amount consistent — same rule as the
 * budget form: the field the user touched wins, and a change to qty/rate
 * re-derives the amount only when a % is set (a hand-typed flat amount is
 * never silently overwritten).
 */
function syncApproveVat(detailId, source) {
    const q = document.querySelector('#approveBudgetModal .approve-qty[data-detail-id="' + detailId + '"]');
    const r = document.querySelector('#approveBudgetModal .approve-rate[data-detail-id="' + detailId + '"]');
    const p = document.querySelector('#approveBudgetModal .approve-vat-pct[data-detail-id="' + detailId + '"]');
    const a = document.querySelector('#approveBudgetModal .approve-vat-amt[data-detail-id="' + detailId + '"]');
    if (!q || !r || !p || !a) return;

    const base = (parseFloat(q.value) || 0) * (parseFloat(r.value) || 0);

    if (source === 'pct') {
        const pct = parseFloat(p.value);
        a.value = (!isNaN(pct) && pct >= 0 && base > 0) ? (base * pct / 100).toFixed(2)
                : (p.value === '' ? a.value : '0.00');
    } else if (source === 'amt') {
        const amt = parseFloat(a.value);
        p.value = (!isNaN(amt) && amt > 0 && base > 0) ? (amt / base * 100).toFixed(3) : '';
    } else {
        const pct = parseFloat(p.value);
        if (!isNaN(pct) && pct >= 0) a.value = base > 0 ? (base * pct / 100).toFixed(2) : '0.00';
    }
}

// Reveal a reason box for each product whose total now exceeds its cap, and hide
// it again when the approver brings the quantity back down. Text already typed is
// left in place, so lowering and re-raising a quantity does not lose the reason.
function refreshApproveExcess() {
    const perProduct = {};
    document.querySelectorAll('#approveBudgetModal .approve-qty').forEach(input => {
        const pid = input.dataset.productId;
        perProduct[pid] = (perProduct[pid] || 0) + (parseFloat(input.value) || 0);
    });
    document.querySelectorAll('#approveBudgetModal .approve-excess-wrap').forEach(wrap => {
        const pid = wrap.dataset.productId;
        const cap = (APPROVE_CAP[pid] !== undefined) ? APPROVE_CAP[pid] : Infinity;
        const excess = (perProduct[pid] || 0) - cap;
        if (excess > 0.0005) {
            wrap.style.display = '';
            const badge = wrap.querySelector('.approve-excess-badge');
            if (badge) badge.textContent = BUDGET_T.over_by.replace('%s', fmtBudgetQty(excess));
        } else {
            wrap.style.display = 'none';
        }
    });
}

function recalcApprove() {
    let goods = 0, vat = 0;
    const sup = {}; // group index -> { g, v } for the Supplier Summary in the modal
    document.querySelectorAll('#approveBudgetModal .approve-qty').forEach(input => {
        const detailId = input.dataset.detailId;
        const qty = parseFloat(input.value) || 0;
        const rateInput = document.querySelector('#approveBudgetModal .approve-rate[data-detail-id="' + detailId + '"]');
        const rate = rateInput ? (parseFloat(rateInput.value) || 0) : 0;
        const amt = qty * rate;
        goods += amt;
        const vatInput = document.querySelector('#approveBudgetModal .approve-vat-amt[data-detail-id="' + detailId + '"]');
        const lineVat = vatInput ? (parseFloat(vatInput.value) || 0) : 0;
        vat += lineVat;
        const gi = APPROVE_GROUP_OF[detailId];
        if (gi !== undefined) {
            if (!sup[gi]) sup[gi] = { g: 0, v: 0 };
            sup[gi].g += amt;
            sup[gi].v += lineVat;
        }
        const cell = document.querySelector('#approveBudgetModal .approve-amount[data-detail-id="' + detailId + '"]');
        if (cell) cell.textContent = fmtBudgetAmt(amt);
    });
    Object.keys(sup).forEach(gi => {
        const gEl = document.getElementById('approveSupGoods' + gi);
        const vEl = document.getElementById('approveSupVat' + gi);
        const tEl = document.getElementById('approveSupTotal' + gi);
        if (gEl) gEl.textContent = fmtBudgetAmt(sup[gi].g);
        if (vEl) vEl.textContent = fmtBudgetAmt(sup[gi].v);
        if (tEl) tEl.textContent = fmtBudgetAmt(sup[gi].g + sup[gi].v);
    });
    const goodsEl = document.getElementById('approveGoodsTotal');
    const vatEl = document.getElementById('approveVatTotal');
    const totalEl = document.getElementById('approveBudgetTotal');
    if (goodsEl) goodsEl.textContent = fmtBudgetAmt(goods);
    if (vatEl) vatEl.textContent = fmtBudgetAmt(vat);
    if (totalEl) totalEl.textContent = fmtBudgetAmt(goods + vat);
    refreshApproveExcess();
}

document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('approveBudgetModal');
    if (modal) {
        modal.addEventListener('input', function (e) {
            const id = e.target.dataset ? e.target.dataset.detailId : null;
            if (e.target.classList.contains('approve-qty') || e.target.classList.contains('approve-rate')) {
                if (id) syncApproveVat(id, 'base');
                recalcApprove();
            } else if (e.target.classList.contains('approve-vat-pct')) {
                if (id) syncApproveVat(id, 'pct');
                recalcApprove();
            } else if (e.target.classList.contains('approve-vat-amt')) {
                if (id) syncApproveVat(id, 'amt');
                recalcApprove();
            }
        });
        modal.addEventListener('shown.bs.modal', function () {
            recalcApprove();
            const c = document.getElementById('budgetApproveSig');
            if (c && window.SignaturePad) {
                budgetSigPad = new SignaturePad(c, { penColor: '#000', lineWidth: 2 });
                c.addEventListener('pointerdown', function () { document.getElementById('budgetApproveSigPh').style.display = 'none'; });
            }
        });
    }
});
function submitApprove(id) {
    const items = [];
    let bad = false;
    document.querySelectorAll('#approveBudgetModal .approve-qty').forEach(input => {
        const detailId = parseInt(input.dataset.detailId, 10);
        const qty = parseFloat(input.value);
        const rateInput = document.querySelector('#approveBudgetModal .approve-rate[data-detail-id="' + detailId + '"]');
        const rate = rateInput ? parseFloat(rateInput.value) : 0;
        const pctInput = document.querySelector('#approveBudgetModal .approve-vat-pct[data-detail-id="' + detailId + '"]');
        const vatInput = document.querySelector('#approveBudgetModal .approve-vat-amt[data-detail-id="' + detailId + '"]');
        const pctRaw = pctInput ? pctInput.value.trim() : '';
        const vatAmt = vatInput ? parseFloat(vatInput.value) : 0;
        if (isNaN(qty) || qty <= 0) { bad = true; }
        items.push({
            detail_id: detailId,
            quantity: qty,
            rate: isNaN(rate) ? 0 : rate,
            // null (not 0) when no percentage was given, so a flat amount is
            // stored as a flat amount rather than as "0%".
            vat_percent: pctRaw === '' ? null : parseFloat(pctRaw),
            vat_amount: isNaN(vatAmt) ? 0 : vatAmt
        });
    });
    const errBox = document.getElementById('approveBudgetError');
    if (bad) { errBox.textContent = '<?php echo addslashes(__('budget_alloc_needs_qty_all')); ?>'; errBox.style.display = ''; return; }

    // Enforce the approved-quantity cap per product (approver may reduce, not exceed).
    const perProduct = {};
    document.querySelectorAll('#approveBudgetModal .approve-qty').forEach(input => {
        const pid = input.dataset.productId;
        perProduct[pid] = (perProduct[pid] || 0) + (parseFloat(input.value) || 0);
    });
    // Going over the requisition quantity is allowed here too, provided a reason
    // is recorded per product. Mirrors the creator's form and the server check in
    // budgets-sync.php, so no path can be blocked by another.
    const excessRemarks = {};
    let missingReason = false;
    for (const pid in perProduct) {
        const cap = (APPROVE_CAP[pid] !== undefined) ? APPROVE_CAP[pid] : Infinity;
        if (perProduct[pid] <= cap + 0.0005) continue;
        const box = document.querySelector('#approveBudgetModal .approve-excess-reason[data-product-id="' + pid + '"]');
        const reason = box ? box.value.trim() : '';
        if (reason === '') { missingReason = true; break; }
        excessRemarks[pid] = reason;
    }
    if (missingReason) {
        errBox.textContent = '<?php echo addslashes(__('budget_over_needs_reason_block')); ?>';
        errBox.style.display = '';
        refreshApproveExcess();
        return;
    }

    const sig = (budgetSigPad && !budgetSigPad.isEmpty()) ? budgetSigPad.toDataURL() : null;
    const remarkEl = document.getElementById('budgetApproveRemark');
    const approveRemark = remarkEl ? remarkEl.value : '';
    postBudget({ action: 'approve', id: id, approved_by_signature: sig, approve_remark: approveRemark, items: items, excess_remarks: excessRemarks },
        () => location.reload(), 'approveBudgetError', 'approveBudgetBtn', '<i class="bi bi-check-circle"></i> <?php echo addslashes(__('approve')); ?>');
}
function submitReject(id) {
    const reason = document.getElementById('budgetRejectReason').value;
    postBudget({ action: 'reject', id: id, reject_reason: reason },
        () => location.reload(), 'rejectBudgetError', 'rejectBudgetBtn', '<i class="bi bi-x-circle"></i> <?php echo addslashes(__('reject')); ?>');
}
<?php endif; ?>
</script>

<?php require_once '../../includes/footer.php'; ?>
