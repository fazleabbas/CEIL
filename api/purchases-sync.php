<?php
/**
 * Purchases Sync API - Handles CRUD operations
 * Manages purchases, purchase_details, and stock_batches
 * Supports temporary IDs for offline-created purchases
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
            createPurchase($conn, $input['data']);
            break;
            
        case 'update':
            updatePurchase($conn, $input['data']);
            break;
            
        case 'delete':
            deletePurchase($conn, $input['data']);
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
 * Create new purchase with items and stock batches
 */
function createPurchase($conn, $data) {
    if (!hasPermission('purchases', 'create')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No permission to create purchases']);
        exit;
    }
    
    // Validate input
    $errors = validatePurchaseData($data, $conn);
    if (!empty($errors)) {
        echo json_encode([
            'success' => false,
            'error' => 'Validation failed',
            'details' => $errors
        ]);
        exit;
    }
    
    $purchaseDate = clean($data['purchase_date']);
    $poNumber = clean($data['po_number'] ?? '');
    $supplierId = (int)$data['supplier_id'];
    $locationId = (int)$data['location_id'];
    $costCentreId = !empty($data['cost_centre_id']) ? (int)$data['cost_centre_id'] : null;
    $currencyId = !empty($data['currency_id']) ? (int)$data['currency_id'] : null;
    $exchangeRateToBase = isset($data['exchange_rate_to_base']) ? floatval($data['exchange_rate_to_base']) : 1.0;
    $vatAmount = isset($data['vat_amount']) ? max(0, floatval($data['vat_amount'])) : 0.0;
    $notes = clean($data['notes'] ?? '');
    $items = $data['details'] ?? [];
    $tempId = $data['tempId'] ?? null; // For reference
    // Optional link back to the requisition this purchase was created from.
    $requisitionId = !empty($data['requisition_id']) ? (int)$data['requisition_id'] : null;
    // Optional link back to the budget this purchase was created from (migration
    // 0034). Guarded so this keeps working if the migration hasn't been applied.
    $budgetId = !empty($data['budget_id']) ? (int)$data['budget_id'] : null;
    $hasBudgetCol = false;
    try {
        $cstmt = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchases' AND COLUMN_NAME = 'budget_id'");
        $hasBudgetCol = ((int)$cstmt->fetchColumn()) > 0;
    } catch (Exception $e) { $hasBudgetCol = false; }

    // Optional link back to the purchase order this purchase was created from
    // (migration 0038). Guarded so this keeps working pre-migration. The PO
    // must be approved, belong to the same supplier, and have enough remaining
    // (approved minus already-purchased) quantity for every posted line.
    $purchaseOrderId = !empty($data['purchase_order_id']) ? (int)$data['purchase_order_id'] : null;
    if ($purchaseOrderId && !po_link_hasColumn($conn)) {
        $purchaseOrderId = null;
    }
    if ($purchaseOrderId) {
        $poRow = po_link_fetch($conn, $purchaseOrderId);
        if (!$poRow) {
            throw new Exception('Purchase order not found');
        }
        if ($poRow['status'] !== 'approved') {
            throw new Exception('Only approved purchase orders can be converted to a purchase');
        }
        if ((int)$poRow['supplier_id'] !== $supplierId) {
            throw new Exception('Supplier does not match the purchase order');
        }
        po_link_checkOverReceipt($conn, $purchaseOrderId, $items);

        // Inherit budget linkage from the PO (budget-raised POs) so budget
        // remaining math and linked-document lists keep working.
        if (!$budgetId && !empty($poRow['budget_id'])) {
            $budgetId = (int)$poRow['budget_id'];
        }
        if (!$requisitionId && !empty($poRow['budget_requisition_id'])) {
            $requisitionId = (int)$poRow['budget_requisition_id'];
        }
    }

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

        // Generate purchase number
        $purchaseNumber = getNextNumber('purchases', 'purchase_number', 'PUR');

        // Calculate total amount
        $totalAmount = 0;
        foreach ($items as $item) {
            $totalAmount += floatval($item['quantity']) * floatval($item['rate_per_unit']);
        }

        // Insert purchase (budget_id column included only when present)
        if ($hasBudgetCol) {
            $stmt = $conn->prepare("
                INSERT INTO purchases
                (purchase_number, po_number, purchase_date, supplier_id, location_id, cost_centre_id, requisition_id, budget_id, currency_id, exchange_rate_to_base, total_amount, vat_amount, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $purchaseNumber,
                $poNumber ?: null,
                $purchaseDate,
                $supplierId,
                $locationId,
                $costCentreId,
                $requisitionId,
                $budgetId,
                $currencyId,
                $exchangeRateToBase,
                $totalAmount,
                $vatAmount,
                $notes,
                getUserId()
            ]);
        } else {
            $stmt = $conn->prepare("
                INSERT INTO purchases
                (purchase_number, po_number, purchase_date, supplier_id, location_id, cost_centre_id, requisition_id, currency_id, exchange_rate_to_base, total_amount, vat_amount, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $purchaseNumber,
                $poNumber ?: null,
                $purchaseDate,
                $supplierId,
                $locationId,
                $costCentreId,
                $requisitionId,
                $currencyId,
                $exchangeRateToBase,
                $totalAmount,
                $vatAmount,
                $notes,
                getUserId()
            ]);
        }
        
        $purchaseId = $conn->lastInsertId();
        
        // Insert purchase details and create stock batches
        $detailIds = [];
        foreach ($items as $item) {
            $productId = (int)$item['product_id'];
            $quantity = floatval($item['quantity']);
            $ratePerUnit = floatval($item['rate_per_unit']);
            
            // Insert purchase detail
            $stmt = $conn->prepare("
                INSERT INTO purchase_details 
                (purchase_id, product_id, quantity, rate_per_unit)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$purchaseId, $productId, $quantity, $ratePerUnit]);
            $detailId = $conn->lastInsertId();
            $detailIds[] = $detailId;
            
            // Create stock batch for FIFO tracking
            $stmt = $conn->prepare("
                INSERT INTO stock_batches 
                (location_id, product_id, batch_date, transaction_type, transaction_id, 
                 quantity_in, quantity_remaining, rate_per_unit)
                VALUES (?, ?, ?, 'purchase', ?, ?, ?, ?)
            ");
            $stmt->execute([
                $locationId,
                $productId,
                $purchaseDate,
                $purchaseId,
                $quantity,
                $quantity, // Initially all quantity remains
                $ratePerUnit
            ]);
        }

        // Link back to the purchase order and accrue its per-line
        // purchased_quantity (auto-closes the PO when fully received).
        if ($purchaseOrderId) {
            $conn->prepare("UPDATE purchases SET purchase_order_id = ? WHERE id = ?")
                 ->execute([$purchaseOrderId, $purchaseId]);
            po_link_recalc($conn, $purchaseOrderId);
        }

        // Double-entry accounting: write the DR Purchase / DR VAT / CR Supplier rows
        // to transactions + transaction_details (Ledger Report source). Auto-creates
        // 'Purchase Account' and 'VAT' system ledgers if missing.
        createPurchaseTransaction($conn, $purchaseId);

        $conn->commit();

        logAudit('purchases', 'create', $purchaseId);
        
        // Fetch the created purchase with details
        $purchase = getPurchaseById($conn, $purchaseId);
        
        echo json_encode([
            'success' => true,
            'message' => 'Purchase created successfully',
            'purchase' => $purchase,
            'serverId' => $purchaseId,
            'serverNumber' => $purchaseNumber,
            'tempId' => $tempId
        ]);
        
    } catch (PDOException $e) {
        $conn->rollBack();
        throw new Exception('Database error: ' . $e->getMessage());
    }
}

