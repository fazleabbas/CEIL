<?php
require_once '../../config/database.php';
require_once '../../includes/functions.php';

$pageTitle = __('total_stock_value');

if (!canViewReport('stock_value')) {
    setAlert(__('no_permission'), 'danger');
    redirect('');
}

require_once '../../includes/header.php';

// =============================================================================
// Optional location_type filter (?location_type=site|warehouse|others|untyped).
// When set, the page is scoped to locations of that type: the category card is
// hidden and the per-location table + totals cover only the matching locations.
// Any other/empty value = no filter (the full, unfiltered view).
// =============================================================================
$validTypes = ['site', 'warehouse', 'others', 'untyped'];
$typeFilter = isset($_GET['location_type']) ? strtolower(trim($_GET['location_type'])) : '';
if (!in_array($typeFilter, $validTypes, true)) {
    $typeFilter = '';
}
$typeLabels = [
    'site'      => __('location_type_site'),
    'warehouse' => __('location_type_warehouse'),
    'others'    => __('location_type_others'),
    'untyped'   => __('location_type_untyped'),
];
$activeTypeLabel = $typeFilter !== '' ? $typeLabels[$typeFilter] : '';

// =============================================================================
// PER-CATEGORY breakdown
// A product's value is the SUM OF ITS PER-LOCATION VALUES (qty at a location x
// that location's own rate), rolled up to the category. It must be computed at
// the (product, location) grain -- exactly like the per-location card below --
// or the two cards on this page stop adding up to the same grand total. That
// is why the movement UNION appears twice: both cards value the same thing the
// same way. All active products are kept via LEFT JOIN so a category whose
// stock is nil still lists at zero rather than vanishing.
// =============================================================================
$uncategorizedLabel = __('uncategorized') ?: 'Uncategorized';

$categorySql = "
    SELECT
        p.category_id AS category_id,
        COALESCE(pc.name, " . $conn->quote($uncategorizedLabel) . ") AS category_name,
        COALESCE(SUM(v.product_value), 0) AS category_value
    FROM products p
    LEFT JOIN (
        SELECT net.product_id,
               SUM(net.net_qty * COALESCE(NULLIF(sbl.avg_rate, 0), sbp.avg_rate, 0)) AS product_value
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
        GROUP BY net.product_id
    ) v ON v.product_id = p.id
    LEFT JOIN product_categories pc ON pc.id = p.category_id
    WHERE p.is_active = 1
    GROUP BY p.category_id, pc.name
    ORDER BY category_name ASC
";

// =============================================================================
// PER-LOCATION breakdown
// Per-(product, location) net qty via UNION ALL of all 8 movement types,
// then multiplied by that LOCATION'S OWN avg_rate from stock_batches, the
// same formula the dashboard tile uses. Same active-product filter as the
// dashboard so the per-location sum reconciles to the tile total.
// =============================================================================
$locationValueSql = "
    SELECT
        net.location_id,
        COALESCE(SUM(net.net_qty * COALESCE(NULLIF(sbl.avg_rate, 0), sbp.avg_rate, 0)), 0) AS location_value
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
    JOIN products p ON p.id = net.product_id AND p.is_active = 1
    -- Each location valued at its own batches. sbp is the product-wide
    -- fallback, reached when a location has no batch rows OR its batches
    -- carry rate 0 -- 0 means 'no cost recorded', never 'free'.
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
    GROUP BY net.location_id
";

$categoryRows = [];
$locationValueMap = []; // location_id => value
$allLocations = [];
$grandTotal = 0.0;
$loadError = null;

