<?php
/**
 * Purchase Imports Sync API - Handles CRUD operations
 * Manages purchase_imports, purchase_import_details, purchase_import_costs, and stock_batches
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is logged in
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['action'])) {
        throw new Exception('Action not specified');
    }

    switch ($input['action']) {
        case 'create':
            createPurchaseImport($conn, $input['data']);
            break;

        case 'update':
            updatePurchaseImport($conn, $input['data']);
            break;

        case 'delete':
            deletePurchaseImport($conn, $input['data']);
            break;

        default:
            throw new Exception('Invalid action');
    }

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Create new purchase import with items, costs, and stock batches
 */
function createPurchaseImport($conn, $data) {
    if (!hasPermission('purchase_imports', 'create')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No permission to create purchase imports']);
        exit;
    }

    // Validate input
    $errors = validateImportData($data, $conn);
    if (!empty($errors)) {
        echo json_encode([
            'success' => false,
            'error' => 'Validation failed',
            'details' => $errors
        ]);
        exit;
    }

    $importDate = clean($data['import_date']);
    $poNumber = clean($data['po_number'] ?? '');
    $supplierId = (int)$data['supplier_id'];
    $locationId = (int)$data['location_id'];
    $costCentreId = !empty($data['cost_centre_id']) ? (int)$data['cost_centre_id'] : null;
    $requisitionId = !empty($data['requisition_id']) ? (int)$data['requisition_id'] : null; // optional link back to requisition
    // Optional link back to the budget (migration 0034). Guarded so this keeps
    // working if the migration hasn't been applied.
    $budgetId = !empty($data['budget_id']) ? (int)$data['budget_id'] : null;
    $hasBudgetCol = false;
    try {
        $cstmt = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_imports' AND COLUMN_NAME = 'budget_id'");
        $hasBudgetCol = ((int)$cstmt->fetchColumn()) > 0;
    } catch (Exception $e) { $hasBudgetCol = false; }
    $currencyId = !empty($data['currency_id']) ? (int)$data['currency_id'] : null;
    $exchangeRateToBase = isset($data['exchange_rate_to_base']) ? floatval($data['exchange_rate_to_base']) : 1.0;
    $vatAmount = isset($data['vat_amount']) ? max(0, floatval($data['vat_amount'])) : 0.0;
    $notes = clean($data['notes'] ?? '');
    $items = $data['details'] ?? [];
    $costsA = $data['costs_a'] ?? []; // Bucket A: supplier-borne
    $costs  = $data['costs'] ?? [];   // Bucket B / legacy
    $costsC = $data['costs_c'] ?? []; // Bucket C: other costs (NOT added to costing)

    // Default currency_id to the system default if not provided
    if (!$currencyId) {
        $defCur = getDefaultCurrency();
        $currencyId = $defCur ? (int)$defCur['id'] : null;
    }

    // Check user has access to this location
    if (!canAccessLocation($locationId)) {
        throw new Exception('You do not have access to this location');
    }

    try {
        $conn->beginTransaction();

        // Generate import number
        $importNumber = getNextNumber('purchase_imports', 'import_number', 'IMP');

        // Calculate totals — Bucket A and Bucket B feed the costing factor (all
        // costs inflate landed cost, regardless of who they're billed to).
        // Bucket C is deliberately NOT included here: it posts its own journal
        // voucher but must not inflate landed cost / inventory value.
        $totalFob = 0;
        foreach ($items as $item) {
            $totalFob += floatval($item['quantity']) * floatval($item['fob_rate_per_unit']);
        }

        // Bucket A amounts are in import-header currency. Bucket B amounts are
        // in each row's own line currency; we convert via the line's FX rate
        // and the header's FX rate so the stored total_additional_costs is in
        // the same currency as total_fob_amount (import-header currency).
        $fxHeader = $exchangeRateToBase > 0 ? $exchangeRateToBase : 1.0;
        $totalAdditionalCosts = 0;
        foreach ($costsA as $c) {
            $totalAdditionalCosts += floatval($c['amount'] ?? 0);
        }
        foreach ($costs as $c) {
            $amt = floatval($c['amount'] ?? 0);
            // Legacy rows (no per-line FX recorded) are assumed to already be
            // in header currency, matching how they were entered originally.
            $lineFx = isset($c['exchange_rate_to_base']) && $c['exchange_rate_to_base']
                ? floatval($c['exchange_rate_to_base']) : $fxHeader;
            $totalAdditionalCosts += ($amt * $lineFx) / $fxHeader;
        }

        // VAT is reclaimable — does NOT go into landed cost / stock basis.
        $totalLanded = $totalFob + $totalAdditionalCosts;
        $costingFactor = $totalFob > 0 ? $totalLanded / $totalFob : 1.0;

        // Insert purchase import header (budget_id column included only when present)
        if ($hasBudgetCol) {
            $stmt = $conn->prepare("
                INSERT INTO purchase_imports
                (import_number, import_date, supplier_id, location_id, cost_centre_id, requisition_id, budget_id, currency_id,
                 exchange_rate_to_base, po_number, total_fob_amount, total_additional_costs,
                 total_landed_amount, costing_factor, vat_amount, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $importNumber, $importDate, $supplierId, $locationId, $costCentreId, $requisitionId, $budgetId, $currencyId,
                $exchangeRateToBase, $poNumber ?: null, $totalFob, $totalAdditionalCosts,
                $totalLanded, $costingFactor, $vatAmount, $notes, getUserId()
            ]);
        } else {
            $stmt = $conn->prepare("
                INSERT INTO purchase_imports
                (import_number, import_date, supplier_id, location_id, cost_centre_id, requisition_id, currency_id,
                 exchange_rate_to_base, po_number, total_fob_amount, total_additional_costs,
                 total_landed_amount, costing_factor, vat_amount, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $importNumber, $importDate, $supplierId, $locationId, $costCentreId, $requisitionId, $currencyId,
                $exchangeRateToBase, $poNumber ?: null, $totalFob, $totalAdditionalCosts,
                $totalLanded, $costingFactor, $vatAmount, $notes, getUserId()
            ]);
        }

        $importId = $conn->lastInsertId();

        // Insert Bucket A (supplier-borne) costs. Description auto-derived from
        // the ledger name since the form has no description input for these.
        insertBucketACosts($conn, $importId, $costsA);

        // Insert Bucket B (third-party) costs. Modern rows have full ledger +
        // beneficiary + currency + VAT data; legacy rows (no ledger_id) save
        // back as just description + amount so they survive a round-trip.
        insertBucketBCosts($conn, $importId, $costs);

        // Insert Bucket C ("Other Costs — not added to costing") rows. Stored
        // exactly like Bucket B and posted as their own journal vouchers, but
        // excluded from total_additional_costs / costing factor above.
        insertBucketCCosts($conn, $importId, $costsC);

        // Insert import details and create stock batches
        foreach ($items as $item) {
            $productId = (int)$item['product_id'];
            $quantity = floatval($item['quantity']);
            $fobRate = floatval($item['fob_rate_per_unit']);
            $landedRate = round($fobRate * $costingFactor, 2);
            // stock_batches.rate_per_unit is a BASE-CURRENCY column: every reader
            // of it (stock value + stock card reports, FIFO in consumption /
            // sales / transfers, profit & loss) treats it as base, and the GL
            // already posts imports in base via createImportTransaction().
            // $landedRate is in the IMPORT HEADER currency, so it must be
            // converted here — storing it raw understated imported stock and
            // cost of sales by the whole exchange rate.
            $landedRateBase = round($fobRate * $costingFactor * $fxHeader, 2);
            $sellingPrice = isset($item['selling_price']) && $item['selling_price'] !== '' && $item['selling_price'] !== null
                ? floatval($item['selling_price']) : null;

            // Insert import detail — landed_rate_per_unit stays in the header
            // currency; that is what the import form and view page display.
            $stmt = $conn->prepare("
                INSERT INTO purchase_import_details
                (import_id, product_id, quantity, fob_rate_per_unit, landed_rate_per_unit, selling_price)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$importId, $productId, $quantity, $fobRate, $landedRate, $sellingPrice]);

            // Create stock batch at LANDED COST, converted to BASE currency
            $stmt = $conn->prepare("
                INSERT INTO stock_batches
                (location_id, product_id, batch_date, transaction_type, transaction_id,
                 quantity_in, quantity_remaining, rate_per_unit)
                VALUES (?, ?, ?, 'purchase_import', ?, ?, ?, ?)
            ");
            $stmt->execute([
                $locationId,
                $productId,
                $importDate,
                $importId,
                $quantity,
                $quantity,
                $landedRateBase
            ]);
        }

        // Main double-entry accounting: DR Purchase / DR VAT / DR Bucket A
        // ledgers / CR Supplier.
        createImportTransaction($conn, $importId);

        // Per-row journal vouchers for Bucket B (third-party) costs. Each
        // row becomes its own DR ledger / [DR VAT] / CR beneficiary entry,
        // linked back to this import via source_voucher_*.
        createImportJournalVouchers($conn, $importId);

        $conn->commit();

        logAudit('purchase_imports', 'create', $importId);

        // Fetch the created import with details
        $import = getImportById($conn, $importId);

        echo json_encode([
            'success' => true,
            'message' => 'Purchase import created successfully',
            'purchase_import' => $import,
            'serverId' => $importId,
            'serverNumber' => $importNumber
        ]);

    } catch (PDOException $e) {
        $conn->rollBack();
        throw new Exception('Database error: ' . $e->getMessage());
    }
}

