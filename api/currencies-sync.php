<?php
/**
 * Currencies CRUD API
 * Handles create, update, delete, set_default operations - NEVER CACHED
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
            if (!hasPermission('currencies', 'create')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'No permission to create']);
                exit;
            }

            $code = strtoupper(clean($input['data']['code'] ?? ''));
            $name = clean($input['data']['name'] ?? '');
            $symbol = clean($input['data']['symbol'] ?? '');
            $decimalPlaces = (int)($input['data']['decimal_places'] ?? 2);
            $isDefault = (int)($input['data']['is_default'] ?? 0);
            $isActive = (int)($input['data']['is_active'] ?? 1);

            if (empty($code) || strlen($code) !== 3) {
                throw new Exception('Currency code must be exactly 3 characters (ISO 4217)');
            }

            if (empty($name)) {
                throw new Exception('Currency name is required');
            }

            if (empty($symbol)) {
                throw new Exception('Currency symbol is required');
            }

            // Check for duplicate code
            $check = $conn->prepare("SELECT id FROM currencies WHERE code = ?");
            $check->execute([$code]);
            if ($check->fetch()) {
                throw new Exception('A currency with this code already exists');
            }

            $conn->beginTransaction();

            // If setting as default, unset all others first
            if ($isDefault) {
                $conn->exec("UPDATE currencies SET is_default = 0");
            }

            $stmt = $conn->prepare("
                INSERT INTO currencies (code, name, symbol, decimal_places, is_default, is_active)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$code, $name, $symbol, $decimalPlaces, $isDefault, $isActive]);

            $newId = $conn->lastInsertId();
            $conn->commit();

            logAudit('currencies', 'create', $newId);

            // Get the created currency
            $getStmt = $conn->prepare("SELECT * FROM currencies WHERE id = ?");
            $getStmt->execute([$newId]);
            $currency = $getStmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'message' => 'Currency created successfully',
                'currency' => $currency,
                'timestamp' => microtime(true)
            ]);
            break;

        case 'update':
            if (!hasPermission('currencies', 'edit')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'No permission to edit']);
                exit;
            }

            $id = (int)$input['data']['id'];
            $code = strtoupper(clean($input['data']['code'] ?? ''));
            $name = clean($input['data']['name'] ?? '');
            $symbol = clean($input['data']['symbol'] ?? '');
            $decimalPlaces = (int)($input['data']['decimal_places'] ?? 2);
            $isDefault = (int)($input['data']['is_default'] ?? 0);
            $isActive = (int)($input['data']['is_active'] ?? 1);

            if (empty($code) || strlen($code) !== 3) {
                throw new Exception('Currency code must be exactly 3 characters (ISO 4217)');
            }

            if (empty($name)) {
                throw new Exception('Currency name is required');
            }

            if (empty($symbol)) {
                throw new Exception('Currency symbol is required');
            }

            // Check if currency exists
            $checkStmt = $conn->prepare("SELECT * FROM currencies WHERE id = ?");
            $checkStmt->execute([$id]);
            $oldCurrency = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$oldCurrency) {
                throw new Exception('Currency not found');
            }

            // Check for duplicate code
            $dupStmt = $conn->prepare("SELECT id FROM currencies WHERE code = ? AND id != ?");
            $dupStmt->execute([$code, $id]);
            if ($dupStmt->fetch()) {
                throw new Exception('A currency with this code already exists');
            }

            // Cannot deactivate the default currency
            if ($oldCurrency['is_default'] == 1 && $isActive == 0) {
                throw new Exception('Cannot deactivate the default currency');
            }

            $conn->beginTransaction();

            // If setting as default, unset all others first
            if ($isDefault && $oldCurrency['is_default'] != 1) {
                $conn->exec("UPDATE currencies SET is_default = 0");
            }

            // Cannot unset default without setting another
            if ($oldCurrency['is_default'] == 1 && !$isDefault) {
                $conn->rollBack();
                throw new Exception('Cannot remove default status. Set another currency as default first.');
            }

            $stmt = $conn->prepare("
                UPDATE currencies
                SET code = ?, name = ?, symbol = ?, decimal_places = ?, is_default = ?, is_active = ?
                WHERE id = ?
            ");
            $stmt->execute([$code, $name, $symbol, $decimalPlaces, $isDefault, $isActive, $id]);

            $conn->commit();

            logAudit('currencies', 'update', $id, $oldCurrency, [
                'code' => $code,
                'name' => $name,
                'symbol' => $symbol,
                'decimal_places' => $decimalPlaces,
                'is_default' => $isDefault,
                'is_active' => $isActive
            ]);

            // Get updated currency
            $getStmt = $conn->prepare("SELECT * FROM currencies WHERE id = ?");
            $getStmt->execute([$id]);
            $currency = $getStmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'message' => 'Currency updated successfully',
                'currency' => $currency,
                'timestamp' => microtime(true)
            ]);
            break;

        case 'set_default':
            if (!hasPermission('currencies', 'edit')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'No permission to edit']);
                exit;
            }

            $id = (int)$input['data']['id'];

            // Check if currency exists and is active
            $checkStmt = $conn->prepare("SELECT * FROM currencies WHERE id = ?");
            $checkStmt->execute([$id]);
            $currency = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$currency) {
                throw new Exception('Currency not found');
            }

            if ($currency['is_active'] != 1) {
                throw new Exception('Cannot set an inactive currency as default');
            }

            $conn->beginTransaction();
            $conn->exec("UPDATE currencies SET is_default = 0");
            $stmt = $conn->prepare("UPDATE currencies SET is_default = 1 WHERE id = ?");
            $stmt->execute([$id]);
            $conn->commit();

            logAudit('currencies', 'set_default', $id);

            echo json_encode([
                'success' => true,
                'message' => $currency['code'] . ' set as default currency',
                'timestamp' => microtime(true)
            ]);
            break;

        case 'delete':
            if (!hasPermission('currencies', 'delete')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'No permission to delete']);
                exit;
            }

            $id = (int)$input['data']['id'];

            // Check if currency exists
            $checkStmt = $conn->prepare("SELECT * FROM currencies WHERE id = ?");
            $checkStmt->execute([$id]);
            $currency = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$currency) {
                throw new Exception('Currency not found');
            }

            // Cannot delete default currency
            if ($currency['is_default'] == 1) {
                throw new Exception('Cannot delete the default currency. Set another currency as default first.');
            }

            // Check if currency is in use by any transaction
            $tables = ['sales', 'purchases', 'payments', 'receipts', 'stock_transfers', 'stock_adjustments', 'consumption_vouchers'];
            foreach ($tables as $table) {
                $usageStmt = $conn->prepare("SELECT COUNT(*) as count FROM `$table` WHERE currency_id = ?");
                $usageStmt->execute([$id]);
                $count = $usageStmt->fetch()['count'];
                if ($count > 0) {
                    throw new Exception("Cannot delete this currency. It is used in $count $table record(s).");
                }
            }

            // Also check exchange_rates
            $erStmt = $conn->prepare("SELECT COUNT(*) as count FROM exchange_rates WHERE from_currency_id = ? OR to_currency_id = ?");
            $erStmt->execute([$id, $id]);
            $erCount = $erStmt->fetch()['count'];
            if ($erCount > 0) {
                throw new Exception("Cannot delete this currency. It has $erCount exchange rate(s). Delete the exchange rates first.");
            }

            // Delete currency
            $stmt = $conn->prepare("DELETE FROM currencies WHERE id = ?");
            $stmt->execute([$id]);

            logAudit('currencies', 'delete', $id);

            echo json_encode([
                'success' => true,
                'message' => 'Currency deleted successfully',
                'timestamp' => microtime(true)
            ]);
            break;

        default:
            throw new Exception('Invalid action');
    }

} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => microtime(true)
    ]);
}
