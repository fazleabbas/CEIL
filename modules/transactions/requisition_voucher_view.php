<?php
/**
 * View Requisition Voucher - Status-dependent behavior
 * Status: draft | pending_approval | approved | closed
 */
require_once '../../config/database.php';
require_once '../../includes/functions.php';

$pageTitle = __('requisition_voucher_details');

if (!hasPermission('requisition_vouchers', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('');
}

$id = (int)($_GET['id'] ?? 0);

if (empty($id)) {
    setAlert(__('requisition_not_found'), 'danger');
    redirect('modules/transactions/requisition_voucher_list.php');
}

// Get requisition header with JOINs
$stmt = $conn->prepare("
    SELECT
        rv.*,
        l.name as destination_location_name,
        cc.name as cost_centre_name,
        cc.code as cost_centre_code,
        cu.name as created_by_name,
        au.name as approved_by_name,
        clu.name as closed_by_name,
        ru.name as rejected_by_name
    FROM requisition_vouchers rv
    LEFT JOIN locations l ON rv.destination_location_id = l.id
    LEFT JOIN cost_centres cc ON rv.cost_centre_id = cc.id
    LEFT JOIN users cu ON rv.created_by = cu.id
    LEFT JOIN users au ON rv.approved_by = au.id
    LEFT JOIN users clu ON rv.closed_by = clu.id
    LEFT JOIN users ru ON rv.rejected_by = ru.id
    WHERE rv.id = ?
");
$stmt->execute([$id]);
$voucher = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$voucher) {
    setAlert(__('requisition_not_found'), 'danger');
    redirect('modules/transactions/requisition_voucher_list.php');
}

// Get requisition details with product and unit info
$detailsStmt = $conn->prepare("
    SELECT
        rvd.*,
        p.name as product_name,
        p.unit as unit_name
    FROM requisition_voucher_details rvd
    JOIN products p ON rvd.product_id = p.id
    WHERE rvd.requisition_id = ?
    ORDER BY rvd.id
");
$detailsStmt->execute([$id]);
$details = $detailsStmt->fetchAll(PDO::FETCH_ASSOC);

// Optional Bill of Materials link (migration 0033). When linked, we show per
// line the BOM quantity / already-transferred / remaining balance, and flag
// lines whose product belongs to the BOM. Balances are computed server-side.
$bomLinked = !empty($voucher['bom_id']);
$bomInfo = null;
$bomBalances = [];
if ($bomLinked) {
    $bomStmt = $conn->prepare("SELECT id, bom_number FROM boms WHERE id = ?");
    $bomStmt->execute([(int)$voucher['bom_id']]);
    $bomInfo = $bomStmt->fetch(PDO::FETCH_ASSOC);
    if (!$bomInfo) {
        $bomLinked = false;
    } else {
        $bomBalances = getBomProductBalances((int)$voucher['bom_id'], $conn);
    }
}

// ---------------------------------------------------------------------------
// Mapping an EXISTING requisition to a BOM (see api map_bom).
//
// The create/edit form is draft-only, so before this everything past draft was
// permanently unmappable. Offered here for ANY status (user-confirmed), because
// the omission is usually noticed after approval — often after the material has
// already gone out.
// ---------------------------------------------------------------------------
$hasBomCol = (bool)$conn->query("
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'requisition_vouchers'
      AND COLUMN_NAME = 'bom_id'
")->fetchColumn();

// Either flag admits — same bar as the API enforces.
$canMapBom = $hasBomCol
    && (hasPermission('requisition_vouchers', 'edit') || hasPermission('requisition_vouchers', 'approve'));

$mapBomList = [];
if ($canMapBom) {
    // Same list the create/edit form offers: BOMs in the user's accessible
    // locations. The `title` column arrived in migration 0035, so guard it and
    // fall back to bom_number.
    $hasBomTitleCol = (bool)$conn->query("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'boms'
          AND COLUMN_NAME = 'title'
    ")->fetchColumn();
    $bomTitleCol = $hasBomTitleCol ? 'b.title AS title' : 'NULL AS title';

    $mapBomLocationIds = array_column(getUserLocations(), 'id');
    if (!empty($mapBomLocationIds)) {
        $ph = implode(',', array_fill(0, count($mapBomLocationIds), '?'));
        // Only APPROVED BOMs are offered (migration 0049); the linked one is
        // appended below whatever its status.
        $bomApprovedOnly = bomApprovalAvailable($conn) ? "AND b.status = 'approved'" : '';
        $listStmt = $conn->prepare("
            SELECT b.id, b.bom_number, $bomTitleCol, b.bom_date, l.name AS location_name
            FROM boms b
            LEFT JOIN locations l ON b.location_id = l.id
            WHERE b.location_id IN ($ph) $bomApprovedOnly
            ORDER BY b.id DESC
        ");
        $listStmt->execute($mapBomLocationIds);
        $mapBomList = $listStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // A BOM already linked but sitting outside the user's locations would be
    // absent from the list above, so the select would silently fall back to the
    // blank option and a save would UNLINK it. Append it so the current value is
    // always selectable. (The API still refuses to link an inaccessible BOM;
    // this only preserves one that is already linked.)
    if ($bomLinked && !in_array((int)$voucher['bom_id'], array_map('intval', array_column($mapBomList, 'id')), true)) {
        $extraStmt = $conn->prepare("
            SELECT b.id, b.bom_number, $bomTitleCol, b.bom_date, l.name AS location_name
            FROM boms b
            LEFT JOIN locations l ON b.location_id = l.id
            WHERE b.id = ?
        ");
        $extraStmt->execute([(int)$voucher['bom_id']]);
        if ($extraRow = $extraStmt->fetch(PDO::FETCH_ASSOC)) {
            array_unshift($mapBomList, $extraRow);
        }
    }
}

// Renders a BOM balance cell for a product line. $compareQty (effective/requested
// qty) drives the over-balance warning; pass null to skip the warning.
// $lineTransferred = this line's own already-transferred qty, which is added back
// to the balance so a line is never flagged against its own transfers (the
// balance is BOM-wide and already nets out this line). The effective threshold is
// therefore bom_qty minus transfers from OTHER requisitions.
$renderBomCell = function ($productId, $compareQty, $lineTransferred = 0) use ($bomBalances) {
    $entry = $bomBalances[$productId] ?? null;
    if (!$entry) {
        return '<span class="text-muted">—</span>';
    }
    $bal = $entry['balance'];
    $threshold = $bal + (float)$lineTransferred;
    $over = ($compareQty !== null) && ($compareQty > $threshold + 0.0005);
    $cls = $over ? 'text-danger fw-bold' : ($bal > 0 ? 'text-success' : 'text-muted');
    $title = htmlspecialchars(__('bom_qty') . ': ' . formatNumber($entry['bom_qty'], 3)
        . ' · ' . __('transferred_so_far') . ': ' . formatNumber($entry['transferred'], 3));
    $html = '<span class="' . $cls . '" title="' . $title . '">' . formatNumber($bal, 3);
    if ($over) {
        $html .= ' <i class="bi bi-exclamation-triangle-fill" title="' . htmlspecialchars(__('over_bom_balance')) . '"></i>';
    }
    $html .= '</span>';
    return $html;
};

// Get linked transfers (when status is approved or closed)
$transfers = [];
if (in_array($voucher['status'], ['approved', 'closed'])) {
    $transfersStmt = $conn->prepare("
        SELECT
            st.*,
            fl.name as from_location_name,
            tl.name as to_location_name
        FROM stock_transfers st
        LEFT JOIN locations fl ON st.from_location_id = fl.id
        LEFT JOIN locations tl ON st.to_location_id = tl.id
        WHERE st.requisition_id = ?
        ORDER BY st.created_at DESC
    ");
    $transfersStmt->execute([$id]);
    $transfers = $transfersStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get user locations for the transfer form (when status is approved)
$userLocations = [];
if ($voucher['status'] === 'approved') {
    $userLocations = getUserLocations();
}

// All procurement docs (purchases / purchase imports) created from this requisition.
// A requisition can spawn several; each click of the create button raises a new one.
// The list below stays visible for traceability; the create button itself is shown
// until the requisition is closed (see $canCreateProcurement). Only synced/server
// rows carry requisition_id, so an offline-pending purchase won't appear until it
// syncs — acceptable, the server is the source of truth.
$linkedPurchaseStmt = $conn->prepare("SELECT id, purchase_number FROM purchases WHERE requisition_id = ? ORDER BY id");
$linkedPurchaseStmt->execute([$id]);
$linkedPurchases = $linkedPurchaseStmt->fetchAll(PDO::FETCH_ASSOC);

$linkedImportStmt = $conn->prepare("SELECT id, import_number FROM purchase_imports WHERE requisition_id = ? ORDER BY id");
$linkedImportStmt->execute([$id]);
$linkedImports = $linkedImportStmt->fetchAll(PDO::FETCH_ASSOC);

$hasLinkedProcurement = !empty($linkedPurchases) || !empty($linkedImports);
$canCreatePurchase = hasPermission('purchases', 'create');
$canCreateImport = hasPermission('purchase_imports', 'create');

$status = $voucher['status'];
$totalItems = count($details);
$totalQuantityRequested = 0;
foreach ($details as $d) {
    $totalQuantityRequested += floatval($d['requested_quantity']);
}

// An open (approved) requisition can have its approved quantities revised by an
// approver. Closing locks them. Partial transfers / linked purchases don't block;
// the API additionally enforces the per-line floor (>= already transferred).
$canEditApproved = ($status === 'approved') && hasPermission('requisition_vouchers', 'approve');

// The destination location may be changed by an approver only while the requisition
// is open AND no stock transfer has been made yet — once anything is dispatched the
// destination is locked (dispatched stock already committed to the old destination).
$canChangeDestination = $canEditApproved && empty($transfers);

// FULL post-approval edit (header + line items) on the requisition form, for
// holders of requisition_vouchers.edit, while nothing has yet been raised against
// this requisition. Deliberately distinct from $canEditApproved above, which is the
// approver's quantity-only revision and stays exactly as it was.
$rvEditBlockers = ($status === 'approved') ? requisitionApprovedEditBlockers($id, $conn) : [];
$canFullEdit = requisitionCanEdit($voucher, $conn, ($status === 'approved') ? $rvEditBlockers : null);

// Amendments made after approval. Empty for every voucher that has never been
// edited post-approval, so the panel simply does not render.
$rvChangeLog = getRequisitionChangeLog($conn, $id);

/**
 * Render one entry of the post-approval change log as an <li>.
 *
 * Values were resolved to human-readable text when the log was written, so
 * nothing here re-queries a name that may since have changed.
 */
function rvRenderChange($ch) {
    $type  = isset($ch['type']) ? $ch['type'] : '';
    $label = isset($ch['label']) ? (string)$ch['label'] : '';
    $from  = isset($ch['from']) ? (string)$ch['from'] : '';
    $to    = isset($ch['to']) ? (string)$ch['to'] : '';

    $show = function ($v) {
        return htmlspecialchars(trim($v) === '' ? '—' : $v);
    };
    $wasNow = '<span class="text-danger">' . $show($from) . '</span>'
            . ' <i class="bi bi-arrow-right text-muted"></i> '
            . '<span class="text-success">' . $show($to) . '</span>';

    switch ($type) {
        case 'field':
            $key = isset($ch['key']) ? $ch['key'] : '';
            $names = [
                'voucher_date'         => __('voucher_date'),
                'destination_location' => __('destination_location'),
                'cost_centre'          => __('cost_centre'),
                'remarks'              => __('remarks'),
                'bom'                  => __('bom'),
            ];
            $name = isset($names[$key]) ? $names[$key] : $key;
            return '<li><strong>' . htmlspecialchars($name) . ':</strong> ' . $wasNow . '</li>';

        case 'item_qty':
            return '<li><i class="bi bi-box-seam"></i> <strong>' . htmlspecialchars($label) . '</strong> — '
                 . htmlspecialchars(__('quantity')) . ': ' . $wasNow . '</li>';

        case 'item_added':
            return '<li class="text-success"><i class="bi bi-plus-circle"></i> '
                 . htmlspecialchars(__('rv_change_item_added')) . ': <strong>'
                 . htmlspecialchars($label) . '</strong> (' . $show($to) . ')</li>';

        case 'item_removed':
            return '<li class="text-danger"><i class="bi bi-dash-circle"></i> '
                 . htmlspecialchars(__('rv_change_item_removed')) . ': <strong>'
                 . htmlspecialchars($label) . '</strong> (' . $show($from) . ')</li>';

        case 'item_replaced':
            return '<li><i class="bi bi-arrow-left-right"></i> '
                 . htmlspecialchars(__('rv_change_item_replaced')) . ': ' . $wasNow . '</li>';

        case 'item_remarks':
            return '<li><i class="bi bi-chat-left-text"></i> <strong>' . htmlspecialchars($label) . '</strong> — '
                 . htmlspecialchars(__('remarks')) . ': ' . $wasNow . '</li>';
    }
    return '';
}

require_once '../../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-12 col-lg-10">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center no-print">
                <h5 class="mb-0">
                    <i class="bi bi-clipboard-check"></i> <?php _e('requisition_voucher_details'); ?>
                </h5>
                <div>
                    <a href="requisition_voucher_list.php" class="btn btn-sm btn-secondary">
                        <i class="bi bi-arrow-left"></i> <?php _e('back'); ?>
                    </a>
                    <button onclick="window.print()" class="btn btn-sm btn-secondary">
                        <i class="bi bi-printer"></i> <?php _e('print'); ?>
                    </button>
                </div>
            </div>
            <div class="card-body">
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
                                <td><strong><?php _e('destination_location'); ?>:</strong></td>
                                <td><?php echo htmlspecialchars($voucher['destination_location_name']); ?></td>
                            </tr>
                            <?php if ($bomLinked || $canMapBom): ?>
                            <?php // Unlinked, the row holds nothing but a button — keep it off the printed voucher. ?>
                            <tr<?php echo $bomLinked ? '' : ' class="no-print"'; ?>>
                                <td><strong><?php _e('bom'); ?>:</strong></td>
                                <td>
                                    <?php if ($bomLinked): ?>
                                        <a href="bom_view.php?id=<?php echo (int)$bomInfo['id']; ?>" class="no-print">
                                            <i class="bi bi-diagram-3"></i> <?php echo htmlspecialchars($bomInfo['bom_number']); ?>
                                        </a>
                                        <span class="d-none d-print-inline"><?php echo htmlspecialchars($bomInfo['bom_number']); ?></span>
                                        <?php if ($canMapBom): ?>
                                        <button type="button" class="btn btn-link btn-sm p-0 ms-2 align-baseline no-print"
                                                data-bs-toggle="modal" data-bs-target="#mapBomModal"
                                                title="<?php echo htmlspecialchars(__('change_bom')); ?>">
                                            <i class="bi bi-pencil-square"></i> <?php _e('change_bom'); ?>
                                        </button>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-outline-primary btn-sm no-print"
                                                data-bs-toggle="modal" data-bs-target="#mapBomModal">
                                            <i class="bi bi-diagram-3"></i> <?php _e('map_bom'); ?>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($voucher['cost_centre_name']): ?>
                            <tr>
                                <td><strong><?php _e('cost_centre_label'); ?></strong></td>
                                <td><?php echo htmlspecialchars($voucher['cost_centre_code'] . ' - ' . $voucher['cost_centre_name']); ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <td><strong><?php _e('status'); ?>:</strong></td>
                                <td>
                                    <?php
                                    $badgeClass = 'secondary';
                                    if ($status === 'draft') $badgeClass = 'secondary';
                                    elseif ($status === 'pending_approval') $badgeClass = 'warning';
                                    elseif ($status === 'approved') $badgeClass = 'success';
                                    elseif ($status === 'rejected') $badgeClass = 'danger';
                                    elseif ($status === 'closed') $badgeClass = 'dark';
                                    ?>
                                    <span class="badge bg-<?php echo $badgeClass; ?>">
                                        <?php _e('status_' . $status); ?>
                                    </span>
                                </td>
                            </tr>
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
                            <?php if ($voucher['approved_by']): ?>
                            <tr>
                                <td><strong><?php _e('approved_by_label'); ?></strong></td>
                                <td><?php echo htmlspecialchars($voucher['approved_by_name']); ?></td>
                            </tr>
                            <tr>
                                <td><strong><?php _e('approved_at_label'); ?></strong></td>
                                <td><?php echo formatDateTime($voucher['approved_at']); ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($voucher['closed_by']): ?>
                            <tr>
                                <td><strong><?php _e('closed_by_label'); ?></strong></td>
                                <td><?php echo htmlspecialchars($voucher['closed_by_name']); ?></td>
                            </tr>
                            <tr>
                                <td><strong><?php _e('closed_at_label'); ?></strong></td>
                                <td><?php echo formatDateTime($voucher['closed_at']); ?></td>
                            </tr>
                            <?php if ($voucher['close_reason']): ?>
                            <tr>
                                <td><strong><?php _e('close_reason'); ?>:</strong></td>
                                <td><?php echo htmlspecialchars($voucher['close_reason']); ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php endif; ?>
                            <?php if (!empty($voucher['rejected_by'])): ?>
                            <tr>
                                <td><strong><?php _e('rejected_by_label'); ?></strong></td>
                                <td><?php echo htmlspecialchars($voucher['rejected_by_name']); ?></td>
                            </tr>
                            <tr>
                                <td><strong><?php _e('rejected_at_label'); ?></strong></td>
                                <td><?php echo formatDateTime($voucher['rejected_at']); ?></td>
                            </tr>
                            <?php if (!empty($voucher['reject_reason'])): ?>
                            <tr>
                                <td><strong><?php _e('reject_reason'); ?>:</strong></td>
                                <td><?php echo htmlspecialchars($voucher['reject_reason']); ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php endif; ?>
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
                                <small class="text-muted"><?php _e('requested_items'); ?></small>
                                <h4 class="mb-0 text-primary"><?php echo $totalItems; ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 col-6">
                        <div class="card bg-light">
                            <div class="card-body text-center py-2">
                                <small class="text-muted"><?php _e('requested_quantity'); ?></small>
                                <h4 class="mb-0 text-primary"><?php echo formatNumber($totalQuantityRequested, 3); ?></h4>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Items Table -->
                <?php
                // Column count of the items table (drives the breakdown row colspan).
                // Base: sr, product, unit, available, requested.
                $mainColCount = 5;
                if ($status === 'approved' || $status === 'closed') $mainColCount++;       // approved qty
                if ($status === 'approved') $mainColCount += 3;                             // transferred, remaining, transferring
                elseif ($status === 'closed') $mainColCount += 1;                           // transferred
                if ($status === 'draft' || $status === 'pending_approval') $mainColCount++; // remarks
                if ($bomLinked) $mainColCount++;                                            // BOM balance
                ?>
                <h6 class="text-muted mb-3"><?php _e('requested_items'); ?></h6>
                <div class="table-responsive">
                    <table class="table table-bordered table-sm">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 5%;"><?php _e('sr'); ?></th>
                                <th style="width: <?php echo ($status === 'approved') ? '18%' : '30%'; ?>;"><?php _e('product'); ?></th>
                                <th style="width: 8%;"><?php _e('unit'); ?></th>
                                <th style="width: 11%;" class="text-end rv-main-available-head">
                                    <span class="d-print-none">Available</span>
                                    <span class="d-none d-print-inline"><?php _e('rv_available_at_print_time'); ?></span>
                                </th>
                                <th style="width: 11%;" class="text-end"><?php _e('requested_quantity'); ?></th>
                                <?php if ($status === 'approved' || $status === 'closed'): ?>
                                <th style="width: 11%;" class="text-end">Approved Quantity</th>
                                <?php endif; ?>
                                <?php if ($status === 'approved'): ?>
                                <th style="width: 11%;" class="text-end"><?php _e('transferred_so_far'); ?></th>
                                <th style="width: 10%;" class="text-end"><?php _e('remaining_quantity'); ?></th>
                                <th style="width: 13%;" class="text-end"><?php _e('transferring_now'); ?></th>
                                <?php elseif ($status === 'closed'): ?>
                                <th style="width: 12%;" class="text-end"><?php _e('transferred_so_far'); ?></th>
                                <?php endif; ?>
                                <?php if ($status === 'draft' || $status === 'pending_approval'): ?>
                                <th style="width: 20%;"><?php _e('remarks'); ?></th>
                                <?php endif; ?>
                                <?php if ($bomLinked): ?>
                                <th style="width: 12%;" class="text-end"><?php _e('bom_balance'); ?></th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $rowNum = 0;
                            foreach ($details as $detail):
                                $rowNum++;
                                $requested = floatval($detail['requested_quantity']);
                                // Effective cap for dispatch: approved if set, else requested (legacy rows)
                                $approvedRaw = isset($detail['approved_quantity']) ? $detail['approved_quantity'] : null;
                                $effective = ($approvedRaw !== null) ? floatval($approvedRaw) : $requested;
                                $transferred = floatval($detail['transferred_quantity']);
                                $remaining = $effective - $transferred;
                                if ($remaining < 0) $remaining = 0;
                                $isBomLine = $bomLinked && isset($bomBalances[(int)$detail['product_id']]);
                            ?>
                            <tr<?php echo $isBomLine ? ' class="bom-line"' : ''; ?>>
                                <td><?php echo $rowNum; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($detail['product_name']); ?></strong>
                                    <?php if ($detail['remarks'] && ($status === 'approved' || $status === 'closed')): ?>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($detail['remarks']); ?></small>
                                    <?php endif; ?>
                                    <?php if (!empty($detail['approval_remarks']) && ($status === 'approved' || $status === 'closed')): ?>
                                    <br><small class="text-info"><i class="bi bi-chat-left-text"></i> <?php _e('approval_remarks'); ?>: <?php echo htmlspecialchars($detail['approval_remarks']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($detail['unit_name'] ?? '-'); ?></td>
                                <td class="text-end rv-main-available-cell text-muted" data-product-id="<?php echo (int)$detail['product_id']; ?>">
                                    <span class="text-muted">…</span>
                                </td>
                                <td class="text-end"><?php echo formatNumber($requested, 3); ?></td>
                                <?php if ($status === 'approved' || $status === 'closed'): ?>
                                <td class="text-end">
                                    <?php
                                    echo formatNumber($effective, 3);
                                    if ($approvedRaw !== null && abs($effective - $requested) > 0.0001) {
                                        if ($effective > $requested) {
                                            echo ' <small class="text-success">(increased)</small>';
                                        } else {
                                            echo ' <small class="text-danger">(reduced)</small>';
                                        }
                                    }
                                    ?>
                                </td>
                                <?php endif; ?>
                                <?php if ($status === 'approved'): ?>
                                <td class="text-end"><?php echo formatNumber($transferred, 3); ?></td>
                                <td class="text-end">
                                    <?php if ($remaining > 0): ?>
                                    <span class="text-warning"><?php echo formatNumber($remaining, 3); ?></span>
                                    <?php else: ?>
                                    <span class="text-success">0</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <input type="number"
                                           class="form-control form-control-sm text-end transferring-now-input"
                                           name="transferring_now[<?php echo $detail['id']; ?>]"
                                           data-detail-id="<?php echo $detail['id']; ?>"
                                           data-product-id="<?php echo $detail['product_id']; ?>"
                                           value="<?php echo ($remaining > 0) ? formatNumber($remaining, 3) : '0'; ?>"
                                           max="<?php echo $remaining; ?>"
                                           min="0"
                                           step="0.001"
                                           <?php echo ($remaining <= 0) ? 'disabled' : ''; ?>
                                    >
                                </td>
                                <?php elseif ($status === 'closed'): ?>
                                <td class="text-end"><?php echo formatNumber($transferred, 3); ?></td>
                                <?php endif; ?>
                                <?php if ($status === 'draft' || $status === 'pending_approval'): ?>
                                <td><?php echo htmlspecialchars($detail['remarks'] ?? '-'); ?></td>
                                <?php endif; ?>
                                <?php if ($bomLinked): ?>
                                <td class="text-end"><?php echo $renderBomCell((int)$detail['product_id'], $effective, $transferred); ?></td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($bomLinked): ?>
                <style>
                    table tr.bom-line > td { background-color: #fff8e1; }
                </style>
                <div class="small text-muted mb-2">
                    <i class="bi bi-info-circle"></i> <?php _e('bom_balance_legend'); ?>
                </div>
                <?php endif; ?>

                <!-- ========================================= -->
                <!-- PROCUREMENT: create a Budget from this     -->
                <!-- approved requisition. The Budget allocates -->
                <!-- items to suppliers; purchases/imports are   -->
                <!-- then raised from the approved Budget.        -->
                <!-- ========================================= -->
                <?php if ($status === 'approved' && hasPermission('budget', 'create')): ?>
                <div class="mt-4 no-print">
                    <a class="btn btn-outline-primary" href="budget.php?from_requisition=<?php echo $id; ?>">
                        <i class="bi bi-cash-coin"></i> <?php _e('create_budget'); ?>
                    </a>
                    <small class="text-muted d-block mt-1">
                        <i class="bi bi-info-circle"></i>
                        <?php _e('budget_create_hint'); ?>
                    </small>
                </div>
                <?php endif; ?>

                <?php if ($hasLinkedProcurement): ?>
                <div class="alert alert-success mt-3 no-print">
                    <div class="mb-1">
                        <i class="bi bi-bag-check"></i>
                        <strong><?php _e('procurement_created_from_requisition', 'Purchases/imports created from this requisition'); ?>:</strong>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($linkedPurchases as $lp): ?>
                        <a href="purchases_view.php?id=<?php echo (int)$lp['id']; ?>" class="badge bg-light text-dark border text-decoration-none">
                            <i class="bi bi-cart"></i> <?php echo htmlspecialchars($lp['purchase_number']); ?>
                        </a>
                        <?php endforeach; ?>
                        <?php foreach ($linkedImports as $li): ?>
                        <a href="purchase_imports.php?view=<?php echo (int)$li['id']; ?>" class="badge bg-light text-dark border text-decoration-none">
                            <i class="bi bi-globe2"></i> <?php echo htmlspecialchars($li['import_number']); ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- ========================================= -->
                <!-- STATUS-SPECIFIC SECTIONS                  -->
                <!-- ========================================= -->

                <?php if ($status === 'draft'): ?>
                <!-- DRAFT: Action buttons -->
                <div class="mt-4 no-print">
                    <?php if (hasPermission('requisition_vouchers', 'create')): ?>
                    <button type="button" class="btn btn-warning" onclick="sendForApproval(<?php echo $id; ?>)">
                        <i class="bi bi-send"></i> <?php _e('send_for_approval'); ?>
                    </button>
                    <?php endif; ?>
                    <?php if (hasPermission('requisition_vouchers', 'edit')): ?>
                    <a href="requisition_voucher.php?id=<?php echo $id; ?>" class="btn btn-primary">
                        <i class="bi bi-pencil"></i> <?php _e('edit'); ?>
                    </a>
                    <?php endif; ?>
                    <?php if (hasPermission('requisition_vouchers', 'delete')): ?>
                    <button type="button" class="btn btn-danger" onclick="deleteRequisition(<?php echo $id; ?>)">
                        <i class="bi bi-trash"></i> <?php _e('delete'); ?>
                    </button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($status === 'pending_approval'): ?>
                <!-- PENDING APPROVAL: Approve button or waiting message -->
                <div class="mt-4 no-print">
                    <?php if (hasPermission('requisition_vouchers', 'approve')): ?>
                    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#approveRequisitionModal">
                        <i class="bi bi-check-circle"></i> <?php _e('approve'); ?>
                    </button>
                    <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#rejectRequisitionModal">
                        <i class="bi bi-x-circle"></i> <?php _e('reject'); ?>
                    </button>
                    <?php else: ?>
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-hourglass-split"></i> <?php _e('status_pending_approval'); ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($status === 'approved' && hasPermission('requisition_vouchers', 'edit')): ?>
                <!-- APPROVED: full edit, until something is raised against this RV -->
                <div class="mt-4 no-print">
                    <?php if ($canFullEdit): ?>
                    <a href="requisition_voucher.php?id=<?php echo $id; ?>" class="btn btn-primary">
                        <i class="bi bi-pencil"></i> <?php _e('edit'); ?>
                    </a>
                    <small class="text-muted d-block mt-1">
                        <i class="bi bi-info-circle"></i> <?php _e('rv_edit_approved_hint'); ?>
                    </small>
                    <?php else: ?>
                    <div class="alert alert-secondary mb-0 py-2 px-3 small">
                        <i class="bi bi-lock"></i> <?php _e('rv_edit_locked_hint'); ?>
                        <?php if (!empty($rvEditBlockers)): ?>
                        <span>
                            <?php foreach ($rvEditBlockers as $bIndex => $blocker): ?><?php echo $bIndex ? ', ' : ' '; ?><strong><?php echo htmlspecialchars($blocker['label']); ?></strong><?php endforeach; ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($canEditApproved): ?>
                <!-- APPROVED & OPEN: revise approved quantities (locks once closed) -->
                <div class="mt-4 no-print">
                    <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#reviseApprovalModal">
                        <i class="bi bi-pencil-square"></i> <?php _e('edit_approved_quantities', 'Edit Approved Quantities'); ?>
                    </button>
                    <small class="text-muted d-block mt-1">
                        <i class="bi bi-info-circle"></i>
                        <?php _e('edit_approved_quantities_hint', 'You can adjust approved quantities while this requisition is open. A line cannot go below what is already transferred.'); ?>
                    </small>
                    <?php if ($canChangeDestination): ?>
                    <div class="mt-3">
                        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#changeDestinationModal">
                            <i class="bi bi-geo-alt"></i> <?php _e('change_destination', 'Change Destination'); ?>
                        </button>
                        <small class="text-muted d-block mt-1">
                            <i class="bi bi-info-circle"></i>
                            <?php _e('change_destination_hint', 'You can change the destination location until the first transfer is made. This does not require re-approval.'); ?>
                        </small>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($status === 'approved'): ?>
                <!-- APPROVED: Transfer form -->
                <div class="card mt-4 no-print">
                    <div class="card-body">
                        <h6 class="text-muted mb-3"><i class="bi bi-truck"></i> <?php _e('make_transfer'); ?></h6>
                        <div class="row align-items-end">
                            <div class="col-md-6">
                                <label class="form-label"><strong><?php _e('source_location'); ?></strong></label>
                                <select id="source_location_id" class="form-select" required>
                                    <option value=""><?php _e('select_source_location'); ?></option>
                                    <?php foreach ($userLocations as $loc): ?>
                                        <?php if ($loc['id'] != $voucher['destination_location_id']): ?>
                                        <option value="<?php echo $loc['id']; ?>"><?php echo htmlspecialchars($loc['name']); ?></option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mt-2 mt-md-0">
                                <button type="button" class="btn btn-primary" onclick="makeTransfer(<?php echo $id; ?>)">
                                    <i class="bi bi-truck"></i> <?php _e('make_transfer'); ?>
                                </button>
                                <button type="button" class="btn btn-dark ms-2" onclick="showCloseModal()">
                                    <i class="bi bi-lock"></i> <?php _e('close_requisition'); ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (in_array($status, ['approved', 'closed'])): ?>
                <!-- TRANSFER HISTORY -->
                <div class="mt-4">
                    <h6 class="text-muted mb-3"><i class="bi bi-clock-history"></i> <?php _e('transfer_history'); ?></h6>
                    <?php if (empty($transfers)): ?>
                    <div class="alert alert-light text-center">
                        <i class="bi bi-inbox"></i> <?php _e('no_transfers_yet'); ?>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm">
                            <thead class="table-light">
                                <tr>
                                    <th><?php _e('transfer_number'); ?></th>
                                    <th><?php _e('date_label'); ?></th>
                                    <th><?php _e('from_location'); ?></th>
                                    <th><?php _e('to_location'); ?></th>
                                    <th><?php _e('status'); ?></th>
                                    <th><?php _e('items'); ?></th>
                                    <th class="no-print"><?php _e('actions'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transfers as $transfer):
                                    // Count items in this transfer
                                    $tItemsStmt = $conn->prepare("SELECT COUNT(*) as cnt FROM stock_transfer_details WHERE transfer_id = ?");
                                    $tItemsStmt->execute([$transfer['id']]);
                                    $tItemCount = $tItemsStmt->fetch()['cnt'];

                                    $tBadge = 'secondary';
                                    if ($transfer['status'] === 'draft') $tBadge = 'secondary';
                                    elseif ($transfer['status'] === 'in_transit') $tBadge = 'warning';
                                    elseif ($transfer['status'] === 'received') $tBadge = 'success';
                                    elseif ($transfer['status'] === 'cancelled') $tBadge = 'danger';
                                ?>
                                <tr>
                                    <td>
                                        <a href="transfers_view.php?id=<?php echo $transfer['id']; ?>">
                                            <?php echo htmlspecialchars($transfer['transfer_number']); ?>
                                        </a>
                                    </td>
                                    <td><?php echo formatDate($transfer['transfer_date']); ?></td>
                                    <td><?php echo htmlspecialchars($transfer['from_location_name']); ?></td>
                                    <td><?php echo htmlspecialchars($transfer['to_location_name']); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $tBadge; ?>">
                                            <?php _e('status_' . $transfer['status']); ?>
                                        </span>
                                    </td>
                                    <td class="text-center"><?php echo $tItemCount; ?></td>
                                    <td class="no-print">
                                        <a href="transfers_view.php?id=<?php echo $transfer['id']; ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-eye"></i> <?php _e('transfer_link'); ?>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($rvChangeLog)): ?>
                <!-- ========================================= -->
                <!-- CHANGE HISTORY: amendments made AFTER      -->
                <!-- approval — what changed, by whom, when.    -->
                <!-- no-print: the printed voucher and the      -->
                <!-- delivery notes stay exactly as they were.  -->
                <!-- ========================================= -->
                <div class="mt-4 no-print">
                    <h6 class="text-muted mb-3">
                        <i class="bi bi-clock-history"></i> <?php _e('rv_change_history'); ?>
                    </h6>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 18%;"><?php _e('date'); ?></th>
                                    <th style="width: 22%;"><?php _e('edited_by'); ?></th>
                                    <th><?php _e('rv_what_changed'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rvChangeLog as $logEntry): ?>
                                <tr>
                                    <td class="small"><?php echo formatDateTime($logEntry['created_at']); ?></td>
                                    <td class="small"><?php echo htmlspecialchars($logEntry['user_name'] ?: '-'); ?></td>
                                    <td>
                                        <ul class="mb-0 ps-3 small">
                                            <?php foreach ($logEntry['changes'] as $logChange): ?>
                                            <?php echo rvRenderChange($logChange); ?>
                                            <?php endforeach; ?>
                                        </ul>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Footer / Signatures (hide empty boxes) -->
                <?php
                $rvHasPreparedSig = !empty($voucher['prepared_by_signature']);
                $rvHasApprovedSig = !empty($voucher['approved_by_signature']);
                ?>
                <?php if ($rvHasPreparedSig || $rvHasApprovedSig): ?>
                <style>
                    .rv-sig-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 40px; padding-top: 16px; border-top: 1px solid #ddd; }
                    .rv-sig-box { text-align: center; padding: 10px 6px; }
                    .rv-sig-box img { max-height: 80px; max-width: 100%; display: block; margin: 0 auto 4px auto; }
                    .rv-sig-box .rv-sig-role { font-weight: 600; border-top: 1px solid #333; padding-top: 6px; }
                    .rv-sig-box .rv-sig-name { font-size: 13px; }
                    .rv-sig-box .rv-sig-date { font-size: 11px; color: #666; }
                </style>
                <div class="rv-sig-grid">
                    <?php if ($rvHasPreparedSig): ?>
                    <div class="rv-sig-box">
                        <img src="<?php echo htmlspecialchars($voucher['prepared_by_signature']); ?>" alt="Prepared By Signature">
                        <div class="rv-sig-role"><?php _e('prepared_by'); ?></div>
                        <div class="rv-sig-name"><?php echo htmlspecialchars($voucher['created_by_name'] ?: '-'); ?></div>
                        <div class="rv-sig-date"><?php echo formatDateTime($voucher['created_at']); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($rvHasApprovedSig): ?>
                    <div class="rv-sig-box">
                        <img src="<?php echo htmlspecialchars($voucher['approved_by_signature']); ?>" alt="Approved By Signature">
                        <div class="rv-sig-role"><?php _e('approved_by'); ?></div>
                        <div class="rv-sig-name"><?php echo htmlspecialchars($voucher['approved_by_name'] ?: '-'); ?></div>
                        <div class="rv-sig-date"><?php echo $voucher['approved_at'] ? formatDateTime($voucher['approved_at']) : ''; ?></div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($status === 'pending_approval' && hasPermission('requisition_vouchers', 'approve')): ?>
<!-- Approve Requisition Modal -->
<div class="modal fade" id="approveRequisitionModal" tabindex="-1" aria-labelledby="approveRequisitionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="approveRequisitionModalLabel">
                    <i class="bi bi-check-circle"></i> <?php _e('approve'); ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-2">Review each line below. You may increase or reduce the approved quantity, and add a remark per line. Type 0 to reject a line.</p>
                <?php if ($bomLinked): ?>
                <p class="small text-muted mb-2">
                    <i class="bi bi-info-circle"></i> <?php _e('bom_balance_legend'); ?>
                </p>
                <?php endif; ?>

                <div class="table-responsive mb-3">
                    <table class="table table-sm table-bordered align-middle">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 5%;"><?php _e('sr'); ?></th>
                                <th><?php _e('product'); ?></th>
                                <th style="width: 9%;"><?php _e('unit'); ?></th>
                                <th style="width: 12%;" class="text-end"><?php _e('requested_quantity'); ?></th>
                                <th style="width: 11%;" class="text-end">Available</th>
                                <?php if ($bomLinked): ?>
                                <th style="width: 11%;" class="text-end"><?php _e('bom_balance'); ?></th>
                                <?php endif; ?>
                                <th style="width: 14%;" class="text-end">Approved Quantity</th>
                                <th style="width: 22%;"><?php _e('approval_remarks'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $modalRowNum = 0;
                            foreach ($details as $detail):
                                $modalRowNum++;
                                $modalRequested = floatval($detail['requested_quantity']);
                                // Pre-fill input with existing approved_quantity if any (e.g. re-approving a draft);
                                // otherwise default to requested.
                                $modalApproved = isset($detail['approved_quantity']) && $detail['approved_quantity'] !== null
                                    ? floatval($detail['approved_quantity'])
                                    : $modalRequested;
                                $modalApprovalRemarks = $detail['approval_remarks'] ?? '';
                                $modalIsBomLine = $bomLinked && isset($bomBalances[(int)$detail['product_id']]);
                            ?>
                            <tr<?php echo $modalIsBomLine ? ' class="bom-line"' : ''; ?>>
                                <td><?php echo $modalRowNum; ?></td>
                                <td><?php echo htmlspecialchars($detail['product_name']); ?></td>
                                <td><?php echo htmlspecialchars($detail['unit_name'] ?? '-'); ?></td>
                                <td class="text-end"><?php echo formatNumber($modalRequested, 3); ?></td>
                                <td class="text-end approve-available-cell text-muted" data-product-id="<?php echo (int)$detail['product_id']; ?>">
                                    <span class="text-muted">…</span>
                                </td>
                                <?php if ($bomLinked): ?>
                                <td class="text-end"><?php echo $renderBomCell((int)$detail['product_id'], $modalRequested); ?></td>
                                <?php endif; ?>
                                <td class="text-end">
                                    <input type="number"
                                           class="form-control form-control-sm text-end approved-qty-input"
                                           data-detail-id="<?php echo (int)$detail['id']; ?>"
                                           data-requested="<?php echo $modalRequested; ?>"
                                           value="<?php echo $modalApproved; ?>"
                                           min="0"
                                           step="0.001">
                                </td>
                                <td>
                                    <input type="text"
                                           class="form-control form-control-sm approval-remarks-input"
                                           data-detail-id="<?php echo (int)$detail['id']; ?>"
                                           value="<?php echo htmlspecialchars($modalApprovalRemarks); ?>"
                                           maxlength="500"
                                           placeholder="<?php _e('approval_remarks'); ?>">
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <label class="form-label fw-bold">Signature <small class="text-muted">(optional)</small></label>
                <?php $myMasterSig = getUserSignature($conn, getUserId()); ?>
                <?php if ($myMasterSig): ?>
                <div class="border rounded p-2 bg-light">
                    <img src="<?php echo htmlspecialchars($myMasterSig); ?>" alt="<?php _e('signature'); ?>" style="max-height: 90px; max-width: 100%;">
                    <div class="text-muted small mt-1"><i class="bi bi-info-circle"></i> <?php _e('signature_from_profile'); ?></div>
                </div>
                <?php else: ?>
                <div style="border: 1px dashed #adb5bd; border-radius: 4px; background: #fafafa; position: relative;">
                    <canvas id="approveSignatureCanvas" style="display:block; width:100%; height:160px; touch-action:none; cursor:crosshair;"></canvas>
                    <span id="approveSignaturePlaceholder" style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); color:#adb5bd; font-style:italic; pointer-events:none; user-select:none; font-size:13px;">Sign here</span>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary mt-1" onclick="clearApproveSig()">
                    <i class="bi bi-x-circle"></i> <?php _e('clear'); ?>
                </button>
                <?php
                /* Not everyone has a touchscreen. Offer an image upload as an alternative
                   to drawing; it is loaded INTO the pad above, so the submit code below is
                   unchanged. Only reachable in this else-branch, i.e. only for signers with
                   no signature on file. */
                $sigUploadCanvasId      = 'approveSignatureCanvas';
                $sigUploadPlaceholderId = 'approveSignaturePlaceholder';
                require __DIR__ . '/../../includes/signature_upload_control.php';
                ?>
                <?php endif; ?>
                <div id="approveModalError" class="alert alert-danger mt-2" style="display:none;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                <button type="button" class="btn btn-success" id="approveSubmitBtn" onclick="submitApprove(<?php echo $id; ?>)">
                    <i class="bi bi-check-circle"></i> <?php _e('approve'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Reject Requisition Modal -->
<div class="modal fade" id="rejectRequisitionModal" tabindex="-1" aria-labelledby="rejectRequisitionModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="rejectRequisitionModalLabel">
                    <i class="bi bi-x-circle text-danger"></i> <?php _e('reject'); ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p><?php _e('confirm_reject_requisition'); ?></p>
                <div class="mb-2">
                    <label for="reject_reason" class="form-label"><?php _e('reject_reason'); ?> <small class="text-muted">(<?php _e('optional'); ?>)</small></label>
                    <textarea id="reject_reason" class="form-control" rows="3"></textarea>
                </div>
                <div id="rejectModalError" class="alert alert-danger mt-2" style="display:none;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                <button type="button" class="btn btn-danger" id="rejectSubmitBtn" onclick="submitReject(<?php echo $id; ?>)">
                    <i class="bi bi-x-circle"></i> <?php _e('reject'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<?php if ($status === 'approved'): ?>
<!-- Close Requisition Modal -->
<div class="modal fade" id="closeRequisitionModal" tabindex="-1" aria-labelledby="closeRequisitionModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="closeRequisitionModalLabel">
                    <i class="bi bi-lock"></i> <?php _e('close_requisition'); ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p><?php _e('confirm_close_requisition'); ?></p>
                <div class="mb-3">
                    <label for="close_reason" class="form-label"><?php _e('close_reason'); ?></label>
                    <textarea id="close_reason" class="form-control" rows="3"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                <button type="button" class="btn btn-dark" onclick="closeRequisition(<?php echo $id; ?>)">
                    <i class="bi bi-lock"></i> <?php _e('close_requisition'); ?>
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($canEditApproved): ?>
<!-- Revise Approved Quantities Modal (open/approved requisitions only) -->
<div class="modal fade" id="reviseApprovalModal" tabindex="-1" aria-labelledby="reviseApprovalModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="reviseApprovalModalLabel">
                    <i class="bi bi-pencil-square"></i> <?php _e('edit_approved_quantities', 'Edit Approved Quantities'); ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-2">
                    <?php _e('edit_approved_quantities_modal_hint', 'Adjust each line below. A line cannot go below the quantity already transferred. Type 0 to reject a line that has not been transferred.'); ?>
                </p>
                <?php if ($bomLinked): ?>
                <p class="small text-muted mb-2">
                    <i class="bi bi-info-circle"></i> <?php _e('bom_balance_legend'); ?>
                </p>
                <?php endif; ?>

                <div class="table-responsive mb-2">
                    <table class="table table-sm table-bordered align-middle">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 5%;"><?php _e('sr'); ?></th>
                                <th><?php _e('product'); ?></th>
                                <th style="width: 9%;"><?php _e('unit'); ?></th>
                                <th style="width: 11%;" class="text-end"><?php _e('requested_quantity'); ?></th>
                                <th style="width: 11%;" class="text-end"><?php _e('transferred_so_far'); ?></th>
                                <th style="width: 11%;" class="text-end">Available</th>
                                <?php if ($bomLinked): ?>
                                <th style="width: 11%;" class="text-end"><?php _e('bom_balance'); ?></th>
                                <?php endif; ?>
                                <th style="width: 13%;" class="text-end">Approved Quantity</th>
                                <th style="width: 20%;"><?php _e('approval_remarks'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $reviseRowNum = 0;
                            foreach ($details as $detail):
                                $reviseRowNum++;
                                $rRequested = floatval($detail['requested_quantity']);
                                $rTransferred = floatval($detail['transferred_quantity']);
                                $rApproved = (isset($detail['approved_quantity']) && $detail['approved_quantity'] !== null)
                                    ? floatval($detail['approved_quantity'])
                                    : $rRequested;
                                $rRemarks = $detail['approval_remarks'] ?? '';
                                $rIsBomLine = $bomLinked && isset($bomBalances[(int)$detail['product_id']]);
                            ?>
                            <tr<?php echo $rIsBomLine ? ' class="bom-line"' : ''; ?>>
                                <td><?php echo $reviseRowNum; ?></td>
                                <td><?php echo htmlspecialchars($detail['product_name']); ?></td>
                                <td><?php echo htmlspecialchars($detail['unit_name'] ?? '-'); ?></td>
                                <td class="text-end"><?php echo formatNumber($rRequested, 3); ?></td>
                                <td class="text-end"><?php echo formatNumber($rTransferred, 3); ?></td>
                                <td class="text-end revise-available-cell text-muted" data-product-id="<?php echo (int)$detail['product_id']; ?>">
                                    <span class="text-muted">…</span>
                                </td>
                                <?php if ($bomLinked): ?>
                                <td class="text-end"><?php echo $renderBomCell((int)$detail['product_id'], $rApproved, $rTransferred); ?></td>
                                <?php endif; ?>
                                <td class="text-end">
                                    <input type="number"
                                           class="form-control form-control-sm text-end revise-qty-input"
                                           data-detail-id="<?php echo (int)$detail['id']; ?>"
                                           data-requested="<?php echo $rRequested; ?>"
                                           data-transferred="<?php echo $rTransferred; ?>"
                                           value="<?php echo $rApproved; ?>"
                                           min="<?php echo $rTransferred; ?>"
                                           step="0.001">
                                </td>
                                <td>
                                    <input type="text"
                                           class="form-control form-control-sm revise-remarks-input"
                                           data-detail-id="<?php echo (int)$detail['id']; ?>"
                                           value="<?php echo htmlspecialchars($rRemarks); ?>"
                                           maxlength="500"
                                           placeholder="<?php _e('approval_remarks'); ?>">
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div id="reviseModalError" class="alert alert-danger mt-2" style="display:none;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                <button type="button" class="btn btn-primary" id="reviseSubmitBtn" onclick="submitRevise(<?php echo $id; ?>)">
                    <i class="bi bi-check-circle"></i> <?php _e('save'); ?>
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($canChangeDestination): ?>
<!-- Change Destination Modal (approved & no transfer made yet) -->
<div class="modal fade" id="changeDestinationModal" tabindex="-1" aria-labelledby="changeDestinationModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="changeDestinationModalLabel">
                    <i class="bi bi-geo-alt"></i> <?php _e('change_destination', 'Change Destination'); ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3">
                    <i class="bi bi-info-circle"></i>
                    <?php _e('change_destination_modal_hint', 'Select the new destination location. Only available until the first transfer is made; approved items and quantities are unaffected.'); ?>
                </p>
                <label class="form-label"><strong><?php _e('destination_location'); ?></strong></label>
                <select id="changeDestinationSelect" class="form-select">
                    <?= renderTreeOptions($userLocations, $voucher['destination_location_id']) ?>
                </select>
                <div id="changeDestinationError" class="alert alert-danger mt-2" style="display:none;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                <button type="button" class="btn btn-primary" id="changeDestinationSubmitBtn" onclick="submitChangeDestination(<?php echo $id; ?>)">
                    <i class="bi bi-check-circle"></i> <?php _e('save'); ?>
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($canMapBom): ?>
<!-- Map to BOM Modal — available at ANY status, unlike the draft-only edit form -->
<div class="modal fade" id="mapBomModal" tabindex="-1" aria-labelledby="mapBomModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="mapBomModalLabel">
                    <i class="bi bi-diagram-3"></i>
                    <?php echo $bomLinked ? htmlspecialchars(__('change_bom')) : htmlspecialchars(__('map_bom')); ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3">
                    <i class="bi bi-info-circle"></i> <?php _e('map_bom_modal_hint'); ?>
                </p>
                <label class="form-label"><strong><?php _e('bom'); ?></strong></label>
                <select id="mapBomSelect" class="form-select">
                    <option value=""><?php _e('unlink_bom'); ?></option>
                    <?php foreach ($mapBomList as $b):
                        // Title (migration 0035) reads better than the number; fall back when absent.
                        $bomOptLabel = !empty($b['title']) ? $b['title'] : $b['bom_number'];
                    ?>
                        <option value="<?php echo (int)$b['id']; ?>" <?php echo ($bomLinked && (int)$voucher['bom_id'] === (int)$b['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($bomOptLabel . ' — ' . formatDate($b['bom_date']) . ' — ' . ($b['location_name'] ?? '')); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($mapBomList)): ?>
                <div class="alert alert-warning mt-2 mb-0 py-2 small">
                    <i class="bi bi-exclamation-triangle"></i> <?php _e('no_boms_available'); ?>
                </div>
                <?php endif; ?>
                <div id="mapBomError" class="alert alert-danger mt-2" style="display:none;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                <button type="button" class="btn btn-primary" id="mapBomSubmitBtn" onclick="submitMapBom(<?php echo $id; ?>)">
                    <i class="bi bi-check-circle"></i> <?php _e('save'); ?>
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="<?php echo BASE_URL; ?>assets/js/signature_pad.js"></script>
<script>
const API_URL = '<?php echo BASE_URL; ?>api/requisition-vouchers-sync.php';

/**
 * Send requisition for approval
 */
function sendForApproval(id) {
    if (!confirm('<?php echo __('confirm_send_for_approval'); ?>')) {
        return;
    }

    fetch(API_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'send_for_approval',
            id: id
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message || '<?php echo __('requisition_sent_for_approval'); ?>');
            window.location.reload();
        } else {
            alert(data.error || data.message || 'Error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred. Please try again.');
    });
}

/**
 * Approve requisition via modal — optionally captures Approved By signature.
 * The signature pad is initialised on first modal show.
 */
let approveSigPad = null;
function initApproveSigPad() {
    const canvas = document.getElementById('approveSignatureCanvas');
    if (!canvas || !window.SignaturePad) return;
    approveSigPad = new SignaturePad(canvas, { penColor: '#000', lineWidth: 2 });
    canvas.addEventListener('pointerdown', function() {
        const ph = document.getElementById('approveSignaturePlaceholder');
        if (ph) ph.style.display = 'none';
    });
}
function clearApproveSig() {
    if (approveSigPad) {
        approveSigPad.clear();
        const ph = document.getElementById('approveSignaturePlaceholder');
        if (ph) ph.style.display = '';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const modalEl = document.getElementById('approveRequisitionModal');
    if (modalEl) {
        // Re-create pad each open so canvas sizes correctly under any layout
        modalEl.addEventListener('shown.bs.modal', function() {
            const errBox = document.getElementById('approveModalError');
            if (errBox) errBox.style.display = 'none';
            const ph = document.getElementById('approveSignaturePlaceholder');
            if (ph) ph.style.display = '';
            initApproveSigPad();
        });
    }
});

function submitApprove(id) {
    const errBox = document.getElementById('approveModalError');
    errBox.style.display = 'none';

    // Collect approved quantities (and per-line approval remarks) from the modal table.
    // Approver may increase or reduce each line; 0 rejects the line. No upper limit.
    const items = [];
    const inputs = document.querySelectorAll('#approveRequisitionModal .approved-qty-input');
    for (const input of inputs) {
        const detailId = parseInt(input.dataset.detailId, 10);
        const raw = input.value === '' ? null : parseFloat(input.value);

        if (raw === null || Number.isNaN(raw)) {
            errBox.textContent = 'Please enter a valid approved quantity for every line.';
            errBox.style.display = '';
            input.focus();
            return;
        }
        if (raw < 0) {
            errBox.textContent = 'Approved quantity cannot be negative.';
            errBox.style.display = '';
            input.focus();
            return;
        }
        const remarksInput = document.querySelector('#approveRequisitionModal .approval-remarks-input[data-detail-id="' + detailId + '"]');
        const approvalRemarks = remarksInput ? remarksInput.value : '';
        items.push({ detail_id: detailId, approved_quantity: raw, approval_remarks: approvalRemarks });
    }

    const sigData = (approveSigPad && !approveSigPad.isEmpty()) ? approveSigPad.toDataURL() : null;
    const submitBtn = document.getElementById('approveSubmitBtn');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';

    fetch(API_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'approve',
            id: id,
            approved_by_signature: sigData,
            items: items
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.reload();
        } else {
            errBox.textContent = data.error || data.message || 'Error';
            errBox.style.display = '';
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="bi bi-check-circle"></i> <?php echo __('approve'); ?>';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        errBox.textContent = 'Network error: ' + error.message;
        errBox.style.display = '';
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="bi bi-check-circle"></i> <?php echo __('approve'); ?>';
    });
}

/**
 * Reject requisition via modal — optional reason.
 */
function submitReject(id) {
    const errBox = document.getElementById('rejectModalError');
    errBox.style.display = 'none';

    const reason = document.getElementById('reject_reason').value;
    const submitBtn = document.getElementById('rejectSubmitBtn');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';

    fetch(API_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'reject',
            id: id,
            reject_reason: reason
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.reload();
        } else {
            errBox.textContent = data.error || data.message || 'Error';
            errBox.style.display = '';
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="bi bi-x-circle"></i> <?php echo __('reject'); ?>';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        errBox.textContent = 'Network error: ' + error.message;
        errBox.style.display = '';
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="bi bi-x-circle"></i> <?php echo __('reject'); ?>';
    });
}

/**
 * Make transfer from requisition
 */
function makeTransfer(id) {
    const sourceLocationId = document.getElementById('source_location_id').value;
    if (!sourceLocationId) {
        alert('<?php echo __('select_source_location'); ?>');
        return;
    }

    // Collect items with transferring_now > 0
    const inputs = document.querySelectorAll('.transferring-now-input');
    const items = [];
    inputs.forEach(function(input) {
        const qty = parseFloat(input.value) || 0;
        if (qty > 0) {
            items.push({
                detail_id: parseInt(input.dataset.detailId),
                product_id: parseInt(input.dataset.productId),
                quantity: qty
            });
        }
    });

    if (items.length === 0) {
        alert('<?php echo __('no_items_to_transfer'); ?>');
        return;
    }

    fetch(API_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'make_transfer',
            id: id,
            source_location_id: parseInt(sourceLocationId),
            items: items
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            let msg = data.message || '<?php echo __('transfer_created_from_requisition'); ?>';
            if (data.transfer_id) {
                msg += '\n\n<?php _e('transfer_link'); ?>: ' + data.transfer_number;
                alert(msg);
                window.location.reload();
            } else {
                alert(msg);
                window.location.reload();
            }
        } else {
            alert(data.error || data.message || 'Error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred. Please try again.');
    });
}

/**
 * Show close requisition modal
 */
function showCloseModal() {
    var modal = new bootstrap.Modal(document.getElementById('closeRequisitionModal'));
    modal.show();
}

/**
 * Close requisition
 */
function closeRequisition(id) {
    const closeReason = document.getElementById('close_reason').value;

    fetch(API_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'close',
            id: id,
            close_reason: closeReason
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message || '<?php echo __('requisition_closed'); ?>');
            window.location.reload();
        } else {
            alert(data.error || data.message || 'Error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred. Please try again.');
    });
}

/**
 * Delete requisition (draft only)
 */
function deleteRequisition(id) {
    if (!confirm('<?php echo __('confirm_delete_requisition'); ?>')) {
        return;
    }

    fetch(API_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'delete',
            id: id
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message || '<?php echo __('requisition_deleted'); ?>');
            window.location.href = 'requisition_voucher_list.php';
        } else {
            alert(data.error || data.message || 'Error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred. Please try again.');
    });
}

<?php if ($canEditApproved): ?>
/**
 * Revise approved quantities (open/approved requisitions). Collects each line's
 * approved quantity + approval remark and posts action 'revise_approval'. The
 * per-line floor (>= already transferred) is validated here and re-checked
 * server-side. Does not change status.
 */
function submitRevise(id) {
    const errBox = document.getElementById('reviseModalError');
    errBox.style.display = 'none';

    const items = [];
    const inputs = document.querySelectorAll('#reviseApprovalModal .revise-qty-input');
    for (const input of inputs) {
        const detailId = parseInt(input.dataset.detailId, 10);
        const transferred = parseFloat(input.dataset.transferred) || 0;
        const raw = input.value === '' ? null : parseFloat(input.value);

        if (raw === null || Number.isNaN(raw)) {
            errBox.textContent = 'Please enter a valid approved quantity for every line.';
            errBox.style.display = '';
            input.focus();
            return;
        }
        if (raw < 0) {
            errBox.textContent = 'Approved quantity cannot be negative.';
            errBox.style.display = '';
            input.focus();
            return;
        }
        if (raw < transferred - 0.0001) {
            errBox.textContent = 'Approved quantity cannot be below the already-transferred quantity (' + transferred.toFixed(3).replace(/\.?0+$/, '') + ').';
            errBox.style.display = '';
            input.focus();
            return;
        }
        const remarksInput = document.querySelector('#reviseApprovalModal .revise-remarks-input[data-detail-id="' + detailId + '"]');
        const approvalRemarks = remarksInput ? remarksInput.value : '';
        items.push({ detail_id: detailId, approved_quantity: raw, approval_remarks: approvalRemarks });
    }

    if (items.length === 0) {
        errBox.textContent = 'No line items to update.';
        errBox.style.display = '';
        return;
    }

    const submitBtn = document.getElementById('reviseSubmitBtn');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';

    fetch(API_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'revise_approval',
            id: id,
            items: items
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.reload();
        } else {
            errBox.textContent = data.error || data.message || 'Error';
            errBox.style.display = '';
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="bi bi-check-circle"></i> <?php echo __('save'); ?>';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        errBox.textContent = 'Network error: ' + error.message;
        errBox.style.display = '';
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="bi bi-check-circle"></i> <?php echo __('save'); ?>';
    });
}
<?php endif; ?>

<?php if ($canChangeDestination): ?>
/**
 * Change destination location (open/approved requisition, no transfer yet).
 * Posts action 'change_destination'. The server re-checks status, the no-transfer
 * guard, cost-centre authorisation and destination access. Does not change status.
 */
function submitChangeDestination(id) {
    const errBox = document.getElementById('changeDestinationError');
    errBox.style.display = 'none';

    const sel = document.getElementById('changeDestinationSelect');
    const newDest = parseInt(sel.value || '0', 10);
    if (!newDest) {
        errBox.textContent = 'Please select a destination location.';
        errBox.style.display = '';
        return;
    }

    const submitBtn = document.getElementById('changeDestinationSubmitBtn');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';

    fetch(API_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'change_destination',
            id: id,
            destination_location_id: newDest
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.reload();
        } else {
            errBox.textContent = data.error || data.message || 'Error';
            errBox.style.display = '';
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="bi bi-check-circle"></i> <?php echo __('save'); ?>';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        errBox.textContent = 'Network error: ' + error.message;
        errBox.style.display = '';
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="bi bi-check-circle"></i> <?php echo __('save'); ?>';
    });
}
<?php endif; ?>

<?php if ($canMapBom): ?>
/**
 * Map this requisition to a BOM, change which BOM it points at, or unlink it.
 * A blank selection means UNLINK, so — unlike change_destination — an empty
 * value is valid and must NOT be rejected client-side.
 */
function submitMapBom(id) {
    const errBox = document.getElementById('mapBomError');
    errBox.style.display = 'none';

    const sel = document.getElementById('mapBomSelect');
    const newBomId = parseInt(sel.value || '0', 10) || 0;

    // Unlinking silently drops this requisition's consumption off the BOM's
    // balance, which other requisitions on that BOM will see. Confirm it.
    if (!newBomId && <?php echo $bomLinked ? 'true' : 'false'; ?>) {
        if (!confirm('<?php echo addslashes(__('confirm_unlink_bom')); ?>')) {
            return;
        }
    }

    const submitBtn = document.getElementById('mapBomSubmitBtn');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';

    fetch(API_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'map_bom',
            id: id,
            bom_id: newBomId  // 0 = unlink
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.reload();
        } else {
            errBox.textContent = data.error || data.message || 'Error';
            errBox.style.display = '';
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="bi bi-check-circle"></i> <?php echo __('save'); ?>';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        errBox.textContent = 'Network error: ' + error.message;
        errBox.style.display = '';
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="bi bi-check-circle"></i> <?php echo __('save'); ?>';
    });
}
<?php endif; ?>
</script>

<?php if ($status === 'pending_approval' && hasPermission('requisition_vouchers', 'approve')): ?>
<script>
/**
 * Approve modal — show system-wide available stock per line product, with a
 * click-through per-location breakdown. Mirrors the requisition create page,
 * reusing the same stock-family API. Self-contained (IIFE) so nothing else is
 * affected; all Bootstrap/jQuery calls run at click/DOMContentLoaded time.
 */
(function () {
    const RV_STOCK_FAMILY_URL = '<?php echo BASE_URL; ?>api/stock-family.php';
    const RV_DEST_LOCATION_ID = <?php echo (int)$voucher['destination_location_id']; ?>;
    const rvStockCache = {}; // productId -> { total_qty, breakdown: [...] }

    function rvFormatQty(n) {
        if (n === null || n === undefined || isNaN(n)) return '0';
        return parseFloat(n).toFixed(3).replace(/\.?0+$/, '');
    }

    function rvEscape(text) {
        const span = document.createElement('span');
        span.textContent = (text === null || text === undefined) ? '' : text;
        return span.innerHTML;
    }

    function rvFetchStock(productIds) {
        productIds = (productIds || []).map(p => parseInt(p, 10)).filter(p => p > 0);
        if (!productIds.length || !RV_DEST_LOCATION_ID) return Promise.resolve();
        return fetch(RV_STOCK_FAMILY_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ location_id: RV_DEST_LOCATION_ID, product_ids: productIds })
        })
        .then(r => r.json())
        .then(data => {
            if (!data || !data.success) return;
            Object.keys(data.stock || {}).forEach(pid => {
                rvStockCache[parseInt(pid, 10)] = data.stock[pid];
            });
        })
        .catch(err => console.error('approve stock fetch failed', err));
    }

    function rvUpdateAvailableCells() {
        document.querySelectorAll('#approveRequisitionModal .approve-available-cell').forEach(cell => {
            const pid = parseInt(cell.dataset.productId, 10);
            const entry = rvStockCache[pid];
            if (!entry) { cell.innerHTML = '<span class="text-muted">…</span>'; return; }
            cell.innerHTML =
                '<a href="#" class="approve-available-link text-decoration-none" data-product-id="' + pid + '">' +
                    rvFormatQty(entry.total_qty) + ' <i class="bi bi-info-circle small"></i>' +
                '</a>';
        });
    }

    // Build the inner HTML (the location table) for a product's breakdown.
    function rvBreakdownInnerHtml(entry) {
        let rows = '';
        let totalText = '0';
        if (!entry || !entry.breakdown || !entry.breakdown.length) {
            rows = '<tr><td colspan="2" class="text-center text-muted">No stock in any location.</td></tr>';
        } else {
            entry.breakdown.forEach(row => {
                let cls = '';
                let badge = '';
                if (row.is_destination) {
                    cls = 'table-warning fw-bold';
                    badge = ' <span class="badge bg-warning text-dark ms-1">destination</span>';
                } else if (row.is_family) {
                    badge = ' <span class="badge bg-info text-dark ms-1">family</span>';
                }
                rows +=
                    '<tr' + (cls ? ' class="' + cls + '"' : '') + '>' +
                        '<td>' + rvEscape(row.location_name) + badge + '</td>' +
                        '<td class="text-end" style="width:30%;">' + rvFormatQty(row.qty) + '</td>' +
                    '</tr>';
            });
            totalText = rvFormatQty(entry.total_qty);
        }
        return (
            '<div class="p-2 m-1 bg-light border rounded">' +
                '<div class="d-flex justify-content-between align-items-center mb-1">' +
                    '<strong class="small"><i class="bi bi-geo-alt"></i> Available stock by location</strong>' +
                    '<button type="button" class="btn-close rv-breakdown-close" aria-label="Close" style="font-size:.7rem;"></button>' +
                '</div>' +
                '<table class="table table-sm table-bordered mb-0">' +
                    '<thead class="table-light"><tr><th>Location</th><th class="text-end" style="width:30%;">Available qty</th></tr></thead>' +
                    '<tbody>' + rows + '</tbody>' +
                    '<tfoot><tr class="table-light"><th class="text-end">Total</th><th class="text-end">' + totalText + '</th></tr></tfoot>' +
                '</table>' +
                '<p class="text-muted small mt-1 mb-0">Only locations with non-zero stock are listed.</p>' +
            '</div>'
        );
    }

    // Toggle the breakdown row directly under the clicked product row. Other open
    // breakdown rows are left untouched (independent expand/collapse).
    function rvToggleBreakdown(link) {
        const productRow = link.closest('tr');
        if (!productRow) return;

        // If this row's breakdown is already open (it's the immediate next row), collapse it.
        const next = productRow.nextElementSibling;
        if (next && next.classList.contains('rv-breakdown-row')) {
            next.remove();
            return;
        }

        const pid = parseInt(link.dataset.productId, 10);
        const entry = rvStockCache[pid];
        productRow.insertAdjacentHTML('afterend',
            '<tr class="rv-breakdown-row"><td colspan="<?php echo $bomLinked ? '8' : '7'; ?>" class="p-0">' + rvBreakdownInnerHtml(entry) + '</td></tr>'
        );
    }

    document.addEventListener('DOMContentLoaded', function () {
        const approveModal = document.getElementById('approveRequisitionModal');
        if (!approveModal) return;

        // Pre-fetch availability for all line products (cells fill in when ready).
        const pids = [];
        approveModal.querySelectorAll('.approve-available-cell').forEach(c => {
            const pid = parseInt(c.dataset.productId, 10);
            if (pid) pids.push(pid);
        });
        if (pids.length) rvFetchStock(pids).then(rvUpdateAvailableCells);

        // Delegated clicks inside the approve modal: open/close a per-row breakdown,
        // or close one via its × button.
        approveModal.addEventListener('click', function (e) {
            const closeBtn = e.target.closest('.rv-breakdown-close');
            if (closeBtn) {
                const row = closeBtn.closest('.rv-breakdown-row');
                if (row) row.remove();
                return;
            }
            const link = e.target.closest('.approve-available-link');
            if (link) {
                e.preventDefault();
                rvToggleBreakdown(link);
            }
        });

        // Clear any open breakdown rows when the approve modal closes.
        approveModal.addEventListener('hidden.bs.modal', function () {
            approveModal.querySelectorAll('.rv-breakdown-row').forEach(r => r.remove());
        });
    });
})();
</script>
<?php endif; ?>

<?php if ($canEditApproved): ?>
<script>
/**
 * Revise modal — fill each line's "Available" cell with the system-wide
 * available stock for that product (relative to the requisition destination),
 * reusing the same stock-family API the approve modal uses. Self-contained IIFE
 * so it touches nothing else; shows the total only (no breakdown) to stay light.
 */
(function () {
    const REVISE_STOCK_FAMILY_URL = '<?php echo BASE_URL; ?>api/stock-family.php';
    const REVISE_DEST_LOCATION_ID = <?php echo (int)$voucher['destination_location_id']; ?>;
    const reviseStockCache = {};

    function reviseFormatQty(n) {
        if (n === null || n === undefined || isNaN(n)) return '0';
        return parseFloat(n).toFixed(3).replace(/\.?0+$/, '');
    }

    function reviseFetchStock(productIds) {
        productIds = (productIds || []).map(p => parseInt(p, 10)).filter(p => p > 0);
        if (!productIds.length || !REVISE_DEST_LOCATION_ID) return Promise.resolve();
        return fetch(REVISE_STOCK_FAMILY_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ location_id: REVISE_DEST_LOCATION_ID, product_ids: productIds })
        })
        .then(r => r.json())
        .then(data => {
            if (!data || !data.success) return;
            Object.keys(data.stock || {}).forEach(pid => {
                reviseStockCache[parseInt(pid, 10)] = data.stock[pid];
            });
        })
        .catch(err => console.error('revise stock fetch failed', err));
    }

    function reviseUpdateAvailableCells() {
        document.querySelectorAll('#reviseApprovalModal .revise-available-cell').forEach(cell => {
            const pid = parseInt(cell.dataset.productId, 10);
            const entry = reviseStockCache[pid];
            cell.innerHTML = entry ? reviseFormatQty(entry.total_qty) : '<span class="text-muted">0</span>';
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        const reviseModal = document.getElementById('reviseApprovalModal');
        if (!reviseModal) return;

        const pids = [];
        reviseModal.querySelectorAll('.revise-available-cell').forEach(c => {
            const pid = parseInt(c.dataset.productId, 10);
            if (pid) pids.push(pid);
        });
        if (pids.length) reviseFetchStock(pids).then(reviseUpdateAvailableCells);

        // Reset any prior error each time the modal opens.
        reviseModal.addEventListener('shown.bs.modal', function () {
            const errBox = document.getElementById('reviseModalError');
            if (errBox) errBox.style.display = 'none';
        });
    });
})();
</script>
<?php endif; ?>

