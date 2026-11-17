<?php
/**
 * One-time data fix: decode HTML entities stored in product text fields.
 *
 * Older saves ran product name/brand/description through clean(), which
 * HTML-encoded them before writing to the DB (e.g. 9'' -> 9&#039;&#039;).
 * Re-saving compounded it into &amp;#039;. This tool decodes those values
 * back to real characters. Output everywhere is escaped at render time, so
 * storing the raw text is correct.
 *
 * Idempotent — rows that are already clean are skipped, so it's safe to
 * re-run. Decoding is repeated until stable to unwind double/triple encoding.
 * Admin only.
 *
 * Usage:
 *   1. Log in as Admin
 *   2. Open: <BASE_URL>tools/fix_product_html_entities.php
 *   3. Review the preview of changes, then click "Apply Fix"
 *
 * Add ?debug=1 to see raw diagnostics.
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

// Text fields to clean up.
$FIELDS = ['name', 'brand', 'description', 'unit', 'barcode'];

/**
 * Decode HTML entities repeatedly until the string stops changing, so that
 * double/triple-encoded values (&amp;#039;) fully unwind to real characters.
 */
function decodeEntitiesFully($value) {
    $cur  = (string)$value;
    $prev = null;
    for ($i = 0; $i < 6 && $cur !== $prev; $i++) {
        $prev = $cur;
        $cur  = html_entity_decode($cur, ENT_QUOTES, 'UTF-8');
    }
    return $cur;
}

/**
 * Return product rows that still contain encoded entities in any text field,
 * along with the per-field before/after values that would change.
 */
