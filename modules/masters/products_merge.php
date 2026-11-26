<?php
/**
 * Merge Products — Admin only.
 *
 * Pick the product to KEEP and the duplicate to REMOVE, preview what moves,
 * then confirm. All the work is in includes/product_merge.php.
 */
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/product_merge.php';

if (getUserData('role_name') !== 'Admin') {
    setAlert(__('no_permission'), 'danger');
    redirect('');
}

$pageTitle = __('pm_title');

$keepId = (int)($_GET['keep'] ?? $_POST['keep'] ?? 0);
$removeId = (int)($_GET['remove'] ?? $_POST['remove'] ?? 0);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();
    if (empty($_POST['confirm'])) {
        $errors[] = __('pm_err_confirm');
    } else {
        try {
            pmMerge($conn, $keepId, $removeId, $_POST['new_name'] ?? '', $_POST['category_id'] ?? 0);
            redirect('modules/masters/products_merge.php?merged=' . $keepId . '&from=' . $removeId);
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }
    }
}

// Success screen (after the redirect, so a refresh cannot re-post).
$merged = null;
if (!empty($_GET['merged'])) {
    $merged = [
        'keep' => pmFetchProduct($conn, (int)$_GET['merged']),
        'remove' => pmFetchProduct($conn, (int)($_GET['from'] ?? 0)),
    ];
}

$preview = ($keepId && $removeId && !$merged) ? pmPreview($conn, $keepId, $removeId) : null;
// A refused merge repeats the preview's own reasons; show them once.
if ($preview && !empty($preview['errors'])) {
    $errors = [];
}

$categories = $conn->query("SELECT id, parent_id, name FROM product_categories WHERE is_active = 1 ORDER BY name")
                   ->fetchAll(PDO::FETCH_ASSOC);

// Friendly names for the tables a product appears in.
$pmTableLabels = [
    'stock_batches' => __('pm_t_stock_batches'),
    'opening_stocks' => __('pm_t_opening_stocks'),
    'purchase_details' => __('pm_t_purchase_details'),
    'purchase_import_details' => __('pm_t_purchase_import_details'),
    'purchase_order_details' => __('pm_t_purchase_order_details'),
    'sale_details' => __('pm_t_sale_details'),
    'stock_transfer_details' => __('pm_t_stock_transfer_details'),
    'consumption_voucher_details' => __('pm_t_consumption_voucher_details'),
    'stock_adjustment_details' => __('pm_t_stock_adjustment_details'),
    'stock_taking_details' => __('pm_t_stock_taking_details'),
    'requisition_voucher_details' => __('pm_t_requisition_voucher_details'),
    'budget_details' => __('pm_t_budget_details'),
    'bom_details' => __('pm_t_bom_details'),
    'boq_details' => __('pm_t_boq_details'),
    'product_pricing' => __('pm_t_product_pricing'),
];

function pmOption($p) {
    if (!$p) return '';
    $label = $p['name'] . (!empty($p['category_name']) ? ' (' . $p['category_name'] . ')' : '');
    return '<option value="' . (int)$p['id'] . '" selected>' . htmlspecialchars($label) . '</option>';
}

require_once '../../includes/header.php';
?>

<div class="row mb-3">
    <div class="col-md-8">
        <h2><i class="bi bi-intersect"></i> <?php _e('pm_title'); ?></h2>
        <p class="text-muted mb-0"><?php _e('pm_intro'); ?></p>
    </div>
    <div class="col-md-4 text-end">
        <a href="products.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> <?php _e('products_management'); ?></a>
    </div>
</div>

<?php if ($merged): ?>
<div class="alert alert-success">
    <i class="bi bi-check-circle"></i>
    <?php echo sprintf(__('pm_done'),
        htmlspecialchars($merged['remove']['name'] ?? ('#' . (int)($_GET['from'] ?? 0))),
        htmlspecialchars($merged['keep']['name'] ?? ('#' . (int)$_GET['merged']))); ?>
