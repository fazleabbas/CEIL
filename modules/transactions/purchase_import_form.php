<?php
/**
 * Purchase Import Form (Create / Edit)
 * Dedicated page for entering a purchase import, including FOB items
 * and the existing Additional Costs section. Submits via fetch() to
 * api/purchase-imports-sync.php (same endpoint the old modal used).
 */
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");

require_once '../../config/database.php';
require_once '../../includes/functions.php';

$isEdit = isset($_GET['id']) && !empty($_GET['id']);
$pageTitle = $isEdit ? 'Edit Purchase Import' : 'New Purchase Import';

$requiredPermission = $isEdit ? 'edit' : 'create';
if (!hasPermission('purchase_imports', $requiredPermission)) {
    setAlert('You do not have permission to access this page', 'danger');
    redirect('modules/transactions/purchase_imports.php');
}

// Initialise blank import + details + costs
$import = [
    'id' => '',
    'import_date' => date('Y-m-d'),
    'po_number' => '',
    'supplier_id' => '',
    'location_id' => '',
    'cost_centre_id' => '',
    'currency_id' => $defaultCurrencyId ?? null,
    'exchange_rate_to_base' => 1,
    'notes' => '',
    'requisition_id' => '',
    'budget_id' => '',
];
$importDetails = [];
$importCosts = [];  // legacy / Bucket B for now (Phase 3 will rebuild)
$importCostsA = []; // Bucket A: supplier-borne additional costs
$importCostsC = []; // Bucket C: other costs (NOT added to costing)

