<?php
/**
 * Suppliers CRUD API
 * Handles create, update, delete operations with ledger account integration - NEVER CACHED
 * Returns calculated balance in all responses
 */

// Prevent ANY caching
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: Mon, 01 Jan 2000 00:00:00 GMT');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
header('Vary: *');

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
            if (!hasPermission('suppliers', 'create')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'No permission to create']);
                exit;
            }
            
            $name = cleanText($input['data']['name']);
            $contactPerson = cleanText($input['data']['contact_person'] ?? '');
            $phone = cleanText($input['data']['phone'] ?? '');
            $email = cleanText($input['data']['email'] ?? '');
            $address = cleanText($input['data']['address'] ?? '');
            $gstin = cleanText($input['data']['gstin'] ?? '');
            $pan = cleanText($input['data']['pan'] ?? '');
            $openingBalance = floatval($input['data']['opening_balance'] ?? 0);
            $isActive = $input['data']['is_active'] ?? 1;
            
            // Validation
            if (empty($name)) {
                throw new Exception('Supplier name is required');
            }
            
            // Email validation
            if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Invalid email format');
            }
            
            // Check for duplicate name in ledger_accounts
            $check = $conn->prepare("SELECT id FROM ledger_accounts WHERE name = ?");
            $check->execute([$name]);
            if ($check->fetch()) {
                throw new Exception('A supplier/customer/account with this name already exists');
            }
            
            $conn->beginTransaction();
            
            // Step 1: Create ledger account
            $defaultCurrency = getDefaultCurrency();
            $stmt = $conn->prepare("
                INSERT INTO ledger_accounts
                (name, account_type, opening_balance, current_balance, currency_id, is_active)
                VALUES (?, 'supplier', ?, ?, ?, 1)
            ");
            $stmt->execute([$name, $openingBalance, $openingBalance, $defaultCurrency['id']]);
            $ledgerAccountId = $conn->lastInsertId();
            
            // Step 2: Create supplier
            $stmt = $conn->prepare("
                INSERT INTO suppliers 
                (ledger_account_id, contact_person, phone, email, address, gstin, pan, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $ledgerAccountId, $contactPerson, $phone, $email, $address,
                $gstin, $pan, $isActive
            ]);
            $newSupplierId = $conn->lastInsertId();
            
            // Step 3: Update ledger account with reference_id
            $stmt = $conn->prepare("UPDATE ledger_accounts SET reference_id = ? WHERE id = ?");
            $stmt->execute([$newSupplierId, $ledgerAccountId]);
            
            $conn->commit();
            
            logAudit('suppliers', 'create', $newSupplierId);
            
            // Get the created supplier with CALCULATED balance
            $getStmt = $conn->prepare("
                SELECT 
                    s.id,
                    s.ledger_account_id,
                    la.name,
                    s.contact_person,
                    s.phone,
                    s.email,
                    s.address,
                    s.gstin,
                    s.pan,
                    la.opening_balance,
                    -- Calculate current balance dynamically
                    (
                        la.opening_balance + 
                        COALESCE((SELECT SUM(total_amount + vat_amount) FROM purchases WHERE supplier_id = s.id), 0) -
                        COALESCE((SELECT SUM(amount) FROM payments WHERE to_account_id = la.id), 0)
                    ) as current_balance,
                    s.is_active,
                    (SELECT COUNT(*) FROM purchases WHERE supplier_id = s.id) as purchase_count,
                    (SELECT COUNT(*) FROM payments WHERE to_account_id = la.id) as payment_count
                FROM suppliers s
                INNER JOIN ledger_accounts la ON s.ledger_account_id = la.id
                WHERE s.id = ?
            ");
            $getStmt->execute([$newSupplierId]);
            $supplier = $getStmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'message' => 'Supplier created successfully',
                'supplier' => $supplier,
                'timestamp' => microtime(true)
            ]);
            break;
            
        case 'update':
            if (!hasPermission('suppliers', 'edit')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'No permission to edit']);
                exit;
            }
            
            $id = (int)$input['data']['id'];
            $name = cleanText($input['data']['name']);
            $contactPerson = cleanText($input['data']['contact_person'] ?? '');
            $phone = cleanText($input['data']['phone'] ?? '');
            $email = cleanText($input['data']['email'] ?? '');
            $address = cleanText($input['data']['address'] ?? '');
            $gstin = cleanText($input['data']['gstin'] ?? '');
            $pan = cleanText($input['data']['pan'] ?? '');
            $openingBalance = floatval($input['data']['opening_balance'] ?? 0);
            $isActive = $input['data']['is_active'] ?? 1;
            
            // Validation
            if (empty($name)) {
                throw new Exception('Supplier name is required');
            }
            
            // Email validation
            if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Invalid email format');
            }
            
            // Check if supplier exists
            $checkStmt = $conn->prepare("SELECT * FROM suppliers WHERE id = ?");
            $checkStmt->execute([$id]);
            $oldSupplier = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$oldSupplier) {
                throw new Exception('Supplier not found');
            }
            
            // Check for duplicate name in ledger_accounts (excluding current supplier's account)
            $dupStmt = $conn->prepare("SELECT id FROM ledger_accounts WHERE name = ? AND id != ?");
            $dupStmt->execute([$name, $oldSupplier['ledger_account_id']]);
            if ($dupStmt->fetch()) {
                throw new Exception('A supplier/customer/account with this name already exists');
            }
            
            $conn->beginTransaction();
            
            // Update ledger account name and opening balance.
            // Self-heal account_type='supplier' so a drifted ledger gets
            // fixed by simply re-saving the supplier (mirrors customer flow).
            $stmt = $conn->prepare("
                UPDATE ledger_accounts
                SET name = ?, opening_balance = ?, account_type = 'supplier'
                WHERE id = ?
            ");
            $stmt->execute([$name, $openingBalance, $oldSupplier['ledger_account_id']]);
            
            // Update supplier details
            $stmt = $conn->prepare("
                UPDATE suppliers 
                SET contact_person = ?, phone = ?, email = ?, address = ?,
                    gstin = ?, pan = ?, is_active = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $contactPerson, $phone, $email, $address,
                $gstin, $pan, $isActive, $id
            ]);
            
            $conn->commit();
            
            logAudit('suppliers', 'update', $id);
            
            // Get the updated supplier with CALCULATED balance
            $getStmt = $conn->prepare("
                SELECT 
                    s.id,
                    s.ledger_account_id,
                    la.name,
                    s.contact_person,
                    s.phone,
                    s.email,
                    s.address,
                    s.gstin,
                    s.pan,
                    la.opening_balance,
                    -- Calculate current balance dynamically
                    (
                        la.opening_balance + 
                        COALESCE((SELECT SUM(total_amount + vat_amount) FROM purchases WHERE supplier_id = s.id), 0) -
                        COALESCE((SELECT SUM(amount) FROM payments WHERE to_account_id = la.id), 0)
                    ) as current_balance,
                    s.is_active,
                    (SELECT COUNT(*) FROM purchases WHERE supplier_id = s.id) as purchase_count,
                    (SELECT COUNT(*) FROM payments WHERE to_account_id = la.id) as payment_count
                FROM suppliers s
                INNER JOIN ledger_accounts la ON s.ledger_account_id = la.id
                WHERE s.id = ?
            ");
            $getStmt->execute([$id]);
            $supplier = $getStmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'message' => 'Supplier updated successfully',
                'supplier' => $supplier,
                'timestamp' => microtime(true)
            ]);
            break;
            
        case 'delete':
            if (!hasPermission('suppliers', 'delete')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'No permission to delete']);
                exit;
            }
            
            $id = (int)$input['data']['id'];
            
            // Get supplier and ledger account
            $stmt = $conn->prepare("SELECT ledger_account_id FROM suppliers WHERE id = ?");
            $stmt->execute([$id]);
            $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$supplier) {
                throw new Exception('Supplier not found');
            }
            
            // Check if supplier is in use
            $checkStmt = $conn->prepare("
                SELECT 
                    (SELECT COUNT(*) FROM purchases WHERE supplier_id = ?) as purchase_count,
                    (SELECT COUNT(*) FROM payments WHERE to_account_id = ?) as payment_count
            ");
            $checkStmt->execute([$id, $supplier['ledger_account_id']]);
            $usage = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            $errors = [];
            if ($usage['purchase_count'] > 0) {
                $errors[] = "{$usage['purchase_count']} purchase(s)";
            }
            if ($usage['payment_count'] > 0) {
                $errors[] = "{$usage['payment_count']} payment(s)";
            }
            
            if (!empty($errors)) {
                throw new Exception('Cannot delete this supplier. It has: ' . implode(', ', $errors));
            }
            
            $conn->beginTransaction();
            
            // Delete supplier first (due to foreign key)
            $stmt = $conn->prepare("DELETE FROM suppliers WHERE id = ?");
            $stmt->execute([$id]);
            
            // Then delete ledger account
            $stmt = $conn->prepare("DELETE FROM ledger_accounts WHERE id = ?");
            $stmt->execute([$supplier['ledger_account_id']]);
            
            $conn->commit();
            
            logAudit('suppliers', 'delete', $id);
            
            echo json_encode([
                'success' => true,
                'message' => 'Supplier deleted successfully',
                'timestamp' => microtime(true)
            ]);
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
        'error' => $e->getMessage(),
        'timestamp' => microtime(true)
    ]);
}