/**
 * Update existing purchase
 */
function updatePurchase($conn, $data) {
    if (!hasPermission('purchases', 'edit')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No permission to edit purchases']);
        exit;
    }
    
    // Validate input
    $errors = validatePurchaseData($data, $conn, true);
    if (!empty($errors)) {
        echo json_encode([
            'success' => false,
            'error' => 'Validation failed',
            'details' => $errors
        ]);
        exit;
    }
    
    $purchaseId = (int)$data['id'];
    $purchaseDate = clean($data['purchase_date']);
    $poNumber = clean($data['po_number'] ?? '');
    $supplierId = (int)$data['supplier_id'];
    $locationId = (int)$data['location_id'];
    $costCentreId = !empty($data['cost_centre_id']) ? (int)$data['cost_centre_id'] : null;
    $currencyId = !empty($data['currency_id']) ? (int)$data['currency_id'] : null;
    $exchangeRateToBase = isset($data['exchange_rate_to_base']) ? floatval($data['exchange_rate_to_base']) : 1.0;
    $vatAmount = isset($data['vat_amount']) ? max(0, floatval($data['vat_amount'])) : 0.0;
    $notes = clean($data['notes'] ?? '');
    $items = $data['details'] ?? [];

    // Default currency_id to the system default if not provided
    if (!$currencyId) {
        $defCur = getDefaultCurrency();
        $currencyId = $defCur ? (int)$defCur['id'] : null;
    }

    // Check if purchase exists
    $stmt = $conn->prepare("SELECT * FROM purchases WHERE id = ?");
    $stmt->execute([$purchaseId]);
    $existingPurchase = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$existingPurchase) {
        throw new Exception('Purchase not found');
    }

    // Check user has access to this location
    if (!canAccessLocation($locationId)) {
        throw new Exception('You do not have access to this location');
    }

    // Purchase raised from a purchase order: the link itself is immutable here;
    // supplier must keep matching the PO and the edited quantities may not
    // exceed the PO's remaining (approved minus other linked purchases).
    $linkedPoId = (po_link_hasColumn($conn) && !empty($existingPurchase['purchase_order_id']))
        ? (int)$existingPurchase['purchase_order_id'] : null;
    if ($linkedPoId) {
        $poRow = po_link_fetch($conn, $linkedPoId);
        if ($poRow) {
            if ((int)$poRow['supplier_id'] !== $supplierId) {
                throw new Exception('Supplier does not match the purchase order this purchase was created from');
            }
            po_link_checkOverReceipt($conn, $linkedPoId, $items, $purchaseId);
        } else {
            $linkedPoId = null;
        }
    }

    try {
        $conn->beginTransaction();
        
        // Calculate new total amount
        $totalAmount = 0;
        foreach ($items as $item) {
            $totalAmount += floatval($item['quantity']) * floatval($item['rate_per_unit']);
        }
        
        // Update purchase
        $stmt = $conn->prepare("
            UPDATE purchases
            SET purchase_date = ?, po_number = ?, supplier_id = ?, location_id = ?,
                cost_centre_id = ?, currency_id = ?, exchange_rate_to_base = ?, total_amount = ?, vat_amount = ?, notes = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $purchaseDate,
            $poNumber ?: null,
            $supplierId,
            $locationId,
            $costCentreId,
            $currencyId,
            $exchangeRateToBase,
            $totalAmount,
            $vatAmount,
            $notes,
            $purchaseId
        ]);
        
        // Delete old stock batches created by this purchase
        $stmt = $conn->prepare("
            DELETE FROM stock_batches 
            WHERE transaction_type = 'purchase' AND transaction_id = ?
        ");
        $stmt->execute([$purchaseId]);
        
        // Delete old purchase details
        $stmt = $conn->prepare("DELETE FROM purchase_details WHERE purchase_id = ?");
        $stmt->execute([$purchaseId]);
        
        // Insert new purchase details and create new stock batches
        foreach ($items as $item) {
            $productId = (int)$item['product_id'];
            $quantity = floatval($item['quantity']);
            $ratePerUnit = floatval($item['rate_per_unit']);
            
            // Insert purchase detail
            $stmt = $conn->prepare("
                INSERT INTO purchase_details 
                (purchase_id, product_id, quantity, rate_per_unit)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$purchaseId, $productId, $quantity, $ratePerUnit]);
            
            // Create stock batch
            $stmt = $conn->prepare("
                INSERT INTO stock_batches 
                (location_id, product_id, batch_date, transaction_type, transaction_id, 
                 quantity_in, quantity_remaining, rate_per_unit)
                VALUES (?, ?, ?, 'purchase', ?, ?, ?, ?)
            ");
            $stmt->execute([
                $locationId,
                $productId,
                $purchaseDate,
                $purchaseId,
                $quantity,
                $quantity,
                $ratePerUnit
            ]);
        }

        // Re-accrue the linked PO's purchased_quantity from the new line set
        // (may reopen a closed PO if quantities dropped).
        if ($linkedPoId) {
            po_link_recalc($conn, $linkedPoId);
        }

        // Re-log double-entry accounting: drop old transaction rows, insert fresh
        // DR Purchase / DR VAT / CR Supplier rows reflecting the updated purchase.
        deleteVoucherTransaction($conn, 'purchase', $purchaseId);
        createPurchaseTransaction($conn, $purchaseId);

        $conn->commit();

        logAudit('purchases', 'update', $purchaseId);
        
        // Fetch the updated purchase with details
        $purchase = getPurchaseById($conn, $purchaseId);
        
        echo json_encode([
            'success' => true,
            'message' => 'Purchase updated successfully',
            'purchase' => $purchase
        ]);
        
    } catch (PDOException $e) {
        $conn->rollBack();
        throw new Exception('Database error: ' . $e->getMessage());
    }
}