</div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <?php foreach ($errors as $err): ?>
    <div><i class="bi bi-exclamation-triangle"></i> <?php echo htmlspecialchars($err); ?></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Step 1: pick the two products -->
<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-5">
                <label class="form-label fw-bold text-success"><i class="bi bi-check2-circle"></i> <?php _e('pm_keep'); ?></label>
                <select name="keep" id="pmKeep" class="form-select pm-product" required>
                    <?php echo $preview ? pmOption($preview['keep']) : ''; ?>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label fw-bold text-danger"><i class="bi bi-x-circle"></i> <?php _e('pm_remove'); ?></label>
                <select name="remove" id="pmRemove" class="form-select pm-product" required>
                    <?php echo $preview ? pmOption($preview['remove']) : ''; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i> <?php _e('pm_preview'); ?></button>
            </div>
        </form>
    </div>
</div>

<?php if ($preview): ?>
<?php $k = $preview['keep']; $r = $preview['remove']; ?>

<?php if (!empty($preview['errors'])): ?>
<div class="alert alert-danger">
    <?php foreach ($preview['errors'] as $err): ?>
    <div><i class="bi bi-x-octagon"></i> <?php echo htmlspecialchars($err); ?></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($k && $r && (int)$k['id'] !== (int)$r['id']): ?>