if ($isEdit) {
    $id = (int)$_GET['id'];

    // Pull the import header alongside a has_gl flag — true iff a row exists
    // in transactions for this import. Legacy imports created before Phase 4
    // never had GL posted; the form shows a banner so the user knows
    // re-saving will post the accounting entries for the first time.
    $stmt = $conn->prepare("
        SELECT pi.*,
               EXISTS (
                   SELECT 1 FROM transactions t
                   WHERE t.voucher_type = 'purchase_import' AND t.voucher_id = pi.id
               ) AS has_gl
        FROM purchase_imports pi
        WHERE pi.id = ?
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        setAlert('Purchase import not found', 'danger');
        redirect('modules/transactions/purchase_imports.php');
    }
    if (!canAccessLocation((int)$row['location_id'])) {
        setAlert('You do not have access to this import', 'danger');
        redirect('modules/transactions/purchase_imports.php');
    }
    $import = $row;

    $detailsStmt = $conn->prepare("
        SELECT pid.*, p.name AS product_name, p.unit
        FROM purchase_import_details pid
        JOIN products p ON pid.product_id = p.id
        WHERE pid.import_id = ?
        ORDER BY pid.id
    ");
    $detailsStmt->execute([$id]);
    $importDetails = $detailsStmt->fetchAll(PDO::FETCH_ASSOC);

    $costsStmt = $conn->prepare("
        SELECT
            pic.*,
            la.name AS ledger_name,
            bla.name AS beneficiary_name,
            cur.code AS currency_code
        FROM purchase_import_costs pic
        LEFT JOIN ledger_accounts la ON pic.ledger_id = la.id
        LEFT JOIN suppliers bs ON pic.beneficiary_supplier_id = bs.id
        LEFT JOIN ledger_accounts bla ON bs.ledger_account_id = bla.id
        LEFT JOIN currencies cur ON pic.currency_id = cur.id
        WHERE pic.import_id = ?
        ORDER BY pic.id
    ");
    $costsStmt->execute([$id]);
    $allCosts = $costsStmt->fetchAll(PDO::FETCH_ASSOC);
    // Split into Bucket A (supplier-borne) and legacy/Bucket B for rendering.
    // Pre-existing rows from before the feature default to bucket='B' via the
    // 0017 migration, so they keep showing in the existing Additional Costs
    // table until Phase 3 rebuilds that section.
    $importCostsA = array_values(array_filter($allCosts, fn($c) => ($c['bucket'] ?? 'B') === 'A'));
    $importCostsC = array_values(array_filter($allCosts, fn($c) => ($c['bucket'] ?? 'B') === 'C'));
    // Bucket B = everything not A or C (legacy rows default to 'B').
    $importCosts  = array_values(array_filter($allCosts, fn($c) => !in_array(($c['bucket'] ?? 'B'), ['A', 'C'], true)));
}

// Prefill a NEW import from a requisition voucher (?from_requisition=<id>): seed
// header (location + cost centre) and items (FOB rate left at 0 for the user to
// fill). Quantity = approved_quantity when set, else requested. Inactive products
// are skipped (server validation rejects them on save). Ignored when editing.
if (!$isEdit && !empty($_GET['from_requisition'])) {
    $rid = (int)$_GET['from_requisition'];
    if ($rid > 0) {
        $rvStmt = $conn->prepare("SELECT id, destination_location_id, cost_centre_id FROM requisition_vouchers WHERE id = ?");
        $rvStmt->execute([$rid]);
        $rv = $rvStmt->fetch(PDO::FETCH_ASSOC);
        if ($rv) {
            $import['requisition_id'] = (int)$rv['id'];
            $import['location_id']    = $rv['destination_location_id'] ?? '';
            $import['cost_centre_id'] = $rv['cost_centre_id'] ?? '';

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
            foreach ($rvdStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $importDetails[] = [
                    'product_id'        => (int)$r['product_id'],
                    'product_name'      => $r['product_name'],
                    'unit'              => $r['unit'],
                    'quantity'          => floatval($r['quantity']),
                    'fob_rate_per_unit' => 0,
                    'selling_price'     => null,
                ];
            }
        }
    }
}

// Prefill a NEW import from an approved Budget (?from_budget=<id>&supplier=<sid>):
// seed header (location, cost centre, supplier) and the supplier's 'import'-type
// allocations with the remaining-to-create quantity and the budgeted rate as FOB.
// The saved import links back via budget_id (+ requisition_id). Ignored when editing.
if (!$isEdit && !empty($_GET['from_budget'])) {
    $bid = (int)$_GET['from_budget'];
    $sid = (int)($_GET['supplier'] ?? 0);
    if ($bid > 0 && $sid > 0) {
        $bStmt = $conn->prepare("SELECT * FROM budgets WHERE id = ? AND status = 'approved'");
        $bStmt->execute([$bid]);
        $bRow = $bStmt->fetch(PDO::FETCH_ASSOC);
        if ($bRow) {
            $import['requisition_id'] = (int)$bRow['requisition_id'];
            $import['budget_id']      = $bid;
            $import['location_id']    = $bRow['location_id'] ?? '';
            $import['cost_centre_id'] = $bRow['cost_centre_id'] ?? '';
            $import['supplier_id']    = $sid;

            // GROUPED BY PRODUCT — same reason as the purchase and PO prefills:
            // a product may hold several rows in one budget for this supplier,
            // while $createdMap below is per product. Ungrouped, the already-
            // imported quantity would be deducted from every row. Rate is
            // quantity-weighted; VAT summed.
            $aStmt = $conn->prepare("
                SELECT bd.product_id,
                       SUM(bd.quantity) AS quantity,
                       ROUND(SUM(bd.quantity * bd.rate) / NULLIF(SUM(bd.quantity), 0), 2) AS rate,
                       SUM(bd.vat_amount) AS vat_amount,
                       p.name AS product_name, p.unit
                FROM budget_details bd
                JOIN products p ON bd.product_id = p.id
                WHERE bd.budget_id = ? AND bd.supplier_id = ? AND bd.procurement_type = 'import' AND p.is_active = 1
                GROUP BY bd.product_id, p.name, p.unit
                ORDER BY p.name
            ");
            $aStmt->execute([$bid, $sid]);
            $allocs = $aStmt->fetchAll(PDO::FETCH_ASSOC);

            $createdStmt = $conn->prepare("
                SELECT pid.product_id, COALESCE(SUM(pid.quantity), 0) AS qty
                FROM purchase_import_details pid
                JOIN purchase_imports pi ON pid.import_id = pi.id
                WHERE pi.budget_id = ? AND pi.supplier_id = ?
                GROUP BY pid.product_id
            ");
            $createdStmt->execute([$bid, $sid]);
            $createdMap = [];
            foreach ($createdStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $createdMap[(int)$r['product_id']] = (float)$r['qty'];
            }

            $budgetVat = 0;   // migration 0044
            foreach ($allocs as $a) {
                $pid = (int)$a['product_id'];
                $rem = (float)$a['quantity'] - ($createdMap[$pid] ?? 0);
                if ($rem <= 0.0005) continue;
                $importDetails[] = [
                    'product_id'        => $pid,
                    'product_name'      => $a['product_name'],
                    'unit'              => $a['unit'],
                    'quantity'          => $rem,
                    'fob_rate_per_unit' => (float)$a['rate'],
                    'selling_price'     => null,
                ];
                $budgetVat += budgetLineVatForQty($a['vat_amount'] ?? 0, $a['quantity'], $rem);
            }

            // Header VAT only. VAT is reclaimable and must NOT enter the landed
            // cost / costing_factor — see the note above updateLandedCosts().
            // Setting $import['vat_amount'] is enough: the VAT input renders it
            // server-side, so no JS change is needed.
            if ($budgetVat > 0) {
                $import['vat_amount'] = round($budgetVat, 2);
            }
        }
    }
}

// Load reference data (server-side render avoids extra round-trips)
$userLocations = getUserLocations();

$suppliersStmt = $conn->query("
    SELECT s.id, la.name
    FROM suppliers s
    INNER JOIN ledger_accounts la ON s.ledger_account_id = la.id
    WHERE s.is_active = 1
    ORDER BY la.name
");
$suppliers = $suppliersStmt->fetchAll(PDO::FETCH_ASSOC);

$productsStmt = $conn->query("
    SELECT p.id, p.name, p.unit, pc.name AS category_name
    FROM products p
    LEFT JOIN product_categories pc ON p.category_id = pc.id
    WHERE p.is_active = 1
    ORDER BY p.name
");
$products = $productsStmt->fetchAll(PDO::FETCH_ASSOC);

$currenciesStmt = $conn->query("SELECT * FROM currencies WHERE is_active = 1 ORDER BY is_default DESC, code ASC");
$allCurrencies = $currenciesStmt->fetchAll(PDO::FETCH_ASSOC);

// Expense/duties ledgers — used by Bucket A (supplier-borne costs).
// Restricted to DR-able cost ledgers, NOT supplier/customer/cash/bank/purchase/sales.
$costLedgersStmt = $conn->query("
    SELECT id, name, account_type
    FROM ledger_accounts
    WHERE account_type IN ('expense', 'duties') AND is_active = 1
    ORDER BY name
");
$costLedgers = $costLedgersStmt->fetchAll(PDO::FETCH_ASSOC);

require_once '../../includes/header.php';
$isLegacy = $isEdit && empty($import['has_gl']);
?>

<?php if ($isLegacy): ?>
<div class="alert alert-warning d-flex align-items-start mb-3">
    <i class="bi bi-exclamation-triangle-fill me-2 mt-1"></i>
    <div>
        <strong>This import has no accounting entries yet.</strong>
        It was created before the GL posting feature was added. Saving the form
        will post the supplier liability, VAT and Bucket A costs to the
        general ledger for the first time, and create journal vouchers for any
        Bucket B (third-party) cost rows that have a ledger and beneficiary.
        Legacy Bucket B rows without a ledger or beneficiary will stay
        unposted — fix them before saving if you want them in the GL.
    </div>
</div>
<?php endif; ?>

<div class="row mb-3">
    <div class="col-md-6">
        <h2>
            <i class="bi bi-globe2"></i>
            <?php echo $isEdit ? 'Edit Purchase Import' : 'New Purchase Import'; ?>
        </h2>
    </div>
    <div class="col-md-6 text-end">
        <a href="purchase_imports.php" class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i> Back to List
        </a>
    </div>
</div>

<form id="importForm" method="POST">
    <input type="hidden" id="importId" name="id" value="<?php echo htmlspecialchars($import['id']); ?>">
    <input type="hidden" id="requisitionId" name="requisition_id" value="<?php echo htmlspecialchars($import['requisition_id'] ?? ''); ?>">
    <input type="hidden" id="budgetId" name="budget_id" value="<?php echo htmlspecialchars($import['budget_id'] ?? ''); ?>">

    <!-- Header Card -->
    <div class="card mb-3">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-info-circle"></i> Import Details</h5>
        </div>
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-md-3">
                    <label class="form-label">Import Date <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" id="importDate" name="import_date"
                           value="<?php echo htmlspecialchars($import['import_date']); ?>"
                           max="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">PO Number</label>
                    <input type="text" class="form-control" id="poNumber" name="po_number"
                           value="<?php echo htmlspecialchars($import['po_number'] ?? ''); ?>"
                           placeholder="Optional" maxlength="50">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Supplier <span class="text-danger">*</span></label>
                    <select class="form-select" id="supplierId" name="supplier_id" required>
                        <option value="">-- Select Supplier --</option>
                        <?php foreach ($suppliers as $s): ?>
                        <option value="<?php echo (int)$s['id']; ?>"
                            <?php echo ((int)$s['id'] === (int)$import['supplier_id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($s['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Location <span class="text-danger">*</span></label>
                    <select class="form-select" id="locationId" name="location_id" required>
                        <option value="">-- Select Location --</option>
                        <?= renderTreeOptions($userLocations, $import['location_id']) ?>
                    </select>
                </div>
            </div>

            <div class="row mb-3">
                <div class="col-md-3">
                    <label class="form-label">Cost Centre</label>
                    <select class="form-select" id="costCentreId" name="cost_centre_id">
                        <option value="">-- None --</option>
                        <?= renderTreeOptions(getUserCostCentres(), $import['cost_centre_id'] ?? null, null, 'name', 'code') ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Currency</label>
                    <select class="form-select" id="currencyId" name="currency_id" onchange="onCurrencyChange()">
                        <?php foreach ($allCurrencies as $c): ?>
                        <option value="<?php echo (int)$c['id']; ?>"
                                data-symbol="<?php echo htmlspecialchars($c['symbol']); ?>"
                                data-code="<?php echo htmlspecialchars($c['code']); ?>"
                                data-is-default="<?php echo (int)$c['is_default']; ?>"
                            <?php echo ((int)$c['id'] === (int)$import['currency_id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['code'] . ' - ' . $c['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3" id="exchangeRateGroup" style="display:none;">
                    <label class="form-label">Exchange Rate</label>
                    <input type="number" class="form-control" id="exchangeRateToBase" name="exchange_rate_to_base"
                           step="0.00000001" min="0.00000001"
                           value="<?php echo htmlspecialchars($import['exchange_rate_to_base'] ?? 1); ?>"
                           oninput="updateItemsTable(); updateSummary();">
                    <small class="text-muted" id="exchangeRateHint"></small>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Notes</label>
                    <input type="text" class="form-control" id="notes" name="notes"
                           value="<?php echo htmlspecialchars($import['notes'] ?? ''); ?>"
                           placeholder="Optional notes">
                </div>
            </div>
        </div>
    </div>

    <!-- FOB Items Card -->
    <div class="card mb-3">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-box-seam"></i> FOB Items <span class="text-danger">*</span></h5>
        </div>
        <div class="card-body">
            <div class="row g-2 mb-3">
                <div class="col-md-4">
                    <select class="form-select" id="productSelect">
                        <option value="">-- Select Product --</option>
                        <?php foreach ($products as $p): ?>
                        <option value="<?php echo (int)$p['id']; ?>"
                                data-unit="<?php echo htmlspecialchars($p['unit'] ?? ''); ?>"
                                data-name="<?php echo htmlspecialchars($p['name']); ?>">
                            <?php echo htmlspecialchars($p['name']); ?>
                            (<?php echo htmlspecialchars($p['category_name'] ?? 'No Category'); ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <input type="number" class="form-control" id="itemQuantity"
                           placeholder="Quantity" min="0.001" step="0.001">
                </div>
                <div class="col-md-2">
                    <input type="number" class="form-control" id="itemFobRate"
                           placeholder="FOB Rate" min="0" step="0.01">
                </div>
                <div class="col-md-2">
                    <input type="number" class="form-control" id="itemSellingPrice"
                           placeholder="Sell Price (opt)" min="0" step="0.01">
                </div>
                <div class="col-md-2">
                    <button type="button" class="btn btn-success w-100" onclick="addItem()">
                        <i class="bi bi-plus"></i> Add Item
                    </button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>Product</th>
                            <th class="text-center">Unit</th>
                            <th class="text-end">Qty</th>
                            <th class="text-end">FOB Rate</th>
                            <th class="text-end">FOB Amount</th>
                            <th class="text-end">Landed Cost</th>
                            <th class="text-end">Sell Price</th>
                            <th class="text-end">Profit</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody id="itemsTableBody">
                        <tr id="noItemsRow">
                            <td colspan="9" class="text-center text-muted">No items added yet</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="table-active">
                            <th colspan="4" class="text-end">Total FOB:</th>
                            <th class="text-end" id="modalTotalFob">0.00</th>
                            <th class="text-end" id="modalTotalLanded">0.00</th>
                            <th colspan="3"></th>
                        </tr>
                        <tr>
                            <th colspan="4" class="text-end">VAT Amount:</th>
                            <th class="text-end p-1" colspan="2">
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
                                           min="0" step="0.01"
                                           value="<?php echo number_format((float)($import['vat_amount'] ?? 0), 2, '.', ''); ?>"
                                           style="max-width: 130px;"
                                           oninput="updatePayable()" onchange="updatePayable()">
                                </div>
                            </th>
                            <th colspan="3"></th>
                        </tr>
                        <tr class="table-active fw-bold">
                            <th colspan="4" class="text-end">Payable to Supplier (FOB + VAT):</th>
                            <th class="text-end" id="modalPayableAmount" colspan="2">0.00</th>
                            <th colspan="3"></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Supplier-borne Additional Costs Card (Bucket A) -->
    <div class="card mb-3">
        <div class="card-header">
            <h5 class="mb-0">
                <i class="bi bi-receipt"></i>
                Supplier-borne Additional Costs
                <small class="text-muted">— charges billed on the supplier's invoice (e.g. freight included by supplier)</small>
            </h5>
        </div>
        <div class="card-body">
            <div class="row g-2 mb-3">
                <div class="col-md-6">
                    <select class="form-select" id="costALedger">
                        <option value="">-- Select Expense / Duties Ledger --</option>
                        <?php foreach ($costLedgers as $lg): ?>
                        <option value="<?php echo (int)$lg['id']; ?>"
                                data-name="<?php echo htmlspecialchars($lg['name']); ?>">
                            <?php echo htmlspecialchars($lg['name']); ?>
                            <small>(<?php echo htmlspecialchars($lg['account_type']); ?>)</small>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <input type="number" class="form-control" id="costAAmount"
                           placeholder="Amount (in import currency)" min="0" step="0.01">
                </div>
                <div class="col-md-2">
                    <button type="button" class="btn btn-success w-100" onclick="addCostA()">
                        <i class="bi bi-plus"></i> Add
                    </button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>Expense / Duties Ledger</th>
                            <th class="text-end" style="width: 200px;">Amount</th>
                            <th class="text-center" style="width: 80px;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="costsATableBody">
                        <tr id="noCostsARow">
                            <td colspan="3" class="text-center text-muted">No supplier-borne costs added</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="table-active">
                            <th class="text-end">Total Supplier-borne Costs:</th>
                            <th class="text-end" id="modalTotalCostsA">0.00</th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Third-party Additional Costs Card (Bucket B) -->
    <div class="card mb-3">
        <div class="card-header">
            <h5 class="mb-0">
                <i class="bi bi-cash-stack"></i>
                Third-party Additional Costs
                <small class="text-muted">— charges paid to other beneficiaries (clearing, port, customs etc.). Each row posts its own journal voucher.</small>
            </h5>
        </div>
        <div class="card-body">
            <!-- Add row: 2-row grid to fit all 6 fields without crowding -->
            <div class="row g-2 mb-2">
                <div class="col-md-3">
                    <label class="form-label small mb-1">Expense / Duties Ledger</label>
                    <select class="form-select form-select-sm" id="costBLedger">
                        <option value="">-- Select Ledger --</option>
                        <?php foreach ($costLedgers as $lg): ?>
                        <option value="<?php echo (int)$lg['id']; ?>"
                                data-name="<?php echo htmlspecialchars($lg['name']); ?>">
                            <?php echo htmlspecialchars($lg['name']); ?>
                            <small>(<?php echo htmlspecialchars($lg['account_type']); ?>)</small>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1">Beneficiary (Supplier)</label>
                    <select class="form-select form-select-sm" id="costBBeneficiary">
                        <option value="">-- Select Beneficiary --</option>
                        <?php foreach ($suppliers as $s): ?>
                        <option value="<?php echo (int)$s['id']; ?>"
                                data-name="<?php echo htmlspecialchars($s['name']); ?>">
                            <?php echo htmlspecialchars($s['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Currency</label>
                    <select class="form-select form-select-sm" id="costBCurrency" onchange="onCostBCurrencyChange()">
                        <?php foreach ($allCurrencies as $c): ?>
                        <option value="<?php echo (int)$c['id']; ?>"
                                data-code="<?php echo htmlspecialchars($c['code']); ?>"
                                data-is-default="<?php echo (int)$c['is_default']; ?>"
                            <?php echo ((int)$c['is_default'] === 1) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['code']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">FX Rate to Base</label>
                    <input type="number" class="form-control form-control-sm" id="costBFx"
                           value="1" min="0.00000001" step="0.00000001">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">&nbsp;</label>
                    <button type="button" class="btn btn-success btn-sm w-100" onclick="addCostB()">
                        <i class="bi bi-plus"></i> Add Cost
                    </button>
                </div>
            </div>
            <div class="row g-2 mb-3">
                <div class="col-md-3">
                    <label class="form-label small mb-1">Amount (in line currency)</label>
                    <input type="number" class="form-control form-control-sm" id="costBAmount"
                           placeholder="Amount" min="0" step="0.01" oninput="onCostBVatRecalc()">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">VAT %</label>
                    <input type="number" class="form-control form-control-sm" id="costBVatPct"
                           placeholder="0.00" min="0" step="0.01" oninput="onCostBVatRecalc()">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">VAT Amount</label>
                    <input type="number" class="form-control form-control-sm" id="costBVatAmount"
                           placeholder="0.00" min="0" step="0.01" readonly>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>Expense / Duties Ledger</th>
                            <th class="text-end">Amount</th>
                            <th>Beneficiary</th>
                            <th class="text-center">Currency + FX</th>
                            <th class="text-end">VAT %</th>
                            <th class="text-end">VAT Amount</th>
                            <th class="text-center" style="width: 80px;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="costsTableBody">
                        <tr id="noCostsRow">
                            <td colspan="7" class="text-center text-muted">No third-party costs added</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="table-active">
                            <th colspan="5" class="text-end">Total Third-party Costs (in base currency):</th>
                            <th class="text-end" id="modalTotalCosts">0.00</th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Other Costs (not added to costing) Card (Bucket C) -->
    <div class="card mb-3">
        <div class="card-header">
            <h5 class="mb-0">
                <i class="bi bi-cash-coin"></i>
                Other Costs (not added to costing)
                <small class="text-muted">— paid to other beneficiaries and posted as their own journal voucher, but NOT added to landed cost.</small>
            </h5>
        </div>
        <div class="card-body">
            <!-- Add row: 2-row grid to fit all 6 fields without crowding -->
            <div class="row g-2 mb-2">
                <div class="col-md-3">
                    <label class="form-label small mb-1">Expense / Duties Ledger</label>
                    <select class="form-select form-select-sm" id="costCLedger">
                        <option value="">-- Select Ledger --</option>
                        <?php foreach ($costLedgers as $lg): ?>
                        <option value="<?php echo (int)$lg['id']; ?>"
                                data-name="<?php echo htmlspecialchars($lg['name']); ?>">
                            <?php echo htmlspecialchars($lg['name']); ?>
                            <small>(<?php echo htmlspecialchars($lg['account_type']); ?>)</small>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1">Beneficiary (Supplier)</label>
                    <select class="form-select form-select-sm" id="costCBeneficiary">
                        <option value="">-- Select Beneficiary --</option>
                        <?php foreach ($suppliers as $s): ?>
                        <option value="<?php echo (int)$s['id']; ?>"
                                data-name="<?php echo htmlspecialchars($s['name']); ?>">
                            <?php echo htmlspecialchars($s['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Currency</label>
                    <select class="form-select form-select-sm" id="costCCurrency" onchange="onCostCCurrencyChange()">
                        <?php foreach ($allCurrencies as $c): ?>
                        <option value="<?php echo (int)$c['id']; ?>"
                                data-code="<?php echo htmlspecialchars($c['code']); ?>"
                                data-is-default="<?php echo (int)$c['is_default']; ?>"
                            <?php echo ((int)$c['is_default'] === 1) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['code']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">FX Rate to Base</label>
                    <input type="number" class="form-control form-control-sm" id="costCFx"
                           value="1" min="0.00000001" step="0.00000001">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">&nbsp;</label>
                    <button type="button" class="btn btn-success btn-sm w-100" onclick="addCostC()">
                        <i class="bi bi-plus"></i> Add Cost
                    </button>
                </div>
            </div>
            <div class="row g-2 mb-3">
                <div class="col-md-3">
                    <label class="form-label small mb-1">Amount (in line currency)</label>
                    <input type="number" class="form-control form-control-sm" id="costCAmount"
                           placeholder="Amount" min="0" step="0.01" oninput="onCostCVatRecalc()">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">VAT %</label>
                    <input type="number" class="form-control form-control-sm" id="costCVatPct"
                           placeholder="0.00" min="0" step="0.01" oninput="onCostCVatRecalc()">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">VAT Amount</label>
                    <input type="number" class="form-control form-control-sm" id="costCVatAmount"
                           placeholder="0.00" min="0" step="0.01" readonly>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>Expense / Duties Ledger</th>
                            <th class="text-end">Amount</th>
                            <th>Beneficiary</th>
                            <th class="text-center">Currency + FX</th>
                            <th class="text-end">VAT %</th>
                            <th class="text-end">VAT Amount</th>
                            <th class="text-center" style="width: 80px;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="costsCTableBody">
                        <tr id="noCostsCRow">
                            <td colspan="7" class="text-center text-muted">No other costs added</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="table-active">
                            <th colspan="5" class="text-end">Total Other Costs (in base currency):</th>
                            <th class="text-end" id="modalTotalCostsC">0.00</th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <small class="text-muted">
                <i class="bi bi-info-circle"></i>
                These post journal vouchers like third-party costs but are excluded from the costing factor below.
            </small>
        </div>
    </div>

    <!-- Costing Summary Card -->
    <div class="card mb-3 bg-light">
        <div class="card-body">
            <div class="row text-center">
                <div class="col-md-3">
                    <small class="text-muted">Total FOB</small>
                    <h5 id="summaryFob">0.00</h5>
                </div>
                <div class="col-md-1 d-flex align-items-center justify-content-center">
                    <h4>+</h4>
                </div>
                <div class="col-md-3">
                    <small class="text-muted">Additional Costs</small>
                    <h5 id="summaryCosts">0.00</h5>
                </div>
                <div class="col-md-1 d-flex align-items-center justify-content-center">
                    <h4>=</h4>
                </div>
                <div class="col-md-2">
                    <small class="text-muted">Total Landed</small>
                    <h5 class="text-success" id="summaryLanded">0.00</h5>
                </div>
                <div class="col-md-2">
                    <small class="text-muted">Costing Factor</small>
                    <h5 class="text-primary" id="summaryFactor">1.0000</h5>
                </div>
            </div>
            <div class="row text-center mt-2" id="summaryOtherCostsRow" style="display:none;">
                <div class="col-12">
                    <small class="text-muted">
                        Other Costs (not in landed):
                        <span class="fw-bold" id="summaryOtherCosts">0.00</span>
                        <i class="bi bi-info-circle" title="Posted as journal vouchers but excluded from the costing factor / landed cost."></i>
                    </small>
                </div>
            </div>
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="card">
        <div class="card-body">
            <div class="d-flex justify-content-between">
                <a href="purchase_imports.php" class="btn btn-secondary">
                    <i class="bi bi-x-circle"></i> Cancel
                </a>
                <button type="button" class="btn btn-primary" onclick="saveImport()" id="saveBtn">
                    <i class="bi bi-check-circle"></i> Save Import
                </button>
            </div>
        </div>
    </div>
</form>

<style>
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
const isEdit = <?php echo $isEdit ? 'true' : 'false'; ?>;
const defaultCurrencyId = <?php echo (int)($defaultCurrencyId ?? 0); ?>;
const baseCurrencyCode = '<?php echo addslashes($defaultCurrency['code'] ?? 'TZS'); ?>';

// Initial state from PHP
let itemsArray = <?php echo json_encode(array_map(function($d) {
    return [
        'product_id' => (int)$d['product_id'],
        'product_name' => $d['product_name'],
        'unit' => $d['unit'],
        'quantity' => floatval($d['quantity']),
        'fob_rate_per_unit' => floatval($d['fob_rate_per_unit']),
        'selling_price' => isset($d['selling_price']) && $d['selling_price'] !== null ? floatval($d['selling_price']) : null,
    ];
}, $importDetails)); ?>;

// Bucket B (third-party costs). Each row needs ledger + beneficiary + currency
// + FX + VAT% + VAT amount. Legacy rows from before Phase 0 have only
// description+amount; those nullables surface here and the row renders in a
// muted "legacy" style until the user reassigns ledger/beneficiary.
let costsArray = <?php echo json_encode(array_map(function($c) {
    return [
        'ledger_id'              => isset($c['ledger_id']) && $c['ledger_id'] ? (int)$c['ledger_id'] : null,
        'ledger_name'            => $c['ledger_name'] ?? null,
        'description'            => $c['description'] ?? null,
        'amount'                 => floatval($c['amount']),
        'beneficiary_supplier_id'=> isset($c['beneficiary_supplier_id']) && $c['beneficiary_supplier_id'] ? (int)$c['beneficiary_supplier_id'] : null,
        'beneficiary_name'       => $c['beneficiary_name'] ?? null,
        'currency_id'            => isset($c['currency_id']) && $c['currency_id'] ? (int)$c['currency_id'] : null,
        'currency_code'          => $c['currency_code'] ?? null,
        'exchange_rate_to_base'  => isset($c['exchange_rate_to_base']) ? floatval($c['exchange_rate_to_base']) : null,
        'vat_percent'            => isset($c['vat_percent']) && $c['vat_percent'] !== null ? floatval($c['vat_percent']) : null,
        'vat_amount'             => floatval($c['vat_amount'] ?? 0),
        'is_legacy'              => empty($c['ledger_id']),
    ];
}, $importCosts)); ?>;

// Bucket A: supplier-borne additional costs. Persisted in purchase_import_costs
// with bucket='A'. Each row needs a ledger pick (Expense/Duties) and amount.
// Description is auto-derived from the ledger name on save (UI has no description field).
let costsArrayA = <?php echo json_encode(array_map(function($c) {
    return [
        'ledger_id'   => (int)$c['ledger_id'],
        'ledger_name' => $c['ledger_name'] ?? '',
        'amount'      => floatval($c['amount']),
    ];
}, $importCostsA)); ?>;

// Bucket C: "Other Costs (not added to costing)". Same row shape as Bucket B
// (ledger + beneficiary + currency + FX + VAT) and posted as journal vouchers,
// but excluded from the costing factor / landed cost.
let costsArrayC = <?php echo json_encode(array_map(function($c) {
    return [
        'ledger_id'              => isset($c['ledger_id']) && $c['ledger_id'] ? (int)$c['ledger_id'] : null,
        'ledger_name'            => $c['ledger_name'] ?? null,
        'description'            => $c['description'] ?? null,
        'amount'                 => floatval($c['amount']),
        'beneficiary_supplier_id'=> isset($c['beneficiary_supplier_id']) && $c['beneficiary_supplier_id'] ? (int)$c['beneficiary_supplier_id'] : null,
        'beneficiary_name'       => $c['beneficiary_name'] ?? null,
        'currency_id'            => isset($c['currency_id']) && $c['currency_id'] ? (int)$c['currency_id'] : null,
        'currency_code'          => $c['currency_code'] ?? null,
        'exchange_rate_to_base'  => isset($c['exchange_rate_to_base']) ? floatval($c['exchange_rate_to_base']) : null,
        'vat_percent'            => isset($c['vat_percent']) && $c['vat_percent'] !== null ? floatval($c['vat_percent']) : null,
        'vat_amount'             => floatval($c['vat_amount'] ?? 0),
        'is_legacy'              => empty($c['ledger_id']),
    ];
}, $importCostsC)); ?>;

let selectedCurrencySymbol = '<?php echo addslashes(isset($defaultCurrencySymbol) ? $defaultCurrencySymbol : 'TZS'); ?>';

// Resolve a usable display label from a currency dropdown option.
// Prefers the ISO 4217 code (USD, TZS, EUR — always correct and unique)
// over the user-typed `symbol` field, which can be wrong or duplicated
// across currencies and historically caused USD rows to display "TZS".
function resolveCurrencyLabel(opt) {
    if (!opt) return 'TZS';
    const code = (opt.dataset.code || '').trim();
    if (code) return code;
    const sym = (opt.dataset.symbol || '').trim();
    return sym || 'TZS';
}

document.addEventListener('DOMContentLoaded', function() {
    // Initialise currency symbol from currently-selected option
    const currencySel = document.getElementById('currencyId');
    if (currencySel && currencySel.options.length) {
        selectedCurrencySymbol = resolveCurrencyLabel(currencySel.options[currencySel.selectedIndex]);
    }

    // If non-default currency is selected (edit case), show FX rate row
    const initialCurrencyId = parseInt(currencySel.value);
    if (initialCurrencyId && initialCurrencyId !== defaultCurrencyId) {
        document.getElementById('exchangeRateGroup').style.display = '';
    }

    // Select2 on the long-list dropdowns. width:'100%' makes them fill the
    // Bootstrap column instead of collapsing to fit the placeholder text.
    $('#productSelect').select2({
        width: '100%',
        placeholder: '-- Select Product --'
    });
    $('#supplierId').select2({
        width: '100%',
        placeholder: '-- Select Supplier --'
    });
    $('#locationId').select2({
        width: '100%',
        placeholder: '-- Select Location --'
    });
    $('#costCentreId').select2({
        width: '100%',
        placeholder: '-- None --',
        allowClear: true
    });
    $('#costBBeneficiary').select2({
        width: '100%',
        placeholder: '-- Select Beneficiary --'
    });
    $('#costCBeneficiary').select2({
        width: '100%',
        placeholder: '-- Select Beneficiary --'
    });

    // Auto-select single available location — use jQuery + trigger('change')
    // so Select2's widget updates to reflect the programmatic value change.
    const locSel = document.getElementById('locationId');
    if (!isEdit && locSel && !locSel.value) {
        const opts = Array.from(locSel.options).filter(o => o.value);
        if (opts.length === 1) {
            $('#locationId').val(opts[0].value).trigger('change');
        }
    }

    updateItemsTable();
    updateCostsTableA();
    updateCostsTable();
    updateCostsTableC();
    updateSummary();
});

// ==================== CURRENCY ====================

function onCurrencyChange() {
    const select = document.getElementById('currencyId');
    const opt = select.options[select.selectedIndex];
    selectedCurrencySymbol = resolveCurrencyLabel(opt);

    const currId = parseInt(select.value);
    const exchangeRateGroup = document.getElementById('exchangeRateGroup');
    if (currId === defaultCurrencyId) {
        exchangeRateGroup.style.display = 'none';
        document.getElementById('exchangeRateToBase').value = '1';
        document.getElementById('exchangeRateHint').textContent = '';
    } else {
        exchangeRateGroup.style.display = '';
        fetchExchangeRate(currId);
    }
    updateItemsTable();
    updateCostsTable();
    updateSummary();
}

async function fetchExchangeRate(currencyId) {
    try {
        const url = `${BASE_URL}api/exchange-rates.php?from_currency_id=${currencyId}&to_currency_id=${defaultCurrencyId}&_=${Date.now()}`;
        const response = await fetch(url, { headers: { 'Cache-Control': 'no-cache' }, cache: 'no-store' });
        if (response.ok) {
            const result = await response.json();
            if (result.success && result.exchange_rates && result.exchange_rates.length > 0) {
                const importDate = document.getElementById('importDate').value || new Date().toISOString().split('T')[0];
                const applicable = result.exchange_rates.filter(r => r.effective_date <= importDate);
                if (applicable.length > 0) {
                    document.getElementById('exchangeRateToBase').value = applicable[0].rate;
                    const fromOpt = document.getElementById('currencyId').options[document.getElementById('currencyId').selectedIndex];
                    const defOpt = Array.from(document.getElementById('currencyId').options).find(o => parseInt(o.value) === defaultCurrencyId);
                    const fromCode = fromOpt ? fromOpt.dataset.code : '';
                    const toCode = defOpt ? defOpt.dataset.code : '';
                    document.getElementById('exchangeRateHint').textContent =
                        `1 ${fromCode} = ${applicable[0].rate} ${toCode}`;
                    return;
                }
            }
        }
    } catch (error) {
        console.error('Failed to fetch exchange rate:', error);
    }
    document.getElementById('exchangeRateHint').textContent = 'No rate found - please enter manually';
}

// ==================== ITEMS ====================

function addItem() {
    const productSelect = document.getElementById('productSelect');
    const productId = productSelect.value;
    const quantity = parseFloat(document.getElementById('itemQuantity').value);
    const fobRate = parseFloat(document.getElementById('itemFobRate').value);
    const sellingPriceVal = document.getElementById('itemSellingPrice').value;
    const sellingPrice = sellingPriceVal !== '' ? parseFloat(sellingPriceVal) : null;

    if (!productId) { showToast('Please select a product', 'warning'); return; }
    if (!quantity || quantity <= 0) { showToast('Please enter a valid quantity', 'warning'); return; }
    if (isNaN(fobRate) || fobRate < 0) { showToast('Please enter a valid FOB rate', 'warning'); return; }

    const existingIndex = itemsArray.findIndex(item => item.product_id == productId);
    if (existingIndex !== -1) {
        showToast('This product is already added. Edit it inline instead.', 'warning');
        return;
    }

    const opt = productSelect.options[productSelect.selectedIndex];
    const productName = opt.dataset.name || opt.textContent.trim();
    const unit = opt.dataset.unit || '';

    itemsArray.push({
        product_id: parseInt(productId),
        product_name: productName,
        unit: unit,
        quantity: quantity,
        fob_rate_per_unit: fobRate,
        selling_price: sellingPrice
    });

    // Reset add-item row
    $('#productSelect').val('').trigger('change');
    document.getElementById('itemQuantity').value = '';
    document.getElementById('itemFobRate').value = '';
    document.getElementById('itemSellingPrice').value = '';

    updateItemsTable();
    updateSummary();
}

function removeItem(index) {
    if (confirm('Remove this item?')) {
        itemsArray.splice(index, 1);
        updateItemsTable();
        updateSummary();
    }
}

function editItemField(index, field, cell) {
    const currentVal = itemsArray[index][field];
    const input = document.createElement('input');
    input.type = 'number';
    input.value = currentVal !== null && currentVal !== undefined ? currentVal : '';
    input.step = field === 'quantity' ? '0.001' : '0.01';
    input.min = field === 'quantity' ? '0.001' : '0';
    input.className = 'form-control form-control-sm';

    const originalContent = cell.innerHTML;
    cell.innerHTML = '';
    cell.appendChild(input);
    cell.classList.add('edit-mode');
    input.focus();
    input.select();

    const saveEdit = () => {
        const newVal = input.value !== '' ? parseFloat(input.value) : null;
        if (field === 'quantity' && (!newVal || newVal <= 0)) {
            cell.innerHTML = originalContent;
            cell.classList.remove('edit-mode');
            showToast('Invalid quantity', 'warning');
            return;
        }
        if (field === 'fob_rate_per_unit' && newVal !== null && newVal < 0) {
            cell.innerHTML = originalContent;
            cell.classList.remove('edit-mode');
            showToast('Rate cannot be negative', 'warning');
            return;
        }
        itemsArray[index][field] = newVal;
        updateItemsTable();
        updateSummary();
    };

    input.addEventListener('blur', saveEdit);
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); saveEdit(); }
        if (e.key === 'Escape') { cell.innerHTML = originalContent; cell.classList.remove('edit-mode'); }
    });
}

// Costing factor is a currency-agnostic ratio. We compute it in BASE currency
// because Bucket B lines can each have their own line currency + FX rate.
//   FOB:      in header currency  →  × headerFX  → base
//   Bucket A: in header currency  →  × headerFX  → base
//   Bucket B: in line currency    →  × lineFX    → base   (legacy rows default
//                                                          to headerFX)
//   VAT is reclaimable; NOT included in landed.
function getCostingFactor() {
    const fxHeader = parseFloat(document.getElementById('exchangeRateToBase').value) || 1;
    const totalFobHeader = itemsArray.reduce((sum, item) => sum + (item.quantity * item.fob_rate_per_unit), 0);
    if (totalFobHeader <= 0) return 1;
    const totalFobBase   = totalFobHeader * fxHeader;
    const totalCostsABase = costsArrayA.reduce((sum, c) => sum + parseFloat(c.amount || 0), 0) * fxHeader;
    const totalCostsBBase = costsArray.reduce((sum, c) => {
        const a  = parseFloat(c.amount || 0);
        const fx = parseFloat(c.exchange_rate_to_base || fxHeader);
        return sum + (a * fx);
    }, 0);
    return (totalFobBase + totalCostsABase + totalCostsBBase) / totalFobBase;
}

function updateItemsTable() {
    const tbody = document.getElementById('itemsTableBody');
    const factor = getCostingFactor();

    if (itemsArray.length === 0) {
        tbody.innerHTML = '<tr id="noItemsRow"><td colspan="9" class="text-center text-muted">No items added yet</td></tr>';
        document.getElementById('modalTotalFob').textContent = '0.00';
        document.getElementById('modalTotalLanded').textContent = '0.00';
        // Keep Payable in sync — VAT may still be typed even with no items.
        if (document.getElementById('modalVatPctToggle')?.checked) {
            onVatPctInput();
        }
        updatePayable();
        return;
    }

    let totalFob = 0;
    let totalLanded = 0;

    tbody.innerHTML = itemsArray.map((item, index) => {
        const fobAmount = item.quantity * item.fob_rate_per_unit;
        const landedRate = item.fob_rate_per_unit * factor;
        const landedAmount = item.quantity * landedRate;
        const profit = item.selling_price !== null ? item.selling_price - landedRate : null;
        totalFob += fobAmount;
        totalLanded += landedAmount;

        return `
            <tr>
                <td>${escapeHtml(item.product_name)}</td>
                <td class="text-center">${escapeHtml(item.unit)}</td>
                <td class="text-end editable-cell" onclick="editItemField(${index}, 'quantity', this)" title="Click to edit">
                    ${formatNumber(item.quantity, 3)}
                    <i class="bi bi-pencil-square ms-1 text-muted" style="font-size: 0.7rem;"></i>
                </td>
                <td class="text-end editable-cell" onclick="editItemField(${index}, 'fob_rate_per_unit', this)" title="Click to edit">
                    ${fmtCurrency(item.fob_rate_per_unit)}
                    <i class="bi bi-pencil-square ms-1 text-muted" style="font-size: 0.7rem;"></i>
                </td>
                <td class="text-end">${fmtCurrency(fobAmount)}</td>
                <td class="text-end text-success fw-bold">${fmtCurrency(landedRate)}</td>
                <td class="text-end editable-cell" onclick="editItemField(${index}, 'selling_price', this)" title="Click to edit">
                    ${item.selling_price !== null ? fmtCurrency(item.selling_price) : '<span class="text-muted">-</span>'}
                    <i class="bi bi-pencil-square ms-1 text-muted" style="font-size: 0.7rem;"></i>
                </td>
                <td class="text-end ${profit !== null ? (profit >= 0 ? 'text-success' : 'text-danger') : ''}">
                    ${profit !== null ? fmtCurrency(profit) : '-'}
                </td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-danger" onclick="removeItem(${index})" title="Remove">
                        <i class="bi bi-trash"></i>
                    </button>
                </td>
            </tr>
        `;
    }).join('');

    document.getElementById('modalTotalFob').textContent = fmtCurrency(totalFob);
    document.getElementById('modalTotalLanded').textContent = fmtCurrency(totalLanded);

    // VAT depends on the FOB total when in % mode, and the Payable row
    // depends on both, so refresh them whenever items change.
    if (document.getElementById('modalVatPctToggle')?.checked) {
        onVatPctInput();
    }
    updatePayable();
}

// ==================== VAT (header-level, supplier-borne) ====================

// Items total in the line currency (excludes VAT, excludes additional costs).
function getItemsTotal() {
    return itemsArray.reduce((sum, it) =>
        sum + (parseFloat(it.quantity || 0) * parseFloat(it.fob_rate_per_unit || 0)), 0);
}

// Payable to supplier = FOB total + VAT. Bucket A costs will join this row
// in a later phase; for now we only have FOB + VAT.
function updatePayable() {
    const total = getItemsTotal();
    const vatInput = document.getElementById('modalVatAmount');
    let vat = parseFloat(vatInput ? vatInput.value : 0);
    if (isNaN(vat) || vat < 0) vat = 0;
    const el = document.getElementById('modalPayableAmount');
    if (el) el.textContent = fmtCurrency(total + vat);
}

// Toggle between flat-amount and percentage modes for VAT. When switching
// ON, derive the equivalent % from any existing VAT amount; when switching
// OFF, clear the % field but keep the amount.
function onVatToggleChange() {
    const toggle = document.getElementById('modalVatPctToggle');
    const pctInput = document.getElementById('modalVatPct');
    const amtInput = document.getElementById('modalVatAmount');
    if (!toggle || !pctInput || !amtInput) return;

    if (toggle.checked) {
        const total = getItemsTotal();
        const vat = parseFloat(amtInput.value) || 0;
        pctInput.value = (total > 0 && vat > 0) ? (vat / total * 100).toFixed(2) : '';
        pctInput.disabled = false;
        amtInput.readOnly = true;
        pctInput.focus();
    } else {
        pctInput.value = '';
        pctInput.disabled = true;
        amtInput.readOnly = false;
    }
    updatePayable();
}

// User typed a %, recompute VAT amount = FOB total × % / 100.
function onVatPctInput() {
    const total = getItemsTotal();
    const pct = parseFloat(document.getElementById('modalVatPct').value);
    let amt = 0;
    if (total > 0 && !isNaN(pct) && pct >= 0) {
        amt = total * pct / 100;
    }
    document.getElementById('modalVatAmount').value = amt.toFixed(2);
    updatePayable();
}

// ==================== SUPPLIER-BORNE COSTS (Bucket A) ====================
// These costs are billed by the same supplier as the import and roll up into
// a single supplier liability (DR cost ledger / CR supplier). They feed into
// the costing factor — landed cost includes them.

function addCostA() {
    const sel = document.getElementById('costALedger');
    const ledgerId = parseInt(sel.value);
    const amount = parseFloat(document.getElementById('costAAmount').value);

    if (!ledgerId) { showToast('Please select a ledger', 'warning'); return; }
    if (isNaN(amount) || amount <= 0) { showToast('Please enter a valid amount', 'warning'); return; }

    if (costsArrayA.some(c => c.ledger_id === ledgerId)) {
        showToast('This ledger is already added. Edit the existing row instead.', 'warning');
        return;
    }

    const opt = sel.options[sel.selectedIndex];
    const ledgerName = opt.dataset.name || opt.textContent.trim();

    costsArrayA.push({
        ledger_id: ledgerId,
        ledger_name: ledgerName,
        amount: amount
    });

    sel.value = '';
    document.getElementById('costAAmount').value = '';

    updateCostsTableA();
    updateItemsTable();  // costing factor changed → re-render landed rates
    updateSummary();
}

function removeCostA(index) {
    if (confirm('Remove this supplier-borne cost?')) {
        costsArrayA.splice(index, 1);
        updateCostsTableA();
        updateItemsTable();
        updateSummary();
    }
}

function editCostAAmount(index, cell) {
    const currentVal = costsArrayA[index].amount;
    const input = document.createElement('input');
    input.type = 'number';
    input.value = currentVal;
    input.step = '0.01';
    input.min = '0';
    input.className = 'form-control form-control-sm';

    const originalContent = cell.innerHTML;
    cell.innerHTML = '';
    cell.appendChild(input);
    cell.classList.add('edit-mode');
    input.focus();
    input.select();

    const saveEdit = () => {
        const newVal = parseFloat(input.value);
        if (isNaN(newVal) || newVal < 0) {
            cell.innerHTML = originalContent;
            cell.classList.remove('edit-mode');
            return;
        }
        costsArrayA[index].amount = newVal;
        updateCostsTableA();
        updateItemsTable();
        updateSummary();
    };

    input.addEventListener('blur', saveEdit);
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); saveEdit(); }
        if (e.key === 'Escape') { cell.innerHTML = originalContent; cell.classList.remove('edit-mode'); }
    });
}

function updateCostsTableA() {
    const tbody = document.getElementById('costsATableBody');

    if (costsArrayA.length === 0) {
        tbody.innerHTML = '<tr id="noCostsARow"><td colspan="3" class="text-center text-muted">No supplier-borne costs added</td></tr>';
        document.getElementById('modalTotalCostsA').textContent = '0.00';
        return;
    }

    let total = 0;
    tbody.innerHTML = costsArrayA.map((cost, index) => {
        total += cost.amount;
        return `
            <tr>
                <td>${escapeHtml(cost.ledger_name)}</td>
                <td class="text-end editable-cell" onclick="editCostAAmount(${index}, this)" title="Click to edit">
                    ${fmtCurrency(cost.amount)}
                    <i class="bi bi-pencil-square ms-1 text-muted" style="font-size: 0.7rem;"></i>
                </td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-danger" onclick="removeCostA(${index})" title="Remove">
                        <i class="bi bi-trash"></i>
                    </button>
                </td>
            </tr>
        `;
    }).join('');

    document.getElementById('modalTotalCostsA').textContent = fmtCurrency(total);
}

// ==================== THIRD-PARTY COSTS (Bucket B) ====================
// Each row is its own journal voucher: DR <ledger> / [DR VAT] / CR <beneficiary>.
// Amount + VAT are in the line's own currency; exchange_rate_to_base converts
// to base for landed-cost math. VAT is reclaimable and does NOT inflate landed.

function getCostBLabel(opt) {
    if (!opt) return '';
    return (opt.dataset.code || '').trim() || opt.textContent.trim();
}

// User changed the currency in the add row — auto-fetch FX vs base and
// pre-fill the FX rate. If line currency == base, FX is locked to 1.
async function onCostBCurrencyChange() {
    const sel = document.getElementById('costBCurrency');
    const fxIn = document.getElementById('costBFx');
    const lineCurId = parseInt(sel.value);
    if (lineCurId === defaultCurrencyId) {
        fxIn.value = '1';
        return;
    }
    try {
        const url = `${BASE_URL}api/exchange-rates.php?from_currency_id=${lineCurId}&to_currency_id=${defaultCurrencyId}&_=${Date.now()}`;
        const resp = await fetch(url, { headers: { 'Cache-Control': 'no-cache' }, cache: 'no-store' });
        if (resp.ok) {
            const r = await resp.json();
            if (r.success && r.exchange_rates && r.exchange_rates.length > 0) {
                const importDate = document.getElementById('importDate').value || new Date().toISOString().split('T')[0];
                const applicable = r.exchange_rates.filter(rr => rr.effective_date <= importDate);
                if (applicable.length > 0) {
                    fxIn.value = applicable[0].rate;
                }
            }
        }
    } catch (e) { console.error('FX fetch failed:', e); }
}

// Recompute VAT amount in the add row whenever amount or VAT% changes.
function onCostBVatRecalc() {
    const amt = parseFloat(document.getElementById('costBAmount').value) || 0;
    const pct = parseFloat(document.getElementById('costBVatPct').value) || 0;
    const vat = (amt > 0 && pct > 0) ? (amt * pct / 100) : 0;
    document.getElementById('costBVatAmount').value = vat.toFixed(2);
}

function addCostB() {
    const ledgerSel  = document.getElementById('costBLedger');
    const benefSel   = document.getElementById('costBBeneficiary');
    const currSel    = document.getElementById('costBCurrency');
    const ledgerId   = parseInt(ledgerSel.value);
    const benefId    = parseInt(benefSel.value);
    const currencyId = parseInt(currSel.value);
    const fx         = parseFloat(document.getElementById('costBFx').value);
    const amount     = parseFloat(document.getElementById('costBAmount').value);
    const vatPct     = parseFloat(document.getElementById('costBVatPct').value) || 0;
    const vatAmount  = parseFloat(document.getElementById('costBVatAmount').value) || 0;

    if (!ledgerId) { showToast('Please select an expense / duties ledger', 'warning'); return; }
    if (!benefId) { showToast('Please select a beneficiary supplier', 'warning'); return; }
    if (!currencyId) { showToast('Please select a currency', 'warning'); return; }
    if (isNaN(fx) || fx <= 0) { showToast('Please enter a valid FX rate', 'warning'); return; }
    if (isNaN(amount) || amount <= 0) { showToast('Please enter a valid amount', 'warning'); return; }

    const ledgerOpt = ledgerSel.options[ledgerSel.selectedIndex];
    const benefOpt  = benefSel.options[benefSel.selectedIndex];
    const currOpt   = currSel.options[currSel.selectedIndex];

    costsArray.push({
        ledger_id: ledgerId,
        ledger_name: ledgerOpt.dataset.name || ledgerOpt.textContent.trim(),
        description: null,
        amount: amount,
        beneficiary_supplier_id: benefId,
        beneficiary_name: benefOpt.dataset.name || benefOpt.textContent.trim(),
        currency_id: currencyId,
        currency_code: getCostBLabel(currOpt),
        exchange_rate_to_base: fx,
        vat_percent: vatPct > 0 ? vatPct : null,
        vat_amount: vatAmount,
        is_legacy: false
    });

    // Reset the add row, keeping the currency/FX selection sticky.
    // costBLedger is a plain select; costBBeneficiary is Select2-wrapped,
    // so it needs .val('').trigger('change') to update its widget.
    ledgerSel.value = '';
    $('#costBBeneficiary').val('').trigger('change');
    document.getElementById('costBAmount').value = '';
    document.getElementById('costBVatPct').value = '';
    document.getElementById('costBVatAmount').value = '';

    updateCostsTable();
    updateItemsTable();
    updateSummary();
}

function removeCostB(index) {
    if (confirm('Remove this third-party cost?')) {
        costsArray.splice(index, 1);
        updateCostsTable();
        updateItemsTable();
        updateSummary();
    }
}

// Inline-edit a numeric field on a Bucket B row. For amount or vat_percent,
// recompute vat_amount automatically.
function editCostBField(index, field, cell) {
    const row = costsArray[index];
    const currentVal = row[field];
    const input = document.createElement('input');
    input.type = 'number';
    input.value = currentVal !== null && currentVal !== undefined ? currentVal : '';
    input.step = '0.01';
    input.min = '0';
    input.className = 'form-control form-control-sm';

    const originalContent = cell.innerHTML;
    cell.innerHTML = '';
    cell.appendChild(input);
    cell.classList.add('edit-mode');
    input.focus();
    input.select();

    const saveEdit = () => {
        const raw = input.value;
        const newVal = raw === '' ? null : parseFloat(raw);
        if (raw !== '' && (isNaN(newVal) || newVal < 0)) {
            cell.innerHTML = originalContent;
            cell.classList.remove('edit-mode');
            return;
        }
        row[field] = newVal;
        if (field === 'amount' || field === 'vat_percent') {
            const a = parseFloat(row.amount || 0);
            const p = parseFloat(row.vat_percent || 0);
            row.vat_amount = (a > 0 && p > 0) ? (a * p / 100) : 0;
        }
        updateCostsTable();
        updateItemsTable();
        updateSummary();
    };

    input.addEventListener('blur', saveEdit);
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); saveEdit(); }
        if (e.key === 'Escape') { cell.innerHTML = originalContent; cell.classList.remove('edit-mode'); }
    });
}

// Inline-edit the Currency + FX of a Bucket B row. Unlike the numeric cells
// this needs two controls (a currency dropdown + an FX input), so it uses an
// explicit focus-out check to avoid saving while the user tabs between them.
// Behaviour mirrors the add row (onCostBCurrencyChange): base currency -> FX
// locked to 1; a foreign currency auto-fetches the rate, but stays manually
// editable when no rate is found.
function editCostBCurrencyFx(index, cell) {
    // The cell's own onclick fires again when the user clicks the <select> or
    // FX input inside it (event bubbling). Without this guard that re-inits the
    // editor and snaps the just-opened dropdown shut.
    if (cell.classList.contains('edit-mode')) return;

    const row = costsArray[index];
    const originalContent = cell.innerHTML;

    // Build the currency dropdown from the same options as the add row.
    const srcSel = document.getElementById('costBCurrency');
    const sel = document.createElement('select');
    sel.className = 'form-select form-select-sm mb-1';
    Array.from(srcSel.options).forEach(o => {
        const opt = document.createElement('option');
        opt.value = o.value;
        opt.textContent = o.textContent.trim();
        opt.dataset.code = o.dataset.code || '';
        opt.dataset.isDefault = o.dataset.isDefault || '0';
        sel.appendChild(opt);
    });
    sel.value = String(row.currency_id || defaultCurrencyId || sel.options[0]?.value || '');

    const fxIn = document.createElement('input');
    fxIn.type = 'number';
    fxIn.step = '0.00000001';
    fxIn.min = '0.00000001';
    fxIn.className = 'form-control form-control-sm';
    fxIn.value = row.exchange_rate_to_base !== null && row.exchange_rate_to_base !== undefined
        ? row.exchange_rate_to_base : 1;

    cell.innerHTML = '';
    cell.classList.add('edit-mode');
    cell.appendChild(sel);
    cell.appendChild(fxIn);
    sel.focus();

    let done = false;
    const cancel = () => {
        if (done) return;
        done = true;
        document.removeEventListener('mousedown', onOutside, true);
        cell.innerHTML = originalContent;
        cell.classList.remove('edit-mode');
    };
    const saveEdit = () => {
        if (done) return;
        const fx = parseFloat(fxIn.value);
        if (isNaN(fx) || fx <= 0) {
            showToast('Please enter a valid FX rate (greater than 0).', 'warning');
            fxIn.focus();
            return;
        }
        done = true;
        document.removeEventListener('mousedown', onOutside, true);
        const opt = sel.options[sel.selectedIndex];
        row.currency_id = parseInt(sel.value) || null;
        row.currency_code = opt ? (opt.dataset.code || opt.textContent.trim()) : row.currency_code;
        row.exchange_rate_to_base = fx;
        updateCostsTable();
        updateItemsTable();
        updateSummary();
    };

    // Commit when the user clicks anywhere outside this cell. Using a
    // click-outside listener (instead of the element blur) avoids the native
    // <select> quirk where opening the dropdown blurs the control and would
    // otherwise close the editor before a choice is made.
    const onOutside = (e) => { if (!cell.contains(e.target)) saveEdit(); };

    // When the currency changes, mirror the add-row behaviour: base -> FX 1,
    // otherwise auto-fetch (leaving the value editable if none is found).
    sel.addEventListener('change', async function() {
        const curId = parseInt(sel.value);
        if (curId === defaultCurrencyId) { fxIn.value = '1'; return; }
        try {
            const url = `${BASE_URL}api/exchange-rates.php?from_currency_id=${curId}&to_currency_id=${defaultCurrencyId}&_=${Date.now()}`;
            const resp = await fetch(url, { headers: { 'Cache-Control': 'no-cache' }, cache: 'no-store' });
            if (resp.ok) {
                const r = await resp.json();
                if (r.success && r.exchange_rates && r.exchange_rates.length > 0) {
                    const importDate = document.getElementById('importDate').value || new Date().toISOString().split('T')[0];
                    const applicable = r.exchange_rates.filter(rr => rr.effective_date <= importDate);
                    if (applicable.length > 0) fxIn.value = applicable[0].rate;
                }
            }
        } catch (e) { console.error('FX fetch failed:', e); }
    });

    // Register on the next tick so the click that opened this editor doesn't
    // immediately trigger the outside-click save. Capture phase so it runs
    // before other handlers.
    setTimeout(() => document.addEventListener('mousedown', onOutside, true), 0);
    [sel, fxIn].forEach(el => el.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); saveEdit(); }
        if (e.key === 'Escape') { e.preventDefault(); cancel(); }
    }));
}