/**
 * Delete purchase and reverse stock batches
 */
function deletePurchase($conn, $data) {
    if (!hasPermission('purchases', 'delete')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No permission to delete purchases']);
        exit;
    }
    
    $purchaseId = (int)$data['id'];
    
    // Check if purchase exists
    $stmt = $conn->prepare("SELECT * FROM purchases WHERE id = ?");
    $stmt->execute([$purchaseId]);
    $purchase = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$purchase) {
        throw new Exception('Purchase not found');
    }

    // Remember the PO link (if any) so its purchased_quantity can be released
    // after the delete (may reopen a closed PO).
    $linkedPoId = (po_link_hasColumn($conn) && !empty($purchase['purchase_order_id']))
        ? (int)$purchase['purchase_order_id'] : null;

    try {
        $conn->beginTransaction();

        // Delete stock batches
        $stmt = $conn->prepare("
            DELETE FROM stock_batches 
            WHERE transaction_type = 'purchase' AND transaction_id = ?
        ");
        $stmt->execute([$purchaseId]);
        
        // Delete purchase details
        $stmt = $conn->prepare("DELETE FROM purchase_details WHERE purchase_id = ?");
        $stmt->execute([$purchaseId]);

        // Delete the linked accounting transaction (no FK from transactions to
        // purchases, so this must be explicit).
        deleteVoucherTransaction($conn, 'purchase', $purchaseId);

        // Delete purchase
        $stmt = $conn->prepare("DELETE FROM purchases WHERE id = ?");
        $stmt->execute([$purchaseId]);

        // Release the deleted quantities on the linked PO (may reopen it).
        if ($linkedPoId) {
            po_link_recalc($conn, $linkedPoId);
        }

        $conn->commit();
        
        logAudit('purchases', 'delete', $purchaseId);
        
        echo json_encode([
            'success' => true,
            'message' => 'Purchase deleted successfully'
        ]);
        
    } catch (PDOException $e) {
        $conn->rollBack();
        throw new Exception('Database error: ' . $e->getMessage());
    }
}

