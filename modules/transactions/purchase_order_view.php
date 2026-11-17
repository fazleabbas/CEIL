<?php
/**
 * Purchase Order view — full document with status timeline and lifecycle
 * actions (send for approval, approve-with-edit, reject, revise, cancel)
 * posted to api/purchase-orders-sync.php. Printable.
 *
 * A PO never touches stock or the GL. Once approved it is converted into
 * Purchase(s); per-line purchased_quantity accrues and the PO auto-closes
 * when fully received.
 *
 * NOTE — "Link to Budget" (attaching a PO to an approved budget after the
 * fact) is available for a PO of ANY status, as long as it is NOT ALREADY
 * LINKED. Purchases already raised from the PO are moved onto the same budget
 * with it. See the $canLinkBudget block below and poLinkBudget() in
 * api/purchase-orders-sync.php, which re-checks every condition.
 */
require_once '../../config/database.php';
require_once '../../includes/functions.php';
$pageTitle = __('purchase_order');
if (!hasPermission('purchase_orders', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('');
}

$id = (int)($_GET['id'] ?? 0);
$stmt = $conn->prepare("
    SELECT po.*,
           la.name AS supplier_name,
           l.name AS location_name,
           cc.name AS cost_centre_name, cc.code AS cost_centre_code,
           cur.code AS currency_code, cur.is_default AS currency_is_default,
           b.budget_number,
           u.name AS created_by_name,
           ua.name AS approved_by_name,
           ur.name AS rejected_by_name,
           uc.name AS closed_by_name
    FROM purchase_orders po
    LEFT JOIN suppliers s ON po.supplier_id = s.id
    LEFT JOIN ledger_accounts la ON s.ledger_account_id = la.id
    LEFT JOIN locations l ON po.location_id = l.id
    LEFT JOIN cost_centres cc ON po.cost_centre_id = cc.id
    LEFT JOIN currencies cur ON po.currency_id = cur.id
    LEFT JOIN budgets b ON po.budget_id = b.id
    LEFT JOIN users u ON po.created_by = u.id
    LEFT JOIN users ua ON po.approved_by = ua.id
    LEFT JOIN users ur ON po.rejected_by = ur.id
    LEFT JOIN users uc ON po.closed_by = uc.id
    WHERE po.id = ?
");
$stmt->execute([$id]);
$po = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$po) {
    setAlert(__('po_not_found'), 'danger');
    redirect('modules/transactions/purchase_order_list.php');
}
$status = $po['status'];

$detStmt = $conn->prepare("
    SELECT pod.*, p.name AS product_name, p.unit
    FROM purchase_order_details pod
    JOIN products p ON pod.product_id = p.id
    WHERE pod.purchase_order_id = ?
    ORDER BY pod.id
");
$detStmt->execute([$id]);
$details = $detStmt->fetchAll(PDO::FETCH_ASSOC);

// Effective line values: the approver's overrides win once set.
$isPostApproval = in_array($status, ['approved', 'closed'], true);
$goodsTotal = 0;
foreach ($details as &$d) {
    $d['eff_qty'] = ($isPostApproval && $d['approved_quantity'] !== null) ? (float)$d['approved_quantity'] : (float)$d['quantity'];
    $d['eff_rate'] = ($isPostApproval && $d['approved_rate'] !== null) ? (float)$d['approved_rate'] : (float)$d['rate_per_unit'];
    $d['eff_amount'] = $d['eff_qty'] * $d['eff_rate'];
    $d['remaining'] = max($d['eff_qty'] - (float)$d['purchased_quantity'], 0);
    $goodsTotal += $d['eff_amount'];
}
unset($d);
$vatAmount = (float)$po['vat_amount'];
$payable = $goodsTotal + $vatAmount;

// Linked purchases raised from this PO.
$linkedPurchases = [];
$lp = $conn->prepare("SELECT id, purchase_number FROM purchases WHERE purchase_order_id = ? ORDER BY id");
$lp->execute([$id]);
$linkedPurchases = $lp->fetchAll(PDO::FETCH_ASSOC);

$canEdit = hasPermission('purchase_orders', 'edit');
$canDelete = hasPermission('purchase_orders', 'delete');
$canApprove = hasPermission('purchase_orders', 'approve');
$canCreatePurchase = hasPermission('purchases', 'create');
$totalRemaining = 0;
foreach ($details as $d) $totalRemaining += $d['remaining'];
$nothingPurchased = true;
foreach ($details as $d) {
    if ((float)$d['purchased_quantity'] > 0.0005) { $nothingPurchased = false; break; }
}

// ---------------------------------------------------------------------------
// Link a PO to an approved budget after the fact.
//
// Offered for ANY status, as long as the PO is not already linked. Where
// purchases have already been raised from it, linking also moves those
// purchase rows onto the budget, so the received quantity shows as "Procured"
// rather than vanishing from the budget's figures.
// api/purchase-orders-sync.php re-checks everything and does both writes in
// one transaction.
// ---------------------------------------------------------------------------
$canLinkBudget = $canEdit && empty($po['budget_id']);

$linkBudgetCandidates = [];
if ($canLinkBudget) {
    // Approved budgets holding 'purchase'-type allocations for this supplier.
    $cbStmt = $conn->prepare("
        SELECT DISTINCT b.id, b.budget_number, b.budget_date
        FROM budgets b
        JOIN budget_details bd ON bd.budget_id = b.id
        WHERE b.status = 'approved'
          AND bd.supplier_id = ?
          AND bd.procurement_type = 'purchase'
        ORDER BY b.id DESC
    ");
    $cbStmt->execute([(int)$po['supplier_id']]);

    // What this PO needs, per product (a product may span several lines).
    $poWanted = [];
    foreach ($details as $d) {
        $pid = (int)$d['product_id'];
        $poWanted[$pid] = ($poWanted[$pid] ?? 0) + (float)$d['eff_qty'];
    }

    foreach ($cbStmt->fetchAll(PDO::FETCH_ASSOC) as $cand) {
        $rem   = getBudgetSupplierRemaining((int)$cand['id'], (int)$po['supplier_id'], $conn, $id);
        $rows  = [];
        $fits  = true;
        foreach ($poWanted as $pid => $qty) {
            $name       = null;
            foreach ($details as $d) { if ((int)$d['product_id'] === $pid) { $name = $d['product_name']; break; } }
            $budgeted   = isset($rem[$pid]);
            $available  = $budgeted ? (float)$rem[$pid]['remaining'] : 0.0;
            $ok         = $budgeted && $qty <= $available + 0.0005;
            if (!$ok) $fits = false;
            $rows[] = [
                'product'   => $name ?: ('#' . $pid),
                'wanted'    => $qty,
                'budgeted'  => $budgeted,
                'available' => $available,
                'ok'        => $ok,
            ];
        }
        $linkBudgetCandidates[] = [
            'id'     => (int)$cand['id'],
            'number' => $cand['budget_number'],
            'date'   => $cand['budget_date'],
            'fits'   => $fits,
            'rows'   => $rows,
        ];
    }
}

// Company letterhead for the printed document (same settings the delivery
// notes use — modules/transactions/requisition_voucher_dn.php et al).
$companyName    = getSetting('company_name', 'Company Name');
$companyAddress = getSetting('company_address', '');
$companyPhone   = getSetting('company_phone', '');

require_once '../../includes/header.php';

$statusBadge = function ($s) {
    switch ($s) {
        case 'draft': return 'secondary';
        case 'pending_approval': return 'warning';
        case 'approved': return 'success';
        case 'rejected': return 'danger';
        case 'closed': return 'dark';
        case 'cancelled': return 'light text-dark border';
        default: return 'secondary';
    }
};
?>

<style>
/* The printed document. Hidden on screen; the screen blocks marked
   .po-screen-only are hidden when printing, so each medium gets a layout built
   for it while both share the one items table. */
.po-print-only { display: none; }

@media print {
    @page { size: A4 portrait; margin: 12mm; }

    /* App chrome that has no place on a printed order. .doc-sig-grid is the
       on-screen signature strip — the print sign-off block replaces it, so
       showing both would sign the document twice. */
    .navbar, .demo-banner, .no-print, .btn, .po-screen-only,
    .modal, .modal-backdrop, .breadcrumb, .doc-sig-grid {
        display: none !important;
    }
    /* footer is NOT hidden — includes/footer.php turns it into the Fabonic
       credit line for print. */

    /* Reject / approval / cancellation remarks ARE part of the document, so
       they stay — just without the screen colour wash. */
    .alert {
        border: 1px solid #000 !important; background: #fff !important; color: #000 !important;
        box-shadow: none !important; padding: 6px 8px !important; font-size: 11px; margin-bottom: 10px !important;
    }

    body { background: #fff !important; padding: 0 !important; margin: 0 !important; font-size: 12px; }
    .container, .container-fluid, .row, [class^="col-"], [class*=" col-"] {
        width: 100% !important; max-width: 100% !important; flex: 0 0 100% !important; padding: 0 !important; margin: 0 !important;
    }
    .card { border: 0 !important; box-shadow: none !important; }
    .card-body { padding: 0 !important; }

    .po-print-only { display: block !important; }

    /* The items table lives in a horizontally scrolling wrapper on screen —
       that wrapper is what clipped the right-hand columns and printed the
       "swipe to see more" hint. Unwrap it completely for print. */
    .table-responsive {
        overflow: visible !important;
        overflow-x: visible !important;
        max-height: none !important;
        border: 0 !important;
    }
    .table-responsive::after, .table-responsive::before { content: none !important; display: none !important; }

    table.table { width: 100% !important; font-size: 11px; border-collapse: collapse !important; }
    table.table th, table.table td { border: 1px solid #000 !important; padding: 5px 6px !important; }
    table.table thead th { background: #eee !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    table.table tfoot th { background: #f5f5f5 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    thead { display: table-header-group; }   /* repeat headings across pages */
    tfoot { display: table-row-group; }      /* keep totals with the last rows */
    tr, td, th { page-break-inside: avoid; }

    /* Screen-only colour helpers that read badly in black and white. */
    .text-warning, .text-success, .text-muted { color: #000 !important; }
    h6.text-muted { font-size: 12px; font-weight: bold; margin: 10px 0 6px 0; }
}

/* ---- printed document blocks (styles apply in both media; only shown in print) ---- */
.po-letterhead { text-align: center; border-bottom: 2px solid #000; padding-bottom: 8px; margin-bottom: 14px; }
.po-company { font-size: 20px; font-weight: bold; letter-spacing: .3px; }
.po-company-line { font-size: 11px; color: #333; margin-top: 2px; }

.po-doc-title {
    text-align: center; font-size: 15px; font-weight: bold; letter-spacing: 2px;
    text-transform: uppercase; border: 1px solid #000; padding: 6px; margin-bottom: 14px;
}

.po-meta { width: 100%; border-collapse: collapse; margin-bottom: 14px; font-size: 11.5px; }
.po-meta th, .po-meta td { border: 1px solid #000; padding: 5px 7px; text-align: left; vertical-align: top; }
.po-meta th { width: 15%; background: #f2f2f2; font-weight: 600; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.po-meta td { width: 35%; }

.po-signoff { display: flex; justify-content: space-between; gap: 18px; margin-top: 42px; page-break-inside: avoid; }
.po-signoff-box { width: 31%; text-align: center; }
.po-signoff-box img { max-height: 55px; max-width: 100%; display: block; margin: 0 auto 2px auto; }
.po-signoff-line { border-top: 1px solid #000; padding-top: 4px; margin-top: 34px; font-weight: 600; font-size: 11px; }
.po-signoff-box img + .po-signoff-line { margin-top: 0; }
.po-signoff-name { font-size: 11px; }
.po-signoff-date { font-size: 9.5px; color: #555; }

.po-print-footer {
    margin-top: 18px; padding-top: 6px; border-top: 1px solid #ccc;
    text-align: center; font-size: 9.5px; color: #666;
    page-break-inside: avoid;
}
.po-print-docline { margin-bottom: 3px; }
</style>

<div class="row justify-content-center">
    <div class="col-md-12 col-lg-11">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center no-print">
                <h5 class="mb-0"><i class="bi bi-file-earmark-text"></i> <?php echo htmlspecialchars($po['po_number']); ?></h5>
                <div>
                    <a href="purchase_order_list.php" class="btn btn-sm btn-secondary"><i class="bi bi-list"></i> <?php _e('list'); ?></a>
                    <button onclick="window.print()" class="btn btn-sm btn-secondary"><i class="bi bi-printer"></i> <?php _e('print'); ?></button>
                </div>
            </div>
            <div class="card-body">
                <!-- ============================================================
                     PRINTED DOCUMENT
                     Screen keeps the existing dashboard-style layout; print
                     gets a purpose-built letterhead + meta table + signature
                     block. The ITEMS TABLE below is shared by both — only the
                     surrounding chrome differs, so the figures can never drift
                     between what is on screen and what comes out of a printer.
                     ============================================================ -->
                <div class="po-print-only">
                    <div class="po-letterhead">
                        <?php /* An uploaded banner (Masters -> Company Settings) already carries
                                the address and phone, so it REPLACES these text lines. With no
                                banner set, renderPrintBanner() prints nothing and the original
                                letterhead below is used exactly as before. */ ?>
                        <?php if (!renderPrintBanner()): ?>
                        <div class="po-company"><?php echo htmlspecialchars($companyName); ?></div>
                        <?php if ($companyAddress): ?>
                        <div class="po-company-line"><?php echo htmlspecialchars($companyAddress); ?></div>
                        <?php endif; ?>
                        <?php if ($companyPhone): ?>
                        <div class="po-company-line"><?php _e('phone'); ?>: <?php echo htmlspecialchars($companyPhone); ?></div>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <div class="po-doc-title"><?php _e('purchase_order'); ?></div>

                    <table class="po-meta">
                        <tr>
                            <th><?php _e('po_number'); ?></th>
                            <td><strong><?php echo htmlspecialchars($po['po_number']); ?></strong></td>
                            <th><?php _e('supplier'); ?></th>
                            <td><strong><?php echo htmlspecialchars($po['supplier_name'] ?? '-'); ?></strong></td>
                        </tr>
                        <tr>
                            <th><?php _e('po_date'); ?></th>
                            <td><?php echo formatDate($po['po_date']); ?></td>
                            <th><?php _e('location'); ?></th>
                            <td><?php echo htmlspecialchars($po['location_name'] ?? '-'); ?></td>
                        </tr>
                        <tr>
                            <th><?php _e('expected_delivery_date'); ?></th>
                            <td><?php echo $po['expected_date'] ? formatDate($po['expected_date']) : '-'; ?></td>
                            <th><?php _e('cost_centre'); ?></th>
                            <td><?php echo $po['cost_centre_name']
                                    ? htmlspecialchars(($po['cost_centre_code'] ? $po['cost_centre_code'] . ' - ' : '') . $po['cost_centre_name'])
                                    : '-'; ?></td>
                        </tr>
                        <tr>
                            <th><?php _e('status'); ?></th>
                            <td><?php _e('status_' . $status); ?></td>
                            <th><?php _e('currency'); ?></th>
                            <td>
                                <?php echo htmlspecialchars($po['currency_code'] ?? '-'); ?>
                                <?php if ($po['currency_code'] && !(int)$po['currency_is_default'] && (float)$po['exchange_rate_to_base'] != 1): ?>
                                    @ <?php echo rtrim(rtrim(number_format((float)$po['exchange_rate_to_base'], 8, '.', ''), '0'), '.'); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if (!empty($po['budget_id'])): ?>
                        <tr>
                            <th><?php _e('budget'); ?></th>
                            <td colspan="3"><?php echo htmlspecialchars($po['budget_number'] ?? ('#' . $po['budget_id'])); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($po['payment_terms'])): ?>
                        <tr>
                            <th><?php _e('payment_terms'); ?></th>
                            <td colspan="3"><?php echo nl2br(htmlspecialchars($po['payment_terms'])); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($po['delivery_terms'])): ?>
                        <tr>
                            <th><?php _e('delivery_terms'); ?></th>
                            <td colspan="3"><?php echo nl2br(htmlspecialchars($po['delivery_terms'])); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($po['remarks'])): ?>
                        <tr>
                            <th><?php _e('remarks'); ?></th>
                            <td colspan="3"><?php echo nl2br(htmlspecialchars($po['remarks'])); ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>

                <div class="row mb-4 po-screen-only">
                    <div class="col-md-2">
                        <div class="text-muted small"><?php _e('po_number'); ?></div>
                        <div class="fw-bold"><?php echo htmlspecialchars($po['po_number']); ?></div>
                    </div>
                    <div class="col-md-2">
                        <div class="text-muted small"><?php _e('po_date'); ?></div>
                        <div class="fw-bold"><?php echo formatDate($po['po_date']); ?></div>
                    </div>
                    <div class="col-md-2">
                        <div class="text-muted small"><?php _e('expected_delivery_date'); ?></div>
                        <div class="fw-bold"><?php echo $po['expected_date'] ? formatDate($po['expected_date']) : '-'; ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small"><?php _e('supplier'); ?></div>
                        <div class="fw-bold"><?php echo htmlspecialchars($po['supplier_name'] ?? '-'); ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small"><?php _e('location'); ?></div>
                        <div class="fw-bold"><?php echo htmlspecialchars($po['location_name'] ?? '-'); ?></div>
                    </div>
                </div>
                <div class="row mb-4 po-screen-only">
                    <div class="col-md-2">
                        <div class="text-muted small"><?php _e('status'); ?></div>
                        <div><span class="badge bg-<?php echo $statusBadge($status); ?>"><?php _e('status_' . $status); ?></span></div>
                    </div>
                    <div class="col-md-2">
                        <div class="text-muted small"><?php _e('cost_centre'); ?></div>
                        <div class="fw-bold">
                            <?php echo $po['cost_centre_name']
                                ? htmlspecialchars(($po['cost_centre_code'] ? $po['cost_centre_code'] . ' - ' : '') . $po['cost_centre_name'])
                                : '-'; ?>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="text-muted small"><?php _e('currency'); ?></div>
                        <div class="fw-bold">
                            <?php echo htmlspecialchars($po['currency_code'] ?? '-'); ?>
                            <?php if ($po['currency_code'] && !(int)$po['currency_is_default'] && (float)$po['exchange_rate_to_base'] != 1): ?>
                            <span class="text-muted small">@ <?php echo rtrim(rtrim(number_format((float)$po['exchange_rate_to_base'], 8, '.', ''), '0'), '.'); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if (!empty($po['budget_id'])): ?>
                    <div class="col-md-3">
                        <div class="text-muted small"><?php _e('budget'); ?></div>
                        <div class="fw-bold">
                            <a href="budget_view.php?id=<?php echo (int)$po['budget_id']; ?>"><?php echo htmlspecialchars($po['budget_number'] ?? ('#' . $po['budget_id'])); ?></a>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($po['payment_terms']) || !empty($po['delivery_terms'])): ?>
                <div class="row mb-3 po-screen-only">
                    <div class="col-md-6">
                        <div class="text-muted small"><?php _e('payment_terms'); ?></div>
                        <div><?php echo !empty($po['payment_terms']) ? nl2br(htmlspecialchars($po['payment_terms'])) : '-'; ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small"><?php _e('delivery_terms'); ?></div>
                        <div><?php echo !empty($po['delivery_terms']) ? nl2br(htmlspecialchars($po['delivery_terms'])) : '-'; ?></div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($po['remarks'])): ?>
                <div class="mb-3 po-screen-only"><div class="text-muted small"><?php _e('remarks'); ?></div><div><?php echo nl2br(htmlspecialchars($po['remarks'])); ?></div></div>
                <?php endif; ?>

                <?php if ($status === 'rejected' && !empty($po['reject_reason'])): ?>
                <div class="alert alert-danger">
                    <strong><?php _e('reject_reason'); ?>:</strong> <?php echo htmlspecialchars($po['reject_reason']); ?>
                    <?php if ($po['rejected_by_name']): ?><div class="small mt-1"><?php echo htmlspecialchars($po['rejected_by_name']); ?> &middot; <?php echo formatDateTime($po['rejected_at']); ?></div><?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if (in_array($status, ['approved', 'closed'], true) && !empty($po['approval_remarks'])): ?>
                <div class="alert alert-success">
                    <strong><?php _e('remarks'); ?>:</strong> <?php echo nl2br(htmlspecialchars($po['approval_remarks'])); ?>
                    <?php if ($po['approved_by_name']): ?><div class="small mt-1"><?php echo htmlspecialchars($po['approved_by_name']); ?> &middot; <?php echo formatDateTime($po['approved_at']); ?></div><?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($status === 'cancelled' && !empty($po['close_reason'])): ?>
                <div class="alert alert-warning">
                    <strong><?php _e('cancel_reason'); ?>:</strong> <?php echo htmlspecialchars($po['close_reason']); ?>
                    <?php if ($po['closed_by_name']): ?><div class="small mt-1"><?php echo htmlspecialchars($po['closed_by_name']); ?> &middot; <?php echo formatDateTime($po['closed_at']); ?></div><?php endif; ?>
                </div>
                <?php endif; ?>

                <h6 class="text-muted mb-3"><?php _e('po_items'); ?></h6>
                <div class="table-responsive">
                    <table class="table table-bordered table-sm align-middle">
                        <thead class="table-light">
                            <tr>
                                <th style="width:4%;"><?php _e('sr'); ?></th>
                                <th><?php _e('product'); ?></th>
                                <th style="width:7%;"><?php _e('unit'); ?></th>
                                <th style="width:10%;" class="text-end"><?php _e('ordered_qty'); ?></th>
                                <?php if ($isPostApproval): ?>
                                <th style="width:10%;" class="text-end"><?php _e('approved_qty'); ?></th>
                                <?php endif; ?>
                                <th style="width:11%;" class="text-end"><?php _e('rate'); ?></th>
                                <th style="width:12%;" class="text-end"><?php _e('amount'); ?></th>
                                <?php if ($isPostApproval): ?>
                                <th style="width:10%;" class="text-end"><?php _e('purchased_qty'); ?></th>
                                <th style="width:10%;" class="text-end"><?php _e('remaining'); ?></th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($details as $i => $d): ?>
                            <tr>
                                <td><?php echo $i + 1; ?></td>
                                <td>
                                    <?php echo htmlspecialchars($d['product_name']); ?>
                                    <?php if (!empty($d['remarks'])): ?>
                                    <div class="text-muted small"><?php echo htmlspecialchars($d['remarks']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($d['unit']); ?></td>
                                <td class="text-end"><?php echo formatNumber($d['quantity'], 3); ?></td>
                                <?php if ($isPostApproval): ?>
                                <td class="text-end"><?php echo formatNumber($d['eff_qty'], 3); ?></td>
                                <?php endif; ?>
                                <td class="text-end"><?php echo formatNumber($d['eff_rate'], 2); ?></td>
                                <td class="text-end"><?php echo formatNumber($d['eff_amount'], 2); ?></td>
                                <?php if ($isPostApproval): ?>
                                <td class="text-end"><?php echo formatNumber($d['purchased_quantity'], 3); ?></td>
                                <td class="text-end <?php echo $d['remaining'] > 0.0005 ? 'text-warning' : 'text-success'; ?>"><?php echo formatNumber($d['remaining'], 3); ?></td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <?php $totCols = $isPostApproval ? 6 : 5; $trailCols = $isPostApproval ? 2 : 0; ?>
                            <tr>
                                <th colspan="<?php echo $totCols; ?>" class="text-end"><?php _e('total_amount'); ?></th>
                                <th class="text-end"><?php echo formatNumber($goodsTotal, 2); ?></th>
                                <?php if ($trailCols): ?><th colspan="<?php echo $trailCols; ?>"></th><?php endif; ?>
                            </tr>
                            <tr>
                                <th colspan="<?php echo $totCols; ?>" class="text-end"><?php _e('vat_amount'); ?></th>
                                <th class="text-end"><?php echo formatNumber($vatAmount, 2); ?></th>
                                <?php if ($trailCols): ?><th colspan="<?php echo $trailCols; ?>"></th><?php endif; ?>
                            </tr>
                            <tr>
                                <th colspan="<?php echo $totCols; ?>" class="text-end"><?php _e('payable_amount'); ?></th>
                                <th class="text-end"><?php echo formatNumber($payable, 2); ?></th>
                                <?php if ($trailCols): ?><th colspan="<?php echo $trailCols; ?>"></th><?php endif; ?>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <?php if (!empty($linkedPurchases)): ?>
                <div class="alert alert-success mt-3 no-print">
                    <div class="mb-1"><i class="bi bi-bag-check"></i> <strong><?php _e('linked_purchases'); ?>:</strong></div>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($linkedPurchases as $lpRow): ?>
                        <a href="purchases_view.php?id=<?php echo (int)$lpRow['id']; ?>" class="badge bg-light text-dark border text-decoration-none">
                            <i class="bi bi-cart"></i> <?php echo htmlspecialchars($lpRow['purchase_number']); ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Status actions -->
                <?php if ($status === 'draft'): ?>
                <div class="mt-4 no-print">
                    <?php if ($canEdit): ?>
                    <button type="button" class="btn btn-warning" onclick="sendForApproval(<?php echo $id; ?>)"><i class="bi bi-send"></i> <?php _e('send_for_approval'); ?></button>
                    <a href="purchase_order.php?id=<?php echo $id; ?>" class="btn btn-primary"><i class="bi bi-pencil"></i> <?php _e('edit'); ?></a>
                    <?php endif; ?>
                    <?php if ($canDelete): ?>
                    <button type="button" class="btn btn-danger" onclick="deletePo(<?php echo $id; ?>)"><i class="bi bi-trash"></i> <?php _e('delete'); ?></button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($status === 'pending_approval'): ?>
                <div class="mt-4 no-print">
                    <?php if ($canApprove): ?>
                    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#approvePoModal"><i class="bi bi-check-circle"></i> <?php _e('approve'); ?></button>
                    <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#rejectPoModal"><i class="bi bi-x-circle"></i> <?php _e('reject'); ?></button>
                    <?php else: ?>
                    <div class="alert alert-info mb-0"><i class="bi bi-hourglass-split"></i> <?php _e('status_pending_approval'); ?></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($status === 'rejected' && $canEdit): ?>
                <div class="mt-4 no-print">
                    <button type="button" class="btn btn-primary" onclick="revisePo(<?php echo $id; ?>)"><i class="bi bi-arrow-counterclockwise"></i> <?php _e('revise_po'); ?></button>
                </div>
                <?php endif; ?>

                <?php if ($status === 'approved'): ?>
                <div class="mt-4 no-print">
                    <?php if ($canCreatePurchase && $totalRemaining > 0.0005): ?>
                    <a href="purchases.php?from_po=<?php echo $id; ?>" class="btn btn-primary">
                        <i class="bi bi-cart-plus"></i> <?php _e('create_purchase'); ?>
                    </a>
                    <?php endif; ?>
                    <?php if ($canEdit && $nothingPurchased): ?>
                    <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelPoModal"><i class="bi bi-slash-circle"></i> <?php _e('cancel_po'); ?></button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($canLinkBudget): ?>
                <!-- Link to an approved budget (draft/approved, unlinked, nothing purchased) -->
                <div class="mt-3 no-print">
                    <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#linkBudgetModal">
                        <i class="bi bi-link-45deg"></i> <?php _e('link_to_budget'); ?>
                    </button>
                </div>
                <?php endif; ?>

                <div class="text-muted small mt-3 po-screen-only">
                    <?php _e('created_by'); ?>: <?php echo htmlspecialchars($po['created_by_name'] ?? '-'); ?> &middot; <?php echo formatDateTime($po['created_at']); ?>
                    <?php if ($po['approved_by_name']): ?>
                    &nbsp;|&nbsp; <?php _e('approved_by'); ?>: <?php echo htmlspecialchars($po['approved_by_name']); ?> &middot; <?php echo formatDateTime($po['approved_at']); ?>
                    <?php endif; ?>
                    <?php /* A budget PO is born approved on the BUDGET's authority — nobody
                            approved this document itself. Say so, so the approver's name is
                            never read as a personal sign-off on this PO. */ ?>
                    <?php if (!empty($po['budget_id']) && in_array($status, ['approved', 'closed'], true)): ?>
                    <span class="badge bg-info text-dark ms-1" title="<?php echo htmlspecialchars(__('po_approved_via_budget_help'), ENT_QUOTES); ?>">
                        <i class="bi bi-cash-coin"></i>
                        <?php echo sprintf(__('po_approved_via_budget'), htmlspecialchars($po['budget_number'] ?? ('#' . (int)$po['budget_id']))); ?>
                    </span>
                    <?php endif; ?>
                </div>

                <!-- Footer / Signatures (hide empty boxes) — same pattern as the RV view -->
                <?php
                $poHasPreparedSig = !empty($po['prepared_by_signature']);
                $poHasApprovedSig = !empty($po['approved_by_signature']);
                ?>
                <?php if ($poHasPreparedSig || $poHasApprovedSig): ?>
                <style>
                    .doc-sig-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 40px; padding-top: 16px; border-top: 1px solid #ddd; }
                    .doc-sig-box { text-align: center; padding: 10px 6px; }
                    .doc-sig-box img { max-height: 80px; max-width: 100%; display: block; margin: 0 auto 4px auto; }
                    .doc-sig-box .doc-sig-role { font-weight: 600; border-top: 1px solid #333; padding-top: 6px; }
                    .doc-sig-box .doc-sig-name { font-size: 13px; }
                    .doc-sig-box .doc-sig-date { font-size: 11px; color: #666; }
                </style>
                <div class="doc-sig-grid">
                    <?php if ($poHasPreparedSig): ?>
                    <div class="doc-sig-box">
                        <img src="<?php echo htmlspecialchars($po['prepared_by_signature']); ?>" alt="Prepared By Signature">
                        <div class="doc-sig-role"><?php _e('prepared_by'); ?></div>
                        <div class="doc-sig-name"><?php echo htmlspecialchars($po['created_by_name'] ?: '-'); ?></div>
                        <div class="doc-sig-date"><?php echo formatDateTime($po['created_at']); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($poHasApprovedSig): ?>
                    <div class="doc-sig-box">
                        <img src="<?php echo htmlspecialchars($po['approved_by_signature']); ?>" alt="Approved By Signature">
                        <div class="doc-sig-role"><?php _e('approved_by'); ?></div>
                        <div class="doc-sig-name"><?php echo htmlspecialchars($po['approved_by_name'] ?: '-'); ?></div>
                        <div class="doc-sig-date"><?php echo $po['approved_at'] ? formatDateTime($po['approved_at']) : ''; ?></div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Print-only sign-off. Ruled lines stand in for whichever
                     e-signature is not on file, so the printed PO always has
                     somewhere to sign. -->
                <div class="po-print-only">
                    <div class="po-signoff">
                        <div class="po-signoff-box">
                            <?php if ($poHasPreparedSig): ?>
                            <img src="<?php echo htmlspecialchars($po['prepared_by_signature']); ?>" alt="">
                            <?php endif; ?>
                            <div class="po-signoff-line"><?php _e('prepared_by'); ?></div>
                            <div class="po-signoff-name"><?php echo htmlspecialchars($po['created_by_name'] ?: ''); ?></div>
                            <div class="po-signoff-date"><?php echo formatDateTime($po['created_at']); ?></div>
                        </div>
                        <div class="po-signoff-box">
                            <?php if ($poHasApprovedSig): ?>
                            <img src="<?php echo htmlspecialchars($po['approved_by_signature']); ?>" alt="">
                            <?php endif; ?>
                            <div class="po-signoff-line"><?php _e('approved_by'); ?></div>
                            <div class="po-signoff-name"><?php echo htmlspecialchars($po['approved_by_name'] ?: ''); ?></div>
                            <div class="po-signoff-date"><?php echo $po['approved_at'] ? formatDateTime($po['approved_at']) : ''; ?></div>
                            <?php /* The printed copy goes to the supplier, so it must not
                                    imply a personal sign-off that never happened. */ ?>
                            <?php if (!empty($po['budget_id']) && in_array($status, ['approved', 'closed'], true)): ?>
                            <div class="po-signoff-date">
                                <?php echo sprintf(__('po_approved_via_budget'), htmlspecialchars($po['budget_number'] ?? ('#' . (int)$po['budget_id']))); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="po-signoff-box">
                            <div class="po-signoff-line"><?php _e('supplier'); ?></div>
                            <div class="po-signoff-name">&nbsp;</div>
                            <div class="po-signoff-date">&nbsp;</div>
                        </div>
                    </div>
                    <!-- Document line only. The Fabonic credit comes from the
                         shared app footer (includes/footer.php) so it is
                         identical on every printable in the system. -->
                    <div class="po-print-footer">
                        <div class="po-print-docline">
                            <?php echo htmlspecialchars($companyName); ?>
                            &middot; <?php echo htmlspecialchars($po['po_number']); ?>
                            &middot; <?php echo formatDateTime(date('Y-m-d H:i:s')); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($status === 'pending_approval' && $canApprove): ?>
<!-- Approve modal (approve-with-edit: quantities and rates may be adjusted) -->
<div class="modal fade" id="approvePoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-check-circle"></i> <?php _e('approve_po'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-2"><?php _e('po_approve_hint'); ?></p>
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-bordered align-middle">
                        <thead class="table-light">
                            <tr>
                                <th style="width:4%;"><?php _e('sr'); ?></th>
                                <th><?php _e('product'); ?></th>
                                <th style="width:12%;" class="text-end"><?php _e('ordered_qty'); ?></th>
                                <th style="width:14%;" class="text-end"><?php _e('approved_qty'); ?></th>
                                <th style="width:14%;" class="text-end"><?php _e('rate'); ?></th>
                                <th style="width:14%;" class="text-end"><?php _e('amount'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($details as $i => $d): ?>
                            <tr>
                                <td><?php echo $i + 1; ?></td>
                                <td><?php echo htmlspecialchars($d['product_name']); ?> <span class="text-muted small">(<?php echo htmlspecialchars($d['unit']); ?>)</span></td>
                                <td class="text-end"><?php echo formatNumber($d['quantity'], 3); ?></td>
                                <td class="text-end">
                                    <input type="number" class="form-control form-control-sm text-end approve-qty" data-detail-id="<?php echo (int)$d['id']; ?>"
                                           value="<?php echo (float)$d['quantity']; ?>" min="0" step="0.001">
                                </td>
                                <td class="text-end">
                                    <input type="number" class="form-control form-control-sm text-end approve-rate" data-detail-id="<?php echo (int)$d['id']; ?>"
                                           value="<?php echo (float)$d['rate_per_unit']; ?>" min="0" step="0.01">
                                </td>
                                <td class="text-end align-middle approve-amount" data-detail-id="<?php echo (int)$d['id']; ?>"><?php echo formatNumber((float)$d['quantity'] * (float)$d['rate_per_unit'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="5" class="text-end"><?php _e('total_amount'); ?></th>
                                <th class="text-end" id="approvePoTotal"><?php echo formatNumber($goodsTotal, 2); ?></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <label class="form-label fw-bold">Signature <small class="text-muted">(<?php _e('optional'); ?>)</small></label>
                <?php $myMasterSig = getUserSignature($conn, getUserId()); ?>
                <?php if ($myMasterSig): ?>
                <div class="border rounded p-2 bg-light">
                    <img src="<?php echo htmlspecialchars($myMasterSig); ?>" alt="<?php _e('signature'); ?>" style="max-height:90px;max-width:100%;">
                    <div class="text-muted small mt-1"><i class="bi bi-info-circle"></i> <?php _e('signature_from_profile'); ?></div>
                </div>
                <?php else: ?>
                <div style="border:1px dashed #adb5bd;border-radius:4px;background:#fafafa;position:relative;">
                    <canvas id="poApproveSig" style="display:block;width:100%;height:160px;touch-action:none;cursor:crosshair;"></canvas>
                    <span id="poApproveSigPh" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);color:#adb5bd;font-style:italic;pointer-events:none;font-size:13px;">Sign here</span>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary mt-1" onclick="clearPoSig()"><i class="bi bi-x-circle"></i> <?php _e('clear'); ?></button>
                <?php
                /* Not everyone has a touchscreen. Offer an image upload as an alternative
                   to drawing; it is loaded INTO the pad above, so the submit code below is
                   unchanged. Only reachable in this else-branch, i.e. only for signers with
                   no signature on file. */
                $sigUploadCanvasId      = 'poApproveSig';
                $sigUploadPlaceholderId = 'poApproveSigPh';
                require __DIR__ . '/../../includes/signature_upload_control.php';
                ?>
                <?php endif; ?>
                <div class="row mt-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold"><?php _e('payment_terms'); ?></label>
                        <textarea id="poApprovePaymentTerms" class="form-control" rows="3"><?php echo htmlspecialchars($po['payment_terms'] ?? ''); ?></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold"><?php _e('delivery_terms'); ?></label>
                        <textarea id="poApproveDeliveryTerms" class="form-control" rows="3"><?php echo htmlspecialchars($po['delivery_terms'] ?? ''); ?></textarea>
                    </div>
                </div>
                <div class="mt-3">
                    <label class="form-label fw-bold"><?php _e('remarks'); ?> <small class="text-muted">(<?php _e('optional'); ?>)</small></label>
                    <textarea id="poApproveRemark" class="form-control" rows="2"></textarea>
                </div>
                <div id="approvePoError" class="alert alert-danger mt-2" style="display:none;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                <button type="button" class="btn btn-success" id="approvePoBtn" onclick="submitApprove(<?php echo $id; ?>)"><i class="bi bi-check-circle"></i> <?php _e('approve'); ?></button>
            </div>
        </div>
    </div>
</div>

<!-- Reject modal -->
<div class="modal fade" id="rejectPoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-x-circle text-danger"></i> <?php _e('reject_po'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p><?php _e('confirm_reject_po'); ?></p>
                <label class="form-label"><?php _e('reject_reason'); ?> <small class="text-muted">(<?php _e('optional'); ?>)</small></label>
                <textarea id="poRejectReason" class="form-control" rows="3"></textarea>
                <div id="rejectPoError" class="alert alert-danger mt-2" style="display:none;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                <button type="button" class="btn btn-danger" id="rejectPoBtn" onclick="submitReject(<?php echo $id; ?>)"><i class="bi bi-x-circle"></i> <?php _e('reject'); ?></button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($status === 'approved' && $canEdit && $nothingPurchased): ?>
<!-- Cancel modal -->
<div class="modal fade" id="cancelPoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-slash-circle text-danger"></i> <?php _e('cancel_po'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p><?php _e('confirm_cancel_po'); ?></p>
                <label class="form-label"><?php _e('cancel_reason'); ?> <small class="text-muted">(<?php _e('optional'); ?>)</small></label>
                <textarea id="poCancelReason" class="form-control" rows="3"></textarea>
                <div id="cancelPoError" class="alert alert-danger mt-2" style="display:none;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                <button type="button" class="btn btn-danger" id="cancelPoBtn" onclick="submitCancel(<?php echo $id; ?>)"><i class="bi bi-slash-circle"></i> <?php _e('cancel_po'); ?></button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($canLinkBudget): ?>
<!-- Link to budget modal -->
<div class="modal fade" id="linkBudgetModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-link-45deg"></i> <?php _e('link_to_budget'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle"></i>
                    <strong><?php _e('note'); ?>:</strong> <?php _e('po_link_availability_note'); ?>
                </div>

                <?php if (!empty($linkedPurchases)): ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i>
                    <?php echo sprintf(__('po_link_will_backfill'), count($linkedPurchases)); ?>
                    <div class="small mt-1">
                        <?php foreach ($linkedPurchases as $lp): ?>
                        <span class="badge bg-secondary"><?php echo htmlspecialchars($lp['purchase_number']); ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (in_array($status, ['rejected', 'cancelled'], true)): ?>
                <div class="alert alert-secondary">
                    <i class="bi bi-info-circle"></i> <?php _e('po_link_inactive_status_note'); ?>
                </div>
                <?php endif; ?>

                <?php if (empty($linkBudgetCandidates)): ?>
                <div class="alert alert-secondary mb-0"><?php _e('po_link_no_budgets'); ?></div>
                <?php else: ?>
                <label class="form-label fw-bold"><?php _e('budget'); ?></label>
                <select id="linkBudgetSelect" class="form-select" onchange="renderLinkPreview()">
                    <option value="">— <?php _e('select'); ?> —</option>
                    <?php foreach ($linkBudgetCandidates as $c): ?>
                    <option value="<?php echo $c['id']; ?>">
                        <?php echo htmlspecialchars($c['number']); ?> · <?php echo formatDate($c['date']); ?>
                        <?php echo $c['fits'] ? '' : ' — ' . __('po_link_insufficient'); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted"><?php _e('po_link_budget_hint'); ?></small>

                <div id="linkPreviewWrap" class="mt-3" style="display:none;">
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th><?php _e('product'); ?></th>
                                    <th class="text-end"><?php _e('quantity'); ?></th>
                                    <th class="text-end"><?php _e('remaining'); ?></th>
                                    <th class="text-center"><?php _e('status'); ?></th>
                                </tr>
                            </thead>
                            <tbody id="linkPreviewBody"></tbody>
                        </table>
                    </div>
                </div>
                <div id="linkBudgetError" class="alert alert-danger mt-2" style="display:none;"></div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                <?php if (!empty($linkBudgetCandidates)): ?>
                <button type="button" class="btn btn-primary" id="linkBudgetBtn" onclick="submitLinkBudget(<?php echo $id; ?>)" disabled>
                    <i class="bi bi-link-45deg"></i> <?php _e('link_to_budget'); ?>
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="<?php echo BASE_URL; ?>assets/js/signature_pad.js"></script>
<script>
const PO_API = '<?php echo BASE_URL; ?>api/purchase-orders-sync.php';

function postPo(payload, onOk, errBoxId, btnId, btnHtml) {
    const errBox = errBoxId ? document.getElementById(errBoxId) : null;
    const btn = btnId ? document.getElementById(btnId) : null;
    if (errBox) errBox.style.display = 'none';
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>...'; }
    fetch(PO_API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
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
    postPo({ action: 'send_for_approval', id: id }, () => location.reload());
}
function deletePo(id) {
    if (!confirm('<?php echo addslashes(__('confirm_delete_po')); ?>')) return;
    postPo({ action: 'delete', id: id }, () => { location.href = 'purchase_order_list.php'; });
}
function revisePo(id) {
    if (!confirm('<?php echo addslashes(__('confirm_revise_po')); ?>')) return;
    postPo({ action: 'revise', id: id }, () => location.reload());
}

<?php if ($canLinkBudget && !empty($linkBudgetCandidates)): ?>
// Per-budget preview of how this PO's quantities sit against the remaining
// allocation. Precomputed server-side with the same maths the API enforces
// (getBudgetSupplierRemaining), so the table can never disagree with the save.
const LINK_BUDGETS = <?php echo json_encode($linkBudgetCandidates); ?>;

function renderLinkPreview() {
    const sel     = document.getElementById('linkBudgetSelect');
    const wrap    = document.getElementById('linkPreviewWrap');
    const body    = document.getElementById('linkPreviewBody');
    const btn     = document.getElementById('linkBudgetBtn');
    const errBox  = document.getElementById('linkBudgetError');
    if (errBox) errBox.style.display = 'none';

    const chosen = LINK_BUDGETS.find(b => String(b.id) === String(sel.value));
    if (!chosen) {
        wrap.style.display = 'none';
        body.innerHTML = '';
        btn.disabled = true;
        return;
    }

    body.innerHTML = chosen.rows.map(r => `
        <tr class="${r.ok ? '' : 'table-danger'}">
            <td>${escapeHtmlPo(r.product)}</td>
            <td class="text-end">${fmtQtyPo(r.wanted)}</td>
            <td class="text-end">${r.budgeted ? fmtQtyPo(r.available) : '—'}</td>
            <td class="text-center">${r.ok
                ? '<span class="badge bg-success"><?php echo addslashes(__('ok')); ?><\/span>'
                : (r.budgeted
                    ? '<span class="badge bg-danger"><?php echo addslashes(__('po_link_exceeds')); ?><\/span>'
                    : '<span class="badge bg-danger"><?php echo addslashes(__('po_link_not_budgeted')); ?><\/span>')}</td>
        </tr>`).join('');

    wrap.style.display = '';
    btn.disabled = !chosen.fits;
}

function fmtQtyPo(n) {
    return (Math.round((parseFloat(n) || 0) * 100) / 100).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function escapeHtmlPo(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function submitLinkBudget(id) {
    const sel = document.getElementById('linkBudgetSelect');
    const budgetId = parseInt(sel.value, 10);
    if (!budgetId) return;
    postPo(
        { action: 'link_budget', id: id, budget_id: budgetId },
        () => location.reload(),
        'linkBudgetError',
        'linkBudgetBtn',
        '<i class="bi bi-link-45deg"></i> <?php echo addslashes(__('link_to_budget')); ?>'
    );
}
<?php endif; ?>

<?php if ($status === 'pending_approval' && $canApprove): ?>
let poSigPad = null;
function clearPoSig() { if (poSigPad) { poSigPad.clear(); document.getElementById('poApproveSigPh').style.display = ''; } }

function fmtPoAmt(n) {
    if (n === null || n === undefined || isNaN(n)) return '0.00';
    return parseFloat(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function recalcApprove() {
    let total = 0;
    document.querySelectorAll('#approvePoModal .approve-qty').forEach(input => {
        const detailId = input.dataset.detailId;
        const qty = parseFloat(input.value) || 0;
        const rateInput = document.querySelector('#approvePoModal .approve-rate[data-detail-id="' + detailId + '"]');
        const rate = rateInput ? (parseFloat(rateInput.value) || 0) : 0;
        const amt = qty * rate;
        total += amt;
        const cell = document.querySelector('#approvePoModal .approve-amount[data-detail-id="' + detailId + '"]');
        if (cell) cell.textContent = fmtPoAmt(amt);
    });
    const totalEl = document.getElementById('approvePoTotal');
    if (totalEl) totalEl.textContent = fmtPoAmt(total);
}

document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('approvePoModal');
    if (modal) {
        modal.addEventListener('input', function (e) {
            if (e.target.classList.contains('approve-qty') || e.target.classList.contains('approve-rate')) {
                recalcApprove();
            }
        });
        modal.addEventListener('shown.bs.modal', function () {
            recalcApprove();
            const c = document.getElementById('poApproveSig');
            if (c && window.SignaturePad && !poSigPad) {
                poSigPad = new SignaturePad(c, { penColor: '#000', lineWidth: 2 });
                c.addEventListener('pointerdown', function () { document.getElementById('poApproveSigPh').style.display = 'none'; });
            }
        });
    }
});

function submitApprove(id) {
    const items = [];
    let anyPositive = false;
    let bad = false;
    document.querySelectorAll('#approvePoModal .approve-qty').forEach(input => {
        const detailId = parseInt(input.dataset.detailId, 10);
        const qty = parseFloat(input.value);
        const rateInput = document.querySelector('#approvePoModal .approve-rate[data-detail-id="' + detailId + '"]');
        const rate = rateInput ? parseFloat(rateInput.value) : 0;
        if (isNaN(qty) || qty < 0) { bad = true; }
        if (!isNaN(qty) && qty > 0) anyPositive = true;
        items.push({ detail_id: detailId, approved_quantity: isNaN(qty) ? 0 : qty, approved_rate: isNaN(rate) ? 0 : rate });
    });
    const errBox = document.getElementById('approvePoError');
    if (bad) { errBox.textContent = '<?php echo addslashes(__('po_approve_qty_invalid')); ?>'; errBox.style.display = ''; return; }
    if (!anyPositive) { errBox.textContent = '<?php echo addslashes(__('po_approve_needs_one_line')); ?>'; errBox.style.display = ''; return; }

    const sig = (poSigPad && !poSigPad.isEmpty()) ? poSigPad.toDataURL() : null;
    const remarkEl = document.getElementById('poApproveRemark');
    const payload = { action: 'approve', id: id, approved_by_signature: sig, approval_remarks: remarkEl ? remarkEl.value : '', items: items };
    // The approver may amend the terms. Sent only when the boxes exist, so an
    // absent key tells the API to leave the stored terms alone.
    const ptEl = document.getElementById('poApprovePaymentTerms');
    const dtEl = document.getElementById('poApproveDeliveryTerms');
    if (ptEl) payload.payment_terms = ptEl.value;
    if (dtEl) payload.delivery_terms = dtEl.value;
    postPo(payload,
        () => location.reload(), 'approvePoError', 'approvePoBtn', '<i class="bi bi-check-circle"></i> <?php echo addslashes(__('approve')); ?>');
}
function submitReject(id) {
    const reason = document.getElementById('poRejectReason').value;
    postPo({ action: 'reject', id: id, reject_reason: reason },
        () => location.reload(), 'rejectPoError', 'rejectPoBtn', '<i class="bi bi-x-circle"></i> <?php echo addslashes(__('reject')); ?>');
}
<?php endif; ?>

<?php if ($status === 'approved' && $canEdit && $nothingPurchased): ?>
function submitCancel(id) {
    const reason = document.getElementById('poCancelReason').value;
    postPo({ action: 'cancel', id: id, cancel_reason: reason },
        () => location.reload(), 'cancelPoError', 'cancelPoBtn', '<i class="bi bi-slash-circle"></i> <?php echo addslashes(__('cancel_po')); ?>');
}
<?php endif; ?>
</script>

<?php require_once '../../includes/footer.php'; ?>
