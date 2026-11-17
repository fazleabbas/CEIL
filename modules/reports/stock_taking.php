<?php
require_once '../../config/database.php';
require_once '../../includes/functions.php';
$pageTitle = __('stock_taking_title');
if (!hasPermission('stock_taking', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && hasPermission('stock_taking', 'create')) {
    checkCSRF();
    
    $locationId = (int)$_POST['location_id'];
    $stockDate = dbDate($_POST['stock_date']);
    $calculationMethod = clean($_POST['calculation_method']);
    $actualCash = floatval($_POST['actual_cash_amount'] ?? 0);
    $products = $_POST['products'] ?? [];
    $physicalQty = $_POST['physical_qty'] ?? [];

    // Prepared By signature (optional). Validate base64 PNG/JPEG data URL.
    $preparedSig = $_POST['prepared_by_signature'] ?? '';
    if (!is_string($preparedSig) || $preparedSig === '') {
        $preparedSig = null;
    } elseif (!preg_match('#^data:image/(png|jpeg|jpg);base64,[A-Za-z0-9+/=]+$#', $preparedSig)
              || strlen($preparedSig) > 2 * 1024 * 1024) {
        $preparedSig = null;
    }
    
    $errors = [];
    
    if (empty($locationId)) {
        $errors[] = __('please_select_location');
    }
    
    if (empty($stockDate)) {
        $errors[] = __('please_select_date');
    }
    
    if (!in_array($calculationMethod, ['FIFO', 'LIFO', 'AVERAGE'])) {
        $errors[] = __('invalid_calculation_method');
    }
    
    // Multiple stock takings per (location, date) are allowed. If a header
    // already exists we reuse it and UPSERT detail rows (see below).

    if (empty($errors)) {
        try {
            $conn->beginTransaction();

            // Reuse existing header for this (location, date) if present —
            // header fields (calculation_method, expected_sales_amount,
            // actual_cash_amount, created_by) are NOT mutated on subsequent
            // saves; only detail rows are upserted.
            $existingHeaderStmt = $conn->prepare("
                SELECT id, calculation_method, created_by, prepared_by_signature
                FROM stock_taking
                WHERE location_id = ? AND stock_date = ?
                LIMIT 1
            ");
            $existingHeaderStmt->execute([$locationId, $stockDate]);
            $existingHeader = $existingHeaderStmt->fetch();

            if ($existingHeader) {
                $takingId = (int)$existingHeader['id'];
                $effectiveMethod = $existingHeader['calculation_method'];
                $isUpdate = true;

                // Nothing drawn and nothing stored yet: fall back to the
                // signature the PREPARER has on file (0041). Keyed on
                // created_by, not whoever is re-saving, so the stored image
                // always belongs to the name shown against it.
                if ($preparedSig === null && empty($existingHeader['prepared_by_signature'])) {
                    $preparedSig = getUserSignature($conn, $existingHeader['created_by']);
                }

                // Allow late capture of prepared_by_signature on re-save:
                // only update when a new non-null signature is posted; never
                // wipe an existing one. Other header fields remain untouched
                // per the documented invariant above.
                if ($preparedSig !== null) {
                    $sigUpdateStmt = $conn->prepare("
                        UPDATE stock_taking
                        SET prepared_by_signature = ?
                        WHERE id = ?
                    ");
                    $sigUpdateStmt->execute([$preparedSig, $takingId]);
                }
            } else {
                // Nothing drawn on the pad — use the creator's signature on file.
                if ($preparedSig === null) {
                    $preparedSig = getUserSignature($conn, getUserId());
                }

                // Calculate expected sales amount (only on first create)
                $salesStmt = $conn->prepare("
                    SELECT COALESCE(SUM(total_amount), 0) as total_sales
                    FROM sales
                    WHERE location_id = ? AND sale_date = ?
                ");
                $salesStmt->execute([$locationId, $stockDate]);
                $expectedSales = $salesStmt->fetchColumn();

                // Create stock taking header
                $takingNumber = getNextNumber('stock_taking', 'stock_taking_number', getSetting('stock_taking_prefix', 'STK'));

                $stmt = $conn->prepare("
                    INSERT INTO stock_taking
                    (stock_taking_number, stock_date, location_id, calculation_method, expected_sales_amount, actual_cash_amount, created_by, prepared_by_signature)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$takingNumber, $stockDate, $locationId, $calculationMethod, $expectedSales, $actualCash, getUserId(), $preparedSig]);

                $takingId = $conn->lastInsertId();
                $effectiveMethod = $calculationMethod;
                $isUpdate = false;
            }

            // Calculate previous day for opening stock
            $prevDate = date('Y-m-d', strtotime($stockDate . ' -1 day'));

            // Pre-load existing detail rows for upsert routing
            $existingDetailIdByProduct = [];
            if ($isUpdate) {
                $exDStmt = $conn->prepare("SELECT id, product_id FROM stock_taking_details WHERE taking_id = ?");
                $exDStmt->execute([$takingId]);
                foreach ($exDStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $existingDetailIdByProduct[(int)$r['product_id']] = (int)$r['id'];
                }
            }

            // Insert / update details
            $detailStmt = $conn->prepare("
                INSERT INTO stock_taking_details
                (taking_id, product_id, system_opening, system_purchases, system_sales, physical_stock, variance_amount)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $detailUpdateStmt = $conn->prepare("
                UPDATE stock_taking_details
                SET system_opening = ?, system_purchases = ?, system_sales = ?, physical_stock = ?, variance_amount = ?
                WHERE id = ?
            ");
            
            foreach ($products as $index => $productId) {
                $physical = floatval($physicalQty[$index] ?? 0);
                
                // Get opening stock - same logic as stock_report.php
                // Opening = opening_stocks (before date) + purchases (before date) - sales (before date)
                
                // Opening stocks before the selected date
                $openingStocksStmt = $conn->prepare("
                    SELECT COALESCE(SUM(quantity), 0) as opening_stock_qty
                    FROM opening_stocks
                    WHERE product_id = ? AND location_id = ? AND stock_date < ?
                ");
                $openingStocksStmt->execute([$productId, $locationId, $stockDate]);
                $openingStockQty = $openingStocksStmt->fetchColumn() ?: 0;
                
                // Purchases before the selected date (regular + imports)
                $purchasesBeforeStmt = $conn->prepare("
                    SELECT COALESCE(SUM(pd.quantity), 0) as purchases_before
                    FROM purchase_details pd
                    JOIN purchases p ON pd.purchase_id = p.id
                    WHERE pd.product_id = ? AND p.location_id = ? AND p.purchase_date < ?
                ");
                $purchasesBeforeStmt->execute([$productId, $locationId, $stockDate]);
                $purchasesBefore = $purchasesBeforeStmt->fetchColumn() ?: 0;
                $importsBeforeStmt = $conn->prepare("
                    SELECT COALESCE(SUM(pid.quantity), 0)
                    FROM purchase_import_details pid
                    JOIN purchase_imports pi ON pid.import_id = pi.id
                    WHERE pid.product_id = ? AND pi.location_id = ? AND pi.import_date < ?
                ");
                $importsBeforeStmt->execute([$productId, $locationId, $stockDate]);
                $purchasesBefore += $importsBeforeStmt->fetchColumn() ?: 0;
                
                // Sales before the selected date
                $salesBeforeStmt = $conn->prepare("
                    SELECT COALESCE(SUM(sd.quantity), 0) as sales_before
                    FROM sale_details sd
                    JOIN sales s ON sd.sale_id = s.id
                    WHERE sd.product_id = ? AND s.location_id = ? AND s.sale_date < ?
                ");
                $salesBeforeStmt->execute([$productId, $locationId, $stockDate]);
                $salesBefore = $salesBeforeStmt->fetchColumn() ?: 0;
                
                // Adjustments before the selected date
                $adjBeforeStmt = $conn->prepare("
                    SELECT COALESCE(SUM(CASE WHEN sad.adjustment_type = 'increase' THEN sad.quantity ELSE -sad.quantity END), 0)
                    FROM stock_adjustment_details sad
                    JOIN stock_adjustments sa ON sad.adjustment_id = sa.id
                    WHERE sad.product_id = ? AND sa.location_id = ? AND sa.adjustment_date < ?
                ");
                $adjBeforeStmt->execute([$productId, $locationId, $stockDate]);
                $adjustmentsBefore = $adjBeforeStmt->fetchColumn() ?: 0;
                
                // Transfer In before the selected date
                $transferInBeforeStmt = $conn->prepare("
                    SELECT COALESCE(SUM(td.received_quantity), 0)
                    FROM stock_transfer_details td
                    JOIN stock_transfers t ON td.transfer_id = t.id
                    WHERE td.product_id = ? AND t.to_location_id = ? AND t.status = 'received' AND DATE(t.received_at) < ?
                ");
                $transferInBeforeStmt->execute([$productId, $locationId, $stockDate]);
                $transferInBefore = $transferInBeforeStmt->fetchColumn() ?: 0;
                
                // Transfer Out before the selected date
                $transferOutBeforeStmt = $conn->prepare("
                    SELECT COALESCE(SUM(td.dispatched_quantity), 0)
                    FROM stock_transfer_details td
                    JOIN stock_transfers t ON td.transfer_id = t.id
                    WHERE td.product_id = ? AND t.from_location_id = ? AND t.status IN ('in_transit', 'received') AND DATE(t.dispatched_at) < ?
                ");
                $transferOutBeforeStmt->execute([$productId, $locationId, $stockDate]);
                $transferOutBefore = $transferOutBeforeStmt->fetchColumn() ?: 0;

                // Consumption before the selected date
                $consumptionBeforeStmt = $conn->prepare("
                    SELECT COALESCE(SUM(cvd.quantity), 0)
                    FROM consumption_voucher_details cvd
                    JOIN consumption_vouchers cv ON cvd.voucher_id = cv.id
                    WHERE cvd.product_id = ? AND cv.location_id = ? AND cv.voucher_date < ?
                ");
                $consumptionBeforeStmt->execute([$productId, $locationId, $stockDate]);
                $consumptionBefore = $consumptionBeforeStmt->fetchColumn() ?: 0;

                $actualOpening = $openingStockQty + $purchasesBefore - $salesBefore + $adjustmentsBefore + $transferInBefore - $transferOutBefore - $consumptionBefore;

                // Get purchases for selected date (regular + imports)
                $purchaseStmt = $conn->prepare("
                    SELECT COALESCE(SUM(pd.quantity), 0) as purchases
                    FROM purchase_details pd
                    JOIN purchases p ON pd.purchase_id = p.id
                    WHERE pd.product_id = ? AND p.location_id = ? AND p.purchase_date = ?
                ");
                $purchaseStmt->execute([$productId, $locationId, $stockDate]);
                $purchases = $purchaseStmt->fetchColumn();
                $importDayStmt = $conn->prepare("
                    SELECT COALESCE(SUM(pid.quantity), 0)
                    FROM purchase_import_details pid
                    JOIN purchase_imports pi ON pid.import_id = pi.id
                    WHERE pid.product_id = ? AND pi.location_id = ? AND pi.import_date = ?
                ");
                $importDayStmt->execute([$productId, $locationId, $stockDate]);
                $purchases += $importDayStmt->fetchColumn() ?: 0;
                
                // Get sales for selected date
                $salesDayStmt = $conn->prepare("
                    SELECT COALESCE(SUM(sd.quantity), 0) as sales
                    FROM sale_details sd
                    JOIN sales s ON sd.sale_id = s.id
                    WHERE sd.product_id = ? AND s.location_id = ? AND s.sale_date = ?
                ");
                $salesDayStmt->execute([$productId, $locationId, $stockDate]);
                $sales = $salesDayStmt->fetchColumn();
                
                // Get adjustments for selected date
                $adjDayStmt = $conn->prepare("
                    SELECT COALESCE(SUM(CASE WHEN sad.adjustment_type = 'increase' THEN sad.quantity ELSE -sad.quantity END), 0)
                    FROM stock_adjustment_details sad
                    JOIN stock_adjustments sa ON sad.adjustment_id = sa.id
                    WHERE sad.product_id = ? AND sa.location_id = ? AND sa.adjustment_date = ?
                ");
                $adjDayStmt->execute([$productId, $locationId, $stockDate]);
                $adjustmentsDay = $adjDayStmt->fetchColumn() ?: 0;
                
                // Get transfer in for selected date
                $transferInDayStmt = $conn->prepare("
                    SELECT COALESCE(SUM(td.received_quantity), 0)
                    FROM stock_transfer_details td
                    JOIN stock_transfers t ON td.transfer_id = t.id
                    WHERE td.product_id = ? AND t.to_location_id = ? AND t.status = 'received' AND DATE(t.received_at) = ?
                ");
                $transferInDayStmt->execute([$productId, $locationId, $stockDate]);
                $transferInDay = $transferInDayStmt->fetchColumn() ?: 0;
                
                // Get transfer out for selected date
                $transferOutDayStmt = $conn->prepare("
                    SELECT COALESCE(SUM(td.dispatched_quantity), 0)
                    FROM stock_transfer_details td
                    JOIN stock_transfers t ON td.transfer_id = t.id
                    WHERE td.product_id = ? AND t.from_location_id = ? AND t.status IN ('in_transit', 'received') AND DATE(t.dispatched_at) = ?
                ");
                $transferOutDayStmt->execute([$productId, $locationId, $stockDate]);
                $transferOutDay = $transferOutDayStmt->fetchColumn() ?: 0;

                // Get consumption for selected date
                $consumptionDayStmt = $conn->prepare("
                    SELECT COALESCE(SUM(cvd.quantity), 0)
                    FROM consumption_voucher_details cvd
                    JOIN consumption_vouchers cv ON cvd.voucher_id = cv.id
                    WHERE cvd.product_id = ? AND cv.location_id = ? AND cv.voucher_date = ?
                ");
                $consumptionDayStmt->execute([$productId, $locationId, $stockDate]);
                $consumptionDay = $consumptionDayStmt->fetchColumn() ?: 0;

                $closing = $actualOpening + $purchases - $sales + $adjustmentsDay + $transferInDay - $transferOutDay - $consumptionDay;
                $varianceQty = $physical - $closing;
                
                // Calculate variance amount based on selected method
                $varianceAmount = 0;
                
                if (abs($varianceQty) > 0.01) {
                    $rate = 0;

                    if ($effectiveMethod == 'FIFO') {
                        // Get oldest batch rate
                        $rateStmt = $conn->prepare("
                            SELECT rate_per_unit
                            FROM stock_batches
                            WHERE product_id = ? AND location_id = ? 
                            AND batch_date <= ? AND quantity_remaining > 0
                            ORDER BY batch_date ASC, id ASC
                            LIMIT 1
                        ");
                        $rateStmt->execute([$productId, $locationId, $stockDate]);
                        $rate = $rateStmt->fetchColumn() ?: 0;
                        
                    } elseif ($effectiveMethod == 'LIFO') {
                        // Get newest batch rate
                        $rateStmt = $conn->prepare("
                            SELECT rate_per_unit
                            FROM stock_batches
                            WHERE product_id = ? AND location_id = ? 
                            AND batch_date <= ? AND quantity_remaining > 0
                            ORDER BY batch_date DESC, id DESC
                            LIMIT 1
                        ");
                        $rateStmt->execute([$productId, $locationId, $stockDate]);
                        $rate = $rateStmt->fetchColumn() ?: 0;
                        
                    } else { // AVERAGE
                        // Calculate weighted average
                        $rateStmt = $conn->prepare("
                            SELECT 
                                COALESCE(
                                    SUM(quantity_remaining * rate_per_unit) / NULLIF(SUM(quantity_remaining), 0),
                                    0
                                ) as avg_rate
                            FROM stock_batches
                            WHERE product_id = ? AND location_id = ? 
                            AND batch_date <= ? AND quantity_remaining > 0
                        ");
                        $rateStmt->execute([$productId, $locationId, $stockDate]);
                        $rate = $rateStmt->fetchColumn() ?: 0;
                    }
                    
                    $varianceAmount = $varianceQty * $rate;
                }
                
                if (isset($existingDetailIdByProduct[(int)$productId])) {
                    $detailUpdateStmt->execute([
                        $actualOpening,
                        $purchases,
                        $sales,
                        $physical,
                        $varianceAmount,
                        $existingDetailIdByProduct[(int)$productId]
                    ]);
                } else {
                    $detailStmt->execute([
                        $takingId,
                        $productId,
                        $actualOpening,
                        $purchases,
                        $sales,
                        $physical,
                        $varianceAmount
                    ]);
                }
            }

            $conn->commit();

            logAudit('stock_taking', $isUpdate ? 'update' : 'create', $takingId);

            // Notify verifiers on first create only (avoid spam on re-saves)
            if (!$isUpdate) {
                // Look up the just-created taking number for the message
                $tnStmt = $conn->prepare("SELECT stock_taking_number FROM stock_taking WHERE id = ?");
                $tnStmt->execute([$takingId]);
                $tn = $tnStmt->fetchColumn() ?: ('#' . $takingId);
                notifyVerifiers(
                    'stock_taking',
                    'stock_taking_verification',
                    __('stock_taking_ready_for_verification', 'Stock Taking ready for verification'),
                    sprintf(
                        __('stock_taking_ready_for_verification_msg', 'Stock Taking %s is ready for verification.'),
                        $tn
                    ),
                    BASE_URL . 'modules/reports/stock_taking_view.php?id=' . $takingId,
                    $takingId,
                    'stock_taking'
                );
            }

            setAlert(__('stock_taking_saved'), 'success');
            redirect('modules/reports/stock_taking_view.php?id=' . $takingId);
            
        } catch (PDOException $e) {
            $conn->rollBack();
            $errors[] = sprintf(__('database_error'), $e->getMessage());
        }
    }
    
    if (!empty($errors)) {
        foreach ($errors as $error) {
            setAlert($error, 'danger');
        }
    }
}

require_once '../../includes/header.php';

// Get user's accessible locations
$locations = getUserLocations();

// Get selected location, date, and calculation method
$selectedLocation = $_GET['location'] ?? '';
$selectedDate = $_GET['date'] ?? date('Y-m-d');
$selectedMethod = $_GET['method'] ?? 'FIFO';

// --- Product filter inputs (new) ---
$selectedCategory   = isset($_GET['category']) && $_GET['category'] !== '' ? (int)$_GET['category'] : 0;
$selectedProductIds = [];
if (!empty($_GET['products']) && is_array($_GET['products'])) {
    $selectedProductIds = array_values(array_unique(array_filter(array_map('intval', $_GET['products']))));
}
$showZero       = !empty($_GET['show_zero']);
$hasFilter      = ($selectedCategory > 0) || !empty($selectedProductIds);
$needsFilter    = false; // set true when user has location+date but no filter chosen
$preselectedProductOptions = []; // [id => name] for hydrating Select2

// Categories for the filter dropdown (small list, render inline)
$filterCategories = [];
try {
    $catStmt = $conn->query("
        SELECT id, name, parent_id
        FROM product_categories
        WHERE is_active = 1
        ORDER BY name ASC
    ");
    $filterCategories = $catStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $filterCategories = [];
}

// Get products for selected location with stock data
$products = [];
$expectedSalesAmount = 0;
$existingStockTaking = null;
$existingPhysicalByProduct = [];

if ($selectedLocation && $selectedDate) {
    // Check if a stock taking header already exists for this location+date.
    // Multiple takings per day are allowed: when one exists we reuse the
    // header on save and pre-fill physical inputs from its detail rows.
    $existingStmt = $conn->prepare("
        SELECT id, stock_taking_number, calculation_method FROM stock_taking
        WHERE location_id = ? AND stock_date = ?
        LIMIT 1
    ");
    $existingStmt->execute([$selectedLocation, $selectedDate]);
    $existingStockTaking = $existingStmt->fetch();

    // product_id => physical_stock map for pre-fill
    $existingPhysicalByProduct = [];
    if ($existingStockTaking) {
        $exDetailsStmt = $conn->prepare("
            SELECT product_id, physical_stock
            FROM stock_taking_details
            WHERE taking_id = ?
        ");
        $exDetailsStmt->execute([$existingStockTaking['id']]);
        foreach ($exDetailsStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $existingPhysicalByProduct[(int)$r['product_id']] = $r['physical_stock'];
        }
    }

    // Calculate previous day
    $prevDate = date('Y-m-d', strtotime($selectedDate . ' -1 day'));

    // Get expected sales amount
    $salesStmt = $conn->prepare("
        SELECT COALESCE(SUM(total_amount), 0) as total_sales
        FROM sales
        WHERE location_id = ? AND sale_date = ?
    ");
    $salesStmt->execute([$selectedLocation, $selectedDate]);
    $expectedSalesAmount = $salesStmt->fetchColumn();

    // Hydrate preselected product names so Select2 can show them on reload
    if (!empty($selectedProductIds)) {
        list($ph, $params) = buildInClause($selectedProductIds);
        $hydStmt = $conn->prepare("SELECT id, name FROM products WHERE id IN ($ph)");
        $hydStmt->execute($params);
        foreach ($hydStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $preselectedProductOptions[(int)$row['id']] = $row['name'];
        }
    }

    // Resolve which products to load. Filter precedence (when set) is unchanged.
    // When no filter is picked AND an existing header is present, auto-load the
    // products that already have detail rows so their physical values pre-fill
    // on first load. Without that, the page would still require a filter and
    // the pre-fill would never become visible.
    $resolvedProductIds = null;
    if (!$hasFilter) {
        if ($existingStockTaking && !empty($existingPhysicalByProduct)) {
            $resolvedProductIds = array_keys($existingPhysicalByProduct);
        } else {
            $needsFilter = true;
        }
    } else {
        if (!empty($selectedProductIds)) {
            // Explicit picks take precedence; show exactly what was picked
            // regardless of zero-stock filter.
            $resolvedProductIds = $selectedProductIds;
        } elseif ($selectedCategory > 0) {
            $catIds = getDescendantCategoryIds($selectedCategory, $conn);
            if (!empty($catIds)) {
                list($cph, $cparams) = buildInClause($catIds);
                $cstmt = $conn->prepare("SELECT id FROM products WHERE is_active = 1 AND category_id IN ($cph)");
                $cstmt->execute($cparams);
                $resolvedProductIds = array_map('intval', $cstmt->fetchAll(PDO::FETCH_COLUMN));
            } else {
                $resolvedProductIds = [];
            }
            // Apply non-zero filter when user picked a category and didn't opt to show zeros.
            // Exception: keep products that already have a stock_taking_details row for this
            // (location, date) header so previously-counted items remain visible/editable
            // even if their live stock is now zero.
            if (!$showZero && !empty($resolvedProductIds)) {
                $nonzeroIds = getProductIdsWithNonZeroStock($selectedLocation, $selectedDate, $conn);
                $keepIds = array_unique(array_merge($nonzeroIds, array_keys($existingPhysicalByProduct)));
                $resolvedProductIds = array_values(array_intersect($resolvedProductIds, $keepIds));
            }
        }
    }

    if ($needsFilter) {
        $allProducts = [];
    } elseif ($resolvedProductIds === null || empty($resolvedProductIds)) {
        $allProducts = [];
    } else {
        list($pph, $pparams) = buildInClause($resolvedProductIds);
        $stmt = $conn->prepare("
            SELECT
                p.id,
                p.name,
                p.unit,
                pc.name as category_name
            FROM products p
            LEFT JOIN product_categories pc ON p.category_id = pc.id
            WHERE p.is_active = 1 AND p.id IN ($pph)
            ORDER BY pc.name, p.name
        ");
        $stmt->execute($pparams);
        $allProducts = $stmt->fetchAll();
    }
    
    // For each product, calculate opening, purchases, sales
    foreach ($allProducts as $product) {
        $productId = $product['id'];
        
        // Get opening stock - same logic as stock_report.php
        // Opening = opening_stocks (before date) + purchases (before date) - sales (before date)
        
        // Opening stocks before the selected date
        $openingStocksStmt = $conn->prepare("
            SELECT COALESCE(SUM(quantity), 0) as opening_stock_qty
            FROM opening_stocks
            WHERE product_id = ? AND location_id = ? AND stock_date <= ?
        ");
        $openingStocksStmt->execute([$productId, $selectedLocation, $selectedDate]);
        $openingStockQty = $openingStocksStmt->fetchColumn() ?: 0;
        
        // Purchases before the selected date (regular + imports)
        $purchasesBeforeStmt = $conn->prepare("
            SELECT COALESCE(SUM(pd.quantity), 0) as purchases_before
            FROM purchase_details pd
            JOIN purchases p ON pd.purchase_id = p.id
            WHERE pd.product_id = ? AND p.location_id = ? AND p.purchase_date < ?
        ");
        $purchasesBeforeStmt->execute([$productId, $selectedLocation, $selectedDate]);
        $purchasesBefore = $purchasesBeforeStmt->fetchColumn() ?: 0;
        $importsBeforeStmt2 = $conn->prepare("
            SELECT COALESCE(SUM(pid.quantity), 0)
            FROM purchase_import_details pid
            JOIN purchase_imports pi ON pid.import_id = pi.id
            WHERE pid.product_id = ? AND pi.location_id = ? AND pi.import_date < ?
        ");
        $importsBeforeStmt2->execute([$productId, $selectedLocation, $selectedDate]);
        $purchasesBefore += $importsBeforeStmt2->fetchColumn() ?: 0;

        // Sales before the selected date
        $salesBeforeStmt = $conn->prepare("
            SELECT COALESCE(SUM(sd.quantity), 0) as sales_before
            FROM sale_details sd
            JOIN sales s ON sd.sale_id = s.id
            WHERE sd.product_id = ? AND s.location_id = ? AND s.sale_date < ?
        ");
        $salesBeforeStmt->execute([$productId, $selectedLocation, $selectedDate]);
        $salesBefore = $salesBeforeStmt->fetchColumn() ?: 0;
        
        // Adjustments before the selected date
        $adjBeforeStmt = $conn->prepare("
            SELECT COALESCE(SUM(CASE WHEN sad.adjustment_type = 'increase' THEN sad.quantity ELSE -sad.quantity END), 0)
            FROM stock_adjustment_details sad
            JOIN stock_adjustments sa ON sad.adjustment_id = sa.id
            WHERE sad.product_id = ? AND sa.location_id = ? AND sa.adjustment_date < ?
        ");
        $adjBeforeStmt->execute([$productId, $selectedLocation, $selectedDate]);
        $adjustmentsBefore = $adjBeforeStmt->fetchColumn() ?: 0;

        // Transfer In before the selected date
        $transferInBeforeStmt = $conn->prepare("
            SELECT COALESCE(SUM(td.received_quantity), 0)
            FROM stock_transfer_details td
            JOIN stock_transfers t ON td.transfer_id = t.id
            WHERE td.product_id = ? AND t.to_location_id = ? AND t.status = 'received' AND DATE(t.received_at) < ?
        ");
        $transferInBeforeStmt->execute([$productId, $selectedLocation, $selectedDate]);
        $transferInBefore = $transferInBeforeStmt->fetchColumn() ?: 0;

        // Transfer Out before the selected date
        $transferOutBeforeStmt = $conn->prepare("
            SELECT COALESCE(SUM(td.dispatched_quantity), 0)
            FROM stock_transfer_details td
            JOIN stock_transfers t ON td.transfer_id = t.id
            WHERE td.product_id = ? AND t.from_location_id = ? AND t.status IN ('in_transit', 'received') AND DATE(t.dispatched_at) < ?
        ");
        $transferOutBeforeStmt->execute([$productId, $selectedLocation, $selectedDate]);
        $transferOutBefore = $transferOutBeforeStmt->fetchColumn() ?: 0;

        // Consumption before the selected date
        $consumptionBeforeStmt = $conn->prepare("
            SELECT COALESCE(SUM(cvd.quantity), 0)
            FROM consumption_voucher_details cvd
            JOIN consumption_vouchers cv ON cvd.voucher_id = cv.id
            WHERE cvd.product_id = ? AND cv.location_id = ? AND cv.voucher_date < ?
        ");
        $consumptionBeforeStmt->execute([$productId, $selectedLocation, $selectedDate]);
        $consumptionBefore = $consumptionBeforeStmt->fetchColumn() ?: 0;

        $actualOpening = $openingStockQty + $purchasesBefore - $salesBefore + $adjustmentsBefore + $transferInBefore - $transferOutBefore - $consumptionBefore;

        // Get purchases for selected date (regular + imports)
        $purchaseStmt = $conn->prepare("
            SELECT COALESCE(SUM(pd.quantity), 0) as purchases
            FROM purchase_details pd
            JOIN purchases p ON pd.purchase_id = p.id
            WHERE pd.product_id = ? AND p.location_id = ? AND p.purchase_date = ?
        ");
        $purchaseStmt->execute([$productId, $selectedLocation, $selectedDate]);
        $purchases = $purchaseStmt->fetchColumn();
        $importDayStmt2 = $conn->prepare("
            SELECT COALESCE(SUM(pid.quantity), 0)
            FROM purchase_import_details pid
            JOIN purchase_imports pi ON pid.import_id = pi.id
            WHERE pid.product_id = ? AND pi.location_id = ? AND pi.import_date = ?
        ");
        $importDayStmt2->execute([$productId, $selectedLocation, $selectedDate]);
        $purchases += $importDayStmt2->fetchColumn() ?: 0;

        // Get sales for selected date
        $salesStmt = $conn->prepare("
            SELECT COALESCE(SUM(sd.quantity), 0) as sales
            FROM sale_details sd
            JOIN sales s ON sd.sale_id = s.id
            WHERE sd.product_id = ? AND s.location_id = ? AND s.sale_date = ?
        ");
        $salesStmt->execute([$productId, $selectedLocation, $selectedDate]);
        $sales = $salesStmt->fetchColumn();

        // Get adjustments for selected date
        $adjDayStmt = $conn->prepare("
            SELECT COALESCE(SUM(CASE WHEN sad.adjustment_type = 'increase' THEN sad.quantity ELSE -sad.quantity END), 0)
            FROM stock_adjustment_details sad
            JOIN stock_adjustments sa ON sad.adjustment_id = sa.id
            WHERE sad.product_id = ? AND sa.location_id = ? AND sa.adjustment_date = ?
        ");
        $adjDayStmt->execute([$productId, $selectedLocation, $selectedDate]);
        $adjustmentsDay = $adjDayStmt->fetchColumn() ?: 0;

        // Get transfer in for selected date
        $transferInDayStmt = $conn->prepare("
            SELECT COALESCE(SUM(td.received_quantity), 0)
            FROM stock_transfer_details td
            JOIN stock_transfers t ON td.transfer_id = t.id
            WHERE td.product_id = ? AND t.to_location_id = ? AND t.status = 'received' AND DATE(t.received_at) = ?
        ");
        $transferInDayStmt->execute([$productId, $selectedLocation, $selectedDate]);
        $transferIn = $transferInDayStmt->fetchColumn() ?: 0;

        // Get transfer out for selected date
        $transferOutDayStmt = $conn->prepare("
            SELECT COALESCE(SUM(td.dispatched_quantity), 0)
            FROM stock_transfer_details td
            JOIN stock_transfers t ON td.transfer_id = t.id
            WHERE td.product_id = ? AND t.from_location_id = ? AND t.status IN ('in_transit', 'received') AND DATE(t.dispatched_at) = ?
        ");
        $transferOutDayStmt->execute([$productId, $selectedLocation, $selectedDate]);
        $transferOut = $transferOutDayStmt->fetchColumn() ?: 0;

        // Get consumption for selected date
        $consumptionDayStmt = $conn->prepare("
            SELECT COALESCE(SUM(cvd.quantity), 0)
            FROM consumption_voucher_details cvd
            JOIN consumption_vouchers cv ON cvd.voucher_id = cv.id
            WHERE cvd.product_id = ? AND cv.location_id = ? AND cv.voucher_date = ?
        ");
        $consumptionDayStmt->execute([$productId, $selectedLocation, $selectedDate]);
        $consumptionDay = $consumptionDayStmt->fetchColumn() ?: 0;

        $closing = $actualOpening + $purchases - $sales + $adjustmentsDay + $transferIn - $transferOut - $consumptionDay;

        $product['opening'] = $actualOpening;
        $product['purchases'] = $purchases;
        $product['sales'] = $sales;
        $product['adjustments'] = $adjustmentsDay;
        $product['transfer_in'] = $transferIn;
        $product['transfer_out'] = $transferOut;
        $product['consumption'] = $consumptionDay;
        $product['closing'] = $closing;

        $products[] = $product;
    }
}
?>

<div class="row mb-3">
    <div class="col-md-6">
        <h2><i class="bi bi-clipboard-check"></i> <?php echo __('stock_taking_title'); ?></h2>
    </div>
    <div class="col-md-6 text-end">
        <a href="stock_taking_list.php" class="btn btn-secondary">
            <i class="bi bi-list"></i> <?php echo __('view_all_stock_takings'); ?>
        </a>
    </div>
</div>

<!-- Location, Calculation Method & Date Selection -->
<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="" class="row g-3" id="stockTakingFilterForm">
            <div class="col-md-4">
                <label for="location" class="form-label required"><?php echo __('location'); ?></label>
                <select name="location" id="location" class="form-select" required>
                    <option value=""><?php echo __('select_location'); ?></option>
                    <?= renderTreeOptions($locations, $selectedLocation) ?>
                </select>
            </div>
            <div class="col-md-3">
                <label for="method" class="form-label required"><?php echo __('calculation_method'); ?></label>
                <select name="method" id="method" class="form-select" required>
                    <option value="FIFO" <?php echo $selectedMethod == 'FIFO' ? 'selected' : ''; ?>><?php echo __('fifo_method'); ?></option>
                    <option value="LIFO" <?php echo $selectedMethod == 'LIFO' ? 'selected' : ''; ?>><?php echo __('lifo_method'); ?></option>
                    <option value="AVERAGE" <?php echo $selectedMethod == 'AVERAGE' ? 'selected' : ''; ?>><?php echo __('average_cost'); ?></option>
                </select>
                <small class="text-muted"><?php echo __('for_variance_calculation'); ?></small>
            </div>
            <div class="col-md-3">
                <label for="date" class="form-label required"><?php echo __('stock_date'); ?></label>
                <input type="date" name="date" id="date" class="form-control"
                       value="<?php echo $selectedDate; ?>" required max="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-arrow-clockwise"></i> <?php echo __('load'); ?>
                </button>
            </div>

            <!-- Product filter row -->
            <div class="col-md-4">
                <label for="filter_category" class="form-label">Category</label>
                <select name="category" id="filter_category" class="form-select">
                    <option value="">-- All Categories --</option>
                    <?php foreach ($filterCategories as $cat): ?>
                        <option value="<?php echo (int)$cat['id']; ?>" <?php echo $selectedCategory === (int)$cat['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cat['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">Pick a category to load all its products</small>
            </div>
            <div class="col-md-6">
                <label for="filter_products" class="form-label">Products</label>
                <select name="products[]" id="filter_products" class="form-select" multiple>
                    <?php foreach ($preselectedProductOptions as $pid => $pname): ?>
                        <option value="<?php echo (int)$pid; ?>" selected>
                            <?php echo htmlspecialchars($pname); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">Type to search. Pick one or more to load only those.</small>
            </div>
            <div class="col-md-2 d-flex align-items-center">
                <div class="form-check mt-3">
                    <input type="checkbox" class="form-check-input" id="filter_show_zero" name="show_zero" value="1" <?php echo $showZero ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="filter_show_zero">Show zero-stock products</label>
                </div>
            </div>
        </form>
    </div>
</div>

<?php if ($existingStockTaking): ?>
<!-- Existing Stock Taking — info only; entry form still renders below -->
<div class="alert alert-info">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <i class="bi bi-info-circle"></i>
            Existing stock taking <strong><?php echo htmlspecialchars($existingStockTaking['stock_taking_number']); ?></strong>
            found for this location and date (method: <strong><?php echo htmlspecialchars($existingStockTaking['calculation_method']); ?></strong>).
            Saved physical counts are pre-filled below — edit any value to overwrite, leave others as-is to keep them, or add new products via the filter to append.
        </div>
        <a href="stock_taking_view.php?id=<?php echo $existingStockTaking['id']; ?>" class="btn btn-sm btn-outline-primary ms-3">
            <i class="bi bi-eye"></i> <?php echo __('view_stock_taking'); ?>
        </a>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($products)): ?>
<!-- Stock Taking Entry Form -->
<form method="POST" action="" id="stockTakingForm">
    <?php echo csrfField(); ?>
    <input type="hidden" name="location_id" value="<?php echo $selectedLocation; ?>">
    <input type="hidden" name="stock_date" value="<?php echo $selectedDate; ?>">
    <input type="hidden" name="calculation_method" value="<?php echo $selectedMethod; ?>">
    
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <?php echo __('enter_physical_stock_count'); ?> 
                <span class="badge bg-info"><?php echo sprintf(__('method_label'), $selectedMethod); ?></span>
            </h5>
            <div>
                <button type="button" class="btn btn-sm btn-secondary" onclick="clearAll()">
                    <i class="bi bi-x-circle"></i> <?php echo __('clear_all'); ?>
                </button>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0" id="stockTakingTable">
                    <thead class="table-light sticky-top">
                        <tr>
                            <th style="width: 3%;"><?php echo __('sr'); ?></th>
                            <th style="width: 16%;"><?php echo __('product'); ?></th>
                            <th style="width: 10%;"><?php echo __('category'); ?></th>
                            <th style="width: 6%;"><?php echo __('unit'); ?></th>
                            <th style="width: 7%;" class="text-end"><?php echo __('opening'); ?></th>
                            <th style="width: 7%;" class="text-end"><?php echo __('purchase'); ?></th>
                            <th style="width: 7%;" class="text-end"><?php echo __('sales'); ?></th>
                            <th style="width: 6%;" class="text-end"><?php echo __('adjustment'); ?></th>
                            <th style="width: 6%;" class="text-end"><?php echo __('transfer_in'); ?></th>
                            <th style="width: 6%;" class="text-end"><?php echo __('transfer_out'); ?></th>
                            <th style="width: 6%;" class="text-end"><?php echo __('consumption_voucher'); ?></th>
                            <th style="width: 6%;" class="text-end"><?php echo __('closing'); ?></th>
                            <th style="width: 10%;"><?php echo __('physical_stock'); ?></th>
                            <th style="width: 6%;" class="text-end"><?php echo __('variance'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $currentCategory = '';
                        $rowNum = 0;
                        foreach ($products as $index => $product): 
                            $rowNum++;
                            
                            // Category separator
                            if ($currentCategory != $product['category_name']):
                                $currentCategory = $product['category_name'];
                        ?>
                        <tr class="table-secondary">
                            <td colspan="14">
                                <strong><i class="bi bi-tag"></i> <?php echo htmlspecialchars($currentCategory ?: __('uncategorized')); ?></strong>
                            </td>
                        </tr>
                        <?php endif; ?>
                        
                        <tr>
                            <td><?php echo $rowNum; ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($product['name']); ?></strong>
                                <input type="hidden" name="products[]" value="<?php echo $product['id']; ?>">
                            </td>
                            <td><?php echo htmlspecialchars($product['category_name'] ?: '-'); ?></td>
                            <td><?php echo htmlspecialchars($product['unit']); ?></td>
                            <td class="text-end">
                                <span class="badge bg-secondary"><?php echo formatNumber($product['opening'], 2); ?></span>
                            </td>
                            <td class="text-end">
                                <span class="badge bg-success"><?php echo formatNumber($product['purchases'], 2); ?></span>
                            </td>
                            <td class="text-end">
                                <span class="badge bg-danger"><?php echo formatNumber($product['sales'], 2); ?></span>
                            </td>
                            <td class="text-end">
                                <?php if ($product['adjustments'] > 0): ?>
                                    <span class="badge bg-info">+<?php echo formatNumber($product['adjustments'], 2); ?></span>
                                <?php elseif ($product['adjustments'] < 0): ?>
                                    <span class="badge bg-warning"><?php echo formatNumber($product['adjustments'], 2); ?></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <span class="badge bg-primary"><?php echo formatNumber($product['transfer_in'], 2); ?></span>
                            </td>
                            <td class="text-end">
                                <span class="badge bg-secondary"><?php echo formatNumber($product['transfer_out'], 2); ?></span>
                            </td>
                            <td class="text-end">
                                <?php if ($product['consumption'] > 0): ?>
                                    <span class="badge bg-danger"><?php echo formatNumber($product['consumption'], 2); ?></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <strong class="closing-qty" data-closing="<?php echo $product['closing']; ?>">
                                    <?php echo formatNumber($product['closing'], 2); ?>
                                </strong>
                            </td>
                            <td>
                                <?php
                                // Pre-fill from existing stock_taking_details when a header
                                // exists for this (location, date). Empty/0 user input on
                                // submit is treated as 0 (a real value), not as "skip".
                                $prefillPhysical = '';
                                if (isset($existingPhysicalByProduct[(int)$product['id']])) {
                                    $prefillPhysical = rtrim(rtrim(number_format((float)$existingPhysicalByProduct[(int)$product['id']], 3, '.', ''), '0'), '.');
                                    if ($prefillPhysical === '') $prefillPhysical = '0';
                                }
                                ?>
                                <input
                                    type="number"
                                    step="0.01"
                                    class="form-control form-control-sm physical-input"
                                    name="physical_qty[]"
                                    value="<?php echo htmlspecialchars($prefillPhysical); ?>"
                                    data-row="<?php echo $index; ?>"
                                    data-closing="<?php echo $product['closing']; ?>"
                                    placeholder="<?php echo __('enter_count'); ?>"
                                    oninput="calculateVariance(<?php echo $index; ?>)"
                                >
                            </td>
                            <td class="text-end">
                                <span class="variance-display" id="variance-<?php echo $index; ?>">-</span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Cash Reconciliation hidden on entry page; submits actual_cash_amount=0.
         To restore: remove the `if (false):` wrapper below. -->
    <input type="hidden" name="actual_cash_amount" value="0">
    <?php if (false): // Cash Reconciliation Section — temporarily hidden ?>
    <div class="card mb-3">
        <div class="card-header bg-info text-white">
            <h5 class="mb-0"><i class="bi bi-cash-stack"></i> <?php echo __('cash_reconciliation'); ?></h5>
        </div>
        <div class="card-body">
            <div class="row align-items-end">
                <div class="col-md-4">
                    <label class="form-label"><?php echo __('expected_sales_system'); ?></label>
                    <div class="input-group">
                        <span class="input-group-text"><?php echo $defaultCurrencySymbol; ?></span>
                        <input type="text" class="form-control form-control-lg"
                               value="<?php echo formatNumber($expectedSalesAmount, 2); ?>"
                               readonly>
                    </div>
                    <small class="text-muted"><?php echo sprintf(__('total_sales_for_date'), formatDate($selectedDate)); ?></small>
                </div>
                <div class="col-md-4">
                    <label class="form-label required"><?php echo __('actual_cash_in_hand'); ?></label>
                    <div class="input-group">
                        <span class="input-group-text"><?php echo $defaultCurrencySymbol; ?></span>
                        <input type="number" step="0.01" class="form-control form-control-lg"
                               name="actual_cash_amount" id="actualCash"
                               placeholder="0.00" required
                               oninput="calculateCashVariance()">
                    </div>
                    <small class="text-muted"><?php echo __('enter_actual_cash'); ?></small>
                </div>
                <div class="col-md-4">
                    <label class="form-label"><?php echo __('cash_variance'); ?></label>
                    <div class="input-group">
                        <span class="input-group-text"><?php echo $defaultCurrencySymbol; ?></span>
                        <input type="text" class="form-control form-control-lg fw-bold"
                               id="cashVariance" value="0.00" readonly>
                    </div>
                    <small class="text-muted" id="varianceHelp"><?php echo __('actual_minus_expected'); ?></small>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- E-Signature: Prepared By -->
    <?php
    // Load existing prepared_by_signature for edit/re-save flow
    $existingPreparedSig = null;
    if ($existingStockTaking) {
        $sigStmt = $conn->prepare("SELECT prepared_by_signature FROM stock_taking WHERE id = ?");
        $sigStmt->execute([$existingStockTaking['id']]);
        $existingPreparedSig = $sigStmt->fetchColumn() ?: null;
    }
    ?>
    <div class="card mb-3">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-pen"></i> <?php _e('prepared_by'); ?> <small class="text-muted">(optional e-signature)</small></h5>
        </div>
        <?php $myMasterSig = getUserSignature($conn, getUserId()); ?>
        <?php if ($myMasterSig): ?>
        <div class="card-body">
            <img src="<?php echo htmlspecialchars($myMasterSig); ?>" alt="<?php _e('signature'); ?>" style="max-height: 90px; max-width: 100%;">
            <div class="text-muted small mt-1"><i class="bi bi-info-circle"></i> <?php _e('signature_from_profile'); ?></div>
        </div>
        <?php else: ?>
        <div class="card-body">
            <style>
                .signature-wrapper { border: 1px dashed #adb5bd; border-radius: 4px; background: #fafafa; position: relative; max-width: 420px; }
                .signature-canvas { display: block; width: 100%; height: 140px; touch-action: none; cursor: crosshair; }
                .signature-placeholder { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: #adb5bd; font-style: italic; pointer-events: none; user-select: none; font-size: 13px; }
            </style>
            <div class="signature-wrapper">
                <canvas id="preparedBySignature" class="signature-canvas"></canvas>
                <span class="signature-placeholder" id="preparedBySignaturePlaceholder">Sign here</span>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary mt-1" onclick="clearPreparedSig()">
                <i class="bi bi-x-circle"></i> <?php _e('clear'); ?>
            </button>
            <?php
            /* Not everyone has a touchscreen. Offer an image upload as an alternative
               to drawing; it is loaded INTO the pad above, so the submit code is
               unchanged. Only reachable in this else-branch, i.e. only for signers
               with no signature on file. */
            $sigUploadCanvasId      = 'preparedBySignature';
            $sigUploadPlaceholderId = 'preparedBySignaturePlaceholder';
            require __DIR__ . '/../../includes/signature_upload_control.php';
            ?>
            <input type="hidden" name="prepared_by_signature" id="preparedBySignatureInput" value="">
        </div>
        <?php endif; ?>
    </div>

    <!-- Submit Section -->
    <div class="card">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center">
                <div class="alert alert-info mb-0 flex-grow-1 me-3">
                    <i class="bi bi-info-circle"></i>
                    <?php echo sprintf(__('stock_taking_instructions'), $selectedMethod); ?>
                </div>
                <?php if (hasPermission('stock_taking', 'create')): ?>
                <button type="submit" class="btn btn-success btn-lg">
                    <i class="bi bi-check-circle"></i> <?php echo __('submit_stock_taking'); ?>
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</form>

<script src="<?php echo BASE_URL; ?>assets/js/signature_pad.js"></script>
<script>
let preparedSigPad = null;
const existingPreparedSigData = <?php echo json_encode($existingPreparedSig); ?>;

function clearPreparedSig() {
    if (preparedSigPad) {
        preparedSigPad.clear();
        document.getElementById('preparedBySignaturePlaceholder').style.display = '';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const canvas = document.getElementById('preparedBySignature');
    if (canvas && window.SignaturePad) {
        preparedSigPad = new SignaturePad(canvas, { penColor: '#000', lineWidth: 2 });
        if (existingPreparedSigData) {
            preparedSigPad.fromDataURL(existingPreparedSigData);
            document.getElementById('preparedBySignaturePlaceholder').style.display = 'none';
        }
        canvas.addEventListener('pointerdown', function() {
            document.getElementById('preparedBySignaturePlaceholder').style.display = 'none';
        });
    }
});

// Capture signature into hidden input right before form submit
document.getElementById('stockTakingForm')?.addEventListener('submit', function() {
    const input = document.getElementById('preparedBySignatureInput');
    if (input && preparedSigPad && !preparedSigPad.isEmpty()) {
        input.value = preparedSigPad.toDataURL();
    }
});
</script>

<script>
const expectedSales = <?php echo $expectedSalesAmount; ?>;

function calculateVariance(rowIndex) {
    const physicalInput = document.querySelector(`input[data-row="${rowIndex}"]`);
    const closing = parseFloat(physicalInput.dataset.closing) || 0;
    const physical = parseFloat(physicalInput.value) || 0;
    const variance = physical - closing;
    
    const varianceDisplay = document.getElementById(`variance-${rowIndex}`);
    
    if (physicalInput.value === '') {
        varianceDisplay.textContent = '-';
        varianceDisplay.className = 'variance-display';
    } else {
        varianceDisplay.textContent = formatNumber(variance, 2);
        
        if (Math.abs(variance) < 0.01) {
            varianceDisplay.className = 'variance-display badge bg-success';
            varianceDisplay.textContent = '0.00';
        } else if (variance > 0) {
            varianceDisplay.className = 'variance-display badge bg-info';
            varianceDisplay.textContent = '+' + formatNumber(variance, 2);
        } else {
            varianceDisplay.className = 'variance-display badge bg-danger';
        }
    }
}

const langPerfectMatch = <?php echo json_encode(__('perfect_match')); ?>;
const langSurplusCash = <?php echo json_encode(__('surplus_cash')); ?>;
const langCashShortage = <?php echo json_encode(__('cash_shortage')); ?>;
const langClearAllConfirm = <?php echo json_encode(__('clear_all_confirm')); ?>;

function calculateCashVariance() {
    const actualCash = parseFloat(document.getElementById('actualCash').value) || 0;
    const variance = actualCash - expectedSales;
    
    const varianceInput = document.getElementById('cashVariance');
    const varianceHelp = document.getElementById('varianceHelp');
    
    varianceInput.value = formatNumber(variance, 2);
    
    if (Math.abs(variance) < 0.01) {
        varianceInput.className = 'form-control form-control-lg fw-bold text-success';
        varianceHelp.textContent = langPerfectMatch;
        varianceHelp.className = 'text-success';
    } else if (variance > 0) {
        varianceInput.className = 'form-control form-control-lg fw-bold text-info';
        varianceHelp.textContent = langSurplusCash;
        varianceHelp.className = 'text-info';
    } else {
        varianceInput.className = 'form-control form-control-lg fw-bold text-danger';
        varianceHelp.textContent = langCashShortage;
        varianceHelp.className = 'text-danger';
    }
}

function clearAll() {
    if (confirm(langClearAllConfirm)) {
        document.querySelectorAll('.physical-input').forEach(input => {
            input.value = '';
        });
        document.querySelectorAll('.variance-display').forEach(el => {
            el.textContent = '-';
            el.className = 'variance-display';
        });
        const actualCashEl = document.getElementById('actualCash');
        if (actualCashEl) {
            actualCashEl.value = '';
            calculateCashVariance();
        }
    }
}

// Confirm before leaving if data entered
let dataEntered = false;
document.querySelectorAll('.physical-input, #actualCash').forEach(input => {
    input.addEventListener('input', function() {
        if (this.value) dataEntered = true;
    });
});

window.addEventListener('beforeunload', function(e) {
    if (dataEntered) {
        e.preventDefault();
        e.returnValue = '';
    }
});

document.getElementById('stockTakingForm')?.addEventListener('submit', function() {
    dataEntered = false;
});

// Recalculate variance for any inputs that arrived pre-filled (existing
// stock_taking_details rows being shown for edit). Runs once on load.
document.querySelectorAll('.physical-input').forEach(function(input) {
    if (input.value !== '') {
        calculateVariance(parseInt(input.dataset.row, 10));
    }
});
</script>

<?php elseif ($selectedLocation && $needsFilter): ?>
<div class="alert alert-warning">
    <i class="bi bi-funnel"></i>
    <strong>Please choose a filter to load products.</strong>
    Pick a <em>Category</em> or one or more <em>Products</em> above, then click Load.
    This avoids loading the full catalogue at once.
</div>
<?php elseif ($selectedLocation && $hasFilter): ?>
<div class="alert alert-info">
    <i class="bi bi-info-circle"></i>
    No products matched your filter<?php echo $showZero ? '' : ' (zero-stock products are hidden — tick "Show zero-stock products" to include them)'; ?>.
</div>
<?php elseif ($selectedLocation): ?>
<div class="alert alert-info">
    <i class="bi bi-info-circle"></i> <?php echo __('no_products_add_first'); ?>
    <a href="../masters/products.php"><?php echo __('products_management'); ?></a>.
</div>
<?php endif; ?>

<style>
.sticky-top {
    position: sticky;
    top: 0;
    z-index: 10;
}

.table-responsive {
    max-height: 600px;
    overflow-y: auto;
}

.physical-input:focus {
    border-color: #0d6efd;
    box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
}

@media (max-width: 768px) {
    .table-responsive {
        font-size: 0.875rem;
    }

    .table th, .table td {
        padding: 0.5rem 0.25rem;
    }
}
</style>

<script>
// Product filter (Select2 + AJAX). jQuery and Select2 are loaded by the footer,
// so wait for window.load to ensure both are available before initializing.
window.addEventListener('load', function() {
    if (!window.jQuery || !$.fn.select2) return;

    (function($) {
        var $location = $('#location');
        var $date     = $('#date');
        var $cat      = $('#filter_category');
        var $showZero = $('#filter_show_zero');
        var $products = $('#filter_products');
        if (!$products.length) return;

        $products.select2({
            width: '100%',
            placeholder: 'Type to search products...',
            allowClear: true,
            minimumInputLength: 0,
            ajax: {
                url: BASE_URL + 'api/products-search.php',
                dataType: 'json',
                delay: 250,
                data: function(params) {
                    return {
                        q: params.term || '',
                        page: params.page || 1,
                        location_id: $location.val() || '',
                        date: $date.val() || '',
                        category_id: $cat.val() || '',
                        nonzero: $showZero.is(':checked') ? 0 : 1
                    };
                },
                processResults: function(data, params) {
                    params.page = params.page || 1;
                    return {
                        results: data.results || [],
                        pagination: data.pagination || { more: false }
                    };
                },
                cache: true
            }
        });

        // Category / show-zero changes alter the search universe — clear
        // explicit picks so stale rows don't sneak through.
        $cat.on('change',      function() { $products.val(null).trigger('change'); });
        $showZero.on('change', function() { $products.val(null).trigger('change'); });

        // Keep the URL clean: don't submit empty filter params.
        $('#stockTakingFilterForm').on('submit', function() {
            if ($cat.val() === '' || $cat.val() === null) {
                $cat.prop('disabled', true);
            }
            var picks = $products.val();
            if (!picks || picks.length === 0) {
                $products.prop('disabled', true);
            }
            if (!$showZero.is(':checked')) {
                $showZero.prop('disabled', true);
            }
        });
    })(window.jQuery);
});
</script>

<?php require_once '../../includes/footer.php'; ?>
