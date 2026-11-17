<?php
// Prevent browser caching of authenticated pages
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");

if (!isLoggedIn()) {
    redirect('modules/auth/login.php');
}

// Language switch is now handled in functions.php (before any translations are used)

// Include subscription functions if not already included
if (!function_exists('getSubscriptionStatus')) {
    require_once __DIR__ . '/subscription.php';
}

// Demo mode: Auto-extend subscription to prevent expiry
if (DEMO_MODE) {
    $demoSubscription = getSubscription($conn);
    if ($demoSubscription) {
        $daysRemaining = (int)(new DateTime())->diff(new DateTime($demoSubscription['end_date']))->format('%R%a');
        // If less than 7 days remaining, extend by 30 days
        if ($daysRemaining < 7) {
            $newEndDate = date('Y-m-d', strtotime('+30 days'));
            $conn->prepare("UPDATE subscriptions SET end_date = ? WHERE id = ?")->execute([$newEndDate, $demoSubscription['id']]);
        }
    }
}

// Check subscription status
$subscriptionStatus = getSubscriptionStatus($conn);

// If blocked, redirect to subscription page (unless already there)
$currentPage = $_SERVER['REQUEST_URI'] ?? '';
if ($subscriptionStatus['blocked'] && strpos($currentPage, 'subscription') === false) {
    header('Location: ' . BASE_URL . 'modules/subscription/status.php?blocked=1');
    exit;
}

$userName = getUserData('name');
$userRole = getUserData('role_name');

// Get default currency for JS globals
$defaultCurrency = getDefaultCurrency();
$defaultCurrencySymbol = $defaultCurrency ? $defaultCurrency['symbol'] : 'TZS';
$defaultCurrencyId = $defaultCurrency ? $defaultCurrency['id'] : 1;
$defaultCurrencyDecimals = $defaultCurrency ? (int)$defaultCurrency['decimal_places'] : 0;
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo $pageTitle ?? APP_NAME; ?></title>
    
    <!-- PWA Meta Tags -->
    <meta name="description" content="Offline-first inventory management system">
    <meta name="theme-color" content="#0d6efd">
    <link rel="manifest" href="<?php echo BASE_URL; ?>manifest.json">
    
    <!-- Apple Touch Icon -->
    <link rel="apple-touch-icon" href="<?php echo BASE_URL; ?>assets/images/icon-192x192.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/style.css">
    
    <?php if (isset($customCSS)): ?>
        <link rel="stylesheet" href="<?php echo BASE_URL . $customCSS; ?>">
    <?php endif; ?>

    <!-- Set BASE_URL and default currency for JavaScript -->
    <script>
        const BASE_URL = '<?php echo BASE_URL; ?>';
        window.DEFAULT_CURRENCY_SYMBOL = '<?php echo addslashes($defaultCurrencySymbol); ?>';
        window.DEFAULT_CURRENCY_ID = <?php echo (int)$defaultCurrencyId; ?>;
        window.DEFAULT_CURRENCY_DECIMALS = <?php echo (int)$defaultCurrencyDecimals; ?>;
    </script>
