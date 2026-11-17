<?php
/**
 * View Bill of Quantities (read-only) with per-line quantity breakdowns.
 */
require_once '../../config/database.php';
require_once '../../includes/functions.php';

$pageTitle = __('boq_details');

if (!hasPermission('boq', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('');
}

$id = (int)($_GET['id'] ?? 0);
if (empty($id)) {
    setAlert(__('boq_not_found'), 'danger');
    redirect('modules/transactions/boq_list.php');
}

$stmt = $conn->prepare("
    SELECT b.*,
        l.name as location_name,
        cc.name as cost_centre_name,
        cc.code as cost_centre_code,
        u.name as created_by_name
    FROM boqs b
    LEFT JOIN locations l ON b.location_id = l.id
    LEFT JOIN cost_centres cc ON b.cost_centre_id = cc.id
    LEFT JOIN users u ON b.created_by = u.id
    WHERE b.id = ?
");
$stmt->execute([$id]);
$boq = $stmt->fetch();

if (!$boq) {
    setAlert(__('boq_not_found'), 'danger');
    redirect('modules/transactions/boq_list.php');
}

$detailsStmt = $conn->prepare("
    SELECT bd.*, p.name as product_name, p.unit
    FROM boq_details bd
    JOIN products p ON bd.product_id = p.id
    WHERE bd.boq_id = ?
    ORDER BY bd.id
");
$detailsStmt->execute([$id]);
$details = $detailsStmt->fetchAll();

// Breakdowns grouped by detail id
$bdStmt = $conn->prepare("
    SELECT bb.*
    FROM boq_detail_breakdowns bb
    INNER JOIN boq_details bd ON bb.boq_detail_id = bd.id
    WHERE bd.boq_id = ?
    ORDER BY bb.boq_detail_id, bb.sort_order, bb.id
");
$bdStmt->execute([$id]);
$breakdownsByDetail = [];
foreach ($bdStmt->fetchAll() as $bd) {
    $breakdownsByDetail[$bd['boq_detail_id']][] = $bd;
}

// Approval workflow (migration 0051). Pre-migration rows carry no status and
// the page renders exactly as before.
$hasApproval = boqApprovalAvailable($conn);
$status = $hasApproval ? $boq['status'] : null;
$approvedByName = $rejectedByName = null;
if ($hasApproval) {
    $nameStmt = $conn->prepare("SELECT name FROM users WHERE id = ?");
    if (!empty($boq['approved_by'])) {
        $nameStmt->execute([(int)$boq['approved_by']]);
        $approvedByName = $nameStmt->fetchColumn() ?: null;
    }
    if (!empty($boq['rejected_by'])) {
        $nameStmt->execute([(int)$boq['rejected_by']]);
        $rejectedByName = $nameStmt->fetchColumn() ?: null;
    }
}
$canApprove = $status === 'pending_approval' && hasPermission('boq', 'approve');
$canEditBoq = boqCanEdit($boq);
$canSendBoq = boqCanSendForApproval($boq);
$canReviseBoq = boqCanRevise($boq);

// While a revision is open: approved vs revised quantity per product, listing
// only the products that differ, including any added or removed.
$approvedQty = $hasApproval ? boqSnapshotQuantities($boq['approved_snapshot'] ?? null) : [];
$revisionOpen = !empty($approvedQty);
$revisionRows = [];
if ($revisionOpen) {
    $revisedQty = [];
    $productInfo = [];
    foreach ($details as $d) {
        $pid = (int)$d['product_id'];
        $revisedQty[$pid] = ($revisedQty[$pid] ?? 0) + (float)$d['total_quantity'];
        $productInfo[$pid] = ['name' => $d['product_name'], 'unit' => $d['unit']];
    }
    $missing = array_diff(array_keys($approvedQty), array_keys($productInfo));
    if (!empty($missing)) {
        $in = implode(',', array_map('intval', $missing));
        foreach ($conn->query("SELECT id, name, unit FROM products WHERE id IN ($in)") as $p) {
            $productInfo[(int)$p['id']] = ['name' => $p['name'], 'unit' => $p['unit']];
        }
    }
    foreach ($approvedQty + $revisedQty as $pid => $unused) {
        $old = $approvedQty[$pid] ?? null;
        $new = $revisedQty[$pid] ?? null;
        if ($old !== null && $new !== null && abs($old - $new) <= 0.0005) {
            continue;
        }
        $revisionRows[] = [
            'name' => $productInfo[$pid]['name'] ?? ('#' . $pid),
            'unit' => $productInfo[$pid]['unit'] ?? '',
            'old' => $old,
            'new' => $new,
            'diff' => (float)$new - (float)$old,
        ];
    }
}

require_once '../../includes/header.php';

$statusBadge = function ($s) {
    switch ($s) {
        case 'pending_approval': return 'warning';
        case 'approved': return 'success';
        case 'rejected': return 'danger';
        default: return 'secondary';
    }
};
?>

<div class="row justify-content-center">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="bi bi-rulers"></i> <?php echo htmlspecialchars($boq['boq_number']); ?>
                </h5>
                <div>
                    <a href="boq_list.php" class="btn btn-sm btn-secondary">
                        <i class="bi bi-list"></i> <?php _e('list'); ?>
                    </a>
                    <?php if ($canEditBoq): ?>
                    <a href="boq.php?id=<?php echo $boq['id']; ?>" class="btn btn-sm btn-warning">
                        <i class="bi bi-pencil"></i> <?php _e('edit'); ?>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="text-muted small"><?php _e('boq_number'); ?></div>
                        <div class="fw-bold"><?php echo htmlspecialchars($boq['boq_number']); ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small"><?php _e('date'); ?></div>
                        <div class="fw-bold"><?php echo formatDate($boq['boq_date']); ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small"><?php _e('location'); ?></div>
                        <div class="fw-bold"><?php echo htmlspecialchars($boq['location_name']); ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small"><?php _e('cost_centre'); ?></div>
                        <div class="fw-bold">
                            <?php if ($boq['cost_centre_name']): ?>
                                <small><?php echo htmlspecialchars($boq['cost_centre_code']); ?></small> -
                                <?php echo htmlspecialchars($boq['cost_centre_name']); ?>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if ($hasApproval): ?>
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="text-muted small"><?php _e('status'); ?></div>
                        <div><span class="badge bg-<?php echo $statusBadge($status); ?>"><?php _e('status_' . $status); ?></span></div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($status === 'rejected' && !empty($boq['reject_reason'])): ?>
                <div class="alert alert-danger">
                    <strong><?php _e('reject_reason'); ?>:</strong> <?php echo nl2br(htmlspecialchars($boq['reject_reason'])); ?>
                </div>
                <?php endif; ?>
                <?php if ($status === 'approved' && !empty($boq['approve_remark'])): ?>
                <div class="alert alert-success">
                    <strong><?php _e('remarks'); ?>:</strong> <?php echo nl2br(htmlspecialchars($boq['approve_remark'])); ?>
                </div>
                <?php endif; ?>

                <?php if ($revisionOpen): ?>
                <div class="border border-warning rounded p-3 mb-4">
                    <h6 class="mb-1"><i class="bi bi-arrow-left-right"></i> <?php _e('boq_revision_title'); ?></h6>
                    <div class="small text-muted mb-2"><?php _e('boq_revision_hint'); ?></div>
                    <?php if (empty($revisionRows)): ?>
                    <div class="small"><?php _e('boq_revision_no_changes'); ?></div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th><?php _e('product'); ?></th>
                                    <th><?php _e('unit'); ?></th>
                                    <th class="text-end"><?php _e('boq_approved_qty'); ?></th>
                                    <th class="text-end"><?php _e('boq_revised_qty'); ?></th>
                                    <th class="text-end"><?php _e('boq_qty_difference'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($revisionRows as $r): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($r['name']); ?></td>
                                    <td><?php echo htmlspecialchars($r['unit']); ?></td>
                                    <td class="text-end"><?php echo $r['old'] === null ? '-' : formatNumber($r['old'], 3); ?></td>
                                    <td class="text-end fw-bold"><?php echo $r['new'] === null ? '-' : formatNumber($r['new'], 3); ?></td>
                                    <td class="text-end <?php echo $r['diff'] < 0 ? 'text-danger' : 'text-success'; ?>"><?php echo ($r['diff'] > 0 ? '+' : '') . formatNumber($r['diff'], 3); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($boq['remarks'])): ?>
                <div class="mb-4">
                    <div class="text-muted small"><?php _e('remarks'); ?></div>
                    <div><?php echo nl2br(htmlspecialchars($boq['remarks'])); ?></div>
                </div>
                <?php endif; ?>

                <h6 class="text-muted mb-3"><?php _e('boq_items'); ?></h6>

                <div class="table-responsive">
                    <table class="table table-bordered table-sm align-middle">
                        <thead class="table-light">
                            <tr>
                                <th style="width:4%;"><?php _e('sr'); ?></th>
                                <th style="width:30%;"><?php _e('product'); ?></th>
                                <th style="width:8%;"><?php _e('unit'); ?></th>
                                <th style="width:12%;" class="text-end"><?php _e('total_quantity'); ?></th>
                                <th style="width:30%;"><?php _e('breakdown'); ?></th>
                                <th style="width:16%;"><?php _e('remarks'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($details as $index => $d): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><?php echo htmlspecialchars($d['product_name']); ?></td>
                                <td><?php echo htmlspecialchars($d['unit']); ?></td>
                                <td class="text-end"><?php echo formatNumber($d['total_quantity'], 3); ?></td>
                                <td>
                                    <?php $bks = $breakdownsByDetail[$d['id']] ?? []; ?>
                                    <?php if (empty($bks)): ?>
                                        <span class="text-muted">-</span>
                                    <?php else: ?>
                                        <table class="table table-sm table-borderless mb-0">
                                            <?php foreach ($bks as $b): ?>
                                            <tr>
                                                <td class="py-0"><?php echo htmlspecialchars($b['label']); ?></td>
                                                <td class="py-0 text-end"><?php echo formatNumber($b['quantity'], 3); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </table>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo nl2br(htmlspecialchars($d['remarks'] ?? '')); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Status actions (migration 0051) -->
                <?php if ($status === 'draft' && ($canSendBoq || $canEditBoq)): ?>
                <div class="mt-4 no-print">
                    <?php if ($canSendBoq): ?>
                    <button type="button" class="btn btn-warning" onclick="sendBoqForApproval(<?php echo $id; ?>)"><i class="bi bi-send"></i> <?php _e('send_for_approval'); ?></button>
                    <?php endif; ?>
                    <?php if ($canEditBoq): ?>
                    <a href="boq.php?id=<?php echo $id; ?>" class="btn btn-primary"><i class="bi bi-pencil"></i> <?php _e('edit'); ?></a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($status === 'pending_approval'): ?>
                <div class="mt-4 no-print">
                    <?php if ($canApprove): ?>
                    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#approveBoqModal"><i class="bi bi-check-circle"></i> <?php _e('approve'); ?></button>
                    <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#rejectBoqModal"><i class="bi bi-x-circle"></i> <?php _e('reject'); ?></button>
                    <?php else: ?>
                    <div class="alert alert-info mb-0"><i class="bi bi-hourglass-split"></i> <?php _e('status_pending_approval'); ?></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($canReviseBoq): ?>
                <div class="mt-4 no-print">
                    <button type="button" class="btn btn-outline-primary" onclick="reviseBoq(<?php echo $id; ?>)"><i class="bi bi-arrow-counterclockwise"></i> <?php _e('boq_revise'); ?></button>
                    <div class="small text-muted mt-2"><i class="bi bi-info-circle"></i> <?php _e($status === 'approved' ? 'boq_revise_approved_hint' : 'boq_revise_rejected_hint'); ?></div>
                </div>
                <?php endif; ?>

                <div class="text-muted small mt-3">
                    <?php _e('created_by'); ?>: <?php echo htmlspecialchars($boq['created_by_name'] ?? '-'); ?>
                    &middot; <?php echo formatDate($boq['created_at']); ?>
                    <?php if ($approvedByName): ?>
                    &nbsp;|&nbsp; <?php _e('approved_by'); ?>: <?php echo htmlspecialchars($approvedByName); ?> &middot; <?php echo formatDateTime($boq['approved_at']); ?>
                    <?php elseif ($status === 'approved' && empty($boq['approved_at'])): ?>
                    &nbsp;|&nbsp; <?php _e('approved_by'); ?>: <?php _e('auto_approved'); ?>
                    <?php endif; ?>
                    <?php if ($status === 'rejected' && $rejectedByName): ?>
                    &nbsp;|&nbsp; <?php _e('boq_rejected_by'); ?>: <?php echo htmlspecialchars($rejectedByName); ?> &middot; <?php echo formatDateTime($boq['rejected_at']); ?>
                    <?php endif; ?>
                </div>

                <?php if ($status === 'approved' && !empty($boq['approved_by_signature'])): ?>
                <style>
                    .doc-sig-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 40px; padding-top: 16px; border-top: 1px solid #ddd; }
                    .doc-sig-box { text-align: center; padding: 10px 6px; }
                    .doc-sig-box img { max-height: 80px; max-width: 100%; display: block; margin: 0 auto 4px auto; }
                    .doc-sig-box .doc-sig-role { font-weight: 600; border-top: 1px solid #333; padding-top: 6px; }
                    .doc-sig-box .doc-sig-name { font-size: 13px; }
                    .doc-sig-box .doc-sig-date { font-size: 11px; color: #666; }
                </style>
                <div class="doc-sig-grid">
                    <div></div>
                    <div class="doc-sig-box">
                        <img src="<?php echo htmlspecialchars($boq['approved_by_signature']); ?>" alt="Approved By Signature">
                        <div class="doc-sig-role"><?php _e('approved_by'); ?></div>
                        <div class="doc-sig-name"><?php echo htmlspecialchars($approvedByName ?: '-'); ?></div>
                        <div class="doc-sig-date"><?php echo $boq['approved_at'] ? formatDateTime($boq['approved_at']) : ''; ?></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($canApprove): ?>
<!-- Approve modal. No quantity editing: breakdowns must keep summing to each
     line's total, so the approver approves or rejects. -->
<div class="modal fade" id="approveBoqModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-check-circle"></i> <?php _e('boq_approve_title'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-2"><?php _e('boq_approve_hint'); ?></p>
                <?php if ($revisionOpen): ?>
                <div class="alert alert-warning small py-2"><i class="bi bi-exclamation-triangle"></i> <?php _e('boq_approve_revision_hint'); ?></div>
                <?php endif; ?>

                <label class="form-label fw-bold"><?php _e('signature'); ?> <small class="text-muted">(<?php _e('optional'); ?>)</small></label>
                <?php $myMasterSig = getUserSignature($conn, getUserId()); ?>
                <?php if ($myMasterSig): ?>
                <div class="border rounded p-2 bg-light">
                    <img src="<?php echo htmlspecialchars($myMasterSig); ?>" alt="<?php _e('signature'); ?>" style="max-height:90px;max-width:100%;">
                    <div class="text-muted small mt-1"><i class="bi bi-info-circle"></i> <?php _e('signature_from_profile'); ?></div>
                </div>
                <?php else: ?>
                <div style="border:1px dashed #adb5bd;border-radius:4px;background:#fafafa;position:relative;">
                    <canvas id="boqApproveSig" style="display:block;width:100%;height:160px;touch-action:none;cursor:crosshair;"></canvas>
                    <span id="boqApproveSigPh" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);color:#adb5bd;font-style:italic;pointer-events:none;font-size:13px;">Sign here</span>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary mt-1" onclick="clearBoqSig()"><i class="bi bi-x-circle"></i> <?php _e('clear'); ?></button>
                <?php
                // Image upload as an alternative to drawing; loaded INTO the pad above.
                $sigUploadCanvasId      = 'boqApproveSig';
                $sigUploadPlaceholderId = 'boqApproveSigPh';
                require __DIR__ . '/../../includes/signature_upload_control.php';
                ?>
                <?php endif; ?>
                <div class="mt-3">
                    <label class="form-label fw-bold"><?php _e('remarks'); ?> <small class="text-muted">(<?php _e('optional'); ?>)</small></label>
                    <textarea id="boqApproveRemark" class="form-control" rows="2"></textarea>
                </div>
                <div id="approveBoqError" class="alert alert-danger mt-2" style="display:none;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                <button type="button" class="btn btn-success" id="approveBoqBtn" onclick="submitBoqApprove(<?php echo $id; ?>)"><i class="bi bi-check-circle"></i> <?php _e('approve'); ?></button>
            </div>
        </div>
    </div>
</div>

<!-- Reject modal -->
<div class="modal fade" id="rejectBoqModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-x-circle text-danger"></i> <?php _e('boq_reject_title'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label"><?php _e('reject_reason'); ?> <span class="text-danger">*</span></label>
                <textarea id="boqRejectReason" class="form-control" rows="3"></textarea>
                <div id="rejectBoqError" class="alert alert-danger mt-2" style="display:none;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                <button type="button" class="btn btn-danger" id="rejectBoqBtn" onclick="submitBoqReject(<?php echo $id; ?>)"><i class="bi bi-x-circle"></i> <?php _e('reject'); ?></button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($hasApproval): ?>
<?php if ($canApprove): ?>
<script src="<?php echo BASE_URL; ?>assets/js/signature_pad.js"></script>
<?php endif; ?>
<script>
const BOQ_API = '<?php echo BASE_URL; ?>api/boq-sync.php';

function postBoq(payload, onOk, errBoxId, btnId, btnHtml) {
    const errBox = errBoxId ? document.getElementById(errBoxId) : null;
    const btn = btnId ? document.getElementById(btnId) : null;
    if (errBox) errBox.style.display = 'none';
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>...'; }
    fetch(BOQ_API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
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

function sendBoqForApproval(id) {
    if (!confirm(<?php echo json_encode(__('boq_confirm_send')); ?>)) return;
    postBoq({ action: 'send_for_approval', id: id }, () => location.reload());
}
function reviseBoq(id) {
    if (!confirm(<?php echo json_encode(__('boq_confirm_revise')); ?>)) return;
    postBoq({ action: 'revise', id: id }, () => location.reload());
}

<?php if ($canApprove): ?>
let boqSigPad = null;
function clearBoqSig() { if (boqSigPad) { boqSigPad.clear(); document.getElementById('boqApproveSigPh').style.display = ''; } }

document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('approveBoqModal');
    if (!modal) return;
    modal.addEventListener('shown.bs.modal', function () {
        const c = document.getElementById('boqApproveSig');
        if (c && window.SignaturePad && !boqSigPad) {
            boqSigPad = new SignaturePad(c, { penColor: '#000', lineWidth: 2 });
            c.addEventListener('pointerdown', function () { document.getElementById('boqApproveSigPh').style.display = 'none'; });
        }
    });
});

function submitBoqApprove(id) {
    const sig = (boqSigPad && !boqSigPad.isEmpty()) ? boqSigPad.toDataURL() : null;
    postBoq({
        action: 'approve',
        id: id,
        approved_by_signature: sig,
        approve_remark: document.getElementById('boqApproveRemark').value
    }, () => location.reload(), 'approveBoqError', 'approveBoqBtn', '<i class="bi bi-check-circle"></i> ' + <?php echo json_encode(__('approve')); ?>);
}
function submitBoqReject(id) {
    const reason = document.getElementById('boqRejectReason').value.trim();
    const errBox = document.getElementById('rejectBoqError');
    if (reason === '') {
        errBox.textContent = <?php echo json_encode(__('boq_reject_reason_required')); ?>;
        errBox.style.display = '';
        return;
    }
    postBoq({ action: 'reject', id: id, reject_reason: reason },
        () => location.reload(), 'rejectBoqError', 'rejectBoqBtn', '<i class="bi bi-x-circle"></i> ' + <?php echo json_encode(__('reject')); ?>);
}
<?php endif; ?>
</script>
<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>