<script>
/**
 * Main items table — show system-wide available stock per line product
 * (relative to the requisition destination), with a click-through per-location
 * breakdown. Mirrors the approve modal; self-contained IIFE so it touches
 * nothing else. Available is informational and shown for every status. It is
 * live stock, not saved on the voucher, so the printed heading says "at print
 * time". The figure prints; the per-location breakdown stays screen-only.
 */
(function () {
    const cells = document.querySelectorAll('.rv-main-available-cell');
    if (!cells.length) return;

    const STOCK_URL = '<?php echo BASE_URL; ?>api/stock-family.php';
    const DEST_LOCATION_ID = <?php echo (int)$voucher['destination_location_id']; ?>;
    const MAIN_COLSPAN = <?php echo (int)$mainColCount; ?>;
    const cache = {};

    function fmt(n) {
        if (n === null || n === undefined || isNaN(n)) return '0';
        return parseFloat(n).toFixed(3).replace(/\.?0+$/, '');
    }
    function esc(t) {
        const s = document.createElement('span');
        s.textContent = (t === null || t === undefined) ? '' : t;
        return s.innerHTML;
    }
    function fetchStock(ids) {
        ids = (ids || []).map(p => parseInt(p, 10)).filter(p => p > 0);
        if (!ids.length || !DEST_LOCATION_ID) return Promise.resolve();
        return fetch(STOCK_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ location_id: DEST_LOCATION_ID, product_ids: ids })
        })
        .then(r => r.json())
        .then(data => {
            if (!data || !data.success) return;
            Object.keys(data.stock || {}).forEach(pid => { cache[parseInt(pid, 10)] = data.stock[pid]; });
        })
        .catch(err => console.error('main available fetch failed', err));
    }
    function updateCells() {
        cells.forEach(cell => {
            const pid = parseInt(cell.dataset.productId, 10);
            const entry = cache[pid];
            if (!entry) { cell.innerHTML = '<span class="text-muted">0</span>'; return; }
            cell.innerHTML =
                '<a href="#" class="rv-main-available-link text-decoration-none" data-product-id="' + pid + '">' +
                    fmt(entry.total_qty) + ' <i class="bi bi-info-circle small"></i>' +
                '</a>';
        });
    }
    function breakdownHtml(entry) {
        let rows = '', total = '0';
        if (!entry || !entry.breakdown || !entry.breakdown.length) {
            rows = '<tr><td colspan="2" class="text-center text-muted">No stock in any location.</td></tr>';
        } else {
            entry.breakdown.forEach(row => {
                let cls = '', badge = '';
                if (row.is_destination) { cls = 'table-warning fw-bold'; badge = ' <span class="badge bg-warning text-dark ms-1">destination</span>'; }
                else if (row.is_family) { badge = ' <span class="badge bg-info text-dark ms-1">family</span>'; }
                rows += '<tr' + (cls ? ' class="' + cls + '"' : '') + '><td>' + esc(row.location_name) + badge + '</td><td class="text-end" style="width:30%;">' + fmt(row.qty) + '</td></tr>';
            });
            total = fmt(entry.total_qty);
        }
        return '<div class="p-2 m-1 bg-light border rounded">' +
            '<div class="d-flex justify-content-between align-items-center mb-1">' +
                '<strong class="small"><i class="bi bi-geo-alt"></i> Available stock by location</strong>' +
                '<button type="button" class="btn-close rv-main-breakdown-close" aria-label="Close" style="font-size:.7rem;"></button>' +
            '</div>' +
            '<table class="table table-sm table-bordered mb-0">' +
                '<thead class="table-light"><tr><th>Location</th><th class="text-end" style="width:30%;">Available qty</th></tr></thead>' +
                '<tbody>' + rows + '</tbody>' +
                '<tfoot><tr class="table-light"><th class="text-end">Total</th><th class="text-end">' + total + '</th></tr></tfoot>' +
            '</table>' +
            '<p class="text-muted small mt-1 mb-0">Only locations with non-zero stock are listed.</p>' +
        '</div>';
    }
    function toggle(link) {
        const tr = link.closest('tr');
        if (!tr) return;
        const next = tr.nextElementSibling;
        if (next && next.classList.contains('rv-main-breakdown-row')) { next.remove(); return; }
        const pid = parseInt(link.dataset.productId, 10);
        tr.insertAdjacentHTML('afterend',
            '<tr class="rv-main-breakdown-row no-print"><td colspan="' + MAIN_COLSPAN + '" class="p-0">' + breakdownHtml(cache[pid]) + '</td></tr>'
        );
    }

    document.addEventListener('DOMContentLoaded', function () {
        const ids = [];
        cells.forEach(c => { const pid = parseInt(c.dataset.productId, 10); if (pid) ids.push(pid); });
        fetchStock(ids).then(updateCells);

        document.addEventListener('click', function (e) {
            const closeBtn = e.target.closest('.rv-main-breakdown-close');
            if (closeBtn) { const r = closeBtn.closest('.rv-main-breakdown-row'); if (r) r.remove(); return; }
            const link = e.target.closest('.rv-main-available-link');
            if (link) { e.preventDefault(); toggle(link); }
        });
    });
})();
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
        color: #000 !important;
        font-size: 7pt !important;
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

    /* The long print heading wraps, so it does not squeeze the product column */
    .rv-main-available-head {
        white-space: normal !important;
    }

    /* Available prints as a plain figure, not a link with an info icon */
    .rv-main-available-cell,
    .rv-main-available-link {
        color: #000 !important;
    }

    .rv-main-available-link i {
        display: none !important;
    }

    /* Hide transfer form inputs in print */
    .transferring-now-input {
        border: none !important;
        background: transparent !important;
        text-align: right !important;
        padding: 0 !important;
    }
}
</style>

<?php require_once '../../includes/footer.php'; ?>
