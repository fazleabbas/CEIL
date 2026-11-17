<?php
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");
require_once '../../config/database.php';
require_once '../../includes/functions.php';
$pageTitle = __('receipts_management');

if (!hasPermission('receipts', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('');
}

// Handle Delete
if (isset($_GET['delete']) && hasPermission('receipts', 'delete')) {
    $id = (int)$_GET['delete'];

    try {
        $conn->beginTransaction();
        // Cascade-cleanup of the GL transaction posted for this receipt.
        // Safe no-op if there's no transactions row (legacy receipt not yet
        // backfilled).
        deleteVoucherTransaction($conn, 'receipt', $id);

        $stmt = $conn->prepare("DELETE FROM receipts WHERE id = ?");
        $stmt->execute([$id]);

        logAudit('receipts', 'delete', $id);
        $conn->commit();
        setAlert(__('receipt_deleted'), 'success');
    } catch (PDOException $e) {
        if ($conn->inTransaction()) { $conn->rollBack(); }
        setAlert(__('error_deleting_receipt') . ': ' . $e->getMessage(), 'danger');
    }

    redirect('modules/payments/receipts.php');
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
$page = $_GET['page'] ?? 1;

$whereClause = 'WHERE 1=1';
$params = [];

if ($search) {
    $whereClause .= " AND (r.receipt_number LIKE ? OR r.reference_number LIKE ? OR from_acc.name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($fromDate) {
    $whereClause .= " AND r.receipt_date >= ?";
    $params[] = dbDate($fromDate);
}

if ($toDate) {
    $whereClause .= " AND r.receipt_date <= ?";
    $params[] = dbDate($toDate);
}

if ($paymentMode) {
    $whereClause .= " AND r.payment_mode = ?";
    $params[] = $paymentMode;
}

if ($locationFilter) {
    if ($locationFilter === 'none') {
        $whereClause .= " AND r.location_id IS NULL";
    } else {
        $whereClause .= " AND r.location_id = ?";
        $params[] = $locationFilter;
    }
}

$countStmt = $conn->prepare("
    SELECT COUNT(*) as total
    FROM receipts r
    INNER JOIN ledger_accounts from_acc ON r.from_account_id = from_acc.id
    INNER JOIN ledger_accounts to_acc ON r.to_account_id = to_acc.id
    $whereClause
");
$countStmt->execute($params);
$totalRecords = $countStmt->fetch()['total'];

$pagination = paginate($totalRecords, $page);

$stmt = $conn->prepare("
    SELECT r.*,
           from_acc.name as from_account,
           to_acc.name as to_account,
           u.name as created_by_name,
           loc.name as location_name
    FROM receipts r
    INNER JOIN ledger_accounts from_acc ON r.from_account_id = from_acc.id
    INNER JOIN ledger_accounts to_acc ON r.to_account_id = to_acc.id
    LEFT JOIN users u ON r.created_by = u.id
    LEFT JOIN locations loc ON r.location_id = loc.id
    $whereClause
    ORDER BY r.receipt_date DESC, r.id DESC
    LIMIT {$pagination['records_per_page']} OFFSET {$pagination['offset']}
");
$stmt->execute($params);
$receipts = $stmt->fetchAll();

// Calculate totals.
// Grouped by currency, because a plain SUM(amount) over a filtered set that
// mixes currencies adds USD to TZS and then labels the result with the default
// symbol. Each group is converted with its own stored exchange_rate_to_base and
// the base-currency figure is what the card + footer show.
// NOTE: for an all-base-currency set every rate is exactly 1, so this returns
// the identical number the old SUM(amount) did — nothing changes on a
// single-currency tenant.
$totalStmt = $conn->prepare("
    SELECT r.currency_id,
           SUM(r.amount) as total_amount,
           SUM(r.amount * COALESCE(NULLIF(r.exchange_rate_to_base, 0), 1)) as total_base
    FROM receipts r
    INNER JOIN ledger_accounts from_acc ON r.from_account_id = from_acc.id
    INNER JOIN ledger_accounts to_acc ON r.to_account_id = to_acc.id
    $whereClause
    GROUP BY r.currency_id
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
        <h2><i class="bi bi-arrow-down-circle"></i> <?php echo __('receipts_management'); ?></h2>
    </div>
    <div class="col-md-6 text-end">
        <?php if (hasPermission('receipts', 'create')): ?>
        <a href="receipts_form.php" class="btn btn-success">
            <i class="bi bi-plus-circle"></i> <?php echo __('add_new_receipt'); ?>
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Filters Card -->
<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="" class="row g-2">
            <div class="col-md-3">
                <input type="text" name="search" class="form-control" 
                       placeholder="<?php echo __('search'); ?>..." 
                       value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-2">
                <input type="date" name="from_date" class="form-control" 
                       placeholder="<?php echo __('from_date'); ?>"
                       value="<?php echo htmlspecialchars($fromDate); ?>">
            </div>
            <div class="col-md-2">
                <input type="date" name="to_date" class="form-control" 
                       placeholder="<?php echo __('to_date'); ?>"
                       value="<?php echo htmlspecialchars($toDate); ?>">
            </div>
            <div class="col-md-2">
                <select name="payment_mode" class="form-select">
                    <option value=""><?php echo __('all_payment_modes'); ?></option>
                    <option value="cash" <?php echo $paymentMode == 'cash' ? 'selected' : ''; ?>><?php echo __('cash'); ?></option>
                    <option value="cheque" <?php echo $paymentMode == 'cheque' ? 'selected' : ''; ?>><?php echo __('cheque'); ?></option>
                    <option value="bank_transfer" <?php echo $paymentMode == 'bank_transfer' ? 'selected' : ''; ?>><?php echo __('bank_transfer'); ?></option>
                    <option value="upi" <?php echo $paymentMode == 'upi' ? 'selected' : ''; ?>><?php echo __('upi'); ?></option>
                    <option value="card" <?php echo $paymentMode == 'card' ? 'selected' : ''; ?>><?php echo __('card'); ?></option>
                </select>
            </div>
            <div class="col-md-2">
                <select name="location" class="form-select">
                    <option value=""><?php echo __('all_locations'); ?></option>
                    <option value="none" <?php echo $locationFilter === 'none' ? 'selected' : ''; ?>><?php echo __('no_location'); ?></option>
                    <?= renderTreeOptions($userLocations, $locationFilter) ?>
                </select>
            </div>
            <div class="col-md-1">
                <div class="btn-group w-100">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search"></i>
                    </button>
                    <?php if ($search || $fromDate || $toDate || $paymentMode || $locationFilter): ?>
                    <a href="receipts.php" class="btn btn-secondary">
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
        <div class="card bg-success text-white">
            <div class="card-body">
                <h6 class="card-title"><?php echo __('total_receipts'); ?></h6>
                <h3 class="mb-0"><?php echo formatCurrency($totalAmount, 2, $baseCurrency); ?></h3>
                <small><?php echo $totalRecords; ?> <?php echo __('transaction_s'); ?></small>
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

<!-- Receipts Table -->
<div class="card">
    <div class="card-body">
        <?php if (empty($receipts)): ?>
        <div class="empty-state">
            <i class="bi bi-arrow-down-circle"></i>
            <h5><?php echo __('no_receipts_found'); ?></h5>
            <p><?php echo __('start_adding_receipt'); ?></p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover" id="receiptsTable">
                <thead>
                    <tr>
                        <th><?php echo __('receipt_number'); ?></th>
                        <th><?php echo __('date'); ?></th>
                        <th><?php echo __('location'); ?></th>
                        <th><?php echo __('from_account'); ?></th>
                        <th><?php echo __('to_account'); ?></th>
                        <th><?php echo __('amount'); ?></th>
                        <th><?php echo __('payment_mode'); ?></th>
                        <th><?php echo __('reference'); ?></th>
                        <th class="no-export"><?php echo __('actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($receipts as $receipt): ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($receipt['receipt_number']); ?></strong>
                        </td>
                        <td><?php echo formatDate($receipt['receipt_date']); ?></td>
                        <td>
                            <?php if ($receipt['location_name']): ?>
                            <span class="badge bg-secondary"><?php echo htmlspecialchars($receipt['location_name']); ?></span>
                            <?php else: ?>
                            <span class="badge bg-light text-dark"><?php echo __('all'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-warning text-dark"><?php echo htmlspecialchars($receipt['from_account']); ?></span>
                        </td>
                        <td>
                            <span class="badge bg-info"><?php echo htmlspecialchars($receipt['to_account']); ?></span>
                        </td>
                        <?php
                        // The voucher's OWN currency. formatCurrency()'s 3rd
                        // argument exists for exactly this; passing nothing
                        // silently fell back to the default currency, so a USD
                        // receipt printed as "TZS". getCurrency() caches per id,
                        // so this costs a handful of queries per page.
                        $receiptCurrency = getCurrency($receipt['currency_id']);
                        $isForeign = $receiptCurrency && $baseCurrencyId !== null
                                     && (int)$receiptCurrency['id'] !== $baseCurrencyId;

                        // The rate and base equivalent go in a TOOLTIP, not as
                        // visible sub-text: exportTableToCSV() in assets/js/main.js
                        // builds each field from the cell's innerText and joins on
                        // "," with no quoting, so any extra visible line here would
                        // inject commas + a newline and corrupt the CSV export.
                        $amountTitle = '';
                        if ($isForeign) {
                            $amountTitle = $receiptCurrency['code'] . ' @ '
                                . formatNumber($receipt['exchange_rate_to_base'], 2) . ' = '
                                . formatCurrency($receipt['amount'] * (float)($receipt['exchange_rate_to_base'] ?: 1), 2, $baseCurrency);
                        }
                        ?>
                        <td class="text-end"<?php echo $isForeign ? ' title="' . htmlspecialchars($amountTitle, ENT_QUOTES) . '"' : ''; ?>>
                            <strong><?php echo formatCurrency($receipt['amount'], 2, $receiptCurrency); ?></strong>
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
                            echo $modeLabels[$receipt['payment_mode']] ?? $receipt['payment_mode'];
                            ?>
                        </td>
                        <td><?php echo htmlspecialchars($receipt['reference_number'] ?? '-'); ?></td>
                        <td class="no-export">
                            <div class="btn-group btn-group-sm">
                                <?php if (hasPermission('receipts', 'edit')): ?>
                                <a href="receipts_form.php?id=<?php echo $receipt['id']; ?>" 
                                   class="btn btn-outline-primary" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <?php endif; ?>
                                
                                <?php if (hasPermission('receipts', 'delete')): ?>
                                <a href="?delete=<?php echo $receipt['id']; ?>" 
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
                    <tr class="table-success fw-bold">
                        <td colspan="5" class="text-end"><?php echo __('total'); ?>:</td>
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
                        <?php echo __('previous'); ?>
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
                        <?php echo __('next'); ?>
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </nav>
        <?php endif; ?>
        
        <!-- Export Button -->
        <div class="text-center mt-3">
            <button onclick="exportTableToCSV('receiptsTable', 'receipts.csv')" class="btn btn-success">
                <i class="bi bi-file-earmark-excel"></i> <?php echo __('export_to_csv'); ?>
            </button>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