function updateCostsTable() {
    const tbody = document.getElementById('costsTableBody');

    if (costsArray.length === 0) {
        tbody.innerHTML = '<tr id="noCostsRow"><td colspan="7" class="text-center text-muted">No third-party costs added</td></tr>';
        document.getElementById('modalTotalCosts').textContent = '0.00';
        return;
    }

    // Total shown in BASE currency since rows can be in different line currencies.
    let totalBase = 0;
    tbody.innerHTML = costsArray.map((row, index) => {
        const amt = parseFloat(row.amount || 0);
        const fx = parseFloat(row.exchange_rate_to_base || 1);
        const baseAmt = amt * fx;
        totalBase += baseAmt;

        const isLegacy = !!row.is_legacy || !row.ledger_id;
        const ledgerCell = isLegacy
            ? `<span class="text-warning" title="Legacy row — re-save to assign ledger"><i class="bi bi-exclamation-triangle"></i> ${escapeHtml(row.description || row.ledger_name || 'Legacy cost')}</span>`
            : escapeHtml(row.ledger_name || '');
        const benefCell = row.beneficiary_name
            ? escapeHtml(row.beneficiary_name)
            : '<span class="text-muted">—</span>';
        const currCell = (row.currency_code || '?') + ' @ ' + parseFloat(row.exchange_rate_to_base || 1).toFixed(4);
        const vatPctCell = row.vat_percent !== null && row.vat_percent !== undefined && row.vat_percent !== ''
            ? parseFloat(row.vat_percent).toFixed(2) + '%'
            : '<span class="text-muted">—</span>';

        return `
            <tr>
                <td>${ledgerCell}</td>
                <td class="text-end editable-cell" onclick="editCostBField(${index}, 'amount', this)" title="Click to edit">
                    ${formatNumber(amt, 2)}
                    <i class="bi bi-pencil-square ms-1 text-muted" style="font-size: 0.7rem;"></i>
                </td>
                <td>${benefCell}</td>
                <td class="text-center editable-cell" onclick="editCostBCurrencyFx(${index}, this)" title="Click to edit currency / FX">
                    <small>${escapeHtml(currCell)}</small>
                    <i class="bi bi-pencil-square ms-1 text-muted" style="font-size: 0.7rem;"></i>
                </td>
                <td class="text-end editable-cell" onclick="editCostBField(${index}, 'vat_percent', this)" title="Click to edit">
                    ${vatPctCell}
                    <i class="bi bi-pencil-square ms-1 text-muted" style="font-size: 0.7rem;"></i>
                </td>
                <td class="text-end">${formatNumber(row.vat_amount || 0, 2)}</td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-danger" onclick="removeCostB(${index})" title="Remove">
                        <i class="bi bi-trash"></i>
                    </button>
                </td>
            </tr>
        `;
    }).join('');

    document.getElementById('modalTotalCosts').textContent = baseCurrencyCode + ' ' + formatNumber(totalBase, 2);
}

