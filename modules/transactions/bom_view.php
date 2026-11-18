<?php
/**
 * View Bill of Materials (read-only) with per-line quantity breakdowns,
 * editable rate (avg cost), line value and a grand total.
 */
require_once '../../config/database.php';
require_once '../../includes/functions.php';

$pageTitle = __('bom_details');

if (!hasPermission('bom', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('');
}

$id = (int)($_GET['id'] ?? 0);
if (empty($id)) {
    setAlert(__('bom_not_found'), 'danger');
    redirect('modules/transactions/bom_list.php');
}

$stmt = $conn->prepare("
    SELECT b.*,
        l.name as location_name,
        cc.name as cost_centre_name,
        cc.code as cost_centre_code,
        u.name as created_by_name
    FROM boms b
    LEFT JOIN locations l ON b.location_id = l.id
    LEFT JOIN cost_centres cc ON b.cost_centre_id = cc.id
    LEFT JOIN users u ON b.created_by = u.id
    WHERE b.id = ?
");
$stmt->execute([$id]);
$bom = $stmt->fetch();

if (!$bom) {
    setAlert(__('bom_not_found'), 'danger');
    redirect('modules/transactions/bom_list.php');
}

$detailsStmt = $conn->prepare("
    SELECT bd.*, p.name as product_name, p.unit
    FROM bom_details bd
    JOIN products p ON bd.product_id = p.id
    WHERE bd.bom_id = ?
    ORDER BY bd.id
");
$detailsStmt->execute([$id]);
$details = $detailsStmt->fetchAll();

// Breakdowns grouped by detail id
$bdStmt = $conn->prepare("
    SELECT bb.*
    FROM bom_detail_breakdowns bb
    INNER JOIN bom_details bd ON bb.bom_detail_id = bd.id
    WHERE bd.bom_id = ?
    ORDER BY bb.bom_detail_id, bb.sort_order, bb.id
");
$bdStmt->execute([$id]);
$breakdownsByDetail = [];
foreach ($bdStmt->fetchAll() as $bd) {
    $breakdownsByDetail[$bd['bom_detail_id']][] = $bd;
}

$grandTotal = 0;
$qtyByProduct = [];
$reasonByProduct = [];
foreach ($details as $d) {
    $grandTotal += (float)$d['total_quantity'] * (float)$d['rate'];
    $pid = (int)$d['product_id'];
    $qtyByProduct[$pid] = ($qtyByProduct[$pid] ?? 0) + (float)$d['total_quantity'];
    if (!isset($reasonByProduct[$pid]) && trim((string)($d['boq_excess_reason'] ?? '')) !== '') {
        $reasonByProduct[$pid] = $d['boq_excess_reason'];
    }
}

// Approval workflow (migration 0049). Pre-migration rows carry no status and
// the page renders exactly as before.
$hasApproval = bomApprovalAvailable($conn);
$status = $hasApproval ? $bom['status'] : null;
$approvedByName = $rejectedByName = null;
if ($hasApproval) {
    $nameStmt = $conn->prepare("SELECT name FROM users WHERE id = ?");
    if (!empty($bom['approved_by'])) {
        $nameStmt->execute([(int)$bom['approved_by']]);
        $approvedByName = $nameStmt->fetchColumn() ?: null;
    }
    if (!empty($bom['rejected_by'])) {
        $nameStmt->execute([(int)$bom['rejected_by']]);
        $rejectedByName = $nameStmt->fetchColumn() ?: null;
    }
}
$canApprove = $status === 'pending_approval' && hasPermission('bom', 'approve');
$canEditBom = bomCanEdit($bom);
$canSendBom = bomCanSendForApproval($bom);
$canReviseBom = bomCanRevise($bom);

// BOQ link: BOQ Qty | On other BOMs | Balance per product. "On other BOMs"
// counts pending + approved BOMs on the same BOQ, never this one.
$boqInfo = null;
$boqBalances = [];
$boqOver = [];
if (bomBoqLinkAvailable($conn) && !empty($bom['boq_id'])) {
    $boqStmt = $conn->prepare("SELECT id, boq_number, title FROM boqs WHERE id = ?");
    $boqStmt->execute([(int)$bom['boq_id']]);
    $boqInfo = $boqStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($boqInfo) {
        $boqBalances = getBoqProductBalances((int)$boqInfo['id'], $conn, $id);
        $boqOver = bomBoqExcessProducts($qtyByProduct, $boqBalances);
    }
}
$boqLinked = $boqInfo !== null;

// BOM issue summary: approved BOM qty | issued from linked requisitions |
// balance, per product. Shown for an approved BOM, or one under revision that
// still holds its approved snapshot (then the approved figures are shown).
$issueSummary = bomShowsIssueSummary($bom) ? getBomIssueSummary($id, $conn) : null;
$issueFromSnapshot = $issueSummary !== null && $status !== null && $status !== 'approved';
$canViewRv = hasPermission('requisition_vouchers', 'view');

// Changes approvers made while the BOM was pending approval.
$approverChangeLog = $hasApproval ? getBomApproverChangeLog($conn, $id) : [];

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
                    <i class="bi bi-diagram-3"></i> <?php echo htmlspecialchars($bom['bom_number']); ?>
                </h5>
                <div>
                    <a href="bom_list.php" class="btn btn-sm btn-secondary">
                        <i class="bi bi-list"></i> <?php _e('list'); ?>
                    </a>
                    <?php if ($canEditBom): ?>
                    <a href="bom.php?id=<?php echo $bom['id']; ?>" class="btn btn-sm btn-warning">
                        <i class="bi bi-pencil"></i> <?php _e('edit'); ?>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="text-muted small"><?php _e('bom_number'); ?></div>
                        <div class="fw-bold"><?php echo htmlspecialchars($bom['bom_number']); ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small"><?php _e('date'); ?></div>
                        <div class="fw-bold"><?php echo formatDate($bom['bom_date']); ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small"><?php _e('location'); ?></div>
                        <div class="fw-bold"><?php echo htmlspecialchars($bom['location_name']); ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small"><?php _e('cost_centre'); ?></div>
                        <div class="fw-bold">
                            <?php if ($bom['cost_centre_name']): ?>
                                <small><?php echo htmlspecialchars($bom['cost_centre_code']); ?></small> -
                                <?php echo htmlspecialchars($bom['cost_centre_name']); ?>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if ($hasApproval || $boqLinked): ?>
                <div class="row mb-4">
                    <?php if ($hasApproval): ?>
                    <div class="col-md-3">
                        <div class="text-muted small"><?php _e('status'); ?></div>
                        <div><span class="badge bg-<?php echo $statusBadge($status); ?>"><?php _e('status_' . $status); ?></span></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($boqLinked): ?>
                    <div class="col-md-6">
                        <div class="text-muted small"><?php _e('boq'); ?></div>
                        <div class="fw-bold">
                            <?php if (hasPermission('boq', 'view')): ?>
                            <a href="boq_view.php?id=<?php echo (int)$boqInfo['id']; ?>"><?php echo htmlspecialchars($boqInfo['boq_number']); ?></a>
                            <?php else: ?>
                            <?php echo htmlspecialchars($boqInfo['boq_number']); ?>
                            <?php endif; ?>
                            <?php if (!empty($boqInfo['title'])): ?>
                            <span class="text-muted fw-normal">&mdash; <?php echo htmlspecialchars($boqInfo['title']); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($status === 'rejected' && !empty($bom['reject_reason'])): ?>
                <div class="alert alert-danger">
                    <strong><?php _e('reject_reason'); ?>:</strong> <?php echo nl2br(htmlspecialchars($bom['reject_reason'])); ?>
                </div>
                <?php endif; ?>
                <?php if ($status === 'approved' && !empty($bom['approve_remark'])): ?>
                <div class="alert alert-success">
                    <strong><?php _e('remarks'); ?>:</strong> <?php echo nl2br(htmlspecialchars($bom['approve_remark'])); ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($bom['remarks'])): ?>
                <div class="mb-4">
                    <div class="text-muted small"><?php _e('remarks'); ?></div>
                    <div><?php echo nl2br(htmlspecialchars($bom['remarks'])); ?></div>
                </div>
                <?php endif; ?>

                <h6 class="text-muted mb-3"><?php _e('bom_items'); ?></h6>
                <?php if ($boqLinked): ?>
                <div class="small text-muted mb-2"><i class="bi bi-info-circle"></i> <?php _e('bom_boq_balance_legend'); ?></div>
                <?php endif; ?>

                <div class="table-responsive">
                    <table class="table table-bordered table-sm align-middle">
                        <thead class="table-light">
                            <tr>
                                <th style="width:4%;"><?php _e('sr'); ?></th>
                                <th style="width:24%;"><?php _e('product'); ?></th>
                                <th style="width:7%;"><?php _e('unit'); ?></th>
                                <th style="width:11%;" class="text-end"><?php _e('total_quantity'); ?></th>
                                <?php if ($boqLinked): ?>
                                <th class="text-end"><?php _e('boq_qty'); ?></th>
                                <th class="text-end"><?php _e('bom_on_other_boms'); ?></th>
                                <th class="text-end"><?php _e('boq_balance'); ?></th>
                                <?php endif; ?>
                                <th style="width:11%;" class="text-end"><?php _e('bom_rate'); ?></th>
                                <th style="width:12%;" class="text-end"><?php _e('value'); ?></th>
                                <th style="width:19%;"><?php _e('breakdown'); ?></th>
                                <th style="width:12%;"><?php _e('remarks'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($details as $index => $d): ?>
                            <?php
                            $lineValue = (float)$d['total_quantity'] * (float)$d['rate'];
                            $lpid = (int)$d['product_id'];
                            $lineOver = isset($boqOver[$lpid]);
                            ?>
                            <tr<?php echo $lineOver ? ' class="table-danger"' : ''; ?>>
                                <td><?php echo $index + 1; ?></td>
                                <td><?php echo htmlspecialchars($d['product_name']); ?></td>
                                <td><?php echo htmlspecialchars($d['unit']); ?></td>
                                <td class="text-end"><?php echo formatNumber($d['total_quantity'], 3); ?></td>
                                <?php if ($boqLinked): ?>
                                <?php $lb = $boqBalances[$lpid] ?? null; ?>
                                <?php if (!$lb || empty($lb['in_boq'])): ?>
                                <td colspan="3" class="text-center">
                                    <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-circle"></i> <?php _e('bom_not_in_boq'); ?></span>
                                </td>
                                <?php else: ?>
                                <td class="text-end"><?php echo formatNumber($lb['boq_qty'], 3); ?></td>
                                <td class="text-end"><?php echo formatNumber($lb['on_other'], 3); ?></td>
                                <td class="text-end fw-bold<?php echo $lineOver ? ' text-danger' : ''; ?>"
                                    title="<?php echo $lineOver ? htmlspecialchars(sprintf(__('bom_boq_over_by'), formatNumber($boqOver[$lpid]['qty'] - $lb['balance'], 3))) : ''; ?>">
                                    <?php echo formatNumber($lb['balance'], 3); ?>
                                    <?php if ($lineOver): ?><i class="bi bi-exclamation-triangle-fill"></i><?php endif; ?>
                                </td>
                                <?php endif; ?>
                                <?php endif; ?>
                                <td class="text-end"><?php echo formatNumber($d['rate'], 2); ?></td>
                                <td class="text-end"><?php echo formatNumber($lineValue, 2); ?></td>
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
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="<?php echo $boqLinked ? 8 : 5; ?>" class="text-end"><?php _e('grand_total'); ?></th>
                                <th class="text-end"><?php echo formatNumber($grandTotal, 2); ?></th>
                                <th colspan="2"></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <?php
                // Reasons for going beyond the BOQ balance, once per product. Shown for
                // any product that carries one, even if a later approval elsewhere has
                // since moved the balance: the reason travels with the figures.
                $reasonsShown = array_intersect_key($reasonByProduct, $qtyByProduct);
                ?>
                <?php if ($boqLinked && !empty($reasonsShown)): ?>
                <div class="border border-danger rounded p-2 mb-3">
                    <div class="small fw-bold text-danger mb-1"><i class="bi bi-exclamation-triangle"></i> <?php _e('bom_boq_excess_reasons'); ?></div>
                    <?php
                    $reasonNames = [];
                    foreach ($details as $d) $reasonNames[(int)$d['product_id']] = $d['product_name'];
                    ?>
                    <?php foreach ($reasonsShown as $rpid => $reason): ?>
                    <div class="small mb-1">
                        <strong><?php echo htmlspecialchars($reasonNames[$rpid] ?? ('#' . $rpid)); ?>:</strong>
                        <?php echo nl2br(htmlspecialchars($reason)); ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if ($issueSummary !== null): ?>
                <!-- BOM issue summary (0053). Prints; the per-requisition rows do not. -->
                <h6 class="text-muted mb-2 mt-4"><i class="bi bi-box-arrow-right"></i> <?php _e('bom_issue_summary'); ?></h6>
                <?php if ($issueFromSnapshot): ?>
                <div class="alert alert-warning py-2 small"><i class="bi bi-exclamation-triangle"></i> <?php _e('bom_issue_from_snapshot'); ?></div>
                <?php endif; ?>
                <div class="small text-muted mb-2 no-print"><i class="bi bi-info-circle"></i> <?php _e('bom_issue_legend'); ?></div>
                <?php if (empty($issueSummary)): ?>
                <p class="text-muted small"><?php _e('bom_issue_none'); ?></p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-sm align-middle" id="bomIssueSummary">
                        <thead class="table-light">
                            <tr>
                                <th style="width:4%;"><?php _e('sr'); ?></th>
                                <th><?php _e('product'); ?></th>
                                <th style="width:8%;"><?php _e('unit'); ?></th>
                                <th style="width:13%;" class="text-end"><?php _e('bom_qty'); ?></th>
                                <th style="width:13%;" class="text-end"><?php _e('bom_issued_qty'); ?></th>
                                <th style="width:13%;" class="text-end"><?php _e('balance'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $isr = 0; foreach ($issueSummary as $ipid => $is):
                                $isr++;
                                $isOver = !$is['on_bom'] || $is['issued'] > $is['bom_qty'] + 0.0005;
                                $isDone = $is['on_bom'] && !$isOver && $is['issued'] >= $is['bom_qty'] - 0.0005;
                            ?>
                            <tr class="<?php echo $isOver ? 'table-danger' : ($isDone ? 'table-success' : ''); ?>">
                                <td><?php echo $isr; ?></td>
                                <td>
                                    <?php echo htmlspecialchars($is['name'] !== '' ? $is['name'] : ('#' . $ipid)); ?>
                                    <?php if (!$is['on_bom']): ?>
                                    <span class="badge bg-danger ms-1"><?php _e('bom_not_on_bom'); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($is['rvs'])): ?>
                                    <button type="button" class="btn btn-link btn-sm p-0 ms-1 no-print" data-bs-toggle="collapse" data-bs-target="#bomIssueRv<?php echo (int)$ipid; ?>" title="<?php _e('bom_issue_show_rvs'); ?>">
                                        <i class="bi bi-chevron-down"></i> <?php echo sprintf(__('bom_issue_rv_count'), count($is['rvs'])); ?>
                                    </button>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($is['unit']); ?></td>
                                <td class="text-end"><?php echo $is['on_bom'] ? formatNumber($is['bom_qty'], 3) : '-'; ?></td>
                                <td class="text-end"><?php echo formatNumber($is['issued'], 3); ?></td>
                                <td class="text-end fw-bold<?php echo $isOver ? ' text-danger' : ''; ?>">
                                    <?php echo formatNumber($is['balance'], 3); ?>
                                    <?php if ($isOver): ?><i class="bi bi-exclamation-triangle-fill" title="<?php _e('bom_issue_over'); ?>"></i><?php endif; ?>
                                </td>
                            </tr>
                            <?php if (!empty($is['rvs'])): ?>
                            <tr class="collapse no-print" id="bomIssueRv<?php echo (int)$ipid; ?>">
                                <td></td>
                                <td colspan="5" class="bg-light">
                                    <?php foreach ($is['rvs'] as $rvRow): ?>
                                    <span class="me-3 small">
                                        <?php if ($canViewRv): ?>
                                        <a href="requisition_voucher_view.php?id=<?php echo (int)$rvRow['id']; ?>"><?php echo htmlspecialchars($rvRow['number']); ?></a>
                                        <?php else: ?>
                                        <?php echo htmlspecialchars($rvRow['number']); ?>
                                        <?php endif; ?>
                                        : <?php echo formatNumber($rvRow['qty'], 3); ?>
                                    </span>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
                <?php endif; ?>

                <?php if (!empty($approverChangeLog)): ?>
                <!-- Changes approvers made while the BOM was pending. Screen only. -->
                <div class="mt-4 no-print">
                    <h6 class="text-muted mb-2"><i class="bi bi-clock-history"></i> <?php _e('bom_approver_changes'); ?></h6>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:18%;"><?php _e('date'); ?></th>
                                    <th style="width:22%;"><?php _e('edited_by'); ?></th>
                                    <th><?php _e('rv_what_changed'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($approverChangeLog as $logEntry): ?>
                                <tr>
                                    <td class="small"><?php echo formatDateTime($logEntry['created_at']); ?></td>
                                    <td class="small"><?php echo htmlspecialchars($logEntry['user_name'] ?: '-'); ?></td>
                                    <td>
                                        <ul class="mb-0 ps-3 small">
                                            <?php foreach ($logEntry['changes'] as $ch):
                                                $chLabel = htmlspecialchars((string)($ch['label'] ?? ''));
                                                $chFrom = htmlspecialchars((string)($ch['from'] ?? ''));
                                                $chTo = htmlspecialchars((string)($ch['to'] ?? ''));
                                                $wasNow = '<span class="text-danger">' . $chFrom . '</span> <i class="bi bi-arrow-right text-muted"></i> <span class="text-success">' . $chTo . '</span>';
                                                switch ($ch['type'] ?? '') {
                                                    case 'item_qty':
                                                        echo '<li><strong>' . $chLabel . '</strong> &mdash; ' . htmlspecialchars(__('quantity')) . ': ' . $wasNow . '</li>';
                                                        break;
                                                    case 'item_rate':
                                                        echo '<li><strong>' . $chLabel . '</strong> &mdash; ' . htmlspecialchars(__('rate')) . ': ' . $wasNow . '</li>';
                                                        break;
                                                    case 'item_added':
                                                        echo '<li class="text-success"><i class="bi bi-plus-circle"></i> ' . htmlspecialchars(__('rv_change_item_added')) . ': <strong>' . $chLabel . '</strong> (' . $chTo . ')</li>';
                                                        break;
                                                    case 'item_removed':
                                                        echo '<li class="text-danger"><i class="bi bi-dash-circle"></i> ' . htmlspecialchars(__('rv_change_item_removed')) . ': <strong>' . $chLabel . '</strong> (' . $chFrom . ')</li>';
                                                        break;
                                                }
                                            endforeach; ?>
                                        </ul>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Status actions (migration 0049) -->
                <?php if ($status === 'draft' && ($canSendBom || $canEditBom)): ?>
                <div class="mt-4 no-print">
                    <?php if ($canSendBom): ?>
                    <button type="button" class="btn btn-warning" onclick="sendBomForApproval(<?php echo $id; ?>)"><i class="bi bi-send"></i> <?php _e('send_for_approval'); ?></button>
                    <?php endif; ?>
                    <?php if ($canEditBom): ?>
                    <a href="bom.php?id=<?php echo $id; ?>" class="btn btn-primary"><i class="bi bi-pencil"></i> <?php _e('edit'); ?></a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($status === 'pending_approval'): ?>
                <div class="mt-4 no-print">
                    <?php if ($canApprove): ?>
                    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#approveBomModal"><i class="bi bi-check-circle"></i> <?php _e('approve'); ?></button>
                    <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#rejectBomModal"><i class="bi bi-x-circle"></i> <?php _e('reject'); ?></button>
                    <?php if ($canEditBom): ?>
                    <a href="bom.php?id=<?php echo $id; ?>" class="btn btn-outline-primary"><i class="bi bi-pencil"></i> <?php _e('bom_edit_quantities'); ?></a>
                    <div class="small text-muted mt-2"><i class="bi bi-info-circle"></i> <?php _e('bom_approver_edit_hint'); ?></div>
                    <?php endif; ?>
                    <?php else: ?>
                    <div class="alert alert-info mb-0"><i class="bi bi-hourglass-split"></i> <?php _e('status_pending_approval'); ?></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($canReviseBom): ?>
                <div class="mt-4 no-print">
                    <button type="button" class="btn btn-outline-primary" onclick="reviseBom(<?php echo $id; ?>)"><i class="bi bi-arrow-counterclockwise"></i> <?php _e('bom_revise'); ?></button>
                    <div class="small text-muted mt-2"><i class="bi bi-info-circle"></i> <?php _e($status === 'approved' ? 'bom_revise_approved_hint' : 'bom_revise_rejected_hint'); ?></div>
                </div>
                <?php endif; ?>

                <div class="text-muted small mt-3">
                    <?php _e('created_by'); ?>: <?php echo htmlspecialchars($bom['created_by_name'] ?? '-'); ?>
                    &middot; <?php echo formatDate($bom['created_at']); ?>
                    <?php if ($approvedByName): ?>
                    &nbsp;|&nbsp; <?php _e('approved_by'); ?>: <?php echo htmlspecialchars($approvedByName); ?> &middot; <?php echo formatDateTime($bom['approved_at']); ?>
                    <?php endif; ?>
                    <?php if ($status === 'rejected' && $rejectedByName): ?>
                    &nbsp;|&nbsp; <?php _e('bom_rejected_by'); ?>: <?php echo htmlspecialchars($rejectedByName); ?> &middot; <?php echo formatDateTime($bom['rejected_at']); ?>
                    <?php endif; ?>
                </div>

                <?php if ($status === 'approved' && !empty($bom['approved_by_signature'])): ?>
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
                        <img src="<?php echo htmlspecialchars($bom['approved_by_signature']); ?>" alt="Approved By Signature">
                        <div class="doc-sig-role"><?php _e('approved_by'); ?></div>
                        <div class="doc-sig-name"><?php echo htmlspecialchars($approvedByName ?: '-'); ?></div>
                        <div class="doc-sig-date"><?php echo $bom['approved_at'] ? formatDateTime($bom['approved_at']) : ''; ?></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($canApprove): ?>
<!-- Approve modal. No quantity editing (breakdowns must keep summing to each
     line's total) — the approver approves or rejects. -->
<div class="modal fade" id="approveBomModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-check-circle"></i> <?php _e('bom_approve_title'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-2"><?php _e('bom_approve_hint'); ?></p>

                <?php if ($boqLinked): ?>
                <?php
                // One row per PRODUCT, the grain the BOQ balance is checked at.
                $approveNames = [];
                $approveUnits = [];
                foreach ($details as $d) {
                    $approveNames[(int)$d['product_id']] = $d['product_name'];
                    $approveUnits[(int)$d['product_id']] = $d['unit'];
                }
                ?>
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-bordered align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th><?php _e('product'); ?></th>
                                <th class="text-end"><?php _e('bom_qty'); ?></th>
                                <th class="text-end"><?php _e('boq_qty'); ?></th>
                                <th class="text-end"><?php _e('bom_on_other_boms'); ?></th>
                                <th class="text-end"><?php _e('boq_balance'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($qtyByProduct as $apid => $aqty):
                                $ab = $boqBalances[$apid] ?? null;
                                $aOver = isset($boqOver[$apid]);
                            ?>
                            <tr<?php echo $aOver ? ' class="table-danger"' : ''; ?>>
                                <td><?php echo htmlspecialchars($approveNames[$apid] ?? ('#' . $apid)); ?> <small class="text-muted"><?php echo htmlspecialchars($approveUnits[$apid] ?? ''); ?></small></td>
                                <td class="text-end fw-bold"><?php echo formatNumber($aqty, 3); ?></td>
                                <?php if (!$ab || empty($ab['in_boq'])): ?>
                                <td colspan="3" class="text-center"><span class="badge bg-warning text-dark"><?php _e('bom_not_in_boq'); ?></span></td>
                                <?php else: ?>
                                <td class="text-end"><?php echo formatNumber($ab['boq_qty'], 3); ?></td>
                                <td class="text-end"><?php echo formatNumber($ab['on_other'], 3); ?></td>
                                <td class="text-end<?php echo $aOver ? ' text-danger fw-bold' : ''; ?>"><?php echo formatNumber($ab['balance'], 3); ?></td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php foreach ($boqOver as $apid => $o): ?>
                <div class="mb-2">
                    <label class="form-label small mb-1 text-danger fw-bold">
                        <i class="bi bi-exclamation-triangle"></i>
                        <?php echo htmlspecialchars($approveNames[$apid] ?? ('#' . $apid)); ?> &mdash;
                        <?php _e('bom_boq_excess_reason_label'); ?>
                        <span class="badge bg-danger ms-1"><?php echo htmlspecialchars(sprintf(__('bom_boq_over_by'), formatNumber($o['qty'] - $o['balance'], 3))); ?></span>
                    </label>
                    <textarea class="form-control form-control-sm bom-approve-excess" data-product-id="<?php echo (int)$apid; ?>"
                              rows="2" maxlength="1000"
                              placeholder="<?php echo htmlspecialchars(__('bom_boq_excess_reason_placeholder')); ?>"><?php echo htmlspecialchars($reasonByProduct[$apid] ?? ''); ?></textarea>
                </div>
                <?php endforeach; ?>
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
                    <canvas id="bomApproveSig" style="display:block;width:100%;height:160px;touch-action:none;cursor:crosshair;"></canvas>
                    <span id="bomApproveSigPh" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);color:#adb5bd;font-style:italic;pointer-events:none;font-size:13px;">Sign here</span>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary mt-1" onclick="clearBomSig()"><i class="bi bi-x-circle"></i> <?php _e('clear'); ?></button>
                <?php
                // Image upload as an alternative to drawing; loaded INTO the pad above.
                $sigUploadCanvasId      = 'bomApproveSig';
                $sigUploadPlaceholderId = 'bomApproveSigPh';
                require __DIR__ . '/../../includes/signature_upload_control.php';
                ?>
                <?php endif; ?>
                <div class="mt-3">
                    <label class="form-label fw-bold"><?php _e('remarks'); ?> <small class="text-muted">(<?php _e('optional'); ?>)</small></label>
                    <textarea id="bomApproveRemark" class="form-control" rows="2"></textarea>
                </div>
                <div id="approveBomError" class="alert alert-danger mt-2" style="display:none;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                <button type="button" class="btn btn-success" id="approveBomBtn" onclick="submitBomApprove(<?php echo $id; ?>)"><i class="bi bi-check-circle"></i> <?php _e('approve'); ?></button>
            </div>
        </div>
    </div>
</div>

<!-- Reject modal -->
<div class="modal fade" id="rejectBomModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-x-circle text-danger"></i> <?php _e('bom_reject_title'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label"><?php _e('reject_reason'); ?> <small class="text-muted">(<?php _e('optional'); ?>)</small></label>
                <textarea id="bomRejectReason" class="form-control" rows="3"></textarea>
                <div id="rejectBomError" class="alert alert-danger mt-2" style="display:none;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                <button type="button" class="btn btn-danger" id="rejectBomBtn" onclick="submitBomReject(<?php echo $id; ?>)"><i class="bi bi-x-circle"></i> <?php _e('reject'); ?></button>
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
const BOM_API = '<?php echo BASE_URL; ?>api/bom-sync.php';

function postBom(payload, onOk, errBoxId, btnId, btnHtml) {
    const errBox = errBoxId ? document.getElementById(errBoxId) : null;
    const btn = btnId ? document.getElementById(btnId) : null;
    if (errBox) errBox.style.display = 'none';
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>...'; }
    fetch(BOM_API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
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

function sendBomForApproval(id) {
    if (!confirm('<?php echo addslashes(__('confirm_send_for_approval')); ?>')) return;
    postBom({ action: 'send_for_approval', id: id }, () => location.reload());
}
function reviseBom(id) {
    if (!confirm('<?php echo addslashes(__('bom_confirm_revise')); ?>')) return;
    postBom({ action: 'revise', id: id }, () => location.reload());
}

<?php if ($canApprove): ?>
let bomSigPad = null;
function clearBomSig() { if (bomSigPad) { bomSigPad.clear(); document.getElementById('bomApproveSigPh').style.display = ''; } }

document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('approveBomModal');
    if (!modal) return;
    modal.addEventListener('shown.bs.modal', function () {
        const c = document.getElementById('bomApproveSig');
        if (c && window.SignaturePad && !bomSigPad) {
            bomSigPad = new SignaturePad(c, { penColor: '#000', lineWidth: 2 });
            c.addEventListener('pointerdown', function () { document.getElementById('bomApproveSigPh').style.display = 'none'; });
        }
    });
});

function submitBomApprove(id) {
    const errBox = document.getElementById('approveBomError');
    const excessRemarks = {};
    let missing = null;
    document.querySelectorAll('#approveBomModal .bom-approve-excess').forEach(ta => {
        const v = ta.value.trim();
        if (v === '' && !missing) missing = ta;
        excessRemarks[ta.dataset.productId] = v;
    });
    if (missing) {
        errBox.textContent = '<?php echo addslashes(__('bom_boq_over_needs_reason_block')); ?>';
        errBox.style.display = '';
        missing.focus();
        return;
    }
    const sig = (bomSigPad && !bomSigPad.isEmpty()) ? bomSigPad.toDataURL() : null;
    postBom({
        action: 'approve',
        id: id,
        approved_by_signature: sig,
        approve_remark: document.getElementById('bomApproveRemark').value,
        excess_remarks: excessRemarks
    }, () => location.reload(), 'approveBomError', 'approveBomBtn', '<i class="bi bi-check-circle"></i> <?php echo addslashes(__('approve')); ?>');
}
function submitBomReject(id) {
    postBom({ action: 'reject', id: id, reject_reason: document.getElementById('bomRejectReason').value },
        () => location.reload(), 'rejectBomError', 'rejectBomBtn', '<i class="bi bi-x-circle"></i> <?php echo addslashes(__('reject')); ?>');
}
<?php endif; ?>
</script>
<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>