try {
    // Category breakdown is only meaningful for the full (unfiltered) view; when a
    // location_type filter is active the category card is hidden, so skip the query.
    if ($typeFilter === '') {
        $stmt = $conn->prepare($categorySql);
        $stmt->execute();
        $leafCategoryRows = $stmt->fetchAll();

        // Roll each product-assigned (leaf) category up to its top-level ancestor so
        // the card lists ONLY root categories, with sub / sub-sub category values
        // aggregated into their root. The grand total is unchanged — values are only
        // regrouped. All categories are loaded (no is_active filter) so the parent
        // chain is never broken by an inactive intermediate category.
        $catParentMap = [];
        $catNameMap = [];
        $catStmt = $conn->query("SELECT id, parent_id, name FROM product_categories");
        foreach ($catStmt->fetchAll() as $c) {
            $cid = (int)$c['id'];
            $catParentMap[$cid] = $c['parent_id'] !== null ? (int)$c['parent_id'] : null;
            $catNameMap[$cid] = $c['name'];
        }
        $rootOf = function ($catId) use ($catParentMap) {
            $catId = (int)$catId;
            $guard = 0;
            while (isset($catParentMap[$catId]) && $catParentMap[$catId] !== null) {
                $catId = $catParentMap[$catId];
                if (++$guard > 1000) break; // safety against accidental cycle
            }
            return $catId;
        };

        $rolled = []; // root key (int id | 'uncat') => rolled-up row
        foreach ($leafCategoryRows as $r) {
            $value = (float)$r['category_value'];
            $grandTotal += $value;
            if ($r['category_id'] === null) {
                $key = 'uncat';
                $rootId = null;
                $rootName = $uncategorizedLabel;
            } else {
                $rootId = $rootOf($r['category_id']);
                $key = $rootId;
                $rootName = isset($catNameMap[$rootId]) ? $catNameMap[$rootId] : $r['category_name'];
            }
            if (!isset($rolled[$key])) {
                $rolled[$key] = [
                    'category_id'    => $rootId,
                    'category_name'  => $rootName,
                    'category_value' => 0.0,
                ];
            }
            $rolled[$key]['category_value'] += $value;
        }

        // Alphabetical, matching the previous ORDER BY category_name.
        usort($rolled, function ($a, $b) {
            return strcasecmp($a['category_name'], $b['category_name']);
        });
        $categoryRows = $rolled;
    }

    $stmt = $conn->prepare($locationValueSql);
    $stmt->execute();
    foreach ($stmt->fetchAll() as $r) {
        $locationValueMap[(int)$r['location_id']] = (float)$r['location_value'];
    }

    // Fetch locations for the per-location table. When a type filter is active,
    // restrict to matching locations ('untyped' = location_type IS NULL).
    if ($typeFilter === 'untyped') {
        $locStmt = $conn->query("SELECT id, parent_id, name FROM locations WHERE location_type IS NULL ORDER BY name ASC");
        $allLocations = $locStmt->fetchAll();
    } elseif ($typeFilter !== '') {
        $locStmt = $conn->prepare("SELECT id, parent_id, name FROM locations WHERE location_type = ? ORDER BY name ASC");
        $locStmt->execute([$typeFilter]);
        $allLocations = $locStmt->fetchAll();
    } else {
        // Full view: fetch every location (active or not) so the hierarchy is
        // complete and any location holding stock still appears.
        $locStmt = $conn->query("SELECT id, parent_id, name FROM locations ORDER BY name ASC");
        $allLocations = $locStmt->fetchAll();
    }
} catch (PDOException $e) {
    $loadError = $e->getMessage();
}

// In the full view, show the location hierarchy (DFS ordering with depth). Under a
// type filter the matching locations may span different branches, so render them as
// a flat alphabetical list (depth 0) to avoid orphaned/dropped rows.
if ($typeFilter === '') {
    $locationTree = buildTree($allLocations);
    $orderedLocations = flattenTree($locationTree);
} else {
    $orderedLocations = array_map(function ($loc) {
        $loc['depth'] = 0;
        return $loc;
    }, $allLocations);
}

// Sum of the per-location values actually displayed.
$locationGrandTotal = 0.0;
foreach ($orderedLocations as $loc) {
    $locationGrandTotal += $locationValueMap[(int)$loc['id']] ?? 0.0;
}