// ==================== OTHER COSTS — NOT ADDED TO COSTING (Bucket C) ====================
// Same accounting as Bucket B: each row posts its own journal voucher
// (DR ledger / [DR VAT] / CR beneficiary). The difference is purely that these
// rows are NOT fed into getCostingFactor() / landed cost — they are a period
// expense, not a capitalized import cost.

// User changed the currency in the add row — auto-fetch FX vs base.
async function onCostCCurrencyChange() {
    const sel = document.getElementById('costCCurrency');
    const fxIn = document.getElementById('costCFx');
    const lineCurId = parseInt(sel.value);
    if (lineCurId === defaultCurrencyId) {
        fxIn.value = '1';
        return;
    }
    try {
        const url = `${BASE_URL}api/exchange-rates.php?from_currency_id=${lineCurId}&to_currency_id=${defaultCurrencyId}&_=${Date.now()}`;
        const resp = await fetch(url, { headers: { 'Cache-Control': 'no-cache' }, cache: 'no-store' });
        if (resp.ok) {
            const r = await resp.json();
            if (r.success && r.exchange_rates && r.exchange_rates.length > 0) {
                const importDate = document.getElementById('importDate').value || new Date().toISOString().split('T')[0];
                const applicable = r.exchange_rates.filter(rr => rr.effective_date <= importDate);
                if (applicable.length > 0) {
                    fxIn.value = applicable[0].rate;
                }
            }
        }
    } catch (e) { console.error('FX fetch failed:', e); }
}