/**
 * Update existing purchase import
 */
function updatePurchaseImport($conn, $data) {
    if (!hasPermission('purchase_imports', 'edit')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No permission to edit purchase imports']);
        exit;
    }

    // Validate input
    $errors = validateImportData($data, $conn, true);
    if (!empty($errors)) {
        echo json_encode([
            'success' => false,
            'error' => 'Validation failed',
            'details' => $errors
        ]);
        exit;
    }

    $importId = (int)$data['id'];
    $importDate = clean($data['import_date']);
    $poNumber = clean($data['po_number'] ?? '');
    $supplierId = (int)$data['supplier_id'];
    $locationId = (int)$data['location_id'];
    $costCentreId = !empty($data['cost_centre_id']) ? (int)$data['cost_centre_id'] : null;
    $currencyId = !empty($data['currency_id']) ? (int)$data['currency_id'] : null;
    $exchangeRateToBase = isset($data['exchange_rate_to_base']) ? floatval($data['exchange_rate_to_base']) : 1.0;
    $vatAmount = isset($data['vat_amount']) ? max(0, floatval($data['vat_amount'])) : 0.0;
    $notes = clean($data['notes'] ?? '');
    $items = $data['details'] ?? [];
    $costsA = $data['costs_a'] ?? []; // Bucket A: supplier-borne
    $costs  = $data['costs'] ?? [];   // Bucket B / legacy
    $costsC = $data['costs_c'] ?? []; // Bucket C: other costs (NOT added to costing)

    // Default currency_id to the system default if not provided
    if (!$currencyId) {
        $defCur = getDefaultCurrency();
        $currencyId = $defCur ? (int)$defCur['id'] : null;
    }

    // Check if import exists
    $stmt = $conn->prepare("SELECT * FROM purchase_imports WHERE id = ?");
    $stmt->execute([$importId]);
    $existingImport = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existingImport) {
        throw new Exception('Purchase import not found');
    }

    // Check user has access to this location
    if (!canAccessLocation($locationId)) {
        throw new Exception('You do not have access to this location');
    }

    try {
        $conn->beginTransaction();

        // Calculate totals — Bucket A and Bucket B feed costing factor.
        // Bucket C is excluded (posts its own JV but must not inflate landed cost).
        $totalFob = 0;
        foreach ($items as $item) {
            $totalFob += floatval($item['quantity']) * floatval($item['fob_rate_per_unit']);
        }

        // Bucket A amounts are in import-header currency. Bucket B amounts are
        // in each row's own line currency; we convert via the line's FX rate
        // and the header's FX rate so the stored total_additional_costs is in
        // the same currency as total_fob_amount (import-header currency).
        $fxHeader = $exchangeRateToBase > 0 ? $exchangeRateToBase : 1.0;
        $totalAdditionalCosts = 0;
        foreach ($costsA as $c) {
            $totalAdditionalCosts += floatval($c['amount'] ?? 0);
        }
        foreach ($costs as $c) {
            $amt = floatval($c['amount'] ?? 0);
            // Legacy rows (no per-line FX recorded) are assumed to already be
            // in header currency, matching how they were entered originally.
            $lineFx = isset($c['exchange_rate_to_base']) && $c['exchange_rate_to_base']
                ? floatval($c['exchange_rate_to_base']) : $fxHeader;
            $totalAdditionalCosts += ($amt * $lineFx) / $fxHeader;
        }

        $totalLanded = $totalFob + $totalAdditionalCosts;
        $costingFactor = $totalFob > 0 ? $totalLanded / $totalFob : 1.0;

        // Update purchase import header
        $stmt = $conn->prepare("
            UPDATE purchase_imports
            SET import_date = ?, supplier_id = ?, location_id = ?, cost_centre_id = ?,
                currency_id = ?, exchange_rate_to_base = ?, po_number = ?,
                total_fob_amount = ?, total_additional_costs = ?, total_landed_amount = ?,
                costing_factor = ?, vat_amount = ?, notes = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $importDate, $supplierId, $locationId, $costCentreId,
            $currencyId, $exchangeRateToBase, $poNumber ?: null,
            $totalFob, $totalAdditionalCosts, $totalLanded,
            $costingFactor, $vatAmount, $notes, $importId
        ]);

        // Delete old stock batches created by this import
        $stmt = $conn->prepare("
            DELETE FROM stock_batches
            WHERE transaction_type = 'purchase_import' AND transaction_id = ?
        ");
        $stmt->execute([$importId]);

        // Delete old costs and details
        $stmt = $conn->prepare("DELETE FROM purchase_import_costs WHERE import_id = ?");
        $stmt->execute([$importId]);
        $stmt = $conn->prepare("DELETE FROM purchase_import_details WHERE import_id = ?");
        $stmt->execute([$importId]);

        // Insert Bucket A (supplier-borne) costs.
        insertBucketACosts($conn, $importId, $costsA);

        // Insert Bucket B (third-party) costs.
        insertBucketBCosts($conn, $importId, $costs);

        // Insert Bucket C ("Other Costs — not added to costing") rows.
        insertBucketCCosts($conn, $importId, $costsC);

        // Insert new details and stock batches
        foreach ($items as $item) {
            $productId = (int)$item['product_id'];
            $quantity = floatval($item['quantity']);
            $fobRate = floatval($item['fob_rate_per_unit']);
            $landedRate = round($fobRate * $costingFactor, 2);
            // Base-currency landed rate for stock_batches — see the matching
            // comment in the create path above.
            $landedRateBase = round($fobRate * $costingFactor * $fxHeader, 2);
            $sellingPrice = isset($item['selling_price']) && $item['selling_price'] !== '' && $item['selling_price'] !== null
                ? floatval($item['selling_price']) : null;

            $stmt = $conn->prepare("
                INSERT INTO purchase_import_details
                (import_id, product_id, quantity, fob_rate_per_unit, landed_rate_per_unit, selling_price)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$importId, $productId, $quantity, $fobRate, $landedRate, $sellingPrice]);

            // Create stock batch at landed cost, converted to BASE currency
            $stmt = $conn->prepare("
                INSERT INTO stock_batches
                (location_id, product_id, batch_date, transaction_type, transaction_id,
                 quantity_in, quantity_remaining, rate_per_unit)
                VALUES (?, ?, ?, 'purchase_import', ?, ?, ?, ?)
            ");
            $stmt->execute([
                $locationId, $productId, $importDate, $importId,
                $quantity, $quantity, $landedRateBase
            ]);
        }

        // Rebuild double-entry accounting from scratch — drop both the main
        // 'purchase_import' transaction AND every child journal voucher
        // (Bucket B) that pointed back at this import, then post fresh ones
        // reflecting the new totals, VAT, Bucket A, and Bucket B rows.
        deleteChildVouchers($conn, 'purchase_import', $importId);
        deleteVoucherTransaction($conn, 'purchase_import', $importId);
        createImportTransaction($conn, $importId);
        createImportJournalVouchers($conn, $importId);

        $conn->commit();

        logAudit('purchase_imports', 'update', $importId);

        // Fetch the updated import
        $import = getImportById($conn, $importId);

        echo json_encode([
            'success' => true,
            'message' => 'Purchase import updated successfully',
            'purchase_import' => $import
        ]);

    } catch (PDOException $e) {
        $conn->rollBack();
        throw new Exception('Database error: ' . $e->getMessage());
    }
}

