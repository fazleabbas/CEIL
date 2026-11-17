<?php
/**
 * Journal Vouchers Sync API — create / update / delete for MANUAL journal
 * vouchers only (voucher_type='manual_journal').
 *
 * Manual JVs are standalone, single-currency, balanced double-entry vouchers
 * with no parent source document (voucher_id = NULL, source_voucher_type =
 * NULL). Any number of DR/CR lines are allowed against ANY ledger account
 * (freeform), but total DR (base) must equal total CR (base).
 *
 * Auto-generated JVs (voucher_type='journal', created from purchase imports)
 * are NEVER touched here — update/delete hard-guard on voucher_type so they
 * stay read-only and continue to be rebuilt by their parent import.
 *
 * GET  ?action=ledgers  → list of all ledger accounts for the DR/CR dropdowns.
 * POST {action, data}   → create | update | delete.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

/**
 * Validate + normalise the posted DR/CR lines for a single-currency JV.
 * Returns [normalisedLines, currencyId, fx]. Throws on any problem.
 *
 *   $fx = currency's exchange rate to base; amount_base = amount * fx.
 */
function jvPrepareLines($conn, $data) {
    $currencyId = !empty($data['currency_id']) ? (int)$data['currency_id'] : null;
    if (!$currencyId) {
        $def = getDefaultCurrency();
        $currencyId = $def ? (int)$def['id'] : null;
    }
    if (!$currencyId) {
        throw new Exception('A currency is required');
    }

    $fx = isset($data['exchange_rate']) ? (float)$data['exchange_rate'] : 1.0;
    if ($fx <= 0) { $fx = 1.0; }

    $rawLines = $data['lines'] ?? [];
    if (!is_array($rawLines) || count($rawLines) < 2) {
        throw new Exception('A journal voucher needs at least two lines (one DR and one CR)');
    }

    // Collect valid ledger ids once to validate against (freeform = any ledger).
    $validLedgers = [];
    foreach ($conn->query("SELECT id FROM ledger_accounts")->fetchAll(PDO::FETCH_COLUMN) as $lid) {
        $validLedgers[(int)$lid] = true;
    }

    $lines = [];
    $totalDr = 0.0;
    $totalCr = 0.0;
    $hasDr = false;
    $hasCr = false;

    foreach ($rawLines as $i => $ln) {
        $ledgerId = (int)($ln['ledger_id'] ?? 0);
        $drCr     = strtoupper(trim($ln['dr_cr'] ?? ''));
        $amount   = round((float)($ln['amount'] ?? 0), 2);

        if (!$ledgerId || empty($validLedgers[$ledgerId])) {
            throw new Exception('Line ' . ($i + 1) . ': please choose a valid ledger account');
        }
        if ($drCr !== 'DR' && $drCr !== 'CR') {
            throw new Exception('Line ' . ($i + 1) . ': must be DR or CR');
        }
        if ($amount <= 0) {
            throw new Exception('Line ' . ($i + 1) . ': amount must be greater than zero');
        }

        $amountBase = round($amount * $fx, 2);
        if ($drCr === 'DR') { $totalDr += $amountBase; $hasDr = true; }
        else                { $totalCr += $amountBase; $hasCr = true; }

        $lines[] = [
            'ledger_id'   => $ledgerId,
            'dr_cr'       => $drCr,
            'amount'      => $amount,
            'amount_base' => $amountBase,
        ];
    }

    if (!$hasDr || !$hasCr) {
        throw new Exception('A journal voucher needs at least one DR line and one CR line');
    }
    // Compare on base currency, allowing a 1-cent rounding tolerance.
    if (abs($totalDr - $totalCr) > 0.01) {
        throw new Exception(sprintf(
            'Voucher is not balanced: total DR (%.2f) must equal total CR (%.2f)',
            $totalDr, $totalCr
        ));
    }

    return [$lines, $currencyId, $fx];
}

/** Insert the DR/CR detail rows for a transaction. */
function jvInsertDetails($conn, $txId, $lines, $currencyId, $fx) {
    $stmt = $conn->prepare("
        INSERT INTO transaction_details
            (transaction_id, ledger_id, currency_id, rate_of_exchange, amount, amount_base, dr_cr)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($lines as $ln) {
        $stmt->execute([
            $txId, $ln['ledger_id'], $currencyId, $fx,
            $ln['amount'], $ln['amount_base'], $ln['dr_cr'],
        ]);
    }
}

/**
 * Load a manual JV transaction by id, guarding that it really is a manual one.
 * Throws if not found or if it is an auto-generated (read-only) JV.
 */
function jvLoadManualOrFail($conn, $id) {
    $stmt = $conn->prepare("SELECT * FROM transactions WHERE id = ?");
    $stmt->execute([$id]);
    $tx = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tx) {
        throw new Exception('Journal voucher not found');
    }
    if ($tx['voucher_type'] !== 'manual_journal') {
        throw new Exception('This journal voucher is auto-generated from its source document and cannot be edited or deleted here');
    }
    return $tx;
}

