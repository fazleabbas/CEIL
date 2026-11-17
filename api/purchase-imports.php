<?php
/**
 * Purchase Imports GET API
 * Retrieve all purchase imports with embedded details and costs
 */

header('Content-Type: application/json');

require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is logged in
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Check permissions
if (!hasPermission('purchase_imports', 'view')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

try {
    // Get user's accessible locations
    $userLocations = getUserLocations();
    $locationIds = array_column($userLocations, 'id');

    if (empty($locationIds)) {
        echo json_encode([
            'success' => true,
            'purchase_imports' => [],
            'timestamp' => date('c')
        ]);
        exit;
    }

    // Fetch purchase imports for user's locations, filtered by the date range
    // the UI requests. Both bounds are optional: omit either to leave that side
    // open. The old hard-coded 90-day window was removed so older imports are
    // reachable by widening the date filter on the page.
    $placeholders = str_repeat('?,', count($locationIds) - 1) . '?';

    $dateFrom = isset($_GET['dateFrom']) ? trim($_GET['dateFrom']) : '';
    $dateTo   = isset($_GET['dateTo'])   ? trim($_GET['dateTo'])   : '';
    // Accept only YYYY-MM-DD; anything else is ignored (treated as no bound).
    $validDate = fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;

    $dateConditions = '';
    $dateParams = [];
    if ($validDate($dateFrom)) {
        $dateConditions .= ' AND pi.import_date >= ?';
        $dateParams[] = $dateFrom;
    }
    if ($validDate($dateTo)) {
        $dateConditions .= ' AND pi.import_date <= ?';
        $dateParams[] = $dateTo;
    }

    // has_gl: 1 when the import has been posted to transactions (i.e. there's
    // a row in `transactions` with voucher_type='purchase_import' and
    // voucher_id=pi.id), 0 for legacy imports created before Phase 4 that
    // never had GL posted. Front-end uses it to render a "Save to post GL"
    // banner so users can upgrade old imports just by re-saving them.
    $stmt = $conn->prepare("
        SELECT
            pi.*,
            la.name as supplier_name,
            l.name as location_name,
            cc.name as cost_centre_name,
            cc.code as cost_centre_code,
            u.name as created_by_name,
            cur.code as currency_code,
            cur.symbol as currency_symbol,
            EXISTS (
                SELECT 1 FROM transactions t
                WHERE t.voucher_type = 'purchase_import' AND t.voucher_id = pi.id
            ) AS has_gl
        FROM purchase_imports pi
        LEFT JOIN suppliers s ON pi.supplier_id = s.id
        LEFT JOIN ledger_accounts la ON s.ledger_account_id = la.id
        LEFT JOIN locations l ON pi.location_id = l.id
        LEFT JOIN cost_centres cc ON pi.cost_centre_id = cc.id
        LEFT JOIN users u ON pi.created_by = u.id
        LEFT JOIN currencies cur ON pi.currency_id = cur.id
        WHERE pi.location_id IN ($placeholders)
          $dateConditions
        ORDER BY pi.import_date DESC, pi.id DESC
    ");

    $params = array_merge($locationIds, $dateParams);
    $stmt->execute($params);
    $imports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch details and costs for each import
    foreach ($imports as &$import) {
        // Details (items)
        $detailsStmt = $conn->prepare("
            SELECT
                pid.*,
                p.name as product_name,
                p.unit,
                pc.name as category_name
            FROM purchase_import_details pid
            JOIN products p ON pid.product_id = p.id
            LEFT JOIN product_categories pc ON p.category_id = pc.id
            WHERE pid.import_id = ?
            ORDER BY pid.id
        ");
        $detailsStmt->execute([$import['id']]);
        $import['details'] = $detailsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Additional costs — join display labels (ledger / beneficiary /
        // currency) and split by bucket so the view modal can render Bucket A
        // (supplier-borne), Bucket B (third-party), and Bucket C (other costs,
        // not added to costing) as separate groups. Mirrors getImportById() in
        // the sync API so both endpoints expose costs identically.
        $costsStmt = $conn->prepare("
            SELECT
                pic.*,
                la.name AS ledger_name,
                bla.name AS beneficiary_name,
                cur.code AS currency_code
            FROM purchase_import_costs pic
            LEFT JOIN ledger_accounts la ON pic.ledger_id = la.id
            LEFT JOIN suppliers bs ON pic.beneficiary_supplier_id = bs.id
            LEFT JOIN ledger_accounts bla ON bs.ledger_account_id = bla.id
            LEFT JOIN currencies cur ON pic.currency_id = cur.id
            WHERE pic.import_id = ?
            ORDER BY pic.id
        ");
        $costsStmt->execute([$import['id']]);
        $allCosts = $costsStmt->fetchAll(PDO::FETCH_ASSOC);
        $import['costs_a'] = array_values(array_filter($allCosts, fn($c) => ($c['bucket'] ?? 'B') === 'A'));
        $import['costs_c'] = array_values(array_filter($allCosts, fn($c) => ($c['bucket'] ?? 'B') === 'C'));
        // Bucket B = everything not A or C (legacy rows default to 'B').
        $import['costs']   = array_values(array_filter($allCosts, fn($c) => !in_array(($c['bucket'] ?? 'B'), ['A', 'C'], true)));
    }

    echo json_encode([
        'success' => true,
        'purchase_imports' => $imports,
        'timestamp' => date('c')
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
