<?php
$pageTitle = 'View Purchase';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

if (!hasPermission('purchases', 'view')) {
    setAlert('You do not have permission to access this page', 'danger');
    redirect('');
}

$purchaseId = $_GET['id'] ?? null;

if (!$purchaseId) {
    setAlert('Purchase ID not provided', 'danger');
    redirect('modules/transactions/purchases.php');
}

// Try to fetch from database if online
$purchase = null;
try {
    $stmt = $conn->prepare("
        SELECT
            p.*,
            la.name as supplier_name,
            l.name as location_name,
            cc.name as cost_centre_name,
            cc.code as cost_centre_code,
            u.name as created_by_name
        FROM purchases p
        LEFT JOIN suppliers s ON p.supplier_id = s.id
        LEFT JOIN ledger_accounts la ON s.ledger_account_id = la.id
        LEFT JOIN locations l ON p.location_id = l.id
        LEFT JOIN cost_centres cc ON p.cost_centre_id = cc.id
        LEFT JOIN users u ON p.created_by = u.id
        WHERE p.id = ?
    ");
    $stmt->execute([$purchaseId]);
    $purchase = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($purchase) {
        // Fetch details
        $detailsStmt = $conn->prepare("
            SELECT 
                pd.*,
                p.name as product_name,
                p.unit,
                pc.name as category_name
            FROM purchase_details pd
            JOIN products p ON pd.product_id = p.id
            LEFT JOIN product_categories pc ON p.category_id = pc.id
            WHERE pd.purchase_id = ?
            ORDER BY pd.id
        ");
        $detailsStmt->execute([$purchaseId]);
        $purchase['details'] = $detailsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Fetch stock batches
        $batchesStmt = $conn->prepare("
            SELECT 
                sb.*,
                p.name as product_name,
                p.unit
            FROM stock_batches sb
            JOIN products p ON sb.product_id = p.id
            WHERE sb.transaction_type = 'purchase' AND sb.transaction_id = ?
            ORDER BY sb.id
        ");
        $batchesStmt->execute([$purchaseId]);
        $purchase['stock_batches'] = $batchesStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    // Will load from IndexedDB via JavaScript
}

require_once '../../includes/header.php';
?>

<!-- Connection Status Banner -->
<div id="connectionStatus" class="connection-status" style="display: none;">
    <span id="statusText"></span>
</div>

<div id="loadingIndicator" class="text-center py-5">
    <div class="spinner-border text-primary" role="status">
        <span class="visually-hidden">Loading...</span>
    </div>
    <p class="mt-2">Loading purchase details...</p>
</div>

<div id="purchaseContent" style="display: <?php echo $purchase ? 'block' : 'none'; ?>;">
    <!-- Action Buttons (No Print) -->
    <div class="row mb-3 no-print">
        <div class="col-md-6">
            <a href="purchases.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back to Purchases
            </a>
        </div>
        <div class="col-md-6 text-end">
            <?php if (hasPermission('purchases', 'edit')): ?>
            <button onclick="editPurchase()" class="btn btn-primary" id="editBtn">
                <i class="bi bi-pencil"></i> Edit
            </button>
            <?php endif; ?>
            
            <?php if (hasPermission('purchases', 'delete')): ?>
            <button onclick="deletePurchase()" class="btn btn-danger" id="deleteBtn">
                <i class="bi bi-trash"></i> Delete
            </button>
            <?php endif; ?>
            
            <button onclick="window.print()" class="btn btn-success">
                <i class="bi bi-printer"></i> Print
            </button>
        </div>
    </div>

    <!-- Purchase View Card -->
    <div class="card">
        <div class="card-header bg-primary text-white">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h4 class="mb-0">
                        <i class="bi bi-receipt"></i>
                        Purchase Details
                    </h4>
                </div>
                <div class="col-md-6 text-end">
                    <h5 class="mb-0" id="purchaseNumber">
                        <?php echo $purchase ? htmlspecialchars($purchase['purchase_number']) : ''; ?>
                    </h5>
                    <span class="badge bg-warning text-dark" id="syncBadge" style="display: none;">
                        Pending Sync
                    </span>
                </div>
            </div>
        </div>
        
        <div class="card-body">
            <!-- Purchase Header Info -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <label class="text-muted small">Purchase Date</label>
                    <div class="fw-bold" id="purchaseDate">
                        <?php echo $purchase ? formatDate($purchase['purchase_date']) : '-'; ?>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <label class="text-muted small">Supplier</label>
                    <div class="fw-bold" id="supplierName">
                        <?php echo $purchase ? htmlspecialchars($purchase['supplier_name']) : '-'; ?>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <label class="text-muted small">Location</label>
                    <div class="fw-bold" id="locationName">
                        <?php echo $purchase ? htmlspecialchars($purchase['location_name']) : '-'; ?>
                    </div>
                </div>

                <?php if (!empty($purchase['cost_centre_id'])): ?>
                <div class="col-md-3">
                    <label class="text-muted small"><?php _e('cost_centre'); ?></label>
                    <div class="fw-bold" id="costCentreName">
                        <?php echo htmlspecialchars($purchase['cost_centre_code'] . ' - ' . $purchase['cost_centre_name']); ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="col-md-3">
                    <label class="text-muted small">Created By</label>
                    <div class="fw-bold" id="createdBy">
                        <?php echo $purchase ? htmlspecialchars($purchase['created_by_name']) : '-'; ?>
                    </div>
                </div>
            </div>

            <!-- Items Table -->
            <h5 class="border-bottom pb-2 mb-3">Purchase Items</h5>
            <div class="table-responsive mb-4">
                <table class="table table-bordered" id="itemsTable">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 5%;">#</th>
                            <th style="width: 35%;">Product</th>
                            <th style="width: 15%;">Category</th>
                            <th style="width: 10%;">Unit</th>
                            <th style="width: 12%;" class="text-end">Quantity</th>
                            <th style="width: 12%;" class="text-end">Rate/Unit</th>
                            <th style="width: 11%;" class="text-end">Amount</th>
                        </tr>
                    </thead>
                    <tbody id="itemsTableBody">
                        <?php if ($purchase && isset($purchase['details'])): ?>
                            <?php foreach ($purchase['details'] as $index => $item): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><strong><?php echo htmlspecialchars($item['product_name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($item['category_name'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($item['unit']); ?></td>
                                <td class="text-end"><?php echo formatNumber($item['quantity'], 3); ?></td>
                                <td class="text-end"><?php echo formatCurrency($item['rate_per_unit']); ?></td>
                                <td class="text-end"><strong><?php echo formatCurrency($item['quantity'] * $item['rate_per_unit']); ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <?php
                        $_vatAmount = $purchase ? (float)($purchase['vat_amount'] ?? 0) : 0;
                        $_totalAmount = $purchase ? (float)$purchase['total_amount'] : 0;
                        $_payable = $_totalAmount + $_vatAmount;
                        $_showVatRows = ($_vatAmount > 0);
                        ?>
                        <tr>
                            <th colspan="6" class="text-end">Total Amount:</th>
                            <th class="text-end" id="totalAmount">
                                <?php echo $purchase ? formatCurrency($_totalAmount) : 'TZS 0.00'; ?>
                            </th>
                        </tr>
                        <tr id="vatRow" style="<?php echo $_showVatRows ? '' : 'display:none;'; ?>">
                            <th colspan="6" class="text-end">VAT Amount:</th>
                            <th class="text-end" id="vatAmount">
                                <?php echo $purchase ? formatCurrency($_vatAmount) : 'TZS 0.00'; ?>
                            </th>
                        </tr>
                        <tr id="payableRow" class="table-active fw-bold" style="<?php echo $_showVatRows ? '' : 'display:none;'; ?>">
                            <th colspan="6" class="text-end">Payable:</th>
                            <th class="text-end" id="payableAmount">
                                <?php echo $purchase ? formatCurrency($_payable) : 'TZS 0.00'; ?>
                            </th>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Notes Section -->
            <?php if ($purchase && !empty($purchase['notes'])): ?>
            <div class="mb-4" id="notesSection">
                <h5 class="border-bottom pb-2 mb-3">Notes</h5>
                <div class="alert alert-info">
                    <i class="bi bi-sticky"></i>
                    <span id="purchaseNotes"><?php echo nl2br(htmlspecialchars($purchase['notes'])); ?></span>
                </div>
            </div>
            <?php endif; ?>

            <!-- Stock Batches Section (Collapsible) -->
            <div class="accordion no-print" id="batchesAccordion">
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" 
                                data-bs-toggle="collapse" data-bs-target="#batchesCollapse">
                            <i class="bi bi-box-seam me-2"></i>
                            Stock Batches Created
                            <span class="badge bg-info ms-2" id="batchCount">
                                <?php echo $purchase && isset($purchase['stock_batches']) ? count($purchase['stock_batches']) : 0; ?>
                            </span>
                        </button>
                    </h2>
                    <div id="batchesCollapse" class="accordion-collapse collapse">
                        <div class="accordion-body">
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Product</th>
                                            <th>Batch Date</th>
                                            <th class="text-end">Quantity In</th>
                                            <th class="text-end">Remaining</th>
                                            <th class="text-end">Rate/Unit</th>
                                        </tr>
                                    </thead>
                                    <tbody id="batchesTableBody">
                                        <?php if ($purchase && isset($purchase['stock_batches'])): ?>
                                            <?php foreach ($purchase['stock_batches'] as $batch): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($batch['product_name']); ?></td>
                                                <td><?php echo formatDate($batch['batch_date']); ?></td>
                                                <td class="text-end"><?php echo formatNumber($batch['quantity_in'], 3); ?> <?php echo $batch['unit']; ?></td>
                                                <td class="text-end"><?php echo formatNumber($batch['quantity_remaining'], 3); ?> <?php echo $batch['unit']; ?></td>
                                                <td class="text-end"><?php echo formatCurrency($batch['rate_per_unit']); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Metadata -->
            <div class="row mt-4 pt-3 border-top">
                <div class="col-md-6">
                    <small class="text-muted">
                        <i class="bi bi-calendar-plus"></i>
                        Created: <span id="createdAt"><?php echo $purchase ? formatDateTime($purchase['created_at']) : '-'; ?></span>
                    </small>
                </div>
                <div class="col-md-6 text-end">
                    <small class="text-muted">
                        <i class="bi bi-calendar-check"></i>
                        Last Updated: <span id="updatedAt"><?php echo $purchase ? formatDateTime($purchase['updated_at']) : '-'; ?></span>
                    </small>
                </div>
            </div>
        </div>
    </div>

    <!-- Print Footer -->
    <div class="print-only mt-4 text-center">
        <small class="text-muted">
            Printed from <?php echo APP_NAME; ?> on <?php echo date('d-m-Y H:i'); ?>
        </small>
    </div>
</div>

<!-- Not Found Message -->
<div id="notFoundMessage" style="display: none;">
    <div class="alert alert-warning">
        <h4><i class="bi bi-exclamation-triangle"></i> Purchase Not Found</h4>
        <p>The requested purchase could not be found. It may have been deleted or you may not have access to it.</p>
        <a href="purchases.php" class="btn btn-primary">
            <i class="bi bi-arrow-left"></i> Back to Purchases
        </a>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle"></i> Confirm Delete
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this purchase?</p>
                <p class="text-danger mb-0"><strong>This will reverse all stock batches and cannot be undone.</strong></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="confirmDelete()">
                    <i class="bi bi-trash"></i> Delete
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.connection-status { position: fixed; top: 0; left: 0; right: 0; padding: 10px; text-align: center; z-index: 9999; font-weight: 500; }
.connection-status.online { background: #d4edda; color: #155724; }
.connection-status.offline { background: #f8d7da; color: #721c24; }

/* Print styles */
@media print {
    .no-print { display: none !important; }
    .print-only { display: block !important; }
    .card { border: none !important; box-shadow: none !important; }
    .card-header { background: #f8f9fa !important; color: #000 !important; }
    body { font-size: 12pt; }
}

.print-only { display: none; }
</style>

<script>
const purchaseId = '<?php echo $purchaseId; ?>';
let currentPurchase = <?php echo $purchase ? json_encode($purchase) : 'null'; ?>;
let deleteModal;

document.addEventListener('DOMContentLoaded', async function() {
    deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
    
    // If purchase not loaded from PHP, try IndexedDB
    if (!currentPurchase) {
        await loadFromIndexedDB();
    } else {
        document.getElementById('loadingIndicator').style.display = 'none';
        document.getElementById('purchaseContent').style.display = 'block';
        updateSyncStatus();
    }
    
    // Listen for online/offline
    window.addEventListener('online', updateConnectionStatus);
    window.addEventListener('offline', updateConnectionStatus);
    updateConnectionStatus();
});

async function loadFromIndexedDB() {
    try {
        await window.offlineDB.init();
        
        // Try to get by numeric ID or temp ID
        let purchase = await window.offlineDB.getItem('purchases', purchaseId);
        
        // If not found by ID, search all purchases
        if (!purchase) {
            const allPurchases = await window.offlineDB.getAllItems('purchases');
            purchase = allPurchases.find(p => 
                p.id == purchaseId || 
                p.tempId == purchaseId ||
                p.purchase_number == purchaseId
            );
        }
        
        if (purchase && !purchase.deleted) {
            currentPurchase = purchase;
            displayPurchase(purchase);
            document.getElementById('loadingIndicator').style.display = 'none';
            document.getElementById('purchaseContent').style.display = 'block';
        } else {
            showNotFound();
        }
        
    } catch (error) {
        console.error('Error loading from IndexedDB:', error);
        showNotFound();
    }
}

function displayPurchase(purchase) {
    // Update header
    document.getElementById('purchaseNumber').textContent = purchase.purchase_number || 'N/A';
    document.getElementById('purchaseDate').textContent = formatDate(purchase.purchase_date);
    document.getElementById('supplierName').textContent = purchase.supplier_name || '-';
    document.getElementById('locationName').textContent = purchase.location_name || '-';
    document.getElementById('createdBy').textContent = purchase.created_by_name || '-';
    
    // Update items table
    const tbody = document.getElementById('itemsTableBody');
    tbody.innerHTML = '';
    
    let total = 0;
    
    if (purchase.details && purchase.details.length > 0) {
        purchase.details.forEach((item, index) => {
            const amount = parseFloat(item.quantity) * parseFloat(item.rate_per_unit);
            total += amount;
            
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>${index + 1}</td>
                <td><strong>${escapeHtml(item.product_name)}</strong></td>
                <td>${escapeHtml(item.category_name || '-')}</td>
                <td>${escapeHtml(item.unit)}</td>
                <td class="text-end">${parseFloat(item.quantity).toFixed(3)}</td>
                <td class="text-end">TZS ${parseFloat(item.rate_per_unit).toFixed(2)}</td>
                <td class="text-end"><strong>TZS ${amount.toFixed(2)}</strong></td>
            `;
            tbody.appendChild(row);
        });
    }
    
    document.getElementById('totalAmount').textContent = `TZS ${total.toFixed(2)}`;

    // VAT + Payable: show only when VAT > 0 (matches how the modal view renders)
    const vatAmt = parseFloat(purchase.vat_amount || 0);
    const vatRow = document.getElementById('vatRow');
    const payableRow = document.getElementById('payableRow');
    if (vatAmt > 0) {
        document.getElementById('vatAmount').textContent = `TZS ${vatAmt.toFixed(2)}`;
        document.getElementById('payableAmount').textContent = `TZS ${(total + vatAmt).toFixed(2)}`;
        if (vatRow) vatRow.style.display = '';
        if (payableRow) payableRow.style.display = '';
    } else {
        if (vatRow) vatRow.style.display = 'none';
        if (payableRow) payableRow.style.display = 'none';
    }

    // Update notes
    const notesSection = document.getElementById('notesSection');
    if (purchase.notes && purchase.notes.trim()) {
        if (!notesSection) {
            // Create notes section if it doesn't exist
            const itemsTable = document.getElementById('itemsTable');
            const notesDiv = document.createElement('div');
            notesDiv.id = 'notesSection';
            notesDiv.className = 'mb-4';
            notesDiv.innerHTML = `
                <h5 class="border-bottom pb-2 mb-3">Notes</h5>
                <div class="alert alert-info">
                    <i class="bi bi-sticky"></i>
                    <span id="purchaseNotes">${escapeHtml(purchase.notes).replace(/\n/g, '<br>')}</span>
                </div>
            `;
            itemsTable.parentElement.insertBefore(notesDiv, itemsTable.nextElementSibling);
        } else {
            notesSection.style.display = 'block';
            document.getElementById('purchaseNotes').innerHTML = escapeHtml(purchase.notes).replace(/\n/g, '<br>');
        }
    } else if (notesSection) {
        notesSection.style.display = 'none';
    }
    
    // Update stock batches
    if (purchase.stock_batches && purchase.stock_batches.length > 0) {
        const batchesTbody = document.getElementById('batchesTableBody');
        batchesTbody.innerHTML = '';
        document.getElementById('batchCount').textContent = purchase.stock_batches.length;
        
        purchase.stock_batches.forEach(batch => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>${escapeHtml(batch.product_name)}</td>
                <td>${formatDate(batch.batch_date)}</td>
                <td class="text-end">${parseFloat(batch.quantity_in).toFixed(3)} ${escapeHtml(batch.unit)}</td>
                <td class="text-end">${parseFloat(batch.quantity_remaining).toFixed(3)} ${escapeHtml(batch.unit)}</td>
                <td class="text-end">TZS ${parseFloat(batch.rate_per_unit).toFixed(2)}</td>
            `;
            batchesTbody.appendChild(row);
        });
    }
    
    // Update metadata
    if (purchase.created_at) {
        document.getElementById('createdAt').textContent = formatDateTime(purchase.created_at);
    }
    if (purchase.updated_at) {
        document.getElementById('updatedAt').textContent = formatDateTime(purchase.updated_at);
    }
    
    updateSyncStatus();
}

function updateSyncStatus() {
    const syncBadge = document.getElementById('syncBadge');
    
    if (currentPurchase) {
        const isSynced = currentPurchase.synced === true;
        const isTemp = String(currentPurchase.purchase_number).startsWith('TEMP-');
        
        if (!isSynced || isTemp) {
            syncBadge.style.display = 'inline-block';
        } else {
            syncBadge.style.display = 'none';
        }
    }
}

function updateConnectionStatus() {
    const statusBanner = document.getElementById('connectionStatus');
    const statusText = document.getElementById('statusText');
    
    if (navigator.onLine) {
        statusBanner.className = 'connection-status online';
        statusBanner.style.display = 'block';
        statusText.textContent = '✓ Online';
        setTimeout(() => statusBanner.style.display = 'none', 3000);
    } else {
        statusBanner.className = 'connection-status offline';
        statusBanner.style.display = 'block';
        statusText.textContent = '⚠ Offline Mode';
    }
}

function showNotFound() {
    document.getElementById('loadingIndicator').style.display = 'none';
    document.getElementById('purchaseContent').style.display = 'none';
    document.getElementById('notFoundMessage').style.display = 'block';
}

function editPurchase() {
    // Redirect to purchases list page with edit parameter
    const idParam = currentPurchase.id || currentPurchase.tempId || purchaseId;
    window.location.href = `purchases.php?edit=${idParam}`;
}

function deletePurchase() {
    deleteModal.show();
}

async function confirmDelete() {
    try {
        const idToDelete = currentPurchase.id || currentPurchase.tempId;
        
        const result = await window.syncManager.saveData('purchases', 'delete', { 
            id: idToDelete,
            tempId: currentPurchase.tempId
        });
        
        if (result.success) {
            deleteModal.hide();
            
            // Show success message
            const alert = document.createElement('div');
            alert.className = 'alert alert-success';
            alert.innerHTML = '<i class="bi bi-check-circle"></i> Purchase deleted successfully. Redirecting...';
            document.querySelector('.container-fluid').prepend(alert);
            
            // Redirect after 2 seconds
            setTimeout(() => {
                window.location.href = 'purchases.php';
            }, 2000);
        } else {
            alert('Error deleting purchase: ' + result.error);
        }
        
    } catch (error) {
        console.error('Error deleting purchase:', error);
        alert('Error deleting purchase');
    }
}

function formatDate(dateStr) {
    if (!dateStr) return '-';
    const date = new Date(dateStr);
    return date.toLocaleDateString();
}

function formatDateTime(dateTimeStr) {
    if (!dateTimeStr) return '-';
    const date = new Date(dateTimeStr);
    return date.toLocaleString();
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

<?php require_once '../../includes/footer.php'; ?>
