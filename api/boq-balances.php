<?php
/**
 * BOQ Balances API
 *
 * Given a Bill of Quantities, returns per-product balance info used by the BOM
 * form to show, on every line, how much of the BOQ is still available:
 *   - boq_qty:  quantity on the BOQ for that product
 *   - on_other: quantity on OTHER BOMs raised against this BOQ that are pending
 *               approval or approved
 *   - balance:  boq_qty - on_other
 *   - in_boq:   whether the product is on the BOQ at all
 * See getBoqProductBalances() in includes/functions.php.
 *
 * Request (GET):
 *   boq_id         - Bill of Quantities ID (required)
 *   exclude_bom_id - the BOM being edited, so it is never counted against itself
 *
 * Response:
 *   { success: true, boq_id: int, products: { <productId>: {...}, ... } }
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

if (!hasPermission('bom', 'create') && !hasPermission('bom', 'edit') && !hasPermission('bom', 'approve')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

$boqId = isset($_GET['boq_id']) ? (int)$_GET['boq_id'] : 0;
$excludeBomId = isset($_GET['exclude_bom_id']) ? (int)$_GET['exclude_bom_id'] : 0;

if ($boqId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'boq_id is required']);
    exit;
}

try {
    $locStmt = $conn->prepare("SELECT location_id FROM boqs WHERE id = ?");
    $locStmt->execute([$boqId]);
    $boqLocation = $locStmt->fetchColumn();
    if ($boqLocation === false) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => __('boq_not_found')]);
        exit;
    }

    // A BOM may stay linked to a BOQ outside the user's locations (the form keeps
    // the existing link selectable), so its own balances must still load.
    $linkedStmt = $conn->prepare("SELECT COUNT(*) FROM boms WHERE id = ? AND boq_id = ?");
    $isOwnLink = $excludeBomId > 0 && bomBoqLinkAvailable($conn)
        && $linkedStmt->execute([$excludeBomId, $boqId]) && (int)$linkedStmt->fetchColumn() > 0;
    if (!$isOwnLink && !canAccessLocation((int)$boqLocation)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Forbidden']);
        exit;
    }

    $products = [];
    foreach (getBoqProductBalances($boqId, $conn, $excludeBomId) as $pid => $row) {
        $products[(int)$pid] = [
            'boq_qty'  => (float)$row['boq_qty'],
            'on_other' => (float)$row['on_other'],
            'balance'  => (float)$row['balance'],
            'in_boq'   => (bool)$row['in_boq'],
        ];
    }

    echo json_encode([
        'success'  => true,
        'boq_id'   => $boqId,
        'products' => (object)$products,
    ]);

} catch (PDOException $e) {
    error_log('BOQ Balances API Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
