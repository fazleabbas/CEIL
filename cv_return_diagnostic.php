<?php
/**
 * TEMPORARY DIAGNOSTIC - delete this file once the Create Return button works.
 *
 * Reports every condition that has to be true for the button to render on
 * modules/transactions/consumption_voucher_view.php, so the missing one is
 * named instead of guessed at.
 *
 * Usage:  https://<your site>/cv_return_diagnostic.php?id=<a consumption voucher id>
 *         (log in to the app first - this uses your own session and permissions)
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: text/plain; charset=utf-8');

if (!isLoggedIn()) {
    die("NOT LOGGED IN - open the app and log in first, then reload this page.\n");
}

function say($label, $ok, $detail = '') {
    printf("%-46s %s%s\n", $label, $ok ? '[ OK ]' : '[ FAIL ]', $detail !== '' ? '  ' . $detail : '');
}

echo "=== Create Return button diagnostic ===\n\n";

// ---------------------------------------------------------------- 1. code files
$viewFile   = __DIR__ . '/modules/transactions/consumption_voucher_view.php';
$returnFile = __DIR__ . '/modules/transactions/consumption_voucher_return.php';

$viewHasButton = is_file($viewFile) && strpos(file_get_contents($viewFile), 'consumption_voucher_return.php?from=') !== false;
say('1. consumption_voucher_return.php uploaded', is_file($returnFile),
    is_file($returnFile) ? '' : 'FILE MISSING - upload it');
say('2. view page contains the button code', $viewHasButton,
    $viewHasButton ? '' : 'STALE consumption_voucher_view.php - re-upload');

$fnHas = function_exists('cvReturnsAvailable') && function_exists('cvCanEdit') && function_exists('cvCanDelete');
say('3. functions.php has the return helpers', $fnHas,
    $fnHas ? '' : 'STALE includes/functions.php - re-upload');

if (!$fnHas) {
    die("\nStop here: includes/functions.php on the server is the old copy. Re-upload it.\n");
}

// ---------------------------------------------------------------- 2. migration
$hasCol = false;
try {
    $conn->query("SELECT return_of_voucher_id FROM consumption_vouchers LIMIT 1");
    $conn->query("SELECT return_of_detail_id FROM consumption_voucher_details LIMIT 1");
    $hasCol = true;
} catch (Throwable $e) {
    $hasCol = false;
}
say('4. migration 0047 columns exist', $hasCol,
    $hasCol ? '' : 'RUN MIGRATION 0047 - this is almost certainly it');

$prefix = getSetting('cvr_prefix', '');
say('5. cvr_prefix setting seeded', $prefix !== '', $prefix !== '' ? "= $prefix" : 'migration 0047 not run');

// PDO must raise exceptions, or the guard above cannot detect a missing column.
$errMode = $conn->getAttribute(PDO::ATTR_ERRMODE);
say('6. PDO throws on SQL errors', $errMode === PDO::ERRMODE_EXCEPTION,
    'ERRMODE=' . $errMode . ($errMode === PDO::ERRMODE_EXCEPTION ? '' : ' (guards may misreport)'));

// ---------------------------------------------------------------- 3. permission
$canCreate = hasPermission('consumption_vouchers', 'create');
say('7. YOU hold consumption_vouchers.create', $canCreate,
    $canCreate ? '' : 'the button needs CREATE, not just view/edit');

// ---------------------------------------------------------------- 4. the voucher
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    echo "\nPass ?id=<voucher id> to also check a specific voucher.\n";
    echo "Recent vouchers:\n";
    foreach ($conn->query("SELECT id, voucher_number FROM consumption_vouchers ORDER BY id DESC LIMIT 10") as $r) {
        echo "   id={$r['id']}  {$r['voucher_number']}\n";
    }
    exit;
}

$stmt = $conn->prepare("SELECT * FROM consumption_vouchers WHERE id = ?");
$stmt->execute([$id]);
$voucher = $stmt->fetch();

if (!$voucher) {
    die("\nNo voucher with id $id.\n");
}

echo "\n--- voucher " . $voucher['voucher_number'] . " ---\n";

$isReturn = !empty($voucher['return_of_voucher_id']);
say('8. this voucher is NOT itself a return', !$isReturn,
    $isReturn ? 'a return cannot be returned - open the ORIGINAL voucher' : '');

$dStmt = $conn->prepare("SELECT id, quantity FROM consumption_voucher_details WHERE voucher_id = ?");
$dStmt->execute([$id]);
$details = $dStmt->fetchAll();

$returnable = 0;
if (!$isReturn && cvReturnsAvailable($conn)) {
    $returnedMap = cvReturnedQuantities($conn, $id);
    foreach ($details as $d) {
        $already = isset($returnedMap[(int)$d['id']]) ? (float)$returnedMap[(int)$d['id']] : 0.0;
        $returnable += max(0, (float)$d['quantity'] - $already);
    }
}
say('9. something is still returnable', $returnable > 0, 'returnable = ' . $returnable
    . ' across ' . count($details) . ' line(s)');

say('10. you can access this location', canAccessLocation($voucher['location_id']));

echo "\n=== VERDICT ===\n";
if (!$hasCol) {
    echo "Migration 0047 has NOT been run on this database. Run it - that is why the button is hidden.\n";
} elseif (!$canCreate) {
    echo "You do not hold consumption_vouchers.create. Tick Create for this module in Masters -> Users.\n";
} elseif ($isReturn) {
    echo "You are looking at a RETURN voucher. Open the original consumption voucher instead.\n";
} elseif ($returnable <= 0) {
    echo "Everything on this voucher has already been returned.\n";
} elseif (!$viewHasButton) {
    echo "consumption_voucher_view.php on the server is the OLD copy. Re-upload it.\n";
} else {
    echo "All conditions pass - the button SHOULD render on\n";
    echo "modules/transactions/consumption_voucher_view.php?id=$id\n";
    echo "If you still do not see it, you are probably looking at the LIST page (the button is\n";
    echo "only on the voucher VIEW page), or the PWA service worker is serving a cached page -\n";
    echo "hard-reload with Ctrl+F5.\n";
}