function findRowsNeedingFix($conn, array $fields) {
    $cols = implode(', ', array_map(function ($f) { return "`$f`"; }, $fields));
    $rows = $conn->query("SELECT id, $cols FROM products ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

    $changes = [];
    foreach ($rows as $row) {
        $rowChanges = [];
        foreach ($fields as $f) {
            $before = (string)($row[$f] ?? '');
            $after  = decodeEntitiesFully($before);
            if ($after !== $before) {
                $rowChanges[$f] = ['before' => $before, 'after' => $after];
            }
        }
        if ($rowChanges) {
            $changes[] = ['id' => (int)$row['id'], 'fields' => $rowChanges];
        }
    }
    return $changes;
}

$pending = findRowsNeedingFix($conn, $FIELDS);

// --------------------------------------------------------
// Action: apply the fix if requested
// --------------------------------------------------------
$ranFix       = false;
$updatedRows  = 0;
$updatedCells = 0;
$fatalError   = null;
$debug        = isset($_GET['debug']);

$postWasReceived = ($_SERVER['REQUEST_METHOD'] === 'POST');
$runFixFlag      = isset($_POST['run_fix']) ? $_POST['run_fix'] : null;

if ($postWasReceived && $runFixFlag) {
    $ranFix = true;
    try {
        $conn->beginTransaction();
        foreach ($pending as $change) {
            $sets = [];
            $vals = [];
            foreach ($change['fields'] as $field => $ba) {
                $sets[] = "`$field` = ?";
                $vals[] = $ba['after'];
                $updatedCells++;
            }
            $vals[] = $change['id'];
            $stmt = $conn->prepare("UPDATE products SET " . implode(', ', $sets) . " WHERE id = ?");
            $stmt->execute($vals);
            $updatedRows++;
        }
        $conn->commit();
        if (function_exists('logAudit')) {
            logAudit('products', 'fix_html_entities', 0, null, [
                'rows' => $updatedRows,
                'cells' => $updatedCells,
            ]);
        }
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $updatedRows  = 0;
        $updatedCells = 0;
        $fatalError = $e->getMessage()
            . "\n\n" . $e->getFile() . ':' . $e->getLine()
            . "\n\n" . $e->getTraceAsString();
    }

    // Refresh after run
    $pending = findRowsNeedingFix($conn, $FIELDS);
}

$pageTitle = 'Fix Product HTML Entities';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-3">
    <div class="col-md-12">
        <h2><i class="bi bi-eraser"></i> Fix Product HTML Entities</h2>
        <p class="text-muted mb-0">
            One-time tool: decodes HTML entities (e.g. <code>9&amp;#039;&amp;#039;</code> &rarr; <code>9''</code>)
            stored in product <code>name</code>, <code>brand</code>, <code>description</code>, <code>unit</code>
            and <code>barcode</code>. Safe to re-run &mdash; already-clean rows are skipped.
        </p>
    </div>
</div>

<?php if ($debug): ?>
<div class="alert alert-info">
    <strong>Debug</strong>
    <pre class="mb-0 small">REQUEST_METHOD: <?php echo htmlspecialchars($_SERVER['REQUEST_METHOD'] ?? ''); ?>
postWasReceived: <?php echo $postWasReceived ? 'true' : 'false'; ?>
runFixFlag: <?php var_export($runFixFlag); ?>
ranFix: <?php echo $ranFix ? 'true' : 'false'; ?>
pending rows: <?php echo count($pending); ?>
</pre>
</div>
<?php endif; ?>

<?php if ($fatalError !== null): ?>
<div class="alert alert-danger">
    <h5 class="mb-2"><i class="bi bi-x-octagon"></i> Fatal Error</h5>
    <p class="mb-2 small">The fix aborted and was rolled back. No rows were changed.</p>
    <pre class="mb-0 small" style="max-height: 240px; overflow:auto;"><?php echo htmlspecialchars($fatalError); ?></pre>
</div>
<?php endif; ?>

<?php if ($ranFix && $fatalError === null): ?>
<div class="alert alert-success">
    <h5 class="mb-2"><i class="bi bi-check-circle"></i> Fix Applied</h5>
    <ul class="mb-0">
        <li><strong><?php echo $updatedRows; ?></strong> product row(s) updated</li>
        <li><strong><?php echo $updatedCells; ?></strong> field(s) decoded</li>
    </ul>
</div>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-body">
        <h5 class="card-title">Current Status</h5>
        <div class="row text-center">
            <div class="col-md-6">
                <div class="border rounded p-3">
                    <div class="text-muted small">Products needing fix</div>
                    <div class="h3 mb-0 <?php echo count($pending) > 0 ? 'text-warning' : 'text-muted'; ?>">
                        <?php echo number_format(count($pending)); ?>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="border rounded p-3">
                    <div class="text-muted small">Fields to decode</div>
                    <div class="h3 mb-0">
                        <?php echo number_format(array_sum(array_map(function ($c) { return count($c['fields']); }, $pending))); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (count($pending) > 0): ?>
<div class="card mb-3">
    <div class="card-header">
        <i class="bi bi-eye"></i> Preview of Changes (<?php echo count($pending); ?> row(s))
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle">
                <thead>
                    <tr>
                        <th style="width: 70px;">ID</th>
                        <th style="width: 110px;">Field</th>
                        <th>Before</th>
                        <th>After</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pending as $change): ?>
                        <?php foreach ($change['fields'] as $field => $ba): ?>
                        <tr>
                            <td><strong><?php echo (int)$change['id']; ?></strong></td>
                            <td><code><?php echo htmlspecialchars($field); ?></code></td>
                            <td class="text-danger small"><?php echo htmlspecialchars($ba['before']); ?></td>
                            <td class="text-success small"><?php echo htmlspecialchars($ba['after']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<form method="POST" action="" id="fixForm">
    <input type="hidden" name="run_fix" value="1">
    <button type="submit" id="fixBtn" class="btn btn-primary btn-lg">
        <i class="bi bi-play-circle"></i> Apply Fix (<?php echo count($pending); ?> row(s))
    </button>
    <a href="<?php echo BASE_URL; ?>" class="btn btn-secondary btn-lg ms-2">
        <i class="bi bi-house"></i> Back to Dashboard
    </a>
</form>

<script>
(function () {
    var form = document.getElementById('fixForm');
    var btn  = document.getElementById('fixBtn');
    if (!form || !btn) return;
    form.addEventListener('submit', function (e) {
        if (!window.confirm('Decode HTML entities in <?php echo count($pending); ?> product row(s)? Review the preview above first.')) {
            e.preventDefault();
            return false;
        }
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Applying...';
    });
})();
</script>
<?php else: ?>
<div class="alert alert-success">
    <i class="bi bi-check-circle"></i> No products contain HTML entities. Nothing to do.
</div>
<a href="<?php echo BASE_URL; ?>" class="btn btn-secondary">
    <i class="bi bi-house"></i> Back to Dashboard
</a>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
