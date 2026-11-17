<?php
require_once '../../config/database.php';
require_once '../../includes/functions.php';

$pageTitle = __('sales_pos');

if (!hasPermission('sales', 'create')) {
    setAlert('You do not have permission to access this page', 'danger');
    redirect('');
}

$today = date('Y-m-d');
$userLocations = getUserLocations();

// Get saved location from cookie
$selectedLocation = $_COOKIE['sales_location'] ?? '';

// Get currency symbol
$currencySymbol = getSetting('currency_symbol', 'TZS');

// Get all active customers
$customersStmt = $conn->query("
    SELECT c.id, la.name
    FROM customers c
    INNER JOIN ledger_accounts la ON c.ledger_account_id = la.id
    WHERE c.is_active = 1
    ORDER BY la.name
");
$customers = $customersStmt->fetchAll();

// Get cash/bank accounts for receipts
$accountsStmt = $conn->query("
    SELECT id, name, account_type
    FROM ledger_accounts
    WHERE account_type IN ('cash', 'bank') AND is_active = 1
    ORDER BY account_type, name
");
$accounts = $accountsStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-QJLXG4H5RY"></script>
    <script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());

    gtag('config', 'G-QJLXG4H5RY');
    </script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php _e('sales_pos'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: #f5f5f5;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding-bottom: 80px;
        }
        
        /* Connection Status Banner */
        .connection-status {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            padding: 10px;
            text-align: center;
            z-index: 9999;
            font-weight: 500;
        }
        .connection-status.online {
            background: #d4edda;
            color: #155724;
        }
        .connection-status.offline {
            background: #f8d7da;
            color: #721c24;
        }
        
        /* Top Bar */
        .top-bar {
            background: #fff;
            padding: 12px 20px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .top-bar a {
            text-decoration: none;
            color: #0d6efd;
            font-weight: 600;
            font-size: 16px;
        }
        
        .controls {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .controls input, .controls select, .controls button {
            font-size: 13px;
        }
        
        .controls input[type="date"], .controls select {
            width: 140px;
        }
        
        /* Products Section */
        .products-section {
            padding: 0 20px 20px;
        }
        
        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 15px;
        }
        
        .product-block {
            background: #fff;
            border: 3px solid #e9ecef;
            border-radius: 12px;
            padding: 20px 15px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
            min-height: 110px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .product-block:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.15);
            border-color: #0d6efd;
        }
        
        .product-block.in-cart {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-color: #667eea;
            color: white;
            font-weight: 600;
            transform: scale(1.02);
        }
        
        .product-block .name {
            font-size: 15px;
            font-weight: 600;
            margin-bottom: 8px;
            line-height: 1.3;
        }
        
        .product-block .price {
            font-size: 13px;
            color: #6c757d;
            font-weight: 500;
        }
        
        .product-block.in-cart .price {
            color: rgba(255,255,255,0.95);
        }
        
        .product-block .qty-badge {
            position: absolute;
            top: -10px;
            right: -10px;
            background: #dc3545;
            color: white;
            border-radius: 50%;
            width: 35px;
            height: 35px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 16px;
            box-shadow: 0 3px 6px rgba(220,53,69,0.4);
            border: 3px solid white;
            transition: transform 0.15s ease, background 0.15s ease;
            z-index: 10;
        }

        .product-block .qty-badge:hover {
            transform: scale(1.15);
            background: #c82333;
        }
        
        .product-block .minus-btn {
            position: absolute;
            top: -10px;
            left: -10px;
            background: #ffc107;
            color: #000;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 18px;
            box-shadow: 0 2px 4px rgba(255,193,7,0.4);
            border: 2px solid white;
            cursor: pointer;
            z-index: 10;
        }
        
        .product-block .minus-btn:hover {
            background: #ffb300;
            transform: scale(1.1);
        }
        
        .add-product-btn {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border: 3px dashed #0d6efd;
            color: #0d6efd;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            gap: 8px;
        }
        
        .add-product-btn:hover {
            background: linear-gradient(135deg, #e7f1ff 0%, #cfe2ff 100%);
            transform: translateY(-3px);
        }
        
        .add-product-btn i {
            font-size: 32px;
        }
        
        /* Bottom Bar */
        .bottom-bar {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: #fff;
            border-top: 3px solid #0d6efd;
            box-shadow: 0 -4px 12px rgba(0,0,0,0.15);
            padding: 12px 20px;
            z-index: 1000;
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .bottom-bar .info-section {
            display: flex;
            align-items: center;
            gap: 20px;
            flex: 1;
            flex-wrap: wrap;
        }
        
        .customer-display {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            background: #f8f9fa;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .customer-display:hover {
            background: #e9ecef;
        }
        
        .customer-display .label {
            font-size: 12px;
            color: #6c757d;
            font-weight: 600;
        }
        
        .customer-display .value {
            font-size: 14px;
            font-weight: 600;
            color: #212529;
        }
        
        .stat-item {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .stat-item .label {
            font-size: 12px;
            color: #6c757d;
            font-weight: 600;
        }
        
        .stat-item .value {
            font-size: 16px;
            font-weight: bold;
            color: #212529;
        }
        
        .stat-item.total .value {
            color: #198754;
            font-size: 20px;
        }
        
        .payment-modes-compact {
            display: flex;
            gap: 6px;
        }
        
        .payment-mode-btn {
            padding: 8px 12px;
            border: 2px solid #dee2e6;
            border-radius: 6px;
            background: white;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 13px;
            font-weight: 600;
            color: #6c757d;
        }
        
        .payment-mode-btn:hover {
            border-color: #0d6efd;
            color: #0d6efd;
        }
        
        .payment-mode-btn.active {
            background: #0d6efd;
            border-color: #0d6efd;
            color: white;
        }
        
        .save-sale-btn {
            padding: 10px 24px;
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border: none;
            border-radius: 8px;
            color: white;
            font-weight: 600;
            font-size: 15px;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 4px 8px rgba(40,167,69,0.3);
        }
        
        .save-sale-btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(40,167,69,0.4);
        }
        
        .save-sale-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        /* Sync Status Badge */
        .sync-status-badge {
            position: fixed;
            bottom: 90px;
            right: 20px;
            background: #ffc107;
            color: #000;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
            z-index: 999;
            display: none;
        }
        
        @media (max-width: 768px) {
            .product-grid {
                grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
                gap: 10px;
            }
            
            .product-block {
                padding: 15px 10px;
                min-height: 90px;
            }
            
            .product-block .name {
                font-size: 13px;
            }
            
            .bottom-bar {
                flex-direction: column;
                gap: 10px;
            }
            
            .bottom-bar .info-section {
                width: 100%;
                justify-content: space-between;
            }
        }
    </style>
</head>
<body>
    <!-- Connection Status Banner -->
    <div id="connectionStatus" class="connection-status online" style="display: none;">
        <i class="bi bi-wifi"></i> <span id="connectionText"><?php _e('online'); ?></span>
    </div>

    <!-- Sync Status Badge -->
    <div id="syncStatusBadge" class="sync-status-badge">
        <i class="bi bi-arrow-repeat"></i> <span id="syncStatusText"><?php _e('syncing'); ?></span>
    </div>

    <!-- Top Bar -->
    <div class="top-bar">
        <a href="sales_management.php">
            <i class="bi bi-arrow-left"></i> <?php _e('back_to_sales_list'); ?>
        </a>
        
        <div class="controls">
            <input type="date" id="saleDate" class="form-control form-control-sm" value="<?php echo $today; ?>" max="<?php echo $today; ?>">
            
            <select id="locationId" class="form-select form-select-sm" required>
                <option value=""><?php _e('select_location'); ?></option>
                <?= renderTreeOptions($userLocations, $selectedLocation) ?>
            </select>

            <select id="costCentreId" class="form-select form-select-sm">
                <option value=""><?php _e('cost_centre'); ?></option>
                <?= renderTreeOptions(getUserCostCentres(), null, null, 'name', 'code') ?>
            </select>

            <button class="btn btn-sm btn-outline-primary" onclick="rearrangeProducts()">
                <i class="bi bi-sort-down"></i> <?php _e('sort'); ?>
            </button>
            
            <button class="btn btn-sm btn-outline-danger" onclick="clearCart()">
                <i class="bi bi-trash"></i> <?php _e('clear'); ?>
            </button>
            
            <button class="btn btn-sm btn-outline-secondary" onclick="window.location.reload()">
                <i class="bi bi-arrow-clockwise"></i>
            </button>
        </div>
    </div>

    <!-- Products Grid -->
    <div class="products-section">
        <div class="product-grid" id="productGrid">
            <!-- Products will be rendered here -->
            <div class="product-block add-product-btn" onclick="showAddProductModal()">
                <i class="bi bi-plus-circle"></i>
                <span><?php _e('add_product'); ?></span>
            </div>
        </div>
    </div>

    <!-- Bottom Bar -->
    <div class="bottom-bar">
        <div class="info-section">
            <div class="customer-display" onclick="editCustomer()">
                <div>
                    <div class="label"><?php _e('customer_label'); ?></div>
                    <div class="value" id="customerName"><?php _e('cash'); ?></div>
                </div>
                <i class="bi bi-pencil"></i>
            </div>

            <div class="customer-display" id="toAccountDisplay" onclick="editToAccount()">
                <div>
                    <div class="label"><?php _e('to_account_label'); ?></div>
                    <div class="value" id="toAccountName"><?php echo !empty($accounts) ? htmlspecialchars($accounts[0]['name']) : __('select'); ?></div>
                </div>
                <i class="bi bi-pencil"></i>
            </div>

            <div class="stat-item">
                <div class="label"><?php _e('items_label'); ?>:</div>
                <div class="value" id="itemCount">0</div>
            </div>
            
            <div class="stat-item total">
                <div class="label"><?php _e('total_label'); ?>:</div>
                <div class="value" id="totalAmount"><?php echo $currencySymbol; ?> 0</div>
            </div>
        </div>
        
        <div class="payment-modes-compact">
            <div class="payment-mode-btn active" data-mode="cash" onclick="selectPaymentMode('cash')"><?php _e('cash'); ?></div>
            <div class="payment-mode-btn" data-mode="card" onclick="selectPaymentMode('card')"><?php _e('card'); ?></div>
            <div class="payment-mode-btn" data-mode="credit" onclick="selectPaymentMode('credit')"><?php _e('credit'); ?></div>
        </div>
        
        <button class="save-sale-btn" onclick="saveSale()" id="saveSaleBtn">
            <i class="bi bi-check-circle"></i> <?php _e('save_sale'); ?>
        </button>
    </div>

    <!-- Add Product Modal -->
    <div class="modal fade" id="addProductModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><?php _e('add_product'); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <input type="text" id="modalSearchProduct" class="form-control form-control-lg" 
                               placeholder="<?php _e('search_products_placeholder'); ?>" autocomplete="off">
                    </div>
                    
                    <div id="modalProductResults" class="list-group" style="max-height: 400px; overflow-y: auto;">
                        <!-- Search results will appear here -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quantity Input Modal -->
    <div class="modal fade" id="qtyInputModal" tabindex="-1">
        <div class="modal-dialog modal-sm modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h6 class="modal-title" id="qtyProductName">Set Quantity</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="input-group input-group-lg">
                        <button class="btn btn-outline-secondary" type="button" onclick="adjustQtyInput(-10)">-10</button>
                        <button class="btn btn-outline-secondary" type="button" onclick="adjustQtyInput(-1)">-</button>
                        <input type="number" id="qtyInputField" class="form-control text-center" min="0" value="1" style="min-width: 100px;">
                        <button class="btn btn-outline-secondary" type="button" onclick="adjustQtyInput(1)">+</button>
                        <button class="btn btn-outline-secondary" type="button" onclick="adjustQtyInput(10)">+10</button>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-danger btn-sm" onclick="removeProductFromCart()"><?php _e('remove'); ?></button>
                    <button type="button" class="btn btn-primary" onclick="saveQtyFromModal()"><?php _e('save'); ?></button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Customer Modal -->
    <div class="modal fade" id="editCustomerModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><?php _e('select_customer'); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <select id="customerSelect" class="form-select form-select-lg mb-3">
                        <?php foreach ($customers as $customer): ?>
                        <option value="<?php echo $customer['id']; ?>" <?php echo $customer['name'] == 'Cash' ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($customer['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <div class="alert alert-info">
                        <strong><?php _e('create_new_customer'); ?>:</strong><br>
                        <input type="text" id="newCustomerName" class="form-control mt-2" placeholder="<?php _e('enter_customer_name'); ?>">
                        <button class="btn btn-sm btn-primary mt-2" onclick="createNewCustomer()">
                            <i class="bi bi-plus-circle"></i> <?php _e('create'); ?>
                        </button>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                    <button type="button" class="btn btn-primary" onclick="saveCustomerSelection()"><?php _e('save'); ?></button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit To Account Modal -->
    <div class="modal fade" id="editToAccountModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><?php _e('select_to_account'); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <select id="toAccountSelect" class="form-select form-select-lg mb-3">
                        <?php foreach ($accounts as $account): ?>
                        <option value="<?php echo $account['id']; ?>">
                            <?php echo htmlspecialchars($account['name']); ?> (<?php echo ucfirst($account['account_type']); ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>

                    <div class="alert alert-info">
                        <strong><?php _e('create_new_account'); ?>:</strong><br>
                        <div class="mt-2">
                            <input type="text" id="newAccountName" class="form-control" placeholder="<?php _e('enter_account_name'); ?>">
                        </div>
                        <div class="mt-2">
                            <select id="newAccountType" class="form-select">
                                <option value="cash"><?php _e('cash_account'); ?></option>
                                <option value="bank"><?php _e('bank_account'); ?></option>
                            </select>
                        </div>
                        <button class="btn btn-sm btn-primary mt-2" onclick="createNewAccount()">
                            <i class="bi bi-plus-circle"></i> <?php _e('create'); ?>
                        </button>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                    <button type="button" class="btn btn-primary" onclick="saveToAccountSelection()"><?php _e('save'); ?></button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
	<script>
		 // Define JavaScript constants from PHP
        const BASE_URL = '<?php echo BASE_URL; ?>';
        const CURRENCY_SYMBOL = <?php echo json_encode($currencySymbol); ?>;
        
        // Language translations for JavaScript
        const LANG = {
            online: <?php echo json_encode(__('online')); ?>,
            offline_mode: <?php echo json_encode(__('offline_mode')); ?>,
            syncing: <?php echo json_encode(__('syncing')); ?>,
            changing_date_confirm: <?php echo json_encode(__('changing_date_confirm')); ?>,
            changing_location_confirm: <?php echo json_encode(__('changing_location_confirm')); ?>,
            no_products_available: <?php echo json_encode(__('no_products_available')); ?>,
            failed_load_products: <?php echo json_encode(__('failed_load_products')); ?>,
            please_select_date: <?php echo json_encode(__('please_select_date')); ?>,
            please_select_location: <?php echo json_encode(__('please_select_location')); ?>,
            please_add_items: <?php echo json_encode(__('please_add_items')); ?>,
            sale_saved_success: <?php echo json_encode(__('sale_saved_success')); ?>,
            error_saving_sale: <?php echo json_encode(__('error_saving_sale')); ?>,
            offline_sync_message: <?php echo json_encode(__('offline_sync_message')); ?>,
            sync_in_progress: <?php echo json_encode(__('sync_in_progress')); ?>,
            sync_completed: <?php echo json_encode(__('sync_completed')); ?>,
            sync_failed: <?php echo json_encode(__('sync_failed')); ?>,
            customer_created: <?php echo json_encode(__('customer_created')); ?>,
            customer_saved_offline: <?php echo json_encode(__('customer_saved_offline')); ?>,
            failed_create_customer: <?php echo json_encode(__('failed_create_customer')); ?>,
            enter_customer_name_alert: <?php echo json_encode(__('enter_customer_name_alert')); ?>,
            click_to_add: <?php echo json_encode(__('click_to_add')); ?>,
            saving_sale: <?php echo json_encode(__('saving_sale')); ?>,
            save_sale: <?php echo json_encode(__('save_sale')); ?>,
            add_product: <?php echo json_encode(__('add_product')); ?>,
            cash: <?php echo json_encode(__('cash')); ?>,
            cart_already_empty: <?php echo json_encode(__('cart_already_empty')); ?>,
            clear_cart_confirm: <?php echo json_encode(__('clear_cart_confirm')); ?>,
            no_products_rearrange: <?php echo json_encode(__('no_products_rearrange')); ?>,
            products_rearranged: <?php echo json_encode(__('products_rearranged')); ?>,
            type_to_search: <?php echo json_encode(__('type_to_search')); ?>,
            select_location_first: <?php echo json_encode(__('select_location_first')); ?>,
            no_products_found: <?php echo json_encode(__('no_products_found')); ?>,
            to_account_label: <?php echo json_encode(__('to_account_label')); ?>,
            select_to_account: <?php echo json_encode(__('select_to_account')); ?>,
            account_created: <?php echo json_encode(__('account_created')); ?>,
            account_saved_offline: <?php echo json_encode(__('account_saved_offline')); ?>,
            failed_create_account: <?php echo json_encode(__('failed_create_account')); ?>,
            enter_account_name_alert: <?php echo json_encode(__('enter_account_name_alert')); ?>,
            to_account_required: <?php echo json_encode(__('to_account_required')); ?>
        };
	</script>
    <script src="<?php echo BASE_URL; ?>assets/js/offline-db.js?_t=<?=time();?>"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/sync-manager.js?_t=<?=time();?>""></script>
    <script>
        console.log('=== SALES POS PAGE LOADED - VERSION 2026-01-02 ===');
        
        // State
        let currentCart = {};
        let savedProducts = [];
        let allProducts = []; // All products from DB for search
        let currentPaymentMode = 'cash';
        let currentCustomerId = 1; // Default to Cash customer
        let currentCustomerName = 'Cash';
        let currentToAccountId = <?php echo !empty($accounts) ? $accounts[0]['id'] : 'null'; ?>; // Default to first cash/bank account
        let currentToAccountName = <?php echo !empty($accounts) ? json_encode($accounts[0]['name']) : '""'; ?>;
        let addProductModal, editCustomerModal, qtyInputModal, editToAccountModal;
        let editingProductId = null;
        let isOnline = navigator.onLine;
        let isSyncing = false;
        
        // Initialize
        document.addEventListener('DOMContentLoaded', async function() {
            console.log('DOM loaded, initializing...');
    
			// Initialize modals
			addProductModal = new bootstrap.Modal(document.getElementById('addProductModal'));
			editCustomerModal = new bootstrap.Modal(document.getElementById('editCustomerModal'));
			qtyInputModal = new bootstrap.Modal(document.getElementById('qtyInputModal'));
			editToAccountModal = new bootstrap.Modal(document.getElementById('editToAccountModal'));
			
			// Initialize sync manager and offline DB
			await offlineDB.init();
			await syncManager.init();
			
			// Auto-select location (last selected from cookie, or first option)
			autoSelectLocation();
			
			// Load data
			await loadProducts();
			await loadAccounts(); // Load cash/bank accounts for receipts
			await loadSavedProductsFromLocalStorage();

			// Set initial To Account visibility based on payment mode
			updateToAccountVisibility();
			
			// Check online status
			updateConnectionStatus();
			window.addEventListener('online', () => {
				isOnline = true;
				updateConnectionStatus();
				syncData();
			});
			window.addEventListener('offline', () => {
				isOnline = false;
				updateConnectionStatus();
			});
			
            
            // Date change - clear everything
			document.getElementById('saleDate').addEventListener('change', function() {
				if (confirm(LANG.changing_date_confirm)) {
					clearAllProducts();
					loadSavedProductsFromLocalStorage();
				}
			});
            
            // Location change - reload products with new prices and clear cart
			document.getElementById('locationId').addEventListener('change', async function() {
				if (!this.value) return;
				
				if (Object.keys(currentCart).length > 0) {
					if (!confirm(LANG.changing_location_confirm)) {
						// Revert to previous location
						const savedLocation = getCookie('sales_location');
						if (savedLocation) {
							this.value = savedLocation;
						}
						return;
					}
				}
				
				// Save to cookie
				document.cookie = `sales_location=${this.value}; path=/; max-age=${365*24*60*60}`;
				
				// Clear cart
				currentCart = {};
				updateCart();
				
				// Reload products with new prices
				console.log('📍 Location changed, reloading products with new prices...');
				await loadProducts();
				
				// Re-render product grid
				renderProducts();
				
				console.log('✅ Products reloaded for new location');
			});
            
            // Search in modal
            document.getElementById('modalSearchProduct').addEventListener('input', function() {
                searchProducts(this.value);
            });
            
            // Auto-focus on modal open
            document.getElementById('addProductModal').addEventListener('shown.bs.modal', function() {
                document.getElementById('modalSearchProduct').focus();
            });

            // Enter key to save quantity in modal
            document.getElementById('qtyInputField').addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    saveQtyFromModal();
                }
            });
            
            console.log('Initialization complete');
        });
        
        // Update connection status banner
        function updateConnectionStatus() {
            const banner = document.getElementById('connectionStatus');
            const text = document.getElementById('connectionText');
            
            if (isOnline) {
                banner.classList.remove('offline');
                banner.classList.add('online');
                text.innerHTML = '<i class="bi bi-wifi"></i> ' + LANG.online;
            } else {
                banner.classList.remove('online');
                banner.classList.add('offline');
                text.innerHTML = '<i class="bi bi-wifi-off"></i> ' + LANG.offline_mode;
            }
            
            banner.style.display = 'block';
            setTimeout(() => {
                banner.style.display = 'none';
            }, 3000);
        }
        
        // Load products with pricing - Always fetch from server if online
        async function loadProducts() {
            console.log('🔄 Loading products...');
            
            try {
                let products = [];
                
                // PRIORITY: Fetch from server if online
                if (isOnline) {
                    console.log('🌐 Online - Fetching from server...');
                    try {
                        const response = await fetch(BASE_URL + 'api/products-list.php?_=' + Date.now());
                        if (response.ok) {
                            products = await response.json();
                            console.log(`✅ Fetched ${products.length} products from server`);
                            
                            // Cache them for offline use
                            await offlineDB.replaceAll('products', products);
                            console.log('💾 Products cached for offline use');
                        } else {
                            throw new Error('Server returned ' + response.status);
                        }
                    } catch (error) {
                        console.error('❌ Server fetch failed:', error);
                        console.log('📦 Falling back to cache...');
                        products = await offlineDB.getAll('products');
                    }
                } else {
                    // Offline - use cache
                    console.log('📴 Offline - Loading from cache...');
                    products = await offlineDB.getAll('products');
                }
                
                if (products.length === 0) {
                    alert(LANG.no_products_available);
                    return;
                }
                
                console.log(`📊 Processing ${products.length} products...`);
                
                // Get current location
                const locationId = document.getElementById('locationId').value;
                console.log('📍 Current location ID:', locationId);
                
                // Enrich products with pricing for POS
                allProducts = products.map(product => {
                    // Start with default price
                    let price = parseFloat(product.default_selling_price || 0);
                    
                    // Try to use location-specific pricing if available
                    if (locationId && product.pricing && Array.isArray(product.pricing) && product.pricing.length > 0) {
                        const locationPrice = product.pricing.find(p => p.location_id == locationId);
                        if (locationPrice && locationPrice.selling_price) {
                            price = parseFloat(locationPrice.selling_price);
                            console.log(`💰 ${product.name}: Using location price ${price}`);
                        } else {
                            console.log(`💰 ${product.name}: No location price, using default ${price}`);
                        }
                    } else {
                        console.log(`💰 ${product.name}: No pricing array, using default ${price}`);
                    }
                    
                    return {
                        id: product.id,
                        name: product.name,
                        category: product.category_name || '',
                        category_name: product.category_name || '',
                        unit: product.unit || '',
                        price: price,
                        is_active: product.is_active,
                        pricing: product.pricing || [],
                        default_selling_price: product.default_selling_price
                    };
                });
                
                console.log(`✅ Loaded ${allProducts.length} products for POS`);
                console.log('Sample product:', allProducts[0]);
            } catch (error) {
                console.error('❌ Error loading products:', error);
                alert(LANG.failed_load_products + ': ' + error.message);
            }
        }
        
        // Load accounts (cash/bank) for offline use
        async function loadAccounts() {
            console.log('🔄 Loading accounts...');

            try {
                if (isOnline) {
                    console.log('🌐 Online - Fetching accounts from server...');
                    try {
                        await syncManager.fetchAccountsFromServer();
                        console.log('✅ Accounts cached for offline use');
                    } catch (error) {
                        console.error('❌ Failed to fetch accounts:', error);
                    }
                }

                // Update the To Account dropdown with cached accounts
                const accounts = await offlineDB.getAll('accounts');
                if (accounts.length > 0) {
                    const select = document.getElementById('toAccountSelect');
                    select.innerHTML = '';
                    accounts.filter(a => a.is_active == 1).forEach(account => {
                        const option = document.createElement('option');
                        option.value = account.id;
                        option.text = account.name + ' (' + (account.account_type === 'cash' ? 'Cash' : 'Bank') + ')';
                        select.appendChild(option);
                    });

                    // Set default to first account if not already set
                    if (!currentToAccountId && accounts.length > 0) {
                        currentToAccountId = accounts[0].id;
                        currentToAccountName = accounts[0].name;
                        document.getElementById('toAccountName').textContent = currentToAccountName;
                    }
                }

                console.log(`✅ Loaded ${accounts.length} accounts`);
            } catch (error) {
                console.error('❌ Error loading accounts:', error);
            }
        }

        // Load saved products for current date from localStorage
        function loadSavedProductsFromLocalStorage() {
            const date = document.getElementById('saleDate').value;
            const storageKey = 'sales_products_' + date;
            const saved = localStorage.getItem(storageKey);
            
            if (saved) {
                try {
                    savedProducts = JSON.parse(saved);
                    console.log(`Loaded ${savedProducts.length} saved products for ${date}`);
                } catch (error) {
                    console.error('Error parsing saved products:', error);
                    savedProducts = [];
                }
            } else {
                savedProducts = [];
            }
            
            renderProducts();
        }
        
        // Save products to localStorage
        function saveProductsToLocalStorage() {
            const date = document.getElementById('saleDate').value;
            const storageKey = 'sales_products_' + date;
            localStorage.setItem(storageKey, JSON.stringify(savedProducts));
        }
        
        // Clear all products for current date
        function clearAllProducts() {
            const date = document.getElementById('saleDate').value;
            const storageKey = 'sales_products_' + date;
            localStorage.removeItem(storageKey);
            savedProducts = [];
            currentCart = {};
            renderProducts();
            updateCart();
        }
        
        // Render products in grid
        function renderProducts() {
            const grid = document.getElementById('productGrid');
            
            // Clear grid except keep structure
            grid.innerHTML = '';
            
            // Render saved products
            savedProducts.forEach(product => {
                const block = createProductBlock(product);
                grid.appendChild(block);
            });
            
            // Add "Add Product" button at end
            const addBtn = document.createElement('div');
            addBtn.className = 'product-block add-product-btn';
            addBtn.onclick = showAddProductModal;
            addBtn.innerHTML = `
                <i class="bi bi-plus-circle"></i>
                <span>${LANG.add_product}</span>
            `;
            grid.appendChild(addBtn);
        }
        
        // Create product block element
        function createProductBlock(product) {
            const div = document.createElement('div');
            div.className = 'product-block';
            div.dataset.productId = product.id;
            
            const qty = currentCart[product.id] || 0;
            if (qty > 0) {
                div.classList.add('in-cart');
            }
            
            div.innerHTML = `
                ${qty > 0 ? '<div class="minus-btn" onclick="event.stopPropagation(); removeFromCart(' + product.id + ')">−</div>' : ''}
                <div class="name">${escapeHtml(product.name)}</div>
                <div class="price">${CURRENCY_SYMBOL} ${parseFloat(product.price).toFixed(2)}</div>
                ${qty > 0 ? `<div class="qty-badge" onclick="event.stopPropagation(); openQtyModal(${product.id}, '${escapeHtml(product.name).replace(/'/g, "\\'")}', ${qty})" style="cursor: pointer;" title="Click to edit quantity">${qty}</div>` : ''}
            `;
            
            div.onclick = () => addToCart(product);
            
            return div;
        }
        
        // Add product to cart
        function addToCart(product) {
            if (!currentCart[product.id]) {
                currentCart[product.id] = 0;
            }
            currentCart[product.id]++;
            
            renderProducts();
            updateCart();
        }
        
        // Remove one quantity from cart
        function removeFromCart(productId) {
            if (currentCart[productId]) {
                currentCart[productId]--;
                if (currentCart[productId] <= 0) {
                    delete currentCart[productId];
                }
                renderProducts();
                updateCart();
            }
        }

        // Open quantity input modal
        function openQtyModal(productId, productName, currentQty) {
            editingProductId = productId;
            document.getElementById('qtyProductName').textContent = productName;
            document.getElementById('qtyInputField').value = currentQty;
            qtyInputModal.show();

            // Focus and select the input field
            setTimeout(() => {
                const input = document.getElementById('qtyInputField');
                input.focus();
                input.select();
            }, 300);
        }

        // Adjust quantity in the input field
        function adjustQtyInput(delta) {
            const input = document.getElementById('qtyInputField');
            let newVal = parseInt(input.value) + delta;
            if (newVal < 0) newVal = 0;
            input.value = newVal;
        }

        // Save quantity from modal
        function saveQtyFromModal() {
            const qty = parseInt(document.getElementById('qtyInputField').value);

            if (qty <= 0) {
                delete currentCart[editingProductId];
            } else {
                currentCart[editingProductId] = qty;
            }

            qtyInputModal.hide();
            renderProducts();
            updateCart();
            editingProductId = null;
        }

        // Remove product from cart entirely (from modal)
        function removeProductFromCart() {
            delete currentCart[editingProductId];
            qtyInputModal.hide();
            renderProducts();
            updateCart();
            editingProductId = null;
        }
        
        // Update cart totals
        function updateCart() {
            let total = 0;
            let itemCount = 0;
            
            savedProducts.forEach(product => {
                const qty = currentCart[product.id] || 0;
                if (qty > 0) {
                    total += qty * parseFloat(product.price);
                    itemCount += qty;
                }
            });
            
            document.getElementById('totalAmount').textContent = CURRENCY_SYMBOL + ' ' + total.toFixed(0);
            document.getElementById('itemCount').textContent = itemCount;
        }
        
        // Clear cart
        function clearCart() {
            if (Object.keys(currentCart).length === 0) {
                alert(LANG.cart_already_empty);
                return;
            }
            
            if (confirm(LANG.clear_cart_confirm)) {
                currentCart = {};
                renderProducts();
                updateCart();
            }
        }
        
        // Rearrange products by quantity sold
        function rearrangeProducts() {
            if (savedProducts.length === 0) {
                alert(LANG.no_products_rearrange);
                return;
            }
            
            // Sort by quantity in cart (descending)
            savedProducts.sort((a, b) => {
                const qtyA = currentCart[a.id] || 0;
                const qtyB = currentCart[b.id] || 0;
                return qtyB - qtyA;
            });
            
            saveProductsToLocalStorage();
            renderProducts();
            
            alert(LANG.products_rearranged);
        }
        
        // Show add product modal
        function showAddProductModal() {
            document.getElementById('modalSearchProduct').value = '';
            document.getElementById('modalProductResults').innerHTML = '';
            addProductModal.show();
        }
        
        // Search products in modal
        function searchProducts(query) {
            const results = document.getElementById('modalProductResults');
            
            if (!query || query.length < 2) {
                results.innerHTML = '<div class="p-3 text-muted">' + LANG.type_to_search + '</div>';
                return;
            }
            
            // Get location
            const locationId = document.getElementById('locationId').value;
            if (!locationId) {
                results.innerHTML = '<div class="p-3 text-danger">' + LANG.select_location_first + '</div>';
                return;
            }
            
            // Filter products
            const filtered = allProducts.filter(product => {
                return product.name.toLowerCase().includes(query.toLowerCase()) && 
                       product.is_active == 1;
            });
            
            if (filtered.length === 0) {
                results.innerHTML = '<div class="p-3 text-muted">' + LANG.no_products_found + '</div>';
                return;
            }
            
            // Render results
            results.innerHTML = '';
            filtered.forEach(product => {
                // Get price for this location
                const price = getProductPrice(product, locationId);
                
                const item = document.createElement('a');
                item.href = '#';
                item.className = 'list-group-item list-group-item-action';
                item.innerHTML = `
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <strong>${escapeHtml(product.name)}</strong>
                            <div class="small text-muted">${escapeHtml(product.category_name || '')} - ${escapeHtml(product.unit)}</div>
                        </div>
                        <div class="text-end">
                            <strong>${CURRENCY_SYMBOL} ${price.toFixed(2)}</strong>
                        </div>
                    </div>
                `;
                
                item.onclick = (e) => {
                    e.preventDefault();
                    addProductToSaved(product, price);
                };
                
                results.appendChild(item);
            });
        }
        
        // Get product price for location
        function getProductPrice(product, locationId) {
			// Check location-specific pricing first
			if (product.pricing && Array.isArray(product.pricing) && product.pricing.length > 0) {
				const locationPricing = product.pricing.find(p => p.location_id == locationId);
				if (locationPricing && locationPricing.selling_price) {
					return parseFloat(locationPricing.selling_price);
				}
			}
			
			// Fallback to default price
			return parseFloat(product.default_selling_price || 0);
		}
        
        // Add product to saved products
        function addProductToSaved(product, price) {
            // Check if already in saved
            const exists = savedProducts.find(p => p.id === product.id);
            if (exists) {
                // Product already on screen, just add to cart
				addToCart(exists);
				addProductModal.hide();
				return;
            }
            
            // Add to saved products
            savedProducts.push({
                id: product.id,
                name: product.name,
                price: price,
                unit: product.unit
            });
            
            saveProductsToLocalStorage();
            renderProducts();
            
            // Auto-add to cart (1 quantity)
			addToCart(savedProducts[savedProducts.length - 1]);

			addProductModal.hide();
        }
        
        // Payment mode selection
        function selectPaymentMode(mode) {
            currentPaymentMode = mode;
            document.querySelectorAll('.payment-mode-btn').forEach(el => {
                el.classList.remove('active');
            });
            document.querySelector(`[data-mode="${mode}"]`).classList.add('active');

            // Show/hide To Account based on payment mode
            updateToAccountVisibility();
        }
        
        // Edit customer
        function editCustomer() {
            editCustomerModal.show();
        }
        
        // Save customer selection
        function saveCustomerSelection() {
            const select = document.getElementById('customerSelect');
            const selectedOption = select.options[select.selectedIndex];
            currentCustomerId = select.value;
            currentCustomerName = selectedOption.text;
            document.getElementById('customerName').textContent = currentCustomerName;
            editCustomerModal.hide();
        }
        
        // Create new customer
        async function createNewCustomer() {
            const name = document.getElementById('newCustomerName').value.trim();
            if (!name) {
                alert(LANG.enter_customer_name_alert);
                return;
            }
            
            try {
                // Use sync manager to create customer (works offline)
                const result = await syncManager.createCustomer({ name: name });
                
                if (result.success) {
                    // Add to select dropdown
                    const select = document.getElementById('customerSelect');
                    const option = document.createElement('option');
                    option.value = result.serverId || result.tempId;
                    option.text = name;
                    option.selected = true;
                    select.appendChild(option);
                    
                    currentCustomerId = result.serverId || result.tempId;
                    currentCustomerName = name;
                    document.getElementById('customerName').textContent = name;
                    document.getElementById('newCustomerName').value = '';
                    
                    const message = result.serverId ? 
                        LANG.customer_created : 
                        LANG.customer_saved_offline;
                    alert(message);
                }
            } catch (error) {
                console.error('Error creating customer:', error);
                alert(LANG.failed_create_customer + ': ' + error.message);
            }
        }
        
        // Edit To Account
        function editToAccount() {
            editToAccountModal.show();
        }

        // Save To Account selection
        function saveToAccountSelection() {
            const select = document.getElementById('toAccountSelect');
            const selectedOption = select.options[select.selectedIndex];
            currentToAccountId = select.value;
            currentToAccountName = selectedOption.text.split(' (')[0]; // Remove the (Cash)/(Bank) suffix
            document.getElementById('toAccountName').textContent = currentToAccountName;
            editToAccountModal.hide();
        }

        // Create new account
        async function createNewAccount() {
            const name = document.getElementById('newAccountName').value.trim();
            const accountType = document.getElementById('newAccountType').value;

            if (!name) {
                alert(LANG.enter_account_name_alert);
                return;
            }

            try {
                // Use sync manager to create account (works offline)
                const result = await syncManager.createAccount({ name: name, account_type: accountType });

                if (result.success) {
                    // Add to select dropdown
                    const select = document.getElementById('toAccountSelect');
                    const option = document.createElement('option');
                    option.value = result.serverId || result.tempId;
                    option.text = name + ' (' + (accountType === 'cash' ? 'Cash' : 'Bank') + ')';
                    option.selected = true;
                    select.appendChild(option);

                    currentToAccountId = result.serverId || result.tempId;
                    currentToAccountName = name;
                    document.getElementById('toAccountName').textContent = name;
                    document.getElementById('newAccountName').value = '';

                    const message = result.serverId ?
                        LANG.account_created :
                        LANG.account_saved_offline;
                    alert(message);
                }
            } catch (error) {
                console.error('Error creating account:', error);
                alert(LANG.failed_create_account + ': ' + error.message);
            }
        }

        // Show/hide To Account based on payment mode
        function updateToAccountVisibility() {
            const toAccountDisplay = document.getElementById('toAccountDisplay');
            if (currentPaymentMode === 'credit') {
                toAccountDisplay.style.display = 'none';
            } else {
                toAccountDisplay.style.display = 'flex';
            }
        }

        // Save sale
		async function saveSale() {
			console.log('Save sale clicked');
			
			// Validate
			const date = document.getElementById('saleDate').value;
			const locationId = document.getElementById('locationId').value;
			
			if (!date) {
				alert(LANG.please_select_date);
				return;
			}
			
			if (!locationId) {
				alert(LANG.please_select_location);
				return;
			}
			
			// Check cart
			const items = Object.keys(currentCart).filter(id => currentCart[id] > 0);
			if (items.length === 0) {
				alert(LANG.please_add_items);
				return;
			}

			// For non-credit sales, validate To Account is selected
			if (currentPaymentMode !== 'credit' && !currentToAccountId) {
				alert(LANG.to_account_required);
				return;
			}
			
			// Build sale details
			const details = [];
			
			savedProducts.forEach(product => {
				const qty = currentCart[product.id];
				if (qty && qty > 0) {
					details.push({
						product_id: product.id,
						quantity: qty,
						rate_per_unit: parseFloat(product.price)
					});
				}
			});
			
			// Create sale object for sync manager
			const costCentreId = document.getElementById('costCentreId').value;
			const saleData = {
				sale_date: date,
				customer_id: parseInt(currentCustomerId),
				print_name: currentCustomerName, // Add customer name for receipts
				location_id: parseInt(locationId),
				cost_centre_id: costCentreId ? parseInt(costCentreId) : null,
				payment_mode: currentPaymentMode,
				to_account_id: currentPaymentMode !== 'credit' ? currentToAccountId : null, // Cash/Bank account for receipt
				remarks: '',
				details: details
			};
			
			console.log('Sale data:', saleData);
			
			// Disable save button
			const btn = document.getElementById('saveSaleBtn');
			btn.disabled = true;
			btn.innerHTML = '<i class="bi bi-hourglass-split"></i> ' + LANG.saving_sale;
			
			try {
				// Use sync manager to create sale (handles online/offline AND prevents duplicates)
				const result = await syncManager.createSale(saleData);
				
				if (result.success) {
					// Success
					alert(LANG.sale_saved_success);
					
					// Clear cart
					currentCart = {};
					updateCart();
					renderProducts();
					
					// Redirect to same page to continue selling
					setTimeout(() => {
						window.location.reload();
					}, 500);
				}
				
			} catch (error) {
				console.error('Error saving sale:', error);
				alert(LANG.error_saving_sale + ': ' + error.message);
				
				// Re-enable button
				btn.disabled = false;
				btn.innerHTML = '<i class="bi bi-check-circle"></i> ' + LANG.save_sale;
			}
		}
        
        // Trigger sync (now handled by sync manager)
		async function syncData() {
			if (!syncManager.isOnline) {
				alert(LANG.offline_sync_message);
				return;
			}
			
			if (syncManager.isSyncing) {
				alert(LANG.sync_in_progress);
				return;
			}
			
			console.log('Manual sync triggered...');
			try {
				await syncManager.sync();
				alert(LANG.sync_completed);
			} catch (error) {
				console.error('Sync error:', error);
				alert(LANG.sync_failed + ': ' + error.message);
			}
		}
		
		// Show sync status
        function showSyncStatus(text) {
            const badge = document.getElementById('syncStatusBadge');
            document.getElementById('syncStatusText').textContent = text;
            badge.style.display = 'block';
        }
        
        // Hide sync status
        function hideSyncStatus() {
            setTimeout(() => {
                document.getElementById('syncStatusBadge').style.display = 'none';
            }, 2000);
        }
        
		// Auto-select location from cookie or first option
		function autoSelectLocation() {
			const locationSelect = document.getElementById('locationId');
			
			// Try to get last selected location from cookie
			const savedLocation = getCookie('sales_location');
			
			if (savedLocation) {
				// Check if saved location still exists in dropdown
				const optionExists = Array.from(locationSelect.options).some(opt => opt.value == savedLocation);
				if (optionExists) {
					locationSelect.value = savedLocation;
					console.log('📍 Auto-selected location from cookie:', savedLocation);
					return;
				}
			}
			
			// Fallback: Select first non-empty option
			if (locationSelect.options.length > 1) {
				for (let i = 1; i < locationSelect.options.length; i++) {
					if (locationSelect.options[i].value) {
						locationSelect.value = locationSelect.options[i].value;
						// Save to cookie
						document.cookie = `sales_location=${locationSelect.options[i].value}; path=/; max-age=${365*24*60*60}`;
						console.log('📍 Auto-selected first location:', locationSelect.options[i].value);
						return;
					}
				}
			}
			
			console.log('⚠️ No location available to auto-select');
		}

		// Get cookie value
		function getCookie(name) {
			const value = `; ${document.cookie}`;
			const parts = value.split(`; ${name}=`);
			if (parts.length === 2) return parts.pop().split(';').shift();
			return null;
		}

        // Utility: Escape HTML
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
		
		
    </script>
</body>
</html>
