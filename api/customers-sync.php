<?php
/**
 * Customers Sync API - Handles CRUD operations
 * Manages both customers and their linked ledger accounts
 * Protects default Cash customer (ID=1)
 * Returns calculated balance in all responses
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
            if (!hasPermission('customers', 'create')) {
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
            
            // Validate
            if (empty($name)) {
                throw new Exception('Customer name is required');
            }
            
            // Email validation
            if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Invalid email format');
            }

            // Check for duplicate name in ledger_accounts
            $check = $conn->prepare("SELECT id FROM ledger_accounts WHERE name = ?");
            $check->execute([$name]);
            if ($check->fetch()) {
                throw new Exception('A customer/supplier/account with this name already exists');
            }
            
            $conn->beginTransaction();
            
            // Step 1: Create ledger account
            $defaultCurrency = getDefaultCurrency();
            $stmt = $conn->prepare("
                INSERT INTO ledger_accounts
                (name, account_type, opening_balance, current_balance, currency_id, is_active)
                VALUES (?, 'customer', ?, ?, ?, 1)
            ");
            $stmt->execute([$name, $openingBalance, $openingBalance, $defaultCurrency['id']]);
            $ledgerAccountId = $conn->lastInsertId();
            
            // Step 2: Create customer
            $stmt = $conn->prepare("
                INSERT INTO customers 
                (ledger_account_id, contact_person, phone, email, address, gstin, pan, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $ledgerAccountId, $contactPerson, $phone, $email, $address,
                $gstin, $pan, $isActive
            ]);
            $newCustomerId = $conn->lastInsertId();
            
            // Step 3: Update ledger account with reference_id
            $stmt = $conn->prepare("UPDATE ledger_accounts SET reference_id = ? WHERE id = ?");
            $stmt->execute([$newCustomerId, $ledgerAccountId]);
            
            $conn->commit();
            
            logAudit('customers', 'create', $newCustomerId);
            
            // Get the created customer with CALCULATED balance
            $getStmt = $conn->prepare("
                SELECT c.*, 
                       la.name, 
                       la.opening_balance,
                       -- Calculate current balance dynamically
                       (
                           la.opening_balance + 
                           COALESCE((SELECT SUM(total_amount) FROM sales WHERE customer_id = c.id), 0) -
                           COALESCE((SELECT SUM(amount) FROM receipts WHERE from_account_id = la.id), 0)
                       ) as current_balance,
                       (SELECT COUNT(*) FROM sales WHERE customer_id = c.id) as sale_count,
                       (SELECT COUNT(*) FROM receipts WHERE from_account_id = la.id) as receipt_count
                FROM customers c
                INNER JOIN ledger_accounts la ON c.ledger_account_id = la.id
                WHERE c.id = ?
            ");
            $getStmt->execute([$newCustomerId]);
            $customer = $getStmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'message' => 'Customer created successfully',
                'customer' => $customer,
                'serverId' => $newCustomerId
            ]);
            break;
            
        case 'update':
            if (!hasPermission('customers', 'edit')) {
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
            
            // Validate
            if (empty($name)) {
                throw new Exception('Customer name is required');
            }
            
            // Email validation
            if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Invalid email format');
            }

            // Check if customer exists
            $checkStmt = $conn->prepare("SELECT * FROM customers WHERE id = ?");
            $checkStmt->execute([$id]);
            $oldCustomer = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$oldCustomer) {
                throw new Exception('Customer not found');
            }
            
            // Check for duplicate name in ledger_accounts
            $dupStmt = $conn->prepare("SELECT id FROM ledger_accounts WHERE name = ? AND id != ?");
            $dupStmt->execute([$name, $oldCustomer['ledger_account_id']]);
            if ($dupStmt->fetch()) {
                throw new Exception('A customer/supplier/account with this name already exists');
            }
            
            $conn->beginTransaction();
            
            // Update ledger account - REMOVED current_balance from SET clause.
            // Self-heal account_type to 'customer' on every edit — if the
            // linked ledger had drifted to another type (e.g. via a manual
            // edit in Masters → Ledgers), the dashboard receivables widget
            // would silently exclude this customer. Forcing it on each edit
            // means saving the customer once also fixes the data.
            $stmt = $conn->prepare("
                UPDATE ledger_accounts
                SET name = ?, opening_balance = ?, account_type = 'customer'
                WHERE id = ?
            ");
            $stmt->execute([$name, $openingBalance, $oldCustomer['ledger_account_id']]);
            
            // Update customer
            $stmt = $conn->prepare("
                UPDATE customers 
                SET contact_person = ?, phone = ?, email = ?, address = ?,
                    gstin = ?, pan = ?, is_active = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $contactPerson, $phone, $email, $address,
                $gstin, $pan, $isActive, $id
            ]);
            
            $conn->commit();
            
            logAudit('customers', 'update', $id);
            
            // Get the updated customer with CALCULATED balance
            $getStmt = $conn->prepare("
                SELECT c.*, 
                       la.name, 
                       la.opening_balance,
                       -- Calculate current balance dynamically
                       (
                           la.opening_balance + 
                           COALESCE((SELECT SUM(total_amount) FROM sales WHERE customer_id = c.id), 0) -
                           COALESCE((SELECT SUM(amount) FROM receipts WHERE from_account_id = la.id), 0)
                       ) as current_balance,
                       (SELECT COUNT(*) FROM sales WHERE customer_id = c.id) as sale_count,
                       (SELECT COUNT(*) FROM receipts WHERE from_account_id = la.id) as receipt_count
                FROM customers c
                INNER JOIN ledger_accounts la ON c.ledger_account_id = la.id
                WHERE c.id = ?
            ");
            $getStmt->execute([$id]);
            $customer = $getStmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'message' => 'Customer updated successfully',
                'customer' => $customer
            ]);
            break;
            
        case 'delete':
            if (!hasPermission('customers', 'delete')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'No permission to delete']);
                exit;
            }
            
            $id = (int)$input['data']['id'];

            // Get customer and ledger account
            $stmt = $conn->prepare("
                SELECT c.ledger_account_id, la.name as ledger_name
                FROM customers c
                JOIN ledger_accounts la ON c.ledger_account_id = la.id
                WHERE c.id = ?
            ");
            $stmt->execute([$id]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$customer) {
                throw new Exception('Customer not found');
            }

            // Protect default Cash customer (by name, not ID)
            if (strcasecmp($customer['ledger_name'], 'Cash') === 0) {
                throw new Exception('Cannot delete the default "Cash" customer');
            }
            
            // Check if customer is in use
            $checkStmt = $conn->prepare("
                SELECT 
                    (SELECT COUNT(*) FROM sales WHERE customer_id = ?) as sale_count,
                    (SELECT COUNT(*) FROM receipts WHERE from_account_id = ?) as receipt_count
            ");
            $checkStmt->execute([$id, $customer['ledger_account_id']]);
            $usage = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            $errors = [];
            if ($usage['sale_count'] > 0) {
                $errors[] = "{$usage['sale_count']} sale(s)";
            }
            if ($usage['receipt_count'] > 0) {
                $errors[] = "{$usage['receipt_count']} receipt(s)";
            }
            
            if (!empty($errors)) {
                throw new Exception('Cannot delete this customer. It has: ' . implode(', ', $errors));
            }
            
            $conn->beginTransaction();
            
            // Delete customer first (due to foreign key)
            $stmt = $conn->prepare("DELETE FROM customers WHERE id = ?");
            $stmt->execute([$id]);
            
            // Then delete ledger account
            $stmt = $conn->prepare("DELETE FROM ledger_accounts WHERE id = ?");
            $stmt->execute([$customer['ledger_account_id']]);
            
            $conn->commit();
            
            logAudit('customers', 'delete', $id);
            
            echo json_encode([
                'success' => true,
                'message' => 'Customer deleted successfully'
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
        'error' => $e->getMessage()
    ]);
}
