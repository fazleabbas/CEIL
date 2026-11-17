<?php
require_once '../../config/database.php';
require_once '../../includes/functions.php';
$pageTitle = __('receipt_form');

$isEdit = isset($_GET['id']);
$requiredPermission = $isEdit ? 'edit' : 'create';

if (!hasPermission('receipts', $requiredPermission)) {
    setAlert(__('no_permission'), 'danger');
    redirect('modules/payments/receipts.php');
}

// Get user locations
$userLocations = getUserLocations();

$receipt = [
    'receipt_date' => date('Y-m-d'),
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
    $stmt = $conn->prepare("SELECT * FROM receipts WHERE id = ?");
    $stmt->execute([$id]);
    $receipt = $stmt->fetch();
    
    if (!$receipt) {
        setAlert(__('receipt_not_found'), 'danger');
        redirect('modules/payments/receipts.php');
    }
}

// Get cash/bank accounts
$toAccountsStmt = $conn->query("
    SELECT id, name FROM ledger_accounts 
    WHERE account_type IN ('cash', 'bank') AND is_active = 1
    ORDER BY name
");
$toAccounts = $toAccountsStmt->fetchAll();

// Get customers with their ledger accounts
$customersStmt = $conn->query("
    SELECT c.id, la.name, la.id as ledger_account_id
    FROM customers c
    INNER JOIN ledger_accounts la ON c.ledger_account_id = la.id
    WHERE c.is_active = 1
    ORDER BY la.name
");
$customers = $customersStmt->fetchAll();

// Get currencies
$currenciesStmt = $conn->query("SELECT * FROM currencies WHERE is_active = 1 ORDER BY is_default DESC, code ASC");
$allCurrencies = $currenciesStmt->fetchAll();
$defaultCurrency = getDefaultCurrency();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    checkCSRF();
    
    $receiptDate = clean($_POST['receipt_date']);
    $fromAccountId = $_POST['from_account_id'] ?? '';
    $newAccountName = cleanText($_POST['new_account_name'] ?? '');
    $toAccountId = $_POST['to_account_id'] ?? '';
    $newToAccountName = cleanText($_POST['new_to_account_name'] ?? '');
    $toAccountType = clean($_POST['to_account_type'] ?? 'cash');
    $locationId = !empty($_POST['location_id']) ? (int)$_POST['location_id'] : null;
    $costCentreId = !empty($_POST['cost_centre_id']) ? (int)$_POST['cost_centre_id'] : null;
    $amount = floatval($_POST['amount'] ?? 0);
    $paymentMode = clean($_POST['payment_mode']);
    $referenceNumber = clean($_POST['reference_number'] ?? '');
    $remarks = clean($_POST['remarks'] ?? '');
    $currencyId = !empty($_POST['currency_id']) ? (int)$_POST['currency_id'] : ($defaultCurrency ? $defaultCurrency['id'] : null);
    $exchangeRateToBase = !empty($_POST['exchange_rate_to_base']) ? floatval($_POST['exchange_rate_to_base']) : 1;

    // Validation
    if (empty($receiptDate)) {
        $errors[] = __('receipt_date_required');
    }
    
    // Handle new account creation or selection
    if ($fromAccountId === 'new') {
        if (empty($newAccountName)) {
            $errors[] = __('customer_name_required');
        } else {
            try {
                $conn->beginTransaction();
                
                // Check if customer name already exists in ledger
                $checkStmt = $conn->prepare("SELECT id FROM ledger_accounts WHERE name = ?");
                $checkStmt->execute([$newAccountName]);
                $existing = $checkStmt->fetch();
                
                if ($existing) {
                    $errors[] = __('customer_account_exists');
                    $conn->rollBack();
                } else {
                    // Step 1: Create ledger account
                    $defaultCurrency = getDefaultCurrency();
                    $stmt = $conn->prepare("
                        INSERT INTO ledger_accounts
                        (name, account_type, opening_balance, current_balance, currency_id, is_active)
                        VALUES (?, 'customer', 0, 0, ?, 1)
                    ");
                    $stmt->execute([$newAccountName, $defaultCurrency['id']]);
                    $ledgerAccountId = $conn->lastInsertId();
                    
                    // Step 2: Create customer
                    $stmt = $conn->prepare("
                        INSERT INTO customers (ledger_account_id, is_active)
                        VALUES (?, 1)
                    ");
                    $stmt->execute([$ledgerAccountId]);
                    $customerId = $conn->lastInsertId();
                    
                    // Step 3: Update ledger account with reference_id
                    $stmt = $conn->prepare("UPDATE ledger_accounts SET reference_id = ? WHERE id = ?");
                    $stmt->execute([$customerId, $ledgerAccountId]);
                    
                    $fromAccountId = $ledgerAccountId;
                    $conn->commit();
                    
                    logAudit('customers', 'create', $customerId, null, ['name' => $newAccountName, 'created_via' => 'receipt_form']);
                }
            } catch (PDOException $e) {
                $conn->rollBack();
                $errors[] = 'Error creating new customer: ' . $e->getMessage();
            }
        }
    } else {
        // Existing customer selected - it's already a ledger_account_id
        $fromAccountId = (int)$fromAccountId;
        if (empty($fromAccountId)) {
            $errors[] = __('from_account_customer_required');
        }
    }
    
   // Handle new TO account (Cash/Bank) creation
    if ($toAccountId === 'new' || $toAccountId === 'new_cash' || $toAccountId === 'new_bank') {
        if (empty($newToAccountName)) {
            $errors[] = __('account_name_required_cash_bank');
        } else {
            try {
                // Check if account name already exists
                $checkStmt = $conn->prepare("SELECT id FROM ledger_accounts WHERE name = ?");
                $checkStmt->execute([$newToAccountName]);
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
                    $stmt->execute([$newToAccountName, $toAccountType, $defaultCurrency['id']]);
                    $toAccountId = $conn->lastInsertId();
                    
                    logAudit('ledger_accounts', 'create', $toAccountId, null, [
                        'name' => $newToAccountName, 
                        'type' => $toAccountType, 
                        'created_via' => 'receipt_form'
                    ]);
                }
            } catch (PDOException $e) {
                $errors[] = 'Error creating new account: ' . $e->getMessage();
            }
        }
    } else {
        $toAccountId = (int)$toAccountId;
        if (empty($toAccountId)) {
            $errors[] = __('to_account_cash_bank_required');
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
                // Get old receipt data for reversing balance updates
                $oldStmt = $conn->prepare("SELECT * FROM receipts WHERE id = ?");
                $oldStmt->execute([$id]);
                $oldReceipt = $oldStmt->fetch();
                
                // Reverse old balance changes
                if ($oldReceipt) {
                    // Restore old from account balance (customer)
                    $stmt = $conn->prepare("UPDATE ledger_accounts SET current_balance = current_balance + ? WHERE id = ?");
                    $stmt->execute([$oldReceipt['amount'], $oldReceipt['from_account_id']]);
                    
                    // Restore old to account balance (cash/bank)
                    $stmt = $conn->prepare("UPDATE ledger_accounts SET current_balance = current_balance - ? WHERE id = ?");
                    $stmt->execute([$oldReceipt['amount'], $oldReceipt['to_account_id']]);
                }
                
                // Update receipt
                $stmt = $conn->prepare("
                    UPDATE receipts
                    SET receipt_date = ?, from_account_id = ?, to_account_id = ?, location_id = ?,
                        cost_centre_id = ?, currency_id = ?, exchange_rate_to_base = ?,
                        amount = ?, payment_mode = ?, reference_number = ?, remarks = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $receiptDate, $fromAccountId, $toAccountId, $locationId,
                    $costCentreId, $currencyId, $exchangeRateToBase,
                    $amount, $paymentMode, $referenceNumber, $remarks, $id
                ]);
                
                // Apply new balance changes
                // Decrease from account (Customer - they owe us less)
                $stmt = $conn->prepare("UPDATE ledger_accounts SET current_balance = current_balance - ? WHERE id = ?");
                $stmt->execute([$amount, $fromAccountId]);

                // Increase to account (Cash/Bank)
                $stmt = $conn->prepare("UPDATE ledger_accounts SET current_balance = current_balance + ? WHERE id = ?");
                $stmt->execute([$amount, $toAccountId]);

                // Rebuild the GL transaction for this receipt — drop the old
                // 'receipt' transaction (cascades to details) and post a fresh
                // DR to_account / CR from_account pair.
                deleteVoucherTransaction($conn, 'receipt', $id);
                createReceiptTransaction($conn, $id);

                logAudit('receipts', 'update', $id);
                $conn->commit();
                setAlert(__('receipt_updated'), 'success');
            } else {
                // Auto-generate receipt number
                $receiptNumber = getNextNumber('receipts', 'receipt_number', getSetting('receipt_prefix', 'REC'));
                
                // Insert receipt
                $stmt = $conn->prepare("
                    INSERT INTO receipts
                    (receipt_number, receipt_date, from_account_id, to_account_id, location_id, cost_centre_id,
                     currency_id, exchange_rate_to_base, amount,
                     payment_mode, reference_number, remarks, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $receiptNumber, $receiptDate, $fromAccountId, $toAccountId, $locationId, $costCentreId,
                    $currencyId, $exchangeRateToBase, $amount,
                    $paymentMode, $referenceNumber, $remarks, getUserId()
                ]);
                $newId = $conn->lastInsertId();
                
                // Update ledger account balances
                // Decrease from account (Customer - they owe us less)
                $stmt = $conn->prepare("UPDATE ledger_accounts SET current_balance = current_balance - ? WHERE id = ?");
                $stmt->execute([$amount, $fromAccountId]);

                // Increase to account (Cash/Bank - we received money)
                $stmt = $conn->prepare("UPDATE ledger_accounts SET current_balance = current_balance + ? WHERE id = ?");
                $stmt->execute([$amount, $toAccountId]);

                // Post the GL transaction: DR to_account / CR from_account
                // (so the receivables dashboard can reconcile via GL).
                createReceiptTransaction($conn, $newId);

                logAudit('receipts', 'create', $newId);
                $conn->commit();
                setAlert(__('receipt_created'), 'success');
            }
            
            redirect('modules/payments/receipts.php');
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
                    <i class="bi bi-arrow-down-circle"></i>
                    <?php echo $isEdit ? __('edit_receipt') : __('add_new_receipt'); ?>
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
                
                <form method="POST" action="" id="receiptForm">
                    <?php echo csrfField(); ?>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="receipt_date" class="form-label required"><?php echo __('receipt_date'); ?></label>
                            <input
                                type="date"
                                class="form-control form-control-lg"
                                id="receipt_date"
                                name="receipt_date"
                                value="<?php echo $receipt['receipt_date']; ?>"
                                max="<?php echo date('Y-m-d'); ?>"
                                required
                            >
                        </div>

                        <div class="col-md-4 mb-3">
                            <label for="location_id" class="form-label"><?php echo __('location'); ?></label>
                            <select
                                class="form-select form-select-lg"
                                id="location_id"
                                name="location_id"
                            >
                                <option value=""><?php echo __('all_locations'); ?></option>
                                <?= renderTreeOptions($userLocations, isset($receipt['location_id']) ? $receipt['location_id'] : null) ?>
                            </select>
                            <small class="text-muted"><?php echo __('leave_empty_for_all'); ?></small>
                        </div>

                        <div class="col-md-4 mb-3">
                            <label for="cost_centre_id" class="form-label"><?php echo __('cost_centre'); ?></label>
                            <select class="form-select form-select-lg" id="cost_centre_id" name="cost_centre_id">
                                <option value=""><?php echo __('select_cost_centre'); ?></option>
                                <?= renderTreeOptions(getUserCostCentres(), isset($receipt['cost_centre_id']) ? $receipt['cost_centre_id'] : null, null, 'name', 'code') ?>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="currency_id" class="form-label"><?php echo __('currency'); ?></label>
                            <select class="form-select form-select-lg" id="currency_id" name="currency_id" onchange="onCurrencyChange()">
                                <?php foreach ($allCurrencies as $cur): ?>
                                <option value="<?php echo $cur['id']; ?>"
                                    data-symbol="<?php echo htmlspecialchars($cur['symbol']); ?>"
                                    data-is-default="<?php echo $cur['is_default']; ?>"
                                    <?php echo (isset($receipt['currency_id']) && $receipt['currency_id'] == $cur['id']) ? 'selected' : ($cur['is_default'] ? 'selected' : ''); ?>>
                                    <?php echo htmlspecialchars($cur['code'] . ' - ' . $cur['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3" id="exchangeRateGroup" style="<?php echo (isset($receipt['currency_id']) && $defaultCurrency && $receipt['currency_id'] != $defaultCurrency['id']) ? '' : 'display:none;'; ?>">
                            <label for="exchange_rate_to_base" class="form-label"><?php echo __('exchange_rate'); ?></label>
                            <input type="number" class="form-control form-control-lg" id="exchange_rate_to_base" name="exchange_rate_to_base"
                                   step="0.00000001" min="0.00000001"
                                   value="<?php echo isset($receipt['exchange_rate_to_base']) ? $receipt['exchange_rate_to_base'] : '1'; ?>">
                            <small class="text-muted" id="exchangeRateHint"></small>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="from_account_id" class="form-label required"><?php echo __('from_account_customer'); ?></label>
                            <select 
                                class="form-select form-select-lg select2" 
                                id="from_account_id" 
                                name="from_account_id"
                                required
                            >
                                <option value=""><?php echo __('select_or_create'); ?></option>
                                <option value="new">➕ <?php echo __('create_new_customer'); ?></option>
                                <?php foreach ($customers as $customer): ?>
                                <option value="<?php echo $customer['ledger_account_id']; ?>" 
                                    <?php echo $receipt['from_account_id'] == $customer['ledger_account_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($customer['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted"><?php echo __('money_coming_from_customer'); ?></small>
                        </div>
                    </div>
                    
                    <!-- New Account Name Field (hidden by default) -->
                    <div class="row" id="newAccountRow" style="display: none;">
                        <div class="col-md-12 mb-3">
                            <label for="new_account_name" class="form-label"><?php echo __('new_customer_name'); ?></label>
                            <input 
                                type="text" 
                                class="form-control form-control-lg" 
                                id="new_account_name" 
                                name="new_account_name" 
                                placeholder="<?php echo __('enter_new_customer_name'); ?>"
                            >
                            <small class="text-muted"><?php echo __('will_create_customer'); ?></small>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="to_account_id" class="form-label required"><?php echo __('to_account_cash_bank'); ?></label>
                            <select 
                                class="form-select form-select-lg select2-to" 
                                id="to_account_id" 
                                name="to_account_id"
                                required
                            >
                                <option value=""><?php echo __('select_or_create'); ?></option>
                                <option value="new_cash">➕ <?php echo __('create_new_cash'); ?></option>
                                <option value="new_bank">➕ <?php echo __('create_new_bank'); ?></option>
                                <?php foreach ($toAccounts as $account): ?>
                                <option value="<?php echo $account['id']; ?>" 
                                    <?php echo $receipt['to_account_id'] == $account['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($account['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted"><?php echo __('money_received_into'); ?></small>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="amount" class="form-label required"><?php echo __('amount'); ?></label>
                            <div class="input-group input-group-lg">
                                <?php
                                $amountSymbol = $defaultCurrencySymbol;
                                if (!empty($receipt['currency_id'])) {
                                    foreach ($allCurrencies as $cur) {
                                        if ($cur['id'] == $receipt['currency_id']) { $amountSymbol = $cur['symbol']; break; }
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
                                    value="<?php echo $receipt['amount']; ?>"
                                    required
                                    autofocus
                                >
                            </div>
                        </div>
                    </div>
                    
                    <!-- New To Account Name Field (hidden by default) -->
                    <div class="row" id="newToAccountRow" style="display: none;">
                        <div class="col-md-12 mb-3">
                            <label for="new_to_account_name" class="form-label">
                                <span id="new_to_account_label"><?php echo __('new_cash_bank_name'); ?></span>
                            </label>
                            <input 
                                type="text" 
                                class="form-control form-control-lg" 
                                id="new_to_account_name" 
                                name="new_to_account_name" 
                                placeholder="<?php echo __('enter_new_account_name'); ?>"
                            >
                            <small class="text-muted"><?php echo __('will_create_cash_bank'); ?></small>
                            <input type="hidden" id="to_account_type" name="to_account_type" value="cash">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="payment_mode" class="form-label required"><?php echo __('payment_mode'); ?></label>
                            <select 
                                class="form-select form-select-lg" 
                                id="payment_mode" 
                                name="payment_mode"
                                required
                            >
                                <option value="cash" <?php echo $receipt['payment_mode'] == 'cash' ? 'selected' : ''; ?>>💵 <?php echo __('cash'); ?></option>
                                <option value="cheque" <?php echo $receipt['payment_mode'] == 'cheque' ? 'selected' : ''; ?>>📝 <?php echo __('cheque'); ?></option>
                                <option value="bank_transfer" <?php echo $receipt['payment_mode'] == 'bank_transfer' ? 'selected' : ''; ?>>🏦 <?php echo __('bank_transfer'); ?></option>
                                <option value="upi" <?php echo $receipt['payment_mode'] == 'upi' ? 'selected' : ''; ?>>📱 <?php echo __('upi'); ?></option>
                                <option value="card" <?php echo $receipt['payment_mode'] == 'card' ? 'selected' : ''; ?>>💳 <?php echo __('card'); ?></option>
                            </select>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="reference_number" class="form-label"><?php echo __('reference_number'); ?></label>
                            <input 
                                type="text" 
                                class="form-control form-control-lg" 
                                id="reference_number" 
                                name="reference_number" 
                                value="<?php echo htmlspecialchars($receipt['reference_number'] ?? ''); ?>"
                                placeholder="<?php echo __('reference_placeholder'); ?>"
                            >
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="remarks" class="form-label"><?php echo __('remarks'); ?></label>
                        <textarea 
                            class="form-control" 
                            id="remarks" 
                            name="remarks" 
                            rows="2"
                            placeholder="<?php echo __('any_additional_notes'); ?>"
                        ><?php echo htmlspecialchars($receipt['remarks'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="d-grid gap-2 mt-4">
                        <button type="submit" class="btn btn-success btn-lg">
                            <i class="bi bi-check-circle"></i>
                            <?php echo $isEdit ? __('update_receipt') : __('create_receipt'); ?>
                        </button>
                        <a href="receipts.php" class="btn btn-secondary btn-lg">
                            <i class="bi bi-x-circle"></i> <?php echo __('cancel'); ?>
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

<script>
// Wait for jQuery to load (it's in footer.php)
document.addEventListener('DOMContentLoaded', function() {
    // Double check jQuery is loaded
    if (typeof jQuery === 'undefined') {
        console.error('jQuery is not loaded!');
        return;
    }
    
    // Initialize Select2 for both dropdowns
    jQuery('#from_account_id').select2({
        placeholder: '<?php echo __('select_or_search'); ?>',
        allowClear: true,
        width: '100%'
    });
    
    jQuery('#to_account_id').select2({
        placeholder: '<?php echo __('select_or_search'); ?>',
        allowClear: true,
        width: '100%'
    });
    
    // Show/hide new FROM account name field (Customer)
    jQuery('#from_account_id').on('change', function() {
        if (jQuery(this).val() === 'new') {
            jQuery('#newAccountRow').show();
            jQuery('#new_account_name').attr('required', true).focus();
        } else {
            jQuery('#newAccountRow').hide();
            jQuery('#new_account_name').attr('required', false).val('');
        }
    });
    
    // Show/hide new TO account name field (Cash/Bank)
    jQuery('#to_account_id').on('change', function() {
        var selectedValue = jQuery(this).val();
        
        if (selectedValue === 'new_cash') {
            // Creating new cash account
            jQuery('#newToAccountRow').show();
            jQuery('#new_to_account_name').attr('required', true).focus();
            jQuery('#new_to_account_label').text('<?php echo __('new_cash_account_name'); ?>');
            jQuery('#to_account_type').val('cash');
        } else if (selectedValue === 'new_bank') {
            // Creating new bank account
            jQuery('#newToAccountRow').show();
            jQuery('#new_to_account_name').attr('required', true).focus();
            jQuery('#new_to_account_label').text('<?php echo __('new_bank_account_name'); ?>');
            jQuery('#to_account_type').val('bank');
        } else {
            // Existing account selected
            jQuery('#newToAccountRow').hide();
            jQuery('#new_to_account_name').attr('required', false).val('');
        }
    });
    
    // Trigger change on page load if editing
    <?php if ($isEdit && isset($receipt['from_account_id']) && $receipt['from_account_id']): ?>
    jQuery('#from_account_id').trigger('change');
    <?php endif; ?>
    
    <?php if ($isEdit && isset($receipt['to_account_id']) && $receipt['to_account_id']): ?>
    jQuery('#to_account_id').trigger('change');
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
    const receiptDate = document.getElementById('receipt_date').value || new Date().toISOString().split('T')[0];
    try {
        const url = `${BASE_URL}api/exchange-rates.php?from_currency_id=${currencyId}&to_currency_id=${defaultCurrencyId}&_=${Date.now()}`;
        const response = await fetch(url);
        if (response.ok) {
            const result = await response.json();
            if (result.success && result.exchange_rates && result.exchange_rates.length > 0) {
                const applicable = result.exchange_rates.filter(r => r.effective_date <= receiptDate);
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
                    const applicable = revResult.exchange_rates.filter(r => r.effective_date <= receiptDate);
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
