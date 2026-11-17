<?php
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");
require_once '../../config/database.php';
require_once '../../includes/functions.php';
$pageTitle = __('payments_management');

if (!hasPermission('payments', 'view')) {
    setAlert('You do not have permission to access this page', 'danger');
    redirect('');
}

// Handle Delete
if (isset($_GET['delete']) && hasPermission('payments', 'delete')) {
    $id = (int)$_GET['delete'];

    try {
        $conn->beginTransaction();
        // Cascade-cleanup of the GL transaction posted for this payment.
        // FK on transaction_details cascades the DR/CR rows. Safe no-op if
        // there's no transactions row (e.g. legacy payment not yet backfilled).
        deleteVoucherTransaction($conn, 'payment', $id);

        $stmt = $conn->prepare("DELETE FROM payments WHERE id = ?");
        $stmt->execute([$id]);

        logAudit('payments', 'delete', $id);
        $conn->commit();
        setAlert(__('payment_deleted'), 'success');
    } catch (PDOException $e) {
        if ($conn->inTransaction()) { $conn->rollBack(); }
        setAlert(__('error_deleting_payment') . ': ' . $e->getMessage(), 'danger');
    }

    redirect('modules/payments/payments.php');
}

// Get user locations
$userLocations = getUserLocations();

require_once '../../includes/header.php';

// Get filters
$search = $_GET['search'] ?? '';
$fromDate = $_GET['from_date'] ?? '';
$toDate = $_GET['to_date'] ?? '';
$paymentMode = $_GET['payment_mode'] ?? '';
$locationFilter = $_GET['location'] ?? '';
// Supplier drill-down filter (from dashboard payables modal): payments.to_account_id = supplier ledger account
$toAccountFilter = isset($_GET['to_account_id']) ? (int)$_GET['to_account_id'] : 0;
$toAccountFilterName = '';
$page = $_GET['page'] ?? 1;

$whereClause = 'WHERE 1=1';
$params = [];

if ($search) {
    $whereClause .= " AND (p.payment_number LIKE ? OR p.reference_number LIKE ? OR to_acc.name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($fromDate) {
    $whereClause .= " AND p.payment_date >= ?";
    $params[] = dbDate($fromDate);
}

if ($toDate) {
    $whereClause .= " AND p.payment_date <= ?";
    $params[] = dbDate($toDate);
}

if ($paymentMode) {
    $whereClause .= " AND p.payment_mode = ?";
    $params[] = $paymentMode;
}

if ($locationFilter) {
    if ($locationFilter === 'none') {
        $whereClause .= " AND p.location_id IS NULL";
    } else {
        $whereClause .= " AND p.location_id = ?";
        $params[] = $locationFilter;
    }
}

if ($toAccountFilter > 0) {
    $whereClause .= " AND p.to_account_id = ?";
    $params[] = $toAccountFilter;

    // Look up the account name for the badge (separate query to keep main query simple)
    $nameStmt = $conn->prepare("SELECT name FROM ledger_accounts WHERE id = ?");
    $nameStmt->execute([$toAccountFilter]);
    $toAccountFilterName = $nameStmt->fetchColumn() ?: '#' . $toAccountFilter;
}

