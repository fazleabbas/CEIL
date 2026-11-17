<?php
/**
 * Ledgers Sync API - Create / Update / Delete (online only).
 * Restricted to manageable account types: cash, bank, expense, purchase, sales, duties.
 * Supplier and customer ledgers are managed via their own master pages.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

const ALLOWED_LEDGER_TYPES = ['cash','bank','expense','purchase','sales','duties'];

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';
$data = $input['data'] ?? [];

try {
    switch ($action) {
        case 'create':
            if (!hasPermission('ledgers', 'create')) {
                throw new Exception('Permission denied');
            }

            $name = trim($data['name'] ?? '');
            $accountType = trim($data['account_type'] ?? '');
            $currencyId = !empty($data['currency_id']) ? (int)$data['currency_id'] : null;
            $openingBalance = isset($data['opening_balance']) ? (float)$data['opening_balance'] : 0.00;
            $isActive = isset($data['is_active']) ? (int)$data['is_active'] : 1;

            if ($name === '') {
                throw new Exception('Ledger name is required');
            }
            if (!in_array($accountType, ALLOWED_LEDGER_TYPES, true)) {
                throw new Exception('Invalid account type');
            }

            // Default currency if not provided
            if (!$currencyId) {
                $defCur = getDefaultCurrency();
                $currencyId = $defCur ? (int)$defCur['id'] : null;
            }

            // Duplicate check
            $stmt = $conn->prepare("SELECT id FROM ledger_accounts WHERE name = ?");
            $stmt->execute([$name]);
            if ($stmt->fetch()) {
                throw new Exception('A ledger with this name already exists');
            }

            $stmt = $conn->prepare("
                INSERT INTO ledger_accounts
                (name, account_type, currency_id, opening_balance, current_balance, is_active)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$name, $accountType, $currencyId, $openingBalance, $openingBalance, $isActive]);
            $newId = $conn->lastInsertId();

            logAudit('ledgers', 'create', $newId, null, $data);

            echo json_encode([
                'success' => true,
                'message' => 'Ledger created successfully',
                'id' => $newId
            ]);
            break;

        case 'update':
            if (!hasPermission('ledgers', 'edit')) {
                throw new Exception('Permission denied');
            }

            $id = (int)($data['id'] ?? 0);
            $name = trim($data['name'] ?? '');
            $accountType = trim($data['account_type'] ?? '');
            $currencyId = !empty($data['currency_id']) ? (int)$data['currency_id'] : null;
            $openingBalance = isset($data['opening_balance']) ? (float)$data['opening_balance'] : 0.00;
            $isActive = isset($data['is_active']) ? (int)$data['is_active'] : 1;

            if (!$id || $name === '') {
                throw new Exception('Invalid data');
            }
            if (!in_array($accountType, ALLOWED_LEDGER_TYPES, true)) {
                throw new Exception('Invalid account type');
            }

            // Load existing
            $stmt = $conn->prepare("SELECT * FROM ledger_accounts WHERE id = ?");
            $stmt->execute([$id]);
            $oldData = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$oldData) {
                throw new Exception('Ledger not found');
            }
            // Hard guard: never let this page touch supplier/customer ledgers
            if (!in_array($oldData['account_type'], ALLOWED_LEDGER_TYPES, true)) {
                throw new Exception('This ledger is managed elsewhere and cannot be edited from here');
            }

            // Duplicate check (excluding current)
            $stmt = $conn->prepare("SELECT id FROM ledger_accounts WHERE name = ? AND id != ?");
            $stmt->execute([$name, $id]);
            if ($stmt->fetch()) {
                throw new Exception('A ledger with this name already exists');
            }

            if (!$currencyId) {
                $defCur = getDefaultCurrency();
                $currencyId = $defCur ? (int)$defCur['id'] : null;
            }

            $stmt = $conn->prepare("
                UPDATE ledger_accounts
                SET name = ?, account_type = ?, currency_id = ?, opening_balance = ?, is_active = ?
                WHERE id = ?
            ");
            $stmt->execute([$name, $accountType, $currencyId, $openingBalance, $isActive, $id]);

            logAudit('ledgers', 'update', $id, $oldData, $data);

            echo json_encode([
                'success' => true,
                'message' => 'Ledger updated successfully'
            ]);
            break;

        case 'delete':
            if (!hasPermission('ledgers', 'delete')) {
                throw new Exception('Permission denied');
            }

            $id = (int)($data['id'] ?? 0);
            if (!$id) {
                throw new Exception('Invalid ID');
            }

            $stmt = $conn->prepare("SELECT * FROM ledger_accounts WHERE id = ?");
            $stmt->execute([$id]);
            $ledger = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$ledger) {
                throw new Exception('Ledger not found');
            }
            if (!in_array($ledger['account_type'], ALLOWED_LEDGER_TYPES, true)) {
                throw new Exception('This ledger is managed elsewhere and cannot be deleted from here');
            }

            // In-use check: any transaction details using this ledger?
            $stmt = $conn->prepare("SELECT COUNT(*) FROM transaction_details WHERE ledger_id = ?");
            $stmt->execute([$id]);
            $txCount = (int)$stmt->fetchColumn();
            if ($txCount > 0) {
                throw new Exception("Cannot delete: {$txCount} transaction entr" . ($txCount === 1 ? 'y is' : 'ies are') . " using this ledger");
            }

            $stmt = $conn->prepare("DELETE FROM ledger_accounts WHERE id = ?");
            $stmt->execute([$id]);

            logAudit('ledgers', 'delete', $id, $ledger, null);

            echo json_encode([
                'success' => true,
                'message' => 'Ledger deleted successfully'
            ]);
            break;

        default:
            throw new Exception('Invalid action');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
