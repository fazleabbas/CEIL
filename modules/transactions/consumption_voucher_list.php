<?php
require_once '../../config/database.php';
require_once '../../includes/functions.php';
$pageTitle = __('consumption_vouchers');
if (!hasPermission('consumption_vouchers', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('');
}

require_once '../../includes/header.php';

// Filters
$locationFilter = $_GET['location'] ?? '';
$costCentreFilter = $_GET['cost_centre'] ?? '';
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

// Product filter: zero or more product ids, matched against the voucher's LINES.
// intval + array_filter drops anything non-numeric, so these can be embedded or
// bound without further escaping.
$selectedProductIds = [];
if (!empty($_GET['products']) && is_array($_GET['products'])) {
    $selectedProductIds = array_values(array_unique(array_filter(array_map('intval', $_GET['products']))));
}

// Get user's accessible locations and cost centres
$locations = getUserLocations();
$costCentres = getUserCostCentres();

// Build query
$whereClause = "WHERE cv.voucher_date BETWEEN ? AND ?";
$params = [$dateFrom, $dateTo];

if ($locationFilter) {
    $whereClause .= " AND cv.location_id = ?";
    $params[] = $locationFilter;
} else {
    $locationIds = array_column($locations, 'id');
    if (!empty($locationIds)) {
        $placeholders = implode(',', array_fill(0, count($locationIds), '?'));
        $whereClause .= " AND cv.location_id IN ($placeholders)";
        $params = array_merge($params, $locationIds);
    }
}

if ($costCentreFilter) {
    $whereClause .= " AND cv.cost_centre_id = ?";
    $params[] = $costCentreFilter;
}

// Keep only vouchers containing at least one of the chosen products.
//
// EXISTS, not a JOIN: joining consumption_voucher_details would return one row
// per matching line, so a voucher containing two of the selected products (or
// the same product on two lines) would be listed twice. idx_cvd_product makes
// this cheap.
if (!empty($selectedProductIds)) {
    list($prodPlaceholders, $prodParams) = buildInClause($selectedProductIds);
    $whereClause .= " AND EXISTS (
        SELECT 1 FROM consumption_voucher_details cvd
        WHERE cvd.voucher_id = cv.id
          AND cvd.product_id IN ($prodPlaceholders)
    )";
    $params = array_merge($params, $prodParams);
}

// Names for the products already chosen, so Select2 shows the chips straight
// away on reload instead of flashing raw ids (or losing them entirely).
$preselectedProductOptions = [];
if (!empty($selectedProductIds)) {
    list($hydPlaceholders, $hydParams) = buildInClause($selectedProductIds);
    $hydStmt = $conn->prepare("SELECT id, name FROM products WHERE id IN ($hydPlaceholders) ORDER BY name");
    $hydStmt->execute($hydParams);
    foreach ($hydStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $preselectedProductOptions[(int)$row['id']] = $row['name'];
    }
}

// Base currency, for flagging + converting the foreign-currency rows below.
$cvBaseCurrency   = getDefaultCurrency();
$cvBaseCurrencyId = $cvBaseCurrency ? (int)$cvBaseCurrency['id'] : null;

