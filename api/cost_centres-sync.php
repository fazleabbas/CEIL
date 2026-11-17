<?php
/**
 * Cost Centres Sync API
 * Handles create, update, delete operations
 */

// Prevent ANY caching
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');

require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is logged in
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Read JSON input
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';
$data = $input['data'] ?? $input;

/**
 * Replace the link-table rows for a given cost centre with the supplied
 * user ids. Used for both `cost_centre_approvers` and `cost_centre_watchers`.
 *
 * - Silently skips when the underlying table is missing (pre-migration safety).
 * - Filters to integers > 0 and de-duplicates before inserting.
 */
function cc_syncUserLinks($conn, $table, $costCentreId, $userIds) {
    try {
        // Test table existence cheaply; if missing, just return.
        $conn->query("SELECT 1 FROM `{$table}` LIMIT 1");
    } catch (PDOException $e) {
        return; // table missing — skip silently (migration 0025 not yet applied)
    }

    $ids = [];
    if (is_array($userIds)) {
        foreach ($userIds as $uid) {
            $uid = (int)$uid;
            if ($uid > 0) $ids[$uid] = true;
        }
    }

    // Wipe + re-insert (simple, correct, low row counts expected).
    $del = $conn->prepare("DELETE FROM `{$table}` WHERE cost_centre_id = ?");
    $del->execute([$costCentreId]);

    if (empty($ids)) return;

    $ins = $conn->prepare("INSERT INTO `{$table}` (cost_centre_id, user_id) VALUES (?, ?)");
    foreach (array_keys($ids) as $uid) {
        try {
            $ins->execute([$costCentreId, $uid]);
        } catch (PDOException $e) {
            // FK violation (user deleted?) — skip that one row, continue.
            error_log("cc_syncUserLinks: skipped {$table} cc={$costCentreId} user={$uid}: " . $e->getMessage());
        }
    }
}

