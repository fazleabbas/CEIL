<?php
/**
 * Exchange Rates CRUD API
 * Handles create, update, delete operations - NEVER CACHED
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
            if (!hasPermission('exchange_rates', 'create')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'No permission to create']);
                exit;
            }

            $fromCurrencyId = (int)($input['data']['from_currency_id'] ?? 0);
            $toCurrencyId = (int)($input['data']['to_currency_id'] ?? 0);
            $rate = floatval($input['data']['rate'] ?? 0);
            $effectiveDate = clean($input['data']['effective_date'] ?? '');

            if (!$fromCurrencyId || !$toCurrencyId) {
                throw new Exception('Both From and To currencies are required');
            }

            if ($fromCurrencyId === $toCurrencyId) {
                throw new Exception('From and To currencies must be different');
            }

            if ($rate <= 0) {
                throw new Exception('Rate must be greater than zero');
            }

            if (empty($effectiveDate)) {
                throw new Exception('Effective date is required');
            }

            // Validate currencies exist
            $checkFrom = $conn->prepare("SELECT id FROM currencies WHERE id = ?");
            $checkFrom->execute([$fromCurrencyId]);
            if (!$checkFrom->fetch()) {
                throw new Exception('From currency not found');
            }

            $checkTo = $conn->prepare("SELECT id FROM currencies WHERE id = ?");
            $checkTo->execute([$toCurrencyId]);
            if (!$checkTo->fetch()) {
                throw new Exception('To currency not found');
            }

            // Check for duplicate (same pair + same date)
            $dupCheck = $conn->prepare("
                SELECT id FROM exchange_rates
                WHERE from_currency_id = ? AND to_currency_id = ? AND effective_date = ?
            ");
            $dupCheck->execute([$fromCurrencyId, $toCurrencyId, $effectiveDate]);
            if ($dupCheck->fetch()) {
                throw new Exception('An exchange rate for this currency pair and date already exists');
            }

            $userId = getUserData('id');

            $stmt = $conn->prepare("
                INSERT INTO exchange_rates (from_currency_id, to_currency_id, rate, effective_date, created_by)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$fromCurrencyId, $toCurrencyId, $rate, $effectiveDate, $userId]);

            $newId = $conn->lastInsertId();
            logAudit('exchange_rates', 'create', $newId);

            echo json_encode([
                'success' => true,
                'message' => 'Exchange rate created successfully',
                'id' => $newId,
                'timestamp' => microtime(true)
            ]);
            break;

        case 'update':
            if (!hasPermission('exchange_rates', 'edit')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'No permission to edit']);
                exit;
            }

            $id = (int)$input['data']['id'];
            $fromCurrencyId = (int)($input['data']['from_currency_id'] ?? 0);
            $toCurrencyId = (int)($input['data']['to_currency_id'] ?? 0);
            $rate = floatval($input['data']['rate'] ?? 0);
            $effectiveDate = clean($input['data']['effective_date'] ?? '');

            if (!$fromCurrencyId || !$toCurrencyId) {
                throw new Exception('Both From and To currencies are required');
            }

            if ($fromCurrencyId === $toCurrencyId) {
                throw new Exception('From and To currencies must be different');
            }

            if ($rate <= 0) {
                throw new Exception('Rate must be greater than zero');
            }

            if (empty($effectiveDate)) {
                throw new Exception('Effective date is required');
            }

            // Check if rate exists
            $checkStmt = $conn->prepare("SELECT * FROM exchange_rates WHERE id = ?");
            $checkStmt->execute([$id]);
            $oldRate = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$oldRate) {
                throw new Exception('Exchange rate not found');
            }

            // Check for duplicate (same pair + same date, excluding self)
            $dupCheck = $conn->prepare("
                SELECT id FROM exchange_rates
                WHERE from_currency_id = ? AND to_currency_id = ? AND effective_date = ? AND id != ?
            ");
            $dupCheck->execute([$fromCurrencyId, $toCurrencyId, $effectiveDate, $id]);
            if ($dupCheck->fetch()) {
                throw new Exception('An exchange rate for this currency pair and date already exists');
            }

            $stmt = $conn->prepare("
                UPDATE exchange_rates
                SET from_currency_id = ?, to_currency_id = ?, rate = ?, effective_date = ?
                WHERE id = ?
            ");
            $stmt->execute([$fromCurrencyId, $toCurrencyId, $rate, $effectiveDate, $id]);

            logAudit('exchange_rates', 'update', $id, $oldRate, [
                'from_currency_id' => $fromCurrencyId,
                'to_currency_id' => $toCurrencyId,
                'rate' => $rate,
                'effective_date' => $effectiveDate
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Exchange rate updated successfully',
                'timestamp' => microtime(true)
            ]);
            break;

        case 'delete':
            if (!hasPermission('exchange_rates', 'delete')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'No permission to delete']);
                exit;
            }

            $id = (int)$input['data']['id'];

            // Check if rate exists
            $checkStmt = $conn->prepare("SELECT * FROM exchange_rates WHERE id = ?");
            $checkStmt->execute([$id]);
            if (!$checkStmt->fetch()) {
                throw new Exception('Exchange rate not found');
            }

            $stmt = $conn->prepare("DELETE FROM exchange_rates WHERE id = ?");
            $stmt->execute([$id]);

            logAudit('exchange_rates', 'delete', $id);

            echo json_encode([
                'success' => true,
                'message' => 'Exchange rate deleted successfully',
                'timestamp' => microtime(true)
            ]);
            break;

        default:
            throw new Exception('Invalid action');
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => microtime(true)
    ]);
}
