<?php
/**
 * Ledger Report
 *
 * Filters: From / To dates, Group (account_type), Ledger (multi).
 * - When only Group is set, the body shows the list of ledgers in that group;
 *   clicking a ledger opens its statement in a new tab.
 * - When one or more ledgers are picked, each ledger's statement appears stacked
 *   in the body (Date / Voucher / Narration / Debit / Credit / Running Balance).
 * - Opening balance is "from all time" — the ledger's opening_balance plus the
 *   net DR/CR of every transaction strictly before the From date.
 * - Default amounts are in base currency. Toggle "Show forex" to also see the
 *   original currency, rate, and original amount per row.
 * - "?standalone=1" hides the filter bar (used for the open-in-new-tab links).
 */

require_once '../../config/database.php';
require_once '../../includes/functions.php';

$pageTitle = __('ledger_report');

if (!canViewReport('ledger_report')) {
    setAlert('You do not have permission to access this page', 'danger');
    redirect('');
}

// --------------------------------------------------------
// Filters
// --------------------------------------------------------
$dateFrom   = $_GET['date_from'] ?? date('Y-m-01');
$dateTo     = $_GET['date_to']   ?? date('Y-m-d');
$groupFilter = $_GET['group']    ?? '';
$showForex   = !empty($_GET['forex']);
$standalone  = !empty($_GET['standalone']);

$selectedLedgerIds = [];
if (isset($_GET['ledger_ids']) && is_array($_GET['ledger_ids'])) {
    foreach ($_GET['ledger_ids'] as $id) {
        $id = (int)$id;
        if ($id > 0) $selectedLedgerIds[] = $id;
    }
    $selectedLedgerIds = array_values(array_unique($selectedLedgerIds));
}

