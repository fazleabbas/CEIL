<?php
/**
 * Quick Customer Creation API
 * Creates customer with ledger account (like purchases/receipts)
 */

require_once '../config/database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

// Check authentication
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Check permission
if (!hasPermission('customers', 'create')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No permission to create customers']);
    exit;
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty($input['name'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Customer name is required']);
    exit;
}

$name = trim($input['name']);

// Validate name
if (strlen($name) < 2) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Name must be at least 2 characters']);
    exit;
}

try {
    $conn->beginTransaction();
    
    // Check if customer already exists
    $checkStmt = $conn->prepare("
        SELECT c.id, la.name 
        FROM customers c 
        INNER JOIN ledger_accounts la ON c.ledger_account_id = la.id 
        WHERE la.name = ?
    ");
    $checkStmt->execute([$name]);
    $existing = $checkStmt->fetch();
    
    if ($existing) {
        $conn->rollBack();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Customer already exists']);
        exit;
    }
    
    // Create ledger account first
    $defaultCurrency = getDefaultCurrency();
    $ledgerStmt = $conn->prepare("
        INSERT INTO ledger_accounts (name, account_type, opening_balance, current_balance, currency_id, is_active)
        VALUES (?, 'customer', 0, 0, ?, 1)
    ");
    $ledgerStmt->execute([$name, $defaultCurrency['id']]);
    $ledgerAccountId = $conn->lastInsertId();
    
    // Create customer
    $customerStmt = $conn->prepare("
        INSERT INTO customers (ledger_account_id, is_active)
        VALUES (?, 1)
    ");
    $customerStmt->execute([$ledgerAccountId]);
    $customerId = $conn->lastInsertId();
    
    $conn->commit();
    
    // Log audit
    logAudit('customers', 'create', $customerId, null, [
        'name' => $name,
        'created_via' => 'sales_form'
    ]);
    
    echo json_encode([
        'success' => true,
        'id' => $customerId,
        'name' => $name,
        'message' => 'Customer created successfully'
    ]);
    
} catch (PDOException $e) {
    $conn->rollBack();
    error_log("Quick customer creation error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