// Recompute VAT amount in the add row whenever amount or VAT% changes.
function onCostCVatRecalc() {
    const amt = parseFloat(document.getElementById('costCAmount').value) || 0;
    const pct = parseFloat(document.getElementById('costCVatPct').value) || 0;
    const vat = (amt > 0 && pct > 0) ? (amt * pct / 100) : 0;
    document.getElementById('costCVatAmount').value = vat.toFixed(2);
}

function addCostC() {
    const ledgerSel  = document.getElementById('costCLedger');
    const benefSel   = document.getElementById('costCBeneficiary');
    const currSel    = document.getElementById('costCCurrency');
    const ledgerId   = parseInt(ledgerSel.value);
    const benefId    = parseInt(benefSel.value);
    const currencyId = parseInt(currSel.value);
    const fx         = parseFloat(document.getElementById('costCFx').value);
    const amount     = parseFloat(document.getElementById('costCAmount').value);
    const vatPct     = parseFloat(document.getElementById('costCVatPct').value) || 0;
    const vatAmount  = parseFloat(document.getElementById('costCVatAmount').value) || 0;

    if (!ledgerId) { showToast('Please select an expense / duties ledger', 'warning'); return; }
    if (!benefId) { showToast('Please select a beneficiary supplier', 'warning'); return; }
    if (!currencyId) { showToast('Please select a currency', 'warning'); return; }
    if (isNaN(fx) || fx <= 0) { showToast('Please enter a valid FX rate', 'warning'); return; }
    if (isNaN(amount) || amount <= 0) { showToast('Please enter a valid amount', 'warning'); return; }

    const ledgerOpt = ledgerSel.options[ledgerSel.selectedIndex];
    const benefOpt  = benefSel.options[benefSel.selectedIndex];
    const currOpt   = currSel.options[currSel.selectedIndex];

    costsArrayC.push({
        ledger_id: ledgerId,
        ledger_name: ledgerOpt.dataset.name || ledgerOpt.textContent.trim(),
        description: null,
        amount: amount,
        beneficiary_supplier_id: benefId,
        beneficiary_name: benefOpt.dataset.name || benefOpt.textContent.trim(),
        currency_id: currencyId,
        currency_code: getCostBLabel(currOpt),
        exchange_rate_to_base: fx,
        vat_percent: vatPct > 0 ? vatPct : null,
        vat_amount: vatAmount,
        is_legacy: false
    });

    // Reset the add row, keeping the currency/FX selection sticky.
    ledgerSel.value = '';
    $('#costCBeneficiary').val('').trigger('change');
    document.getElementById('costCAmount').value = '';
    document.getElementById('costCVatPct').value = '';
    document.getElementById('costCVatAmount').value = '';

    updateCostsTableC();
    updateSummary();
}

