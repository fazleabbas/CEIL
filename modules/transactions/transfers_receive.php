<?php
/**
 * Receive Stock Transfer Form
 * Allows TO location user to enter received quantities
 */
require_once '../../config/database.php';
require_once '../../includes/functions.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

if (!hasPermission('stock_transfer', 'edit')) {
    setAlert(__('no_permission'), 'error');
    redirect('index.php');
}

$transferId = $_GET['id'] ?? 0;

if (empty($transferId)) {
    setAlert(__('transfer_not_found'), 'error');
    redirect('modules/transactions/transfers_management.php');
}

// Get transfer details
$stmt = $conn->prepare("
    SELECT t.*,
           fl.name as from_location_name,
           tl.name as to_location_name
    FROM stock_transfers t
    LEFT JOIN locations fl ON t.from_location_id = fl.id
    LEFT JOIN locations tl ON t.to_location_id = tl.id
    WHERE t.id = ?
");
$stmt->execute([$transferId]);
$transfer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$transfer) {
    setAlert(__('transfer_not_found'), 'error');
    redirect('modules/transactions/transfers_management.php');
}

// Check status
if ($transfer['status'] !== 'in_transit') {
    setAlert(__('only_in_transit_can_receive'), 'error');
    redirect('modules/transactions/transfers_management.php');
}

// Check location access (user must have access to TO location)
if (!canAccessLocation($transfer['to_location_id'])) {
    setAlert(__('transfer_no_access_to'), 'error');
    redirect('modules/transactions/transfers_management.php');
}

