<?php
/**
 * This endpoint is polled every 60 seconds by includes/header.php whether or not
 * anyone is at the keyboard, so by default it must NOT count as user activity —
 * otherwise it would postpone the idle logout forever (and it is the reason
 * PHP's own session GC never expires a session here while a tab is open).
 *
 * The poller appends &active=1 when the browser has seen real interaction since
 * the previous poll; only then does this request push the clock forward.
 *
 * Must be defined BEFORE functions.php, which enforces the timeout on include.
 */
define('SESSION_ACTIVITY_PASSIVE', !isset($_GET['active']) || $_GET['active'] !== '1');

require_once '../config/database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = '';

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
} else {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? $_POST['action'] ?? '';
}

switch ($action) {
    case 'recent':
        $notifications = getRecentNotifications(15);
        $unreadCount = getUnreadNotificationCount();
        echo json_encode([
            'success' => true,
            'notifications' => $notifications,
            'unread_count' => $unreadCount
        ]);
        break;

    case 'mark_read':
        $id = (int)($input['id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, getUserId()]);
        }
        echo json_encode(['success' => true]);
        break;

    case 'mark_all_read':
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0");
        $stmt->execute([getUserId()]);
        echo json_encode(['success' => true]);
        break;

    case 'count':
        echo json_encode([
            'success' => true,
            'unread_count' => getUnreadNotificationCount()
        ]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}
