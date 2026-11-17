<?php
require_once '../../config/database.php';
require_once '../../includes/functions.php';

$isEdit = isset($_GET['id']) && !empty($_GET['id']);
$pageTitle = $isEdit ? __('edit_requisition_voucher') : __('new_requisition_voucher');

if (!hasPermission('requisition_vouchers', 'create')) {
    setAlert(__('no_permission'), 'danger');
    redirect('');
}

/**
 * =====================================================================
 * POST-APPROVAL EDIT SUPPORT (approved requisitions only)
 * =====================================================================
 * A DRAFT edit deletes every detail row and re-inserts them — cheap and
 * correct, because a draft has nothing hanging off its lines. That path is
 * untouched and must stay that way.
 *
 * An APPROVED requisition cannot be edited like that. Its detail rows carry
 * approved_quantity and transferred_quantity, and stock_transfer_details
 * points at them through requisition_detail_id (FK ON DELETE SET NULL). A
 * delete-and-reinsert would therefore wipe the approved figures, reset the
 * transferred totals to zero and silently sever the transfer links that
 * transfer reversal depends on. So the approved path below is diff-based:
 * existing rows are UPDATEd in place, only genuinely new products are
 * INSERTed, and only lines the user removed are DELETEd — never one that has
 * already dispatched stock.
 */

/**
 * Whether a column exists (guards tenants that have not run every migration).
 */