// Get transfer items
$itemsStmt = $conn->prepare("
    SELECT td.*, p.name as product_name, p.unit, p.barcode
    FROM stock_transfer_details td
    INNER JOIN products p ON td.product_id = p.id
    WHERE td.transfer_id = ?
    ORDER BY td.id
");
$itemsStmt->execute([$transferId]);
$items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = __('receive_transfer') . ' - ' . $transfer['transfer_number'];
include '../../includes/header.php';
?>

<div class="container">
    <div class="page-header">
        <h1><?php _e('receive_transfer'); ?></h1>
        <div class="page-actions">
            <a href="<?php echo BASE_URL; ?>modules/transactions/transfers_management.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> <?php _e('back'); ?>
            </a>
        </div>
    </div>

    <!-- Transfer Info -->
    <div class="card mb-3">
        <div class="card-body">
            <div class="row">
                <div class="col-md-3">
                    <strong><?php _e('transfer_number'); ?>:</strong><br>
                    <?php echo htmlspecialchars($transfer['transfer_number']); ?>
                </div>
                <div class="col-md-3">
                    <strong><?php _e('transfer_date'); ?>:</strong><br>
                    <?php echo formatDate($transfer['transfer_date']); ?>
                </div>
                <div class="col-md-3">
                    <strong><?php _e('from_location'); ?>:</strong><br>
                    <?php echo htmlspecialchars($transfer['from_location_name']); ?>
                </div>
                <div class="col-md-3">
                    <strong><?php _e('to_location'); ?>:</strong><br>
                    <?php echo htmlspecialchars($transfer['to_location_name']); ?>
                </div>
            </div>
            <?php if ($transfer['vehicle_number'] || $transfer['driver_name']): ?>
            <hr>
            <div class="row">
                <div class="col-md-4">
                    <strong><?php _e('vehicle_number'); ?>:</strong> <?php echo htmlspecialchars($transfer['vehicle_number'] ?: '-'); ?>
                </div>
                <div class="col-md-4">
                    <strong><?php _e('driver_name'); ?>:</strong> <?php echo htmlspecialchars($transfer['driver_name'] ?: '-'); ?>
                </div>
                <div class="col-md-4">
                    <strong><?php _e('driver_phone'); ?>:</strong> <?php echo htmlspecialchars($transfer['driver_phone'] ?: '-'); ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Receive Form -->
    <div class="card">
        <div class="card-header">
            <h3><?php _e('transfer_items'); ?></h3>
            <button type="button" class="btn btn-sm btn-info" onclick="receiveAll()">
                <i class="fas fa-check-double"></i> <?php _e('receive_all'); ?>
            </button>
        </div>
        <div class="card-body">
            <form id="receiveForm">
                <input type="hidden" name="transfer_id" value="<?php echo $transferId; ?>">

                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th style="width: 40px;">#</th>
                            <th><?php _e('product'); ?></th>
                            <th style="width: 80px;"><?php _e('unit'); ?></th>
                            <th style="width: 120px;" class="text-right"><?php _e('dispatched_qty'); ?></th>
                            <th style="width: 150px;" class="text-center"><?php _e('received_qty'); ?></th>
                            <th style="width: 120px;" class="text-right"><?php _e('shortage_qty'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $sn = 0;
                        foreach ($items as $item):
                            $sn++;
                            $dispatched = floatval($item['dispatched_quantity']);
                        ?>
                        <tr>
                            <td class="text-center"><?php echo $sn; ?></td>
                            <td>
                                <?php echo htmlspecialchars($item['product_name']); ?>
                                <?php if ($item['barcode']): ?>
                                <br><small class="text-muted"><?php echo htmlspecialchars($item['barcode']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="text-center"><?php echo htmlspecialchars($item['unit']); ?></td>
                            <td class="text-right dispatched-qty"><?php echo formatNumber($dispatched); ?></td>
                            <td class="text-center">
                                <input type="number"
                                       class="form-control received-qty-input text-center"
                                       name="items[<?php echo $item['id']; ?>]"
                                       data-detail-id="<?php echo $item['id']; ?>"
                                       data-dispatched="<?php echo $dispatched; ?>"
                                       value="<?php echo $dispatched; ?>"
                                       min="0"
                                       max="<?php echo $dispatched; ?>"
                                       step="0.01"
                                       required>
                            </td>
                            <td class="text-right shortage-qty">0.00</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="3"><?php _e('total'); ?></th>
                            <th class="text-right" id="totalDispatched">0.00</th>
                            <th class="text-center" id="totalReceived">0.00</th>
                            <th class="text-right" id="totalShortage">0.00</th>
                        </tr>
                    </tfoot>
                </table>

                <div class="form-group">
                    <label for="receiveRemarks"><?php _e('remarks'); ?></label>
                    <textarea id="receiveRemarks" name="receive_remarks" class="form-control" rows="2"
                              placeholder="<?php _e('enter_remarks'); ?>"></textarea>
                </div>

                <!-- E-Signature: Received By -->
                <style>
                    .signature-wrapper { border: 1px dashed #adb5bd; border-radius: 4px; background: #fafafa; position: relative; max-width: 420px; }
                    .signature-canvas { display: block; width: 100%; height: 140px; touch-action: none; cursor: crosshair; }
                    .signature-placeholder { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: #adb5bd; font-style: italic; pointer-events: none; user-select: none; font-size: 13px; }
                </style>
                <div class="form-group">
                    <label class="fw-bold"><?php _e('received_by'); ?> <small class="text-muted">(optional e-signature)</small></label>
                    <?php $myMasterSig = getUserSignature($conn, getUserId()); ?>
                    <?php if ($myMasterSig): ?>
                    <div class="border rounded p-2 bg-light" style="max-width: 420px;">
                        <img src="<?php echo htmlspecialchars($myMasterSig); ?>" alt="<?php _e('signature'); ?>" style="max-height: 90px; max-width: 100%;">
                        <div class="text-muted small mt-1"><i class="bi bi-info-circle"></i> <?php _e('signature_from_profile'); ?></div>
                    </div>
                    <?php else: ?>
                    <div class="signature-wrapper">
                        <canvas id="receivedBySignature" class="signature-canvas"></canvas>
                        <span class="signature-placeholder" id="receivedBySignaturePlaceholder">Sign here</span>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-secondary mt-1" onclick="clearReceivedSig()">
                        <i class="fas fa-times"></i> <?php _e('clear'); ?>
                    </button>
                    <?php
                    /* Not everyone has a touchscreen. Offer an image upload as an alternative
                       to drawing; it is loaded INTO the pad above, so the submit code is
                       unchanged. Only reachable in this else-branch, i.e. only for signers
                       with no signature on file. */
                    $sigUploadCanvasId      = 'receivedBySignature';
                    $sigUploadPlaceholderId = 'receivedBySignaturePlaceholder';
                    require __DIR__ . '/../../includes/signature_upload_control.php';
                    ?>
                    <?php endif; ?>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-success btn-lg" id="submitBtn">
                        <i class="fas fa-check"></i> <?php _e('confirm_receive'); ?>
                    </button>
                    <a href="<?php echo BASE_URL; ?>modules/transactions/transfers_management.php" class="btn btn-secondary btn-lg">
                        <?php _e('cancel'); ?>
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="<?php echo BASE_URL; ?>assets/js/signature_pad.js"></script>
<script>
let receivedSigPad = null;

function clearReceivedSig() {
    if (receivedSigPad) {
        receivedSigPad.clear();
        const ph = document.getElementById('receivedBySignaturePlaceholder');
        if (ph) ph.style.display = '';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('receiveForm');
    const receivedInputs = document.querySelectorAll('.received-qty-input');

    // Initialize received-by signature pad
    const sigCanvas = document.getElementById('receivedBySignature');
    if (sigCanvas && window.SignaturePad) {
        receivedSigPad = new SignaturePad(sigCanvas, { penColor: '#000', lineWidth: 2 });
        sigCanvas.addEventListener('pointerdown', function() {
            const ph = document.getElementById('receivedBySignaturePlaceholder');
            if (ph) ph.style.display = 'none';
        });
    }

    // Calculate shortage on input change
    receivedInputs.forEach(input => {
        input.addEventListener('input', function() {
            const dispatched = parseFloat(this.dataset.dispatched) || 0;
            let received = parseFloat(this.value) || 0;

            // Validate max
            if (received > dispatched) {
                received = dispatched;
                this.value = received;
            }
            if (received < 0) {
                received = 0;
                this.value = 0;
            }

            const shortage = dispatched - received;
            const row = this.closest('tr');
            row.querySelector('.shortage-qty').textContent = shortage.toFixed(2);

            // Highlight if shortage
            if (shortage > 0) {
                row.classList.add('table-warning');
            } else {
                row.classList.remove('table-warning');
            }

            updateTotals();
        });
    });

    function updateTotals() {
        let totalDispatched = 0;
        let totalReceived = 0;
        let totalShortage = 0;

        receivedInputs.forEach(input => {
            const dispatched = parseFloat(input.dataset.dispatched) || 0;
            const received = parseFloat(input.value) || 0;
            const shortage = dispatched - received;

            totalDispatched += dispatched;
            totalReceived += received;
            totalShortage += shortage;
        });

        document.getElementById('totalDispatched').textContent = totalDispatched.toFixed(2);
        document.getElementById('totalReceived').textContent = totalReceived.toFixed(2);
        document.getElementById('totalShortage').textContent = totalShortage.toFixed(2);
    }

    // Initial calculation
    updateTotals();

    // Form submit
    form.addEventListener('submit', async function(e) {
        e.preventDefault();

        const submitBtn = document.getElementById('submitBtn');
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <?php _e('processing'); ?>...';

        // Build items array
        const items = [];
        receivedInputs.forEach(input => {
            items.push({
                detail_id: parseInt(input.dataset.detailId),
                received_quantity: parseFloat(input.value) || 0
            });
        });

        const receivedSig = (receivedSigPad && !receivedSigPad.isEmpty()) ? receivedSigPad.toDataURL() : null;

        const data = {
            action: 'receive',
            transfer_id: <?php echo $transferId; ?>,
            received_by_signature: receivedSig,
            items: items
        };

        try {
            const response = await fetch('<?php echo BASE_URL; ?>api/transfers-sync.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });

            const result = await response.json();

            if (result.success) {
                alert(result.message || '<?php _e('transfer_received'); ?>');
                window.location.href = '<?php echo BASE_URL; ?>modules/transactions/transfers_management.php';
            } else {
                alert(result.error || result.message || '<?php _e('error_occurred'); ?>');
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-check"></i> <?php _e('confirm_receive'); ?>';
            }
        } catch (error) {
            console.error('Error:', error);
            alert('<?php _e('error_occurred'); ?>');
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-check"></i> <?php _e('confirm_receive'); ?>';
        }
    });
});

function receiveAll() {
    document.querySelectorAll('.received-qty-input').forEach(input => {
        input.value = input.dataset.dispatched;
        input.dispatchEvent(new Event('input'));
    });
}
</script>

<?php include '../../includes/footer.php'; ?>