/**
 * Delete purchase import and reverse stock batches
 */
function deletePurchaseImport($conn, $data) {
    if (!hasPermission('purchase_imports', 'delete')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No permission to delete purchase imports']);
        exit;
    }

    $importId = (int)$data['id'];

    // Check if import exists
    $stmt = $conn->prepare("SELECT * FROM purchase_imports WHERE id = ?");
    $stmt->execute([$importId]);
    $import = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$import) {
        throw new Exception('Purchase import not found');
    }

    try {
        $conn->beginTransaction();

        // Delete the linked accounting transactions (no FK from transactions
        // to purchase_imports, so this must be explicit):
        //   - the main 'purchase_import' transaction
        //   - every child journal voucher (Bucket B) that pointed back here
        // Both queries cascade to transaction_details via FK.
        deleteChildVouchers($conn, 'purchase_import', $importId);
        deleteVoucherTransaction($conn, 'purchase_import', $importId);

        // Delete stock batches
        $stmt = $conn->prepare("
            DELETE FROM stock_batches
            WHERE transaction_type = 'purchase_import' AND transaction_id = ?
        ");
        $stmt->execute([$importId]);

        // Delete costs
        $stmt = $conn->prepare("DELETE FROM purchase_import_costs WHERE import_id = ?");
        $stmt->execute([$importId]);

        // Delete details
        $stmt = $conn->prepare("DELETE FROM purchase_import_details WHERE import_id = ?");
        $stmt->execute([$importId]);

        // Delete import header
        $stmt = $conn->prepare("DELETE FROM purchase_imports WHERE id = ?");
        $stmt->execute([$importId]);

        $conn->commit();

        logAudit('purchase_imports', 'delete', $importId);

        echo json_encode([
            'success' => true,
            'message' => 'Purchase import deleted successfully'
        ]);

    } catch (PDOException $e) {
        $conn->rollBack();
        throw new Exception('Database error: ' . $e->getMessage());
    }
}

