<?php
$pageTitle = 'Stock Taking Report';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

if (!hasPermission('stock_taking', 'view')) {
    setAlert('You do not have permission to access this page', 'danger');
    redirect('');
}

// Handle Delete
if (isset($_GET['delete']) && hasPermission('stock_taking', 'delete')) {
    $deleteId = (int)$_GET['delete'];
    
    try {
        $conn->beginTransaction();
        
        // Get stock taking info for audit
        $stmt = $conn->prepare("SELECT stock_taking_number FROM stock_taking WHERE id = ?");
        $stmt->execute([$deleteId]);
        $stockTaking = $stmt->fetch();
        
        if ($stockTaking) {
            // Delete details first (CASCADE should handle this, but being explicit)
            $stmt = $conn->prepare("DELETE FROM stock_taking_details WHERE taking_id = ?");
            $stmt->execute([$deleteId]);
            
            // Delete header
            $stmt = $conn->prepare("DELETE FROM stock_taking WHERE id = ?");
            $stmt->execute([$deleteId]);
            
            $conn->commit();
            
            logAudit('stock_taking', 'delete', $deleteId);
            setAlert("Stock taking {$stockTaking['stock_taking_number']} deleted successfully", 'success');
            redirect('modules/reports/stock_taking_list.php');
        } else {
            setAlert('Stock taking not found', 'danger');
            redirect('modules/reports/stock_taking_list.php');
        }
    } catch (PDOException $e) {
        $conn->rollBack();
        setAlert('Error deleting stock taking: ' . $e->getMessage(), 'danger');
        redirect('modules/reports/stock_taking_list.php');
    }
}

$id = $_GET['id'] ?? 0;