function removeCostC(index) {
    if (confirm('Remove this cost?')) {
        costsArrayC.splice(index, 1);
        updateCostsTableC();
        updateSummary();
    }
}

// Inline-edit a numeric field on a Bucket C row. For amount or vat_percent,
// recompute vat_amount automatically.
function editCostCField(index, field, cell) {
    const row = costsArrayC[index];
    const currentVal = row[field];
    const input = document.createElement('input');
    input.type = 'number';
    input.value = currentVal !== null && currentVal !== undefined ? currentVal : '';
    input.step = '0.01';
    input.min = '0';
    input.className = 'form-control form-control-sm';

    const originalContent = cell.innerHTML;
    cell.innerHTML = '';
    cell.appendChild(input);
    cell.classList.add('edit-mode');
    input.focus();
    input.select();

    const saveEdit = () => {
        const raw = input.value;
        const newVal = raw === '' ? null : parseFloat(raw);
        if (raw !== '' && (isNaN(newVal) || newVal < 0)) {
            cell.innerHTML = originalContent;
            cell.classList.remove('edit-mode');
            return;
        }
        row[field] = newVal;
        if (field === 'amount' || field === 'vat_percent') {
            const a = parseFloat(row.amount || 0);
            const p = parseFloat(row.vat_percent || 0);
            row.vat_amount = (a > 0 && p > 0) ? (a * p / 100) : 0;
        }
        updateCostsTableC();
        updateSummary();
    };

    input.addEventListener('blur', saveEdit);
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); saveEdit(); }
        if (e.key === 'Escape') { cell.innerHTML = originalContent; cell.classList.remove('edit-mode'); }
    });
}

