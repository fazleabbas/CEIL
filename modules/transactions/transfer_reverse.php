<?php
/**
 * Reverse Transfer Form
 *
 * Return goods that were previously transferred TO a location back to the
 * source. Launched from a RECEIVED transfer's view/list ("Reverse Transfer").
 * The original's from/to are swapped (goods go back the other way), the cost
 * centre is kept, and every product on the original transfer is listed with a
 * return-quantity column defaulting to 0. On submit it calls the sync API
 * `create_reverse` action, which stores the reverse as an ordinary transfer
 * (draft) flagged via reverse_of_transfer_id. Stock and BOM balance are only
 * affected later, through the normal dispatch/receive lifecycle.
 */
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");

require_once '../../config/database.php';
require_once '../../includes/functions.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

if (!hasPermission('stock_transfer', 'create')) {
    setAlert(__('no_permission'), 'danger');
    redirect('modules/transactions/transfers_management.php');
}

$parentId = isset($_GET['from']) ? (int)$_GET['from'] : 0;
if ($parentId <= 0) {
    setAlert(__('transfer_not_found'), 'danger');
    redirect('modules/transactions/transfers_management.php');
}

// Load the original (source) transfer
$stmt = $conn->prepare("
    SELECT t.*,
           fl.name as from_location_name,
           tl.name as to_location_name,
           cc.name as cost_centre_name, cc.code as cost_centre_code,
           cu.code as currency_code, cu.symbol as currency_symbol
    FROM stock_transfers t
    LEFT JOIN locations fl ON t.from_location_id = fl.id
    LEFT JOIN locations tl ON t.to_location_id = tl.id
    LEFT JOIN cost_centres cc ON t.cost_centre_id = cc.id
    LEFT JOIN currencies cu ON t.currency_id = cu.id
    WHERE t.id = ?
");
$stmt->execute([$parentId]);
$parent = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$parent) {
    setAlert(__('transfer_not_found'), 'danger');
    redirect('modules/transactions/transfers_management.php');
}

// Only a received transfer can be reversed, and not a reverse itself.
if ($parent['status'] !== 'received') {
    setAlert(__('only_received_can_reverse'), 'warning');
    redirect('modules/transactions/transfers_view.php?id=' . $parentId);
}
if (!empty($parent['reverse_of_transfer_id'])) {
    setAlert(__('cannot_reverse_a_reverse'), 'warning');
    redirect('modules/transactions/transfers_view.php?id=' . $parentId);
}

// Goods physically sit at the parent's destination; the user returns from there.
if (!canAccessLocation($parent['to_location_id'])) {
    setAlert(__('no_permission'), 'danger');
    redirect('modules/transactions/transfers_management.php');
}

