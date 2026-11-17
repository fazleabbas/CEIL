<?php
/**
 * Import Costing Report
 * Shows import costing breakdown - per import (matching the Excel format) and summary across imports
 */
require_once '../../config/database.php';
require_once '../../includes/functions.php';

$pageTitle = 'Import Costing Report';

if (!hasPermission('purchase_imports', 'view')) {
    setAlert('You do not have permission to access this page', 'danger');
    redirect('');
}

require_once '../../includes/header.php';

// Get filters
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$supplierFilter = $_GET['supplier'] ?? '';
$locationFilter = $_GET['location'] ?? '';
$viewMode = $_GET['view'] ?? 'summary'; // 'summary' or 'detail'
$importId = $_GET['import_id'] ?? '';

// Get user locations
$userLocations = getUserLocations();
$locationIds = array_column($userLocations, 'id');

// Get suppliers for filter
$suppliersStmt = $conn->query("SELECT s.id, la.name FROM suppliers s LEFT JOIN ledger_accounts la ON s.ledger_account_id = la.id WHERE s.is_active = 1 ORDER BY la.name");
$suppliersList = $suppliersStmt->fetchAll();

// Get default currency
$defaultCurrency = getDefaultCurrency();
$currSymbol = $defaultCurrency ? $defaultCurrency['symbol'] : 'TZS';
?>

<div class="row mb-3">
    <div class="col-md-6">
        <h2><i class="bi bi-globe2"></i> Import Costing Report</h2>
    </div>
    <div class="col-md-6 text-end">
        <button class="btn btn-outline-primary btn-sm" onclick="window.print()">
            <i class="bi bi-printer"></i> Print
        </button>
    </div>
</div>

