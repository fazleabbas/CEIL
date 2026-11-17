<?php
/**
 * Cost Centres GET API
 * Returns all cost centres with usage counts - ALWAYS FRESH, NEVER CACHED
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

// Side-channel: ?action=users — returns active users for the notification
// multi-selects in the Cost Centre modal. Requires cost_centres edit OR
// create permission (same gate as opening the modal in the first place).
$action = $_GET['action'] ?? '';
if ($action === 'users') {
    if (!hasPermission('cost_centres', 'edit') && !hasPermission('cost_centres', 'create')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Forbidden']);
        exit;
    }
    try {
        $stmt = $conn->prepare("
            SELECT u.id, u.name AS name, u.email
            FROM users u
            WHERE u.is_active = 1
            ORDER BY u.name ASC
        ");
        $stmt->execute();
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'users' => $users]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Check permissions
if (!hasPermission('cost_centres', 'view')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

try {
    $stmt = $conn->prepare("
        SELECT
            cc.*,
            (SELECT COUNT(*) FROM user_cost_centres WHERE cost_centre_id = cc.id) as user_count
        FROM cost_centres cc
        ORDER BY cc.name ASC
    ");
    $stmt->execute();
    $costCentres = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Attach approver_ids and watcher_ids per CC. Wrapped in try/catch so the
    // endpoint keeps working even before migration 0025 is applied.
    try {
        $approverStmt = $conn->query("SELECT cost_centre_id, user_id FROM cost_centre_approvers");
        $watcherStmt  = $conn->query("SELECT cost_centre_id, user_id FROM cost_centre_watchers");

        $approverMap = [];
        foreach ($approverStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $approverMap[(int)$row['cost_centre_id']][] = (int)$row['user_id'];
        }
        $watcherMap = [];
        foreach ($watcherStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $watcherMap[(int)$row['cost_centre_id']][] = (int)$row['user_id'];
        }

        foreach ($costCentres as &$cc) {
            $ccId = (int)$cc['id'];
            $cc['approver_ids'] = $approverMap[$ccId] ?? [];
            $cc['watcher_ids']  = $watcherMap[$ccId]  ?? [];
        }
        unset($cc);
    } catch (PDOException $e) {
        // Tables missing — pre-migration; fall back to empty arrays so the UI
        // still loads instead of erroring out.
        foreach ($costCentres as &$cc) {
            $cc['approver_ids'] = [];
            $cc['watcher_ids']  = [];
        }
        unset($cc);
    }

    echo json_encode([
        'success' => true,
        'cost_centres' => $costCentres,
        'timestamp' => microtime(true),
        'server_time' => date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
