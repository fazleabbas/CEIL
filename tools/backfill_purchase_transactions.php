<?php
/**
 * One-time backfill: write transaction + transaction_details rows for purchases
 * created BEFORE the VAT/transactions feature shipped.
 *
 * Idempotent — purchases that already have a row in `transactions` are skipped.
 * Safe to run multiple times. Admin only.
 *
 * Usage:
 *   1. Log in as Admin
 *   2. Open: <BASE_URL>tools/backfill_purchase_transactions.php
 *   3. Click "Run Backfill"
 *   4. Re-run any time — already-processed purchases are skipped.
 *
 * Add ?debug=1 to see raw POST/server diagnostics.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isLoggedIn()) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}
if (getUserData('role_name') !== 'Admin') {
    http_response_code(403);
    die('This tool is restricted to Admin users.');
}

// --------------------------------------------------------
// Status query: total purchases, already backfilled, pending
// --------------------------------------------------------
function fetchStatus($conn) {
    $total = (int)$conn->query("SELECT COUNT(*) FROM purchases")->fetchColumn();
    $done  = (int)$conn->query("
        SELECT COUNT(DISTINCT p.id)
        FROM purchases p
        INNER JOIN transactions t ON t.voucher_type = 'purchase' AND t.voucher_id = p.id
    ")->fetchColumn();
    return ['total' => $total, 'done' => $done, 'pending' => $total - $done];
}

$status = fetchStatus($conn);

// --------------------------------------------------------
// Action: run the backfill if requested
// --------------------------------------------------------
$ranBackfill   = false;
$successCount  = 0;
$skippedCount  = 0;
$failures      = [];
$fatalError    = null;
$debug         = isset($_GET['debug']);

$postWasReceived  = ($_SERVER['REQUEST_METHOD'] === 'POST');
$runBackfillFlag  = isset($_POST['run_backfill']) ? $_POST['run_backfill'] : null;

if ($postWasReceived && $runBackfillFlag) {
    $ranBackfill = true;

    try {
        // Fetch pending purchases (no transactions row yet)
        $pendingStmt = $conn->prepare("
            SELECT p.id
            FROM purchases p
            LEFT JOIN transactions t ON t.voucher_type = 'purchase' AND t.voucher_id = p.id
            WHERE t.id IS NULL
            ORDER BY p.id ASC
        ");
        $pendingStmt->execute();
        $pendingIds = $pendingStmt->fetchAll(PDO::FETCH_COLUMN);

        // Pre-create system ledgers ONCE outside the per-purchase transaction.
        // (They'd be auto-created on first call anyway, but this keeps each
        // per-row transaction tighter.)
        ensureSystemLedgers($conn);

        foreach ($pendingIds as $pid) {
            // Re-check inside the loop to defend against concurrent inserts
            $check = $conn->prepare("SELECT id FROM transactions WHERE voucher_type='purchase' AND voucher_id = ?");
            $check->execute([$pid]);
            if ($check->fetch()) {
                $skippedCount++;
                continue;
            }

            try {
                $conn->beginTransaction();
                createPurchaseTransaction($conn, $pid);
                $conn->commit();
                $successCount++;
            } catch (Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                $failures[] = [
                    'purchase_id' => $pid,
                    'error'       => $e->getMessage(),
                ];
            }
        }
    } catch (Throwable $e) {
        // Anything outside the per-purchase try lands here (DB schema issues, etc.)
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $fatalError = $e->getMessage()
            . "\n\n" . $e->getFile() . ':' . $e->getLine()
            . "\n\n" . $e->getTraceAsString();
    }

    // Refresh status counts after run
    $status = fetchStatus($conn);
}

$pageTitle = 'Backfill Purchase Transactions';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-3">
    <div class="col-md-12">
        <h2><i class="bi bi-arrow-repeat"></i> Backfill Purchase Transactions</h2>
        <p class="text-muted mb-0">
            One-time tool: writes <code>transactions</code> + <code>transaction_details</code> rows
            for purchases created before this feature shipped. Safe to run multiple times — already-processed purchases are skipped.
        </p>
    </div>
</div>

<?php if ($debug): ?>
<div class="alert alert-info">
    <strong>Debug</strong>
    <pre class="mb-0 small">REQUEST_METHOD: <?php echo htmlspecialchars($_SERVER['REQUEST_METHOD'] ?? ''); ?>
postWasReceived: <?php echo $postWasReceived ? 'true' : 'false'; ?>

runBackfillFlag: <?php var_export($runBackfillFlag); ?>

ranBackfill:    <?php echo $ranBackfill ? 'true' : 'false'; ?>

$_POST: <?php print_r($_POST); ?>

$_GET:  <?php print_r($_GET); ?>
status: <?php print_r($status); ?>
</pre>
</div>
<?php endif; ?>

<?php if ($fatalError !== null): ?>
<div class="alert alert-danger">
    <h5 class="mb-2"><i class="bi bi-x-octagon"></i> Fatal Error</h5>
    <p class="mb-2 small">The backfill aborted before it could finish. None of the purchases in this run were committed.</p>
    <pre class="mb-0 small" style="max-height: 240px; overflow:auto;"><?php echo htmlspecialchars($fatalError); ?></pre>
</div>
<?php endif; ?>

<?php if ($ranBackfill && $fatalError === null): ?>
<div class="alert <?php echo empty($failures) ? 'alert-success' : 'alert-warning'; ?>">
    <h5 class="mb-2">
        <i class="bi bi-<?php echo empty($failures) ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
        Backfill Complete
    </h5>
    <ul class="mb-0">
        <li><strong><?php echo $successCount; ?></strong> purchase(s) backfilled successfully</li>
        <?php if ($skippedCount > 0): ?>
        <li><strong><?php echo $skippedCount; ?></strong> skipped (already had transactions)</li>
        <?php endif; ?>
        <?php if (!empty($failures)): ?>
        <li class="text-danger"><strong><?php echo count($failures); ?></strong> failure(s) — see below</li>
        <?php endif; ?>
    </ul>
</div>

<?php if (!empty($failures)): ?>
<div class="card mb-3">
    <div class="card-header bg-danger text-white">
        <i class="bi bi-x-octagon"></i> Failures (<?php echo count($failures); ?>)
    </div>
    <div class="card-body">
        <p class="text-muted small">
            These purchases could not be backfilled. The most common cause is a supplier
            whose <code>ledger_account_id</code> is NULL — open the supplier in the Suppliers master
            and re-save it to create the missing ledger, then re-run this tool.
        </p>
        <table class="table table-sm">
            <thead>
                <tr>
                    <th style="width: 120px;">Purchase ID</th>
                    <th>Error</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($failures as $f): ?>
                <tr>
                    <td><strong><?php echo (int)$f['purchase_id']; ?></strong></td>
                    <td><code><?php echo htmlspecialchars($f['error']); ?></code></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-body">
        <h5 class="card-title">Current Status</h5>
        <div class="row text-center">
            <div class="col-md-4">
                <div class="border rounded p-3">
                    <div class="text-muted small">Total purchases</div>
                    <div class="h3 mb-0"><?php echo number_format($status['total']); ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="border rounded p-3">
                    <div class="text-muted small">Already backfilled</div>
                    <div class="h3 mb-0 text-success"><?php echo number_format($status['done']); ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="border rounded p-3">
                    <div class="text-muted small">Pending</div>
                    <div class="h3 mb-0 <?php echo $status['pending'] > 0 ? 'text-warning' : 'text-muted'; ?>">
                        <?php echo number_format($status['pending']); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($status['pending'] > 0): ?>
<form method="POST" action="" id="backfillForm">
    <!-- Hidden input is more reliable than relying on the submit button's name/value -->
    <input type="hidden" name="run_backfill" value="1">
    <button type="submit" id="backfillBtn" class="btn btn-primary btn-lg">
        <i class="bi bi-play-circle"></i> Run Backfill (<?php echo number_format($status['pending']); ?> pending)
    </button>
    <a href="<?php echo BASE_URL; ?>" class="btn btn-secondary btn-lg ms-2">
        <i class="bi bi-house"></i> Back to Dashboard
    </a>
</form>

<script>
(function () {
    var form = document.getElementById('backfillForm');
    var btn  = document.getElementById('backfillBtn');
    if (!form || !btn) return;

    form.addEventListener('submit', function (e) {
        var msg = 'Backfill <?php echo $status['pending']; ?> purchase(s)?\n\n'
                + 'This will write transaction + transaction_details rows for them. '
                + 'Safe to retry (idempotent).';
        if (!window.confirm(msg)) {
            e.preventDefault();
            return false;
        }
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Running...';
    });
})();
</script>
<?php else: ?>
<div class="alert alert-success">
    <i class="bi bi-check-circle"></i> All purchases have been backfilled. Nothing to do.
</div>
<a href="<?php echo BASE_URL; ?>" class="btn btn-secondary">
    <i class="bi bi-house"></i> Back to Dashboard
</a>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