<!-- Filters -->
<div class="card mb-3 no-print">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label">From Date</label>
                <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($dateFrom); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">To Date</label>
                <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($dateTo); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Supplier</label>
                <select name="supplier" class="form-select">
                    <option value="">All Suppliers</option>
                    <?php foreach ($suppliersList as $s): ?>
                    <option value="<?php echo $s['id']; ?>" <?php echo $supplierFilter == $s['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($s['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Location</label>
                <select name="location" class="form-select">
                    <option value="">All Locations</option>
                    <?= renderTreeOptions($userLocations, $locationFilter) ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">View</label>
                <select name="view" class="form-select">
                    <option value="summary" <?php echo $viewMode === 'summary' ? 'selected' : ''; ?>>Summary</option>
                    <option value="detail" <?php echo $viewMode === 'detail' ? 'selected' : ''; ?>>Detail (Per Import)</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-search"></i> Filter
                </button>
            </div>
        </form>
    </div>
</div>

<?php
// Build query
$conditions = ["pi.location_id IN (" . str_repeat('?,', count($locationIds) - 1) . '?)'];
$params = $locationIds;

$conditions[] = "pi.import_date >= ?";
$params[] = $dateFrom;
$conditions[] = "pi.import_date <= ?";
$params[] = $dateTo;

if ($supplierFilter) {
    $conditions[] = "pi.supplier_id = ?";
    $params[] = $supplierFilter;
}
if ($locationFilter) {
    // Aggregate parent + all descendant locations
    $locIds = getDescendantLocationIds($locationFilter, $conn);
    $locInClause = implode(',', array_map('intval', $locIds));
    $conditions[] = "pi.location_id IN ($locInClause)";
}
if ($importId) {
    $conditions[] = "pi.id = ?";
    $params[] = $importId;
}

$where = implode(' AND ', $conditions);

// Fetch imports
$stmt = $conn->prepare("
    SELECT
        pi.*,
        la.name as supplier_name,
        l.name as location_name,
        cur.code as currency_code,
        cur.symbol as currency_symbol,
        u.name as created_by_name
    FROM purchase_imports pi
    LEFT JOIN suppliers s ON pi.supplier_id = s.id
    LEFT JOIN ledger_accounts la ON s.ledger_account_id = la.id
    LEFT JOIN locations l ON pi.location_id = l.id
    LEFT JOIN currencies cur ON pi.currency_id = cur.id
    LEFT JOIN users u ON pi.created_by = u.id
    WHERE $where
    ORDER BY pi.import_date DESC, pi.id DESC
");
$stmt->execute($params);
$importsData = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch details and costs for each import
foreach ($importsData as &$imp) {
    $dStmt = $conn->prepare("
        SELECT pid.*, p.name as product_name, p.unit, pc.name as category_name
        FROM purchase_import_details pid
        JOIN products p ON pid.product_id = p.id
        LEFT JOIN product_categories pc ON p.category_id = pc.id
        WHERE pid.import_id = ?
        ORDER BY pid.id
    ");
    $dStmt->execute([$imp['id']]);
    $imp['details'] = $dStmt->fetchAll(PDO::FETCH_ASSOC);

    $cStmt = $conn->prepare("SELECT * FROM purchase_import_costs WHERE import_id = ? ORDER BY id");
    $cStmt->execute([$imp['id']]);
    $imp['costs'] = $cStmt->fetchAll(PDO::FETCH_ASSOC);
}
unset($imp);

if ($viewMode === 'summary'):
?>

<!-- ==================== SUMMARY VIEW ==================== -->
<div class="card">
    <div class="card-header bg-light">
        <strong>Import Summary</strong>
        <span class="text-muted ms-2">(<?php echo count($importsData); ?> imports found)</span>
    </div>
    <div class="card-body">
        <?php if (empty($importsData)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-inbox" style="font-size: 3rem;"></i>
                <p class="mt-3">No purchase imports found for the selected period.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-striped table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>Import #</th>
                            <th>Date</th>
                            <th>Supplier</th>
                            <th>Location</th>
                            <th class="text-center">Items</th>
                            <th class="text-end">Total FOB</th>
                            <th class="text-end">Additional Costs</th>
                            <th class="text-end">Total Landed</th>
                            <th class="text-center">Factor</th>
                            <th class="text-center no-print">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $grandFob = 0; $grandCosts = 0; $grandLanded = 0;
                        foreach ($importsData as $imp):
                            $grandFob += floatval($imp['total_fob_amount']);
                            $grandCosts += floatval($imp['total_additional_costs']);
                            $grandLanded += floatval($imp['total_landed_amount']);
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($imp['import_number']); ?></strong></td>
                            <td><?php echo date(DATE_FORMAT, strtotime($imp['import_date'])); ?></td>
                            <td><?php echo htmlspecialchars($imp['supplier_name'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($imp['location_name'] ?? ''); ?></td>
                            <td class="text-center"><span class="badge bg-info"><?php echo count($imp['details']); ?></span></td>
                            <td class="text-end"><?php echo number_format($imp['total_fob_amount'], 2); ?></td>
                            <td class="text-end"><?php echo number_format($imp['total_additional_costs'], 2); ?></td>
                            <td class="text-end fw-bold"><?php echo number_format($imp['total_landed_amount'], 2); ?></td>
                            <td class="text-center"><span class="badge bg-secondary"><?php echo number_format($imp['costing_factor'], 4); ?></span></td>
                            <td class="text-center no-print">
                                <a href="?view=detail&import_id=<?php echo $imp['id']; ?>&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>"
                                   class="btn btn-sm btn-info" title="View Detail">
                                    <i class="bi bi-eye"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-active fw-bold">
                            <td colspan="5" class="text-end">Grand Totals:</td>
                            <td class="text-end"><?php echo number_format($grandFob, 2); ?></td>
                            <td class="text-end"><?php echo number_format($grandCosts, 2); ?></td>
                            <td class="text-end"><?php echo number_format($grandLanded, 2); ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php else: ?>

<!-- ==================== DETAIL VIEW (Per Import) ==================== -->
<?php if (empty($importsData)): ?>
    <div class="card">
        <div class="card-body text-center py-5 text-muted">
            <i class="bi bi-inbox" style="font-size: 3rem;"></i>
            <p class="mt-3">No purchase imports found.</p>
        </div>
    </div>
<?php else: ?>
    <?php foreach ($importsData as $imp):
        $impSymbol = $imp['currency_symbol'] ?? $currSymbol;
    ?>
    <div class="card mb-4">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <div>
                <strong><?php echo htmlspecialchars($imp['import_number']); ?></strong>
                <span class="ms-3"><?php echo date(DATE_FORMAT, strtotime($imp['import_date'])); ?></span>
                <span class="ms-3"><?php echo htmlspecialchars($imp['supplier_name'] ?? ''); ?></span>
                <span class="ms-3"><?php echo htmlspecialchars($imp['location_name'] ?? ''); ?></span>
            </div>
            <div>
                <span class="badge bg-light text-dark"><?php echo $imp['currency_code'] ?? ''; ?></span>
            </div>
        </div>
        <div class="card-body">
            <!-- Costing Breakdown (matches Excel format) -->
            <div class="row">
                <div class="col-md-7">
                    <h6 class="text-muted mb-3">IMPORTATION COST WORKING</h6>
                    <table class="table table-sm table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th>ITEMS</th>
                                <th class="text-end" style="width: 180px;">FIGURES (<?php echo htmlspecialchars($impSymbol); ?>)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>TOTAL FOB PRICE</td>
                                <td class="text-end fw-bold"><?php echo number_format($imp['total_fob_amount'], 2); ?></td>
                            </tr>
                            <?php foreach ($imp['costs'] as $cost): ?>
                            <tr>
                                <td>ADD: <?php echo htmlspecialchars(strtoupper($cost['description'])); ?></td>
                                <td class="text-end"><?php echo number_format($cost['amount'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="table-success fw-bold">
                                <td>TOTAL VALUE OF GOODS (LANDED)</td>
                                <td class="text-end"><?php echo number_format($imp['total_landed_amount'], 2); ?></td>
                            </tr>
                            <tr class="table-light">
                                <td>DIVIDE BY TOTAL FOB PRICE</td>
                                <td class="text-end"><?php echo number_format($imp['total_fob_amount'], 2); ?></td>
                            </tr>
                            <tr class="table-warning fw-bold">
                                <td>COSTING FACTOR (Landed / FOB)</td>
                                <td class="text-end"><?php echo number_format($imp['costing_factor'], 8); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="col-md-5">
                    <div class="card bg-light h-100">
                        <div class="card-body text-center d-flex flex-column justify-content-center">
                            <h6 class="text-muted">Costing Factor</h6>
                            <h2 class="text-primary mb-2"><?php echo number_format($imp['costing_factor'], 4); ?></h2>
                            <p class="text-muted mb-0">
                                <small>Each item's FOB price is multiplied by this factor to get the landed cost</small>
                            </p>
                            <hr>
                            <div class="row text-center">
                                <div class="col-4">
                                    <small class="text-muted d-block">FOB</small>
                                    <strong><?php echo number_format($imp['total_fob_amount'], 2); ?></strong>
                                </div>
                                <div class="col-4">
                                    <small class="text-muted d-block">+ Costs</small>
                                    <strong><?php echo number_format($imp['total_additional_costs'], 2); ?></strong>
                                </div>
                                <div class="col-4">
                                    <small class="text-muted d-block">= Landed</small>
                                    <strong class="text-success"><?php echo number_format($imp['total_landed_amount'], 2); ?></strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <hr>

            <!-- Items with Landed Cost -->
            <h6 class="text-muted mb-3">ITEM DETAILS WITH LANDED COST</h6>
            <div class="table-responsive">
                <table class="table table-sm table-bordered table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>DESCRIPTION</th>
                            <th class="text-center">UNIT</th>
                            <th class="text-end">QTY</th>
                            <th class="text-end">FOB RATE</th>
                            <th class="text-end">FOB AMOUNT</th>
                            <th class="text-end">LANDED COST</th>
                            <th class="text-end">SELLING PRICE</th>
                            <th class="text-end">PROFIT/UNIT</th>
                            <th class="text-end">TOTAL LANDED</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $totalFobAmt = 0; $totalLandedAmt = 0;
                        foreach ($imp['details'] as $idx => $item):
                            $fobAmt = floatval($item['quantity']) * floatval($item['fob_rate_per_unit']);
                            $landedAmt = floatval($item['quantity']) * floatval($item['landed_rate_per_unit']);
                            $profit = $item['selling_price'] ? floatval($item['selling_price']) - floatval($item['landed_rate_per_unit']) : null;
                            $totalFobAmt += $fobAmt;
                            $totalLandedAmt += $landedAmt;
                        ?>
                        <tr>
                            <td class="text-center"><?php echo $idx + 1; ?></td>
                            <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                            <td class="text-center"><?php echo htmlspecialchars($item['unit'] ?? ''); ?></td>
                            <td class="text-end"><?php echo number_format($item['quantity'], 3); ?></td>
                            <td class="text-end"><?php echo number_format($item['fob_rate_per_unit'], 2); ?></td>
                            <td class="text-end"><?php echo number_format($fobAmt, 2); ?></td>
                            <td class="text-end fw-bold text-success"><?php echo number_format($item['landed_rate_per_unit'], 2); ?></td>
                            <td class="text-end"><?php echo $item['selling_price'] ? number_format($item['selling_price'], 2) : '-'; ?></td>
                            <td class="text-end <?php echo $profit !== null ? ($profit >= 0 ? 'text-success' : 'text-danger') : ''; ?>">
                                <?php echo $profit !== null ? number_format($profit, 2) : '-'; ?>
                            </td>
                            <td class="text-end fw-bold"><?php echo number_format($landedAmt, 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-active fw-bold">
                            <td colspan="5" class="text-end">TOTALS:</td>
                            <td class="text-end"><?php echo number_format($totalFobAmt, 2); ?></td>
                            <td colspan="3"></td>
                            <td class="text-end"><?php echo number_format($totalLandedAmt, 2); ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="row mt-2">
                <div class="col text-muted">
                    <small>
                        Created by: <?php echo htmlspecialchars($imp['created_by_name'] ?? 'Unknown'); ?>
                        | <?php echo date(DATETIME_FORMAT, strtotime($imp['created_at'])); ?>
                    </small>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if ($importId): ?>
    <div class="no-print">
        <a href="?view=summary&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>&supplier=<?php echo $supplierFilter; ?>&location=<?php echo $locationFilter; ?>"
           class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i> Back to Summary
        </a>
    </div>
    <?php endif; ?>

<?php endif; ?>
<?php endif; ?>

<style>
@media print {
    .no-print, .navbar, .demo-banner, .alert { display: none !important; }
    .card { border: none !important; box-shadow: none !important; }
    .card-header { background-color: #f8f9fa !important; color: #000 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    body { font-size: 11px; }
    .table { font-size: 10px; }
}
</style>

<?php require_once '../../includes/footer.php'; ?>