// Get stock taking header
$stmt = $conn->prepare("
    SELECT
        st.*,
        l.name as location_name,
        u.name as created_by_name,
        vu.name as verified_by_name,
        au.name as approved_by_name
    FROM stock_taking st
    LEFT JOIN locations l ON st.location_id = l.id
    LEFT JOIN users u ON st.created_by = u.id
    LEFT JOIN users vu ON st.verified_by = vu.id
    LEFT JOIN users au ON st.approved_by = au.id
    WHERE st.id = ?
");
$stmt->execute([$id]);
$taking = $stmt->fetch();

if (!$taking) {
    setAlert('Stock taking not found', 'danger');
    redirect('modules/reports/stock_taking_list.php');
}

// Get stock taking details
$detailsStmt = $conn->prepare("
    SELECT 
        std.*,
        p.name as product_name,
        p.unit,
        pc.name as category_name
    FROM stock_taking_details std
    JOIN products p ON std.product_id = p.id
    LEFT JOIN product_categories pc ON p.category_id = pc.id
    WHERE std.taking_id = ?
    ORDER BY pc.name, p.name
");
$detailsStmt->execute([$id]);
$details = $detailsStmt->fetchAll();

require_once '../../includes/header.php';

// Calculate totals
$totalOpening = 0;
$totalPurchases = 0;
$totalSales = 0;
$totalClosing = 0;
$totalPhysical = 0;
$totalVariance = 0;
$totalVarianceAmount = 0;
$productsWithVariance = 0;
$hasVariance = false;

foreach ($details as $detail) {
    $totalOpening += $detail['system_opening'];
    $totalPurchases += $detail['system_purchases'];
    $totalSales += $detail['system_sales'];
    $totalClosing += $detail['system_closing'];
    $totalPhysical += $detail['physical_stock'];
    $totalVariance += $detail['variance_qty'];
    $totalVarianceAmount += $detail['variance_amount'];
    
    if (abs($detail['variance_qty']) > 0.01) {
        $hasVariance = true;
        $productsWithVariance++;
    }
}
?>

<div class="row justify-content-center">
    <div class="col-md-12 col-lg-11">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center no-print">
                <h5 class="mb-0">
                    <i class="bi bi-clipboard-check"></i> Stock Taking Report
                </h5>
                <div>
                    <button onclick="window.print()" class="btn btn-sm btn-secondary">
                        <i class="bi bi-printer"></i> Print
                    </button>
                    <button onclick="exportTableToExcel()" class="btn btn-sm btn-success">
                        <i class="bi bi-file-earmark-excel"></i> Export Excel
                    </button>
                    <?php if (hasPermission('stock_taking', 'delete')): ?>
                    <button onclick="confirmDelete(<?php echo $id; ?>)" class="btn btn-sm btn-danger">
                        <i class="bi bi-trash"></i> Delete
                    </button>
                    <?php endif; ?>
                    <a href="stock_taking.php" class="btn btn-sm btn-primary">
                        <i class="bi bi-plus"></i> New
                    </a>
                    <a href="stock_taking_list.php" class="btn btn-sm btn-secondary">
                        <i class="bi bi-list"></i> List
                    </a>
                </div>
            </div>
            <div class="card-body">
                <!-- Header Info -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <h4 class="text-primary mb-3"><?php echo APP_NAME; ?></h4>
                        <table class="table table-sm table-borderless">
                            <tr>
                                <td width="40%"><strong>Stock Taking #:</strong></td>
                                <td><?php echo htmlspecialchars($taking['stock_taking_number']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Date:</strong></td>
                                <td><?php echo formatDate($taking['stock_date']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Location:</strong></td>
                                <td><?php echo htmlspecialchars($taking['location_name']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Calculation Method:</strong></td>
                                <td>
                                    <span class="badge bg-info"><?php echo $taking['calculation_method']; ?></span>
                                </td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6 text-end">
                        <table class="table table-sm table-borderless">
                            <tr>
                                <td width="40%"><strong>Created By:</strong></td>
                                <td><?php echo htmlspecialchars($taking['created_by_name']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Created At:</strong></td>
                                <td><?php echo formatDateTime($taking['created_at']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Status:</strong></td>
                                <td>
                                    <span class="badge bg-success">Completed</span>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <hr>
                
                <!-- Summary Cards - REORDERED -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card bg-light">
                            <div class="card-body text-center">
                                <small class="text-muted">TOTAL VARIANCE (PRODUCTS)</small>
                                <h4 class="mb-0 <?php echo $productsWithVariance == 0 ? 'text-success' : 'text-warning'; ?>">
                                    <?php echo $productsWithVariance; ?>
                                </h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-light">
                            <div class="card-body text-center">
                                <small class="text-muted">TOTAL VARIANCE (AMOUNT)</small>
                                <h4 class="mb-0 <?php echo $totalVarianceAmount == 0 ? 'text-success' : ($totalVarianceAmount > 0 ? 'text-info' : 'text-danger'); ?>">
                                    <?php echo formatCurrency($totalVarianceAmount); ?>
                                </h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-light">
                            <div class="card-body text-center">
                                <small class="text-muted">EXPECTED SALES</small>
                                <h4 class="mb-0"><?php echo formatCurrency($taking['expected_sales_amount']); ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-light">
                            <div class="card-body text-center">
                                <small class="text-muted">CASH VARIANCE</small>
                                <h4 class="mb-0 <?php echo $taking['cash_variance'] == 0 ? 'text-success' : ($taking['cash_variance'] > 0 ? 'text-info' : 'text-danger'); ?>">
                                    <?php echo formatCurrency($taking['cash_variance']); ?>
                                </h4>
                            </div>
                        </div>
                    </div>
                </div>
                
<?php if (false): // Cash Reconciliation — temporarily hidden. Remove this if/endif wrapper to restore. ?>
                <!-- Cash Reconciliation -->
                <div class="card mb-4 border-info">
                    <div class="card-header bg-info text-white">
                        <h6 class="mb-0"><i class="bi bi-cash-stack"></i> Cash Reconciliation</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-2">
                                    <small class="text-muted">Expected Sales Amount (System):</small>
                                    <h5><?php echo formatCurrency($taking['expected_sales_amount']); ?></h5>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-2">
                                    <small class="text-muted">Actual Cash Counted:</small>
                                    <h5><?php echo formatCurrency($taking['actual_cash_amount']); ?></h5>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-2">
                                    <small class="text-muted">Cash Variance:</small>
                                    <h5 class="<?php echo $taking['cash_variance'] == 0 ? 'text-success' : ($taking['cash_variance'] > 0 ? 'text-info' : 'text-danger'); ?>">
                                        <?php echo formatCurrency($taking['cash_variance']); ?>
                                        <?php if ($taking['cash_variance'] > 0): ?>
                                            <small>(Surplus)</small>
                                        <?php elseif ($taking['cash_variance'] < 0): ?>
                                            <small>(Shortage)</small>
                                        <?php else: ?>
                                            <small>(Perfect Match)</small>
                                        <?php endif; ?>
                                    </h5>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
<?php endif; ?>

                <!-- Stock Variance Details -->
                <h6 class="text-muted mb-3">STOCK VARIANCE DETAILS</h6>
                <div class="table-responsive">
                    <table class="table table-bordered table-sm" id="stockTakingTable">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 3%;">Sr.</th>
                                <th style="width: 18%;">Product</th>
                                <th style="width: 10%;">Category</th>
                                <th style="width: 7%;">Unit</th>
                                <th style="width: 8%;" class="text-end">Opening</th>
                                <th style="width: 8%;" class="text-end">Purchase</th>
                                <th style="width: 8%;" class="text-end">Sales</th>
                                <th style="width: 8%;" class="text-end">Closing</th>
                                <th style="width: 8%;" class="text-end">Physical</th>
                                <th style="width: 7%;" class="text-end">Variance</th>
                                <th style="width: 15%;" class="text-end">Variance Amt</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $rowNum = 0;
                            $currentCategory = '';
                            foreach ($details as $detail): 
                                $rowNum++;
                                
                                // Category header
                                if ($currentCategory != $detail['category_name']):
                                    $currentCategory = $detail['category_name'];
                            ?>
                            <tr class="table-secondary">
                                <td colspan="11">
                                    <strong><i class="bi bi-tag"></i> <?php echo htmlspecialchars($currentCategory ?: 'Uncategorized'); ?></strong>
                                </td>
                            </tr>
                            <?php endif; ?>
                            
                            <?php
                            $variance = $detail['variance_qty'];
                            $varianceAmt = $detail['variance_amount'];
                            $rowClass = '';
                            if (abs($variance) > 0.01) {
                                $rowClass = $variance > 0 ? 'table-info' : 'table-danger';
                            }
                            ?>
                            <tr class="<?php echo $rowClass; ?>">
                                <td><?php echo $rowNum; ?></td>
                                <td><strong><?php echo htmlspecialchars($detail['product_name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($detail['category_name'] ?: '-'); ?></td>
                                <td><?php echo htmlspecialchars($detail['unit']); ?></td>
                                <td class="text-end"><?php echo formatNumber($detail['system_opening'], 2); ?></td>
                                <td class="text-end"><?php echo formatNumber($detail['system_purchases'], 2); ?></td>
                                <td class="text-end"><?php echo formatNumber($detail['system_sales'], 2); ?></td>
                                <td class="text-end"><strong><?php echo formatNumber($detail['system_closing'], 2); ?></strong></td>
                                <td class="text-end"><strong><?php echo formatNumber($detail['physical_stock'], 2); ?></strong></td>
                                <td class="text-end">
                                    <?php 
                                    if (abs($variance) < 0.01) {
                                        echo '<span class="badge bg-success">0.00</span>';
                                    } elseif ($variance > 0) {
                                        echo '<span class="badge bg-info">+' . formatNumber($variance, 2) . '</span>';
                                    } else {
                                        echo '<span class="badge bg-danger">' . formatNumber($variance, 2) . '</span>';
                                    }
                                    ?>
                                </td>
                                <td class="text-end">
                                    <strong class="<?php echo $varianceAmt == 0 ? 'text-success' : ($varianceAmt > 0 ? 'text-info' : 'text-danger'); ?>">
                                        <?php echo formatCurrency($varianceAmt); ?>
                                    </strong>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="4" class="text-end">TOTALS:</th>
                                <th class="text-end"><?php echo formatNumber($totalOpening, 2); ?></th>
                                <th class="text-end"><?php echo formatNumber($totalPurchases, 2); ?></th>
                                <th class="text-end"><?php echo formatNumber($totalSales, 2); ?></th>
                                <th class="text-end"><?php echo formatNumber($totalClosing, 2); ?></th>
                                <th class="text-end"><?php echo formatNumber($totalPhysical, 2); ?></th>
                                <th class="text-end">
                                    <strong class="<?php echo $totalVariance == 0 ? 'text-success' : ($totalVariance > 0 ? 'text-info' : 'text-danger'); ?>">
                                        <?php echo $totalVariance > 0 ? '+' : ''; ?><?php echo formatNumber($totalVariance, 2); ?>
                                    </strong>
                                </th>
                                <th class="text-end">
                                    <strong class="<?php echo $totalVarianceAmount == 0 ? 'text-success' : ($totalVarianceAmount > 0 ? 'text-info' : 'text-danger'); ?>">
                                        <?php echo formatCurrency($totalVarianceAmount); ?>
                                    </strong>
                                </th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                
                <!-- Legend -->
                <div class="mt-4">
                    <h6 class="text-muted">LEGEND</h6>
                    <div class="row">
                        <div class="col-md-4">
                            <span class="badge bg-success">Green</span> = Perfect match (no variance)
                        </div>
                        <div class="col-md-4">
                            <span class="badge bg-info">Blue</span> = Surplus (physical &gt; closing)
                        </div>
                        <div class="col-md-4">
                            <span class="badge bg-danger">Red</span> = Shortage (physical &lt; closing)
                        </div>
                    </div>
                </div>
                
                <!-- Summary Notes -->
                <?php 
                // Check if adjustment already exists for this stock taking
                $adjCheckStmt = $conn->prepare("SELECT id, adjustment_number FROM stock_adjustments WHERE stock_taking_id = ?");
                $adjCheckStmt->execute([$id]);
                $existingAdjustment = $adjCheckStmt->fetch();
                ?>
                
                <?php if ($hasVariance || $taking['cash_variance'] != 0): ?>
                <div class="alert alert-warning mt-4">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h6><i class="bi bi-exclamation-triangle"></i> Variance Detected</h6>
                            <ul class="mb-0">
                                <?php if ($hasVariance): ?>
                                <li>Stock variance detected in <strong><?php echo $productsWithVariance; ?> product(s)</strong></li>
                                <li>Total variance amount: <strong><?php echo formatCurrency(abs($totalVarianceAmount)); ?></strong></li>
                                <?php endif; ?>
                                <?php if ($taking['cash_variance'] != 0): ?>
                                <li>Cash variance of <strong><?php echo formatCurrency(abs($taking['cash_variance'])); ?></strong> 
                                    (<?php echo $taking['cash_variance'] > 0 ? 'Surplus' : 'Shortage'; ?>)
                                </li>
                                <?php endif; ?>
                            </ul>
                        </div>
                        <?php if ($hasVariance && hasPermission('stock_adjustment', 'create')): ?>
                        <div class="ms-3">
                            <?php if ($existingAdjustment): ?>
                            <a href="../transactions/stock_adjustment_view.php?id=<?php echo $existingAdjustment['id']; ?>" 
                               class="btn btn-info">
                                <i class="bi bi-check-circle"></i> View Adjustment (<?php echo htmlspecialchars($existingAdjustment['adjustment_number']); ?>)
                            </a>
                            <?php else: ?>
                            <a href="../transactions/stock_adjustment.php?stock_taking_id=<?php echo $id; ?>" 
                               class="btn btn-primary"
                               onclick="return confirm('Create stock adjustment to correct the variance?\n\nThis will open a form pre-filled with all variance items for your review.')">
                                <i class="bi bi-sliders"></i> Create Stock Adjustment
                            </a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="alert alert-success mt-4">
                    <i class="bi bi-check-circle"></i> Perfect match! No variance detected in stock or cash.
                </div>
                <?php endif; ?>
                
                <!-- Signatures / Sign-off section -->
                <?php
                $hasPreparedSig  = !empty($taking['prepared_by_signature']);
                $hasVerifiedSig  = !empty($taking['verified_by_signature']);
                $hasApprovedSig  = !empty($taking['approved_by_signature']);

                $canVerify  = hasPermission('stock_taking', 'verify');
                $canApprove = hasPermission('stock_taking', 'approve');
                $isVerified = !empty($taking['verified_by']);
                $isApproved = !empty($taking['approved_by']);
                ?>
                <style>
                    .st-sig-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; margin-top: 40px; padding-top: 16px; border-top: 1px solid #ddd; }
                    .st-sig-box { text-align: center; padding: 10px 6px; }
                    .st-sig-box img { max-height: 80px; max-width: 100%; display: block; margin: 0 auto 4px auto; }
                    .st-sig-box .st-sig-role { font-weight: 600; border-top: 1px solid #333; padding-top: 6px; }
                    .st-sig-box .st-sig-name { font-size: 13px; }
                    .st-sig-box .st-sig-date { font-size: 11px; color: #666; }
                </style>
                <div class="st-sig-grid">
                    <!-- Prepared By -->
                    <div class="st-sig-box">
                        <?php if ($hasPreparedSig): ?>
                            <img src="<?php echo htmlspecialchars($taking['prepared_by_signature']); ?>" alt="Prepared By Signature">
                        <?php endif; ?>
                        <div class="st-sig-role">Prepared By</div>
                        <?php if (!empty($taking['created_by_name'])): ?>
                            <div class="st-sig-name"><?php echo htmlspecialchars($taking['created_by_name']); ?></div>
                            <div class="st-sig-date"><?php echo formatDateTime($taking['created_at']); ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- Verified By -->
                    <div class="st-sig-box">
                        <?php if ($hasVerifiedSig): ?>
                            <img src="<?php echo htmlspecialchars($taking['verified_by_signature']); ?>" alt="Verified By Signature">
                        <?php endif; ?>
                        <div class="st-sig-role">Verified By</div>
                        <?php if ($isVerified): ?>
                            <div class="st-sig-name"><?php echo htmlspecialchars($taking['verified_by_name'] ?: '-'); ?></div>
                            <div class="st-sig-date"><?php echo $taking['verified_at'] ? formatDateTime($taking['verified_at']) : ''; ?></div>
                        <?php elseif ($canVerify): ?>
                            <button type="button" class="btn btn-sm btn-outline-primary mt-2 no-print"
                                    onclick="openSignModal('verify')">
                                <i class="bi bi-pen"></i> Sign as Verifier
                            </button>
                        <?php endif; ?>
                    </div>

                    <!-- Approved By -->
                    <div class="st-sig-box">
                        <?php if ($hasApprovedSig): ?>
                            <img src="<?php echo htmlspecialchars($taking['approved_by_signature']); ?>" alt="Approved By Signature">
                        <?php endif; ?>
                        <div class="st-sig-role">Approved By</div>
                        <?php if ($isApproved): ?>
                            <div class="st-sig-name"><?php echo htmlspecialchars($taking['approved_by_name'] ?: '-'); ?></div>
                            <div class="st-sig-date"><?php echo $taking['approved_at'] ? formatDateTime($taking['approved_at']) : ''; ?></div>
                        <?php elseif ($canApprove): ?>
                            <?php if ($isVerified): ?>
                                <button type="button" class="btn btn-sm btn-outline-success mt-2 no-print"
                                        onclick="openSignModal('approve')">
                                    <i class="bi bi-check2-circle"></i> Sign as Approver
                                </button>
                            <?php else: ?>
                                <div class="text-muted small mt-2 no-print"><em>Awaiting verification</em></div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Sign Modal -->
                <?php $myMasterSig = getUserSignature($conn, getUserId()); ?>
                <?php if (($canVerify && !$isVerified) || ($canApprove && !$isApproved)): ?>
                <div class="modal fade no-print" id="signModal" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="signModalTitle">Sign</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <?php if ($myMasterSig): ?>
                                <div class="border rounded p-2 bg-light text-center">
                                    <img src="<?php echo htmlspecialchars($myMasterSig); ?>" alt="<?php _e('signature'); ?>" style="max-height: 110px; max-width: 100%;">
                                </div>
                                <div class="text-muted small mt-1"><i class="bi bi-info-circle"></i> <?php _e('signature_from_profile'); ?></div>
                                <?php else: ?>
                                <p class="text-muted small mb-2">Draw your signature below using mouse, touch, or stylus.</p>
                                <div style="border: 1px dashed #adb5bd; border-radius: 4px; background: #fafafa; position: relative;">
                                    <canvas id="signModalCanvas" style="display:block; width:100%; height:180px; touch-action:none; cursor:crosshair;"></canvas>
                                    <span id="signModalPlaceholder" style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); color:#adb5bd; font-style:italic; pointer-events:none; user-select:none; font-size:13px;">Sign here</span>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="clearSignModalPad()">
                                    <i class="bi bi-x-circle"></i> Clear
                                </button>
                                <?php
                                /* Not everyone has a touchscreen. Offer an image upload as an alternative
                                   to drawing; it is loaded INTO the pad above, so the submit code below is
                                   unchanged. Only reachable in this else-branch, i.e. only for signers with
                                   no signature on file. */
                                $sigUploadCanvasId      = 'signModalCanvas';
                                $sigUploadPlaceholderId = 'signModalPlaceholder';
                                require __DIR__ . '/../../includes/signature_upload_control.php';
                                ?>
                                <?php endif; ?>
                                <div id="signModalError" class="alert alert-danger mt-2" style="display:none;"></div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="button" class="btn btn-primary" id="signModalSubmit" onclick="submitSignModal()">
                                    <i class="bi bi-check-circle"></i> Confirm &amp; Save
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function confirmDelete(id) {
    if (confirm('Are you sure you want to delete this stock taking?\n\nThis action cannot be undone and will delete:\n- All product variance records\n- Cash reconciliation data\n- Complete stock taking report\n\nDo you want to proceed?')) {
        window.location.href = 'stock_taking_view.php?delete=' + id;
    }
}

function exportTableToExcel() {
    const table = document.getElementById('stockTakingTable');
    const wb = XLSX.utils.book_new();
    
    // Create worksheet from table
    const ws = XLSX.utils.table_to_sheet(table);
    
    // Add to workbook
    XLSX.utils.book_append_sheet(wb, ws, 'Stock Taking');
    
    // Generate filename
    const filename = 'Stock_Taking_<?php echo $taking['stock_taking_number']; ?>_<?php echo date('Ymd'); ?>.xlsx';
    
    // Download
    XLSX.writeFile(wb, filename);
}
</script>

<!-- Include SheetJS library for Excel export -->
<script src="https://cdn.sheetjs.com/xlsx-latest/package/dist/xlsx.full.min.js"></script>

<?php if (($canVerify && !$isVerified) || ($canApprove && !$isApproved)): ?>
<!-- Signature pad for verify/approve modal -->
<script src="<?php echo BASE_URL; ?>assets/js/signature_pad.js"></script>
<script>
let signModalPad = null;
let signModalAction = null;
let signModalEl = null;
// When the signer has a signature on file the pad isn't rendered at all —
// the server applies the stored one (see getUserSignature / migration 0041).
const HAS_MASTER_SIG = <?php echo $myMasterSig ? 'true' : 'false'; ?>;

function openSignModal(action) {
    signModalAction = action;
    if (!signModalEl) signModalEl = new bootstrap.Modal(document.getElementById('signModal'));
    document.getElementById('signModalTitle').textContent = (action === 'verify')
        ? 'Sign as Verifier'
        : 'Sign as Approver';
    document.getElementById('signModalError').style.display = 'none';
    const placeholder = document.getElementById('signModalPlaceholder');
    if (placeholder) placeholder.style.display = '';
    signModalEl.show();

    // Initialise pad on first open (canvas needs to be visible to size correctly)
    setTimeout(function() {
        const canvas = document.getElementById('signModalCanvas');
        if (canvas && window.SignaturePad) {
            // Recreate each open so the canvas size matches current modal layout
            signModalPad = new SignaturePad(canvas, { penColor: '#000', lineWidth: 2 });
            canvas.onpointerdown = function() {
                const ph = document.getElementById('signModalPlaceholder');
                if (ph) ph.style.display = 'none';
            };
        }
    }, 200);
}

function clearSignModalPad() {
    if (signModalPad) {
        signModalPad.clear();
        const ph = document.getElementById('signModalPlaceholder');
        if (ph) ph.style.display = '';
    }
}

async function submitSignModal() {
    const errBox = document.getElementById('signModalError');
    errBox.style.display = 'none';

    // Only demand a drawn signature from users who have none on file.
    if (!HAS_MASTER_SIG && (!signModalPad || signModalPad.isEmpty())) {
        errBox.textContent = 'Please sign before confirming.';
        errBox.style.display = '';
        return;
    }

    const submitBtn = document.getElementById('signModalSubmit');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';

    try {
        const response = await fetch('<?php echo BASE_URL; ?>ajax/stock_taking_sign.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: signModalAction,
                taking_id: <?php echo (int)$id; ?>,
                signature: (signModalPad && !signModalPad.isEmpty()) ? signModalPad.toDataURL() : null
            })
        });
        const result = await response.json();
        if (result.success) {
            window.location.reload();
        } else {
            errBox.textContent = result.error || 'Save failed';
            errBox.style.display = '';
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="bi bi-check-circle"></i> Confirm &amp; Save';
        }
    } catch (e) {
        errBox.textContent = 'Network error: ' + e.message;
        errBox.style.display = '';
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="bi bi-check-circle"></i> Confirm &amp; Save';
    }
}
</script>
<?php endif; ?>

<style>
@media print {
    .no-print, .btn, .navbar, .footer, .card-header .no-print {
        display: none !important;
    }
    
    .card {
        border: none !important;
        box-shadow: none !important;
    }
    
    .card-body {
        padding: 0 !important;
    }
    
    body {
        background: white !important;
    }
    
    .table {
        font-size: 10pt;
    }
    
    .badge {
        border: 1px solid #000;
        padding: 2px 6px;
    }
}

@media screen and (max-width: 768px) {
    .table-responsive {
        font-size: 0.875rem;
    }
    
    .table th, .table td {
        padding: 0.5rem 0.25rem;
    }
}
</style>

<?php require_once '../../includes/footer.php'; ?>