function updateCostsTableC() {
    const tbody = document.getElementById('costsCTableBody');

    if (costsArrayC.length === 0) {
        tbody.innerHTML = '<tr id="noCostsCRow"><td colspan="7" class="text-center text-muted">No other costs added</td></tr>';
        document.getElementById('modalTotalCostsC').textContent = '0.00';
        return;
    }

    // Total shown in BASE currency since rows can be in different line currencies.
    let totalBase = 0;
    tbody.innerHTML = costsArrayC.map((row, index) => {
        const amt = parseFloat(row.amount || 0);
        const fx = parseFloat(row.exchange_rate_to_base || 1);
        totalBase += amt * fx;

        const isLegacy = !!row.is_legacy || !row.ledger_id;
        const ledgerCell = isLegacy
            ? `<span class="text-warning" title="Legacy row — re-save to assign ledger"><i class="bi bi-exclamation-triangle"></i> ${escapeHtml(row.description || row.ledger_name || 'Legacy cost')}</span>`
            : escapeHtml(row.ledger_name || '');
        const benefCell = row.beneficiary_name
            ? escapeHtml(row.beneficiary_name)
            : '<span class="text-muted">—</span>';
        const currCell = (row.currency_code || '?') + ' @ ' + parseFloat(row.exchange_rate_to_base || 1).toFixed(4);
        const vatPctCell = row.vat_percent !== null && row.vat_percent !== undefined && row.vat_percent !== ''
            ? parseFloat(row.vat_percent).toFixed(2) + '%'
            : '<span class="text-muted">—</span>';

        return `
            <tr>
                <td>${ledgerCell}</td>
                <td class="text-end editable-cell" onclick="editCostCField(${index}, 'amount', this)" title="Click to edit">
                    ${formatNumber(amt, 2)}
                    <i class="bi bi-pencil-square ms-1 text-muted" style="font-size: 0.7rem;"></i>
                </td>
                <td>${benefCell}</td>
                <td class="text-center"><small>${escapeHtml(currCell)}</small></td>
                <td class="text-end editable-cell" onclick="editCostCField(${index}, 'vat_percent', this)" title="Click to edit">
                    ${vatPctCell}
                    <i class="bi bi-pencil-square ms-1 text-muted" style="font-size: 0.7rem;"></i>
                </td>
                <td class="text-end">${formatNumber(row.vat_amount || 0, 2)}</td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-danger" onclick="removeCostC(${index})" title="Remove">
                        <i class="bi bi-trash"></i>
                    </button>
                </td>
            </tr>
        `;
    }).join('');

    document.getElementById('modalTotalCostsC').textContent = baseCurrencyCode + ' ' + formatNumber(totalBase, 2);
}