// --------------------------------------------------------
// All ledgers (for dropdowns and the group-list view)
// --------------------------------------------------------
$ledgersStmt = $conn->prepare("
    SELECT la.id, la.name, la.account_type, la.opening_balance, la.currency_id,
           c.code AS currency_code, c.symbol AS currency_symbol
    FROM ledger_accounts la
    LEFT JOIN currencies c ON la.currency_id = c.id
    ORDER BY la.account_type ASC, la.name ASC
");
$ledgersStmt->execute();
$allLedgers = $ledgersStmt->fetchAll(PDO::FETCH_ASSOC);

$ledgersById = [];
foreach ($allLedgers as $l) {
    $ledgersById[(int)$l['id']] = $l;
}

// Distinct account_types for the Group dropdown
$accountTypes = [];
foreach ($allLedgers as $l) {
    if (!in_array($l['account_type'], $accountTypes, true)) {
        $accountTypes[] = $l['account_type'];
    }
}
sort($accountTypes);

// --------------------------------------------------------
// Compute one ledger's statement: opening, rows, closing
// --------------------------------------------------------
function getLedgerStatement($conn, $ledger, $dateFrom, $dateTo) {
    $ledgerId = (int)$ledger['id'];

    // Opening balance: ledger.opening_balance + (DR-CR) of all transactions strictly before $dateFrom
    $stmt = $conn->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN td.dr_cr = 'DR' THEN td.amount_base ELSE 0 END), 0) AS dr_total,
            COALESCE(SUM(CASE WHEN td.dr_cr = 'CR' THEN td.amount_base ELSE 0 END), 0) AS cr_total
        FROM transaction_details td
        INNER JOIN transactions t ON td.transaction_id = t.id
        WHERE td.ledger_id = ? AND t.status = 'active' AND t.date < ?
    ");
    $stmt->execute([$ledgerId, $dateFrom]);
    $priorTotals = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['dr_total' => 0, 'cr_total' => 0];
    $opening = (float)$ledger['opening_balance'] + (float)$priorTotals['dr_total'] - (float)$priorTotals['cr_total'];

    // Rows in the date range
    $stmt = $conn->prepare("
        SELECT
            t.id           AS transaction_id,
            t.date         AS txn_date,
            t.voucher_type,
            t.voucher_number,
            t.voucher_id,
            t.narration,
            td.amount,
            td.amount_base,
            td.rate_of_exchange,
            td.dr_cr,
            td.currency_id,
            cur.code   AS currency_code,
            cur.symbol AS currency_symbol
        FROM transaction_details td
        INNER JOIN transactions t ON td.transaction_id = t.id
        LEFT JOIN currencies cur  ON td.currency_id = cur.id
        WHERE td.ledger_id = ? AND t.status = 'active'
          AND t.date BETWEEN ? AND ?
        ORDER BY t.date ASC, t.id ASC, td.id ASC
    ");
    $stmt->execute([$ledgerId, $dateFrom, $dateTo]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $running = $opening;
    $totalDr = 0.0;
    $totalCr = 0.0;
    foreach ($rows as &$r) {
        $base = (float)$r['amount_base'];
        if ($r['dr_cr'] === 'DR') {
            $running += $base;
            $totalDr += $base;
            $r['dr'] = $base;
            $r['cr'] = 0.0;
        } else {
            $running -= $base;
            $totalCr += $base;
            $r['dr'] = 0.0;
            $r['cr'] = $base;
        }
        $r['running_balance'] = $running;
    }
    unset($r);

    return [
        'ledger'   => $ledger,
        'opening'  => $opening,
        'rows'     => $rows,
        'total_dr' => $totalDr,
        'total_cr' => $totalCr,
        'closing'  => $running,
    ];
}

// --------------------------------------------------------
// Build statements for each selected ledger
// --------------------------------------------------------
$statements = [];
foreach ($selectedLedgerIds as $lid) {
    if (!isset($ledgersById[$lid])) continue;
    $statements[] = getLedgerStatement($conn, $ledgersById[$lid], $dateFrom, $dateTo);
}

// Group-list view: when group is selected and no ledgers picked yet
$groupLedgers = [];
$groupClosingBalances = []; // ledger_id => closing balance as of $dateTo
if ($groupFilter !== '' && empty($selectedLedgerIds)) {
    foreach ($allLedgers as $l) {
        if ($l['account_type'] === $groupFilter) {
            $groupLedgers[] = $l;
        }
    }

    // One aggregate query for all ledgers in the group: closing balance as of To date
    if (!empty($groupLedgers)) {
        $stmt = $conn->prepare("
            SELECT la.id,
                   (la.opening_balance + COALESCE(d.delta, 0)) AS closing_balance
            FROM ledger_accounts la
            LEFT JOIN (
                SELECT td.ledger_id,
                       SUM(CASE WHEN td.dr_cr = 'DR' THEN td.amount_base ELSE -td.amount_base END) AS delta
                FROM transaction_details td
                INNER JOIN transactions t ON td.transaction_id = t.id
                WHERE t.status = 'active' AND t.date <= ?
                GROUP BY td.ledger_id
            ) d ON d.ledger_id = la.id
            WHERE la.account_type = ?
        ");
        $stmt->execute([$dateTo, $groupFilter]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $groupClosingBalances[(int)$row['id']] = (float)$row['closing_balance'];
        }
    }
}

// --------------------------------------------------------
// Helpers
// --------------------------------------------------------
function fmtAmt($n) {
    return number_format((float)$n, 2);
}

function buildLedgerLink($ledgerId, $dateFrom, $dateTo, $standalone = false, $forex = false, $group = '') {
    $params = [
        'date_from'    => $dateFrom,
        'date_to'      => $dateTo,
        'ledger_ids[]' => (int)$ledgerId,
    ];
    if ($standalone) $params['standalone'] = '1';
    if ($forex)      $params['forex']      = '1';
    if ($group !== '') $params['group']    = $group;
    return BASE_URL . 'modules/reports/ledger_report.php?' . http_build_query($params);
}

function voucherLink($r) {
    // Drill-down links from the ledger report into the source document.
    // Uses standalone view pages (or list?view=ID for screens that don't
    // have a dedicated view file) so the row always loads from the DB.
    if (empty($r['voucher_id'])) return null;

    switch ($r['voucher_type']) {
        case 'purchase':
            return BASE_URL . 'modules/transactions/purchases_view.php?id=' . (int)$r['voucher_id'];

        case 'purchase_import':
            // Imports use the listing's view modal (no standalone view page).
            return BASE_URL . 'modules/transactions/purchase_imports.php?view=' . (int)$r['voucher_id'];

        case 'journal':
            // Auto-generated journal vouchers (Bucket B costs on imports etc.).
            // Their listing has no per-voucher deep link yet, so jump to the
            // list which the user can filter from.
            return BASE_URL . 'modules/transactions/journal_vouchers.php';
    }
    return null;
}

require_once '../../includes/header.php';
?>

<style>
.ledger-section { margin-bottom: 2rem; }
.ledger-section .section-header {
    background: #f1f3f5;
    padding: 0.75rem 1rem;
    border-left: 4px solid #0d6efd;
    display: flex; justify-content: space-between; align-items: center;
}
.ledger-section .opening-row,
.ledger-section .closing-row { background: #fff8e1; font-weight: 600; }
.group-card { cursor: pointer; transition: transform .1s; }
.group-card:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,.08); }
@media print {
    .no-print, .filter-card { display: none !important; }
    .ledger-section { page-break-inside: avoid; }
}
</style>

<?php
/* A ledger filtered to one account is the statement that gets sent to that
   customer or supplier, so the printout needs to say who issued it. Print-only,
   so the on-screen report is unchanged. Included for the standalone deep-link
   view too — that one has no Print button but still prints from the browser. */
$printLetterheadTitle = __('ledger_report');
require __DIR__ . '/../../includes/print_letterhead.php';
?>

<?php if (!$standalone): ?>
<div class="row mb-3">
    <div class="col-md-9">
        <h2><i class="bi bi-journal-text"></i> <?php _e('ledger_report'); ?></h2>
        <p class="text-muted mb-0">
            <?php _e('ledger_report_help'); ?>
        </p>
    </div>
    <div class="col-md-3 text-end">
        <button class="btn btn-secondary no-print" onclick="window.print()">
            <i class="bi bi-printer"></i> <?php _e('print'); ?>
        </button>
    </div>
</div>

<!-- Filter bar -->
<div class="card mb-3 filter-card no-print">
    <div class="card-body">
        <form method="GET" id="filterForm">
            <div class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small mb-1"><?php _e('from_date'); ?></label>
                    <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($dateFrom); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1"><?php _e('to_date'); ?></label>
                    <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($dateTo); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1"><?php _e('group'); ?></label>
                    <select name="group" id="groupFilter" class="form-select form-select-sm">
                        <option value=""><?php _e('all_groups'); ?></option>
                        <?php foreach ($accountTypes as $at): ?>
                        <option value="<?php echo htmlspecialchars($at); ?>" <?php echo $groupFilter === $at ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(__('lt_' . $at, ucfirst($at))); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small mb-1"><?php _e('ledgers'); ?></label>
                    <select name="ledger_ids[]" id="ledgerFilter" class="form-select form-select-sm" multiple size="4">
                        <?php foreach ($allLedgers as $l): ?>
                        <option value="<?php echo (int)$l['id']; ?>"
                                data-group="<?php echo htmlspecialchars($l['account_type']); ?>"
                                <?php echo in_array((int)$l['id'], $selectedLedgerIds, true) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($l['name']); ?>
                            (<?php echo htmlspecialchars(__('lt_' . $l['account_type'], $l['account_type'])); ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted"><?php _e('multi_select_hint'); ?></small>
                </div>
                <div class="col-md-2 d-flex flex-column gap-1">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" name="forex" value="1" id="forexToggle" <?php echo $showForex ? 'checked' : ''; ?>>
                        <label class="form-check-label small" for="forexToggle"><?php _e('show_forex'); ?></label>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-funnel"></i> <?php _e('apply'); ?>
                    </button>
                    <a href="<?php echo BASE_URL; ?>modules/reports/ledger_report.php" class="btn btn-link btn-sm p-0">
                        <i class="bi bi-x-circle"></i> <?php _e('clear_filters'); ?>
                    </a>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($groupLedgers)): ?>
<!-- Group view: list ledgers in this group, click to open in new tab -->
<div class="card mb-3">
    <div class="card-header">
        <strong><?php echo htmlspecialchars(__('lt_' . $groupFilter, $groupFilter)); ?></strong>
        &mdash; <?php echo count($groupLedgers); ?> <?php _e('ledgers'); ?>
    </div>
    <div class="card-body">
        <div class="row g-2">
            <?php foreach ($groupLedgers as $gl):
                $closing = isset($groupClosingBalances[(int)$gl['id']]) ? $groupClosingBalances[(int)$gl['id']] : (float)$gl['opening_balance'];
                $balanceClass = $closing < 0 ? 'text-danger' : ($closing > 0 ? 'text-success' : 'text-muted');
            ?>
            <div class="col-md-4 col-lg-3">
                <a class="card group-card text-decoration-none text-dark"
                   href="<?php echo buildLedgerLink($gl['id'], $dateFrom, $dateTo, true, $showForex); ?>"
                   target="_blank" rel="noopener">
                    <div class="card-body p-2">
                        <div class="fw-bold"><?php echo htmlspecialchars($gl['name']); ?></div>
                        <div class="small text-muted">
                            <?php _e('closing_balance'); ?> (<?php echo htmlspecialchars($dateTo); ?>):
                            <span class="fw-semibold <?php echo $balanceClass; ?>"><?php echo fmtAmt($closing); ?></span>
                            <?php if (!empty($gl['currency_code'])): ?>
                              &middot; <?php echo htmlspecialchars($gl['currency_code']); ?>
                            <?php endif; ?>
                        </div>
                        <div class="small text-primary mt-1">
                            <i class="bi bi-box-arrow-up-right"></i> <?php _e('open_in_new_tab'); ?>
                        </div>
                    </div>
                </a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($statements)): ?>