/**
 * Validate import data
 */
function validateImportData($data, $conn, $isUpdate = false) {
    $errors = [];

    // Import date
    if (empty($data['import_date'])) {
        $errors['import_date'] = 'Import date is required';
    } else {
        $importDate = strtotime($data['import_date']);
        $today = strtotime(date('Y-m-d'));
        if ($importDate > $today) {
            $errors['import_date'] = 'Import date cannot be in the future';
        }
    }

    // Supplier
    if (empty($data['supplier_id'])) {
        $errors['supplier_id'] = 'Supplier is required';
    } else {
        $stmt = $conn->prepare("SELECT id FROM suppliers WHERE id = ? AND is_active = 1");
        $stmt->execute([$data['supplier_id']]);
        if (!$stmt->fetch()) {
            $errors['supplier_id'] = 'Invalid supplier selected';
        }
    }

    // Location
    if (empty($data['location_id'])) {
        $errors['location_id'] = 'Location is required';
    } else {
        $stmt = $conn->prepare("SELECT id FROM locations WHERE id = ? AND is_active = 1");
        $stmt->execute([$data['location_id']]);
        if (!$stmt->fetch()) {
            $errors['location_id'] = 'Invalid location selected';
        }
    }

    // Header exchange rate — REQUIRED for foreign-currency imports. When the
    // import currency differs from the base (default) currency, a missing/1.0
    // rate makes the costing math dump other-currency additional costs (e.g.
    // base-currency Bucket B costs) into the header-currency landed total
    // WITHOUT converting them — inflating the landed value and costing factor.
    // The conversion in create/update divides by this rate, so it must be a
    // real header->base rate (never blank or 1) for a foreign currency.
    $defCur = getDefaultCurrency();
    $defaultCurrencyId = $defCur ? (int)$defCur['id'] : null;
    $currencyId = !empty($data['currency_id']) ? (int)$data['currency_id'] : $defaultCurrencyId;
    if ($defaultCurrencyId && $currencyId && $currencyId !== $defaultCurrencyId) {
        $fx = isset($data['exchange_rate_to_base']) ? floatval($data['exchange_rate_to_base']) : 0.0;
        if ($fx <= 0 || abs($fx - 1.0) < 0.0000001) {
            $errors['exchange_rate_to_base'] = 'A valid exchange rate to the base currency is required for a foreign-currency import (it cannot be blank or 1).';
        }
    }

    // Items
    if (empty($data['details']) || !is_array($data['details'])) {
        $errors['details'] = 'At least one item is required';
    } else {
        if (count($data['details']) === 0) {
            $errors['details'] = 'At least one item is required';
        }

        foreach ($data['details'] as $index => $item) {
            if (empty($item['product_id'])) {
                $errors["details.$index.product_id"] = 'Product is required';
            } else {
                $stmt = $conn->prepare("SELECT id FROM products WHERE id = ? AND is_active = 1");
                $stmt->execute([$item['product_id']]);
                if (!$stmt->fetch()) {
                    $errors["details.$index.product_id"] = 'Invalid product selected';
                }
            }

            if (!isset($item['quantity']) || floatval($item['quantity']) <= 0) {
                $errors["details.$index.quantity"] = 'Quantity must be greater than 0';
            }

            if (!isset($item['fob_rate_per_unit']) || floatval($item['fob_rate_per_unit']) < 0) {
                $errors["details.$index.fob_rate_per_unit"] = 'FOB rate cannot be negative';
            }
        }
    }

    return $errors;
}