function updateSummary() {
    const fxHeader = parseFloat(document.getElementById('exchangeRateToBase').value) || 1;
    const totalFob = itemsArray.reduce((sum, item) => sum + (item.quantity * item.fob_rate_per_unit), 0);
    const totalCostsA = costsArrayA.reduce((sum, c) => sum + parseFloat(c.amount || 0), 0);
    // Bucket B amounts are in their own line currency. Convert each to the
    // import-header currency so the displayed "Additional Costs" total is
    // comparable to FOB / Landed (all in header currency).
    const totalCostsBHeader = costsArray.reduce((sum, c) => {
        const a  = parseFloat(c.amount || 0);
        const fx = parseFloat(c.exchange_rate_to_base || fxHeader);
        if (fxHeader <= 0) return sum;
        return sum + (a * fx / fxHeader);
    }, 0);
    const totalCosts = totalCostsA + totalCostsBHeader;
    const totalLanded = totalFob + totalCosts;
    const factor = totalFob > 0 ? totalLanded / totalFob : 1;

    document.getElementById('summaryFob').textContent = fmtCurrency(totalFob);
    document.getElementById('summaryCosts').textContent = fmtCurrency(totalCosts);
    document.getElementById('summaryLanded').textContent = fmtCurrency(totalLanded);
    document.getElementById('summaryFactor').textContent = factor.toFixed(4);

    // Bucket C ("Other Costs") — shown for transparency but deliberately NOT
    // folded into totalCosts / totalLanded / factor above. Display in base
    // currency since rows can each carry their own line currency + FX.
    const totalCostsCBase = costsArrayC.reduce((sum, c) => {
        const a  = parseFloat(c.amount || 0);
        const fx = parseFloat(c.exchange_rate_to_base || 1);
        return sum + (a * fx);
    }, 0);
    const otherRow = document.getElementById('summaryOtherCostsRow');
    const otherEl  = document.getElementById('summaryOtherCosts');
    if (costsArrayC.length > 0) {
        otherEl.textContent = baseCurrencyCode + ' ' + formatNumber(totalCostsCBase, 2);
        otherRow.style.display = '';
    } else {
        otherRow.style.display = 'none';
    }
}

// ==================== SAVE ====================

async function saveImport() {
    const importDate = document.getElementById('importDate').value;
    const supplierId = document.getElementById('supplierId').value;
    const locationId = document.getElementById('locationId').value;

    if (!importDate) { showToast('Please select import date', 'warning'); return; }
    if (!supplierId) { showToast('Please select supplier', 'warning'); return; }
    if (!locationId) { showToast('Please select location', 'warning'); return; }
    if (itemsArray.length === 0) { showToast('Please add at least one item', 'warning'); return; }

    const importId = document.getElementById('importId').value;
    const poNumber = document.getElementById('poNumber').value.trim();
    const costCentreId = document.getElementById('costCentreId').value;
    const currencyId = parseInt(document.getElementById('currencyId').value) || defaultCurrencyId;
    const exchangeRateToBase = parseFloat(document.getElementById('exchangeRateToBase').value) || 1;

    // Foreign-currency imports need a real header exchange rate. A blank/1 rate
    // would leave other-currency additional costs unconverted in the landed
    // total (mirrors the server-side guard in validateImportData). Reveal the
    // rate field and stop here so the user can enter it.
    if (currencyId !== defaultCurrencyId &&
        (!exchangeRateToBase || exchangeRateToBase <= 0 || exchangeRateToBase === 1)) {
        const grp = document.getElementById('exchangeRateGroup');
        if (grp) grp.style.display = '';
        showToast('Please enter a valid exchange rate to the base currency (it cannot be blank or 1) for this foreign-currency import.', 'warning');
        document.getElementById('exchangeRateToBase').focus();
        return;
    }
    let vatAmount = parseFloat(document.getElementById('modalVatAmount').value);
    if (isNaN(vatAmount) || vatAmount < 0) vatAmount = 0;
    const notes = document.getElementById('notes').value;

    const requisitionId = document.getElementById('requisitionId').value;
    const budgetIdEl = document.getElementById('budgetId');
    const budgetId = budgetIdEl ? budgetIdEl.value : '';

    const data = {
        import_date: importDate,
        po_number: poNumber,
        supplier_id: parseInt(supplierId),
        location_id: parseInt(locationId),
        cost_centre_id: costCentreId ? parseInt(costCentreId) : null,
        requisition_id: requisitionId ? parseInt(requisitionId) : null,
        budget_id: budgetId ? parseInt(budgetId) : null,
        currency_id: currencyId,
        exchange_rate_to_base: exchangeRateToBase,
        vat_amount: vatAmount,
        notes: notes,
        details: itemsArray.map(item => ({
            product_id: parseInt(item.product_id),
            quantity: item.quantity,
            fob_rate_per_unit: item.fob_rate_per_unit,
            selling_price: item.selling_price
        })),
        costs_a: costsArrayA.map(c => ({
            ledger_id: c.ledger_id,
            amount: c.amount
        })),
        costs: costsArray.map(c => ({
            // ledger/beneficiary/currency may be NULL for legacy rows — keep
            // them as-is so the API can persist them unchanged.
            ledger_id: c.ledger_id || null,
            description: c.description || null,
            amount: c.amount,
            beneficiary_supplier_id: c.beneficiary_supplier_id || null,
            currency_id: c.currency_id || null,
            exchange_rate_to_base: c.exchange_rate_to_base || null,
            vat_percent: c.vat_percent || null,
            vat_amount: c.vat_amount || 0,
            is_legacy: !!c.is_legacy
        })),
        // Bucket C: "Other Costs (not added to costing)". Same payload shape as
        // Bucket B; the API stores them with bucket='C' and excludes them from
        // the costing factor.
        costs_c: costsArrayC.map(c => ({
            ledger_id: c.ledger_id || null,
            description: c.description || null,
            amount: c.amount,
            beneficiary_supplier_id: c.beneficiary_supplier_id || null,
            currency_id: c.currency_id || null,
            exchange_rate_to_base: c.exchange_rate_to_base || null,
            vat_percent: c.vat_percent || null,
            vat_amount: c.vat_amount || 0,
            is_legacy: !!c.is_legacy
        }))
    };

    const saveBtn = document.getElementById('saveBtn');
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';

    try {
        const action = isEdit ? 'update' : 'create';
        if (isEdit && importId) {
            data.id = parseInt(importId);
        }

        const response = await fetch(BASE_URL + 'api/purchase-imports-sync.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: action, data: data })
        });

        const result = await response.json();
        if (result.success) {
            // Redirect back to listing, with view-modal auto-open on the saved
            // import. Also pass the import's date so the listing widens its
            // (default = today) date filter to include this record — otherwise
            // a back-dated import is filtered out server-side and the auto-open
            // fails with "Import not found".
            const savedId = result.purchase_import ? result.purchase_import.id : (result.serverId || importId);
            let target = 'purchase_imports.php';
            if (savedId) {
                target += `?view=${savedId}`;
                if (importDate) target += `&date=${encodeURIComponent(importDate)}`;
            }
            window.location.href = target;
        } else {
            const errorMsg = result.details ? Object.values(result.details).join(', ') : result.error;
            showToast('Error: ' + errorMsg, 'danger');
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i class="bi bi-check-circle"></i> Save Import';
        }
    } catch (error) {
        console.error('Save failed:', error);
        showToast('Failed to save: ' + error.message, 'danger');
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<i class="bi bi-check-circle"></i> Save Import';
    }
}

// ==================== UTILITIES ====================

function fmtCurrency(amount) {
    const n = parseFloat(amount);
    const s = selectedCurrencySymbol || 'TZS';
    if (isNaN(n)) return s + ' 0.00';
    return s + ' ' + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
}

function formatNumber(num, decimals = 2) {
    const n = parseFloat(num);
    if (isNaN(n)) return '0.00';
    return n.toFixed(decimals).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
}

function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    const div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML;
}

function showToast(message, type = 'info') {
    document.querySelectorAll('.import-toast').forEach(t => t.remove());
    const toast = document.createElement('div');
    toast.className = `import-toast alert alert-${type} alert-dismissible fade show`;
    toast.style.cssText = 'position:fixed;top:80px;right:20px;z-index:9999;min-width:300px;box-shadow:0 4px 6px rgba(0,0,0,0.2);';
    toast.innerHTML = `${message}<button type="button" class="btn-close" data-bs-dismiss="alert"></button>`;
    document.body.appendChild(toast);
    setTimeout(() => { toast.classList.remove('show'); setTimeout(() => toast.remove(), 150); }, 5000);
}
</script>

<?php require_once '../../includes/footer.php'; ?>