// Original lines + how much of each has already been returned.
$linesStmt = $conn->prepare("
    SELECT td.*, p.name as product_name, p.unit
    FROM stock_transfer_details td
    INNER JOIN products p ON td.product_id = p.id
    WHERE td.transfer_id = ?
    ORDER BY td.id
");
$linesStmt->execute([$parentId]);
$parentLines = $linesStmt->fetchAll(PDO::FETCH_ASSOC);

$alreadyReturned = getTransferReturnedQty($parentId, $conn);

// Build the display rows (received qty is the returnable ceiling).
$rows = [];
$anyReturnable = false;
foreach ($parentLines as $l) {
    $pid = (int)$l['product_id'];
    $received = ($l['received_quantity'] !== null)
        ? floatval($l['received_quantity'])
        : floatval($l['dispatched_quantity']);
    $returned = (float)($alreadyReturned[$pid] ?? 0);
    $remaining = $received - $returned;
    if ($remaining < 0) $remaining = 0;
    if ($remaining > 0) $anyReturnable = true;
    $rows[] = [
        'product_id'   => $pid,
        'product_name' => $l['product_name'],
        'unit'         => $l['unit'],
        'received'     => $received,
        'returned'     => $returned,
        'remaining'    => $remaining,
        'rate'         => floatval($l['rate_per_unit']),
    ];
}

$symbol = $parent['currency_symbol'] ?: 'TZS';
$pageTitle = __('reverse_transfer') . ' - ' . $parent['transfer_number'];
require_once '../../includes/header.php';
?>

<div class="row mb-3">
    <div class="col-md-8">
        <h2><i class="bi bi-arrow-counterclockwise"></i> <?php _e('reverse_transfer'); ?></h2>
        <p class="text-muted mb-0">
            <?php _e('reverse_of'); ?>:
            <a href="transfers_view.php?id=<?php echo $parentId; ?>">
                <?php echo htmlspecialchars($parent['transfer_number']); ?>
            </a>
        </p>
    </div>
    <div class="col-md-4 text-end">
        <a href="transfers_view.php?id=<?php echo $parentId; ?>" class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i> <?php _e('cancel'); ?>
        </a>
    </div>
</div>

<form id="reverseForm" onsubmit="return false;">
    <div class="card mb-3">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-info-circle"></i> <?php _e('transfer_details'); ?></h5>
        </div>
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-md-3">
                    <label class="form-label"><?php _e('transfer_date'); ?> <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" id="reverseDate"
                           value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label"><?php _e('from_location'); ?> <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" readonly
                           value="<?php echo htmlspecialchars($parent['to_location_name']); ?>">
                    <small class="text-muted"><?php _e('returning_from'); ?></small>
                </div>
                <div class="col-md-4">
                    <label class="form-label"><?php _e('to_location'); ?> <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" readonly
                           value="<?php echo htmlspecialchars($parent['from_location_name']); ?>">
                    <small class="text-muted"><?php _e('returning_to'); ?></small>
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="form-label"><?php _e('cost_centre'); ?></label>
                    <input type="text" class="form-control" readonly
                           value="<?php echo $parent['cost_centre_id'] ? htmlspecialchars($parent['cost_centre_code'] . ' - ' . $parent['cost_centre_name']) : '-'; ?>">
                </div>
                <div class="col-md-8">
                    <label class="form-label"><?php _e('remarks'); ?></label>
                    <input type="text" class="form-control" id="reverseRemarks" maxlength="255"
                           placeholder="<?php _e('optional'); ?>"
                           value="<?php echo htmlspecialchars('Reverse of ' . $parent['transfer_number']); ?>">
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-box-seam"></i> <?php _e('transfer_items'); ?></h5>
        </div>
        <div class="card-body">
            <?php if (!$anyReturnable): ?>
            <div class="alert alert-info mb-0">
                <i class="bi bi-info-circle"></i> <?php _e('nothing_left_to_return'); ?>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="width:40px;">#</th>
                            <th><?php _e('product'); ?></th>
                            <th class="text-center" style="width:80px;"><?php _e('unit'); ?></th>
                            <th class="text-end" style="width:110px;"><?php _e('transferred'); ?></th>
                            <th class="text-end" style="width:110px;"><?php _e('already_returned'); ?></th>
                            <th class="text-end" style="width:110px;"><?php _e('remaining_returnable'); ?></th>
                            <th class="text-end" style="width:150px;"><?php _e('return_quantity'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $sn = 0; foreach ($rows as $r): $sn++; ?>
                        <tr>
                            <td class="text-center"><?php echo $sn; ?></td>
                            <td><?php echo htmlspecialchars($r['product_name']); ?></td>
                            <td class="text-center"><?php echo htmlspecialchars($r['unit']); ?></td>
                            <td class="text-end"><?php echo formatNumber($r['received'], 3); ?></td>
                            <td class="text-end"><?php echo formatNumber($r['returned'], 3); ?></td>
                            <td class="text-end"><strong><?php echo formatNumber($r['remaining'], 3); ?></strong></td>
                            <td class="text-end">
                                <input type="number"
                                       class="form-control form-control-sm text-end return-qty-input"
                                       data-product-id="<?php echo $r['product_id']; ?>"
                                       data-remaining="<?php echo $r['remaining']; ?>"
                                       value="0" min="0" step="0.001"
                                       max="<?php echo $r['remaining']; ?>"
                                       <?php echo $r['remaining'] <= 0 ? 'readonly' : ''; ?>>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($anyReturnable): ?>
    <!-- E-Signature: Prepared By (optional) -->
    <div class="card mb-3">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-pen"></i> E-Signatures <small class="text-muted">(optional)</small></h5>
        </div>
        <div class="card-body">
            <style>
                .signature-wrapper { border: 1px dashed #adb5bd; border-radius: 4px; background: #fafafa; position: relative; max-width: 420px; }
                .signature-canvas { display: block; width: 100%; height: 140px; touch-action: none; cursor: crosshair; }
                .signature-placeholder { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: #adb5bd; font-style: italic; pointer-events: none; user-select: none; font-size: 13px; }
            </style>
            <label class="form-label fw-bold"><?php _e('prepared_by'); ?></label>
            <?php $myMasterSig = getUserSignature($conn, getUserId()); ?>
            <?php if ($myMasterSig): ?>
            <div class="border rounded p-2 bg-light" style="max-width: 420px;">
                <img src="<?php echo htmlspecialchars($myMasterSig); ?>" alt="<?php _e('signature'); ?>" style="max-height: 90px; max-width: 100%;">
                <div class="text-muted small mt-1"><i class="bi bi-info-circle"></i> <?php _e('signature_from_profile'); ?></div>
            </div>
            <?php else: ?>
            <div class="signature-wrapper">
                <canvas id="preparedBySignature" class="signature-canvas"></canvas>
                <span class="signature-placeholder" id="preparedBySignaturePlaceholder">Sign here</span>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary mt-1" onclick="clearPreparedSig()">
                <i class="bi bi-x-circle"></i> <?php _e('clear'); ?>
            </button>
            <?php
            /* Not everyone has a touchscreen. Offer an image upload as an alternative
               to drawing; it is loaded INTO the pad above, so the submit code is
               unchanged. Only reachable in this else-branch, i.e. only for signers
               with no signature on file. */
            $sigUploadCanvasId      = 'preparedBySignature';
            $sigUploadPlaceholderId = 'preparedBySignaturePlaceholder';
            require __DIR__ . '/../../includes/signature_upload_control.php';
            ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-body d-flex justify-content-between">
            <a href="transfers_view.php?id=<?php echo $parentId; ?>" class="btn btn-secondary">
                <i class="bi bi-x-circle"></i> <?php _e('cancel'); ?>
            </a>
            <div>
                <button type="button" class="btn btn-primary" id="saveBtn" onclick="saveReverse(false)">
                    <i class="bi bi-check-circle"></i> <?php _e('save_transfer'); ?>
                </button>
                <button type="button" class="btn btn-success" id="saveDispatchBtn" onclick="saveReverse(true)">
                    <i class="bi bi-truck"></i> <?php _e('save_transfer'); ?> &amp; <?php _e('dispatch_transfer'); ?>
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>
</form>

<script src="<?php echo BASE_URL; ?>assets/js/signature_pad.js"></script>
<script>
const PARENT_ID = <?php echo $parentId; ?>;
let preparedSigPad = null;

document.addEventListener('DOMContentLoaded', function() {
    const canvas = document.getElementById('preparedBySignature');
    if (canvas && window.SignaturePad) {
        preparedSigPad = new SignaturePad(canvas, { penColor: '#000', lineWidth: 2 });
        canvas.addEventListener('pointerdown', function() {
            document.getElementById('preparedBySignaturePlaceholder').style.display = 'none';
        });
    }

    // Clamp each input to its returnable ceiling.
    document.querySelectorAll('.return-qty-input').forEach(function(input) {
        input.addEventListener('input', function() {
            const max = parseFloat(this.dataset.remaining) || 0;
            let val = parseFloat(this.value) || 0;
            if (val < 0) { val = 0; this.value = 0; }
            if (val > max) { val = max; this.value = max; }
        });
    });
});

function clearPreparedSig() {
    if (preparedSigPad) {
        preparedSigPad.clear();
        document.getElementById('preparedBySignaturePlaceholder').style.display = '';
    }
}

function collectItems() {
    const items = [];
    document.querySelectorAll('.return-qty-input').forEach(function(input) {
        const qty = parseFloat(input.value) || 0;
        if (qty > 0) {
            items.push({ product_id: parseInt(input.dataset.productId), quantity: qty });
        }
    });
    return items;
}

async function saveReverse(dispatchAfterSave) {
    const items = collectItems();
    if (items.length === 0) {
        alert('<?php echo addslashes(__('enter_return_qty')); ?>');
        return;
    }

    const saveBtn = document.getElementById('saveBtn');
    const saveDispatchBtn = document.getElementById('saveDispatchBtn');
    saveBtn.disabled = true;
    saveDispatchBtn.disabled = true;

    const preparedSig = (preparedSigPad && !preparedSigPad.isEmpty()) ? preparedSigPad.toDataURL() : null;

    const data = {
        action: 'create_reverse',
        reverse_of_transfer_id: PARENT_ID,
        transfer_date: document.getElementById('reverseDate').value,
        remarks: document.getElementById('reverseRemarks').value,
        prepared_by_signature: preparedSig,
        items: items
    };

    try {
        const response = await fetch(BASE_URL + 'api/transfers-sync.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        const result = await response.json();

        if (result.success) {
            if (dispatchAfterSave && result.serverId) {
                const dRes = await fetch(BASE_URL + 'api/transfers-sync.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'dispatch', transfer_id: result.serverId })
                });
                const dResult = await dRes.json();
                if (!dResult.success) {
                    alert(dResult.error || 'Saved but failed to dispatch');
                }
            }
            window.location.href = 'transfers_management.php';
        } else {
            alert(result.error || result.message || 'Failed to save reverse transfer');
            saveBtn.disabled = false;
            saveDispatchBtn.disabled = false;
        }
    } catch (error) {
        console.error('Error:', error);
        alert('An error occurred while saving');
        saveBtn.disabled = false;
        saveDispatchBtn.disabled = false;
    }
}
</script>

<?php require_once '../../includes/footer.php'; ?>
