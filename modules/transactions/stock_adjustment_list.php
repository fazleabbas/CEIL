<?php
require_once '../../config/database.php';
require_once '../../includes/functions.php';
$pageTitle = __('stock_adjustments');
if (!hasPermission('stock_adjustment', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('');
}

require_once '../../includes/header.php';

// Filters
$locationFilter = $_GET['location'] ?? '';
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

// Get user's accessible locations
$locations = getUserLocations();

// Build query
$whereClause = "WHERE sa.adjustment_date BETWEEN ? AND ?";
$params = [$dateFrom, $dateTo];

if ($locationFilter) {
    $whereClause .= " AND sa.location_id = ?";
    $params[] = $locationFilter;
} else {
    $locationIds = array_column($locations, 'id');
    if (!empty($locationIds)) {
        $placeholders = implode(',', array_fill(0, count($locationIds), '?'));
        $whereClause .= " AND sa.location_id IN ($placeholders)";
        $params = array_merge($params, $locationIds);
    }
}

// Base currency, for flagging + converting the foreign-currency rows below.
$adjBaseCurrency   = getDefaultCurrency();
$adjBaseCurrencyId = $adjBaseCurrency ? (int)$adjBaseCurrency['id'] : null;

$stmt = $conn->prepare("
    SELECT
        sa.*,
        l.name as location_name,
        u.name as created_by_name,
        st.stock_taking_number,
        (SELECT COUNT(*) FROM stock_adjustment_details WHERE adjustment_id = sa.id) as item_count
    FROM stock_adjustments sa
    LEFT JOIN locations l ON sa.location_id = l.id
    LEFT JOIN users u ON sa.created_by = u.id
    LEFT JOIN stock_taking st ON sa.stock_taking_id = st.id
    $whereClause
    ORDER BY sa.adjustment_date DESC, sa.id DESC
");
$stmt->execute($params);
$adjustments = $stmt->fetchAll();
?>

<div class="row justify-content-center">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="bi bi-sliders"></i> <?php _e('stock_adjustments'); ?>
                </h5>
                <?php if (hasPermission('stock_adjustment', 'create')): ?>
                <a href="stock_adjustment.php" class="btn btn-sm btn-primary">
                    <i class="bi bi-plus"></i> <?php _e('new_adjustment'); ?>
                </a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <!-- Filters -->
                <form method="GET" class="row g-3 mb-4">
                    <div class="col-md-3">
                        <label class="form-label"><?php _e('location'); ?></label>
                        <select name="location" class="form-select">
                            <option value=""><?php _e('all_locations'); ?></option>
                            <?= renderTreeOptions($locations, $locationFilter) ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?php _e('from_date'); ?></label>
                        <input type="date" name="date_from" class="form-control" value="<?php echo $dateFrom; ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?php _e('to_date'); ?></label>
                        <input type="date" name="date_to" class="form-control" value="<?php echo $dateTo; ?>">
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="bi bi-search"></i> <?php _e('filter'); ?>
                        </button>
                        <a href="stock_adjustment_list.php" class="btn btn-secondary">
                            <i class="bi bi-x-circle"></i> <?php _e('reset'); ?>
                        </a>
                    </div>
                </form>
                
                <?php if (empty($adjustments)): ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i> <?php _e('no_stock_adjustments_found'); ?>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover table-sm">
                        <thead class="table-light">
                            <tr>
                                <th><?php _e('adjustment_number'); ?></th>
                                <th><?php _e('date'); ?></th>
                                <th><?php _e('location'); ?></th>
                                <th class="text-center"><?php _e('items'); ?></th>
                                <th class="text-end"><?php _e('net_amount'); ?></th>
                                <th><?php _e('stock_taking'); ?></th>
                                <th><?php _e('created_by'); ?></th>
                                <th><?php _e('actions'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($adjustments as $adj): ?>
                            <tr>
                                <td>
                                    <a href="stock_adjustment_view.php?id=<?php echo $adj['id']; ?>">
                                        <strong><?php echo htmlspecialchars($adj['adjustment_number']); ?></strong>
                                    </a>
                                </td>
                                <td><?php echo formatDate($adj['adjustment_date']); ?></td>
                                <td><?php echo htmlspecialchars($adj['location_name']); ?></td>
                                <td class="text-center">
                                    <span class="badge bg-secondary"><?php echo $adj['item_count']; ?></span>
                                </td>
                                <?php
                                // The adjustment's OWN currency — formatCurrency()'s 3rd
                                // argument. Passing nothing fell back to the default
                                // currency, so a USD adjustment printed as "TZS".
                                $adjCurrency  = getCurrency($adj['currency_id']);
                                $adjRate      = (float)($adj['exchange_rate_to_base'] ?: 1);
                                $adjIsForeign = $adjCurrency && $adjBaseCurrencyId !== null
                                                && (int)$adjCurrency['id'] !== $adjBaseCurrencyId;
                                $adjTitle = $adjIsForeign
                                    ? $adjCurrency['code'] . ' @ ' . formatNumber($adjRate, 2) . ' = '
                                      . formatCurrency((float)$adj['total_adjustment_amount'] * $adjRate, 2, $adjBaseCurrency)
                                    : '';
                                ?>
                                <td class="text-end"<?php echo $adjIsForeign ? ' title="' . htmlspecialchars($adjTitle, ENT_QUOTES) . '"' : ''; ?>>
                                    <strong class="<?php echo $adj['total_adjustment_amount'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo $adj['total_adjustment_amount'] >= 0 ? '+' : ''; ?>
                                        <?php echo formatCurrency($adj['total_adjustment_amount'], 2, $adjCurrency); ?>
                                    </strong>
                                </td>
                                <td>
                                    <?php if ($adj['stock_taking_number']): ?>
                                    <a href="../reports/stock_taking_view.php?id=<?php echo $adj['stock_taking_id']; ?>" class="text-decoration-none">
                                        <?php echo htmlspecialchars($adj['stock_taking_number']); ?>
                                    </a>
                                    <?php else: ?>
                                    <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($adj['created_by_name']); ?></td>
                                <td>
                                    <a href="stock_adjustment_view.php?id=<?php echo $adj['id']; ?>"
                                       class="btn btn-sm btn-outline-primary" title="<?php echo __('view'); ?>">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <?php if (hasPermission('stock_adjustment', 'delete')): ?>
                                    <button onclick="confirmDelete(<?php echo $adj['id']; ?>, '<?php echo $adj['adjustment_number']; ?>')"
                                            class="btn btn-sm btn-outline-danger" title="<?php echo __('delete'); ?>">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="text-muted">
                    <small><?php echo sprintf(__('showing_adjustments'), count($adjustments)); ?></small>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function confirmDelete(id, number) {
    const message = '<?php echo __('confirm_delete_adjustment'); ?>'.replace('%s', number) + '\n\n<?php echo __('confirm_delete_adjustment_warning'); ?>';
    if (confirm(message)) {
        window.location.href = 'stock_adjustment_view.php?delete=' + id;
    }
}
</script>

<?php require_once '../../includes/footer.php'; ?>
