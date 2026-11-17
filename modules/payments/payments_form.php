<?php
require_once '../../config/database.php';
require_once '../../includes/functions.php';
$pageTitle = __('payment_form');

$isEdit = isset($_GET['id']);
$requiredPermission = $isEdit ? 'edit' : 'create';

if (!hasPermission('payments', $requiredPermission)) {
    setAlert('You do not have permission to access this page', 'danger');
    redirect('modules/payments/payments.php');
}

// Get user locations
$userLocations = getUserLocations();

$payment = [
    'payment_date' => date('Y-m-d'),
    'from_account_id' => 0,
    'to_account_id' => 0,
    'location_id' => (count($userLocations) == 1) ? $userLocations[0]['id'] : '',
    'amount' => '',
    'payment_mode' => 'cash',
    'reference_number' => '',
    'remarks' => ''
];
$errors = [];

if ($isEdit) {
    $id = (int)$_GET['id'];
    $stmt = $conn->prepare("SELECT * FROM payments WHERE id = ?");
    $stmt->execute([$id]);
    $payment = $stmt->fetch();
    
    if (!$payment) {
        setAlert(__('payment_not_found'), 'danger');
        redirect('modules/payments/payments.php');
    }
}

// Get cash/bank accounts
$fromAccountsStmt = $conn->query("
    SELECT id, name FROM ledger_accounts 
    WHERE account_type IN ('cash', 'bank') AND is_active = 1
    ORDER BY name
");
$fromAccounts = $fromAccountsStmt->fetchAll();

// Get suppliers with their ledger accounts
$suppliersStmt = $conn->query("
    SELECT s.id, la.name, la.id as ledger_account_id
    FROM suppliers s
    INNER JOIN ledger_accounts la ON s.ledger_account_id = la.id
    WHERE s.is_active = 1
    ORDER BY la.name
");
$suppliers = $suppliersStmt->fetchAll();

// Get expense accounts
$expenseAccountsStmt = $conn->query("
    SELECT id, name FROM ledger_accounts 
    WHERE account_type = 'expense' AND is_active = 1
    ORDER BY name
");
$expenseAccounts = $expenseAccountsStmt->fetchAll();

// Get currencies
$currenciesStmt = $conn->query("SELECT * FROM currencies WHERE is_active = 1 ORDER BY is_default DESC, code ASC");
$allCurrencies = $currenciesStmt->fetchAll();
$defaultCurrency = getDefaultCurrency();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    checkCSRF();
    
    $paymentDate = clean($_POST['payment_date']);
    $fromAccountId = $_POST['from_account_id'] ?? '';
    $newFromAccountName = cleanText($_POST['new_from_account_name'] ?? '');
    $fromAccountType = clean($_POST['from_account_type'] ?? 'cash');
    $toAccountId = $_POST['to_account_id'] ?? '';
    $accountCategory = clean($_POST['account_category'] ?? 'supplier'); // 'supplier' or 'expense'
    $newAccountName = cleanText($_POST['new_account_name'] ?? '');
    $locationId = !empty($_POST['location_id']) ? (int)$_POST['location_id'] : null;
    $costCentreId = !empty($_POST['cost_centre_id']) ? (int)$_POST['cost_centre_id'] : null;
    $amount = floatval($_POST['amount'] ?? 0);
    $paymentMode = clean($_POST['payment_mode']);
    $referenceNumber = clean($_POST['reference_number'] ?? '');
    $remarks = clean($_POST['remarks'] ?? '');
    $currencyId = !empty($_POST['currency_id']) ? (int)$_POST['currency_id'] : ($defaultCurrency ? $defaultCurrency['id'] : null);
    $exchangeRateToBase = !empty($_POST['exchange_rate_to_base']) ? floatval($_POST['exchange_rate_to_base']) : 1;

    // Validation
    if (empty($paymentDate)) {
        $errors[] = __('payment_date_required');
    }
    
    // Handle new FROM account (Cash/Bank) creation
    if ($fromAccountId === 'new' || $fromAccountId === 'new_cash' || $fromAccountId === 'new_bank') {
        if (empty($newFromAccountName)) {
            $errors[] = __('account_name_required_cash_bank');
        } else {
            try {
                // Check if account name already exists
                $checkStmt = $conn->prepare("SELECT id FROM ledger_accounts WHERE name = ?");
                $checkStmt->execute([$newFromAccountName]);
                $existing = $checkStmt->fetch();
                
                if ($existing) {
                    $errors[] = __('account_exists');
                } else {
                    // Create new Cash/Bank account
                    $defaultCurrency = getDefaultCurrency();
                    $stmt = $conn->prepare("
                        INSERT INTO ledger_accounts
                        (name, account_type, opening_balance, current_balance, currency_id, is_active)
                        VALUES (?, ?, 0, 0, ?, 1)
                    ");
                    $stmt->execute([$newFromAccountName, $fromAccountType, $defaultCurrency['id']]);
                    $fromAccountId = $conn->lastInsertId();
                    
                    logAudit('ledger_accounts', 'create', $fromAccountId, null, [
                        'name' => $newFromAccountName, 
                        'type' => $fromAccountType, 
                        'created_via' => 'payment_form'
                    ]);
                }
            } catch (PDOException $e) {
                $errors[] = 'Error creating new account: ' . $e->getMessage();
            }
        }
    } else {
        $fromAccountId = (int)$fromAccountId;
        if (empty($fromAccountId)) {
            $errors[] = __('from_account_required');
        }
    }
    
    // Handle new account creation or selection
    if ($toAccountId === 'new' || $toAccountId === 'new_expense') {
        if (empty($newAccountName)) {
            $errors[] = __('account_name_required');
        } else {
            try {
                $conn->beginTransaction();
                
                if ($accountCategory == 'supplier') {
                    // Check if supplier name already exists in ledger
                    $checkStmt = $conn->prepare("SELECT id FROM ledger_accounts WHERE name = ?");
                    $checkStmt->execute([$newAccountName]);
                    $existing = $checkStmt->fetch();
                    
                    if ($existing) {
                        $errors[] = __('supplier_exists');
                        $conn->rollBack();
                    } else {
                        // Step 1: Create ledger account
                        $defaultCurrency = getDefaultCurrency();
                        $stmt = $conn->prepare("
                            INSERT INTO ledger_accounts
                            (name, account_type, opening_balance, current_balance, currency_id, is_active)
                            VALUES (?, 'supplier', 0, 0, ?, 1)
                        ");
                        $stmt->execute([$newAccountName, $defaultCurrency['id']]);
                        $ledgerAccountId = $conn->lastInsertId();
                        
                        // Step 2: Create supplier
                        $stmt = $conn->prepare("
                            INSERT INTO suppliers (ledger_account_id, is_active)
                            VALUES (?, 1)
                        ");
                        $stmt->execute([$ledgerAccountId]);
                        $supplierId = $conn->lastInsertId();
                        
                        // Step 3: Update ledger account with reference_id
                        $stmt = $conn->prepare("UPDATE ledger_accounts SET reference_id = ? WHERE id = ?");
                        $stmt->execute([$supplierId, $ledgerAccountId]);
                        
                        $toAccountId = $ledgerAccountId;
                        $conn->commit();
                        
                        logAudit('suppliers', 'create', $supplierId, null, ['name' => $newAccountName, 'created_via' => 'payment_form']);
                    }
                } else {
                    // Create expense account
                    $checkStmt = $conn->prepare("SELECT id FROM ledger_accounts WHERE name = ? AND account_type = 'expense'");
                    $checkStmt->execute([$newAccountName]);
                    $existing = $checkStmt->fetch();
                    
                    if ($existing) {
                        $toAccountId = $existing['id'];
                        $conn->rollBack();
                    } else {
                        $defaultCurrency = getDefaultCurrency();
                        $insertStmt = $conn->prepare("
                            INSERT INTO ledger_accounts (name, account_type, opening_balance, current_balance, currency_id, is_active)
                            VALUES (?, 'expense', 0, 0, ?, 1)
                        ");
                        $insertStmt->execute([$newAccountName, $defaultCurrency['id']]);
                        $toAccountId = $conn->lastInsertId();
                        $conn->commit();
                        
                        logAudit('ledger_accounts', 'create', $toAccountId, null, ['name' => $newAccountName, 'type' => 'expense', 'created_via' => 'payment_form']);
                    }
                }
            } catch (PDOException $e) {
                $conn->rollBack();
                $errors[] = 'Error creating new account: ' . $e->getMessage();
            }
        }
    } else {
        // Existing account selected - it's already a ledger_account_id
        $toAccountId = (int)$toAccountId;
        if (empty($toAccountId)) {
            $errors[] = __('to_account_required');
        }
    }
    
    if ($amount <= 0) {
        $errors[] = __('amount_greater_zero');
    }
    
    if (empty($paymentMode)) {
        $errors[] = __('payment_mode_required');
    }
    
    // Check from and to accounts are different
    if ($fromAccountId == $toAccountId && $fromAccountId > 0) {
        $errors[] = __('accounts_same_error');
    }
    
    if (empty($errors)) {
        try {
            $conn->beginTransaction();
            
            if ($isEdit) {
                // Get old payment data for reversing balance updates
                $oldStmt = $conn->prepare("SELECT * FROM payments WHERE id = ?");
                $oldStmt->execute([$id]);
                $oldPayment = $oldStmt->fetch();
                
                // Reverse old balance changes
                if ($oldPayment) {
                    // Restore old from account balance
                    $stmt = $conn->prepare("UPDATE ledger_accounts SET current_balance = current_balance + ? WHERE id = ?");
                    $stmt->execute([$oldPayment['amount'], $oldPayment['from_account_id']]);
                    
                    // Restore old to account balance
                    $stmt = $conn->prepare("UPDATE ledger_accounts SET current_balance = current_balance - ? WHERE id = ?");
                    $stmt->execute([$oldPayment['amount'], $oldPayment['to_account_id']]);
                }
                
                // Update payment
                $stmt = $conn->prepare("
                    UPDATE payments
                    SET payment_date = ?, from_account_id = ?, to_account_id = ?, location_id = ?,
                        cost_centre_id = ?, currency_id = ?, exchange_rate_to_base = ?,
                        amount = ?, payment_mode = ?, reference_number = ?, remarks = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $paymentDate, $fromAccountId, $toAccountId, $locationId,
                    $costCentreId, $currencyId, $exchangeRateToBase,
                    $amount, $paymentMode, $referenceNumber, $remarks, $id
                ]);
                
                // Apply new balance changes
                // Decrease from account (Cash/Bank)
                $stmt = $conn->prepare("UPDATE ledger_accounts SET current_balance = current_balance - ? WHERE id = ?");
                $stmt->execute([$amount, $fromAccountId]);

                // Increase to account (Supplier/Expense)
                $stmt = $conn->prepare("UPDATE ledger_accounts SET current_balance = current_balance + ? WHERE id = ?");
                $stmt->execute([$amount, $toAccountId]);

                // Rebuild the GL transaction for this payment — drop the old
                // 'payment' transaction (cascades to details) and post a fresh
                // DR to_account / CR from_account pair.
                deleteVoucherTransaction($conn, 'payment', $id);
                createPaymentTransaction($conn, $id);

                logAudit('payments', 'update', $id);
                $conn->commit();
                setAlert(__('payment_updated'), 'success');
            } else {
                // Auto-generate payment number
                $paymentNumber = getNextNumber('payments', 'payment_number', getSetting('payment_prefix', 'PAY'));
                
                // Insert payment
                $stmt = $conn->prepare("
                    INSERT INTO payments
                    (payment_number, payment_date, from_account_id, to_account_id, location_id, cost_centre_id,
                     currency_id, exchange_rate_to_base, amount,
                     payment_mode, reference_number, remarks, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $paymentNumber, $paymentDate, $fromAccountId, $toAccountId, $locationId, $costCentreId,
                    $currencyId, $exchangeRateToBase, $amount,
                    $paymentMode, $referenceNumber, $remarks, getUserId()
                ]);
                $newId = $conn->lastInsertId();
                
                // Update ledger account balances
                // Decrease from account (Cash/Bank)
                $stmt = $conn->prepare("UPDATE ledger_accounts SET current_balance = current_balance - ? WHERE id = ?");
                $stmt->execute([$amount, $fromAccountId]);

                // Increase to account (Supplier/Expense) - We owe them more
                $stmt = $conn->prepare("UPDATE ledger_accounts SET current_balance = current_balance + ? WHERE id = ?");
                $stmt->execute([$amount, $toAccountId]);

                // Post the GL transaction: DR to_account / CR from_account
                // (matches the balance updates above; lets payables/receivables
                // dashboards read directly from transaction_details).
                createPaymentTransaction($conn, $newId);

                logAudit('payments', 'create', $newId);
                $conn->commit();
                setAlert(__('payment_created'), 'success');
            }
            
            redirect('modules/payments/payments.php');
        } catch (PDOException $e) {
            $conn->rollBack();
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

require_once '../../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-10">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="bi bi-arrow-up-circle"></i>
                    <?php echo $isEdit ? __('edit_payment') : __('add_new_payment'); ?>
                </h5>
            </div>
            <div class="card-body">
                <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                        <li><?php echo $error; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                
                <form method="POST" action="" id="paymentForm">
                    <?php echo csrfField(); ?>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="payment_date" class="form-label required"><?php _e('payment_date'); ?></label>
                            <input
                                type="date"
                                class="form-control form-control-lg"
                                id="payment_date"
                                name="payment_date"
                                value="<?php echo $payment['payment_date']; ?>"
                                required
                            >
                        </div>

                        <div class="col-md-4 mb-3">
                            <label for="location_id" class="form-label"><?php _e('location'); ?></label>
                            <select
                                class="form-select form-select-lg"
                                id="location_id"
                                name="location_id"
                            >
                                <option value=""><?php _e('all_locations'); ?></option>
                                <?= renderTreeOptions($userLocations, isset($payment['location_id']) ? $payment['location_id'] : null) ?>
                            </select>
                            <small class="text-muted"><?php _e('leave_empty_for_all'); ?></small>
                        </div>

                        <div class="col-md-4 mb-3">
                            <label for="cost_centre_id" class="form-label"><?php _e('cost_centre'); ?></label>
                            <select class="form-select form-select-lg" id="cost_centre_id" name="cost_centre_id">
                                <option value=""><?php _e('select_cost_centre'); ?></option>
                                <?= renderTreeOptions(getUserCostCentres(), isset($payment['cost_centre_id']) ? $payment['cost_centre_id'] : null, null, 'name', 'code') ?>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="currency_id" class="form-label"><?php _e('currency'); ?></label>
                            <select class="form-select form-select-lg" id="currency_id" name="currency_id" onchange="onCurrencyChange()">
                                <?php foreach ($allCurrencies as $cur): ?>
                                <option value="<?php echo $cur['id']; ?>"
                                    data-symbol="<?php echo htmlspecialchars($cur['symbol']); ?>"
                                    data-is-default="<?php echo $cur['is_default']; ?>"
                                    <?php echo (isset($payment['currency_id']) && $payment['currency_id'] == $cur['id']) ? 'selected' : ($cur['is_default'] ? 'selected' : ''); ?>>
                                    <?php echo htmlspecialchars($cur['code'] . ' - ' . $cur['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3" id="exchangeRateGroup" style="<?php echo (isset($payment['currency_id']) && $defaultCurrency && $payment['currency_id'] != $defaultCurrency['id']) ? '' : 'display:none;'; ?>">
                            <label for="exchange_rate_to_base" class="form-label"><?php _e('exchange_rate'); ?></label>
                            <input type="number" class="form-control form-control-lg" id="exchange_rate_to_base" name="exchange_rate_to_base"
                                   step="0.00000001" min="0.00000001"
                                   value="<?php echo isset($payment['exchange_rate_to_base']) ? $payment['exchange_rate_to_base'] : '1'; ?>">
                            <small class="text-muted" id="exchangeRateHint"></small>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="from_account_id" class="form-label required"><?php _e('from_account_cash_bank'); ?></label>
                            <select 
                                class="form-select form-select-lg select2-from" 
                                id="from_account_id" 
                                name="from_account_id"
                                required
                            >
                                <option value=""><?php _e('select_or_create'); ?></option>
                                <option value="new_cash">➕ <?php _e('create_new_cash'); ?></option>
                                <option value="new_bank">➕ <?php _e('create_new_bank'); ?></option>
                                <?php foreach ($fromAccounts as $account): ?>
                                <option value="<?php echo $account['id']; ?>" 
                                    <?php echo $payment['from_account_id'] == $account['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($account['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted"><?php _e('money_going_out'); ?></small>
                        </div>
                    </div>
                    
                    <!-- New From Account Name Field (hidden by default) -->
                    <div class="row" id="newFromAccountRow" style="display: none;">
                        <div class="col-md-12 mb-3">
                            <label for="new_from_account_name" class="form-label">
                                <span id="new_from_account_label"><?php _e('new_cash_bank_name'); ?></span>
                            </label>
                            <input 
                                type="text" 
                                class="form-control form-control-lg" 
                                id="new_from_account_name" 
                                name="new_from_account_name" 
                                placeholder="<?php _e('enter_new_account_name'); ?>"
                            >
                            <small class="text-muted"><?php _e('will_create_cash_bank'); ?></small>
                            <input type="hidden" id="from_account_type" name="from_account_type" value="cash">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <label for="to_account_id" class="form-label required"><?php _e('to_account_supplier_expense'); ?></label>
                            <select 
                                class="form-select form-select-lg select2" 
                                id="to_account_id" 
                                name="to_account_id"
                                required
                            >
                                <option value=""><?php _e('select_or_create'); ?></option>
                                <option value="new" data-category="supplier">➕ <?php _e('create_new_supplier'); ?></option>
                                <option value="new_expense" data-category="expense">➕ <?php _e('create_new_expense'); ?></option>
                                <optgroup label="<?php _e('suppliers'); ?>">
                                    <?php foreach ($suppliers as $supplier): ?>
                                    <option value="<?php echo $supplier['ledger_account_id']; ?>" data-category="supplier"
                                        <?php echo $payment['to_account_id'] == $supplier['ledger_account_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($supplier['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <optgroup label="<?php _e('expenses'); ?>">
                                    <?php foreach ($expenseAccounts as $account): ?>
                                    <option value="<?php echo $account['id']; ?>" data-category="expense"
                                        <?php echo $payment['to_account_id'] == $account['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($account['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            </select>
                            <!-- Hidden field to track account category -->
                            <input type="hidden" id="account_category" name="account_category" value="supplier">
                            <small class="text-muted"><?php _e('money_going_to'); ?></small>
                        </div>
                    </div>
                    
                    <!-- New Account Name Field (hidden by default) -->
                    <div class="row" id="newAccountRow" style="display: none;">
                        <div class="col-md-12 mb-3">
                            <label for="new_account_name" class="form-label">
                                <span id="new_account_label"><?php _e('new_supplier_expense_name'); ?></span>
                            </label>
                            <input 
                                type="text" 
                                class="form-control form-control-lg" 
                                id="new_account_name" 
                                name="new_account_name" 
                                placeholder="<?php _e('enter_new_name'); ?>"
                            >
                            <small class="text-muted"><?php _e('will_create_account'); ?></small>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="amount" class="form-label required"><?php _e('amount'); ?></label>
                            <div class="input-group input-group-lg">
                                <?php
                                $amountSymbol = $defaultCurrencySymbol;
                                if (!empty($payment['currency_id'])) {
                                    foreach ($allCurrencies as $cur) {
                                        if ($cur['id'] == $payment['currency_id']) { $amountSymbol = $cur['symbol']; break; }
                                    }
                                }
                                ?>
                                <span class="input-group-text" id="amountCurrencySymbol"><?php echo htmlspecialchars($amountSymbol); ?></span>
                                <input 
                                    type="number" 
                                    step="0.01"
                                    min="0.01"
                                    class="form-control" 
                                    id="amount" 
                                    name="amount" 
                                    value="<?php echo $payment['amount']; ?>"
                                    required
                                    autofocus
                                >
                            </div>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="payment_mode" class="form-label required"><?php _e('payment_mode'); ?></label>
                            <select 
                                class="form-select form-select-lg" 
                                id="payment_mode" 
                                name="payment_mode"
                                required
                            >
                                <option value="cash" <?php echo $payment['payment_mode'] == 'cash' ? 'selected' : ''; ?>>💵 <?php _e('cash'); ?></option>
                                <option value="cheque" <?php echo $payment['payment_mode'] == 'cheque' ? 'selected' : ''; ?>>📝 <?php _e('cheque'); ?></option>
                                <option value="bank_transfer" <?php echo $payment['payment_mode'] == 'bank_transfer' ? 'selected' : ''; ?>>🏦 <?php _e('bank_transfer'); ?></option>
                                <option value="upi" <?php echo $payment['payment_mode'] == 'upi' ? 'selected' : ''; ?>>📱 <?php _e('upi'); ?></option>
                                <option value="card" <?php echo $payment['payment_mode'] == 'card' ? 'selected' : ''; ?>>💳 <?php _e('card'); ?></option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="reference_number" class="form-label"><?php _e('reference_number'); ?></label>
                        <input 
                            type="text" 
                            class="form-control form-control-lg" 
                            id="reference_number" 
                            name="reference_number" 
                            value="<?php echo htmlspecialchars($payment['reference_number'] ?? ''); ?>"
                            placeholder="<?php _e('reference_placeholder'); ?>"
                        >
                    </div>
                    
                    <div class="mb-3">
                        <label for="remarks" class="form-label"><?php _e('remarks'); ?></label>
                        <textarea 
                            class="form-control" 
                            id="remarks" 
                            name="remarks" 
                            rows="2"
                            placeholder="<?php _e('any_additional_notes'); ?>"
                        ><?php echo htmlspecialchars($payment['remarks'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="d-grid gap-2 mt-4">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="bi bi-check-circle"></i>
                            <?php echo $isEdit ? __('update_payment') : __('create_payment'); ?>
                        </button>
                        <a href="payments.php" class="btn btn-secondary btn-lg">
                            <i class="bi bi-x-circle"></i> <?php _e('cancel'); ?>
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

<script>
// Language translations for JavaScript
var LANG = {
    select_or_search: '<?php echo addslashes(__('select_or_search')); ?>',
    new_cash_account_name: '<?php echo addslashes(__('new_cash_account_name')); ?>',
    new_bank_account_name: '<?php echo addslashes(__('new_bank_account_name')); ?>',
    new_supplier_name: '<?php echo addslashes(__('new_supplier_name')); ?>',
    new_expense_name: '<?php echo addslashes(__('new_expense_name')); ?>'
};

// Wait for jQuery to load (it's in footer.php)
document.addEventListener('DOMContentLoaded', function() {
    // Double check jQuery is loaded
    if (typeof jQuery === 'undefined') {
        console.error('jQuery is not loaded!');
        return;
    }
    
    // Initialize Select2 for both dropdowns
    jQuery('#from_account_id').select2({
        placeholder: LANG.select_or_search,
        allowClear: true,
        width: '100%'
    });
    
    jQuery('#to_account_id').select2({
        placeholder: LANG.select_or_search,
        allowClear: true,
        width: '100%'
    });
    
    // Show/hide new FROM account name field (Cash/Bank)
    jQuery('#from_account_id').on('change', function() {
        var selectedValue = jQuery(this).val();
        
        if (selectedValue === 'new_cash') {
            // Creating new cash account
            jQuery('#newFromAccountRow').show();
            jQuery('#new_from_account_name').attr('required', true).focus();
            jQuery('#new_from_account_label').text(LANG.new_cash_account_name);
            jQuery('#from_account_type').val('cash');
        } else if (selectedValue === 'new_bank') {
            // Creating new bank account
            jQuery('#newFromAccountRow').show();
            jQuery('#new_from_account_name').attr('required', true).focus();
            jQuery('#new_from_account_label').text(LANG.new_bank_account_name);
            jQuery('#from_account_type').val('bank');
        } else {
            // Existing account selected
            jQuery('#newFromAccountRow').hide();
            jQuery('#new_from_account_name').attr('required', false).val('');
        }
    });
    
    // Show/hide new TO account name field and set category
    jQuery('#to_account_id').on('change', function() {
        var selectedValue = jQuery(this).val();
        var selectedOption = jQuery(this).find('option:selected');
        var category = selectedOption.data('category');
        
        if (selectedValue === 'new') {
            // Creating new supplier
            jQuery('#newAccountRow').show();
            jQuery('#new_account_name').attr('required', true).focus();
            jQuery('#new_account_label').text(LANG.new_supplier_name);
            jQuery('#account_category').val('supplier');
        } else if (selectedValue === 'new_expense') {
            // Creating new expense
            jQuery('#newAccountRow').show();
            jQuery('#new_account_name').attr('required', true).focus();
            jQuery('#new_account_label').text(LANG.new_expense_name);
            jQuery('#account_category').val('expense');
        } else {
            // Existing account selected
            jQuery('#newAccountRow').hide();
            jQuery('#new_account_name').attr('required', false).val('');
            jQuery('#account_category').val(category || 'supplier');
        }
    });
    
    // Trigger change on page load if editing
    <?php if ($isEdit && isset($payment['to_account_id']) && $payment['to_account_id']): ?>
    jQuery('#to_account_id').trigger('change');
    <?php endif; ?>
    
    <?php if ($isEdit && isset($payment['from_account_id']) && $payment['from_account_id']): ?>
    jQuery('#from_account_id').trigger('change');
    <?php endif; ?>
});

// Currency change handler
const defaultCurrencyId = <?php echo $defaultCurrency ? $defaultCurrency['id'] : 'null'; ?>;

function onCurrencyChange() {
    const select = document.getElementById('currency_id');
    const currId = parseInt(select.value);
    const exchangeRateGroup = document.getElementById('exchangeRateGroup');

    // Keep the Amount prefix in sync with the chosen currency
    const symbolSpan = document.getElementById('amountCurrencySymbol');
    const opt = select.options[select.selectedIndex];
    if (symbolSpan && opt) {
        symbolSpan.textContent = opt.getAttribute('data-symbol') || '';
    }

    if (currId === defaultCurrencyId) {
        exchangeRateGroup.style.display = 'none';
        document.getElementById('exchange_rate_to_base').value = '1';
    } else {
        exchangeRateGroup.style.display = '';
        fetchExchangeRate(currId);
    }
}

async function fetchExchangeRate(currencyId) {
    const paymentDate = document.getElementById('payment_date').value || new Date().toISOString().split('T')[0];
    try {
        const url = `${BASE_URL}api/exchange-rates.php?from_currency_id=${currencyId}&to_currency_id=${defaultCurrencyId}&_=${Date.now()}`;
        const response = await fetch(url);
        if (response.ok) {
            const result = await response.json();
            if (result.success && result.exchange_rates && result.exchange_rates.length > 0) {
                const applicable = result.exchange_rates.filter(r => r.effective_date <= paymentDate);
                if (applicable.length > 0) {
                    document.getElementById('exchange_rate_to_base').value = applicable[0].rate;
                    document.getElementById('exchangeRateHint').textContent =
                        `1 ${applicable[0].from_currency_code} = ${applicable[0].rate} ${applicable[0].to_currency_code}`;
                    return;
                }
            }
            // Try reverse
            const revUrl = `${BASE_URL}api/exchange-rates.php?from_currency_id=${defaultCurrencyId}&to_currency_id=${currencyId}&_=${Date.now()}`;
            const revResponse = await fetch(revUrl);
            if (revResponse.ok) {
                const revResult = await revResponse.json();
                if (revResult.success && revResult.exchange_rates && revResult.exchange_rates.length > 0) {
                    const applicable = revResult.exchange_rates.filter(r => r.effective_date <= paymentDate);
                    if (applicable.length > 0 && parseFloat(applicable[0].rate) !== 0) {
                        const reverseRate = (1 / parseFloat(applicable[0].rate)).toFixed(8);
                        document.getElementById('exchange_rate_to_base').value = reverseRate;
                        document.getElementById('exchangeRateHint').textContent =
                            `1 ${applicable[0].to_currency_code} = ${reverseRate} ${applicable[0].from_currency_code} (reverse)`;
                        return;
                    }
                }
            }
        }
    } catch (error) {
        console.error('Failed to fetch exchange rate:', error);
    }
    document.getElementById('exchangeRateHint').textContent = 'No rate found - please enter manually';
}
</script>
