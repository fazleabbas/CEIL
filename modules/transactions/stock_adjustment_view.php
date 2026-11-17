<?php
require_once '../../config/database.php';
require_once '../../includes/functions.php';
$pageTitle = __('stock_adjustment_details');
if (!hasPermission('stock_adjustment', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('');
}

// Handle Delete
if (isset($_GET['delete']) && hasPermission('stock_adjustment', 'delete')) {
    $deleteId = (int)$_GET['delete'];
    
    try {
        $conn->beginTransaction();
        
        // Get adjustment info
        $stmt = $conn->prepare("SELECT adjustment_number, location_id FROM stock_adjustments WHERE id = ?");
        $stmt->execute([$deleteId]);
        $adjustment = $stmt->fetch();
        
        if ($adjustment) {
            // Reverse stock batch changes
            $detailsStmt = $conn->prepare("
                SELECT sad.*, p.name as product_name
                FROM stock_adjustment_details sad
                JOIN products p ON sad.product_id = p.id
                WHERE sad.adjustment_id = ?
            ");
            $detailsStmt->execute([$deleteId]);
            $details = $detailsStmt->fetchAll();
            
            foreach ($details as $detail) {
                if ($detail['adjustment_type'] === 'increase') {
                    // Delete the batch created for increase
                    $deleteBatchStmt = $conn->prepare("
                        DELETE FROM stock_batches 
                        WHERE transaction_type = 'adjustment' AND transaction_id = ?
                    ");
                    $deleteBatchStmt->execute([$detail['id']]);
                } else {
                    // For decrease, we need to restore quantities - this is complex
                    // For simplicity, we'll add back as a new adjustment batch
                    $restoreBatchStmt = $conn->prepare("
                        INSERT INTO stock_batches 
                        (location_id, product_id, batch_date, transaction_type, transaction_id, quantity_in, quantity_remaining, rate_per_unit)
                        SELECT ?, ?, CURDATE(), 'adjustment', NULL, ?, ?, ?
                    ");
                    $restoreBatchStmt->execute([
                        $adjustment['location_id'],
                        $detail['product_id'],
                        $detail['quantity'],
                        $detail['quantity'],
                        $detail['rate_per_unit']
                    ]);
                }
            }
            
            // Delete details (CASCADE should handle this)
            $stmt = $conn->prepare("DELETE FROM stock_adjustment_details WHERE adjustment_id = ?");
            $stmt->execute([$deleteId]);
            
            // Delete header
            $stmt = $conn->prepare("DELETE FROM stock_adjustments WHERE id = ?");
            $stmt->execute([$deleteId]);
            
            $conn->commit();

            logAudit('stock_adjustment', 'delete', $deleteId);
            setAlert(sprintf(__('stock_adjustment_deleted'), $adjustment['adjustment_number']), 'success');
            redirect('modules/transactions/stock_adjustment_list.php');
        } else {
            setAlert(__('stock_adjustment_not_found'), 'danger');
            redirect('modules/transactions/stock_adjustment_list.php');
        }
    } catch (PDOException $e) {
        $conn->rollBack();
        setAlert(sprintf(__('error_deleting_adjustment'), $e->getMessage()), 'danger');
        redirect('modules/transactions/stock_adjustment_list.php');
    }
}

$id = $_GET['id'] ?? 0;

// Get adjustment header
$stmt = $conn->prepare("
    SELECT 
        sa.*,
        l.name as location_name,
        u.name as created_by_name,
        st.stock_taking_number
    FROM stock_adjustments sa
    LEFT JOIN locations l ON sa.location_id = l.id
    LEFT JOIN users u ON sa.created_by = u.id
    LEFT JOIN stock_taking st ON sa.stock_taking_id = st.id
    WHERE sa.id = ?
");
$stmt->execute([$id]);
$adjustment = $stmt->fetch();

if (!$adjustment) {
    setAlert(__('stock_adjustment_not_found'), 'danger');
    redirect('modules/transactions/stock_adjustment_list.php');
}

// Get adjustment details
$detailsStmt = $conn->prepare("
    SELECT 
        sad.*,
        p.name as product_name,
        p.unit,
        pc.name as category_name
    FROM stock_adjustment_details sad
    JOIN products p ON sad.product_id = p.id
    LEFT JOIN product_categories pc ON p.category_id = pc.id
    WHERE sad.adjustment_id = ?
    ORDER BY pc.name, p.name
");
$detailsStmt->execute([$id]);
$details = $detailsStmt->fetchAll();

require_once '../../includes/header.php';

// Calculate totals
$totalIncrease = 0;
$totalDecrease = 0;
$increaseCount = 0;
$decreaseCount = 0;

foreach ($details as $detail) {
    if ($detail['adjustment_type'] === 'increase') {
        $totalIncrease += $detail['adjustment_amount'];
        $increaseCount++;
    } else {
        $totalDecrease += $detail['adjustment_amount'];
        $decreaseCount++;
    }
}
?>

<div class="row justify-content-center">
    <div class="col-md-12 col-lg-10">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center no-print">
                <h5 class="mb-0">
                    <i class="bi bi-sliders"></i> <?php _e('stock_adjustment_details'); ?>
                </h5>
                <div>
                    <button onclick="window.print()" class="btn btn-sm btn-secondary">
                        <i class="bi bi-printer"></i> <?php _e('print'); ?>
                    </button>
                    <?php if (hasPermission('stock_adjustment', 'delete')): ?>
                    <button onclick="confirmDelete(<?php echo $id; ?>)" class="btn btn-sm btn-danger">
                        <i class="bi bi-trash"></i> <?php _e('delete'); ?>
                    </button>
                    <?php endif; ?>
                    <a href="stock_adjustment.php" class="btn btn-sm btn-primary">
                        <i class="bi bi-plus"></i> <?php _e('new'); ?>
                    </a>
                    <a href="stock_adjustment_list.php" class="btn btn-sm btn-secondary">
                        <i class="bi bi-list"></i> <?php _e('list'); ?>
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
                                <td width="40%"><strong><?php _e('adjustment_number_label'); ?></strong></td>
                                <td><?php echo htmlspecialchars($adjustment['adjustment_number']); ?></td>
                            </tr>
                            <tr>
                                <td><strong><?php _e('date_label'); ?></strong></td>
                                <td><?php echo formatDate($adjustment['adjustment_date']); ?></td>
                            </tr>
                            <tr>
                                <td><strong><?php _e('location_label'); ?></strong></td>
                                <td><?php echo htmlspecialchars($adjustment['location_name']); ?></td>
                            </tr>
                            <?php if ($adjustment['stock_taking_number']): ?>
                            <tr>
                                <td><strong><?php _e('stock_taking_label'); ?></strong></td>
                                <td>
                                    <a href="../reports/stock_taking_view.php?id=<?php echo $adjustment['stock_taking_id']; ?>">
                                        <?php echo htmlspecialchars($adjustment['stock_taking_number']); ?>
                                    </a>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </table>
                    </div>
                    <div class="col-md-6 text-end">
                        <table class="table table-sm table-borderless">
                            <tr>
                                <td width="40%"><strong><?php _e('created_by_label'); ?></strong></td>
                                <td><?php echo htmlspecialchars($adjustment['created_by_name']); ?></td>
                            </tr>
                            <tr>
                                <td><strong><?php _e('created_at_label'); ?></strong></td>
                                <td><?php echo formatDateTime($adjustment['created_at']); ?></td>
                            </tr>
                            <?php if ($adjustment['remarks']): ?>
                            <tr>
                                <td><strong><?php _e('remarks_label'); ?></strong></td>
                                <td><?php echo htmlspecialchars($adjustment['remarks']); ?></td>
                            </tr>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>
                
                <hr>
                
                <!-- Summary Cards -->
                <div class="row mb-4 print-summary">
                    <div class="col-md-3 col-6">
                        <div class="card bg-light">
                            <div class="card-body text-center py-2">
                                <small class="text-muted"><?php _e('increase_items'); ?></small>
                                <h4 class="mb-0 text-success"><?php echo $increaseCount; ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="card bg-light">
                            <div class="card-body text-center py-2">
                                <small class="text-muted"><?php _e('increase_amount'); ?></small>
                                <h4 class="mb-0 text-success"><?php echo formatCurrency($totalIncrease); ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="card bg-light">
                            <div class="card-body text-center py-2">
                                <small class="text-muted"><?php _e('decrease_items'); ?></small>
                                <h4 class="mb-0 text-danger"><?php echo $decreaseCount; ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="card bg-light">
                            <div class="card-body text-center py-2">
                                <small class="text-muted"><?php _e('decrease_amount'); ?></small>
                                <h4 class="mb-0 text-danger"><?php echo formatCurrency($totalDecrease); ?></h4>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Net Effect -->
                <div class="alert <?php echo $adjustment['total_adjustment_amount'] >= 0 ? 'alert-success' : 'alert-danger'; ?> mb-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <span><strong><?php _e('net_adjustment_effect'); ?></strong></span>
                        <h4 class="mb-0">
                            <?php echo $adjustment['total_adjustment_amount'] >= 0 ? '+' : ''; ?>
                            <?php echo formatCurrency($adjustment['total_adjustment_amount']); ?>
                        </h4>
                    </div>
                </div>
                
                <!-- Details Table -->
                <h6 class="text-muted mb-3"><?php _e('adjustment_details'); ?></h6>
                <div class="table-responsive">
                    <table class="table table-bordered table-sm">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 5%;"><?php _e('sr'); ?></th>
                                <th style="width: 30%;"><?php _e('product'); ?></th>
                                <th style="width: 12%;"><?php _e('category'); ?></th>
                                <th style="width: 8%;"><?php _e('unit'); ?></th>
                                <th style="width: 10%;"><?php _e('type'); ?></th>
                                <th style="width: 10%;" class="text-end"><?php _e('quantity'); ?></th>
                                <th style="width: 10%;" class="text-end"><?php _e('rate'); ?></th>
                                <th style="width: 15%;" class="text-end"><?php _e('amount'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $rowNum = 0;
                            $currentCategory = '';
                            foreach ($details as $detail): 
                                $rowNum++;
                                
                                if ($currentCategory != $detail['category_name']):
                                    $currentCategory = $detail['category_name'];
                            ?>
                            <tr class="table-secondary">
                                <td colspan="8">
                                    <strong><i class="bi bi-tag"></i> <?php echo htmlspecialchars($currentCategory ?: __('uncategorized')); ?></strong>
                                </td>
                            </tr>
                            <?php endif; ?>
                            
                            <tr class="<?php echo $detail['adjustment_type'] === 'increase' ? 'table-success' : 'table-danger'; ?>" style="--bs-table-bg-opacity: 0.3;">
                                <td><?php echo $rowNum; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($detail['product_name']); ?></strong>
                                    <?php if ($detail['remarks']): ?>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($detail['remarks']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($detail['category_name'] ?: '-'); ?></td>
                                <td><?php echo htmlspecialchars($detail['unit']); ?></td>
                                <td>
                                    <?php if ($detail['adjustment_type'] === 'increase'): ?>
                                    <span class="badge bg-success"><i class="bi bi-plus-circle"></i> <?php _e('increase_label'); ?></span>
                                    <?php else: ?>
                                    <span class="badge bg-danger"><i class="bi bi-dash-circle"></i> <?php _e('decrease_label'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end"><?php echo formatNumber($detail['quantity'], 3); ?></td>
                                <td class="text-end"><?php echo formatCurrency($detail['rate_per_unit']); ?></td>
                                <td class="text-end">
                                    <strong class="<?php echo $detail['adjustment_type'] === 'increase' ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo $detail['adjustment_type'] === 'increase' ? '+' : '-'; ?>
                                        <?php echo formatCurrency($detail['adjustment_amount']); ?>
                                    </strong>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="7" class="text-end"><?php _e('net_total'); ?></th>
                                <th class="text-end">
                                    <strong class="<?php echo $adjustment['total_adjustment_amount'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo $adjustment['total_adjustment_amount'] >= 0 ? '+' : ''; ?>
                                        <?php echo formatCurrency($adjustment['total_adjustment_amount']); ?>
                                    </strong>
                                </th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                
                <!-- Footer -->
                <div class="row mt-5 pt-4 border-top">
                    <div class="col-6 text-center">
                        <div style="border-top: 1px solid #000; display: inline-block; padding-top: 5px; min-width: 150px;">
                            <?php _e('prepared_by'); ?>
                        </div>
                    </div>
                    <div class="col-6 text-center">
                        <div style="border-top: 1px solid #000; display: inline-block; padding-top: 5px; min-width: 150px;">
                            <?php _e('approved_by'); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function confirmDelete(id) {
    if (confirm('<?php echo __('confirm_delete_stock_adjustment'); ?>\n\n<?php echo __('confirm_delete_will_reverse'); ?>')) {
        window.location.href = 'stock_adjustment_view.php?delete=' + id;
    }
}
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
    
    /* Compact header */
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
    
    /* Summary cards - force horizontal layout */
    .print-summary {
        display: flex !important;
        flex-direction: row !important;
        flex-wrap: nowrap !important;
        justify-content: space-between !important;
        gap: 8px !important;
        margin-bottom: 0.5rem !important;
    }
    
    .print-summary > div {
        flex: 1 1 25% !important;
        max-width: 25% !important;
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
    
    /* Net effect alert - compact */
    .alert.mb-4 {
        padding: 4px 10px !important;
        margin-bottom: 0.5rem !important;
    }
    
    .alert.mb-4 h4 {
        font-size: 11pt !important;
    }
    
    /* Table styling */
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
        font-size: 7pt !important;
    }
    
    .table-secondary td {
        padding: 1px 4px !important;
        font-size: 7pt !important;
        background-color: #e9ecef !important;
    }
    
    .table-success {
        background-color: rgba(25, 135, 84, 0.1) !important;
    }
    
    .table-danger {
        background-color: rgba(220, 53, 69, 0.1) !important;
    }
    
    .badge {
        font-size: 6pt !important;
        padding: 1px 4px !important;
    }
    
    /* Section title */
    h6.text-muted.mb-3 {
        font-size: 8pt !important;
        margin-bottom: 0.3rem !important;
    }
    
    /* Footer signatures */
    .row.mt-5.pt-4.border-top {
        margin-top: 1rem !important;
        padding-top: 0.5rem !important;
    }
    
    .row.mt-5.pt-4.border-top > div {
        font-size: 8pt !important;
    }
    
    /* Hide swipe indicator */
    .text-muted.small {
        display: none !important;
    }
}
</style>

<?php require_once '../../includes/footer.php'; ?>