try {
    switch ($action) {
        case 'create':
            if (!hasPermission('cost_centres', 'create')) {
                throw new Exception('No permission to create cost centres');
            }

            $code = cleanText($data['code'] ?? '');
            $name = cleanText($data['name'] ?? '');
            $description = cleanText($data['description'] ?? '');
            $startDate = $data['start_date'] ?? null;
            $endDate = $data['end_date'] ?? null;
            $status = $data['status'] ?? 'active';
            $budgetAmount = floatval($data['budget_amount'] ?? 0);
            $isActive = isset($data['is_active']) ? (int)$data['is_active'] : 1;
            $parentId = isset($data['parent_id']) && $data['parent_id'] !== '' && $data['parent_id'] !== null ? (int)$data['parent_id'] : null;

            if (empty($code)) throw new Exception('Cost centre code is required');
            if (empty($name)) throw new Exception('Cost centre name is required');

            // Validate parent exists if specified
            if ($parentId !== null) {
                $parentCheck = $conn->prepare("SELECT id FROM cost_centres WHERE id = ?");
                $parentCheck->execute([$parentId]);
                if (!$parentCheck->fetch()) {
                    throw new Exception('Parent cost centre not found');
                }
            }

            // Check duplicate code
            $checkStmt = $conn->prepare("SELECT id FROM cost_centres WHERE code = ?");
            $checkStmt->execute([$code]);
            if ($checkStmt->fetch()) {
                throw new Exception('A cost centre with this code already exists');
            }

            $stmt = $conn->prepare("
                INSERT INTO cost_centres (parent_id, code, name, description, start_date, end_date, status, budget_amount, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $parentId, $code, $name, $description ?: null,
                $startDate ?: null, $endDate ?: null,
                $status, $budgetAmount, $isActive
            ]);

            $newId = $conn->lastInsertId();

            // Persist notification routing (only touched when keys are present
            // in the payload — keeps old API clients backwards-compatible).
            if (array_key_exists('approver_ids', $data)) {
                cc_syncUserLinks($conn, 'cost_centre_approvers', (int)$newId, $data['approver_ids']);
            }
            if (array_key_exists('watcher_ids', $data)) {
                cc_syncUserLinks($conn, 'cost_centre_watchers', (int)$newId, $data['watcher_ids']);
            }

            logAudit('cost_centres', 'create', $newId);

            // Return the created record
            $fetchStmt = $conn->prepare("
                SELECT cc.*,
                    (SELECT COUNT(*) FROM user_cost_centres WHERE cost_centre_id = cc.id) as user_count
                FROM cost_centres cc WHERE cc.id = ?
            ");
            $fetchStmt->execute([$newId]);
            $costCentre = $fetchStmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'message' => 'Cost centre created successfully',
                'cost_centre' => $costCentre,
                'timestamp' => microtime(true)
            ]);
            break;

        case 'update':
            if (!hasPermission('cost_centres', 'edit')) {
                throw new Exception('No permission to edit cost centres');
            }

            $id = (int)($data['id'] ?? 0);
            if (!$id) throw new Exception('Cost centre ID is required');

            // Get existing record for audit
            $oldStmt = $conn->prepare("SELECT * FROM cost_centres WHERE id = ?");
            $oldStmt->execute([$id]);
            $oldRecord = $oldStmt->fetch(PDO::FETCH_ASSOC);
            if (!$oldRecord) throw new Exception('Cost centre not found');

            $code = cleanText($data['code'] ?? '');
            $name = cleanText($data['name'] ?? '');
            $description = cleanText($data['description'] ?? '');
            $startDate = $data['start_date'] ?? null;
            $endDate = $data['end_date'] ?? null;
            $status = $data['status'] ?? 'active';
            $budgetAmount = floatval($data['budget_amount'] ?? 0);
            $isActive = isset($data['is_active']) ? (int)$data['is_active'] : 1;
            $parentId = isset($data['parent_id']) && $data['parent_id'] !== '' && $data['parent_id'] !== null ? (int)$data['parent_id'] : null;

            if (empty($code)) throw new Exception('Cost centre code is required');
            if (empty($name)) throw new Exception('Cost centre name is required');

            // Prevent setting self as parent
            if ($parentId !== null && $parentId === $id) {
                throw new Exception('A cost centre cannot be its own parent');
            }

            // Prevent circular reference
            if ($parentId !== null) {
                $descendantIds = getDescendantCostCentreIds($id, $conn);
                if (in_array($parentId, $descendantIds)) {
                    throw new Exception('Cannot set a child cost centre as parent (circular reference)');
                }
            }

            // Check duplicate code (exclude self)
            $checkStmt = $conn->prepare("SELECT id FROM cost_centres WHERE code = ? AND id != ?");
            $checkStmt->execute([$code, $id]);
            if ($checkStmt->fetch()) {
                throw new Exception('A cost centre with this code already exists');
            }

            $stmt = $conn->prepare("
                UPDATE cost_centres
                SET parent_id = ?, code = ?, name = ?, description = ?, start_date = ?, end_date = ?,
                    status = ?, budget_amount = ?, is_active = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $parentId, $code, $name, $description ?: null,
                $startDate ?: null, $endDate ?: null,
                $status, $budgetAmount, $isActive, $id
            ]);

            // Persist notification routing (only touched when keys are present
            // in the payload — keeps old API clients backwards-compatible).
            if (array_key_exists('approver_ids', $data)) {
                cc_syncUserLinks($conn, 'cost_centre_approvers', $id, $data['approver_ids']);
            }
            if (array_key_exists('watcher_ids', $data)) {
                cc_syncUserLinks($conn, 'cost_centre_watchers', $id, $data['watcher_ids']);
            }

            // Get updated record
            $fetchStmt = $conn->prepare("
                SELECT cc.*,
                    (SELECT COUNT(*) FROM user_cost_centres WHERE cost_centre_id = cc.id) as user_count
                FROM cost_centres cc WHERE cc.id = ?
            ");
            $fetchStmt->execute([$id]);
            $costCentre = $fetchStmt->fetch(PDO::FETCH_ASSOC);

            logAudit('cost_centres', 'update', $id, $oldRecord, $costCentre);

            echo json_encode([
                'success' => true,
                'message' => 'Cost centre updated successfully',
                'cost_centre' => $costCentre,
                'timestamp' => microtime(true)
            ]);
            break;

        case 'delete':
            if (!hasPermission('cost_centres', 'delete')) {
                throw new Exception('No permission to delete cost centres');
            }

            $id = (int)($data['id'] ?? 0);
            if (!$id) throw new Exception('Cost centre ID is required');

            // Check if cost centre has transactions
            $txnCount = 0;
            $tables = ['purchases', 'sales', 'payments', 'receipts', 'stock_transfers'];
            foreach ($tables as $table) {
                $countStmt = $conn->prepare("SELECT COUNT(*) FROM $table WHERE cost_centre_id = ?");
                $countStmt->execute([$id]);
                $txnCount += (int)$countStmt->fetchColumn();
            }

            if ($txnCount > 0) {
                throw new Exception(sprintf('This cost centre has %d transaction(s). Remove all transactions first before deleting.', $txnCount));
            }

            // Delete (user_cost_centres will cascade)
            $conn->prepare("DELETE FROM cost_centres WHERE id = ?")->execute([$id]);
            logAudit('cost_centres', 'delete', $id);

            echo json_encode([
                'success' => true,
                'message' => 'Cost centre deleted successfully',
                'timestamp' => microtime(true)
            ]);
            break;

        default:
            throw new Exception('Invalid action: ' . $action);
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