function rvfHasColumn($conn, $table, $column) {
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
        ");
        $stmt->execute([$table, $column]);
        $cache[$key] = ((int)$stmt->fetchColumn() > 0);
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

/**
 * Human-readable name lookups used to build the change log. Names are resolved
 * at write time and stored in the log, so the history keeps reading correctly
 * even if a location or product is renamed later.
 */
function rvfLookupName($conn, $table, $id, $column = 'name') {
    $id = (int)$id;
    if ($id <= 0) {
        return '';
    }
    try {
        $stmt = $conn->prepare("SELECT `$column` FROM `$table` WHERE id = ?");
        $stmt->execute([$id]);
        $val = $stmt->fetchColumn();
        return ($val === false || $val === null) ? '' : (string)$val;
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Header-level diff between the stored requisition and what was submitted.
 *
 * @return array List of change entries for the log
 */
function rvfHeaderChanges($conn, $existing, $new, $hasBomCol) {
    $changes = [];

    if ((string)$existing['voucher_date'] !== (string)$new['voucher_date']) {
        $changes[] = [
            'type' => 'field',
            'key'  => 'voucher_date',
            'from' => formatDate($existing['voucher_date']),
            'to'   => formatDate($new['voucher_date']),
        ];
    }

    if ((int)$existing['destination_location_id'] !== (int)$new['destination_location_id']) {
        $changes[] = [
            'type' => 'field',
            'key'  => 'destination_location',
            'from' => rvfLookupName($conn, 'locations', $existing['destination_location_id']),
            'to'   => rvfLookupName($conn, 'locations', $new['destination_location_id']),
        ];
    }

    if ((int)$existing['cost_centre_id'] !== (int)$new['cost_centre_id']) {
        $changes[] = [
            'type' => 'field',
            'key'  => 'cost_centre',
            'from' => rvfLookupName($conn, 'cost_centres', $existing['cost_centre_id']),
            'to'   => rvfLookupName($conn, 'cost_centres', $new['cost_centre_id']),
        ];
    }

    if (trim((string)$existing['remarks']) !== trim((string)$new['remarks'])) {
        $changes[] = [
            'type' => 'field',
            'key'  => 'remarks',
            'from' => (string)$existing['remarks'],
            'to'   => (string)$new['remarks'],
        ];
    }

    if ($hasBomCol && (int)($existing['bom_id'] ?? 0) !== (int)($new['bom_id'] ?? 0)) {
        $changes[] = [
            'type' => 'field',
            'key'  => 'bom',
            'from' => rvfLookupName($conn, 'boms', $existing['bom_id'] ?? 0, 'bom_number'),
            'to'   => rvfLookupName($conn, 'boms', $new['bom_id'] ?? 0, 'bom_number'),
        ];
    }

    return $changes;
}

/**
 * Apply the line-item half of a post-approval edit, in place.
 *
 * Runs inside the caller's transaction: any Exception thrown here rolls the
 * whole edit back, so a rejected line can never leave the voucher half-changed.
 *
 * Rules enforced (all of them server-side, regardless of what the form sent):
 *  - a quantity may not fall below what that line has already transferred;
 *  - a line with any transferred quantity may be neither deleted nor have its
 *    product swapped;
 *  - a posted detail_id must belong to this requisition, and only once.
 *
 * The quantity entered becomes the APPROVED quantity as well as the requested
 * one — everything downstream (budget remaining, transfers, BOM balances)
 * reads COALESCE(approved_quantity, requested_quantity), so leaving the
 * approved figure behind would make the edit look applied while changing
 * nothing. The form states this before the user types.
 *
 * @return array ['changes' => [...], 'old_items' => [...], 'new_items' => [...]]
 */
function rvfApplyApprovedLineEdit($conn, $requisitionId, $detailIds, $products, $quantities, $itemRemarks) {
    $requisitionId = (int)$requisitionId;
    $hasApprovedCol = rvfHasColumn($conn, 'requisition_voucher_details', 'approved_quantity');

    $stmt = $conn->prepare("
        SELECT rvd.*, p.name AS product_name
        FROM requisition_voucher_details rvd
        LEFT JOIN products p ON rvd.product_id = p.id
        WHERE rvd.requisition_id = ?
        ORDER BY rvd.id
    ");
    $stmt->execute([$requisitionId]);
    $existingRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $existingById = [];
    $oldItems = [];
    foreach ($existingRows as $row) {
        $existingById[(int)$row['id']] = $row;
        $oldItems[] = [
            'detail_id'   => (int)$row['id'],
            'product'     => (string)$row['product_name'],
            'quantity'    => (float)$row['requested_quantity'],
            'approved'    => isset($row['approved_quantity']) && $row['approved_quantity'] !== null
                             ? (float)$row['approved_quantity'] : null,
            'transferred' => (float)$row['transferred_quantity'],
            'remarks'     => (string)($row['remarks'] ?? ''),
        ];
    }

    // ---- Parse what was posted -------------------------------------------
    $posted = [];
    $seenDetailIds = [];
    foreach ($products as $index => $rawProductId) {
        $productId = (int)$rawProductId;
        $qty = round(floatval($quantities[$index] ?? 0), 3);
        $detailId = (int)($detailIds[$index] ?? 0);
        if ($productId <= 0 || $qty < 0) {
            continue;
        }
        // A line the approver rejected sits at 0. Posting it back at 0 keeps it
        // exactly as they left it — only a line missing from the POST entirely
        // counts as a removal. A NEW line still needs a real quantity.
        if ($qty <= 0 && $detailId <= 0) {
            continue;
        }
        if ($detailId > 0) {
            if (!isset($existingById[$detailId]) || isset($seenDetailIds[$detailId])) {
                throw new Exception(__('requisition_edit_line_mismatch'));
            }
            $seenDetailIds[$detailId] = true;
        }
        $posted[] = [
            'detail_id'  => $detailId,
            'product_id' => $productId,
            'qty'        => $qty,
            'remarks'    => clean($itemRemarks[$index] ?? ''),
        ];
    }

    if (empty($posted)) {
        throw new Exception(__('please_add_at_least_one_item'));
    }

    $changes = [];

    // ---- Removed lines ----------------------------------------------------
    foreach ($existingRows as $row) {
        $detailId = (int)$row['id'];
        if (isset($seenDetailIds[$detailId])) {
            continue;
        }
        if (floatval($row['transferred_quantity']) > 0.0001) {
            throw new Exception(sprintf(
                __('requisition_line_transferred_no_delete'),
                (string)$row['product_name'],
                formatNumber($row['transferred_quantity'], 3)
            ));
        }
        $del = $conn->prepare("DELETE FROM requisition_voucher_details WHERE id = ? AND requisition_id = ?");
        $del->execute([$detailId, $requisitionId]);

        $removedQty = (isset($row['approved_quantity']) && $row['approved_quantity'] !== null)
            ? $row['approved_quantity'] : $row['requested_quantity'];
        $changes[] = [
            'type'  => 'item_removed',
            'label' => (string)$row['product_name'],
            'from'  => formatNumber($removedQty, 3),
        ];
    }

    // ---- Updated + added lines -------------------------------------------
    // The quantity box on an approved voucher IS the approved quantity, so an
    // existing line keeps its original requested_quantity — overwriting it would
    // destroy the record of what was actually asked for. Only a tenant with no
    // approved_quantity column (pre-0023) writes requested_quantity here.
    $updSql = $hasApprovedCol
        ? "UPDATE requisition_voucher_details
           SET product_id = ?, approved_quantity = ?, remarks = ?
           WHERE id = ? AND requisition_id = ?"
        : "UPDATE requisition_voucher_details
           SET product_id = ?, requested_quantity = ?, remarks = ?
           WHERE id = ? AND requisition_id = ?";
    $upd = $conn->prepare($updSql);

    $insSql = $hasApprovedCol
        ? "INSERT INTO requisition_voucher_details
           (requisition_id, product_id, requested_quantity, approved_quantity, remarks)
           VALUES (?, ?, ?, ?, ?)"
        : "INSERT INTO requisition_voucher_details
           (requisition_id, product_id, requested_quantity, remarks)
           VALUES (?, ?, ?, ?)";
    $ins = $conn->prepare($insSql);

    foreach ($posted as $line) {
        $detailId = $line['detail_id'];

        if ($detailId > 0) {
            $row = $existingById[$detailId];
            $transferred = floatval($row['transferred_quantity']);
            $productName = (string)$row['product_name'];

            // Floor: a line can never be cut below what has already gone out.
            if ($line['qty'] < $transferred - 0.0001) {
                throw new Exception(sprintf(
                    __('requisition_qty_below_transferred'),
                    $productName,
                    formatNumber($line['qty'], 3),
                    formatNumber($transferred, 3)
                ));
            }

            $productChanged = ((int)$row['product_id'] !== $line['product_id']);
            if ($productChanged && $transferred > 0.0001) {
                throw new Exception(sprintf(
                    __('requisition_line_transferred_no_product_change'),
                    $productName
                ));
            }

            $upd->execute([$line['product_id'], $line['qty'], $line['remarks'], $detailId, $requisitionId]);

            if ($productChanged) {
                $changes[] = [
                    'type'  => 'item_replaced',
                    'label' => $productName,
                    'from'  => $productName,
                    'to'    => rvfLookupName($conn, 'products', $line['product_id']),
                ];
            }

            // Compare against the EFFECTIVE quantity the rest of the app reads,
            // so a change that actually moves downstream numbers is what gets
            // logged (COALESCE(approved_quantity, requested_quantity)).
            $oldEffective = ($hasApprovedCol && $row['approved_quantity'] !== null)
                ? floatval($row['approved_quantity'])
                : floatval($row['requested_quantity']);
            if (abs($oldEffective - $line['qty']) > 0.0005) {
                $changes[] = [
                    'type'  => 'item_qty',
                    'label' => $productChanged ? rvfLookupName($conn, 'products', $line['product_id']) : $productName,
                    'from'  => formatNumber($oldEffective, 3),
                    'to'    => formatNumber($line['qty'], 3),
                ];
            }

            if (trim((string)($row['remarks'] ?? '')) !== trim($line['remarks'])) {
                $changes[] = [
                    'type'  => 'item_remarks',
                    'label' => $productChanged ? rvfLookupName($conn, 'products', $line['product_id']) : $productName,
                    'from'  => (string)($row['remarks'] ?? ''),
                    'to'    => $line['remarks'],
                ];
            }
        } else {
            if ($hasApprovedCol) {
                $ins->execute([$requisitionId, $line['product_id'], $line['qty'], $line['qty'], $line['remarks']]);
            } else {
                $ins->execute([$requisitionId, $line['product_id'], $line['qty'], $line['remarks']]);
            }
            $changes[] = [
                'type'  => 'item_added',
                'label' => rvfLookupName($conn, 'products', $line['product_id']),
                'to'    => formatNumber($line['qty'], 3),
            ];
        }
    }

    // ---- Post-edit snapshot ----------------------------------------------
    $stmt->execute([$requisitionId]);
    $newItems = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $newItems[] = [
            'detail_id'   => (int)$row['id'],
            'product'     => (string)$row['product_name'],
            'quantity'    => (float)$row['requested_quantity'],
            'approved'    => isset($row['approved_quantity']) && $row['approved_quantity'] !== null
                             ? (float)$row['approved_quantity'] : null,
            'transferred' => (float)$row['transferred_quantity'],
            'remarks'     => (string)($row['remarks'] ?? ''),
        ];
    }

    return ['changes' => $changes, 'old_items' => $oldItems, 'new_items' => $newItems];
}

// Whether the optional BOM link column exists (migration 0033). Guards all
// bom_id reads/writes so the page still works if the migration hasn't run.
$hasBomCol = (bool)$conn->query("
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'requisition_vouchers'
      AND COLUMN_NAME = 'bom_id'
")->fetchColumn();

// Initialize requisition data
$requisition = [
    'id' => '',
    'voucher_date' => date('Y-m-d'),
    'destination_location_id' => '',
    'cost_centre_id' => '',
    'bom_id' => '',
    'remarks' => '',
    'status' => 'draft'
];
$requisitionDetails = [];

// If editing, load existing requisition
if ($isEdit) {
    $id = (int)$_GET['id'];
    $stmt = $conn->prepare("
        SELECT rv.*
        FROM requisition_vouchers rv
        WHERE rv.id = ?
    ");
    $stmt->execute([$id]);
    $requisition = $stmt->fetch();

    if (!$requisition) {
        setAlert(__('requisition_not_found'), 'danger');
        redirect('modules/transactions/requisition_voucher_list.php');
    }

    // Draft editing is unchanged. An APPROVED requisition is also editable,
    // but only while nothing has been raised against it — requisitionCanEdit()
    // is the single place that decides, shared with the view page and the list
    // so they can never offer a pencil this form would then refuse. Every other
    // status (pending_approval, rejected, closed) stays locked exactly as before.
    if (!requisitionCanEdit($requisition, $conn)) {
        setAlert(
            $requisition['status'] === 'approved'
                ? __('requisition_edit_locked_by_procurement')
                : __('requisition_only_draft_edit'),
            'warning'
        );
        redirect('modules/transactions/requisition_voucher_view.php?id=' . $id);
    }

    // Load requisition details
    $detailsStmt = $conn->prepare("
        SELECT rvd.*, p.name as product_name, p.unit
        FROM requisition_voucher_details rvd
        LEFT JOIN products p ON rvd.product_id = p.id
        WHERE rvd.requisition_id = ?
        ORDER BY rvd.id
    ");
    $detailsStmt->execute([$id]);
    $requisitionDetails = $detailsStmt->fetchAll();
}

// Editing an already-approved voucher. The form warns before anything is typed
// (the quantity entered becomes the APPROVED quantity), shows each line's
// approved / transferred figures, and blocks removal of a dispatched line.
$isApprovedEdit = $isEdit && (($requisition['status'] ?? '') === 'approved');
$rvHasTransfers = $isApprovedEdit ? requisitionHasTransfers($requisition['id'], $conn) : false;

// Validation / save errors, rendered inline in the card body below. They are
// ALSO passed to setAlert() at the end of the POST block, but that goes nowhere
// — getAlert() has no callers anywhere in the app — so without the inline block
// a rejected save (a line cut below what it has already transferred, say) looks
// like a dead Save button.
$errors = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && hasPermission('requisition_vouchers', 'create')) {
    checkCSRF();

    $destinationLocationId = (int)$_POST['destination_location_id'];
    $costCentreId = !empty($_POST['cost_centre_id']) ? (int)$_POST['cost_centre_id'] : null;
    $voucherDate = dbDate($_POST['voucher_date']);
    $remarks = clean($_POST['remarks'] ?? '');
    $bomId = ($hasBomCol && !empty($_POST['bom_id'])) ? (int)$_POST['bom_id'] : null;
    $editId = !empty($_POST['edit_id']) ? (int)$_POST['edit_id'] : null;

    $products = $_POST['products'] ?? [];
    $quantities = $_POST['requested_quantity'] ?? [];
    $itemRemarks = $_POST['item_remarks'] ?? [];
    // Only read by the post-approval path, which matches posted rows to the
    // detail rows they came from. The draft path ignores it entirely.
    $detailIds = $_POST['detail_id'] ?? [];

    // Prepared By signature (optional). Validate base64 PNG/JPEG data URL.
    $preparedSig = $_POST['prepared_by_signature'] ?? '';
    if (!is_string($preparedSig) || $preparedSig === '') {
        $preparedSig = null;
    } elseif (!preg_match('#^data:image/(png|jpeg|jpg);base64,[A-Za-z0-9+/=]+$#', $preparedSig)
              || strlen($preparedSig) > 2 * 1024 * 1024) {
        $preparedSig = null;
    }

    $errors = [];

    // Post-approval edit state. All inert on the draft path.
    $isApprovedEditPost = false;
    $rvHeaderChanges = [];
    $rvChanges = [];
    $rvOldItems = [];
    $rvNewItems = [];

    if (empty($destinationLocationId)) {
        $errors[] = __('please_select_location');
    }

    if (empty($voucherDate)) {
        $errors[] = __('please_select_date');
    }

    // Validate at least one item
    $hasItems = false;
    foreach ($products as $index => $productId) {
        if (!empty($productId) && floatval($quantities[$index] ?? 0) > 0) {
            $hasItems = true;
            break;
        }
    }

    if (!$hasItems) {
        $errors[] = __('please_add_at_least_one_item');
    }

    // Only an APPROVED BOM may be newly linked (migration 0049). The BOM this
    // requisition is already linked to is always kept, whatever its status.
    if ($hasBomCol && $bomId) {
        $currentBomId = null;
        if ($editId) {
            $cbStmt = $conn->prepare("SELECT bom_id FROM requisition_vouchers WHERE id = ?");
            $cbStmt->execute([$editId]);
            $currentBomId = $cbStmt->fetchColumn() ?: null;
        }
        if ($bomLinkErr = bomLinkError($conn, $bomId, $currentBomId)) {
            $errors[] = $bomLinkErr;
        }
    }

    if (empty($errors)) {
        try {
            $conn->beginTransaction();

            if ($editId) {
                // Verify the requisition is still draft
                $checkStmt = $conn->prepare("SELECT * FROM requisition_vouchers WHERE id = ?");
                $checkStmt->execute([$editId]);
                $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
                $currentStatus = $existing ? $existing['status'] : null;

                $isApprovedEditPost = ($currentStatus === 'approved');

                if ($currentStatus !== 'draft' && !$isApprovedEditPost) {
                    throw new Exception(__('requisition_only_draft_edit'));
                }

                if ($isApprovedEditPost) {
                    // Re-checked INSIDE the transaction: a budget may have been
                    // raised between this page loading and this POST arriving.
                    if (!requisitionCanEdit($existing, $conn)) {
                        throw new Exception(__('requisition_edit_locked_by_procurement'));
                    }

                    // The destination freezes once anything has been dispatched
                    // — the same rule change_destination already enforces.
                    if ((int)$destinationLocationId !== (int)$existing['destination_location_id']
                        && requisitionHasTransfers($editId, $conn)) {
                        throw new Exception(__('rv_destination_locked_after_transfer'));
                    }

                    // Captured before the header UPDATE overwrites the old values.
                    $rvHeaderChanges = rvfHeaderChanges($conn, $existing, [
                        'voucher_date'            => $voucherDate,
                        'destination_location_id' => $destinationLocationId,
                        'cost_centre_id'          => $costCentreId,
                        'remarks'                 => $remarks,
                        'bom_id'                  => $bomId,
                    ], $hasBomCol);
                }

                // Nothing drawn and nothing stored yet: fall back to the
                // signature the PREPARER has on file. Deliberately keyed on
                // created_by, not the editor — the delivery note prints the
                // creator's name beside this image, so pulling a different
                // user's signature would pair the wrong name with it.
                if ($preparedSig === null && empty($existing['prepared_by_signature'])) {
                    $preparedSig = getUserSignature($conn, $existing['created_by']);
                }

                // Update header. Signature column is only touched when a
                // non-empty new signature was posted; never wipe an existing one.
                // bom_id is included only when the column exists (migration 0033).
                $bomSet = $hasBomCol ? ', bom_id = ?' : '';
                if ($preparedSig !== null) {
                    $stmt = $conn->prepare("
                        UPDATE requisition_vouchers
                        SET voucher_date = ?, destination_location_id = ?, cost_centre_id = ?, remarks = ?{$bomSet}, prepared_by_signature = ?
                        WHERE id = ?
                    ");
                    $params = [$voucherDate, $destinationLocationId, $costCentreId, $remarks];
                    if ($hasBomCol) $params[] = $bomId;
                    $params[] = $preparedSig;
                    $params[] = $editId;
                    $stmt->execute($params);
                } else {
                    $stmt = $conn->prepare("
                        UPDATE requisition_vouchers
                        SET voucher_date = ?, destination_location_id = ?, cost_centre_id = ?, remarks = ?{$bomSet}
                        WHERE id = ?
                    ");
                    $params = [$voucherDate, $destinationLocationId, $costCentreId, $remarks];
                    if ($hasBomCol) $params[] = $bomId;
                    $params[] = $editId;
                    $stmt->execute($params);
                }

                if ($isApprovedEditPost) {
                    // Diff the lines in place. Detail ids — and the
                    // stock_transfer_details rows that point at them — have to
                    // survive, so nothing is deleted wholesale here.
                    $lineResult = rvfApplyApprovedLineEdit($conn, $editId, $detailIds, $products, $quantities, $itemRemarks);
                    $rvChanges  = array_merge($rvHeaderChanges, $lineResult['changes']);
                    $rvOldItems = $lineResult['old_items'];
                    $rvNewItems = $lineResult['new_items'];
                } else {
                    // Draft: unchanged — delete old details, re-insert below.
                    $deleteStmt = $conn->prepare("DELETE FROM requisition_voucher_details WHERE requisition_id = ?");
                    $deleteStmt->execute([$editId]);
                }

                $voucherId = $editId;
            } else {
                // Nothing drawn on the pad — use the creator's signature on file.
                if ($preparedSig === null) {
                    $preparedSig = getUserSignature($conn, getUserId());
                }

                // Generate voucher number
                $voucherNumber = getNextNumber('requisition_vouchers', 'voucher_number', getSetting('rv_prefix', 'RV'));

                // Insert header (bom_id column included only when present).
                if ($hasBomCol) {
                    $stmt = $conn->prepare("
                        INSERT INTO requisition_vouchers
                        (voucher_number, voucher_date, destination_location_id, cost_centre_id, bom_id, remarks, status, created_by, prepared_by_signature)
                        VALUES (?, ?, ?, ?, ?, ?, 'draft', ?, ?)
                    ");
                    $stmt->execute([$voucherNumber, $voucherDate, $destinationLocationId, $costCentreId, $bomId, $remarks, getUserId(), $preparedSig]);
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO requisition_vouchers
                        (voucher_number, voucher_date, destination_location_id, cost_centre_id, remarks, status, created_by, prepared_by_signature)
                        VALUES (?, ?, ?, ?, ?, 'draft', ?, ?)
                    ");
                    $stmt->execute([$voucherNumber, $voucherDate, $destinationLocationId, $costCentreId, $remarks, getUserId(), $preparedSig]);
                }

                $voucherId = $conn->lastInsertId();
            }

            // Insert details. Skipped for a post-approval edit, which has
            // already updated / inserted / deleted its rows individually above.
            foreach (($isApprovedEditPost ? [] : $products) as $index => $productId) {
                $productId = (int)$productId;
                $qty = floatval($quantities[$index] ?? 0);
                $itemRemark = clean($itemRemarks[$index] ?? '');

                if ($productId > 0 && $qty > 0) {
                    $detailStmt = $conn->prepare("
                        INSERT INTO requisition_voucher_details
                        (requisition_id, product_id, requested_quantity, remarks)
                        VALUES (?, ?, ?, ?)
                    ");
                    $detailStmt->execute([$voucherId, $productId, $qty, $itemRemark]);
                }
            }

            $conn->commit();

            if ($isApprovedEditPost) {
                // Distinct action, so post-approval amendments are greppable and
                // a draft's ordinary edits never appear in the Change History.
                // Nothing actually changed = no log row (matches map_bom's no-op).
                if (!empty($rvChanges)) {
                    logAudit(
                        'requisition_voucher',
                        'edit_after_approval',
                        $voucherId,
                        ['header' => $rvHeaderChanges, 'items' => $rvOldItems],
                        ['changes' => $rvChanges, 'items' => $rvNewItems]
                    );
                }
            } else {
                $action = $editId ? 'update' : 'create';
                logAudit('requisition_voucher', $action, $voucherId);
            }
            setAlert($editId ? __('requisition_updated') : __('requisition_created'), 'success');
            redirect('modules/transactions/requisition_voucher_view.php?id=' . $voucherId);

        } catch (PDOException $e) {
            $conn->rollBack();
            $errors[] = sprintf(__('database_error'), $e->getMessage());
        } catch (Exception $e) {
            $conn->rollBack();
            $errors[] = $e->getMessage();
        }
    }

    if (!empty($errors)) {
        foreach ($errors as $error) {
            setAlert($error, 'danger');
        }
    }
}

require_once '../../includes/header.php';

// In edit mode the destination is already chosen and will be auto-locked on page load.
$destinationLockedOnLoad = $isEdit && !empty($requisition['destination_location_id']);

// Get user's accessible locations
$locations = getUserLocations();

// Get user's accessible cost centres
$costCentres = getUserCostCentres();

// Optional Bill of Materials list (within the user's accessible locations).
// Only loaded when the link column exists (migration 0033).
$boms = [];
if ($hasBomCol) {
    $locationIds = array_column($locations, 'id');
    if (!empty($locationIds)) {
        // The dropdown shows the BOM title (migration 0035). Guard the column so
        // this still works pre-migration; the label falls back to bom_number below.
        $hasBomTitleCol = (bool)$conn->query("
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'boms'
              AND COLUMN_NAME = 'title'
        ")->fetchColumn();
        $bomTitleCol = $hasBomTitleCol ? 'b.title AS title' : 'NULL AS title';
        $bomPlaceholders = implode(',', array_fill(0, count($locationIds), '?'));
        // Only APPROVED BOMs are offered (migration 0049).
        $bomApprovedOnly = bomApprovalAvailable($conn) ? "AND b.status = 'approved'" : '';
        $bomStmt = $conn->prepare("
            SELECT b.id, b.bom_number, $bomTitleCol, b.bom_date, l.name AS location_name
            FROM boms b
            LEFT JOIN locations l ON b.location_id = l.id
            WHERE b.location_id IN ($bomPlaceholders) $bomApprovedOnly
            ORDER BY b.id DESC
        ");
        $bomStmt->execute($locationIds);
        $boms = $bomStmt->fetchAll(PDO::FETCH_ASSOC);

        // The BOM this requisition is already linked to stays selectable even
        // when it is no longer approved (revised back to draft) or sits outside
        // the user's locations — otherwise the select falls back to blank and
        // the next save silently UNLINKS it.
        $linkedBomId = (int)($requisition['bom_id'] ?? 0);
        if ($linkedBomId > 0 && !in_array($linkedBomId, array_map('intval', array_column($boms, 'id')), true)) {
            $extraStmt = $conn->prepare("
                SELECT b.id, b.bom_number, $bomTitleCol, b.bom_date, l.name AS location_name
                FROM boms b
                LEFT JOIN locations l ON b.location_id = l.id
                WHERE b.id = ?
            ");
            $extraStmt->execute([$linkedBomId]);
            if ($extraRow = $extraStmt->fetch(PDO::FETCH_ASSOC)) {
                array_unshift($boms, $extraRow);
            }
        }
    }
}
$selectedBomId = $hasBomCol ? ($requisition['bom_id'] ?? '') : '';
?>

<div class="row justify-content-center">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="bi bi-clipboard-check"></i>
                    <?php echo $isEdit ? __('edit_requisition_voucher') : __('new_requisition_voucher'); ?>
                </h5>
                <div>
                    <a href="requisition_voucher_list.php" class="btn btn-sm btn-secondary">
                        <i class="bi bi-list"></i> <?php _e('list'); ?>
                    </a>
                </div>
            </div>
            <div class="card-body">
                <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <form method="POST" id="requisitionForm">
                    <?php echo csrfField(); ?>
                    <?php if ($isEdit): ?>
                    <input type="hidden" name="edit_id" value="<?php echo htmlspecialchars($requisition['id']); ?>">
                    <?php endif; ?>

                    <?php if ($isApprovedEdit): ?>
                    <div class="alert alert-warning">
                        <div class="fw-bold mb-1">
                            <i class="bi bi-exclamation-triangle"></i> <?php _e('rv_editing_approved_title'); ?>
                        </div>
                        <ul class="mb-0 small ps-3">
                            <li><?php _e('rv_editing_approved_status'); ?></li>
                            <li><?php _e('rv_editing_approved_qty'); ?></li>
                            <?php if ($rvHasTransfers): ?>
                            <li><?php _e('rv_editing_approved_transferred'); ?></li>
                            <?php endif; ?>
                            <li><?php _e('rv_editing_approved_logged'); ?></li>
                        </ul>
                    </div>
                    <?php endif; ?>

                    <div class="row mb-3">
                        <div class="col-md-3">
                            <label class="form-label required">
                                <?php _e('destination_location'); ?>
                                <span id="destinationLockedBadge" class="badge bg-secondary ms-1" style="display:none;">
                                    <i class="bi bi-lock-fill"></i> locked
                                </span>
                            </label>
                            <select name="destination_location_id" id="destinationLocationId" class="form-select" required>
                                <option value=""><?php _e('select_destination_location'); ?></option>
                                <?= renderTreeOptions($locations, $requisition['destination_location_id']) ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><?php _e('cost_centre'); ?></label>
                            <select name="cost_centre_id" id="costCentreId" class="form-select">
                                <option value=""><?php _e('select_cost_centre'); ?></option>
                                <?= renderTreeOptions($costCentres, isset($requisition['cost_centre_id']) ? $requisition['cost_centre_id'] : null, null, 'name', 'code') ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label required"><?php _e('voucher_date'); ?></label>
                            <input type="date" name="voucher_date" id="voucherDate" class="form-control"
                                   value="<?php echo htmlspecialchars($requisition['voucher_date']); ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><?php _e('remarks'); ?></label>
                            <textarea name="remarks" class="form-control" rows="1"
                                      placeholder="<?php echo __('optional'); ?>"><?php echo htmlspecialchars($requisition['remarks'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <?php if ($hasBomCol): ?>
                    <style>
                        /* Lines whose product belongs to the selected BOM. */
                        #requisitionItems .item-row.bom-line > td { background-color: #fff8e1; }
                    </style>
                    <div class="row mb-3">
                        <div class="col-md-5">
                            <label class="form-label"><i class="bi bi-diagram-3"></i> <?php _e('bom'); ?> <small class="text-muted">(<?php _e('optional'); ?>)</small></label>
                            <select name="bom_id" id="bomId" class="form-select">
                                <option value=""><?php _e('select_bom'); ?></option>
                                <?php foreach ($boms as $b):
                                    // Show the BOM title; fall back to bom_number when no title is set.
                                    $bomLabel = !empty($b['title']) ? $b['title'] : $b['bom_number'];
                                ?>
                                <option value="<?php echo (int)$b['id']; ?>" <?php echo ((string)$selectedBomId === (string)$b['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($bomLabel . ' — ' . formatDate($b['bom_date']) . ' — ' . ($b['location_name'] ?? '')); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <small id="bomLegend" class="text-muted d-block mt-1" style="display:none !important;">
                                <i class="bi bi-info-circle"></i> <?php _e('bom_balance_legend'); ?>
                            </small>
                        </div>
                    </div>
                    <?php endif; ?>

                    <h6 class="text-muted mb-3"><?php _e('requisition_items'); ?></h6>

                    <div class="table-responsive">
                        <table class="table table-bordered table-sm" id="requisitionTable">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 5%;"><?php _e('sr'); ?></th>
                                    <th style="width: <?php echo $hasBomCol ? '28%' : '35%'; ?>;"><?php _e('product'); ?></th>
                                    <th style="width: 11%;" class="text-end">Available</th>
                                    <?php if ($hasBomCol): ?>
                                    <th style="width: 13%;" class="text-end"><?php _e('bom_balance'); ?></th>
                                    <?php endif; ?>
                                    <th style="width: 13%;"><?php echo $isApprovedEdit ? __('approved_quantity') : __('requested_quantity'); ?></th>
                                    <th style="width: <?php echo $hasBomCol ? '20%' : '25%'; ?>;"><?php _e('remarks'); ?></th>
                                    <th style="width: 5%;"><?php _e('action'); ?></th>
                                </tr>
                            </thead>
                            <tbody id="requisitionItems">
                                <?php if ($isEdit && !empty($requisitionDetails)): ?>
                                    <?php foreach ($requisitionDetails as $index => $detail): ?>
                                <?php
                                    // A line that has already dispatched stock cannot be
                                    // removed, and its quantity cannot fall below what went
                                    // out. Both are re-enforced server-side.
                                    $lineTransferred = floatval($detail['transferred_quantity'] ?? 0);
                                    $lineLocked = $isApprovedEdit && $lineTransferred > 0;
                                ?>
                                <tr class="item-row">
                                    <td class="row-number"><?php echo $index + 1; ?></td>
                                    <td>
                                        <input type="hidden" name="detail_id[]" value="<?php echo (int)$detail['id']; ?>">
                                        <select name="products[]" class="form-select form-select-sm product-select" required>
                                            <option value="<?php echo (int)$detail['product_id']; ?>" selected>
                                                <?php echo htmlspecialchars($detail['product_name']); ?> (<?php echo htmlspecialchars($detail['unit']); ?>)
                                            </option>
                                        </select>
                                    </td>
                                    <td class="text-end available-cell text-muted">—</td>
                                    <?php if ($hasBomCol): ?>
                                    <td class="text-end bom-balance-cell text-muted">—</td>
                                    <?php endif; ?>
                                    <?php
                                        // On an approved voucher the box holds the EFFECTIVE quantity —
                                        // the approved figure when the approver set one. Prefilling the
                                        // requested quantity instead would silently undo an approver's
                                        // reduction, and resurrect a line they rejected (approved = 0),
                                        // on a save that changed nothing else.
                                        $lineQtyValue = $detail['requested_quantity'];
                                        if ($isApprovedEdit && isset($detail['approved_quantity']) && $detail['approved_quantity'] !== null) {
                                            $lineQtyValue = $detail['approved_quantity'];
                                        }
                                    ?>
                                    <td>
                                        <input type="number" name="requested_quantity[]" step="0.001"
                                               min="<?php echo $isApprovedEdit ? htmlspecialchars($lineTransferred) : '0.001'; ?>"
                                               data-transferred="<?php echo $lineTransferred; ?>"
                                               class="form-control form-control-sm qty-input"
                                               value="<?php echo htmlspecialchars($lineQtyValue); ?>" required>
                                        <?php if ($isApprovedEdit): ?>
                                        <div class="small text-muted mt-1">
                                            <span class="d-block">
                                                <?php _e('requested_quantity'); ?>: <?php echo formatNumber($detail['requested_quantity'], 3); ?>
                                            </span>
                                            <?php if ($lineTransferred > 0): ?>
                                            <span class="d-block text-danger">
                                                <i class="bi bi-truck"></i>
                                                <?php _e('transferred_so_far'); ?>: <?php echo formatNumber($lineTransferred, 3); ?>
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <input type="text" name="item_remarks[]" class="form-control form-control-sm"
                                               value="<?php echo htmlspecialchars($detail['remarks'] ?? ''); ?>"
                                               placeholder="<?php echo __('optional'); ?>">
                                    </td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-danger" onclick="removeRow(this)"
                                                <?php if ($lineLocked): ?>disabled data-keep-disabled="1"
                                                title="<?php echo htmlspecialchars(__('rv_line_locked_by_transfer')); ?>"<?php endif; ?>>
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                <tr class="item-row">
                                    <td class="row-number">1</td>
                                    <td>
                                        <input type="hidden" name="detail_id[]" value="">
                                        <select name="products[]" class="form-select form-select-sm product-select" required>
                                            <option value=""></option>
                                        </select>
                                    </td>
                                    <td class="text-end available-cell text-muted">—</td>
                                    <?php if ($hasBomCol): ?>
                                    <td class="text-end bom-balance-cell text-muted">—</td>
                                    <?php endif; ?>
                                    <td>
                                        <input type="number" name="requested_quantity[]" step="0.001" min="0.001"
                                               class="form-control form-control-sm qty-input" required>
                                    </td>
                                    <td>
                                        <input type="text" name="item_remarks[]" class="form-control form-control-sm" placeholder="<?php echo __('optional'); ?>">
                                    </td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-danger" onclick="removeRow(this)">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="<?php echo $hasBomCol ? '7' : '6'; ?>">
                                        <button type="button" class="btn btn-sm btn-primary" id="addRowBtn" onclick="addRow()">
                                            <i class="bi bi-plus"></i> <?php _e('add_row'); ?>
                                        </button>
                                        <?php require __DIR__ . '/../../includes/rv_import_control.php'; ?>
                                        <span id="itemsLockedHint" class="text-muted ms-2 small" style="display:none;">
                                            <i class="bi bi-info-circle"></i> Lock the destination location to add items.
                                        </span>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <!-- E-Signature: Prepared By -->
                    <?php $myMasterSig = getUserSignature($conn, getUserId()); ?>
                    <div class="card mt-4 mb-3">
                        <div class="card-header">
                            <h6 class="mb-0"><i class="bi bi-pen"></i> <?php _e('prepared_by'); ?> <small class="text-muted">(optional e-signature)</small></h6>
                        </div>
                        <?php if ($myMasterSig): ?>
                        <div class="card-body">
                            <img src="<?php echo htmlspecialchars($myMasterSig); ?>" alt="<?php _e('signature'); ?>" style="max-height: 90px; max-width: 100%;">
                            <div class="text-muted small mt-1"><i class="bi bi-info-circle"></i> <?php _e('signature_from_profile'); ?></div>
                        </div>
                        <?php else: ?>
                        <div class="card-body">
                            <style>
                                .rv-signature-wrapper { border: 1px dashed #adb5bd; border-radius: 4px; background: #fafafa; position: relative; max-width: 420px; }
                                .rv-signature-canvas { display: block; width: 100%; height: 140px; touch-action: none; cursor: crosshair; }
                                .rv-signature-placeholder { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: #adb5bd; font-style: italic; pointer-events: none; user-select: none; font-size: 13px; }
                            </style>
                            <div class="rv-signature-wrapper">
                                <canvas id="preparedBySignature" class="rv-signature-canvas"></canvas>
                                <span class="rv-signature-placeholder" id="preparedBySignaturePlaceholder">Sign here</span>
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

                    <div class="d-flex justify-content-end mt-4">
                        <a href="requisition_voucher_list.php" class="btn btn-secondary me-2"><?php _e('cancel'); ?></a>
                        <?php if (hasPermission('requisition_vouchers', 'create')): ?>
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check-circle"></i> <?php _e('save_requisition_voucher'); ?>
                        </button>
                        <?php endif; ?>
                    </div>
                </form>

<script src="<?php echo BASE_URL; ?>assets/js/signature_pad.js"></script>
<script>
let preparedSigPad = null;
const existingPreparedSigDataRV = <?php echo json_encode($requisition['prepared_by_signature'] ?? null); ?>;

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
        if (existingPreparedSigDataRV) {
            preparedSigPad.fromDataURL(existingPreparedSigDataRV);
            document.getElementById('preparedBySignaturePlaceholder').style.display = 'none';
        }
        canvas.addEventListener('pointerdown', function() {
            document.getElementById('preparedBySignaturePlaceholder').style.display = 'none';
        });
    }
});

// Capture signature into hidden input right before form submit
document.getElementById('requisitionForm')?.addEventListener('submit', function() {
    const input = document.getElementById('preparedBySignatureInput');
    if (input && preparedSigPad && !preparedSigPad.isEmpty()) {
        input.value = preparedSigPad.toDataURL();
    }
});
</script>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="stockBreakdownModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    Available stock by location
                    <small class="text-muted ms-2" id="stockBreakdownProductName"></small>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Location</th>
                            <th class="text-end" style="width: 30%;">Available qty</th>
                        </tr>
                    </thead>
                    <tbody id="stockBreakdownBody"></tbody>
                    <tfoot>
                        <tr class="table-light">
                            <th class="text-end">Total</th>
                            <th class="text-end" id="stockBreakdownTotal">0</th>
                        </tr>
                    </tfoot>
                </table>
                <p class="text-muted small mt-2 mb-0">Only locations with non-zero stock are listed.</p>
            </div>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

<script>
const PRODUCT_SEARCH_URL = BASE_URL + 'api/products-search.php';
const STOCK_FAMILY_URL   = BASE_URL + 'api/stock-family.php';
const PRODUCT_PLACEHOLDER = '<?php echo __('select_product'); ?>';
const IS_EDIT_MODE = <?php echo $destinationLockedOnLoad ? 'true' : 'false'; ?>;

// Tracks the value of the destination select before a change (used to revert
// when the user declines the "are you sure" confirmation).
let prevDestinationValue = document.getElementById('destinationLocationId').value || '';
let destinationLocked = false;

// Per-product stock cache: { productId: { total_qty, breakdown: [...] } }
// Populated as products are picked; used by the bifurcation modal.
const stockFamilyCache = {};

function initProductSelect2($el) {
    $el.select2({
        width: '100%',
        placeholder: PRODUCT_PLACEHOLDER,
        allowClear: false,
        minimumInputLength: 1,
        ajax: {
            url: PRODUCT_SEARCH_URL,
            dataType: 'json',
            delay: 250,
            data: function (params) {
                return { q: params.term || '', page: params.page || 1 };
            },
            processResults: function (data, params) {
                params.page = params.page || 1;
                return {
                    results: data.results || [],
                    pagination: { more: !!(data.pagination && data.pagination.more) }
                };
            },
            cache: true
        }
    });

    // When a product is chosen, fetch the family stock for it.
    $el.on('select2:select', function (e) {
        const row = $el.closest('tr')[0];
        const productId = parseInt($el.val(), 10);
        if (!productId) return;
        fetchFamilyStockForProducts([productId]).then(() => {
            updateAvailableCellsForRow(row);
        });
        updateBomCellForRow(row); // BOM balance (no-op when no BOM linked)
    });
}

function getLockedDestinationId() {
    // The select gets disabled after lock, so its own .value is still readable
    // but the POST value lives in a hidden input. Both should agree.
    const hidden = document.querySelector('input[type=hidden][name=destination_location_id]');
    if (hidden && hidden.value) return parseInt(hidden.value, 10);
    const sel = document.getElementById('destinationLocationId');
    return parseInt(sel.value || '0', 10);
}

function fetchFamilyStockForProducts(productIds) {
    productIds = (productIds || []).map(p => parseInt(p, 10)).filter(p => p > 0);
    if (productIds.length === 0) return Promise.resolve();
    const locId = getLockedDestinationId();
    if (!locId) return Promise.resolve();

    return fetch(STOCK_FAMILY_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ location_id: locId, product_ids: productIds })
    })
    .then(r => r.json())
    .then(data => {
        if (!data || !data.success) return;
        Object.keys(data.stock || {}).forEach(pid => {
            stockFamilyCache[parseInt(pid, 10)] = data.stock[pid];
        });
    })
    .catch(err => {
        console.error('stock-family fetch failed', err);
    });
}

function formatQty(n) {
    if (n === null || n === undefined || isNaN(n)) return '0';
    // Trim trailing zeros but keep up to 3 decimals (matches qty step 0.001).
    return parseFloat(n).toFixed(3).replace(/\.?0+$/, '');
}

function updateAvailableCellsForRow(row) {
    if (!row) return;
    const cell = row.querySelector('.available-cell');
    if (!cell) return;
    const productSelect = row.querySelector('.product-select');
    const productId = productSelect ? parseInt(productSelect.value, 10) : 0;
    if (!productId) {
        cell.innerHTML = '<span class="text-muted">—</span>';
        return;
    }
    const entry = stockFamilyCache[productId];
    if (!entry) {
        cell.innerHTML = '<span class="text-muted">…</span>';
        return;
    }
    const qty = formatQty(entry.total_qty);
    cell.innerHTML =
        '<a href="#" class="available-link text-decoration-none" data-product-id="' + productId + '">' +
            qty + ' <i class="bi bi-info-circle small"></i>' +
        '</a>';
}

function updateAllAvailableCells() {
    document.querySelectorAll('#requisitionItems .item-row').forEach(updateAvailableCellsForRow);
}

// ============================================================
// BOM balances (optional Bill of Materials link). All no-ops
// when no BOM column / no BOM selected, so non-BOM use is
// completely unaffected.
// ============================================================
const BOM_BALANCES_URL = BASE_URL + 'api/bom-balances.php';
const BOM_T = {
    qty: '<?php echo addslashes(__('bom_qty')); ?>',
    transferred: '<?php echo addslashes(__('transferred_so_far')); ?>',
    over: '<?php echo addslashes(__('over_bom_balance')); ?>'
};
const bomBalancesCache = {}; // productId -> { bom_qty, transferred, balance }
let bomLoaded = false;

function getSelectedBomId() {
    const el = document.getElementById('bomId');
    return el ? parseInt(el.value || '0', 10) : 0;
}

function fetchBomBalances(bomId) {
    Object.keys(bomBalancesCache).forEach(k => delete bomBalancesCache[k]);
    bomLoaded = false;
    if (!bomId) return Promise.resolve();
    return fetch(BOM_BALANCES_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ bom_id: bomId })
    })
    .then(r => r.json())
    .then(data => {
        if (data && data.success) {
            Object.keys(data.products || {}).forEach(pid => {
                bomBalancesCache[parseInt(pid, 10)] = data.products[pid];
            });
            bomLoaded = true;
        }
    })
    .catch(err => console.error('bom-balances fetch failed', err));
}

function updateBomCellForRow(row) {
    if (!row) return;
    const cell = row.querySelector('.bom-balance-cell');
    if (!cell) return; // column not present (no BOM feature)

    const sel = row.querySelector('.product-select');
    const productId = sel ? parseInt(sel.value, 10) : 0;
    const bomId = getSelectedBomId();

    if (!bomId || !productId) {
        row.classList.remove('bom-line');
        cell.innerHTML = '<span class="text-muted">—</span>';
        return;
    }

    const entry = bomBalancesCache[productId];
    if (!entry) {
        // Product is not part of the selected BOM (or still loading).
        row.classList.remove('bom-line');
        cell.innerHTML = '<span class="text-muted">' + (bomLoaded ? '—' : '…') + '</span>';
        return;
    }

    row.classList.add('bom-line');
    const bal = entry.balance;
    const qtyInput = row.querySelector('.qty-input');
    const requested = qtyInput ? parseFloat(qtyInput.value) : NaN;
    const over = !isNaN(requested) && requested > bal + 0.0005;
    const cls = over ? 'text-danger fw-bold' : (bal > 0 ? 'text-success' : 'text-muted');
    const title = BOM_T.qty + ': ' + formatQty(entry.bom_qty) + ' · ' + BOM_T.transferred + ': ' + formatQty(entry.transferred);

    cell.innerHTML =
        '<span class="' + cls + '" title="' + title + '">' +
            formatQty(bal) +
            (over ? ' <i class="bi bi-exclamation-triangle-fill" title="' + BOM_T.over + '"></i>' : '') +
        '</span>';
}

function updateAllBomCells() {
    document.querySelectorAll('#requisitionItems .item-row').forEach(updateBomCellForRow);
    const legend = document.getElementById('bomLegend');
    if (legend) legend.style.setProperty('display', getSelectedBomId() ? 'block' : 'none', 'important');
}

function openBreakdownModal(productId) {
    const entry = stockFamilyCache[productId];
    const $body = $('#stockBreakdownBody');
    $body.empty();
    if (!entry || !entry.breakdown || entry.breakdown.length === 0) {
        $body.append('<tr><td colspan="2" class="text-center text-muted">No stock in any location.</td></tr>');
        $('#stockBreakdownTotal').text('0');
    } else {
        entry.breakdown.forEach(row => {
            let cls = '';
            let badge = '';
            if (row.is_destination) {
                cls = 'table-warning fw-bold';
                badge = ' <span class="badge bg-warning text-dark ms-1">destination</span>';
            } else if (row.is_family) {
                badge = ' <span class="badge bg-info text-dark ms-1">family</span>';
            }
            $body.append(
                '<tr class="' + cls + '">' +
                    '<td>' + $('<div>').text(row.location_name).html() + badge + '</td>' +
                    '<td class="text-end">' + formatQty(row.qty) + '</td>' +
                '</tr>'
            );
        });
        $('#stockBreakdownTotal').text(formatQty(entry.total_qty));
    }

    // Product name shown next to modal title (read from the row's select2 option text).
    let productName = '';
    document.querySelectorAll('#requisitionItems .item-row .product-select').forEach(sel => {
        if (parseInt(sel.value, 10) === productId) {
            const opt = sel.options[sel.selectedIndex];
            if (opt) productName = opt.text;
        }
    });
    $('#stockBreakdownProductName').text(productName);

    const modalEl = document.getElementById('stockBreakdownModal');
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
}

function setItemsSectionEnabled(enabled) {
    document.getElementById('addRowBtn').disabled = !enabled;
    document.getElementById('itemsLockedHint').style.display = enabled ? 'none' : '';
    document.querySelectorAll('#requisitionItems .item-row').forEach(row => {
        row.querySelectorAll('input:not([type=hidden]), button').forEach(el => {
            if (enabled && el.dataset.keepDisabled === '1') return;
            el.disabled = !enabled;
        });
        const $sel = $(row).find('.product-select');
        if ($sel.length) $sel.prop('disabled', !enabled).trigger('change.select2');
    });
}

function lockDestination() {
    if (destinationLocked) return;
    const sel = document.getElementById('destinationLocationId');
    const value = sel.value;
    if (!value) return;

    // Add hidden input so the value still POSTs (a disabled select does not).
    let hidden = document.querySelector('input[type=hidden][name=destination_location_id]');
    if (!hidden) {
        hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'destination_location_id';
        sel.parentNode.appendChild(hidden);
    }
    hidden.value = value;

    // Disable the select for UX (also prevents it from posting a duplicate).
    $(sel).prop('disabled', true).trigger('change.select2');
    document.getElementById('destinationLockedBadge').style.display = '';
    destinationLocked = true;
    prevDestinationValue = value;

    setItemsSectionEnabled(true);
}

document.getElementById('requisitionForm').addEventListener('submit', function(e) {
    const destinationLocation = getLockedDestinationId();
    if (!destinationLocation) {
        e.preventDefault();
        alert('<?php echo __('please_select_location'); ?>');
        return false;
    }

    const rows = document.querySelectorAll('#requisitionItems .item-row');
    let hasValidItem = false;
    rows.forEach(row => {
        const productSelect = row.querySelector('.product-select');
        const qtyInput = row.querySelector('.qty-input');
        if (productSelect.value && parseFloat(qtyInput.value) > 0) {
            hasValidItem = true;
        }
    });

    if (!hasValidItem) {
        e.preventDefault();
        alert('<?php echo __('please_add_at_least_one_item'); ?>');
        return false;
    }

    // Post-approval edit: a line can never be cut below what has already been
    // dispatched against it. Re-enforced server-side in rvfApplyApprovedLineEdit.
    let floorBreach = null;
    rows.forEach(row => {
        const qtyInput = row.querySelector('.qty-input');
        if (!qtyInput) return;
        const transferred = parseFloat(qtyInput.dataset.transferred || '0');
        if (!(transferred > 0)) return;
        const qty = parseFloat(qtyInput.value);
        if (!(qty >= transferred - 0.0005)) {
            const sel = row.querySelector('.product-select');
            const opt = sel && sel.options[sel.selectedIndex];
            floorBreach = (opt ? opt.text : '') + ' — ' + transferred.toFixed(3);
        }
    });
    if (floorBreach) {
        e.preventDefault();
        alert('<?php echo __('requisition_qty_below_transferred_short'); ?>\n\n' + floorBreach);
        return false;
    }
});

/**
 * Add an item row.
 *
 * `data` is OPTIONAL and only ever passed by the Excel import
 * (includes/rv_import_control.php): {product_id, product_text, qty, remark}.
 * Called with no argument — which is what the Add Row button does — this
 * behaves exactly as it always has.
 */
function addRow(data) {
    if (!destinationLocked) return;
    const tbody = document.getElementById('requisitionItems');
    const rowCount = tbody.querySelectorAll('.item-row').length + 1;

    const newRow = document.createElement('tr');
    newRow.className = 'item-row';
    newRow.innerHTML = `
        <td class="row-number">${rowCount}</td>
        <td>
            <input type="hidden" name="detail_id[]" value="">
            <select name="products[]" class="form-select form-select-sm product-select" required>
                <option value=""></option>
            </select>
        </td>
        <td class="text-end available-cell text-muted">—</td>
        <?php echo $hasBomCol ? '<td class="text-end bom-balance-cell text-muted">—</td>' : ''; ?>
        <td>
            <input type="number" name="requested_quantity[]" step="0.001" min="0.001"
                   class="form-control form-control-sm qty-input" required>
        </td>
        <td>
            <input type="text" name="item_remarks[]" class="form-control form-control-sm" placeholder="<?php echo __('optional'); ?>">
        </td>
        <td class="text-center">
            <button type="button" class="btn btn-sm btn-danger" onclick="removeRow(this)">
                <i class="bi bi-trash"></i>
            </button>
        </td>
    `;

    tbody.appendChild(newRow);
    const $sel = $(newRow).find('.product-select');
    initProductSelect2($sel);

    // Prefill (import only). The product dropdown is AJAX-backed, so the chosen
    // product has to be injected as an already-selected <option> — there is no
    // static list to pick from. The Available / BOM cells are NOT refreshed
    // here: select2:select does not fire for a programmatic selection, and the
    // caller batches one stock lookup for the whole import instead of one per
    // row.
    if (data && data.product_id) {
        $sel.append(new Option(data.product_text || ('#' + data.product_id), data.product_id, true, true))
            .trigger('change.select2');
        const qtyInput = newRow.querySelector('.qty-input');
        if (qtyInput && data.qty) qtyInput.value = data.qty;
        const remarkInput = newRow.querySelector('input[name="item_remarks[]"]');
        if (remarkInput && data.remark) remarkInput.value = data.remark;
    }
}

function removeRow(btn) {
    const tbody = document.getElementById('requisitionItems');
    if (tbody.querySelectorAll('.item-row').length > 1) {
        const row = btn.closest('tr');
        $(row).find('.product-select').select2('destroy');
        row.remove();
        renumberRows();
    } else {
        alert('<?php echo __('at_least_one_item_required'); ?>');
    }
}

function renumberRows() {
    document.querySelectorAll('#requisitionItems .item-row').forEach((row, index) => {
        row.querySelector('.row-number').textContent = index + 1;
    });
}

$(document).ready(function() {
    $('#destinationLocationId').select2({
        width: '100%',
        placeholder: '<?php echo __('select_destination_location'); ?>'
    });

    $('#costCentreId').select2({
        width: '100%',
        placeholder: '<?php echo __('select_cost_centre'); ?>',
        allowClear: true
    });

    $('.product-select').each(function() {
        initProductSelect2($(this));
    });

    // Confirm + lock on destination change (create mode).
    $('#destinationLocationId').on('change', function () {
        if (destinationLocked) return;
        const newVal = this.value;
        if (!newVal) {
            prevDestinationValue = '';
            return;
        }
        if (confirm('Lock destination location? You will not be able to change it after confirming.')) {
            lockDestination();
        } else {
            // Revert silently.
            $(this).val(prevDestinationValue).trigger('change.select2');
        }
    });

    // Click on any Available cell -> open the bifurcation modal.
    $('#requisitionItems').on('click', '.available-link', function (e) {
        e.preventDefault();
        const pid = parseInt($(this).data('product-id'), 10);
        if (pid) openBreakdownModal(pid);
    });

    // BOM link (optional). Re-evaluate balances when the BOM changes, and the
    // over-balance warning when a requested quantity changes.
    if (document.getElementById('bomId')) {
        $('#bomId').select2({
            width: '100%',
            placeholder: '<?php echo addslashes(__('select_bom')); ?>',
            allowClear: true
        });
        $('#bomId').on('change', function () {
            fetchBomBalances(getSelectedBomId()).then(updateAllBomCells);
        });
        $('#requisitionItems').on('input', '.qty-input', function () {
            updateBomCellForRow(this.closest('tr'));
        });
        // Initial load (e.g. edit mode with a BOM already linked).
        if (getSelectedBomId()) {
            fetchBomBalances(getSelectedBomId()).then(updateAllBomCells);
        }
    }

    if (IS_EDIT_MODE) {
        // Edit mode: auto-lock the already-chosen destination, no confirm.
        lockDestination();

        // Pre-fetch family stock for all pre-loaded products in one shot.
        const preloadedProductIds = [];
        document.querySelectorAll('#requisitionItems .product-select').forEach(sel => {
            const pid = parseInt(sel.value, 10);
            if (pid) preloadedProductIds.push(pid);
        });
        if (preloadedProductIds.length) {
            fetchFamilyStockForProducts(preloadedProductIds).then(updateAllAvailableCells);
        }
    } else {
        // Create mode: items disabled until the user locks a destination.
        setItemsSectionEnabled(false);
    }
});
</script>
