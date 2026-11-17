<?php
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/dead_stock_query.php';

if (!canViewReport('dead_stock')) {
    setAlert(__('no_permission'), 'danger');
    redirect('');
}

$pageTitle = __('dead_stock');

$userLocations = getUserLocations();
$categories = $conn->query("SELECT id, parent_id, name FROM product_categories WHERE is_active = 1 ORDER BY name")->fetchAll();

$locationFilter = (int)($_GET['location'] ?? 0);
$categoryFilter = (int)($_GET['category'] ?? 0);
$daysInput = $_GET['days'] ?? (string)DEAD_STOCK_DEFAULT_DAYS;

// The report runs only after Generate is pressed: across all locations it can
// be thousands of rows, too heavy to load just for opening the page.
$generated = isset($_GET['days']);
$days = deadStockParseDays($daysInput);
$rows = [];
$error = null;

if ($generated) {
    $locationIds = deadStockResolveLocationIds($conn, $locationFilter);
    if ($days === null) {
        $error = sprintf(__('dead_stock_days_invalid'), DEAD_STOCK_MAX_DAYS);
    } elseif (empty($locationIds)) {
        $error = __('dead_stock_no_location_access');
    } else {
        $categoryIds = $categoryFilter > 0 ? getDescendantCategoryIds($categoryFilter, $conn) : [];
        $rows = deadStockFetchRows($conn, $locationIds, $days, $categoryIds);
    }
}

$totalQty = 0.0;
$totalValue = 0.0;
$groups = [];
foreach ($rows as $row) {
    $totalQty += (float)$row['quantity'];
    $totalValue += (float)$row['stock_value'];
    $locId = (int)$row['location_id'];
    if (!isset($groups[$locId])) {
        $groups[$locId] = ['name' => $row['location_name'], 'rows' => [], 'qty' => 0.0, 'value' => 0.0];
    }
    $groups[$locId]['rows'][] = $row;
    $groups[$locId]['qty'] += (float)$row['quantity'];
    $groups[$locId]['value'] += (float)$row['stock_value'];
}

$exportQuery = http_build_query(['days' => $days, 'location' => $locationFilter, 'category' => $categoryFilter]);

require_once '../../includes/header.php';
?>

<div class="row mb-3">
    <div class="col-md-12">
        <h2><i class="bi bi-hourglass-split"></i> <?php _e('dead_stock'); ?></h2>
    </div>
</div>

<div class="card mb-3 no-print">
    <div class="card-body">
        <form method="GET" action="">
            <div class="row g-3">
                <div class="col-md-2">
                    <label class="form-label required"><?php _e('dead_stock_days'); ?></label>
                    <input type="number" name="days" class="form-control" min="1" max="<?php echo DEAD_STOCK_MAX_DAYS; ?>" step="1"
                           value="<?php echo htmlspecialchars((string)$daysInput); ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label"><?php _e('location'); ?></label>
                    <select name="location" class="form-select select2">
                        <option value=""><?php _e('all_locations'); ?></option>
                        <?php echo renderTreeOptions($userLocations, $locationFilter); ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label"><?php _e('category'); ?></label>
                    <select name="category" class="form-select select2">
                        <option value=""><?php _e('all_categories'); ?></option>
                        <?php echo renderTreeOptions($categories, $categoryFilter); ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-search"></i> <?php _e('generate'); ?>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php if ($error): ?>
<div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php elseif ($generated): ?>

<p class="mb-2"><strong><?php echo htmlspecialchars(sprintf(__('dead_stock_criteria'), $days)); ?></strong></p>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small"><?php _e('dead_stock_lines'); ?></div>
            <div class="fs-4 fw-bold" id="deadStockLineCount"><?php echo number_format(count($rows)); ?></div>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small"><?php _e('dead_stock_total_qty'); ?></div>
            <div class="fs-4 fw-bold"><?php echo formatNumber($totalQty, 3); ?></div>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small"><?php _e('dead_stock_total_value'); ?></div>
            <div class="fs-4 fw-bold"><?php echo formatCurrency($totalValue); ?></div>
        </div></div>
    </div>
</div>

<?php if (empty($rows)): ?>
<div class="alert alert-info"><?php _e('dead_stock_none_found'); ?></div>
<?php else: ?>

