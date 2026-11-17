<?php
require_once '../../config/database.php';
require_once '../../includes/functions.php';

if (!canViewReport('stock_value')) {
    setAlert(__('no_permission'), 'danger');
    redirect('');
}

$productId = (int)($_GET['product_id'] ?? 0);
if ($productId <= 0) {
    setAlert(__('no_data'), 'danger');
    redirect('modules/reports/stock_value_breakdown.php');
}

$stmt = $conn->prepare("SELECT id, name, unit FROM products WHERE id = ? AND is_active = 1");
$stmt->execute([$productId]);
$product = $stmt->fetch();
if (!$product) {
    setAlert(__('no_data'), 'danger');
    redirect('modules/reports/stock_value_breakdown.php');
}

$pageTitle = __('total_stock_value') . ' — ' . $product['name'];

require_once '../../includes/header.php';

// Per-(product, location) net qty filtered to ONE product, then × EACH
// LOCATION'S OWN avg_rate from its own batches. Reconciles to the product
// row's value on the category drill-down, which sums the same per-location
// values.
$sql = "
    SELECT
        net.location_id,
        net.net_qty AS current_stock,
        COALESCE(NULLIF(sbl.avg_rate, 0), sbp.avg_rate, 0) AS avg_rate,
        (net.net_qty * COALESCE(NULLIF(sbl.avg_rate, 0), sbp.avg_rate, 0)) AS location_value
    FROM (
        SELECT location_id, product_id, SUM(signed_qty) AS net_qty
        FROM (
            SELECT location_id, product_id, quantity AS signed_qty
              FROM opening_stocks
              WHERE stock_date <= CURDATE() AND product_id = ?
            UNION ALL
            SELECT pu.location_id, pd.product_id, pd.quantity
              FROM purchase_details pd
              JOIN purchases pu ON pd.purchase_id = pu.id
              WHERE pu.purchase_date <= CURDATE() AND pd.product_id = ?
            UNION ALL
            SELECT pi.location_id, pid.product_id, pid.quantity
              FROM purchase_import_details pid
              JOIN purchase_imports pi ON pid.import_id = pi.id
              WHERE pi.import_date <= CURDATE() AND pid.product_id = ?
            UNION ALL
            SELECT s.location_id, sd.product_id, -sd.quantity
              FROM sale_details sd
              JOIN sales s ON sd.sale_id = s.id
              WHERE s.sale_date <= CURDATE() AND sd.product_id = ?
            UNION ALL
            SELECT t.to_location_id AS location_id, td.product_id, td.received_quantity
              FROM stock_transfer_details td
              JOIN stock_transfers t ON td.transfer_id = t.id
              WHERE t.status = 'received' AND DATE(t.received_at) <= CURDATE() AND td.product_id = ?
            UNION ALL
            SELECT t.from_location_id AS location_id, td.product_id, -td.dispatched_quantity
              FROM stock_transfer_details td
              JOIN stock_transfers t ON td.transfer_id = t.id
              WHERE t.status IN ('in_transit', 'received') AND DATE(t.dispatched_at) <= CURDATE() AND td.product_id = ?
            UNION ALL
            SELECT sa.location_id, sad.product_id,
                   CASE WHEN sad.adjustment_type = 'increase' THEN sad.quantity ELSE -sad.quantity END
              FROM stock_adjustment_details sad
              JOIN stock_adjustments sa ON sad.adjustment_id = sa.id
              WHERE sa.adjustment_date <= CURDATE() AND sad.product_id = ?
            UNION ALL
            SELECT cv.location_id, cvd.product_id, -cvd.quantity
              FROM consumption_voucher_details cvd
              JOIN consumption_vouchers cv ON cvd.voucher_id = cv.id
              WHERE cv.voucher_date <= CURDATE() AND cvd.product_id = ?
        ) movements
        GROUP BY location_id, product_id
    ) net
    -- Each location valued at its own cost. sbp is the product-wide fallback,
    -- reached when a location has no batch rows OR its batches carry rate 0
    -- (real in this data) -- 0 means 'no cost recorded', never 'free'.
    LEFT JOIN (
        SELECT product_id, location_id,
               SUM(quantity_in * rate_per_unit) / NULLIF(SUM(quantity_in), 0) AS avg_rate
        FROM stock_batches
        WHERE batch_date <= CURDATE()
        GROUP BY product_id, location_id
    ) sbl ON sbl.product_id = net.product_id AND sbl.location_id = net.location_id
    LEFT JOIN (
        SELECT product_id,
               SUM(quantity_in * rate_per_unit) / NULLIF(SUM(quantity_in), 0) AS avg_rate
        FROM stock_batches
        WHERE batch_date <= CURDATE()
        GROUP BY product_id
    ) sbp ON sbp.product_id = net.product_id
    WHERE net.net_qty != 0
