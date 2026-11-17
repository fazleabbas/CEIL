<?php
require_once '../../config/database.php';
require_once '../../includes/functions.php';

$pageTitle = __('product_pricing');

// Check permissions
if (!hasPermission('products', 'view')) {
    setAlert('You do not have permission to access this page', 'danger');
    redirect('modules/masters/products.php');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && hasPermission('products', 'edit')) {
    checkCSRF();
    
    $productId = (int)$_POST['product_id'];
    $prices = $_POST['prices'] ?? [];
    
    try {
        $conn->beginTransaction();
        
        $saved = 0;
        foreach ($prices as $locationId => $price) {
            $locationId = (int)$locationId;
            $price = trim($price);
            
            if ($price === '' || $price < 0) {
                // Delete if empty or negative
                $stmt = $conn->prepare("DELETE FROM product_pricing WHERE product_id = ? AND location_id = ?");
                $stmt->execute([$productId, $locationId]);
            } else {
                // Insert or update
                $stmt = $conn->prepare("
                    INSERT INTO product_pricing (product_id, location_id, selling_price)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        selling_price = VALUES(selling_price),
                        updated_at = CURRENT_TIMESTAMP
                ");
                $stmt->execute([$productId, $locationId, $price]);
                $saved++;
            }
        }
        
        $conn->commit();
        setAlert("Pricing updated successfully! ($saved locations)", 'success');
        redirect('modules/masters/product_pricing.php?product=' . $productId);
        
    } catch (Exception $e) {
        $conn->rollBack();
        setAlert('Error saving prices: ' . $e->getMessage(), 'danger');
    }
}

// Get selected product
$selectedProduct = $_GET['product'] ?? '';
$products = [];
$locations = [];
$currentPrices = [];

// Get all products
$stmt = $conn->query("
    SELECT id, name, unit, default_selling_price 
    FROM products 
    WHERE is_active = 1 
    ORDER BY name
");
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all locations
$stmt = $conn->query("
    SELECT id, name 
    FROM locations 
    WHERE is_active = 1 
    ORDER BY name
");
$locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// If product selected, get current prices
if ($selectedProduct) {
    $stmt = $conn->prepare("
        SELECT location_id, selling_price 
        FROM product_pricing 
        WHERE product_id = ? AND is_active = 1
    ");
    $stmt->execute([$selectedProduct]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($rows as $row) {
        $currentPrices[$row['location_id']] = $row['selling_price'];
    }
    
    // Get product details
    $stmt = $conn->prepare("SELECT name, unit, default_selling_price FROM products WHERE id = ?");
    $stmt->execute([$selectedProduct]);
    $productDetails = $stmt->fetch(PDO::FETCH_ASSOC);
}

require_once '../../includes/header.php';
?>

<div class="container-fluid px-4 py-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-tag"></i> <?php _e('product_pricing'); ?></h2>
        <a href="products.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> <?php _e('back_to_products'); ?>
        </a>
    </div>

    <div class="card">
        <div class="card-body">
            <form method="GET" class="row g-3 mb-4">
                <div class="col-md-8">
                    <label class="form-label"><?php _e('select_product'); ?></label>
                    <select name="product" class="form-select" onchange="this.form.submit()" required>
                        <option value=""><?php _e('choose_product'); ?></option>
                        <?php foreach ($products as $p): ?>
                        <option value="<?php echo $p['id']; ?>" <?php echo $selectedProduct == $p['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($p['name']); ?> 
                            (<?php echo $p['unit']; ?>) 
                            - <?php _e('default_label'); ?>: <?php echo getSetting('currency_symbol'); ?><?php echo number_format($p['default_selling_price'], 2); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>

            <?php if ($selectedProduct && isset($productDetails)): ?>
            <div class="alert alert-info">
                <h5 class="mb-2">
                    <i class="bi bi-box"></i> <?php echo htmlspecialchars($productDetails['name']); ?>
                </h5>
                <p class="mb-0">
                    <strong><?php _e('unit'); ?>:</strong> <?php echo $productDetails['unit']; ?> &nbsp;|&nbsp;
                    <strong><?php _e('default_price'); ?>:</strong> <?php echo getSetting('currency_symbol'); ?><?php echo number_format($productDetails['default_selling_price'], 2); ?>
                </p>
                <small class="text-muted">
                    <?php _e('no_location_price_info'); ?>
                </small>
            </div>

            <form method="POST" action="">
                <?php echo csrfField(); ?>
                <input type="hidden" name="product_id" value="<?php echo $selectedProduct; ?>">

                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead class="table-light">
                            <tr>
                                <th width="40%"><?php _e('location'); ?></th>
                                <th width="25%"><?php _e('specific_price'); ?></th>
                                <th width="25%"><?php _e('effective_price'); ?></th>
                                <th width="10%"><?php _e('action'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($locations as $location): 
                                $currentPrice = $currentPrices[$location['id']] ?? '';
                                $effectivePrice = $currentPrice ?: $productDetails['default_selling_price'];
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($location['name']); ?></strong>
                                </td>
                                <td>
                                    <div class="input-group">
                                        <span class="input-group-text"><?php echo getSetting('currency_symbol'); ?></span>
                                        <input 
                                            type="number" 
                                            name="prices[<?php echo $location['id']; ?>]" 
                                            class="form-control price-input" 
                                            step="0.01" 
                                            min="0"
                                            value="<?php echo $currentPrice; ?>"
                                            placeholder="<?php _e('leave_empty_default_placeholder'); ?>"
                                            data-default="<?php echo $productDetails['default_selling_price']; ?>"
                                            data-location="<?php echo $location['id']; ?>"
                                        >
                                    </div>
                                    <small class="text-muted"><?php _e('leave_empty_default'); ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-success effective-price" data-location="<?php echo $location['id']; ?>">
                                        <?php echo getSetting('currency_symbol'); ?><?php echo number_format($effectivePrice, 2); ?>
                                    </span>
                                    <small class="text-muted d-block price-type-label" data-location="<?php echo $location['id']; ?>">
                                        <?php echo $currentPrice ? __('custom_label') : __('default_label'); ?>
                                    </small>
                                </td>
                                <td>
                                    <?php if ($currentPrice): ?>
                                    <button 
                                        type="button" 
                                        class="btn btn-sm btn-outline-danger clear-price"
                                        data-location="<?php echo $location['id']; ?>"
                                        title="<?php _e('clear_custom_price'); ?>"
                                    >
                                        <i class="bi bi-x"></i>
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (hasPermission('products', 'edit')): ?>
                <div class="mt-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> <?php _e('save_pricing'); ?>
                    </button>
                    <button type="button" class="btn btn-outline-secondary" onclick="applyToAll()">
                        <i class="bi bi-arrow-repeat"></i> <?php _e('apply_first_to_all'); ?>
                    </button>
                </div>
                <?php endif; ?>
            </form>
            <?php else: ?>
            <div class="alert alert-warning">
                <i class="bi bi-info-circle"></i> <?php _e('select_product_message'); ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
const currencySymbol = <?php echo json_encode(getSetting('currency_symbol')); ?>;
const LANG = {
    custom_label: <?php echo json_encode(__('custom_label')); ?>,
    default_label: <?php echo json_encode(__('default_label')); ?>,
    enter_first_price: <?php echo json_encode(__('enter_first_price')); ?>,
    confirm_apply_all: <?php echo json_encode(__('confirm_apply_all')); ?>
};

// Update effective price on input change
document.querySelectorAll('.price-input').forEach(input => {
    input.addEventListener('input', function() {
        const locationId = this.dataset.location;
        const defaultPrice = parseFloat(this.dataset.default);
        const customPrice = parseFloat(this.value);
        const effectivePrice = customPrice > 0 ? customPrice : defaultPrice;
        
        const badge = document.querySelector(`.effective-price[data-location="${locationId}"]`);
        badge.textContent = currencySymbol + effectivePrice.toFixed(2);
        
        const label = document.querySelector(`.price-type-label[data-location="${locationId}"]`);
        if (label) {
            label.textContent = customPrice > 0 ? LANG.custom_label : LANG.default_label;
        }
    });
});

// Clear price button
document.querySelectorAll('.clear-price').forEach(btn => {
    btn.addEventListener('click', function() {
        const locationId = this.dataset.location;
        const input = document.querySelector(`.price-input[data-location="${locationId}"]`);
        input.value = '';
        input.dispatchEvent(new Event('input'));
    });
});

// Apply first price to all locations
function applyToAll() {
    const firstInput = document.querySelector('.price-input');
    if (!firstInput || !firstInput.value) {
        alert(LANG.enter_first_price);
        return;
    }
    
    if (!confirm(LANG.confirm_apply_all)) {
        return;
    }
    
    const price = firstInput.value;
    document.querySelectorAll('.price-input').forEach(input => {
        input.value = price;
        input.dispatchEvent(new Event('input'));
    });
}
</script>

<?php require_once '../../includes/footer.php'; ?>
