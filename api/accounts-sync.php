<?php
/**
 * Accounts Sync API - POST Endpoint
 * Handles create operations for cash/bank accounts
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

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

$action = $input['action'] ?? '';
$data = $input['data'] ?? [];

try {
    switch ($action) {
        case 'create':
            handleCreate($data, $conn);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
            exit;
    }
} catch (Exception $e) {
    error_log("Accounts Sync Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * Handle CREATE cash/bank account
 */
function handleCreate($data, $conn) {
    // Validate required fields
    $errors = [];

    if (empty($data['name'])) {
        $errors[] = 'Account name is required';
    }

    if (empty($data['account_type']) || !in_array($data['account_type'], ['cash', 'bank'])) {
        $errors[] = 'Account type must be cash or bank';
    }

    if (!empty($errors)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => implode(', ', $errors)]);
        exit;
    }

    // Check if account name already exists
    $checkStmt = $conn->prepare("SELECT id FROM ledger_accounts WHERE name = ?");
    $checkStmt->execute([$data['name']]);
    if ($checkStmt->fetch()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'An account with this name already exists']);
        exit;
    }

    try {
        // Create new Cash/Bank account
        $defaultCurrency = getDefaultCurrency();
        $stmt = $conn->prepare("
            INSERT INTO ledger_accounts
            (name, account_type, opening_balance, current_balance, currency_id, is_active)
            VALUES (?, ?, 0, 0, ?, 1)
        ");
        $stmt->execute([$data['name'], $data['account_type'], $defaultCurrency['id']]);
        $accountId = $conn->lastInsertId();

        // Fetch the created account
        $stmt = $conn->prepare("SELECT * FROM ledger_accounts WHERE id = ?");
        $stmt->execute([$accountId]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        logAudit('ledger_accounts', 'create', $accountId, null, [
            'name' => $data['name'],
            'type' => $data['account_type'],
            'created_via' => 'sales_pos'
        ]);

        echo json_encode([
            'success' => true,
            'serverId' => $accountId,
            'account' => $account,
            'message' => 'Account created successfully'
        ]);

    } catch (PDOException $e) {
        throw new Exception('Database error: ' . $e->getMessage());
    }
}