";

$locationValueMap = []; // location_id => ['qty' => ..., 'rate' => ..., 'value' => ...]
$total = 0.0;
$loadError = null;

try {
    $stmt = $conn->prepare($sql);
    $stmt->execute(array_fill(0, 8, $productId));
    foreach ($stmt->fetchAll() as $r) {
        $locationValueMap[(int)$r['location_id']] = [
            'qty'   => (float)$r['current_stock'],
            'rate'  => (float)$r['avg_rate'],
            'value' => (float)$r['location_value'],
        ];
        $total += (float)$r['location_value'];
    }

    $locStmt = $conn->query("SELECT id, parent_id, name FROM locations ORDER BY name ASC");
    $allLocations = $locStmt->fetchAll();
} catch (PDOException $e) {
    $loadError = $e->getMessage();
    $allLocations = [];
}

$locationTree = buildTree($allLocations);
$orderedLocations = flattenTree($locationTree);
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="mb-1">
            <i class="bi bi-box-seam text-success me-2"></i>
            <?php echo htmlspecialchars($product['name']); ?>
            <?php if (!empty($product['unit'])): ?>
                <small class="text-muted">(<?php echo htmlspecialchars($product['unit']); ?>)</small>
            <?php endif; ?>
        </h4>
        <div class="text-muted small"><?php _e('product'); ?> &mdash; <?php _e('location'); ?></div>
    </div>
    <div class="text-end">
        <div class="text-muted small"><?php echo date('d M Y, H:i'); ?></div>
        <h5 class="mb-0 text-success"><?php echo formatCurrency($total); ?></h5>
    </div>
</div>

<?php if ($loadError): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($loadError); ?></div>
<?php else: ?>
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th><?php _e('location'); ?></th>
                            <th class="text-end"><?php _e('qty'); ?></th>
                            <th class="text-end"><?php _e('rate'); ?></th>
                            <th class="text-end" style="width: 22%;"><?php _e('total_stock_value'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $rendered = 0;
                        foreach ($orderedLocations as $loc):
                            $locId = (int)$loc['id'];
                            if (!isset($locationValueMap[$locId])) {
                                continue; // skip locations with zero net qty for this product
                            }
                            $rendered++;
                            $row = $locationValueMap[$locId];
                            $depth = (int)($loc['depth'] ?? 0);
                            $indent = str_repeat('— ', $depth);
                        ?>
                            <tr>
                                <td>
                                    <?php if ($depth > 0): ?>
                                        <span class="text-muted"><?php echo $indent; ?></span>
                                    <?php endif; ?>
                                    <?php echo htmlspecialchars($loc['name']); ?>
                                </td>
                                <td class="text-end">
                                    <?php echo number_format($row['qty'], 2); ?>
                                    <?php if (!empty($product['unit'])): ?>
                                        <small class="text-muted"><?php echo htmlspecialchars($product['unit']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end"><?php echo formatCurrency($row['rate']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['value']); ?></td>
                            </tr>
                        <?php endforeach; ?>

                        <?php
                        // Defensive: if a transaction references a location that has been
                        // deleted from the locations table, render it as a fallback row so
                        // the per-row sum still equals the total. (Should not normally happen.)
                        $renderedIds = [];
                        foreach ($orderedLocations as $loc) {
                            if (isset($locationValueMap[(int)$loc['id']])) {
                                $renderedIds[(int)$loc['id']] = true;
                            }
                        }
                        foreach ($locationValueMap as $locId => $row):
                            if (isset($renderedIds[$locId])) continue;
                            $rendered++;
                        ?>
                            <tr>
                                <td><em class="text-muted">#<?php echo (int)$locId; ?></em></td>
                                <td class="text-end">
                                    <?php echo number_format($row['qty'], 2); ?>
                                    <?php if (!empty($product['unit'])): ?>
                                        <small class="text-muted"><?php echo htmlspecialchars($product['unit']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end"><?php echo formatCurrency($row['rate']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['value']); ?></td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if ($rendered === 0): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4"><?php _e('no_data'); ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <th colspan="3" class="text-end"><?php _e('total'); ?></th>
                            <th class="text-end text-success"><?php echo formatCurrency($total); ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>