// Under a type filter the headline total is the filtered location total.
if ($typeFilter !== '') {
    $grandTotal = $locationGrandTotal;
}
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="mb-0">
        <i class="bi bi-boxes text-success me-2"></i>
        <?php _e('total_stock_value'); ?>
        <?php if ($activeTypeLabel !== ''): ?>
            <span class="badge bg-success-subtle text-success border border-success ms-1 align-middle">
                <i class="bi bi-funnel"></i> <?php echo htmlspecialchars($activeTypeLabel); ?>
            </span>
        <?php endif; ?>
    </h4>
    <div class="text-muted small">
        <?php echo date('d M Y, H:i'); ?>
    </div>
</div>

<?php if ($loadError): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($loadError); ?></div>
<?php else: ?>
    <!-- Headline grand total -->
    <div class="card border-success mb-4">
        <div class="card-body text-center py-3">
            <div class="text-muted small mb-1"><?php _e('total_stock_value'); ?></div>
            <h3 class="mb-0 text-success"><?php echo formatCurrency($grandTotal); ?></h3>
        </div>
    </div>

    <div class="row g-3">
        <?php if ($typeFilter === ''): ?>
        <!-- By Category (only in the full, unfiltered view) -->
        <div class="col-12 col-lg-6">
            <div class="card h-100">
                <div class="card-header bg-light">
                    <strong><?php _e('category'); ?></strong>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th><?php _e('category'); ?></th>
                                    <th class="text-end" style="width: 35%;"><?php _e('total_stock_value'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($categoryRows)): ?>
                                    <tr>
                                        <td colspan="2" class="text-center text-muted py-4">&mdash;</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($categoryRows as $r):
                                        // 0 → uncategorized (NULL category_id) on the drill-down page
                                        $catParam = $r['category_id'] === null ? 0 : (int)$r['category_id'];
                                        $catUrl = BASE_URL . 'modules/reports/stock_value_by_category_products.php?category_id=' . $catParam;
                                    ?>
                                        <tr style="cursor: pointer;"
                                            onclick="window.open('<?php echo htmlspecialchars($catUrl, ENT_QUOTES); ?>', '_blank', 'noopener')"
                                            title="<?php _e('product'); ?>">
                                            <td><?php echo htmlspecialchars($r['category_name']); ?></td>
                                            <td class="text-end"><?php echo formatCurrency((float)$r['category_value']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <th class="text-end"><?php _e('total'); ?></th>
                                    <th class="text-end text-success"><?php echo formatCurrency($grandTotal); ?></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- By Location (hierarchical: parent → child → grandchild) -->
        <div class="<?php echo $typeFilter === '' ? 'col-12 col-lg-6' : 'col-12'; ?>">
            <div class="card h-100">
                <div class="card-header bg-light">
                    <strong><?php _e('location'); ?></strong>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th><?php _e('location'); ?></th>
                                    <th class="text-end" style="width: 35%;"><?php _e('total_stock_value'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($orderedLocations)): ?>
                                    <tr>
                                        <td colspan="2" class="text-center text-muted py-4">&mdash;</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($orderedLocations as $loc):
                                        $value = isset($locationValueMap[(int)$loc['id']]) ? $locationValueMap[(int)$loc['id']] : 0.0;
                                        $depth = (int)($loc['depth'] ?? 0);
                                        $indent = str_repeat('— ', $depth);
                                        $locUrl = BASE_URL . 'modules/reports/stock_value_by_location_products.php?location_id=' . (int)$loc['id'];
                                    ?>
                                        <tr style="cursor: pointer;"
                                            onclick="window.open('<?php echo htmlspecialchars($locUrl, ENT_QUOTES); ?>', '_blank', 'noopener')"
                                            title="<?php _e('product'); ?>">
                                            <td>
                                                <?php if ($depth > 0): ?>
                                                    <span class="text-muted"><?php echo $indent; ?></span>
                                                <?php endif; ?>
                                                <?php echo htmlspecialchars($loc['name']); ?>
                                            </td>
                                            <td class="text-end"><?php echo formatCurrency($value); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <th class="text-end"><?php _e('total'); ?></th>
                                    <th class="text-end text-success"><?php echo formatCurrency($locationGrandTotal); ?></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>
