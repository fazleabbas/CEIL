<?php
/**
 * Purchases Management - With Offline Support
 * Users can create purchases offline, view existing purchases
 * Edit/Delete only available online
 * Version 2: With inline editing of quantities and rates
 */
require_once '../../config/database.php';
require_once '../../includes/functions.php';

$pageTitle = __('purchases_management');

if (!hasPermission('purchases', 'view')) {
    setAlert('You do not have permission to access this page', 'danger');
    redirect('');
}

// Deep-link edit (e.g., from Ledger Report -> purchases_view.php -> Edit).
// Fetch the target purchase fresh from the DB so we don't rely on the
// IndexedDB cache used by the regular list flow. Only fires when ?edit=<id>
// is present — every other path through this page is unchanged.
$editBootstrap = null;
$editNotFound  = false;
if (!empty($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    if ($eid > 0) {
        $stmt = $conn->prepare("
            SELECT
                p.*,
                la.name AS supplier_name,
                l.name  AS location_name,
                cc.name AS cost_centre_name,
                cc.code AS cost_centre_code,
                u.name  AS created_by_name
            FROM purchases p
            LEFT JOIN suppliers s        ON p.supplier_id = s.id
            LEFT JOIN ledger_accounts la ON s.ledger_account_id = la.id
            LEFT JOIN locations l        ON p.location_id = l.id
            LEFT JOIN cost_centres cc    ON p.cost_centre_id = cc.id
            LEFT JOIN users u            ON p.created_by = u.id
            WHERE p.id = ?
        ");
        $stmt->execute([$eid]);
        $editBootstrap = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($editBootstrap) {
            $detailsStmt = $conn->prepare("
                SELECT pd.*, p.name AS product_name, p.unit, pc.name AS category_name
                FROM purchase_details pd
                JOIN products p ON pd.product_id = p.id
                LEFT JOIN product_categories pc ON p.category_id = pc.id
                WHERE pd.purchase_id = ?
                ORDER BY pd.id
            ");
            $detailsStmt->execute([$eid]);
            $editBootstrap['details'] = $detailsStmt->fetchAll(PDO::FETCH_ASSOC);
            $editBootstrap['synced']  = true;
        } else {
            $editNotFound = true;
        }
    }
}

// Prefill from a requisition voucher (?from_requisition=<id>): pull its header
// (destination location + cost centre) and line items so the create modal opens
// pre-populated. Procurement quantity = approved_quantity when set, else requested.
// Inactive products are skipped (server validation rejects them on save).
// Purely additive: when the param is absent $reqPrefill stays null and nothing changes.
$reqPrefill = null;
if (!empty($_GET['from_requisition'])) {
    $rid = (int)$_GET['from_requisition'];
    if ($rid > 0) {
        $rvStmt = $conn->prepare("SELECT id, destination_location_id, cost_centre_id FROM requisition_vouchers WHERE id = ?");
        $rvStmt->execute([$rid]);
        $rv = $rvStmt->fetch(PDO::FETCH_ASSOC);
        if ($rv) {
            $rvdStmt = $conn->prepare("
                SELECT rvd.product_id,
                       COALESCE(rvd.approved_quantity, rvd.requested_quantity) AS quantity,
                       p.name AS product_name, p.unit
                FROM requisition_voucher_details rvd
                JOIN products p ON rvd.product_id = p.id
                WHERE rvd.requisition_id = ? AND p.is_active = 1
                ORDER BY rvd.id
            ");
            $rvdStmt->execute([$rid]);
            $rvItems = $rvdStmt->fetchAll(PDO::FETCH_ASSOC);
            $reqPrefill = [
                'requisition_id' => (int)$rv['id'],
                'location_id'    => $rv['destination_location_id'] ? (int)$rv['destination_location_id'] : null,
                'cost_centre_id' => $rv['cost_centre_id'] ? (int)$rv['cost_centre_id'] : null,
                'items'          => array_map(function ($it) {
                    return [
                        'product_id'    => (int)$it['product_id'],
                        'product_name'  => $it['product_name'],
                        'unit'          => $it['unit'],
                        'quantity'      => floatval($it['quantity']),
                        'rate_per_unit' => 0,
                    ];
                }, $rvItems),
            ];
        }
    }
}

// Prefill from an approved Budget (?from_budget=<id>&supplier=<sid>): pull that
// supplier's 'purchase'-type allocations with the remaining-to-create quantity
// (allocation qty minus what has already been purchased from this budget for the
// supplier) and the budgeted rate. Supplier is preset and the saved purchase
// links back via budget_id (+ requisition_id). Additive: param absent -> null.
$budgetPrefill = null;
if (!empty($_GET['from_budget'])) {
    $bid = (int)$_GET['from_budget'];
    $sid = (int)($_GET['supplier'] ?? 0);
    if ($bid > 0 && $sid > 0) {
        $bStmt = $conn->prepare("SELECT * FROM budgets WHERE id = ? AND status = 'approved'");
        $bStmt->execute([$bid]);
        $bRow = $bStmt->fetch(PDO::FETCH_ASSOC);
        if ($bRow) {
            // GROUPED BY PRODUCT — a product may sit on several rows of the same
            // budget for this supplier, while $createdMap / $poOutMap below are
            // per product. Ungrouped, each row would have the whole purchased
            // quantity subtracted from it and the prefill would understate what
            // is left. Rate is quantity-weighted; VAT summed.
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
            $aStmt->execute([$bid, $sid]);
            $allocs = $aStmt->fetchAll(PDO::FETCH_ASSOC);

            $createdStmt = $conn->prepare("
                SELECT pd.product_id, COALESCE(SUM(pd.quantity), 0) AS qty
                FROM purchase_details pd
                JOIN purchases pu ON pd.purchase_id = pu.id
                WHERE pu.budget_id = ? AND pu.supplier_id = ?
                GROUP BY pd.product_id
            ");
            $createdStmt->execute([$bid, $sid]);
            $createdMap = [];
            foreach ($createdStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $createdMap[(int)$r['product_id']] = (float)$r['qty'];
            }

            // Quantities on OPEN purchase orders raised from this budget for the
            // supplier also reduce remaining — a budget line already on a PO
            // must not be purchasable directly a second time.
            $poOutMap = getBudgetPoOutstanding($bid, $conn, $sid);

            $items = [];
            $vatPrefill = 0;   // migration 0044
            foreach ($allocs as $a) {
                $pid = (int)$a['product_id'];
                $rem = (float)$a['quantity'] - ($createdMap[$pid] ?? 0) - ($poOutMap[$pid] ?? 0);
                if ($rem <= 0.0005) continue;
                $items[] = [
                    'product_id'    => $pid,
                    'product_name'  => $a['product_name'],
                    'unit'          => $a['unit'],
                    'quantity'      => $rem,
                    'rate_per_unit' => (float)$a['rate'],
                ];
                // The budget's per-line VAT, pro-rated to the quantity actually
                // being purchased now, summed into this purchase's header VAT —
                // purchases have no per-line VAT column to put it on.
                $vatPrefill += budgetLineVatForQty($a['vat_amount'] ?? 0, $a['quantity'], $rem);
            }
            $budgetPrefill = [
                'budget_id'      => $bid,
                'requisition_id' => (int)$bRow['requisition_id'],
                'supplier_id'    => $sid,
                'location_id'    => (int)$bRow['location_id'],
                'cost_centre_id' => $bRow['cost_centre_id'] ? (int)$bRow['cost_centre_id'] : null,
                'items'          => $items,
                'vat_amount'     => round($vatPrefill, 2),
            ];
        }
    }
}

// Prefill from an approved Purchase Order (?from_po=<id>): supplier, location,
// cost centre, currency and exchange rate are preset from the PO; line items
// come in at the remaining-to-purchase quantity (approved qty minus what earlier
// purchases against this PO already took) and the approved rate. The saved
// purchase links back via purchase_order_id — and inherits budget_id (+ the
// budget's requisition_id) when the PO was raised from a budget, so budget
// tracking keeps working. Additive: param absent -> null, nothing changes.
$poPrefill = null;
if (!empty($_GET['from_po'])) {
    $poid = (int)$_GET['from_po'];
    if ($poid > 0) {
        $poStmt = $conn->prepare("
            SELECT po.*, b.requisition_id AS budget_requisition_id
            FROM purchase_orders po
            LEFT JOIN budgets b ON po.budget_id = b.id
            WHERE po.id = ? AND po.status = 'approved'
        ");
        $poStmt->execute([$poid]);
        $poRow = $poStmt->fetch(PDO::FETCH_ASSOC);
        if ($poRow) {
            $podStmt = $conn->prepare("
                SELECT pod.product_id,
                       COALESCE(pod.approved_quantity, pod.quantity) AS line_qty,
                       COALESCE(pod.approved_quantity, pod.quantity) - pod.purchased_quantity AS remaining,
                       COALESCE(pod.approved_rate, pod.rate_per_unit) AS rate,
                       p.name AS product_name, p.unit
                FROM purchase_order_details pod
                JOIN products p ON pod.product_id = p.id
                WHERE pod.purchase_order_id = ? AND p.is_active = 1
                ORDER BY pod.id
            ");
            $podStmt->execute([$poid]);

            $items = [];
            $poGoodsTotal = 0;   // full value of the PO's lines
            $poGoodsTaken = 0;   // value of what is being purchased now
            foreach ($podStmt->fetchAll(PDO::FETCH_ASSOC) as $ln) {
                $rem  = (float)$ln['remaining'];
                $rate = (float)$ln['rate'];
                $poGoodsTotal += (float)$ln['line_qty'] * $rate;
                if ($rem <= 0.0005) continue;
                $poGoodsTaken += $rem * $rate;
                $items[] = [
                    'product_id'    => (int)$ln['product_id'],
                    'product_name'  => $ln['product_name'],
                    'unit'          => $ln['unit'],
                    'quantity'      => $rem,
                    'rate_per_unit' => $rate,
                ];
            }

            // Carry the PO's VAT across, so it is not lost on the
            // budget -> PO -> purchase route. The PO holds ONE header VAT figure
            // (no per-line VAT column exists), so it pro-rates by the VALUE of
            // the lines being taken now rather than per line — identical result
            // when the whole PO is purchased at once.
            $poVat = (float)($poRow['vat_amount'] ?? 0);
            $poVatPrefill = ($poVat > 0 && $poGoodsTotal > 0)
                ? round($poVat * min($poGoodsTaken / $poGoodsTotal, 1), 2)
                : 0;
            $poPrefill = [
                'purchase_order_id' => $poid,
                'budget_id'         => $poRow['budget_id'] ? (int)$poRow['budget_id'] : null,
                'requisition_id'    => $poRow['budget_requisition_id'] ? (int)$poRow['budget_requisition_id'] : null,
                'supplier_id'       => (int)$poRow['supplier_id'],
                'location_id'       => (int)$poRow['location_id'],
                'cost_centre_id'    => $poRow['cost_centre_id'] ? (int)$poRow['cost_centre_id'] : null,
                'currency_id'       => $poRow['currency_id'] ? (int)$poRow['currency_id'] : null,
                'exchange_rate_to_base' => (float)$poRow['exchange_rate_to_base'],
                'items'             => $items,
                'vat_amount'        => $poVatPrefill,
            ];
        }
    }
}

require_once '../../includes/header.php';

?>

<!-- Deep-link edit bootstrap (set by ?edit=<id> server-side fetch) -->
<script>
window.__editBootstrap = <?php echo $editBootstrap ? json_encode($editBootstrap) : 'null'; ?>;
window.__editNotFound  = <?php echo $editNotFound ? 'true' : 'false'; ?>;
window.__reqPrefill    = <?php echo $reqPrefill ? json_encode($reqPrefill) : 'null'; ?>;
window.__budgetPrefill = <?php echo $budgetPrefill ? json_encode($budgetPrefill) : 'null'; ?>;
window.__poPrefill     = <?php echo $poPrefill ? json_encode($poPrefill) : 'null'; ?>;
</script>

<!-- Online Status Indicator -->
<div id="onlineStatus" class="badge bg-secondary position-fixed" style="top: 10px; right: 10px; z-index: 1050;">
    <i class="bi bi-wifi-off"></i> <?php _e('checking_connection'); ?>
</div>

<!-- Supplier filter badge (shown only when ?supplier_id= is in URL, set by JS) -->
<div id="supplierFilterBanner" class="alert alert-info align-items-center justify-content-between mb-3" style="display:none;">
    <div>
        <i class="bi bi-funnel-fill"></i>
        <?php _e('filtering_by_supplier', 'Filtering by supplier'); ?>:
        <strong id="supplierFilterName">&hellip;</strong>
    </div>
    <a href="purchases.php" class="btn btn-sm btn-outline-secondary" title="<?php _e('clear_filter', 'Clear filter'); ?>">
        <i class="bi bi-x-circle"></i> <?php _e('clear_filter', 'Clear filter'); ?>
    </a>
</div>

<div class="row mb-3">
    <div class="col-md-6">
        <h2><i class="bi bi-cart-plus"></i> <?php _e('purchases_management'); ?></h2>
    </div>
    <div class="col-md-6 text-end">
        <?php if (hasPermission('purchases', 'create')): ?>
        <button class="btn btn-primary" onclick="showCreateModal()" id="createBtn">
            <i class="bi bi-plus-circle"></i> <?php _e('add_new_purchase'); ?>
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- Search and Filter -->
<div class="card mb-3">
    <div class="card-body">
        <div class="row g-2">
            <div class="col-md-3">
                <input type="date" id="dateFrom" class="form-control" placeholder="<?php _e('from_date'); ?>">
            </div>
            <div class="col-md-3">
                <input type="date" id="dateTo" class="form-control" placeholder="<?php _e('to_date'); ?>">
            </div>
            <div class="col-md-3">
                <input type="text" id="searchInput" class="form-control"
                       placeholder="<?php _e('search_purchases'); ?>" onkeyup="filterPurchases()">
            </div>
            <div class="col-md-3">
                <div class="d-flex gap-1">
                    <button onclick="clearFilters()" class="btn btn-secondary flex-grow-1">
                        <i class="bi bi-x-circle"></i> <?php _e('clear'); ?>
                    </button>
                    <button onclick="refreshFromServer()" id="refreshBtn"
                            class="btn btn-outline-primary flex-grow-1"
                            title="Pulls fresh data from the server, bypassing the local cache">
                        <i class="bi bi-arrow-clockwise"></i> <?php _e('refresh'); ?>
                    </button>
                </div>
            </div>
        </div>
        <!-- Product filter: options are the products found on the purchases
             currently loaded (date range), so it works offline and never offers
             a product that would return an empty list. -->
        <div class="row g-2 mt-0">
            <div class="col-12">
                <select id="productFilter" class="form-select" multiple
                        data-placeholder="<?php echo htmlspecialchars(__('filter_by_products'), ENT_QUOTES); ?>"></select>
            </div>
        </div>
    </div>
</div>

<!-- Purchases Table -->
<div class="card">
    <div class="card-body">
        <div id="loadingSpinner" class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden"><?php _e('loading'); ?></span>
            </div>
            <p class="mt-2"><?php _e('loading_purchases'); ?></p>
        </div>
        
        <div id="purchasesContainer" style="display:none;">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <span id="purchaseCount" class="text-muted">0 purchases</span>
                <span id="totalAmount" class="fw-bold text-primary">Total: TZS 0.00</span>
            </div>
            
            <div class="table-responsive">
                <table class="table table-hover table-striped">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 120px;"><?php _e('number'); ?></th>
                            <th style="width: 110px;"><?php _e('po_number'); ?></th>
                            <th style="width: 110px;"><?php _e('date'); ?></th>
                            <th><?php _e('supplier'); ?></th>
                            <th><?php _e('location'); ?></th>
                            <th style="width: 80px;" class="text-center"><?php _e('items'); ?></th>
                            <th style="width: 140px;" class="text-end"><?php _e('bill_amount'); ?></th>
                            <th style="width: 100px;" class="text-center"><?php _e('status'); ?></th>
                            <th style="width: 150px;" class="text-center no-export"><?php _e('actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="purchasesTableBody">
                        <!-- Purchases will be loaded here -->
                    </tbody>
                </table>
            </div>
        </div>
        
        <div id="emptyState" style="display:none;" class="text-center py-5">
            <i class="bi bi-inbox" style="font-size: 3rem; color: #ccc;"></i>
            <p class="text-muted mt-3"><?php _e('no_purchases_found'); ?></p>
            <?php if (hasPermission('purchases', 'create')): ?>
            <button class="btn btn-primary mt-2" onclick="showCreateModal()">
                <i class="bi bi-plus-circle"></i> <?php _e('create_first_purchase'); ?>
            </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- View Purchase Modal -->
<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">
                    <i class="bi bi-eye"></i> <?php _e('purchase_details'); ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-md-4">
                        <strong><?php _e('purchase_number'); ?>:</strong><br>
                        <span id="viewPurchaseNumber" class="fs-5 text-primary"></span>
                    </div>
                    <div class="col-md-4">
                        <strong><?php _e('po_number'); ?>:</strong><br>
                        <span id="viewPoNumber" class="text-muted">-</span>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <strong><?php _e('status'); ?>:</strong><br>
                        <span id="viewStatus"></span>
                    </div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-4">
                        <strong><i class="bi bi-calendar"></i> <?php _e('date'); ?>:</strong><br>
                        <span id="viewDate"></span>
                    </div>
                    <div class="col-md-4">
                        <strong><i class="bi bi-truck"></i> <?php _e('supplier'); ?>:</strong><br>
                        <span id="viewSupplier"></span>
                    </div>
                    <div class="col-md-4">
                        <strong><i class="bi bi-geo-alt"></i> <?php _e('location'); ?>:</strong><br>
                        <span id="viewLocation"></span>
                    </div>
                </div>

                <div class="row mb-3" id="viewCostCentreRow" style="display:none;">
                    <div class="col-12">
                        <strong><i class="bi bi-building"></i> <?php _e('cost_centre'); ?>:</strong>
                        <span id="viewCostCentre"></span>
                    </div>
                </div>

                <div class="row mb-3" id="viewNotesRow" style="display:none;">
                    <div class="col-12">
                        <strong><i class="bi bi-sticky"></i> <?php _e('notes'); ?>:</strong><br>
                        <span id="viewNotes" class="text-muted"></span>
                    </div>
                </div>
                
                <hr>
                
                <h6 class="mb-3"><i class="bi bi-box-seam"></i> <?php _e('purchase_items'); ?></h6>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 5%;">#</th>
                                <th><?php _e('product'); ?></th>
                                <th class="text-center"><?php _e('unit'); ?></th>
                                <th class="text-end"><?php _e('quantity'); ?></th>
                                <th class="text-end"><?php _e('rate'); ?></th>
                                <th class="text-end"><?php _e('amount'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="viewItemsBody">
                        </tbody>
                        <tfoot>
                            <tr class="table-active fw-bold">
                                <td colspan="5" class="text-end"><?php _e('total_amount'); ?>:</td>
                                <td class="text-end" id="viewTotalAmount"></td>
                            </tr>
                            <tr id="viewVatRow" style="display:none;">
                                <td colspan="5" class="text-end"><?php _e('vat_amount'); ?>:</td>
                                <td class="text-end" id="viewVatAmount"></td>
                            </tr>
                            <tr class="table-active fw-bold" id="viewPayableRow" style="display:none;">
                                <td colspan="5" class="text-end"><?php _e('payable_amount'); ?>:</td>
                                <td class="text-end" id="viewPayableAmount"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                
                <div class="row mt-3">
                    <div class="col-md-6">
                        <small class="text-muted">
                            <i class="bi bi-person"></i> <?php _e('created_by'); ?>: <span id="viewCreatedBy"></span>
                        </small>
                    </div>
                    <div class="col-md-6 text-md-end">
                        <small class="text-muted">
                            <i class="bi bi-clock"></i> <?php _e('created'); ?>: <span id="viewCreatedAt"></span>
                        </small>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> <?php _e('close'); ?>
                </button>
                <?php if (hasPermission('purchases', 'edit')): ?>
                <button type="button" class="btn btn-warning" id="viewEditBtn" onclick="editFromView()">
                    <i class="bi bi-pencil"></i> <?php _e('edit_purchase'); ?>
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Create/Edit Purchase Modal -->
<div class="modal fade" id="purchaseModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle"><?php _e('add_new_purchase'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="purchaseForm">
                    <input type="hidden" id="purchaseId" name="id">
                    
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <label class="form-label"><?php _e('purchase_date'); ?> <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="purchaseDate" required 
                                   max="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><?php _e('po_number'); ?></label>
                            <input type="text" class="form-control" id="poNumber" placeholder="<?php _e('optional'); ?>" maxlength="50">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><?php _e('supplier'); ?> <span class="text-danger">*</span></label>
                            <select class="form-select" id="supplierId" required>
                                <option value=""><?php _e('select_supplier'); ?></option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><?php _e('location'); ?> <span class="text-danger">*</span></label>
                            <select class="form-select" id="locationId" required>
                                <option value=""><?php _e('select_location'); ?></option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><?php _e('cost_centre'); ?></label>
                            <select class="form-select" id="costCentreId">
                                <option value=""><?php _e('select_cost_centre'); ?></option>
                                <?= renderTreeOptions(getUserCostCentres(), null, null, 'name', 'code') ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><?php _e('currency'); ?></label>
                            <select class="form-select" id="currencyId" onchange="onCurrencyChange()">
                            </select>
                        </div>
                        <div class="col-md-3" id="exchangeRateGroup" style="display:none;">
                            <label class="form-label"><?php _e('exchange_rate'); ?></label>
                            <input type="number" class="form-control" id="exchangeRateToBase" step="0.00000001" min="0.00000001" value="1">
                            <small class="text-muted" id="exchangeRateHint"></small>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label"><?php _e('notes'); ?></label>
                        <textarea class="form-control" id="notes" rows="2"></textarea>
                    </div>
                    
                    <hr>
                    
                    <h6 class="mb-3"><?php _e('purchase_items'); ?> <span class="text-danger">*</span></h6>
                    
                    <div class="mb-3">
                        <div class="row g-2">
                            <div class="col-md-5">
                                <select class="form-select" id="productSelect">
                                    <option value=""><?php _e('select_product_to_add'); ?></option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <input type="number" class="form-control" id="itemQuantity" 
                                       placeholder="<?php _e('quantity'); ?>" min="0.001" step="0.001">
                            </div>
                            <div class="col-md-3">
                                <input type="number" class="form-control" id="itemRate" 
                                       placeholder="<?php _e('rate_per_unit'); ?>" min="0" step="0.01">
                            </div>
                            <div class="col-md-1">
                                <button type="button" class="btn btn-success w-100" onclick="addItem()">
                                    <i class="bi bi-plus"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered">
                            <thead class="table-light">
                                <tr>
                                    <th><?php _e('product'); ?></th>
                                    <th class="text-center"><?php _e('unit'); ?></th>
                                    <th class="text-end"><?php _e('quantity'); ?></th>
                                    <th class="text-end"><?php _e('rate'); ?></th>
                                    <th class="text-end"><?php _e('amount'); ?></th>
                                    <th class="text-center"><?php _e('action'); ?></th>
                                </tr>
                            </thead>
                            <tbody id="itemsTableBody">
                                <tr id="noItemsRow">
                                    <td colspan="6" class="text-center text-muted"><?php _e('no_items_added'); ?></td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr class="table-active">
                                    <th colspan="4" class="text-end"><?php _e('total_amount'); ?>:</th>
                                    <th class="text-end" id="modalTotalAmount">TZS 0.00</th>
                                    <th></th>
                                </tr>
                                <tr>
                                    <th colspan="4" class="text-end"><?php _e('vat_amount'); ?>:</th>
                                    <th class="text-end p-1">
                                        <div class="d-flex gap-1 justify-content-end align-items-center">
                                            <div class="form-check form-switch mb-0 me-1" title="Toggle ON to enter VAT as a percentage">
                                                <input type="checkbox" id="modalVatPctToggle" class="form-check-input" onchange="onVatToggleChange()">
                                                <label for="modalVatPctToggle" class="form-check-label small">%</label>
                                            </div>
                                            <input type="number" id="modalVatPct"
                                                   class="form-control form-control-sm text-end"
                                                   min="0" step="0.01" placeholder="%" disabled
                                                   style="max-width: 80px;"
                                                   oninput="onVatPctInput()" onchange="onVatPctInput()">
                                            <input type="number" id="modalVatAmount"
                                                   class="form-control form-control-sm text-end"
                                                   min="0" step="0.01" value="0"
                                                   style="max-width: 130px;"
                                                   oninput="updatePayable()" onchange="updatePayable()">
                                        </div>
                                    </th>
                                    <th></th>
                                </tr>
                                <tr class="table-active fw-bold">
                                    <th colspan="4" class="text-end"><?php _e('payable_amount'); ?>:</th>
                                    <th class="text-end" id="modalPayableAmount">TZS 0.00</th>
                                    <th></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> <?php _e('cancel'); ?>
                </button>
                <button type="button" class="btn btn-primary" onclick="savePurchase()" id="saveBtn">
                    <i class="bi bi-check-circle"></i> <?php _e('save_purchase'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.badge-synced {
    background-color: #198754;
}
.badge-pending {
    background-color: #ffc107;
    color: #000;
}
.purchase-row-pending {
    background-color: #fff3cd;
}
/* Inline editing styles */
.editable-cell {
    cursor: pointer;
    position: relative;
}
.editable-cell:hover {
    background-color: #f8f9fa;
}
.editable-cell input {
    width: 100%;
    border: 2px solid #0d6efd;
    padding: 4px 8px;
    text-align: right;
}
.edit-mode {
    background-color: #e7f3ff !important;
}
</style>

<script>
// Translations for JavaScript
const LANG = {
    add_new_purchase: <?php echo json_encode(__('add_new_purchase')); ?>,
    edit_purchase: <?php echo json_encode(__('edit_purchase')); ?>,
    synced: <?php echo json_encode(__('synced')); ?>,
    pending_sync: <?php echo json_encode(__('pending_sync')); ?>,
    no_items: <?php echo json_encode(__('no_items')); ?>,
    items: <?php echo json_encode(__('items')); ?>,
    purchases: <?php echo json_encode(__('purchases')); ?>,
    total: <?php echo json_encode(__('total')); ?>,
    edit: <?php echo json_encode(__('edit')); ?>,
    delete: <?php echo json_encode(__('delete')); ?>,
    view: <?php echo json_encode(__('view')); ?>,
    confirm_delete_purchase: <?php echo json_encode(__('confirm_delete_purchase')); ?>,
    edit_only_online: <?php echo json_encode(__('edit_only_online')); ?>,
    delete_only_online: <?php echo json_encode(__('delete_only_online')); ?>,
    editing_only_online: <?php echo json_encode(__('editing_only_online')); ?>,
    purchase_not_found: <?php echo json_encode(__('purchase_not_found')); ?>,
    failed_save_purchase: <?php echo json_encode(__('failed_save_purchase')); ?>,
    failed_delete_purchase: <?php echo json_encode(__('failed_delete_purchase')); ?>,
    failed_load_data: <?php echo json_encode(__('failed_load_data')); ?>,
    saving: <?php echo json_encode(__('saving')); ?>,
    save_purchase: <?php echo json_encode(__('save_purchase')); ?>
};

const isAdmin = true;

let viewModal;
let currentViewPurchaseId = null;
// Global variables
let purchases = [];
let filteredPurchases = [];
let purchaseModal;
let suppliers = [];
let locations = [];
let products = [];
let currencies = [];
let defaultCurrencyId = null;
let selectedCurrencySymbol = 'TZS';
let itemsArray = [];
let isEditMode = false;
let editingPurchaseId = null;
// Set when the create modal was opened prefilled from a requisition (?from_requisition).
// Sent as requisition_id on create so the purchase links back. Reset by showCreateModal().
let prefillRequisitionId = null;
let prefillBudgetId = null;
let prefillPurchaseOrderId = null;

// Supplier-drilldown filter from URL (?supplier_id=). Null when not drilling in.
const supplierFilterId = (() => {
    const v = new URLSearchParams(window.location.search).get('supplier_id');
    const n = parseInt(v, 10);
    return Number.isFinite(n) && n > 0 ? n : null;
})();

// Initialize
document.addEventListener('DOMContentLoaded', async function() {
    console.log('🎬 Purchases page loaded');
    
    // Initialize modal
    purchaseModal = new bootstrap.Modal(document.getElementById('purchaseModal'));
	viewModal = new bootstrap.Modal(document.getElementById('viewModal')); 
    
    // Initialize sync manager and offline DB
    await syncManager.init();
    
    // Set date filter (use stored dates or default to today).
    // When drilling in from the dashboard payables modal, show all-time so totals match.
    if (supplierFilterId) {
        document.getElementById('dateFrom').value = '';
        document.getElementById('dateTo').value = '';
        document.getElementById('supplierFilterBanner').style.display = 'flex';
    } else {
        const today = new Date().toISOString().split('T')[0];
        const storedDateFrom = sessionStorage.getItem('purchasesFilterDateFrom');
        const storedDateTo = sessionStorage.getItem('purchasesFilterDateTo');
        // With a remembered product filter, a stored BLANK date means "all
        // time" (set by the product filter) and must not fall back to today.
        const keepBlank = getStoredProductFilter().length > 0;
        document.getElementById('dateFrom').value =
            (keepBlank && storedDateFrom !== null) ? storedDateFrom : (storedDateFrom || today);
        document.getElementById('dateTo').value =
            (keepBlank && storedDateTo !== null) ? storedDateTo : (storedDateTo || today);
    }
    
    // Product filter (must be wired before loadAllData, which calls filterPurchases)
    initProductFilter();

    // Load data
    await loadAllData();

    // Set default date for the "Add New Purchase" form (must run BEFORE the
    // deep-link block below, because editPurchase() inside the deep-link block
    // sets purchase_date from the loaded purchase and we don't want that
    // overwritten back to today).
    document.getElementById('purchaseDate').valueAsDate = new Date();

    // Deep-link edit: if we arrived via ?edit=<id>, the bootstrap was filled
    // server-side. Upsert it into the purchases array (so editPurchase() finds
    // fresh data, not whatever stale entry IndexedDB had) and open the modal.
    if (window.__editNotFound) {
        showToast('Purchase not found — it may have been deleted.', 'warning');
    } else if (window.__editBootstrap) {
        const bp = window.__editBootstrap;
        const idx = purchases.findIndex(p => p.id == bp.id);
        if (idx >= 0) purchases[idx] = bp;
        else purchases.push(bp);
        editPurchase(bp.id);
    }

    // Prefill from a requisition (?from_requisition=<id>): open the create modal
    // with items + quantities populated; the user adds supplier/rates and saves.
    if (window.__reqPrefill) {
        applyRequisitionPrefill(window.__reqPrefill);
    }

    // Prefill from an approved Budget (?from_budget): like requisition prefill,
    // but the supplier is preset and the saved purchase links back via budget_id.
    if (window.__budgetPrefill) {
        applyBudgetPrefill(window.__budgetPrefill);
    }

    // Prefill from an approved Purchase Order (?from_po): supplier/location/
    // currency preset, items at remaining qty + approved rate; the saved
    // purchase links back via purchase_order_id.
    if (window.__poPrefill) {
        applyPoPrefill(window.__poPrefill);
    }
    
    // Date filters - save to sessionStorage and re-fetch from server on change
    document.getElementById('dateFrom').addEventListener('change', async function() {
        sessionStorage.setItem('purchasesFilterDateFrom', this.value);
        await loadPurchases();
        filterPurchases();
    });
    document.getElementById('dateTo').addEventListener('change', async function() {
        sessionStorage.setItem('purchasesFilterDateTo', this.value);
        await loadPurchases();
        filterPurchases();
    });
});

// Load all required data
async function loadAllData() {
    console.log('📥 Loading all data...');
    document.getElementById('loadingSpinner').style.display = 'block';
    document.getElementById('purchasesContainer').style.display = 'none';
    document.getElementById('emptyState').style.display = 'none';
    
    try {
        // Load purchases, suppliers, locations, products, currencies
        await Promise.all([
            loadPurchases(),
            loadSuppliers(),
            loadLocations(),
            loadProducts(),
            loadCurrencies(),
            loadPurchasedProducts()
        ]);
        
        console.log('✅ All data loaded');
        filterPurchases();
        
    } catch (error) {
        console.error('❌ Failed to load data:', error);
        showToast('Failed to load data: ' + error.message, 'danger');
    } finally {
        document.getElementById('loadingSpinner').style.display = 'none';
    }
}

// Load purchases (scoped to the current date-range filter)
async function loadPurchases() {
    const dateFrom = document.getElementById('dateFrom').value || null;
    const dateTo = document.getElementById('dateTo').value || null;
    console.log('📦 Loading purchases...', { dateFrom, dateTo });
    purchases = await syncManager.getPurchases(dateFrom, dateTo);
    filteredPurchases = purchases;
    console.log(`✅ Loaded ${purchases.length} purchases`);
}

// Refresh purchases directly from the server (bypasses the IndexedDB cache for
// the active date range). Reconciles the cache:
//   - synced entries within the date range that the server didn't return are
//     deleted from IndexedDB (covers the "deleted-on-server" case);
//   - offline-pending (temp_*) entries are preserved untouched;
//   - entries outside the date range are preserved untouched.
async function refreshFromServer() {
    if (!syncManager.isOnline) {
        showToast('You must be online to refresh', 'warning');
        return;
    }

    const btn = document.getElementById('refreshBtn');
    const oldHTML = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>...';
    }

    try {
        const dateFrom = document.getElementById('dateFrom').value;
        const dateTo   = document.getElementById('dateTo').value;

        const params = new URLSearchParams();
        if (dateFrom) params.set('date_from', dateFrom);
        if (dateTo)   params.set('date_to',   dateTo);
        params.set('_', Date.now()); // cache-bust

        const url = `${BASE_URL}api/purchases.php?${params.toString()}`;
        const response = await fetch(url, {
            method: 'GET',
            headers: { 'Cache-Control': 'no-cache' },
            cache: 'no-store'
        });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const result = await response.json();
        if (!result.success) throw new Error(result.error || 'Failed to fetch');

        const serverIdSet = new Set(result.purchases.map(p => String(p.id)));
        const cachedAll   = await offlineDB.getAll('purchases');

        // Reconcile IndexedDB: drop synced entries in range that server no longer has.
        for (const cached of cachedAll) {
            const id = String(cached.id);
            if (id.startsWith('temp_')) continue; // never touch offline-pending
            const inRange = (!dateFrom || cached.purchase_date >= dateFrom) &&
                            (!dateTo   || cached.purchase_date <= dateTo);
            if (inRange && !serverIdSet.has(id)) {
                await offlineDB.delete('purchases', cached.id);
            }
        }
        await offlineDB.upsertAll('purchases', result.purchases);

        // Replace in-memory list = fresh server data + preserved offline-pending
        const offlinePending = cachedAll.filter(p => String(p.id).startsWith('temp_'));
        purchases = [...result.purchases, ...offlinePending];

        await loadPurchasedProducts();
        filterPurchases();
        showToast(`Refreshed (${result.purchases.length} from server)`, 'success');
    } catch (error) {
        console.error('Refresh failed:', error);
        showToast('Refresh failed: ' + error.message, 'danger');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = oldHTML || '<i class="bi bi-arrow-clockwise"></i> <?php echo addslashes(__('refresh')); ?>';
        }
    }
}

// Load suppliers - always fetch from server when online, fallback to cache when offline
async function loadSuppliers() {
    console.log('🚚 Loading suppliers...');

    // If online, always fetch fresh data from server
    if (syncManager.isOnline) {
        console.log('📡 Online - fetching suppliers from server...');
        try {
            const response = await fetch(BASE_URL + 'api/suppliers.php?_=' + Date.now(), {
                method: 'GET',
                headers: {
                    'Cache-Control': 'no-cache, no-store, must-revalidate',
                    'Pragma': 'no-cache'
                },
                cache: 'no-store'
            });

            if (response.ok) {
                const result = await response.json();
                if (result.success && result.suppliers) {
                    suppliers = result.suppliers;
                    // Cache them for offline use
                    await offlineDB.replaceAll('suppliers', suppliers);
                    console.log(`✅ Fetched and cached ${suppliers.length} suppliers from server`);
                }
            }
        } catch (error) {
            console.error('❌ Failed to fetch suppliers from server:', error);
            // Fall back to cache on network error
            suppliers = await offlineDB.getAll('suppliers');
            console.log(`⚠️ Using cached suppliers (${suppliers.length})`);
        }
    } else {
        // Offline - use cached data
        suppliers = await offlineDB.getAll('suppliers');
        console.log(`📦 Offline - using cached suppliers (${suppliers.length})`);
    }
    
    // Filter active suppliers
    suppliers = suppliers.filter(s => s.is_active == 1);

    // Populate supplier dropdown
    const supplierSelect = document.getElementById('supplierId');
    const supplierHtml = ['<option value="">-- Select Supplier --</option>'];
    suppliers.forEach(supplier => {
        supplierHtml.push(`<option value="${supplier.id}">${escapeHtml(supplier.name)}</option>`);
    });
    supplierSelect.innerHTML = supplierHtml.join('');

    // If drilling in from dashboard, set the badge to show which supplier we're filtering by.
    if (supplierFilterId) {
        const match = suppliers.find(s => parseInt(s.id, 10) === supplierFilterId);
        const nameEl = document.getElementById('supplierFilterName');
        if (nameEl) nameEl.textContent = match ? match.name : `#${supplierFilterId}`;
    }

    console.log(`✅ Loaded ${suppliers.length} active suppliers`);
}

// Tree helper functions for indented dropdowns
function buildTreeJS(items, parentId = null, depth = 0) {
    const branch = [];
    items.forEach(item => {
        const itemParent = item.parent_id ? parseInt(item.parent_id) : null;
        if (itemParent === parentId) {
            const node = { ...item, depth, children: [] };
            node.children = buildTreeJS(items, parseInt(item.id), depth + 1);
            branch.push(node);
        }
    });
    return branch;
}

function flattenTreeJS(tree) {
    const result = [];
    tree.forEach(item => {
        const children = item.children;
        const flat = { ...item };
        delete flat.children;
        result.push(flat);
        if (children && children.length > 0) result.push(...flattenTreeJS(children));
    });
    return result;
}

// Load locations
async function loadLocations() {
    console.log('📍 Loading locations...');
    locations = await syncManager.getLocations();

    // Populate location dropdown (tree-indented)
    const locationSelect = document.getElementById('locationId');
    const tree = buildTreeJS(locations);
    const sorted = flattenTreeJS(tree);
    const locationHtml = ['<option value="">-- Select Location --</option>'];
    sorted.forEach(location => {
        const indent = '\u2014 '.repeat(location.depth || 0);
        locationHtml.push(`<option value="${location.id}">${escapeHtml(indent + location.name)}</option>`);
    });
    locationSelect.innerHTML = locationHtml.join('');

    // Auto-select if only one location
    if (locations.length === 1) {
        locationSelect.value = locations[0].id;
    }

    console.log(`✅ Loaded ${locations.length} locations`);
}

// Load products
async function loadProducts() {
    console.log('📦 Loading products...');
    products = await syncManager.getProducts();

    // Populate product dropdown
    const productSelect = document.getElementById('productSelect');
    const productHtml = ['<option value="">-- Select Product to Add --</option>'];
    products.forEach(product => {
        productHtml.push(`<option value="${product.id}" data-unit="${escapeHtml(product.unit || '')}">${escapeHtml(product.name)} (${escapeHtml(product.category_name || 'No Category')})</option>`);
    });
    productSelect.innerHTML = productHtml.join('');

    // Make product dropdown searchable (type-to-filter) inside the modal
    if (window.jQuery && $.fn.select2) {
        if ($('#productSelect').hasClass('select2-hidden-accessible')) {
            $('#productSelect').select2('destroy');
        }
        $('#productSelect').select2({
            width: '100%',
            placeholder: '-- Select Product to Add --',
            dropdownParent: $('#purchaseModal')
        });
    }

    console.log(`✅ Loaded ${products.length} products`);
}

// Load currencies
async function loadCurrencies() {
    console.log('Loading currencies...');
    try {
        const response = await fetch(BASE_URL + 'api/currencies.php?_=' + Date.now(), {
            method: 'GET',
            headers: { 'Cache-Control': 'no-cache' },
            cache: 'no-store'
        });
        if (response.ok) {
            const result = await response.json();
            if (result.success && result.currencies) {
                currencies = result.currencies;
            }
        }
    } catch (error) {
        console.error('Failed to load currencies:', error);
    }

    // Populate currency dropdown
    const currencySelect = document.getElementById('currencyId');
    currencySelect.innerHTML = '';
    currencies.forEach(c => {
        const opt = document.createElement('option');
        opt.value = c.id;
        opt.textContent = c.code + ' - ' + c.name;
        opt.dataset.symbol = c.symbol;
        opt.dataset.isDefault = c.is_default;
        if (c.is_default == 1) {
            opt.selected = true;
            defaultCurrencyId = parseInt(c.id);
            selectedCurrencySymbol = c.symbol;
        }
        currencySelect.appendChild(opt);
    });
    console.log(`Loaded ${currencies.length} currencies, default: ${defaultCurrencyId}`);
}

// Handle currency change
function onCurrencyChange() {
    const select = document.getElementById('currencyId');
    const currId = parseInt(select.value);
    const opt = select.options[select.selectedIndex];
    selectedCurrencySymbol = opt ? opt.dataset.symbol : 'TZS';

    const exchangeRateGroup = document.getElementById('exchangeRateGroup');
    if (currId === defaultCurrencyId) {
        exchangeRateGroup.style.display = 'none';
        document.getElementById('exchangeRateToBase').value = '1';
    } else {
        exchangeRateGroup.style.display = '';
        // Fetch latest exchange rate
        fetchExchangeRate(currId);
    }
    // Refresh items table to show new currency symbol
    updateItemsTable();
}

// Fetch exchange rate from server
async function fetchExchangeRate(currencyId) {
    const purchaseDate = document.getElementById('purchaseDate').value || new Date().toISOString().split('T')[0];
    try {
        const url = `${BASE_URL}api/exchange-rates.php?from_currency_id=${currencyId}&to_currency_id=${defaultCurrencyId}&_=${Date.now()}`;
        const response = await fetch(url, {
            method: 'GET',
            headers: { 'Cache-Control': 'no-cache' },
            cache: 'no-store'
        });
        if (response.ok) {
            const result = await response.json();
            if (result.success && result.exchange_rates && result.exchange_rates.length > 0) {
                // Find latest rate on or before purchase date
                const applicable = result.exchange_rates.filter(r => r.effective_date <= purchaseDate);
                if (applicable.length > 0) {
                    document.getElementById('exchangeRateToBase').value = applicable[0].rate;
                    const fromCur = currencies.find(c => c.id == currencyId);
                    const toCur = currencies.find(c => c.id == defaultCurrencyId);
                    document.getElementById('exchangeRateHint').textContent =
                        `1 ${fromCur ? fromCur.code : ''} = ${applicable[0].rate} ${toCur ? toCur.code : ''}`;
                    return;
                }
            }
            // Try reverse
            const revUrl = `${BASE_URL}api/exchange-rates.php?from_currency_id=${defaultCurrencyId}&to_currency_id=${currencyId}&_=${Date.now()}`;
            const revResponse = await fetch(revUrl, {
                method: 'GET',
                headers: { 'Cache-Control': 'no-cache' },
                cache: 'no-store'
            });
            if (revResponse.ok) {
                const revResult = await revResponse.json();
                if (revResult.success && revResult.exchange_rates && revResult.exchange_rates.length > 0) {
                    const applicable = revResult.exchange_rates.filter(r => r.effective_date <= purchaseDate);
                    if (applicable.length > 0 && parseFloat(applicable[0].rate) !== 0) {
                        const reverseRate = (1 / parseFloat(applicable[0].rate)).toFixed(8);
                        document.getElementById('exchangeRateToBase').value = reverseRate;
                        const fromCur = currencies.find(c => c.id == currencyId);
                        const toCur = currencies.find(c => c.id == defaultCurrencyId);
                        document.getElementById('exchangeRateHint').textContent =
                            `1 ${fromCur ? fromCur.code : ''} = ${reverseRate} ${toCur ? toCur.code : ''} (reverse)`;
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

// Display purchases in table
function displayPurchases() {
    const tbody = document.getElementById('purchasesTableBody');
    const container = document.getElementById('purchasesContainer');
    const emptyState = document.getElementById('emptyState');
    
    if (filteredPurchases.length === 0) {
        container.style.display = 'none';
        emptyState.style.display = 'block';
        return;
    }
    
    container.style.display = 'block';
    emptyState.style.display = 'none';
    
    // Calculate totals (Bill Amount = goods value + VAT).
    //
    // Grouped by currency: a plain sum over a filtered set that mixes currencies
    // adds USD to TZS and then labels the result with the default symbol. Each
    // row is converted with its OWN exchange_rate_to_base and the base-currency
    // figure is what the header shows.
    // For an all-base-currency set every rate is 1, so this produces the
    // identical number the old reduce() did — nothing changes on a
    // single-currency tenant.
    let totalAmount = 0;
    const byCurrency = new Map();   // currency_id -> { symbol, decimals, sum }

    filteredPurchases.forEach(p => {
        const bill = parseFloat(p.total_amount || 0) + parseFloat(p.vat_amount || 0);
        const rate = parseFloat(p.exchange_rate_to_base) || 1;
        totalAmount += bill * rate;

        const key = p.currency_id != null ? String(p.currency_id) : 'default';
        if (!byCurrency.has(key)) {
            byCurrency.set(key, { symbol: rowCurrencySymbol(p), decimals: rowCurrencyDecimals(p), sum: 0 });
        }
        byCurrency.get(key).sum += bill;
    });

    const hasForeign = filteredPurchases.some(rowIsForeignCurrency);

    document.getElementById('purchaseCount').textContent = `${filteredPurchases.length} purchase${filteredPurchases.length !== 1 ? 's' : ''}`;
    if (isAdmin) {
        const baseTotal = formatCurrency(totalAmount, window.DEFAULT_CURRENCY_DECIMALS, window.DEFAULT_CURRENCY_SYMBOL);
        const totalEl = document.getElementById('totalAmount');

        if (hasForeign) {
            // Say so rather than presenting a converted figure as if it were native.
            const parts = [...byCurrency.values()]
                .map(c => escapeHtml(formatCurrency(c.sum, c.decimals, c.symbol)))
                .join(' &middot; ');
            totalEl.innerHTML = `Total: ${escapeHtml(baseTotal)}`
                + `<br><small class="text-muted fw-normal">`
                + `${escapeHtml(CONVERTED_TO_BASE)}: ${parts}</small>`;
        } else {
            totalEl.textContent = `Total: ${baseTotal}`;
        }
    }
    
    tbody.innerHTML = filteredPurchases.map(purchase => {
        const isSynced = purchase.synced !== false && !purchase.id.toString().startsWith('temp_');
        const rowClass = !isSynced ? 'purchase-row-pending' : '';
        const statusBadge = isSynced 
            ? '<span class="badge badge-synced"><i class="bi bi-check-circle"></i> Synced</span>'
            : '<span class="badge badge-pending"><i class="bi bi-clock"></i> Pending</span>';
        
        const itemCount = purchase.details ? purchase.details.length : 0;
        
        return `
            <tr class="${rowClass}">
                <td>
                    <strong>${escapeHtml(purchase.purchase_number)}</strong>
                </td>
                <td>${escapeHtml(purchase.po_number || '-')}</td>
                <td>${formatDate(purchase.purchase_date)}</td>
                <td>${escapeHtml(purchase.supplier_name || 'Unknown')}</td>
                <td>${escapeHtml(purchase.location_name || 'Unknown')}</td>
                <td class="text-center">
                    <span class="badge bg-info">${itemCount}</span>
                </td>
                ${isAdmin ? `
                <td class="text-end"${rowFxTitle(purchase, parseFloat(purchase.total_amount || 0) + parseFloat(purchase.vat_amount || 0)) ? ` title="${escapeHtml(rowFxTitle(purchase, parseFloat(purchase.total_amount || 0) + parseFloat(purchase.vat_amount || 0)))}"` : ''}>
                    <strong>${formatRowCurrency(purchase, parseFloat(purchase.total_amount || 0) + parseFloat(purchase.vat_amount || 0))}</strong>
                </td>
                ` : ''}
                <td class="text-center">${statusBadge}</td>
                <td class="text-center">
                    <button class="btn btn-sm btn-info" onclick="viewPurchase('${purchase.id}')" title="View">
                        <i class="bi bi-eye"></i>
                    </button>
                    ${hasEditPermission() && isSynced ? `
                        <button class="btn btn-sm btn-warning" onclick="editPurchase('${purchase.id}')" 
                                title="Edit" id="editBtn_${purchase.id}">
                            <i class="bi bi-pencil"></i>
                        </button>
                    ` : ''}
                    ${isSynced ? `
                        <button class="btn btn-sm btn-success" onclick="exportPurchaseItems('${purchase.id}')"
                                title="Export line items to Excel">
                            <i class="bi bi-file-earmark-excel"></i>
                        </button>
                    ` : ''}
                    ${hasDeletePermission() && isSynced ? `
                        <button class="btn btn-sm btn-danger" onclick="deletePurchase('${purchase.id}')"
                                title="Delete" id="deleteBtn_${purchase.id}">
                            <i class="bi bi-trash"></i>
                        </button>
                    ` : ''}
                </td>
            </tr>
        `;
    }).join('');
}

// ---- Product filter ---------------------------------------------------------
// Remembered in sessionStorage like the dates. The selection is always the
// stored ids that are still offered, so no hidden filter can linger.
const PRODUCT_FILTER_KEY = 'purchasesFilterProducts';
// Picking the first product switches the list to all time; the dates in use
// before that are kept here and restored when the last product is removed.
const DATES_BEFORE_PRODUCTS_KEY = 'purchasesFilterDatesBeforeProducts';
let productFilterSignature = null;

// Set both date boxes and remember them, exactly as typing into them does.
function setListDates(from, to) {
    document.getElementById('dateFrom').value = from;
    document.getElementById('dateTo').value = to;
    try {
        sessionStorage.setItem('purchasesFilterDateFrom', from);
        sessionStorage.setItem('purchasesFilterDateTo', to);
    } catch (e) {}
}

function getStoredProductFilter() {
    try {
        const v = JSON.parse(sessionStorage.getItem(PRODUCT_FILTER_KEY) || '[]');
        return Array.isArray(v) ? v.map(String) : [];
    } catch (e) { return []; }
}

function setStoredProductFilter(ids) {
    try {
        if (ids.length) sessionStorage.setItem(PRODUCT_FILTER_KEY, JSON.stringify(ids));
        else sessionStorage.removeItem(PRODUCT_FILTER_KEY);
    } catch (e) { /* storage unavailable: filter still works for this page view */ }
}

function getSelectedProductIds() {
    const el = document.getElementById('productFilter');
    return el ? Array.from(el.selectedOptions).map(o => o.value) : [];
}

function initProductFilter() {
    const el = document.getElementById('productFilter');
    if (!el) return;
    const onChange = async () => {
        const hadProducts = getStoredProductFilter().length > 0;
        const ids = getSelectedProductIds();
        setStoredProductFilter(ids);

        const fromEl = document.getElementById('dateFrom');
        const toEl = document.getElementById('dateTo');
        if (!hadProducts && ids.length > 0) {
            // First product picked: remember the dates, then show ALL TIME.
            // The user can still type dates afterwards to narrow it down.
            try {
                sessionStorage.setItem(DATES_BEFORE_PRODUCTS_KEY,
                    JSON.stringify({ from: fromEl.value, to: toEl.value }));
            } catch (e) {}
            setListDates('', '');
            await loadPurchases();
        } else if (hadProducts && ids.length === 0) {
            // Last product removed: put back the dates from before.
            let saved = null;
            try { saved = JSON.parse(sessionStorage.getItem(DATES_BEFORE_PRODUCTS_KEY) || 'null'); } catch (e) {}
            try { sessionStorage.removeItem(DATES_BEFORE_PRODUCTS_KEY); } catch (e) {}
            if (saved) {
                setListDates(saved.from || '', saved.to || '');
                await loadPurchases();
            }
        }
        filterPurchases();
    };
    if (window.jQuery && $.fn.select2) {
        $(el).select2({
            width: '100%',
            placeholder: el.dataset.placeholder || '',
            allowClear: true,
            closeOnSelect: false
        });
        // Select2 fires change through jQuery, which addEventListener does not see.
        $(el).on('change', onChange);
    } else {
        el.addEventListener('change', onChange);
    }
}

// Every product ever purchased (any date), id -> name. Filled by
// loadPurchasedProducts(); NOT tied to the date range, so the dropdown is never
// empty just because the chosen dates hold no purchases. Results still follow
// the dates in filterPurchases().
let purchasedProducts = new Map();

function addPurchaseDetailsToProductMap(map, list) {
    (list || []).forEach(p => {
        (p.details || []).forEach(d => {
            if (d.product_id == null || d.product_id === '') return;
            const id = String(d.product_id);
            if (!map.has(id) && d.product_name) map.set(id, d.product_name);
        });
    });
}

// Online: api/purchased-products.php. Offline or on failure: the products on
// every purchase already cached on this device.
async function loadPurchasedProducts() {
    const map = new Map();
    let fromServer = false;
    if (syncManager.isOnline) {
        try {
            const res = await fetch(`${BASE_URL}api/purchased-products.php?_=${Date.now()}`,
                                    { cache: 'no-store' });
            if (res.ok) {
                const result = await res.json();
                if (result.success && Array.isArray(result.products)) {
                    result.products.forEach(pr => map.set(String(pr.id), pr.name));
                    fromServer = true;
                }
            }
        } catch (e) {
            console.warn('Purchased products fetch failed, using cache', e);
        }
    }
    if (!fromServer) {
        try {
            addPurchaseDetailsToProductMap(map, await offlineDB.getAll('purchases'));
        } catch (e) { /* no cache: in-memory purchases still feed the list */ }
    }
    purchasedProducts = map;
    productFilterSignature = null;
}

// Rebuild the options. Only touches the DOM when the set of products or the
// selection actually changed, so typing in the search box costs nothing.
function refreshProductFilterOptions() {
    const el = document.getElementById('productFilter');
    if (!el) return;

    // Purchased-ever list + anything on the loaded purchases (covers offline
    // temp_* purchases and one just saved with a first-time product).
    const names = new Map(purchasedProducts);
    addPurchaseDetailsToProductMap(names, purchases);

    // A remembered pick is kept even if this list lacks it (e.g. a smaller
    // offline cache), as long as the product itself is known.
    const stored = getStoredProductFilter();
    stored.forEach(id => {
        if (names.has(id)) return;
        const prod = products.find(x => String(x.id) === id);
        if (prod) names.set(id, prod.name);
    });

    const sorted = Array.from(names.entries())
        .sort((a, b) => String(a[1]).localeCompare(String(b[1])));
    const selected = stored.filter(id => names.has(id));
    const signature = JSON.stringify([sorted.map(e => e[0]), selected]);
    if (signature === productFilterSignature) return;
    productFilterSignature = signature;

    const selSet = new Set(selected);
    el.innerHTML = sorted.map(([id, name]) =>
        `<option value="${escapeHtml(id)}"${selSet.has(id) ? ' selected' : ''}>${escapeHtml(name)}</option>`
    ).join('');
    // Refresh Select2's display only; the namespaced event skips onChange.
    if (window.jQuery && $(el).hasClass('select2-hidden-accessible')) {
        $(el).trigger('change.select2');
    }
    if (selected.length !== stored.length) setStoredProductFilter(selected);
}

// Filter purchases
function filterPurchases() {
    const searchTerm = document.getElementById('searchInput').value.toLowerCase();
    const dateFrom = document.getElementById('dateFrom').value;
    const dateTo = document.getElementById('dateTo').value;

    refreshProductFilterOptions();
    const productIds = new Set(getSelectedProductIds());

    filteredPurchases = purchases.filter(purchase => {
        // Product filter: purchase contains ANY selected product
        if (productIds.size > 0 &&
            !(purchase.details || []).some(d => productIds.has(String(d.product_id)))) {
            return false;
        }

        // Supplier drill-down filter (from ?supplier_id= URL param)
        if (supplierFilterId && parseInt(purchase.supplier_id, 10) !== supplierFilterId) {
            return false;
        }

        // Search filter
        const matchesSearch = !searchTerm ||
            purchase.purchase_number.toLowerCase().includes(searchTerm) ||
            (purchase.po_number && purchase.po_number.toLowerCase().includes(searchTerm)) ||
            (purchase.supplier_name && purchase.supplier_name.toLowerCase().includes(searchTerm)) ||
            (purchase.location_name && purchase.location_name.toLowerCase().includes(searchTerm));

        // Date filters
        const purchaseDate = purchase.purchase_date;
        const matchesDateFrom = !dateFrom || purchaseDate >= dateFrom;
        const matchesDateTo = !dateTo || purchaseDate <= dateTo;

        return matchesSearch && matchesDateFrom && matchesDateTo;
    });

    displayPurchases();
}

// Clear filters
async function clearFilters() {
    document.getElementById('searchInput').value = '';
    document.getElementById('dateFrom').value = '';
    document.getElementById('dateTo').value = '';
    sessionStorage.removeItem('purchasesFilterDateFrom');
    sessionStorage.removeItem('purchasesFilterDateTo');
    setStoredProductFilter([]);
    try { sessionStorage.removeItem(DATES_BEFORE_PRODUCTS_KEY); } catch (e) {}
    const pf = document.getElementById('productFilter');
    if (pf) Array.from(pf.options).forEach(o => { o.selected = false; });
    productFilterSignature = null;
    await loadPurchases();
    filterPurchases();
}

// Show create modal
function showCreateModal() {
    isEditMode = false;
    editingPurchaseId = null;
    prefillRequisitionId = null; // plain "Add New Purchase" is never auto-linked to a requisition
    prefillBudgetId = null;
    prefillPurchaseOrderId = null;

    document.getElementById('modalTitle').textContent = LANG.add_new_purchase;
    document.getElementById('purchaseForm').reset();
    document.getElementById('purchaseId').value = '';
    document.getElementById('purchaseDate').valueAsDate = new Date();
    document.getElementById('poNumber').value = '';
    
    // Auto-select if only one location
    if (locations.length === 1) {
        document.getElementById('locationId').value = locations[0].id;
    }

    // Reset currency to default
    if (defaultCurrencyId) {
        document.getElementById('currencyId').value = defaultCurrencyId;
    }
    document.getElementById('exchangeRateToBase').value = '1';
    document.getElementById('exchangeRateGroup').style.display = 'none';
    const defCur = currencies.find(c => c.is_default == 1);
    selectedCurrencySymbol = defCur ? defCur.symbol : 'TZS';

    itemsArray = [];
    document.getElementById('modalVatAmount').value = '0';
    resetVatControls();
    updateItemsTable();

    // Sync Select2 display with reset product select
    if (window.jQuery && $('#productSelect').hasClass('select2-hidden-accessible')) {
        $('#productSelect').val('').trigger('change');
    }

    purchaseModal.show();
}

// Open the create modal pre-populated from a requisition voucher. Reuses
// showCreateModal() for a clean reset, then overrides location / cost centre /
// items and remembers the requisition id so the saved purchase links back.
function applyRequisitionPrefill(prefill) {
    showCreateModal();
    prefillRequisitionId = prefill.requisition_id || null;

    if (prefill.location_id) {
        document.getElementById('locationId').value = prefill.location_id;
    }
    if (prefill.cost_centre_id) {
        document.getElementById('costCentreId').value = prefill.cost_centre_id;
    }

    itemsArray = (prefill.items || []).map(it => ({
        product_id: it.product_id,
        product_name: it.product_name,
        unit: it.unit,
        quantity: parseFloat(it.quantity) || 0,
        rate_per_unit: parseFloat(it.rate_per_unit) || 0
    }));
    updateItemsTable();

    if (!itemsArray.length) {
        showToast('No active products found on this requisition to prefill.', 'warning');
    }
}

// Open the create modal pre-populated from an approved Budget for one supplier.
// Presets supplier + location + cost centre + items (remaining-to-create qty and
// budgeted rate), and remembers budget_id + requisition_id so the saved purchase
// links back.
function applyBudgetPrefill(prefill) {
    showCreateModal();
    prefillBudgetId = prefill.budget_id || null;
    prefillRequisitionId = prefill.requisition_id || null;

    if (prefill.location_id) {
        document.getElementById('locationId').value = prefill.location_id;
    }
    if (prefill.cost_centre_id) {
        document.getElementById('costCentreId').value = prefill.cost_centre_id;
    }
    if (prefill.supplier_id) {
        const supEl = document.getElementById('supplierId');
        supEl.value = prefill.supplier_id;
        if (window.jQuery && $(supEl).hasClass('select2-hidden-accessible')) {
            $(supEl).val(String(prefill.supplier_id)).trigger('change');
        }
    }

    itemsArray = (prefill.items || []).map(it => ({
        product_id: it.product_id,
        product_name: it.product_name,
        unit: it.unit,
        quantity: parseFloat(it.quantity) || 0,
        rate_per_unit: parseFloat(it.rate_per_unit) || 0
    }));
    updateItemsTable();

    // VAT agreed on the budget lines, pro-rated to what is being purchased now.
    // MUST come after showCreateModal() above — that resets the VAT box to '0'
    // — and needs updatePayable() so the Payable figure picks it up.
    applyPrefilledVat(prefill.vat_amount);

    if (!itemsArray.length) {
        showToast('Nothing left to purchase for this supplier on the budget.', 'warning');
    }
}

/**
 * Drop a prefilled VAT amount into the entry form.
 *
 * Left as a plain amount rather than switching on the % toggle: the figure comes
 * from summing several budget lines that may each carry a different rate, so
 * there is not necessarily one percentage that describes it. The user can still
 * flip the % toggle to re-derive it from the items total.
 */
function applyPrefilledVat(amount) {
    const vat = parseFloat(amount);
    if (isNaN(vat) || vat <= 0) return;
    const el = document.getElementById('modalVatAmount');
    if (!el) return;
    el.value = vat.toFixed(2);
    updatePayable();
}

// Open the create modal pre-populated from an approved Purchase Order. Presets
// supplier + location + cost centre + currency (with the PO's exchange rate)
// and items (remaining-to-purchase qty at the approved rate). Remembers
// purchase_order_id — plus budget_id/requisition_id when the PO came from a
// budget — so the saved purchase links back.
function applyPoPrefill(prefill) {
    showCreateModal();
    prefillPurchaseOrderId = prefill.purchase_order_id || null;
    prefillBudgetId = prefill.budget_id || null;
    prefillRequisitionId = prefill.requisition_id || null;

    if (prefill.location_id) {
        document.getElementById('locationId').value = prefill.location_id;
    }
    if (prefill.cost_centre_id) {
        document.getElementById('costCentreId').value = prefill.cost_centre_id;
    }
    if (prefill.supplier_id) {
        const supEl = document.getElementById('supplierId');
        supEl.value = prefill.supplier_id;
        if (window.jQuery && $(supEl).hasClass('select2-hidden-accessible')) {
            $(supEl).val(String(prefill.supplier_id)).trigger('change');
        }
    }
    if (prefill.currency_id) {
        document.getElementById('currencyId').value = prefill.currency_id;
        onCurrencyChange();
        // The PO's stored rate wins over the auto-fetched one.
        if (prefill.exchange_rate_to_base) {
            document.getElementById('exchangeRateToBase').value = prefill.exchange_rate_to_base;
        }
    }

    itemsArray = (prefill.items || []).map(it => ({
        product_id: it.product_id,
        product_name: it.product_name,
        unit: it.unit,
        quantity: parseFloat(it.quantity) || 0,
        rate_per_unit: parseFloat(it.rate_per_unit) || 0
    }));
    updateItemsTable();

    // The PO's VAT, pro-rated to the lines being purchased now. Same ordering
    // constraint as the budget prefill — after showCreateModal()'s reset.
    applyPrefilledVat(prefill.vat_amount);

    if (!itemsArray.length) {
        showToast('Nothing left to purchase on this purchase order.', 'warning');
    }
}

// Add item to purchase
function addItem() {
    const productSelect = document.getElementById('productSelect');
    const productId = productSelect.value;
    const quantity = parseFloat(document.getElementById('itemQuantity').value);
    // For non-admin, rate is always 0
    const rateInput = document.getElementById('itemRate');
    const rate = isAdmin ? parseFloat(rateInput?.value || 0) : 0;
    
    if (!productId) {
        showToast('Please select a product', 'warning');
        return;
    }
    
    if (!quantity || quantity <= 0) {
        showToast('Please enter a valid quantity', 'warning');
        return;
    }
    
    if (isAdmin && rate < 0) {
        showToast('Rate cannot be negative', 'warning');
        return;
    }
    
    // Check if product already exists
    const existingIndex = itemsArray.findIndex(item => item.product_id == productId);
    if (existingIndex !== -1) {
        showToast('This product is already added. Use the edit icon to change quantity or rate.', 'warning');
        return;
    }
    
    // Get product details
    const product = products.find(p => p.id == productId);
    if (!product) {
        showToast('Product not found', 'danger');
        return;
    }
    
    // Add item
    itemsArray.push({
        product_id: productId,
        product_name: product.name,
        unit: product.unit,
        quantity: quantity,
        rate_per_unit: rate
    });
    
    // Clear inputs
    if (window.jQuery && $('#productSelect').hasClass('select2-hidden-accessible')) {
        $('#productSelect').val('').trigger('change');
    } else {
        document.getElementById('productSelect').value = '';
    }
    document.getElementById('itemQuantity').value = '';
    if (isAdmin) {
        document.getElementById('itemRate').value = '';
    }
    
    updateItemsTable();
}

// Remove item
function removeItem(index) {
    if (confirm('Remove this item?')) {
        itemsArray.splice(index, 1);
        updateItemsTable();
    }
}

// Edit item quantity inline
function editQuantity(index, cell) {
    const currentQty = itemsArray[index].quantity;
    const input = document.createElement('input');
    input.type = 'number';
    input.value = currentQty;
    input.step = '0.001';
    input.min = '0.001';
    input.className = 'form-control form-control-sm';
    
    // Replace cell content with input
    const originalContent = cell.innerHTML;
    cell.innerHTML = '';
    cell.appendChild(input);
    cell.classList.add('edit-mode');
    input.focus();
    input.select();
    
    // Save on blur or enter
    const saveEdit = () => {
        const newQty = parseFloat(input.value);
        if (newQty && newQty > 0) {
            itemsArray[index].quantity = newQty;
            updateItemsTable();
        } else {
            cell.innerHTML = originalContent;
            cell.classList.remove('edit-mode');
            showToast('Invalid quantity', 'warning');
        }
    };
    
    input.addEventListener('blur', saveEdit);
    input.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            saveEdit();
        } else if (e.key === 'Escape') {
            cell.innerHTML = originalContent;
            cell.classList.remove('edit-mode');
        }
    });
}

// Edit item rate inline
function editRate(index, cell) {
    const currentRate = itemsArray[index].rate_per_unit;
    const input = document.createElement('input');
    input.type = 'number';
    input.value = currentRate;
    input.step = '0.01';
    input.min = '0';
    input.className = 'form-control form-control-sm';
    
    // Replace cell content with input
    const originalContent = cell.innerHTML;
    cell.innerHTML = '';
    cell.appendChild(input);
    cell.classList.add('edit-mode');
    input.focus();
    input.select();
    
    // Save on blur or enter
    const saveEdit = () => {
        const newRate = parseFloat(input.value);
        if (newRate >= 0) {
            itemsArray[index].rate_per_unit = newRate;
            updateItemsTable();
        } else {
            cell.innerHTML = originalContent;
            cell.classList.remove('edit-mode');
            showToast('Invalid rate', 'warning');
        }
    };
    
    input.addEventListener('blur', saveEdit);
    input.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            saveEdit();
        } else if (e.key === 'Escape') {
            cell.innerHTML = originalContent;
            cell.classList.remove('edit-mode');
        }
    });
}

// Update items table
function updateItemsTable() {
    const tbody = document.getElementById('itemsTableBody');
    const colSpan = isAdmin ? 6 : 4;
    
    if (itemsArray.length === 0) {
        tbody.innerHTML = '<tr id="noItemsRow"><td colspan="' + colSpan + '" class="text-center text-muted">No items added yet</td></tr>';
        if (isAdmin) {
            document.getElementById('modalTotalAmount').textContent = 'TZS 0.00';
        }
        return;
    }
    
    let totalAmount = 0;
    
    tbody.innerHTML = itemsArray.map((item, index) => {
            const amount = item.quantity * item.rate_per_unit;
            totalAmount += amount;
            
            return `
                <tr>
                    <td>${escapeHtml(item.product_name)}</td>
                    <td class="text-center">${escapeHtml(item.unit)}</td>
                    <td class="text-end editable-cell" onclick="editQuantity(${index}, this)" title="Click to edit">
                        ${formatNumber(item.quantity, 3)}
                        <i class="bi bi-pencil-square ms-1 text-muted" style="font-size: 0.8rem;"></i>
                    </td>
                    ${isAdmin ? `
                    <td class="text-end editable-cell" onclick="editRate(${index}, this)" title="Click to edit">
                        ${formatEntryCurrency(item.rate_per_unit)}
                        <i class="bi bi-pencil-square ms-1 text-muted" style="font-size: 0.8rem;"></i>
                    </td>
                    <td class="text-end">${formatEntryCurrency(amount)}</td>
                    ` : ''}
                    <td class="text-center">
                        <button type="button" class="btn btn-sm btn-danger" onclick="removeItem(${index})" title="Remove">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                </tr>
            `;
        }).join('');
    
    if (isAdmin) {
        document.getElementById('modalTotalAmount').textContent = formatEntryCurrency(totalAmount);
    }
    updatePayable();
}

// Sum of (qty × rate) for all items currently in the form (excludes VAT)
function getItemsTotal() {
    let t = 0;
    for (const item of itemsArray) {
        t += parseFloat(item.quantity || 0) * parseFloat(item.rate_per_unit || 0);
    }
    return t;
}

// Compute and display "Payable" = total + VAT
function updatePayable() {
    const totalAmount = getItemsTotal();
    const vatInput = document.getElementById('modalVatAmount');
    let vat = parseFloat(vatInput ? vatInput.value : 0);
    if (isNaN(vat) || vat < 0) vat = 0;
    const payable = totalAmount + vat;
    const payableEl = document.getElementById('modalPayableAmount');
    if (payableEl) payableEl.textContent = formatEntryCurrency(payable);
}

// Toggle between flat-amount mode and percentage mode for VAT.
// ON  : amount becomes read-only, % field is enabled. If a saved/typed VAT
//       amount already exists, derive the equivalent % from current items
//       total and pre-fill the % field.
// OFF : % field clears + disables, amount becomes editable again (current
//       value preserved).
function onVatToggleChange() {
    const toggle   = document.getElementById('modalVatPctToggle');
    const pctInput = document.getElementById('modalVatPct');
    const amtInput = document.getElementById('modalVatAmount');
    if (!toggle || !pctInput || !amtInput) return;

    if (toggle.checked) {
        const total = getItemsTotal();
        const vat   = parseFloat(amtInput.value) || 0;
        pctInput.value    = (total > 0 && vat > 0) ? (vat / total * 100).toFixed(2) : '';
        pctInput.disabled = false;
        amtInput.readOnly = true;
        pctInput.focus();
    } else {
        pctInput.value    = '';
        pctInput.disabled = true;
        amtInput.readOnly = false;
    }
    updatePayable();
}

// User types a % in the percentage field — recompute VAT amount = total × % / 100.
function onVatPctInput() {
    const total = getItemsTotal();
    const pct   = parseFloat(document.getElementById('modalVatPct').value);
    let amt = 0;
    if (total > 0 && !isNaN(pct) && pct >= 0) {
        amt = total * pct / 100;
    }
    document.getElementById('modalVatAmount').value = amt.toFixed(2);
    updatePayable();
}

// Reset VAT controls to default state (toggle OFF, amount editable, % blank+disabled).
function resetVatControls() {
    const toggle   = document.getElementById('modalVatPctToggle');
    const pctInput = document.getElementById('modalVatPct');
    const amtInput = document.getElementById('modalVatAmount');
    if (toggle)   toggle.checked = false;
    if (pctInput) { pctInput.value = ''; pctInput.disabled = true; }
    if (amtInput) amtInput.readOnly = false;
}

// Save purchase
async function savePurchase() {
    // Validate form
    const purchaseDate = document.getElementById('purchaseDate').value;
    const poNumber = document.getElementById('poNumber').value.trim();
    const supplierId = document.getElementById('supplierId').value;
    const locationId = document.getElementById('locationId').value;
    const notes = document.getElementById('notes').value;
    
    if (!purchaseDate) {
        showToast('Please select purchase date', 'warning');
        return;
    }
    
    if (!supplierId) {
        showToast('Please select supplier', 'warning');
        return;
    }
    
    if (!locationId) {
        showToast('Please select location', 'warning');
        return;
    }
    
    if (itemsArray.length === 0) {
        showToast('Please add at least one item', 'warning');
        return;
    }
    
    // Prepare data
    const costCentreId = document.getElementById('costCentreId').value;
    const currencyId = parseInt(document.getElementById('currencyId').value) || defaultCurrencyId;
    const exchangeRateToBase = parseFloat(document.getElementById('exchangeRateToBase').value) || 1;
    let vatAmount = parseFloat(document.getElementById('modalVatAmount').value);
    if (isNaN(vatAmount) || vatAmount < 0) vatAmount = 0;
    const data = {
        purchase_date: purchaseDate,
        po_number: poNumber,
        supplier_id: parseInt(supplierId),
        location_id: parseInt(locationId),
        cost_centre_id: costCentreId ? parseInt(costCentreId) : null,
        currency_id: currencyId,
        exchange_rate_to_base: exchangeRateToBase,
        vat_amount: vatAmount,
        notes: notes,
        details: itemsArray
    };

    // Link back to the originating requisition (create-from-requisition flow only).
    if (!isEditMode && prefillRequisitionId) {
        data.requisition_id = prefillRequisitionId;
    }
    // Link back to the originating budget (create-from-budget flow only).
    if (!isEditMode && prefillBudgetId) {
        data.budget_id = prefillBudgetId;
    }
    // Link back to the originating purchase order (create-from-PO flow only).
    if (!isEditMode && prefillPurchaseOrderId) {
        data.purchase_order_id = prefillPurchaseOrderId;
    }

    // Disable save button
    const saveBtn = document.getElementById('saveBtn');
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>' + LANG.saving;
    
    try {
        if (isEditMode && editingPurchaseId) {
            // Update purchase (online only)
            data.id = editingPurchaseId;
            await syncManager.updatePurchase(data);
        } else {
            // Create new purchase (works offline)
            await syncManager.createPurchase(data);
        }
        
        // Reload purchases
        await loadPurchases();
        filterPurchases();
        
        // Close modal
        purchaseModal.hide();
        
    } catch (error) {
        console.error('❌ Save failed:', error);
        showToast(LANG.failed_save_purchase + ': ' + error.message, 'danger');
    } finally {
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<i class="bi bi-check-circle"></i> ' + LANG.save_purchase;
    }
}

// View purchase in modal
function viewPurchase(id) {
    const purchase = purchases.find(p => p.id == id);
    if (!purchase) {
        showToast(LANG.purchase_not_found, 'danger');
        return;
    }
    
    currentViewPurchaseId = id;
    
    // Populate view modal
    document.getElementById('viewPurchaseNumber').textContent = purchase.purchase_number;
    document.getElementById('viewPoNumber').textContent = purchase.po_number || '-';
    document.getElementById('viewDate').textContent = formatDate(purchase.purchase_date);
    document.getElementById('viewSupplier').textContent = purchase.supplier_name || 'Unknown';
    document.getElementById('viewLocation').textContent = purchase.location_name || 'Unknown';

    // Cost Centre
    const ccRow = document.getElementById('viewCostCentreRow');
    if (purchase.cost_centre_id && purchase.cost_centre_name) {
        document.getElementById('viewCostCentre').textContent = (purchase.cost_centre_code || '') + ' - ' + purchase.cost_centre_name;
        ccRow.style.display = '';
    } else {
        ccRow.style.display = 'none';
    }

    // Status badge
    const isSynced = purchase.synced !== false && !purchase.id.toString().startsWith('temp_');
    const statusBadge = isSynced 
        ? '<span class="badge badge-synced"><i class="bi bi-check-circle"></i> ' + LANG.synced + '</span>'
        : '<span class="badge badge-pending"><i class="bi bi-clock"></i> ' + LANG.pending_sync + '</span>';
    document.getElementById('viewStatus').innerHTML = statusBadge;
    
    // Notes
    if (purchase.notes && purchase.notes.trim()) {
        document.getElementById('viewNotesRow').style.display = '';
        document.getElementById('viewNotes').textContent = purchase.notes;
    } else {
        document.getElementById('viewNotesRow').style.display = 'none';
    }
    
    // Items table
    const itemsBody = document.getElementById('viewItemsBody');
    if (purchase.details && purchase.details.length > 0) {
        itemsBody.innerHTML = purchase.details.map((item, index) => {
            const amount = parseFloat(item.quantity) * parseFloat(item.rate_per_unit);
            return `
                <tr>
                    <td class="text-center">${index + 1}</td>
                    <td>${escapeHtml(item.product_name)}</td>
                    <td class="text-center">${escapeHtml(item.unit || '')}</td>
                    <td class="text-end">${formatNumber(item.quantity, 3)}</td>
                    ${isAdmin ? `
                    <td class="text-end">${formatRowCurrency(purchase, item.rate_per_unit)}</td>
                    <td class="text-end">${formatRowCurrency(purchase, amount)}</td>
                    ` : ''}
                </tr>
            `;
        }).join('');
    } else {
        itemsBody.innerHTML = '<tr><td colspan="' + (isAdmin ? '6' : '4') + '" class="text-center text-muted">' + LANG.no_items + '</td></tr>';
    }

    if (isAdmin) {
        // The SAVED purchase's own currency — this modal printed the tenant
        // default here too.
        document.getElementById('viewTotalAmount').textContent = formatRowCurrency(purchase, purchase.total_amount);

        // VAT + Payable rows: show only when VAT > 0
        const vatVal = parseFloat(purchase.vat_amount || 0);
        const vatRow = document.getElementById('viewVatRow');
        const payableRow = document.getElementById('viewPayableRow');
        if (vatVal > 0) {
            document.getElementById('viewVatAmount').textContent = formatRowCurrency(purchase, vatVal);
            document.getElementById('viewPayableAmount').textContent = formatRowCurrency(purchase, parseFloat(purchase.total_amount || 0) + vatVal);
            vatRow.style.display = '';
            payableRow.style.display = '';
        } else {
            vatRow.style.display = 'none';
            payableRow.style.display = 'none';
        }
    }

    // Created by
    document.getElementById('viewCreatedBy').textContent = purchase.created_by_name || 'Unknown';
    document.getElementById('viewCreatedAt').textContent = formatDateTime(purchase.created_at);
    
    // Edit button - only show for synced purchases and if user has permission
    const editBtn = document.getElementById('viewEditBtn');
    if (editBtn) {
        if (isSynced && hasEditPermission()) {
            editBtn.style.display = '';
            editBtn.disabled = !syncManager.isOnline;
            if (!syncManager.isOnline) {
                editBtn.title = LANG.edit_only_online;
            }
        } else {
            editBtn.style.display = 'none';
        }
    }
    
    // Show modal
    viewModal.show();
}

// Edit purchase (online only)
async function editPurchase(id) {
    if (!syncManager.isOnline) {
        showToast(LANG.editing_only_online, 'warning');
        return;
    }
    
    const purchase = purchases.find(p => p.id == id);
    if (!purchase) {
        showToast(LANG.purchase_not_found, 'danger');
        return;
    }
    
    isEditMode = true;
    editingPurchaseId = id;
    
    document.getElementById('modalTitle').textContent = LANG.edit_purchase;
    document.getElementById('purchaseId').value = id;
    document.getElementById('purchaseDate').value = purchase.purchase_date;
    document.getElementById('poNumber').value = purchase.po_number || '';
    document.getElementById('supplierId').value = purchase.supplier_id;
    document.getElementById('locationId').value = purchase.location_id;
    document.getElementById('costCentreId').value = purchase.cost_centre_id || '';
    document.getElementById('notes').value = purchase.notes || '';

    // Set currency
    const purchaseCurrencyId = purchase.currency_id || defaultCurrencyId;
    document.getElementById('currencyId').value = purchaseCurrencyId;
    const curOpt = document.getElementById('currencyId').options[document.getElementById('currencyId').selectedIndex];
    selectedCurrencySymbol = curOpt ? curOpt.dataset.symbol : 'TZS';
    if (parseInt(purchaseCurrencyId) !== defaultCurrencyId) {
        document.getElementById('exchangeRateGroup').style.display = '';
        document.getElementById('exchangeRateToBase').value = purchase.exchange_rate_to_base || '1';
    } else {
        document.getElementById('exchangeRateGroup').style.display = 'none';
        document.getElementById('exchangeRateToBase').value = '1';
    }

    // Load items
    itemsArray = purchase.details ? purchase.details.map(detail => ({
        product_id: detail.product_id,
        product_name: detail.product_name,
        unit: detail.unit,
        quantity: parseFloat(detail.quantity),
        rate_per_unit: parseFloat(detail.rate_per_unit)
    })) : [];

    // VAT
    const vatVal = parseFloat(purchase.vat_amount || 0);
    document.getElementById('modalVatAmount').value = vatVal.toFixed(2);
    resetVatControls();

    // Show the effective VAT percentage (VAT ÷ goods total) in the % box for reference
    const vatItemsTotal = getItemsTotal();
    const pctBox = document.getElementById('modalVatPct');
    if (pctBox && vatItemsTotal > 0 && vatVal > 0) {
        pctBox.value = Math.round(vatVal / vatItemsTotal * 10000) / 100;
    }

    updateItemsTable();
    purchaseModal.show();
}

// Download a single purchase's line items as an Excel-friendly CSV.
function exportPurchaseItems(id) {
    window.location.href = BASE_URL + 'modules/reports/purchase_items_export.php?id=' + encodeURIComponent(id);
}

// Delete purchase (online only)
async function deletePurchase(id) {
    if (!syncManager.isOnline) {
        showToast(LANG.delete_only_online, 'warning');
        return;
    }
    
    if (!confirm(LANG.confirm_delete_purchase)) {
        return;
    }
    
    try {
        await syncManager.deletePurchase(id);
        await loadPurchases();
        filterPurchases();
    } catch (error) {
        console.error('❌ Delete failed:', error);
        showToast(LANG.failed_delete_purchase + ': ' + error.message, 'danger');
    }
}

// Edit from view modal
function editFromView() {
    viewModal.hide();
    setTimeout(() => {
        editPurchase(currentViewPurchaseId);
    }, 300);
}

// Permission checks
function hasEditPermission() {
    return <?php echo hasPermission('purchases', 'edit') ? 'true' : 'false'; ?>;
}

function hasDeletePermission() {
    return <?php echo hasPermission('purchases', 'delete') ? 'true' : 'false'; ?>;
}

// Utility functions
//
// NOTE: there used to be a local formatCurrency(amount, symbol) here. It was
// already DEAD CODE — includes/footer.php loads assets/js/main.js AFTER this
// inline block, and main.js declares a global formatCurrency(amount, decimals,
// symbol) that overwrote it. Worse, the two signatures disagree in position 2
// (symbol vs decimals), so any 2-argument call would have silently passed a
// currency symbol where a decimal count was expected. Every call site here is
// single-argument, so nothing broke — but the trap is removed rather than left
// for the next person. main.js already accepts a symbol override; the call
// sites now pass one.

const CONVERTED_TO_BASE = <?php echo json_encode(__('converted_to_base')); ?>;

/**
 * The symbol to print for a LIST row, from the currency joined in
 * api/purchases.php.
 *
 * Falls back to the tenant default — the old behaviour — for rows cached in
 * IndexedDB BEFORE that join was added, and for offline rows created locally.
 * Those correct themselves on the next sync; until then they render as they
 * always did rather than blank.
 *
 * NOTE: this is for SAVED rows. The entry form uses selectedCurrencySymbol,
 * which is whatever the form's currency dropdown is showing.
 */
function rowCurrencySymbol(row) {
    return (row && row.currency_symbol) || window.DEFAULT_CURRENCY_SYMBOL || 'TZS';
}

function rowCurrencyDecimals(row) {
    if (row && row.currency_decimals !== undefined && row.currency_decimals !== null) {
        return parseInt(row.currency_decimals, 10);
    }
    return window.DEFAULT_CURRENCY_DECIMALS !== undefined ? window.DEFAULT_CURRENCY_DECIMALS : 2;
}

/** Is this row in a currency other than the tenant's base currency? */
function rowIsForeignCurrency(row) {
    if (!row || !row.currency_id || window.DEFAULT_CURRENCY_ID === undefined) return false;
    return Number(row.currency_id) !== Number(window.DEFAULT_CURRENCY_ID);
}

/** Row amount in the row's own currency. */
function formatRowCurrency(row, amount) {
    return formatCurrency(amount, rowCurrencyDecimals(row), rowCurrencySymbol(row));
}

/**
 * Amount in the ENTRY FORM's currently selected currency.
 *
 * This is what the removed local formatCurrency() was reaching for with its
 * `selectedCurrencySymbol` default — but because main.js overwrote it, the form
 * had been showing the tenant default while you typed a foreign-currency
 * purchase. selectedCurrencySymbol is maintained by the form's currency
 * dropdown (see onCurrencyChange / loadCurrencies).
 */
function formatEntryCurrency(amount) {
    return formatCurrency(amount,
                          window.DEFAULT_CURRENCY_DECIMALS,
                          selectedCurrencySymbol || window.DEFAULT_CURRENCY_SYMBOL || 'TZS');
}

/**
 * "USD @ 2,450.60 = TZS. 339,413,001.20" for the amount cell's tooltip, or ''
 * for a base-currency row. Kept out of the visible cell text to match the
 * payments/receipts lists.
 */
function rowFxTitle(row, amount) {
    if (!rowIsForeignCurrency(row)) return '';
    const rate = parseFloat(row.exchange_rate_to_base) || 1;
    const base = formatCurrency(parseFloat(amount || 0) * rate,
                                window.DEFAULT_CURRENCY_DECIMALS,
                                window.DEFAULT_CURRENCY_SYMBOL);
    return (row.currency_code || rowCurrencySymbol(row)) + ' @ ' + formatNumber(rate, 2) + ' = ' + base;
}

function formatNumber(num, decimals = 2) {
    const n = parseFloat(num);
    if (isNaN(n)) return '0.00';
    return n.toFixed(decimals).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
}

function formatDate(dateString) {
    if (!dateString) return '';
    const date = new Date(dateString);
    const day = String(date.getDate()).padStart(2, '0');
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const year = date.getFullYear();
    return `${day}-${month}-${year}`;
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function showToast(message, type = 'info') {
    document.querySelectorAll('.purchase-toast').forEach(t => t.remove());
    
    const toast = document.createElement('div');
    toast.className = `purchase-toast alert alert-${type} alert-dismissible fade show`;
    toast.style.cssText = `
        position: fixed;
        top: 80px;
        right: 20px;
        z-index: 9999;
        min-width: 300px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.2);
    `;
    toast.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 150);
    }, 5000);
}

function formatDateTime(dateTimeString) {
    if (!dateTimeString) return '';
    const date = new Date(dateTimeString);
    const day = String(date.getDate()).padStart(2, '0');
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const year = date.getFullYear();
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    return `${day}-${month}-${year} ${hours}:${minutes}`;
}
</script>

<?php require_once '../../includes/footer.php'; ?>