$stmt = $conn->prepare("
    SELECT
        cv.*,
        l.name as location_name,
        cc.name as cost_centre_name,
        cc.code as cost_centre_code,
        u.name as created_by_name,
        (SELECT COUNT(*) FROM consumption_voucher_details WHERE voucher_id = cv.id) as item_count
    FROM consumption_vouchers cv
    LEFT JOIN locations l ON cv.location_id = l.id
    LEFT JOIN cost_centres cc ON cv.cost_centre_id = cc.id
    LEFT JOIN users u ON cv.created_by = u.id
    $whereClause
    ORDER BY cv.voucher_date DESC, cv.id DESC
");
$stmt->execute($params);
$vouchers = $stmt->fetchAll();

// Which of these vouchers have been returned against (migration 0047). Batched
// into ONE query for the whole page rather than one per row, and fed to the
// SAME cvCanEdit() / cvCanDelete() helpers the form and the view use - so the
// list can never show a pencil the form then refuses.
$cvIds = [];
foreach ($vouchers as $v) {
    $cvIds[] = (int)$v['id'];
}
$cvReturnCounts = cvReturnCounts($conn, $cvIds);
?>

<div class="row justify-content-center">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="bi bi-clipboard-minus"></i> <?php _e('consumption_vouchers'); ?>
                </h5>
                <div>
                    <?php if (hasPermission('consumption_vouchers', 'create')): ?>
                    <a href="consumption_voucher.php" class="btn btn-sm btn-primary">
                        <i class="bi bi-plus"></i> <?php _e('new_consumption_voucher'); ?>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <!-- Filters -->
                <form method="GET" class="row g-3 mb-4" id="cvFilterForm">
                    <div class="col-md-3">
                        <label class="form-label"><?php _e('location'); ?></label>
                        <select name="location" class="form-select form-select-sm">
                            <option value=""><?php _e('all_locations'); ?></option>
                            <?= renderTreeOptions($locations, $locationFilter) ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?php _e('cost_centre'); ?></label>
                        <select name="cost_centre" class="form-select form-select-sm">
                            <option value=""><?php _e('all_cost_centres'); ?></option>
                            <?= renderTreeOptions($costCentres, $costCentreFilter, null, 'name', 'code') ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><?php _e('from_date'); ?></label>
                        <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo $dateFrom; ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><?php _e('to_date'); ?></label>
                        <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo $dateTo; ?>">
                    </div>

                    <?php /* Second row: location + cost centre + the two dates already
                             take 10 of the row's 12 columns, so products and the buttons
                             flow onto their own line rather than being squeezed.
                             Preselected products are rendered server-side as selected
                             <option>s so Select2 shows the chips immediately on reload
                             — no hydration round trip. */ ?>
                    <div class="col-md-8">
                        <label class="form-label" for="cvFilterProducts"><?php _e('products'); ?></label>
                        <select name="products[]" id="cvFilterProducts" class="form-select form-select-sm" multiple>
                            <?php foreach ($preselectedProductOptions as $pid => $pname): ?>
                            <option value="<?php echo (int)$pid; ?>" selected><?php echo htmlspecialchars($pname); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted"><?php _e('product_filter_hint'); ?></small>
                    </div>

                    <div class="col-md-4 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-sm btn-primary"><?php _e('filter'); ?></button>
                        <a href="consumption_voucher_list.php" class="btn btn-sm btn-secondary"><?php _e('reset'); ?></a>
                    </div>
                </form>

                <?php if (empty($vouchers)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-clipboard-minus" style="font-size: 3rem; color: #ccc;"></i>
                    <p class="text-muted mt-3"><?php _e('no_consumption_vouchers_found'); ?></p>
                    <?php if (hasPermission('consumption_vouchers', 'create')): ?>
                    <a href="consumption_voucher.php" class="btn btn-primary">
                        <i class="bi bi-plus"></i> <?php _e('new_consumption_voucher'); ?>
                    </a>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <p class="text-muted small mb-2"><?php echo sprintf(__('showing_vouchers'), count($vouchers)); ?></p>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover table-sm">
                        <thead class="table-light">
                            <tr>
                                <th><?php _e('voucher_number'); ?></th>
                                <th><?php _e('date'); ?></th>
                                <th><?php _e('location'); ?></th>
                                <th><?php _e('cost_centre'); ?></th>
                                <th class="text-center"><?php _e('items'); ?></th>
                                <th class="text-end"><?php _e('total_amount'); ?></th>
                                <th><?php _e('created_by'); ?></th>
                                <th class="text-center"><?php _e('actions'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($vouchers as $v): ?>
                            <tr>
                                <td>
                                    <a href="consumption_voucher_view.php?id=<?php echo $v['id']; ?>">
                                        <strong><?php echo htmlspecialchars($v['voucher_number']); ?></strong>
                                    </a>
                                    <?php if (!empty($v['return_of_voucher_id'])): ?>
                                    <span class="badge bg-warning text-dark ms-1"><?php _e('return_voucher'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo formatDate($v['voucher_date']); ?></td>
                                <td><?php echo htmlspecialchars($v['location_name']); ?></td>
                                <td>
                                    <?php if ($v['cost_centre_name']): ?>
                                    <small><?php echo htmlspecialchars($v['cost_centre_code']); ?></small> -
                                    <?php echo htmlspecialchars($v['cost_centre_name']); ?>
                                    <?php else: ?>
                                    <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-secondary"><?php echo $v['item_count']; ?></span>
                                </td>
                                <?php
                                // The voucher's OWN currency — formatCurrency()'s 3rd
                                // argument. Passing nothing fell back to the default
                                // currency, so a USD voucher printed as "TZS".
                                $cvCurrency  = getCurrency($v['currency_id']);
                                $cvRate      = (float)($v['exchange_rate_to_base'] ?: 1);
                                $cvIsForeign = $cvCurrency && $cvBaseCurrencyId !== null
                                               && (int)$cvCurrency['id'] !== $cvBaseCurrencyId;
                                $cvTitle = $cvIsForeign
                                    ? $cvCurrency['code'] . ' @ ' . formatNumber($cvRate, 2) . ' = '
                                      . formatCurrency((float)$v['total_amount'] * $cvRate, 2, $cvBaseCurrency)
                                    : '';
                                ?>
                                <td class="text-end text-danger"<?php echo $cvIsForeign ? ' title="' . htmlspecialchars($cvTitle, ENT_QUOTES) . '"' : ''; ?>>
                                    <strong><?php echo formatCurrency($v['total_amount'], 2, $cvCurrency); ?></strong>
                                </td>
                                <td><?php echo htmlspecialchars($v['created_by_name']); ?></td>
                                <td class="text-center">
                                    <a href="consumption_voucher_view.php?id=<?php echo $v['id']; ?>" class="btn btn-sm btn-outline-primary" title="<?php _e('view'); ?>">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a href="consumption_voucher_dn.php?id=<?php echo $v['id']; ?>" class="btn btn-sm btn-outline-secondary" target="_blank" title="<?php _e('print_delivery_note'); ?>">
                                        <i class="bi bi-printer"></i>
                                    </a>
                                    <?php
                                    // Same gates as the form and the view, fed the
                                    // pre-fetched flag so no extra query per row.
                                    $vHasReturns = !empty($cvReturnCounts[(int)$v['id']]);
                                    ?>
                                    <?php if (hasPermission('consumption_vouchers', 'edit') && cvCanEdit($conn, $v, $vHasReturns)): ?>
                                    <a href="consumption_voucher.php?id=<?php echo $v['id']; ?>" class="btn btn-sm btn-outline-warning" title="<?php _e('edit'); ?>">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <?php endif; ?>
                                    <?php if (hasPermission('consumption_vouchers', 'delete') && cvCanDelete($conn, $v, $vHasReturns)): ?>
                                    <button onclick="confirmDelete(<?php echo $v['id']; ?>, '<?php echo htmlspecialchars($v['voucher_number']); ?>')" class="btn btn-sm btn-outline-danger" title="<?php _e('delete'); ?>">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Product filter. jQuery + Select2 are loaded by includes/footer.php, which
    // comes AFTER this inline block, so initialise on DOMContentLoaded (by then
    // those blocking scripts have run) and guard in case the CDN is unreachable
    // — without the guard the whole filter form would break, with it the field
    // degrades to a plain multi-select.
    if (window.jQuery && $.fn.select2) {
        const $products = $('#cvFilterProducts');

        // Searched server-side rather than dumping every product into the page.
        $products.select2({
            width: '100%',
            placeholder: <?php echo json_encode(__('product_filter_placeholder')); ?>,
            allowClear: true,
            minimumInputLength: 0,
            ajax: {
                url: BASE_URL + 'api/products-search.php',
                dataType: 'json',
                delay: 250,
                data: params => ({ q: params.term || '', page: params.page || 1 }),
                processResults: (data, params) => {
                    params.page = params.page || 1;
                    return {
                        results: data.results || [],
                        pagination: data.pagination || { more: false }
                    };
                },
                cache: true
            }
        });

        // Keep the URL clean: an untouched multi-select would still submit
        // products[]= and leave a stray empty param on every filter.
        $('#cvFilterForm').on('submit', function () {
            const picks = $products.val();
            if (!picks || picks.length === 0) {
                $products.prop('disabled', true);
            }
        });
    }
});

function confirmDelete(id, number) {
    if (confirm(<?php echo json_encode(__('confirm_delete_voucher')); ?>.replace('%s', number) + '\n' + <?php echo json_encode(__('confirm_delete_voucher_warning')); ?>)) {
        window.location.href = 'consumption_voucher_view.php?delete=' + id;
    }
}
</script>

<?php require_once '../../includes/footer.php'; ?>