/**
 * Validate purchase data
 */
function validatePurchaseData($data, $conn, $isUpdate = false) {
    $errors = [];
    
    // Purchase date
    if (empty($data['purchase_date'])) {
        $errors['purchase_date'] = 'Purchase date is required';
    } else {
        $purchaseDate = strtotime($data['purchase_date']);
        $today = strtotime(date('Y-m-d'));
        if ($purchaseDate > $today) {
            $errors['purchase_date'] = 'Purchase date cannot be in the future';
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
    
    // Items
    if (empty($data['details']) || !is_array($data['details'])) {
        $errors['details'] = 'At least one item is required';
    } else {
        $items = $data['details'];
        if (count($items) === 0) {
            $errors['details'] = 'At least one item is required';
        }
        
        foreach ($items as $index => $item) {
            // Product
            if (empty($item['product_id'])) {
                $errors["details.$index.product_id"] = 'Product is required';
            } else {
                $stmt = $conn->prepare("SELECT id FROM products WHERE id = ? AND is_active = 1");
                $stmt->execute([$item['product_id']]);
                if (!$stmt->fetch()) {
                    $errors["details.$index.product_id"] = 'Invalid product selected';
                }
            }
            
            // Quantity
            if (!isset($item['quantity']) || floatval($item['quantity']) <= 0) {
                $errors["details.$index.quantity"] = 'Quantity must be greater than 0';
            }
            
            // Rate
            if (!isset($item['rate_per_unit']) || floatval($item['rate_per_unit']) < 0) {
                $errors["details.$index.rate_per_unit"] = 'Rate cannot be negative';
            }
        }
    }
    
    return $errors;
}

/**
 * Get purchase by ID with details
 */
function getPurchaseById($conn, $purchaseId) {
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
    
    if (!$purchase) {
        return null;
    }
    
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
    $purchase['synced'] = true;

    return $purchase;
}

// ============================================================================
// Purchase Order linkage helpers (migration 0038). All no-ops / null when the
// purchases.purchase_order_id column doesn't exist yet, so pre-migration
// tenants are unaffected.
// ============================================================================

/**
 * Whether purchases.purchase_order_id exists (migration 0038 applied).
 */
function po_link_hasColumn($conn) {
    static $has = null;
    if ($has !== null) return $has;
    try {
        $stmt = $conn->query("
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchases' AND COLUMN_NAME = 'purchase_order_id'
        ");
        $has = ((int)$stmt->fetchColumn()) > 0;
    } catch (Exception $e) {
        $has = false;
    }
    return $has;
}

/**
 * Fetch a purchase order header (+ the requisition of its budget, when any).
 */
function po_link_fetch($conn, $poId) {
    $stmt = $conn->prepare("
        SELECT po.*, b.requisition_id AS budget_requisition_id
        FROM purchase_orders po
        LEFT JOIN budgets b ON po.budget_id = b.id
        WHERE po.id = ?
    ");
    $stmt->execute([(int)$poId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Over-receipt guard: per product, the posted quantities plus what other
 * purchases already took from this PO may not exceed the PO's effective
 * (approved, else ordered) quantity. Products not on the PO are rejected.
 * $excludePurchaseId skips one purchase (the one being updated).
 */
function po_link_checkOverReceipt($conn, $poId, $items, $excludePurchaseId = null) {
    $poId = (int)$poId;

    // Effective quantity per product on the PO.
    $effStmt = $conn->prepare("
        SELECT product_id, SUM(COALESCE(approved_quantity, quantity)) AS eff_qty
        FROM purchase_order_details
        WHERE purchase_order_id = ?
        GROUP BY product_id
    ");
    $effStmt->execute([$poId]);
    $eff = [];
    foreach ($effStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $eff[(int)$r['product_id']] = (float)$r['eff_qty'];
    }

    // Already purchased per product from OTHER purchases linked to this PO.
    $sql = "
        SELECT pd.product_id, COALESCE(SUM(pd.quantity), 0) AS qty
        FROM purchase_details pd
        JOIN purchases pu ON pd.purchase_id = pu.id
        WHERE pu.purchase_order_id = ?
    ";
    $params = [$poId];
    if ($excludePurchaseId) {
        $sql .= " AND pu.id != ?";
        $params[] = (int)$excludePurchaseId;
    }
    $sql .= " GROUP BY pd.product_id";
    $doneStmt = $conn->prepare($sql);
    $doneStmt->execute($params);
    $done = [];
    foreach ($doneStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $done[(int)$r['product_id']] = (float)$r['qty'];
    }

    // Posted quantities per product.
    $posted = [];
    foreach ($items as $item) {
        $pid = (int)($item['product_id'] ?? 0);
        if ($pid <= 0) continue;
        $posted[$pid] = ($posted[$pid] ?? 0) + floatval($item['quantity'] ?? 0);
    }

    foreach ($posted as $pid => $qty) {
        if (!isset($eff[$pid])) {
            throw new Exception('Product #' . $pid . ' is not on the purchase order');
        }
        $remaining = $eff[$pid] - ($done[$pid] ?? 0);
        if ($qty > $remaining + 0.0005) {
            $pn = $conn->prepare("SELECT name FROM products WHERE id = ?");
            $pn->execute([$pid]);
            $pname = $pn->fetchColumn() ?: ('#' . $pid);
            $fmt = function ($n) { return rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.'); };
            throw new Exception(sprintf(
                'Quantity for %s exceeds the purchase order remaining (%s > %s)',
                $pname, $fmt($qty), $fmt(max($remaining, 0))
            ));
        }
    }
}

/**
 * Recompute per-line purchased_quantity on a PO from all purchases linked to
 * it, then auto-close it when every line is fully received — or reopen a
 * closed PO whose received quantity dropped (purchase edited/deleted).
 * Must be called inside the caller's transaction.
 */
function po_link_recalc($conn, $poId) {
    $poId = (int)$poId;

    // Per-product purchased totals across all linked purchases.
    $stmt = $conn->prepare("
        SELECT pd.product_id, COALESCE(SUM(pd.quantity), 0) AS qty
        FROM purchase_details pd
        JOIN purchases pu ON pd.purchase_id = pu.id
        WHERE pu.purchase_order_id = ?
        GROUP BY pd.product_id
    ");
    $stmt->execute([$poId]);
    $purchasedByProduct = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $purchasedByProduct[(int)$r['product_id']] = (float)$r['qty'];
    }

    // PO lines in order; distribute each product's purchased total across its
    // lines sequentially (any excess lands on the product's last line so the
    // per-product sum stays accurate even with duplicate-product lines).
    $lineStmt = $conn->prepare("
        SELECT id, product_id, COALESCE(approved_quantity, quantity) AS eff_qty
        FROM purchase_order_details
        WHERE purchase_order_id = ?
        ORDER BY id
    ");
    $lineStmt->execute([$poId]);
    $lines = $lineStmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($lines)) return;

    $lastLineOfProduct = [];
    foreach ($lines as $ln) {
        $lastLineOfProduct[(int)$ln['product_id']] = (int)$ln['id'];
    }

    $upd = $conn->prepare("UPDATE purchase_order_details SET purchased_quantity = ? WHERE id = ?");
    $available = $purchasedByProduct;
    $allDone = true;
    foreach ($lines as $ln) {
        $lineId = (int)$ln['id'];
        $pid = (int)$ln['product_id'];
        $effQty = (float)$ln['eff_qty'];
        $avail = $available[$pid] ?? 0;

        $take = min($effQty, $avail);
        if ($lastLineOfProduct[$pid] === $lineId) {
            $take = $avail; // last line absorbs any excess
        }
        $available[$pid] = $avail - $take;

        $upd->execute([round($take, 3), $lineId]);
        if ($effQty - $take > 0.0005) {
            $allDone = false;
        }
    }

    // Auto-close / reopen. Only flips between 'approved' and 'closed'; every
    // other status is left untouched.
    $curStmt = $conn->prepare("SELECT status FROM purchase_orders WHERE id = ?");
    $curStmt->execute([$poId]);
    $current = $curStmt->fetchColumn();
    if ($current === 'approved' && $allDone) {
        $conn->prepare("UPDATE purchase_orders SET status = 'closed', closed_by = ?, closed_at = NOW() WHERE id = ?")
             ->execute([getUserId(), $poId]);
    } elseif ($current === 'closed' && !$allDone) {
        $conn->prepare("UPDATE purchase_orders SET status = 'approved', closed_by = NULL, closed_at = NULL WHERE id = ?")
             ->execute([$poId]);
    }
}