/**
 * Get import by ID with details and costs
 */
function getImportById($conn, $importId) {
    $stmt = $conn->prepare("
        SELECT
            pi.*,
            EXISTS (
                SELECT 1 FROM transactions t
                WHERE t.voucher_type = 'purchase_import' AND t.voucher_id = pi.id
            ) AS has_gl,
            la.name as supplier_name,
            l.name as location_name,
            cc.name as cost_centre_name,
            cc.code as cost_centre_code,
            u.name as created_by_name,
            cur.code as currency_code,
            cur.symbol as currency_symbol
        FROM purchase_imports pi
        LEFT JOIN suppliers s ON pi.supplier_id = s.id
        LEFT JOIN ledger_accounts la ON s.ledger_account_id = la.id
        LEFT JOIN locations l ON pi.location_id = l.id
        LEFT JOIN cost_centres cc ON pi.cost_centre_id = cc.id
        LEFT JOIN users u ON pi.created_by = u.id
        LEFT JOIN currencies cur ON pi.currency_id = cur.id
        WHERE pi.id = ?
    ");
    $stmt->execute([$importId]);
    $import = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$import) {
        return null;
    }

    // Fetch details
    $detailsStmt = $conn->prepare("
        SELECT
            pid.*,
            p.name as product_name,
            p.unit,
            pc.name as category_name
        FROM purchase_import_details pid
        JOIN products p ON pid.product_id = p.id
        LEFT JOIN product_categories pc ON p.category_id = pc.id
        WHERE pid.import_id = ?
        ORDER BY pid.id
    ");
    $detailsStmt->execute([$importId]);
    $import['details'] = $detailsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch costs with joins for display labels (ledger name, beneficiary
    // name, currency code), then split by bucket so the front-end can render
    // Bucket A and Bucket B independently without re-querying.
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
    $costsStmt->execute([$importId]);
    $allCosts = $costsStmt->fetchAll(PDO::FETCH_ASSOC);
    $import['costs_a'] = array_values(array_filter($allCosts, fn($c) => ($c['bucket'] ?? 'B') === 'A'));
    $import['costs_c'] = array_values(array_filter($allCosts, fn($c) => ($c['bucket'] ?? 'B') === 'C'));
    // Bucket B = everything that isn't A or C (covers legacy rows defaulting to 'B').
    $import['costs']   = array_values(array_filter($allCosts, fn($c) => !in_array(($c['bucket'] ?? 'B'), ['A', 'C'], true)));

    return $import;
}

/**
 * Insert Bucket A (supplier-borne) cost rows for an import.
 * Description is auto-derived from the ledger name since the form doesn't
 * have a description input for these rows.
 */
function insertBucketACosts($conn, $importId, $costsA) {
    if (empty($costsA)) return;

    $stmt = $conn->prepare("
        INSERT INTO purchase_import_costs (import_id, bucket, ledger_id, description, amount)
        VALUES (?, 'A', ?, ?, ?)
    ");
    foreach ($costsA as $c) {
        $ledgerId = (int)($c['ledger_id'] ?? 0);
        $amount   = floatval($c['amount'] ?? 0);
        if ($ledgerId <= 0 || $amount <= 0) continue;

        // Look up the ledger name so the description column stays meaningful
        // for any reports that read it raw.
        $ln = $conn->prepare("SELECT name FROM ledger_accounts WHERE id = ?");
        $ln->execute([$ledgerId]);
        $ledgerName = $ln->fetchColumn() ?: 'Supplier-borne cost';

        $stmt->execute([$importId, $ledgerId, $ledgerName, $amount]);
    }
}

/**
 * Insert Bucket B (third-party) cost rows for an import.
 *
 * Modern rows carry ledger_id + beneficiary_supplier_id + currency_id + FX +
 * VAT; each one will become a journal voucher in Phase 5. Legacy rows (from
 * imports created before the feature) only have description + amount — we
 * preserve them as-is so a round-trip save doesn't lose data. Once the user
 * fills in the missing fields and saves, they'll get upgraded.
 */
function insertBucketBCosts($conn, $importId, $costs) {
    insertThirdPartyCosts($conn, $importId, $costs, 'B');
}

/**
 * Insert Bucket C ("Other Costs — not added to costing") cost rows.
 *
 * Persisted and posted identically to Bucket B (each row becomes its own
 * journal voucher: DR ledger / DR VAT / CR beneficiary). The ONLY difference
 * is that the sync API does not include Bucket C in total_additional_costs,
 * so these rows never inflate the import's costing factor / landed cost.
 */
function insertBucketCCosts($conn, $importId, $costs) {
    insertThirdPartyCosts($conn, $importId, $costs, 'C');
}

/**
 * Shared insert for the third-party cost buckets (B and C). They have an
 * identical row shape — ledger + beneficiary + currency + FX + VAT — and only
 * differ by their bucket flag (which determines whether the sync API folds
 * them into the costing factor). $bucket must be 'B' or 'C'.
 */
function insertThirdPartyCosts($conn, $importId, $costs, $bucket) {
    if (empty($costs)) return;

    $stmt = $conn->prepare("
        INSERT INTO purchase_import_costs
            (import_id, bucket, ledger_id, description, amount,
             beneficiary_supplier_id, currency_id, exchange_rate_to_base,
             vat_percent, vat_amount)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($costs as $c) {
        $amount = floatval($c['amount'] ?? 0);
        if ($amount <= 0) continue;

        $ledgerId      = !empty($c['ledger_id']) ? (int)$c['ledger_id'] : null;
        $beneficiaryId = !empty($c['beneficiary_supplier_id']) ? (int)$c['beneficiary_supplier_id'] : null;
        $currencyId    = !empty($c['currency_id']) ? (int)$c['currency_id'] : null;
        $fx            = !empty($c['exchange_rate_to_base']) ? floatval($c['exchange_rate_to_base']) : null;
        $vatPct        = isset($c['vat_percent']) && $c['vat_percent'] !== null && $c['vat_percent'] !== ''
                            ? floatval($c['vat_percent']) : null;
        $vatAmount     = floatval($c['vat_amount'] ?? 0);

        // Description: use the user-typed description for legacy rows; for
        // modern rows derive from the ledger so reports that read the column
        // raw still get a meaningful label.
        $description = clean($c['description'] ?? '');
        if ($description === '' && $ledgerId) {
            $ln = $conn->prepare("SELECT name FROM ledger_accounts WHERE id = ?");
            $ln->execute([$ledgerId]);
            $description = $ln->fetchColumn() ?: 'Third-party cost';
        } elseif ($description === '') {
            $description = 'Third-party cost';
        }

        $stmt->execute([
            $importId, $bucket, $ledgerId, $description, $amount,
            $beneficiaryId, $currencyId, $fx, $vatPct, $vatAmount
        ]);
    }
}