<?php foreach ($statements as $st): $l = $st['ledger']; ?>
<div class="card ledger-section">
    <div class="section-header">
        <div>
            <strong class="h5 mb-0"><?php echo htmlspecialchars($l['name']); ?></strong>
            <span class="badge bg-secondary ms-2"><?php echo htmlspecialchars(__('lt_' . $l['account_type'], $l['account_type'])); ?></span>
            <small class="text-muted ms-2">
                <?php echo htmlspecialchars($dateFrom); ?> &mdash; <?php echo htmlspecialchars($dateTo); ?>
            </small>
        </div>
        <div class="no-print">
            <a class="btn btn-sm btn-outline-primary"
               href="<?php echo buildLedgerLink($l['id'], $dateFrom, $dateTo, true, $showForex); ?>"
               target="_blank" rel="noopener" title="<?php _e('open_in_new_tab'); ?>">
                <i class="bi bi-box-arrow-up-right"></i>
            </a>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-bordered mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width: 100px;"><?php _e('date'); ?></th>
                    <th style="width: 150px;"><?php _e('voucher'); ?></th>
                    <th><?php _e('narration'); ?></th>
                    <?php if ($showForex): ?>
                    <th class="text-end" style="width: 120px;"><?php _e('original_amount'); ?></th>
                    <th style="width: 60px;"><?php _e('currency'); ?></th>
                    <th class="text-end" style="width: 80px;"><?php _e('rate'); ?></th>
                    <?php endif; ?>
                    <th class="text-end" style="width: 110px;"><?php _e('debit'); ?></th>
                    <th class="text-end" style="width: 110px;"><?php _e('credit'); ?></th>
                    <th class="text-end" style="width: 130px;"><?php _e('running_balance'); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr class="opening-row">
                    <td colspan="<?php echo $showForex ? 6 : 3; ?>" class="text-end"><?php _e('opening_balance'); ?>:</td>
                    <td class="text-end">&mdash;</td>
                    <td class="text-end">&mdash;</td>
                    <td class="text-end"><?php echo fmtAmt($st['opening']); ?></td>
                </tr>
                <?php if (empty($st['rows'])): ?>
                <tr>
                    <td colspan="<?php echo $showForex ? 9 : 6; ?>" class="text-center text-muted py-3">
                        <?php _e('no_transactions_in_range'); ?>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($st['rows'] as $r):
                    $voucherUrl = voucherLink($r);
                    $voucherLabel = trim(($r['voucher_type'] ? ucfirst($r['voucher_type']) . ' ' : '') . ($r['voucher_number'] ?: ''));
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($r['txn_date']); ?></td>
                    <td>
                        <?php if ($voucherUrl): ?>
                        <a href="<?php echo $voucherUrl; ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($voucherLabel); ?></a>
                        <?php else: ?>
                        <?php echo htmlspecialchars($voucherLabel); ?>
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($r['narration']); ?></td>
                    <?php if ($showForex): ?>
                    <td class="text-end"><?php echo fmtAmt($r['amount']); ?></td>
                    <td><?php echo htmlspecialchars($r['currency_code'] ?: ''); ?></td>
                    <td class="text-end"><?php echo number_format((float)$r['rate_of_exchange'], 4); ?></td>
                    <?php endif; ?>
                    <td class="text-end"><?php echo $r['dr'] > 0 ? fmtAmt($r['dr']) : ''; ?></td>
                    <td class="text-end"><?php echo $r['cr'] > 0 ? fmtAmt($r['cr']) : ''; ?></td>
                    <td class="text-end"><?php echo fmtAmt($r['running_balance']); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
                <tr class="closing-row">
                    <td colspan="<?php echo $showForex ? 6 : 3; ?>" class="text-end"><?php _e('totals'); ?>:</td>
                    <td class="text-end"><?php echo fmtAmt($st['total_dr']); ?></td>
                    <td class="text-end"><?php echo fmtAmt($st['total_cr']); ?></td>
                    <td class="text-end">&mdash;</td>
                </tr>
                <tr class="closing-row">
                    <td colspan="<?php echo $showForex ? 6 : 3; ?>" class="text-end"><?php _e('closing_balance'); ?>:</td>
                    <td class="text-end">&mdash;</td>
                    <td class="text-end">&mdash;</td>
                    <td class="text-end"><?php echo fmtAmt($st['closing']); ?></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php if (empty($statements) && empty($groupLedgers)): ?>