<div class="card mb-3">
    <div class="card-header"><strong><?php _e('pm_what_moves'); ?></strong></div>
    <div class="card-body">
        <table class="table table-sm table-bordered">
            <thead class="table-light">
                <tr>
                    <th></th>
                    <th class="text-success"><?php _e('pm_keep'); ?> (#<?php echo (int)$k['id']; ?>)</th>
                    <th class="text-danger"><?php _e('pm_remove'); ?> (#<?php echo (int)$r['id']; ?>)</th>
                </tr>
            </thead>
            <tbody>
                <tr><th><?php _e('name'); ?></th><td><?php echo htmlspecialchars($k['name']); ?></td><td><?php echo htmlspecialchars($r['name']); ?></td></tr>
                <tr><th><?php _e('unit'); ?></th><td><?php echo htmlspecialchars($k['unit']); ?></td><td><?php echo htmlspecialchars($r['unit']); ?></td></tr>
                <tr><th><?php _e('category'); ?></th><td><?php echo htmlspecialchars($k['category_name'] ?? '-'); ?></td><td><?php echo htmlspecialchars($r['category_name'] ?? '-'); ?></td></tr>
                <?php foreach ($preview['tables'] as $t): ?>
                <tr>
                    <th class="fw-normal"><?php echo htmlspecialchars($pmTableLabels[$t['table']] ?? $t['table']); ?></th>
                    <td><?php echo (int)$t['keep']; ?></td>
                    <td><?php echo (int)$t['remove']; ?> <?php if ($t['remove'] > 0): ?><i class="bi bi-arrow-left text-primary" title="<?php _e('pm_moves_to_keep'); ?>"></i><?php endif; ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if ($preview['snapshots'] > 0): ?>
                <tr><th class="fw-normal"><?php _e('pm_t_snapshots'); ?></th><td></td><td><?php echo (int)$preview['snapshots']; ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if (!empty($preview['stock'])): ?>
        <h6 class="mt-3"><?php _e('pm_stock_by_location'); ?></h6>
        <table class="table table-sm table-bordered">
            <thead class="table-light">
                <tr>
                    <th><?php _e('location'); ?></th>
                    <th class="text-end"><?php _e('pm_keep'); ?></th>
                    <th class="text-end"><?php _e('pm_remove'); ?></th>
                    <th class="text-end"><?php _e('pm_after_merge'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($preview['stock'] as $s): ?>
                <tr>
                    <td><?php echo htmlspecialchars($s['location_name'] ?? ('#' . (int)$s['location_id'])); ?></td>
                    <td class="text-end"><?php echo formatNumber($s['keep_qty'], 3); ?></td>
                    <td class="text-end"><?php echo formatNumber($s['remove_qty'], 3); ?></td>
                    <td class="text-end fw-bold"><?php echo formatNumber($s['keep_qty'] + $s['remove_qty'], 3); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <?php if (!empty($preview['opening_fold'])): ?>
        <div class="alert alert-info small">
            <i class="bi bi-info-circle"></i> <?php _e('pm_opening_fold'); ?>
            <?php foreach ($preview['opening_fold'] as $o): ?>
            <div>&bull; <?php echo htmlspecialchars($o['location_name'] ?? '-'); ?>:
                <?php echo formatNumber($o['keep_qty'], 3); ?> + <?php echo formatNumber($o['remove_qty'], 3); ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($preview['pricing_drop'])): ?>
        <div class="alert alert-warning small">
            <i class="bi bi-exclamation-triangle"></i> <?php _e('pm_pricing_drop'); ?>
            <?php foreach ($preview['pricing_drop'] as $p): ?>
            <div>&bull; <?php echo htmlspecialchars($p['location_name'] ?? '-'); ?>:
                <?php echo formatNumber($p['keep_price'], 2); ?> (<?php _e('pm_kept'); ?>) / <?php echo formatNumber($p['remove_price'], 2); ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if (empty($preview['errors'])): ?>
<!-- Step 2: confirm -->
<div class="card mb-3 border-danger">
    <div class="card-body">
        <form method="POST" onsubmit="return confirm(<?php echo htmlspecialchars(json_encode(__('pm_confirm_js')), ENT_QUOTES); ?>);">
            <?php echo csrfField(); ?>
            <input type="hidden" name="keep" value="<?php echo (int)$k['id']; ?>">
            <input type="hidden" name="remove" value="<?php echo (int)$r['id']; ?>">
            <div class="row g-3">
                <div class="col-md-7">
                    <label class="form-label fw-bold"><?php _e('pm_final_name'); ?></label>
                    <input type="text" name="new_name" class="form-control" maxlength="200" required
                           value="<?php echo htmlspecialchars($_POST['new_name'] ?? $k['name']); ?>">
                </div>
                <div class="col-md-5">
                    <label class="form-label fw-bold"><?php _e('category'); ?></label>
                    <select name="category_id" class="form-select" required>
                        <?= renderTreeOptions($categories, (int)($_POST['category_id'] ?? $k['category_id'])) ?>
                    </select>
                </div>
            </div>
            <ul class="small text-muted mt-3 mb-2">
                <li><?php _e('pm_note_moves'); ?></li>
                <li><?php _e('pm_note_deactivate'); ?></li>
                <li><?php _e('pm_note_undo'); ?></li>
            </ul>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="confirm" value="1" id="pmConfirm" required>
                <label class="form-check-label fw-bold" for="pmConfirm"><?php _e('pm_confirm_box'); ?></label>
            </div>
            <button type="submit" class="btn btn-danger"><i class="bi bi-intersect"></i> <?php _e('pm_merge_button'); ?></button>
        </form>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (!(window.jQuery && $.fn.select2)) return;
    $('.pm-product').select2({
        width: '100%',
        placeholder: <?php echo json_encode(__('pm_search_product')); ?>,
        minimumInputLength: 2,
        ajax: {
            url: <?php echo json_encode(BASE_URL . 'api/products-search.php'); ?>,
            dataType: 'json',
            delay: 250,
            data: function (params) { return { q: params.term, page: params.page || 1 }; },
            processResults: function (data) {
                return {
                    results: (data.results || []).map(function (r) {
                        return { id: r.id, text: '#' + r.id + ' ' + r.text + (r.unit ? ' [' + r.unit + ']' : '') };
                    }),
                    pagination: data.pagination || { more: false }
                };
            }
        }
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>