$countStmt = $conn->prepare("
    SELECT COUNT(*) as total 
    FROM payments p
    INNER JOIN ledger_accounts to_acc ON p.to_account_id = to_acc.id
    INNER JOIN ledger_accounts from_acc ON p.from_account_id = from_acc.id
    $whereClause
");
$countStmt->execute($params);
$totalRecords = $countStmt->fetch()['total'];

$pagination = paginate($totalRecords, $page);

$stmt = $conn->prepare("
    SELECT p.*,
           from_acc.name as from_account,
           to_acc.name as to_account,
           u.name as created_by_name,
           loc.name as location_name
    FROM payments p
    INNER JOIN ledger_accounts from_acc ON p.from_account_id = from_acc.id
    INNER JOIN ledger_accounts to_acc ON p.to_account_id = to_acc.id
    LEFT JOIN users u ON p.created_by = u.id
    LEFT JOIN locations loc ON p.location_id = loc.id
    $whereClause
    ORDER BY p.payment_date DESC, p.id DESC
    LIMIT {$pagination['records_per_page']} OFFSET {$pagination['offset']}
");
$stmt->execute($params);
$payments = $stmt->fetchAll();

// Calculate totals.
// Grouped by currency, because a plain SUM(amount) over a filtered set that
// mixes currencies adds USD to TZS and then labels the result with the default
// symbol. Each group is converted with its own stored exchange_rate_to_base and
// the base-currency figure is what the card + footer show.
// NOTE: for an all-base-currency set every rate is exactly 1, so this returns
// the identical number the old SUM(amount) did — nothing changes on a
// single-currency tenant.
$totalStmt = $conn->prepare("
    SELECT p.currency_id,
           SUM(p.amount) as total_amount,
           SUM(p.amount * COALESCE(NULLIF(p.exchange_rate_to_base, 0), 1)) as total_base
    FROM payments p
    INNER JOIN ledger_accounts to_acc ON p.to_account_id = to_acc.id
    INNER JOIN ledger_accounts from_acc ON p.from_account_id = from_acc.id
    $whereClause
    GROUP BY p.currency_id
");
$totalStmt->execute($params);
$currencyTotals = $totalStmt->fetchAll();

$baseCurrency = getDefaultCurrency();
$baseCurrencyId = $baseCurrency ? (int)$baseCurrency['id'] : null;

// $totalAmount stays the base-currency total the card and footer have always
// printed — only the way it is arrived at changed.
$totalAmount = 0;
$hasForeignCurrency = false;
foreach ($currencyTotals as $ct) {
    $totalAmount += (float)$ct['total_base'];
    if ($ct['currency_id'] !== null && (int)$ct['currency_id'] !== $baseCurrencyId) {
        $hasForeignCurrency = true;
    }
}
?>

<div class="row mb-3">
    <div class="col-md-6">
        <h2><i class="bi bi-arrow-up-circle"></i> <?php _e('payments_management'); ?></h2>
    </div>
    <div class="col-md-6 text-end">
        <?php if (hasPermission('payments', 'create')): ?>
        <a href="payments_form.php" class="btn btn-primary">
            <i class="bi bi-plus-circle"></i> <?php _e('add_new_payment'); ?>
        </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($toAccountFilter > 0): ?>
<!-- Supplier drill-down badge (set by dashboard payables modal via ?to_account_id=) -->
<div class="alert alert-info d-flex align-items-center justify-content-between mb-3">
    <div>
        <i class="bi bi-funnel-fill"></i>
        <?php _e('filtering_by_supplier', 'Filtering by supplier'); ?>:
        <strong><?php echo htmlspecialchars($toAccountFilterName); ?></strong>
    </div>
    <a href="payments.php" class="btn btn-sm btn-outline-secondary" title="<?php _e('clear_filter', 'Clear filter'); ?>">
        <i class="bi bi-x-circle"></i> <?php _e('clear_filter', 'Clear filter'); ?>
    </a>
</div>
<?php endif; ?>

<!-- Filters Card -->
<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="" class="row g-2">
            <?php if ($toAccountFilter > 0): ?>
            <input type="hidden" name="to_account_id" value="<?php echo (int)$toAccountFilter; ?>">
            <?php endif; ?>
            <div class="col-md-3">
                <input type="text" name="search" class="form-control"
                       placeholder="Search..."
                       value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-2">
                <input type="date" name="from_date" class="form-control" 
                       placeholder="From Date"
                       value="<?php echo htmlspecialchars($fromDate); ?>">
            </div>
            <div class="col-md-2">
                <input type="date" name="to_date" class="form-control" 
                       placeholder="To Date"
                       value="<?php echo htmlspecialchars($toDate); ?>">
            </div>
            <div class="col-md-2">
                <select name="payment_mode" class="form-select">
                    <option value=""><?php _e('all_payment_modes'); ?></option>
                    <option value="cash" <?php echo $paymentMode == 'cash' ? 'selected' : ''; ?>><?php _e('cash'); ?></option>
                    <option value="cheque" <?php echo $paymentMode == 'cheque' ? 'selected' : ''; ?>><?php _e('cheque'); ?></option>
                    <option value="bank_transfer" <?php echo $paymentMode == 'bank_transfer' ? 'selected' : ''; ?>><?php _e('bank_transfer'); ?></option>
                    <option value="upi" <?php echo $paymentMode == 'upi' ? 'selected' : ''; ?>><?php _e('upi'); ?></option>
                    <option value="card" <?php echo $paymentMode == 'card' ? 'selected' : ''; ?>><?php _e('card'); ?></option>
                </select>
            </div>
            <div class="col-md-2">
                <select name="location" class="form-select">
                    <option value=""><?php _e('all_locations'); ?></option>
                    <option value="none" <?php echo $locationFilter === 'none' ? 'selected' : ''; ?>><?php _e('no_location'); ?></option>
                    <?= renderTreeOptions($userLocations, $locationFilter) ?>
                </select>
            </div>
            <div class="col-md-1">
                <div class="btn-group w-100">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search"></i>
                    </button>
                    <?php if ($search || $fromDate || $toDate || $paymentMode || $locationFilter || $toAccountFilter): ?>
                    <a href="payments.php" class="btn btn-secondary">
                        <i class="bi bi-x-circle"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Summary Card -->
<div class="row mb-3">
    <div class="col-md-4">
        <div class="card bg-primary text-white">
            <div class="card-body">
                <h6 class="card-title"><?php _e('total_payments'); ?></h6>
                <h3 class="mb-0"><?php echo formatCurrency($totalAmount, 2, $baseCurrency); ?></h3>
                <small><?php echo $totalRecords; ?> <?php _e('transaction_s'); ?></small>
                <?php if ($hasForeignCurrency): ?>
                <?php /* Say so rather than presenting a mixed-currency sum as if
                        it were all base currency. */ ?>
                <div class="mt-2 pt-2 border-top border-light small">
                    <div class="mb-1"><?php _e('converted_to_base'); ?></div>
                    <?php foreach ($currencyTotals as $ct):
                        $ctCurrency = getCurrency($ct['currency_id']); ?>
                    <div><?php echo formatCurrency($ct['total_amount'], 2, $ctCurrency); ?></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Payments Table -->
<div class="card">
    <div class="card-body">
        <?php if (empty($payments)): ?>
        <div class="empty-state">
            <i class="bi bi-arrow-up-circle"></i>
            <h5><?php _e('no_payments_found'); ?></h5>
            <p><?php _e('start_adding_payment'); ?></p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover" id="paymentsTable">
                <thead>
                    <tr>
                        <th><?php _e('payment_number'); ?></th>
                        <th><?php _e('date'); ?></th>
                        <th><?php _e('location'); ?></th>
                        <th><?php _e('from_account'); ?></th>
                        <th><?php _e('to_account'); ?></th>
                        <th><?php _e('amount'); ?></th>
                        <th><?php _e('payment_mode'); ?></th>
                        <th><?php _e('reference'); ?></th>
                        <th class="no-export"><?php _e('actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $payment): ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($payment['payment_number']); ?></strong>
                        </td>
                        <td><?php echo formatDate($payment['payment_date']); ?></td>
                        <td>
                            <?php if ($payment['location_name']): ?>
                            <span class="badge bg-secondary"><?php echo htmlspecialchars($payment['location_name']); ?></span>
                            <?php else: ?>
                            <span class="badge bg-light text-dark"><?php _e('all'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-info"><?php echo htmlspecialchars($payment['from_account']); ?></span>
                        </td>
                        <td>
                            <span class="badge bg-warning text-dark"><?php echo htmlspecialchars($payment['to_account']); ?></span>
                        </td>
                        <?php
                        // The voucher's OWN currency. formatCurrency()'s 3rd
                        // argument exists for exactly this; passing nothing
                        // silently fell back to the default currency, so a USD
                        // payment printed as "TZS". getCurrency() caches per id,
                        // so this costs a handful of queries per page.
                        $paymentCurrency = getCurrency($payment['currency_id']);
                        $isForeign = $paymentCurrency && $baseCurrencyId !== null
                                     && (int)$paymentCurrency['id'] !== $baseCurrencyId;

                        // The rate and base equivalent go in a TOOLTIP, not as
                        // visible sub-text: exportTableToCSV() in assets/js/main.js
                        // builds each field from the cell's innerText and joins on
                        // "," with no quoting, so any extra visible line here would
                        // inject commas + a newline and corrupt the CSV export.
                        $amountTitle = '';
                        if ($isForeign) {
                            $amountTitle = $paymentCurrency['code'] . ' @ '
                                . formatNumber($payment['exchange_rate_to_base'], 2) . ' = '
                                . formatCurrency($payment['amount'] * (float)($payment['exchange_rate_to_base'] ?: 1), 2, $baseCurrency);
                        }
                        ?>
                        <td class="text-end"<?php echo $isForeign ? ' title="' . htmlspecialchars($amountTitle, ENT_QUOTES) . '"' : ''; ?>>
                            <strong><?php echo formatCurrency($payment['amount'], 2, $paymentCurrency); ?></strong>
                        </td>
                        <td>
                            <?php
                            $modeLabels = [
                                'cash' => '<span class="badge bg-success">' . __('cash') . '</span>',
                                'cheque' => '<span class="badge bg-primary">' . __('cheque') . '</span>',
                                'bank_transfer' => '<span class="badge bg-info">' . __('bank_transfer') . '</span>',
                                'upi' => '<span class="badge bg-warning text-dark">' . __('upi') . '</span>',
                                'card' => '<span class="badge bg-secondary">' . __('card') . '</span>'
                            ];
                            echo $modeLabels[$payment['payment_mode']] ?? $payment['payment_mode'];
                            ?>
                        </td>
                        <td><?php echo htmlspecialchars($payment['reference_number'] ?? '-'); ?></td>
                        <td class="no-export">
                            <div class="btn-group btn-group-sm">
                                <?php if (hasPermission('payments', 'edit')): ?>
                                <a href="payments_form.php?id=<?php echo $payment['id']; ?>" 
                                   class="btn btn-outline-primary" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <?php endif; ?>
                                
                                <?php if (hasPermission('payments', 'delete')): ?>
                                <a href="?delete=<?php echo $payment['id']; ?>" 
                                   class="btn btn-outline-danger confirm-delete" title="Delete">
                                    <i class="bi bi-trash"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="table-primary fw-bold">
                        <td colspan="5" class="text-end"><?php _e('total'); ?>:</td>
                        <?php /* Note goes in the tooltip, not in the cell text —
                                exportTableToCSV() walks tfoot rows too. */ ?>
                        <td class="text-end"<?php echo $hasForeignCurrency ? ' title="' . htmlspecialchars(__('converted_to_base'), ENT_QUOTES) . '"' : ''; ?>>
                            <?php echo formatCurrency($totalAmount, 2, $baseCurrency); ?>
                        </td>
                        <td colspan="3"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($pagination['total_pages'] > 1): ?>
        <nav class="mt-3">
            <ul class="pagination justify-content-center">
                <?php if ($pagination['has_prev']): ?>
                <li class="page-item">
                    <a class="page-link" href="?page=<?php echo $pagination['prev_page']; ?>&search=<?php echo urlencode($search); ?>&from_date=<?php echo $fromDate; ?>&to_date=<?php echo $toDate; ?>&payment_mode=<?php echo $paymentMode; ?>&location=<?php echo $locationFilter; ?>">
                        <?php _e('previous'); ?>
                    </a>
                </li>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $pagination['total_pages']; $i++): ?>
                <li class="page-item <?php echo $i == $pagination['current_page'] ? 'active' : ''; ?>">
                    <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&from_date=<?php echo $fromDate; ?>&to_date=<?php echo $toDate; ?>&payment_mode=<?php echo $paymentMode; ?>&location=<?php echo $locationFilter; ?>">
                        <?php echo $i; ?>
                    </a>
                </li>
                <?php endfor; ?>

                <?php if ($pagination['has_next']): ?>
                <li class="page-item">
                    <a class="page-link" href="?page=<?php echo $pagination['next_page']; ?>&search=<?php echo urlencode($search); ?>&from_date=<?php echo $fromDate; ?>&to_date=<?php echo $toDate; ?>&payment_mode=<?php echo $paymentMode; ?>&location=<?php echo $locationFilter; ?>">
                        <?php _e('next'); ?>
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </nav>
        <?php endif; ?>
        
        <!-- Export Button -->
        <div class="text-center mt-3">
            <button onclick="exportTableToCSV('paymentsTable', 'payments.csv')" class="btn btn-success">
                <i class="bi bi-file-earmark-excel"></i> <?php _e('export_csv'); ?>
            </button>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