<div class="card">
    <div class="card-body text-center py-5">
        <i class="bi bi-funnel" style="font-size: 4rem; color: #ccc;"></i>
        <h5 class="mt-3"><?php _e('select_filters_to_view'); ?></h5>
        <p class="text-muted mb-0"><?php _e('select_filters_hint'); ?></p>
    </div>
</div>
<?php endif; ?>

<script>
// jQuery + Select2 are loaded by footer.php which comes AFTER this <script>
// block. Defer all wiring to the window 'load' event so those libraries are
// guaranteed available when we touch them.
(function () {
    var GROUP_PLACEHOLDER  = <?php echo json_encode(__('all_groups')); ?>;
    var LEDGER_PLACEHOLDER = <?php echo json_encode(__('search_select_ledgers')); ?>;

    function refreshLedgerOptionsSelect2() {
        var group = $('#groupFilter').val() || '';
        var $sel  = $('#ledgerFilter');
        $sel.find('option').each(function () {
            var og = $(this).attr('data-group') || '';
            var matches = !group || og === group;
            $(this).prop('disabled', !matches);
            if (!matches) $(this).prop('selected', false);
        });
        $sel.trigger('change.select2');
    }

    function refreshLedgerOptionsRaw() {
        var group = document.getElementById('groupFilter').value;
        var sel = document.getElementById('ledgerFilter');
        if (!sel) return;
        for (var i = 0; i < sel.options.length; i++) {
            var opt = sel.options[i];
            var og = opt.getAttribute('data-group');
            opt.hidden = !!(group && og !== group);
            opt.disabled = opt.hidden;
            if (opt.hidden) opt.selected = false;
        }
    }

    function init() {
        if (window.jQuery && $.fn && $.fn.select2) {
            $('#groupFilter').select2({
                placeholder: GROUP_PLACEHOLDER,
                allowClear: true,
                width: '100%'
            });
            $('#ledgerFilter').select2({
                placeholder: LEDGER_PLACEHOLDER,
                width: '100%',
                closeOnSelect: false
            });
            $('#groupFilter').on('change', refreshLedgerOptionsSelect2);
            refreshLedgerOptionsSelect2();
        } else {
            // Fallback if Select2 didn't load
            var g = document.getElementById('groupFilter');
            if (g) g.addEventListener('change', refreshLedgerOptionsRaw);
            refreshLedgerOptionsRaw();
        }
    }

    // window 'load' fires after footer scripts (jQuery, Select2) finish loading.
    if (document.readyState === 'complete') {
        init();
    } else {
        window.addEventListener('load', init);
    }
})();
</script>

<style>
/* Make Select2 match Bootstrap form-control-sm sizing in the filter bar */
.filter-card .select2-container--default .select2-selection--single,
.filter-card .select2-container--default .select2-selection--multiple {
    min-height: calc(1.5em + 0.5rem + 2px);
    border-color: #ced4da;
    font-size: 0.875rem;
}
.filter-card .select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: calc(1.5em + 0.5rem);
}
.filter-card .select2-container--default .select2-selection--single .select2-selection__arrow {
    height: calc(1.5em + 0.5rem + 2px);
}
</style>

<?php require_once '../../includes/footer.php'; ?>