try {
    // ---- GET: ledger list for the dropdowns (freeform = all ledgers) ----
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (($_GET['action'] ?? '') === 'ledgers') {
            if (!hasPermission('journal_vouchers', 'view')) {
                throw new Exception('Permission denied');
            }
            // Every active ledger, of every account_type — a manual JV may hit
            // any of them. Ordered so the accounts a JV normally touches come
            // first and the (many) supplier ledgers sit last; the leading
            // "= 0" term parks any future enum value we haven't listed at the
            // end instead of the top. The page renders one <optgroup> per type
            // in exactly this order.
            $typeOrder = "FIELD(account_type,'cash','bank','customer','expense','sales','purchase','duties','supplier')";
            $rows = $conn->query("
                SELECT id, name, account_type
                FROM ledger_accounts
                WHERE is_active = 1
                ORDER BY ($typeOrder = 0), $typeOrder, name
            ")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'ledgers' => $rows]);
            exit;
        }
        throw new Exception('Invalid action');
    }

    $input  = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    $data   = $input['data'] ?? [];

    switch ($action) {
        case 'create': {
            if (!hasPermission('journal_vouchers', 'create')) {
                throw new Exception('Permission denied');
            }

            $date = trim($data['date'] ?? '');
            if ($date === '') {
                throw new Exception('Date is required');
            }
            $narration = trim($data['narration'] ?? '');
            $costCentreId = !empty($data['cost_centre_id']) ? (int)$data['cost_centre_id'] : null;

            list($lines, $currencyId, $fx) = jvPrepareLines($conn, $data);

            $conn->beginTransaction();
            try {
                $voucherNumber = getNextNumber('transactions', 'voucher_number', 'JV');

                $stmt = $conn->prepare("
                    INSERT INTO transactions
                        (date, voucher_id, voucher_type, voucher_number, narration,
                         source_voucher_type, source_voucher_id,
                         cost_centre_id, status, created_by)
                    VALUES (?, NULL, 'manual_journal', ?, ?, NULL, NULL, ?, 'active', ?)
                ");
                $stmt->execute([$date, $voucherNumber, $narration, $costCentreId, getUserId()]);
                $txId = (int)$conn->lastInsertId();

                jvInsertDetails($conn, $txId, $lines, $currencyId, $fx);

                $conn->commit();
            } catch (Exception $e) {
                $conn->rollBack();
                throw $e;
            }

            logAudit('journal_vouchers', 'create', $txId, null, $data);
            echo json_encode([
                'success' => true,
                'message' => 'Journal voucher ' . $voucherNumber . ' created',
                'id' => $txId,
                'voucher_number' => $voucherNumber,
            ]);
            break;
        }

        case 'update': {
            if (!hasPermission('journal_vouchers', 'edit')) {
                throw new Exception('Permission denied');
            }

            $id = (int)($data['id'] ?? 0);
            if (!$id) {
                throw new Exception('Invalid voucher id');
            }
            $old = jvLoadManualOrFail($conn, $id);

            $date = trim($data['date'] ?? '');
            if ($date === '') {
                throw new Exception('Date is required');
            }
            $narration = trim($data['narration'] ?? '');
            $costCentreId = !empty($data['cost_centre_id']) ? (int)$data['cost_centre_id'] : null;

            list($lines, $currencyId, $fx) = jvPrepareLines($conn, $data);

            $conn->beginTransaction();
            try {
                $stmt = $conn->prepare("
                    UPDATE transactions
                    SET date = ?, narration = ?, cost_centre_id = ?
                    WHERE id = ? AND voucher_type = 'manual_journal'
                ");
                $stmt->execute([$date, $narration, $costCentreId, $id]);

                // Rebuild the DR/CR lines from scratch (keeps the voucher number).
                $conn->prepare("DELETE FROM transaction_details WHERE transaction_id = ?")->execute([$id]);
                jvInsertDetails($conn, $id, $lines, $currencyId, $fx);

                $conn->commit();
            } catch (Exception $e) {
                $conn->rollBack();
                throw $e;
            }

            logAudit('journal_vouchers', 'update', $id, $old, $data);
            echo json_encode(['success' => true, 'message' => 'Journal voucher updated']);
            break;
        }

        case 'delete': {
            if (!hasPermission('journal_vouchers', 'delete')) {
                throw new Exception('Permission denied');
            }

            $id = (int)($data['id'] ?? 0);
            if (!$id) {
                throw new Exception('Invalid voucher id');
            }
            $old = jvLoadManualOrFail($conn, $id);

            // transaction_details rows cascade via FK ON DELETE CASCADE.
            $conn->prepare("DELETE FROM transactions WHERE id = ? AND voucher_type = 'manual_journal'")
                 ->execute([$id]);

            logAudit('journal_vouchers', 'delete', $id, $old, null);
            echo json_encode(['success' => true, 'message' => 'Journal voucher deleted']);
            break;
        }

        default:
            throw new Exception('Invalid action');
    }

} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
