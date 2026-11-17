<?php
require_once '../../config/database.php';
require_once '../../includes/functions.php';

if (!canViewReport('stock_value')) {
    setAlert(__('no_permission'), 'danger');
    redirect('');
}

// Accept category_id; 0 = uncategorized (NULL category_id)
$rawCatId = $_GET['category_id'] ?? null;
if ($rawCatId === null || $rawCatId === '') {
    setAlert(__('no_data'), 'danger');
    redirect('modules/reports/stock_value_breakdown.php');
}
$categoryId = (int)$rawCatId;
$isUncategorized = ($categoryId === 0);

if ($isUncategorized) {
    $categoryName = __('uncategorized') ?: 'Uncategorized';
} else {
    $stmt = $conn->prepare("SELECT name FROM product_categories WHERE id = ?");
    $stmt->execute([$categoryId]);
    $row = $stmt->fetch();
    if (!$row) {
        setAlert(__('no_data'), 'danger');
        redirect('modules/reports/stock_value_breakdown.php');
    }
    $categoryName = $row['name'];
}

$pageTitle = __('total_stock_value') . ' — ' . $categoryName;

require_once '../../includes/header.php';

// Same per-product stock formula as the dashboard tile / breakdown page,
// filtered to one category. A product's value is the sum of its PER-LOCATION
// values (qty at a location x that location's own rate), so this reconciles to
// the category row on the breakdown page, which is built the same way. The
// Rate column is therefore a DERIVED weighted average (value / qty) rather
// than a rate read straight from stock_batches -- that keeps qty x rate = value
// on screen for a product stocked at several locations at different costs.
// Aggregate the clicked category PLUS all of its descendant categories, so the
// product list reconciles with the rolled-up root row on the breakdown page.
// All categories are loaded (no is_active filter) so the chain matches the
// breakdown rollup exactly. IDs are intval'd into the IN clause (safe).
if ($isUncategorized) {
    $catClause = 'p.category_id IS NULL';
} else {
    $allCats = $conn->query("SELECT id, parent_id FROM product_categories")->fetchAll(PDO::FETCH_ASSOC);
    $catIds = getDescendantIds($allCats, $categoryId);
    $catIds[] = $categoryId;
    $catInClause = implode(',', array_map('intval', $catIds));
    $catClause = "p.category_id IN ($catInClause)";
}

$sql = "
    SELECT
        t.id,
        t.name,
        t.unit,
        t.current_stock,
        t.avg_rate,
        t.stock_value
    FROM (
        SELECT
            p.id,
            p.name,
            p.unit,
            SUM(net.net_qty) AS current_stock,
            SUM(net.net_qty * COALESCE(NULLIF(sbl.avg_rate, 0), sbp.avg_rate, 0)) AS stock_value,
            SUM(net.net_qty * COALESCE(NULLIF(sbl.avg_rate, 0), sbp.avg_rate, 0))
                / NULLIF(SUM(net.net_qty), 0) AS avg_rate
        FROM (
        SELECT location_id, product_id, SUM(signed_qty) AS net_qty
        FROM (
            SELECT location_id, product_id, quantity AS signed_qty
              FROM opening_stocks
              WHERE stock_date <= CURDATE()
            UNION ALL
            SELECT pu.location_id, pd.product_id, pd.quantity
              FROM purchase_details pd
              JOIN purchases pu ON pd.purchase_id = pu.id
              WHERE pu.purchase_date <= CURDATE()
            UNION ALL
            SELECT pi.location_id, pid.product_id, pid.quantity
              FROM purchase_import_details pid
              JOIN purchase_imports pi ON pid.import_id = pi.id
              WHERE pi.import_date <= CURDATE()
            UNION ALL
            SELECT s.location_id, sd.product_id, -sd.quantity
              FROM sale_details sd
              JOIN sales s ON sd.sale_id = s.id
              WHERE s.sale_date <= CURDATE()
            UNION ALL
            SELECT t.to_location_id AS location_id, td.product_id, td.received_quantity
              FROM stock_transfer_details td
              JOIN stock_transfers t ON td.transfer_id = t.id
              WHERE t.status = 'received' AND DATE(t.received_at) <= CURDATE()
            UNION ALL
            SELECT t.from_location_id AS location_id, td.product_id, -td.dispatched_quantity
              FROM stock_transfer_details td
              JOIN stock_transfers t ON td.transfer_id = t.id
              WHERE t.status IN ('in_transit', 'received') AND DATE(t.dispatched_at) <= CURDATE()
            UNION ALL
            SELECT sa.location_id, sad.product_id,
                   CASE WHEN sad.adjustment_type = 'increase' THEN sad.quantity ELSE -sad.quantity END
              FROM stock_adjustment_details sad
              JOIN stock_adjustments sa ON sad.adjustment_id = sa.id
              WHERE sa.adjustment_date <= CURDATE()
            UNION ALL
            SELECT cv.location_id, cvd.product_id, -cvd.quantity
              FROM consumption_voucher_details cvd
              JOIN consumption_vouchers cv ON cvd.voucher_id = cv.id
              WHERE cv.voucher_date <= CURDATE()
        ) movements
        GROUP BY location_id, product_id
        ) net
        JOIN products p ON p.id = net.product_id
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
        WHERE p.is_active = 1 AND $catClause
        GROUP BY p.id, p.name, p.unit
    ) t
    WHERE t.current_stock != 0
    ORDER BY stock_value DESC, t.name ASC
";

$rows = [];
$total = 0.0;
$loadError = null;

try {
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    foreach ($rows as $r) {
        $total += (float)$r['stock_value'];
    }
} catch (PDOException $e) {
    $loadError = $e->getMessage();
}
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="mb-1">
            <i class="bi bi-tags text-success me-2"></i>
            <?php echo htmlspecialchars($categoryName); ?>
        </h4>
        <div class="text-muted small"><?php _e('category'); ?> &mdash; <?php _e('total_stock_value'); ?></div>
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
                            <th><?php _e('product'); ?></th>
                            <th class="text-end"><?php _e('qty'); ?></th>
                            <th class="text-end"><?php _e('rate'); ?></th>
                            <th class="text-end" style="width: 22%;"><?php _e('total_stock_value'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4"><?php _e('no_data'); ?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rows as $r):
                                $prodUrl = BASE_URL . 'modules/reports/stock_value_by_product_locations.php?product_id=' . (int)$r['id'];
                            ?>
                                <tr style="cursor: pointer;"
                                    onclick="window.open('<?php echo htmlspecialchars($prodUrl, ENT_QUOTES); ?>', '_blank', 'noopener')"
                                    title="<?php _e('location'); ?>">
                                    <td><?php echo htmlspecialchars($r['name']); ?></td>
                                    <td class="text-end">
                                        <?php echo number_format((float)$r['current_stock'], 2); ?>
                                        <?php if (!empty($r['unit'])): ?>
                                            <small class="text-muted"><?php echo htmlspecialchars($r['unit']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end"><?php echo formatCurrency((float)$r['avg_rate']); ?></td>
                                    <td class="text-end"><?php echo formatCurrency((float)$r['stock_value']); ?></td>
                                </tr>
                            <?php endforeach; ?>
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