</head>
<body>
    <?php if (DEMO_MODE): ?>
    <!-- Demo Banner -->
    <div class="demo-banner" style="background: linear-gradient(90deg, #ff6b35, #f7931e); color: white; padding: 8px 15px; text-align: center; font-size: 14px; position: relative; z-index: 1050;">
        <i class="bi bi-info-circle me-2"></i>
        <strong><?php _e('demo_mode'); ?>:</strong> <?php _e('demo_message'); ?>
        <a href="https://fis.co.tz/#trial" target="_blank" class="btn btn-light btn-sm ms-3" style="font-size: 12px; padding: 3px 12px;">
            <i class="bi bi-cart-check me-1"></i> <?php _e('buy_now'); ?>
        </a>
    </div>
    <?php endif; ?>
    
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary sticky-top">
        <div class="container-fluid">
            <a class="navbar-brand" href="<?php echo BASE_URL; ?>">
                <i class="bi bi-box-seam"></i> <?php echo APP_NAME; ?>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <!-- Dashboard -->
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>">
                            <i class="bi bi-house"></i> <?php _e('dashboard'); ?>
                        </a>
                    </li>
                    
                    <!-- Masters Dropdown -->
                    <?php if (hasPermission('roles', 'view') || hasPermission('locations', 'view') ||
                              hasPermission('categories', 'view') || hasPermission('products', 'view') ||
                              hasPermission('units', 'view') || hasPermission('suppliers', 'view') ||
                              hasPermission('customers', 'view') || hasPermission('users', 'view') ||
                              hasPermission('cost_centres', 'view') || hasPermission('currencies', 'view') ||
                              hasPermission('exchange_rates', 'view') || hasPermission('ledgers', 'view')): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-folder"></i> <?php _e('masters'); ?>
                        </a>
                        <ul class="dropdown-menu">
                            <?php if (hasPermission('roles', 'view')): ?>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/masters/roles.php">
                                <i class="bi bi-shield"></i> <?php _e('roles'); ?>
                            </a></li>
                            <?php endif; ?>
                            
                            <?php if (hasPermission('locations', 'view')): ?>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/masters/locations.php">
                                <i class="bi bi-geo-alt"></i> <?php _e('locations'); ?>
                            </a></li>
                            <?php endif; ?>
                            
                            <?php if (hasPermission('categories', 'view')): ?>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/masters/categories.php">
                                <i class="bi bi-tags"></i> <?php _e('categories'); ?>
                            </a></li>
                            <?php endif; ?>
                            
                            <?php if (hasPermission('products', 'view')): ?>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/masters/products.php">
                                <i class="bi bi-box"></i> <?php _e('products'); ?>
                            </a></li>
                            <?php endif; ?>
                            
                            <?php if (hasPermission('units', 'view')): ?>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/masters/units.php">
                                <i class="bi bi-rulers"></i> <?php _e('units'); ?>
                            </a></li>
                            <?php endif; ?>
                            
                            <?php if (hasPermission('products', 'view')): ?>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/masters/product_pricing.php">
                                <i class="bi bi-tag"></i> <?php _e('product_pricing'); ?>
                            </a></li>
                            <?php endif; ?>
                            
                            <?php if (hasPermission('suppliers', 'view')): ?>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/masters/suppliers.php">
                                <i class="bi bi-truck"></i> <?php _e('suppliers'); ?>
                            </a></li>
                            <?php endif; ?>
                            
                            <?php if (hasPermission('customers', 'view')): ?>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/masters/customers.php">
                                <i class="bi bi-people"></i> <?php _e('customers'); ?>
                            </a></li>
                            <?php endif; ?>

                            <?php if (hasPermission('ledgers', 'view')): ?>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/masters/ledgers.php">
                                <i class="bi bi-journal-bookmark"></i> <?php _e('ledgers'); ?>
                            </a></li>
                            <?php endif; ?>

                            <?php if (hasPermission('cost_centres', 'view')): ?>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/masters/cost_centres.php">
                                <i class="bi bi-building"></i> <?php _e('cost_centres'); ?>
                            </a></li>
                            <?php endif; ?>

                            <?php if (hasPermission('currencies', 'view')): ?>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/masters/currencies.php">
                                <i class="bi bi-currency-exchange"></i> <?php _e('currencies'); ?>
                            </a></li>
                            <?php endif; ?>

                            <?php if (hasPermission('exchange_rates', 'view')): ?>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/masters/exchange_rates.php">
                                <i class="bi bi-graph-up-arrow"></i> <?php _e('exchange_rates'); ?>
                            </a></li>
                            <?php endif; ?>

                            <li><hr class="dropdown-divider"></li>
                            
                            <?php if (hasPermission('opening_stocks', 'view')): ?>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/masters/opening_stocks.php">
                                <i class="bi bi-clipboard-data"></i> <?php _e('opening_stocks'); ?>
                            </a></li>
                            <?php endif; ?>
                            
                            <?php if (hasPermission('users', 'view')): ?>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/masters/users.php">
                                <i class="bi bi-person-badge"></i> <?php _e('users'); ?>
                            </a></li>
                            <?php endif; ?>

                            <?php if (getUserData('role_name') === 'Admin'): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/admin/company_settings.php">
                                <i class="bi bi-building-gear"></i> <?php _e('company_settings'); ?>
                            </a></li>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/admin/smtp_settings.php">
                                <i class="bi bi-envelope-gear"></i> SMTP &amp; Email Settings
                            </a></li>
                            <?php endif; ?>
                        </ul>
                    </li>
                    <?php endif; ?>
                    
                    <!-- Transactions Dropdown -->
                    <?php if (hasPermission('purchases', 'view') || hasPermission('purchase_orders', 'view') || hasPermission('purchase_imports', 'view') || hasPermission('sales', 'view') || hasPermission('stock_transfer', 'view') || hasPermission('consumption_vouchers', 'view') || hasPermission('requisition_vouchers', 'view') || hasPermission('boq', 'view') || hasPermission('bom', 'view') || hasPermission('budget', 'view')): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-arrow-left-right"></i> <?php _e('transactions'); ?>
                        </a>
                        <ul class="dropdown-menu">
                            <?php if (hasPermission('purchases', 'view')): ?>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/transactions/purchases.php">
                                <i class="bi bi-cart-plus"></i> <?php _e('purchases'); ?>
                            </a></li>
                            <?php endif; ?>

                            <?php if (hasPermission('purchase_orders', 'view')): ?>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/transactions/purchase_order_list.php">
                                <i class="bi bi-file-earmark-text"></i> <?php _e('purchase_orders'); ?>
                            </a></li>
                            <?php endif; ?>

                            <?php if (hasPermission('purchase_imports', 'view')): ?>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/transactions/purchase_imports.php">
                                <i class="bi bi-globe2"></i> <?php _e('purchase_imports'); ?>
                            </a></li>
                            <?php endif; ?>

                             <?php if (hasPermission('sales', 'view')): ?>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/transactions/sales_management.php">
                                <i class="bi bi-cart-check"></i> <?php _e('sales_list'); ?>
                            </a></li>
                            <?php endif; ?>

                            <?php if (hasPermission('stock_transfer', 'view')): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/transactions/transfers_management.php">
                                <i class="bi bi-arrow-left-right"></i> <?php _e('stock_transfers'); ?>
                            </a></li>
                            <?php endif; ?>

                            <?php if (hasPermission('consumption_vouchers', 'view')): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/transactions/consumption_voucher_list.php">
                                <i class="bi bi-clipboard-minus"></i> <?php _e('consumption_vouchers'); ?>
                            </a></li>
                            <?php endif; ?>

                            <?php if (hasPermission('consumption_vouchers', 'create')): ?>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/transactions/consumption_voucher.php">
                                <i class="bi bi-plus-circle"></i> <?php _e('new_consumption_voucher'); ?>
                            </a></li>
                            <?php endif; ?>

                            <?php if (hasPermission('boq', 'view')): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/transactions/boq_list.php">
                                <i class="bi bi-rulers"></i> <?php _e('boq'); ?>
                            </a></li>
                            <?php endif; ?>

                            <?php if (hasPermission('boq', 'create')): ?>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/transactions/boq.php">
                                <i class="bi bi-plus-circle"></i> <?php _e('new_boq'); ?>
                            </a></li>
                            <?php endif; ?>

                            <?php if (hasPermission('bom', 'view')): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/transactions/bom_list.php">
                                <i class="bi bi-diagram-3"></i> <?php _e('bom'); ?>
                            </a></li>
                            <?php endif; ?>

                            <?php if (hasPermission('bom', 'create')): ?>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/transactions/bom.php">
                                <i class="bi bi-plus-circle"></i> <?php _e('new_bom'); ?>
                            </a></li>
                            <?php endif; ?>

                            <?php if (hasPermission('requisition_vouchers', 'view')): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/transactions/requisition_voucher_list.php">
                                <i class="bi bi-clipboard-check"></i> <?php _e('requisition_vouchers'); ?>
                            </a></li>
                            <?php endif; ?>

                            <?php if (hasPermission('requisition_vouchers', 'create')): ?>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/transactions/requisition_voucher.php">
                                <i class="bi bi-plus-circle"></i> <?php _e('new_requisition_voucher'); ?>
                            </a></li>
                            <?php endif; ?>

                            <?php if (hasPermission('budget', 'view')): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/transactions/budget_list.php">
                                <i class="bi bi-cash-coin"></i> <?php _e('budgets'); ?>
                            </a></li>
                            <?php endif; ?>
                        </ul>
                    </li>
                    <?php endif; ?>

                    <!-- Payments & Receipts -->
                    <?php if (hasPermission('payments', 'view') || hasPermission('receipts', 'view') || hasPermission('journal_vouchers', 'view')): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-cash-stack"></i> <?php _e('money'); ?>
                        </a>
                        <ul class="dropdown-menu">
                            <?php if (hasPermission('payments', 'view')): ?>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/payments/payments.php">
                                <i class="bi bi-arrow-up-circle"></i> <?php _e('payments'); ?>
                            </a></li>
                            <?php endif; ?>

                            <?php if (hasPermission('receipts', 'view')): ?>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/payments/receipts.php">
                                <i class="bi bi-arrow-down-circle"></i> <?php _e('receipts'); ?>
                            </a></li>
                            <?php endif; ?>

                            <?php if (hasPermission('journal_vouchers', 'view')): ?>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/transactions/journal_vouchers.php">
                                <i class="bi bi-journal-text"></i> Journal Vouchers
                            </a></li>
                            <?php endif; ?>
                        </ul>
                    </li>
                    <?php endif; ?>
                    
                    <!-- Reports Dropdown -->
                    <?php if (canViewAnyReport()): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-file-earmark-bar-graph"></i> <?php _e('reports'); ?>
                        </a>
                        <ul class="dropdown-menu">
                            <?php if (canViewReport('stock_report')): ?>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/reports/stock_report.php">
                                <i class="bi bi-boxes"></i> <?php _e('stock_report'); ?>
                            </a></li>
                            <?php endif; ?>
                            <?php if (canViewReport('stock_report_by_type')): ?>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/reports/stock_report_by_type.php">
                                <i class="bi bi-diagram-3"></i> <?php _e('stock_report_by_type'); ?>
                            </a></li>
                            <?php endif; ?>
                            <?php if (canViewReport('dead_stock')): ?>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/reports/dead_stock.php">
                                <i class="bi bi-hourglass-split"></i> <?php _e('dead_stock'); ?>
                            </a></li>
                            <?php endif; ?>
                            <?php if (canViewReport('stock_value')): ?>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/reports/stock_value_breakdown.php">
                                <i class="bi bi-cash-stack"></i> <?php _e('total_stock_value'); ?>
                            </a></li>
                            <?php endif; ?>
                            <?php if (hasPermission('stock_taking', 'view')): ?>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/reports/stock_taking.php">
                                <i class="bi bi-clipboard-check"></i> <?php _e('stock_taking'); ?>
                            </a></li>
                            <?php endif; ?>
                            <?php if (hasPermission('stock_adjustment', 'view')): ?>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/transactions/stock_adjustment_list.php">
                                <i class="bi bi-sliders"></i> <?php _e('stock_adjustments'); ?>
                            </a></li>
                            <?php endif; ?>
                            <?php if (canViewReport('profit_loss')): ?>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/reports/profit_loss.php">
                                <i class="bi bi-graph-up"></i> <?php _e('profit_loss'); ?>
                            </a></li>
                            <?php endif; ?>
                            <?php if (canViewReport('cost_centre_report')): ?>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/reports/cost_centre_report.php">
                                <i class="bi bi-building"></i> <?php _e('cost_centre_report'); ?>
                            </a></li>
                            <?php endif; ?>
                            <?php if (canViewReport('cost_centre_comparison')): ?>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/reports/cost_centre_comparison.php">
                                <i class="bi bi-bar-chart-steps"></i> <?php _e('cost_centre_comparison'); ?>
                            </a></li>
                            <?php endif; ?>
                            <?php if (canViewReport('product_analysis')): ?>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/reports/product_analysis.php">
                                <i class="bi bi-bar-chart-line"></i> <?php _e('product_analysis'); ?>
                            </a></li>
                            <?php endif; ?>
                            <?php if (canViewReport('ledger_report')): ?>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/reports/ledger_report.php">
                                <i class="bi bi-journal-text"></i> <?php _e('ledger_report'); ?>
                            </a></li>
                            <?php endif; ?>
                            <?php if (hasPermission('purchase_imports', 'view')): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/reports/import_costing.php">
                                <i class="bi bi-globe2"></i> <?php _e('import_costing'); ?>
                            </a></li>
                            <?php endif; ?>
                        </ul>
                    </li>
                    <?php endif; ?>
                </ul>
                
                <!-- Language Switcher -->
                <?php
                // Build language URLs preserving existing query parameters
                $currentParams = $_GET;
                $currentParams['lang'] = 'en';
                $langEnUrl = '?' . http_build_query($currentParams);
                $currentParams['lang'] = 'sw';
                $langSwUrl = '?' . http_build_query($currentParams);
                ?>
                <ul class="navbar-nav me-2">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-globe"></i> <?php echo getCurrentLang() === 'en' ? 'EN' : 'SW'; ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item <?php echo getCurrentLang() === 'en' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($langEnUrl); ?>">
                                <i class="bi bi-check <?php echo getCurrentLang() === 'en' ? '' : 'invisible'; ?>"></i> English
                            </a></li>
                            <li><a class="dropdown-item <?php echo getCurrentLang() === 'sw' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($langSwUrl); ?>">
                                <i class="bi bi-check <?php echo getCurrentLang() === 'sw' ? '' : 'invisible'; ?>"></i> Kiswahili
                            </a></li>
                        </ul>
                    </li>
                </ul>

                <!-- Notification Bell -->
                <ul class="navbar-nav me-2">
                    <li class="nav-item dropdown">
                        <a class="nav-link position-relative" href="#" role="button" data-bs-toggle="dropdown" id="notificationBell" aria-expanded="false">
                            <i class="bi bi-bell"></i>
                            <?php $unreadCount = getUnreadNotificationCount(); if ($unreadCount > 0): ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" id="notifBadge" style="font-size: 0.6rem;">
                                <?php echo $unreadCount > 99 ? '99+' : $unreadCount; ?>
                            </span>
                            <?php endif; ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end shadow" style="width: 340px; max-height: 420px; overflow-y: auto;" id="notificationDropdown">
                            <li>
                                <h6 class="dropdown-header d-flex justify-content-between align-items-center">
                                    <?php _e('notifications'); ?>
                                    <a href="#" onclick="markAllNotificationsRead(event)" class="text-decoration-none small"><?php _e('mark_all_read'); ?></a>
                                </h6>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li class="text-center py-3 text-muted" id="notifLoading">
                                <small><?php _e('loading'); ?></small>
                            </li>
                        </ul>
                    </li>
                </ul>

                <!-- User Menu -->
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle"></i> <?php echo $userName; ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><h6 class="dropdown-header"><?php echo $userRole; ?></h6></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/auth/profile.php">
                                <i class="bi bi-person"></i> <?php _e('profile'); ?>
                            </a></li>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/auth/change_password.php">
                                <i class="bi bi-key"></i> <?php _e('change_password'); ?>
                            </a></li>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/auth/my_signature.php">
                                <i class="bi bi-pen"></i> <?php _e('my_signature'); ?>
                            </a></li>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/subscription/status.php">
                                <i class="bi bi-credit-card"></i> <?php _e('subscription'); ?>
                            </a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="<?php echo BASE_URL; ?>modules/auth/logout.php">
                                <i class="bi bi-box-arrow-right"></i> <?php _e('logout'); ?>
                            </a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    
    <!-- Subscription Warning Banner -->
    <?php if (isset($subscriptionStatus['warning']) && $subscriptionStatus['warning']): ?>
    <div class="alert alert-<?= $subscriptionStatus['status'] === 'grace_period' ? 'danger' : 'warning' ?> alert-dismissible fade show m-0 rounded-0" role="alert">
        <div class="container-fluid">
            <i class="bi bi-exclamation-triangle"></i>
            <strong><?= $subscriptionStatus['is_trial'] ? 'Trial Period:' : 'Subscription Alert:' ?></strong>
            <?= htmlspecialchars($subscriptionStatus['message']) ?>
            <a href="<?= BASE_URL ?>modules/subscription/status.php" class="alert-link ms-2">View Details</a>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Main Content -->
    <div class="container-fluid mt-3 mb-5">

    <!-- Service Worker Registration Script -->
    <script>
    // Register service worker for offline functionality
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', async () => {
            try {
                const registration = await navigator.serviceWorker.register(BASE_URL + 'service-worker.js');
                console.log('Service Worker registered:', registration.scope);
                
                // Check for updates
                registration.addEventListener('updatefound', () => {
                    const newWorker = registration.installing;
                    newWorker.addEventListener('statechange', () => {
                        if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                            // New service worker available, show update prompt
                            if (confirm('A new version is available. Reload to update?')) {
                                newWorker.postMessage({ type: 'SKIP_WAITING' });
                                window.location.reload();
                            }
                        }
                    });
                });
                
            } catch (error) {
                console.error('Service Worker registration failed:', error);
            }
        });
    }

    // Utility function to format numbers
    function formatNumber(num, decimals = 2) {
        return parseFloat(num).toFixed(decimals).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    }

    // ==================== NOTIFICATION BELL ====================
    let notificationsLoaded = false;

    document.getElementById('notificationBell')?.addEventListener('click', function() {
        if (!notificationsLoaded) {
            loadNotifications();
        }
    });

    async function loadNotifications() {
        try {
            const response = await fetch(BASE_URL + 'ajax/notifications.php?action=recent&_=' + Date.now());
            const data = await response.json();
            if (!data.success) return;

            const dropdown = document.getElementById('notificationDropdown');
            const badge = document.getElementById('notifBadge');

            // Update badge
            if (data.unread_count > 0) {
                if (badge) {
                    badge.textContent = data.unread_count > 99 ? '99+' : data.unread_count;
                    badge.style.display = '';
                } else {
                    const bell = document.getElementById('notificationBell');
                    const newBadge = document.createElement('span');
                    newBadge.className = 'position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger';
                    newBadge.id = 'notifBadge';
                    newBadge.style.fontSize = '0.6rem';
                    newBadge.textContent = data.unread_count > 99 ? '99+' : data.unread_count;
                    bell.appendChild(newBadge);
                }
            } else if (badge) {
                badge.style.display = 'none';
            }

            // Build notification list
            let html = `<li><h6 class="dropdown-header d-flex justify-content-between align-items-center">
                <?php _e('notifications'); ?>
                <a href="#" onclick="markAllNotificationsRead(event)" class="text-decoration-none small"><?php _e('mark_all_read'); ?></a>
            </h6></li><li><hr class="dropdown-divider"></li>`;

            if (data.notifications.length === 0) {
                html += `<li class="text-center py-3 text-muted"><small><?php _e('no_notifications'); ?></small></li>`;
            } else {
                data.notifications.forEach(n => {
                    const isUnread = n.is_read == 0;
                    const bgClass = isUnread ? 'bg-light' : '';
                    const timeAgo = formatTimeAgo(n.created_at);
                    html += `<li>
                        <a class="dropdown-item py-2 ${bgClass}" href="#" onclick="clickNotification(event, ${n.id}, '${(n.link || '').replace(/'/g, "\\'")}')">
                            <div class="d-flex align-items-start">
                                <i class="bi bi-${isUnread ? 'bell-fill text-primary' : 'bell text-muted'} me-2 mt-1"></i>
                                <div class="flex-grow-1" style="white-space: normal; word-wrap: break-word;">
                                    <div class="fw-${isUnread ? 'bold' : 'normal'}" style="font-size: 0.85rem;">${escapeHtml(n.title)}</div>
                                    <small class="text-muted">${timeAgo}</small>
                                </div>
                            </div>
                        </a>
                    </li>`;
                });
            }

            dropdown.innerHTML = html;
            notificationsLoaded = true;
        } catch (err) {
            console.error('Failed to load notifications:', err);
        }
    }

    async function clickNotification(event, id, link) {
        event.preventDefault();
        try {
            await fetch(BASE_URL + 'ajax/notifications.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({action: 'mark_read', id: id})
            });
        } catch(e) {}
        if (link) {
            window.location.href = link;
        } else {
            notificationsLoaded = false;
            loadNotifications();
        }
    }

    async function markAllNotificationsRead(event) {
        event.preventDefault();
        event.stopPropagation();
        try {
            await fetch(BASE_URL + 'ajax/notifications.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({action: 'mark_all_read'})
            });
            const badge = document.getElementById('notifBadge');
            if (badge) badge.style.display = 'none';
            notificationsLoaded = false;
            loadNotifications();
        } catch(e) {}
    }

    function formatTimeAgo(dateStr) {
        const date = new Date(dateStr);
        const now = new Date();
        const diffMs = now - date;
        const diffMins = Math.floor(diffMs / 60000);
        if (diffMins < 1) return 'Just now';
        if (diffMins < 60) return diffMins + 'm ago';
        const diffHrs = Math.floor(diffMins / 60);
        if (diffHrs < 24) return diffHrs + 'h ago';
        const diffDays = Math.floor(diffHrs / 24);
        if (diffDays < 7) return diffDays + 'd ago';
        return date.toLocaleDateString();
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Auto-refresh notification badge, and double as the session keep-alive.
    //
    // active=1 is sent ONLY when the user actually interacted since the last
    // tick — see idleLogout below and the SESSION_ACTIVITY_PASSIVE note in
    // ajax/notifications.php. Sending it unconditionally would postpone the
    // idle logout forever.
    //
    // The interval normally stays at 60s, but a SHORT timeout needs a faster
    // tick or the keep-alive would arrive after the server had already expired
    // the session (a 1-minute timeout with a 60-second ping is a coin flip).
    // A third of the timeout leaves room for two pings before expiry.
    const NOTIF_POLL_MS = (function () {
        const t = <?php echo (int)getSessionTimeoutMinutes(); ?> * 60 * 1000;
        return (t > 0) ? Math.max(5000, Math.min(60000, Math.floor(t / 3))) : 60000;
    })();

    setInterval(async () => {
        try {
            const active = (typeof idleLogout !== 'undefined' && idleLogout.consumeInteraction()) ? '&active=1' : '';
            const resp = await fetch(BASE_URL + 'ajax/notifications.php?action=count' + active + '&_=' + Date.now());
            const data = await resp.json();
            if (data.success) {
                const badge = document.getElementById('notifBadge');
                if (data.unread_count > 0) {
                    if (badge) {
                        badge.textContent = data.unread_count > 99 ? '99+' : data.unread_count;
                        badge.style.display = '';
                    } else {
                        const bell = document.getElementById('notificationBell');
                        if (bell) {
                            const newBadge = document.createElement('span');
                            newBadge.className = 'position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger';
                            newBadge.id = 'notifBadge';
                            newBadge.style.fontSize = '0.6rem';
                            newBadge.textContent = data.unread_count > 99 ? '99+' : data.unread_count;
                            bell.appendChild(newBadge);
                        }
                    }
                    notificationsLoaded = false;
                } else if (badge) {
                    badge.style.display = 'none';
                }
            }
        } catch(e) {}
    }, NOTIF_POLL_MS);

    /* ================================================================
       IDLE LOGOUT
       The SERVER is authoritative (enforceSessionTimeout() in
       includes/functions.php). This exists so the user SEES it happen —
       with a chance to stay signed in — instead of discovering it on
       their next click.
       ================================================================ */
    const idleLogout = (function () {
        const timeoutMinutes = <?php echo (int)getSessionTimeoutMinutes(); ?>;
        if (timeoutMinutes <= 0) {
            // Disabled: behave exactly as the app did before this feature.
            return { consumeInteraction: () => false };
        }

        const TIMEOUT_MS = timeoutMinutes * 60 * 1000;
        // Warn a minute out, but never more than a third of a short timeout —
        // a 1-minute setting must not warn before it starts counting.
        const WARN_MS = Math.min(60 * 1000, Math.floor(TIMEOUT_MS / 3));

        let lastInteraction = Date.now();
        let interactedSincePoll = false;
        let warned = false;
        let modal = null;

        function markInteraction() {
            lastInteraction = Date.now();
            interactedSincePoll = true;
            if (warned) {
                warned = false;
                hideWarning();
            }
        }

        // passive listeners so this can never affect scrolling performance
        ['mousedown', 'keydown', 'touchstart', 'scroll', 'wheel'].forEach(evt =>
            window.addEventListener(evt, markInteraction, { passive: true }));

        function hideWarning() {
            if (modal) modal.hide();
        }

        async function pendingOfflineWrites() {
            // Never let someone walk away without knowing unsynced work exists.
            try {
                if (typeof offlineDB !== 'undefined' && offlineDB.getQueue) {
                    const q = await offlineDB.getQueue();
                    return Array.isArray(q) ? q.length : 0;
                }
            } catch (e) {}
            return 0;
        }

        async function showWarning() {
            const el = document.getElementById('idleLogoutModal');
            if (!el) return;

            const pending = await pendingOfflineWrites();
            const warnEl = document.getElementById('idleLogoutPending');
            if (warnEl) {
                warnEl.style.display = pending > 0 ? '' : 'none';
                warnEl.textContent = <?php echo json_encode(__('idle_pending_writes')); ?>.replace('%d', pending);
            }

            if (!modal) modal = new bootstrap.Modal(el, { backdrop: 'static', keyboard: false });
            modal.show();
        }

        setInterval(() => {
            const idleFor = Date.now() - lastInteraction;

            if (idleFor >= TIMEOUT_MS) {
                window.location.href = BASE_URL + 'modules/auth/logout.php?expired=1';
                return;
            }
            if (!warned && idleFor >= TIMEOUT_MS - WARN_MS) {
                warned = true;
                showWarning();
            }
            if (warned) {
                const left = Math.max(0, Math.ceil((TIMEOUT_MS - idleFor) / 1000));
                const c = document.getElementById('idleLogoutCountdown');
                if (c) c.textContent = left;
            }
        }, 1000);

        return {
            /** True once per poll if the user interacted since the last one. */
            consumeInteraction() {
                const v = interactedSincePoll;
                interactedSincePoll = false;
                return v;
            }
        };
    })();

    /* ================================================================
       EXPIRED-SESSION -> LOGIN, for AJAX
       Page loads are already covered: header.php redirects when
       isLoggedIn() is false. But every api/ endpoint answers an expired
       session with a bare 401 and, until now, NOTHING in the app noticed
       — so a user who came back after the timeout and clicked something
       just got opaque failures with no idea they had been signed out.
       Wrapping fetch once here covers all ~50 endpoints without touching
       any of them.
       ================================================================ */
    (function () {
        const nativeFetch = window.fetch.bind(window);
        let redirecting = false;

        window.fetch = async function (...args) {
            const response = await nativeFetch(...args);

            if (response.status === 401 && !redirecting) {
                let url = '';
                try {
                    url = (typeof args[0] === 'string') ? args[0] : (args[0] && args[0].url) || '';
                } catch (e) {}

                // Only our own endpoints — a third-party 401 must not boot the user.
                if (url.indexOf(BASE_URL) === 0 || url.charAt(0) === '/' || url.indexOf('://') === -1) {
                    redirecting = true;
                    window.location.href = BASE_URL + 'modules/auth/logout.php?expired=1';
                }
            }

            return response;
        };
    })();

    function idleStaySignedIn() {
        // A real request, so the SERVER's clock is reset too — not just the
        // browser's. Without this the tab would look alive while the session
        // had already expired server-side.
        fetch(BASE_URL + 'ajax/notifications.php?action=count&active=1&_=' + Date.now())
            .catch(() => {});
    }
    </script>

    <?php if (getSessionTimeoutMinutes() > 0): ?>
    <!-- Idle-logout warning. Rendered only when the timeout is enabled. -->
    <div class="modal fade" id="idleLogoutModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-clock-history"></i> <?php _e('idle_logout_title'); ?></h5>
                </div>
                <div class="modal-body">
                    <p class="mb-2">
                        <?php _e('idle_logout_body'); ?>
                        <strong><span id="idleLogoutCountdown">60</span></strong> <?php _e('seconds'); ?>.
                    </p>
                    <div class="alert alert-warning mb-0" id="idleLogoutPending" style="display:none;"></div>
                </div>
                <div class="modal-footer">
                    <a href="<?php echo BASE_URL; ?>modules/auth/logout.php" class="btn btn-outline-secondary">
                        <?php _e('logout'); ?>
                    </a>
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal" onclick="idleStaySignedIn()">
                        <?php _e('idle_stay_signed_in'); ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