<div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2 no-print">
    <div class="text-muted small"><i class="bi bi-info-circle"></i> <?php _e('dead_stock_click_hint'); ?></div>
    <div class="d-flex gap-2">
        <a class="btn btn-success btn-sm" href="dead_stock_export.php?<?php echo htmlspecialchars($exportQuery); ?>">
            <i class="bi bi-file-earmark-excel"></i> <?php _e('export_excel'); ?>
        </a>
        <button type="button" class="btn btn-secondary btn-sm" onclick="window.print()">
            <i class="bi bi-printer"></i> <?php _e('print'); ?>
        </button>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0" id="deadStockTable">
            <thead class="table-light">
                <tr>
                    <th><?php _e('product'); ?></th>
                    <th><?php _e('category'); ?></th>
                    <th><?php _e('unit'); ?></th>
                    <th class="text-end"><?php _e('quantity'); ?></th>
                    <th><?php _e('dead_stock_last_movement'); ?></th>
                    <th class="text-end"><?php _e('dead_stock_days_idle'); ?></th>
                    <th class="text-end"><?php _e('rate'); ?></th>
                    <th class="text-end"><?php _e('value'); ?></th>
                </tr>
            </thead>
            <?php foreach ($groups as $locId => $group): ?>
            <tbody>
                <tr class="table-secondary">
                    <th colspan="8"><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($group['name']); ?></th>
                </tr>
                <?php foreach ($group['rows'] as $row):
                    $cardUrl = 'product_stock_card.php?' . http_build_query([
                        'location' => $locId,
                        'product' => $row['product_id'],
                        'date_from' => '2000-01-01',
                        'date_to' => date('Y-m-d'),
                    ]);
                ?>
                <tr class="dead-stock-row" style="cursor:pointer"
                    data-product="<?php echo (int)$row['product_id']; ?>" data-location="<?php echo $locId; ?>">
                    <td><a href="<?php echo htmlspecialchars($cardUrl); ?>" class="dead-stock-link"><?php echo htmlspecialchars($row['product_name']); ?></a></td>
                    <td><?php echo htmlspecialchars((string)$row['category_name']); ?></td>
                    <td><?php echo htmlspecialchars((string)$row['unit']); ?></td>
                    <td class="text-end"><?php echo formatNumber($row['quantity'], 3); ?></td>
                    <td>
                        <?php if ($row['last_movement_date']): ?>
                            <?php echo htmlspecialchars(formatDate($row['last_movement_date'])); ?>
                            <span class="text-muted small">(<?php echo htmlspecialchars(deadStockMovementLabel($row['last_movement_type'])); ?>)</span>
                        <?php else: ?>
                            <span class="badge bg-danger"><?php _e('dead_stock_never_moved'); ?></span>
                            <?php if ($row['opening_date']): ?>
                            <span class="text-muted small">(<?php _e('dead_stock_opening_short'); ?>: <?php echo htmlspecialchars(formatDate($row['opening_date'])); ?>)</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-end"><?php echo $row['days_idle'] !== null ? number_format((int)$row['days_idle']) : '&ndash;'; ?></td>
                    <td class="text-end"><?php echo formatNumber($row['rate'], 2); ?></td>
                    <td class="text-end"><?php echo formatNumber($row['stock_value'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="fw-bold table-light">
                    <td colspan="3" class="text-end"><?php _e('dead_stock_location_total'); ?></td>
                    <td class="text-end"><?php echo formatNumber($group['qty'], 3); ?></td>
                    <td colspan="3"></td>
                    <td class="text-end"><?php echo formatNumber($group['value'], 2); ?></td>
                </tr>
            </tbody>
            <?php endforeach; ?>
            <tfoot class="table-dark">
                <tr class="fw-bold">
                    <td colspan="3" class="text-end"><?php _e('grand_total'); ?></td>
                    <td class="text-end"><?php echo formatNumber($totalQty, 3); ?></td>
                    <td colspan="3"></td>
                    <td class="text-end"><?php echo formatCurrency($totalValue); ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<div class="modal fade" id="deadStockModal" tabindex="-1" aria-labelledby="deadStockModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-0" id="deadStockModalTitle"></h5>
                    <div class="text-muted small" id="deadStockModalLocation"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo htmlspecialchars(__('close')); ?>"></button>
            </div>
            <div class="modal-body" id="deadStockModalBody"></div>
            <div class="modal-footer">
                <?php if (canViewReport('stock_report')): ?>
                <a class="btn btn-outline-primary" id="deadStockCardLink" href="#"><i class="bi bi-card-list"></i> <?php _e('dead_stock_full_history'); ?></a>
                <?php endif; ?>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php _e('close'); ?></button>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.jQuery && $.fn.select2) {
        $('.select2').each(function () {
            $(this).select2({
                placeholder: $(this).find('option[value=""]').text(),
                allowClear: true,
                width: '100%'
            });
        });
    }

    const table = document.getElementById('deadStockTable');
    if (!table) return;

    const labels = <?php echo json_encode([
        'loading' => __('dead_stock_loading'),
        'loadFailed' => __('dead_stock_load_failed'),
        'type' => __('dead_stock_movement_type'),
        'date' => __('date'),
        'document' => __('dead_stock_document'),
        'quantity' => __('quantity'),
        'party' => __('party'),
        'none' => __('dead_stock_none'),
        'notCounted' => __('dead_stock_not_counted'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

    const modalEl = document.getElementById('deadStockModal');
    const bodyEl = document.getElementById('deadStockModalBody');
    const titleEl = document.getElementById('deadStockModalTitle');
    const locationEl = document.getElementById('deadStockModalLocation');
    const cardLink = document.getElementById('deadStockCardLink');
    let latestRequest = 0;

    function cell(text, className) {
        const td = document.createElement('td');
        td.textContent = text;
        if (className) td.className = className;
        return td;
    }

    function message(text, className) {
        const div = document.createElement('div');
        div.className = className;
        div.textContent = text;
        bodyEl.replaceChildren(div);
    }

    function renderDetail(data) {
        const table = document.createElement('table');
        table.className = 'table table-sm table-bordered align-middle mb-0';

        const thead = table.createTHead().insertRow();
        [labels.type, labels.date, labels.document, labels.quantity, labels.party].forEach(function (heading, i) {
            const th = document.createElement('th');
            th.textContent = heading;
            if (i === 3) th.className = 'text-end';
            thead.appendChild(th);
        });

        const tbody = table.createTBody();
        data.rows.forEach(function (row) {
            const tr = tbody.insertRow();
            if (!row.counts_as_movement) tr.className = 'table-light';

            const typeCell = cell(row.label);
            if (!row.counts_as_movement) {
                const note = document.createElement('div');
                note.className = 'text-muted small';
                note.textContent = labels.notCounted;
                typeCell.appendChild(note);
            }
            tr.appendChild(typeCell);

            if (!row.date) {
                const none = cell(labels.none, 'text-muted');
                none.colSpan = 4;
                tr.appendChild(none);
                return;
            }

            tr.appendChild(cell(row.date));
            const docCell = cell(row.url ? '' : (row.document || '–'));
            if (row.url) {
                const link = document.createElement('a');
                link.href = row.url;
                link.textContent = row.document;
                docCell.appendChild(link);
            }
            tr.appendChild(docCell);
            tr.appendChild(cell(row.quantity, 'text-end'));
            tr.appendChild(cell(row.party || '–'));
        });

        const wrapper = document.createElement('div');
        wrapper.className = 'table-responsive';
        wrapper.appendChild(table);
        bodyEl.replaceChildren(wrapper);
    }

    table.addEventListener('click', function (event) {
        const row = event.target.closest('tr.dead-stock-row');
        if (!row) return;
        event.preventDefault();

        const requestId = ++latestRequest;
        const link = row.querySelector('.dead-stock-link');
        titleEl.textContent = link.textContent;
        locationEl.textContent = '';
        if (cardLink) cardLink.href = link.href;
        message(labels.loading, 'text-muted');
        bootstrap.Modal.getOrCreateInstance(modalEl).show();

        const params = new URLSearchParams({ product: row.dataset.product, location: row.dataset.location });
        fetch('../../api/dead-stock-detail.php?' + params.toString(), { credentials: 'same-origin' })
            .then(function (response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.json();
            })
            .then(function (data) {
                if (requestId !== latestRequest) return;
                if (!data.success) throw new Error(data.message || 'failed');
                titleEl.textContent = data.product;
                locationEl.textContent = data.location;
                renderDetail(data);
            })
            .catch(function () {
                if (requestId === latestRequest) message(labels.loadFailed, 'alert alert-danger mb-0');
            });
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>
