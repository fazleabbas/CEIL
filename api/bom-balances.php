<?php
/**
 * BOM Balances API
 *
 * Given a Bill of Materials, returns per-product balance info used by the
 * requisition voucher create/approve screens:
 *   - bom_qty:     quantity specified on the BOM for that product
 *   - transferred: quantity already transferred against this BOM (across all
 *                  requisition vouchers linked to it)
 *   - balance:     bom_qty - transferred
 *
 * Request (GET or POST JSON):
 *   bom_id - Bill of Materials ID (required)
 *
 * Response:
 *   {
 *     success: true,
 *     bom_id: int,
 *     products: { <productId>: { bom_qty, transferred, balance }, ... }
 *   }
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

// Requisition creators (create page) and approvers (approve modal) may read this.
// Additive — does not narrow any existing access.
if (!hasPermission('requisition_vouchers', 'create') && !hasPermission('requisition_vouchers', 'approve')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $bomId = isset($input['bom_id']) ? (int)$input['bom_id'] : 0;
} else {
    $bomId = isset($_GET['bom_id']) ? (int)$_GET['bom_id'] : 0;
}

if ($bomId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'bom_id is required']);
    exit;
}

try {
    $balances = getBomProductBalances($bomId, $conn);

    // Re-key as plain object for the client (product_id => balance row).
    $products = [];
    foreach ($balances as $pid => $row) {
        $products[(int)$pid] = [
            'bom_qty'     => (float)$row['bom_qty'],
            'transferred' => (float)$row['transferred'],
            'balance'     => (float)$row['balance'],
        ];
    }

    echo json_encode([
        'success'  => true,
        'bom_id'   => $bomId,
        'products' => $products,
    ]);

} catch (PDOException $e) {
    error_log('BOM Balances API Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
